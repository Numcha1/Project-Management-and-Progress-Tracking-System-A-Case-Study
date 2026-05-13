<?php
session_start();
require_once __DIR__ . '/../System/db_connect.php';
require_once __DIR__ . '/../System/app_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentUserName = (string)($_SESSION['fullname'] ?? 'ผู้ใช้งาน');
$currentRole = (string)($_SESSION['role'] ?? 'student');

if (!in_array($currentRole, ['student', 'teacher', 'admin'], true)) {
    header('Location: index.php');
    exit;
}

try {
    ensureProjectApprovalTables($conn);
} catch (Throwable $e) {
    die('ไม่สามารถเริ่มระบบอนุมัติโครงงานได้');
}

csrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensureValidCsrfOrRedirect('approval_center.php');
}

$redirect = static function (string $status): void {
    header('Location: approval_center.php?status=' . urlencode($status));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'submit_request') {
    if ($currentRole !== 'student') {
        $redirect('permission_denied');
    }

    $projectId = (int)($_POST['project_id'] ?? 0);
    $errorCode = null;
    $requestId = submitProjectApprovalRequest($conn, $projectId, $currentUserId, $errorCode);
    if ($requestId !== null) {
        $redirect('submitted');
    }
    $redirect((string)($errorCode ?: 'submit_failed'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'review_request') {
    if (!in_array($currentRole, ['teacher', 'admin'], true)) {
        $redirect('permission_denied');
    }

    $requestId = (int)($_POST['request_id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    $errorCode = null;
    $ok = processProjectApprovalDecision($conn, $requestId, $currentUserId, $currentRole, $decision, $note, $errorCode);
    if ($ok) {
        $redirect($decision === 'approve' ? 'approved' : 'rejected');
    }
    $redirect((string)($errorCode ?: 'review_failed'));
}

$studentProjects = [];
$approvalQueue = [];
$historyRows = [];

if ($currentRole === 'student') {
    $stmt = $conn->prepare("
        SELECT
            p.id,
            p.name,
            p.progress,
            p.status,
            p.created_at,
            adv.fullname AS advisor_name,
            co.fullname AS co_advisor_name,
            r.id AS request_id,
            r.current_step,
            r.total_steps,
            r.status AS request_status,
            r.submitted_at,
            r.decided_at,
            r.last_action_note
        FROM projects p
        LEFT JOIN users adv ON adv.id = p.advisor_id
        LEFT JOIN users co ON co.id = p.co_advisor_id
        LEFT JOIN project_approval_requests r
            ON r.id = (
                SELECT r2.id
                FROM project_approval_requests r2
                WHERE r2.project_id = p.id
                ORDER BY r2.id DESC
                LIMIT 1
            )
        WHERE p.student_id = ?
        ORDER BY p.id DESC
    ");
    $stmt->execute([$currentUserId]);
    $studentProjects = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $historyStmt = $conn->prepare("
        SELECT
            r.id AS request_id,
            p.id AS project_id,
            p.name AS project_name,
            u.fullname AS submitted_by_name,
            la.fullname AS last_actor_name,
            r.current_step,
            r.total_steps,
            r.status AS request_status,
            r.submitted_at,
            r.decided_at,
            r.last_action_note
        FROM project_approval_requests r
        INNER JOIN projects p ON p.id = r.project_id
        INNER JOIN users u ON u.id = r.submitted_by
        LEFT JOIN users la ON la.id = r.last_action_by
        WHERE p.student_id = ?
        ORDER BY r.id DESC
        LIMIT 100
    ");
    $historyStmt->execute([$currentUserId]);
    $historyRows = $historyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} elseif ($currentRole === 'teacher') {
    $queueStmt = $conn->prepare("
        SELECT
            r.id AS request_id,
            r.project_id,
            r.current_step,
            r.total_steps,
            r.status AS request_status,
            r.submitted_at,
            r.last_action_note,
            p.name AS project_name,
            p.progress,
            stu.fullname AS student_name,
            req.fullname AS submitted_by_name
        FROM project_approval_requests r
        INNER JOIN projects p ON p.id = r.project_id
        LEFT JOIN users stu ON stu.id = p.student_id
        LEFT JOIN users req ON req.id = r.submitted_by
        WHERE r.status = 'pending'
          AND r.current_step = 1
          AND (p.advisor_id = ? OR p.co_advisor_id = ?)
        ORDER BY r.submitted_at ASC
        LIMIT 100
    ");
    $queueStmt->execute([$currentUserId, $currentUserId]);
    $approvalQueue = $queueStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $historyStmt = $conn->prepare("
        SELECT
            r.id AS request_id,
            p.id AS project_id,
            p.name AS project_name,
            u.fullname AS submitted_by_name,
            la.fullname AS last_actor_name,
            r.current_step,
            r.total_steps,
            r.status AS request_status,
            r.submitted_at,
            r.decided_at,
            r.last_action_note
        FROM project_approval_requests r
        INNER JOIN projects p ON p.id = r.project_id
        INNER JOIN users u ON u.id = r.submitted_by
        LEFT JOIN users la ON la.id = r.last_action_by
        WHERE p.advisor_id = ? OR p.co_advisor_id = ?
        ORDER BY r.id DESC
        LIMIT 100
    ");
    $historyStmt->execute([$currentUserId, $currentUserId]);
    $historyRows = $historyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
    $queueStmt = $conn->prepare("
        SELECT
            r.id AS request_id,
            r.project_id,
            r.current_step,
            r.total_steps,
            r.status AS request_status,
            r.submitted_at,
            r.last_action_note,
            p.name AS project_name,
            p.progress,
            stu.fullname AS student_name,
            req.fullname AS submitted_by_name
        FROM project_approval_requests r
        INNER JOIN projects p ON p.id = r.project_id
        LEFT JOIN users stu ON stu.id = p.student_id
        LEFT JOIN users req ON req.id = r.submitted_by
        WHERE r.status = 'pending'
          AND r.current_step = 2
        ORDER BY r.submitted_at ASC
        LIMIT 100
    ");
    $queueStmt->execute();
    $approvalQueue = $queueStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $historyStmt = $conn->prepare("
        SELECT
            r.id AS request_id,
            p.id AS project_id,
            p.name AS project_name,
            u.fullname AS submitted_by_name,
            la.fullname AS last_actor_name,
            r.current_step,
            r.total_steps,
            r.status AS request_status,
            r.submitted_at,
            r.decided_at,
            r.last_action_note
        FROM project_approval_requests r
        INNER JOIN projects p ON p.id = r.project_id
        INNER JOIN users u ON u.id = r.submitted_by
        LEFT JOIN users la ON la.id = r.last_action_by
        ORDER BY r.id DESC
        LIMIT 150
    ");
    $historyStmt->execute();
    $historyRows = $historyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$queueCount = count($approvalQueue);
$historyCount = count($historyRows);
$pendingHistoryCount = 0;
$approvedHistoryCount = 0;
$rejectedHistoryCount = 0;
foreach ($historyRows as $h) {
    $st = (string)($h['request_status'] ?? '');
    if ($st === 'pending') {
        $pendingHistoryCount++;
    } elseif ($st === 'approved') {
        $approvedHistoryCount++;
    } elseif ($st === 'rejected') {
        $rejectedHistoryCount++;
    }
}

writeAuditLog($conn, $currentUserId, 'project.approval.center.view', 'เปิดศูนย์อนุมัติโครงงาน', 'approval', null);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ศูนย์อนุมัติโครงงาน</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/rmutp-ui.js"></script>
    <style>body { font-family: "Sarabun", sans-serif; }</style>
</head>
<body class="bg-gray-100 text-gray-800 min-h-screen">
    <nav class="bg-slate-800 text-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <i class="fas fa-route"></i>
                <span class="font-bold">ศูนย์อนุมัติโครงงาน</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs md:text-sm"><?= htmlspecialchars($currentUserName) ?> (<?= htmlspecialchars($currentRole) ?>)</span>
                <?php
                $backUrl = 'index.php';
                if ($currentRole === 'student') {
                    $backUrl = 'student_dashboard.php';
                } elseif ($currentRole === 'teacher') {
                    $backUrl = 'teacher_dashboard.php';
                } elseif ($currentRole === 'admin') {
                    $backUrl = 'admin_dashboard.php';
                }
                ?>
                <a href="<?= htmlspecialchars($backUrl) ?>" class="bg-slate-700 hover:bg-slate-600 px-3 py-1.5 rounded text-sm">
                    <i class="fas fa-arrow-left mr-1"></i> กลับหน้าหลัก
                </a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto p-4 md:p-8 space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-blue-500">
                <div class="text-sm text-gray-500">รอคุณอนุมัติ</div>
                <div class="text-2xl font-bold"><span data-rt-approval-queue><?= (int)$queueCount ?></span></div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-amber-500">
                <div class="text-sm text-gray-500">คำขอที่ยังรอ</div>
                <div class="text-2xl font-bold"><span data-rt-approval-pending><?= (int)$pendingHistoryCount ?></span></div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-emerald-500">
                <div class="text-sm text-gray-500">อนุมัติแล้ว</div>
                <div class="text-2xl font-bold"><span data-rt-approval-approved><?= (int)$approvedHistoryCount ?></span></div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-rose-500">
                <div class="text-sm text-gray-500">ตีกลับ</div>
                <div class="text-2xl font-bold"><span data-rt-approval-rejected><?= (int)$rejectedHistoryCount ?></span></div>
            </div>
        </div>

        <?php if ($currentRole === 'student'): ?>
            <section class="bg-white rounded-xl shadow p-5">
                <h2 class="font-bold text-lg mb-4"><i class="fas fa-paper-plane mr-2"></i>ยื่นคำขออนุมัติ</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left">โครงงาน</th>
                                <th class="px-4 py-3 text-center">ความคืบหน้า</th>
                                <th class="px-4 py-3 text-left">อาจารย์ที่ปรึกษา</th>
                                <th class="px-4 py-3 text-center">สถานะคำขอ</th>
                                <th class="px-4 py-3 text-center">การดำเนินการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($studentProjects)): ?>
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-gray-500">ยังไม่มีโครงงานของคุณ</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($studentProjects as $row): ?>
                                    <?php
                                    $requestStatus = (string)($row['request_status'] ?? '');
                                    $canSubmit = ((int)$row['progress'] >= 100)
                                        && (!empty($row['advisor_name']) || !empty($row['co_advisor_name']))
                                        && ($requestStatus !== 'pending')
                                        && ($requestStatus !== 'approved');
                                    ?>
                                    <tr class="border-t">
                                        <td class="px-4 py-3">
                                            <div class="font-semibold"><?= htmlspecialchars((string)$row['name']) ?></div>
                                            <div class="text-xs text-gray-500">#<?= (int)$row['id'] ?></div>
                                        </td>
                                        <td class="px-4 py-3 text-center font-bold"><?= (int)$row['progress'] ?>%</td>
                                        <td class="px-4 py-3">
                                            <div><?= htmlspecialchars((string)($row['advisor_name'] ?: '-')) ?></div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars((string)($row['co_advisor_name'] ?: '-')) ?></div>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <?php if ($requestStatus === ''): ?>
                                                <span class="px-2 py-1 rounded-full bg-gray-100 text-gray-700 text-xs">ยังไม่เคยยื่น</span>
                                            <?php elseif ($requestStatus === 'pending'): ?>
                                                <span class="px-2 py-1 rounded-full bg-amber-100 text-amber-800 text-xs">
                                                    รออนุมัติ (<?= htmlspecialchars(approvalStepLabel((int)$row['current_step'])) ?>)
                                                </span>
                                            <?php elseif ($requestStatus === 'approved'): ?>
                                                <span class="px-2 py-1 rounded-full bg-emerald-100 text-emerald-800 text-xs">อนุมัติแล้ว</span>
                                            <?php elseif ($requestStatus === 'rejected'): ?>
                                                <span class="px-2 py-1 rounded-full bg-rose-100 text-rose-800 text-xs">ตีกลับ</span>
                                            <?php else: ?>
                                                <span class="px-2 py-1 rounded-full bg-gray-100 text-gray-700 text-xs"><?= htmlspecialchars(approvalStatusText($requestStatus)) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <?php if ($canSubmit): ?>
                                                <form method="POST" class="inline-block">
                                                    <?= csrfInputField() ?>
                                                    <input type="hidden" name="action" value="submit_request">
                                                    <input type="hidden" name="project_id" value="<?= (int)$row['id'] ?>">
                                                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-xs font-semibold">
                                                        <?= $requestStatus === 'rejected' ? 'ยื่นขออนุมัติใหม่' : 'ยื่นขออนุมัติ' ?>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-xs text-gray-500">
                                                    <?php if ((int)$row['progress'] < 100): ?>
                                                        ต้องมีความคืบหน้า 100%
                                                    <?php elseif (empty($row['advisor_name']) && empty($row['co_advisor_name'])): ?>
                                                        ต้องมีอาจารย์ที่ปรึกษา
                                                    <?php elseif ($requestStatus === 'pending'): ?>
                                                        อยู่ระหว่างพิจารณา
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if (in_array($currentRole, ['teacher', 'admin'], true)): ?>
            <section class="bg-white rounded-xl shadow p-5">
                <h2 class="font-bold text-lg mb-4"><i class="fas fa-clipboard-check mr-2"></i>คิวคำขอที่ต้องพิจารณา</h2>
                <?php if (empty($approvalQueue)): ?>
                    <div class="text-center text-gray-500 py-8">ไม่มีคำขอที่รอการอนุมัติในคิวของคุณ</div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach ($approvalQueue as $q): ?>
                            <div class="border rounded-lg p-4 bg-gray-50">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <div class="font-semibold"><?= htmlspecialchars((string)$q['project_name']) ?></div>
                                        <div class="text-xs text-gray-600 mt-1">นักศึกษา: <?= htmlspecialchars((string)$q['student_name']) ?></div>
                                        <div class="text-xs text-gray-600">ผู้ยื่น: <?= htmlspecialchars((string)$q['submitted_by_name']) ?></div>
                                        <div class="text-xs text-gray-600">เมื่อ: <?= htmlspecialchars((string)$q['submitted_at']) ?></div>
                                    </div>
                                    <span class="px-2 py-1 rounded-full bg-amber-100 text-amber-800 text-xs">
                                        <?= htmlspecialchars(approvalStepLabel((int)$q['current_step'])) ?>
                                    </span>
                                </div>

                                <form method="POST" class="mt-3 space-y-2">
                                    <?= csrfInputField() ?>
                                    <input type="hidden" name="action" value="review_request">
                                    <input type="hidden" name="request_id" value="<?= (int)$q['request_id'] ?>">
                                    <textarea name="note" rows="2" class="w-full border rounded p-2 text-sm" placeholder="หมายเหตุ (ถ้ามี)"></textarea>
                                    <div class="flex gap-2">
                                        <button type="submit" name="decision" value="approve" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white py-2 rounded text-sm font-semibold">
                                            <i class="fas fa-check mr-1"></i> อนุมัติ
                                        </button>
                                        <button type="submit" name="decision" value="reject" class="flex-1 bg-rose-600 hover:bg-rose-700 text-white py-2 rounded text-sm font-semibold">
                                            <i class="fas fa-rotate-left mr-1"></i> ตีกลับ
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="bg-white rounded-xl shadow p-5">
            <h2 class="font-bold text-lg mb-4"><i class="fas fa-history mr-2"></i>ประวัติคำขออนุมัติ (<span data-rt-approval-history><?= (int)$historyCount ?></span>)</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left">เวลา</th>
                            <th class="px-4 py-3 text-left">โครงงาน</th>
                            <th class="px-4 py-3 text-left">ผู้ยื่น</th>
                            <th class="px-4 py-3 text-center">สถานะ</th>
                            <th class="px-4 py-3 text-left">ผู้ดำเนินการล่าสุด</th>
                            <th class="px-4 py-3 text-left">หมายเหตุ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($historyRows)): ?>
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-gray-500">ยังไม่มีประวัติคำขออนุมัติ</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($historyRows as $h): ?>
                                <?php
                                $badgeClass = 'bg-gray-100 text-gray-700';
                                if ((string)$h['request_status'] === 'pending') {
                                    $badgeClass = 'bg-amber-100 text-amber-800';
                                } elseif ((string)$h['request_status'] === 'approved') {
                                    $badgeClass = 'bg-emerald-100 text-emerald-800';
                                } elseif ((string)$h['request_status'] === 'rejected') {
                                    $badgeClass = 'bg-rose-100 text-rose-800';
                                }
                                ?>
                                <tr class="border-t">
                                    <td class="px-4 py-3 whitespace-nowrap"><?= htmlspecialchars((string)$h['submitted_at']) ?></td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium"><?= htmlspecialchars((string)$h['project_name']) ?></div>
                                        <div class="text-xs text-gray-500">#<?= (int)$h['project_id'] ?></div>
                                    </td>
                                    <td class="px-4 py-3"><?= htmlspecialchars((string)$h['submitted_by_name']) ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="px-2 py-1 rounded-full text-xs <?= $badgeClass ?>">
                                            <?= htmlspecialchars(approvalStatusText((string)$h['request_status'])) ?>
                                        </span>
                                        <?php if ((string)$h['request_status'] === 'pending'): ?>
                                            <div class="text-[11px] text-gray-500 mt-1"><?= htmlspecialchars(approvalStepLabel((int)$h['current_step'])) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3"><?= htmlspecialchars((string)($h['last_actor_name'] ?? '-')) ?></td>
                                    <td class="px-4 py-3"><?= htmlspecialchars((string)($h['last_action_note'] ?? '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <script>
        const params = new URLSearchParams(window.location.search);
        const status = params.get('status');
        const clearStatus = () => window.history.replaceState({}, document.title, window.location.pathname);

        const map = {
            submitted: { icon: 'success', title: 'ส่งคำขออนุมัติเรียบร้อย' },
            approved: { icon: 'success', title: 'อนุมัติคำขอเรียบร้อย' },
            rejected: { icon: 'success', title: 'ตีกลับคำขอเรียบร้อย' },
            progress_not_ready: { icon: 'error', title: 'ยังยื่นไม่ได้', text: 'โครงงานต้องมีความคืบหน้า 100% ก่อนยื่นคำขอ' },
            advisor_missing: { icon: 'error', title: 'ยังยื่นไม่ได้', text: 'ต้องมีอาจารย์ที่ปรึกษาอย่างน้อย 1 ท่าน' },
            pending_exists: { icon: 'warning', title: 'มีคำขอที่กำลังรออยู่แล้ว' },
            permission_denied: { icon: 'error', title: 'คุณไม่มีสิทธิ์ทำรายการนี้' },
            request_not_pending: { icon: 'warning', title: 'คำขอนี้ถูกดำเนินการไปแล้ว' },
            csrf_invalid: { icon: 'error', title: 'คำขอไม่ถูกต้อง', text: 'กรุณาลองใหม่อีกครั้ง' }
        };

        const updateTextMany = (selector, value) => {
            document.querySelectorAll(selector).forEach((el) => {
                el.textContent = String(value);
            });
        };

        const applyRealtimeApproval = (data) => {
            const approval = data && data.approval ? data.approval : {};
            updateTextMany('[data-rt-approval-queue]', Number(approval.queue ?? 0));
            updateTextMany('[data-rt-approval-pending]', Number(approval.pending ?? 0));
            updateTextMany('[data-rt-approval-approved]', Number(approval.approved ?? 0));
            updateTextMany('[data-rt-approval-rejected]', Number(approval.rejected ?? 0));
            updateTextMany('[data-rt-approval-history]', Number(approval.history ?? 0));
        };

        let realtimeTimer = null;
        const pollRealtime = async () => {
            try {
                const res = await fetch(`realtime_status.php?scope=approval&t=${Date.now()}`, {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store'
                });
                if (!res.ok) return;
                const json = await res.json();
                if (!json || !json.ok || !json.data) return;
                applyRealtimeApproval(json.data);
            } catch (error) {
                // Keep center usable when realtime endpoint is temporarily unavailable.
            }
        };

        pollRealtime();
        realtimeTimer = setInterval(pollRealtime, 15000);
        window.addEventListener('beforeunload', () => {
            if (realtimeTimer) {
                clearInterval(realtimeTimer);
            }
        });

        if (status && map[status]) {
            Swal.fire({
                icon: map[status].icon,
                title: map[status].title,
                text: map[status].text || '',
                timer: map[status].text ? undefined : 1700,
                showConfirmButton: !!map[status].text
            }).then(clearStatus);
        }
    </script>
</body>
</html>
