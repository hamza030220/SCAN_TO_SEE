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

function Install-ComposerPhar {
    param([string] $Destination)
    if (Test-Path -LiteralPath $Destination -PathType Leaf) { return }
    $tempInstaller = Join-Path ([IO.Path]::GetTempPath()) ("composer-setup-{0}.php" -f [guid]::NewGuid())
    try {
        $expected = (Invoke-RestMethod 'https://composer.github.io/installer.sig' -TimeoutSec 30).Trim()
        Invoke-WebRequest 'https://getcomposer.org/installer' -OutFile $tempInstaller -UseBasicParsing
        $actual = (Get-FileHash -LiteralPath $tempInstaller -Algorithm SHA384).Hash.ToLowerInvariant()
        if ($actual -ne $expected.ToLowerInvariant()) { throw 'Composer installer signature verification failed.' }
        & $script:PhpExecutable $tempInstaller --install-dir=$layout.Tools --filename=composer.phar --quiet
        if ($LASTEXITCODE -ne 0) { throw 'Composer installation failed.' }
    } finally {
        Remove-Item -LiteralPath $tempInstaller -Force -ErrorAction SilentlyContinue
    }
}

function Invoke-MySql {
    param([Parameter(Mandatory)][string] $Sql, [switch] $ApplicationUser)
    $arguments = @('--protocol=tcp', '-h', '127.0.0.1', '--batch', '--skip-column-names')
    if ($ApplicationUser) {
        $arguments += @('-u', 'scantosee', "--password=$($secrets.DATABASE_PASSWORD)")
    } else {
        $arguments += @('-u', 'root')
        if ($secrets.MYSQL_ROOT_PASSWORD) { $arguments += "--password=$($secrets.MYSQL_ROOT_PASSWORD)" }
    }
    $arguments += @('-e', $Sql)
    & $script:MySqlExecutable @arguments
    if ($LASTEXITCODE -ne 0) { throw 'A MariaDB setup command failed.' }
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

# Hard preflight: do not clone, install packages, or create directories first.
Assert-CheckpointBundle
$secrets = Read-SecretFile -Path $SecretPath
Assert-RequiredSecrets -Secrets $secrets
Write-Host 'USB preflight passed: complete secret.txt and checkpoint-765 were found.' -ForegroundColor Green
if ($PreflightOnly) {
    Write-Host 'Preflight-only mode: no package, repository, database, or configuration change was made.' -ForegroundColor Cyan
    exit 0
}
Assert-Administrator

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
foreach ($extension in @('intl', 'mbstring', 'pdo_mysql', 'mysqli', 'gd', 'curl', 'openssl')) {
    Add-PhpExtension -Name $extension
}
New-Item -ItemType Directory -Path $layout.Root, $layout.Tools, $layout.Deployment -Force | Out-Null

$ngrokSource = Get-Command ngrok.exe -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source -First 1
if (!$ngrokSource) { throw 'ngrok was installed but ngrok.exe is not available in PATH. Restart PowerShell and rerun.' }
Copy-Item -LiteralPath $ngrokSource -Destination (Join-Path $layout.Tools 'ngrok.exe') -Force
Install-ComposerPhar -Destination (Join-Path $layout.Tools 'composer.phar')

if (Test-Path -LiteralPath $layout.Web -PathType Container) {
    & git -c safe.directory=$($layout.Web) -C $layout.Web fetch origin $DeploymentBranch
    if ($LASTEXITCODE -ne 0) { throw 'Could not update the Symfony repository.' }
    & git -c safe.directory=$($layout.Web) -C $layout.Web switch $DeploymentBranch
    & git -c safe.directory=$($layout.Web) -C $layout.Web reset --hard "origin/$DeploymentBranch"
} else {
    & git clone --branch $DeploymentBranch --single-branch $WebRepository $layout.Web
    if ($LASTEXITCODE -ne 0) { throw 'Could not clone the Symfony repository.' }
}
if (Test-Path -LiteralPath $layout.Ai -PathType Container) {
    & git -c safe.directory=$($layout.Ai) -C $layout.Ai fetch origin $DeploymentBranch
    if ($LASTEXITCODE -ne 0) { throw 'Could not update the AI repository.' }
    & git -c safe.directory=$($layout.Ai) -C $layout.Ai switch $DeploymentBranch
    & git -c safe.directory=$($layout.Ai) -C $layout.Ai reset --hard "origin/$DeploymentBranch"
} else {
    & git clone --branch $DeploymentBranch --single-branch $AiRepository $layout.Ai
    if ($LASTEXITCODE -ne 0) { throw 'Could not clone the AI repository.' }
}

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

$settingsJson = @{ ngrokDomain = [string] $secrets.NGROK_DOMAIN } | ConvertTo-Json
[IO.File]::WriteAllText((Join-Path $layout.Deployment 'deployment-settings.json'), $settingsJson, [Text.UTF8Encoding]::new($false))

Start-XamppMySql
$escapedPassword = ([string] $secrets.DATABASE_PASSWORD).Replace("'", "''")
Invoke-MySql -Sql "CREATE DATABASE IF NOT EXISTS scantosee_supervisor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'scantosee'@'127.0.0.1' IDENTIFIED BY '$escapedPassword'; ALTER USER 'scantosee'@'127.0.0.1' IDENTIFIED BY '$escapedPassword'; GRANT ALL PRIVILEGES ON scantosee_supervisor.* TO 'scantosee'@'127.0.0.1'; FLUSH PRIVILEGES;"

Push-Location $layout.Web
try {
    & $script:PhpExecutable (Join-Path $layout.Tools 'composer.phar') install --no-interaction --prefer-dist
    if ($LASTEXITCODE -ne 0) { throw 'Composer dependency installation failed.' }
    & $script:PhpExecutable bin/console doctrine:migrations:migrate --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Database migrations failed.' }
    & $script:PhpExecutable bin/console app:create-admin $secrets.SUPERVISOR_ADMIN_EMAIL $secrets.SUPERVISOR_ADMIN_PASSWORD
    if ($LASTEXITCODE -ne 0) { throw 'Supervisor admin creation failed.' }
    & $script:PhpExecutable bin/console app:seed-owner $secrets.SUPERVISOR_OWNER_EMAIL $secrets.SUPERVISOR_OWNER_PASSWORD
    if ($LASTEXITCODE -ne 0) { throw 'Supervisor owner creation failed.' }
} finally { Pop-Location }

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
& $venvPython -m pip install -r $runtimeRequirements
if ($LASTEXITCODE -ne 0) { throw 'OCR dependency installation failed.' }
Remove-Item -LiteralPath $runtimeRequirements -Force -ErrorAction SilentlyContinue

$device = & $venvPython -c "import torch; print('cuda' if torch.cuda.is_available() else 'cpu')"
Write-Host "PyTorch inference device: $device" -ForegroundColor Cyan
& (Join-Path $layout.Tools 'ngrok.exe') config add-authtoken $secrets.NGROK_AUTHTOKEN | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'ngrok authentication failed.' }

& (Join-Path $layout.Deployment 'Start-ScanToSee.ps1') -InstallRoot $layout.Root

Write-Host ''
Write-Host 'Installation completed successfully.' -ForegroundColor Green
Write-Host "Admin login: $($secrets.SUPERVISOR_ADMIN_EMAIL)"
Write-Host "Owner login: $($secrets.SUPERVISOR_OWNER_EMAIL)"
Write-Host 'Passwords remain only in the USB secret.txt; they were not copied to documentation or logs.'
Write-Host 'Remove the USB drive now and keep it secure.' -ForegroundColor Yellow
