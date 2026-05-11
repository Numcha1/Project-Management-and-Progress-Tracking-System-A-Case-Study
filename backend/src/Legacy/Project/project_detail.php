<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();
require_once __DIR__ . '/../System/db_connect.php';
require_once __DIR__ . '/../System/app_helpers.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['role'] ?? 'student'; // เก็บ Role เพื่อใช้เช็คสิทธิ์ลบคอมเมนต์
$project_id = (int)($_GET['id'] ?? 0);
$msg = '';

// กำหนดหน้าที่จะกลับ (Back URL) ตามสิทธิ์ของผู้ใช้
$back_url = 'student_dashboard.php'; 
if ($user_role == 'teacher') {
    $back_url = 'teacher_dashboard.php';
} elseif ($user_role == 'admin') {
    $back_url = 'admin_dashboard.php';
}

// Fetch project data
$stmt = $conn->prepare("
    SELECT p.*, 
           u.fullname as leader_name, 
           t1.fullname as advisor_name,
           t2.fullname as co_advisor_name,
           (SELECT fullname FROM users WHERE id = p.pending_advisor_id) as pending_advisor_name,
           (SELECT fullname FROM users WHERE id = p.pending_co_advisor_id) as pending_co_advisor_name
    FROM projects p
    LEFT JOIN users u ON p.student_id = u.id
    LEFT JOIN users t1 ON p.advisor_id = t1.id
    LEFT JOIN users t2 ON p.co_advisor_id = t2.id
    WHERE p.id = ?
");
$stmt->execute([$project_id]);
$project = $stmt->fetch();

if (!$project) die("ไม่พบโครงงาน");

// --- Check Permissions ---
$is_leader = ($project['student_id'] == $user_id);
$is_main_advisor = ($project['advisor_id'] == $user_id);
$is_co_advisor = ($project['co_advisor_id'] == $user_id);
$is_advisor_team = ($is_main_advisor || $is_co_advisor);

// Check if member
$memberCheckStmt = $conn->prepare("SELECT COUNT(*) FROM project_members WHERE project_id = ? AND user_id = ? AND status = 'accepted'");
$memberCheckStmt->execute([$project_id, $user_id]);
$is_member = ((int)$memberCheckStmt->fetchColumn()) > 0;

$can_view = ($user_role == 'admin' || $is_leader || $is_member || $is_advisor_team);
if (!$can_view && $user_role === 'teacher' && (int)$project['progress'] === 100) {
    $can_view = true;
}
$can_edit = ($is_leader); 
$has_primary_advisor = (!empty($project['advisor_id']) || !empty($project['pending_advisor_id']));
$script_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$upload_web_base = preg_match('#/frontend/public$#', $script_dir)
    ? $script_dir . '/uploads'
    : $script_dir . '/frontend/public/uploads';

if (!defined('MAX_TASKS_PER_PROJECT')) {
    define('MAX_TASKS_PER_PROJECT', getMaxTasksPerProject($conn));
}

if (!function_exists('recalculateProjectProgressByApprovedTasks')) {
    function recalculateProjectProgressByApprovedTasks(PDO $conn, int $projectId): int
    {
        if (function_exists('recalculateProjectProgress')) {
            return recalculateProjectProgress($conn, $projectId);
        }

        $done_stmt = $conn->prepare("SELECT COUNT(*) FROM tasks WHERE project_id = ? AND teacher_status = 'approved'");
        $done_stmt->execute([$projectId]);
        $approved_count = min((int)$done_stmt->fetchColumn(), MAX_TASKS_PER_PROJECT);
        $pct = (int)round(($approved_count / MAX_TASKS_PER_PROJECT) * 100);
        $conn->prepare("UPDATE projects SET progress = ? WHERE id = ?")->execute([$pct, $projectId]);
        return $pct;
    }
}

if (!function_exists('saveUploadedReturnAttachment')) {
    function saveUploadedReturnAttachment(array $file): ?string
    {
        if (empty($file['name'])) {
            return null;
        }

        $target_dir = __DIR__ . '/../../../../frontend/public/uploads/';
        $errorCode = null;
        $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'zip', 'rar', '7z'];
        return storeUploadedFileSecure($file, $target_dir, 'return', $allowed, 10 * 1024 * 1024, $errorCode);
    }
}

if (!$can_view) die("<div class='text-center p-10 text-red-500 font-bold'>⛔ คุณไม่มีสิทธิ์เข้าถึงโครงงานนี้</div>");

csrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensureValidCsrfOrRedirect('project_detail.php?id=' . (int)$project_id);
}


// --- 🗑️ ACTION: Delete Comment ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_comment') {
    $cmt_id = (int)($_POST['comment_id'] ?? 0);
    
    // ตรวจสอบว่าเป็นเจ้าของคอมเมนต์ หรือเป็น Admin/Teacher
    $chk = $conn->prepare("SELECT user_id FROM task_comments WHERE id=?");
    $chk->execute([$cmt_id]);
    $owner_id = $chk->fetchColumn();

    if ($owner_id == $user_id || $user_role == 'admin' || $user_role == 'teacher') {
        $conn->prepare("DELETE FROM task_comments WHERE id=?")->execute([$cmt_id]);
        header("Location: project_detail.php?id=$project_id&status=comment_deleted"); 
        exit;
    }
}

// --- ACTION: Update Project Details ---
if ($can_edit && isset($_POST['update_project_details'])) {
    $new_description = trim($_POST['description'] ?? '');
    $new_case_study = trim($_POST['case_study'] ?? '');

    $conn->prepare("UPDATE projects SET description = ?, case_study = ? WHERE id = ? AND student_id = ?")
         ->execute([$new_description, $new_case_study, $project_id, $user_id]);

    header("Location: project_detail.php?id=$project_id&status=project_updated");
    exit;
}

// --- ACTION: Add Task ---
if ($can_edit && isset($_POST['add_task'])) {
    if (!$has_primary_advisor) {
        header("Location: project_detail.php?id=$project_id&status=advisor_required_for_task");
        exit;
    }

    $task_count_stmt = $conn->prepare("SELECT COUNT(*) FROM tasks WHERE project_id = ?");
    $task_count_stmt->execute([$project_id]);
    $current_task_count = (int)$task_count_stmt->fetchColumn();
    if ($current_task_count >= MAX_TASKS_PER_PROJECT) {
        header("Location: project_detail.php?id=$project_id&status=task_limit_reached");
        exit;
    }

    $conn->prepare("INSERT INTO tasks (project_id, name, assignee_name, due_date, status) VALUES (?, ?, ?, ?, 'todo')")->execute([$project_id, $_POST['task_name'], $_POST['assignee'], $_POST['due_date']]);
    header("Location: project_detail.php?id=$project_id&status=task_added");
    exit;
}

// --- ACTION: Delete Task (Leader only) ---
if ($can_edit && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_task') {
    $task_id_to_delete = (int)($_POST['task_id'] ?? 0);
    $conn->prepare("DELETE FROM tasks WHERE id = ? AND project_id = ?")->execute([$task_id_to_delete, $project_id]);
    recalculateProjectProgressByApprovedTasks($conn, (int)$project_id);

    header("Location: project_detail.php?id=$project_id&status=task_deleted");
    exit;
}

// --- ACTION: Remove Member ---
if ($can_edit && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_member') {
    $member_id_to_remove = (int)($_POST['member_id'] ?? 0);
    $conn->prepare("DELETE FROM project_members WHERE user_id = ? AND project_id = ?")->execute([$member_id_to_remove, $project_id]);
    header("Location: project_detail.php?id=$project_id");
    exit;
}

// --- ACTION: Cancel Advisor Invitation ---
if ($can_edit && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_advisor_invite') {
    $type = (string)($_POST['type'] ?? ''); 
    if ($type == 'main') {
        $conn->prepare("UPDATE projects SET pending_advisor_id = NULL WHERE id = ?")->execute([$project_id]);
    } else {
        $conn->prepare("UPDATE projects SET pending_co_advisor_id = NULL WHERE id = ?")->execute([$project_id]);
    }
    header("Location: project_detail.php?id=$project_id&status=invite_cancelled");
    exit;
}

