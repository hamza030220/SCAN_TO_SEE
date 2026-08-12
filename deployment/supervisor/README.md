# Supervisor Windows deployment bundle

Copy this whole directory to the USB drive. Before installation, it must contain:

```text
Install-ScanToSee.ps1
Start-ScanToSee.ps1
Nuke-Personal-Data.ps1
Supervisor.Common.ps1
secret.txt
checkpoint-765\
  config.json
  model.safetensors
  tokenizer.json
  preprocessor_config.json
  ...the remaining model/checkpoint files...
USB-SHA256.txt
```

`USB-SHA256.txt` is generated locally with the private bundle. After copying,
compare its entries with `Get-FileHash -Algorithm SHA256` on the USB files.

Create `secret.txt` on the development computer:

```powershell
.\Export-SecretFile.ps1
```

Copy the current model checkpoint:

```powershell
Copy-Item `
  ..\..\..\handwritten-menu-scanner\models\trocr_menu_v1_digits_v3\checkpoints\checkpoint-765 `
  .\checkpoint-765 -Recurse
```

On the supervisor computer, open PowerShell in the USB directory and run:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\Install-ScanToSee.ps1
```

The installer refuses to clone or install anything until `secret.txt` and a
complete checkpoint are beside it. It installs a local demonstration stack,
configures mandatory ngrok access for QR generation, creates an admin and
seeded owner account, and starts the application. Later starts use the copied script:

```powershell
& "$env:USERPROFILE\ScanToSeeSupervisor\deployment\Start-ScanToSee.ps1"
```

After the demonstration, remove credentials and personal/demo data:

```powershell
& "$env:USERPROFILE\ScanToSeeSupervisor\deployment\Nuke-Personal-Data.ps1"
```

The destructive script deletes remote Cloudinary scan captures where possible,
test-mode Stripe customers referenced by the demo database, the complete demo
database, local credentials, ngrok authentication, generated uploads, sessions,
caches, and logs. It preserves the Git clones and model checkpoint.
