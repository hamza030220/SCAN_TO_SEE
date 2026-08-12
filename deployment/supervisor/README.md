# ScanToSee supervisor deployment guide

This guide installs the complete ScanToSee demonstration application on a
Windows computer whose existing software is unknown. It covers the Symfony web
application, MariaDB database, FastAPI OCR service, AI inference model, and the
mandatory ngrok tunnel used by public menu QR codes.

The same procedure should first be rehearsed on a separate Windows computer.

## 1. What the installation contains

The installer deploys two independent Git repositories as sibling directories:

```text
%USERPROFILE%\ScanToSeeSupervisor\
  my_project_directory\       Symfony/PHP application
  handwritten-menu-scanner\   FastAPI/Python OCR application
  deployment\                 copied control scripts
  tools\                      local Composer and ngrok executables
  logs\                       runtime logs, created when services start
```

The installer checks out `agent/supervisor-deployment` from both repositories.
That branch contains the exact deployment-compatible code tested before the
bundle was prepared.

The installed services use these local ports:

| Service | Port | Purpose |
|---|---:|---|
| Symfony | 8000 | Web application |
| FastAPI | 8001 | OCR API used by Symfony |
| ngrok inspector | 4040 | Local tunnel status and diagnostics |
| MariaDB | 3306 | Application database |

## 2. Requirements for the destination computer

Before starting, confirm that the computer has:

- Windows 10 or Windows 11, 64-bit;
- an administrator account;
- a stable internet connection during installation;
- at least 20 GB of free disk space;
- access to GitHub, Python package indexes, Hugging Face/Paddle model sources,
  ngrok, Stripe, Cloudinary, and the configured mail provider;
- a phone with Microsoft Authenticator, Google Authenticator, Authy, or another
  standards-compatible TOTP authenticator installed;
- ports 8000, 8001, 3306, and 4040 available.

The computer does not need an NVIDIA GPU. The installer attempts the supported
CUDA PyTorch package when an NVIDIA GPU is detected. If CUDA installation or
initialization fails, inference automatically uses CPU. Training is not
installed or started on the supervisor computer.

## 3. Required USB bundle

Copy this entire `supervisor` directory to the USB drive. Do not copy only the
installer script. The directory must contain:

```text
supervisor\
  Install-ScanToSee.ps1
  Start-ScanToSee.ps1
  Nuke-Personal-Data.ps1
  Supervisor.Common.ps1
  README.md
  secret.txt
  USB-SHA256.txt
  checkpoint-765\
    config.json
    generation_config.json
    model.safetensors
    tokenizer.json
    tokenizer_config.json
    preprocessor_config.json
    special_tokens_map.json
    vocab.json
    merges.txt
```

`secret.txt`, `USB-SHA256.txt`, and `checkpoint-765` are intentionally excluded
from Git. Never upload the private bundle to GitHub, cloud storage, chat, email,
or an issue tracker.

The inference-only bundle is approximately 1.25 GiB. Copy the directory itself;
Windows PowerShell's built-in ZIP implementation may fail on the large model
file.

## 4. Preparing the private bundle on the development computer

The repository already contains a generated private bundle. If it must be
rebuilt, open PowerShell in `deployment\supervisor`.

