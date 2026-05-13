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
    ensureValidCsrfOrRedirect('proposal_center.php');
}

$tenantContext = tenantRuntimeContext();
$tenantLabel = trim((string)($tenantContext['tenant_name'] ?? ''));
if ($tenantLabel === '') {
    $tenantLabel = 'ค่าเริ่มต้น';
}
$tenantCode = trim((string)($tenantContext['tenant_code'] ?? ''));

$projectSql = "
    SELECT p.id, p.name, p.student_id, p.advisor_id, p.co_advisor_id, p.proposal_status, u.fullname AS leader_name
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

$canEditProposal = false;
$canReviewProposal = false;
if ($selectedProject) {
    if ($role === 'admin') {
        $canEditProposal = true;
        $canReviewProposal = true;
    } elseif ($role === 'student') {
        $canEditProposal = (int)$selectedProject['student_id'] === $userId;
    } elseif ($role === 'teacher') {
        $canReviewProposal = ((int)$selectedProject['advisor_id'] === $userId || (int)$selectedProject['co_advisor_id'] === $userId);
    }
}

$redirectToProject = static function (int $projectId, string $status): void {
    $url = 'proposal_center.php';
    if ($projectId > 0) {
        $url = appendQueryParam($url, 'project_id', (string)$projectId);
    }
    $url = appendQueryParam($url, 'status', $status);
    header('Location: ' . $url);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedProjectId > 0) {
    $action = (string)($_POST['action'] ?? '');
    $existingProposal = getProposalByProjectId($conn, $selectedProjectId);

    if ($action === 'save_draft') {
        if (!$canEditProposal) {
            $redirectToProject($selectedProjectId, 'permission_denied');
        }

        $title = trim((string)($_POST['title'] ?? ''));
        $objective = trim((string)($_POST['objective'] ?? ''));
        $summary = trim((string)($_POST['summary'] ?? ''));
        $programCode = trim((string)($_POST['program_code'] ?? 'GENERIC-BIT'));
        $semesterLabel = trim((string)($_POST['semester_label'] ?? '1/2569'));
        if ($title === '') {
            $redirectToProject($selectedProjectId, 'title_required');
        }

        $conn->beginTransaction();
        try {
            if (!$existingProposal) {
                $insert = $conn->prepare("
                    INSERT INTO proposals (
                        project_id, submitted_by, current_version_no, status, program_code, semester_label,
                        title, objective, summary, created_at, updated_at
                    )
                    VALUES (?, ?, 1, 'draft', ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $insert->execute([
                    $selectedProjectId,
                    $userId,
                    $programCode,
                    $semesterLabel,
                    $title,
                    $objective,
                    $summary,
                ]);
                $proposalId = (int)$conn->lastInsertId();
                createProposalVersionSnapshot($conn, $proposalId, 1, $title, $objective, $summary, 'draft', $userId);
                syncProposalStatusToProject($conn, $selectedProjectId, 'draft', $programCode, $semesterLabel);
            } else {
                $nextVersion = max(1, (int)$existingProposal['current_version_no'] + 1);
                $update = $conn->prepare("
                    UPDATE proposals
                    SET current_version_no = ?, status = 'draft', program_code = ?, semester_label = ?,
                        title = ?, objective = ?, summary = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $update->execute([
                    $nextVersion,
                    $programCode,
                    $semesterLabel,
                    $title,
                    $objective,
                    $summary,
                    (int)$existingProposal['id'],
                ]);
                createProposalVersionSnapshot($conn, (int)$existingProposal['id'], $nextVersion, $title, $objective, $summary, 'draft', $userId);
                syncProposalStatusToProject($conn, $selectedProjectId, 'draft', $programCode, $semesterLabel);
            }

            writeAuditLog($conn, $userId, 'proposal.draft.save', 'Saved proposal draft', 'project', $selectedProjectId);
            $conn->commit();
            $redirectToProject($selectedProjectId, 'draft_saved');
        } catch (Throwable $e) {
            $conn->rollBack();
            $redirectToProject($selectedProjectId, 'save_failed');
        }
    }

    if ($action === 'submit_proposal') {
        if (!$canEditProposal) {
            $redirectToProject($selectedProjectId, 'permission_denied');
        }
        if (!$existingProposal) {
            $redirectToProject($selectedProjectId, 'draft_required');
        }

        $proposalId = (int)$existingProposal['id'];
        $committeeId = ensureProposalCommittee($conn, $proposalId, $userId, 2);
        $quorumOk = proposalCommitteeHasQuorum($conn, $proposalId);
        $newStatus = $quorumOk ? 'submitted' : 'in_review';

        $update = $conn->prepare("
            UPDATE proposals
            SET status = ?, submitted_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $update->execute([$newStatus, $proposalId]);

        createProposalVersionSnapshot(
            $conn,
            $proposalId,
            (int)$existingProposal['current_version_no'],
            (string)$existingProposal['title'],
            (string)($existingProposal['objective'] ?? ''),
            (string)($existingProposal['summary'] ?? ''),
            $newStatus,
            $userId
        );
        syncProposalStatusToProject(
            $conn,
            $selectedProjectId,
            $newStatus,
            (string)($existingProposal['program_code'] ?? 'GENERIC-BIT'),
            (string)($existingProposal['semester_label'] ?? '1/2569')
        );

        $committeeMemberStmt = $conn->prepare("
            SELECT pcm.user_id
            FROM proposal_committee_members pcm
            WHERE pcm.committee_id = ?
        ");
        $committeeMemberStmt->execute([$committeeId]);
        $committeeUsers = array_map('intval', $committeeMemberStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        createNotificationForUsers(
            $conn,
            $committeeUsers,
            'ส่งข้อเสนอโครงงานแล้ว',
            'มีข้อเสนอที่รอการพิจารณาจากคุณ',
            'info',
            $selectedProjectId
        );
        writeAuditLog($conn, $userId, 'proposal.submit', 'Submitted proposal for review', 'project', $selectedProjectId);

        $redirectToProject($selectedProjectId, $quorumOk ? 'submitted' : 'submitted_no_quorum');
    }

    if ($action === 'review_proposal') {
        if (!$canReviewProposal && $role !== 'admin') {
            $redirectToProject($selectedProjectId, 'permission_denied');
        }
        if (!$existingProposal) {
            $redirectToProject($selectedProjectId, 'proposal_not_found');
        }

        $reviewAction = (string)($_POST['review_action'] ?? 'comment');
        if (!in_array($reviewAction, ['comment', 'approved', 'rejected', 'revise_required'], true)) {
            $reviewAction = 'comment';
        }
        $note = trim((string)($_POST['review_note'] ?? ''));

        $proposalId = (int)$existingProposal['id'];
        $insertReview = $conn->prepare("
            INSERT INTO proposal_reviews (proposal_id, reviewer_id, action_key, note, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $insertReview->execute([$proposalId, $userId, $reviewAction, $note]);

        $newStatus = 'in_review';
        if ($reviewAction === 'approved') {
            $votes = countCommitteeVotes($conn, $proposalId);
            $quorumReady = proposalCommitteeHasQuorum($conn, $proposalId);
            $committeeMetaStmt = $conn->prepare("SELECT quorum_min FROM proposal_committees WHERE proposal_id = ? LIMIT 1");
            $committeeMetaStmt->execute([$proposalId]);
            $quorumMin = (int)$committeeMetaStmt->fetchColumn();
            if ($quorumMin <= 0) {
                $quorumMin = 2;
            }
            if ($quorumReady && $votes['approve'] >= $quorumMin) {
                $newStatus = 'approved';
            } else {
                $newStatus = 'in_review';
            }
        } elseif ($reviewAction === 'rejected') {
            $newStatus = 'rejected';
        } elseif ($reviewAction === 'revise_required') {
            $newStatus = 'revise_required';
        }

        $updateProposal = $conn->prepare("
            UPDATE proposals
            SET status = ?, last_decision_note = ?, decided_at = CASE WHEN ? IN ('approved', 'rejected') THEN NOW() ELSE decided_at END, updated_at = NOW()
            WHERE id = ?
        ");
        $updateProposal->execute([$newStatus, $note, $newStatus, $proposalId]);

        syncProposalStatusToProject(
            $conn,
            $selectedProjectId,
            $newStatus,
            (string)($existingProposal['program_code'] ?? 'GENERIC-BIT'),
            (string)($existingProposal['semester_label'] ?? '1/2569')
        );

        $participants = getProjectParticipantUserIds($conn, $selectedProjectId);
        createNotificationForUsers(
            $conn,
            $participants,
            'อัปเดตผลการรีวิวข้อเสนอ',
            'Proposal status changed to: ' . strtoupper($newStatus),
            $newStatus === 'approved' ? 'success' : ($newStatus === 'rejected' ? 'error' : 'warning'),
            $selectedProjectId
        );
        writeAuditLog($conn, $userId, 'proposal.review.' . $reviewAction, 'Proposal review action: ' . $reviewAction, 'project', $selectedProjectId);

        $redirectToProject($selectedProjectId, 'review_saved');
    }
}

$proposal = null;
$versions = [];
$reviews = [];
$committee = null;
$committeeMembers = [];
$votes = ['approve' => 0, 'reject' => 0, 'revise' => 0];

if ($selectedProjectId > 0) {
    $proposal = getProposalByProjectId($conn, $selectedProjectId);
    if ($proposal) {
        $proposalId = (int)$proposal['id'];

        $versionsStmt = $conn->prepare("
            SELECT pv.*, u.fullname AS changed_by_name
            FROM proposal_versions pv
            LEFT JOIN users u ON u.id = pv.changed_by
            WHERE pv.proposal_id = ?
            ORDER BY pv.version_no DESC
        ");
        $versionsStmt->execute([$proposalId]);
        $versions = $versionsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $reviewsStmt = $conn->prepare("
            SELECT pr.*, u.fullname AS reviewer_name
            FROM proposal_reviews pr
            LEFT JOIN users u ON u.id = pr.reviewer_id
            WHERE pr.proposal_id = ?
            ORDER BY pr.created_at DESC, pr.id DESC
        ");
        $reviewsStmt->execute([$proposalId]);
        $reviews = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $committeeStmt = $conn->prepare("SELECT * FROM proposal_committees WHERE proposal_id = ? LIMIT 1");
        $committeeStmt->execute([$proposalId]);
        $committee = $committeeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($committee) {
            $committeeMemberStmt = $conn->prepare("
                SELECT pcm.*, u.fullname, u.email
                FROM proposal_committee_members pcm
                LEFT JOIN users u ON u.id = pcm.user_id
                WHERE pcm.committee_id = ?
                ORDER BY FIELD(pcm.role_key, 'chair', 'advisor', 'reviewer'), pcm.id ASC
            ");
            $committeeMemberStmt->execute([(int)$committee['id']]);
            $committeeMembers = $committeeMemberStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $votes = countCommitteeVotes($conn, $proposalId);
    }
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
$committeeStatusLabel = static function (string $status): string {
    $map = [
        'draft' => 'ร่าง',
        'active' => 'ใช้งาน',
        'closed' => 'ปิดงาน',
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
$reviewActionLabel = static function (string $action): string {
    $map = [
        'comment' => 'แสดงความเห็น',
        'approved' => 'อนุมัติ',
        'rejected' => 'ไม่อนุมัติ',
        'revise_required' => 'ขอแก้ไข',
    ];
    return $map[$action] ?? $action;
};
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ศูนย์ข้อเสนอโครงงาน</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="assets/js/rmutp-ui.js"></script>
</head>
<body class="bg-slate-100 text-slate-900">
    <div class="max-w-6xl mx-auto px-4 py-6">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <div>
                <h1 class="text-2xl font-bold">ศูนย์ข้อเสนอโครงงาน</h1>
                <p class="text-sm text-slate-600">
                    ผู้ใช้: <?= htmlspecialchars($fullname) ?> (<?= htmlspecialchars($roleLabel($role)) ?>)
                    | เทนเนนต์: <?= htmlspecialchars($tenantLabel) ?><?= $tenantCode !== '' ? ' [' . htmlspecialchars($tenantCode) . ']' : '' ?>
                </p>
            </div>
            <div class="flex gap-2">
                <a href="milestone_board.php<?= $selectedProjectId > 0 ? '?project_id=' . (int)$selectedProjectId : '' ?>" class="px-3 py-2 rounded bg-indigo-600 text-white text-sm">กระดานไมล์สโตน</a>
                <a href="committee_assignment.php<?= $selectedProjectId > 0 ? '?project_id=' . (int)$selectedProjectId : '' ?>" class="px-3 py-2 rounded bg-cyan-700 text-white text-sm">จัดสรรกรรมการ</a>
                <a href="<?= $role === 'admin' ? 'admin_dashboard.php' : ($role === 'teacher' ? 'teacher_dashboard.php' : 'student_dashboard.php') ?>" class="px-3 py-2 rounded bg-slate-700 text-white text-sm">กลับ</a>
            </div>
        </div>

        <?php if ($status !== ''): ?>
            <div class="mb-4 rounded border border-slate-300 bg-white px-4 py-3 text-sm">
                สถานะ: <?= htmlspecialchars($status) ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow p-4 mb-4">
            <form method="GET" class="flex flex-wrap gap-2 items-end">
                <div class="min-w-[260px]">
                    <label class="block text-sm font-medium mb-1">โครงงาน</label>
                    <select name="project_id" class="w-full border rounded px-3 py-2">
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $selectedProjectId ? 'selected' : '' ?>>
                                #<?= (int)$p['id'] ?> <?= htmlspecialchars((string)$p['name']) ?> (<?= htmlspecialchars($proposalStatusLabel((string)$p['proposal_status'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="px-3 py-2 rounded bg-slate-800 text-white text-sm">โหลด</button>
            </form>
        </div>

        <?php if (!$selectedProject): ?>
            <div class="bg-white rounded-xl shadow p-6 text-sm text-slate-600">
                ไม่พบโครงงานที่ผู้ใช้นี้เข้าถึงได้
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="lg:col-span-2 space-y-4">
                    <div class="bg-white rounded-xl shadow p-4">
                        <h2 class="text-lg font-bold mb-2">แบบร่างข้อเสนอ</h2>
                        <form method="POST" class="space-y-3">
                            <?= csrfInputField() ?>
                            <input type="hidden" name="action" value="save_draft">
                            <div>
                                <label class="block text-sm font-medium mb-1">ชื่อข้อเสนอ</label>
                                <input type="text" name="title" value="<?= htmlspecialchars((string)($proposal['title'] ?? ('ข้อเสนอ: ' . (string)$selectedProject['name']))) ?>" class="w-full border rounded px-3 py-2" <?= $canEditProposal ? '' : 'readonly' ?>>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm font-medium mb-1">รหัสหลักสูตร</label>
                                    <input type="text" name="program_code" value="<?= htmlspecialchars((string)($proposal['program_code'] ?? 'GENERIC-BIT')) ?>" class="w-full border rounded px-3 py-2" <?= $canEditProposal ? '' : 'readonly' ?>>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1">ภาคการศึกษา</label>
                                    <input type="text" name="semester_label" value="<?= htmlspecialchars((string)($proposal['semester_label'] ?? '1/2569')) ?>" class="w-full border rounded px-3 py-2" <?= $canEditProposal ? '' : 'readonly' ?>>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">วัตถุประสงค์</label>
                                <textarea name="objective" rows="3" class="w-full border rounded px-3 py-2" <?= $canEditProposal ? '' : 'readonly' ?>><?= htmlspecialchars((string)($proposal['objective'] ?? '')) ?></textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">สรุป</label>
                                <textarea name="summary" rows="4" class="w-full border rounded px-3 py-2" <?= $canEditProposal ? '' : 'readonly' ?>><?= htmlspecialchars((string)($proposal['summary'] ?? '')) ?></textarea>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <?php if ($canEditProposal): ?>
                                    <button type="submit" class="px-3 py-2 rounded bg-blue-700 text-white text-sm">บันทึกแบบร่าง</button>
                                <?php endif; ?>
                            </div>
                        </form>

                        <?php if ($proposal && $canEditProposal): ?>
                            <form method="POST" class="mt-3">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="action" value="submit_proposal">
                                <button type="submit" class="px-3 py-2 rounded bg-emerald-700 text-white text-sm">ส่งข้อเสนอ</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if ($proposal): ?>
                        <div class="bg-white rounded-xl shadow p-4">
                            <h2 class="text-lg font-bold mb-2">การรีวิว</h2>
                            <?php if ($canReviewProposal || $role === 'admin'): ?>
                                <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-2 mb-4">
                                    <?= csrfInputField() ?>
                                    <input type="hidden" name="action" value="review_proposal">
                                    <select name="review_action" class="border rounded px-3 py-2 text-sm">
                                        <option value="comment">แสดงความเห็น</option>
                                        <option value="approved">อนุมัติ</option>
                                        <option value="revise_required">ขอแก้ไข</option>
                                        <option value="rejected">ไม่อนุมัติ</option>
                                    </select>
                                    <input type="text" name="review_note" placeholder="หมายเหตุการรีวิว" class="md:col-span-2 border rounded px-3 py-2 text-sm">
                                    <button type="submit" class="rounded bg-slate-800 text-white text-sm px-3 py-2">บันทึกรีวิว</button>
                                </form>
                            <?php endif; ?>

                            <div class="text-sm text-slate-700 mb-3">
                                ผลโหวต: อนุมัติ=<?= (int)$votes['approve'] ?>, ไม่อนุมัติ=<?= (int)$votes['reject'] ?>, ขอแก้ไข=<?= (int)$votes['revise'] ?>
                            </div>

                            <div class="space-y-2">
                                <?php foreach ($reviews as $review): ?>
                                    <div class="border rounded px-3 py-2 text-sm">
                                        <div class="font-medium">
                                            <?= htmlspecialchars((string)$review['reviewer_name']) ?> |
                                            <span class="uppercase"><?= htmlspecialchars($reviewActionLabel((string)$review['action_key'])) ?></span>
                                            | <?= htmlspecialchars((string)$review['created_at']) ?>
                                        </div>
                                        <div class="text-slate-600"><?= nl2br(htmlspecialchars((string)($review['note'] ?? ''))) ?></div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($reviews)): ?>
                                    <div class="text-sm text-slate-500">ยังไม่มีการรีวิว</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="space-y-4">
                    <div class="bg-white rounded-xl shadow p-4">
                        <h3 class="text-base font-bold mb-2">สถานะปัจจุบัน</h3>
                        <div class="text-sm text-slate-700">
                            <div>โครงงาน: #<?= (int)$selectedProject['id'] ?> <?= htmlspecialchars((string)$selectedProject['name']) ?></div>
                            <div>หัวหน้าทีม: <?= htmlspecialchars((string)$selectedProject['leader_name']) ?></div>
                            <div>สถานะข้อเสนอ: <span class="font-semibold"><?= htmlspecialchars($proposalStatusLabel((string)($proposal['status'] ?? 'not_started'))) ?></span></div>
                            <div>เวอร์ชัน: <?= (int)($proposal['current_version_no'] ?? 0) ?></div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow p-4">
                        <h3 class="text-base font-bold mb-2">คณะกรรมการ</h3>
                        <?php if ($committee): ?>
                            <div class="text-sm text-slate-700 mb-2">
                                <div>องค์ประชุมขั้นต่ำ: <?= (int)$committee['quorum_min'] ?></div>
                                <div>สถานะ: <?= htmlspecialchars($committeeStatusLabel((string)$committee['status'])) ?></div>
                            </div>
                            <div class="space-y-1 text-sm">
                                <?php foreach ($committeeMembers as $member): ?>
                                    <div class="border rounded px-2 py-1">
                                        <?= htmlspecialchars((string)$member['fullname']) ?>
                                        (<?= htmlspecialchars($committeeRoleLabel((string)$member['role_key'])) ?>)
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($committeeMembers)): ?>
                                    <div class="text-slate-500">ยังไม่มีสมาชิกกรรมการ</div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-sm text-slate-500">ยังไม่ได้สร้างชุดกรรมการ</div>
                        <?php endif; ?>
                    </div>

                    <div class="bg-white rounded-xl shadow p-4">
                        <h3 class="text-base font-bold mb-2">ประวัติเวอร์ชัน</h3>
                        <div class="space-y-2 text-sm">
                            <?php foreach ($versions as $version): ?>
                                <div class="border rounded px-2 py-1">
                                    v<?= (int)$version['version_no'] ?> | <?= htmlspecialchars((string)$version['status_snapshot']) ?><br>
                                    <span class="text-slate-500"><?= htmlspecialchars((string)$version['changed_by_name']) ?> @ <?= htmlspecialchars((string)$version['created_at']) ?></span>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($versions)): ?>
                                <div class="text-slate-500">ยังไม่มีประวัติเวอร์ชัน</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
