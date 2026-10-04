<?php

declare(strict_types=1);

/**
 * Read-only admission upgrade/deployment preflight.
 * Usage: php scripts/admissions-preflight.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASE_PATH', dirname(__DIR__));
$composer = BASE_PATH . '/vendor/autoload.php';
if (is_file($composer)) require $composer;
else {
    require BASE_PATH . '/app/Support/helpers.php';
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'App\\')) return;
        $file=BASE_PATH.'/app/'.str_replace('\\','/',substr($class,4)).'.php';
        if (is_file($file)) require $file;
    });
}

use App\Core\Database;
use App\Core\Env;

Env::load(BASE_PATH.'/.env');
$db=Database::get();
$pdo=$db->pdo();
$database=(string)$db->scalar('SELECT DATABASE()');
$warnings=[]; $errors=[];
$tableExists=static fn(string $table): bool => (int)$db->scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table',['table'=>$table])===1;
$columnExists=static fn(string $table,string $column): bool => (int)$db->scalar('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column',['table'=>$table,'column'=>$column])===1;

foreach (['schema_migrations','admission_cycles','cycle_programs','applications','application_preferences','mail_logs'] as $table) if (!$tableExists($table)) $errors[]="Required baseline table {$table} is missing.";
if ($errors) {
    foreach ($errors as $message) fwrite(STDERR,"ERROR: {$message}\n");
    exit(1);
}

$version=(string)$db->scalar('SELECT VERSION()');
$tableCount=(int)$db->scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'");
$migrations=$db->all('SELECT version, applied_at FROM schema_migrations ORDER BY id');
$counts=[];
foreach (['admission_cycles','cycle_programs','applications','application_preferences','application_documents','payments'] as $table) $counts[$table]=(int)$db->scalar("SELECT COUNT(*) FROM `{$table}`");

$invalidDates=(int)$db->scalar('SELECT COUNT(*) FROM admission_cycles WHERE ends_at <= starts_at OR (correction_deadline IS NOT NULL AND correction_deadline < ends_at)');
if ($invalidDates) $errors[]="{$invalidDates} admission cycle(s) have invalid date ordering.";
$unknownCycleStates=$db->all("SELECT status, COUNT(*) AS total FROM admission_cycles WHERE status NOT IN ('draft','open','published','closed','processing','archived') GROUP BY status");
foreach ($unknownCycleStates as $row) $warnings[]="Unknown cycle status {$row['status']} appears on {$row['total']} record(s).";
$unknownApplicationStates=$db->all("SELECT status, COUNT(*) AS total FROM applications WHERE status NOT IN ('draft','submitted','resubmitted','eligibility_check','under_review','correction_required','approved','verified','waitlisted','selected','payment_pending','fee_verified','admitted','offer_expired','not_selected','rejected','withdrawn') GROUP BY status");
foreach ($unknownApplicationStates as $row) $warnings[]="Unknown application status {$row['status']} appears on {$row['total']} record(s).";
$capacityMismatch=$db->all('SELECT cp.id, cp.seat_capacity, COALESCE(SUM(sm.seats),0) AS matrix_total FROM cycle_programs cp LEFT JOIN seat_matrix sm ON sm.cycle_program_id=cp.id GROUP BY cp.id HAVING matrix_total <> cp.seat_capacity');
foreach ($capacityMismatch as $row) $warnings[]="Cycle-program #{$row['id']} capacity {$row['seat_capacity']} differs from seat matrix total {$row['matrix_total']}.";
$sensitive=(int)$db->scalar("SELECT COUNT(*) FROM mail_logs WHERE template_key IN ('verify_email','password_reset','staff_mfa') AND body_html IS NOT NULL");
if ($sensitive) $warnings[]="{$sensitive} authentication-secret mail body/bodies require approved migration redaction.";
$legacySelected=(int)$db->scalar("SELECT COUNT(*) FROM applications WHERE status IN ('selected','fee_verified','admitted')" . ($columnExists('applications','selected_cycle_program_id') ? ' AND selected_cycle_program_id IS NULL' : ''));
if ($legacySelected) $warnings[]="{$legacySelected} selected/admitted legacy application(s) require selected-program reconciliation.";
if ($tableExists('seat_allocations')) {
    $seatMismatch=(int)$db->scalar('SELECT COUNT(*) FROM seat_matrix sm WHERE sm.filled_seats <> (SELECT COUNT(*) FROM seat_allocations sa WHERE sa.seat_matrix_id=sm.id AND sa.is_active=1)');
    if ($seatMismatch) $warnings[]="{$seatMismatch} seat matrix row(s) disagree with active allocation records.";
}
$missingFiles=0;
foreach ($db->all('SELECT path FROM application_documents') as $document) if (!is_file(BASE_PATH.'/storage/private/'.ltrim((string)$document['path'],'/'))) $missingFiles++;
if ($missingFiles) $warnings[]="{$missingFiles} application document database record(s) do not have a local protected file. Confirm shared/deployed storage before migration.";

$pending=[];
foreach (glob(BASE_PATH.'/database/migrations/*.php')?:[] as $file) if (!(int)$db->scalar('SELECT COUNT(*) FROM schema_migrations WHERE version=:version',['version'=>basename($file,'.php')])) $pending[]=basename($file,'.php');

echo "Admission preflight (read only)\n";
echo "Database: {$database}\nServer: {$version}\nTables: {$tableCount}\n";
echo 'Applied migrations: '.implode(', ',array_column($migrations,'version'))."\n";
echo 'Pending migrations: '.($pending?implode(', ',$pending):'none')."\n";
foreach ($counts as $table=>$count) echo str_pad($table,28)." {$count}\n";
foreach ($warnings as $message) echo "WARNING: {$message}\n";
foreach ($errors as $message) echo "ERROR: {$message}\n";
echo $errors ? "Preflight failed.\n" : "Preflight completed".($warnings?' with warnings.':' successfully.')."\n";
exit($errors?1:0);
