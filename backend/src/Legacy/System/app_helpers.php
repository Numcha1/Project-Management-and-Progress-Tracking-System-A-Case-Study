<?php

if (!function_exists('systemSetting')) {
    function systemSetting(PDO $conn, string $key, ?string $defaultValue = null): string
    {
        $stmt = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        if ($value === false || $value === null) {
            if ($defaultValue !== null) {
                $upsert = $conn->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, updated_at)
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
                ");
                $upsert->execute([$key, $defaultValue]);
                return $defaultValue;
            }
            return '';
        }

        return (string)$value;
    }
}

if (!function_exists('systemSettingInt')) {
    function systemSettingInt(PDO $conn, string $key, int $defaultValue, int $min = 1, int $max = 999): int
    {
        $raw = systemSetting($conn, $key, (string)$defaultValue);
        $value = (int)$raw;
        if ($value < $min) {
            $value = $min;
        }
        if ($value > $max) {
            $value = $max;
        }
        return $value;
    }
}

if (!function_exists('saveSystemSetting')) {
    function saveSystemSetting(PDO $conn, string $key, string $value, ?int $updatedBy = null): void
    {
        $stmt = $conn->prepare("
            INSERT INTO system_settings (setting_key, setting_value, updated_by, updated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
        ");
        $stmt->execute([$key, $value, $updatedBy]);
    }
}

if (!function_exists('getMaxTasksPerProject')) {
    function getMaxTasksPerProject(PDO $conn): int
    {
        return systemSettingInt($conn, 'max_tasks_per_project', 5, 1, 20);
    }
}

if (!function_exists('getProgressMode')) {
    function getProgressMode(PDO $conn): string
    {
        $mode = strtolower(systemSetting($conn, 'progress_mode', 'approved_only'));
        if (!in_array($mode, ['approved_only', 'done_or_approved'], true)) {
            $mode = 'approved_only';
        }
        return $mode;
    }
}

if (!function_exists('isRegistrationOpen')) {
    function isRegistrationOpen(PDO $conn): bool
    {
        return systemSettingInt($conn, 'registration_open', 1, 0, 1) === 1;
    }
}

if (!function_exists('getAcademicYear')) {
    function getAcademicYear(PDO $conn): string
    {
        $defaultYear = (string)((int)date('Y') + 543);
        return systemSetting($conn, 'academic_year', $defaultYear);
    }
}

if (!function_exists('recalculateProjectProgress')) {
    function recalculateProjectProgress(PDO $conn, int $projectId): int
    {
        $maxTasks = getMaxTasksPerProject($conn);
        $progressMode = getProgressMode($conn);

        if ($progressMode === 'done_or_approved') {
            $stmt = $conn->prepare("
                SELECT COUNT(*) 
                FROM tasks
                WHERE project_id = ?
                  AND (status = 'done' OR teacher_status = 'approved')
            ");
            $stmt->execute([$projectId]);
        } else {
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM tasks
                WHERE project_id = ?
                  AND teacher_status = 'approved'
            ");
            $stmt->execute([$projectId]);
        }

        $doneCount = min((int)$stmt->fetchColumn(), $maxTasks);
        $percent = (int)round(($doneCount / $maxTasks) * 100);

        $update = $conn->prepare('UPDATE projects SET progress = ? WHERE id = ?');
        $update->execute([$percent, $projectId]);

        return $percent;
    }
}

if (!function_exists('defaultAdminPermissions')) {
    function defaultAdminPermissions(): array
    {
        return [
            'can_manage_users' => 1,
            'can_manage_projects' => 1,
            'can_manage_announcements' => 1,
            'can_manage_settings' => 1,
            'can_manage_permissions' => 1,
            'can_backup_restore' => 1,
            'can_view_audit' => 1,
            'can_send_notifications' => 1,
        ];
    }
}

if (!function_exists('canUseAdminProjectPermissionColumn')) {
    function canUseAdminProjectPermissionColumn(PDO $conn): bool
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        $resolved = false;
        try {
            $stmt = $conn->query("SHOW COLUMNS FROM admin_permissions LIKE 'can_manage_projects'");
            $hasColumn = $stmt && $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$hasColumn) {
                $conn->exec("ALTER TABLE admin_permissions ADD COLUMN can_manage_projects TINYINT(1) NOT NULL DEFAULT 1 AFTER can_manage_users");
                $stmt = $conn->query("SHOW COLUMNS FROM admin_permissions LIKE 'can_manage_projects'");
                $hasColumn = $stmt && $stmt->fetch(PDO::FETCH_ASSOC);
            }

            $resolved = (bool)$hasColumn;
        } catch (Throwable $e) {
            $resolved = false;
        }

        return $resolved;
    }
}

if (!function_exists('ensureAdminPermissionRow')) {
    function ensureAdminPermissionRow(PDO $conn, int $adminId): void
    {
        $defaults = defaultAdminPermissions();
        $hasProjectPermissionColumn = canUseAdminProjectPermissionColumn($conn);

        if ($hasProjectPermissionColumn) {
            $stmt = $conn->prepare("
                INSERT INTO admin_permissions (
                    admin_id,
                    can_manage_users,
                    can_manage_projects,
                    can_manage_announcements,
                    can_manage_settings,
                    can_manage_permissions,
                    can_backup_restore,
                    can_view_audit,
                    can_send_notifications,
                    updated_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE updated_at = updated_at
            ");
            $stmt->execute([
                $adminId,
                $defaults['can_manage_users'],
                $defaults['can_manage_projects'],
                $defaults['can_manage_announcements'],
                $defaults['can_manage_settings'],
                $defaults['can_manage_permissions'],
                $defaults['can_backup_restore'],
                $defaults['can_view_audit'],
                $defaults['can_send_notifications'],
            ]);
            return;
        }

        $stmt = $conn->prepare("
            INSERT INTO admin_permissions (
                admin_id,
                can_manage_users,
                can_manage_announcements,
                can_manage_settings,
                can_manage_permissions,
                can_backup_restore,
                can_view_audit,
                can_send_notifications,
                updated_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE updated_at = updated_at
        ");
        $stmt->execute([
            $adminId,
            $defaults['can_manage_users'],
            $defaults['can_manage_announcements'],
            $defaults['can_manage_settings'],
            $defaults['can_manage_permissions'],
            $defaults['can_backup_restore'],
            $defaults['can_view_audit'],
            $defaults['can_send_notifications'],
        ]);
    }
}

if (!function_exists('getAdminPermissions')) {
    function getAdminPermissions(PDO $conn, int $adminId): array
    {
        $hasProjectPermissionColumn = canUseAdminProjectPermissionColumn($conn);
        ensureAdminPermissionRow($conn, $adminId);
        $stmt = $conn->prepare('SELECT * FROM admin_permissions WHERE admin_id = ? LIMIT 1');
        $stmt->execute([$adminId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $defaults = defaultAdminPermissions();
        if (!$row) {
            return $defaults;
        }

        foreach ($defaults as $key => $defaultValue) {
            $defaults[$key] = isset($row[$key]) ? (int)$row[$key] : $defaultValue;
        }
        if (!$hasProjectPermissionColumn) {
            $defaults['can_manage_projects'] = isset($row['can_manage_users']) ? (int)$row['can_manage_users'] : $defaults['can_manage_projects'];
        }
        return $defaults;
    }
}

if (!function_exists('updateAdminPermissions')) {
    function updateAdminPermissions(PDO $conn, int $adminId, array $permissions, ?int $updatedBy = null): void
    {
        $defaults = defaultAdminPermissions();
        $hasProjectPermissionColumn = canUseAdminProjectPermissionColumn($conn);
        $final = [];
        foreach ($defaults as $key => $defaultValue) {
            $final[$key] = !empty($permissions[$key]) ? 1 : 0;
        }

        if ($hasProjectPermissionColumn) {
            $stmt = $conn->prepare("
                INSERT INTO admin_permissions (
                    admin_id,
                    can_manage_users,
                    can_manage_projects,
                    can_manage_announcements,
                    can_manage_settings,
                    can_manage_permissions,
                    can_backup_restore,
                    can_view_audit,
                    can_send_notifications,
                    updated_by,
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    can_manage_users = VALUES(can_manage_users),
                    can_manage_projects = VALUES(can_manage_projects),
                    can_manage_announcements = VALUES(can_manage_announcements),
                    can_manage_settings = VALUES(can_manage_settings),
                    can_manage_permissions = VALUES(can_manage_permissions),
                    can_backup_restore = VALUES(can_backup_restore),
                    can_view_audit = VALUES(can_view_audit),
                    can_send_notifications = VALUES(can_send_notifications),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()
            ");
            $stmt->execute([
                $adminId,
                $final['can_manage_users'],
                $final['can_manage_projects'],
                $final['can_manage_announcements'],
                $final['can_manage_settings'],
                $final['can_manage_permissions'],
                $final['can_backup_restore'],
                $final['can_view_audit'],
                $final['can_send_notifications'],
                $updatedBy
            ]);
            return;
        }

        $stmt = $conn->prepare("
            INSERT INTO admin_permissions (
                admin_id,
                can_manage_users,
                can_manage_announcements,
                can_manage_settings,
                can_manage_permissions,
                can_backup_restore,
                can_view_audit,
                can_send_notifications,
                updated_by,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                can_manage_users = VALUES(can_manage_users),
                can_manage_announcements = VALUES(can_manage_announcements),
                can_manage_settings = VALUES(can_manage_settings),
                can_manage_permissions = VALUES(can_manage_permissions),
                can_backup_restore = VALUES(can_backup_restore),
                can_view_audit = VALUES(can_view_audit),
                can_send_notifications = VALUES(can_send_notifications),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
        ");
        $stmt->execute([
            $adminId,
            $final['can_manage_users'],
            $final['can_manage_announcements'],
            $final['can_manage_settings'],
            $final['can_manage_permissions'],
            $final['can_backup_restore'],
            $final['can_view_audit'],
            $final['can_send_notifications'],
            $updatedBy
        ]);
    }
}

if (!function_exists('hasAdminPermission')) {
    function hasAdminPermission(array $permissionMap, string $permissionKey): bool
    {
        if (
            $permissionKey === 'can_manage_projects'
            && !array_key_exists('can_manage_projects', $permissionMap)
        ) {
            return !empty($permissionMap['can_manage_users']);
        }
        return !empty($permissionMap[$permissionKey]);
    }
}

if (!function_exists('writeAuditLog')) {
    function writeAuditLog(
        PDO $conn,
        int $actorId,
        string $actionKey,
        string $actionDetail = '',
        ?string $targetType = null,
        ?int $targetId = null,
        ?array $beforePayload = null,
        ?array $afterPayload = null,
        ?string $requestId = null
    ): void {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $actorRole = (string)($_SESSION['role'] ?? '');
            $tenantCtx = function_exists('tenantRuntimeContext') ? tenantRuntimeContext() : [];
            $tenantCode = (string)($tenantCtx['tenant_code'] ?? '');
            $requestId = $requestId ?: (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
            $beforeJson = $beforePayload !== null ? json_encode($beforePayload, JSON_UNESCAPED_UNICODE) : null;
            $afterJson = $afterPayload !== null ? json_encode($afterPayload, JSON_UNESCAPED_UNICODE) : null;

            try {
                $stmt = $conn->prepare("
                    INSERT INTO audit_logs (
                        actor_id, action_key, action_detail, target_type, target_id, ip_address,
                        actor_role, request_id, tenant_code, before_payload, after_payload, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $actorId,
                    $actionKey,
                    $actionDetail,
                    $targetType,
                    $targetId,
                    $ip,
                    $actorRole !== '' ? $actorRole : null,
                    $requestId !== '' ? $requestId : null,
                    $tenantCode !== '' ? $tenantCode : null,
                    $beforeJson,
                    $afterJson
                ]);
                return;
            } catch (Throwable $inner) {
                $stmt = $conn->prepare("
                    INSERT INTO audit_logs (
                        actor_id, action_key, action_detail, target_type, target_id, ip_address, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$actorId, $actionKey, $actionDetail, $targetType, $targetId, $ip]);
            }
        } catch (Exception $e) {
            // Keep primary workflow running even if audit logging fails.
        }
    }
}

if (!function_exists('createNotification')) {
    function createNotification(
        PDO $conn,
        int $userId,
        string $title,
        string $message,
        string $type = 'info',
        ?int $relatedProjectId = null
    ): void {
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, type, related_project_id, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([$userId, $title, $message, $type, $relatedProjectId]);
    }
}

if (!function_exists('createNotificationForUsers')) {
    function createNotificationForUsers(
        PDO $conn,
        array $userIds,
        string $title,
        string $message,
        string $type = 'info',
        ?int $relatedProjectId = null
    ): void {
        $uniqueIds = array_values(array_unique(array_map('intval', $userIds)));
        foreach ($uniqueIds as $uid) {
            if ($uid > 0) {
                createNotification($conn, $uid, $title, $message, $type, $relatedProjectId);
            }
        }
    }
}

if (!function_exists('createNotificationByRole')) {
    function createNotificationByRole(
        PDO $conn,
        string $role,
        string $title,
        string $message,
        string $type = 'info',
        ?int $relatedProjectId = null
    ): void {
        $stmt = $conn->prepare('SELECT id FROM users WHERE role = ?');
        $stmt->execute([$role]);
        $userIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        createNotificationForUsers($conn, $userIds, $title, $message, $type, $relatedProjectId);
    }
}

if (!function_exists('fetchUnreadNotifications')) {
    function fetchUnreadNotifications(PDO $conn, int $userId, int $limit = 8): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $conn->prepare("
            SELECT *
            FROM notifications
            WHERE user_id = ? AND is_read = 0
            ORDER BY created_at DESC
            LIMIT $limit
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('countUnreadNotifications')) {
    function countUnreadNotifications(PDO $conn, int $userId): int
    {
        $stmt = $conn->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('markNotificationAsRead')) {
    function markNotificationAsRead(PDO $conn, int $notificationId, int $userId): void
    {
        $stmt = $conn->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?');
        $stmt->execute([$notificationId, $userId]);
    }
}

if (!function_exists('markAllNotificationsAsRead')) {
    function markAllNotificationsAsRead(PDO $conn, int $userId): void
    {
        $stmt = $conn->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
    }
}

if (!function_exists('isDeadlineReminderEnabled')) {
    function isDeadlineReminderEnabled(PDO $conn): bool
    {
        return systemSettingInt($conn, 'deadline_reminder_enabled', 1, 0, 1) === 1;
    }
}

if (!function_exists('getDeadlineReminderDays')) {
    function getDeadlineReminderDays(PDO $conn): int
    {
        return systemSettingInt($conn, 'deadline_reminder_days', 3, 1, 30);
    }
}

if (!function_exists('getDeadlineReminderIntervalMinutes')) {
    function getDeadlineReminderIntervalMinutes(PDO $conn): int
    {
        return systemSettingInt($conn, 'deadline_reminder_interval_minutes', 10, 1, 120);
    }
}

if (!function_exists('fetchDeadlineReminderStats')) {
    function fetchDeadlineReminderStats(PDO $conn, ?int $daysAhead = null): array
    {
        $daysAhead = $daysAhead ?? getDeadlineReminderDays($conn);
        $daysAhead = max(1, min(30, $daysAhead));

        $sql = "
            SELECT
                SUM(CASE WHEN t.due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_count,
                SUM(CASE WHEN t.due_date = CURDATE() THEN 1 ELSE 0 END) AS due_today_count,
                SUM(CASE WHEN t.due_date > CURDATE() AND t.due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 1 ELSE 0 END) AS due_soon_count
            FROM tasks t
            WHERE t.due_date IS NOT NULL
              AND t.due_date <> '0000-00-00'
              AND t.status <> 'done'
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$daysAhead]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'overdue_count' => (int)($row['overdue_count'] ?? 0),
            'due_today_count' => (int)($row['due_today_count'] ?? 0),
            'due_soon_count' => (int)($row['due_soon_count'] ?? 0),
            'days_ahead' => $daysAhead,
        ];
    }
}

if (!function_exists('dispatchAutomaticDeadlineReminders')) {
    function dispatchAutomaticDeadlineReminders(PDO $conn, ?int $actorId = null, bool $forceRun = false): array
    {
        $result = [
            'checked_tasks' => 0,
            'sent_notifications' => 0,
            'days_ahead' => getDeadlineReminderDays($conn),
            'skipped_reason' => '',
        ];

        if (!$forceRun && !isDeadlineReminderEnabled($conn)) {
            $result['skipped_reason'] = 'disabled';
            return $result;
        }

        $intervalMinutes = getDeadlineReminderIntervalMinutes($conn);
        $nowTs = time();
        $lastRunRaw = systemSetting($conn, 'deadline_reminder_last_run', '0');
        $lastRunTs = ctype_digit((string)$lastRunRaw) ? (int)$lastRunRaw : 0;

        if (!$forceRun && $lastRunTs > 0 && ($nowTs - $lastRunTs) < ($intervalMinutes * 60)) {
            $result['skipped_reason'] = 'throttled';
            return $result;
        }

        $daysAhead = getDeadlineReminderDays($conn);
        $result['days_ahead'] = $daysAhead;

        $stmtTasks = $conn->prepare("
            SELECT t.id, t.project_id, t.name, t.due_date, p.name AS project_name
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.due_date IS NOT NULL
              AND t.due_date <> '0000-00-00'
              AND t.status <> 'done'
              AND (t.teacher_status IS NULL OR t.teacher_status <> 'approved')
              AND t.due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
            ORDER BY t.due_date ASC
            LIMIT 500
        ");
        $stmtTasks->execute([$daysAhead]);
        $tasks = $stmtTasks->fetchAll(PDO::FETCH_ASSOC);
        $result['checked_tasks'] = count($tasks);

        $dispatchDate = date('Ymd');
        $today = new DateTimeImmutable('today');
        $participantCache = [];
        $insertDispatch = $conn->prepare("
            INSERT IGNORE INTO notification_dispatch_log (
                dispatch_key, event_type, user_id, project_id, task_id, created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");

        foreach ($tasks as $task) {
            $taskId = (int)($task['id'] ?? 0);
            $projectId = (int)($task['project_id'] ?? 0);
            $taskName = trim((string)($task['name'] ?? 'งานโครงงาน'));
            $projectName = trim((string)($task['project_name'] ?? 'โครงงาน'));
            $dueDateRaw = (string)($task['due_date'] ?? '');
            $dueDate = DateTimeImmutable::createFromFormat('Y-m-d', substr($dueDateRaw, 0, 10));
            if (!$dueDate || $taskId <= 0 || $projectId <= 0) {
                continue;
            }

            $daysDiff = (int)$today->diff($dueDate)->format('%r%a');
            if ($daysDiff > $daysAhead) {
                continue;
            }

            $eventType = ($daysDiff < 0) ? 'deadline.overdue' : 'deadline.upcoming';
            $title = ($daysDiff < 0) ? 'แจ้งเตือนงานเกินกำหนด' : 'แจ้งเตือนงานใกล้ครบกำหนด';
            $notifyType = ($daysDiff < 0) ? 'warning' : 'info';
            $dueDateText = $dueDate->format('d/m/Y');

            if ($daysDiff < 0) {
                $overdueDays = abs($daysDiff);
                $message = 'งาน "' . $taskName . '" ของโครงงาน "' . $projectName . '" เลยกำหนดแล้ว ' . $overdueDays . ' วัน (กำหนดส่ง ' . $dueDateText . ')';
            } elseif ($daysDiff === 0) {
                $message = 'งาน "' . $taskName . '" ของโครงงาน "' . $projectName . '" ครบกำหนดส่งวันนี้ (' . $dueDateText . ')';
            } else {
                $message = 'งาน "' . $taskName . '" ของโครงงาน "' . $projectName . '" จะครบกำหนดในอีก ' . $daysDiff . ' วัน (กำหนดส่ง ' . $dueDateText . ')';
            }

            if (!isset($participantCache[$projectId])) {
                $participantCache[$projectId] = getProjectParticipantUserIds($conn, $projectId);
            }
            $targets = $participantCache[$projectId];
            if (empty($targets)) {
                continue;
            }

            foreach ($targets as $uid) {
                $targetUserId = (int)$uid;
                if ($targetUserId <= 0) {
                    continue;
                }

                $dispatchKey = 'deadline|' . $eventType . '|' . $dispatchDate . '|task:' . $taskId . '|user:' . $targetUserId;
                $insertDispatch->execute([$dispatchKey, $eventType, $targetUserId, $projectId, $taskId]);

                if ($insertDispatch->rowCount() > 0) {
                    createNotification($conn, $targetUserId, $title, $message, $notifyType, $projectId);
                    $result['sent_notifications']++;
                }
            }
        }

        saveSystemSetting($conn, 'deadline_reminder_last_run', (string)$nowTs, $actorId && $actorId > 0 ? $actorId : null);

        try {
            $conn->exec("DELETE FROM notification_dispatch_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)");
        } catch (Throwable $e) {
            // Keep reminder flow running even if cleanup fails.
        }

        if (($actorId ?? 0) > 0 && $result['sent_notifications'] > 0) {
            writeAuditLog(
                $conn,
                (int)$actorId,
                'notification.deadline.auto_dispatch',
                'days=' . $daysAhead . ', checked=' . $result['checked_tasks'] . ', sent=' . $result['sent_notifications'] . ($forceRun ? ', forced=1' : ''),
                'system',
                null
            );
        }

        return $result;
    }
}

if (!function_exists('getProjectParticipantUserIds')) {
    function getProjectParticipantUserIds(PDO $conn, int $projectId): array
    {
        $userIds = [];

        $projectStmt = $conn->prepare('SELECT student_id, advisor_id, co_advisor_id FROM projects WHERE id = ? LIMIT 1');
        $projectStmt->execute([$projectId]);
        $projectRow = $projectStmt->fetch(PDO::FETCH_ASSOC);
        if ($projectRow) {
            $userIds[] = (int)$projectRow['student_id'];
            if (!empty($projectRow['advisor_id'])) {
                $userIds[] = (int)$projectRow['advisor_id'];
            }
            if (!empty($projectRow['co_advisor_id'])) {
                $userIds[] = (int)$projectRow['co_advisor_id'];
            }
        }

        $memberStmt = $conn->prepare("
            SELECT user_id
            FROM project_members
            WHERE project_id = ? AND status = 'accepted'
        ");
        $memberStmt->execute([$projectId]);
        foreach ($memberStmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            $userIds[] = (int)$uid;
        }

        return array_values(array_unique(array_filter($userIds, function ($value) {
            return (int)$value > 0;
        })));
    }
}

if (!function_exists('projectRootPath')) {
    function projectRootPath(): string
    {
        static $rootPath = null;
        if ($rootPath !== null) {
            return $rootPath;
        }

        $resolved = realpath(__DIR__ . '/../../../../');
        if ($resolved === false) {
            $resolved = __DIR__ . '/../../../../';
        }

        $rootPath = rtrim((string)$resolved, '/\\');
        return $rootPath;
    }
}

if (!function_exists('databaseBackupDirectory')) {
    function databaseBackupDirectory(): string
    {
        return projectRootPath()
            . DIRECTORY_SEPARATOR . 'backend'
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'backups';
    }
}

if (!function_exists('ensureDatabaseBackupDirectory')) {
    function ensureDatabaseBackupDirectory(): bool
    {
        $dir = databaseBackupDirectory();
        if (is_dir($dir)) {
            return true;
        }
        return mkdir($dir, 0775, true) || is_dir($dir);
    }
}

if (!function_exists('normalizeDatabaseBackupFileName')) {
    function normalizeDatabaseBackupFileName(string $rawName): ?string
    {
        $fileName = basename(trim($rawName));
        if ($fileName === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9._-]+\.sql$/', $fileName) !== 1) {
            return null;
        }
        return $fileName;
    }
}

if (!function_exists('listDatabaseBackupFiles')) {
    function listDatabaseBackupFiles(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $dir = databaseBackupDirectory();
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        $items = [];
        foreach ($files as $path) {
            if (!is_file($path)) {
                continue;
            }
            $name = basename($path);
            $items[] = [
                'name' => $name,
                'path' => $path,
                'size_bytes' => (int)filesize($path),
                'modified_at' => date('Y-m-d H:i:s', (int)filemtime($path)),
                'modified_ts' => (int)filemtime($path),
            ];
        }

        usort($items, static function (array $a, array $b): int {
            return ($b['modified_ts'] <=> $a['modified_ts']);
        });

        if (count($items) > $limit) {
            $items = array_slice($items, 0, $limit);
        }

        return $items;
    }
}

if (!function_exists('latestDatabaseBackupFile')) {
    function latestDatabaseBackupFile(): ?array
    {
        $items = listDatabaseBackupFiles(1);
        if (empty($items)) {
            return null;
        }
        return $items[0];
    }
}

if (!function_exists('createDatabaseBackup')) {
    function createDatabaseBackup(PDO $conn, ?int $actorId = null): array
    {
        $result = [
            'success' => false,
            'file_name' => '',
            'file_path' => '',
            'size_bytes' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'error' => '',
        ];

        if (!ensureDatabaseBackupDirectory()) {
            $result['error'] = 'cannot_create_backup_dir';
            return $result;
        }

        $backupDir = databaseBackupDirectory();
        $databaseName = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
        if ($databaseName === '') {
            $databaseName = 'database';
        }

        $safeDbName = preg_replace('/[^A-Za-z0-9_-]/', '_', $databaseName);
        $fileName = $safeDbName . '_backup_' . date('Ymd_His') . '.sql';
        $filePath = $backupDir . DIRECTORY_SEPARATOR . $fileName;

        $fp = @fopen($filePath, 'wb');
        if ($fp === false) {
            $result['error'] = 'cannot_open_backup_file';
            return $result;
        }

        $result['file_name'] = $fileName;
        $result['file_path'] = $filePath;

        try {
            fwrite($fp, "-- RMUTP Database Backup\n");
            fwrite($fp, "-- Generated at: " . date('Y-m-d H:i:s') . "\n");
            fwrite($fp, "-- Database: " . $databaseName . "\n\n");
            fwrite($fp, "SET NAMES utf8mb4;\n");
            fwrite($fp, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

            $tables = $conn->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($tables as $tableRaw) {
                $tableName = (string)$tableRaw;
                if ($tableName === '') {
                    continue;
                }

                $tableRef = '`' . str_replace('`', '``', $tableName) . '`';

                $createStmt = $conn->query('SHOW CREATE TABLE ' . $tableRef);
                $createRow = $createStmt ? $createStmt->fetch(PDO::FETCH_ASSOC) : [];
                $createSql = '';
                if (is_array($createRow)) {
                    foreach ($createRow as $key => $value) {
                        if (stripos((string)$key, 'create table') !== false) {
                            $createSql = (string)$value;
                            break;
                        }
                    }
                    if ($createSql === '') {
                        $createVals = array_values($createRow);
                        $createSql = (string)($createVals[1] ?? '');
                    }
                }

                if ($createSql === '') {
                    continue;
                }

                fwrite($fp, "--\n-- Structure for table " . $tableName . "\n--\n");
                fwrite($fp, "DROP TABLE IF EXISTS " . $tableRef . ";\n");
                fwrite($fp, $createSql . ";\n\n");

                $rowsStmt = $conn->query('SELECT * FROM ' . $tableRef);
                $batch = [];
                if ($rowsStmt) {
                    while (($row = $rowsStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                        $values = [];
                        foreach ($row as $value) {
                            if ($value === null) {
                                $values[] = 'NULL';
                            } else {
                                $values[] = $conn->quote((string)$value);
                            }
                        }
                        $batch[] = '(' . implode(', ', $values) . ')';

                        if (count($batch) >= 100) {
                            fwrite($fp, 'INSERT INTO ' . $tableRef . " VALUES\n" . implode(",\n", $batch) . ";\n");
                            $batch = [];
                        }
                    }
                }

                if (!empty($batch)) {
                    fwrite($fp, 'INSERT INTO ' . $tableRef . " VALUES\n" . implode(",\n", $batch) . ";\n");
                }

                fwrite($fp, "\n");
            }

            fwrite($fp, "SET FOREIGN_KEY_CHECKS = 1;\n");
            fclose($fp);
            $fp = null;

            $result['size_bytes'] = is_file($filePath) ? (int)filesize($filePath) : 0;
            $result['success'] = true;

            saveSystemSetting($conn, 'backup_last_generated_at', $result['created_at'], $actorId);
            saveSystemSetting($conn, 'backup_last_file_name', $fileName, $actorId);
            saveSystemSetting($conn, 'backup_last_size_bytes', (string)$result['size_bytes'], $actorId);
            saveSystemSetting($conn, 'backup_last_error', '', $actorId);

            if (($actorId ?? 0) > 0) {
                writeAuditLog(
                    $conn,
                    (int)$actorId,
                    'admin.backup.create',
                    'สร้างไฟล์สำรองฐานข้อมูล: ' . $fileName,
                    'system',
                    null
                );
            }
        } catch (Throwable $e) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            if (is_file($filePath)) {
                @unlink($filePath);
            }
            $result['error'] = 'backup_failed';
            saveSystemSetting($conn, 'backup_last_error', (string)$e->getMessage(), $actorId);

            if (($actorId ?? 0) > 0) {
                writeAuditLog(
                    $conn,
                    (int)$actorId,
                    'admin.backup.create_failed',
                    'ไม่สามารถสร้างไฟล์สำรองฐานข้อมูลได้',
                    'system',
                    null
                );
            }
        }

        return $result;
    }
}

if (!function_exists('maybeRunAutomaticDatabaseBackup')) {
    function maybeRunAutomaticDatabaseBackup(PDO $conn, ?int $actorId = null, bool $force = false): array
    {
        $enabled = systemSettingInt($conn, 'backup_auto_enabled', 1, 0, 1) === 1;
        $intervalHours = systemSettingInt($conn, 'backup_auto_interval_hours', 24, 1, 168);
        $lastBackupAt = systemSetting($conn, 'backup_last_generated_at', '');

        $result = [
            'status' => 'skipped',
            'enabled' => $enabled ? 1 : 0,
            'interval_hours' => $intervalHours,
            'last_backup_at' => $lastBackupAt,
            'backup' => null,
        ];

        if (!$enabled && !$force) {
            $result['status'] = 'disabled';
            return $result;
        }

        $lastBackupTs = strtotime($lastBackupAt);
        if (!$force && $lastBackupTs !== false && $lastBackupTs > 0) {
            if ((time() - $lastBackupTs) < ($intervalHours * 3600)) {
                $result['status'] = 'not_due';
                return $result;
            }
        }

        $backup = createDatabaseBackup($conn, $actorId);
        $result['backup'] = $backup;
        $result['status'] = !empty($backup['success']) ? 'created' : 'error';
        if (!empty($backup['success'])) {
            saveSystemSetting($conn, 'backup_last_auto_at', date('Y-m-d H:i:s'), $actorId);
        }
        return $result;
    }
}

if (!function_exists('ensureProjectApprovalTables')) {
    function ensureProjectApprovalTables(PDO $conn): void
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }

        $conn->exec("
            CREATE TABLE IF NOT EXISTS project_approval_requests (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id INT UNSIGNED NOT NULL,
                submitted_by INT UNSIGNED NOT NULL,
                current_step TINYINT UNSIGNED NOT NULL DEFAULT 1,
                total_steps TINYINT UNSIGNED NOT NULL DEFAULT 2,
                status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
                last_action_by INT UNSIGNED DEFAULT NULL,
                last_action_note VARCHAR(255) DEFAULT NULL,
                submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                decided_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_par_project_status (project_id, status),
                KEY idx_par_status_step (status, current_step),
                KEY idx_par_submitted_by (submitted_by),
                KEY idx_par_last_action_by (last_action_by),
                CONSTRAINT fk_par_project
                    FOREIGN KEY (project_id) REFERENCES projects(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_par_submitted_by
                    FOREIGN KEY (submitted_by) REFERENCES users(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_par_last_action_by
                    FOREIGN KEY (last_action_by) REFERENCES users(id)
                    ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $conn->exec("
            CREATE TABLE IF NOT EXISTS project_approval_actions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                request_id BIGINT UNSIGNED NOT NULL,
                step_no TINYINT UNSIGNED NOT NULL,
                action_key ENUM('submitted', 'resubmitted', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'submitted',
                action_by INT UNSIGNED NOT NULL,
                action_role ENUM('student', 'teacher', 'admin') NOT NULL DEFAULT 'student',
                note TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_paa_request_id (request_id),
                KEY idx_paa_action_by (action_by),
                KEY idx_paa_action_key (action_key),
                KEY idx_paa_created_at (created_at),
                CONSTRAINT fk_paa_request
                    FOREIGN KEY (request_id) REFERENCES project_approval_requests(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_paa_action_by
                    FOREIGN KEY (action_by) REFERENCES users(id)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $initialized = true;
    }
}

if (!function_exists('approvalStepLabel')) {
    function approvalStepLabel(int $stepNo): string
    {
        if ($stepNo === 1) {
            return 'อาจารย์ที่ปรึกษา';
        }
        if ($stepNo === 2) {
            return 'ผู้ดูแลระบบ';
        }
        return 'ขั้นตอนที่ ' . $stepNo;
    }
}

if (!function_exists('approvalStatusText')) {
    function approvalStatusText(string $status): string
    {
        if ($status === 'pending') {
            return 'รออนุมัติ';
        }
        if ($status === 'approved') {
            return 'อนุมัติแล้ว';
        }
        if ($status === 'rejected') {
            return 'ตีกลับ';
        }
        if ($status === 'cancelled') {
            return 'ยกเลิก';
        }
        return $status;
    }
}

if (!function_exists('isUserProjectAdvisor')) {
    function isUserProjectAdvisor(PDO $conn, int $projectId, int $teacherId): bool
    {
        $stmt = $conn->prepare("
            SELECT id
            FROM projects
            WHERE id = ? AND (advisor_id = ? OR co_advisor_id = ?)
            LIMIT 1
        ");
        $stmt->execute([$projectId, $teacherId, $teacherId]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('canUserApproveProjectRequest')) {
    function canUserApproveProjectRequest(PDO $conn, array $requestRow, int $actorId, string $actorRole): bool
    {
        if ((string)($requestRow['status'] ?? '') !== 'pending') {
            return false;
        }

        $stepNo = (int)($requestRow['current_step'] ?? 0);
        $projectId = (int)($requestRow['project_id'] ?? 0);
        if ($stepNo <= 0 || $projectId <= 0) {
            return false;
        }

        if ($stepNo === 1) {
            if ($actorRole !== 'teacher') {
                return false;
            }
            return isUserProjectAdvisor($conn, $projectId, $actorId);
        }

        if ($stepNo === 2) {
            return $actorRole === 'admin';
        }

        return false;
    }
}

if (!function_exists('createProjectApprovalAction')) {
    function createProjectApprovalAction(
        PDO $conn,
        int $requestId,
        int $stepNo,
        string $actionKey,
        int $actorId,
        string $actorRole,
        string $note = ''
    ): void {
        $allowedActions = ['submitted', 'resubmitted', 'approved', 'rejected', 'cancelled'];
        if (!in_array($actionKey, $allowedActions, true)) {
            $actionKey = 'submitted';
        }

        $allowedRoles = ['student', 'teacher', 'admin'];
        if (!in_array($actorRole, $allowedRoles, true)) {
            $actorRole = 'student';
        }

        $stmt = $conn->prepare("
            INSERT INTO project_approval_actions (
                request_id, step_no, action_key, action_by, action_role, note, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$requestId, $stepNo, $actionKey, $actorId, $actorRole, $note]);
    }
}

if (!function_exists('latestProjectApprovalRequest')) {
    function latestProjectApprovalRequest(PDO $conn, int $projectId): ?array
    {
        ensureProjectApprovalTables($conn);
        $stmt = $conn->prepare("
            SELECT *
            FROM project_approval_requests
            WHERE project_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('submitProjectApprovalRequest')) {
    function submitProjectApprovalRequest(PDO $conn, int $projectId, int $studentId, ?string &$errorCode = null): ?int
    {
        ensureProjectApprovalTables($conn);
        $errorCode = null;

        $stmtProject = $conn->prepare("
            SELECT id, name, status, progress, student_id, advisor_id, co_advisor_id
            FROM projects
            WHERE id = ?
            LIMIT 1
        ");
        $stmtProject->execute([$projectId]);
        $project = $stmtProject->fetch(PDO::FETCH_ASSOC);
        if (!$project) {
            $errorCode = 'project_not_found';
            return null;
        }
        if ((int)$project['student_id'] !== $studentId) {
            $errorCode = 'not_owner';
            return null;
        }
        if ((int)$project['progress'] < 100) {
            $errorCode = 'progress_not_ready';
            return null;
        }
        if (empty($project['advisor_id']) && empty($project['co_advisor_id'])) {
            $errorCode = 'advisor_missing';
            return null;
        }

        $pendingStmt = $conn->prepare("
            SELECT id
            FROM project_approval_requests
            WHERE project_id = ? AND status = 'pending'
            LIMIT 1
        ");
        $pendingStmt->execute([$projectId]);
        if ($pendingStmt->fetch(PDO::FETCH_ASSOC)) {
            $errorCode = 'pending_exists';
            return null;
        }

        $latest = latestProjectApprovalRequest($conn, $projectId);
        $actionKey = ($latest && (string)($latest['status'] ?? '') === 'rejected') ? 'resubmitted' : 'submitted';

        $conn->beginTransaction();
        try {
            $insert = $conn->prepare("
                INSERT INTO project_approval_requests (
                    project_id,
                    submitted_by,
                    current_step,
                    total_steps,
                    status,
                    submitted_at,
                    updated_at
                ) VALUES (?, ?, 1, 2, 'pending', NOW(), NOW())
            ");
            $insert->execute([$projectId, $studentId]);
            $requestId = (int)$conn->lastInsertId();

            createProjectApprovalAction(
                $conn,
                $requestId,
                1,
                $actionKey,
                $studentId,
                'student',
                'ส่งคำขออนุมัติโครงงาน'
            );

            $conn->prepare("UPDATE projects SET status = 'pending' WHERE id = ?")->execute([$projectId]);

            $teacherIds = [];
            if (!empty($project['advisor_id'])) {
                $teacherIds[] = (int)$project['advisor_id'];
            }
            if (!empty($project['co_advisor_id'])) {
                $teacherIds[] = (int)$project['co_advisor_id'];
            }
            createNotificationForUsers(
                $conn,
                array_values(array_unique($teacherIds)),
                'มีคำขออนุมัติโครงงาน',
                'โครงงาน "' . (string)$project['name'] . '" ส่งคำขออนุมัติแล้ว กรุณาตรวจสอบที่ศูนย์อนุมัติ',
                'info',
                $projectId
            );

            writeAuditLog(
                $conn,
                $studentId,
                'project.approval.submit',
                'ส่งคำขออนุมัติโครงงาน ID: ' . $projectId,
                'project',
                $projectId
            );

            $conn->commit();
            return $requestId;
        } catch (Throwable $e) {
            $conn->rollBack();
            $errorCode = 'submit_failed';
            return null;
        }
    }
}

if (!function_exists('processProjectApprovalDecision')) {
    function processProjectApprovalDecision(
        PDO $conn,
        int $requestId,
        int $actorId,
        string $actorRole,
        string $decision,
        string $note = '',
        ?string &$errorCode = null
    ): bool {
        ensureProjectApprovalTables($conn);
        $errorCode = null;
        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['approve', 'reject'], true)) {
            $errorCode = 'invalid_decision';
            return false;
        }

        $stmt = $conn->prepare("
            SELECT
                r.*,
                p.name AS project_name,
                p.student_id,
                p.progress
            FROM project_approval_requests r
            INNER JOIN projects p ON p.id = r.project_id
            WHERE r.id = ?
            LIMIT 1
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            $errorCode = 'request_not_found';
            return false;
        }
        if ((string)$request['status'] !== 'pending') {
            $errorCode = 'request_not_pending';
            return false;
        }
        if (!canUserApproveProjectRequest($conn, $request, $actorId, $actorRole)) {
            $errorCode = 'permission_denied';
            return false;
        }

        $projectId = (int)$request['project_id'];
        $studentId = (int)$request['student_id'];
        $currentStep = (int)$request['current_step'];
        $totalSteps = max(1, (int)$request['total_steps']);
        $note = trim($note);
        $lastNote = $note;
        if (strlen($lastNote) > 255) {
            $lastNote = substr($lastNote, 0, 255);
        }

        $conn->beginTransaction();
        try {
            if ($decision === 'approve') {
                createProjectApprovalAction(
                    $conn,
                    $requestId,
                    $currentStep,
                    'approved',
                    $actorId,
                    $actorRole,
                    $note
                );

                if ($currentStep < $totalSteps) {
                    $nextStep = $currentStep + 1;
                    $update = $conn->prepare("
                        UPDATE project_approval_requests
                        SET current_step = ?,
                            last_action_by = ?,
                            last_action_note = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $update->execute([$nextStep, $actorId, $lastNote, $requestId]);

                    if ($nextStep === 2) {
                        $adminIds = $conn->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                        createNotificationForUsers(
                            $conn,
                            array_map('intval', $adminIds),
                            'คำขออนุมัติโครงงานรอผู้ดูแลตรวจสอบ',
                            'โครงงาน "' . (string)$request['project_name'] . '" ผ่านขั้นอาจารย์ที่ปรึกษาแล้ว',
                            'info',
                            $projectId
                        );
                    }

                    createNotification(
                        $conn,
                        $studentId,
                        'คำขออนุมัติผ่านขั้นตอน ' . approvalStepLabel($currentStep),
                        'โครงงาน "' . (string)$request['project_name'] . '" ผ่านการอนุมัติในขั้นตอน ' . approvalStepLabel($currentStep),
                        'success',
                        $projectId
                    );
                } else {
                    $update = $conn->prepare("
                        UPDATE project_approval_requests
                        SET status = 'approved',
                            last_action_by = ?,
                            last_action_note = ?,
                            updated_at = NOW(),
                            decided_at = NOW()
                        WHERE id = ?
                    ");
                    $update->execute([$actorId, $lastNote, $requestId]);

                    $conn->prepare("
                        UPDATE projects
                        SET status = CASE
                            WHEN progress >= 100 THEN 'completed'
                            ELSE status
                        END
                        WHERE id = ?
                    ")->execute([$projectId]);

                    createNotification(
                        $conn,
                        $studentId,
                        'โครงงานอนุมัติเรียบร้อย',
                        'โครงงาน "' . (string)$request['project_name'] . '" ผ่านการอนุมัติครบทุกขั้นตอนแล้ว',
                        'success',
                        $projectId
                    );
                }

                writeAuditLog(
                    $conn,
                    $actorId,
                    'project.approval.approve',
                    'อนุมัติคำขอโครงงาน request_id=' . $requestId . ', step=' . $currentStep,
                    'project',
                    $projectId
                );
            } else {
                createProjectApprovalAction(
                    $conn,
                    $requestId,
                    $currentStep,
                    'rejected',
                    $actorId,
                    $actorRole,
                    $note
                );

                $update = $conn->prepare("
                    UPDATE project_approval_requests
                    SET status = 'rejected',
                        last_action_by = ?,
                        last_action_note = ?,
                        updated_at = NOW(),
                        decided_at = NOW()
                    WHERE id = ?
                ");
                $update->execute([$actorId, $lastNote, $requestId]);

                $conn->prepare("
                    UPDATE projects
                    SET status = CASE
                        WHEN status = 'cancelled' THEN status
                        ELSE 'in_progress'
                    END
                    WHERE id = ?
                ")->execute([$projectId]);

                $message = 'คำขอโครงงาน "' . (string)$request['project_name'] . '" ถูกตีกลับ';
                if ($note !== '') {
                    $message .= ' - หมายเหตุ: ' . $note;
                }
                createNotification(
                    $conn,
                    $studentId,
                    'คำขออนุมัติถูกตีกลับ',
                    $message,
                    'warning',
                    $projectId
                );

                writeAuditLog(
                    $conn,
                    $actorId,
                    'project.approval.reject',
                    'ตีกลับคำขอโครงงาน request_id=' . $requestId . ', step=' . $currentStep,
                    'project',
                    $projectId
                );
            }

            $conn->commit();
            return true;
        } catch (Throwable $e) {
            $conn->rollBack();
            $errorCode = 'decision_failed';
            return false;
        }
    }
}

if (!function_exists('appendQueryParam')) {
    function appendQueryParam(string $url, string $key, string $value): string
    {
        $joiner = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $joiner . rawurlencode($key) . '=' . rawurlencode($value);
    }
}

if (!function_exists('csrfToken')) {
    function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $existing = $_SESSION['_csrf_token'] ?? '';
        if (!is_string($existing) || strlen($existing) < 32) {
            $existing = bin2hex(random_bytes(32));
            $_SESSION['_csrf_token'] = $existing;
        }

        return $existing;
    }
}

if (!function_exists('csrfInputField')) {
    function csrfInputField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('isValidCsrfToken')) {
    function isValidCsrfToken(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $sessionToken = csrfToken();
        return hash_equals($sessionToken, $token);
    }
}

if (!function_exists('ensureValidCsrfOrRedirect')) {
    function ensureValidCsrfOrRedirect(string $redirectUrl, string $status = 'csrf_invalid'): void
    {
        $token = $_POST['csrf_token'] ?? null;
        if (!is_string($token) || !isValidCsrfToken($token)) {
            header('Location: ' . appendQueryParam($redirectUrl, 'status', $status));
            exit;
        }
    }
}

if (!function_exists('defaultUploadExtensionMap')) {
    function defaultUploadExtensionMap(): array
    {
        return [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'csv' => ['text/csv', 'text/plain', 'application/vnd.ms-excel'],
            'ppt' => ['application/vnd.ms-powerpoint'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
            'txt' => ['text/plain'],
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'zip' => ['application/zip', 'application/x-zip-compressed'],
            'rar' => ['application/vnd.rar', 'application/x-rar-compressed'],
            '7z' => ['application/x-7z-compressed'],
        ];
    }
}

if (!function_exists('sanitizeUploadBaseName')) {
    function sanitizeUploadBaseName(string $rawName): string
    {
        $name = basename($rawName);
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        $name = trim((string)$name, '._-');
        if ($name === '') {
            $name = 'file';
        }
        return $name;
    }
}

if (!function_exists('storeUploadedFileSecure')) {
    function storeUploadedFileSecure(
        array $file,
        string $targetDir,
        string $prefix = '',
        ?array $allowedExtensions = null,
        int $maxBytes = 10485760,
        ?string &$errorCode = null
    ): ?string {
        $errorCode = null;

        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errorCode = 'upload_error';
            return null;
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            $errorCode = 'invalid_tmp';
            return null;
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            $errorCode = 'file_too_large';
            return null;
        }

        $rawOriginal = (string)($file['name'] ?? 'file');
        $safeOriginal = sanitizeUploadBaseName($rawOriginal);
        $extension = strtolower(pathinfo($safeOriginal, PATHINFO_EXTENSION));
        if ($extension === '') {
            $errorCode = 'extension_missing';
            return null;
        }

        $extMap = defaultUploadExtensionMap();
        $allowedExtensions = $allowedExtensions ?? array_keys($extMap);
        $allowedExtensions = array_map('strtolower', $allowedExtensions);
        if (!in_array($extension, $allowedExtensions, true)) {
            $errorCode = 'extension_not_allowed';
            return null;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string)$finfo->file($tmpPath));
        $allowedMimesForExt = $extMap[$extension] ?? [];
        if (!empty($allowedMimesForExt)) {
            $allowedMimesNormalized = array_map('strtolower', $allowedMimesForExt);
            if (!in_array($mime, $allowedMimesNormalized, true)) {
                $errorCode = 'mime_not_allowed';
                return null;
            }
        }

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            $errorCode = 'cannot_create_target_dir';
            return null;
        }

        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '_', $prefix);
        $prefix = trim((string)$prefix, '_');
        if ($prefix === '') {
            $prefix = 'upload';
        }

        $fileToken = bin2hex(random_bytes(6));
        $finalName = $prefix . '_' . time() . '_' . $fileToken . '_' . $safeOriginal;
        $targetPath = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $finalName;

        if (!move_uploaded_file($tmpPath, $targetPath)) {
            $errorCode = 'move_failed';
            return null;
        }

        return $finalName;
    }
}

if (!function_exists('fetchApprovalRealtimeCounts')) {
    function fetchApprovalRealtimeCounts(PDO $conn, int $userId, string $role): array
    {
        $result = [
            'queue' => 0,
            'history' => 0,
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
        ];

        if (!in_array($role, ['student', 'teacher', 'admin'], true)) {
            return $result;
        }

        try {
            ensureProjectApprovalTables($conn);

            if ($role === 'student') {
                $statsStmt = $conn->prepare("
                    SELECT
                        COUNT(*) AS history_count,
                        SUM(CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                        SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                        SUM(CASE WHEN r.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count
                    FROM project_approval_requests r
                    INNER JOIN projects p ON p.id = r.project_id
                    WHERE p.student_id = ?
                ");
                $statsStmt->execute([$userId]);
                $row = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $result['history'] = (int)($row['history_count'] ?? 0);
                $result['pending'] = (int)($row['pending_count'] ?? 0);
                $result['approved'] = (int)($row['approved_count'] ?? 0);
                $result['rejected'] = (int)($row['rejected_count'] ?? 0);
                $result['queue'] = 0;

                return $result;
            }

            if ($role === 'teacher') {
                $queueStmt = $conn->prepare("
                    SELECT COUNT(*)
                    FROM project_approval_requests r
                    INNER JOIN projects p ON p.id = r.project_id
                    WHERE r.status = 'pending'
                      AND r.current_step = 1
                      AND (p.advisor_id = ? OR p.co_advisor_id = ?)
                ");
                $queueStmt->execute([$userId, $userId]);
                $result['queue'] = (int)$queueStmt->fetchColumn();

                $statsStmt = $conn->prepare("
                    SELECT
                        COUNT(*) AS history_count,
                        SUM(CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                        SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                        SUM(CASE WHEN r.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count
                    FROM project_approval_requests r
                    INNER JOIN projects p ON p.id = r.project_id
                    WHERE p.advisor_id = ? OR p.co_advisor_id = ?
                ");
                $statsStmt->execute([$userId, $userId]);
                $row = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $result['history'] = (int)($row['history_count'] ?? 0);
                $result['pending'] = (int)($row['pending_count'] ?? 0);
                $result['approved'] = (int)($row['approved_count'] ?? 0);
                $result['rejected'] = (int)($row['rejected_count'] ?? 0);

                return $result;
            }

            $queueStmt = $conn->query("
                SELECT COUNT(*)
                FROM project_approval_requests r
                WHERE r.status = 'pending'
                  AND r.current_step = 2
            ");
            $result['queue'] = (int)$queueStmt->fetchColumn();

            $statsStmt = $conn->query("
                SELECT
                    COUNT(*) AS history_count,
                    SUM(CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                    SUM(CASE WHEN r.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count
                FROM project_approval_requests r
            ");
            $row = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $result['history'] = (int)($row['history_count'] ?? 0);
            $result['pending'] = (int)($row['pending_count'] ?? 0);
            $result['approved'] = (int)($row['approved_count'] ?? 0);
            $result['rejected'] = (int)($row['rejected_count'] ?? 0);
        } catch (Throwable $e) {
            // Fallback to zero counters when approval tables are unavailable.
        }

        return $result;
    }
}

if (!function_exists('buildRealtimeStatus')) {
    function buildRealtimeStatus(PDO $conn, int $userId, string $role, string $userFullname = ''): array
    {
        $safeRole = in_array($role, ['student', 'teacher', 'admin'], true) ? $role : 'guest';
        $result = [
            'role' => $safeRole,
            'server_time' => date('c'),
            'counters' => [
                'pending_tasks' => 0,
                'pending_invites' => 0,
                'pending_advisor_requests' => 0,
                'pending_main_advisor_requests' => 0,
                'pending_co_advisor_requests' => 0,
                'my_projects' => 0,
                'users' => 0,
                'students' => 0,
                'teachers' => 0,
                'projects' => 0,
                'completed_projects' => 0,
                'ongoing_projects' => 0,
                'pending_reviews' => 0,
                'overdue_tasks' => 0,
            ],
            'approval' => [
                'queue' => 0,
                'history' => 0,
                'pending' => 0,
                'approved' => 0,
                'rejected' => 0,
            ],
        ];

        if ($safeRole === 'student') {
            try {
                $inviteStmt = $conn->prepare("
                    SELECT COUNT(*)
                    FROM project_members
                    WHERE user_id = ?
                      AND status = 'pending'
                ");
                $inviteStmt->execute([$userId]);
                $result['counters']['pending_invites'] = (int)$inviteStmt->fetchColumn();
            } catch (Throwable $e) {
                $result['counters']['pending_invites'] = 0;
            }

            try {
                $effectiveFullname = trim($userFullname);
                if ($effectiveFullname === '') {
                    $nameStmt = $conn->prepare("SELECT fullname FROM users WHERE id = ? LIMIT 1");
                    $nameStmt->execute([$userId]);
                    $effectiveFullname = (string)($nameStmt->fetchColumn() ?: '');
                }

                if ($effectiveFullname !== '') {
                    $taskStmt = $conn->prepare("
                        SELECT COUNT(*)
                        FROM tasks t
                        WHERE t.assignee_name = ?
                          AND t.status <> 'done'
                    ");
                    $taskStmt->execute([$effectiveFullname]);
                    $result['counters']['pending_tasks'] = (int)$taskStmt->fetchColumn();
                }
            } catch (Throwable $e) {
                $result['counters']['pending_tasks'] = 0;
            }

            try {
                $projectStmt = $conn->prepare("
                    SELECT COUNT(*) FROM (
                        SELECT p.id
                        FROM projects p
                        WHERE p.student_id = ?
                        UNION
                        SELECT p2.id
                        FROM projects p2
                        INNER JOIN project_members pm ON p2.id = pm.project_id
                        WHERE pm.user_id = ?
                          AND pm.status = 'accepted'
                    ) all_projects
                ");
                $projectStmt->execute([$userId, $userId]);
                $result['counters']['my_projects'] = (int)$projectStmt->fetchColumn();
            } catch (Throwable $e) {
                $result['counters']['my_projects'] = 0;
            }
        } elseif ($safeRole === 'teacher') {
            try {
                $pendingMainStmt = $conn->prepare("SELECT COUNT(*) FROM projects WHERE pending_advisor_id = ?");
                $pendingMainStmt->execute([$userId]);
                $pendingMain = (int)$pendingMainStmt->fetchColumn();

                $pendingCoStmt = $conn->prepare("SELECT COUNT(*) FROM projects WHERE pending_co_advisor_id = ?");
                $pendingCoStmt->execute([$userId]);
                $pendingCo = (int)$pendingCoStmt->fetchColumn();

                $result['counters']['pending_main_advisor_requests'] = $pendingMain;
                $result['counters']['pending_co_advisor_requests'] = $pendingCo;
                $result['counters']['pending_advisor_requests'] = $pendingMain + $pendingCo;
            } catch (Throwable $e) {
                $result['counters']['pending_main_advisor_requests'] = 0;
                $result['counters']['pending_co_advisor_requests'] = 0;
                $result['counters']['pending_advisor_requests'] = 0;
            }

            try {
                $pendingTaskStmt = $conn->prepare("
                    SELECT COUNT(*)
                    FROM tasks t
                    INNER JOIN projects p ON t.project_id = p.id
                    WHERE (p.advisor_id = ? OR p.co_advisor_id = ?)
                      AND t.status = 'done'
                      AND t.teacher_status = 'pending'
                ");
                $pendingTaskStmt->execute([$userId, $userId]);
                $result['counters']['pending_tasks'] = (int)$pendingTaskStmt->fetchColumn();
            } catch (Throwable $e) {
                $result['counters']['pending_tasks'] = 0;
            }

            try {
                $myProjectStmt = $conn->prepare("
                    SELECT COUNT(*)
                    FROM projects
                    WHERE advisor_id = ? OR co_advisor_id = ?
                ");
                $myProjectStmt->execute([$userId, $userId]);
                $result['counters']['my_projects'] = (int)$myProjectStmt->fetchColumn();
            } catch (Throwable $e) {
                $result['counters']['my_projects'] = 0;
            }
        } elseif ($safeRole === 'admin') {
            try {
                $result['counters']['users'] = (int)$conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
                $result['counters']['students'] = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
                $result['counters']['teachers'] = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='teacher'")->fetchColumn();
                $result['counters']['projects'] = (int)$conn->query("SELECT COUNT(*) FROM projects")->fetchColumn();
                $result['counters']['completed_projects'] = (int)$conn->query("SELECT COUNT(*) FROM projects WHERE progress = 100")->fetchColumn();
                $result['counters']['ongoing_projects'] = max(0, $result['counters']['projects'] - $result['counters']['completed_projects']);
                $result['counters']['pending_reviews'] = (int)$conn->query("SELECT COUNT(*) FROM tasks WHERE status = 'done' AND teacher_status = 'pending'")->fetchColumn();
                $result['counters']['overdue_tasks'] = (int)$conn->query("
                    SELECT COUNT(*)
                    FROM tasks
                    WHERE due_date IS NOT NULL
                      AND due_date <> '0000-00-00'
                      AND due_date < CURDATE()
                      AND status <> 'done'
                ")->fetchColumn();
            } catch (Throwable $e) {
                // Keep default zero values on query failure.
            }
        }

        $result['approval'] = fetchApprovalRealtimeCounts($conn, $userId, $safeRole);

        return $result;
    }
}
