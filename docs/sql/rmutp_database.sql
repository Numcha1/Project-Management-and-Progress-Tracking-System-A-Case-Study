-- RMUTP Project Tracker - Combined SQL
-- This file includes full schema + incremental upgrade in one file.
-- Safe to run multiple times.
-- RMUTP Project Tracker schema
-- Compatible with MySQL/MariaDB (including InfinityFree phpMyAdmin import)

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    fullname VARCHAR(150) NOT NULL,
    student_code VARCHAR(30) DEFAULT NULL,
    email VARCHAR(191) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('student', 'teacher', 'admin') NOT NULL DEFAULT 'student',
    reset_token VARCHAR(128) DEFAULT NULL,
    token_expire DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_student_code (student_code),
    UNIQUE KEY uq_users_reset_token (reset_token),
    KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    case_study VARCHAR(255) NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
    advisor_id INT UNSIGNED DEFAULT NULL,
    co_advisor_id INT UNSIGNED DEFAULT NULL,
    pending_advisor_id INT UNSIGNED DEFAULT NULL,
    pending_co_advisor_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_projects_student_id (student_id),
    KEY idx_projects_advisor_id (advisor_id),
    KEY idx_projects_co_advisor_id (co_advisor_id),
    KEY idx_projects_pending_advisor_id (pending_advisor_id),
    KEY idx_projects_pending_co_advisor_id (pending_co_advisor_id),
    KEY idx_projects_progress_created (progress, created_at),
    CONSTRAINT fk_projects_student
        FOREIGN KEY (student_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_projects_advisor
        FOREIGN KEY (advisor_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_projects_co_advisor
        FOREIGN KEY (co_advisor_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_projects_pending_advisor
        FOREIGN KEY (pending_advisor_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_projects_pending_co_advisor
        FOREIGN KEY (pending_co_advisor_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    assignee_name VARCHAR(150) NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('todo', 'done') NOT NULL DEFAULT 'todo',
    teacher_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    file_path VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tasks_project_id (project_id),
    KEY idx_tasks_assignee_name (assignee_name),
    KEY idx_tasks_due_date (due_date),
    KEY idx_tasks_status (status),
    KEY idx_tasks_teacher_status (teacher_status),
    CONSTRAINT fk_tasks_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_members (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    status ENUM('pending', 'accepted') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_project_members_project_user (project_id, user_id),
    KEY idx_project_members_user_id (user_id),
    KEY idx_project_members_status (status),
    CONSTRAINT fk_project_members_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_project_members_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_comments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    comment TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_task_comments_task_id (task_id),
    KEY idx_task_comments_user_id (user_id),
    CONSTRAINT fk_task_comments_task
        FOREIGN KEY (task_id) REFERENCES tasks(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_task_comments_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_return_history (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    reviewer_id INT UNSIGNED NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    attachment_path VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_trh_task_id (task_id),
    KEY idx_trh_project_id (project_id),
    KEY idx_trh_reviewer_id (reviewer_id),
    CONSTRAINT fk_trh_task
        FOREIGN KEY (task_id) REFERENCES tasks(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_trh_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_trh_reviewer
        FOREIGN KEY (reviewer_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NOT NULL,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_system_settings_key (setting_key),
    KEY idx_system_settings_updated_by (updated_by),
    CONSTRAINT fk_system_settings_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_permissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id INT UNSIGNED NOT NULL,
    can_manage_users TINYINT(1) NOT NULL DEFAULT 1,
    can_manage_projects TINYINT(1) NOT NULL DEFAULT 1,
    can_manage_announcements TINYINT(1) NOT NULL DEFAULT 1,
    can_manage_settings TINYINT(1) NOT NULL DEFAULT 1,
    can_manage_permissions TINYINT(1) NOT NULL DEFAULT 1,
    can_backup_restore TINYINT(1) NOT NULL DEFAULT 1,
    can_view_audit TINYINT(1) NOT NULL DEFAULT 1,
    can_send_notifications TINYINT(1) NOT NULL DEFAULT 1,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_permissions_admin_id (admin_id),
    KEY idx_admin_permissions_updated_by (updated_by),
    CONSTRAINT fk_admin_permissions_admin
        FOREIGN KEY (admin_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_admin_permissions_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id INT UNSIGNED NOT NULL,
    action_key VARCHAR(120) NOT NULL,
    action_detail TEXT DEFAULT NULL,
    target_type VARCHAR(80) DEFAULT NULL,
    target_id INT UNSIGNED DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_logs_actor_id (actor_id),
    KEY idx_audit_logs_action_key (action_key),
    KEY idx_audit_logs_target (target_type, target_id),
    KEY idx_audit_logs_created_at (created_at),
    CONSTRAINT fk_audit_logs_actor
        FOREIGN KEY (actor_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'success', 'warning', 'error') NOT NULL DEFAULT 'info',
    related_project_id INT UNSIGNED DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_user_read (user_id, is_read),
    KEY idx_notifications_created_at (created_at),
    KEY idx_notifications_related_project_id (related_project_id),
    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_notifications_project
        FOREIGN KEY (related_project_id) REFERENCES projects(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_dispatch_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dispatch_key VARCHAR(191) NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED DEFAULT NULL,
    task_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_dispatch_key (dispatch_key),
    KEY idx_notification_dispatch_created_at (created_at),
    KEY idx_notification_dispatch_user_id (user_id),
    KEY idx_notification_dispatch_task_id (task_id),
    CONSTRAINT fk_notification_dispatch_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_notification_dispatch_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_notification_dispatch_task
        FOREIGN KEY (task_id) REFERENCES tasks(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_stages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    stage_key VARCHAR(50) NOT NULL,
    stage_name VARCHAR(120) NOT NULL,
    status ENUM('pending', 'in_progress', 'completed', 'rejected') NOT NULL DEFAULT 'pending',
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_project_stages_project_stage (project_id, stage_key),
    KEY idx_project_stages_status (status),
    KEY idx_project_stages_created_by (created_by),
    CONSTRAINT fk_project_stages_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_project_stages_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_milestones (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT DEFAULT NULL,
    due_date DATE DEFAULT NULL,
    weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    status ENUM('pending', 'done', 'overdue', 'cancelled') NOT NULL DEFAULT 'pending',
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_project_milestones_project_id (project_id),
    KEY idx_project_milestones_status (status),
    KEY idx_project_milestones_due_date (due_date),
    KEY idx_project_milestones_created_by (created_by),
    CONSTRAINT fk_project_milestones_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_project_milestones_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    uploaded_by INT UNSIGNED DEFAULT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(100) DEFAULT NULL,
    file_size BIGINT UNSIGNED DEFAULT NULL,
    version_no INT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_project_files_project_id (project_id),
    KEY idx_project_files_uploaded_by (uploaded_by),
    KEY idx_project_files_created_at (created_at),
    KEY idx_project_files_active (is_active),
    CONSTRAINT fk_project_files_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_project_files_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id INT UNSIGNED NOT NULL,
    submitted_by INT UNSIGNED DEFAULT NULL,
    file_path VARCHAR(255) NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT UNSIGNED DEFAULT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_task_submissions_task_id (task_id),
    KEY idx_task_submissions_submitted_by (submitted_by),
    KEY idx_task_submissions_status (status),
    KEY idx_task_submissions_reviewed_by (reviewed_by),
    KEY idx_task_submissions_submitted_at (submitted_at),
    CONSTRAINT fk_task_submissions_task
        FOREIGN KEY (task_id) REFERENCES tasks(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_task_submissions_submitted_by
        FOREIGN KEY (submitted_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_task_submissions_reviewed_by
        FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rubric_criteria (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    criterion_key VARCHAR(80) NOT NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT DEFAULT NULL,
    max_score DECIMAL(6,2) NOT NULL DEFAULT 10.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rubric_criteria_key (criterion_key),
    KEY idx_rubric_criteria_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_evaluations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    evaluator_id INT UNSIGNED DEFAULT NULL,
    evaluation_round TINYINT UNSIGNED NOT NULL DEFAULT 1,
    total_score DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    max_score DECIMAL(6,2) NOT NULL DEFAULT 100.00,
    result ENUM('pending', 'pass', 'revise', 'fail') NOT NULL DEFAULT 'pending',
    comment TEXT DEFAULT NULL,
    evaluated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_project_evaluations_project_id (project_id),
    KEY idx_project_evaluations_evaluator_id (evaluator_id),
    KEY idx_project_evaluations_round (evaluation_round),
    KEY idx_project_evaluations_result (result),
    CONSTRAINT fk_project_evaluations_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_project_evaluations_evaluator
        FOREIGN KEY (evaluator_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_scores (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    evaluation_id BIGINT UNSIGNED NOT NULL,
    criterion_id INT UNSIGNED NOT NULL,
    score DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    note VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_evaluation_scores_eval_criterion (evaluation_id, criterion_id),
    KEY idx_evaluation_scores_criterion_id (criterion_id),
    CONSTRAINT fk_evaluation_scores_evaluation
        FOREIGN KEY (evaluation_id) REFERENCES project_evaluations(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_evaluation_scores_criterion
        FOREIGN KEY (criterion_id) REFERENCES rubric_criteria(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS advisor_meetings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    advisor_id INT UNSIGNED DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    scheduled_at DATETIME NOT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    meeting_type ENUM('onsite', 'online') NOT NULL DEFAULT 'onsite',
    location_or_link VARCHAR(255) DEFAULT NULL,
    agenda TEXT DEFAULT NULL,
    status ENUM('scheduled', 'completed', 'cancelled') NOT NULL DEFAULT 'scheduled',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_advisor_meetings_project_id (project_id),
    KEY idx_advisor_meetings_advisor_id (advisor_id),
    KEY idx_advisor_meetings_created_by (created_by),
    KEY idx_advisor_meetings_scheduled_at (scheduled_at),
    KEY idx_advisor_meetings_status (status),
    CONSTRAINT fk_advisor_meetings_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_advisor_meetings_advisor
        FOREIGN KEY (advisor_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_advisor_meetings_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO announcements (id, message, created_at)
SELECT 1, '', NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM announcements WHERE id = 1
);

INSERT INTO system_settings (setting_key, setting_value)
VALUES
    ('registration_open', '1'),
    ('academic_year', '2569'),
    ('max_tasks_per_project', '5'),
    ('progress_mode', 'approved_only'),
    ('system_email', 'admin@rmutp.ac.th'),
    ('maintenance_mode', '0'),
    ('deadline_reminder_enabled', '1'),
    ('deadline_reminder_days', '3'),
    ('deadline_reminder_interval_minutes', '10'),
    ('deadline_reminder_last_run', '0')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ===== Incremental Upgrade Section =====
-- RMUTP Project Tracker incremental upgrade script
-- Safe to run multiple times on existing databases.
-- Recommended DB engine: MariaDB 10.4+ / MySQL 8+

SET NAMES utf8mb4;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS student_code VARCHAR(30) DEFAULT NULL AFTER fullname,
    ADD COLUMN IF NOT EXISTS reset_token VARCHAR(128) DEFAULT NULL AFTER role,
    ADD COLUMN IF NOT EXISTS token_expire DATETIME DEFAULT NULL AFTER reset_token,
    ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER token_expire,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD UNIQUE KEY IF NOT EXISTS uq_users_email (email),
    ADD UNIQUE KEY IF NOT EXISTS uq_users_student_code (student_code),
    ADD UNIQUE KEY IF NOT EXISTS uq_users_reset_token (reset_token),
    ADD KEY IF NOT EXISTS idx_users_role (role);

ALTER TABLE projects
    ADD COLUMN IF NOT EXISTS status ENUM('pending', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending' AFTER student_id,
    ADD COLUMN IF NOT EXISTS pending_advisor_id INT UNSIGNED DEFAULT NULL AFTER co_advisor_id,
    ADD COLUMN IF NOT EXISTS pending_co_advisor_id INT UNSIGNED DEFAULT NULL AFTER pending_advisor_id,
    ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER pending_co_advisor_id,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD KEY IF NOT EXISTS idx_projects_student_id (student_id),
    ADD KEY IF NOT EXISTS idx_projects_advisor_id (advisor_id),
    ADD KEY IF NOT EXISTS idx_projects_co_advisor_id (co_advisor_id),
    ADD KEY IF NOT EXISTS idx_projects_pending_advisor_id (pending_advisor_id),
    ADD KEY IF NOT EXISTS idx_projects_pending_co_advisor_id (pending_co_advisor_id),
    ADD KEY IF NOT EXISTS idx_projects_progress_created (progress, created_at);

ALTER TABLE tasks
    ADD COLUMN IF NOT EXISTS teacher_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' AFTER status,
    ADD COLUMN IF NOT EXISTS file_path VARCHAR(255) DEFAULT NULL AFTER teacher_status,
    ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER file_path,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD KEY IF NOT EXISTS idx_tasks_project_id (project_id),
    ADD KEY IF NOT EXISTS idx_tasks_assignee_name (assignee_name),
    ADD KEY IF NOT EXISTS idx_tasks_due_date (due_date),
    ADD KEY IF NOT EXISTS idx_tasks_status (status),
    ADD KEY IF NOT EXISTS idx_tasks_teacher_status (teacher_status);

ALTER TABLE project_members
    ADD COLUMN IF NOT EXISTS status ENUM('pending', 'accepted') NOT NULL DEFAULT 'pending' AFTER user_id,
    ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status,
    ADD UNIQUE KEY IF NOT EXISTS uq_project_members_project_user (project_id, user_id),
    ADD KEY IF NOT EXISTS idx_project_members_user_id (user_id),
    ADD KEY IF NOT EXISTS idx_project_members_status (status);

ALTER TABLE task_comments
    ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER comment,
    ADD KEY IF NOT EXISTS idx_task_comments_task_id (task_id),
    ADD KEY IF NOT EXISTS idx_task_comments_user_id (user_id);

ALTER TABLE task_return_history
    ADD COLUMN IF NOT EXISTS note VARCHAR(255) DEFAULT NULL AFTER reviewer_id,
    ADD COLUMN IF NOT EXISTS attachment_path VARCHAR(255) DEFAULT NULL AFTER note,
    ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER attachment_path,
    ADD KEY IF NOT EXISTS idx_trh_task_id (task_id),
    ADD KEY IF NOT EXISTS idx_trh_project_id (project_id),
    ADD KEY IF NOT EXISTS idx_trh_reviewer_id (reviewer_id);

ALTER TABLE system_settings
    ADD COLUMN IF NOT EXISTS updated_by INT UNSIGNED DEFAULT NULL AFTER setting_value,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER updated_by,
    ADD UNIQUE KEY IF NOT EXISTS uq_system_settings_key (setting_key),
    ADD KEY IF NOT EXISTS idx_system_settings_updated_by (updated_by);

ALTER TABLE admin_permissions
    ADD COLUMN IF NOT EXISTS can_manage_projects TINYINT(1) NOT NULL DEFAULT 1 AFTER can_manage_users,
    ADD COLUMN IF NOT EXISTS updated_by INT UNSIGNED DEFAULT NULL AFTER can_send_notifications,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER updated_by,
    ADD UNIQUE KEY IF NOT EXISTS uq_admin_permissions_admin_id (admin_id),
    ADD KEY IF NOT EXISTS idx_admin_permissions_updated_by (updated_by);

ALTER TABLE audit_logs
    ADD COLUMN IF NOT EXISTS action_detail TEXT DEFAULT NULL AFTER action_key,
    ADD COLUMN IF NOT EXISTS target_type VARCHAR(80) DEFAULT NULL AFTER action_detail,
    ADD COLUMN IF NOT EXISTS target_id INT UNSIGNED DEFAULT NULL AFTER target_type,
    ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) DEFAULT NULL AFTER target_id,
    ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER ip_address,
    ADD KEY IF NOT EXISTS idx_audit_logs_actor_id (actor_id),
    ADD KEY IF NOT EXISTS idx_audit_logs_action_key (action_key),
    ADD KEY IF NOT EXISTS idx_audit_logs_target (target_type, target_id),
    ADD KEY IF NOT EXISTS idx_audit_logs_created_at (created_at);

ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS type ENUM('info', 'success', 'warning', 'error') NOT NULL DEFAULT 'info' AFTER message,
    ADD COLUMN IF NOT EXISTS related_project_id INT UNSIGNED DEFAULT NULL AFTER type,
    ADD COLUMN IF NOT EXISTS is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER related_project_id,
    ADD COLUMN IF NOT EXISTS read_at DATETIME DEFAULT NULL AFTER is_read,
    ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER read_at,
    ADD KEY IF NOT EXISTS idx_notifications_user_read (user_id, is_read),
    ADD KEY IF NOT EXISTS idx_notifications_created_at (created_at),
    ADD KEY IF NOT EXISTS idx_notifications_related_project_id (related_project_id);

ALTER TABLE notification_dispatch_log
    ADD COLUMN IF NOT EXISTS dispatch_key VARCHAR(191) NOT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS event_type VARCHAR(80) NOT NULL AFTER dispatch_key,
    ADD COLUMN IF NOT EXISTS project_id INT UNSIGNED DEFAULT NULL AFTER user_id,
    ADD COLUMN IF NOT EXISTS task_id INT UNSIGNED DEFAULT NULL AFTER project_id,
    ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER task_id,
    ADD UNIQUE KEY IF NOT EXISTS uq_notification_dispatch_key (dispatch_key),
    ADD KEY IF NOT EXISTS idx_notification_dispatch_created_at (created_at),
    ADD KEY IF NOT EXISTS idx_notification_dispatch_user_id (user_id),
    ADD KEY IF NOT EXISTS idx_notification_dispatch_task_id (task_id);

INSERT INTO announcements (id, message, created_at)
SELECT 1, '', NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM announcements WHERE id = 1
);

INSERT INTO system_settings (setting_key, setting_value)
VALUES
    ('registration_open', '1'),
    ('academic_year', '2569'),
    ('max_tasks_per_project', '5'),
    ('progress_mode', 'approved_only'),
    ('system_email', 'admin@rmutp.ac.th'),
    ('maintenance_mode', '0'),
    ('deadline_reminder_enabled', '1'),
    ('deadline_reminder_days', '3'),
    ('deadline_reminder_interval_minutes', '10'),
    ('deadline_reminder_last_run', '0')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- Cleanup legacy/unused settings keys (kept for backward compatibility in older versions)
DELETE FROM system_settings
WHERE setting_key IN ('allow_registration', 'items_per_page', 'system_name');

