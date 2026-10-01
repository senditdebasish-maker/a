<?php

declare(strict_types=1);

use App\Core\MigrationContext;

return [
    'version'=>'003_submission_snapshot_revisions',
    'description'=>'Preserve immutable application submission and correction snapshot revisions.',
    'up'=>static function(MigrationContext $m): void {
        $m->createTable('application_submission_snapshot_revisions',<<<'SQL'
CREATE TABLE application_submission_snapshot_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    configuration_version_id BIGINT UNSIGNED NOT NULL,
    revision_no INT UNSIGNED NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    snapshot_json LONGTEXT NOT NULL,
    snapshot_hash CHAR(64) NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_application_snapshot_revision (application_id,revision_no),
    INDEX idx_snapshot_revision_hash (snapshot_hash),
    CONSTRAINT fk_snapshot_revisions_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_snapshot_revisions_configuration FOREIGN KEY (configuration_version_id) REFERENCES admission_configuration_versions(id),
    CONSTRAINT fk_snapshot_revisions_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        if(!$m->isDryRun()) $m->execute("INSERT INTO application_submission_snapshot_revisions (application_id,configuration_version_id,revision_no,event_type,snapshot_json,snapshot_hash,created_by,created_at) SELECT application_id,configuration_version_id,1,'legacy_submission',snapshot_json,snapshot_hash,NULL,created_at FROM application_submission_snapshots cur WHERE NOT EXISTS (SELECT 1 FROM application_submission_snapshot_revisions revision WHERE revision.application_id=cur.application_id)",[],'Backfill the immutable first revision from current submission snapshots');
    },
    'verify'=>static function(MigrationContext $m): void {
        if($m->isDryRun()){$m->note('PLAN verify immutable submission snapshot revision storage');return;}
        $m->assert($m->tableExists('application_submission_snapshot_revisions'),'Migration verification failed: snapshot revision table is missing.');
        $missing=(int)$m->value('SELECT COUNT(*) FROM application_submission_snapshots cur WHERE NOT EXISTS (SELECT 1 FROM application_submission_snapshot_revisions revision WHERE revision.application_id=cur.application_id)');
        $m->assert($missing===0,'Migration verification failed: one or more existing snapshots lack a revision.');
    },
];
