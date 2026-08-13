[CmdletBinding()]
param(
    [string] $InstallRoot = (Join-Path $env:USERPROFILE 'ScanToSeeSupervisor'),
    [string] $WebRepository = 'https://github.com/hamza030220/SCAN_TO_SEE.git',
    [string] $AiRepository = 'https://github.com/hamza030220/Handwritten-Menu-Scanner_V2.git',
    [string] $DeploymentBranch = 'agent/supervisor-deployment',
    [switch] $PreflightOnly
)

. (Join-Path $PSScriptRoot 'Supervisor.Common.ps1')

$BundleRoot = $PSScriptRoot
$SecretPath = Join-Path $BundleRoot 'secret.txt'
$HashManifestPath = Join-Path $BundleRoot 'USB-SHA256.txt'
$CheckpointSource = Join-Path $BundleRoot 'checkpoint-765'
$layout = Get-SupervisorLayout -InstallRoot $InstallRoot

function Assert-CheckpointBundle {
    # Include processor/tokenizer metadata in the USB bundle so TrOCR model
    # loading never depends on a first-run Hugging Face download.
    $required = @(
        'config.json', 'generation_config.json', 'preprocessor_config.json',
        'special_tokens_map.json', 'tokenizer.json', 'tokenizer_config.json',
        'vocab.json', 'merges.txt'
    )
    if (!(Test-Path -LiteralPath $CheckpointSource -PathType Container)) {
        throw "Required model directory was not found beside the installer: $CheckpointSource"
    }
    $missing = @($required | Where-Object { !(Test-Path -LiteralPath (Join-Path $CheckpointSource $_) -PathType Leaf) })
    $weights = @(Get-ChildItem -LiteralPath $CheckpointSource -File | Where-Object {
        $_.Extension -in @('.safetensors', '.bin', '.pt', '.pth') -and $_.Length -gt 1GB
    })
    if ($missing.Count -or !$weights.Count) {
        $details = if ($missing.Count) { " Missing files: $($missing -join ', ')." } else { '' }
        throw "checkpoint-765 is incomplete; a model weight file larger than 1 GiB is also required.$details"
    }
}

function Assert-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)
    if (!$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Run PowerShell as Administrator. Prerequisite installation and MariaDB setup require elevation.'
    }
}

function Install-WinGetPackage {
    param([Parameter(Mandatory)][string] $Id, [Parameter(Mandatory)][string] $DisplayName)
    Write-Host "Ensuring $DisplayName is installed..." -ForegroundColor Cyan
    & winget install --id $Id --exact --silent --accept-package-agreements --accept-source-agreements --disable-interactivity
    if ($LASTEXITCODE -notin @(0, -1978335189)) {
        throw "WinGet could not install $DisplayName (exit code $LASTEXITCODE)."
    }
    Refresh-ProcessPath
}

function Install-BundledComposer {
    param(
        [Parameter(Mandatory)][string] $Source,
        [Parameter(Mandatory)][string] $Destination
    )
    if (!(Test-Path -LiteralPath $Source -PathType Leaf)) {
        throw "The verified bundled Composer executable is missing: $Source"
    }
    New-Item -ItemType Directory -Path (Split-Path -Parent $Destination) -Force | Out-Null
    Copy-Item -LiteralPath $Source -Destination $Destination -Force
    & $script:PhpExecutable $Destination --version --no-ansi
    if ($LASTEXITCODE -ne 0) { throw 'The bundled Composer executable could not run.' }
}

function Invoke-MySql {
    param([Parameter(Mandatory)][string] $Sql, [switch] $ApplicationUser, [switch] $PassThru)
    $arguments = @('--protocol=tcp', '-h', '127.0.0.1', '--batch', '--skip-column-names')
    if ($ApplicationUser) {
        $arguments += @('-u', 'scantosee', "--password=$($secrets.DATABASE_PASSWORD)")
    } else {
        $arguments += @('-u', 'root')
        if ($secrets.MYSQL_ROOT_PASSWORD) { $arguments += "--password=$($secrets.MYSQL_ROOT_PASSWORD)" }
    }
    $arguments += @('-e', $Sql)
    $output = @(& $script:MySqlExecutable @arguments)
    if ($LASTEXITCODE -ne 0) { throw 'A MariaDB setup command failed.' }
    if ($PassThru) { return $output }
}

