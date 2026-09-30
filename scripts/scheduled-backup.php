<?php

declare(strict_types=1);

// Schedule with Windows Task Scheduler or cron. This command never exposes a backup over HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
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
use App\Services\BackupService;

Env::load(BASE_PATH . '/.env');
date_default_timezone_set((string) config('app.timezone', 'Asia/Kolkata'));
if (!filter_var(env('APP_INSTALLED', false), FILTER_VALIDATE_BOOL)) { fwrite(STDERR, "Application is not installed.\n"); exit(2); }

$options = getopt('', ['retention-days::']);
$retention = isset($options['retention-days']) ? (int) $options['retention-days'] : (int) env('BACKUP_RETENTION_DAYS', 30);
if ($retention < 1 || $retention > 3650) { fwrite(STDERR, "Retention must be between 1 and 3650 days.\n"); exit(2); }

$lockDirectory = BASE_PATH . '/storage/cache';
if (!is_dir($lockDirectory)) mkdir($lockDirectory, 0770, true);
$lock = fopen($lockDirectory . '/scheduled-backup.lock', 'c+');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "Another backup process is already running.\n"); exit(3); }
ftruncate($lock, 0); fwrite($lock, (string) getmypid()); fflush($lock);

$exitCode = 0;
try {
    $result = (new BackupService())->create();
    echo sprintf("Created %s (%d bytes)\nSHA-256: %s\n", $result['filename'], $result['size_bytes'], $result['checksum_sha256']);

    $directory = realpath(BASE_PATH . '/storage/backups');
    $cutoff = date('Y-m-d H:i:s', strtotime('-' . $retention . ' days'));
    $pruned = 0;
    foreach (Database::get()->all("SELECT * FROM backup_logs WHERE status = 'completed' AND completed_at < :cutoff", ['cutoff' => $cutoff]) as $backup) {
        $path = $directory ? realpath($directory . '/' . basename((string) $backup['filename'])) : false;
        if ($path && str_starts_with($path, $directory . DIRECTORY_SEPARATOR) && is_file($path) && !unlink($path)) {
            fwrite(STDERR, 'Warning: could not prune ' . basename($path) . "\n");
            continue;
        }
        Database::get()->update('backup_logs', ['status' => 'pruned', 'error_message' => 'Removed by scheduled retention policy after ' . $retention . ' days.'], 'id = :id', ['id' => $backup['id']]);
        $pruned++;
    }
    echo "Retention: {$retention} days; pruned {$pruned} archive(s).\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Backup failed: ' . $exception->getMessage() . "\n");
    $exitCode = 1;
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
exit($exitCode);
