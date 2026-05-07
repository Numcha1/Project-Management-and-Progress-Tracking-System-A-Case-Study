<?php
session_start();
require_once __DIR__ . '/../System/db_connect.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student'; // เก็บ Role เพื่อใช้เช็คสิทธิ์ลบคอมเมนต์
$project_id = $_GET['id'] ?? 0;
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
$is_member = $conn->query("SELECT COUNT(*) FROM project_members WHERE project_id=$project_id AND user_id=$user_id AND status='accepted'")->fetchColumn() > 0;

$can_view = ($project['progress'] == 100 || $user_role == 'admin' || $is_leader || $is_member || $is_advisor_team);
$can_edit = ($is_leader); 

if (!$can_view) die("<div class='text-center p-10 text-red-500 font-bold'>⛔ คุณไม่มีสิทธิ์เข้าถึงโครงงานนี้</div>");

// --- 🗑️ ACTION: Delete Comment ---
if (isset($_GET['delete_comment'])) {
    $cmt_id = $_GET['delete_comment'];
    
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

// --- ACTION: Add Task ---
if ($can_edit && isset($_POST['add_task'])) {
    $conn->prepare("INSERT INTO tasks (project_id, name, assignee_name, due_date, status) VALUES (?, ?, ?, ?, 'todo')")->execute([$project_id, $_POST['task_name'], $_POST['assignee'], $_POST['due_date']]);
    header("Location: project_detail.php?id=$project_id&status=task_added");
    exit;
}

// --- ACTION: Delete Task (Leader only) ---
if ($can_edit && isset($_GET['delete_task'])) {
    $conn->prepare("DELETE FROM tasks WHERE id = ? AND project_id = ?")->execute([$_GET['delete_task'], $project_id]);
    
    // Recalculate Progress (นับเฉพาะที่อาจารย์อนุมัติแล้ว)
    $total = $conn->query("SELECT COUNT(*) FROM tasks WHERE project_id=$project_id")->fetchColumn();
    $done = $conn->query("SELECT COUNT(*) FROM tasks WHERE project_id=$project_id AND teacher_status='approved'")->fetchColumn(); 
    $pct = ($total>0)?round(($done/$total)*100):0;
    $conn->prepare("UPDATE projects SET progress=? WHERE id=?")->execute([$pct, $project_id]);

    header("Location: project_detail.php?id=$project_id&status=task_deleted");
    exit;
}

// --- ACTION: Remove Member ---
if ($can_edit && isset($_GET['remove_member'])) {
    $conn->prepare("DELETE FROM project_members WHERE user_id = ? AND project_id = ?")->execute([$_GET['remove_member'], $project_id]);
    header("Location: project_detail.php?id=$project_id");
    exit;
}

// --- ACTION: Cancel Advisor Invitation ---
if ($can_edit && isset($_GET['cancel_advisor_invite'])) {
    $type = $_GET['type']; 
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
    $email = $_POST['email'];
    $u = $conn->prepare("SELECT id FROM users WHERE email=? AND role='student'");
    $u->execute([$email]);
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
            header("Location: project_detail.php?id=$project_id&status=invite_success");
        }
    } else {
        header("Location: project_detail.php?id=$project_id&status=user_not_found");
    }
    exit;
}

// --- ACTION: Advisor Review Task ---
if ($is_advisor_team && isset($_GET['review_task']) && isset($_GET['task_id'])) {
    $tid = $_GET['task_id'];
    $review = $_GET['review_task'];

    if ($review == 'approve') {
        $conn->prepare("UPDATE tasks SET teacher_status = 'approved' WHERE id = ?")->execute([$tid]);
    } elseif ($review == 'reject') {
        $conn->prepare("UPDATE tasks SET teacher_status = 'rejected', status = 'todo' WHERE id = ?")->execute([$tid]);
    }
    
    // Recalculate Progress (นับเฉพาะที่อาจารย์อนุมัติแล้ว)
    $total = $conn->query("SELECT COUNT(*) FROM tasks WHERE project_id=$project_id")->fetchColumn();
    $done = $conn->query("SELECT COUNT(*) FROM tasks WHERE project_id=$project_id AND teacher_status='approved'")->fetchColumn(); 
    $pct = ($total>0)?round(($done/$total)*100):0;
    $conn->prepare("UPDATE projects SET progress=? WHERE id=?")->execute([$pct, $project_id]);

    header("Location: project_detail.php?id=$project_id&status=reviewed");
    exit;
}

