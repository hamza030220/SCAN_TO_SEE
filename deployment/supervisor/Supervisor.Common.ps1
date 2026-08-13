Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Read-SecretFile {
    param([Parameter(Mandatory)][string] $Path)

    if (!(Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "Required secret file was not found: $Path"
    }

    $values = @{}
    foreach ($line in Get-Content -LiteralPath $Path) {
        $trimmed = $line.Trim()
        if (!$trimmed -or $trimmed.StartsWith('#')) { continue }
        $parts = $trimmed -split '=', 2
        if ($parts.Count -ne 2) { throw "Invalid secret.txt line (expected NAME=value): $trimmed" }
        $value = $parts[1].Trim()
        if ($value.Length -ge 2 -and $value.StartsWith('"') -and $value.EndsWith('"')) {
            $value = $value.Substring(1, $value.Length - 2)
            $value = $value.Replace('\$', '$').Replace('\"', '"').Replace('\\', '\')
        } elseif ($value.Length -ge 2 -and $value.StartsWith("'") -and $value.EndsWith("'")) {
            $value = $value.Substring(1, $value.Length - 2)
        }
        $values[$parts[0].Trim()] = $value
    }
    return $values
}

function Assert-RequiredSecrets {
    param([Parameter(Mandatory)][hashtable] $Secrets)

    $required = @(
        'APP_SECRET', 'DATABASE_PASSWORD', 'NGROK_AUTHTOKEN',
        'SUPERVISOR_ADMIN_EMAIL', 'SUPERVISOR_ADMIN_BOOTSTRAP_PASSWORD',
        'SUPERVISOR_ADMIN_ACCOUNT_B64',
        'SUPERVISOR_OWNER_EMAIL', 'SUPERVISOR_OWNER_BOOTSTRAP_PASSWORD',
        'SUPERVISOR_OWNER_ACCOUNT_B64',
        'MAILER_DSN', 'MAILER_FROM',
        'STRIPE_SECRET_KEY', 'STRIPE_PUBLISHABLE_KEY', 'STRIPE_WEBHOOK_SECRET',
        'STRIPE_PRICE_BASIC_MONTHLY', 'STRIPE_PRICE_BASIC_YEARLY',
        'STRIPE_PRICE_PREMIUM_MONTHLY', 'STRIPE_PRICE_PREMIUM_YEARLY',
        'STRIPE_PRICE_PRO_MONTHLY', 'STRIPE_PRICE_PRO_YEARLY',
        'SCANTOSEE_MODEL_VERSION'
    )

    $missing = @($required | Where-Object {
        !$Secrets.ContainsKey($_) -or
        [string]::IsNullOrWhiteSpace([string] $Secrets[$_]) -or
        [string] $Secrets[$_] -match '^(CHANGE_ME|REQUIRED|TODO)$'
    })
    if ($missing.Count) {
        throw "secret.txt is incomplete. Missing values: $($missing -join ', ')"
    }
    if ([string] $Secrets.DATABASE_PASSWORD -notmatch '^[A-Za-z0-9_-]{16,128}$') {
        throw 'DATABASE_PASSWORD must contain 16-128 letters, digits, underscores, or hyphens.'
    }
    foreach ($name in 'SUPERVISOR_ADMIN_BOOTSTRAP_PASSWORD', 'SUPERVISOR_OWNER_BOOTSTRAP_PASSWORD') {
        if ([string] $Secrets[$name] -notmatch '^.{12,128}$') {
            throw "$name must contain 12-128 characters."
        }
    }
    foreach ($role in 'ADMIN', 'OWNER') {
        $encodedName = "SUPERVISOR_${role}_ACCOUNT_B64"
        try {
            $json = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String([string] $Secrets[$encodedName]))
            $account = $json | ConvertFrom-Json
            foreach ($property in 'email', 'password', 'full_name', 'is_active', 'created_at', 'enforcement_required', 'trial_ai_uses') {
                if ($null -eq $account.$property) { throw "missing property '$property'" }
            }
            $emailName = "SUPERVISOR_${role}_EMAIL"
            if ([string] $account.email -cne [string] $Secrets[$emailName]) {
                throw "email does not match $emailName"
            }
        } catch {
            throw "$encodedName is invalid. Recreate secret.txt with Export-SecretFile.ps1."
        }
    }
    if ($Secrets.ContainsKey('CLOUDINARY_UPLOAD_ENABLED') -and
        [string] $Secrets.CLOUDINARY_UPLOAD_ENABLED -notin @('0', '1')) {
        throw 'CLOUDINARY_UPLOAD_ENABLED must be 0 or 1.'
    }
    if ((!$Secrets.ContainsKey('CLOUDINARY_UPLOAD_ENABLED') -or [string] $Secrets.CLOUDINARY_UPLOAD_ENABLED -eq '1') -and
        (!$Secrets.ContainsKey('CLOUDINARY_URL') -or [string]::IsNullOrWhiteSpace([string] $Secrets.CLOUDINARY_URL))) {
        throw 'CLOUDINARY_URL is required when CLOUDINARY_UPLOAD_ENABLED is 1.'
    }
}

function ConvertTo-DotEnvValue {
    param([AllowEmptyString()][string] $Value)
    if ($Value.Contains("`r") -or $Value.Contains("`n")) {
        throw 'Environment values containing line breaks are not supported.'
    }
    $escaped = $Value.Replace('\', '\\').Replace('$', '\$').Replace('"', '\"')
    return '"' + $escaped + '"'
}

function Set-DotEnvValue {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][string] $Name,
        [AllowEmptyString()][string] $Value
    )

    $entry = "$Name=$(ConvertTo-DotEnvValue $Value)"
    $lines = if (Test-Path -LiteralPath $Path) { @(Get-Content -LiteralPath $Path) } else { @() }
    $found = $false
    $updated = foreach ($line in $lines) {
        if ($line -match "^$([regex]::Escape($Name))=") {
            $found = $true
            $entry
        } else {
            $line
        }
    }
    if (!$found) { $updated = @($updated) + $entry }
    [IO.File]::WriteAllLines($Path, [string[]] @($updated), [Text.UTF8Encoding]::new($false))
}