Generate a fresh `secret.txt` without printing secret values:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\Export-SecretFile.ps1
```

This copies the required Stripe, email, Cloudinary, and ngrok credentials from
the ignored local configuration. It also reads the current local `S2S`
database and requires exactly one `admin` and one `owner`. Their exact email,
password hash, email-verification state, and TOTP/backup-code state are placed
in the private transfer file without being printed. It generates new values
only for:

- the Symfony application secret;
- the demonstration database password;
- two temporary bootstrap passwords, which are replaced by the copied password
  hashes during installation.

Consequently, use the same current administrator and owner passwords and the
same two authenticator entries on the rehearsal/supervisor computer. The
copied real email addresses also keep account-related mail flows testable.

The private file contains these settings:

```text
APP_SECRET
DATABASE_PASSWORD
MYSQL_ROOT_PASSWORD
NGROK_AUTHTOKEN
NGROK_DOMAIN
SUPERVISOR_ADMIN_EMAIL
SUPERVISOR_ADMIN_BOOTSTRAP_PASSWORD
SUPERVISOR_ADMIN_ACCOUNT_B64
SUPERVISOR_OWNER_EMAIL
SUPERVISOR_OWNER_BOOTSTRAP_PASSWORD
SUPERVISOR_OWNER_ACCOUNT_B64
MAILER_DSN
MAILER_FROM
STRIPE_SECRET_KEY
STRIPE_PUBLISHABLE_KEY
STRIPE_WEBHOOK_SECRET
STRIPE_PRICE_BASIC_MONTHLY
STRIPE_PRICE_BASIC_YEARLY
STRIPE_PRICE_PREMIUM_MONTHLY
STRIPE_PRICE_PREMIUM_YEARLY
STRIPE_PRICE_PRO_MONTHLY
STRIPE_PRICE_PRO_YEARLY
CLOUDINARY_UPLOAD_ENABLED
CLOUDINARY_URL
SCANTOSEE_MODEL_VERSION
```

Copy the trained checkpoint if the bundle does not already contain it:

```powershell
Copy-Item `
  ..\..\..\handwritten-menu-scanner\models\trocr_menu_v1_digits_v3\checkpoints\checkpoint-765 `
  .\checkpoint-765 -Recurse
```

For self-contained TrOCR loading, the USB checkpoint must also include the
tokenizer and processor JSON files listed above. Training-only files such as
`optimizer.pt` are unnecessary and should not be copied.

## 5. Verifying the USB transfer

After copying the bundle to USB, safely eject it, reconnect it, and verify the
important files. `USB-SHA256.txt` contains their expected SHA-256 values.

Example, assuming the USB drive is `E:`:

```powershell
cd E:\supervisor
Get-FileHash .\secret.txt -Algorithm SHA256
Get-FileHash .\checkpoint-765\model.safetensors -Algorithm SHA256
Get-FileHash .\Install-ScanToSee.ps1 -Algorithm SHA256
Get-FileHash .\Nuke-Personal-Data.ps1 -Algorithm SHA256
```

Compare the full hashes with `USB-SHA256.txt`. Do not continue if a hash differs
or a required file is missing.

The installer can validate the bundle without making any system change:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\Install-ScanToSee.ps1 -PreflightOnly
```

Expected result:

```text
USB preflight passed: complete secret.txt and checkpoint-765 were found.
Preflight-only mode: no package, repository, database, or configuration change was made.
```

## 6. Installing on the rehearsal or supervisor computer

### 6.1 Copy the installer locally

Create a temporary local folder and copy the complete USB directory into it:

```powershell
New-Item -ItemType Directory -Path C:\ScanToSee-Installer -Force
Copy-Item E:\supervisor\* C:\ScanToSee-Installer -Recurse -Force
```

Replace `E:` with the actual USB drive letter.

### 6.2 Run PowerShell as Administrator

Open the Start menu, search for **PowerShell**, right-click it, and select
**Run as administrator**. Then run:

```powershell
cd C:\ScanToSee-Installer
Set-ExecutionPolicy -Scope Process Bypass
.\Install-ScanToSee.ps1
```

The execution-policy adjustment applies only to that PowerShell process. It
does not permanently weaken the computer's policy.

### 6.3 What the installer does

Before making any change, it validates both mandatory inputs:

- a complete `secret.txt`;
- a complete `checkpoint-765` with model weights.

If either is missing or incomplete, installation stops before cloning or
installing anything.

After preflight, it:

1. installs missing prerequisites with Windows Package Manager;
2. installs or locates Git, XAMPP/PHP/MariaDB, Python 3.10, Composer, and ngrok;
3. enables the PHP extensions required by Symfony;
4. clones both deployment branches;
5. copies the inference model into the AI repository;
6. writes private, Git-ignored Symfony and FastAPI environment files;
7. creates the `scantosee_supervisor` MariaDB database and restricted database user;
8. installs Composer dependencies and runs Doctrine migrations;
9. creates the supervisor administrator and seeded owner, then runs exactly one
   SQL `UPDATE` for each account to restore the current password hash, verified
   email address, and existing 2FA state;
