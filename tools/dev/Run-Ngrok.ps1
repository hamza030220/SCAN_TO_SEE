$ErrorActionPreference = 'Stop'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')

$host.UI.RawUI.WindowTitle = 'S2S - ngrok -> :8000'
Set-Location $ProjectRoot

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

if (Test-TcpPort -Port 4040) {
    Write-Host 'ngrok is already active. Keeping the existing tunnel.' -ForegroundColor Yellow
    while (Test-TcpPort -Port 4040) {
        Start-Sleep -Seconds 3
    }
    exit 0
}

$ngrok = Get-Command ngrok -ErrorAction SilentlyContinue
if (!$ngrok) {
    throw 'ngrok was not found in PATH.'
}

Write-Host 'S2S ngrok tunnel' -ForegroundColor Yellow
Write-Host 'Target:    http://127.0.0.1:8000'
Write-Host 'Inspector: http://127.0.0.1:4040'
Write-Host ''

& $ngrok.Source http 8000