// --- ACTION: Invite Friend ---
if ($is_leader && isset($_POST['invite_member'])) {
    $student_code = trim($_POST['student_code'] ?? '');
    if ($student_code === '') {
        header("Location: project_detail.php?id=$project_id&status=student_code_required");
        exit;
    }

    $u = $conn->prepare("SELECT id FROM users WHERE student_code=? AND role='student' LIMIT 1");
    $u->execute([$student_code]);
    $target = $u->fetch();

    if($target) {
        if($target['id'] == $user_id) {
            header("Location: project_detail.php?id=$project_id&status=invite_self"); exit;
        }
        $chk = $conn->prepare("SELECT status FROM project_members WHERE project_id=? AND user_id=?");
        $chk->execute([$project_id, $target['id']]);
        $existing = $chk->fetch();

        if($existing) {
            if ($existing['status'] == 'accepted') header("Location: project_detail.php?id=$project_id&status=already_member");
            elseif ($existing['status'] == 'pending') header("Location: project_detail.php?id=$project_id&status=already_invited_member");
        } else {
            $conn->prepare("INSERT INTO project_members (project_id, user_id, status) VALUES (?, ?, 'pending')")->execute([$project_id, $target['id']]);
            createNotification(
                $conn,
                (int)$target['id'],
                'คำเชิญเข้าร่วมโครงงาน',
                'คุณได้รับคำเชิญเข้าร่วมโครงงาน: ' . (string)$project['name'],
                'info',
                (int)$project_id
            );
            writeAuditLog($conn, (int)$user_id, 'project.member.invite', 'เชิญสมาชิกใหม่ด้วยรหัสนักศึกษาเข้าสู่โครงงาน', 'project', (int)$project_id);
            header("Location: project_detail.php?id=$project_id&status=invite_success");
        }
    } else {
        header("Location: project_detail.php?id=$project_id&status=student_code_not_found");
    }
    exit;
}

// --- ACTION: Advisor Review Task ---
if ($is_advisor_team && isset($_POST['review_task_submit']) && isset($_POST['task_id'])) {
    $tid = (int)$_POST['task_id'];
    $review = $_POST['review_action'] ?? '';

    $task_check = $conn->prepare("SELECT id FROM tasks WHERE id = ? AND project_id = ?");
    $task_check->execute([$tid, $project_id]);
    if (!$task_check->fetchColumn()) {
        header("Location: project_detail.php?id=$project_id&status=invalid_task");
        exit;
    }

    if ($review === 'approve') {
        $conn->prepare("UPDATE tasks SET teacher_status = 'approved' WHERE id = ?")->execute([$tid]);
        $participantIds = getProjectParticipantUserIds($conn, (int)$project_id);
        $participantIds = array_values(array_filter($participantIds, function ($uid) use ($user_id) {
            return (int)$uid !== (int)$user_id;
        }));
        createNotificationForUsers(
            $conn,
            $participantIds,
            'งานผ่านการอนุมัติ',
            'อาจารย์อนุมัติงานในโครงงาน: ' . (string)$project['name'],
            'success',
            (int)$project_id
        );
        writeAuditLog($conn, (int)$user_id, 'task.review.approve', 'อนุมัติงาน ID: ' . $tid, 'task', (int)$tid);
    } elseif ($review === 'reject') {
        $conn->prepare("UPDATE tasks SET teacher_status = 'rejected', status = 'todo' WHERE id = ?")->execute([$tid]);
        $return_note = trim($_POST['return_note'] ?? '');
        if ($return_note === '') {
            $return_note = 'ส่งคืนให้แก้ไข';
        }
        $attachment_path = saveUploadedReturnAttachment($_FILES['return_file'] ?? []);

        try {
            $conn->prepare("INSERT INTO task_return_history (task_id, project_id, reviewer_id, note, attachment_path) VALUES (?, ?, ?, ?, ?)")
                 ->execute([$tid, $project_id, $user_id, $return_note, $attachment_path]);
        } catch (Exception $e) {
            try {
                $conn->prepare("INSERT INTO task_return_history (task_id, project_id, reviewer_id, note) VALUES (?, ?, ?, ?)")
                     ->execute([$tid, $project_id, $user_id, $return_note]);
            } catch (Exception $e2) {
                // Keep workflow running even if history table is unavailable.
            }
        }
        $participantIds = getProjectParticipantUserIds($conn, (int)$project_id);
        $participantIds = array_values(array_filter($participantIds, function ($uid) use ($user_id) {
            return (int)$uid !== (int)$user_id;
        }));
        createNotificationForUsers(
            $conn,
            $participantIds,
            'มีงานถูกส่งคืนให้แก้ไข',
            'อาจารย์ส่งคืนงานให้แก้ไขในโครงงาน: ' . (string)$project['name'],
            'warning',
            (int)$project_id
        );
        writeAuditLog($conn, (int)$user_id, 'task.review.reject', 'ส่งคืนงาน ID: ' . $tid, 'task', (int)$tid);
    }

    recalculateProjectProgressByApprovedTasks($conn, (int)$project_id);

    header("Location: project_detail.php?id=$project_id&status=reviewed");
    exit;
}

// Legacy GET review flow is intentionally disabled for security hardening.

// --- ACTION: Update Task ---
if (($is_leader || $is_member) && isset($_POST['update_task'])) {
    $tid = $_POST['task_id'];
    $st = $_POST['status'];
    $status_after_update = 'task_updated';
    $teacher_status_update = ", teacher_status = 'pending'";

    if (!empty($_FILES['file']['name'])) {
        $target_dir = __DIR__ . '/../../../../frontend/public/uploads/';
        $uploadError = null;
        $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'zip', 'rar', '7z'];
        $fname = storeUploadedFileSecure($_FILES['file'], $target_dir, 'task', $allowedExtensions, 10 * 1024 * 1024, $uploadError);
        if ($fname !== null) {
             $conn->prepare("UPDATE tasks SET file_path=?, status='done' $teacher_status_update WHERE id=?")->execute([$fname, $tid]);
             $st = 'done';
        } else {
            $status_after_update = 'task_file_upload_failed';
        }
    } else {
        $conn->prepare("UPDATE tasks SET status=? $teacher_status_update WHERE id=?")->execute([$st, $tid]);
    }
    
    recalculateProjectProgressByApprovedTasks($conn, (int)$project_id);

    if ($st === 'done') {
        $projectMetaStmt = $conn->prepare("SELECT advisor_id, co_advisor_id, name FROM projects WHERE id = ? LIMIT 1");
        $projectMetaStmt->execute([(int)$project_id]);
        $projectMeta = $projectMetaStmt->fetch(PDO::FETCH_ASSOC);
        if ($projectMeta) {
            $teacherTargets = [];
            if (!empty($projectMeta['advisor_id'])) {
                $teacherTargets[] = (int)$projectMeta['advisor_id'];
            }
            if (!empty($projectMeta['co_advisor_id'])) {
                $teacherTargets[] = (int)$projectMeta['co_advisor_id'];
            }
            if (!empty($teacherTargets)) {
                createNotificationForUsers(
                    $conn,
                    $teacherTargets,
                    'มีงานใหม่รอตรวจ',
                    'นักศึกษาส่งงานเข้าตรวจในโครงงาน: ' . (string)$projectMeta['name'],
                    'info',
                    (int)$project_id
                );
            }
        }
    }
    writeAuditLog($conn, (int)$user_id, 'task.update', 'อัปเดตสถานะงาน ID: ' . (int)$tid . ' เป็น ' . $st, 'task', (int)$tid);
    
    header("Location: project_detail.php?id=$project_id&status=$status_after_update");
    exit;
}

// --- ACTION: Add Comment ---
if (isset($_POST['add_comment'])) {
    $task_id = $_POST['task_id'];
    $comment = trim($_POST['comment']);
    
    if (!empty($comment)) {
        $conn->prepare("INSERT INTO task_comments (task_id, user_id, comment) VALUES (?, ?, ?)")
             ->execute([$task_id, $user_id, $comment]);
        header("Location: project_detail.php?id=$project_id"); 
        exit;
    }
}

