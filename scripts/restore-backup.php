<?php

declare(strict_types=1);

/**
 * Disaster-recovery CLI only.
 * Usage: php scripts/restore-backup.php storage/backups/file.zip.enc --confirm=RESTORE
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
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
$file = $argv[1] ?? '';
$confirmed = in_array('--confirm=RESTORE', $argv, true);
if (!$confirmed || $file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php scripts/restore-backup.php /path/to/ncp-backup.zip.enc --confirm=RESTORE\n");
    fwrite(STDERR, "Run only in an isolated maintenance window after taking a separate safety backup.\n");
    exit(2);
}
if (!class_exists(ZipArchive::class)) { fwrite(STDERR, "PHP ZIP extension is required.\n"); exit(2); }

$maintenance = BASE_PATH . '/storage/maintenance.lock';
$tempDir = BASE_PATH . '/storage/cache/restore-' . bin2hex(random_bytes(6));
$tempZip = $tempDir . '/backup.zip';
$exitCode = 0;
try {
    if (!is_dir($tempDir) && !mkdir($tempDir, 0770, true) && !is_dir($tempDir)) throw new RuntimeException('Could not create restore workspace.');
    file_put_contents($maintenance, date(DATE_ATOM) . "\n", LOCK_EX);
    $payload = file_get_contents($file);
    if ($payload === false || !str_starts_with($payload, 'NCPBACKUP1') || strlen($payload) < 39) throw new RuntimeException('Invalid encrypted backup format.');
    $payload = substr($payload, 10);
    $iv = substr($payload, 0, 12); $tag = substr($payload, 12, 16); $cipher = substr($payload, 28);
    $key = hash('sha256', (string) config('app.key'), true);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) throw new RuntimeException('Backup authentication failed. Confirm that this installation uses the original APP_KEY.');
    if (file_put_contents($tempZip, $plain, LOCK_EX) === false) throw new RuntimeException('Could not write temporary archive.');
    unset($plain, $payload, $cipher);

    $zip = new ZipArchive();
    if ($zip->open($tempZip) !== true) throw new RuntimeException('Decrypted archive is not a valid ZIP file.');
    if ($zip->locateName('database.sql') === false || $zip->locateName('manifest.json') === false) throw new RuntimeException('Archive manifest or database dump is missing.');
    if (!$zip->extractTo($tempDir . '/extracted')) throw new RuntimeException('Could not extract backup archive.');
    $zip->close();
    $manifest = json_decode((string) file_get_contents($tempDir . '/extracted/manifest.json'), true);
    if (!is_array($manifest) || ($manifest['product'] ?? '') !== 'Netaji Admission Hub') throw new RuntimeException('Backup manifest does not match this product.');
    $sql = file_get_contents($tempDir . '/extracted/database.sql');
    if ($sql === false) throw new RuntimeException('Could not read database dump.');

    echo "Restoring database from authenticated archive…\n";
    Database::get()->pdo()->exec($sql);
    $source = $tempDir . '/extracted/private';
    if (is_dir($source)) {
        echo "Restoring protected files…\n";
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $target = BASE_PATH . '/storage/private/' . $relative;
            if ($item->isDir()) { if (!is_dir($target)) mkdir($target, 0770, true); }
            else { if (!is_dir(dirname($target))) mkdir(dirname($target), 0770, true); copy($item->getPathname(), $target); chmod($target, 0640); }
        }
    }
    echo "Restore completed. Remove maintenance mode, sign in, run preflight, verify record counts and perform workflow checks.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Restore failed: ' . $exception->getMessage() . "\n");
    $exitCode = 1;
} finally {
    @unlink($maintenance);
    if (is_dir($tempDir)) {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($tempDir);
    }
}
exit($exitCode);
