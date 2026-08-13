[CmdletBinding()]
param(
    [string] $InstallRoot = (Join-Path $env:USERPROFILE 'ScanToSeeSupervisor'),
    [switch] $ConfirmNuke
)

. (Join-Path $PSScriptRoot 'Supervisor.Common.ps1')
$layout = Get-SupervisorLayout -InstallRoot $InstallRoot

if (!$ConfirmNuke) {
    Write-Host 'DESTRUCTIVE CLEANUP' -ForegroundColor Red
    Write-Host 'This permanently removes the supervisor demo database, accounts, uploads, logs,'
    Write-Host 'local secrets, ngrok authentication, and reachable Cloudinary/Stripe demo data.'
    Write-Host 'Git repositories and the transferred AI checkpoint are preserved.'
    $typed = Read-Host 'Type NUKE SCANTOSEE to continue'
    if ($typed -cne 'NUKE SCANTOSEE') { throw 'Cleanup cancelled; confirmation did not match.' }
}

function Remove-ValidatedDirectoryContents {
    param([Parameter(Mandatory)][string] $Directory, [Parameter(Mandatory)][string] $AllowedRoot)
    if (!(Test-Path -LiteralPath $Directory -PathType Container)) { return }
    $resolvedDirectory = [IO.Path]::GetFullPath($Directory).TrimEnd('\')
    $resolvedRoot = [IO.Path]::GetFullPath($AllowedRoot).TrimEnd('\')
    if (!$resolvedDirectory.StartsWith("$resolvedRoot\", [StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to clean a directory outside the installation: $resolvedDirectory"
    }
    Get-ChildItem -LiteralPath $resolvedDirectory -Force | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
}

function Remove-CloudinaryScanAssets {
    param([hashtable] $Secrets, [string[]] $ScanUuids)
    if (!$Secrets.ContainsKey('CLOUDINARY_URL') -or !$Secrets.CLOUDINARY_URL -or !$ScanUuids.Count) { return }
    $python = Join-Path $layout.Ai '.venv\Scripts\python.exe'
    if (!(Test-Path -LiteralPath $python -PathType Leaf)) { return }
    $env:CLOUDINARY_URL = [string] $Secrets.CLOUDINARY_URL
    $env:SCANTOSEE_SCAN_UUIDS = $ScanUuids -join ','
    $cleanupScript = Join-Path ([IO.Path]::GetTempPath()) ("scantosee-cloudinary-cleanup-{0}.py" -f [guid]::NewGuid())
    Write-Utf8File -Path $cleanupScript -Lines @(
        'import os',
        'import cloudinary',
        'import cloudinary.api',
        'cloudinary.config(secure=True)',
        'for scan_uuid in os.environ["SCANTOSEE_SCAN_UUIDS"].split(","):',
        '    if scan_uuid:',
        '        cloudinary.api.delete_resources_by_prefix(f"scantosee/menus/{scan_uuid}/", resource_type="image", invalidate=True)',
        'print("Supervisor Cloudinary scan assets removed")'
    )
    try {
        & $python $cleanupScript
        if ($LASTEXITCODE -ne 0) { Write-Warning 'Cloudinary cleanup failed; revoke/clean the Cloudinary account manually.' }
    } finally {
        Remove-Item -LiteralPath $cleanupScript -Force -ErrorAction SilentlyContinue
        Remove-Item Env:CLOUDINARY_URL -ErrorAction SilentlyContinue
        Remove-Item Env:SCANTOSEE_SCAN_UUIDS -ErrorAction SilentlyContinue
    }
}

function Remove-StripeDemoCustomers {
    param([hashtable] $Secrets)
    if (!$Secrets.ContainsKey('STRIPE_SECRET_KEY') -or [string] $Secrets.STRIPE_SECRET_KEY -notmatch '^sk_test_') {
        Write-Warning 'Stripe customer cleanup skipped because the supplied key is not a test-mode secret key.'
        return
    }
    $php = $null
    try { $php = Resolve-PhpExecutable } catch { return }
    $vendor = Join-Path $layout.Web 'vendor\autoload.php'
    if (!(Test-Path -LiteralPath $vendor -PathType Leaf)) { return }
    $env:SCANTOSEE_STRIPE_KEY = [string] $Secrets.STRIPE_SECRET_KEY
    $env:SCANTOSEE_VENDOR_AUTOLOAD = $vendor
    $demoEmails = @()
    foreach ($name in @('SUPERVISOR_ADMIN_EMAIL', 'SUPERVISOR_OWNER_EMAIL')) {
        if ($Secrets.ContainsKey($name) -and $Secrets[$name]) { $demoEmails += [string] $Secrets[$name] }
    }
    if (!$demoEmails.Count) { return }
    $env:SCANTOSEE_DEMO_EMAILS = $demoEmails -join ','
    $cleanupScript = Join-Path ([IO.Path]::GetTempPath()) ("scantosee-stripe-cleanup-{0}.php" -f [guid]::NewGuid())
    $code = @'
<?php
require getenv('SCANTOSEE_VENDOR_AUTOLOAD');
$client = new \Stripe\StripeClient(getenv('SCANTOSEE_STRIPE_KEY'));
$emails = array_filter(array_map('strtolower', explode(',', getenv('SCANTOSEE_DEMO_EMAILS'))));
foreach ($client->customers->all(['limit' => 100])->autoPagingIterator() as $customer) {
    $email = strtolower((string) ($customer->email ?? ''));
    if (in_array($email, $emails, true)) { $client->customers->delete($customer->id, []); }
}
'@
    Write-Utf8File -Path $cleanupScript -Lines @($code)
    try {
        & $php $cleanupScript
        if ($LASTEXITCODE -ne 0) { Write-Warning 'Stripe test-customer cleanup failed; inspect the Stripe test dashboard manually.' }
    } finally {
        Remove-Item -LiteralPath $cleanupScript -Force -ErrorAction SilentlyContinue
        Remove-Item Env:SCANTOSEE_STRIPE_KEY -ErrorAction SilentlyContinue
        Remove-Item Env:SCANTOSEE_VENDOR_AUTOLOAD -ErrorAction SilentlyContinue
        Remove-Item Env:SCANTOSEE_DEMO_EMAILS -ErrorAction SilentlyContinue
    }
}

$sourceSecretPath = $null
$settingsPath = Join-Path $layout.Deployment 'deployment-settings.json'
if (Test-Path -LiteralPath $settingsPath -PathType Leaf) {
    try {
        $storedSettings = Get-Content -LiteralPath $settingsPath -Raw | ConvertFrom-Json
        if ($storedSettings.sourceSecretPath) {
            $candidate = [IO.Path]::GetFullPath([string] $storedSettings.sourceSecretPath)
            if ([IO.Path]::GetFileName($candidate) -ceq 'secret.txt') { $sourceSecretPath = $candidate }
        }
    } catch { Write-Warning 'Could not read the original secret.txt location from deployment settings.' }
}

$secretCandidates = @(
    (Join-Path $PSScriptRoot 'secret.txt'),
    (Join-Path $layout.Deployment 'secret.txt'),
    (Join-Path $layout.Web '.env.local'),
    (Join-Path $layout.Ai '.env')
)
if ($sourceSecretPath) { $secretCandidates += $sourceSecretPath }
$secretCandidates = @($secretCandidates | Select-Object -Unique)
$secrets = @{}
foreach ($secretSource in $secretCandidates) {
    if (!(Test-Path -LiteralPath $secretSource -PathType Leaf)) { continue }
    $sourceValues = Read-SecretFile -Path $secretSource
    foreach ($entry in $sourceValues.GetEnumerator()) { $secrets[$entry.Key] = $entry.Value }
}

$startScript = Join-Path $layout.Deployment 'Start-ScanToSee.ps1'
if (Test-Path -LiteralPath $startScript -PathType Leaf) {
    & $startScript -Action Stop -InstallRoot $layout.Root
}

$scanUuids = @()
try {
    Start-XamppMySql
    $mysql = Resolve-MySqlExecutable
    if ($secrets.ContainsKey('DATABASE_PASSWORD') -and $secrets.DATABASE_PASSWORD) {
        $scanUuids = @(& $mysql --protocol=tcp -h 127.0.0.1 -u scantosee "--password=$($secrets.DATABASE_PASSWORD)" --batch --skip-column-names -e 'SELECT scan_uuid FROM scantosee_supervisor.scan_capture;' 2>$null)
    }
    Remove-CloudinaryScanAssets -Secrets $secrets -ScanUuids $scanUuids
    Remove-StripeDemoCustomers -Secrets $secrets
    $rootArgs = @('--protocol=tcp', '-h', '127.0.0.1', '-u', 'root')
    if ($secrets.ContainsKey('MYSQL_ROOT_PASSWORD') -and $secrets.MYSQL_ROOT_PASSWORD) {
        $rootArgs += "--password=$($secrets.MYSQL_ROOT_PASSWORD)"
    }
    $rootArgs += @('-e', "DROP DATABASE IF EXISTS scantosee_supervisor; DROP USER IF EXISTS 'scantosee'@'127.0.0.1'; FLUSH PRIVILEGES;")
    & $mysql @rootArgs
    if ($LASTEXITCODE -ne 0) { Write-Warning 'The demo database could not be destroyed automatically.' }
} catch {
    Write-Warning "Database/provider cleanup failed: $($_.Exception.Message)"
    Write-Warning 'Provider credentials should be rotated if remote cleanup could not be verified.'
}

foreach ($path in @(
    (Join-Path $layout.Web '.env'), (Join-Path $layout.Web '.env.local'),
    (Join-Path $layout.Web '.env.local.php'), (Join-Path $layout.Ai '.env'),
    (Join-Path $layout.State 'unused'), (Join-Path $layout.Root 'supervisor-state.json'),
    (Join-Path $layout.Deployment 'deployment-settings.json')
)) {
    Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue
}

foreach ($directory in @(
    (Join-Path $layout.Web 'var'), (Join-Path $layout.Root 'logs'),
    (Join-Path $layout.Ai 'data\samples'),
    (Join-Path $layout.Ai 'detected_crops')
)) { Remove-ValidatedDirectoryContents -Directory $directory -AllowedRoot $layout.Root }

# Remove generated uploads while preserving every image that belongs to the
# checked-out application. `git clean` only targets untracked files here.
if (Test-Path -LiteralPath (Join-Path $layout.Web '.git') -PathType Container) {
    & git -c "safe.directory=$($layout.Web)" -C $layout.Web clean -fd -- public/image/business public/image/items public/image/menu public/image/hero
    if ($LASTEXITCODE -ne 0) { Write-Warning 'Some generated web uploads could not be removed.' }
}

$ngrokConfigs = @(
    (Join-Path $env:LOCALAPPDATA 'ngrok\ngrok.yml'),
    (Join-Path $env:USERPROFILE '.config\ngrok\ngrok.yml'),
    (Join-Path $env:USERPROFILE '.ngrok2\ngrok.yml')
) | Select-Object -Unique
foreach ($path in $ngrokConfigs) { Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue }

$userVariables = @(
    'APP_SECRET', 'DATABASE_URL', 'MAILER_DSN', 'STRIPE_SECRET_KEY',
    'STRIPE_PUBLISHABLE_KEY', 'STRIPE_WEBHOOK_SECRET', 'CLOUDINARY_URL',
    'NGROK_AUTHTOKEN', 'OCR_CLEANUP_TOKEN'
)
foreach ($name in $userVariables) {
    [Environment]::SetEnvironmentVariable($name, $null, 'User')
    Remove-Item "Env:$name" -ErrorAction SilentlyContinue
}

foreach ($path in $secretCandidates) { Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue }

$patterns = @('sk_live_', 'sk_test_', 'whsec_', 'cloudinary://', 'authtoken:', 'gho_', 'ghp_')
$findings = @()
foreach ($root in @($layout.Web, $layout.Ai, $layout.Deployment)) {
    if (!(Test-Path -LiteralPath $root -PathType Container)) { continue }
    $files = Get-ChildItem -LiteralPath $root -Recurse -Force -File -ErrorAction SilentlyContinue | Where-Object {
        $_.FullName -notmatch '\\.git\\' -and $_.Length -lt 10MB -and $_.Extension -notin @('.bin', '.safetensors', '.png', '.jpg', '.jpeg', '.gif', '.webp', '.ico')
    }
    foreach ($file in $files) {
        foreach ($pattern in $patterns) {
            if (Select-String -LiteralPath $file.FullName -SimpleMatch $pattern -Quiet -ErrorAction SilentlyContinue) {
                $findings += $file.FullName
                break
            }
        }
    }
}

Write-Host 'Personal/demo data cleanup finished.' -ForegroundColor Green
Write-Host 'Preserved: Git repositories, installed prerequisites, Python environment, and AI checkpoint.'
if ($findings.Count) {
    Write-Warning "Possible credential-shaped text remains in: $($findings | Sort-Object -Unique | ForEach-Object { "`n - $_" })"
    Write-Warning 'Review those files and rotate provider credentials if any were ever committed or copied elsewhere.'
} else {
    Write-Host 'Post-cleanup scan found no high-confidence credential prefixes outside Git metadata.' -ForegroundColor Green
}
