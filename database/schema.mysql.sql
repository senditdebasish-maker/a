SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(500) NULL,
    checksum_sha256 CHAR(64) NULL,
    batch INT UNSIGNED NOT NULL DEFAULT 1,
    execution_ms INT UNSIGNED NULL,
    applied_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_name VARCHAR(80) NOT NULL DEFAULT 'general',
    key_name VARCHAR(120) NOT NULL UNIQUE,
    value LONGTEXT NULL,
    value_type VARCHAR(30) NOT NULL DEFAULT 'string',
    is_public TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_settings_group (group_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(500) NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    module VARCHAR(80) NOT NULL,
    description VARCHAR(500) NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    mobile VARCHAR(20) NULL,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    preferred_locale VARCHAR(10) NOT NULL DEFAULT 'en',
    avatar_path VARCHAR(500) NULL,
    email_verified_at DATETIME NULL,
    email_verification_token VARCHAR(64) NULL,
    email_verification_expires_at DATETIME NULL,
    last_login_at DATETIME NULL,
    last_login_ip VARCHAR(45) NULL,
    password_changed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    INDEX idx_users_status (status),
    INDEX idx_users_mobile (mobile),
    INDEX idx_users_verify_token (email_verification_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(500) NULL,
    successful TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at DATETIME NOT NULL,
    INDEX idx_login_lookup (email, ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mfa_challenges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_mfa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_mfa_user (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_resets_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE departments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    code VARCHAR(30) NOT NULL UNIQUE,
    description TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE programs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id BIGINT UNSIGNED NULL,
    name VARCHAR(180) NOT NULL,
    code VARCHAR(40) NOT NULL UNIQUE,
    slug VARCHAR(190) NOT NULL UNIQUE,
    award_type VARCHAR(80) NOT NULL,
    duration_years DECIMAL(3,1) NOT NULL,
    total_semesters SMALLINT UNSIGNED NOT NULL,
    summary TEXT NULL,
    description LONGTEXT NULL,
    eligibility_summary TEXT NULL,
    career_summary TEXT NULL,
    image_path VARCHAR(500) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_program_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    INDEX idx_programs_status (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE academic_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'upcoming',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admission_cycles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    academic_session_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    code VARCHAR(50) NOT NULL UNIQUE,
    slug VARCHAR(190) NULL UNIQUE,
    summary TEXT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    correction_deadline DATETIME NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    instructions LONGTEXT NULL,
    declaration_text LONGTEXT NULL,
    prospectus_path VARCHAR(500) NULL,
    prospectus_original_name VARCHAR(255) NULL,
    prospectus_mime_type VARCHAR(100) NULL,
    application_number_prefix VARCHAR(30) NOT NULL DEFAULT 'NCP-APP',
    application_fee_strategy VARCHAR(40) NOT NULL DEFAULT 'first_preference',
    max_program_preferences SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    closing_soon_hours SMALLINT UNSIGNED NOT NULL DEFAULT 72,
    configuration_version INT UNSIGNED NOT NULL DEFAULT 0,
    published_at DATETIME NULL,
    published_by BIGINT UNSIGNED NULL,
    closed_at DATETIME NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_cycles_session FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id),
    CONSTRAINT fk_cycles_publisher FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_cycles_status_dates (status, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cycle_programs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admission_cycle_id BIGINT UNSIGNED NOT NULL,
    program_id BIGINT UNSIGNED NOT NULL,
    seat_capacity INT UNSIGNED NOT NULL DEFAULT 0,
    application_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
    admission_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
    minimum_marks_general DECIMAL(5,2) NULL,
    minimum_marks_reserved DECIMAL(5,2) NULL,
    min_age SMALLINT UNSIGNED NULL,
    max_age SMALLINT UNSIGNED NULL,
    accepted_entrance_exams VARCHAR(500) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_cycle_program (admission_cycle_id, program_id),
    CONSTRAINT fk_cycle_program_cycle FOREIGN KEY (admission_cycle_id) REFERENCES admission_cycles(id) ON DELETE CASCADE,
    CONSTRAINT fk_cycle_program_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE eligibility_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_program_id BIGINT UNSIGNED NOT NULL,
    rule_type VARCHAR(80) NOT NULL,
    field_name VARCHAR(100) NOT NULL,
    operator VARCHAR(30) NOT NULL,
    comparison_value VARCHAR(500) NOT NULL,
    message VARCHAR(500) NOT NULL,
    is_blocking TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_eligibility_cycle_program FOREIGN KEY (cycle_program_id) REFERENCES cycle_programs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seat_matrix (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_program_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(80) NOT NULL,
    quota VARCHAR(80) NOT NULL DEFAULT 'general',
    seats INT UNSIGNED NOT NULL DEFAULT 0,
    filled_seats INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_seat_matrix (cycle_program_id, category, quota),
    CONSTRAINT fk_seats_cycle_program FOREIGN KEY (cycle_program_id) REFERENCES cycle_programs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    code VARCHAR(80) NOT NULL UNIQUE,
    description VARCHAR(500) NULL,
    allowed_mimes VARCHAR(500) NOT NULL DEFAULT 'application/pdf,image/jpeg,image/png',
    max_size_mb INT UNSIGNED NOT NULL DEFAULT 5,
    sort_order INT NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cycle_document_requirements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admission_cycle_id BIGINT UNSIGNED NOT NULL,
    document_type_id BIGINT UNSIGNED NOT NULL,
    program_id BIGINT UNSIGNED NULL,
    category VARCHAR(80) NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    stage VARCHAR(40) NOT NULL DEFAULT 'application',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_cycle_document (admission_cycle_id, document_type_id, program_id, category),
    CONSTRAINT fk_cycle_documents_cycle FOREIGN KEY (admission_cycle_id) REFERENCES admission_cycles(id) ON DELETE CASCADE,
    CONSTRAINT fk_cycle_documents_type FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE,
    CONSTRAINT fk_cycle_documents_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE applicant_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    date_of_birth DATE NULL,
    gender VARCHAR(40) NULL,
    category VARCHAR(80) NULL,
    nationality VARCHAR(80) NULL DEFAULT 'Indian',
    blood_group VARCHAR(10) NULL,
    religion VARCHAR(80) NULL,
    mother_tongue VARCHAR(80) NULL,
    disability_status VARCHAR(100) NULL,
    disability_percentage DECIMAL(5,2) NULL,
    government_id_type VARCHAR(50) NULL,
    government_id_encrypted LONGTEXT NULL,
    government_id_last4 VARCHAR(4) NULL,
    photo_path VARCHAR(500) NULL,
    signature_path VARCHAR(500) NULL,
    profile_completion SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_profiles_category (category),
    INDEX idx_profiles_gender (gender)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE applications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_number VARCHAR(60) NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    admission_cycle_id BIGINT UNSIGNED NOT NULL,
    attempt_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    reapplied_from_application_id BIGINT UNSIGNED NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'draft',
    current_step SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    completion_percentage SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    eligibility_status VARCHAR(40) NOT NULL DEFAULT 'not_evaluated',
    eligibility_flags LONGTEXT NULL,
    assigned_to BIGINT UNSIGNED NULL,
    assigned_at DATETIME NULL,
    configuration_version_id BIGINT UNSIGNED NULL,
    selected_cycle_program_id BIGINT UNSIGNED NULL,
    submitted_at DATETIME NULL,
    resubmitted_at DATETIME NULL,
    decision_at DATETIME NULL,
    withdrawn_at DATETIME NULL,
    locked_at DATETIME NULL,
    admitted_at DATETIME NULL,
    withdrawal_reason TEXT NULL,
    status_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT fk_applications_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_applications_cycle FOREIGN KEY (admission_cycle_id) REFERENCES admission_cycles(id),
    CONSTRAINT fk_applications_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_applications_reapplied_from FOREIGN KEY (reapplied_from_application_id) REFERENCES applications(id) ON DELETE SET NULL,
    UNIQUE KEY uq_user_cycle_attempt (user_id, admission_cycle_id, attempt_no),
    INDEX idx_applications_user_cycle (user_id, admission_cycle_id, created_at),
    INDEX idx_applications_reapplied_from (reapplied_from_application_id),
    INDEX idx_applications_status (status),
    INDEX idx_applications_submitted (submitted_at),
    INDEX idx_applications_assignee (assigned_to, status),
    INDEX idx_applications_cycle_status (admission_cycle_id, status, submitted_at),
    INDEX idx_applications_selected_program (selected_cycle_program_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE applicant_addresses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL UNIQUE,
    address_line1 VARCHAR(200) NOT NULL,
    address_line2 VARCHAR(200) NULL,
    city VARCHAR(100) NOT NULL,
    district VARCHAR(100) NULL,
    state VARCHAR(100) NOT NULL,
    postal_code VARCHAR(12) NOT NULL,
    country VARCHAR(80) NOT NULL DEFAULT 'India',
    same_as_correspondence TINYINT(1) NOT NULL DEFAULT 1,
    correspondence_address TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_addresses_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    INDEX idx_addresses_state (state),
    INDEX idx_addresses_district (district)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE guardians (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    relationship VARCHAR(80) NOT NULL,
    occupation VARCHAR(120) NULL,
    annual_income DECIMAL(14,2) NULL,
    mobile VARCHAR(20) NOT NULL,
    email VARCHAR(190) NULL,
    address TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_guardians_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE education_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    level VARCHAR(50) NOT NULL,
    board VARCHAR(180) NOT NULL,
    institution VARCHAR(220) NOT NULL,
    passing_year SMALLINT UNSIGNED NOT NULL,
    roll_number VARCHAR(100) NULL,
    registration_number VARCHAR(100) NULL,
    total_marks DECIMAL(10,2) NULL,
    obtained_marks DECIMAL(10,2) NULL,
    percentage DECIMAL(6,2) NULL,
    grade_cgpa VARCHAR(30) NULL,
    subjects VARCHAR(500) NULL,
    result_status VARCHAR(30) NOT NULL DEFAULT 'passed',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_education_level (application_id, level),
    CONSTRAINT fk_education_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE entrance_exams (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    exam_name VARCHAR(120) NOT NULL,
    roll_number VARCHAR(100) NULL,
    rank_score VARCHAR(100) NULL,
    percentile DECIMAL(7,3) NULL,
    exam_year SMALLINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_exams_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE application_preferences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    cycle_program_id BIGINT UNSIGNED NOT NULL,
    preference_order SMALLINT UNSIGNED NOT NULL,
    allocation_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_application_preference (application_id, cycle_program_id),
    UNIQUE KEY uq_preference_order (application_id, preference_order),
    CONSTRAINT fk_preferences_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_preferences_cycle_program FOREIGN KEY (cycle_program_id) REFERENCES cycle_programs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE application_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    document_type_id BIGINT UNSIGNED NOT NULL,
    path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    checksum_sha256 VARCHAR(64) NOT NULL,
    revision_no INT UNSIGNED NOT NULL DEFAULT 1,
    uploaded_by BIGINT UNSIGNED NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    review_remarks VARCHAR(1000) NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    uploaded_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_application_document (application_id, document_type_id),
    CONSTRAINT fk_app_documents_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_app_documents_type FOREIGN KEY (document_type_id) REFERENCES document_types(id),
    CONSTRAINT fk_app_documents_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_application_documents_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_documents_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE application_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(40) NULL,
    to_status VARCHAR(40) NOT NULL,
    remarks TEXT NULL,
    changed_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_status_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_status_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status_history (application_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    note TEXT NOT NULL,
    visibility VARCHAR(30) NOT NULL DEFAULT 'staff',
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_notes_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_notes_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_notes_application (application_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE application_declarations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    declaration_version VARCHAR(30) NOT NULL,
    accepted TINYINT(1) NOT NULL,
    ip_address VARCHAR(45) NULL,
    accepted_at DATETIME NOT NULL,
    CONSTRAINT fk_declarations_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    fee_assessment_id BIGINT UNSIGNED NULL,
    type VARCHAR(60) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'INR',
    method VARCHAR(50) NOT NULL,
    reference_number VARCHAR(150) NOT NULL,
    proof_path VARCHAR(500) NULL,
    proof_original_name VARCHAR(255) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    verification_remarks VARCHAR(1000) NULL,
    verified_by BIGINT UNSIGNED NULL,
    verified_at DATETIME NULL,
    receipt_number VARCHAR(80) NULL UNIQUE,
    paid_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_payments_application FOREIGN KEY (application_id) REFERENCES applications(id),
    CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_payments_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_payments_status (status),
    INDEX idx_payments_type_status (type, status, created_at),
    INDEX idx_payments_reference (reference_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_enrollments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    cycle_program_id BIGINT UNSIGNED NULL,
    enrollment_number VARCHAR(80) NOT NULL UNIQUE,
    university_roll_number VARCHAR(100) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    enrolled_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    CONSTRAINT fk_enrollment_application FOREIGN KEY (application_id) REFERENCES applications(id),
    CONSTRAINT fk_enrollment_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_enrollment_program FOREIGN KEY (cycle_program_id) REFERENCES cycle_programs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE generated_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    document_kind VARCHAR(80) NOT NULL,
    document_number VARCHAR(100) NULL,
    path VARCHAR(500) NULL,
    checksum_sha256 VARCHAR(64) NULL,
    version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    generated_by BIGINT UNSIGNED NULL,
    generated_at DATETIME NOT NULL,
    invalidated_at DATETIME NULL,
    CONSTRAINT fk_generated_application FOREIGN KEY (application_id) REFERENCES applications(id),
    CONSTRAINT fk_generated_user FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_generated_kind (application_id, document_kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(60) NOT NULL,
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    action_url VARCHAR(500) NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notifications_user (user_id, read_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mail_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient VARCHAR(190) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    template_key VARCHAR(100) NULL,
    body_html LONGTEXT NULL,
    body_checksum_sha256 CHAR(64) NULL,
    sensitive_redacted TINYINT(1) NOT NULL DEFAULT 0,
    correlation_id VARCHAR(64) NULL,
    metadata_json LONGTEXT NULL,
    status VARCHAR(30) NOT NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    INDEX idx_mail_status (status, created_at),
    INDEX idx_mail_recipient (recipient),
    INDEX idx_mail_correlation (correlation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_number VARCHAR(60) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NULL,
    assigned_to BIGINT UNSIGNED NULL,
    subject VARCHAR(180) NOT NULL,
    category VARCHAR(60) NOT NULL,
    priority VARCHAR(30) NOT NULL DEFAULT 'normal',
    status VARCHAR(30) NOT NULL DEFAULT 'open',
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_tickets_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_tickets_status (status, priority),
    INDEX idx_tickets_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    attachment_path VARCHAR(500) NULL,
    is_staff_reply TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_ticket_messages_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_messages_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_ticket_messages (ticket_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    eyebrow VARCHAR(120) NULL,
    excerpt TEXT NULL,
    body LONGTEXT NULL,
    template VARCHAR(80) NOT NULL DEFAULT 'standard',
    hero_image VARCHAR(500) NULL,
    meta_title VARCHAR(255) NULL,
    meta_description VARCHAR(500) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_pages_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_pages_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_pages_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE page_sections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    section_key VARCHAR(120) NOT NULL,
    section_type VARCHAR(40) NOT NULL,
    eyebrow VARCHAR(160) NULL,
    title VARCHAR(255) NULL,
    body LONGTEXT NULL,
    image_path VARCHAR(500) NULL,
    image_alt VARCHAR(255) NULL,
    link_label VARCHAR(120) NULL,
    link_url VARCHAR(500) NULL,
    items_json LONGTEXT NULL,
    module_key VARCHAR(60) NULL,
    style_variant VARCHAR(40) NOT NULL DEFAULT 'light',
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    sort_order INT NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_page_section_key (page_id, section_key),
    INDEX idx_page_sections_public (page_id, status, sort_order),
    CONSTRAINT fk_page_sections_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_page_sections_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_page_sections_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE content_translations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(10) NOT NULL,
    fields_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_content_translation (entity_type, entity_id, locale),
    INDEX idx_translation_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    category VARCHAR(80) NOT NULL DEFAULT 'general',
    excerpt TEXT NULL,
    body LONGTEXT NULL,
    attachment_path VARCHAR(500) NULL,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    audience VARCHAR(80) NOT NULL DEFAULT 'public',
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    expires_at DATE NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_notices_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_notices_public (status, is_pinned, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE facilities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    icon VARCHAR(80) NULL,
    summary TEXT NULL,
    description LONGTEXT NULL,
    image_path VARCHAR(500) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'published',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE faculty (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id BIGINT UNSIGNED NULL,
    name VARCHAR(180) NOT NULL,
    designation VARCHAR(160) NOT NULL,
    qualifications VARCHAR(500) NULL,
    specialisation VARCHAR(300) NULL,
    bio TEXT NULL,
    email VARCHAR(190) NULL,
    photo_path VARCHAR(500) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_faculty_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE faqs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(80) NOT NULL DEFAULT 'admissions',
    question VARCHAR(500) NOT NULL,
    answer TEXT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'published',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gallery_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    caption VARCHAR(500) NULL,
    image_path VARCHAR(500) NOT NULL,
    album VARCHAR(100) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'published',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_submissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(20) NULL,
    subject VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'new',
    assigned_to BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,
    responded_at DATETIME NULL,
    CONSTRAINT fk_contacts_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_contacts_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE media (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uploaded_by BIGINT UNSIGNED NULL,
    disk VARCHAR(30) NOT NULL DEFAULT 'public',
    path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    alt_text VARCHAR(255) NULL,
    checksum_sha256 VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_media_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE consent_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    application_id BIGINT UNSIGNED NULL,
    consent_type VARCHAR(100) NOT NULL,
    purpose VARCHAR(500) NULL,
    version VARCHAR(30) NOT NULL,
    granted TINYINT(1) NOT NULL,
    ip_address VARCHAR(45) NULL,
    withdrawn_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_consents_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_consents_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE SET NULL,
    INDEX idx_consents_lookup (user_id, consent_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(120) NOT NULL,
    entity_type VARCHAR(120) NOT NULL,
    entity_id VARCHAR(100) NULL,
    old_values LONGTEXT NULL,
    new_values LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_user_time (user_id, created_at),
    INDEX idx_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE backup_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(500) NOT NULL,
    size_bytes BIGINT UNSIGNED NULL,
    checksum_sha256 VARCHAR(64) NULL,
    encrypted TINYINT(1) NOT NULL DEFAULT 1,
    status VARCHAR(30) NOT NULL,
    initiated_by BIGINT UNSIGNED NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    CONSTRAINT fk_backups_user FOREIGN KEY (initiated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    UNIQUE KEY uq_application_snapshot_revision (application_id, revision_no),
    INDEX idx_snapshot_revision_hash (snapshot_hash),
    CONSTRAINT fk_snapshot_revisions_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_snapshot_revisions_configuration FOREIGN KEY (configuration_version_id) REFERENCES admission_configuration_versions(id),
    CONSTRAINT fk_snapshot_revisions_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE application_number_counters (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admission_cycle_id BIGINT UNSIGNED NOT NULL,
    counter_key VARCHAR(120) NOT NULL,
    last_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_application_number_counter (admission_cycle_id, counter_key),
    CONSTRAINT fk_application_number_counters_cycle FOREIGN KEY (admission_cycle_id) REFERENCES admission_cycles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE applications
    ADD CONSTRAINT fk_applications_config_version FOREIGN KEY (configuration_version_id) REFERENCES admission_configuration_versions(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_applications_selected_program FOREIGN KEY (selected_cycle_program_id) REFERENCES cycle_programs(id) ON DELETE SET NULL;

ALTER TABLE payments
    ADD CONSTRAINT fk_payments_fee_assessment FOREIGN KEY (fee_assessment_id) REFERENCES application_fee_assessments(id) ON DELETE SET NULL;

INSERT INTO schema_migrations (version, description, checksum_sha256, batch, execution_ms, applied_at) VALUES ('001_initial_schema', 'Initial clean-install schema', NULL, 1, NULL, NOW());
INSERT INTO schema_migrations (version, description, checksum_sha256, batch, execution_ms, applied_at) VALUES ('002_admission_management', 'Admission management schema included by clean installer', NULL, 1, NULL, NOW());
INSERT INTO schema_migrations (version, description, checksum_sha256, batch, execution_ms, applied_at) VALUES ('003_submission_snapshot_revisions', 'Immutable submission revision schema included by clean installer', NULL, 1, NULL, NOW());
INSERT INTO schema_migrations (version, description, checksum_sha256, batch, execution_ms, applied_at) VALUES ('004_cms_page_builder', 'CMS page builder schema included by clean installer', NULL, 1, NULL, NOW());
INSERT INTO schema_migrations (version, description, checksum_sha256, batch, execution_ms, applied_at) VALUES ('005_application_reapply_attempts', 'Rejected-application reapply attempt lineage included by clean installer', NULL, 1, NULL, NOW());
INSERT INTO schema_migrations (version, description, checksum_sha256, batch, execution_ms, applied_at) VALUES ('006_merit_selection_payments', 'Merit, selection offer and online payment schema included by clean installer', NULL, 1, NULL, NOW());
SET FOREIGN_KEY_CHECKS = 1;
