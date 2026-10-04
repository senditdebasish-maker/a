<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$read = static function (string $relative) use ($root, &$failures): string {
    $path = $root . '/' . $relative;
    $contents = is_file($path) ? file_get_contents($path) : false;
    if ($contents === false) {
        $failures[] = "Missing or unreadable root-deployment file: {$relative}";
        return '';
    }
    return $contents;
};

$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$front = $read('index.php');
$htaccess = $read('.htaccess');
$installer = $read('public/install/index.php');
$launcher = $read('scripts/install-xampp-root.ps1');
$guide = $read('docs/XAMPP-INSTALL.md');

$expect(str_contains($front, "require __DIR__ . '/public/index.php';"), 'Root index.php must delegate to the existing public front controller.');

foreach (['app', 'config', 'database', 'docs', 'resources', 'routes', 'scripts', 'storage', 'vendor'] as $privateDirectory) {
    $expect(str_contains($htaccess, $privateDirectory), "Root .htaccess does not protect {$privateDirectory}/.");
}
$expect(str_contains($htaccess, '^assets'), 'Root .htaccess must expose public assets at /assets.');
$expect(str_contains($htaccess, '^install'), 'Root .htaccess must expose the installer at /install.');
$expect(str_contains($htaccess, 'index.php [QSA,L]'), 'Root .htaccess must route application requests through index.php.');
$expect(str_contains($htaccess, 'NCP_ROOT_LAUNCHER:1'), 'Root installer rewrite must identify the browser-visible root URL.');
$expect(str_contains($htaccess, 'X-Content-Type-Options'), 'Root deployment must retain the public security headers.');

$expect(str_contains($installer, "isset(\$_SERVER['REDIRECT_NCP_ROOT_LAUNCHER'])"), 'Installer must recognise the root-launcher rewrite environment.');
$expect(str_contains($installer, "str_ends_with(\$path, '/public')"), 'Installer must remove an internal /public suffix from APP_URL.');

$expect(str_contains($launcher, 'NCP_ROOT_LAUNCHER'), 'PowerShell launcher must mark the files it owns.');
$expect(str_contains($launcher, 'storage\\backups\\root-launcher-'), 'PowerShell launcher must back up existing htdocs entry files.');
$expect(str_contains($launcher, 'APP_URL='), 'PowerShell launcher must update APP_URL for an existing installation.');
$expect(str_contains($launcher, 'THE_REQUEST'), 'PowerShell launcher must block direct requests to the project folder.');
$expect(str_contains($launcher, 'public/assets'), 'PowerShell launcher must expose public assets at the root URL.');
$expect(str_contains($launcher, 'public/install'), 'PowerShell launcher must expose the installer at the root URL.');
$expect(str_contains($launcher, 'X-Content-Type-Options'), 'PowerShell launcher must retain the public security headers.');

$expect(str_contains($guide, 'http://localhost/install/'), 'XAMPP guide must document the root installer URL.');
$expect(str_contains($guide, 'http://localhost/'), 'XAMPP guide must document the application root URL.');
$expect(!str_contains($guide, 'http://localhost/netaji-hub/public/install/'), 'XAMPP guide still advertises the obsolete folder/public installer URL.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "[FAIL] {$failure}\n");
    }
    exit(1);
}

echo "XAMPP root-deployment source contracts passed.\n";
