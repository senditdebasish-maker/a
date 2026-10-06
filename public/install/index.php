<?php

declare(strict_types=1);

session_start();
$basePath = dirname(__DIR__, 2);
$envPath = $basePath . '/.env';

function h(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function envInstalled(string $path): bool { return is_file($path) && preg_match('/^APP_INSTALLED=true\s*$/mi', (string) file_get_contents($path)); }
function envValue(string|int|bool $value): string {
    if (is_bool($value)) return $value ? 'true' : 'false';
    return (string) json_encode((string) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
function appBaseUrl(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/install/index.php');
    $path = rtrim(dirname(dirname($script)), '/.');
    // Root-front-controller deployments internally rewrite /install to
    // public/install. Keep the generated APP_URL at the browser-visible root.
    $rootLauncher = isset($_SERVER['NCP_ROOT_LAUNCHER'])
        || isset($_SERVER['REDIRECT_NCP_ROOT_LAUNCHER'])
        || getenv('NCP_ROOT_LAUNCHER') !== false
        || getenv('REDIRECT_NCP_ROOT_LAUNCHER') !== false;
    if ($rootLauncher || $path === '/public') $path = '';
    elseif (str_ends_with($path, '/public')) $path = substr($path, 0, -strlen('/public'));
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($path ? $path : '');
}

$installed = envInstalled($envPath);
$errors = [];
$success = false;
$requirements = [
    'PHP 8.1 or newer' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO extension' => extension_loaded('pdo'),
    'PDO MySQL extension' => extension_loaded('pdo_mysql'),
    'Mbstring extension' => extension_loaded('mbstring'),
    'OpenSSL extension' => extension_loaded('openssl'),
    'Fileinfo extension' => extension_loaded('fileinfo'),
    'JSON extension' => extension_loaded('json'),
    'ZIP extension' => extension_loaded('zip'),
    'Writable project directory' => is_writable($basePath),
    'Writable storage directory' => is_dir($basePath . '/storage') ? is_writable($basePath . '/storage') : is_writable($basePath),
];
$requirementsOk = !in_array(false, $requirements, true);
// Passwordless sign-in requires SMTP. Do not boot Composer just to show setup
// requirements: a platform mismatch remains a readable installer check, not a fatal error.
$phpMailerAvailable = is_file($basePath . '/vendor/autoload.php')
    && is_file($basePath . '/vendor/phpmailer/phpmailer/src/PHPMailer.php');

if (empty($_SESSION['install_token'])) $_SESSION['install_token'] = bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    if (!hash_equals((string) $_SESSION['install_token'], (string) ($_POST['_token'] ?? ''))) $errors[] = 'The setup session expired. Refresh and try again.';
    if (!$requirementsOk) $errors[] = 'Resolve every failed system check before installing.';
    $dbName = trim((string) ($_POST['db_name'] ?? ''));
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) $errors[] = 'Database name may contain only letters, numbers and underscores.';
    $adminEmail = strtolower(trim((string) ($_POST['admin_email'] ?? '')));
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid Super Admin email address.';
    // Passwordless accounts still need a non-null legacy hash. It is random and
    // never shown; every portal sign-in is completed with a delivered email code.
    $password = bin2hex(random_bytes(32));
    if (trim((string) ($_POST['college_name'] ?? '')) === '') $errors[] = 'College name is required.';
    if (empty($_POST['privacy_ack'])) $errors[] = 'Acknowledge the privacy and regulatory setup notice.';

    $mailDriver = strtolower(trim((string) ($_POST['mail_driver'] ?? 'smtp')));
    $mailHost = trim((string) ($_POST['mail_host'] ?? ''));
    $mailPort = filter_var($_POST['mail_port'] ?? 587, FILTER_VALIDATE_INT);
    $mailAuth = !empty($_POST['mail_auth']);
    $mailUsername = trim((string) ($_POST['mail_username'] ?? ''));
    $mailPassword = (string) ($_POST['mail_password'] ?? '');
    $mailEncryption = strtolower(trim((string) ($_POST['mail_encryption'] ?? 'tls')));
    $mailFromAddress = strtolower(trim((string) ($_POST['mail_from_address'] ?? $adminEmail)));
    $mailFromName = trim((string) ($_POST['mail_from_name'] ?? ($_POST['college_name'] ?? '')));
    $mailTimeout = filter_var($_POST['mail_timeout'] ?? 20, FILTER_VALIDATE_INT);
    if ($mailDriver !== 'smtp') $errors[] = 'Email-code sign-in requires SMTP delivery; local email log cannot deliver sign-in codes.';
    if ($mailDriver === 'smtp') {
        if (!$phpMailerAvailable) $errors[] = 'SMTP setup requires PHPMailer. Run composer install --no-dev --optimize-autoloader, then refresh this page.';
        if ($mailHost === '' || mb_strlen($mailHost) > 255 || preg_match('/[\s\/?#]/u', $mailHost) || str_contains($mailHost, '://')) $errors[] = 'Enter an SMTP host name or IP address only, without a protocol, path or spaces.';
        if ($mailPort === false || $mailPort < 1 || $mailPort > 65535) $errors[] = 'SMTP port must be between 1 and 65,535.';
        if (!in_array($mailEncryption, ['none', 'tls', 'ssl'], true)) $errors[] = 'Choose no encryption, STARTTLS or implicit TLS for SMTP.';
        if ($mailAuth && ($mailUsername === '' || mb_strlen($mailUsername) > 255 || preg_match('/[\r\n\0]/', $mailUsername))) $errors[] = 'An SMTP username without line breaks is required when authentication is enabled.';
        if ($mailAuth && ($mailPassword === '' || strlen($mailPassword) > 1024 || preg_match('/[\r\n\0]/', $mailPassword))) $errors[] = 'An SMTP password of up to 1,024 characters without line breaks is required when authentication is enabled.';
        if (!filter_var($mailFromAddress, FILTER_VALIDATE_EMAIL) || mb_strlen($mailFromAddress) > 190 || preg_match('/[\r\n]/', $mailFromAddress)) $errors[] = 'Enter a valid From email address for SMTP.';
        if ($mailFromName === '' || mb_strlen($mailFromName) > 190 || preg_match('/[\r\n]/', $mailFromName)) $errors[] = 'Enter a valid From name for SMTP.';
        if ($mailTimeout === false || $mailTimeout < 5 || $mailTimeout > 60) $errors[] = 'SMTP connection timeout must be between 5 and 60 seconds.';
    }

    if (!$errors) {
        $host = trim((string) ($_POST['db_host'] ?? '127.0.0.1'));
        $port = (int) ($_POST['db_port'] ?? 3306);
        $user = trim((string) ($_POST['db_user'] ?? 'root'));
        $dbPassword = (string) ($_POST['db_password'] ?? '');
        try {
            $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true]);
            $server->exec('CREATE DATABASE IF NOT EXISTS `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $db = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]);
            $existing = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $db->quote($dbName))->fetchColumn();
            if ((int) $existing > 0) throw new RuntimeException('The selected database is not empty. Use a new database to avoid overwriting data.');
            $schema = file_get_contents($basePath . '/database/schema.mysql.sql');
            if ($schema === false) throw new RuntimeException('Database schema file is missing.');
            $db->exec($schema);
            require $basePath . '/database/Seeder.php';
            (new \Database\Seeder($db))->run([
                'first_name' => trim((string) $_POST['admin_first_name']),
                'last_name' => trim((string) $_POST['admin_last_name']),
                'email' => $adminEmail,
                'mobile' => trim((string) ($_POST['admin_mobile'] ?? '')),
                'password' => $password,
            ], trim((string) $_POST['college_name']), !empty($_POST['demo_data']));

            foreach (['cache','logs','sessions','backups','private'] as $folder) {
                $path = $basePath . '/storage/' . $folder;
                if (!is_dir($path)) mkdir($path, 0775, true);
            }
            $appKey = 'base64:' . base64_encode(random_bytes(32));
            $env = [
                'APP_NAME=' . json_encode(trim((string) $_POST['college_name']), JSON_UNESCAPED_SLASHES),
                'APP_ENV=production', 'APP_DEBUG=false', 'APP_URL=' . appBaseUrl(), 'APP_KEY=' . $appKey,
                'APP_TIMEZONE=Asia/Kolkata', 'APP_LOCALE=en', 'APP_FALLBACK_LOCALE=en', 'APP_CURRENCY=INR', 'APP_DATE_FORMAT=d-m-Y', 'LOGIN_MODE=email_otp', 'APP_INSTALLED=true', '',
                'DB_DRIVER=mysql', 'DB_HOST=' . $host, 'DB_PORT=' . $port, 'DB_DATABASE=' . $dbName,
                'DB_USERNAME=' . json_encode($user), 'DB_PASSWORD=' . json_encode($dbPassword), 'DB_CHARSET=utf8mb4', '',
                'SESSION_NAME=ncp_session', 'SESSION_LIFETIME=120', 'SESSION_SECURE=' . (str_starts_with(appBaseUrl(), 'https://') ? 'true' : 'false'), '',
                'MAIL_DRIVER=' . $mailDriver, 'MAIL_HOST=' . envValue($mailDriver === 'smtp' ? $mailHost : ''), 'MAIL_PORT=' . ($mailDriver === 'smtp' ? (int) $mailPort : 587), 'MAIL_AUTH=' . ($mailDriver === 'smtp' && $mailAuth ? 'true' : 'false'),
                'MAIL_USERNAME=' . envValue($mailDriver === 'smtp' ? $mailUsername : ''), 'MAIL_PASSWORD=' . envValue($mailDriver === 'smtp' ? $mailPassword : ''), 'MAIL_ENCRYPTION=' . ($mailDriver === 'smtp' ? $mailEncryption : 'tls'),
                'MAIL_FROM_ADDRESS=' . envValue($mailDriver === 'smtp' ? $mailFromAddress : $adminEmail), 'MAIL_FROM_NAME=' . envValue($mailDriver === 'smtp' ? $mailFromName : trim((string) $_POST['college_name'])), 'MAIL_TIMEOUT=' . ($mailDriver === 'smtp' ? (int) $mailTimeout : 20), '',
                'UPLOAD_MAX_MB=5', 'BACKUP_ENCRYPTION=true', 'BACKUP_RETENTION_DAYS=30', '',
            ];
            if (file_put_contents($envPath, implode("\n", $env), LOCK_EX) === false) throw new RuntimeException('Could not write the .env file. Check folder permissions.');
            @chmod($envPath, 0640);
            file_put_contents($basePath . '/storage/installed.lock', date('c') . "\n", LOCK_EX);
            $installed = true;
            $success = true;
        } catch (Throwable $exception) {
            $errors[] = 'Installation failed: ' . $exception->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $installed ? 'Installation complete' : 'Install Netaji Admission Hub' ?></title>
<style>
:root{--ink:#102d35;--muted:#62767b;--teal:#075e61;--teal2:#0c7776;--gold:#e7a83e;--line:#dbe5e4;--soft:#f1f7f6;--danger:#a13e3e}*{box-sizing:border-box}body{margin:0;background:linear-gradient(145deg,#eaf4f2,#f9f7f0);color:var(--ink);font:15px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif}.shell{width:min(1100px,calc(100% - 32px));margin:42px auto;display:grid;grid-template-columns:340px 1fr;background:#fff;border:1px solid rgba(7,94,97,.12);border-radius:24px;overflow:hidden;box-shadow:0 24px 80px rgba(16,45,53,.13)}.aside{background:var(--teal);color:white;padding:44px 36px;position:relative;overflow:hidden}.aside:after{content:"Rx";position:absolute;right:-18px;bottom:-58px;font:bold 190px Georgia;opacity:.07}.mark{width:54px;height:54px;border-radius:16px;background:var(--gold);display:grid;place-items:center;color:#173d42;font:bold 25px Georgia;margin-bottom:25px}.aside h1{font:700 31px/1.15 Georgia,serif;margin:0 0 14px}.aside p{color:#cce2df}.steps{margin:34px 0 0;padding:0;list-style:none}.steps li{display:flex;gap:12px;margin:19px 0;color:#d8ece9}.steps b{width:27px;height:27px;border-radius:50%;background:rgba(255,255,255,.12);display:grid;place-items:center;color:#f4c775;font-size:12px;flex:none}.main{padding:42px 46px}.eyebrow{text-transform:uppercase;letter-spacing:.16em;font-size:11px;color:var(--teal);font-weight:800}.main h2{font:700 31px/1.2 Georgia,serif;margin:6px 0 7px}.lead{color:var(--muted);margin:0 0 26px}.checks{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:22px 0 28px}.check{border:1px solid var(--line);border-radius:10px;padding:9px 11px;font-size:13px}.ok{color:var(--teal)}.bad{color:var(--danger);background:#fff6f6}.section{border-top:1px solid var(--line);padding-top:25px;margin-top:25px}.section h3{margin:0 0 15px;font-size:16px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:15px}.full{grid-column:1/-1}label{display:block;font-weight:700;font-size:12px;color:#37555a;margin-bottom:6px}input,select{width:100%;border:1px solid #c9d8d6;border-radius:9px;padding:11px 12px;font:inherit;outline:none;background:#fff}input:focus,select:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(7,94,97,.1)}.checkbox,.delivery-choice{display:flex;gap:10px;align-items:flex-start;font-weight:400;font-size:13px;background:var(--soft);padding:12px;border-radius:10px}.checkbox input,.delivery-choice input{width:auto;margin-top:4px}.delivery-options{display:grid;grid-template-columns:1fr 1fr;gap:10px}.delivery-choice{border:1px solid transparent;cursor:pointer}.delivery-choice:has(input:checked){border-color:#82b7b1;background:#e8f5f3}.smtp-config{margin-top:15px;padding:16px;border:1px solid var(--line);border-radius:12px;background:#fbfdfd}.smtp-config h4{margin:0 0 5px}.smtp-config p{font-size:12px;color:var(--muted);margin:0 0 15px}.inline-check{display:flex;align-items:center;gap:8px;margin:2px 0 3px;font-size:13px}.inline-check input{width:auto}.mail-status{font-size:12px;margin:0 0 12px;color:var(--muted)}.mail-status.ok{color:var(--teal)}.button{border:0;background:var(--teal);color:#fff;border-radius:10px;padding:13px 20px;font-weight:800;font-size:14px;cursor:pointer}.button:hover{background:var(--teal2)}.alerts{padding:0;list-style:none}.alerts li{background:#fff3f2;border:1px solid #f0c9c7;color:#8a3535;border-radius:9px;padding:9px 12px;margin:7px 0}.success{background:var(--soft);border:1px solid #b9d9d5;border-radius:14px;padding:25px}.success strong{display:block;font-size:20px;margin-bottom:6px}.note{font-size:12px;color:var(--muted);margin-top:20px}.code{font-family:monospace;background:#eef3f2;border-radius:4px;padding:2px 5px}@media(max-width:820px){.shell{grid-template-columns:1fr;margin:0;width:100%;border-radius:0}.aside{padding:28px}.steps{display:none}.main{padding:30px 22px}.grid,.checks,.delivery-options{grid-template-columns:1fr}.full{grid-column:auto}}
</style>
</head>
<body><main class="shell"><aside class="aside"><div class="mark">N</div><h1>Netaji Admission Hub</h1><p>XAMPP-ready admissions, website CMS and shared college data foundation.</p><ol class="steps"><li><b>1</b><span>Verify PHP and writable folders</span></li><li><b>2</b><span>Connect a clean MySQL database</span></li><li><b>3</b><span>Create the first Super Admin</span></li><li><b>4</b><span>Configure SMTP for email-code sign-in</span></li><li><b>5</b><span>Seed the B.Pharm 2027–28 cycle</span></li></ol></aside><section class="main">
<?php if ($installed): ?>
<div class="eyebrow">Setup complete</div><h2>Your college hub is ready.</h2><p class="lead">The database, roles, CMS content and admission settings were created successfully.</p>
<div class="success"><strong>Before opening real applications</strong>Confirm real college/regulatory details, test a passwordless email sign-in, review eligibility and seat rules, test backup/restore, and change the admission cycle from Draft to Open.</div>
<p><a class="button" href="<?= h(appBaseUrl()) ?>">Open website</a></p>
<?php else: ?>
<div class="eyebrow">Secure first-run setup</div><h2>Let’s configure your installation.</h2><p class="lead">Use a new database. The installer will not write into a database that already contains tables.</p>
<?php if ($errors): ?><ul class="alerts"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach ?></ul><?php endif ?>
<h3>System check</h3><div class="checks"><?php foreach ($requirements as $name => $passed): ?><div class="check <?= $passed ? 'ok' : 'bad' ?>"><?= $passed ? '✓' : '×' ?> <?= h($name) ?></div><?php endforeach ?></div>
<form method="post"><input type="hidden" name="_token" value="<?= h($_SESSION['install_token']) ?>">
<div class="section"><h3>1. College identity</h3><div class="grid"><div class="full"><label>College name</label><input name="college_name" required value="<?= h($_POST['college_name'] ?? 'Netaji College of Pharmacy') ?>"></div></div></div>
<div class="section"><h3>2. MySQL database</h3><div class="grid"><div><label>Host</label><input name="db_host" required value="<?= h($_POST['db_host'] ?? '127.0.0.1') ?>"></div><div><label>Port</label><input name="db_port" required inputmode="numeric" value="<?= h($_POST['db_port'] ?? '3306') ?>"></div><div><label>Database name</label><input name="db_name" required value="<?= h($_POST['db_name'] ?? 'netaji_pharmacy') ?>"></div><div><label>MySQL username</label><input name="db_user" required value="<?= h($_POST['db_user'] ?? 'root') ?>"></div><div class="full"><label>MySQL password (XAMPP default is blank)</label><input type="password" name="db_password" value=""></div></div></div>
<div class="section"><h3>3. Super Admin</h3><div class="grid"><div><label>First name</label><input name="admin_first_name" required value="<?= h($_POST['admin_first_name'] ?? '') ?>"></div><div><label>Last name</label><input name="admin_last_name" required value="<?= h($_POST['admin_last_name'] ?? '') ?>"></div><div><label>Email</label><input type="email" name="admin_email" required value="<?= h($_POST['admin_email'] ?? '') ?>"></div><div><label>Mobile (optional)</label><input name="admin_mobile" value="<?= h($_POST['admin_mobile'] ?? '') ?>"></div><div class="full"><p class="mail-status ok">Sign-in is passwordless. The Super Admin will receive a six-digit code at this email address after setup.</p></div></div></div>
<div class="section"><h3>4. Email delivery for passwordless sign-in</h3><p class="mail-status <?= $phpMailerAvailable ? 'ok' : '' ?>"><?= $phpMailerAvailable ? '✓ PHPMailer is installed. Configure SMTP to deliver every sign-in code.' : 'PHPMailer is not installed. Run composer install --no-dev --optimize-autoloader before installing; local mail logging cannot deliver sign-in codes.' ?></p><div class="smtp-config"><h4>Required SMTP connection</h4><p>Every applicant, student and staff member signs in with a one-time email code. Use an app password or provider-issued credential, never an individual staff member’s normal password. Credentials are never displayed or written to the email log.</p><div class="grid"><div><label>SMTP host</label><input name="mail_host" maxlength="255" placeholder="smtp.example.edu.in" value="<?= h($_POST['mail_host'] ?? '') ?>"></div><div><label>Port</label><input name="mail_port" inputmode="numeric" value="<?= h($_POST['mail_port'] ?? '587') ?>"></div><div><label>Encryption</label><select name="mail_encryption"><option value="tls"<?= ($_POST['mail_encryption'] ?? 'tls') === 'tls' ? ' selected' : '' ?>>STARTTLS (recommended, port 587)</option><option value="ssl"<?= ($_POST['mail_encryption'] ?? '') === 'ssl' ? ' selected' : '' ?>>Implicit TLS (usually port 465)</option><option value="none"<?= ($_POST['mail_encryption'] ?? '') === 'none' ? ' selected' : '' ?>>None (provider-approved relay only)</option></select></div><div><label>Connection timeout (seconds)</label><input name="mail_timeout" inputmode="numeric" min="5" max="60" value="<?= h($_POST['mail_timeout'] ?? '20') ?>"></div><div class="full"><label class="inline-check"><input type="checkbox" name="mail_auth" value="1" <?= !isset($_POST['mail_auth']) || !empty($_POST['mail_auth']) ? 'checked' : '' ?>>This provider requires SMTP authentication</label></div><div><label>SMTP username</label><input name="mail_username" maxlength="255" autocomplete="username" value="<?= h($_POST['mail_username'] ?? '') ?>"></div><div><label>SMTP password / app password</label><input type="password" name="mail_password" maxlength="1024" autocomplete="new-password" value=""></div><div><label>From email address</label><input type="email" name="mail_from_address" maxlength="190" placeholder="admissions@example.edu.in" value="<?= h($_POST['mail_from_address'] ?? ($_POST['admin_email'] ?? '')) ?>"></div><div><label>From name</label><input name="mail_from_name" maxlength="190" value="<?= h($_POST['mail_from_name'] ?? ($_POST['college_name'] ?? '')) ?>"></div></div></div></div>
<div class="section"><h3>5. Seed options</h3><label class="checkbox"><input type="checkbox" name="demo_data" value="1" <?= !empty($_POST['demo_data']) ? 'checked' : '' ?>><span>Add clearly marked demonstration staff, applicants, workflow records and support tickets. Leave off for production.</span></label><br><label class="checkbox"><input type="checkbox" name="privacy_ack" value="1" required><span>I understand that fictional approval/affiliation details are not published, Aadhaar collection must be legally reviewed and configured, and institutional policies must be approved before real use.</span></label></div>
<p style="margin-top:25px"><button class="button" type="submit" <?= (!$requirementsOk || !$phpMailerAvailable) ? 'disabled' : '' ?>>Install college hub</button></p></form>
<p class="note">The installer writes <span class="code">.env</span> outside the public directory and stores applicant files under protected storage. Reopening this URL after installation will not expose the setup form.</p>
<?php endif ?>
</section></main></body></html>
