<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../System/db_connect.php';
require_once __DIR__ . '/../System/app_helpers.php';
require_once __DIR__ . '/../System/ops_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

$adminId = (int)($_SESSION['user_id'] ?? 0);
$adminName = (string)($_SESSION['fullname'] ?? 'ผู้ดูแลระบบ');
$permissions = defaultAdminPermissions();
try {
    $permissions = getAdminPermissions($conn, $adminId);
} catch (Throwable $e) {
    $permissions = defaultAdminPermissions();
}

$canManageOps = hasAdminPermission($permissions, 'can_manage_settings')
    || hasAdminPermission($permissions, 'can_backup_restore')
    || hasAdminPermission($permissions, 'can_view_audit');
if (!$canManageOps) {
    header('Location: admin_dashboard.php?status=permission_denied');
    exit;
}

$statusLabel = static function (string $status): string {
    $map = [
        'all' => 'ทั้งหมด',
        'pending' => 'รอดำเนินการ',
        'running' => 'กำลังทำงาน',
        'done' => 'เสร็จสิ้น',
        'failed' => 'ล้มเหลว',
        'cancelled' => 'ยกเลิกแล้ว',
        'idle' => 'ว่าง',
    ];
    return $map[$status] ?? $status;
};

$jobTypeLabel = static function (string $jobType): string {
    $map = [
        'all' => 'ทุกชนิด',
        'deadline_reminder' => 'แจ้งเตือนกำหนดส่ง',
        'auto_backup' => 'สำรองข้อมูลอัตโนมัติ',
        'retention_cleanup' => 'ล้างข้อมูลตามนโยบาย',
        'recalculate_progress' => 'คำนวณความคืบหน้าใหม่',
    ];
    return $map[$jobType] ?? $jobType;
};

$eventLabel = static function (string $event): string {
    $map = [
        'queued' => 'เข้าคิว',
        'started' => 'เริ่มทำงาน',
        'done' => 'สำเร็จ',
        'failed' => 'ล้มเหลว',
        'cancelled' => 'ยกเลิก',
        'requeued' => 'เข้าคิวใหม่',
    ];
    return $map[$event] ?? $event;
};

$redirect = static function (string $status, array $extra = []): void {
    $params = array_merge(['status' => $status], $extra);
    header('Location: admin_ops.php?' . http_build_query($params));
    exit;
};

csrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensureValidCsrfOrRedirect('admin_ops.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'save_worker_settings') {
            $before = [
                'worker_deadline_interval_minutes' => systemSettingInt($conn, 'worker_deadline_interval_minutes', 10, 1, 120),
                'worker_backup_interval_minutes' => systemSettingInt($conn, 'worker_backup_interval_minutes', 60, 5, 720),
                'worker_retention_interval_minutes' => systemSettingInt($conn, 'worker_retention_interval_minutes', 1440, 60, 10080),
                'worker_retention_audit_days' => systemSettingInt($conn, 'worker_retention_audit_days', 365, 30, 3650),
                'worker_retention_notification_log_days' => systemSettingInt($conn, 'worker_retention_notification_log_days', 60, 7, 3650),
                'worker_retention_cleanup_log_days' => systemSettingInt($conn, 'worker_retention_cleanup_log_days', 90, 7, 3650),
            ];

            $deadlineMinutes = (int)($_POST['worker_deadline_interval_minutes'] ?? 10);
            $backupMinutes = (int)($_POST['worker_backup_interval_minutes'] ?? 60);
            $retentionMinutes = (int)($_POST['worker_retention_interval_minutes'] ?? 1440);
            $retentionAuditDays = (int)($_POST['worker_retention_audit_days'] ?? 365);
            $retentionDispatchDays = (int)($_POST['worker_retention_notification_log_days'] ?? 60);
            $retentionCleanupDays = (int)($_POST['worker_retention_cleanup_log_days'] ?? 90);

            $deadlineMinutes = max(1, min(120, $deadlineMinutes));
            $backupMinutes = max(5, min(720, $backupMinutes));
            $retentionMinutes = max(60, min(10080, $retentionMinutes));
            $retentionAuditDays = max(30, min(3650, $retentionAuditDays));
            $retentionDispatchDays = max(7, min(3650, $retentionDispatchDays));
            $retentionCleanupDays = max(7, min(3650, $retentionCleanupDays));

            saveSystemSetting($conn, 'worker_deadline_interval_minutes', (string)$deadlineMinutes, $adminId);
            saveSystemSetting($conn, 'worker_backup_interval_minutes', (string)$backupMinutes, $adminId);
            saveSystemSetting($conn, 'worker_retention_interval_minutes', (string)$retentionMinutes, $adminId);
            saveSystemSetting($conn, 'worker_retention_audit_days', (string)$retentionAuditDays, $adminId);
            saveSystemSetting($conn, 'worker_retention_notification_log_days', (string)$retentionDispatchDays, $adminId);
            saveSystemSetting($conn, 'worker_retention_cleanup_log_days', (string)$retentionCleanupDays, $adminId);

            $after = [
                'worker_deadline_interval_minutes' => $deadlineMinutes,
                'worker_backup_interval_minutes' => $backupMinutes,
                'worker_retention_interval_minutes' => $retentionMinutes,
                'worker_retention_audit_days' => $retentionAuditDays,
                'worker_retention_notification_log_days' => $retentionDispatchDays,
                'worker_retention_cleanup_log_days' => $retentionCleanupDays,
            ];

            writeAuditLog(
                $conn,
                $adminId,
                'admin.ops.settings.update',
                'Updated worker scheduling and retention settings',
                'system',
                null,
                $before,
                $after
            );
            $redirect('settings_saved');
        }

        if ($action === 'enqueue_job') {
            $jobType = trim((string)($_POST['job_type'] ?? ''));
            $priority = (int)($_POST['priority'] ?? 0);
            $maxAttempts = (int)($_POST['max_attempts'] ?? 3);
            $dedupeKey = trim((string)($_POST['dedupe_key'] ?? ''));
            $payloadRaw = trim((string)($_POST['payload_json'] ?? ''));

            $priority = max(-100, min(100, $priority));
            $maxAttempts = max(1, min(20, $maxAttempts));
            if ($dedupeKey === '') {
                $dedupeKey = null;
            }

            $payload = [];
            if ($payloadRaw !== '') {
                try {
                    $decoded = json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $payload = $decoded;
                    }
                } catch (Throwable $jsonError) {
                    throw new RuntimeException('รูปแบบ JSON ของ payload ไม่ถูกต้อง');
                }
            }

            $jobId = opsEnqueueJob($conn, $jobType, $payload, $adminId, $priority, null, $maxAttempts, $dedupeKey);
            if ($jobId <= 0) {
                throw new RuntimeException('ไม่สามารถเพิ่มงานเข้าคิวได้');
            }

            writeAuditLog(
                $conn,
                $adminId,
                'admin.ops.job.enqueue',
                'Queued job ' . $jobType . ' #' . $jobId,
                'system_job_queue',
                $jobId,
                null,
                [
                    'job_type' => $jobType,
                    'priority' => $priority,
                    'max_attempts' => $maxAttempts,
                    'dedupe_key' => $dedupeKey,
                ]
            );
            $redirect('job_queued', ['job_id' => $jobId]);
        }

        if ($action === 'run_worker_once') {
            $jobType = trim((string)($_POST['job_type'] ?? ''));
            $limit = (int)($_POST['limit'] ?? 20);
            $scheduleRecurring = isset($_POST['schedule_recurring']) && (string)$_POST['schedule_recurring'] === '1';
            $workerId = trim((string)($_POST['worker_id'] ?? ''));

            $limit = max(1, min(200, $limit));
            if ($workerId === '') {
                $workerId = 'web-admin-' . $adminId . '-' . date('YmdHis');
            }

            $result = opsRunWorker($conn, [
                'worker_id' => $workerId,
                'job_type' => $jobType,
                'limit' => $limit,
                'actor_id' => $adminId,
                'schedule_recurring' => $scheduleRecurring,
            ]);

            writeAuditLog(
                $conn,
                $adminId,
                'admin.ops.worker.run_once',
                'Ran worker once: ' . $workerId,
                'system',
                null,
                [
                    'job_type' => $jobType,
                    'limit' => $limit,
                    'schedule_recurring' => $scheduleRecurring ? 1 : 0,
                ],
                $result
            );

            $redirect('worker_ran', [
                'processed' => (int)($result['processed'] ?? 0),
                'done' => (int)($result['done'] ?? 0),
                'failed' => (int)($result['failed'] ?? 0),
            ]);
        }

        if ($action === 'schedule_recurring_now') {
            $result = opsScheduleRecurringJobs($conn, $adminId);
            $count = (int)($result['count'] ?? 0);
            writeAuditLog(
                $conn,
                $adminId,
                'admin.ops.schedule.recurring',
                'Scheduled recurring jobs manually',
                'system',
                null,
                null,
                ['count' => $count]
            );
            $redirect('recurring_scheduled', ['count' => $count]);
        }

        if ($action === 'retry_job') {
            $jobId = (int)($_POST['job_id'] ?? 0);
            if ($jobId <= 0 || !opsRetryFailedJob($conn, $jobId)) {
                throw new RuntimeException('ลองใหม่ไม่สำเร็จ งานต้องอยู่ในสถานะล้มเหลวก่อน');
            }

            writeAuditLog(
                $conn,
                $adminId,
                'admin.ops.job.retry',
                'Retried failed job #' . $jobId,
                'system_job_queue',
                $jobId
            );
            $redirect('job_retried', ['job_id' => $jobId]);
        }

        if ($action === 'cancel_job') {
            $jobId = (int)($_POST['job_id'] ?? 0);
            if ($jobId <= 0 || !opsCancelPendingJob($conn, $jobId)) {
                throw new RuntimeException('ยกเลิกไม่สำเร็จ งานต้องอยู่ในสถานะรอดำเนินการหรือกำลังทำงาน');
            }

            writeAuditLog(
                $conn,
                $adminId,
                'admin.ops.job.cancel',
                'Cancelled job #' . $jobId,
                'system_job_queue',
                $jobId
            );
            $redirect('job_cancelled', ['job_id' => $jobId]);
        }

        $redirect('unknown_action');
    } catch (Throwable $e) {
        writeAuditLog(
            $conn,
            $adminId,
            'admin.ops.action.error',
            'Admin ops action failed: ' . $e->getMessage(),
            'system',
            null
        );
        $redirect('action_error', ['message' => substr($e->getMessage(), 0, 120)]);
    }
}

