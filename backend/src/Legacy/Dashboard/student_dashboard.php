<?php
session_start();
require_once __DIR__ . '/../System/db_connect.php';

// 1. เช็คสิทธิ์นักศึกษา
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
if (($_SESSION['role'] ?? '') !== 'student') {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_fullname = $_SESSION['fullname']; 

// --- ดึงประกาศข่าวสารจาก Admin ---
$announcement_msg = $conn->query("SELECT message FROM announcements LIMIT 1")->fetchColumn();

// --- ACTION: ตอบรับ/ปฏิเสธ คำเชิญ ---
if (isset($_GET['respond_invite']) && isset($_GET['invite_id'])) {
    $invite_id = $_GET['invite_id'];
    $response = $_GET['respond_invite']; 

    if ($response == 'accept') {
        $stmt = $conn->prepare("UPDATE project_members SET status = 'accepted' WHERE id = ? AND user_id = ?");
        $stmt->execute([$invite_id, $user_id]);
        header("Location: student_dashboard.php?status=joined");
    } elseif ($response == 'decline') {
        $stmt = $conn->prepare("DELETE FROM project_members WHERE id = ? AND user_id = ?");
        $stmt->execute([$invite_id, $user_id]);
        header("Location: student_dashboard.php?status=declined");
    }
    exit;
}

// --- ACTION: สร้างโครงงาน ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'create_project') {
    $name = trim($_POST['name']);
    $desc = trim($_POST['desc']);
    $case_study = trim($_POST['case_study']);

    $check_duplicate = $conn->prepare("
        SELECT id FROM projects 
        WHERE name = ? 
        AND case_study = ? 
        AND created_at >= DATE_SUB(NOW(), INTERVAL 5 YEAR)
    ");
    $check_duplicate->execute([$name, $case_study]);

    if ($check_duplicate->rowCount() > 0) {
        header("Location: student_dashboard.php?status=duplicate");
        exit;
    } else {
        $sql = "INSERT INTO projects (name, description, case_study, student_id, status, progress) VALUES (?, ?, ?, ?, 'pending', 0)";
        if ($conn->prepare($sql)->execute([$name, $desc, $case_study, $user_id])) {
            header("Location: student_dashboard.php?status=created");
            exit;
        }
    }
}

// --- ACTION: ลบโครงงาน (เฉพาะหัวหน้ากลุ่ม Leader) ---
if (isset($_GET['delete_project'])) {
    $del_id = $_GET['delete_project'];
    
    $check_owner = $conn->prepare("SELECT id FROM projects WHERE id = ? AND student_id = ?");
    $check_owner->execute([$del_id, $user_id]);
    
    if ($check_owner->rowCount() > 0) {
        $files = $conn->prepare("SELECT file_path FROM tasks WHERE project_id = ? AND file_path IS NOT NULL");
        $files->execute([$del_id]);
        while ($f = $files->fetch()) { if (file_exists("uploads/".$f['file_path'])) unlink("uploads/".$f['file_path']); }
        $conn->prepare("DELETE FROM projects WHERE id = ?")->execute([$del_id]);
        header("Location: student_dashboard.php?status=deleted"); exit;
    }
}

// --- ACTION: ออกจากกลุ่ม (เฉพาะสมาชิก Member) ---
if (isset($_GET['leave_project'])) {
    $leave_id = $_GET['leave_project'];
    $stmt = $conn->prepare("DELETE FROM project_members WHERE project_id = ? AND user_id = ?");
    $stmt->execute([$leave_id, $user_id]);
    header("Location: student_dashboard.php?status=left"); 
    exit;
}

// --- ACTION: ส่งงาน ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'upload_file') {
    $task_id = $_POST['task_id'];
    if (!empty($_FILES['file']['name'])) {
        $target_dir = "uploads/";
        if (!file_exists($target_dir)) { mkdir($target_dir, 0777, true); }
        $filename = time() . "_" . basename($_FILES["file"]["name"]);
        if (move_uploaded_file($_FILES["file"]["tmp_name"], $target_dir . $filename)) {
            $conn->prepare("UPDATE tasks SET file_path = ?, status = 'done', teacher_status = 'pending' WHERE id = ?")->execute([$filename, $task_id]);
            
            $pid = $conn->query("SELECT project_id FROM tasks WHERE id = $task_id")->fetchColumn();
            $total = $conn->query("SELECT COUNT(*) FROM tasks WHERE project_id = $pid")->fetchColumn();
            $done = $conn->query("SELECT COUNT(*) FROM tasks WHERE project_id = $pid AND teacher_status = 'approved'")->fetchColumn(); 
            $percent = ($total > 0) ? round(($done / $total) * 100) : 0;
            $conn->prepare("UPDATE projects SET progress = ? WHERE id = ?")->execute([$percent, $pid]);
        }
    }
    header("Location: student_dashboard.php"); exit;
}

// ===================== QUERIES =====================

