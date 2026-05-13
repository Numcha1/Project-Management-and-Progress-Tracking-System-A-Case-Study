<?php

declare(strict_types=1);

if (!function_exists('ensureAcademicLifecycleTables')) {
    function ensureAcademicLifecycleTables(PDO $conn): void
    {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS proposals (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id INT UNSIGNED NOT NULL,
                submitted_by INT UNSIGNED NOT NULL,
                current_version_no INT UNSIGNED NOT NULL DEFAULT 1,
                status ENUM('draft', 'submitted', 'in_review', 'approved', 'rejected', 'revise_required') NOT NULL DEFAULT 'draft',
                program_code VARCHAR(50) DEFAULT NULL,
                semester_label VARCHAR(50) DEFAULT NULL,
                title VARCHAR(255) NOT NULL,
                objective TEXT DEFAULT NULL,
                summary TEXT DEFAULT NULL,
                last_decision_note VARCHAR(255) DEFAULT NULL,
                submitted_at DATETIME DEFAULT NULL,
                decided_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_proposals_project_id (project_id),
                KEY idx_proposals_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $conn->exec("
            CREATE TABLE IF NOT EXISTS proposal_versions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                proposal_id BIGINT UNSIGNED NOT NULL,
                version_no INT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                objective TEXT DEFAULT NULL,
                summary TEXT DEFAULT NULL,
                status_snapshot ENUM('draft', 'submitted', 'in_review', 'approved', 'rejected', 'revise_required') NOT NULL DEFAULT 'draft',
                changed_by INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_proposal_versions_unique (proposal_id, version_no),
                KEY idx_proposal_versions_changed_by (changed_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $conn->exec("
            CREATE TABLE IF NOT EXISTS proposal_reviews (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                proposal_id BIGINT UNSIGNED NOT NULL,
                reviewer_id INT UNSIGNED NOT NULL,
                action_key ENUM('comment', 'approved', 'rejected', 'revise_required') NOT NULL DEFAULT 'comment',
                note TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_proposal_reviews_proposal_id (proposal_id),
                KEY idx_proposal_reviews_reviewer_id (reviewer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $conn->exec("
            CREATE TABLE IF NOT EXISTS proposal_committees (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                proposal_id BIGINT UNSIGNED NOT NULL,
                quorum_min TINYINT UNSIGNED NOT NULL DEFAULT 2,
                status ENUM('draft', 'active', 'closed') NOT NULL DEFAULT 'draft',
                created_by INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_proposal_committees_proposal (proposal_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $conn->exec("
            CREATE TABLE IF NOT EXISTS proposal_committee_members (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                committee_id BIGINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                role_key ENUM('advisor', 'reviewer', 'chair') NOT NULL DEFAULT 'reviewer',
                can_vote TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_proposal_committee_member (committee_id, user_id),
                KEY idx_proposal_committee_members_role (role_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $conn->exec("
            CREATE TABLE IF NOT EXISTS milestone_templates (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                program_code VARCHAR(50) NOT NULL,
                template_name VARCHAR(180) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_milestone_templates_program_name (program_code, template_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $conn->exec("
            CREATE TABLE IF NOT EXISTS milestone_template_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                template_id BIGINT UNSIGNED NOT NULL,
                sequence_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                title VARCHAR(180) NOT NULL,
                default_due_offset_days SMALLINT NOT NULL DEFAULT 14,
                weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                lock_before_submission TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_milestone_template_items_template_id (template_id),
                KEY idx_milestone_template_items_sequence (template_id, sequence_no)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $conn->exec("
            CREATE TABLE IF NOT EXISTS milestone_progress_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                milestone_id INT UNSIGNED NOT NULL,
                action_key ENUM('created', 'updated', 'status_change', 'locked', 'unlocked') NOT NULL DEFAULT 'updated',
                before_status VARCHAR(50) DEFAULT NULL,
                after_status VARCHAR(50) DEFAULT NULL,
                note VARCHAR(255) DEFAULT NULL,
                action_by INT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_milestone_progress_logs_milestone_id (milestone_id),
                KEY idx_milestone_progress_logs_action_key (action_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $conn->exec("
            ALTER TABLE projects
                ADD COLUMN IF NOT EXISTS proposal_status ENUM('not_started', 'draft', 'submitted', 'approved', 'rejected', 'revise_required') NOT NULL DEFAULT 'not_started',
                ADD COLUMN IF NOT EXISTS program_code VARCHAR(50) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS semester_label VARCHAR(50) DEFAULT NULL
        ");

        $conn->exec("
            ALTER TABLE project_milestones
                ADD COLUMN IF NOT EXISTS template_item_id BIGINT UNSIGNED DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS locked_by_policy TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN IF NOT EXISTS lock_until DATETIME DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS reviewed_by INT UNSIGNED DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS reviewed_at DATETIME DEFAULT NULL
        ");
    }
}

if (!function_exists('syncProposalStatusToProject')) {
    function syncProposalStatusToProject(PDO $conn, int $projectId, string $proposalStatus, ?string $programCode = null, ?string $semesterLabel = null): void
    {
        $stmt = $conn->prepare("
            UPDATE projects
            SET proposal_status = ?,
                program_code = ?,
                semester_label = ?
            WHERE id = ?
        ");
        $stmt->execute([$proposalStatus, $programCode, $semesterLabel, $projectId]);
    }
}

if (!function_exists('createProposalVersionSnapshot')) {
    function createProposalVersionSnapshot(PDO $conn, int $proposalId, int $versionNo, string $title, string $objective, string $summary, string $statusSnapshot, int $changedBy): void
    {
        $stmt = $conn->prepare("
            INSERT INTO proposal_versions (
                proposal_id, version_no, title, objective, summary, status_snapshot, changed_by, created_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                objective = VALUES(objective),
                summary = VALUES(summary),
                status_snapshot = VALUES(status_snapshot),
                changed_by = VALUES(changed_by),
                created_at = NOW()
        ");
        $stmt->execute([$proposalId, $versionNo, $title, $objective, $summary, $statusSnapshot, $changedBy]);
    }
}

if (!function_exists('ensureProposalCommittee')) {
    function ensureProposalCommittee(PDO $conn, int $proposalId, int $createdBy, int $quorumMin = 2): int
    {
        $check = $conn->prepare('SELECT id FROM proposal_committees WHERE proposal_id = ? LIMIT 1');
        $check->execute([$proposalId]);
        $existing = (int)$check->fetchColumn();
        if ($existing > 0) {
            return $existing;
        }

        $create = $conn->prepare("
            INSERT INTO proposal_committees (proposal_id, quorum_min, status, created_by, created_at, updated_at)
            VALUES (?, ?, 'active', ?, NOW(), NOW())
        ");
        $create->execute([$proposalId, max(1, $quorumMin), $createdBy]);
        return (int)$conn->lastInsertId();
    }
}

if (!function_exists('getProposalByProjectId')) {
    function getProposalByProjectId(PDO $conn, int $projectId): ?array
    {
        $stmt = $conn->prepare("SELECT * FROM proposals WHERE project_id = ? LIMIT 1");
        $stmt->execute([$projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('countCommitteeVotes')) {
    function countCommitteeVotes(PDO $conn, int $proposalId): array
    {
        $stmt = $conn->prepare("
            SELECT
                SUM(CASE WHEN pr.action_key = 'approved' THEN 1 ELSE 0 END) AS approve_count,
                SUM(CASE WHEN pr.action_key = 'rejected' THEN 1 ELSE 0 END) AS reject_count,
                SUM(CASE WHEN pr.action_key = 'revise_required' THEN 1 ELSE 0 END) AS revise_count
            FROM proposal_reviews pr
            WHERE pr.proposal_id = ?
        ");
        $stmt->execute([$proposalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'approve' => (int)($row['approve_count'] ?? 0),
            'reject' => (int)($row['reject_count'] ?? 0),
            'revise' => (int)($row['revise_count'] ?? 0),
        ];
    }
}

if (!function_exists('proposalCommitteeHasQuorum')) {
    function proposalCommitteeHasQuorum(PDO $conn, int $proposalId): bool
    {
        $stmt = $conn->prepare("
            SELECT pc.quorum_min, COUNT(pcm.id) AS member_count
            FROM proposal_committees pc
            LEFT JOIN proposal_committee_members pcm ON pcm.committee_id = pc.id AND pcm.can_vote = 1
            WHERE pc.proposal_id = ?
            GROUP BY pc.id, pc.quorum_min
            LIMIT 1
        ");
        $stmt->execute([$proposalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $quorumMin = max(1, (int)($row['quorum_min'] ?? 1));
        $memberCount = (int)($row['member_count'] ?? 0);
        return $memberCount >= $quorumMin;
    }
}

if (!function_exists('seedDefaultMilestoneTemplateIfMissing')) {
    function seedDefaultMilestoneTemplateIfMissing(PDO $conn, int $adminId = 0): void
    {
        $insertTemplate = $conn->prepare("
            INSERT INTO milestone_templates (program_code, template_name, is_active, created_by, created_at, updated_at)
            VALUES ('GENERIC-BIT', 'Default Project Lifecycle', 1, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE is_active = VALUES(is_active), updated_at = NOW()
        ");
        $insertTemplate->execute([$adminId > 0 ? $adminId : null]);

        $templateIdStmt = $conn->prepare("
            SELECT id FROM milestone_templates
            WHERE program_code = 'GENERIC-BIT' AND template_name = 'Default Project Lifecycle'
            LIMIT 1
        ");
        $templateIdStmt->execute();
        $templateId = (int)$templateIdStmt->fetchColumn();
        if ($templateId <= 0) {
            return;
        }

        $items = [
            [1, 'Proposal Submission', 7, 10.00, 0],
            [2, 'Requirement Validation', 21, 20.00, 1],
            [3, 'Implementation Sprint', 45, 40.00, 1],
            [4, 'Pre-Defense Review', 60, 30.00, 1],
        ];
        $stmt = $conn->prepare("
            INSERT INTO milestone_template_items (
                template_id, sequence_no, title, default_due_offset_days, weight_percent, lock_before_submission, created_at
            )
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                default_due_offset_days = VALUES(default_due_offset_days),
                weight_percent = VALUES(weight_percent),
                lock_before_submission = VALUES(lock_before_submission)
        ");
        foreach ($items as $item) {
            $stmt->execute([$templateId, $item[0], $item[1], $item[2], $item[3], $item[4]]);
        }
    }
}

if (!function_exists('syncProjectMilestonesFromTemplate')) {
    function syncProjectMilestonesFromTemplate(PDO $conn, int $projectId, string $programCode, int $createdBy): int
    {
        $templateStmt = $conn->prepare("
            SELECT id
            FROM milestone_templates
            WHERE program_code = ? AND is_active = 1
            ORDER BY id DESC
            LIMIT 1
        ");
        $templateStmt->execute([$programCode]);
        $templateId = (int)$templateStmt->fetchColumn();
        if ($templateId <= 0) {
            return 0;
        }

        $projectCreatedAtStmt = $conn->prepare("SELECT created_at FROM projects WHERE id = ? LIMIT 1");
        $projectCreatedAtStmt->execute([$projectId]);
        $projectCreatedAt = $projectCreatedAtStmt->fetchColumn();
        $baseDate = $projectCreatedAt ? date('Y-m-d', strtotime((string)$projectCreatedAt)) : date('Y-m-d');

        $itemsStmt = $conn->prepare("
            SELECT id, sequence_no, title, default_due_offset_days, weight_percent, lock_before_submission
            FROM milestone_template_items
            WHERE template_id = ?
            ORDER BY sequence_no ASC
        ");
        $itemsStmt->execute([$templateId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) {
            return 0;
        }

        $inserted = 0;
        $insertMilestone = $conn->prepare("
            INSERT INTO project_milestones (
                project_id, template_item_id, title, description, due_date, weight_percent,
                status, locked_by_policy, lock_until, created_by, created_at, updated_at
            )
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, NOW(), NOW())
        ");
        $insertLog = $conn->prepare("
            INSERT INTO milestone_progress_logs (
                milestone_id, action_key, before_status, after_status, note, action_by, created_at
            )
            VALUES (?, 'created', NULL, 'pending', 'Generated from lifecycle template', ?, NOW())
        ");

        foreach ($items as $item) {
            $existsStmt = $conn->prepare("
                SELECT id FROM project_milestones
                WHERE project_id = ? AND title = ?
                LIMIT 1
            ");
            $existsStmt->execute([$projectId, $item['title']]);
            if ($existsStmt->fetchColumn()) {
                continue;
            }

            $offsetDays = (int)($item['default_due_offset_days'] ?? 14);
            $dueDate = date('Y-m-d', strtotime($baseDate . ' +' . $offsetDays . ' days'));
            $lockedByPolicy = (int)($item['lock_before_submission'] ?? 0) === 1 ? 1 : 0;
            $lockUntil = $lockedByPolicy ? date('Y-m-d H:i:s', strtotime($dueDate . ' 23:59:59')) : null;

            $insertMilestone->execute([
                $projectId,
                (int)$item['id'],
                (string)$item['title'],
                'Template: ' . (string)$item['title'],
                $dueDate,
                (float)$item['weight_percent'],
                $lockedByPolicy,
                $lockUntil,
                $createdBy,
            ]);

            $milestoneId = (int)$conn->lastInsertId();
            if ($milestoneId > 0) {
                $insertLog->execute([$milestoneId, $createdBy]);
            }
            $inserted++;
        }

        return $inserted;
    }
}

if (!function_exists('refreshMilestoneOverdueStatus')) {
    function refreshMilestoneOverdueStatus(PDO $conn): int
    {
        $stmt = $conn->prepare("
            UPDATE project_milestones
            SET status = 'overdue', updated_at = NOW()
            WHERE status = 'pending'
              AND due_date IS NOT NULL
              AND due_date < CURDATE()
        ");
        $stmt->execute();
        return (int)$stmt->rowCount();
    }
}
