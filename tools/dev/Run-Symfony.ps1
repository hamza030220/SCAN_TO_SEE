$ErrorActionPreference = 'Stop'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')

$host.UI.RawUI.WindowTitle = 'S2S - Symfony :8000'
Set-Location $ProjectRoot

Write-Host 'S2S Symfony server' -ForegroundColor Yellow
Write-Host 'Local URL: http://127.0.0.1:8000'
Write-Host 'Workers are disabled here because FastAPI and ngrok have their own terminals.'
Write-Host ''

& symfony serve --no-workers