$status = trim((string)($_GET['status'] ?? ''));
$statusMessage = '';
$statusTone = 'info';

if ($status === 'settings_saved') {
    $statusTone = 'success';
    $statusMessage = 'บันทึกการตั้งค่าตัวประมวลผลงานเรียบร้อยแล้ว';
}
if ($status === 'job_queued') {
    $statusTone = 'success';
    $statusMessage = 'เพิ่มงานเข้าคิวเรียบร้อย (งาน #' . (int)($_GET['job_id'] ?? 0) . ')';
}
if ($status === 'worker_ran') {
    $statusTone = 'success';
    $statusMessage = sprintf(
        'รันตัวประมวลผลแล้ว: ประมวลผล=%d, สำเร็จ=%d, ล้มเหลว=%d',
        (int)($_GET['processed'] ?? 0),
        (int)($_GET['done'] ?? 0),
        (int)($_GET['failed'] ?? 0)
    );
}
if ($status === 'recurring_scheduled') {
    $statusTone = 'success';
    $statusMessage = 'จัดคิวงานประจำเรียบร้อย จำนวน ' . (int)($_GET['count'] ?? 0) . ' งาน';
}
if ($status === 'job_retried') {
    $statusTone = 'success';
    $statusMessage = 'สั่งลองใหม่งาน #' . (int)($_GET['job_id'] ?? 0) . ' แล้ว';
}
if ($status === 'job_cancelled') {
    $statusTone = 'warning';
    $statusMessage = 'ยกเลิกงาน #' . (int)($_GET['job_id'] ?? 0) . ' แล้ว';
}
if ($status === 'action_error') {
    $statusTone = 'error';
    $statusMessage = trim((string)($_GET['message'] ?? 'การทำงานล้มเหลว'));
}
if ($status === 'csrf_invalid') {
    $statusTone = 'error';
    $statusMessage = 'คำขอไม่ถูกต้อง กรุณาลองอีกครั้ง';
}
if ($status === 'unknown_action') {
    $statusTone = 'error';
    $statusMessage = 'ไม่รู้จักคำสั่งที่ส่งมา';
}