function Write-Utf8File {
    param([Parameter(Mandatory)][string] $Path, [Parameter(Mandatory)][string[]] $Lines)
    [IO.File]::WriteAllLines($Path, $Lines, [Text.UTF8Encoding]::new($false))
}

function Write-BundleHashManifest {
    param(
        [Parameter(Mandatory)][string] $BundleRoot,
        [Parameter(Mandatory)][string] $ManifestPath
    )

    $resolvedRoot = [IO.Path]::GetFullPath($BundleRoot).TrimEnd('\')
    $rootFiles = @(
        'secret.txt', 'Install-ScanToSee.ps1', 'Start-ScanToSee.ps1',
        'Nuke-Personal-Data.ps1', 'Supervisor.Common.ps1',
        'Export-SecretFile.ps1', 'README.md', 'composer.phar', 'cacert.pem'
    )
    $files = @($rootFiles | ForEach-Object { Join-Path $resolvedRoot $_ })
    $checkpoint = Join-Path $resolvedRoot 'checkpoint-765'
    if (Test-Path -LiteralPath $checkpoint -PathType Container) {
        $files += @(Get-ChildItem -LiteralPath $checkpoint -Recurse -File | Select-Object -ExpandProperty FullName)
    }
    $missing = @($files | Where-Object { !(Test-Path -LiteralPath $_ -PathType Leaf) })
    if ($missing.Count) { throw "Cannot create USB hashes; bundle files are missing: $($missing -join ', ')" }

    $lines = @('# Generated locally. Verify automatically with Install-ScanToSee.ps1 -PreflightOnly')
    foreach ($file in @($files | Sort-Object -Unique)) {
        $resolvedFile = [IO.Path]::GetFullPath($file)
        if (!$resolvedFile.StartsWith("$resolvedRoot\", [StringComparison]::OrdinalIgnoreCase)) {
            throw "Refusing to hash a file outside the supervisor bundle: $resolvedFile"
        }
        $relative = $resolvedFile.Substring($resolvedRoot.Length + 1)
        $hash = (Get-FileHash -LiteralPath $resolvedFile -Algorithm SHA256).Hash
        $lines += "$hash  $relative"
    }
    Write-Utf8File -Path $ManifestPath -Lines $lines
}

function Assert-BundleHashManifest {
    param(
        [Parameter(Mandatory)][string] $BundleRoot,
        [Parameter(Mandatory)][string] $ManifestPath
    )

    if (!(Test-Path -LiteralPath $ManifestPath -PathType Leaf)) {
        throw "Required USB hash manifest was not found: $ManifestPath"
    }
    $resolvedRoot = [IO.Path]::GetFullPath($BundleRoot).TrimEnd('\')
    $entries = @{}
    foreach ($line in Get-Content -LiteralPath $ManifestPath) {
        $trimmed = $line.Trim()
        if (!$trimmed -or $trimmed.StartsWith('#')) { continue }
        if ($trimmed -notmatch '^([A-Fa-f0-9]{64})\s{2}(.+)$') {
            throw "Invalid USB-SHA256.txt line: $trimmed"
        }
        $relative = $Matches[2]
        if ([IO.Path]::IsPathRooted($relative)) { throw "Absolute path is forbidden in USB-SHA256.txt: $relative" }
        $path = [IO.Path]::GetFullPath((Join-Path $resolvedRoot $relative))
        if (!$path.StartsWith("$resolvedRoot\", [StringComparison]::OrdinalIgnoreCase)) {
            throw "Path escapes the supervisor bundle in USB-SHA256.txt: $relative"
        }
        $entries[$relative.Replace('/', '\')] = $Matches[1].ToUpperInvariant()
    }

    $required = @(
        'secret.txt', 'Install-ScanToSee.ps1', 'Start-ScanToSee.ps1',
        'Nuke-Personal-Data.ps1', 'Supervisor.Common.ps1', 'composer.phar', 'cacert.pem',
        'checkpoint-765\model.safetensors', 'checkpoint-765\config.json',
        'checkpoint-765\tokenizer.json', 'checkpoint-765\preprocessor_config.json'
    )
    $missingEntries = @($required | Where-Object { !$entries.ContainsKey($_) })
    if ($missingEntries.Count) {
        throw "USB-SHA256.txt is incomplete. Missing entries: $($missingEntries -join ', ')"
    }
    foreach ($entry in $entries.GetEnumerator()) {
        $path = [IO.Path]::GetFullPath((Join-Path $resolvedRoot $entry.Key))
        if (!(Test-Path -LiteralPath $path -PathType Leaf)) { throw "Bundle file listed in USB-SHA256.txt is missing: $($entry.Key)" }
        $actual = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash
        if ($actual -cne $entry.Value) { throw "USB bundle integrity check failed for $($entry.Key). Copy the complete current bundle again." }
    }
}

function Test-TcpPort {
    param([Parameter(Mandatory)][int] $Port)
    $client = [Net.Sockets.TcpClient]::new()
    try {
        $task = $client.ConnectAsync('127.0.0.1', $Port)
        return $task.Wait(400) -and $client.Connected
    } catch { return $false } finally { $client.Dispose() }
}

function Wait-TcpPort {
    param([Parameter(Mandatory)][int] $Port, [int] $TimeoutSeconds = 60)
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        if (Test-TcpPort -Port $Port) { return }
        Start-Sleep -Milliseconds 500
    }
    throw "Port $Port did not become ready within $TimeoutSeconds seconds."
}

function Refresh-ProcessPath {
    $machine = [Environment]::GetEnvironmentVariable('Path', 'Machine')
    $user = [Environment]::GetEnvironmentVariable('Path', 'User')
    $env:Path = "$machine;$user"
}

function Resolve-PhpExecutable {
    $candidates = @(
        'C:\xampp\php\php.exe',
        (Join-Path ${env:ProgramFiles} 'xampp\php\php.exe'),
        (Get-Command php.exe -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source -First 1)
    ) | Where-Object { $_ }
    $match = $candidates | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
    if (!$match) { throw 'PHP 8.2 was not found after prerequisite installation.' }
    return $match
}

function Resolve-PythonExecutable {
    $candidates = @(
        (Join-Path $env:LOCALAPPDATA 'Programs\Python\Python310\python.exe'),
        (Join-Path $env:ProgramFiles 'Python310\python.exe'),
        (Get-Command python.exe -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source -First 1)
    ) | Where-Object { $_ }
    foreach ($candidate in $candidates) {
        if (!(Test-Path -LiteralPath $candidate -PathType Leaf)) { continue }
        # Avoid nested quotes in `python -c`: Windows PowerShell 5.1 strips
        # them differently from PowerShell 7 and produced invalid Python on
        # clean machines. This expression contains no embedded string quotes.
        $version = & $candidate -c 'import sys; print(sys.version_info.major * 100 + sys.version_info.minor)' 2>$null
        if ($LASTEXITCODE -eq 0 -and $version -eq '310') { return $candidate }
    }
    throw 'Python 3.10 was not found after prerequisite installation.'
}

function Resolve-MySqlExecutable {
    $candidate = 'C:\xampp\mysql\bin\mysql.exe'
    if (!(Test-Path -LiteralPath $candidate -PathType Leaf)) {
        throw 'The XAMPP MySQL client was not found at C:\xampp\mysql\bin\mysql.exe.'
    }
    return $candidate
}

function Start-XamppMySql {
    if (Test-TcpPort -Port 3306) { return }
    $launcher = 'C:\xampp\mysql_start.bat'
    if (!(Test-Path -LiteralPath $launcher -PathType Leaf)) {
        throw 'XAMPP MySQL launcher was not found.'
    }
    Start-Process -FilePath $launcher -WindowStyle Hidden | Out-Null
    Wait-TcpPort -Port 3306 -TimeoutSeconds 90
}

function Get-SupervisorLayout {
    param([Parameter(Mandatory)][string] $InstallRoot)
    $resolved = [IO.Path]::GetFullPath($InstallRoot)
    return @{
        Root = $resolved
        Web = Join-Path $resolved 'my_project_directory'
        Ai = Join-Path $resolved 'handwritten-menu-scanner'
        Tools = Join-Path $resolved 'tools'
        Deployment = Join-Path $resolved 'deployment'
        State = Join-Path $resolved 'supervisor-state.json'
    }
}
