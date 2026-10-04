# XAMPP installation

## Requirements

- XAMPP with **PHP 8.1 or newer** and MySQL/MariaDB.
- Enable PHP extensions: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `json`, `zip`, `gd`, `curl`, and `xml`.
- Composer 2 is required once during setup. Composer is a PHP dependency manager; it is not Node.js and no Node server is used.
- Apache `mod_rewrite` and `mod_headers` should be enabled.

## Local installation at `http://localhost/` (no folder in the URL)

1. Copy the repository folder to `C:\xampp\htdocs\netaji-hub`.
2. Open PowerShell in that folder and install PHP dependencies:

   ```powershell
   composer install --no-dev --optimize-autoloader
   ```

3. Install the supplied htdocs-root launcher:

   ```powershell
   powershell -ExecutionPolicy Bypass -File .\scripts\install-xampp-root.ps1 -Force
   ```

   `-Force` is needed the first time because a standard XAMPP installation already has its own dashboard `index.php`. The script does **not** delete it: it backs up the existing htdocs `index.php` and `.htaccess` under `storage\backups\root-launcher-<timestamp>`, writes a small root front controller, blocks direct browser access to the project folder, and sets `APP_URL=http://localhost` when `.env` already exists.

4. Start or restart **Apache** and **MySQL** in XAMPP Control Panel.
5. For a new installation, browse to `http://localhost/install/`. For an existing installation, browse directly to `http://localhost/`.
6. Keep the XAMPP database defaults unless you changed MySQL:
   - Host: `127.0.0.1`
   - Port: `3306`
   - Database: `netaji_pharmacy`
   - Username: `root`
   - Password: blank on an unchanged local XAMPP installation
7. Create a strong Super Admin account. Leave **demonstration data** unchecked for a real installation.
8. The 2027–28 cycle starts in **Draft**. Review every rule before changing it to Open.

If you prefer to make the project itself the htdocs root, copy the **contents** of the repository (not its containing folder) directly into `C:\xampp\htdocs`. The included root `index.php` and `.htaccess` provide the same clean URLs while protecting source/configuration directories. Back up or move the original XAMPP dashboard files first, then run the same PowerShell command from `C:\xampp\htdocs`; it detects that the project is already at the root and only normalises an existing `.env` to `APP_URL=http://localhost`.

## Recommended Apache virtual host

Point Apache directly at `public/`, not the project root:

```apache
<VirtualHost *:80>
    ServerName pharmacy.local
    DocumentRoot "C:/xampp/htdocs/netaji-hub/public"

    <Directory "C:/xampp/htdocs/netaji-hub/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Add `127.0.0.1 pharmacy.local` to the Windows hosts file, restart Apache, and update `APP_URL=http://pharmacy.local` in `.env`.

## Local email and MFA

The installer uses `MAIL_DRIVER=log` and `REQUIRE_STAFF_MFA=false` to prevent a local setup lockout. Verification messages are written to **Admin → Email log**. Before production:

1. Sign in as a super administrator and open **Settings → Email & SMTP**.
2. Enter the provider details and use **Save & send test email**. The SMTP password is encrypted and is never displayed again.
3. Test verification, password reset and staff OTP delivery.
4. Change `REQUIRE_STAFF_MFA=true` only after delivery succeeds.
5. Use HTTPS and change `SESSION_SECURE=true`.

You may instead configure the `.env` values shown below for deployment automation. Saved Admin Settings take precedence over those fallback values.

### Gmail SMTP example

Google normally requires 2-Step Verification and an **App Password**. Do not use or share the normal Gmail password.

```dotenv
MAIL_DRIVER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_AUTH=true
MAIL_USERNAME=your-official-address@gmail.com
MAIL_PASSWORD=your-16-character-google-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your-official-address@gmail.com
MAIL_FROM_NAME="Netaji College of Pharmacy"
MAIL_TIMEOUT=20
```

Keep `MAIL_FROM_ADDRESS` the same as the authenticated Gmail account unless Google Workspace has authorised another sender. Restart Apache after editing `.env`, request a new message, check Spam, and review **Admin → Email log** for `sent` or `failed` plus the provider error. Google Workspace administrators may need to permit App Passwords or provide the institution's approved SMTP relay.

For an installation that is currently stuck at the OTP screen, temporarily set `REQUIRE_STAFF_MFA=false`, restart Apache, sign in, finish and test SMTP, then set it back to `true`. Never paste SMTP credentials into chat or commit them to Git.

## Scheduled encrypted backups

Set `BACKUP_RETENTION_DAYS` in `.env` (30 by default), then create a Windows Task Scheduler task that runs under a restricted service account:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\netaji-hub\scripts\scheduled-backup.php
```

Use the project folder as the task's **Start in** directory, run it daily during a low-traffic window, capture non-zero exit codes, and copy successful `.zip.enc` files from `storage/backups/` to protected off-site storage. The command prevents concurrent runs and applies local retention. Test `scripts/restore-backup.php` with the original `APP_KEY` on an isolated installation.

## Scheduled admissions jobs

After migration 006, add two more Windows Task Scheduler tasks under the same restricted service account. Run offer expiry every 10–15 minutes and the email worker every 2–5 minutes:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\netaji-hub\scripts\expire-admission-offers.php
C:\xampp\php\php.exe C:\xampp\htdocs\netaji-hub\scripts\process-admission-notifications.php
```

Offer expiry releases a still-unpaid seat but deliberately does not auto-promote a waitlisted applicant. The mail worker drains and retries milestone-email jobs created by bulk publication, closure and expiry. Monitor non-zero exit codes. See `docs/MERIT-SELECTION-PAYMENTS.md` for gateway webhook URLs and operating checks.

## Static previews

When PHP is unavailable, `public/preview.html`, `student-preview.html`, and `admin-preview.html` show the visual prototype only. The working PHP application uses `public/index.php`.
