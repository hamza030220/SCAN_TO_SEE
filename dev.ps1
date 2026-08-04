[CmdletBinding()]
param(
    [ValidateSet('up', 'down', 'status', 'assets')]
    [string] $Action = 'up',
    [switch] $DryRun,
    [switch] $Public
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = $PSScriptRoot
$StateFile = Join-Path $ProjectRoot 'var\dev-stack.json'

function Test-TcpPort {
    param([int] $Port)

    $client = [System.Net.Sockets.TcpClient]::new()
    try {
        $connect = $client.ConnectAsync('127.0.0.1', $Port)
        return $connect.Wait(250) -and $client.Connected
    } catch {
        return $false
    } finally {
        $client.Dispose()
    }
}

function Write-ServiceStatus {
    $services = @(
        @{ Name = 'Symfony'; Port = 8000; Url = 'http://127.0.0.1:8000' },
        @{ Name = 'FastAPI'; Port = 8001; Url = 'http://127.0.0.1:8001/docs' },
        @{ Name = 'ngrok inspector'; Port = 4040; Url = 'http://127.0.0.1:4040' }
    )

    foreach ($service in $services) {
        $running = Test-TcpPort -Port $service.Port
        $label = if ($running) { 'RUNNING' } else { 'STOPPED' }
        $color = if ($running) { 'Green' } else { 'DarkGray' }
        Write-Host ('{0,-16} {1,-8} {2}' -f $service.Name, $label, $service.Url) -ForegroundColor $color
    }

    $schedulerRunning = $false
    if (Test-Path -LiteralPath $StateFile) {
        try {
            $state = Get-Content -LiteralPath $StateFile -Raw | ConvertFrom-Json
            $schedulerRunning = $null -ne $state.schedulerTerminalPid -and
                $null -ne (Get-Process -Id $state.schedulerTerminalPid -ErrorAction SilentlyContinue)
        } catch {
            $schedulerRunning = $false
        }
    }
    $schedulerLabel = if ($schedulerRunning) { 'RUNNING' } else { 'STOPPED' }
    $schedulerColor = if ($schedulerRunning) { 'Green' } else { 'DarkGray' }
    Write-Host ('{0,-16} {1,-8} {2}' -f 'Scheduler', $schedulerLabel, 'daily at 08:00') -ForegroundColor $schedulerColor

    if (Test-TcpPort -Port 4040) {
        try {
            $response = Invoke-RestMethod 'http://127.0.0.1:4040/api/tunnels' -TimeoutSec 2
            foreach ($tunnel in $response.tunnels) {
                Write-Host ('Public URL       {0} -> {1}' -f $tunnel.public_url, $tunnel.config.addr) -ForegroundColor Cyan
            }
        } catch {
            Write-Host 'ngrok is listening, but its tunnel API could not be read.' -ForegroundColor Yellow
        }
    }
}

function Start-VisibleService {
    param(
        [string] $Name,
        [int] $Port,
        [string] $Script
    )

    if (Test-TcpPort -Port $Port) {
        Write-Host "$Name is already using port $Port; keeping the existing process." -ForegroundColor Yellow
        return $null
    }

    $scriptPath = Join-Path $ProjectRoot $Script
    if (!(Test-Path -LiteralPath $scriptPath)) {
        throw "Missing launcher: $scriptPath"
    }

    if ($DryRun) {
        Write-Host "[dry-run] Would open $Name using $scriptPath" -ForegroundColor Cyan
        return $null
    }

    $arguments = "-NoExit -ExecutionPolicy Bypass -File `"$scriptPath`""
    $process = Start-Process `
        -FilePath 'powershell.exe' `
        -ArgumentList $arguments `
        -WorkingDirectory $ProjectRoot `
        -PassThru

    Write-Host "Opened $Name terminal (PID $($process.Id))." -ForegroundColor Green
    return $process.Id
}

function Start-VisibleWorker {
    param(
        [string] $Name,
        [string] $Script,
        [AllowNull()]
        [int] $ExistingPid
    )

    if ($ExistingPid -and (Get-Process -Id $ExistingPid -ErrorAction SilentlyContinue)) {
        Write-Host "$Name worker is already running; keeping PID $ExistingPid." -ForegroundColor Yellow
        return $ExistingPid
    }

    $scriptPath = Join-Path $ProjectRoot $Script
    if (!(Test-Path -LiteralPath $scriptPath)) {
        throw "Missing launcher: $scriptPath"
    }

    if ($DryRun) {
        Write-Host "[dry-run] Would open $Name using $scriptPath" -ForegroundColor Cyan
        return $null
    }

    $arguments = "-NoProfile -ExecutionPolicy Bypass -File `"$scriptPath`""
    $process = Start-Process `
        -FilePath 'powershell.exe' `
        -ArgumentList $arguments `
        -WorkingDirectory $ProjectRoot `
        -PassThru

    Write-Host "Opened $Name terminal (PID $($process.Id))." -ForegroundColor Green
    return $process.Id
}

function Stop-PortProcess {
    param(
        [int] $Port,
        [string[]] $AllowedProcessNames
    )

    $listeners = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
    foreach ($listener in $listeners) {
        $process = Get-Process -Id $listener.OwningProcess -ErrorAction SilentlyContinue
        if (!$process) {
            continue
        }
        if ($process.ProcessName -notin $AllowedProcessNames) {
            Write-Host "Port $Port belongs to $($process.ProcessName); it was not stopped." -ForegroundColor Yellow
            continue
        }
        Stop-Process -Id $process.Id -Force
        Write-Host "Stopped $($process.ProcessName) on port $Port." -ForegroundColor Green
    }
}

switch ($Action) {
    'assets' {
        Write-Host 'Refreshing compiled web assets...' -ForegroundColor Cyan
        & php bin/console cache:clear --env=prod --no-debug
        if ($LASTEXITCODE -ne 0) {
            throw 'Symfony production cache could not be refreshed.'
        }
        & php bin/console asset-map:compile --env=prod --no-debug
        if ($LASTEXITCODE -ne 0) {
            throw 'Symfony assets could not be refreshed.'
        }
        Write-Host 'Assets refreshed. Reload the browser; restarting Symfony is not required.' -ForegroundColor Green
        break
    }

    'status' {
        Write-ServiceStatus
        break
    }

    'up' {
        $requiredCommands = 'symfony', 'ngrok'
        foreach ($command in $requiredCommands) {
            if (!(Get-Command $command -ErrorAction SilentlyContinue)) {
                throw "Required command '$command' was not found in PATH."
            }
        }

        # FastAPI accepts destructive training-asset cleanup only from this
        # Symfony application. Child terminals inherit this process variable.
        $appSecretLine = Get-Content -LiteralPath (Join-Path $ProjectRoot '.env') |
            Where-Object { $_ -match '^APP_SECRET=' } |
            Select-Object -First 1
        if (!$appSecretLine) {
            throw 'APP_SECRET is missing; secure OCR asset cleanup cannot be configured.'
        }
        $env:OCR_CLEANUP_TOKEN = ($appSecretLine -split '=', 2)[1].Trim()

        if ($Public) {
            Write-Host 'Public sharing mode: Symfony browser debug is disabled; terminal logs remain visible.' -ForegroundColor Cyan
            if (!$DryRun) {
                $env:APP_ENV = 'prod'
                $env:APP_DEBUG = '0'

                & php bin/console cache:clear --env=prod --no-debug
                if ($LASTEXITCODE -ne 0) {
                    throw 'Could not prepare the Symfony production cache.'
                }
                & php bin/console asset-map:compile --env=prod --no-debug
                if ($LASTEXITCODE -ne 0) {
                    throw 'Could not compile Symfony production assets.'
                }
            }
        }

        $existingPids = @()
        $existingSchedulerPid = $null
        if (Test-Path -LiteralPath $StateFile) {
            try {
                $existingState = Get-Content -LiteralPath $StateFile -Raw | ConvertFrom-Json
                $existingPids = @($existingState.terminalPids | Where-Object {
                    Get-Process -Id $_ -ErrorAction SilentlyContinue
                })
                if ($existingState.schedulerTerminalPid -and
                    (Get-Process -Id $existingState.schedulerTerminalPid -ErrorAction SilentlyContinue)) {
                    $existingSchedulerPid = [int] $existingState.schedulerTerminalPid
                }
            } catch {
                $existingPids = @()
                $existingSchedulerPid = $null
            }
        }

        $terminalPids = @()
        $terminalPids += Start-VisibleService -Name 'Symfony' -Port 8000 -Script 'tools\dev\Run-Symfony.ps1'
        $terminalPids += Start-VisibleService -Name 'FastAPI' -Port 8001 -Script 'tools\dev\Run-FastApi.ps1'
        $terminalPids += Start-VisibleService -Name 'ngrok' -Port 4040 -Script 'tools\dev\Run-Ngrok.ps1'
        $schedulerTerminalPid = Start-VisibleWorker `
            -Name 'Scheduler' `
            -Script 'tools\dev\Run-Scheduler.ps1' `
            -ExistingPid $existingSchedulerPid
        $terminalPids += $schedulerTerminalPid
        $terminalPids = @($terminalPids | Where-Object { $_ })

        if (!$DryRun) {
            $terminalPids = @($existingPids + $terminalPids | Select-Object -Unique)

            $stateDirectory = Split-Path -Parent $StateFile
            if (!(Test-Path -LiteralPath $stateDirectory)) {
                New-Item -ItemType Directory -Path $stateDirectory | Out-Null
            }
            @{
                startedAt = (Get-Date).ToString('o')
                terminalPids = $terminalPids
                schedulerTerminalPid = $schedulerTerminalPid
            } | ConvertTo-Json | Set-Content -LiteralPath $StateFile -Encoding UTF8
        }

        Write-Host ''
        if ($DryRun) {
            Write-Host 'Dry run complete; no service was started.' -ForegroundColor Cyan
        } else {
            Write-Host 'The services are starting in separate terminals.' -ForegroundColor Cyan
        }
        Write-Host 'Run ".\dev.cmd status" after a few seconds to see URLs.'
        Write-Host 'Run ".\dev.cmd down" to stop the complete stack.'
        break
    }

    'down' {
        if ($DryRun) {
            Write-Host '[dry-run] Would stop this project Symfony server, FastAPI on 8001, ngrok on 4040, and the Scheduler worker.'
            break
        }

        Push-Location $ProjectRoot
        try {
            & symfony server:stop 2>$null
        } catch {
            Write-Host 'Symfony server was already stopped or could not be reached.' -ForegroundColor DarkGray
        } finally {
            Pop-Location
        }

        Stop-PortProcess -Port 8001 -AllowedProcessNames @('python', 'pythonw')
        Stop-PortProcess -Port 4040 -AllowedProcessNames @('ngrok')

        if (Test-Path -LiteralPath $StateFile) {
            $state = Get-Content -LiteralPath $StateFile -Raw | ConvertFrom-Json
            foreach ($terminalPid in @($state.terminalPids)) {
                $terminal = Get-Process -Id $terminalPid -ErrorAction SilentlyContinue
                if ($terminal -and $terminal.ProcessName -in @('powershell', 'pwsh')) {
                    Stop-Process -Id $terminal.Id -Force
                }
            }
            Remove-Item -LiteralPath $StateFile -Force
        }

        Write-Host 'S2S development stack stopped.' -ForegroundColor Green
        break
    }
}
