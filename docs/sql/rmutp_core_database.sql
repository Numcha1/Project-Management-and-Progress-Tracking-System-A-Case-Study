-- RMUTP Core Database (University-level control plane)
-- Safe to run multiple times.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS faculties (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(40) NOT NULL,
    name_th VARCHAR(180) NOT NULL,
    name_en VARCHAR(180) DEFAULT NULL,
    tenant_db_name VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_faculties_code (code),
    UNIQUE KEY uq_faculties_tenant_db_name (tenant_db_name),
    KEY idx_faculties_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS programs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    faculty_id INT UNSIGNED NOT NULL,
    code VARCHAR(50) NOT NULL,
    name_th VARCHAR(220) NOT NULL,
    name_en VARCHAR(220) DEFAULT NULL,
    level ENUM('undergraduate', 'master', 'doctorate') NOT NULL DEFAULT 'undergraduate',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_programs_faculty_code (faculty_id, code),
    KEY idx_programs_active (is_active),
    CONSTRAINT fk_programs_faculty
        FOREIGN KEY (faculty_id) REFERENCES faculties(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS semesters (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    faculty_id INT UNSIGNED DEFAULT NULL,
    academic_year VARCHAR(20) NOT NULL,
    term_no TINYINT UNSIGNED NOT NULL DEFAULT 1,
    starts_at DATE DEFAULT NULL,
    ends_at DATE DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_semesters_faculty_id (faculty_id),
    KEY idx_semesters_active (is_active),
    KEY idx_semesters_year_term (academic_year, term_no),
    CONSTRAINT fk_semesters_faculty
        FOREIGN KEY (faculty_id) REFERENCES faculties(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_policies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    faculty_id INT UNSIGNED DEFAULT NULL,
    program_id INT UNSIGNED DEFAULT NULL,
    project_type VARCHAR(120) DEFAULT NULL,
    step_no TINYINT UNSIGNED NOT NULL DEFAULT 1,
    approver_role ENUM('teacher', 'committee_chair', 'admin') NOT NULL DEFAULT 'teacher',
    min_approvals TINYINT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_approval_policies_faculty_program (faculty_id, program_id),
    KEY idx_approval_policies_active (is_active),
    CONSTRAINT fk_approval_policies_faculty
        FOREIGN KEY (faculty_id) REFERENCES faculties(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_approval_policies_program
        FOREIGN KEY (program_id) REFERENCES programs(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_backup_policies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    faculty_id INT UNSIGNED NOT NULL,
    frequency ENUM('daily', 'weekly', 'monthly') NOT NULL DEFAULT 'daily',
    retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    verify_restore TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_backup_policies_faculty (faculty_id),
    CONSTRAINT fk_tenant_backup_policies_faculty
        FOREIGN KEY (faculty_id) REFERENCES faculties(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_retention_policies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    faculty_id INT UNSIGNED DEFAULT NULL,
    target_key VARCHAR(80) NOT NULL,
    retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 365,
    purge_strategy ENUM('soft_delete', 'hard_delete') NOT NULL DEFAULT 'soft_delete',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_data_retention_target (faculty_id, target_key),
    CONSTRAINT fk_data_retention_faculty
        FOREIGN KEY (faculty_id) REFERENCES faculties(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_import_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    faculty_id INT UNSIGNED NOT NULL,
    source_file VARCHAR(255) NOT NULL,
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    inserted_rows INT UNSIGNED NOT NULL DEFAULT 0,
    updated_rows INT UNSIGNED NOT NULL DEFAULT 0,
    failed_rows INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('running', 'completed', 'failed') NOT NULL DEFAULT 'running',
    started_by INT UNSIGNED DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_user_import_jobs_faculty (faculty_id),
    KEY idx_user_import_jobs_status (status),
    CONSTRAINT fk_user_import_jobs_faculty
        FOREIGN KEY (faculty_id) REFERENCES faculties(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_import_job_rows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    row_number INT UNSIGNED NOT NULL,
    email VARCHAR(191) DEFAULT NULL,
    action_result ENUM('inserted', 'updated', 'failed', 'skipped') NOT NULL DEFAULT 'skipped',
    message VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_import_job_rows_job_id (job_id),
    KEY idx_user_import_job_rows_result (action_result),
    CONSTRAINT fk_user_import_job_rows_job
        FOREIGN KEY (job_id) REFERENCES user_import_jobs(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO faculties (code, name_th, name_en, tenant_db_name, is_active, created_at, updated_at)
VALUES
    ('fst', 'คณะวิทยาศาสตร์และเทคโนโลยี', 'Faculty of Science and Technology', 'rmutp_fst', 1, NOW(), NOW()),
    ('eng', 'คณะวิศวกรรมศาสตร์', 'Faculty of Engineering', 'rmutp_eng', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    name_th = VALUES(name_th),
    name_en = VALUES(name_en),
    tenant_db_name = VALUES(tenant_db_name),
    is_active = VALUES(is_active),
    updated_at = NOW();

INSERT INTO tenant_backup_policies (faculty_id, frequency, retention_days, verify_restore, is_active)
SELECT f.id, 'daily', 30, 1, 1
FROM faculties f
WHERE f.is_active = 1
ON DUPLICATE KEY UPDATE
    frequency = VALUES(frequency),
    retention_days = VALUES(retention_days),
    verify_restore = VALUES(verify_restore),
    is_active = VALUES(is_active);

INSERT INTO data_retention_policies (faculty_id, target_key, retention_days, purge_strategy, is_active)
SELECT f.id, 'audit_logs', 730, 'soft_delete', 1
FROM faculties f
WHERE f.is_active = 1
ON DUPLICATE KEY UPDATE
    retention_days = VALUES(retention_days),
    purge_strategy = VALUES(purge_strategy),
    is_active = VALUES(is_active),
    updated_at = NOW();

INSERT INTO data_retention_policies (faculty_id, target_key, retention_days, purge_strategy, is_active)
SELECT f.id, 'task_files', 365, 'soft_delete', 1
FROM faculties f
WHERE f.is_active = 1
ON DUPLICATE KEY UPDATE
    retention_days = VALUES(retention_days),
    purge_strategy = VALUES(purge_strategy),
    is_active = VALUES(is_active),
    updated_at = NOW();
