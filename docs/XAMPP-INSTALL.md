# XAMPP installation

## Requirements

- XAMPP with **PHP 8.1 or newer** and MySQL/MariaDB.
- Enable PHP extensions: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `json`, `zip`, `gd`, `curl`, and `xml`.
- Composer 2 is required once during setup. Composer is a PHP dependency manager; it is not Node.js and no Node server is used.
- Apache `mod_rewrite` and `mod_headers` should be enabled.

## Local installation

1. Copy the repository folder to `C:\xampp\htdocs\netaji-hub`.
2. Open a terminal in that folder.
3. Run:

   ```powershell
   composer install --no-dev --optimize-autoloader
   ```

4. Start **Apache** and **MySQL** in XAMPP Control Panel.
5. Browse to `http://localhost/netaji-hub/public/install/`.
6. Keep the XAMPP defaults unless you changed MySQL:
   - Host: `127.0.0.1`
   - Port: `3306`
   - Database: `netaji_pharmacy`
   - Username: `root`
   - Password: blank on an unchanged local XAMPP installation
7. Create a strong Super Admin account. Leave **demonstration data** unchecked for a real installation.
8. The 2027–28 cycle starts in **Draft**. Review every rule before changing it to Open.

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

1. Configure authenticated SMTP in `.env`.
2. Test verification, password reset and staff OTP delivery.
3. Change `REQUIRE_STAFF_MFA=true` only after delivery succeeds.
4. Use HTTPS and change `SESSION_SECURE=true`.

### Gmail SMTP example

Google normally requires 2-Step Verification and an **App Password**. Do not use or share the normal Gmail password.

```dotenv
MAIL_DRIVER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-official-address@gmail.com
MAIL_PASSWORD=your-16-character-google-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your-official-address@gmail.com
MAIL_FROM_NAME="Netaji College of Pharmacy"
```

Keep `MAIL_FROM_ADDRESS` the same as the authenticated Gmail account unless Google Workspace has authorised another sender. Restart Apache after editing `.env`, request a new message, check Spam, and review **Admin → Email log** for `sent` or `failed` plus the provider error. Google Workspace administrators may need to permit App Passwords or provide the institution's approved SMTP relay.

For an installation that is currently stuck at the OTP screen, temporarily set `REQUIRE_STAFF_MFA=false`, restart Apache, sign in, finish and test SMTP, then set it back to `true`. Never paste SMTP credentials into chat or commit them to Git.

## Scheduled encrypted backups

Set `BACKUP_RETENTION_DAYS` in `.env` (30 by default), then create a Windows Task Scheduler task that runs under a restricted service account:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\netaji-hub\scripts\scheduled-backup.php
```

Use the project folder as the task's **Start in** directory, run it daily during a low-traffic window, capture non-zero exit codes, and copy successful `.zip.enc` files from `storage/backups/` to protected off-site storage. The command prevents concurrent runs and applies local retention. Test `scripts/restore-backup.php` with the original `APP_KEY` on an isolated installation.

## Static previews

When PHP is unavailable, `public/preview.html`, `student-preview.html`, and `admin-preview.html` show the visual prototype only. The working PHP application uses `public/index.php`.
