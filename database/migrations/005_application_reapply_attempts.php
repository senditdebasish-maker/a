<?php

declare(strict_types=1);

use App\Core\MigrationContext;

return [
    'version' => '005_application_reapply_attempts',
    'description' => 'Allow data-preserving reapplication attempts after rejection while retaining immutable prior records.',
    'up' => static function (MigrationContext $m): void {
        $m->addColumn('applications', 'attempt_no', 'SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER admission_cycle_id');
        $m->addColumn('applications', 'reapplied_from_application_id', 'BIGINT UNSIGNED NULL AFTER attempt_no');

        // Add the replacement user-leading index before dropping the legacy one: existing
        // foreign keys may currently depend on uq_user_cycle as their supporting index.
        $m->addIndex('applications', 'idx_applications_user_cycle', '`user_id`,`admission_cycle_id`,`created_at`');
        if ($m->indexExists('applications', 'uq_user_cycle')) {
            $m->execute('ALTER TABLE applications DROP INDEX uq_user_cycle', [], 'DROP obsolete one-application-per-cycle unique index');
        } else {
            $m->note('SKIP obsolete index uq_user_cycle is already absent');
        }
        $m->addIndex('applications', 'uq_user_cycle_attempt', '`user_id`,`admission_cycle_id`,`attempt_no`', true);
        $m->addIndex('applications', 'idx_applications_reapplied_from', '`reapplied_from_application_id`');
        $m->addForeignKey('applications', 'fk_applications_reapplied_from', 'FOREIGN KEY (`reapplied_from_application_id`) REFERENCES `applications` (`id`) ON DELETE SET NULL');
    },
    'verify' => static function (MigrationContext $m): void {
        if ($m->isDryRun()) {
            $m->note('PLAN verify reapplication attempt columns, lineage key, and replacement uniqueness contract');
            return;
        }
        $m->assert($m->columnExists('applications', 'attempt_no'), 'Migration verification failed: applications.attempt_no is missing.');
        $m->assert($m->columnExists('applications', 'reapplied_from_application_id'), 'Migration verification failed: applications.reapplied_from_application_id is missing.');
        $m->assert(!$m->indexExists('applications', 'uq_user_cycle'), 'Migration verification failed: obsolete one-application-per-cycle index remains.');
        $m->assert($m->indexExists('applications', 'uq_user_cycle_attempt'), 'Migration verification failed: per-attempt uniqueness index is missing.');
        $m->assert($m->foreignKeyExists('fk_applications_reapplied_from'), 'Migration verification failed: reapplication lineage foreign key is missing.');
        $invalidAttempts = (int) $m->value('SELECT COUNT(*) FROM applications WHERE attempt_no < 1');
        $m->assert($invalidAttempts === 0, 'Migration verification failed: an invalid application attempt number exists.');
    },
];