10. creates a Python virtual environment and installs inference dependencies;
11. selects CUDA or CPU PyTorch and verifies the selected device;
12. configures mandatory ngrok authentication;
13. starts MariaDB, FastAPI, Symfony, the subscription scheduler, and ngrok;
14. verifies Symfony and FastAPI are reachable.

The first installation can take significant time because it downloads Windows,
PHP, Python, PyTorch, PaddleOCR, and application dependencies. Do not close the
PowerShell window or disconnect the network during installation.

## 7. Login and existing two-factor authentication

When installation finishes, open this address on the Windows computer:

```text
http://127.0.0.1:8000
```

The installer restores the exact current administrator and owner accounts. It
copies their password hashes, valid email addresses, email-verification flags,
TOTP secrets, and hashed backup-code lists. It never prints passwords, hashes,
or TOTP secrets. The two login email addresses are these fields in the USB
`secret.txt`:

```text
SUPERVISOR_ADMIN_EMAIL
SUPERVISOR_OWNER_EMAIL
```

Use the same passwords you use on the development computer. Do not use the
`*_BOOTSTRAP_PASSWORD` values: those are internal one-time installer values
that are overwritten before the application starts.

### 7.1 Verify the owner account

1. Open `http://127.0.0.1:8000`.
2. Sign in with `SUPERVISOR_OWNER_EMAIL` and the owner's current password.
3. When the 2FA challenge opens, use the owner's existing ScanToSee entry in
   the authenticator app.
4. Enter its current six-digit code and confirm the owner dashboard opens.
5. Avoid using backup codes for routine testing. Because each database has an
   independent copy of the same hashes, a copied backup code can be accepted
   once by each installation.

### 7.2 Verify the administrator account

The administrator keeps its separate existing TOTP secret and backup codes. Do
not use the owner's six-digit code.

1. Sign out of the owner account.
2. Sign in with `SUPERVISOR_ADMIN_EMAIL` and the administrator's current
   password.
3. Use the administrator's existing ScanToSee authenticator entry.
4. Enter its current six-digit code and confirm administrator pages open.

Every later login for either account requires its password followed by the
current six-digit code from the matching authenticator entry. A remaining
single-use backup code can replace the authenticator code if the phone is
unavailable.

If the phone clock is incorrect, TOTP confirmation may fail. Enable automatic
date, time, and time-zone synchronization on the phone.

Use the owner account to demonstrate menu creation, design, publication, QR
access, and OCR. Use the administrator account only for administrator features.

After confirming the credentials work, remove the USB drive and store it
securely.

## 8. Complete rehearsal checklist

Perform this full rehearsal on the separate test computer before meeting the
supervisor.

### 8.1 Basic application checks

1. Open `http://127.0.0.1:8000`.
2. Sign in with the seeded owner account.
3. Complete the owner login and existing 2FA challenge described in section 7.
4. Sign out, sign back in, and confirm the password is followed by the 2FA code
   challenge.
5. Confirm the owner dashboard opens without an exception.
6. Confirm the seeded business and menus are visible.
7. Open a menu and confirm its categories and items render.
8. Sign out and sign in with the administrator account.
9. Complete the administrator's separate existing 2FA challenge.
10. Sign out, sign back in, and confirm the administrator password is followed
    by the 2FA code challenge.
11. Confirm the administrator dashboard opens.
12. Test one owner backup code and one administrator backup code only if you
    have safely retained the remaining codes. Each tested backup code is
    permanently consumed.

### 8.2 QR code and phone checks

ngrok is mandatory and starts automatically. The launcher does not print the
public HTTPS address, but it writes the current address into Symfony before QR
codes are generated.

1. Sign in as the owner.
2. Open or create a published menu.
3. Display or download its QR code.
4. Disable Wi-Fi on the phone so the test uses mobile internet.
5. Scan the QR code with the phone camera.
6. Confirm the public menu loads without requiring a login.
7. Change an item name, price, description, or availability in the web app.
8. Publish/save the change.
9. Refresh the phone and confirm the public menu reflects the change.

For local ngrok diagnostics only, open:

```text
http://127.0.0.1:4040
```

