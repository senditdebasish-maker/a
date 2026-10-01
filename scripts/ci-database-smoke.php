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
    'roles' => 14, 'permissions' => 36, 'users' => 8, 'programs' => 1,
    'admission_cycles' => 1, 'applications' => 5, 'pages' => 3,
    'document_types' => 11, 'support_tickets' => 1,
    'admission_categories' => 6, 'admission_form_sections' => 5, 'admission_form_fields' => 20,
    'admission_configuration_versions' => 1, 'application_submission_snapshots' => 5,
    'admission_fee_rules' => 2, 'application_fee_assessments' => 5, 'seat_allocations' => 2,
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
$tableCount = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchColumn();
if ($tableCount !== 63) throw new RuntimeException("Expected 63 schema tables, got {$tableCount}");
$migrationCount = (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version IN ('001_initial_schema','002_admission_management')")->fetchColumn();
if ($migrationCount !== 2) throw new RuntimeException('Clean-install migration ledger is incomplete.');
$unversioned = (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE configuration_version_id IS NULL")->fetchColumn();
if ($unversioned !== 0) throw new RuntimeException('Seeded applications are missing configuration versions.');
$snapshotMismatch = (int) $pdo->query("SELECT COUNT(*) FROM application_submission_snapshots s JOIN applications a ON a.id = s.application_id WHERE s.configuration_version_id <> a.configuration_version_id")->fetchColumn();
if ($snapshotMismatch !== 0) throw new RuntimeException('Submission snapshot/configuration version mismatch.');
$seatMismatch = (int) $pdo->query("SELECT COUNT(*) FROM seat_matrix sm WHERE sm.filled_seats <> (SELECT COUNT(*) FROM seat_allocations sa WHERE sa.seat_matrix_id = sm.id AND sa.is_active = 1)")->fetchColumn();
if ($seatMismatch !== 0) throw new RuntimeException('Seat allocation counters are inconsistent.');
echo "Database schema and seeder smoke test passed.\n";
