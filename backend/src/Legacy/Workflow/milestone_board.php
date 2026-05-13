<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../System/db_connect.php';
require_once __DIR__ . '/../System/app_helpers.php';
require_once __DIR__ . '/../System/lifecycle_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? '');
$fullname = (string)($_SESSION['fullname'] ?? '');
if (!in_array($role, ['student', 'teacher', 'admin'], true)) {
    header('Location: index.php');
    exit;
}

ensureAcademicLifecycleTables($conn);
refreshMilestoneOverdueStatus($conn);
if ($role === 'admin') {
    seedDefaultMilestoneTemplateIfMissing($conn, $userId);
}

csrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensureValidCsrfOrRedirect('milestone_board.php');
}

$projectSql = "
    SELECT p.id, p.name, p.student_id, p.advisor_id, p.co_advisor_id, p.program_code, p.semester_label, u.fullname AS leader_name
    FROM projects p
    INNER JOIN users u ON u.id = p.student_id
";
$projectParams = [];
if ($role === 'student') {
    $projectSql .= "
        LEFT JOIN project_members pm ON pm.project_id = p.id
        WHERE p.student_id = ? OR (pm.user_id = ? AND pm.status = 'accepted')
    ";
    $projectParams[] = $userId;
    $projectParams[] = $userId;
} elseif ($role === 'teacher') {
    $projectSql .= " WHERE p.advisor_id = ? OR p.co_advisor_id = ? ";
    $projectParams[] = $userId;
    $projectParams[] = $userId;
}
$projectSql .= " GROUP BY p.id ORDER BY p.updated_at DESC, p.id DESC";

$projectStmt = $conn->prepare($projectSql);
$projectStmt->execute($projectParams);
$projects = $projectStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$selectedProjectId = (int)($_GET['project_id'] ?? 0);
if ($selectedProjectId <= 0 && !empty($projects)) {
    $selectedProjectId = (int)$projects[0]['id'];
}

$selectedProject = null;
foreach ($projects as $projectRow) {
    if ((int)$projectRow['id'] === $selectedProjectId) {
        $selectedProject = $projectRow;
        break;
    }
}

$isLeader = $selectedProject && (int)$selectedProject['student_id'] === $userId;
$isAdvisor = $selectedProject && ((int)$selectedProject['advisor_id'] === $userId || (int)$selectedProject['co_advisor_id'] === $userId);
$isAdmin = $role === 'admin';
$canManageMilestone = (bool)($isLeader || $isAdvisor || $isAdmin);