// --- ACTION: Invite Advisor ---
if ($is_leader && isset($_POST['invite_advisor'])) {
    $adv_id = $_POST['advisor_id'];
    $type = $_POST['type']; 
    
    // ตรวจสอบว่าอาจารย์ท่านนี้อยู่ในสถานะใดสถานะหนึ่งของโปรเจกต์ไปแล้วหรือยัง
    $is_main = ($project['advisor_id'] == $adv_id || $project['pending_advisor_id'] == $adv_id);
    $is_co   = ($project['co_advisor_id'] == $adv_id || $project['pending_co_advisor_id'] == $adv_id);

    if($type == 'main') {
        if ($is_main || $is_co) { header("Location: project_detail.php?id=$project_id&status=advisor_error"); exit; }
        $conn->prepare("UPDATE projects SET pending_advisor_id=? WHERE id=?")->execute([$adv_id, $project_id]);
        createNotification(
            $conn,
            (int)$adv_id,
            'คำเชิญเป็นที่ปรึกษาหลัก',
            'มีคำเชิญให้เป็นที่ปรึกษาหลักในโครงงาน: ' . (string)$project['name'],
            'info',
            (int)$project_id
        );
        writeAuditLog($conn, (int)$user_id, 'advisor.invite.main', 'เชิญอาจารย์ที่ปรึกษาหลัก', 'project', (int)$project_id);
    } else {
        if ($is_co || $is_main) { header("Location: project_detail.php?id=$project_id&status=advisor_error"); exit; }
        $conn->prepare("UPDATE projects SET pending_co_advisor_id=? WHERE id=?")->execute([$adv_id, $project_id]);
        createNotification(
            $conn,
            (int)$adv_id,
            'คำเชิญเป็นที่ปรึกษารอง',
            'มีคำเชิญให้เป็นที่ปรึกษารองในโครงงาน: ' . (string)$project['name'],
            'info',
            (int)$project_id
        );
        writeAuditLog($conn, (int)$user_id, 'advisor.invite.co', 'เชิญอาจารย์ที่ปรึกษารอง', 'project', (int)$project_id);
    }
    
    header("Location: project_detail.php?id=$project_id&status=invite_success");
    exit;
}

