<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('CI') !== 'true') {
    fwrite(STDERR, "This smoke test runs only in CI.\n");
    exit(2);
}
require dirname(__DIR__) . '/database/Seeder.php';

$pdo = new PDO(
    'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'ncp_test') . ';charset=utf8mb4',
    getenv('DB_USERNAME') ?: 'root', getenv('DB_PASSWORD') ?: 'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
(new \Database\Seeder($pdo))->run([
    'first_name' => 'CI', 'last_name' => 'Administrator', 'email' => 'ci-admin@example.test',
    'mobile' => '9000000000', 'password' => 'CI-Temporary#2027',
], 'CI Pharmacy College', true);

$expectations = [
    'roles' => 14, 'permissions' => 26, 'users' => 8, 'programs' => 1,
    'admission_cycles' => 1, 'applications' => 5, 'pages' => 3,
    'document_types' => 11, 'support_tickets' => 1,
];
foreach ($expectations as $table => $minimum) {
    $actual = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    if ($actual < $minimum) throw new RuntimeException("{$table}: expected at least {$minimum}, got {$actual}");
    echo "PASS {$table}: {$actual}\n";
}
$adminPermissions = (int) $pdo->query("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.slug = 'super-admin'")->fetchColumn();
if ($adminPermissions !== (int) $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn()) throw new RuntimeException('Super Admin permission matrix is incomplete.');
$statusRows = (int) $pdo->query('SELECT COUNT(*) FROM application_status_history')->fetchColumn();
if ($statusRows < 5) throw new RuntimeException('Demo workflow status history was not seeded.');
echo "Database schema and seeder smoke test passed.\n";