$queueStatusFilter = trim((string)($_GET['queue_status'] ?? 'all'));
$queueTypeFilter = trim((string)($_GET['queue_type'] ?? 'all'));
$allowedStatuses = ['all', 'pending', 'running', 'done', 'failed', 'cancelled'];
$allowedTypes = ['all', 'deadline_reminder', 'auto_backup', 'retention_cleanup', 'recalculate_progress'];
if (!in_array($queueStatusFilter, $allowedStatuses, true)) {
    $queueStatusFilter = 'all';
}
if (!in_array($queueTypeFilter, $allowedTypes, true)) {
    $queueTypeFilter = 'all';
}

ensureSystemOpsTables($conn);
$stats = opsQueueStats($conn);
$workers = opsRecentWorkers($conn, 20);

$where = [];
$params = [];
if ($queueStatusFilter !== 'all') {
    $where[] = 'q.status = ?';
    $params[] = $queueStatusFilter;
}
if ($queueTypeFilter !== 'all') {
    $where[] = 'q.job_type = ?';
    $params[] = $queueTypeFilter;
}

$sql = '
    SELECT q.id, q.job_type, q.status, q.priority, q.attempt_count, q.max_attempts, q.available_at, q.locked_by, q.last_error, q.created_at, q.finished_at
    FROM system_job_queue q
';
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY q.id DESC LIMIT 120';

