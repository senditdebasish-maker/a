<?php
declare(strict_types=1);

/**
 * CI system-integrity checks for the complete Release 1 foundation.
 * Runs only in CI after the database smoke/seed phase.
 */
if (PHP_SAPI !== 'cli' || getenv('CI') !== 'true') {
    fwrite(STDERR, "This integrity test runs only in CI.\n");
    exit(2);
}

define('BASE_PATH', dirname(__DIR__));

$required = [
    'public/index.php',
    'routes/web.php',
    'database/schema.mysql.sql',
    'database/migrations/002_admission_management.php',
    'database/migrations/003_submission_snapshot_revisions.php',
    'app/Controllers/Admin/AdmissionController.php',
    'app/Controllers/Admin/ApplicationController.php',
    'app/Controllers/Admin/ReportController.php',
    'app/Controllers/ApplicantController.php',
    'app/Services/AdmissionCycleService.php',
    'app/Services/ApplicationWorkflowService.php',
    'app/Services/EligibilityService.php',
    'app/Services/AdmissionFeeService.php',
    'app/Services/SeatAllocationService.php',
    'app/Services/UploadService.php',
    'app/Services/BackupService.php',
    'resources/views/admin/admissions/index.php',
    'resources/views/admin/admissions/show.php',
    'resources/views/admin/applications/index.php',
    'resources/views/admin/applications/show.php',
    'resources/views/student/application.php',
    'resources/views/student/dashboard.php',
];

foreach ($required as $path) {
    if (!is_file(BASE_PATH . '/' . $path)) {
        throw new RuntimeException("Missing required system file: {$path}");
    }
}

$text = static function (string $path): string {
    return (string) file_get_contents(BASE_PATH . '/' . $path);
};

$routes = $text('routes/web.php');
$mustContainRoutes = [
    "GET', '/admissions'",
    "GET', '/admissions/{slug}'",
    "GET', '/admissions/{slug}/apply'",
    "GET', '/admin/dashboard'",
    "GET', '/admin/admissions'",
    "POST', '/admin/admissions/{id}/publish'",
    "GET', '/admin/applications'",
    "GET', '/admin/reports'",
    "GET', '/student/dashboard'",
    "POST', '/student/application/submit'",
    "POST', '/student/application/document'",
];

foreach ($mustContainRoutes as $needle) {
    if (!str_contains($routes, $needle)) {
        throw new RuntimeException("Missing critical route: {$needle}");
    }
}

$applicationController = $text('app/Controllers/Admin/ApplicationController.php');
foreach ([
    'status_version',
    'application_corrections',
    'application_document_versions',
    'application_fee_assessments',
    'SeatAllocationService',
    'AuditService::log',
] as $needle) {
    if (!str_contains($applicationController, $needle)) {
        throw new RuntimeException("Application review safety/workflow hook missing: {$needle}");
    }
}

$cycleService = $text('app/Services/AdmissionCycleService.php');
foreach ([
    'readiness',
    'configuration_version',
    'duplicate',
    'publish',
    'effectiveStatus',
] as $needle) {
    if (!str_contains($cycleService, $needle)) {
        throw new RuntimeException("Admission lifecycle capability missing: {$needle}");
    }
}

$schema = $text('database/schema.mysql.sql');
foreach ([
    'CREATE TABLE application_submission_snapshots',
    'CREATE TABLE application_submission_snapshot_revisions',
    'CREATE TABLE application_corrections',
    'CREATE TABLE application_fee_assessments',
    'CREATE TABLE seat_allocations',
] as $needle) {
    if (!str_contains($schema, $needle)) {
        throw new RuntimeException("Schema capability missing: {$needle}");
    }
}

echo "System integrity checks passed: routes, workflow guards, lifecycle services and core schema surfaces are present.\n";