The inspector should show an active HTTPS tunnel targeting
`http://127.0.0.1:8000`. Do not use the inspector URL as the customer menu URL.

On a free ngrok account, the public domain can change after a stop/restart.
Start the complete stack before generating or presenting QR codes. A reserved
domain can be set with `NGROK_DOMAIN` in `secret.txt` when the ngrok account
supports one.

### 8.3 OCR checks

1. Sign in as the owner and open a target menu.
2. Open **Scan Menu (Beta)**.
3. Upload a clear JPG or PNG menu image.
4. Start the scan and wait for FastAPI inference.
5. Confirm categories, item names, prices, confidence indicators, and review
   fields appear.
6. Correct at least one extracted value.
7. Save the reviewed results into the menu.
8. Confirm the new categories/items exist in the menu editor.

The first OCR scan can be slower because PaddleOCR initializes its detector.
CPU inference is also slower than CUDA inference; this is expected and is not a
failure if the request eventually completes.

### 8.4 External integration checks

Only perform actions appropriate for the supplied test credentials:

- send a password-reset or verification email and confirm delivery;
- verify Stripe test-mode pages/actions do not use live charges;
- confirm OCR training assets upload only when Cloudinary upload is enabled.

Never use production payment actions during a rehearsal.

## 9. Starting, checking, and stopping later

The installer copies the control scripts into the installed deployment folder.
These commands can be run from an ordinary PowerShell window.

Allow scripts in the current PowerShell process:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
```

Start the complete stack:

```powershell
& "$env:USERPROFILE\ScanToSeeSupervisor\deployment\Start-ScanToSee.ps1"
```

Check service status:

```powershell
& "$env:USERPROFILE\ScanToSeeSupervisor\deployment\Start-ScanToSee.ps1" -Action Status
```

Expected services are Symfony, FastAPI, ngrok, and MariaDB. The subscription
scheduler runs as a background process and writes to its log.

Stop application processes:

```powershell
& "$env:USERPROFILE\ScanToSeeSupervisor\deployment\Start-ScanToSee.ps1" -Action Stop
```

Stopping preserves the database, accounts, uploads, configuration, model, and
installed dependencies. Use the destructive cleanup script only when that data
must be removed.

## 10. Logs and troubleshooting

Runtime logs are stored under:

```text
%USERPROFILE%\ScanToSeeSupervisor\logs\
  symfony.out.log
  symfony.err.log
  fastapi.out.log
  fastapi.err.log
  scheduler.out.log
  scheduler.err.log
  ngrok.out.log
  ngrok.err.log
```

### Installer reports missing `secret.txt` or checkpoint

Confirm that both inputs are directly beside `Install-ScanToSee.ps1`, not in a
nested directory. Run `-PreflightOnly` again.

### PowerShell says script execution is disabled

Run this in the same PowerShell window before the script:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
```

### Administrator error

Close PowerShell, reopen it with **Run as administrator**, return to
`C:\ScanToSee-Installer`, and rerun the installer.

### A required port is already used

Inspect the four ports:

```powershell
Get-NetTCPConnection -State Listen |
  Where-Object LocalPort -In 8000, 8001, 3306, 4040 |
  Select-Object LocalAddress, LocalPort, OwningProcess
```

Stop or reconfigure the conflicting application before starting ScanToSee.

### Symfony does not open

Check `symfony.err.log`, confirm MariaDB is running, and check status:

```powershell
& "$env:USERPROFILE\ScanToSeeSupervisor\deployment\Start-ScanToSee.ps1" -Action Status
```

### Composer reports `System.Collections.Hashtable.Tools`

This message comes from an obsolete installer copy whose Composer destination
argument was expanded incorrectly by Windows PowerShell. Replace all deployment
scripts in `C:\ScanToSee-Installer` with the current bundle and rerun
`Install-ScanToSee.ps1`. The installer is resumable; already installed Windows
prerequisites do not need to be removed.

### Login redirects to `/2fa/setup`

This is not expected for the exported accounts because their existing TOTP
state is restored. Recreate `secret.txt` from the current database, copy it to
the rehearsal machine, and reinstall. A `/2fa/setup` redirect means the account
export was absent or the wrong database/account was used.

