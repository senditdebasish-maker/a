<?php

declare(strict_types=1);

/**
 * Guarded existing-install migration runner.
 *
 * Preview: php scripts/migrate.php --dry-run
 * Apply:   php scripts/migrate.php --confirm=APPLY --backup-confirmed
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
$composer = BASE_PATH . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    require BASE_PATH . '/app/Support/helpers.php';
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'App\\')) return;
        $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) require $file;
    });
}

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\MigrationContext;

Env::load(BASE_PATH . '/.env');
date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Kolkata'));

$dryRun = in_array('--dry-run', $argv, true);
$apply = in_array('--confirm=APPLY', $argv, true);
$backupConfirmed = in_array('--backup-confirmed', $argv, true);
if (!$dryRun && (!$apply || !$backupConfirmed)) {
    fwrite(STDERR, "Safe migration runner\n\n");
    fwrite(STDERR, "Preview: php scripts/migrate.php --dry-run\n");
    fwrite(STDERR, "Apply:   php scripts/migrate.php --confirm=APPLY --backup-confirmed\n\n");
    fwrite(STDERR, "Before applying, create and independently verify an encrypted backup.\n");
    exit(2);
}
if ($dryRun && $apply) {
    fwrite(STDERR, "Choose either --dry-run or --confirm=APPLY, not both.\n");
    exit(2);
}
if (!Env::get('APP_INSTALLED', false)) {
    fwrite(STDERR, "APP_INSTALLED is not true. Use the installer for a new database.\n");
    exit(2);
}

$pdo = Database::get()->pdo();
if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    fwrite(STDERR, "Only MySQL/MariaDB production databases are supported.\n");
    exit(2);
}
$database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$ledgerExists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $pdo->quote($database) . " AND table_name = 'schema_migrations'")->fetchColumn();
if ($ledgerExists !== 1) {
    fwrite(STDERR, "schema_migrations is missing. Refusing to guess the database baseline.\n");
    exit(1);
}
$baseline = $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = '001_initial_schema'")->fetchColumn();
if ((int) $baseline !== 1) {
    fwrite(STDERR, "The 001_initial_schema baseline is not recorded. Migration stopped.\n");
    exit(1);
}

$files = glob(BASE_PATH . '/database/migrations/*.php') ?: [];
sort($files, SORT_STRING);
if (!$files) {
    echo "No migration files found.\n";
    exit(0);
}

$hasChecksum = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = " . $pdo->quote($database) . " AND table_name = 'schema_migrations' AND column_name = 'checksum_sha256'")->fetchColumn() === 1;
$columns = $hasChecksum ? 'version, checksum_sha256' : 'version, NULL AS checksum_sha256';
$appliedRows = $pdo->query("SELECT {$columns} FROM schema_migrations")->fetchAll(PDO::FETCH_ASSOC);
$applied = [];
foreach ($appliedRows as $row) $applied[(string) $row['version']] = $row['checksum_sha256'];

$migrations = [];
foreach ($files as $file) {
    $definition = require $file;
    if (!is_array($definition) || empty($definition['version']) || !is_callable($definition['up'] ?? null) || !is_callable($definition['verify'] ?? null)) {
        throw new RuntimeException('Invalid migration definition: ' . basename($file));
    }
    if ((string) $definition['version'] !== basename($file, '.php')) {
        throw new RuntimeException('Migration version must match its filename: ' . basename($file));
    }
    $checksum = hash_file('sha256', $file);
    if (array_key_exists($definition['version'], $applied)) {
        $recorded = (string) ($applied[$definition['version']] ?? '');
        if ($recorded !== '' && !hash_equals($recorded, $checksum)) {
            throw new RuntimeException('Applied migration checksum mismatch: ' . $definition['version']);
        }
        continue;
    }
    $migrations[] = [$file, $definition, $checksum];
}

if (!$migrations) {
    echo "Database schema is up to date.\n";
    exit(0);
}

echo ($dryRun ? 'DRY RUN' : 'APPLY') . " against database {$database}\n";
echo 'Pending: ' . implode(', ', array_map(static fn (array $item): string => $item[1]['version'], $migrations)) . "\n\n";

$lock = (int) $pdo->query("SELECT GET_LOCK('ncp_schema_migration', 10)")->fetchColumn();
if ($lock !== 1) {
    fwrite(STDERR, "Could not acquire the database migration lock.\n");
    exit(1);
}
$maintenance = BASE_PATH . '/storage/maintenance.lock';
$exitCode = 0;
try {
    if (!$dryRun) file_put_contents($maintenance, date(DATE_ATOM) . " schema migration\n", LOCK_EX);
    $batch = 1;
    if ($hasChecksum) $batch = 1 + (int) $pdo->query('SELECT COALESCE(MAX(batch), 0) FROM schema_migrations')->fetchColumn();

    foreach ($migrations as [$file, $definition, $checksum]) {
        echo "==> {$definition['version']}: {$definition['description']}\n";
        $started = microtime(true);
        $context = new MigrationContext($pdo, $dryRun);
        ($definition['up'])($context);
        ($definition['verify'])($context);
        foreach ($context->messages() as $message) echo "    {$message}\n";
        $elapsed = (int) round((microtime(true) - $started) * 1000);
        if (!$dryRun) {
            $ledgerHasChecksum = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = " . $pdo->quote($database) . " AND table_name = 'schema_migrations' AND column_name = 'checksum_sha256'")->fetchColumn() === 1;
            if ($ledgerHasChecksum) {
                $statement = $pdo->prepare('INSERT INTO schema_migrations (version, description, checksum_sha256, batch, execution_ms, applied_at) VALUES (:version,:description,:checksum,:batch,:elapsed,:applied)');
                $statement->execute(['version'=>$definition['version'],'description'=>$definition['description'],'checksum'=>$checksum,'batch'=>$batch,'elapsed'=>$elapsed,'applied'=>date('Y-m-d H:i:s')]);
            } else {
                $statement = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:version,:applied)');
                $statement->execute(['version'=>$definition['version'],'applied'=>date('Y-m-d H:i:s')]);
            }
        }
        echo '    ' . ($dryRun ? 'Preview complete' : 'Applied') . " in {$elapsed} ms\n\n";
    }
    echo $dryRun ? "Dry run complete; no database changes were made.\n" : "All migrations applied and verified.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Migration failed: {$exception->getMessage()}\n");
    fwrite(STDERR, "The failed migration was not recorded. Resolve the cause, inspect the database, and rerun; operations are idempotent.\n");
    $exitCode = 1;
} finally {
    if (!$dryRun) @unlink($maintenance);
    try { $pdo->query("SELECT RELEASE_LOCK('ncp_schema_migration')"); } catch (Throwable) {}
}
exit($exitCode);
