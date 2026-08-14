[CmdletBinding()]
param(
    [ValidateSet('Up', 'Stop', 'Status', 'WatchCleanup')]
    [string] $Action = 'Up',
    [string] $InstallRoot = (Join-Path $env:USERPROFILE 'ScanToSeeSupervisor'),
    [int] $ExcludeProcessId = 0
)

. (Join-Path $PSScriptRoot 'Supervisor.Common.ps1')
$layout = Get-SupervisorLayout -InstallRoot $InstallRoot

function Read-State {
    if (!(Test-Path -LiteralPath $layout.State -PathType Leaf)) { return $null }
    try { return Get-Content -LiteralPath $layout.State -Raw | ConvertFrom-Json } catch { return $null }
}

function Stop-SupervisorProcesses {
    param([int] $ExcludedProcessId = 0)
    $state = Read-State
    if ($state) {
        if ($state.PSObject.Properties.Name -contains 'processes') {
            foreach ($record in @($state.processes)) {
                if ([int] $record.id -eq $ExcludedProcessId) { continue }
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
                if ([int] $serviceProcessId -eq $ExcludedProcessId) { continue }
                $process = Get-Process -Id $serviceProcessId -ErrorAction SilentlyContinue
                if ($process -and $process.ProcessName -in @('php', 'python', 'pythonw', 'ngrok')) {
                    Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue
                }
            }
        }
    }
    Remove-Item -LiteralPath $layout.State -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath (Join-Path $layout.Root 'cleanup-watcher.ready') -Force -ErrorAction SilentlyContinue
}

