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
3. Change `REQUIRE_STAFF_MFA=true`.
4. Use HTTPS and change `SESSION_SECURE=true`.

## Static previews

When PHP is unavailable, `public/preview.html`, `student-preview.html`, and `admin-preview.html` show the visual prototype only. The working PHP application uses `public/index.php`.
