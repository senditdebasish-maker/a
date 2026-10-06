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
7. Create the first Super Admin account. In **Email delivery**, enter the institution’s SMTP/app-password details. Every applicant, student and staff sign-in uses a one-time email code, so SMTP is required. Leave **demonstration data** unchecked for a real installation.
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

## Passwordless email sign-in and SMTP

Every applicant, student and staff member signs in with a six-digit email code. The installer therefore requires **SMTP with PHPMailer**; Local email log mode cannot deliver a secret sign-in code and is not offered for a new installation. The installer validates the selected host, port, encryption, sender and authentication details before installing, but it does **not** send a message during database creation.

Codes expire after 10 minutes, are one-time use, permit five attempts and cannot be requested again for 60 seconds. The portal gives the same delivery response for unknown and known addresses, reducing email-address enumeration. Authentication emails never retain codes, links or message bodies in **Admin → Email log**.

SMTP credentials are never redisplayed or included in the email log; the first-run values are written only to the protected `.env` file. Restrict OS/project-folder access to that file. Before admitting real users:

1. Complete the installer using a real accessible Super Admin email address.
2. Open the sign-in page and confirm a Super Admin email code arrives and works.
3. Sign in, open **Settings → Email & SMTP**, then use **Save & send test email**. When credentials are saved from Admin Settings, the password is encrypted in the database and is never displayed again.
4. Test applicant email verification and passwordless sign-in before publishing a cycle.
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

If email codes do not arrive, do not disable sign-in security. Check the sender address, provider restrictions, Spam folder and **Admin → Email log** using a currently authorised administrator session; then correct and test SMTP. Never paste SMTP credentials into chat or commit them to Git.

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

### Upgrading an existing installation

Before replacing an existing portal with this passwordless release, use the currently running portal to sign in as a Super Admin. In **Admin Settings → Email & SMTP**, save SMTP credentials and use **Save & send test email**. Only then deploy the new files and test an email-code sign-in.

Do not rely on an `.env` SMTP change alone when the database already has mail settings: saved mail settings take precedence. An old Local log configuration suppresses authentication secrets by design, so it cannot deliver a sign-in code. Take an encrypted backup before any configuration or deployment change.
