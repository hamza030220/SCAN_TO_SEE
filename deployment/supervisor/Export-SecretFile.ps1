[CmdletBinding()]
param(
    [string] $NgrokAuthtoken = '',
    [string] $SourceDatabase = 'S2S',
    [string] $MySqlRootPassword = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$PackageRoot = $PSScriptRoot
. (Join-Path $PackageRoot 'Supervisor.Common.ps1')
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

function Export-CurrentAccount {
    param([Parameter(Mandatory)][ValidateSet('admin', 'owner')][string] $Role)

    $mysql = 'C:\xampp\mysql\bin\mysql.exe'
    if (!(Test-Path -LiteralPath $mysql -PathType Leaf)) {
        throw 'The local XAMPP MySQL client was not found at C:\xampp\mysql\bin\mysql.exe.'
    }

    # A single base64 JSON value keeps hashes, TOTP material, backup-code JSON,
    # Unicode names, and nullable dates safe without ever printing them.
    $query = @"
SELECT REPLACE(REPLACE(TO_BASE64(JSON_OBJECT(
    'email', email,
    'password', password,
    'full_name', full_name,
    'is_active', is_active,
    'created_at', created_at,
    'totp_secret', totp_secret,
    'backup_codes', backup_codes,
    'enforcement_required', enforcement_required,
    'email_verified_at', email_verified_at,
    'trial_ends_at', trial_ends_at,
    'trial_ai_uses', trial_ai_uses
)), CHAR(10), ''), CHAR(13), '')
FROM user
WHERE role = '$Role'
ORDER BY id;
"@
    $arguments = @('-u', 'root', '--batch', '--skip-column-names')
    if ($MySqlRootPassword) { $arguments += "--password=$MySqlRootPassword" }
    $arguments += @($SourceDatabase, '-e', $query)
    $rows = @(& $mysql @arguments)
    if ($LASTEXITCODE -ne 0) { throw "Could not read the current $Role account from the local database." }
    $rows = @($rows | Where-Object { ![string]::IsNullOrWhiteSpace($_) })
    if ($rows.Count -ne 1) {
        throw "Expected exactly one $Role account in database '$SourceDatabase'; found $($rows.Count)."
    }
    return [string] $rows[0]
}

function Get-AccountEmail {
    param([Parameter(Mandatory)][string] $AccountBase64)
    $json = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($AccountBase64))
    return [string] (($json | ConvertFrom-Json).email)
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

$adminAccount = Export-CurrentAccount -Role admin
$ownerAccount = Export-CurrentAccount -Role owner
$adminEmail = Get-AccountEmail -AccountBase64 $adminAccount
$ownerEmail = Get-AccountEmail -AccountBase64 $ownerAccount

$values = [ordered]@{
    APP_SECRET = New-PrivateToken -Bytes 32
    DATABASE_PASSWORD = New-PrivateToken -Bytes 24
    MYSQL_ROOT_PASSWORD = ''
    NGROK_AUTHTOKEN = $NgrokAuthtoken
    NGROK_DOMAIN = ''
    SUPERVISOR_ADMIN_EMAIL = $adminEmail
    SUPERVISOR_ADMIN_BOOTSTRAP_PASSWORD = New-PrivateToken -Bytes 18
    SUPERVISOR_ADMIN_ACCOUNT_B64 = $adminAccount
    SUPERVISOR_OWNER_EMAIL = $ownerEmail
    SUPERVISOR_OWNER_BOOTSTRAP_PASSWORD = New-PrivateToken -Bytes 18
    SUPERVISOR_OWNER_ACCOUNT_B64 = $ownerAccount
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
Write-BundleHashManifest -BundleRoot $PackageRoot -ManifestPath (Join-Path $PackageRoot 'USB-SHA256.txt')

Write-Host "Created private transfer file: $OutputPath" -ForegroundColor Green
Write-Host 'It contains the exact current admin/owner login state plus all required external-service credentials.'
Write-Host 'Use the same current passwords and authenticator entries on the supervisor machine.'
Write-Host 'No secret value was printed to the terminal.'
Write-Host 'USB-SHA256.txt was regenerated for every transferred script and model file.'