$redirect = static function (int $projectId, string $status): void {
    $url = 'milestone_board.php';
    if ($projectId > 0) {
        $url = appendQueryParam($url, 'project_id', (string)$projectId);
    }
    $url = appendQueryParam($url, 'status', $status);
    header('Location: ' . $url);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedProjectId > 0 && $canManageMilestone) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'sync_template') {
        $programCode = trim((string)($selectedProject['program_code'] ?? ''));
        if ($programCode === '') {
            $programCode = 'GENERIC-BIT';
            $updateProjectProgram = $conn->prepare("UPDATE projects SET program_code = ? WHERE id = ?");
            $updateProjectProgram->execute([$programCode, $selectedProjectId]);
        }

        $inserted = syncProjectMilestonesFromTemplate($conn, $selectedProjectId, $programCode, $userId);
        writeAuditLog($conn, $userId, 'milestone.template.sync', 'Inserted milestones: ' . $inserted, 'project', $selectedProjectId);
        $redirect($selectedProjectId, 'template_synced');
    }

    if ($action === 'create_milestone') {
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $dueDate = trim((string)($_POST['due_date'] ?? ''));
        $weightPercent = (float)($_POST['weight_percent'] ?? 0);
        if ($title === '') {
            $redirect($selectedProjectId, 'title_required');
        }
        if ($dueDate === '') {
            $dueDate = null;
        }

        $insert = $conn->prepare("
            INSERT INTO project_milestones (
                project_id, title, description, due_date, weight_percent, status, locked_by_policy, created_by, created_at, updated_at
            )
            VALUES (?, ?, ?, ?, ?, 'pending', 0, ?, NOW(), NOW())
        ");
        $insert->execute([$selectedProjectId, $title, $description, $dueDate, $weightPercent, $userId]);
        $milestoneId = (int)$conn->lastInsertId();

        if ($milestoneId > 0) {
            $log = $conn->prepare("
                INSERT INTO milestone_progress_logs (milestone_id, action_key, before_status, after_status, note, action_by, created_at)
                VALUES (?, 'created', NULL, 'pending', 'สร้างรายการด้วยตนเอง', ?, NOW())
            ");
            $log->execute([$milestoneId, $userId]);
        }

        writeAuditLog($conn, $userId, 'milestone.create', 'Created milestone', 'project', $selectedProjectId);
        $redirect($selectedProjectId, 'milestone_created');
    }

    if ($action === 'set_status') {
        $milestoneId = (int)($_POST['milestone_id'] ?? 0);
        $newStatus = (string)($_POST['new_status'] ?? 'pending');
        if (!in_array($newStatus, ['pending', 'done', 'overdue', 'cancelled'], true)) {
            $newStatus = 'pending';
        }

        $check = $conn->prepare("
            SELECT id, status, locked_by_policy, lock_until
            FROM project_milestones
            WHERE id = ? AND project_id = ?
            LIMIT 1
        ");
        $check->execute([$milestoneId, $selectedProjectId]);
        $milestone = $check->fetch(PDO::FETCH_ASSOC);
        if (!$milestone) {
            $redirect($selectedProjectId, 'milestone_not_found');
        }

        $isLocked = (int)($milestone['locked_by_policy'] ?? 0) === 1;
        $lockUntil = trim((string)($milestone['lock_until'] ?? ''));
        if ($isLocked && $lockUntil !== '' && strtotime($lockUntil) > time() && !$isAdmin && !$isAdvisor) {
            $redirect($selectedProjectId, 'milestone_locked');
        }

        $previousStatus = (string)($milestone['status'] ?? 'pending');
        $update = $conn->prepare("
            UPDATE project_milestones
            SET status = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $update->execute([$newStatus, $userId, $milestoneId]);

        $log = $conn->prepare("
            INSERT INTO milestone_progress_logs (milestone_id, action_key, before_status, after_status, note, action_by, created_at)
            VALUES (?, 'status_change', ?, ?, 'อัปเดตสถานะจากกระดานไมล์สโตน', ?, NOW())
        ");
        $log->execute([$milestoneId, $previousStatus, $newStatus, $userId]);

        $participants = getProjectParticipantUserIds($conn, $selectedProjectId);
        createNotificationForUsers(
            $conn,
            $participants,
            'อัปเดตสถานะไมล์สโตน',
            'Milestone status changed to ' . strtoupper($newStatus),
            'info',
            $selectedProjectId
        );
        writeAuditLog($conn, $userId, 'milestone.status.update', 'Milestone #' . $milestoneId . ' => ' . $newStatus, 'project', $selectedProjectId);

        $redirect($selectedProjectId, 'status_updated');
    }

    if ($action === 'unlock_milestone' && ($isAdmin || $isAdvisor)) {
        $milestoneId = (int)($_POST['milestone_id'] ?? 0);
        $update = $conn->prepare("
            UPDATE project_milestones
            SET locked_by_policy = 0, lock_until = NULL, updated_at = NOW()
            WHERE id = ? AND project_id = ?
        ");
        $update->execute([$milestoneId, $selectedProjectId]);

        $log = $conn->prepare("
            INSERT INTO milestone_progress_logs (milestone_id, action_key, before_status, after_status, note, action_by, created_at)
            VALUES (?, 'unlocked', NULL, NULL, 'Unlocked โดย advisor/admin', ?, NOW())
        ");
        $log->execute([$milestoneId, $userId]);

        writeAuditLog($conn, $userId, 'milestone.unlock', 'Unlocked milestone #' . $milestoneId, 'project', $selectedProjectId);
        $redirect($selectedProjectId, 'milestone_unlocked');
    }
}

$milestones = [];
$milestoneLogs = [];
if ($selectedProjectId > 0) {
    $milestoneStmt = $conn->prepare("
        SELECT *
        FROM project_milestones
        WHERE project_id = ?
        ORDER BY due_date IS NULL ASC, due_date ASC, id ASC
    ");
    $milestoneStmt->execute([$selectedProjectId]);
    $milestones = $milestoneStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $logStmt = $conn->prepare("
        SELECT mpl.*, u.fullname AS actor_name
        FROM milestone_progress_logs mpl
        LEFT JOIN users u ON u.id = mpl.action_by
        INNER JOIN project_milestones pm ON pm.id = mpl.milestone_id
        WHERE pm.project_id = ?
        ORDER BY mpl.created_at DESC, mpl.id DESC
        LIMIT 30
    ");
    $logStmt->execute([$selectedProjectId]);
    $milestoneLogs = $logStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$status = (string)($_GET['status'] ?? '');
$roleLabel = static function (string $role): string {
    $map = [
        'student' => 'นักศึกษา',
        'teacher' => 'อาจารย์',
        'admin' => 'ผู้ดูแลระบบ',
    ];
    return $map[$role] ?? $role;
};
$milestoneStatusLabel = static function (string $status): string {
    $map = [
        'pending' => 'รอดำเนินการ',
        'done' => 'เสร็จสิ้น',
        'overdue' => 'เลยกำหนด',
        'cancelled' => 'ยกเลิก',
    ];
    return $map[$status] ?? $status;
};
$tenantContext = tenantRuntimeContext();
$tenantLabel = trim((string)($tenantContext['tenant_name'] ?? 'Default'));
if ($tenantLabel === '') {
    $tenantLabel = 'ค่าเริ่มต้น';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>กระดานไมล์สโตน</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="assets/js/rmutp-ui.js"></script>
</head>
<body class="bg-slate-100 text-slate-900">
    <div class="max-w-6xl mx-auto px-4 py-6">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <div>
                <h1 class="text-2xl font-bold">กระดานไมล์สโตน</h1>
                <p class="text-sm text-slate-600">ผู้ใช้: <?= htmlspecialchars($fullname) ?> (<?= htmlspecialchars($roleLabel($role)) ?>) | เทนเนนต์: <?= htmlspecialchars($tenantLabel) ?></p>
            </div>
            <div class="flex gap-2">
                <a href="proposal_center.php<?= $selectedProjectId > 0 ? '?project_id=' . (int)$selectedProjectId : '' ?>" class="px-3 py-2 rounded bg-indigo-600 text-white text-sm">ศูนย์ข้อเสนอโครงงาน</a>
                <a href="committee_assignment.php<?= $selectedProjectId > 0 ? '?project_id=' . (int)$selectedProjectId : '' ?>" class="px-3 py-2 rounded bg-cyan-700 text-white text-sm">จัดสรรกรรมการ</a>
                <a href="<?= $role === 'admin' ? 'admin_dashboard.php' : ($role === 'teacher' ? 'teacher_dashboard.php' : 'student_dashboard.php') ?>" class="px-3 py-2 rounded bg-slate-700 text-white text-sm">กลับ</a>
            </div>
        </div>

        <?php if ($status !== ''): ?>
            <div class="mb-4 rounded border border-slate-300 bg-white px-4 py-3 text-sm">สถานะ: <?= htmlspecialchars($status) ?></div>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow p-4 mb-4">
            <form method="GET" class="flex flex-wrap gap-2 items-end">
                <div class="min-w-[260px]">
                    <label class="block text-sm font-medium mb-1">โครงงาน</label>
                    <select name="project_id" class="w-full border rounded px-3 py-2">
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $selectedProjectId ? 'selected' : '' ?>>
                                #<?= (int)$p['id'] ?> <?= htmlspecialchars((string)$p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="px-3 py-2 rounded bg-slate-800 text-white text-sm">โหลด</button>
            </form>
        </div>

        <?php if (!$selectedProject): ?>
            <div class="bg-white rounded-xl shadow p-6 text-sm text-slate-600">ไม่พบโครงงานที่เข้าถึงได้</div>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="lg:col-span-2 space-y-4">
                    <?php if ($canManageMilestone): ?>
                        <div class="bg-white rounded-xl shadow p-4">
                            <h2 class="font-bold mb-3">การจัดการ</h2>
                            <div class="flex flex-wrap gap-2 mb-4">
                                <form method="POST">
                                    <?= csrfInputField() ?>
                                    <input type="hidden" name="action" value="sync_template">
                                    <button type="submit" class="px-3 py-2 rounded bg-emerald-700 text-white text-sm">ดึงจากเทมเพลต</button>
                                </form>
                            </div>
                            <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-2">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="action" value="create_milestone">
                                <input type="text" name="title" placeholder="ชื่อไมล์สโตน" class="border rounded px-3 py-2 text-sm md:col-span-2" required>
                                <input type="date" name="due_date" class="border rounded px-3 py-2 text-sm">
                                <input type="number" step="0.01" min="0" max="100" name="weight_percent" placeholder="สัดส่วน %" class="border rounded px-3 py-2 text-sm">
                                <textarea name="description" rows="2" placeholder="รายละเอียด" class="border rounded px-3 py-2 text-sm md:col-span-3"></textarea>
                                <button type="submit" class="rounded bg-slate-800 text-white text-sm px-3 py-2">สร้าง</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <div class="bg-white rounded-xl shadow p-4">
                        <h2 class="font-bold mb-3">รายการไมล์สโตน</h2>
                        <div class="space-y-3">
                            <?php foreach ($milestones as $m): ?>
                                <?php
                                    $isLocked = (int)($m['locked_by_policy'] ?? 0) === 1;
                                    $lockUntil = trim((string)($m['lock_until'] ?? ''));
                                ?>
                                <div class="border rounded p-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                                        <div class="font-medium"><?= htmlspecialchars((string)$m['title']) ?></div>
                                        <div class="text-xs text-slate-600">
                                            สถานะ=<strong><?= htmlspecialchars($milestoneStatusLabel((string)$m['status'])) ?></strong>
                                            | กำหนดส่ง=<?= htmlspecialchars((string)($m['due_date'] ?? '-')) ?>
                                            | น้ำหนัก=<?= htmlspecialchars((string)$m['weight_percent']) ?>%
                                        </div>
                                    </div>
                                    <div class="text-sm text-slate-600 mb-2"><?= nl2br(htmlspecialchars((string)($m['description'] ?? ''))) ?></div>
                                    <?php if ($isLocked): ?>
                                        <div class="text-xs text-amber-700 mb-2">ล็อกจากนโยบาย<?= $lockUntil !== '' ? ' ถึง ' . htmlspecialchars($lockUntil) : '' ?></div>
                                    <?php endif; ?>

                                    <?php if ($canManageMilestone): ?>
                                        <div class="flex flex-wrap gap-2">
                                            <form method="POST" class="flex gap-2">
                                                <?= csrfInputField() ?>
                                                <input type="hidden" name="action" value="set_status">
                                                <input type="hidden" name="milestone_id" value="<?= (int)$m['id'] ?>">
                                                <select name="new_status" class="border rounded px-2 py-1 text-sm">
                                                    <?php foreach (['pending', 'done', 'overdue', 'cancelled'] as $statusOption): ?>
                                                        <option value="<?= $statusOption ?>" <?= (string)$m['status'] === $statusOption ? 'selected' : '' ?>><?= htmlspecialchars($milestoneStatusLabel($statusOption)) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="px-2 py-1 rounded bg-blue-700 text-white text-xs">อัปเดต</button>
                                            </form>
                                            <?php if (($isAdmin || $isAdvisor) && $isLocked): ?>
                                                <form method="POST">
                                                    <?= csrfInputField() ?>
                                                    <input type="hidden" name="action" value="unlock_milestone">
                                                    <input type="hidden" name="milestone_id" value="<?= (int)$m['id'] ?>">
                                                    <button type="submit" class="px-2 py-1 rounded bg-orange-700 text-white text-xs">ปลดล็อก</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($milestones)): ?>
                                <div class="text-sm text-slate-500">ยังไม่มีไมล์สโตน</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="bg-white rounded-xl shadow p-4">
                        <h3 class="font-bold mb-2">ภาพรวมโครงงาน</h3>
                        <div class="text-sm text-slate-700">
                            <div>#<?= (int)$selectedProject['id'] ?> <?= htmlspecialchars((string)$selectedProject['name']) ?></div>
                            <div>หัวหน้าทีม: <?= htmlspecialchars((string)$selectedProject['leader_name']) ?></div>
                            <div>หลักสูตร: <?= htmlspecialchars((string)($selectedProject['program_code'] ?? 'GENERIC-BIT')) ?></div>
                            <div>ภาคการศึกษา: <?= htmlspecialchars((string)($selectedProject['semester_label'] ?? '-')) ?></div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow p-4">
                        <h3 class="font-bold mb-2">กิจกรรมล่าสุด</h3>
                        <div class="space-y-2 text-sm">
                            <?php foreach ($milestoneLogs as $log): ?>
                                <div class="border rounded px-2 py-1">
                                    <div><strong><?= htmlspecialchars((string)$log['action_key']) ?></strong> โดย <?= htmlspecialchars((string)($log['actor_name'] ?? 'system')) ?></div>
                                    <div class="text-slate-500"><?= htmlspecialchars((string)$log['created_at']) ?></div>
                                    <?php if (!empty($log['note'])): ?>
                                        <div class="text-slate-600"><?= htmlspecialchars((string)$log['note']) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($milestoneLogs)): ?>
                                <div class="text-slate-500">ยังไม่มีกิจกรรม</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
