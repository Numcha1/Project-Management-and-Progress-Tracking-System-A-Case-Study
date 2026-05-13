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
csrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensureValidCsrfOrRedirect('committee_assignment.php');
}

$projectSql = "
    SELECT p.id, p.name, p.student_id, p.advisor_id, p.co_advisor_id, u.fullname AS leader_name
    FROM projects p
    INNER JOIN users u ON u.id = p.student_id
";
$projectParams = [];
if ($role === 'student') {
    $projectSql .= " WHERE p.student_id = ? ";
    $projectParams[] = $userId;
} elseif ($role === 'teacher') {
    $projectSql .= " WHERE p.advisor_id = ? OR p.co_advisor_id = ? ";
    $projectParams[] = $userId;
    $projectParams[] = $userId;
}
$projectSql .= " ORDER BY p.updated_at DESC, p.id DESC";

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

$proposal = $selectedProjectId > 0 ? getProposalByProjectId($conn, $selectedProjectId) : null;
$isLeader = $selectedProject && (int)$selectedProject['student_id'] === $userId;
$isAdvisor = $selectedProject && ((int)$selectedProject['advisor_id'] === $userId || (int)$selectedProject['co_advisor_id'] === $userId);
$isAdmin = $role === 'admin';
$canManageCommittee = (bool)($isAdmin || $isAdvisor || $isLeader);