// --- DATA FETCHING ---
$tasks = $conn->prepare("SELECT * FROM tasks WHERE project_id=? ORDER BY due_date ASC");
$tasks->execute([$project_id]);
$tasks_list = $tasks->fetchAll();
$task_total_count = count($tasks_list);
$can_add_more_tasks = ($task_total_count < MAX_TASKS_PER_PROJECT);
$task_return_history_map = [];
if ($task_total_count > 0) {
    $task_ids = array_map('intval', array_column($tasks_list, 'id'));
    $placeholders = implode(',', array_fill(0, count($task_ids), '?'));
    try {
        $hist_stmt = $conn->prepare("
            SELECT h.task_id, h.created_at, h.note, h.attachment_path, u.fullname AS reviewer_name
            FROM task_return_history h
            LEFT JOIN users u ON h.reviewer_id = u.id
            WHERE h.task_id IN ($placeholders)
            ORDER BY h.created_at DESC
        ");
        $hist_stmt->execute($task_ids);
        foreach ($hist_stmt->fetchAll() as $h) {
            $tid = (int)$h['task_id'];
            if (!isset($task_return_history_map[$tid])) {
                $task_return_history_map[$tid] = [];
            }
            $task_return_history_map[$tid][] = $h;
        }
    } catch (Exception $e) {
        $task_return_history_map = [];
    }
}

$membersStmt = $conn->prepare("SELECT u.id, u.fullname, pm.status FROM project_members pm JOIN users u ON pm.user_id = u.id WHERE pm.project_id = ?");
$membersStmt->execute([$project_id]);
$members = $membersStmt->fetchAll();

$assignees = [];
$assignees[] = $project['leader_name']; 
foreach($members as $m) {
    if($m['status'] == 'accepted') {
        $assignees[] = $m['fullname'];
    }
}

// กรองอาจารย์: ไม่เอาคนที่เป็นที่ปรึกษาไปแล้วมาแสดงให้เลือกซ้ำ
$unavailable_teacher_ids = array_filter([
    $project['advisor_id'],
    $project['co_advisor_id'],
    $project['pending_advisor_id'],
    $project['pending_co_advisor_id']
]);
$teachers = $conn->query("SELECT * FROM users WHERE role='teacher'")->fetchAll();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($project['name']) ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>@import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;700&display=swap'); body{font-family:'Sarabun',sans-serif;}</style>
</head>
<body class="bg-gray-100 text-gray-800">

<nav class="bg-white p-4 shadow flex flex-col md:flex-row justify-between items-center sticky top-0 z-40 gap-4">
    <div class="flex items-center gap-4 w-full md:w-auto">
        <a href="<?= $back_url ?>" class="text-gray-500 hover:text-purple-800 shrink-0"><i class="fas fa-arrow-left"></i> กลับ</a>
        <h1 class="font-bold text-xl text-purple-800 break-words leading-tight"><?= htmlspecialchars($project['name']) ?></h1>
    </div>
    <div class="flex items-center gap-2">
        <a href="project_evaluation.php?id=<?= (int)$project_id ?>" class="bg-indigo-600 text-white px-3 py-1 rounded-full text-sm font-bold hover:bg-indigo-700 transition">
            <i class="fas fa-clipboard-check mr-1"></i> Rubric
        </a>
        <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-bold shrink-0">ความคืบหน้า: <?= $project['progress'] ?>%</span>
    </div>
</nav>

<div class="container mx-auto p-6 grid grid-cols-1 lg:grid-cols-4 gap-6">
    
    <div class="lg:col-span-1 space-y-6">
        <div class="bg-white p-6 rounded shadow">
            <div class="flex items-center justify-between mb-2 border-b pb-2 gap-2">
                <h3 class="font-bold text-gray-700">รายละเอียด</h3>
                <?php if($can_edit): ?>
                    <button type="button" onclick="document.getElementById('modal-project-details').classList.remove('hidden')" class="text-xs bg-purple-700 text-white px-2 py-1 rounded hover:bg-purple-800">
                        <i class="fas fa-pen"></i> แก้ไข
                    </button>
                <?php endif; ?>
            </div>
            <p class="text-sm text-gray-600 mb-4"><?= nl2br(htmlspecialchars($project['description'] !== '' ? $project['description'] : '-')) ?></p>
            <div class="text-xs"><b>กรณีศึกษา:</b> <?= htmlspecialchars($project['case_study'] !== '' ? $project['case_study'] : '-') ?></div>
        </div>
        
        <div class="bg-white p-6 rounded shadow">
            <h3 class="font-bold text-gray-700 mb-3 border-b pb-2">สมาชิกในกลุ่ม</h3>
            <ul class="text-sm space-y-3">
                <li class="flex items-center gap-2 text-purple-800 font-bold bg-purple-50 p-2 rounded">
                    <i class="fas fa-crown text-yellow-500"></i> 
                    <div><?= htmlspecialchars($project['leader_name']) ?><div class="text-[10px] font-normal text-gray-500">หัวหน้าโครงการ</div></div>
                </li>
                <?php foreach($members as $m): ?>
                    <li class="flex justify-between items-center p-2 border-b last:border-0">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-user text-gray-400"></i> 
                            <div><?= htmlspecialchars($m['fullname']) ?> <?php if($m['status']=='pending') echo '<span class="text-[10px] text-yellow-600">(รอตอบรับ)</span>'; ?></div>
                        </div>
                        <?php if($is_leader): ?>
                            <button type="button" onclick="confirmRemoveMember(event, <?= (int)$m['id'] ?>)" class="text-red-400 hover:text-red-600 px-2" title="ź��Ҫԡ"><i class="fas fa-times"></i></button>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if($is_leader): ?>
            <form method="POST" class="mt-4 pt-4 border-t">
                <?= csrfInputField() ?>
                <input type="hidden" name="invite_member" value="1">
                <label class="text-xs font-bold">เชิญสมาชิก (เลขประจำตัวนักศึกษา)</label>
                <div class="flex gap-1 mt-1">
                    <input type="text" name="student_code" required class="border text-xs p-1 flex-1 rounded" placeholder="เช่น 653040123-1">
                    <button class="bg-purple-600 text-white text-xs px-2 rounded">+</button>
                </div>
            </form>
            <?php endif; ?>
        </div>

        <div class="bg-white p-6 rounded shadow">
            <h3 class="font-bold text-gray-700 mb-3 border-b pb-2">อาจารย์ที่ปรึกษา</h3>
            
            <div class="mb-4">
                <div class="text-xs font-bold text-gray-500 mb-1">ที่ปรึกษาหลัก (Primary)</div>
                <?php if($project['advisor_name']): ?>
                    <div class="text-green-700 font-bold text-sm"><i class="fas fa-check-circle"></i> <?= $project['advisor_name'] ?></div>
                <?php elseif($project['pending_advisor_name']): ?>
                    <div class="text-yellow-600 text-xs flex items-center gap-1">
                        <i class="fas fa-clock"></i> รอ: <?= $project['pending_advisor_name'] ?>
                        <?php if($is_leader): ?>
                            <button type="button" onclick="confirmCancelAdvisorInvite(event, 'main')" class="text-red-400 hover:text-red-600 ml-1" title="¡��ԡ���ԭ"><i class="fas fa-times-circle"></i></button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="text-gray-400 text-xs italic">ยังไม่มี</div>
                    <?php if($is_leader): ?>
                        <form method="POST" class="mt-1 flex gap-1">
                            <?= csrfInputField() ?>
                            <input type="hidden" name="invite_advisor" value="1">
                            <input type="hidden" name="type" value="main">
                            <select id="primary-advisor-select" name="advisor_id" class="text-xs border w-full rounded outline-none p-1" required>
                                <option value="">เลือกอาจารย์</option>
                                <?php foreach($teachers as $t): 
                                    if (in_array($t['id'], $unavailable_teacher_ids)) continue;
                                    echo "<option value='{$t['id']}'>{$t['fullname']}</option>"; 
                                endforeach; ?>
                            </select>
                            <button class="bg-blue-600 text-white text-xs px-2 rounded">เชิญ</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div>
                <div class="text-xs font-bold text-gray-500 mb-1">ที่ปรึกษารอง</div>
                <?php if($project['co_advisor_name']): ?>
                    <div class="text-blue-700 font-bold text-sm"><i class="fas fa-check-circle"></i> <?= $project['co_advisor_name'] ?></div>
                <?php elseif($project['pending_co_advisor_name']): ?>
                    <div class="text-yellow-600 text-xs flex items-center gap-1">
                        <i class="fas fa-clock"></i> รอ: <?= $project['pending_co_advisor_name'] ?>
                        <?php if($is_leader): ?>
                            <button type="button" onclick="confirmCancelAdvisorInvite(event, 'co')" class="text-red-400 hover:text-red-600 ml-1" title="¡��ԡ���ԭ"><i class="fas fa-times-circle"></i></button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="text-gray-400 text-xs italic">ยังไม่มี</div>
                    <?php if($is_leader): ?>
                        <form method="POST" class="mt-1 flex gap-1">
                            <?= csrfInputField() ?>
                            <input type="hidden" name="invite_advisor" value="1">
                            <input type="hidden" name="type" value="co">
                            <select id="co-advisor-select" name="advisor_id" class="text-xs border w-full rounded outline-none p-1" required>
                                <option value="">เลือกอาจารย์</option>
                                <?php foreach($teachers as $t): 
                                    if (in_array($t['id'], $unavailable_teacher_ids)) continue;
                                    echo "<option value='{$t['id']}'>{$t['fullname']}</option>"; 
                                endforeach; ?>
                            </select>
                            <button class="bg-blue-600 text-white text-xs px-2 rounded">เชิญ</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="lg:col-span-3 bg-white p-6 rounded shadow min-h-[500px]">
        <div class="flex justify-between mb-4">
            <h2 class="font-bold text-lg"><i class="fas fa-tasks"></i> รายการงาน</h2>
            <?php if($can_edit && $has_primary_advisor && $can_add_more_tasks): ?>
                <button onclick="document.getElementById('modal-task').classList.remove('hidden')" class="bg-purple-800 text-white px-3 py-1 rounded text-sm shadow hover:bg-purple-900">+ เพิ่มงาน</button>
            <?php endif; ?>
            <?php if($can_edit && !$has_primary_advisor): ?>
                <button type="button" class="bg-gray-300 text-gray-600 px-3 py-1 rounded text-sm shadow cursor-not-allowed" disabled>+ เพิ่มงาน</button>
            <?php endif; ?>
            <?php if($can_edit && $has_primary_advisor && !$can_add_more_tasks): ?>
                <button type="button" class="bg-gray-300 text-gray-600 px-3 py-1 rounded text-sm shadow cursor-not-allowed" disabled>+ เพิ่มงาน (ครบ <?= MAX_TASKS_PER_PROJECT ?> งาน)</button>
            <?php endif; ?>
        </div>
        <?php if($can_edit && !$has_primary_advisor): ?>
            <div class="mb-4 rounded border border-yellow-200 bg-yellow-50 text-yellow-800 text-xs px-3 py-2">
                ต้องเลือกอาจารย์ที่ปรึกษาหลักก่อน จึงจะเพิ่มงานได้
            </div>
        <?php endif; ?>
        <?php if($can_edit && $has_primary_advisor && !$can_add_more_tasks): ?>
            <div class="mb-4 rounded border border-blue-200 bg-blue-50 text-blue-800 text-xs px-3 py-2">
                เพิ่มงานได้สูงสุด <?= MAX_TASKS_PER_PROJECT ?> งาน (ขณะนี้ <?= $task_total_count ?>/<?= MAX_TASKS_PER_PROJECT ?> งาน)
            </div>
        <?php endif; ?>

        <div class="space-y-4">
            <?php foreach($tasks_list as $t): 
                $due = strtotime($t['due_date']);
                $days = ceil(($due - time())/86400);
                $is_done = ($t['status']=='done');
                $t_status = $t['teacher_status'] ?? 'pending'; 
                
                $border_class = 'border-l-4 border-yellow-400'; 
                if ($t_status == 'approved') $border_class = 'border-l-4 border-green-500 bg-green-50';
                elseif ($t_status == 'rejected') $border_class = 'border-l-4 border-red-500 bg-red-50';
                elseif ($is_done) $border_class = 'border-l-4 border-blue-400 bg-blue-50';

                $cmts = $conn->prepare("SELECT c.*, u.fullname FROM task_comments c JOIN users u ON c.user_id=u.id WHERE task_id=? ORDER BY created_at ASC");
                $cmts->execute([$t['id']]);
                $all_comments = $cmts->fetchAll();
                $total_comments = count($all_comments);
                $preview_comments = array_slice($all_comments, -2);
                $json_comments = json_encode($all_comments);
                $return_history = $task_return_history_map[(int)$t['id']] ?? [];
                $return_history_for_modal = array_map(function($rh) use ($upload_web_base) {
                    $attachment = trim((string)($rh['attachment_path'] ?? ''));
                    return [
                        'reviewer_name' => $rh['reviewer_name'] ?? 'อาจารย์',
                        'note' => $rh['note'] ?? 'ส่งคืนให้แก้ไข',
                        'created_at' => date('d/m/Y H:i', strtotime($rh['created_at'])),
                        'attachment_name' => $attachment,
                        'attachment_url' => $attachment !== '' ? ($upload_web_base . '/' . rawurlencode($attachment)) : ''
                    ];
                }, $return_history);
                $return_history_json = json_encode($return_history_for_modal, JSON_UNESCAPED_UNICODE);
                if ($return_history_json === false) {
                    $return_history_json = '[]';
                }
            ?>
            <div class="border rounded p-4 relative <?= $border_class ?>">
                <div class="flex flex-col md:flex-row justify-between gap-2">
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <h4 class="font-bold <?= $t_status=='approved'?'text-green-700':'' ?>"><?= htmlspecialchars($t['name']) ?></h4>
                            
                            <?php if($t_status == 'approved'): ?>
                                <span class="bg-green-100 text-green-800 text-[10px] px-2 py-0.5 rounded-full font-bold border border-green-200"><i class="fas fa-check-circle"></i> ผ่านแล้ว</span>
                            <?php elseif($t_status == 'rejected'): ?>
                                <span class="bg-red-100 text-red-800 text-[10px] px-2 py-0.5 rounded-full font-bold border border-red-200"><i class="fas fa-exclamation-circle"></i> แก้ไขด่วน</span>
                            <?php elseif($is_done): ?>
                                <span class="bg-blue-100 text-blue-800 text-[10px] px-2 py-0.5 rounded-full font-bold border border-blue-200"><i class="fas fa-hourglass-half"></i> รอตรวจ</span>
                            <?php endif; ?>

                            <?php if($is_leader): ?>
                                <a href="#" onclick="confirmDeleteTask(event, <?= $project_id ?>, <?= $t['id'] ?>)" class="text-gray-300 hover:text-red-500 text-xs" title="ลบงานนี้"><i class="fas fa-trash"></i></a>
                            <?php endif; ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">ผู้รับผิดชอบ: <?= $t['assignee_name'] ?> | กำหนด: <?= date('d/m/Y', $due) ?></div>
                    </div>
                    <?php if(!empty($t['file_path'])): ?>
                        <?php $task_file_url = $upload_web_base . '/' . rawurlencode($t['file_path']); ?>
                        <button onclick='openFile(<?= json_encode($task_file_url) ?>)' class="bg-white border text-gray-700 px-3 py-1 rounded text-xs h-fit shrink-0 shadow-sm hover:bg-gray-50"><i class="fas fa-paperclip"></i> ไฟล์แนบ</button>
                    <?php endif; ?>
                </div>

                <?php if($is_advisor_team && $is_done && $t_status != 'approved'): ?>
                <div class="mt-3 pt-3 border-t flex gap-2 items-center bg-yellow-50 -mx-4 -mb-4 p-3 rounded-b">
                    <div class="text-xs font-bold text-gray-600 mr-2"><i class="fas fa-user-tie"></i> ส่วนอาจารย์:</div>
                    <?php if(!empty($t['file_path'])): ?>
                        <?php $advisor_file_url = $upload_web_base . '/' . rawurlencode($t['file_path']); ?>
                        <button type="button" onclick='openFile(<?= json_encode($advisor_file_url) ?>)' class="bg-white border text-gray-700 text-xs px-3 py-1.5 rounded shadow-sm hover:bg-gray-50">
                            <i class="fas fa-file-arrow-down"></i> ไฟล์ที่นักศึกษาส่ง
                        </button>
                    <?php else: ?>
                        <span class="text-xs text-amber-700">ยังไม่มีไฟล์ที่นักศึกษาส่ง</span>
                    <?php endif; ?>
                    <form method="POST" class="flex-1" onsubmit="return confirm('ยืนยันให้ผ่าน?')">
                        <?= csrfInputField() ?>
                        <input type="hidden" name="review_task_submit" value="1">
                        <input type="hidden" name="review_action" value="approve">
                        <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                        <button type="submit" class="w-full bg-green-600 text-white text-xs px-3 py-1.5 rounded text-center shadow hover:bg-green-700">
                            <i class="fas fa-check"></i> อนุมัติ (ผ่าน)
                        </button>
                    </form>
                    <button
                        type="button"
                        data-open-return-task="<?= (int)$t['id'] ?>"
                        onclick='openRejectReviewModal(<?= (int)$t["id"] ?>, <?= json_encode($t["name"], JSON_UNESCAPED_UNICODE) ?>)'
                        class="flex-1 bg-red-500 text-white text-xs px-3 py-1.5 rounded text-center shadow hover:bg-red-600"
                    >
                        <i class="fas fa-undo"></i> ส่งคืน (แก้)
                    </button>
                </div>
                <?php endif; ?>

                <?php if(($is_leader || $is_member) && $t_status != 'approved'): ?>
                <div class="mt-3 pt-3 border-t bg-gray-50 -mx-4 -mb-4 p-3 rounded-b flex flex-wrap gap-2 items-center">
                    <form method="POST" enctype="multipart/form-data" class="flex-1 space-y-2">
                        <?= csrfInputField() ?>
                        <input type="hidden" name="update_task" value="1">
                        <input type="hidden" name="task_id" value="<?= $t['id'] ?>">

                        <div class="flex flex-wrap gap-2 items-center">
                            <select name="status" class="text-xs border rounded outline-none p-1"><option value="todo">ทำ</option><option value="done" <?= $is_done?'selected':'' ?>>เสร็จ/ส่งตรวจ</option></select>
                            <input type="file" name="file" class="text-xs w-32 task-file-input" data-task-id="<?= $t['id'] ?>">
                            <button class="bg-blue-600 text-white text-xs px-2 py-1 rounded">อัปเดต</button>
                        </div>

                        <div class="pt-2 border-t border-gray-200">
                            <div class="text-[11px] font-semibold text-gray-500 mb-1">ไฟล์แนบล่าสุด</div>
                            <span id="task-file-name-<?= $t['id'] ?>" class="text-[11px] text-gray-600 block">
                                <?php if(!empty($t['file_path'])): ?>
                                    <?php
                                        $file_ext = strtolower(pathinfo($t['file_path'], PATHINFO_EXTENSION));
                                        $file_icon = 'fa-file';
                                        $file_icon_color = 'text-gray-400';
                                        if (in_array($file_ext, ['doc', 'docx'])) { $file_icon = 'fa-file-word'; $file_icon_color = 'text-blue-600'; }
                                        elseif (in_array($file_ext, ['xls', 'xlsx', 'csv'])) { $file_icon = 'fa-file-excel'; $file_icon_color = 'text-green-600'; }
                                        elseif (in_array($file_ext, ['ppt', 'pptx'])) { $file_icon = 'fa-file-powerpoint'; $file_icon_color = 'text-orange-500'; }
                                        elseif (in_array($file_ext, ['pdf'])) { $file_icon = 'fa-file-pdf'; $file_icon_color = 'text-red-600'; }
                                        elseif (in_array($file_ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'])) { $file_icon = 'fa-file-image'; $file_icon_color = 'text-cyan-500'; }
                                        elseif (in_array($file_ext, ['zip', 'rar', '7z'])) { $file_icon = 'fa-file-zipper'; $file_icon_color = 'text-amber-600'; }
                                    ?>
                                    <a href="<?= $upload_web_base ?>/<?= rawurlencode($t['file_path']) ?>" target="_blank" class="inline-flex flex-col items-center w-[86px] group" title="<?= htmlspecialchars($t['file_path']) ?>">
                                        <span class="w-[58px] h-[70px] bg-white border rounded-md shadow-sm group-hover:shadow flex items-center justify-center">
                                            <i class="fas <?= $file_icon ?> <?= $file_icon_color ?> text-3xl"></i>
                                        </span>
                                        <span class="mt-1 text-[10px] text-center text-gray-700 leading-tight break-all line-clamp-2 w-[86px]"><?= htmlspecialchars($t['file_path']) ?></span>
                                    </a>
                                <?php else: ?>
                                    <span class="inline-flex flex-col items-center w-[86px]">
                                        <span class="w-[58px] h-[70px] bg-white border border-dashed rounded-md flex items-center justify-center">
                                            <i class="fas fa-file text-gray-300 text-3xl"></i>
                                        </span>
                                        <span class="mt-1 text-[10px] text-center text-gray-400 leading-tight w-[86px]">ยังไม่มีไฟล์</span>
                                    </span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <div class="mt-3 pt-3 border-t">
                    <button
                        type="button"
                        onclick='openReturnHistoryModal(<?= json_encode($t['name']) ?>, <?= $return_history_json ?>)'
                        class="inline-flex items-center gap-2 text-xs font-bold px-3 py-1.5 rounded border border-red-200 bg-red-50 text-red-700 hover:bg-red-100"
                    >
                        <i class="fas fa-clock-rotate-left"></i>
                        ประวัติการส่งคืน (แก้) <?= count($return_history) ?> ครั้ง
                    </button>
                </div>

                <div class="mt-4 border-t pt-2">
                    <div class="flex justify-between items-center mb-2">
                        <div class="text-xs font-bold text-gray-500">ความคิดเห็น (<?= $total_comments ?>)</div>
                        <?php if($total_comments > 0): ?>
                            <button onclick='openCommentModal(<?= $t['id'] ?>, <?= json_encode($t['name']) ?>, <?= $json_comments ?>)' class="text-xs text-purple-700 hover:underline">ดูทั้งหมด</button>
                        <?php endif; ?>
                    </div>
                    <div class="space-y-1 mb-2">
                        <?php foreach($preview_comments as $c): 
                            $can_del_cmt = ($c['user_id'] == $user_id || $user_role == 'admin' || $user_role == 'teacher');
                        ?>
                            <div class="text-xs bg-white p-1.5 border rounded flex justify-between group">
                                <span><b class="text-purple-800"><?= htmlspecialchars($c['fullname']) ?>:</b> <span class="text-gray-700 truncate"><?= htmlspecialchars($c['comment']) ?></span></span>
                                
                                <?php if($can_del_cmt): ?>
                                    <a href="#" onclick="confirmDeleteComment(event, <?= $project_id ?>, <?= $c['id'] ?>)" class="text-gray-300 hover:text-red-500 opacity-0 group-hover:opacity-100"><i class="fas fa-trash"></i></a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if($total_comments == 0): ?><div class="text-xs text-gray-400 italic">ยังไม่มีความคิดเห็น</div><?php endif; ?>
                    </div>
                    <?php if($can_view): ?>
                    <form method="POST" class="flex gap-1">
                        <?= csrfInputField() ?>
                        <input type="hidden" name="add_comment" value="1"><input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                        <input type="text" name="comment" placeholder="พิมพ์ข้อความ..." class="border text-xs flex-1 p-1 rounded outline-none focus:ring-1 focus:ring-purple-300" required>
                        <button class="text-purple-800 px-2"><i class="fas fa-paper-plane"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if($can_edit): ?>
<div id="modal-project-details" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white p-6 rounded w-full max-w-lg shadow-xl">
        <h3 class="font-bold mb-4 text-lg text-purple-800">แก้ไขรายละเอียดโครงงาน</h3>
        <form method="POST">
            <?= csrfInputField() ?>
            <input type="hidden" name="update_project_details" value="1">
            <label class="text-xs font-bold block mb-1">รายละเอียด</label>
            <textarea name="description" rows="5" class="w-full border p-2 mb-3 rounded outline-none" placeholder="รายละเอียดโครงงาน..."><?= htmlspecialchars($project['description']) ?></textarea>

            <label class="text-xs font-bold block mb-1">กรณีศึกษา</label>
            <input type="text" name="case_study" value="<?= htmlspecialchars($project['case_study']) ?>" class="w-full border p-2 mb-4 rounded outline-none" placeholder="กรณีศึกษา...">

            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('modal-project-details').classList.add('hidden')" class="px-3 py-1 border rounded">ยกเลิก</button>
                <button class="px-3 py-1 bg-purple-800 text-white rounded">บันทึก</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if($can_edit && $has_primary_advisor && $can_add_more_tasks): ?>
<div id="modal-task" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white p-6 rounded w-full max-w-sm">
        <h3 class="font-bold mb-4 text-lg">เพิ่มงาน</h3>
        <form method="POST">
            <?= csrfInputField() ?>
            <input type="hidden" name="add_task" value="1">
            <label class="text-xs font-bold block mb-1">ชื่องาน</label><input type="text" name="task_name" required class="w-full border p-2 mb-2 rounded outline-none">
            <label class="text-xs font-bold block mb-1">ผู้รับผิดชอบ</label><select name="assignee" class="w-full border p-2 mb-2 rounded outline-none"><?php foreach($assignees as $p) echo "<option value='$p'>$p</option>"; ?></select>
            <label class="text-xs font-bold block mb-1">กำหนดส่ง</label><input type="date" name="due_date" required class="w-full border p-2 mb-4 rounded outline-none">
            <div class="flex justify-end gap-2"><button type="button" onclick="document.getElementById('modal-task').classList.add('hidden')" class="px-3 py-1 border rounded">ยกเลิก</button><button class="px-3 py-1 bg-purple-800 text-white rounded">บันทึก</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<div id="modal-comment" class="fixed inset-0 bg-black bg-opacity-70 hidden flex items-center justify-center z-50">
    <div class="bg-white p-0 rounded-lg w-full max-w-lg shadow-2xl flex flex-col h-[500px]">
        <div class="p-4 border-b bg-purple-50 rounded-t-lg flex justify-between items-center">
            <h3 class="font-bold text-purple-800 text-lg" id="comment-modal-title">ความคิดเห็น</h3>
            <button onclick="document.getElementById('modal-comment').classList.add('hidden')" class="text-gray-400 hover:text-red-500 text-xl font-bold">&times;</button>
        </div>
        <div class="flex-1 p-4 overflow-y-auto bg-gray-50 space-y-3" id="comment-list-container"></div>
        <div class="p-3 border-t bg-white rounded-b-lg">
            <form method="POST" class="flex gap-2">
                <?= csrfInputField() ?>
                <input type="hidden" name="add_comment" value="1"><input type="hidden" name="task_id" id="modal-task-id">
                <input type="text" name="comment" placeholder="แสดงความคิดเห็นที่นี่..." class="border flex-1 p-2 rounded-full text-sm outline-none focus:ring-2 focus:ring-purple-300" required>
                <button class="bg-purple-600 text-white w-10 h-10 rounded-full shadow flex items-center justify-center"><i class="fas fa-paper-plane"></i></button>
            </form>
        </div>
    </div>
</div>

<div id="modal-return-history" class="fixed inset-0 bg-black bg-opacity-70 hidden flex items-center justify-center z-50">
    <div class="bg-white p-0 rounded-lg w-full max-w-2xl shadow-2xl flex flex-col max-h-[80vh]">
        <div class="p-4 border-b bg-red-50 rounded-t-lg flex justify-between items-center">
            <h3 class="font-bold text-red-800 text-lg" id="return-history-modal-title">ประวัติการส่งคืน (แก้)</h3>
            <button onclick="document.getElementById('modal-return-history').classList.add('hidden')" class="text-gray-400 hover:text-red-500 text-xl font-bold">&times;</button>
        </div>
        <div class="flex-1 p-4 overflow-y-auto bg-gray-50 space-y-2" id="return-history-list-container"></div>
    </div>
</div>

<?php if($is_advisor_team): ?>
<div id="modal-reject-review" class="fixed inset-0 bg-black bg-opacity-70 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-lg w-full max-w-lg shadow-2xl">
        <div class="p-4 border-b bg-red-50 rounded-t-lg flex justify-between items-center">
            <h3 class="font-bold text-red-800 text-lg">ส่งงานให้นักศึกษาแก้ไข</h3>
            <button type="button" onclick="document.getElementById('modal-reject-review').classList.add('hidden')" class="text-gray-400 hover:text-red-500 text-xl font-bold">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="p-4 space-y-3">
            <?= csrfInputField() ?>
            <input type="hidden" name="review_task_submit" value="1">
            <input type="hidden" name="review_action" value="reject">
            <input type="hidden" name="task_id" id="reject-review-task-id">

            <div class="text-sm text-gray-700">
                งาน: <span id="reject-review-task-name" class="font-bold text-gray-900">-</span>
            </div>

            <div>
                <label class="text-xs font-bold block mb-1">หมายเหตุจากอาจารย์</label>
                <textarea name="return_note" rows="3" class="w-full border p-2 rounded outline-none focus:ring-2 focus:ring-red-300" placeholder="เช่น กรุณาปรับแก้ไขหัวข้อที่ 3 และอัปโหลดรูปแบบเอกสาร"></textarea>
            </div>

            <div>
                <label class="text-xs font-bold block mb-1">แนบไฟล์ส่งคืน (ถ้ามี)</label>
                <input type="file" name="return_file" id="reject-review-file-input" class="w-full text-xs border p-2 rounded bg-white">
                <div id="reject-review-file-name" class="text-[11px] text-gray-500 mt-1">ยังไม่ได้เลือกไฟล์</div>
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t">
                <button type="button" onclick="document.getElementById('modal-reject-review').classList.add('hidden')" class="px-3 py-1 border rounded">ยกเลิก</button>
                <button type="submit" class="px-3 py-1 bg-red-600 text-white rounded hover:bg-red-700">ยืนยันส่งคืน</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div id="modal-file" class="fixed inset-0 bg-black bg-opacity-80 hidden flex items-center justify-center z-50">
    <div class="bg-white p-2 rounded w-full max-w-4xl h-[90vh] flex flex-col">
        <div class="flex justify-between p-2 border-b items-center"><h3 class="font-bold text-lg">ตัวอย่างไฟล์</h3><button onclick="document.getElementById('modal-file').classList.add('hidden')" class="text-red-500 font-bold text-xl px-2 hover:text-red-700">&times;</button></div>
        <iframe id="file-viewer" class="w-full flex-1 border bg-gray-100"></iframe>
    </div>
</div>

<form id="delete-task-form" method="POST" class="hidden">
    <?= csrfInputField() ?>
    <input type="hidden" name="action" value="delete_task">
    <input type="hidden" name="task_id" id="delete-task-id">
</form>

<form id="delete-comment-form" method="POST" class="hidden">
    <?= csrfInputField() ?>
    <input type="hidden" name="action" value="delete_comment">
    <input type="hidden" name="comment_id" id="delete-comment-id">
</form>

<form id="remove-member-form" method="POST" class="hidden">
    <?= csrfInputField() ?>
    <input type="hidden" name="action" value="remove_member">
    <input type="hidden" name="member_id" id="remove-member-id">
</form>

<form id="cancel-advisor-invite-form" method="POST" class="hidden">
    <?= csrfInputField() ?>
    <input type="hidden" name="action" value="cancel_advisor_invite">
    <input type="hidden" name="type" id="cancel-advisor-type">
</form>

<script>
    // ส่ง id ของ Project ไปใช้ใน JS ด้วย
    const currentProjectId = <?= $project_id ?>;
    const deleteTaskForm = document.getElementById('delete-task-form');
    const deleteTaskIdInput = document.getElementById('delete-task-id');
    const deleteCommentForm = document.getElementById('delete-comment-form');
    const deleteCommentIdInput = document.getElementById('delete-comment-id');
    const removeMemberForm = document.getElementById('remove-member-form');
    const removeMemberIdInput = document.getElementById('remove-member-id');
    const cancelAdvisorInviteForm = document.getElementById('cancel-advisor-invite-form');
    const cancelAdvisorTypeInput = document.getElementById('cancel-advisor-type');

    function openFile(path) {
        if (!path) return;

        const lower = String(path).toLowerCase();
        const previewable = ['.pdf', '.png', '.jpg', '.jpeg', '.gif', '.webp', '.txt'];
        const canPreviewInIframe = previewable.some((ext) => lower.endsWith(ext));

        if (canPreviewInIframe) {
            document.getElementById('file-viewer').src = path;
            document.getElementById('modal-file').classList.remove('hidden');
            return;
        }

        window.open(path, '_blank', 'noopener');
    }
    
    function openCommentModal(taskId, taskName, comments) {
        document.getElementById('modal-comment').classList.remove('hidden');
        document.getElementById('comment-modal-title').innerText = taskName;
        document.getElementById('modal-task-id').value = taskId;
        const container = document.getElementById('comment-list-container');
        container.innerHTML = '';
        if (comments.length === 0) container.innerHTML = '<div class="text-center text-gray-400 mt-10">ยังไม่มีความคิดเห็น</div>';
        else {
            comments.forEach(c => {
                const isMe = (c.user_id == <?= $user_id ?>);
                const canDel = (isMe || '<?= $user_role ?>' == 'admin' || '<?= $user_role ?>' == 'teacher');
                const align = isMe ? 'justify-end' : 'justify-start';
                const bg = isMe ? 'bg-purple-600 text-white' : 'bg-white border text-gray-800';
                
                // ✅ เปลี่ยนเป็นฟังก์ชัน SweetAlert สำหรับคอมเมนต์ใน Modal
                const delHtml = canDel ? `<a href="#" onclick="confirmDeleteComment(event, ${currentProjectId}, ${c.id})" class="text-[10px] text-red-400 hover:text-red-600 ml-2"><i class="fas fa-trash"></i></a>` : '';

                const html = `
                    <div class="flex ${align}">
                        <div class="max-w-[80%]">
                            <div class="flex items-center gap-1 ${isMe?'justify-end':''}">
                                <div class="text-[10px] text-gray-500 mb-0.5">${isMe?'ฉัน':c.fullname}</div>
                                ${delHtml}
                            </div>
                            <div class="${bg} px-3 py-2 rounded-2xl text-sm shadow-sm break-words">${c.comment}</div>
                        </div>
                    </div>`;
                container.insertAdjacentHTML('beforeend', html);
            });
            setTimeout(() => { container.scrollTop = container.scrollHeight; }, 100);
        }
    }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function openReturnHistoryModal(taskName, historyRows) {
        document.getElementById('modal-return-history').classList.remove('hidden');
        document.getElementById('return-history-modal-title').innerText = `ประวัติการส่งคืน (แก้): ${taskName}`;
        const container = document.getElementById('return-history-list-container');
        container.innerHTML = '';

        if (!historyRows || historyRows.length === 0) {
            container.innerHTML = '<div class="text-center text-gray-400 mt-8">ยังไม่มีประวัติการส่งคืนของงานนี้</div>';
            return;
        }

        historyRows.forEach((row, idx) => {
            const roundNo = historyRows.length - idx;
            const reviewer = escapeHtml(row.reviewer_name || 'อาจารย์');
            const note = escapeHtml(row.note || 'ส่งคืนให้แก้ไข');
            const createdAt = escapeHtml(row.created_at || '-');
            const hasAttachment = !!row.attachment_url;
            const attachmentUrl = hasAttachment ? String(row.attachment_url) : '';
            const attachmentName = escapeHtml(row.attachment_name || 'ไฟล์แนบ');
            const attachmentHtml = hasAttachment
                ? `<a href="${attachmentUrl}" target="_blank" class="inline-flex items-center gap-2 mt-2 px-2 py-1 rounded border border-red-200 bg-white text-red-700 hover:bg-red-50">
                        <i class="fas fa-paperclip"></i>
                        <span class="text-xs break-all">${attachmentName}</span>
                   </a>`
                : '';
            const itemHtml = `
                <div class="bg-white border border-red-100 rounded p-3 shadow-sm">
                    <div class="flex justify-between gap-2 items-start">
                        <div class="text-sm font-bold text-red-700">ครั้งที่ ${roundNo} โดย ${reviewer}</div>
                        <div class="text-xs text-gray-500 whitespace-nowrap">${createdAt}</div>
                    </div>
                    <div class="text-xs text-gray-700 mt-1">${note}</div>
                    ${attachmentHtml}
                </div>
            `;
            container.insertAdjacentHTML('beforeend', itemHtml);
        });
    }

    function openRejectReviewModal(taskId, taskName) {
        const modal = document.getElementById('modal-reject-review');
        if (!modal) return;
        document.getElementById('reject-review-task-id').value = String(taskId);
        document.getElementById('reject-review-task-name').innerText = taskName || '-';
        const fileInput = document.getElementById('reject-review-file-input');
        const fileName = document.getElementById('reject-review-file-name');
        if (fileInput) fileInput.value = '';
        if (fileName) fileName.innerText = 'ยังไม่ได้เลือกไฟล์';
        modal.classList.remove('hidden');
    }
    
    const urlParams = new URLSearchParams(window.location.search);
    const status = urlParams.get('status');
    const cleanUrl = () => window.history.replaceState(null, null, window.location.pathname + window.location.search.replace(/[\?&]status=[^&]+/, '').replace(/^&/, '?'));
    if (status === 'reviewed') Swal.fire({icon: 'success', title: 'Review saved successfully', showConfirmButton: false, timer: 1500}).then(cleanUrl);
    else if (status === 'project_updated') Swal.fire({icon: 'success', title: 'Project updated successfully', showConfirmButton: false, timer: 1500}).then(cleanUrl);
    else if (status === 'task_added') Swal.fire({icon: 'success', title: 'Task added successfully', showConfirmButton: false, timer: 1500}).then(cleanUrl);
    else if (status === 'task_updated') Swal.fire({icon: 'success', title: 'Task updated successfully', showConfirmButton: false, timer: 1500}).then(cleanUrl);
    else if (status === 'task_file_upload_failed') Swal.fire({icon: 'error', title: 'File upload failed', text: 'Please check file type or file size and try again.'}).then(cleanUrl);
    else if (status === 'task_deleted') Swal.fire({icon: 'success', title: 'Task deleted successfully', showConfirmButton: false, timer: 1500}).then(cleanUrl);
    else if (status === 'comment_deleted') Swal.fire({icon: 'success', title: 'ลบความเห็นแล้ว', showConfirmButton: false, timer: 1500}).then(cleanUrl);
    else if (status === 'already_member') Swal.fire({icon: 'warning', title: 'เป็นสมาชิกอยู่แล้ว'}).then(cleanUrl);
    else if (status === 'invite_success') Swal.fire({icon: 'success', title: 'เชิญสำเร็จ', showConfirmButton: false, timer: 1500}).then(cleanUrl);
    else if (status === 'student_code_required') Swal.fire({icon: 'warning', title: 'กรุณากรอกเลขประจำตัวนักศึกษา'}).then(cleanUrl);
    else if (status === 'student_code_not_found') Swal.fire({icon: 'error', title: 'ไม่พบเลขประจำตัวนักศึกษาในระบบ'}).then(cleanUrl);
    else if (status === 'csrf_invalid') Swal.fire({icon: 'error', title: 'คำขอไม่ถูกต้อง', text: 'กรุณาลองใหม่อีกครั้ง'}).then(cleanUrl);
    else if (status === 'advisor_error') Swal.fire({icon: 'error', title: 'เลือกซ้ำซ้อน', text: 'อาจารย์ท่านนี้อยู่ในสถานะที่ปรึกษาหลักหรือรองของโครงงานนี้แล้ว'}).then(cleanUrl);
    else if (status === 'advisor_required_for_task') Swal.fire({icon: 'warning', title: 'ยังเพิ่มงานไม่ได้', text: 'ต้องเลือกอาจารย์ที่ปรึกษาหลักก่อน'}).then(cleanUrl);
    else if (status === 'task_limit_reached') Swal.fire({icon: 'warning', title: 'เพิ่มงานได้สูงสุด <?= MAX_TASKS_PER_PROJECT ?> งาน'}).then(cleanUrl);
    else if (status === 'invalid_task') Swal.fire({icon: 'error', title: 'ไม่พบงานที่ต้องการตรวจ'}).then(cleanUrl);

    const rejectFileInput = document.getElementById('reject-review-file-input');
    if (rejectFileInput) {
        rejectFileInput.addEventListener('change', () => {
            const fileNameEl = document.getElementById('reject-review-file-name');
            if (!fileNameEl) return;
            fileNameEl.innerText = (rejectFileInput.files && rejectFileInput.files.length > 0)
                ? rejectFileInput.files[0].name
                : 'ยังไม่ได้เลือกไฟล์';
        });
    }

    const openReturnTaskId = urlParams.get('open_return_task');
    if (openReturnTaskId) {
        const triggerBtn = document.querySelector(`[data-open-return-task="${openReturnTaskId}"]`);
        if (triggerBtn) {
            triggerBtn.click();
        }
        const params = new URLSearchParams(window.location.search);
        params.delete('open_return_task');
        const nextQuery = params.toString();
        const nextUrl = window.location.pathname + (nextQuery ? `?${nextQuery}` : '');
        window.history.replaceState(null, null, nextUrl);
    }

    const primaryAdvisorSelect = document.getElementById('primary-advisor-select');
    const coAdvisorSelect = document.getElementById('co-advisor-select');

    function syncAdvisorDropdowns() {
        if (!primaryAdvisorSelect || !coAdvisorSelect) return;

        const primaryValue = primaryAdvisorSelect.value;
        const coValue = coAdvisorSelect.value;

        Array.from(primaryAdvisorSelect.options).forEach((option) => {
            if (!option.value) return;
            option.hidden = (option.value === coValue);
        });

        Array.from(coAdvisorSelect.options).forEach((option) => {
            if (!option.value) return;
            option.hidden = (option.value === primaryValue);
        });

        if (primaryValue && coValue && primaryValue === coValue) {
            coAdvisorSelect.value = '';
        }
    }

    if (primaryAdvisorSelect && coAdvisorSelect) {
        primaryAdvisorSelect.addEventListener('change', syncAdvisorDropdowns);
        coAdvisorSelect.addEventListener('change', syncAdvisorDropdowns);
        syncAdvisorDropdowns();
    }

    document.querySelectorAll('.task-file-input').forEach((input) => {
        input.addEventListener('change', () => {
            const taskId = input.getAttribute('data-task-id');
            const nameEl = document.getElementById(`task-file-name-${taskId}`);
            if (!nameEl) return;
            if (input.files && input.files.length > 0) {
                const fileName = input.files[0].name;
                const ext = fileName.includes('.') ? fileName.split('.').pop().toLowerCase() : '';
                let icon = 'fa-file';
                let color = 'text-gray-400';
                if (['doc', 'docx'].includes(ext)) { icon = 'fa-file-word'; color = 'text-blue-600'; }
                else if (['xls', 'xlsx', 'csv'].includes(ext)) { icon = 'fa-file-excel'; color = 'text-green-600'; }
                else if (['ppt', 'pptx'].includes(ext)) { icon = 'fa-file-powerpoint'; color = 'text-orange-500'; }
                else if (['pdf'].includes(ext)) { icon = 'fa-file-pdf'; color = 'text-red-600'; }
                else if (['png', 'jpg', 'jpeg', 'gif', 'webp'].includes(ext)) { icon = 'fa-file-image'; color = 'text-cyan-500'; }
                else if (['zip', 'rar', '7z'].includes(ext)) { icon = 'fa-file-zipper'; color = 'text-amber-600'; }
                const tile = `<span class="inline-flex flex-col items-center w-[86px]"><span class="w-[58px] h-[70px] bg-white border rounded-md shadow-sm flex items-center justify-center"><i class="fas ${icon} ${color} text-3xl"></i></span><span class="mt-1 text-[10px] text-center text-gray-700 leading-tight break-all line-clamp-2 w-[86px]">${fileName}</span></span>`;
                nameEl.innerHTML = tile;
            } else {
                nameEl.innerHTML = `<span class="inline-flex flex-col items-center w-[86px]"><span class="w-[58px] h-[70px] bg-white border border-dashed rounded-md flex items-center justify-center"><i class="fas fa-file text-gray-300 text-3xl"></i></span><span class="mt-1 text-[10px] text-center text-gray-400 leading-tight w-[86px]">ยังไม่มีไฟล์</span></span>`;
            }
        });
    });

    function confirmDeleteTask(event, projectId, taskId) {
        event.preventDefault();
        Swal.fire({
            title: 'ยืนยันลบงานนี้?',
            text: "หากลบแล้วจะไม่สามารถกู้คืนข้อมูลและไฟล์ที่แนบได้!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'ใช่, ลบเลย!',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                deleteTaskIdInput.value = String(taskId);
                deleteTaskForm.submit();
            }
        });
    }

    function confirmDeleteComment(event, projectId, commentId) {
        event.preventDefault();
        Swal.fire({
            title: 'ยืนยันลบความคิดเห็น?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'ใช่, ลบเลย!',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                deleteCommentIdInput.value = String(commentId);
                deleteCommentForm.submit();
            }
        });
    }

    function confirmRemoveMember(event, memberId) {
        event.preventDefault();
        Swal.fire({
            title: 'Confirm member removal?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Yes, remove',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                removeMemberIdInput.value = String(memberId);
                removeMemberForm.submit();
            }
        });
    }

    function confirmCancelAdvisorInvite(event, inviteType) {
        event.preventDefault();
        Swal.fire({
            title: 'Confirm cancel invitation?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Yes, cancel',
            cancelButtonText: 'Back'
        }).then((result) => {
            if (result.isConfirmed) {
                cancelAdvisorTypeInput.value = String(inviteType);
                cancelAdvisorInviteForm.submit();
            }
        });
    }
</script>
</body>
</html>

