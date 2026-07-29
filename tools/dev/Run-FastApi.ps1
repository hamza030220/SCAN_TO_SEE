$ErrorActionPreference = 'Stop'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$AppRoot = Split-Path -Parent $ProjectRoot
$FastApiRoot = Join-Path $AppRoot 'handwritten-menu-scanner\src'

$host.UI.RawUI.WindowTitle = 'S2S - FastAPI :8001'

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

if (Test-TcpPort -Port 8001) {
    Write-Host 'Port 8001 is already active. Keeping the existing FastAPI process.' -ForegroundColor Yellow
    while (Test-TcpPort -Port 8001) {
        Start-Sleep -Seconds 3
    }
    exit 0
}

if (!(Test-Path -LiteralPath (Join-Path $FastApiRoot 'main.py'))) {
    throw "FastAPI project was not found at $FastApiRoot"
}

$pythonCandidates = @(
    (Join-Path $env:USERPROFILE 'anaconda3\envs\training\python.exe'),
    (Join-Path $env:USERPROFILE 'miniconda3\envs\training\python.exe'),
    (Join-Path $env:USERPROFILE '.conda\envs\training\python.exe'),
    'C:\ProgramData\anaconda3\envs\training\python.exe',
    'C:\ProgramData\miniconda3\envs\training\python.exe'
)
$TrainingPython = $pythonCandidates |
    Where-Object { Test-Path -LiteralPath $_ } |
    Select-Object -First 1

if (!$TrainingPython) {
    throw 'The Conda environment "training" was not found in a supported location.'
}

Set-Location $FastApiRoot

Write-Host 'S2S FastAPI OCR pipeline' -ForegroundColor Yellow
Write-Host "Python: $TrainingPython"
Write-Host 'Local URL: http://127.0.0.1:8001'
Write-Host 'API docs:  http://127.0.0.1:8001/docs'
Write-Host ''

& $TrainingPython -m uvicorn main:app --host 127.0.0.1 --port 8001