$redirect = static function (int $projectId, string $status): void {
    $url = 'committee_assignment.php';
    if ($projectId > 0) {
        $url = appendQueryParam($url, 'project_id', (string)$projectId);
    }
    $url = appendQueryParam($url, 'status', $status);
    header('Location: ' . $url);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedProjectId > 0) {
    if (!$proposal) {
        $redirect($selectedProjectId, 'proposal_required');
    }
    if (!$canManageCommittee) {
        $redirect($selectedProjectId, 'permission_denied');
    }

    $proposalId = (int)$proposal['id'];
    $committeeId = ensureProposalCommittee($conn, $proposalId, $userId, 2);
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'set_quorum') {
        $quorumMin = max(1, (int)($_POST['quorum_min'] ?? 2));
        $stmt = $conn->prepare("UPDATE proposal_committees SET quorum_min = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$quorumMin, $committeeId]);
        writeAuditLog($conn, $userId, 'committee.quorum.update', 'Set quorum to ' . $quorumMin, 'project', $selectedProjectId);
        $redirect($selectedProjectId, 'quorum_updated');
    }

    if ($action === 'add_member') {
        $memberUserId = (int)($_POST['member_user_id'] ?? 0);
        $roleKey = (string)($_POST['role_key'] ?? 'reviewer');
        if (!in_array($roleKey, ['advisor', 'reviewer', 'chair'], true)) {
            $roleKey = 'reviewer';
        }
        if ($memberUserId <= 0) {
            $redirect($selectedProjectId, 'invalid_member');
        }

        $insert = $conn->prepare("
            INSERT INTO proposal_committee_members (committee_id, user_id, role_key, can_vote, created_at)
            VALUES (?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE role_key = VALUES(role_key), can_vote = VALUES(can_vote)
        ");
        $insert->execute([$committeeId, $memberUserId, $roleKey]);

        createNotification(
            $conn,
            $memberUserId,
            'มอบหมายกรรมการโครงงาน',
            'You have been assigned to a proposal committee as ' . $roleKey . '.',
            'info',
            $selectedProjectId
        );
        writeAuditLog($conn, $userId, 'committee.member.add', 'Added member #' . $memberUserId . ' as ' . $roleKey, 'project', $selectedProjectId);
        $redirect($selectedProjectId, 'member_added');
    }

    if ($action === 'remove_member') {
        $memberId = (int)($_POST['committee_member_id'] ?? 0);
        if ($memberId <= 0) {
            $redirect($selectedProjectId, 'invalid_member');
        }

        $delete = $conn->prepare("DELETE FROM proposal_committee_members WHERE id = ? AND committee_id = ?");
        $delete->execute([$memberId, $committeeId]);
        writeAuditLog($conn, $userId, 'committee.member.remove', 'Removed member row #' . $memberId, 'project', $selectedProjectId);
        $redirect($selectedProjectId, 'member_removed');
    }
}

$committee = null;
$committeeMembers = [];
$votes = ['approve' => 0, 'reject' => 0, 'revise' => 0];
$eligibleUsers = [];
if ($proposal) {
    $committeeStmt = $conn->prepare("SELECT * FROM proposal_committees WHERE proposal_id = ? LIMIT 1");
    $committeeStmt->execute([(int)$proposal['id']]);
    $committee = $committeeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($committee) {
        $membersStmt = $conn->prepare("
            SELECT pcm.*, u.fullname, u.email, u.role
            FROM proposal_committee_members pcm
            LEFT JOIN users u ON u.id = pcm.user_id
            WHERE pcm.committee_id = ?
            ORDER BY FIELD(pcm.role_key, 'chair', 'advisor', 'reviewer'), pcm.id ASC
        ");
        $membersStmt->execute([(int)$committee['id']]);
        $committeeMembers = $membersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $votes = countCommitteeVotes($conn, (int)$proposal['id']);
}

if ($canManageCommittee) {
    $eligibleStmt = $conn->query("
        SELECT id, fullname, email, role
        FROM users
        WHERE role IN ('teacher', 'admin')
        ORDER BY role ASC, fullname ASC
    ");
    $eligibleUsers = $eligibleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$status = (string)($_GET['status'] ?? '');
$tenantContext = tenantRuntimeContext();
$tenantLabel = trim((string)($tenantContext['tenant_name'] ?? 'ค่าเริ่มต้น'));
if ($tenantLabel === '') {
    $tenantLabel = 'ค่าเริ่มต้น';
}
$roleLabel = static function (string $role): string {
    $map = [
        'student' => 'นักศึกษา',
        'teacher' => 'อาจารย์',
        'admin' => 'ผู้ดูแลระบบ',
    ];
    return $map[$role] ?? $role;
};
$userRoleLabel = static function (string $role): string {
    $map = [
        'student' => 'นักศึกษา',
        'teacher' => 'อาจารย์',
        'admin' => 'ผู้ดูแลระบบ',
    ];
    return $map[$role] ?? $role;
};
$proposalStatusLabel = static function (string $status): string {
    $map = [
        'not_started' => 'ยังไม่เริ่ม',
        'draft' => 'แบบร่าง',
        'submitted' => 'ส่งพิจารณาแล้ว',
        'in_review' => 'อยู่ระหว่างรีวิว',
        'approved' => 'อนุมัติแล้ว',
        'rejected' => 'ไม่อนุมัติ',
        'revise_required' => 'ต้องแก้ไข',
    ];
    return $map[$status] ?? $status;
};
$committeeRoleLabel = static function (string $roleKey): string {
    $map = [
        'advisor' => 'อาจารย์ที่ปรึกษา',
        'reviewer' => 'ผู้รีวิว',
        'chair' => 'ประธานกรรมการ',
    ];
    return $map[$roleKey] ?? $roleKey;
};
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดสรรกรรมการ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="assets/js/rmutp-ui.js"></script>
</head>
<body class="bg-slate-100 text-slate-900">
    <div class="max-w-6xl mx-auto px-4 py-6">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <div>
                <h1 class="text-2xl font-bold">จัดสรรกรรมการ</h1>
                <p class="text-sm text-slate-600">ผู้ใช้: <?= htmlspecialchars($fullname) ?> (<?= htmlspecialchars($roleLabel($role)) ?>) | เทนเนนต์: <?= htmlspecialchars($tenantLabel) ?></p>
            </div>
            <div class="flex gap-2">
                <a href="proposal_center.php<?= $selectedProjectId > 0 ? '?project_id=' . (int)$selectedProjectId : '' ?>" class="px-3 py-2 rounded bg-indigo-600 text-white text-sm">ศูนย์ข้อเสนอโครงงาน</a>
                <a href="milestone_board.php<?= $selectedProjectId > 0 ? '?project_id=' . (int)$selectedProjectId : '' ?>" class="px-3 py-2 rounded bg-cyan-700 text-white text-sm">กระดานไมล์สโตน</a>
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
            <div class="bg-white rounded-xl shadow p-6 text-sm text-slate-600">ไม่พบโครงงาน</div>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="lg:col-span-2 space-y-4">
                    <div class="bg-white rounded-xl shadow p-4">
                        <h2 class="font-bold mb-2">ข้อมูลโครงงาน</h2>
                        <div class="text-sm text-slate-700">
                            <div>#<?= (int)$selectedProject['id'] ?> <?= htmlspecialchars((string)$selectedProject['name']) ?></div>
                            <div>หัวหน้าทีม: <?= htmlspecialchars((string)$selectedProject['leader_name']) ?></div>
                            <div>สถานะข้อเสนอ: <?= htmlspecialchars($proposalStatusLabel((string)($proposal['status'] ?? 'not_started'))) ?></div>
                        </div>
                    </div>

                    <?php if (!$proposal): ?>
                        <div class="bg-white rounded-xl shadow p-4 text-sm text-slate-600">
                            ยังไม่มีข้อเสนอของโครงงานนี้ กรุณาสร้างแบบร่างที่ศูนย์ข้อเสนอโครงงานก่อน
                        </div>
                    <?php else: ?>
                        <?php if ($canManageCommittee): ?>
                            <div class="bg-white rounded-xl shadow p-4 space-y-4">
                                <h2 class="font-bold">ตั้งค่ากรรมการ</h2>

                                <form method="POST" class="flex flex-wrap gap-2 items-end">
                                    <?= csrfInputField() ?>
                                    <input type="hidden" name="action" value="set_quorum">
                                    <div>
                                        <label class="block text-sm font-medium mb-1">จำนวนองค์ประชุมขั้นต่ำ</label>
                                        <input type="number" min="1" max="10" name="quorum_min" value="<?= (int)($committee['quorum_min'] ?? 2) ?>" class="border rounded px-3 py-2 text-sm w-28">
                                    </div>
                                    <button type="submit" class="px-3 py-2 rounded bg-blue-700 text-white text-sm">อัปเดตองค์ประชุม</button>
                                </form>

                                <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-2">
                                    <?= csrfInputField() ?>
                                    <input type="hidden" name="action" value="add_member">
                                    <select name="member_user_id" class="border rounded px-3 py-2 text-sm md:col-span-2">
                                        <?php foreach ($eligibleUsers as $user): ?>
                                            <option value="<?= (int)$user['id'] ?>">
                                                <?= htmlspecialchars((string)$user['fullname']) ?> (<?= htmlspecialchars((string)$user['role']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select name="role_key" class="border rounded px-3 py-2 text-sm">
                                        <option value="advisor">อาจารย์ที่ปรึกษา</option>
                                        <option value="reviewer">ผู้รีวิว</option>
                                        <option value="chair">ประธานกรรมการ</option>
                                    </select>
                                    <button type="submit" class="rounded bg-emerald-700 text-white text-sm px-3 py-2">เพิ่มสมาชิก</button>
                                </form>
                            </div>
                        <?php endif; ?>

                        <div class="bg-white rounded-xl shadow p-4">
                            <h2 class="font-bold mb-2">รายชื่อกรรมการ</h2>
                            <div class="text-sm text-slate-700 mb-3">
                                องค์ประชุมขั้นต่ำ: <?= (int)($committee['quorum_min'] ?? 2) ?> |
                                ผลโหวต: อนุมัติ=<?= (int)$votes['approve'] ?>, ไม่อนุมัติ=<?= (int)$votes['reject'] ?>, ขอแก้ไข=<?= (int)$votes['revise'] ?>
                            </div>
                            <div class="space-y-2">
                                <?php foreach ($committeeMembers as $member): ?>
                                    <div class="border rounded px-3 py-2 flex flex-wrap items-center justify-between gap-2">
                                        <div class="text-sm">
                                            <strong><?= htmlspecialchars((string)$member['fullname']) ?></strong>
                                            (<?= htmlspecialchars($userRoleLabel((string)$member['role'])) ?> / <?= htmlspecialchars($committeeRoleLabel((string)$member['role_key'])) ?>)
                                            <div class="text-xs text-slate-500"><?= htmlspecialchars((string)$member['email']) ?></div>
                                        </div>
                                        <?php if ($canManageCommittee): ?>
                                            <form method="POST">
                                                <?= csrfInputField() ?>
                                                <input type="hidden" name="action" value="remove_member">
                                                <input type="hidden" name="committee_member_id" value="<?= (int)$member['id'] ?>">
                                                <button type="submit" class="px-2 py-1 rounded bg-rose-700 text-white text-xs">ลบ</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($committeeMembers)): ?>
                                    <div class="text-sm text-slate-500">ยังไม่มีกรรมการ</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="space-y-4">
                    <div class="bg-white rounded-xl shadow p-4">
                        <h3 class="font-bold mb-2">สิทธิ์การเข้าถึง</h3>
                        <div class="text-sm text-slate-700">
                            <div>จัดการได้: <?= $canManageCommittee ? 'ใช่' : 'ไม่ใช่' ?></div>
                            <div>บทบาทปัจจุบัน: <?= htmlspecialchars($role) ?></div>
                            <div>เป็นหัวหน้าทีม: <?= $isLeader ? 'ใช่' : 'ไม่ใช่' ?></div>
                            <div>เป็นอาจารย์ที่ปรึกษา: <?= $isAdvisor ? 'ใช่' : 'ไม่ใช่' ?></div>
                            <div>เป็นผู้ดูแลระบบ: <?= $isAdmin ? 'ใช่' : 'ไม่ใช่' ?></div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