$jobsStmt = $conn->prepare($sql);
$jobsStmt->execute($params);
$jobs = $jobsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$logsStmt = $conn->query("
    SELECT l.id, l.job_id, l.event_type, l.message, l.created_at, q.job_type
    FROM system_job_logs l
    LEFT JOIN system_job_queue q ON q.id = l.job_id
    ORDER BY l.id DESC
    LIMIT 50
");
$logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$settings = [
    'worker_deadline_interval_minutes' => systemSettingInt($conn, 'worker_deadline_interval_minutes', 10, 1, 120),
    'worker_backup_interval_minutes' => systemSettingInt($conn, 'worker_backup_interval_minutes', 60, 5, 720),
    'worker_retention_interval_minutes' => systemSettingInt($conn, 'worker_retention_interval_minutes', 1440, 60, 10080),
    'worker_retention_audit_days' => systemSettingInt($conn, 'worker_retention_audit_days', 365, 30, 3650),
    'worker_retention_notification_log_days' => systemSettingInt($conn, 'worker_retention_notification_log_days', 60, 7, 3650),
    'worker_retention_cleanup_log_days' => systemSettingInt($conn, 'worker_retention_cleanup_log_days', 90, 7, 3650),
];

writeAuditLog($conn, $adminId, 'admin.ops.view', 'เปิดหน้าศูนย์ปฏิบัติการระบบ', 'system', null);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ศูนย์ปฏิบัติการระบบ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: "Sarabun", sans-serif; }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 min-h-screen">
    <nav class="bg-slate-900 text-white">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <i class="fas fa-server text-sky-300"></i>
                <span class="font-bold">ศูนย์ปฏิบัติการระบบ</span>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-sm hidden md:inline"><?= htmlspecialchars($adminName) ?></span>
                <a href="admin_dashboard.php" class="bg-slate-700 hover:bg-slate-600 px-3 py-1.5 rounded text-sm">
                    <i class="fas fa-arrow-left mr-1"></i> กลับหน้าแอดมิน
                </a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-4 py-6 space-y-6">
        <?php if ($statusMessage !== ''): ?>
            <?php
                $toneClass = 'bg-sky-50 border-sky-200 text-sky-700';
                if ($statusTone === 'success') {
                    $toneClass = 'bg-emerald-50 border-emerald-200 text-emerald-700';
                }
                if ($statusTone === 'warning') {
                    $toneClass = 'bg-amber-50 border-amber-200 text-amber-700';
                }
                if ($statusTone === 'error') {
                    $toneClass = 'bg-rose-50 border-rose-200 text-rose-700';
                }
            ?>
            <div class="rounded-lg border px-4 py-3 text-sm <?= $toneClass ?>">
                <?= htmlspecialchars($statusMessage) ?>
            </div>
        <?php endif; ?>

        <section class="grid grid-cols-2 lg:grid-cols-5 gap-4">
            <article class="bg-white rounded-xl shadow p-4 border-l-4 border-blue-500">
                <div class="text-xs text-slate-500">รอดำเนินการ</div>
                <div class="text-2xl font-bold"><?= (int)($stats['pending'] ?? 0) ?></div>
            </article>
            <article class="bg-white rounded-xl shadow p-4 border-l-4 border-cyan-500">
                <div class="text-xs text-slate-500">กำลังทำงาน</div>
                <div class="text-2xl font-bold"><?= (int)($stats['running'] ?? 0) ?></div>
            </article>
            <article class="bg-white rounded-xl shadow p-4 border-l-4 border-emerald-500">
                <div class="text-xs text-slate-500">เสร็จสิ้น (24 ชม.)</div>
                <div class="text-2xl font-bold"><?= (int)($stats['done_24h'] ?? 0) ?></div>
            </article>
            <article class="bg-white rounded-xl shadow p-4 border-l-4 border-rose-500">
                <div class="text-xs text-slate-500">ล้มเหลว</div>
                <div class="text-2xl font-bold"><?= (int)($stats['failed'] ?? 0) ?></div>
            </article>
            <article class="bg-white rounded-xl shadow p-4 border-l-4 border-violet-500">
                <div class="text-xs text-slate-500">ตัวประมวลผลที่ออนไลน์ (5 นาที)</div>
                <div class="text-2xl font-bold"><?= (int)($stats['workers_active'] ?? 0) ?></div>
            </article>
        </section>

        <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
            <div class="xl:col-span-2 bg-white rounded-xl shadow p-5">
                <h2 class="font-bold mb-3 text-lg"><i class="fas fa-play-circle mr-2 text-indigo-600"></i>สั่งรันตัวประมวลผลงาน</h2>
                <form method="POST" class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="action" value="run_worker_once">
                    <div>
                        <label class="block text-sm font-medium mb-1">ชนิดงาน</label>
                        <select name="job_type" class="w-full border rounded px-3 py-2 text-sm">
                            <option value="">ทุกชนิด</option>
                            <option value="deadline_reminder"><?= htmlspecialchars($jobTypeLabel('deadline_reminder')) ?></option>
                            <option value="auto_backup"><?= htmlspecialchars($jobTypeLabel('auto_backup')) ?></option>
                            <option value="retention_cleanup"><?= htmlspecialchars($jobTypeLabel('retention_cleanup')) ?></option>
                            <option value="recalculate_progress"><?= htmlspecialchars($jobTypeLabel('recalculate_progress')) ?></option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">จำนวนงานสูงสุด</label>
                        <input type="number" name="limit" value="20" min="1" max="200" class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">รหัสตัวประมวลผล (ไม่บังคับ)</label>
                        <input type="text" name="worker_id" placeholder="auto-generate" class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                    <label class="text-sm flex items-center gap-2">
                        <input type="checkbox" name="schedule_recurring" value="1" class="h-4 w-4">
                        จัดคิวงานประจำก่อนรัน
                    </label>
                    <button type="submit" class="md:justify-self-end bg-indigo-700 hover:bg-indigo-800 text-white px-4 py-2 rounded text-sm font-semibold">
                        <i class="fas fa-play mr-1"></i> รัน 1 รอบ
                    </button>
                </form>
                <form method="POST" class="mt-3">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="action" value="schedule_recurring_now">
                    <button type="submit" class="bg-sky-700 hover:bg-sky-800 text-white px-4 py-2 rounded text-sm font-semibold">
                        <i class="fas fa-clock mr-1"></i> จัดคิวงานประจำทันที
                    </button>
                </form>
            </div>

            <div class="bg-white rounded-xl shadow p-5">
                <h2 class="font-bold mb-3 text-lg"><i class="fas fa-plus-circle mr-2 text-emerald-600"></i>เพิ่มงานเข้าคิว</h2>
                <form method="POST" class="space-y-3">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="action" value="enqueue_job">
                    <div>
                        <label class="block text-sm font-medium mb-1">ชนิดงาน</label>
                        <select name="job_type" class="w-full border rounded px-3 py-2 text-sm" required>
                            <option value="deadline_reminder"><?= htmlspecialchars($jobTypeLabel('deadline_reminder')) ?></option>
                            <option value="auto_backup"><?= htmlspecialchars($jobTypeLabel('auto_backup')) ?></option>
                            <option value="retention_cleanup"><?= htmlspecialchars($jobTypeLabel('retention_cleanup')) ?></option>
                            <option value="recalculate_progress"><?= htmlspecialchars($jobTypeLabel('recalculate_progress')) ?></option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium mb-1">ลำดับความสำคัญ</label>
                            <input type="number" name="priority" value="10" min="-100" max="100" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">จำนวนครั้งสูงสุด</label>
                            <input type="number" name="max_attempts" value="3" min="1" max="20" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">คีย์กันงานซ้ำ (ไม่บังคับ)</label>
                        <input type="text" name="dedupe_key" placeholder="e.g. auto_backup|20260512-1000" class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">ข้อมูลประกอบงาน (JSON) (ไม่บังคับ)</label>
                        <textarea name="payload_json" rows="4" class="w-full border rounded px-3 py-2 text-sm" placeholder='{"trigger":"manual"}'></textarea>
                    </div>
                    <button type="submit" class="bg-emerald-700 hover:bg-emerald-800 text-white px-4 py-2 rounded text-sm font-semibold">
                        <i class="fas fa-paper-plane mr-1"></i> เพิ่มเข้าคิว
                    </button>
                </form>
            </div>
        </section>

        <section class="bg-white rounded-xl shadow p-5">
            <h2 class="font-bold mb-4 text-lg"><i class="fas fa-sliders-h mr-2 text-amber-600"></i>ตั้งค่าตัวประมวลผลและการเก็บข้อมูล</h2>
            <form method="POST" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 items-end">
                <?= csrfInputField() ?>
                <input type="hidden" name="action" value="save_worker_settings">

                <div>
                    <label class="block text-sm font-medium mb-1">รอบแจ้งเตือนกำหนดส่ง (นาที)</label>
                    <input type="number" name="worker_deadline_interval_minutes" value="<?= (int)$settings['worker_deadline_interval_minutes'] ?>" min="1" max="120" class="w-full border rounded px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">รอบสำรองข้อมูลอัตโนมัติ (นาที)</label>
                    <input type="number" name="worker_backup_interval_minutes" value="<?= (int)$settings['worker_backup_interval_minutes'] ?>" min="5" max="720" class="w-full border rounded px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">รอบล้างข้อมูลตามนโยบาย (นาที)</label>
                    <input type="number" name="worker_retention_interval_minutes" value="<?= (int)$settings['worker_retention_interval_minutes'] ?>" min="60" max="10080" class="w-full border rounded px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">เก็บบันทึกการตรวจสอบ (วัน)</label>
                    <input type="number" name="worker_retention_audit_days" value="<?= (int)$settings['worker_retention_audit_days'] ?>" min="30" max="3650" class="w-full border rounded px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">เก็บบันทึกการส่งแจ้งเตือน (วัน)</label>
                    <input type="number" name="worker_retention_notification_log_days" value="<?= (int)$settings['worker_retention_notification_log_days'] ?>" min="7" max="3650" class="w-full border rounded px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">เก็บบันทึกล้างข้อมูลตามนโยบาย (วัน)</label>
                    <input type="number" name="worker_retention_cleanup_log_days" value="<?= (int)$settings['worker_retention_cleanup_log_days'] ?>" min="7" max="3650" class="w-full border rounded px-3 py-2 text-sm">
                </div>
                <div class="md:col-span-2 lg:col-span-3 text-right">
                    <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white px-5 py-2 rounded text-sm font-semibold">
                        <i class="fas fa-save mr-1"></i> บันทึกการตั้งค่า
                    </button>
                </div>
            </form>
        </section>

        <section class="bg-white rounded-xl shadow p-5">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                <h2 class="font-bold text-lg"><i class="fas fa-list mr-2 text-slate-700"></i>รายการงานในคิว</h2>
                <form method="GET" class="flex flex-wrap items-center gap-2 text-sm">
                    <select name="queue_status" class="border rounded px-2 py-1.5">
                        <?php foreach ($allowedStatuses as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>" <?= $queueStatusFilter === $s ? 'selected' : '' ?>>สถานะ: <?= htmlspecialchars($statusLabel($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="queue_type" class="border rounded px-2 py-1.5">
                        <?php foreach ($allowedTypes as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>" <?= $queueTypeFilter === $t ? 'selected' : '' ?>>ชนิดงาน: <?= htmlspecialchars($jobTypeLabel($t)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="bg-slate-800 text-white px-3 py-1.5 rounded">กรอง</button>
                    <a href="admin_ops.php" class="bg-slate-100 px-3 py-1.5 rounded border">ล้างกรอง</a>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-slate-100 text-slate-700 text-left">
                            <th class="px-3 py-2">ID</th>
                            <th class="px-3 py-2">ชนิดงาน</th>
                            <th class="px-3 py-2">สถานะ</th>
                            <th class="px-3 py-2">จำนวนครั้ง</th>
                            <th class="px-3 py-2">ลำดับความสำคัญ</th>
                            <th class="px-3 py-2">พร้อมรันเมื่อ</th>
                            <th class="px-3 py-2">ถูกล็อกโดย</th>
                            <th class="px-3 py-2">ข้อผิดพลาด</th>
                            <th class="px-3 py-2">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($jobs)): ?>
                            <tr><td colspan="9" class="px-3 py-6 text-center text-slate-500">ไม่พบรายการงาน</td></tr>
                        <?php else: ?>
                            <?php foreach ($jobs as $job): ?>
                                <?php
                                    $jid = (int)($job['id'] ?? 0);
                                    $statusText = (string)($job['status'] ?? '');
                                    $canRetry = $statusText === 'failed';
                                    $canCancel = in_array($statusText, ['pending', 'running'], true);
                                ?>
                                <tr class="border-t align-top">
                                    <td class="px-3 py-2 font-semibold"><?= $jid ?></td>
                                    <td class="px-3 py-2"><?= htmlspecialchars($jobTypeLabel((string)($job['job_type'] ?? ''))) ?></td>
                                    <td class="px-3 py-2"><?= htmlspecialchars($statusLabel($statusText)) ?></td>
                                    <td class="px-3 py-2"><?= (int)($job['attempt_count'] ?? 0) ?>/<?= (int)($job['max_attempts'] ?? 0) ?></td>
                                    <td class="px-3 py-2"><?= (int)($job['priority'] ?? 0) ?></td>
                                    <td class="px-3 py-2 whitespace-nowrap"><?= htmlspecialchars((string)($job['available_at'] ?? '-')) ?></td>
                                    <td class="px-3 py-2"><?= htmlspecialchars((string)($job['locked_by'] ?? '-')) ?></td>
                                    <td class="px-3 py-2 max-w-xs break-words text-rose-700"><?= htmlspecialchars((string)($job['last_error'] ?? '')) ?></td>
                                    <td class="px-3 py-2">
                                        <div class="flex flex-wrap gap-2">
                                            <?php if ($canRetry): ?>
                                                <form method="POST" class="inline">
                                                    <?= csrfInputField() ?>
                                                    <input type="hidden" name="action" value="retry_job">
                                                    <input type="hidden" name="job_id" value="<?= $jid ?>">
                                                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-2 py-1 rounded text-xs">ลองใหม่</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($canCancel): ?>
                                                <form method="POST" class="inline">
                                                    <?= csrfInputField() ?>
                                                    <input type="hidden" name="action" value="cancel_job">
                                                    <input type="hidden" name="job_id" value="<?= $jid ?>">
                                                    <button type="submit" class="bg-rose-600 hover:bg-rose-700 text-white px-2 py-1 rounded text-xs">ยกเลิก</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <div class="bg-white rounded-xl shadow p-5">
                <h2 class="font-bold mb-3 text-lg"><i class="fas fa-heartbeat mr-2 text-violet-600"></i>สถานะหัวใจเต้นของตัวประมวลผล</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="bg-slate-100 text-left">
                                <th class="px-3 py-2">รหัสตัวประมวลผล</th>
                                <th class="px-3 py-2">สถานะ</th>
                                <th class="px-3 py-2">ประมวลผลแล้ว</th>
                                <th class="px-3 py-2">ล้มเหลว</th>
                                <th class="px-3 py-2">ออนไลน์ล่าสุด</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($workers)): ?>
                                <tr><td colspan="5" class="px-3 py-6 text-center text-slate-500">ยังไม่มีสัญญาณจากตัวประมวลผล</td></tr>
                            <?php else: ?>
                                <?php foreach ($workers as $worker): ?>
                                    <tr class="border-t">
                                        <td class="px-3 py-2"><?= htmlspecialchars((string)($worker['worker_id'] ?? '')) ?></td>
                                        <td class="px-3 py-2"><?= htmlspecialchars($statusLabel((string)($worker['last_status'] ?? ''))) ?></td>
                                        <td class="px-3 py-2"><?= (int)($worker['processed_count'] ?? 0) ?></td>
                                        <td class="px-3 py-2"><?= (int)($worker['failed_count'] ?? 0) ?></td>
                                        <td class="px-3 py-2 whitespace-nowrap"><?= htmlspecialchars((string)($worker['last_seen_at'] ?? '-')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow p-5">
                <h2 class="font-bold mb-3 text-lg"><i class="fas fa-history mr-2 text-slate-600"></i>บันทึกเหตุการณ์งานล่าสุด</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="bg-slate-100 text-left">
                                <th class="px-3 py-2">เวลา</th>
                                <th class="px-3 py-2">งาน</th>
                                <th class="px-3 py-2">ชนิดงาน</th>
                                <th class="px-3 py-2">เหตุการณ์</th>
                                <th class="px-3 py-2">ข้อความ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr><td colspan="5" class="px-3 py-6 text-center text-slate-500">ยังไม่มีบันทึก</td></tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr class="border-t align-top">
                                        <td class="px-3 py-2 whitespace-nowrap"><?= htmlspecialchars((string)($log['created_at'] ?? '-')) ?></td>
                                        <td class="px-3 py-2">#<?= (int)($log['job_id'] ?? 0) ?></td>
                                        <td class="px-3 py-2"><?= htmlspecialchars($jobTypeLabel((string)($log['job_type'] ?? '-'))) ?></td>
                                        <td class="px-3 py-2"><?= htmlspecialchars($eventLabel((string)($log['event_type'] ?? '-'))) ?></td>
                                        <td class="px-3 py-2 break-words"><?= htmlspecialchars((string)($log['message'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/rmutp-ui.js"></script>
    <script>
        window.RMUTP.attachFormSubmitGuard();
    </script>
</body>
</html>
