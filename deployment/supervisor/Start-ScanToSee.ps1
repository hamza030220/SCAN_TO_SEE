[CmdletBinding()]
param(
    [ValidateSet('Up', 'Stop', 'Status')]
    [string] $Action = 'Up',
    [string] $InstallRoot = (Join-Path $env:USERPROFILE 'ScanToSeeSupervisor')
)

. (Join-Path $PSScriptRoot 'Supervisor.Common.ps1')
$layout = Get-SupervisorLayout -InstallRoot $InstallRoot

function Read-State {
    if (!(Test-Path -LiteralPath $layout.State -PathType Leaf)) { return $null }
    try { return Get-Content -LiteralPath $layout.State -Raw | ConvertFrom-Json } catch { return $null }
}

function Stop-SupervisorProcesses {
    $state = Read-State
    if ($state) {
        if ($state.PSObject.Properties.Name -contains 'processes') {
            foreach ($record in @($state.processes)) {
                $process = Get-Process -Id ([int] $record.id) -ErrorAction SilentlyContinue
                if (!$process) { continue }
                try {
                    $samePath = [IO.Path]::GetFullPath($process.Path) -ieq [IO.Path]::GetFullPath([string] $record.path)
                    $sameStart = [math]::Abs(($process.StartTime - [datetime] $record.startedAt).TotalSeconds) -lt 2
                    if ($samePath -and $sameStart) { Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue }
                } catch { continue }
            }
        } elseif ($state.PSObject.Properties.Name -contains 'processIds') {
            # Compatibility with state written by the earlier deployment
            # launcher. New state records path/time and cannot kill a reused PID.
            foreach ($serviceProcessId in @($state.processIds)) {
                $process = Get-Process -Id $serviceProcessId -ErrorAction SilentlyContinue
                if ($process -and $process.ProcessName -in @('php', 'python', 'pythonw', 'ngrok')) {
                    Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue
                }
            }
        }
    }
    Remove-Item -LiteralPath $layout.State -Force -ErrorAction SilentlyContinue
}

function Show-Status {
    $services = @(
        @{ Name = 'Symfony'; Port = 8000; Url = 'http://127.0.0.1:8000' },
        @{ Name = 'FastAPI'; Port = 8001; Url = 'http://127.0.0.1:8001/health' },
        @{ Name = 'ngrok'; Port = 4040; Url = 'http://127.0.0.1:4040' },
        @{ Name = 'MariaDB'; Port = 3306; Url = 'local database' }
    )
    foreach ($service in $services) {
        $status = if (Test-TcpPort -Port $service.Port) { 'RUNNING' } else { 'STOPPED' }
        Write-Host ('{0,-10} {1,-8} {2}' -f $service.Name, $status, $service.Url)
    }
    if (Test-TcpPort -Port 4040) {
        try {
            $tunnels = Invoke-RestMethod 'http://127.0.0.1:4040/api/tunnels' -TimeoutSec 3
            $publicUrl = $tunnels.tunnels | Where-Object { $_.proto -eq 'https' } | Select-Object -ExpandProperty public_url -First 1
            if ($publicUrl) { Write-Host 'ngrok HTTPS tunnel is ready.' -ForegroundColor Cyan }
        } catch { Write-Warning 'ngrok is running but its local API could not be read.' }
    }
}

if ($Action -eq 'Stop') {
    Stop-SupervisorProcesses
    Write-Host 'ScanToSee supervisor services stopped.' -ForegroundColor Green
    exit 0
}
if ($Action -eq 'Status') { Show-Status; exit 0 }

foreach ($directory in @($layout.Web, $layout.Ai)) {
    if (!(Test-Path -LiteralPath $directory -PathType Container)) {
        throw "Installation is incomplete; missing $directory"
    }
}

Start-XamppMySql
$php = Resolve-PhpExecutable
$python = Join-Path $layout.Ai '.venv\Scripts\python.exe'
if (!(Test-Path -LiteralPath $python -PathType Leaf)) { throw 'The OCR virtual environment is missing. Run the installer.' }
$ngrok = Join-Path $layout.Tools 'ngrok.exe'
if (Test-Path -LiteralPath $ngrok -PathType Leaf) {
    try {
        & $ngrok version | Out-Null
        if ($LASTEXITCODE -ne 0) { $ngrok = $null }
    } catch { $ngrok = $null }
} else { $ngrok = $null }
if (!$ngrok) {
    $ngrok = Get-Command ngrok.exe -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source -First 1
}
if (!$ngrok) { throw 'ngrok is mandatory but ngrok.exe was not found.' }
try {
    & $ngrok version | Out-Null
} catch {
    throw "ngrok is installed but Windows blocked it: $($_.Exception.Message)"
}
if ($LASTEXITCODE -ne 0) { throw 'ngrok failed its version test.' }

Stop-SupervisorProcesses
$busyPorts = @(@(4040, 8000, 8001) | Where-Object { Test-TcpPort -Port $_ })
if ($busyPorts.Count) {
    throw "Required application ports are already in use: $($busyPorts -join ', '). Stop the conflicting processes and rerun."
}
$logRoot = Join-Path $layout.Root 'logs'
New-Item -ItemType Directory -Path $logRoot -Force | Out-Null
$processes = @()
$startupSucceeded = $false

