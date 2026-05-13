<?php

declare(strict_types=1);

if (!function_exists('ensureSystemOpsTables')) {
    function ensureSystemOpsTables(PDO $conn): void
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }

        $conn->exec("
            CREATE TABLE IF NOT EXISTS system_job_queue (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                job_type VARCHAR(80) NOT NULL,
                payload_json LONGTEXT DEFAULT NULL,
                priority SMALLINT NOT NULL DEFAULT 0,
                status ENUM('pending', 'running', 'done', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
                available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                locked_at DATETIME DEFAULT NULL,
                locked_by VARCHAR(120) DEFAULT NULL,
                attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
                dedupe_key VARCHAR(191) DEFAULT NULL,
                last_error TEXT DEFAULT NULL,
                created_by INT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                finished_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_system_job_queue_dedupe (dedupe_key),
                KEY idx_system_job_queue_status_available (status, available_at, priority, id),
                KEY idx_system_job_queue_locked (locked_by, locked_at),
                KEY idx_system_job_queue_type_status (job_type, status),
                CONSTRAINT fk_system_job_queue_created_by
                    FOREIGN KEY (created_by) REFERENCES users(id)
                    ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $conn->exec("
            CREATE TABLE IF NOT EXISTS system_job_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                job_id BIGINT UNSIGNED NOT NULL,
                event_type ENUM('queued', 'started', 'done', 'failed', 'cancelled', 'requeued') NOT NULL DEFAULT 'queued',
                message VARCHAR(255) DEFAULT NULL,
                context_json LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_system_job_logs_job_id (job_id),
                KEY idx_system_job_logs_event_type (event_type),
                KEY idx_system_job_logs_created_at (created_at),
                CONSTRAINT fk_system_job_logs_job
                    FOREIGN KEY (job_id) REFERENCES system_job_queue(id)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $conn->exec("
            CREATE TABLE IF NOT EXISTS system_worker_heartbeats (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                worker_id VARCHAR(120) NOT NULL,
                hostname VARCHAR(120) DEFAULT NULL,
                tenant_code VARCHAR(40) DEFAULT NULL,
                last_status VARCHAR(40) NOT NULL DEFAULT 'idle',
                processed_count INT UNSIGNED NOT NULL DEFAULT 0,
                failed_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_system_worker_heartbeats_worker_id (worker_id),
                KEY idx_system_worker_heartbeats_last_seen_at (last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $initialized = true;
    }
}

if (!function_exists('opsNormalizeJobType')) {
    function opsNormalizeJobType(string $jobType): string
    {
        $jobType = strtolower(trim($jobType));
        $allowed = [
            'deadline_reminder',
            'auto_backup',
            'retention_cleanup',
            'recalculate_progress',
        ];
        if (!in_array($jobType, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported job type: ' . $jobType);
        }
        return $jobType;
    }
}

if (!function_exists('opsEncodeContextJson')) {
    function opsEncodeContextJson(array $data): string
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded !== false ? $encoded : '{}';
    }
}

if (!function_exists('opsLogJobEvent')) {
    function opsLogJobEvent(PDO $conn, int $jobId, string $eventType, ?string $message = null, array $context = []): void
    {
        $stmt = $conn->prepare("
            INSERT INTO system_job_logs (job_id, event_type, message, context_json, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $jobId,
            $eventType,
            $message,
            opsEncodeContextJson($context),
        ]);
    }
}

if (!function_exists('opsEnqueueJob')) {
    function opsEnqueueJob(
        PDO $conn,
        string $jobType,
        array $payload = [],
        ?int $createdBy = null,
        int $priority = 0,
        ?string $availableAt = null,
        int $maxAttempts = 3,
        ?string $dedupeKey = null
    ): int {
        ensureSystemOpsTables($conn);
        $jobType = opsNormalizeJobType($jobType);
        $priority = max(-100, min(100, $priority));
        $maxAttempts = max(1, min(20, $maxAttempts));
        $availableAt = $availableAt ?: date('Y-m-d H:i:s');

        $stmt = $conn->prepare("
            INSERT INTO system_job_queue (
                job_type, payload_json, priority, status, available_at, max_attempts, dedupe_key, created_by, created_at, updated_at
            ) VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                status = IF(status IN ('done', 'failed', 'cancelled'), 'pending', status),
                available_at = VALUES(available_at),
                priority = VALUES(priority),
                payload_json = VALUES(payload_json),
                max_attempts = VALUES(max_attempts),
                updated_at = NOW()
        ");
        $stmt->execute([
            $jobType,
            opsEncodeContextJson($payload),
            $priority,
            $availableAt,
            $maxAttempts,
            $dedupeKey,
            $createdBy,
        ]);

        $jobId = (int)$conn->lastInsertId();
        if ($jobId <= 0 && $dedupeKey !== null && $dedupeKey !== '') {
            $findStmt = $conn->prepare("SELECT id FROM system_job_queue WHERE dedupe_key = ? LIMIT 1");
            $findStmt->execute([$dedupeKey]);
            $jobId = (int)$findStmt->fetchColumn();
        }

        if ($jobId > 0) {
            opsLogJobEvent($conn, $jobId, 'queued', 'Job queued', [
                'job_type' => $jobType,
                'priority' => $priority,
                'available_at' => $availableAt,
                'dedupe_key' => $dedupeKey,
            ]);
        }

        return $jobId;
    }
}

if (!function_exists('opsClaimJobs')) {
    function opsClaimJobs(PDO $conn, string $workerId, int $limit = 5, ?string $jobType = null): array
    {
        ensureSystemOpsTables($conn);
        $limit = max(1, min(200, $limit));
        $workerId = trim($workerId);
        if ($workerId === '') {
            $workerId = 'worker-' . getmypid();
        }

        if ($jobType !== null && $jobType !== '') {
            $jobType = opsNormalizeJobType($jobType);
        } else {
            $jobType = null;
        }

        if ($jobType === null) {
            $candidateStmt = $conn->prepare("
                SELECT id
                FROM system_job_queue
                WHERE status = 'pending'
                  AND available_at <= NOW()
                ORDER BY priority DESC, id ASC
                LIMIT {$limit}
            ");
            $candidateStmt->execute();
        } else {
            $candidateStmt = $conn->prepare("
                SELECT id
                FROM system_job_queue
                WHERE status = 'pending'
                  AND available_at <= NOW()
                  AND job_type = ?
                ORDER BY priority DESC, id ASC
                LIMIT {$limit}
            ");
            $candidateStmt->execute([$jobType]);
        }

        $ids = array_map('intval', $candidateStmt->fetchAll(PDO::FETCH_COLUMN));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $updateSql = "
            UPDATE system_job_queue
            SET status = 'running',
                locked_at = NOW(),
                locked_by = ?,
                attempt_count = attempt_count + 1,
                updated_at = NOW()
            WHERE status = 'pending'
              AND id IN ({$placeholders})
        ";
        $updateParams = array_merge([$workerId], $ids);
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute($updateParams);

        $fetchSql = "
            SELECT *
            FROM system_job_queue
            WHERE status = 'running'
              AND locked_by = ?
              AND id IN ({$placeholders})
            ORDER BY priority DESC, id ASC
        ";
        $fetchParams = array_merge([$workerId], $ids);
        $fetchStmt = $conn->prepare($fetchSql);
        $fetchStmt->execute($fetchParams);
        $jobs = $fetchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($jobs as $job) {
            $jid = (int)($job['id'] ?? 0);
            if ($jid > 0) {
                opsLogJobEvent($conn, $jid, 'started', 'Job claimed by worker', [
                    'worker_id' => $workerId,
                    'attempt_count' => (int)($job['attempt_count'] ?? 0),
                ]);
            }
        }

        return $jobs;
    }
}

if (!function_exists('opsHeartbeat')) {
    function opsHeartbeat(PDO $conn, string $workerId, string $status, int $processed = 0, int $failed = 0): void
    {
        ensureSystemOpsTables($conn);
        $workerId = trim($workerId);
        if ($workerId === '') {
            return;
        }

        $tenant = function_exists('tenantRuntimeContext') ? tenantRuntimeContext() : [];
        $tenantCode = trim((string)($tenant['tenant_code'] ?? ''));
        $hostname = gethostname() ?: php_uname('n');

        $stmt = $conn->prepare("
            INSERT INTO system_worker_heartbeats (
                worker_id, hostname, tenant_code, last_status, processed_count, failed_count, last_seen_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                hostname = VALUES(hostname),
                tenant_code = VALUES(tenant_code),
                last_status = VALUES(last_status),
                processed_count = processed_count + VALUES(processed_count),
                failed_count = failed_count + VALUES(failed_count),
                last_seen_at = NOW(),
                updated_at = NOW()
        ");
        $stmt->execute([
            $workerId,
            $hostname,
            $tenantCode !== '' ? $tenantCode : null,
            $status,
            max(0, $processed),
            max(0, $failed),
        ]);
    }
}

if (!function_exists('opsRecalculateProjectProgressJob')) {
    function opsRecalculateProjectProgressJob(PDO $conn, array $payload = []): array
    {
        $projectId = isset($payload['project_id']) ? (int)$payload['project_id'] : 0;

        if ($projectId > 0) {
            $progress = recalculateProjectProgress($conn, $projectId);
            return [
                'processed_projects' => 1,
                'project_id' => $projectId,
                'progress' => $progress,
            ];
        }

        $ids = $conn->query("SELECT id FROM projects")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $processed = 0;
        foreach ($ids as $idRaw) {
            $id = (int)$idRaw;
            if ($id <= 0) {
                continue;
            }
            recalculateProjectProgress($conn, $id);
            $processed++;
        }

        return [
            'processed_projects' => $processed,
        ];
    }
}

if (!function_exists('opsRunRetentionCleanup')) {
    function opsRunRetentionCleanup(PDO $conn, ?int $actorId = null): array
    {
        $auditDays = systemSettingInt($conn, 'worker_retention_audit_days', 365, 30, 3650);
        $dispatchDays = systemSettingInt($conn, 'worker_retention_notification_log_days', 60, 7, 3650);
        $cleanupLogDays = systemSettingInt($conn, 'worker_retention_cleanup_log_days', 90, 7, 3650);

        $stats = [
            'audit_logs_deleted' => 0,
            'dispatch_logs_deleted' => 0,
            'cleanup_logs_deleted' => 0,
            'retention_days' => [
                'audit_logs' => $auditDays,
                'notification_dispatch_log' => $dispatchDays,
                'retention_cleanup_logs' => $cleanupLogDays,
            ],
        ];

        $stmtAudit = $conn->prepare("DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmtAudit->execute([$auditDays]);
        $stats['audit_logs_deleted'] = (int)$stmtAudit->rowCount();

        $stmtDispatch = $conn->prepare("DELETE FROM notification_dispatch_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmtDispatch->execute([$dispatchDays]);
        $stats['dispatch_logs_deleted'] = (int)$stmtDispatch->rowCount();

        $stmtCleanup = $conn->prepare("DELETE FROM retention_cleanup_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmtCleanup->execute([$cleanupLogDays]);
        $stats['cleanup_logs_deleted'] = (int)$stmtCleanup->rowCount();

        $insertCleanup = $conn->prepare("
            INSERT INTO retention_cleanup_logs (target_key, retained_days, affected_rows, status, note, executed_by, created_at)
            VALUES (?, ?, ?, 'success', ?, ?, NOW())
        ");
        $insertCleanup->execute([
            'audit_logs',
            $auditDays,
            $stats['audit_logs_deleted'],
            'worker retention cleanup',
            $actorId,
        ]);
        $insertCleanup->execute([
            'notification_dispatch_log',
            $dispatchDays,
            $stats['dispatch_logs_deleted'],
            'worker retention cleanup',
            $actorId,
        ]);

        return $stats;
    }
}

if (!function_exists('opsExecuteJob')) {
    function opsExecuteJob(PDO $conn, array $job, ?int $actorId = null): array
    {
        $jobType = opsNormalizeJobType((string)($job['job_type'] ?? ''));
        $payloadRaw = (string)($job['payload_json'] ?? '');
        $payload = [];
        if ($payloadRaw !== '') {
            $decoded = json_decode($payloadRaw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        if ($jobType === 'deadline_reminder') {
            return dispatchAutomaticDeadlineReminders($conn, $actorId, true);
        }
        if ($jobType === 'auto_backup') {
            return maybeRunAutomaticDatabaseBackup($conn, $actorId, true);
        }
        if ($jobType === 'retention_cleanup') {
            return opsRunRetentionCleanup($conn, $actorId);
        }
        if ($jobType === 'recalculate_progress') {
            return opsRecalculateProjectProgressJob($conn, $payload);
        }

        throw new RuntimeException('Unhandled job type: ' . $jobType);
    }
}

if (!function_exists('opsFinalizeJob')) {
    function opsFinalizeJob(PDO $conn, array $job, bool $ok, array $result = [], ?string $errorMessage = null): void
    {
        $jobId = (int)($job['id'] ?? 0);
        if ($jobId <= 0) {
            return;
        }

        $attemptCount = (int)($job['attempt_count'] ?? 0);
        $maxAttempts = (int)($job['max_attempts'] ?? 1);
        $shouldRetry = (!$ok && $attemptCount < $maxAttempts);

        if ($ok) {
            $status = 'done';
        } elseif ($shouldRetry) {
            $status = 'pending';
        } else {
            $status = 'failed';
        }

        $availableAt = $shouldRetry
            ? date('Y-m-d H:i:s', time() + min(600, max(20, $attemptCount * 30)))
            : null;

        $stmt = $conn->prepare("
            UPDATE system_job_queue
            SET status = ?,
                locked_at = NULL,
                locked_by = NULL,
                available_at = COALESCE(?, available_at),
                last_error = ?,
                finished_at = CASE WHEN ? IN ('done', 'failed', 'cancelled') THEN NOW() ELSE NULL END,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $status,
            $availableAt,
            $ok ? null : substr((string)$errorMessage, 0, 4000),
            $status,
            $jobId,
        ]);

        if ($ok) {
            opsLogJobEvent($conn, $jobId, 'done', 'Job completed', $result);
            return;
        }

        if ($shouldRetry) {
            opsLogJobEvent($conn, $jobId, 'requeued', 'Job failed and requeued', [
                'error' => $errorMessage,
                'next_available_at' => $availableAt,
                'attempt_count' => $attemptCount,
                'max_attempts' => $maxAttempts,
            ]);
            return;
        }

        opsLogJobEvent($conn, $jobId, 'failed', 'Job failed permanently', [
            'error' => $errorMessage,
            'attempt_count' => $attemptCount,
            'max_attempts' => $maxAttempts,
        ]);
    }
}

if (!function_exists('opsScheduleRecurringJobs')) {
    function opsScheduleRecurringJobs(PDO $conn, ?int $actorId = null): array
    {
        ensureSystemOpsTables($conn);

        $now = time();
        $scheduled = [];

        $deadlineInterval = systemSettingInt($conn, 'worker_deadline_interval_minutes', 10, 1, 120);
        $backupInterval = systemSettingInt($conn, 'worker_backup_interval_minutes', 60, 5, 720);
        $retentionInterval = systemSettingInt($conn, 'worker_retention_interval_minutes', 1440, 60, 10080);

        $scheduleMap = [
            'deadline_reminder' => $deadlineInterval,
            'auto_backup' => $backupInterval,
            'retention_cleanup' => $retentionInterval,
        ];

        foreach ($scheduleMap as $jobType => $intervalMinutes) {
            $lastRun = systemSettingInt($conn, 'worker_last_' . $jobType . '_enqueue_ts', 0, 0, 2147483647);
            if ($lastRun > 0 && ($now - $lastRun) < ($intervalMinutes * 60)) {
                continue;
            }

            $dedupeKey = $jobType . '|' . date('YmdHi', $now);
            $jobId = opsEnqueueJob(
                $conn,
                $jobType,
                ['trigger' => 'recurring'],
                $actorId,
                10,
                date('Y-m-d H:i:s'),
                3,
                $dedupeKey
            );

            if ($jobId > 0) {
                saveSystemSetting($conn, 'worker_last_' . $jobType . '_enqueue_ts', (string)$now, $actorId);
                $scheduled[] = [
                    'job_type' => $jobType,
                    'job_id' => $jobId,
                ];
            }
        }

        return [
            'scheduled' => $scheduled,
            'count' => count($scheduled),
        ];
    }
}

if (!function_exists('opsRunWorker')) {
    function opsRunWorker(PDO $conn, array $options = []): array
    {
        ensureSystemOpsTables($conn);

        $workerId = trim((string)($options['worker_id'] ?? ''));
        if ($workerId === '') {
            $workerId = 'worker-' . getmypid();
        }

        $jobType = trim((string)($options['job_type'] ?? ''));
        if ($jobType !== '') {
            $jobType = opsNormalizeJobType($jobType);
        } else {
            $jobType = '';
        }

        $limit = max(1, min(500, (int)($options['limit'] ?? 10)));
        $actorId = isset($options['actor_id']) ? (int)$options['actor_id'] : null;
        $scheduleRecurring = !empty($options['schedule_recurring']);

        if ($scheduleRecurring) {
            opsScheduleRecurringJobs($conn, $actorId);
        }

        $jobs = opsClaimJobs($conn, $workerId, $limit, $jobType !== '' ? $jobType : null);
        $processed = 0;
        $failed = 0;
        $done = 0;
        $errors = [];

        opsHeartbeat($conn, $workerId, empty($jobs) ? 'idle' : 'running', 0, 0);

        foreach ($jobs as $job) {
            $processed++;
            try {
                $result = opsExecuteJob($conn, $job, $actorId);
                opsFinalizeJob($conn, $job, true, is_array($result) ? $result : ['result' => $result], null);
                $done++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = [
                    'job_id' => (int)($job['id'] ?? 0),
                    'message' => $e->getMessage(),
                ];
                opsFinalizeJob($conn, $job, false, [], $e->getMessage());
            }
        }

        opsHeartbeat($conn, $workerId, 'idle', $processed, $failed);

        return [
            'worker_id' => $workerId,
            'processed' => $processed,
            'done' => $done,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }
}

if (!function_exists('opsQueueStats')) {
    function opsQueueStats(PDO $conn): array
    {
        ensureSystemOpsTables($conn);

        $stats = [
            'pending' => 0,
            'running' => 0,
            'done_24h' => 0,
            'failed' => 0,
            'workers_active' => 0,
        ];

        $statusStmt = $conn->query("
            SELECT status, COUNT(*) AS cnt
            FROM system_job_queue
            GROUP BY status
        ");
        foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = (string)($row['status'] ?? '');
            $cnt = (int)($row['cnt'] ?? 0);
            if (array_key_exists($status, $stats)) {
                $stats[$status] = $cnt;
            }
        }

        $doneStmt = $conn->query("
            SELECT COUNT(*)
            FROM system_job_queue
            WHERE status = 'done'
              AND finished_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stats['done_24h'] = (int)$doneStmt->fetchColumn();

        $activeStmt = $conn->query("
            SELECT COUNT(*)
            FROM system_worker_heartbeats
            WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $stats['workers_active'] = (int)$activeStmt->fetchColumn();

        return $stats;
    }
}

if (!function_exists('opsRecentJobs')) {
    function opsRecentJobs(PDO $conn, int $limit = 50): array
    {
        ensureSystemOpsTables($conn);
        $limit = max(1, min(300, $limit));
        $stmt = $conn->query("
            SELECT id, job_type, status, priority, attempt_count, max_attempts, available_at, locked_at, locked_by, last_error, created_at, finished_at
            FROM system_job_queue
            ORDER BY id DESC
            LIMIT {$limit}
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('opsRecentWorkers')) {
    function opsRecentWorkers(PDO $conn, int $limit = 30): array
    {
        ensureSystemOpsTables($conn);
        $limit = max(1, min(200, $limit));
        $stmt = $conn->query("
            SELECT worker_id, hostname, tenant_code, last_status, processed_count, failed_count, last_seen_at
            FROM system_worker_heartbeats
            ORDER BY last_seen_at DESC
            LIMIT {$limit}
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('opsRetryFailedJob')) {
    function opsRetryFailedJob(PDO $conn, int $jobId): bool
    {
        ensureSystemOpsTables($conn);
        if ($jobId <= 0) {
            return false;
        }

        $stmt = $conn->prepare("
            UPDATE system_job_queue
            SET status = 'pending',
                available_at = NOW(),
                locked_at = NULL,
                locked_by = NULL,
                last_error = NULL,
                finished_at = NULL,
                updated_at = NOW()
            WHERE id = ?
              AND status = 'failed'
        ");
        $stmt->execute([$jobId]);
        $ok = $stmt->rowCount() > 0;
        if ($ok) {
            opsLogJobEvent($conn, $jobId, 'requeued', 'Manual retry from ops center', []);
        }
        return $ok;
    }
}

if (!function_exists('opsCancelPendingJob')) {
    function opsCancelPendingJob(PDO $conn, int $jobId): bool
    {
        ensureSystemOpsTables($conn);
        if ($jobId <= 0) {
            return false;
        }

        $stmt = $conn->prepare("
            UPDATE system_job_queue
            SET status = 'cancelled',
                finished_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
              AND status IN ('pending', 'running')
        ");
        $stmt->execute([$jobId]);
        $ok = $stmt->rowCount() > 0;
        if ($ok) {
            opsLogJobEvent($conn, $jobId, 'cancelled', 'Manual cancel from ops center', []);
        }
        return $ok;
    }
}