// --- ACTION: Update Task ---
if (($is_leader || $is_member) && isset($_POST['update_task'])) {
    $tid = $_POST['task_id'];
    $st = $_POST['status'];
    $teacher_status_update = ", teacher_status = 'pending'";

    if (!empty($_FILES['file']['name'])) {
        $target_dir = "uploads/";
        if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);
        $fname = time()."_".$_FILES['file']['name'];
        if(move_uploaded_file($_FILES['file']['tmp_name'], $target_dir.$fname)){
             $conn->prepare("UPDATE tasks SET file_path=?, status='done' $teacher_status_update WHERE id=?")->execute([$fname, $tid]);
             $st = 'done'; 
        }
    } else {
        $conn->prepare("UPDATE tasks SET status=? $teacher_status_update WHERE id=?")->execute([$st, $tid]);
    }
    
    // Recalculate Progress (นับเฉพาะที่อาจารย์อนุมัติแล้ว)
    $total = $conn->query("SELECT COUNT(*) FROM tasks WHERE project_id=$project_id")->fetchColumn();
    $done = $conn->query("SELECT COUNT(*) FROM tasks WHERE project_id=$project_id AND teacher_status='approved'")->fetchColumn(); 
    $pct = ($total>0)?round(($done/$total)*100):0;
    $conn->prepare("UPDATE projects SET progress=? WHERE id=?")->execute([$pct, $project_id]);
    
    header("Refresh:0");
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
    } else {
        if ($is_co || $is_main) { header("Location: project_detail.php?id=$project_id&status=advisor_error"); exit; }
        $conn->prepare("UPDATE projects SET pending_co_advisor_id=? WHERE id=?")->execute([$adv_id, $project_id]);
    }
    
    header("Location: project_detail.php?id=$project_id&status=invite_success");
    exit;
}

// --- DATA FETCHING ---
$tasks = $conn->prepare("SELECT * FROM tasks WHERE project_id=? ORDER BY due_date ASC");
$tasks->execute([$project_id]);
$tasks_list = $tasks->fetchAll();

$members = $conn->query("SELECT u.id, u.fullname, pm.status FROM project_members pm JOIN users u ON pm.user_id=u.id WHERE pm.project_id=$project_id")->fetchAll();

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
    <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-bold shrink-0">Progress: <?= $project['progress'] ?>%</span>
</nav>

