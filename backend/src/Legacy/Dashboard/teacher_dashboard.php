<?php
session_start();
require_once __DIR__ . '/../System/db_connect.php';

// 1. เช็คสิทธิ์ (ต้องเป็น Teacher)
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
if (($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$fullname = $_SESSION['fullname'];
$script_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$upload_web_base = preg_match('#/frontend/public$#', $script_dir)
    ? $script_dir . '/uploads'
    : $script_dir . '/frontend/public/uploads';

// --- ดึงประกาศข่าวสารจาก Admin ---
$announcement_msg = $conn->query("SELECT message FROM announcements LIMIT 1")->fetchColumn();

// --- ACTION: ตอบรับ/ปฏิเสธ คำเชิญเป็นที่ปรึกษา ---
if (isset($_GET['action']) && isset($_GET['project_id']) && isset($_GET['type'])) {
    $pid = $_GET['project_id'];
    $type = $_GET['type'];
    $act = $_GET['action'];

    if ($act == 'accept') {
        if ($type == 'main') {
            $sql = "UPDATE projects SET advisor_id = ?, pending_advisor_id = NULL WHERE id = ?";
        } else {
            $sql = "UPDATE projects SET co_advisor_id = ?, pending_co_advisor_id = NULL WHERE id = ?";
        }
        $conn->prepare($sql)->execute([$user_id, $pid]);
        header("Location: teacher_dashboard.php?status=accepted");

    } elseif ($act == 'decline') {
        if ($type == 'main') {
            $sql = "UPDATE projects SET pending_advisor_id = NULL WHERE id = ?";
        } else {
            $sql = "UPDATE projects SET pending_co_advisor_id = NULL WHERE id = ?";
        }
        $conn->prepare($sql)->execute([$pid]);
        header("Location: teacher_dashboard.php?status=declined");
    }
    exit;
}

// --- ACTION: ตรวจสอบงาน (Approve / Reject) จากหน้า Dashboard ---
if (isset($_GET['review_task']) && isset($_GET['task_id']) && isset($_GET['project_id'])) {
    $tid = $_GET['task_id'];
    $pid = $_GET['project_id'];
    $review = $_GET['review_task'];

    // เช็คความปลอดภัย ว่าอาจารย์คนนี้ดูแลโปรเจกต์นี้จริงๆ
    $chk = $conn->prepare("SELECT id FROM projects WHERE id=? AND (advisor_id=? OR co_advisor_id=?)");
    $chk->execute([$pid, $user_id, $user_id]);
    
    if($chk->rowCount() > 0) {
        if ($review == 'approve') {
            $conn->prepare("UPDATE tasks SET teacher_status = 'approved' WHERE id = ?")->execute([$tid]);
        } elseif ($review == 'reject') {
            $conn->prepare("UPDATE tasks SET teacher_status = 'rejected', status = 'todo' WHERE id = ?")->execute([$tid]);
        }
        
        // คำนวณความคืบหน้า (Progress) ใหม่
        $total = $conn->query("SELECT COUNT(*) FROM tasks WHERE project_id=$pid")->fetchColumn();
        $done = $conn->query("SELECT COUNT(*) FROM tasks WHERE project_id=$pid AND teacher_status='approved'")->fetchColumn(); 
        $pct = ($total > 0) ? round(($done / $total) * 100) : 0;
        $conn->prepare("UPDATE projects SET progress=? WHERE id=?")->execute([$pct, $pid]);
        
        header("Location: teacher_dashboard.php?status=reviewed");
        exit;
    }
}

// ===================== QUERIES =====================

// --- QUERY 1 & 2: คำเชิญ ---
$stmt_pending_main = $conn->prepare("SELECT p.*, u.fullname as student_name FROM projects p JOIN users u ON p.student_id = u.id WHERE p.pending_advisor_id = ?");
$stmt_pending_main->execute([$user_id]);
$pending_main = $stmt_pending_main->fetchAll();

$stmt_pending_co = $conn->prepare("SELECT p.*, u.fullname as student_name FROM projects p JOIN users u ON p.student_id = u.id WHERE p.pending_co_advisor_id = ?");
$stmt_pending_co->execute([$user_id]);
$pending_co = $stmt_pending_co->fetchAll();

// --- QUERY 3: โครงงานที่ดูแลอยู่ ---
$stmt_my_projects = $conn->prepare("
    SELECT p.*, u.fullname as student_name,
           CASE 
               WHEN p.advisor_id = ? THEN 'Main Advisor'
               WHEN p.co_advisor_id = ? THEN 'Co-Advisor'
           END as my_role
    FROM projects p
    JOIN users u ON p.student_id = u.id
    WHERE p.advisor_id = ? OR p.co_advisor_id = ?
    ORDER BY p.id DESC
");
$stmt_my_projects->execute([$user_id, $user_id, $user_id, $user_id]);
$my_projects = $stmt_my_projects->fetchAll();

// --- QUERY 4: งานที่รอการตรวจสอบ (Pending Review) ---
$stmt_pending_tasks = $conn->prepare("
    SELECT t.*, p.name AS project_name, p.id AS project_id
    FROM tasks t 
    JOIN projects p ON t.project_id = p.id 
    WHERE (p.advisor_id = ? OR p.co_advisor_id = ?) 
      AND t.status = 'done' 
      AND t.teacher_status = 'pending'
    ORDER BY t.due_date ASC
");
$stmt_pending_tasks->execute([$user_id, $user_id]);
$pending_tasks = $stmt_pending_tasks->fetchAll();
$total_pending_tasks = count($pending_tasks);

// --- QUERY 5: คลังโครงงานสมบูรณ์ (100%) ---
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
    <title>Teacher Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>@import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;700&display=swap'); body{font-family:'Sarabun',sans-serif;}</style>
</head>
<body class="bg-gray-50 text-gray-800">

<!-- Header Container ทำให้เมนูและประกาศติดขอบบน (Sticky) -->
<header class="sticky top-0 z-50 w-full shadow-lg">
    <nav class="bg-blue-900 text-white p-4 flex justify-between">
        <div class="font-bold text-xl flex items-center gap-2">
            <i class="fas fa-chalkboard-teacher"></i> RMUTP Teacher
        </div>
        <div class="flex items-center gap-3">
            <span class="mr-1 text-sm opacity-90">อาจารย์ <?= htmlspecialchars($fullname) ?></span>
            <a href="edit_profile.php" class="bg-white text-blue-900 px-3 py-1 rounded text-sm hover:bg-gray-100 font-bold transition">
                <i class="fas fa-user-cog"></i> แก้ไขส่วนตัว
            </a>
            <a href="logout.php" class="bg-yellow-500 text-black px-3 py-1 rounded text-sm hover:bg-yellow-400 transition">Logout</a>
        </div>
    </nav>

    <!-- แสดงประกาศข่าวสารจากแอดมิน (ถ้ามี) -->
    <?php if(!empty($announcement_msg)): ?>
    <div class="bg-gradient-to-r from-amber-400 via-orange-500 to-red-500 relative overflow-hidden border-t border-blue-950">
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

    <!-- Section: คำขอเป็นที่ปรึกษา -->
    <?php if(count($pending_main) > 0 || count($pending_co) > 0): ?>
    <div class="mb-10 animate-fade-in-down">
        <h3 class="font-bold text-xl text-yellow-700 mb-4 flex items-center">
            <i class="fas fa-bell mr-2 animate-swing"></i> คำขอเป็นที่ปรึกษา (Pending Request)
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <?php foreach($pending_main as $p): ?>
            <div class="bg-white border-l-4 border-yellow-500 p-5 rounded shadow-md flex flex-col justify-between">
                <div>
                    <div class="flex justify-between items-start">
                        <h4 class="font-bold text-lg text-gray-800"><?= htmlspecialchars($p['name']) ?></h4>
                        <span class="bg-yellow-100 text-yellow-800 text-xs px-2 py-1 rounded-full font-bold">Main Advisor</span>
                    </div>
                    <p class="text-sm text-gray-600 mt-2">โดย: <?= htmlspecialchars($p['student_name']) ?></p>
                </div>
                <div class="flex gap-2 mt-4 pt-4 border-t">
                    <a href="?action=accept&project_id=<?= $p['id'] ?>&type=main" class="flex-1 bg-green-600 hover:bg-green-700 text-white text-center py-2 rounded text-sm shadow transition"><i class="fas fa-check-circle"></i> ตอบรับ</a>
                    <a href="?action=decline&project_id=<?= $p['id'] ?>&type=main" onclick="return confirm('ปฏิเสธ?')" class="flex-1 bg-red-100 hover:bg-red-200 text-red-700 text-center py-2 rounded text-sm transition">ปฏิเสธ</a>
                </div>
            </div>
            <?php endforeach; ?>
            <?php foreach($pending_co as $p): ?>
            <div class="bg-white border-l-4 border-blue-400 p-5 rounded shadow-md flex flex-col justify-between">
                <div>
                    <div class="flex justify-between items-start">
                        <h4 class="font-bold text-lg text-gray-800"><?= htmlspecialchars($p['name']) ?></h4>
                        <span class="bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded-full font-bold">Co-Advisor</span>
                    </div>
                    <p class="text-sm text-gray-600 mt-2">โดย: <?= htmlspecialchars($p['student_name']) ?></p>
                </div>
                <div class="flex gap-2 mt-4 pt-4 border-t">
                    <a href="?action=accept&project_id=<?= $p['id'] ?>&type=co" class="flex-1 bg-green-600 hover:bg-green-700 text-white text-center py-2 rounded text-sm shadow transition"><i class="fas fa-check-circle"></i> ตอบรับ</a>
                    <a href="?action=decline&project_id=<?= $p['id'] ?>&type=co" onclick="return confirm('ปฏิเสธ?')" class="flex-1 bg-red-100 hover:bg-red-200 text-red-700 text-center py-2 rounded text-sm transition">ปฏิเสธ</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <hr class="border-gray-300 mb-10">
    <?php endif; ?>

    <!-- Section: แบ่ง 2 คอลัมน์ (โครงงานที่ดูแล & งานที่รอตรวจ) -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
        
        <!-- ฝั่งซ้าย: โครงงานภายใต้การดูแล -->
        <div class="lg:col-span-2 space-y-4">
            <div class="flex justify-between items-center mb-2">
                <h3 class="font-bold text-xl text-blue-900 flex items-center">
                    <i class="fas fa-folder-open mr-2"></i> โครงงานภายใต้การดูแล (<?= count($my_projects) ?>)
                </h3>
            </div>

            <?php if(count($my_projects) > 0): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <?php 
                    $visible_my_projs = array_slice($my_projects, 0, 2);
                    foreach($visible_my_projs as $p): 
                        $role_color = ($p['my_role'] == 'Main Advisor') ? 'text-green-700 bg-green-100 border-green-200' : 'text-blue-700 bg-blue-100 border-blue-200';
                    ?>
                    <div class="bg-white p-6 rounded-lg shadow hover:shadow-xl transition duration-300 border border-gray-100 flex flex-col justify-between h-full">
                        <div>
                            <div class="flex justify-between items-start mb-2">
                                <span class="text-[10px] font-bold px-2 py-1 rounded border <?= $role_color ?>">
                                    <?= $p['my_role'] ?>
                                </span>
                                <span class="text-gray-400 text-xs"><i class="far fa-clock"></i> <?= date('d/m/Y', strtotime($p['created_at'])) ?></span>
                            </div>
                            <h4 class="font-bold text-lg text-gray-800 mb-1 line-clamp-2"><?= htmlspecialchars($p['name']) ?></h4>
                            <div class="text-sm text-gray-500 mb-4"><i class="fas fa-user-graduate mr-1"></i> <?= htmlspecialchars($p['student_name']) ?></div>
                            <div class="mb-4">
                                <div class="flex justify-between text-xs mb-1 text-gray-500">
                                    <span>ความคืบหน้า</span>
                                    <span><?= $p['progress'] ?>%</span>
                                </div>
                                <div class="w-full bg-gray-100 rounded-full h-2">
                                    <div class="bg-blue-600 h-2 rounded-full transition-all duration-500" style="width:<?= $p['progress'] ?>%"></div>
                                </div>
                            </div>
                        </div>
                        <a href="project_detail.php?id=<?= $p['id'] ?>" class="block w-full text-center border border-blue-600 text-blue-600 py-2 rounded hover:bg-blue-600 hover:text-white transition mt-auto">
                            เข้าสู่ระบบโครงงาน
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if(count($my_projects) > 2): ?>
                <button onclick="document.getElementById('modal-my-projects').classList.remove('hidden')" class="w-full text-center text-sm text-blue-700 hover:text-blue-900 font-bold mt-2 bg-blue-50 p-2 rounded hover:bg-blue-100 transition border border-blue-100">
                    ดูโครงงานทั้งหมด (<?= count($my_projects) ?>) <i class="fas fa-list"></i>
                </button>
                <?php endif; ?>

            <?php else: ?>
                <div class="bg-white p-12 rounded-lg border-2 border-dashed border-gray-300 text-center">
                    <i class="fas fa-clipboard-list text-4xl text-gray-300 mb-3"></i>
                    <p class="text-gray-500">ท่านยังไม่มีโครงงานที่ดูแลในขณะนี้</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- ฝั่งขวา: งานที่รอตรวจ (Pending Review) -->
        <div class="space-y-3">
            <div class="flex justify-between items-center mb-2">
                <h3 class="font-bold text-lg text-gray-700"><i class="fas fa-tasks mr-2"></i> งานที่รอตรวจ</h3>
                <span class="bg-red-100 text-red-800 text-xs px-2 py-1 rounded-full font-bold"><?= $total_pending_tasks ?> รอตรวจ</span>
            </div>
            
            <?php if($total_pending_tasks > 0): ?>
                <?php 
                $displayed_tasks = array_slice($pending_tasks, 0, 2);
                foreach($displayed_tasks as $t): 
                ?>
                <div class="border-l-4 border-blue-400 bg-white p-4 rounded shadow-md relative hover:-translate-y-1 transition flex flex-col justify-between">
                    <a href="project_detail.php?id=<?= $t['project_id'] ?>" class="block group mb-2">
                        <div class="text-[10px] text-blue-600 font-bold mb-1 uppercase tracking-wide line-clamp-1 group-hover:underline">
                            <i class="fas fa-project-diagram mr-1"></i> <?= htmlspecialchars($t['project_name']) ?>
                        </div>
                        <h5 class="font-bold text-sm text-gray-800 line-clamp-2 group-hover:text-blue-700"><?= htmlspecialchars($t['name']) ?></h5>
                    </a>
                    
                    <div class="text-xs text-gray-500 mb-3">
                        <i class="fas fa-user text-gray-400"></i> นศ: <?= htmlspecialchars($t['assignee_name']) ?>
                    </div>
                    
                    <div class="mt-auto border-t pt-3 space-y-2">
                        <?php if($t['file_path']): ?>
                            <button type="button" onclick='openFile(<?= json_encode($upload_web_base . "/" . rawurlencode($t["file_path"])) ?>)' class="w-full text-left bg-gray-100 border text-gray-700 px-3 py-1.5 rounded text-xs hover:bg-gray-200 transition">
                                <i class="fas fa-paperclip text-blue-600"></i> ดูไฟล์แนบผลงาน
                            </button>
                        <?php endif; ?>
                        
                        <div class="flex gap-2">
                            <a href="#" onclick="confirmReview(event, 'approve', <?= $t['id'] ?>, <?= $t['project_id'] ?>)" class="flex-1 bg-green-600 text-white text-[10px] px-2 py-1.5 rounded text-center hover:bg-green-700 shadow-sm transition">
                                <i class="fas fa-check"></i> ผ่าน
                            </a>
                            <a href="#" onclick="confirmReview(event, 'reject', <?= $t['id'] ?>, <?= $t['project_id'] ?>)" class="flex-1 bg-red-500 text-white text-[10px] px-2 py-1.5 rounded text-center hover:bg-red-600 shadow-sm transition">
                                <i class="fas fa-undo"></i> แก้ไข
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if($total_pending_tasks > 2): ?>
                <button onclick="document.getElementById('modal-all-tasks').classList.remove('hidden')" class="w-full text-center text-sm text-blue-700 hover:text-blue-900 font-bold mt-2 bg-blue-50 p-2 rounded hover:bg-blue-100 transition">
                    ดูงานที่รอตรวจทั้งหมด (<?= $total_pending_tasks ?>) <i class="fas fa-list"></i>
                </button>
                <?php endif; ?>

            <?php else: ?>
                <div class="text-center text-gray-400 py-8 bg-white rounded shadow-sm border border-gray-100">
                    <i class="fas fa-check-circle text-3xl text-green-200 mb-2"></i><br>ไม่มีงานรอตรวจ
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Section: คลังโครงงานสมบูรณ์ -->
    <hr class="my-10 border-gray-300">
    <div class="bg-white p-6 rounded-lg shadow-lg border border-gray-200">
        <h2 class="font-bold text-xl mb-4 text-gray-700 flex items-center">
            <i class="fas fa-archive mr-2"></i> คลังโครงงานสมบูรณ์ (100%)
        </h2>
        
        <form method="GET" class="flex flex-col md:flex-row gap-4 mb-6 bg-gray-50 p-4 rounded-lg">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหาด้วยชื่อบุคคล (นศ. หรือ อาจารย์)..." class="border p-2 rounded flex-1 focus:outline-none focus:ring-2 focus:ring-blue-500">
            <select name="filter_year" class="border p-2 rounded bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
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
                        <h4 class="font-bold text-blue-900 line-clamp-1 text-lg group-hover:text-blue-700 transition"><?= htmlspecialchars($cp['name']) ?></h4>
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

<!-- Modal: โครงงานทั้งหมดที่ดูแล -->
<div id="modal-my-projects" class="fixed inset-0 bg-black bg-opacity-70 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-lg w-full max-w-5xl h-[85vh] flex flex-col">
        <div class="p-4 border-b flex justify-between items-center bg-gray-50 rounded-t-lg">
            <h3 class="font-bold text-xl text-blue-900"><i class="fas fa-folder-open mr-2"></i> โครงงานภายใต้การดูแลทั้งหมด</h3>
            <button onclick="document.getElementById('modal-my-projects').classList.add('hidden')" class="text-gray-400 hover:text-red-500 text-2xl font-bold">&times;</button>
        </div>
        <div class="flex-1 p-6 overflow-y-auto bg-gray-50">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach($my_projects as $p): 
                    $role_color = ($p['my_role'] == 'Main Advisor') ? 'text-green-700 bg-green-100 border-green-200' : 'text-blue-700 bg-blue-100 border-blue-200';
                ?>
                <div class="bg-white p-6 rounded-lg shadow hover:shadow-xl transition duration-300 border border-gray-100 flex flex-col justify-between h-full">
                    <div>
                        <div class="flex justify-between items-start mb-2">
                            <span class="text-[10px] font-bold px-2 py-1 rounded border <?= $role_color ?>">
                                <?= $p['my_role'] ?>
                            </span>
                            <span class="text-gray-400 text-xs"><i class="far fa-clock"></i> <?= date('d/m/Y', strtotime($p['created_at'])) ?></span>
                        </div>
                        <h4 class="font-bold text-lg text-gray-800 mb-1 line-clamp-2"><?= htmlspecialchars($p['name']) ?></h4>
                        <div class="text-sm text-gray-500 mb-4"><i class="fas fa-user-graduate mr-1"></i> <?= htmlspecialchars($p['student_name']) ?></div>
                        <div class="mb-4">
                            <div class="flex justify-between text-xs mb-1 text-gray-500">
                                <span>ความคืบหน้า</span>
                                <span><?= $p['progress'] ?>%</span>
                            </div>
                            <div class="w-full bg-gray-100 rounded-full h-2">
                                <div class="bg-blue-600 h-2 rounded-full transition-all duration-500" style="width:<?= $p['progress'] ?>%"></div>
                            </div>
                        </div>
                    </div>
                    <a href="project_detail.php?id=<?= $p['id'] ?>" class="block w-full text-center border border-blue-600 text-blue-600 py-2 rounded hover:bg-blue-600 hover:text-white transition mt-auto">
                        เข้าสู่ระบบโครงงาน
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal: งานที่รอตรวจทั้งหมด -->
<div id="modal-all-tasks" class="fixed inset-0 bg-black bg-opacity-60 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-lg w-full max-w-4xl shadow-2xl h-[80vh] flex flex-col">
        <div class="p-4 border-b flex justify-between items-center bg-blue-900 text-white rounded-t-lg">
            <h3 class="font-bold text-lg"><i class="fas fa-tasks mr-2"></i> งานที่รอการตรวจสอบทั้งหมด (<?= $total_pending_tasks ?>)</h3>
            <button onclick="document.getElementById('modal-all-tasks').classList.add('hidden')" class="text-white hover:text-gray-300 text-xl font-bold">&times;</button>
        </div>
        
        <div class="flex-1 p-4 overflow-y-auto bg-gray-50">
            <?php if(count($pending_tasks) > 0): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse bg-white shadow-sm rounded-lg overflow-hidden">
                    <thead class="bg-gray-100 border-b">
                        <tr>
                            <th class="p-3 text-sm font-bold text-gray-600">ชื่องาน / โครงงาน</th>
                            <th class="p-3 text-sm font-bold text-gray-600">นักศึกษา</th>
                            <th class="p-3 text-sm font-bold text-gray-600 text-center">ไฟล์แนบ</th>
                            <th class="p-3 text-sm font-bold text-gray-600 text-center">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($pending_tasks as $t): ?>
                        <tr class="border-b hover:bg-blue-50 transition">
                            <td class="p-3">
                                <a href="project_detail.php?id=<?= $t['project_id'] ?>" class="block group">
                                    <div class="text-sm font-bold text-gray-800 group-hover:text-blue-700 group-hover:underline"><?= htmlspecialchars($t['name']) ?></div>
                                    <div class="text-xs text-blue-600"><?= htmlspecialchars($t['project_name']) ?></div>
                                </a>
                            </td>
                            <td class="p-3 text-xs text-gray-600"><?= htmlspecialchars($t['assignee_name']) ?></td>
                            <td class="p-3 text-center">
                                <?php if($t['file_path']): ?>
                                    <button type="button" onclick='openFile(<?= json_encode($upload_web_base . "/" . rawurlencode($t["file_path"])) ?>)' class="bg-gray-100 border text-gray-700 px-3 py-1 rounded text-xs hover:bg-gray-200 transition">
                                        <i class="fas fa-paperclip text-blue-600"></i> เปิดดูไฟล์
                                    </button>
                                <?php else: ?>
                                    <span class="text-gray-400 text-xs">- ไม่มีไฟล์ -</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-3 text-center">
                                <div class="flex gap-1 justify-center">
                                    <a href="#" onclick="confirmReview(event, 'approve', <?= $t['id'] ?>, <?= $t['project_id'] ?>)" class="bg-green-600 text-white text-[10px] px-3 py-1.5 rounded hover:bg-green-700 shadow-sm transition"><i class="fas fa-check"></i> ผ่าน</a>
                                    <a href="#" onclick="confirmReview(event, 'reject', <?= $t['id'] ?>, <?= $t['project_id'] ?>)" class="bg-red-500 text-white text-[10px] px-3 py-1.5 rounded hover:bg-red-600 shadow-sm transition"><i class="fas fa-undo"></i> แก้</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <p class="text-center text-gray-500 mt-10">ไม่พบงานรอตรวจ</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal: คลังโครงงานสมบูรณ์ -->
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
                            <h4 class="font-bold text-blue-900 line-clamp-1 text-lg group-hover:text-blue-700 transition"><?= htmlspecialchars($cp['name']) ?></h4>
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

<!-- Modal: ดูไฟล์ -->
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
    
    const urlParams = new URLSearchParams(window.location.search);
    const status = urlParams.get('status');

    if (status === 'accepted') {
        Swal.fire({icon: 'success', title: 'ตอบรับเรียบร้อย', showConfirmButton: false, timer: 1500}).then(() => window.history.replaceState(null, null, window.location.pathname));
    } else if (status === 'declined') {
        Swal.fire({icon: 'info', title: 'ปฏิเสธเรียบร้อย', showConfirmButton: false, timer: 1500}).then(() => window.history.replaceState(null, null, window.location.pathname));
    } else if (status === 'reviewed') {
        Swal.fire({icon: 'success', title: 'บันทึกผลการตรวจแล้ว', showConfirmButton: false, timer: 1500}).then(() => window.history.replaceState(null, null, window.location.pathname));
    }

    // ฟังก์ชัน SweetAlert สำหรับกดยืนยันการตรวจงาน
    function confirmReview(event, actionType, taskId, projectId) {
        event.preventDefault();
        
        let titleText = actionType === 'approve' ? 'ยืนยันให้ผ่าน?' : 'ส่งกลับให้แก้ไข?';
        let confirmColor = actionType === 'approve' ? '#16a34a' : '#ef4444';
        
        Swal.fire({
            title: titleText,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: confirmColor,
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'ตกลง',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `?review_task=${actionType}&task_id=${taskId}&project_id=${projectId}`;
            }
        });
    }
</script>

</body>
</html>