function Assert-EmergencyCleanupTargets {
    $expectedRoot = [IO.Path]::GetFullPath((Join-Path $env:USERPROFILE 'ScanToSeeSupervisor')).TrimEnd('\')
    $actualRoot = [IO.Path]::GetFullPath($layout.Root).TrimEnd('\')
    if ($actualRoot -ine $expectedRoot) {
        throw "Emergency cleanup only accepts the exact supervisor installation root: $expectedRoot"
    }
    if ($actualRoot -eq [IO.Path]::GetPathRoot($actualRoot) -or $actualRoot -ieq [IO.Path]::GetFullPath($env:USERPROFILE).TrimEnd('\')) {
        throw 'Emergency cleanup refused an unsafe installation root.'
    }

    $settingsPath = Join-Path $layout.Deployment 'deployment-settings.json'
    if (!(Test-Path -LiteralPath $settingsPath -PathType Leaf)) {
        throw 'Emergency cleanup cannot validate the original secret.txt path because deployment settings are missing.'
    }
    $settings = Get-Content -LiteralPath $settingsPath -Raw | ConvertFrom-Json
    if (!$settings.sourceSecretPath) { throw 'The recorded source secret.txt path is missing.' }
    $secretPath = [IO.Path]::GetFullPath([string] $settings.sourceSecretPath)
    if ([IO.Path]::GetFileName($secretPath) -cne 'secret.txt') {
        throw 'Emergency cleanup refused a source secret path whose filename is not exactly secret.txt.'
    }
    $secretParent = [IO.Path]::GetDirectoryName($secretPath).TrimEnd('\')
    if (!$secretParent -or $secretParent -eq [IO.Path]::GetPathRoot($secretPath).TrimEnd('\')) {
        throw 'Emergency cleanup refused a secret.txt stored directly at a drive root.'
    }
    if ($secretParent -ieq $actualRoot -or $secretParent.StartsWith("$actualRoot\", [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Emergency cleanup requires source secret.txt to be outside the installation being destroyed.'
    }
    return @{
        Root = $actualRoot
        Secret = $secretPath
        Notice = Join-Path $secretParent 'LIS-MOI-AU-CAS-OU-LE-CODE-A-DISPARU.txt'
    }
}

function Start-EmergencyCleanupWatcher {
    $powerShell = (Get-Command powershell.exe -ErrorAction Stop).Source
    $escapedScript = $PSCommandPath.Replace("'", "''")
    $escapedRoot = $layout.Root.Replace("'", "''")
    $command = "& '$escapedScript' -Action WatchCleanup -InstallRoot '$escapedRoot'"
    $encodedCommand = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($command))
    return Start-Process -FilePath $powerShell -ArgumentList @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', $encodedCommand
    ) -WorkingDirectory ([IO.Path]::GetTempPath()) -WindowStyle Hidden -PassThru
}

function Invoke-EmergencyCleanupWatcher {
    # Give the parent launcher time to record this watcher in supervisor-state.json.
    # Normal `-Action Stop` can then terminate it with the other services.
    $stateDeadline = (Get-Date).AddSeconds(30)
    $recordedInState = $false
    while ((Get-Date) -lt $stateDeadline) {
        $state = Read-State
        if ($state -and @($state.processes | Where-Object { [int] $_.id -eq $PID }).Count) {
            $recordedInState = $true
            break
        }
        Start-Sleep -Milliseconds 250
    }
    if (!$recordedInState) { throw 'The cleanup watcher was not recorded in supervisor process state.' }

    $targets = Assert-EmergencyCleanupTargets
    if (-not ('ScanToSeeEmergencyHotKey' -as [type])) {
        Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
public static class ScanToSeeEmergencyHotKey {
    [StructLayout(LayoutKind.Sequential)]
    public struct MSG {
        public IntPtr hwnd;
        public uint message;
        public UIntPtr wParam;
        public IntPtr lParam;
        public uint time;
        public int ptX;
        public int ptY;
    }
    [DllImport("user32.dll", SetLastError = true)]
    public static extern bool RegisterHotKey(IntPtr hWnd, int id, uint modifiers, uint virtualKey);
    [DllImport("user32.dll", SetLastError = true)]
    public static extern bool UnregisterHotKey(IntPtr hWnd, int id);
    [DllImport("user32.dll")]
    public static extern int GetMessage(out MSG message, IntPtr hWnd, uint min, uint max);
}
'@
    }

    $hotKeyId = 0x5343
    $modAlt = 0x0001
    $keyH = 0x48
    $wmHotKey = 0x0312
    if (![ScanToSeeEmergencyHotKey]::RegisterHotKey([IntPtr]::Zero, $hotKeyId, $modAlt, $keyH)) {
        throw "Could not register the emergency Alt+H shortcut (Win32 error $([Runtime.InteropServices.Marshal]::GetLastWin32Error()))."
    }
    [IO.File]::WriteAllText(
        (Join-Path $layout.Root 'cleanup-watcher.ready'),
        [string] $PID,
        [Text.UTF8Encoding]::new($false)
    )

    $firstPress = [datetime]::MinValue
    try {
        while ($true) {
            $message = New-Object ScanToSeeEmergencyHotKey+MSG
            if ([ScanToSeeEmergencyHotKey]::GetMessage([ref] $message, [IntPtr]::Zero, 0, 0) -le 0) { break }
            if ($message.message -ne $wmHotKey -or $message.wParam.ToUInt32() -ne $hotKeyId) { continue }
            $now = Get-Date
            if (($now - $firstPress).TotalSeconds -gt 5) {
                $firstPress = $now
                try { [Console]::Beep(900, 150) } catch {}
                continue
            }

            try { [Console]::Beep(650, 150); [Console]::Beep(450, 250) } catch {}
            [ScanToSeeEmergencyHotKey]::UnregisterHotKey([IntPtr]::Zero, $hotKeyId) | Out-Null
            $nukeScript = Join-Path $layout.Deployment 'Nuke-Personal-Data.ps1'
            try {
                if (Test-Path -LiteralPath $nukeScript -PathType Leaf) {
                    & $nukeScript -InstallRoot $targets.Root -ConfirmNuke -ExcludeProcessId $PID -SkipResidualScan
                }
            } finally {
                # The watcher runs with a temporary working directory, so its
                # own installed script tree can be removed safely.
                $cleanupFailures = @()
                try {
                    Remove-Item -LiteralPath $targets.Secret -Force -ErrorAction Stop
                } catch {
                    if (Test-Path -LiteralPath $targets.Secret) { $cleanupFailures += $targets.Secret }
                }
                for ($attempt = 1; $attempt -le 3 -and (Test-Path -LiteralPath $targets.Root); $attempt++) {
                    Remove-Item -LiteralPath $targets.Root -Recurse -Force -ErrorAction SilentlyContinue
                    if (Test-Path -LiteralPath $targets.Root) { Start-Sleep -Seconds 1 }
                }
                if (Test-Path -LiteralPath $targets.Root) { $cleanupFailures += $targets.Root }
                if ($cleanupFailures.Count) {
                    throw "Emergency cleanup could not completely remove: $($cleanupFailures -join ', ')"
                }
                Write-CleanupNotice -Path $targets.Notice | Out-Null
            }
            break
        }
    } finally {
        [ScanToSeeEmergencyHotKey]::UnregisterHotKey([IntPtr]::Zero, $hotKeyId) | Out-Null
        Remove-Item -LiteralPath (Join-Path $layout.Root 'cleanup-watcher.ready') -Force -ErrorAction SilentlyContinue
    }
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
    Stop-SupervisorProcesses -ExcludedProcessId $ExcludeProcessId
    Write-Host 'ScanToSee supervisor services stopped.' -ForegroundColor Green
    return
}
if ($Action -eq 'Status') { Show-Status; return }
if ($Action -eq 'WatchCleanup') { Invoke-EmergencyCleanupWatcher; return }

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
    & $php (Join-Path $layout.Web 'bin\console') asset-map:compile --env=prod --no-debug
    if ($LASTEXITCODE -ne 0) { throw 'Symfony production asset compilation failed.' }

    $env:SCANTOSEE_TORCH_DEVICE = 'auto'
    $fastApi = Start-Process -FilePath $python -ArgumentList @('-m', 'uvicorn', 'main:app', '--host', '127.0.0.1', '--port', '8001') `
        -WorkingDirectory (Join-Path $layout.Ai 'src') -WindowStyle Minimized -PassThru `
        -RedirectStandardOutput (Join-Path $logRoot 'fastapi.out.log') `
        -RedirectStandardError (Join-Path $logRoot 'fastapi.err.log')
    $processes += $fastApi

    $symfony = Start-Process -FilePath $php -ArgumentList @('-d', 'max_execution_time=300', '-S', '127.0.0.1:8000', '-t', 'public', 'public/index.php') `
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

    $watcherReadyPath = Join-Path $layout.Root 'cleanup-watcher.ready'
    Remove-Item -LiteralPath $watcherReadyPath -Force -ErrorAction SilentlyContinue
    $cleanupWatcher = Start-EmergencyCleanupWatcher
    $processes += $cleanupWatcher

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
    $watcherDeadline = (Get-Date).AddSeconds(15)
    while ((Get-Date) -lt $watcherDeadline) {
        $cleanupWatcher.Refresh()
        if ($cleanupWatcher.HasExited) { throw 'The emergency cleanup shortcut watcher exited during startup.' }
        if (Test-Path -LiteralPath $watcherReadyPath -PathType Leaf) {
            $readyPid = Get-Content -LiteralPath $watcherReadyPath -Raw
            if ($readyPid.Trim() -eq [string] $cleanupWatcher.Id) { break }
        }
        Start-Sleep -Milliseconds 250
    }
    if (!(Test-Path -LiteralPath $watcherReadyPath -PathType Leaf)) {
        throw 'The emergency cleanup shortcut did not become ready within 15 seconds.'
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
        Remove-Item -LiteralPath (Join-Path $layout.Root 'cleanup-watcher.ready') -Force -ErrorAction SilentlyContinue
    }
}

Write-Host 'ScanToSee is running.' -ForegroundColor Green
Write-Host 'The mandatory ngrok HTTPS tunnel is connected and QR generation is configured.' -ForegroundColor Cyan
Write-Host 'FastAPI selected CUDA automatically when usable; otherwise it uses CPU.'
Write-Host 'Emergency cleanup is armed: press Alt+H twice within five seconds to remove the private installation and source secret.txt.' -ForegroundColor Yellow
Write-Host "Logs: $logRoot"
