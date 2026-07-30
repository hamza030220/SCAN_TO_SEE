$ErrorActionPreference = 'Stop'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')

$host.UI.RawUI.WindowTitle = 'S2S - Subscription Scheduler'
Set-Location $ProjectRoot

Write-Host 'S2S subscription scheduler' -ForegroundColor Yellow
Write-Host 'Runs subscription reminders and expiry checks daily at 08:00.'
Write-Host ''

& php bin/console messenger:consume scheduler_subscription_reminders -vv
exit $LASTEXITCODE
