$ErrorActionPreference = 'Stop'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')

$host.UI.RawUI.WindowTitle = 'S2S - AI Training Worker'
Set-Location $ProjectRoot

Write-Host 'S2S AI training worker' -ForegroundColor Yellow
Write-Host 'Waits for training jobs created from the administrator panel.'
Write-Host 'Closing this window stops the worker, but never changes the active model.'
Write-Host ''

& php bin/console messenger:consume async -vv --keepalive=5
exit $LASTEXITCODE
