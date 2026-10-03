<?php

declare(strict_types=1);

use App\Core\MigrationContext;

return [
    'version' => '006_merit_selection_payments',
    'description' => 'Add immutable merit lists, selection offers, and secure online-payment gateway records.',
    'up' => static function (MigrationContext $m): void {
        $m->createTable('merit_cycle_settings', <<<'SQL'
CREATE TABLE merit_cycle_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admission_cycle_id BIGINT UNSIGNED NOT NULL UNIQUE,
    reservation_policy VARCHAR(40) NOT NULL DEFAULT 'open_first',
    default_quota VARCHAR(80) NOT NULL DEFAULT 'state',
    offer_valid_hours SMALLINT UNSIGNED NOT NULL DEFAULT 72,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_merit_settings_cycle FOREIGN KEY (admission_cycle_id) REFERENCES admission_cycles(id),
    CONSTRAINT fk_merit_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('merit_formula_versions', <<<'SQL'
CREATE TABLE merit_formula_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admission_cycle_id BIGINT UNSIGNED NOT NULL,
    cycle_program_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    class_10_weight DECIMAL(6,3) NOT NULL DEFAULT 0,
    class_12_weight DECIMAL(6,3) NOT NULL DEFAULT 0,
    entrance_weight DECIMAL(6,3) NOT NULL DEFAULT 0,
    tie_break_json LONGTEXT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    retired_at DATETIME NULL,
    UNIQUE KEY uq_merit_formula_version (cycle_program_id, version_no),
    CONSTRAINT fk_merit_formula_cycle FOREIGN KEY (admission_cycle_id) REFERENCES admission_cycles(id),
    CONSTRAINT fk_merit_formula_program FOREIGN KEY (cycle_program_id) REFERENCES cycle_programs(id),
    CONSTRAINT fk_merit_formula_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_merit_formula_active (admission_cycle_id, cycle_program_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('merit_runs', <<<'SQL'
CREATE TABLE merit_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admission_cycle_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    reservation_policy VARCHAR(40) NOT NULL,
    source_statuses VARCHAR(255) NOT NULL,
    criteria_hash CHAR(64) NOT NULL,
    applicant_count INT UNSIGNED NOT NULL DEFAULT 0,
    entry_count INT UNSIGNED NOT NULL DEFAULT 0,
    generated_by BIGINT UNSIGNED NULL,
    generated_at DATETIME NOT NULL,
    published_by BIGINT UNSIGNED NULL,
    published_at DATETIME NULL,
    superseded_at DATETIME NULL,
    UNIQUE KEY uq_merit_run_version (admission_cycle_id, version_no),
    CONSTRAINT fk_merit_run_cycle FOREIGN KEY (admission_cycle_id) REFERENCES admission_cycles(id),
    CONSTRAINT fk_merit_run_generator FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_merit_run_publisher FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_merit_run_status (admission_cycle_id, status, version_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('merit_run_programs', <<<'SQL'
CREATE TABLE merit_run_programs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    merit_run_id BIGINT UNSIGNED NOT NULL,
    cycle_program_id BIGINT UNSIGNED NOT NULL,
    formula_version_id BIGINT UNSIGNED NOT NULL,
    formula_snapshot_json LONGTEXT NOT NULL,
    eligible_count INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_merit_run_program (merit_run_id, cycle_program_id),
    CONSTRAINT fk_merit_run_program_run FOREIGN KEY (merit_run_id) REFERENCES merit_runs(id),
    CONSTRAINT fk_merit_run_program_program FOREIGN KEY (cycle_program_id) REFERENCES cycle_programs(id),
    CONSTRAINT fk_merit_run_program_formula FOREIGN KEY (formula_version_id) REFERENCES merit_formula_versions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('merit_entries', <<<'SQL'
CREATE TABLE merit_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    merit_run_id BIGINT UNSIGNED NOT NULL,
    merit_run_program_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NOT NULL,
    cycle_program_id BIGINT UNSIGNED NOT NULL,
    application_number VARCHAR(60) NOT NULL,
    applicant_category VARCHAR(80) NOT NULL,
    merit_category VARCHAR(80) NOT NULL,
    quota VARCHAR(80) NOT NULL,
    preference_order SMALLINT UNSIGNED NOT NULL,
    merit_score DECIMAL(10,5) NOT NULL,
    overall_rank INT UNSIGNED NOT NULL,
    category_rank INT UNSIGNED NOT NULL,
    class_10_percentage DECIMAL(6,2) NOT NULL,
    class_12_percentage DECIMAL(6,2) NOT NULL,
    entrance_percentile DECIMAL(7,3) NULL,
    component_snapshot_json LONGTEXT NOT NULL,
    tie_break_snapshot_json LONGTEXT NOT NULL,
    result_status VARCHAR(30) NOT NULL DEFAULT 'ranked',
    selected_at DATETIME NULL,
    selected_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_merit_entry (merit_run_id, application_id, cycle_program_id, merit_category, quota),
    CONSTRAINT fk_merit_entry_run FOREIGN KEY (merit_run_id) REFERENCES merit_runs(id),
    CONSTRAINT fk_merit_entry_run_program FOREIGN KEY (merit_run_program_id) REFERENCES merit_run_programs(id),
    CONSTRAINT fk_merit_entry_application FOREIGN KEY (application_id) REFERENCES applications(id),
    CONSTRAINT fk_merit_entry_program FOREIGN KEY (cycle_program_id) REFERENCES cycle_programs(id),
    CONSTRAINT fk_merit_entry_selector FOREIGN KEY (selected_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_merit_entry_list (merit_run_id, cycle_program_id, merit_category, quota, category_rank),
    INDEX idx_merit_entry_application (application_id, result_status),
    INDEX idx_merit_entry_selection (merit_run_id, result_status, overall_rank)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('selection_offers', <<<'SQL'
CREATE TABLE selection_offers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    merit_entry_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NOT NULL,
    seat_allocation_id BIGINT UNSIGNED NOT NULL,
    offer_number VARCHAR(80) NOT NULL UNIQUE,
    status VARCHAR(30) NOT NULL DEFAULT 'payment_due',
    offered_by BIGINT UNSIGNED NULL,
    offered_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    payment_received_at DATETIME NULL,
    payment_verified_at DATETIME NULL,
    expired_at DATETIME NULL,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_selection_offer_entry (merit_entry_id),
    CONSTRAINT fk_selection_offer_entry FOREIGN KEY (merit_entry_id) REFERENCES merit_entries(id),
    CONSTRAINT fk_selection_offer_application FOREIGN KEY (application_id) REFERENCES applications(id),
    CONSTRAINT fk_selection_offer_allocation FOREIGN KEY (seat_allocation_id) REFERENCES seat_allocations(id),
    CONSTRAINT fk_selection_offer_user FOREIGN KEY (offered_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_selection_offer_due (status, expires_at),
    INDEX idx_selection_offer_application (application_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('admission_notification_outbox', <<<'SQL'
CREATE TABLE admission_notification_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    recipient VARCHAR(190) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body_html LONGTEXT NOT NULL,
    template_key VARCHAR(80) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'queued',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    last_error VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_admission_outbox_application FOREIGN KEY (application_id) REFERENCES applications(id),
    INDEX idx_admission_outbox_queue (status, available_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('payment_gateway_configs', <<<'SQL'
CREATE TABLE payment_gateway_configs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(40) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    environment VARCHAR(20) NOT NULL DEFAULT 'sandbox',
    public_credential VARCHAR(500) NULL,
    secret_credential_encrypted LONGTEXT NULL,
    webhook_secret_encrypted LONGTEXT NULL,
    configuration_json LONGTEXT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_gateway_config_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_gateway_config_active (is_active, is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('payment_gateway_transactions', <<<'SQL'
CREATE TABLE payment_gateway_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    fee_assessment_id BIGINT UNSIGNED NOT NULL,
    gateway_config_id BIGINT UNSIGNED NOT NULL,
    provider_order_id VARCHAR(190) NOT NULL,
    provider_payment_id VARCHAR(190) NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'INR',
    status VARCHAR(30) NOT NULL DEFAULT 'created',
    request_reference VARCHAR(80) NOT NULL UNIQUE,
    payload_hash CHAR(64) NULL,
    response_json LONGTEXT NULL,
    expires_at DATETIME NULL,
    paid_at DATETIME NULL,
    verified_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_gateway_provider_order (gateway_config_id, provider_order_id),
    CONSTRAINT fk_gateway_transaction_application FOREIGN KEY (application_id) REFERENCES applications(id),
    CONSTRAINT fk_gateway_transaction_assessment FOREIGN KEY (fee_assessment_id) REFERENCES application_fee_assessments(id),
    CONSTRAINT fk_gateway_transaction_config FOREIGN KEY (gateway_config_id) REFERENCES payment_gateway_configs(id),
    INDEX idx_gateway_transaction_status (status, created_at),
    INDEX idx_gateway_transaction_application (application_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->createTable('payment_gateway_events', <<<'SQL'
CREATE TABLE payment_gateway_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gateway_transaction_id BIGINT UNSIGNED NULL,
    gateway_config_id BIGINT UNSIGNED NOT NULL,
    provider_event_id VARCHAR(190) NULL,
    event_type VARCHAR(120) NOT NULL,
    signature_valid TINYINT(1) NOT NULL DEFAULT 0,
    payload_hash CHAR(64) NOT NULL,
    processing_status VARCHAR(30) NOT NULL DEFAULT 'received',
    error_message VARCHAR(1000) NULL,
    received_at DATETIME NOT NULL,
    processed_at DATETIME NULL,
    CONSTRAINT fk_gateway_event_transaction FOREIGN KEY (gateway_transaction_id) REFERENCES payment_gateway_transactions(id) ON DELETE SET NULL,
    CONSTRAINT fk_gateway_event_config FOREIGN KEY (gateway_config_id) REFERENCES payment_gateway_configs(id),
    UNIQUE KEY uq_gateway_provider_event (gateway_config_id, provider_event_id),
    INDEX idx_gateway_event_status (processing_status, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $m->execute("INSERT IGNORE INTO permissions (name, slug, module, created_at) VALUES ('Manage merit workspace','merit.manage','admissions',NOW()),('Publish merit lists','merit.publish','admissions',NOW())", [], 'seed merit permissions');
        $m->execute("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON p.slug IN ('merit.manage','merit.publish') WHERE r.slug IN ('super-admin','admission-officer')", [], 'grant merit permissions to admission roles');
    },
    'verify' => static function (MigrationContext $m): void {
        if ($m->isDryRun()) {
            $m->note('PLAN verify merit, offer, and payment-gateway tables and foreign-key contracts');
            return;
        }
        foreach (['merit_cycle_settings','merit_formula_versions','merit_runs','merit_run_programs','merit_entries','selection_offers','admission_notification_outbox','payment_gateway_configs','payment_gateway_transactions','payment_gateway_events'] as $table) {
            $m->assert($m->tableExists($table), "Migration verification failed: {$table} is missing.");
        }
        $m->assert($m->foreignKeyExists('fk_merit_entry_application'), 'Migration verification failed: merit entry application key is missing.');
        $m->assert($m->indexExists('merit_entries', 'idx_merit_entry_list'), 'Migration verification failed: merit list ranking index is missing.');
        $m->assert((int)$m->value("SELECT COUNT(*) FROM permissions WHERE slug IN ('merit.manage','merit.publish')") === 2, 'Migration verification failed: merit permissions are missing.');
    },
];