// 1. คำเชิญ
$stmt_invites = $conn->prepare("SELECT pm.id as invite_id, p.name as project_name, u.fullname as leader_name FROM project_members pm JOIN projects p ON pm.project_id = p.id JOIN users u ON p.student_id = u.id WHERE pm.user_id = ? AND pm.status = 'pending'");
$stmt_invites->execute([$user_id]);
$pending_invites = $stmt_invites->fetchAll();

// 2. โครงงานของฉัน
$sql_my_projs = "SELECT p.*, 'leader' as role FROM projects p WHERE p.student_id = ? UNION SELECT p.*, 'member' as role FROM projects p JOIN project_members pm ON p.id = pm.project_id WHERE pm.user_id = ? AND pm.status = 'accepted' ORDER BY id DESC";
$stmt_my_projs = $conn->prepare($sql_my_projs);
$stmt_my_projs->execute([$user_id, $user_id]);
$my_projs = $stmt_my_projs->fetchAll();

// 3. งาน (To-Do)
$stmt_tasks = $conn->prepare("
    SELECT t.*, p.name AS project_name 
    FROM tasks t 
    JOIN projects p ON t.project_id = p.id 
    WHERE t.assignee_name = ? AND t.status != 'done'
    ORDER BY t.due_date ASC
");
$stmt_tasks->execute([$user_fullname]);
$all_pending_tasks = $stmt_tasks->fetchAll();
$total_pending_tasks = count($all_pending_tasks);

// 4. คลังโครงงานสมบูรณ์ (100%)
$search = trim($_GET['search'] ?? ''); 
$filter = $_GET['filter_year'] ?? 'all';

$stmt_years = $conn->query("SELECT DISTINCT YEAR(created_at) as y FROM projects WHERE progress = 100 AND created_at IS NOT NULL ORDER BY y DESC");
$available_years = $stmt_years->fetchAll(PDO::FETCH_COLUMN);

$sql_search = "
    SELECT DISTINCT p.*, u.fullname as student_name 
    FROM projects p 
    JOIN users u ON p.student_id = u.id 
    LEFT JOIN users adv ON p.advisor_id = adv.id
    LEFT JOIN users co_adv ON p.co_advisor_id = co_adv.id
    WHERE p.progress = 100 
    AND (
        u.fullname LIKE ? 
        OR adv.fullname LIKE ? 
        OR co_adv.fullname LIKE ? 
        OR EXISTS (
            SELECT 1 FROM project_members pm 
            JOIN users mu ON pm.user_id = mu.id 
            WHERE pm.project_id = p.id AND pm.status = 'accepted' AND mu.fullname LIKE ?
        )
    )
";

if ($filter !== 'all' && is_numeric($filter)) {
    $sql_search .= " AND YEAR(p.created_at) = " . intval($filter);
}
$sql_search .= " ORDER BY p.id DESC";

$stmt_search = $conn->prepare($sql_search);
$search_param = "%$search%";
$stmt_search->execute([$search_param, $search_param, $search_param, $search_param]);
$completed_projects = $stmt_search->fetchAll();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แดชบอร์ดนักศึกษา</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/rmutp-ui.js"></script>
    <style>@import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;700&display=swap'); body{font-family:'Sarabun',sans-serif;}</style>
</head>
<body class="bg-gray-100 text-gray-800">

<!-- Header Container ทำให้เมนูและประกาศติดขอบบน (Sticky) -->
<header class="sticky top-0 z-50 w-full shadow-lg">
    <nav class="bg-purple-800 text-white p-4 flex justify-between">
        <div class="font-bold text-xl">RMUTP Project</div>
        <div class="flex items-center gap-3">
            <span><?= htmlspecialchars($_SESSION['fullname']) ?></span>
            <a href="approval_center.php" class="bg-indigo-500 text-white px-3 py-1 rounded text-sm hover:bg-indigo-600 font-bold transition">
                <i class="fas fa-route"></i> ศูนย์อนุมัติ
            </a>
            <a href="proposal_center.php" class="bg-blue-600 text-white px-3 py-1 rounded text-sm hover:bg-blue-700 font-bold transition">
                <i class="fas fa-file-signature"></i> ศูนย์ข้อเสนอโครงงาน
            </a>
            <a href="milestone_board.php" class="bg-cyan-600 text-white px-3 py-1 rounded text-sm hover:bg-cyan-700 font-bold transition">
                <i class="fas fa-flag-checkered"></i> กระดานไมล์สโตน
            </a>
            <a href="committee_assignment.php" class="bg-slate-700 text-white px-3 py-1 rounded text-sm hover:bg-slate-800 font-bold transition">
                <i class="fas fa-users"></i> กรรมการ
            </a>
            <a href="edit_profile.php" class="bg-white text-purple-800 px-3 py-1 rounded text-sm hover:bg-gray-100 font-bold transition"><i class="fas fa-user-cog"></i> แก้ไขส่วนตัว</a>
            <a href="logout.php" class="bg-yellow-500 text-black px-3 py-1 rounded text-sm hover:bg-yellow-400 transition">ออกจากระบบ</a>
        </div>
    </nav>

    <!-- แสดงประกาศข่าวสารจากแอดมิน (ถ้ามี) -->
    <?php if(!empty($announcement_msg)): ?>
    <div class="bg-gradient-to-r from-amber-400 via-orange-500 to-red-500 relative overflow-hidden border-t border-purple-900">
        <!-- ลวดลายพื้นหลังจางๆ เพื่อความสวยงาม -->
        <div class="absolute top-0 left-0 w-full h-full bg-white opacity-10 pointer-events-none" style="background-image: repeating-linear-gradient(45deg, transparent, transparent 10px, rgba(255,255,255,0.2) 10px, rgba(255,255,255,0.2) 20px);"></div>
        
        <div class="container mx-auto max-w-6xl px-4 py-3 flex items-center relative z-10">
            <!-- ไอคอนลำโพง -->
            <div class="flex-shrink-0 bg-white/25 p-2.5 rounded-full backdrop-blur-sm mr-4 shadow-inner border border-white/30 flex items-center justify-center">
                <i class="fas fa-bullhorn text-white text-lg animate-pulse drop-shadow-md"></i>
            </div>
            
            <!-- ข้อความประกาศ -->
            <div class="text-white font-medium text-sm md:text-base drop-shadow-md flex-1 leading-relaxed">
                <span class="bg-white text-orange-600 text-[10px] md:text-xs font-extrabold px-3 py-1 rounded-full mr-2 shadow-sm whitespace-nowrap align-middle">
                    ประกาศสำคัญ
                </span>
                <span class="align-middle"><?= htmlspecialchars($announcement_msg) ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>
</header>

<div class="container mx-auto p-6 max-w-6xl">

    <?php if(count($pending_invites) > 0): ?>
    <div class="mb-8 bg-white border-l-4 border-yellow-400 p-6 rounded shadow-lg animate-pulse">
        <h3 class="font-bold text-lg text-yellow-700 mb-4"><i class="fas fa-envelope-open-text mr-2"></i> คำเชิญเข้าร่วมกลุ่ม</h3>
        <div class="grid gap-4 md:grid-cols-2">
            <?php foreach($pending_invites as $invite): ?>
                <div class="border p-4 rounded bg-yellow-50 flex justify-between items-center shadow-sm">
                    <div>
                        <div class="font-bold text-gray-800 text-lg"><?= htmlspecialchars($invite['project_name']) ?></div>
                        <div class="text-sm text-gray-500">โดย: <?= htmlspecialchars($invite['leader_name']) ?></div>
                    </div>
                    <div class="flex gap-2">
                        <a href="?respond_invite=accept&invite_id=<?= $invite['invite_id'] ?>" class="bg-green-500 text-white px-3 py-1 rounded text-sm hover:bg-green-600">รับ</a>
                        <a href="#" onclick="confirmInviteDecline(event, <?= (int)$invite['invite_id'] ?>)" class="bg-red-500 text-white px-3 py-1 rounded text-sm hover:bg-red-600">ปฏิเสธ</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
        
        <div class="lg:col-span-2 space-y-4">
            <div class="flex justify-between items-center">
                <h3 class="font-bold text-xl text-purple-800"><i class="fas fa-folder-open"></i> โครงงานของฉัน</h3>
                <button onclick="document.getElementById('modal-create').classList.remove('hidden')" class="bg-green-600 text-white px-4 py-2 rounded shadow text-sm hover:bg-green-700"><i class="fas fa-plus"></i> สร้างใหม่</button>
            </div>
            
            <?php if(count($my_projs) > 0): ?>
                <?php 
                $visible_my_projs = array_slice($my_projs, 0, 1);
                foreach($visible_my_projs as $p): 
                ?>
                <div class="bg-white p-6 rounded-lg shadow-md border-l-4 border-purple-800 relative hover:shadow-xl transition">
                    <div class="absolute top-4 right-4 bg-<?= $p['role']=='leader'?'yellow':'blue' ?>-100 text-<?= $p['role']=='leader'?'yellow':'blue' ?>-800 px-2 py-1 rounded-full text-xs font-bold">
                        <?= $p['role']=='leader'?'<i class="fas fa-crown"></i> หัวหน้าทีม':'<i class="fas fa-user"></i> สมาชิก' ?>
                    </div>
                    <h4 class="font-bold text-lg text-gray-800"><?= htmlspecialchars($p['name']) ?></h4>
                    <p class="text-gray-600 text-sm mt-1">กรณีศึกษา: <?= htmlspecialchars($p['case_study']) ?></p>
                    <div class="mt-4">
                        <div class="flex justify-between text-xs mb-1"><span>ความคืบหน้า</span><span class="font-bold"><?= $p['progress'] ?>%</span></div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5"><div class="bg-green-600 h-2.5 rounded-full" style="width:<?= $p['progress'] ?>%"></div></div>
                    </div>
                    <div class="flex gap-2 mt-4">
                        <a href="project_detail.php?id=<?= $p['id'] ?>" class="flex-1 block text-center bg-purple-800 text-white py-2 rounded hover:bg-purple-900 text-sm shadow">เข้าสู่ระบบโครงงาน</a>
                        
                        <?php if($p['role'] == 'leader'): ?>
                            <a href="#" onclick="confirmDelete(event, <?= $p['id'] ?>)" class="bg-red-100 text-red-600 px-3 py-2 rounded hover:bg-red-200 border border-red-200" title="ลบโครงงาน"><i class="fas fa-trash-alt"></i></a>
                        <?php else: ?>
                            <a href="#" onclick="confirmLeave(event, <?= $p['id'] ?>)" class="bg-orange-100 text-orange-600 px-3 py-2 rounded hover:bg-orange-200 border border-orange-200" title="ออกจากกลุ่ม"><i class="fas fa-sign-out-alt"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if(count($my_projs) > 1): ?>
                <button onclick="document.getElementById('modal-my-projects').classList.remove('hidden')" class="w-full text-center text-sm text-purple-700 hover:text-purple-900 font-bold mt-2 bg-purple-50 p-2 rounded hover:bg-purple-100 transition border border-purple-100">
                    ดูโครงงานทั้งหมด (<span data-rt-student-my-projects><?= (int)count($my_projs) ?></span>) <i class="fas fa-list"></i>
                </button>
                <?php endif; ?>

            <?php else: ?>
                <div class="bg-white p-12 rounded-lg border-2 border-dashed border-gray-300 text-center text-gray-500">
                    <i class="fas fa-folder-open text-4xl mb-3 text-gray-300"></i><br>ยังไม่มีโครงงาน
                </div>
            <?php endif; ?>
        </div>

        <div class="space-y-3">
            <div class="flex justify-between items-center mb-2">
                <h3 class="font-bold text-lg text-gray-700"><i class="fas fa-tasks mr-2"></i> งานที่ต้องส่ง</h3>
                <span class="bg-red-100 text-red-800 text-xs px-2 py-1 rounded-full font-bold"><span data-rt-student-pending-tasks><?= (int)$total_pending_tasks ?></span> งานค้าง</span>
            </div>
            
            <?php if($total_pending_tasks > 0): ?>
                <?php 
                $displayed_tasks = array_slice($all_pending_tasks, 0, 2);
                foreach($displayed_tasks as $t): 
                    $due = strtotime($t['due_date']);
                    $days = ceil(($due - time())/86400);
                    $t_status = $t['teacher_status'] ?? 'pending';
                    
                    $status_badge = "";
                    $border_cal = 'border-l-4 border-yellow-400';

                    if ($t_status == 'rejected') {
                        $border_cal = 'border-l-4 border-red-500 bg-red-50';
                        $status_badge = '<span class="text-red-600 font-bold"><i class="fas fa-exclamation-circle"></i> แก้ไขงาน</span>';
                    } elseif ($days < 0) {
                        $border_cal = 'border-l-4 border-red-400';
                        $status_badge = '<span class="text-red-500 font-bold">เลยกำหนด</span>';
                    } elseif ($days >= 0 && $days <= 3) {
                        $border_cal = 'border-l-4 border-orange-500 bg-orange-50';
                        $status_badge = '<span class="text-orange-600 font-bold animate-pulse"><i class="fas fa-fire"></i> ใกล้กำหนด (เหลือ '.$days.' วัน)</span>';
                    } else {
                        $status_badge = '<span class="text-gray-500">เหลือ '.$days.' วัน</span>';
                    }
                ?>
                <div class="<?= $border_cal ?> p-4 rounded shadow-md relative bg-white hover:-translate-y-1 transition flex flex-col justify-between">
                    <a href="project_detail.php?id=<?= $t['project_id'] ?>" class="block group mb-2">
                        <div class="text-[10px] text-purple-600 font-bold mb-1 uppercase tracking-wide truncate group-hover:underline">
                            <i class="fas fa-project-diagram mr-1"></i> <?= htmlspecialchars($t['project_name']) ?>
                        </div>
                        <h5 class="font-bold text-sm text-gray-800 truncate group-hover:text-purple-700"><?= htmlspecialchars($t['name']) ?></h5>
                    </a>
                    
                    <div class="mt-auto">
                        <div class="text-xs flex justify-between items-center">
                            <?= $status_badge ?>
                            <span class="text-gray-400"><?= date('d/M', $due) ?></span>
                        </div>
                        
                        <form method="POST" enctype="multipart/form-data" class="mt-3 flex gap-2 items-center border-t pt-3">
                            <input type="hidden" name="action" value="upload_file">
                            <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                            <input type="file" name="file" class="text-[10px] flex-1 text-gray-600 file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:text-[10px] file:font-semibold file:bg-gray-100 file:text-purple-700 hover:file:bg-gray-200 cursor-pointer" required>
                            <button type="submit" class="bg-purple-600 text-white text-[10px] px-3 py-1.5 rounded hover:bg-purple-700 shadow-sm shrink-0 flex items-center gap-1">
                                <i class="fas fa-paper-plane"></i> ส่ง
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if($total_pending_tasks > 2): ?>
                <button onclick="document.getElementById('modal-all-tasks').classList.remove('hidden')" class="w-full text-center text-sm text-purple-700 hover:text-purple-900 font-bold mt-2 bg-purple-50 p-2 rounded hover:bg-purple-100 transition">
                    ดูงานที่เหลือทั้งหมด (<span data-rt-student-pending-tasks><?= (int)$total_pending_tasks ?></span>) <i class="fas fa-list"></i>
                </button>
                <?php endif; ?>

            <?php else: ?>
                <div class="text-center text-gray-400 py-8 bg-white rounded shadow-sm border border-gray-100">
                    <i class="fas fa-check-circle text-3xl text-green-200 mb-2"></i><br>ไม่มีงานค้างส่ง
                </div>
            <?php endif; ?>
        </div>
    </div>

    <hr class="my-10 border-gray-300">
    <div class="bg-white p-6 rounded-lg shadow-lg border border-gray-200">
        <h2 class="font-bold text-xl mb-4 text-gray-700 flex items-center">
            <i class="fas fa-archive mr-2"></i> คลังโครงงานสมบูรณ์ (100%)
        </h2>
        
        <form method="GET" class="flex flex-col md:flex-row gap-4 mb-6 bg-gray-50 p-4 rounded-lg">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหาด้วยชื่อบุคคล (นศ. หรือ อาจารย์)..." class="border p-2 rounded flex-1 focus:outline-none focus:ring-2 focus:ring-purple-500">
            <select name="filter_year" class="border p-2 rounded bg-white focus:outline-none focus:ring-2 focus:ring-purple-500">
                <option value="all" <?= $filter=='all'?'selected':'' ?>>ทุกปี พ.ศ.</option>
                <?php foreach($available_years as $y): $th_year = $y + 543; ?>
                    <option value="<?= $y ?>" <?= $filter==strval($y)?'selected':'' ?>>ปี พ.ศ. <?= $th_year ?></option>
                <?php endforeach; ?>
            </select>
            <button class="bg-gray-800 text-white px-6 py-2 rounded hover:bg-gray-900 transition flex items-center justify-center">
                <i class="fas fa-search mr-2"></i> ค้นหา
            </button>
        </form>

        <?php 
        $is_searching = (!empty($search) || $filter !== 'all');
        
        if ($is_searching) {
            $visible_completed_projects = $completed_projects;
            $show_view_all_btn = false;
        } else {
            $visible_completed_projects = array_slice($completed_projects, 0, 3);
            $show_view_all_btn = count($completed_projects) > 3;
        }
        ?>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach($visible_completed_projects as $cp): ?>
            <div class="border rounded-lg p-4 hover:shadow-lg bg-white flex flex-col justify-between transition h-full group">
                <div>
                    <div class="flex justify-between items-start">
                        <h4 class="font-bold text-purple-900 line-clamp-1 text-lg group-hover:text-purple-700 transition"><?= htmlspecialchars($cp['name']) ?></h4>
                        <span class="bg-green-100 text-green-800 text-xs px-2 py-1 rounded font-bold h-fit whitespace-nowrap border border-green-200">100%</span>
                    </div>
                    <p class="text-sm text-gray-600 mt-2 line-clamp-2"><?= htmlspecialchars($cp['description']) ?></p>
                    
                    <div class="text-xs text-gray-500 mt-3 border-t pt-2 flex justify-between items-center">
                        <div class="flex items-center truncate mr-2">
                            <i class="fas fa-user-graduate mr-2 text-gray-400"></i> <span class="truncate"><?= htmlspecialchars($cp['student_name']) ?></span>
                        </div>
                        <div class="bg-purple-100 text-purple-800 px-2 py-1 rounded font-bold shrink-0">
                            ปี <?= date('Y', strtotime($cp['created_at'])) + 543 ?>
                        </div>
                    </div>
                </div>
                <a href="project_detail.php?id=<?= $cp['id'] ?>" class="mt-4 block text-center border border-purple-800 text-purple-800 text-sm py-2 rounded hover:bg-blue-800 hover:text-white transition">
                    ดูรายละเอียด
                </a>
            </div>
            <?php endforeach; ?>
            
            <?php if(count($completed_projects) == 0): ?>
                <div class="col-span-full text-center py-10 text-gray-400 bg-gray-50 rounded-lg border border-dashed border-gray-300">
                    <i class="fas fa-search-minus text-4xl mb-2"></i><br>
                    ไม่พบโครงงานที่ค้นหา
                </div>
            <?php endif; ?>
        </div>

        <?php if($show_view_all_btn): ?>
        <div class="mt-6 text-center">
            <button onclick="document.getElementById('modal-all-projects').classList.remove('hidden')" class="bg-gray-100 text-gray-700 px-6 py-2 rounded-full hover:bg-gray-200 transition font-bold text-sm flex items-center justify-center mx-auto">
                ดูทั้งหมด (<?= count($completed_projects) ?>) <i class="fas fa-arrow-right ml-2"></i>
            </button>
        </div>
        <?php endif; ?>
    </div>

</div>

<div id="modal-create" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white p-6 rounded-lg w-full max-w-md shadow-xl transform transition-all">
        <h3 class="font-bold mb-4 text-lg">สร้างโครงงานใหม่</h3>
        <form method="POST">
            <input type="hidden" name="action" value="create_project">
            <div class="space-y-3">
                <input type="text" name="name" required placeholder="ชื่อโครงงาน" class="w-full border p-2 rounded focus:ring-2 focus:ring-purple-500 outline-none">
                <input type="text" name="case_study" required placeholder="กรณีศึกษา" class="w-full border p-2 rounded focus:ring-2 focus:ring-purple-500 outline-none">
                <textarea name="desc" placeholder="รายละเอียด" class="w-full border p-2 rounded focus:ring-2 focus:ring-purple-500 outline-none h-24"></textarea>
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <button type="button" onclick="document.getElementById('modal-create').classList.add('hidden')" class="px-4 py-2 border rounded hover:bg-gray-100 transition">ยกเลิก</button>
                <button class="px-4 py-2 bg-purple-800 text-white rounded hover:bg-purple-900 transition">บันทึก</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-my-projects" class="fixed inset-0 bg-black bg-opacity-70 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-lg w-full max-w-5xl h-[85vh] flex flex-col">
        <div class="p-4 border-b flex justify-between items-center bg-gray-50 rounded-t-lg">
            <h3 class="font-bold text-xl text-purple-800"><i class="fas fa-folder-open mr-2"></i> โครงงานของฉันทั้งหมด</h3>
            <button onclick="document.getElementById('modal-my-projects').classList.add('hidden')" class="text-gray-400 hover:text-red-500 text-2xl font-bold">&times;</button>
        </div>
        <div class="flex-1 p-6 overflow-y-auto bg-gray-50">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach($my_projs as $p): ?>
                <div class="bg-white p-6 rounded-lg shadow-md border-l-4 border-purple-800 relative hover:shadow-xl transition">
                    <div class="absolute top-4 right-4 bg-<?= $p['role']=='leader'?'yellow':'blue' ?>-100 text-<?= $p['role']=='leader'?'yellow':'blue' ?>-800 px-2 py-1 rounded-full text-xs font-bold">
                        <?= $p['role']=='leader'?'<i class="fas fa-crown"></i> หัวหน้าทีม':'<i class="fas fa-user"></i> สมาชิก' ?>
                    </div>
                    <h4 class="font-bold text-lg text-gray-800"><?= htmlspecialchars($p['name']) ?></h4>
                    <p class="text-gray-600 text-sm mt-1">กรณีศึกษา: <?= htmlspecialchars($p['case_study']) ?></p>
                    <div class="mt-4">
                        <div class="flex justify-between text-xs mb-1"><span>ความคืบหน้า</span><span class="font-bold"><?= $p['progress'] ?>%</span></div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5"><div class="bg-green-600 h-2.5 rounded-full" style="width:<?= $p['progress'] ?>%"></div></div>
                    </div>
                    <div class="flex gap-2 mt-4">
                        <a href="project_detail.php?id=<?= $p['id'] ?>" class="flex-1 block text-center bg-purple-800 text-white py-2 rounded hover:bg-purple-900 text-sm shadow">เข้าสู่ระบบโครงงาน</a>
                        
                        <?php if($p['role'] == 'leader'): ?>
                            <a href="#" onclick="confirmDelete(event, <?= $p['id'] ?>)" class="bg-red-100 text-red-600 px-3 py-2 rounded hover:bg-red-200 border border-red-200" title="ลบโครงงาน"><i class="fas fa-trash-alt"></i></a>
                        <?php else: ?>
                            <a href="#" onclick="confirmLeave(event, <?= $p['id'] ?>)" class="bg-orange-100 text-orange-600 px-3 py-2 rounded hover:bg-orange-200 border border-orange-200" title="ออกจากกลุ่ม"><i class="fas fa-sign-out-alt"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div id="modal-all-tasks" class="fixed inset-0 bg-black bg-opacity-60 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-lg w-full max-w-3xl shadow-2xl h-[80vh] flex flex-col">
        <div class="p-4 border-b flex justify-between items-center bg-purple-800 text-white rounded-t-lg">
            <h3 class="font-bold text-lg"><i class="fas fa-tasks mr-2"></i> งานที่ต้องส่งทั้งหมด (<span data-rt-student-pending-tasks><?= (int)$total_pending_tasks ?></span>)</h3>
            <button onclick="document.getElementById('modal-all-tasks').classList.add('hidden')" class="text-white hover:text-gray-300 text-xl font-bold">&times;</button>
        </div>
        
        <div class="flex-1 p-4 overflow-y-auto bg-gray-50">
            <?php if(count($all_pending_tasks) > 0): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse bg-white shadow-sm rounded-lg overflow-hidden">
                    <thead class="bg-gray-100 border-b">
                        <tr>
                            <th class="p-3 text-sm font-bold text-gray-600">ชื่องาน / โครงงาน</th>
                            <th class="p-3 text-sm font-bold text-gray-600 text-center">สถานะ</th>
                            <th class="p-3 text-sm font-bold text-gray-600 text-center">ส่งงาน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($all_pending_tasks as $t): 
                             $due = strtotime($t['due_date']);
                             $days = ceil(($due - time())/86400);
                             $t_status = $t['teacher_status'] ?? 'pending';
                             
                             $status_badge_table = "";
                             if ($t_status == 'rejected') {
                                 $status_badge_table = '<span class="bg-red-100 text-red-800 text-[10px] px-2 py-1 rounded-full font-bold">แก้ไข</span>';
                             } elseif ($days < 0) {
                                 $status_badge_table = '<span class="text-red-500 font-bold text-xs">เลยกำหนด</span>';
                             } elseif ($days >= 0 && $days <= 3) {
                                 $status_badge_table = '<span class="bg-orange-100 text-orange-800 text-[10px] px-2 py-1 rounded-full font-bold">ด่วน ('.$days.' วัน)</span>';
                             } else {
                                 $status_badge_table = '<span class="text-gray-500 text-xs">เหลือ '.$days.' วัน</span>';
                             }
                        ?>
                        <tr class="border-b hover:bg-purple-50 transition">
                            <td class="p-3">
                                <a href="project_detail.php?id=<?= $t['project_id'] ?>" class="block group">
                                    <div class="text-sm font-bold text-gray-800 group-hover:text-purple-700 group-hover:underline"><?= htmlspecialchars($t['name']) ?></div>
                                    <div class="text-xs text-purple-600"><?= htmlspecialchars($t['project_name']) ?></div>
                                </a>
                            </td>
                            <td class="p-3 text-center text-xs">
                                <div class="mb-1"><?= date('d/m/Y', $due) ?></div>
                                <?= $status_badge_table ?>
                            </td>
                            <td class="p-3">
                                <form method="POST" enctype="multipart/form-data" class="flex gap-2 items-center justify-center">
                                    <input type="hidden" name="action" value="upload_file">
                                    <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                                    <input type="file" name="file" class="text-[10px] w-32 text-gray-500 file:mr-1 file:py-1 file:px-2 file:rounded file:border-0 file:text-[10px] file:font-semibold file:bg-gray-100 file:text-purple-700 hover:file:bg-gray-200 cursor-pointer" required>
                                    <button type="submit" class="bg-purple-600 text-white text-[10px] px-3 py-1.5 rounded hover:bg-purple-700 shadow-sm shrink-0">ส่ง</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <p class="text-center text-gray-500 mt-10">ไม่พบงานค้างส่ง</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="modal-all-projects" class="fixed inset-0 bg-black bg-opacity-70 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-lg w-full max-w-5xl h-[85vh] flex flex-col">
        <div class="p-4 border-b flex justify-between items-center bg-gray-50 rounded-t-lg">
            <h3 class="font-bold text-xl text-gray-800"><i class="fas fa-archive mr-2"></i> คลังโครงงานทั้งหมด (<?= count($completed_projects) ?>)</h3>
            <button onclick="document.getElementById('modal-all-projects').classList.add('hidden')" class="text-gray-400 hover:text-red-500 text-2xl font-bold">&times;</button>
        </div>
        
        <div class="flex-1 p-6 overflow-y-auto bg-gray-50">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach($completed_projects as $cp): ?>
                <div class="border rounded-lg p-4 hover:shadow-lg bg-white flex flex-col justify-between transition h-full group">
                    <div>
                        <div class="flex justify-between items-start">
                            <h4 class="font-bold text-purple-900 line-clamp-1 text-lg group-hover:text-purple-700 transition"><?= htmlspecialchars($cp['name']) ?></h4>
                            <span class="bg-green-100 text-green-800 text-xs px-2 py-1 rounded font-bold h-fit whitespace-nowrap border border-green-200">100%</span>
                        </div>
                        <p class="text-sm text-gray-600 mt-2 line-clamp-2"><?= htmlspecialchars($cp['description']) ?></p>
                        
                        <div class="text-xs text-gray-500 mt-3 border-t pt-2 flex justify-between items-center">
                            <div class="flex items-center truncate mr-2">
                                <i class="fas fa-user-graduate mr-2 text-gray-400"></i> <span class="truncate"><?= htmlspecialchars($cp['student_name']) ?></span>
                            </div>
                            <div class="bg-blue-100 text-blue-800 px-2 py-1 rounded font-bold shrink-0">
                                ปี <?= date('Y', strtotime($cp['created_at'])) + 543 ?>
                            </div>
                        </div>
                    </div>
                    <a href="project_detail.php?id=<?= $cp['id'] ?>" class="mt-4 block text-center border border-blue-800 text-blue-800 text-sm py-2 rounded hover:bg-blue-800 hover:text-white transition">
                        ดูรายละเอียด
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div id="modal-file" class="fixed inset-0 bg-black bg-opacity-80 hidden flex items-center justify-center z-50">
    <div class="bg-white p-2 rounded-lg w-full max-w-4xl h-[90vh] flex flex-col">
        <div class="flex justify-between p-2 border-b items-center">
            <h3 class="font-bold text-lg">ตัวอย่างไฟล์</h3>
            <button onclick="document.getElementById('modal-file').classList.add('hidden')" class="text-red-500 font-bold text-xl px-2 hover:text-red-700">&times;</button>
        </div>
        <iframe id="file-viewer" class="w-full flex-1 border bg-gray-100"></iframe>
    </div>
</div>

<script>
    window.openFile = function (path) {
        window.RMUTP.openFilePreview(path);
    };

    window.RMUTP.showStatusFromQuery({
        created: { icon: 'success', title: 'สร้างโครงงานสำเร็จ', showConfirmButton: false, timer: 1500 },
        duplicate: {
            icon: 'error',
            title: 'ข้อมูลซ้ำซ้อน!',
            text: 'มีโครงงานชื่อนี้และกรณีศึกษานี้อยู่ในระบบแล้ว กรุณาตรวจสอบหรือเปลี่ยนกรณีศึกษา',
            confirmButtonText: 'ตกลง'
        },
        joined: { icon: 'success', title: 'เข้าร่วมกลุ่มแล้ว', timer: 1500, showConfirmButton: false },
        deleted: { icon: 'success', title: 'ลบโครงงานเรียบร้อย', timer: 1500, showConfirmButton: false },
        left: { icon: 'success', title: 'ออกจากกลุ่มเรียบร้อย', timer: 1500, showConfirmButton: false }
    });

    const applyRealtimeStudent = function (data) {
        const counters = data && data.counters ? data.counters : {};
        window.RMUTP.updateTextMany('[data-rt-student-pending-tasks]', Number(counters.pending_tasks ?? 0));
        window.RMUTP.updateTextMany('[data-rt-student-my-projects]', Number(counters.my_projects ?? 0));
    };

    const stopRealtime = window.RMUTP.startRealtimePoller({
        scope: 'student',
        intervalMs: 12000,
        onData: applyRealtimeStudent
    });

    window.addEventListener('beforeunload', stopRealtime);
    window.RMUTP.attachFormSubmitGuard();
    window.RMUTP.attachActionLinkGuard('a[href*="respond_invite=accept"]', 'กำลังตอบรับคำเชิญ...');

    window.confirmDelete = function (event, projectId) {
        window.RMUTP.confirmAndNavigate(event, {
            title: 'ยืนยันลบโครงงาน?',
            text: 'การกระทำนี้จะลบข้อมูลทั้งหมดที่เกี่ยวข้องและไม่สามารถกู้คืนได้!',
            icon: 'warning',
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'ใช่, ลบเลย!',
            cancelButtonText: 'ยกเลิก',
            url: '?delete_project=' + projectId,
            loadingText: 'กำลังลบโครงงาน...'
        });
    };

    window.confirmLeave = function (event, projectId) {
        window.RMUTP.confirmAndNavigate(event, {
            title: 'ยืนยันออกจากกลุ่มโครงงานนี้?',
            text: 'คุณจะถูกลบออกจากรายชื่อสมาชิกของโครงงานนี้',
            icon: 'warning',
            confirmButtonColor: '#f97316',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'ใช่, ออกจากกลุ่ม!',
            cancelButtonText: 'ยกเลิก',
            url: '?leave_project=' + projectId,
            loadingText: 'กำลังออกจากกลุ่ม...'
        });
    };

    window.confirmInviteDecline = function (event, inviteId) {
        window.RMUTP.confirmAndNavigate(event, {
            title: 'ยืนยันปฏิเสธคำเชิญ?',
            icon: 'question',
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'ปฏิเสธ',
            cancelButtonText: 'ยกเลิก',
            url: '?respond_invite=decline&invite_id=' + inviteId,
            loadingText: 'กำลังอัปเดตคำเชิญ...'
        });
    };
</script>
</body>
</html>