function Sync-GitRepository {
    param(
        [Parameter(Mandatory)][string] $Repository,
        [Parameter(Mandatory)][string] $Destination,
        [Parameter(Mandatory)][string] $Branch,
        [Parameter(Mandatory)][string] $DisplayName
    )

    $resolvedRoot = [IO.Path]::GetFullPath($layout.Root).TrimEnd('\')
    $resolvedDestination = [IO.Path]::GetFullPath($Destination).TrimEnd('\')
    if (!$resolvedDestination.StartsWith("$resolvedRoot\", [StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to manage $DisplayName outside the installation root: $resolvedDestination"
    }
    if (Test-Path -LiteralPath $resolvedDestination -PathType Container) {
        if (!(Test-Path -LiteralPath (Join-Path $resolvedDestination '.git') -PathType Container)) {
            Write-Warning "Removing an incomplete previous $DisplayName clone: $resolvedDestination"
            Remove-Item -LiteralPath $resolvedDestination -Recurse -Force
        } else {
            & git -c "safe.directory=$resolvedDestination" -C $resolvedDestination fetch origin $Branch
            if ($LASTEXITCODE -ne 0) { throw "Could not fetch the $DisplayName deployment branch." }
            & git -c "safe.directory=$resolvedDestination" -C $resolvedDestination switch $Branch
            if ($LASTEXITCODE -ne 0) { throw "Could not switch the $DisplayName deployment branch." }
            & git -c "safe.directory=$resolvedDestination" -C $resolvedDestination reset --hard "origin/$Branch"
            if ($LASTEXITCODE -ne 0) { throw "Could not reset $DisplayName to the tested remote branch." }
            return
        }
    }
    & git clone --branch $Branch --single-branch --depth 1 $Repository $resolvedDestination
    if ($LASTEXITCODE -ne 0) { throw "Could not clone $DisplayName from $Repository." }
}

function Import-CurrentAccount {
    param(
        [Parameter(Mandatory)][ValidateSet('admin', 'owner')][string] $Role,
        [Parameter(Mandatory)][string] $AccountBase64
    )

    if ($AccountBase64 -notmatch '^[A-Za-z0-9+/]+={0,2}$') {
        throw "The transferred $Role account is not valid base64 data. Recreate secret.txt."
    }
    $accountVariable = if ($Role -eq 'admin') { '@admin_account' } else { '@owner_account' }
    # This is intentionally one UPDATE per account. JSON extraction restores
    # the exact local email, password hash, name, verification, trial, and 2FA
    # state while leaving reset tokens unset on the copied machine.
    $sql = @"
SET $accountVariable = CONVERT(FROM_BASE64('$AccountBase64') USING utf8mb4);
UPDATE scantosee_supervisor.user SET
    email = JSON_UNQUOTE(JSON_EXTRACT($accountVariable, '$.email')),
    password = JSON_UNQUOTE(JSON_EXTRACT($accountVariable, '$.password')),
    full_name = JSON_UNQUOTE(JSON_EXTRACT($accountVariable, '$.full_name')),
    is_active = CAST(JSON_UNQUOTE(JSON_EXTRACT($accountVariable, '$.is_active')) AS UNSIGNED),
    created_at = JSON_UNQUOTE(JSON_EXTRACT($accountVariable, '$.created_at')),
    totp_secret = NULLIF(JSON_UNQUOTE(JSON_EXTRACT($accountVariable, '$.totp_secret')), 'null'),
    backup_codes = NULLIF(JSON_UNQUOTE(JSON_EXTRACT($accountVariable, '$.backup_codes')), 'null'),
    enforcement_required = CAST(JSON_UNQUOTE(JSON_EXTRACT($accountVariable, '$.enforcement_required')) AS UNSIGNED),
    email_verified_at = NULLIF(JSON_UNQUOTE(JSON_EXTRACT($accountVariable, '$.email_verified_at')), 'null'),
    trial_ends_at = NULLIF(JSON_UNQUOTE(JSON_EXTRACT($accountVariable, '$.trial_ends_at')), 'null'),
    trial_ai_uses = CAST(JSON_UNQUOTE(JSON_EXTRACT($accountVariable, '$.trial_ai_uses')) AS UNSIGNED),
    password_reset_token = NULL,
    password_reset_token_expires_at = NULL,
    email_verification_token_hash = NULL,
    email_verification_expires_at = NULL
WHERE role = '$Role' AND email = JSON_UNQUOTE(JSON_EXTRACT($accountVariable, '$.email'));
"@
    Invoke-MySql -Sql $sql -ApplicationUser
}

function Add-PhpExtension {
    param([Parameter(Mandatory)][string] $Name)
    $extensionDirectory = Join-Path (Split-Path -Parent $script:PhpExecutable) 'ext'
    $dll = Join-Path $extensionDirectory "php_$Name.dll"
    if (!(Test-Path -LiteralPath $dll -PathType Leaf)) { throw "Required PHP extension file is missing: $dll" }
    $ini = Join-Path (Split-Path -Parent $script:PhpExecutable) 'php.ini'
    $contents = Get-Content -LiteralPath $ini -Raw
    if ($contents -match "(?m)^\s*extension\s*=\s*(?:php_)?$([regex]::Escape($Name))(?:\.dll)?\s*$") { return }
    if ($contents -match "(?m)^\s*;\s*extension\s*=\s*(?:php_)?$([regex]::Escape($Name))(?:\.dll)?\s*$") {
        $pattern = "(?m)^\s*;\s*extension\s*=\s*(?:php_)?$([regex]::Escape($Name))(?:\.dll)?\s*$"
        $regex = [regex]::new($pattern)
        $contents = $regex.Replace($contents, "extension=$Name", 1)
        [IO.File]::WriteAllText($ini, $contents, [Text.UTF8Encoding]::new($false))
    } else {
        [IO.File]::AppendAllText($ini, "`r`nextension=$Name", [Text.UTF8Encoding]::new($false))
    }
}

function Set-PhpIniPathSetting {
    param(
        [Parameter(Mandatory)][string] $Name,
        [Parameter(Mandatory)][string] $Value
    )
    $ini = Join-Path (Split-Path -Parent $script:PhpExecutable) 'php.ini'
    $contents = Get-Content -LiteralPath $ini -Raw
    $portableValue = $Value.Replace('\', '/')
    $setting = "$Name=`"$portableValue`""
    $pattern = "(?m)^\s*;?\s*$([regex]::Escape($Name))\s*=.*$"
    if ($contents -match $pattern) {
        $contents = [regex]::Replace($contents, $pattern, $setting)
    } else {
        $contents += "`r`n$setting`r`n"
    }
    [IO.File]::WriteAllText($ini, $contents, [Text.UTF8Encoding]::new($false))
}

function New-PhpCertificateBundle {
    param(
        [Parameter(Mandatory)][string] $MozillaBundle,
        [Parameter(Mandatory)][string] $Destination
    )
    $builder = [Text.StringBuilder]::new()
    [void] $builder.Append((Get-Content -LiteralPath $MozillaBundle -Raw))
    if ($builder.Length -and $builder[$builder.Length - 1] -ne "`n") { [void] $builder.AppendLine() }

    # Corporate/school networks can terminate TLS with a root trusted by
    # Windows but absent from Mozilla's generic bundle. Preserve Mozilla's
    # roots and append the roots trusted by this exact Windows installation.
    $seen = @{}
    $windowsRootCount = 0
    foreach ($store in @('Cert:\LocalMachine\Root', 'Cert:\CurrentUser\Root')) {
        foreach ($certificate in @(Get-ChildItem -Path $store -ErrorAction Stop)) {
            if ($seen.ContainsKey($certificate.Thumbprint)) { continue }
            $seen[$certificate.Thumbprint] = $true
            $base64 = [Convert]::ToBase64String($certificate.RawData)
            [void] $builder.AppendLine('-----BEGIN CERTIFICATE-----')
            for ($offset = 0; $offset -lt $base64.Length; $offset += 64) {
                [void] $builder.AppendLine($base64.Substring($offset, [Math]::Min(64, $base64.Length - $offset)))
            }
            [void] $builder.AppendLine('-----END CERTIFICATE-----')
            $windowsRootCount++
        }
    }
    if (!$windowsRootCount) { throw 'No trusted root certificates could be read from Windows.' }
    [IO.File]::WriteAllText($Destination, $builder.ToString(), [Text.UTF8Encoding]::new($false))
    Write-Host "Configured PHP with Mozilla certificates plus $windowsRootCount Windows trusted roots."
}

# Hard preflight: do not clone, install packages, or create directories first.
Assert-BundleHashManifest -BundleRoot $BundleRoot -ManifestPath $HashManifestPath
Assert-CheckpointBundle
$secrets = Read-SecretFile -Path $SecretPath
Assert-RequiredSecrets -Secrets $secrets
Write-Host 'USB preflight passed: the complete secret, model, Composer, CA, and script bundle was verified.' -ForegroundColor Green
if ($PreflightOnly) {
    Write-Host 'Preflight-only mode: no package, repository, database, or configuration change was made.' -ForegroundColor Cyan
    exit 0
}
Assert-Administrator

$installDrive = [IO.DriveInfo]::new([IO.Path]::GetPathRoot($layout.Root))
if ($installDrive.AvailableFreeSpace -lt 15GB) {
    throw "At least 15 GiB of free space is required on $($installDrive.Name); only $([math]::Round($installDrive.AvailableFreeSpace / 1GB, 1)) GiB is available."
}
$windowsVersion = [Environment]::OSVersion.Version
if ($windowsVersion.Major -lt 10) { throw 'Windows 10 or Windows 11 is required.' }

$winget = Get-Command winget.exe -ErrorAction SilentlyContinue
if (!$winget) { throw 'Windows Package Manager (winget) is required. Install Microsoft App Installer and rerun.' }

if (!(Get-Command git.exe -ErrorAction SilentlyContinue)) { Install-WinGetPackage -Id 'Git.Git' -DisplayName 'Git' }
if (!(Get-Command ngrok.exe -ErrorAction SilentlyContinue)) { Install-WinGetPackage -Id 'Ngrok.Ngrok' -DisplayName 'ngrok' }
if (!(Test-Path -LiteralPath 'C:\xampp\php\php.exe')) {
    Install-WinGetPackage -Id 'ApacheFriends.Xampp.8.2' -DisplayName 'XAMPP 8.2 (PHP and MariaDB)'
}
try { $null = Resolve-PythonExecutable } catch {
    Install-WinGetPackage -Id 'Python.Python.3.10' -DisplayName 'Python 3.10'
}

$script:PhpExecutable = Resolve-PhpExecutable
$python = Resolve-PythonExecutable
$script:MySqlExecutable = Resolve-MySqlExecutable
$phpVersion = & $script:PhpExecutable -r 'echo PHP_MAJOR_VERSION * 100 + PHP_MINOR_VERSION;'
if ($LASTEXITCODE -ne 0 -or [int] $phpVersion -lt 802 -or [int] $phpVersion -ge 900) {
    throw "PHP 8.2 or newer in the PHP 8 series is required; found version code '$phpVersion'."
}
foreach ($extension in @('intl', 'mbstring', 'pdo_mysql', 'mysqli', 'gd', 'curl', 'openssl', 'zip')) {
    Add-PhpExtension -Name $extension
}
# Re-read php.ini after enabling extensions; this must happen before any
# Composer or Symfony command so a half-configured XAMPP install fails early.
$loadedExtensionsJson = & $script:PhpExecutable -r 'echo json_encode(get_loaded_extensions());'
if ($LASTEXITCODE -ne 0) { throw 'PHP could not report its loaded extensions.' }
$loadedExtensionSet = @($loadedExtensionsJson | ConvertFrom-Json | ForEach-Object { $_.ToLowerInvariant() })
$missingLoadedExtensions = @(@('intl', 'mbstring', 'pdo_mysql', 'mysqli', 'gd', 'curl', 'openssl', 'zip') |
    Where-Object { $_ -notin $loadedExtensionSet })
if ($missingLoadedExtensions.Count) { throw "PHP extensions did not load: $($missingLoadedExtensions -join ', ')." }
New-Item -ItemType Directory -Path $layout.Root, $layout.Tools, $layout.Deployment -Force | Out-Null
$caDestination = Join-Path $layout.Tools 'cacert-combined.pem'
New-PhpCertificateBundle -MozillaBundle (Join-Path $BundleRoot 'cacert.pem') -Destination $caDestination
Set-PhpIniPathSetting -Name 'openssl.cafile' -Value $caDestination
Set-PhpIniPathSetting -Name 'curl.cainfo' -Value $caDestination
$activeCaFile = & $script:PhpExecutable -r "echo ini_get('openssl.cafile');"
if ($LASTEXITCODE -ne 0 -or !(Test-Path -LiteralPath $activeCaFile -PathType Leaf)) {
    throw 'PHP did not load the bundled certificate-authority file.'
}
$existingLauncher = Join-Path $layout.Deployment 'Start-ScanToSee.ps1'
if (Test-Path -LiteralPath $existingLauncher -PathType Leaf) {
    & $existingLauncher -Action Stop -InstallRoot $layout.Root
}

$ngrokSource = Get-Command ngrok.exe -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source -First 1
if (!$ngrokSource) { throw 'ngrok was installed but ngrok.exe is not available in PATH. Restart PowerShell and rerun.' }
Copy-Item -LiteralPath $ngrokSource -Destination (Join-Path $layout.Tools 'ngrok.exe') -Force
Install-BundledComposer -Source (Join-Path $BundleRoot 'composer.phar') -Destination (Join-Path $layout.Tools 'composer.phar')

Sync-GitRepository -Repository $WebRepository -Destination $layout.Web -Branch $DeploymentBranch -DisplayName 'Symfony repository'
Sync-GitRepository -Repository $AiRepository -Destination $layout.Ai -Branch $DeploymentBranch -DisplayName 'FastAPI repository'

Copy-Item -LiteralPath (Join-Path $BundleRoot 'Supervisor.Common.ps1') -Destination $layout.Deployment -Force
Copy-Item -LiteralPath (Join-Path $BundleRoot 'Start-ScanToSee.ps1') -Destination $layout.Deployment -Force
Copy-Item -LiteralPath (Join-Path $BundleRoot 'Nuke-Personal-Data.ps1') -Destination $layout.Deployment -Force

$checkpointDestination = Join-Path $layout.Ai 'models\trocr_menu_v1_digits_v3\checkpoints\checkpoint-765'
New-Item -ItemType Directory -Path $checkpointDestination -Force | Out-Null
Get-ChildItem -LiteralPath $CheckpointSource -Force | Copy-Item -Destination $checkpointDestination -Recurse -Force
# Training-only state is large and unnecessary for inference. The supervisor
# copy keeps model.safetensors plus config/tokenizer/processor metadata only.
foreach ($trainingOnlyFile in @('optimizer.pt', 'scheduler.pt', 'rng_state.pth', 'trainer_state.json', 'training_args.bin')) {
    Remove-Item -LiteralPath (Join-Path $checkpointDestination $trainingOnlyFile) -Force -ErrorAction SilentlyContinue
}

$webBaseEnv = Join-Path $layout.Web '.env'
$webEnv = Join-Path $layout.Web '.env.local'
$databaseUrl = "mysql://scantosee:$($secrets.DATABASE_PASSWORD)@127.0.0.1:3306/scantosee_supervisor?serverVersion=10.4.32-MariaDB&charset=utf8mb4"
$webValues = [ordered]@{
    APP_ENV = 'prod'; APP_DEBUG = '0'; APP_SECRET = $secrets.APP_SECRET
    DATABASE_URL = $databaseUrl; MESSENGER_TRANSPORT_DSN = 'doctrine://default?auto_setup=0'
    MAILER_DSN = $secrets.MAILER_DSN; MAILER_FROM = $secrets.MAILER_FROM
    MAILER_BASE_URL = 'http://127.0.0.1:8000'; PUBLIC_BASE_URL = 'http://127.0.0.1:8000'
    OCR_PIPELINE_URL = 'http://127.0.0.1:8001'; LOCK_DSN = 'flock'
    STRIPE_SECRET_KEY = $secrets.STRIPE_SECRET_KEY
    STRIPE_PUBLISHABLE_KEY = $secrets.STRIPE_PUBLISHABLE_KEY
    STRIPE_WEBHOOK_SECRET = $secrets.STRIPE_WEBHOOK_SECRET
    STRIPE_PRICE_BASIC_MONTHLY = $secrets.STRIPE_PRICE_BASIC_MONTHLY
    STRIPE_PRICE_BASIC_YEARLY = $secrets.STRIPE_PRICE_BASIC_YEARLY
    STRIPE_PRICE_PREMIUM_MONTHLY = $secrets.STRIPE_PRICE_PREMIUM_MONTHLY
    STRIPE_PRICE_PREMIUM_YEARLY = $secrets.STRIPE_PRICE_PREMIUM_YEARLY
    STRIPE_PRICE_PRO_MONTHLY = $secrets.STRIPE_PRICE_PRO_MONTHLY
    STRIPE_PRICE_PRO_YEARLY = $secrets.STRIPE_PRICE_PRO_YEARLY
}
Write-Utf8File -Path $webBaseEnv -Lines @(
    '# Generated by the supervisor installer. Private; never commit.',
    'APP_ENV=prod',
    'APP_DEBUG=0'
)
Write-Utf8File -Path $webEnv -Lines @('# Generated by the supervisor installer. Private; never commit.')
foreach ($entry in $webValues.GetEnumerator()) { Set-DotEnvValue -Path $webEnv -Name $entry.Key -Value ([string] $entry.Value) }

$aiEnv = Join-Path $layout.Ai '.env'
Write-Utf8File -Path $aiEnv -Lines @('# Generated by the supervisor installer. Private; never commit.')
Set-DotEnvValue -Path $aiEnv -Name 'CLOUDINARY_UPLOAD_ENABLED' -Value $(if ($secrets.ContainsKey('CLOUDINARY_UPLOAD_ENABLED')) { [string] $secrets.CLOUDINARY_UPLOAD_ENABLED } else { '1' })
Set-DotEnvValue -Path $aiEnv -Name 'CLOUDINARY_URL' -Value $secrets.CLOUDINARY_URL
Set-DotEnvValue -Path $aiEnv -Name 'OCR_CLEANUP_TOKEN' -Value $secrets.APP_SECRET
Set-DotEnvValue -Path $aiEnv -Name 'SCANTOSEE_MODEL_VERSION' -Value $secrets.SCANTOSEE_MODEL_VERSION
Set-DotEnvValue -Path $aiEnv -Name 'SCANTOSEE_MODEL_CHECKPOINT' -Value $checkpointDestination
Set-DotEnvValue -Path $aiEnv -Name 'SCANTOSEE_TORCH_DEVICE' -Value 'auto'

$settingsJson = @{
    ngrokDomain = [string] $secrets.NGROK_DOMAIN
    sourceSecretPath = [IO.Path]::GetFullPath($SecretPath)
} | ConvertTo-Json
[IO.File]::WriteAllText((Join-Path $layout.Deployment 'deployment-settings.json'), $settingsJson, [Text.UTF8Encoding]::new($false))

Start-XamppMySql
$databaseVersion = @(Invoke-MySql -Sql 'SELECT VERSION();' -PassThru)
if ($databaseVersion.Count -ne 1 -or [string] $databaseVersion[0] -notmatch 'MariaDB') {
    throw "Port 3306 is not the expected MariaDB service. Reported version: $($databaseVersion -join ' ')"
}
$escapedPassword = ([string] $secrets.DATABASE_PASSWORD).Replace("'", "''")
# Installation owns this dedicated database. Rebuilding it on every attempt
# guarantees that a retry after interrupted migrations/seeding starts clean.
Invoke-MySql -Sql "DROP DATABASE IF EXISTS scantosee_supervisor; CREATE DATABASE scantosee_supervisor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'scantosee'@'127.0.0.1' IDENTIFIED BY '$escapedPassword'; ALTER USER 'scantosee'@'127.0.0.1' IDENTIFIED BY '$escapedPassword'; GRANT ALL PRIVILEGES ON scantosee_supervisor.* TO 'scantosee'@'127.0.0.1'; FLUSH PRIVILEGES;"

Push-Location $layout.Web
try {
    & $script:PhpExecutable (Join-Path $layout.Tools 'composer.phar') install --no-interaction --prefer-dist
    if ($LASTEXITCODE -ne 0) { throw 'Composer dependency installation failed.' }
    & $script:PhpExecutable bin/console doctrine:migrations:migrate --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Database migrations failed.' }
    & $script:PhpExecutable bin/console app:create-admin $secrets.SUPERVISOR_ADMIN_EMAIL $secrets.SUPERVISOR_ADMIN_BOOTSTRAP_PASSWORD
    if ($LASTEXITCODE -ne 0) { throw 'Supervisor admin creation failed.' }
    & $script:PhpExecutable bin/console app:seed-owner $secrets.SUPERVISOR_OWNER_EMAIL $secrets.SUPERVISOR_OWNER_BOOTSTRAP_PASSWORD
    if ($LASTEXITCODE -ne 0) { throw 'Supervisor owner creation failed.' }
} finally { Pop-Location }

# Preserve the two current accounts exactly, including their valid email,
# password hash, email-verification status, and existing TOTP enrollment.
Import-CurrentAccount -Role admin -AccountBase64 $secrets.SUPERVISOR_ADMIN_ACCOUNT_B64
Import-CurrentAccount -Role owner -AccountBase64 $secrets.SUPERVISOR_OWNER_ACCOUNT_B64

$venvPython = Join-Path $layout.Ai '.venv\Scripts\python.exe'
if (!(Test-Path -LiteralPath $venvPython)) {
    & $python -m venv (Join-Path $layout.Ai '.venv')
    if ($LASTEXITCODE -ne 0) { throw 'Python virtual environment creation failed.' }
}
& $venvPython -m pip install --upgrade pip
if ($LASTEXITCODE -ne 0) { throw 'pip upgrade failed.' }

$hasNvidia = $null -ne (Get-Command nvidia-smi.exe -ErrorAction SilentlyContinue)
$torchIndex = if ($hasNvidia) { 'https://download.pytorch.org/whl/cu124' } else { 'https://download.pytorch.org/whl/cpu' }
& $venvPython -m pip install 'torch==2.5.1' --index-url $torchIndex
if ($LASTEXITCODE -ne 0 -and $hasNvidia) {
    Write-Warning 'CUDA PyTorch installation failed; installing the official CPU wheel.'
    & $venvPython -m pip install --force-reinstall 'torch==2.5.1' --index-url 'https://download.pytorch.org/whl/cpu'
}
if ($LASTEXITCODE -ne 0) { throw 'PyTorch installation failed for both CUDA and CPU.' }
# Install everything except torch so pip cannot replace the selected official
# CUDA/CPU build with a wheel from the default package index.
$runtimeRequirements = Join-Path $layout.Ai 'requirements.runtime.txt'
$runtimeLines = @(Get-Content -LiteralPath (Join-Path $layout.Ai 'requirements.txt') |
    Where-Object { $_ -notmatch '^\s*torch\s*==' })
Write-Utf8File -Path $runtimeRequirements -Lines $runtimeLines
try {
    & $venvPython -m pip install -r $runtimeRequirements
    if ($LASTEXITCODE -ne 0) { throw 'OCR dependency installation failed.' }
} finally {
    Remove-Item -LiteralPath $runtimeRequirements -Force -ErrorAction SilentlyContinue
}

$env:SCANTOSEE_MODEL_CHECKPOINT = $checkpointDestination
$env:SCANTOSEE_TORCH_DEVICE = 'auto'
try {
    Push-Location (Join-Path $layout.Ai 'src')
    try {
        $verifiedDevice = & $venvPython -c 'from recognition import _get_model;from detection import _get_detector;p,m,d=_get_model();_get_detector();print(d)'
        if ($LASTEXITCODE -ne 0 -or [string] $verifiedDevice -notin @('cpu', 'cuda')) {
            throw 'OCR model smoke test failed. The TrOCR and Paddle detection models must both load before installation can finish.'
        }
    } finally { Pop-Location }
} finally {
    Remove-Item Env:SCANTOSEE_MODEL_CHECKPOINT -ErrorAction SilentlyContinue
    Remove-Item Env:SCANTOSEE_TORCH_DEVICE -ErrorAction SilentlyContinue
}
Write-Host "OCR model smoke test passed: TrOCR and Paddle detection are ready on $verifiedDevice." -ForegroundColor Green
& (Join-Path $layout.Tools 'ngrok.exe') config add-authtoken $secrets.NGROK_AUTHTOKEN | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'ngrok authentication failed.' }

& (Join-Path $layout.Deployment 'Start-ScanToSee.ps1') -InstallRoot $layout.Root

Write-Host ''
Write-Host 'Installation completed successfully.' -ForegroundColor Green
Write-Host "Admin login: $($secrets.SUPERVISOR_ADMIN_EMAIL)"
Write-Host "Owner login: $($secrets.SUPERVISOR_OWNER_EMAIL)"
Write-Host 'Use the same passwords and TOTP authenticator entries as on the current machine.'
Write-Host 'Account hashes and 2FA secrets remain only in the USB secret.txt; they were not copied to documentation or logs.'
Write-Host 'Remove the USB drive now and keep it secure.' -ForegroundColor Yellow
