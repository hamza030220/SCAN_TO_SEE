[CmdletBinding()]
param(
    [string] $AdminEmail = 'supervisor.admin@example.test',
    [string] $OwnerEmail = 'supervisor.owner@example.test',
    [string] $NgrokAuthtoken = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$PackageRoot = $PSScriptRoot
$WebRoot = Resolve-Path (Join-Path $PackageRoot '..\..')
$WorkspaceRoot = Split-Path -Parent $WebRoot
$AiRoot = Join-Path $WorkspaceRoot 'handwritten-menu-scanner'
$OutputPath = Join-Path $PackageRoot 'secret.txt'

function Import-DotEnv {
    param([string] $Path, [hashtable] $Destination)
    if (!(Test-Path -LiteralPath $Path -PathType Leaf)) { return }
    foreach ($line in Get-Content -LiteralPath $Path) {
        if ($line -notmatch '^([A-Za-z_][A-Za-z0-9_]*)=(.*)$') { continue }
        $value = $Matches[2].Trim()
        if (($value.StartsWith('"') -and $value.EndsWith('"')) -or
            ($value.StartsWith("'") -and $value.EndsWith("'"))) {
            $value = $value.Substring(1, $value.Length - 2)
        }
        $Destination[$Matches[1]] = $value
    }
}

function New-PrivateToken {
    param([int] $Bytes = 24)
    $buffer = New-Object byte[] $Bytes
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($buffer) } finally { $rng.Dispose() }
    return ([Convert]::ToBase64String($buffer)).TrimEnd('=').Replace('+', '_').Replace('/', '-')
}

function Find-NgrokAuthtoken {
    $candidateFiles = @(
        (Join-Path $env:LOCALAPPDATA 'ngrok\ngrok.yml'),
        (Join-Path $env:USERPROFILE '.config\ngrok\ngrok.yml'),
        (Join-Path $env:USERPROFILE 'AppData\Local\ngrok\ngrok.yml'),
        (Join-Path $env:USERPROFILE '.ngrok2\ngrok.yml')
    ) | Select-Object -Unique
    foreach ($file in $candidateFiles) {
        if (!(Test-Path -LiteralPath $file -PathType Leaf)) { continue }
        $line = Get-Content -LiteralPath $file | Where-Object { $_ -match '^\s*authtoken\s*:\s*(\S+)\s*$' } | Select-Object -First 1
        if ($line -match '^\s*authtoken\s*:\s*(\S+)\s*$') { return $Matches[1].Trim('"').Trim("'") }
    }
    return ''
}

$web = @{}
Import-DotEnv -Path (Join-Path $WebRoot '.env') -Destination $web
Import-DotEnv -Path (Join-Path $WebRoot '.env.local') -Destination $web
$ai = @{}
Import-DotEnv -Path (Join-Path $AiRoot '.env') -Destination $ai

if (!$NgrokAuthtoken) { $NgrokAuthtoken = Find-NgrokAuthtoken }
if (!$NgrokAuthtoken) {
    throw 'The ngrok authtoken was not found. Pass it with -NgrokAuthtoken; it will not be printed.'
}

$copyFromWeb = @(
    'MAILER_DSN', 'MAILER_FROM',
    'STRIPE_SECRET_KEY', 'STRIPE_PUBLISHABLE_KEY', 'STRIPE_WEBHOOK_SECRET',
    'STRIPE_PRICE_BASIC_MONTHLY', 'STRIPE_PRICE_BASIC_YEARLY',
    'STRIPE_PRICE_PREMIUM_MONTHLY', 'STRIPE_PRICE_PREMIUM_YEARLY',
    'STRIPE_PRICE_PRO_MONTHLY', 'STRIPE_PRICE_PRO_YEARLY'
)
$missing = @($copyFromWeb | Where-Object { !$web.ContainsKey($_) -or [string]::IsNullOrWhiteSpace($web[$_]) })
if ($missing.Count) { throw "Local Symfony environment is missing: $($missing -join ', ')" }
if (!$ai.ContainsKey('CLOUDINARY_URL') -or [string]::IsNullOrWhiteSpace($ai.CLOUDINARY_URL)) {
    throw 'Local AI .env does not contain CLOUDINARY_URL.'
}

$values = [ordered]@{
    APP_SECRET = New-PrivateToken -Bytes 32
    DATABASE_PASSWORD = New-PrivateToken -Bytes 24
    MYSQL_ROOT_PASSWORD = ''
    NGROK_AUTHTOKEN = $NgrokAuthtoken
    NGROK_DOMAIN = ''
    SUPERVISOR_ADMIN_EMAIL = $AdminEmail
    SUPERVISOR_ADMIN_PASSWORD = New-PrivateToken -Bytes 18
    SUPERVISOR_OWNER_EMAIL = $OwnerEmail
    SUPERVISOR_OWNER_PASSWORD = New-PrivateToken -Bytes 18
    MAILER_DSN = $web.MAILER_DSN
    MAILER_FROM = $web.MAILER_FROM
    STRIPE_SECRET_KEY = $web.STRIPE_SECRET_KEY
    STRIPE_PUBLISHABLE_KEY = $web.STRIPE_PUBLISHABLE_KEY
    STRIPE_WEBHOOK_SECRET = $web.STRIPE_WEBHOOK_SECRET
    STRIPE_PRICE_BASIC_MONTHLY = $web.STRIPE_PRICE_BASIC_MONTHLY
    STRIPE_PRICE_BASIC_YEARLY = $web.STRIPE_PRICE_BASIC_YEARLY
    STRIPE_PRICE_PREMIUM_MONTHLY = $web.STRIPE_PRICE_PREMIUM_MONTHLY
    STRIPE_PRICE_PREMIUM_YEARLY = $web.STRIPE_PRICE_PREMIUM_YEARLY
    STRIPE_PRICE_PRO_MONTHLY = $web.STRIPE_PRICE_PRO_MONTHLY
    STRIPE_PRICE_PRO_YEARLY = $web.STRIPE_PRICE_PRO_YEARLY
    CLOUDINARY_UPLOAD_ENABLED = '1'
    CLOUDINARY_URL = $ai.CLOUDINARY_URL
    SCANTOSEE_MODEL_VERSION = 'trocr-menu-v1-digits-v3-checkpoint-765'
}

$lines = @(
    '# PRIVATE USB TRANSFER FILE - NEVER COMMIT OR EMAIL',
    '# Generated for the supervisor demonstration. Delete with Nuke-Personal-Data.ps1.'
)
foreach ($entry in $values.GetEnumerator()) { $lines += "$($entry.Key)=$($entry.Value)" }
[IO.File]::WriteAllLines($OutputPath, [string[]] $lines, [Text.UTF8Encoding]::new($false))

Write-Host "Created private transfer file: $OutputPath" -ForegroundColor Green
Write-Host 'It contains fresh app/database/account credentials plus all required external-service credentials.'
Write-Host 'No secret value was printed to the terminal.'
