<?php

declare(strict_types=1);

// Run before launch: php scripts/preflight.php
define('BASE_PATH', dirname(__DIR__));
$composer = BASE_PATH . '/vendor/autoload.php';
if (is_file($composer)) require $composer;
else {
    require BASE_PATH . '/app/Support/helpers.php';
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'App\\')) return;
        $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) require $file;
    });
}

use App\Core\Database;
use App\Core\Env;

Env::load(BASE_PATH . '/.env');
$checks = [];
$check = static function (string $name, bool $passed, string $detail = '') use (&$checks): void { $checks[] = compact('name', 'passed', 'detail'); };
$check('PHP 8.1+', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION);
foreach (['pdo_mysql','mbstring','openssl','fileinfo','json','zip'] as $extension) $check('Extension: ' . $extension, extension_loaded($extension));
$check('Application installed', filter_var(env('APP_INSTALLED', false), FILTER_VALIDATE_BOOL));
$check('Debug mode disabled', !filter_var(env('APP_DEBUG', true), FILTER_VALIDATE_BOOL));
$check('Strong application key', str_starts_with((string) env('APP_KEY', ''), 'base64:') && strlen((string) env('APP_KEY')) >= 50);
$check('HTTPS application URL', str_starts_with((string) env('APP_URL', ''), 'https://'), (string) env('APP_URL', 'not configured'));
$check('Secure session cookies', filter_var(env('SESSION_SECURE', false), FILTER_VALIDATE_BOOL));
$check('SMTP email configured', env('MAIL_DRIVER') === 'smtp', 'Current driver: ' . env('MAIL_DRIVER', 'log'));
$check('Staff MFA required', filter_var(env('REQUIRE_STAFF_MFA', false), FILTER_VALIDATE_BOOL));
foreach (['private','backups','logs','cache'] as $folder) $check('Writable storage/' . $folder, is_dir(BASE_PATH . '/storage/' . $folder) && is_writable(BASE_PATH . '/storage/' . $folder));
try {
    $db = Database::get();
    $check('Database connection', (bool) $db->scalar('SELECT 1'));
    $college = (string) $db->scalar("SELECT value FROM settings WHERE key_name = 'college_name'");
    $check('College identity configured', $college !== '' && $college !== 'Netaji College of Pharmacy', $college . ' (confirm this is the intended legal name)');
    $placeholderEmails = (int) $db->scalar("SELECT COUNT(*) FROM settings WHERE value LIKE '%example.edu.in%'");
    $check('Placeholder email addresses removed', $placeholderEmails === 0, $placeholderEmails . ' setting(s) still use example.edu.in');
    $openCycles = (int) $db->scalar("SELECT COUNT(*) FROM admission_cycles WHERE status = 'open' AND starts_at <= NOW() AND ends_at >= NOW()");
    $check('An active admission cycle is open', $openCycles > 0, $openCycles . ' current open cycle(s)');
    $backups = (int) $db->scalar("SELECT COUNT(*) FROM backup_logs WHERE status = 'completed'");
    $check('At least one backup created', $backups > 0, $backups . ' completed backup(s)');
} catch (Throwable $exception) {
    $check('Database connection', false, $exception->getMessage());
}

$failed = 0;
echo "\nNetaji Admission Hub — production preflight\n" . str_repeat('=', 46) . "\n";
foreach ($checks as $item) {
    echo ($item['passed'] ? '[PASS] ' : '[FAIL] ') . $item['name'];
    if ($item['detail'] !== '') echo ' — ' . $item['detail'];
    echo "\n";
    if (!$item['passed']) $failed++;
}
echo str_repeat('-', 46) . "\n" . ($failed ? "{$failed} check(s) require attention. Do not launch yet.\n" : "All automated checks passed. Complete the manual checklist too.\n");
exit($failed ? 1 : 0);
