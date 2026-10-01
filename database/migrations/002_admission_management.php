<?php

declare(strict_types=1);

use App\Core\MigrationContext;
use PDO;

return [
    'version' => '002_admission_management',
    'description' => 'Add versioned admission configuration, dynamic forms, corrections, fee assessments, seat allocations, and secret-safe mail logs.',
    'up' => static function (MigrationContext $m): void {
        foreach ([
            ['schema_migrations', 'description', 'VARCHAR(500) NULL'],
            ['schema_migrations', 'checksum_sha256', 'CHAR(64) NULL'],
            ['schema_migrations', 'batch', 'INT UNSIGNED NOT NULL DEFAULT 1'],
            ['schema_migrations', 'execution_ms', 'INT UNSIGNED NULL'],
            ['admission_cycles', 'slug', 'VARCHAR(190) NULL'],
            ['admission_cycles', 'summary', 'TEXT NULL'],
            ['admission_cycles', 'prospectus_path', 'VARCHAR(500) NULL'],
            ['admission_cycles', 'prospectus_original_name', 'VARCHAR(255) NULL'],
            ['admission_cycles', 'prospectus_mime_type', 'VARCHAR(100) NULL'],
            ['admission_cycles', 'published_at', 'DATETIME NULL'],
            ['admission_cycles', 'published_by', 'BIGINT UNSIGNED NULL'],
            ['admission_cycles', 'closed_at', 'DATETIME NULL'],
            ['admission_cycles', 'archived_at', 'DATETIME NULL'],
            ['admission_cycles', 'closing_soon_hours', 'SMALLINT UNSIGNED NOT NULL DEFAULT 72'],
            ['admission_cycles', 'configuration_version', 'INT UNSIGNED NOT NULL DEFAULT 0'],
            ['admission_cycles', 'application_fee_strategy', "VARCHAR(40) NOT NULL DEFAULT 'first_preference'"],
            ['admission_cycles', 'max_program_preferences', 'SMALLINT UNSIGNED NOT NULL DEFAULT 3'],
            ['applications', 'configuration_version_id', 'BIGINT UNSIGNED NULL'],
            ['applications', 'selected_cycle_program_id', 'BIGINT UNSIGNED NULL'],
            ['applications', 'resubmitted_at', 'DATETIME NULL'],
            ['applications', 'decision_at', 'DATETIME NULL'],
            ['applications', 'withdrawn_at', 'DATETIME NULL'],
            ['applications', 'status_version', 'INT UNSIGNED NOT NULL DEFAULT 0'],
            ['application_documents', 'revision_no', 'INT UNSIGNED NOT NULL DEFAULT 1'],
            ['application_documents', 'uploaded_by', 'BIGINT UNSIGNED NULL'],
            ['payments', 'fee_assessment_id', 'BIGINT UNSIGNED NULL'],
            ['mail_logs', 'body_checksum_sha256', 'CHAR(64) NULL'],
            ['mail_logs', 'sensitive_redacted', 'TINYINT(1) NOT NULL DEFAULT 0'],
            ['mail_logs', 'correlation_id', 'VARCHAR(64) NULL'],
            ['mail_logs', 'metadata_json', 'LONGTEXT NULL'],
        ] as [$table, $column, $definition]) {
            $m->addColumn($table, $column, $definition);
        }
        $m->modifyColumn('mail_logs', 'body_html', 'LONGTEXT NULL');

        $m->addIndex('admission_cycles', 'uq_admission_cycles_slug', '`slug`', true);
        $m->addIndex('applications', 'idx_applications_cycle_status', '`admission_cycle_id`, `status`, `submitted_at`');
        $m->addIndex('applications', 'idx_applications_selected_program', '`selected_cycle_program_id`');
        $m->addIndex('payments', 'idx_payments_type_status', '`type`, `status`, `created_at`');
        $m->addIndex('mail_logs', 'idx_mail_correlation', '`correlation_id`');
        $m->addForeignKey('admission_cycles', 'fk_cycles_publisher', 'FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL');

        $m->createTable('admission_categories', <<<'SQL'
CREATE TABLE admission_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    is_reserved TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_admission_categories_status (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('admission_configuration_versions', <<<'SQL'
CREATE TABLE admission_configuration_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admission_cycle_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    snapshot_json LONGTEXT NOT NULL,
    snapshot_hash CHAR(64) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'published',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_admission_config_version (admission_cycle_id, version_no),
    CONSTRAINT fk_config_versions_cycle FOREIGN KEY (admission_cycle_id) REFERENCES admission_cycles(id),
    CONSTRAINT fk_config_versions_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_config_versions_hash (snapshot_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('admission_form_sections', <<<'SQL'
CREATE TABLE admission_form_sections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admission_cycle_id BIGINT UNSIGNED NOT NULL,
    section_key VARCHAR(100) NOT NULL,
    title VARCHAR(180) NOT NULL,
    description VARCHAR(500) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_form_section_key (admission_cycle_id, section_key),
    CONSTRAINT fk_form_sections_cycle FOREIGN KEY (admission_cycle_id) REFERENCES admission_cycles(id) ON DELETE CASCADE,
    INDEX idx_form_sections_order (admission_cycle_id, status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('admission_form_fields', <<<'SQL'
CREATE TABLE admission_form_fields (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admission_cycle_id BIGINT UNSIGNED NOT NULL,
    section_id BIGINT UNSIGNED NOT NULL,
    field_key VARCHAR(120) NOT NULL,
    label VARCHAR(180) NOT NULL,
    field_type VARCHAR(40) NOT NULL,
    canonical_binding VARCHAR(160) NULL,
    help_text VARCHAR(500) NULL,
    placeholder VARCHAR(255) NULL,
    default_value TEXT NULL,
    options_json LONGTEXT NULL,
    validation_rules LONGTEXT NULL,
    conditional_rules LONGTEXT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    is_searchable TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_form_field_key (admission_cycle_id, field_key),
    CONSTRAINT fk_form_fields_cycle FOREIGN KEY (admission_cycle_id) REFERENCES admission_cycles(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_fields_section FOREIGN KEY (section_id) REFERENCES admission_form_sections(id) ON DELETE CASCADE,
    INDEX idx_form_fields_order (section_id, status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('application_field_responses', <<<'SQL'
CREATE TABLE application_field_responses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    form_field_id BIGINT UNSIGNED NOT NULL,
    configuration_version_id BIGINT UNSIGNED NULL,
    value_text LONGTEXT NULL,
    value_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_application_field_response (application_id, form_field_id),
    CONSTRAINT fk_field_responses_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_field_responses_field FOREIGN KEY (form_field_id) REFERENCES admission_form_fields(id),
    CONSTRAINT fk_field_responses_version FOREIGN KEY (configuration_version_id) REFERENCES admission_configuration_versions(id) ON DELETE SET NULL,
    INDEX idx_field_responses_field (form_field_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('application_submission_snapshots', <<<'SQL'
CREATE TABLE application_submission_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL UNIQUE,
    configuration_version_id BIGINT UNSIGNED NOT NULL,
    snapshot_json LONGTEXT NOT NULL,
    snapshot_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_submission_snapshots_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_submission_snapshots_version FOREIGN KEY (configuration_version_id) REFERENCES admission_configuration_versions(id),
    INDEX idx_submission_snapshots_hash (snapshot_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('application_corrections', <<<'SQL'
CREATE TABLE application_corrections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'open',
    due_at DATETIME NULL,
    submitted_at DATETIME NULL,
    resolved_by BIGINT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_corrections_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_corrections_requester FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_corrections_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_corrections_application (application_id, status),
    INDEX idx_corrections_due (status, due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('application_correction_items', <<<'SQL'
CREATE TABLE application_correction_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    correction_id BIGINT UNSIGNED NOT NULL,
    target_type VARCHAR(30) NOT NULL,
    target_key VARCHAR(160) NOT NULL,
    form_field_id BIGINT UNSIGNED NULL,
    document_type_id BIGINT UNSIGNED NULL,
    instructions VARCHAR(1000) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'open',
    responded_at DATETIME NULL,
    resolved_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_correction_target (correction_id, target_type, target_key),
    CONSTRAINT fk_correction_items_correction FOREIGN KEY (correction_id) REFERENCES application_corrections(id) ON DELETE CASCADE,
    CONSTRAINT fk_correction_items_field FOREIGN KEY (form_field_id) REFERENCES admission_form_fields(id) ON DELETE SET NULL,
    CONSTRAINT fk_correction_items_document FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE SET NULL,
    INDEX idx_correction_items_status (correction_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('application_document_versions', <<<'SQL'
CREATE TABLE application_document_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_document_id BIGINT UNSIGNED NOT NULL,
    revision_no INT UNSIGNED NOT NULL,
    path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL,
    status VARCHAR(40) NOT NULL,
    review_remarks VARCHAR(1000) NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_document_revision (application_document_id, revision_no),
    CONSTRAINT fk_document_versions_document FOREIGN KEY (application_document_id) REFERENCES application_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_document_versions_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_document_versions_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_document_versions_checksum (checksum_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('admission_fee_rules', <<<'SQL'
CREATE TABLE admission_fee_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_program_id BIGINT UNSIGNED NOT NULL,
    category_code VARCHAR(80) NULL,
    fee_type VARCHAR(40) NOT NULL,
    label VARCHAR(160) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency VARCHAR(3) NOT NULL DEFAULT 'INR',
    due_at DATETIME NULL,
    late_fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    refund_policy TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_fee_rules_lookup (cycle_program_id, fee_type, category_code, status),
    CONSTRAINT fk_fee_rules_cycle_program FOREIGN KEY (cycle_program_id) REFERENCES cycle_programs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('application_fee_assessments', <<<'SQL'
CREATE TABLE application_fee_assessments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    cycle_program_id BIGINT UNSIGNED NOT NULL,
    fee_rule_id BIGINT UNSIGNED NULL,
    configuration_version_id BIGINT UNSIGNED NULL,
    fee_type VARCHAR(40) NOT NULL,
    category_code VARCHAR(80) NULL,
    base_amount DECIMAL(12,2) NOT NULL,
    late_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'INR',
    due_at DATETIME NULL,
    calculation_json LONGTEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'due',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_application_fee_type (application_id, fee_type),
    CONSTRAINT fk_fee_assessments_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_fee_assessments_cycle_program FOREIGN KEY (cycle_program_id) REFERENCES cycle_programs(id),
    CONSTRAINT fk_fee_assessments_rule FOREIGN KEY (fee_rule_id) REFERENCES admission_fee_rules(id) ON DELETE SET NULL,
    CONSTRAINT fk_fee_assessments_version FOREIGN KEY (configuration_version_id) REFERENCES admission_configuration_versions(id) ON DELETE SET NULL,
    INDEX idx_fee_assessments_status (status, due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('payment_refunds', <<<'SQL'
CREATE TABLE payment_refunds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'requested',
    requested_by BIGINT UNSIGNED NULL,
    decided_by BIGINT UNSIGNED NULL,
    requested_at DATETIME NOT NULL,
    decided_at DATETIME NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_payment_refunds_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
    CONSTRAINT fk_payment_refunds_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_payment_refunds_decider FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_payment_refunds_status (status, requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('seat_allocations', <<<'SQL'
CREATE TABLE seat_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    cycle_program_id BIGINT UNSIGNED NOT NULL,
    seat_matrix_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(80) NOT NULL,
    quota VARCHAR(80) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'reserved',
    is_active TINYINT(1) NULL DEFAULT 1,
    allocated_by BIGINT UNSIGNED NOT NULL,
    allocated_at DATETIME NOT NULL,
    confirmed_at DATETIME NULL,
    released_by BIGINT UNSIGNED NULL,
    released_at DATETIME NULL,
    release_reason VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_active_application_allocation (application_id, is_active),
    CONSTRAINT fk_seat_allocations_application FOREIGN KEY (application_id) REFERENCES applications(id),
    CONSTRAINT fk_seat_allocations_cycle_program FOREIGN KEY (cycle_program_id) REFERENCES cycle_programs(id),
    CONSTRAINT fk_seat_allocations_matrix FOREIGN KEY (seat_matrix_id) REFERENCES seat_matrix(id),
    CONSTRAINT fk_seat_allocations_allocator FOREIGN KEY (allocated_by) REFERENCES users(id),
    CONSTRAINT fk_seat_allocations_releaser FOREIGN KEY (released_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_seat_allocations_matrix (seat_matrix_id, status),
    INDEX idx_seat_allocations_program (cycle_program_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('application_number_counters', <<<'SQL'
CREATE TABLE application_number_counters (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admission_cycle_id BIGINT UNSIGNED NOT NULL,
    counter_key VARCHAR(120) NOT NULL,
    last_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_application_number_counter (admission_cycle_id, counter_key),
    CONSTRAINT fk_application_number_counters_cycle FOREIGN KEY (admission_cycle_id) REFERENCES admission_cycles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->addForeignKey('applications', 'fk_applications_config_version', 'FOREIGN KEY (`configuration_version_id`) REFERENCES `admission_configuration_versions` (`id`) ON DELETE SET NULL');
        $m->addForeignKey('applications', 'fk_applications_selected_program', 'FOREIGN KEY (`selected_cycle_program_id`) REFERENCES `cycle_programs` (`id`) ON DELETE SET NULL');
        $m->addForeignKey('application_documents', 'fk_application_documents_uploader', 'FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL');
        $m->addForeignKey('payments', 'fk_payments_fee_assessment', 'FOREIGN KEY (`fee_assessment_id`) REFERENCES `application_fee_assessments` (`id`) ON DELETE SET NULL');

        if ($m->isDryRun()) {
            $m->note('PLAN normalize legacy cycle states, create slugs, seed categories/configuration/forms/fees, snapshot submitted applications, archive document revision 1, and redact sensitive mail bodies');
            return;
        }

        $pdo = $m->pdo();
        $now = date('Y-m-d H:i:s');
        $m->execute("UPDATE admission_cycles SET status = 'published', published_at = COALESCE(published_at, updated_at) WHERE status = 'open'", [], 'Normalize legacy open cycles');
        $m->execute("UPDATE admission_cycles SET status = 'closed', closed_at = COALESCE(closed_at, updated_at) WHERE status = 'processing'", [], 'Normalize legacy processing cycles');

        $cycles = $pdo->query('SELECT id, code, slug FROM admission_cycles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $slugUpdate = $pdo->prepare('UPDATE admission_cycles SET slug = :slug WHERE id = :id');
        foreach ($cycles as $cycle) {
            if (trim((string) ($cycle['slug'] ?? '')) !== '') continue;
            $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', (string) $cycle['code']), '-')) ?: 'admission-cycle';
            $slug = $base;
            $suffix = 1;
            $check = $pdo->prepare('SELECT COUNT(*) FROM admission_cycles WHERE slug = :slug AND id <> :id');
            do {
                $check->execute(['slug' => $slug, 'id' => $cycle['id']]);
                if ((int) $check->fetchColumn() === 0) break;
                $slug = $base . '-' . (++$suffix);
            } while (true);
            $slugUpdate->execute(['slug' => $slug, 'id' => $cycle['id']]);
        }

        $categoryInsert = $pdo->prepare("INSERT INTO admission_categories (code, name, is_reserved, sort_order, status, created_at, updated_at) VALUES (:code, :name, :reserved, :sort, 'active', :created, :updated) ON DUPLICATE KEY UPDATE name = VALUES(name), is_reserved = VALUES(is_reserved), sort_order = VALUES(sort_order), updated_at = VALUES(updated_at)");
        foreach ([['General','General',0,10],['SC','Scheduled Caste',1,20],['ST','Scheduled Tribe',1,30],['OBC-A','Other Backward Class A',1,40],['OBC-B','Other Backward Class B',1,50],['EWS','Economically Weaker Section',1,60]] as [$code,$name,$reserved,$sort]) {
            $categoryInsert->execute(['code'=>$code,'name'=>$name,'reserved'=>$reserved,'sort'=>$sort,'created'=>$now,'updated'=>$now]);
        }
        foreach ($pdo->query("SELECT DISTINCT category FROM applicant_profiles WHERE category IS NOT NULL AND category <> '' UNION SELECT DISTINCT category FROM seat_matrix WHERE category <> ''")->fetchAll(PDO::FETCH_COLUMN) as $category) {
            $categoryInsert->execute(['code'=>$category,'name'=>$category,'reserved'=>$category === 'General' ? 0 : 1,'sort'=>999,'created'=>$now,'updated'=>$now]);
        }

        $permissions = [
            ['View admission configuration','admissions.view'], ['Manage admission cycles','admissions.manage'],
            ['Publish admission cycles','admissions.publish'], ['Duplicate admission cycles','admissions.duplicate'],
            ['Manage admission forms','admission_forms.manage'], ['Manage admission documents','admission_documents.manage'],
            ['Manage admission fees','admission_fees.manage'], ['Manage admission seats','admission_seats.manage'],
            ['Manage correction requests','applications.correct'], ['Withdraw applications','applications.withdraw'],
        ];
        $permissionInsert = $pdo->prepare("INSERT INTO permissions (name, slug, module, description, created_at) VALUES (:name, :slug, 'admissions', NULL, :created) ON DUPLICATE KEY UPDATE name = VALUES(name), module = VALUES(module)");
        foreach ($permissions as [$name,$slug]) $permissionInsert->execute(['name'=>$name,'slug'=>$slug,'created'=>$now]);
        $rolePermissions = [
            'admission-officer' => ['admissions.view','admissions.manage','admissions.publish','admissions.duplicate','admission_forms.manage','admission_documents.manage','admission_fees.manage','admission_seats.manage','applications.correct','applications.withdraw'],
            'reviewer' => ['admissions.view','applications.correct'],
            'accounts-officer' => ['admissions.view','admission_fees.manage'],
            'principal' => ['admissions.view','admissions.publish'],
            'auditor' => ['admissions.view'],
            'office-staff' => ['admissions.view'],
        ];
        $grant = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug = :permission WHERE r.slug = :role');
        foreach ($rolePermissions as $role => $slugs) foreach ($slugs as $slug) $grant->execute(['role'=>$role,'permission'=>$slug]);

        $sections = [
            ['personal','Personal details','Identity and applicant profile',10],
            ['address','Address','Permanent and correspondence address',20],
            ['guardian','Parent / guardian','Parent or guardian contact',30],
            ['academic','Academic history','Qualifying examinations and entrance test',40],
            ['preferences','Programme preferences','Rank programme choices',50],
        ];
        $sectionInsert = $pdo->prepare("INSERT IGNORE INTO admission_form_sections (admission_cycle_id, section_key, title, description, sort_order, status, created_at, updated_at) VALUES (:cycle,:key,:title,:description,:sort,'active',:created,:updated)");
        foreach ($cycles as $cycle) foreach ($sections as [$key,$title,$description,$sort]) $sectionInsert->execute(['cycle'=>$cycle['id'],'key'=>$key,'title'=>$title,'description'=>$description,'sort'=>$sort,'created'=>$now,'updated'=>$now]);

        $fields = [
            ['personal','date_of_birth','Date of birth','date','applicant_profiles.date_of_birth',1,null,10],
            ['personal','gender','Gender','select','applicant_profiles.gender',1,['female'=>'Female','male'=>'Male','other'=>'Other','prefer_not_to_say'=>'Prefer not to say'],20],
            ['personal','category','Category','select','applicant_profiles.category',1,null,30],
            ['personal','nationality','Nationality','text','applicant_profiles.nationality',1,null,40],
            ['personal','blood_group','Blood group','select','applicant_profiles.blood_group',0,['A+'=>'A+','A-'=>'A-','B+'=>'B+','B-'=>'B-','AB+'=>'AB+','AB-'=>'AB-','O+'=>'O+','O-'=>'O-'],50],
            ['personal','mother_tongue','Mother tongue','text','applicant_profiles.mother_tongue',0,null,60],
            ['personal','religion','Religion','text','applicant_profiles.religion',0,null,70],
            ['address','address_line1','Address line 1','text','applicant_addresses.address_line1',1,null,10],
            ['address','address_line2','Address line 2','text','applicant_addresses.address_line2',0,null,20],
            ['address','city','City / town','text','applicant_addresses.city',1,null,30],
            ['address','district','District','text','applicant_addresses.district',0,null,40],
            ['address','state','State','text','applicant_addresses.state',1,null,50],
            ['address','postal_code','PIN code','text','applicant_addresses.postal_code',1,null,60],
            ['address','country','Country','text','applicant_addresses.country',1,null,70],
            ['guardian','guardian_name','Full name','text','guardians.name',1,null,10],
            ['guardian','relationship','Relationship','text','guardians.relationship',1,null,20],
            ['guardian','occupation','Occupation','text','guardians.occupation',0,null,30],
            ['guardian','annual_income','Annual family income','number','guardians.annual_income',0,null,40],
            ['guardian','guardian_mobile','Mobile number','tel','guardians.mobile',1,null,50],
            ['guardian','guardian_email','Email','email','guardians.email',0,null,60],
            ['academic','class_10_percentage','Class 10 percentage','number','education_records.class_10.percentage',1,null,10],
            ['academic','class_12_percentage','Class 12 percentage','number','education_records.class_12.percentage',1,null,20],
            ['academic','class_12_subjects','Class 12 subjects','text','education_records.class_12.subjects',1,null,30],
            ['academic','entrance_exam','Entrance examination','text','entrance_exams.exam_name',0,null,40],
            ['preferences','program_preferences','Programme preferences','program_preferences','application_preferences.cycle_program_ids',1,null,10],
        ];
        $fieldInsert = $pdo->prepare("INSERT IGNORE INTO admission_form_fields (admission_cycle_id, section_id, field_key, label, field_type, canonical_binding, options_json, validation_rules, is_required, is_searchable, sort_order, status, created_at, updated_at) SELECT :cycle, s.id, :field_key, :label, :field_type, :binding, :options, :rules, :required, :searchable, :sort, 'active', :created, :updated FROM admission_form_sections s WHERE s.admission_cycle_id = :cycle2 AND s.section_key = :section_key");
        foreach ($cycles as $cycle) foreach ($fields as [$section,$key,$label,$type,$binding,$required,$options,$sort]) {
            $fieldInsert->execute(['cycle'=>$cycle['id'],'field_key'=>$key,'label'=>$label,'field_type'=>$type,'binding'=>$binding,'options'=>$options ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,'rules'=>json_encode(['required'=>(bool)$required], JSON_UNESCAPED_UNICODE),'required'=>$required,'searchable'=>in_array($key,['category','city','district','state'],true)?1:0,'sort'=>$sort,'created'=>$now,'updated'=>$now,'cycle2'=>$cycle['id'],'section_key'=>$section]);
        }

        $m->execute("INSERT INTO admission_fee_rules (cycle_program_id, category_code, fee_type, label, amount, currency, status, created_at, updated_at) SELECT cp.id, NULL, 'application_fee', 'Application fee', cp.application_fee, 'INR', 'active', :now1, :now2 FROM cycle_programs cp WHERE NOT EXISTS (SELECT 1 FROM admission_fee_rules afr WHERE afr.cycle_program_id = cp.id AND afr.fee_type = 'application_fee' AND afr.category_code IS NULL)", ['now1'=>$now,'now2'=>$now], 'Backfill application fee rules');
        $m->execute("INSERT INTO admission_fee_rules (cycle_program_id, category_code, fee_type, label, amount, currency, status, created_at, updated_at) SELECT cp.id, NULL, 'admission_fee', 'Admission fee', cp.admission_fee, 'INR', 'active', :now1, :now2 FROM cycle_programs cp WHERE NOT EXISTS (SELECT 1 FROM admission_fee_rules afr WHERE afr.cycle_program_id = cp.id AND afr.fee_type = 'admission_fee' AND afr.category_code IS NULL)", ['now1'=>$now,'now2'=>$now], 'Backfill admission fee rules');
        $m->execute("INSERT IGNORE INTO application_document_versions (application_document_id, revision_no, path, original_name, mime_type, size_bytes, checksum_sha256, status, review_remarks, reviewed_by, reviewed_at, uploaded_by, created_at) SELECT ad.id, ad.revision_no, ad.path, ad.original_name, ad.mime_type, ad.size_bytes, ad.checksum_sha256, ad.status, ad.review_remarks, ad.reviewed_by, ad.reviewed_at, a.user_id, COALESCE(ad.uploaded_at, ad.created_at) FROM application_documents ad JOIN applications a ON a.id = ad.application_id", [], 'Backfill initial document revisions');
        $m->execute("UPDATE application_documents ad JOIN applications a ON a.id = ad.application_id SET ad.uploaded_by = a.user_id WHERE ad.uploaded_by IS NULL", [], 'Backfill document upload actors');

        $configSelect = [
            'programs' => "SELECT cp.*, p.code AS program_code, p.name AS program_name FROM cycle_programs cp JOIN programs p ON p.id = cp.program_id WHERE cp.admission_cycle_id = :cycle ORDER BY p.sort_order, p.name",
            'eligibility_rules' => "SELECT er.* FROM eligibility_rules er JOIN cycle_programs cp ON cp.id = er.cycle_program_id WHERE cp.admission_cycle_id = :cycle ORDER BY er.cycle_program_id, er.sort_order, er.id",
            'documents' => "SELECT cdr.*, dt.code AS document_code, dt.name AS document_name, dt.allowed_mimes, dt.max_size_mb FROM cycle_document_requirements cdr JOIN document_types dt ON dt.id = cdr.document_type_id WHERE cdr.admission_cycle_id = :cycle ORDER BY cdr.sort_order, cdr.id",
            'seats' => "SELECT sm.* FROM seat_matrix sm JOIN cycle_programs cp ON cp.id = sm.cycle_program_id WHERE cp.admission_cycle_id = :cycle ORDER BY sm.cycle_program_id, sm.category, sm.quota",
            'form_sections' => "SELECT * FROM admission_form_sections WHERE admission_cycle_id = :cycle ORDER BY sort_order, id",
            'form_fields' => "SELECT * FROM admission_form_fields WHERE admission_cycle_id = :cycle ORDER BY section_id, sort_order, id",
            'fees' => "SELECT afr.* FROM admission_fee_rules afr JOIN cycle_programs cp ON cp.id = afr.cycle_program_id WHERE cp.admission_cycle_id = :cycle ORDER BY afr.cycle_program_id, afr.fee_type, afr.id",
        ];
        $versionLookup = $pdo->prepare('SELECT id, version_no FROM admission_configuration_versions WHERE admission_cycle_id = :cycle ORDER BY version_no DESC LIMIT 1');
        $versionInsert = $pdo->prepare("INSERT INTO admission_configuration_versions (admission_cycle_id, version_no, snapshot_json, snapshot_hash, status, created_by, created_at) VALUES (:cycle, 1, :snapshot, :hash, 'legacy_import', NULL, :created)");
        $cycleVersionUpdate = $pdo->prepare('UPDATE admission_cycles SET configuration_version = GREATEST(configuration_version, :version) WHERE id = :cycle');
        $applicationVersionUpdate = $pdo->prepare('UPDATE applications SET configuration_version_id = :version WHERE admission_cycle_id = :cycle AND configuration_version_id IS NULL');
        $versionIds = [];
        foreach ($pdo->query('SELECT * FROM admission_cycles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $cycle) {
            $versionLookup->execute(['cycle'=>$cycle['id']]);
            $version = $versionLookup->fetch(PDO::FETCH_ASSOC);
            if (!$version) {
                $payload = ['cycle'=>$cycle];
                foreach ($configSelect as $key=>$sql) {
                    $statement=$pdo->prepare($sql); $statement->execute(['cycle'=>$cycle['id']]); $payload[$key]=$statement->fetchAll(PDO::FETCH_ASSOC);
                }
                $json=json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                $versionInsert->execute(['cycle'=>$cycle['id'],'snapshot'=>$json,'hash'=>hash('sha256',$json),'created'=>$now]);
                $version=['id'=>(int)$pdo->lastInsertId(),'version_no'=>1];
            }
            $versionIds[(int)$cycle['id']] = (int)$version['id'];
            $cycleVersionUpdate->execute(['version'=>$version['version_no'],'cycle'=>$cycle['id']]);
            $applicationVersionUpdate->execute(['version'=>$version['id'],'cycle'=>$cycle['id']]);
        }

        $snapshotExists = $pdo->prepare('SELECT COUNT(*) FROM application_submission_snapshots WHERE application_id = :application');
        $snapshotInsert = $pdo->prepare('INSERT INTO application_submission_snapshots (application_id, configuration_version_id, snapshot_json, snapshot_hash, created_at) VALUES (:application,:version,:snapshot,:hash,:created)');
        $submitted = $pdo->query("SELECT a.*, u.first_name, u.last_name, u.email, u.mobile FROM applications a JOIN users u ON u.id = a.user_id WHERE a.submitted_at IS NOT NULL OR a.status <> 'draft' ORDER BY a.id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($submitted as $application) {
            $snapshotExists->execute(['application'=>$application['id']]);
            if ((int)$snapshotExists->fetchColumn() > 0) continue;
            $payload=['application'=>$application];
            foreach ([
                'profile'=>'SELECT * FROM applicant_profiles WHERE user_id = :id',
                'address'=>'SELECT * FROM applicant_addresses WHERE application_id = :id',
                'guardian'=>'SELECT * FROM guardians WHERE application_id = :id',
                'education'=>'SELECT * FROM education_records WHERE application_id = :id ORDER BY level, id',
                'exam'=>'SELECT * FROM entrance_exams WHERE application_id = :id ORDER BY id',
                'preferences'=>'SELECT pref.*, cp.program_id FROM application_preferences pref JOIN cycle_programs cp ON cp.id = pref.cycle_program_id WHERE pref.application_id = :id ORDER BY pref.preference_order',
                'responses'=>'SELECT * FROM application_field_responses WHERE application_id = :id ORDER BY form_field_id',
            ] as $key=>$sql) {
                $statement=$pdo->prepare($sql); $statement->execute(['id'=>$key==='profile'?$application['user_id']:$application['id']]); $payload[$key]=$statement->fetchAll(PDO::FETCH_ASSOC);
            }
            $json=json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $versionId=(int)($application['configuration_version_id'] ?: ($versionIds[(int)$application['admission_cycle_id']]??0));
            if ($versionId) $snapshotInsert->execute(['application'=>$application['id'],'version'=>$versionId,'snapshot'=>$json,'hash'=>hash('sha256',$json),'created'=>$application['submitted_at'] ?: $now]);
        }

        $m->execute("UPDATE mail_logs SET body_checksum_sha256 = SHA2(body_html, 256), body_html = NULL, sensitive_redacted = 1, metadata_json = JSON_OBJECT('redacted_reason','authentication_secret','redacted_at',:redacted_at) WHERE template_key IN ('verify_email','password_reset','staff_mfa') AND body_html IS NOT NULL", ['redacted_at'=>$now], 'Redact historical authentication-secret mail bodies');
    },
    'verify' => static function (MigrationContext $m): void {
        if ($m->isDryRun()) {
            $m->note('PLAN verify all new tables, columns, foreign keys, and mail redaction after apply');
            return;
        }
        foreach (['admission_configuration_versions','admission_form_sections','admission_form_fields','application_submission_snapshots','application_corrections','application_document_versions','admission_fee_rules','application_fee_assessments','seat_allocations','application_number_counters'] as $table) {
            $m->assert($m->tableExists($table), "Migration verification failed: {$table} is missing.");
        }
        foreach ([['admission_cycles','slug'],['applications','configuration_version_id'],['applications','selected_cycle_program_id'],['mail_logs','sensitive_redacted']] as [$table,$column]) {
            $m->assert($m->columnExists($table,$column), "Migration verification failed: {$table}.{$column} is missing.");
        }
        if (!$m->isDryRun()) {
            $remaining = (int)$m->value("SELECT COUNT(*) FROM mail_logs WHERE template_key IN ('verify_email','password_reset','staff_mfa') AND body_html IS NOT NULL");
            $m->assert($remaining === 0, 'Sensitive authentication mail bodies remain after migration.');
        }
    },
];