<div class="container mx-auto p-6 grid grid-cols-1 lg:grid-cols-4 gap-6">
    
    <div class="lg:col-span-1 space-y-6">
        <div class="bg-white p-6 rounded shadow">
            <h3 class="font-bold text-gray-700 mb-2 border-b pb-2">รายละเอียด</h3>
            <p class="text-sm text-gray-600 mb-4"><?= nl2br(htmlspecialchars($project['description'])) ?></p>
            <div class="text-xs"><b>กรณีศึกษา:</b> <?= htmlspecialchars($project['case_study']) ?></div>
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
                            <a href="?id=<?= $project_id ?>&remove_member=<?= $m['id'] ?>" onclick="return confirm('ต้องการลบสมาชิกคนนี้ออกจากกลุ่มใช่หรือไม่?')" class="text-red-400 hover:text-red-600 px-2"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if($is_leader): ?>
            <form method="POST" class="mt-4 pt-4 border-t">
                <input type="hidden" name="invite_member" value="1">
                <label class="text-xs font-bold">เชิญสมาชิก (Email)</label>
                <div class="flex gap-1 mt-1">
                    <input type="email" name="email" required class="border text-xs p-1 flex-1 rounded" placeholder="email...">
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
                            <a href="?id=<?= $project_id ?>&cancel_advisor_invite=1&type=main" onclick="return confirm('ต้องการยกเลิกคำเชิญนี้ใช่หรือไม่?')" class="text-red-400 hover:text-red-600 ml-1" title="ยกเลิกคำเชิญ">
                                <i class="fas fa-times-circle"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="text-gray-400 text-xs italic">ยังไม่มี</div>
                    <?php if($is_leader): ?>
                        <form method="POST" class="mt-1 flex gap-1">
                            <input type="hidden" name="invite_advisor" value="1">
                            <input type="hidden" name="type" value="main">
                            <select name="advisor_id" class="text-xs border w-full rounded outline-none p-1" required>
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
                <div class="text-xs font-bold text-gray-500 mb-1">ที่ปรึกษารอง (Co-Advisor)</div>
                <?php if($project['co_advisor_name']): ?>
                    <div class="text-blue-700 font-bold text-sm"><i class="fas fa-check-circle"></i> <?= $project['co_advisor_name'] ?></div>
                <?php elseif($project['pending_co_advisor_name']): ?>
                    <div class="text-yellow-600 text-xs flex items-center gap-1">
                        <i class="fas fa-clock"></i> รอ: <?= $project['pending_co_advisor_name'] ?>
                        <?php if($is_leader): ?>
                            <a href="?id=<?= $project_id ?>&cancel_advisor_invite=1&type=co" onclick="return confirm('ต้องการยกเลิกคำเชิญนี้ใช่หรือไม่?')" class="text-red-400 hover:text-red-600 ml-1" title="ยกเลิกคำเชิญ">
                                <i class="fas fa-times-circle"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="text-gray-400 text-xs italic">ยังไม่มี</div>
                    <?php if($is_leader): ?>
                        <form method="POST" class="mt-1 flex gap-1">
                            <input type="hidden" name="invite_advisor" value="1">
                            <input type="hidden" name="type" value="co">
                            <select name="advisor_id" class="text-xs border w-full rounded outline-none p-1" required>
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
            <?php if($can_edit): ?>
                <button onclick="document.getElementById('modal-task').classList.remove('hidden')" class="bg-purple-800 text-white px-3 py-1 rounded text-sm shadow hover:bg-purple-900">+ เพิ่มงาน</button>
            <?php endif; ?>
        </div>

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
                    <?php if($t['file_path']): ?>
                        <button onclick="openFile('uploads/<?= $t['file_path'] ?>')" class="bg-white border text-gray-700 px-3 py-1 rounded text-xs h-fit shrink-0 shadow-sm hover:bg-gray-50"><i class="fas fa-paperclip"></i> ไฟล์แนบ</button>
                    <?php endif; ?>
                </div>

                <?php if($is_advisor_team && $is_done && $t_status != 'approved'): ?>
                <div class="mt-3 pt-3 border-t flex gap-2 items-center bg-yellow-50 -mx-4 -mb-4 p-3 rounded-b">
                    <div class="text-xs font-bold text-gray-600 mr-2"><i class="fas fa-user-tie"></i> ส่วนอาจารย์:</div>
                    <a href="?id=<?= $project_id ?>&review_task=approve&task_id=<?= $t['id'] ?>" onclick="return confirm('ยืนยันให้ผ่าน?')" class="flex-1 bg-green-600 text-white text-xs px-3 py-1.5 rounded text-center shadow hover:bg-green-700">
                        <i class="fas fa-check"></i> อนุมัติ (ผ่าน)
                    </a>
                    <a href="?id=<?= $project_id ?>&review_task=reject&task_id=<?= $t['id'] ?>" onclick="return confirm('ส่งกลับให้แก้ไข?')" class="flex-1 bg-red-500 text-white text-xs px-3 py-1.5 rounded text-center shadow hover:bg-red-600">
                        <i class="fas fa-undo"></i> ส่งคืน (แก้)
                    </a>
                </div>
                <?php endif; ?>

                <?php if(($is_leader || $is_member) && $t_status != 'approved'): ?>
                <div class="mt-3 pt-3 border-t bg-gray-50 -mx-4 -mb-4 p-3 rounded-b flex flex-wrap gap-2 items-center">
                    <form method="POST" enctype="multipart/form-data" class="flex gap-2 items-center flex-1">
                        <input type="hidden" name="update_task" value="1">
                        <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                        <select name="status" class="text-xs border rounded outline-none p-1"><option value="todo">ทำ</option><option value="done" <?= $is_done?'selected':'' ?>>เสร็จ/ส่งตรวจ</option></select>
                        <input type="file" name="file" class="text-xs w-32">
                        <button class="bg-blue-600 text-white text-xs px-2 py-1 rounded">อัปเดต</button>
                    </form>
                </div>
                <?php endif; ?>

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

<div id="modal-task" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white p-6 rounded w-full max-w-sm">
        <h3 class="font-bold mb-4 text-lg">เพิ่มงาน</h3>
        <form method="POST">
            <input type="hidden" name="add_task" value="1">
            <label class="text-xs font-bold block mb-1">ชื่องาน</label><input type="text" name="task_name" required class="w-full border p-2 mb-2 rounded outline-none">
            <label class="text-xs font-bold block mb-1">ผู้รับผิดชอบ</label><select name="assignee" class="w-full border p-2 mb-2 rounded outline-none"><?php foreach($assignees as $p) echo "<option value='$p'>$p</option>"; ?></select>
            <label class="text-xs font-bold block mb-1">กำหนดส่ง</label><input type="date" name="due_date" required class="w-full border p-2 mb-4 rounded outline-none">
            <div class="flex justify-end gap-2"><button type="button" onclick="document.getElementById('modal-task').classList.add('hidden')" class="px-3 py-1 border rounded">ยกเลิก</button><button class="px-3 py-1 bg-purple-800 text-white rounded">บันทึก</button></div>
        </form>
    </div>