try {
    # Start ngrok first so the public URL can be written into Symfony before its
    # production cache is built. This makes newly generated QR codes phone-ready.
    $settingsPath = Join-Path $layout.Deployment 'deployment-settings.json'
    $settings = if (Test-Path -LiteralPath $settingsPath) {
        Get-Content -LiteralPath $settingsPath -Raw | ConvertFrom-Json
    } else { [pscustomobject]@{ ngrokDomain = '' } }
    $ngrokArgs = @('http', '8000')
    if ($settings.ngrokDomain) { $ngrokArgs = @('http', "--url=$($settings.ngrokDomain)", '8000') }
    $ngrokProcess = Start-Process -FilePath $ngrok -ArgumentList $ngrokArgs -WorkingDirectory $layout.Root `
        -WindowStyle Minimized -PassThru `
        -RedirectStandardOutput (Join-Path $logRoot 'ngrok.out.log') `
        -RedirectStandardError (Join-Path $logRoot 'ngrok.err.log')
    $processes += $ngrokProcess
    Wait-TcpPort -Port 4040 -TimeoutSeconds 45

    $publicUrl = $null
    $deadline = (Get-Date).AddSeconds(45)
    while ((Get-Date) -lt $deadline -and !$publicUrl) {
        try {
            $tunnels = Invoke-RestMethod 'http://127.0.0.1:4040/api/tunnels' -TimeoutSec 3
            $publicUrl = $tunnels.tunnels | Where-Object { $_.proto -eq 'https' } | Select-Object -ExpandProperty public_url -First 1
        } catch { Start-Sleep -Seconds 1 }
    }
    if (!$publicUrl) { throw 'ngrok started but did not provide an HTTPS tunnel URL.' }

    $webEnv = Join-Path $layout.Web '.env.local'
    Set-DotEnvValue -Path $webEnv -Name 'PUBLIC_BASE_URL' -Value $publicUrl
    Set-DotEnvValue -Path $webEnv -Name 'MAILER_BASE_URL' -Value $publicUrl

    $env:APP_ENV = 'prod'
    $env:APP_DEBUG = '0'
    & $php (Join-Path $layout.Web 'bin\console') cache:clear --env=prod --no-debug
    if ($LASTEXITCODE -ne 0) { throw 'Symfony production cache preparation failed.' }

    $env:SCANTOSEE_TORCH_DEVICE = 'auto'
    $fastApi = Start-Process -FilePath $python -ArgumentList @('-m', 'uvicorn', 'main:app', '--host', '127.0.0.1', '--port', '8001') `
        -WorkingDirectory (Join-Path $layout.Ai 'src') -WindowStyle Minimized -PassThru `
        -RedirectStandardOutput (Join-Path $logRoot 'fastapi.out.log') `
        -RedirectStandardError (Join-Path $logRoot 'fastapi.err.log')
    $processes += $fastApi

    $symfony = Start-Process -FilePath $php -ArgumentList @('-S', '127.0.0.1:8000', '-t', 'public', 'public/index.php') `
        -WorkingDirectory $layout.Web -WindowStyle Minimized -PassThru `
        -RedirectStandardOutput (Join-Path $logRoot 'symfony.out.log') `
        -RedirectStandardError (Join-Path $logRoot 'symfony.err.log')
    $processes += $symfony

    $scheduler = Start-Process -FilePath $php -ArgumentList @('bin/console', 'messenger:consume', 'scheduler_subscription_reminders', '-vv') `
        -WorkingDirectory $layout.Web -WindowStyle Minimized -PassThru `
        -RedirectStandardOutput (Join-Path $logRoot 'scheduler.out.log') `
        -RedirectStandardError (Join-Path $logRoot 'scheduler.err.log')
    $processes += $scheduler

    Wait-TcpPort -Port 8000 -TimeoutSeconds 60
    Wait-TcpPort -Port 8001 -TimeoutSeconds 180
    foreach ($serviceProcess in $processes) {
        $serviceProcess.Refresh()
        if ($serviceProcess.HasExited) { throw "A service process exited during startup. Inspect logs in $logRoot." }
    }
    $health = Invoke-RestMethod 'http://127.0.0.1:8001/health' -TimeoutSec 30
    if ($health.status -ne 'ok') { throw 'FastAPI health verification failed.' }
    $webResponse = Invoke-WebRequest 'http://127.0.0.1:8000/' -UseBasicParsing -TimeoutSec 30
    if ([int] $webResponse.StatusCode -ge 500) { throw "Symfony returned HTTP $($webResponse.StatusCode)." }

    @{
        startedAt = (Get-Date).ToString('o')
        processes = @($processes | ForEach-Object {
            $_.Refresh()
            @{ id = $_.Id; path = $_.Path; startedAt = $_.StartTime.ToString('o') }
        })
        publicUrl = $publicUrl
    } | ConvertTo-Json | ForEach-Object {
        [IO.File]::WriteAllText($layout.State, $_, [Text.UTF8Encoding]::new($false))
    }
    $startupSucceeded = $true
} finally {
    if (!$startupSucceeded) {
        foreach ($serviceProcess in $processes) {
            if ($serviceProcess -and !$serviceProcess.HasExited) {
                Stop-Process -Id $serviceProcess.Id -Force -ErrorAction SilentlyContinue
            }
        }
        Remove-Item -LiteralPath $layout.State -Force -ErrorAction SilentlyContinue
    }
}

Write-Host 'ScanToSee is running.' -ForegroundColor Green
Write-Host 'The mandatory ngrok HTTPS tunnel is connected and QR generation is configured.' -ForegroundColor Cyan
Write-Host 'FastAPI selected CUDA automatically when usable; otherwise it uses CPU.'
Write-Host "Logs: $logRoot"
