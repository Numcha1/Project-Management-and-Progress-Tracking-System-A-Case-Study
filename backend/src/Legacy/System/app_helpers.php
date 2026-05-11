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
        ?int $targetId = null
    ): void {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $stmt = $conn->prepare("
                INSERT INTO audit_logs (
                    actor_id, action_key, action_detail, target_type, target_id, ip_address, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$actorId, $actionKey, $actionDetail, $targetType, $targetId, $ip]);
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