</div>

<div id="modal-comment" class="fixed inset-0 bg-black bg-opacity-70 hidden flex items-center justify-center z-50">
    <div class="bg-white p-0 rounded-lg w-full max-w-lg shadow-2xl flex flex-col h-[500px]">
        <div class="p-4 border-b bg-purple-50 rounded-t-lg flex justify-between items-center">
            <h3 class="font-bold text-purple-800 text-lg" id="comment-modal-title">ความคิดเห็น</h3>
            <button onclick="document.getElementById('modal-comment').classList.add('hidden')" class="text-gray-400 hover:text-red-500 text-xl font-bold">&times;</button>
        </div>
        <div class="flex-1 p-4 overflow-y-auto bg-gray-50 space-y-3" id="comment-list-container"></div>
        <div class="p-3 border-t bg-white rounded-b-lg">
            <form method="POST" class="flex gap-2">
                <input type="hidden" name="add_comment" value="1"><input type="hidden" name="task_id" id="modal-task-id">
                <input type="text" name="comment" placeholder="แสดงความคิดเห็นที่นี่..." class="border flex-1 p-2 rounded-full text-sm outline-none focus:ring-2 focus:ring-purple-300" required>
                <button class="bg-purple-600 text-white w-10 h-10 rounded-full shadow flex items-center justify-center"><i class="fas fa-paper-plane"></i></button>
            </form>
        </div>
    </div>
</div>

<div id="modal-file" class="fixed inset-0 bg-black bg-opacity-80 hidden flex items-center justify-center z-50">
    <div class="bg-white p-2 rounded w-full max-w-4xl h-[90vh] flex flex-col">
        <div class="flex justify-between p-2 border-b items-center"><h3 class="font-bold text-lg">ตัวอย่างไฟล์</h3><button onclick="document.getElementById('modal-file').classList.add('hidden')" class="text-red-500 font-bold text-xl px-2 hover:text-red-700">&times;</button></div>
        <iframe id="file-viewer" class="w-full flex-1 border bg-gray-100"></iframe>
    </div>
</div>

<script>
    // ส่ง id ของ Project ไปใช้ใน JS ด้วย
    const currentProjectId = <?= $project_id ?>;

    function openFile(path) { document.getElementById('file-viewer').src=path; document.getElementById('modal-file').classList.remove('hidden'); }
    
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
    
    const urlParams = new URLSearchParams(window.location.search);
    const status = urlParams.get('status');
    const cleanUrl = () => window.history.replaceState(null, null, window.location.pathname + window.location.search.replace(/[\?&]status=[^&]+/, '').replace(/^&/, '?'));

    if (status === 'reviewed') Swal.fire({icon: 'success', title: 'บันทึกผลการตรวจแล้ว', showConfirmButton: false, timer: 1500}).then(cleanUrl);
    else if (status === 'task_added') Swal.fire({icon: 'success', title: 'เพิ่มงานสำเร็จ', showConfirmButton: false, timer: 1500}).then(cleanUrl);
    else if (status === 'task_deleted') Swal.fire({icon: 'success', title: 'ลบงานเรียบร้อย', showConfirmButton: false, timer: 1500}).then(cleanUrl);
    else if (status === 'comment_deleted') Swal.fire({icon: 'success', title: 'ลบความเห็นแล้ว', showConfirmButton: false, timer: 1500}).then(cleanUrl);
    else if (status === 'already_member') Swal.fire({icon: 'warning', title: 'เป็นสมาชิกอยู่แล้ว'}).then(cleanUrl);
    else if (status === 'invite_success') Swal.fire({icon: 'success', title: 'เชิญสำเร็จ', showConfirmButton: false, timer: 1500}).then(cleanUrl);
    else if (status === 'advisor_error') Swal.fire({icon: 'error', title: 'เลือกซ้ำซ้อน', text: 'อาจารย์ท่านนี้อยู่ในสถานะที่ปรึกษาหลักหรือรองของโครงงานนี้แล้ว'}).then(cleanUrl);

    // ✅ ฟังก์ชัน SweetAlert สำหรับยืนยันการลบงาน (Task)
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
                window.location.href = '?id=' + projectId + '&delete_task=' + taskId;
            }
        });
    }

    // ✅ ฟังก์ชัน SweetAlert สำหรับยืนยันการลบความเห็น (Comment)
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
                window.location.href = '?id=' + projectId + '&delete_comment=' + commentId;
            }
        });
    }
</script>
</body>
</html>