### Login asks for an authenticator code

Open the authenticator entry matching the account email and enter its current
six-digit code. The owner and administrator use different TOTP entries. If the
code is repeatedly rejected, confirm the phone uses automatic date and time.

### Authenticator phone or entry is unavailable

Select **Use a backup code** on the two-factor login page and enter one of that
account's unused eight-character backup codes. Each backup code works once. If
no authenticator entry or backup code remains, the account's TOTP data must be
reset directly in the local database or the demonstration database must be
recreated; there is no insecure 2FA bypass in the installer.

### FastAPI or OCR fails

Open `fastapi.err.log`. Confirm the model file exists:

```powershell
Test-Path "$env:USERPROFILE\ScanToSeeSupervisor\handwritten-menu-scanner\models\trocr_menu_v1_digits_v3\checkpoints\checkpoint-765\model.safetensors"
```

Confirm FastAPI health:

```powershell
Invoke-RestMethod http://127.0.0.1:8001/health
```

The response should contain `status` equal to `ok`.

### Phone cannot open the QR menu

1. Confirm the local menu works in the computer browser.
2. Confirm `Start-ScanToSee.ps1 -Action Status` shows ngrok running.
3. Open `http://127.0.0.1:4040` and confirm an HTTPS tunnel exists.
4. Confirm the menu was published before generating the QR code.
5. Regenerate the QR code after a restart if the free ngrok domain changed.
6. Test the phone using mobile data to avoid local Wi-Fi/DNS interference.

### ngrok authentication fails

The `NGROK_AUTHTOKEN` in `secret.txt` may be expired or revoked. Generate a new
private bundle using a valid ngrok account token, or replace the value locally
and rerun installation.

## 11. Destructive cleanup after testing or demonstration

Use this only when the demonstration data and credentials must be permanently
removed from the machine.

Run:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
& "$env:USERPROFILE\ScanToSeeSupervisor\deployment\Nuke-Personal-Data.ps1"
```

The script displays a warning. To continue, type exactly:

```text
NUKE SCANTOSEE
```

The cleanup attempts to remove:

- Symfony and FastAPI private environment files;
- the original local/USB `secret.txt` path recorded by the installer;
- the complete `scantosee_supervisor` database and its restricted user;
- supervisor administrator and owner accounts stored in that database;
- locally generated business logos, item images, menu backgrounds, and heroes;
- sessions, caches, temporary files, logs, and captured samples;
- ngrok authentication configuration;
- matching user-level credential environment variables;
- Cloudinary images belonging to scan UUIDs recorded by this installation;
- Stripe test customers matching the two configured demonstration emails.

It preserves:

- both Git repositories;
- installed prerequisites;
- the Python virtual environment;
- the transferred AI inference model.

Remote cleanup is best-effort. If Stripe, Cloudinary, mail, or ngrok cannot be
reached, inspect the corresponding provider dashboard and revoke or delete the
demonstration credentials/data manually.

After the cleanup finishes:

1. delete `C:\ScanToSee-Installer`; the nuke removes its recorded `secret.txt`,
   but the non-secret scripts and model bundle remain;
2. remove and secure the USB drive;
3. check the Stripe test dashboard and Cloudinary folder if remote cleanup
   reported a warning;
4. rotate credentials if the USB was lost, copied elsewhere, or exposed;
5. optionally delete `%USERPROFILE%\ScanToSeeSupervisor` when the preserved
   repositories/model are no longer needed.

## 12. Security rules

- Never commit `secret.txt`, `USB-SHA256.txt`, or `checkpoint-765`.
- Never paste credentials into terminal logs, screenshots, reports, chat, or
  GitHub issues.
- Keep Stripe in test mode for rehearsals and demonstrations.
- Remove the USB drive after installation and login verification.
- Keep the owner and administrator backup codes private and clearly separated;
  they are authentication credentials even though they are not in
  `secret.txt`.
- Run the cleanup before returning or repurposing a borrowed test computer.
- Provider-side credential rotation is the only reliable response to a leaked
  token; deleting a local file cannot revoke a credential already copied.
