<?php
session_start();
require_once __DIR__ . '/../System/db_connect.php';
require_once __DIR__ . '/../System/app_helpers.php';

// 1. เช็คสิทธิ์ (ต้องเป็น Admin เท่านั้น)
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: index.php");
    exit;
}

$user_name = $_SESSION['fullname'];
$current_user_id = $_SESSION['user_id'];
$admin_permissions = defaultAdminPermissions();
try {
    $admin_permissions = getAdminPermissions($conn, (int)$current_user_id);
} catch (Throwable $e) {
    // Fallback to defaults when permission table is not available yet.
}

$redirectWithStatus = static function (string $status): void {
    header('Location: admin_dashboard.php?status=' . urlencode($status));
    exit;
};

$requirePermission = static function (string $permissionKey) use ($admin_permissions, $redirectWithStatus): void {
    if (!hasAdminPermission($admin_permissions, $permissionKey)) {
        $redirectWithStatus('permission_denied');
    }
};

csrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensureValidCsrfOrRedirect('admin_dashboard.php');
}

// --- ACTION: อัปเดตประกาศ (Announcement) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_announcement') {
    $requirePermission('can_manage_announcements');
    $new_message = trim($_POST['announcement_msg']);
    
    // ตรวจสอบว่ามีแถวในตารางหรือยัง
    $check = $conn->query("SELECT id FROM announcements LIMIT 1")->fetch();
    if ($check) {
        // มีแล้ว -> อัปเดต
        $stmt = $conn->prepare("UPDATE announcements SET message = ?, created_at = NOW() WHERE id = ?");
        $stmt->execute([$new_message, $check['id']]);
    } else {
        // ยังไม่มี -> สร้างใหม่
        $stmt = $conn->prepare("INSERT INTO announcements (message) VALUES (?)");
        $stmt->execute([$new_message]);
    }
    writeAuditLog($conn, (int)$current_user_id, 'admin.announcement.update', 'อัปเดตประกาศหลักของระบบ', 'announcement', 1);
    header("Location: admin_dashboard.php?status=announced");
    exit;
}

// --- ACTION: แก้ไขข้อมูลผู้ใช้ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit_user') {
    $requirePermission('can_manage_users');
    $e_id = $_POST['edit_id'];
    $e_name = trim($_POST['edit_fullname']);
    $e_email = trim($_POST['edit_email']);
    $e_role = $_POST['edit_role'];
    $e_student_code = trim($_POST['edit_student_code']);

    if ($e_id == $current_user_id && $e_role != 'admin') { $e_role = 'admin'; }

    if ($e_student_code !== '') {
        $check = $conn->prepare("SELECT id FROM users WHERE student_code = ? AND id != ?");
        $check->execute([$e_student_code, $e_id]);
        if ($check->fetch()) {
            header("Location: admin_dashboard.php?status=student_code_exists");
            exit;
        }
    } else {
        $e_student_code = null;
    }

    $stmt = $conn->prepare("UPDATE users SET fullname = ?, student_code = ?, email = ?, role = ? WHERE id = ?");
    $stmt->execute([$e_name, $e_student_code, $e_email, $e_role, $e_id]);
    writeAuditLog($conn, (int)$current_user_id, 'admin.user.update', 'แก้ไขข้อมูลผู้ใช้ ID: ' . (int)$e_id, 'user', (int)$e_id);
    header("Location: admin_dashboard.php?status=edited");
    exit;
}

// --- ACTION: ลบผู้ใช้ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_user') {
    $requirePermission('can_manage_users');
    $del_id = (int)($_POST['delete_id'] ?? 0);
    if ($del_id != $current_user_id) {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$del_id]);
        writeAuditLog($conn, (int)$current_user_id, 'admin.user.delete', 'ลบผู้ใช้ ID: ' . (int)$del_id, 'user', (int)$del_id);
        header("Location: admin_dashboard.php?status=deleted");
    } else {
        header("Location: admin_dashboard.php?status=error_self");
    }
    exit;
}

// --- ACTION: เพิ่มผู้ใช้ใหม่ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_user') {
    $requirePermission('can_manage_users');
    $u_name = trim($_POST['user_fullname']);
    $u_email = trim($_POST['user_email']);
    $u_role = $_POST['user_role'];
    $u_student_code = trim($_POST['user_student_code']);
    $u_password = password_hash($_POST['user_password'], PASSWORD_DEFAULT);

    // ตรวจสอบอีเมลซ้ำ
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->execute([$u_email]);
    if ($check->fetch()) {
        header("Location: admin_dashboard.php?status=email_exists");
        exit;
    }

    if ($u_student_code !== '') {
        $check = $conn->prepare("SELECT id FROM users WHERE student_code = ?");
        $check->execute([$u_student_code]);
        if ($check->fetch()) {
            header("Location: admin_dashboard.php?status=student_code_exists");
            exit;
        }
    } else {
        $u_student_code = null;
    }

    $stmt = $conn->prepare("INSERT INTO users (fullname, student_code, email, password, role) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$u_name, $u_student_code, $u_email, $u_password, $u_role]);
    $new_user_id = (int)$conn->lastInsertId();
    writeAuditLog($conn, (int)$current_user_id, 'admin.user.create', 'เพิ่มผู้ใช้ใหม่: ' . $u_email, 'user', $new_user_id > 0 ? $new_user_id : null);
    header("Location: admin_dashboard.php?status=user_added");
    exit;
}

// --- ACTION: แก้ไขโครงงาน ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit_project') {
    $requirePermission('can_manage_projects');
    $p_id = $_POST['project_id'];
    $p_name = trim($_POST['project_name']);
    $p_description = trim($_POST['project_description']);

    $stmt = $conn->prepare("UPDATE projects SET name = ?, description = ? WHERE id = ?");
    $stmt->execute([$p_name, $p_description, $p_id]);
    writeAuditLog($conn, (int)$current_user_id, 'admin.project.update', 'แก้ไขโครงงาน ID: ' . (int)$p_id, 'project', (int)$p_id);
    header("Location: admin_dashboard.php?status=project_edited");
    exit;
}

// --- ACTION: ลบโครงงาน ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_project') {
    $requirePermission('can_manage_projects');
    $del_project_id = (int)($_POST['delete_project_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM projects WHERE id = ?");
    $stmt->execute([$del_project_id]);
    writeAuditLog($conn, (int)$current_user_id, 'admin.project.delete', 'ลบโครงงาน ID: ' . (int)$del_project_id, 'project', (int)$del_project_id);
    header("Location: admin_dashboard.php?status=project_deleted");
    exit;
}

// --- ACTION: บันทึกการตั้งค่าระบบ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_settings') {
    $requirePermission('can_manage_settings');
    $systemEmail = trim((string)($_POST['system_email'] ?? 'admin@rmutp.ac.th'));
    if (!filter_var($systemEmail, FILTER_VALIDATE_EMAIL)) {
        $systemEmail = 'admin@rmutp.ac.th';
    }

    $maxTasks = (int)($_POST['max_tasks_per_project'] ?? 5);
    if (!in_array($maxTasks, [3, 5, 10, 15], true)) {
        $maxTasks = 5;
    }

    $reminderDays = (int)($_POST['deadline_reminder_days'] ?? 3);
    if (!in_array($reminderDays, [1, 3, 7, 14], true)) {
        $reminderDays = 3;
    }

    $settings_to_save = [
        'system_email' => $systemEmail,
        'registration_open' => isset($_POST['registration_open']) ? '1' : '0',
        'maintenance_mode' => isset($_POST['maintenance_mode']) ? '1' : '0',
        'max_tasks_per_project' => $maxTasks,
        'deadline_reminder_enabled' => isset($_POST['deadline_reminder_enabled']) ? '1' : '0',
        'deadline_reminder_days' => $reminderDays,
    ];

    foreach ($settings_to_save as $key => $value) {
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by) 
                               VALUES (?, ?, ?) 
                               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)");
        $stmt->execute([$key, $value, $current_user_id]);
    }
    writeAuditLog($conn, (int)$current_user_id, 'admin.settings.update', 'บันทึกการตั้งค่าระบบโดยผู้ดูแล', 'system_settings', null);

    header("Location: admin_dashboard.php?status=settings_saved");
    exit;
}

// --- QUERY: ดึงประกาศปัจจุบัน ---
$current_announcement = $conn->query("SELECT message FROM announcements LIMIT 1")->fetchColumn();
if (!$current_announcement) $current_announcement = "";

// --- QUERY: ดึงสถิติ ---
$count_users = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
$count_students = $conn->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
$count_teachers = $conn->query("SELECT COUNT(*) FROM users WHERE role='teacher'")->fetchColumn();
$count_projects = $conn->query("SELECT COUNT(*) FROM projects")->fetchColumn();
$count_completed = $conn->query("SELECT COUNT(*) FROM projects WHERE progress=100")->fetchColumn();
$count_ongoing = $count_projects - $count_completed;

// --- QUERY: ค้นหาและกรองผู้ใช้ ---
$search = trim($_GET['search'] ?? '');
$role_filter = $_GET['role'] ?? 'all';
$users_page = max(1, (int)($_GET['users_page'] ?? 1));
$items_per_page = 25;

$users_where = " WHERE (fullname LIKE ? OR email LIKE ? OR student_code LIKE ?)";
$users_params = ["%$search%", "%$search%", "%$search%"];
if ($role_filter !== 'all') {
    $users_where .= " AND role = ?";
    $users_params[] = $role_filter;
}

$users_count_stmt = $conn->prepare("SELECT COUNT(*) FROM users" . $users_where);
$users_count_stmt->execute($users_params);
$users_total = (int)$users_count_stmt->fetchColumn();
$users_total_pages = max(1, (int)ceil($users_total / $items_per_page));
if ($users_page > $users_total_pages) {
    $users_page = $users_total_pages;
}
$users_offset = ($users_page - 1) * $items_per_page;

$users_sql = "SELECT * FROM users" . $users_where . " ORDER BY id DESC LIMIT " . (int)$items_per_page . " OFFSET " . (int)$users_offset;
$users_stmt = $conn->prepare($users_sql);
$users_stmt->execute($users_params);
$users_list = $users_stmt->fetchAll();

// --- QUERY: ค้นหาและกรองโครงงาน ---
$project_search = trim($_GET['project_search'] ?? '');
$status_filter = $_GET['project_status'] ?? 'all';
$projects_page = max(1, (int)($_GET['projects_page'] ?? 1));

$project_where = " WHERE (p.name LIKE ? OR p.description LIKE ? OR s.fullname LIKE ?)";
$project_params = ["%$project_search%", "%$project_search%", "%$project_search%"];
if ($status_filter !== 'all') {
    $project_where .= " AND p.status = ?";
    $project_params[] = $status_filter;
}

$project_count_sql = "SELECT COUNT(*) FROM projects p
                LEFT JOIN users s ON p.student_id = s.id
                LEFT JOIN users a ON p.advisor_id = a.id
                LEFT JOIN users c ON p.co_advisor_id = c.id" . $project_where;
$project_count_stmt = $conn->prepare($project_count_sql);
$project_count_stmt->execute($project_params);
$projects_total = (int)$project_count_stmt->fetchColumn();
$projects_total_pages = max(1, (int)ceil($projects_total / $items_per_page));
if ($projects_page > $projects_total_pages) {
    $projects_page = $projects_total_pages;
}
$projects_offset = ($projects_page - 1) * $items_per_page;

$project_sql = "SELECT p.*, 
                       s.fullname as student_name, s.email as student_email,
                       a.fullname as advisor_name,
                       c.fullname as co_advisor_name
                FROM projects p
                LEFT JOIN users s ON p.student_id = s.id
                LEFT JOIN users a ON p.advisor_id = a.id
                LEFT JOIN users c ON p.co_advisor_id = c.id
                " . $project_where . "
                ORDER BY p.created_at DESC
                LIMIT " . (int)$items_per_page . " OFFSET " . (int)$projects_offset;
$project_stmt = $conn->prepare($project_sql);
$project_stmt->execute($project_params);
$projects_list = $project_stmt->fetchAll();

$users_base_query = [
    'search' => $search,
    'role' => $role_filter,
    'project_search' => $project_search,
    'project_status' => $status_filter,
    'projects_page' => $projects_page,
];

$projects_base_query = [
    'search' => $search,
    'role' => $role_filter,
    'users_page' => $users_page,
    'project_search' => $project_search,
    'project_status' => $status_filter,
];

// --- QUERY: ดึงการตั้งค่าระบบ ---
$settings_sql = "SELECT setting_key, setting_value FROM system_settings ORDER BY setting_key ASC";
$settings_result = $conn->query($settings_sql);
$system_settings = [];
while ($row = $settings_result->fetch()) {
    $system_settings[$row['setting_key']] = $row['setting_value'];
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ผู้ดูแลระบบ - RMUTP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;700&display=swap');
        body { font-family: 'Sarabun', sans-serif; background-color: #f3f4f6; }
        .card { background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
    </style>
</head>
<body class="text-gray-800 min-h-screen flex flex-col">

    <!-- Navbar -->
    <nav class="bg-gray-800 text-white shadow-lg z-50 sticky top-0">
        <div class="container mx-auto px-4 py-3 flex justify-between items-center">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-white rounded-full flex items-center justify-center text-gray-800 font-bold text-xl"><i class="fas fa-shield-alt text-red-600"></i></div>
                <div><h1 class="text-lg font-bold">ผู้ดูแลระบบ</h1></div>
            </div>
            <div class="flex items-center space-x-4">
                <span class="font-medium text-sm hidden md:inline"><?= htmlspecialchars($user_name) ?></span>
                <span class="px-3 py-1 bg-red-600 text-white text-xs font-bold rounded-full shadow">แอดมิน</span>
                <a href="logout.php" class="text-sm bg-gray-700 px-3 py-1.5 rounded hover:bg-gray-600 transition"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
            </div>
        </div>
    </nav>

    <div class="flex-1 p-4 md:p-8">
        <div class="max-w-7xl mx-auto">
            
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-gray-800">แดชบอร์ดการจัดการ</h2>
            </div>
            <div class="card p-4 mb-6 bg-white border border-gray-100">
                <div class="flex flex-wrap gap-3">
                    <a href="admin_attachments.php" class="inline-flex items-center px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition text-sm font-bold">
                        <i class="fas fa-paperclip mr-2"></i> ศูนย์จัดการไฟล์แนบ
                    </a>
                    <a href="admin_reports.php" class="inline-flex items-center px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 transition text-sm font-bold">
                        <i class="fas fa-file-export mr-2"></i> รายงานและส่งออก CSV
                    </a>
                    <a href="admin_kpi.php" class="inline-flex items-center px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 transition text-sm font-bold">
                        <i class="fas fa-chart-line mr-2"></i> KPI Dashboard
                    </a>
                    <?php if (hasAdminPermission($admin_permissions, 'can_view_audit')): ?>
                    <a href="admin_audit_logs.php" class="inline-flex items-center px-4 py-2 rounded-lg bg-gray-700 text-white hover:bg-gray-800 transition text-sm font-bold">
                        <i class="fas fa-clipboard-list mr-2"></i> ประวัติการใช้งาน
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- กล่องจัดการประกาศข่าวสาร (ใหม่) -->
            <div class="card p-6 border-l-4 border-yellow-500 mb-8 bg-yellow-50">
                <form method="POST">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="action" value="update_announcement">
                    <div class="flex flex-col md:flex-row gap-4 items-start md:items-center">
                        <div class="flex-1 w-full">
                            <label class="block text-sm font-bold text-yellow-800 mb-2">
                                <i class="fas fa-bullhorn mr-1"></i> ประกาศข่าวสาร (แสดงในหน้าของ นศ. และ อาจารย์)
                            </label>
                            <input type="text" name="announcement_msg" value="<?= htmlspecialchars($current_announcement) ?>" placeholder="พิมพ์ข้อความที่ต้องการประกาศที่นี่ (เว้นว่างหากต้องการลบประกาศ)..." class="w-full border p-3 rounded-lg focus:ring-2 focus:ring-yellow-500 outline-none text-gray-800 shadow-sm">
                        </div>
                        <button type="submit" class="w-full md:w-auto bg-yellow-600 text-white px-6 py-3 rounded-lg hover:bg-yellow-700 transition font-bold shadow-md mt-6 md:mt-0 whitespace-nowrap">
                            <i class="fas fa-paper-plane mr-1"></i> อัปเดตประกาศ
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- สถิติ (Statistics) -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <div class="card p-5 border-l-4 border-blue-500 hover:shadow-lg transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-gray-500 text-sm font-bold">ผู้ใช้งานทั้งหมด</div>
                            <div class="text-2xl font-bold text-gray-800 mt-1"><?= $count_users ?> <span class="text-sm font-normal text-gray-500">บัญชี</span></div>
                        </div>
                        <div class="text-blue-200 text-4xl"><i class="fas fa-users"></i></div>
                    </div>
                </div>
                <div class="card p-5 border-l-4 border-indigo-500 hover:shadow-lg transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-gray-500 text-sm font-bold">นักศึกษา / อาจารย์</div>
                            <div class="text-2xl font-bold text-gray-800 mt-1"><?= $count_students ?> <span class="text-sm font-normal text-gray-400">/ <?= $count_teachers ?></span></div>
                        </div>
                        <div class="text-indigo-200 text-4xl"><i class="fas fa-user-graduate"></i></div>
                    </div>
                </div>
                <div class="card p-5 border-l-4 border-purple-500 hover:shadow-lg transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-gray-500 text-sm font-bold">โครงงานทั้งหมด</div>
                            <div class="text-2xl font-bold text-gray-800 mt-1"><?= $count_projects ?> <span class="text-sm font-normal text-gray-500">งาน</span></div>
                        </div>
                        <div class="text-purple-200 text-4xl"><i class="fas fa-project-diagram"></i></div>
                    </div>
                </div>
                <div class="card p-5 border-l-4 border-green-500 hover:shadow-lg transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-gray-500 text-sm font-bold">โครงงานสมบูรณ์แล้ว</div>
                            <div class="text-2xl font-bold text-gray-800 mt-1"><?= $count_completed ?> <span class="text-sm font-normal text-gray-400">/ ทำอยู่ <?= $count_ongoing ?></span></div>
                        </div>
                        <div class="text-green-200 text-4xl"><i class="fas fa-check-circle"></i></div>
                    </div>
                </div>
            </div>

            <!-- การจัดการผู้ใช้งาน -->
            <div class="card overflow-hidden">
                <div class="p-5 border-b bg-white flex flex-col lg:flex-row justify-between items-center gap-4">
                    <h3 class="font-bold text-lg text-gray-800"><i class="fas fa-user-cog mr-2"></i> จัดการผู้ใช้งาน</h3>
                    
                    <div class="flex gap-2">
                        <?php if (hasAdminPermission($admin_permissions, 'can_manage_users')): ?>
                        <button onclick="openAddUserModal()" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 transition shadow text-sm flex items-center">
                            <i class="fas fa-user-plus mr-1"></i> เพิ่มผู้ใช้ใหม่
                        </button>
                        <?php endif; ?>
                        
                        <form method="GET" class="flex gap-2">
                            <input type="hidden" name="users_page" value="1">
                            <input type="hidden" name="projects_page" value="<?= (int)$projects_page ?>">
                            <input type="hidden" name="project_search" value="<?= htmlspecialchars($project_search) ?>">
                            <input type="hidden" name="project_status" value="<?= htmlspecialchars($status_filter) ?>">
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหาชื่อ หรือ อีเมล..." class="border p-2 rounded w-full lg:w-64 focus:ring-2 focus:ring-gray-800 outline-none text-sm">
                            <select name="role" class="border p-2 rounded bg-white focus:ring-2 focus:ring-gray-800 outline-none text-sm cursor-pointer">
                                <option value="all" <?= $role_filter=='all'?'selected':'' ?>>ทุกบทบาท</option>
                                <option value="student" <?= $role_filter=='student'?'selected':'' ?>>นักศึกษา</option>
                                <option value="teacher" <?= $role_filter=='teacher'?'selected':'' ?>>อาจารย์</option>
                                <option value="admin" <?= $role_filter=='admin'?'selected':'' ?>>แอดมิน</option>
                            </select>
                            <button type="submit" class="bg-gray-800 text-white px-4 py-2 rounded hover:bg-gray-900 transition shadow shrink-0"><i class="fas fa-search"></i></button>
                            
                            <?php if(!empty($search) || $role_filter!='all'): ?>
                                <a href="admin_dashboard.php" class="bg-red-100 text-red-600 px-4 py-2 rounded hover:bg-red-200 transition text-sm flex items-center shrink-0">ล้างค่า</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full leading-normal">
                        <thead>
                            <tr>
                                <th class="px-5 py-3 border-b-2 bg-gray-50 text-left text-xs font-bold text-gray-600 uppercase">รหัส</th>
                                <th class="px-5 py-3 border-b-2 bg-gray-50 text-left text-xs font-bold text-gray-600 uppercase">ชื่อ-นามสกุล</th>
                                <th class="px-5 py-3 border-b-2 bg-gray-50 text-left text-xs font-bold text-gray-600 uppercase">อีเมล</th>
                        <th class="px-5 py-3 border-b-2 bg-gray-50 text-left text-xs font-bold text-gray-600 uppercase">รหัสนักศึกษา</th>
                                <th class="px-5 py-3 border-b-2 bg-gray-50 text-center text-xs font-bold text-gray-600 uppercase">บทบาท</th>
                                <th class="px-5 py-3 border-b-2 bg-gray-50 text-center text-xs font-bold text-gray-600 uppercase">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($users_list as $u): ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-5 py-4 border-b border-gray-100 text-sm text-gray-500">#<?= $u['id'] ?></td>
                                <td class="px-5 py-4 border-b border-gray-100 text-sm font-bold text-gray-800"><?= htmlspecialchars($u['fullname']) ?></td>
                                <td class="px-5 py-4 border-b border-gray-100 text-sm text-gray-600"><?= htmlspecialchars($u['email']) ?></td>
                                <td class="px-5 py-4 border-b border-gray-100 text-sm text-gray-600"><?= htmlspecialchars($u['student_code'] ?? '-') ?></td>
                                <td class="px-5 py-4 border-b border-gray-100 text-sm text-center">
                                    <?php 
                                        $bg = 'bg-gray-100'; $text = 'text-gray-800'; $icon = 'fa-user'; $role_label = 'ไม่ระบุ';
                                        if($u['role'] == 'student') { $bg='bg-green-100'; $text='text-green-800'; $icon='fa-user-graduate'; $role_label = 'นักศึกษา'; }
                                        elseif($u['role'] == 'teacher') { $bg='bg-yellow-100'; $text='text-yellow-800'; $icon='fa-chalkboard-teacher'; $role_label = 'อาจารย์'; }
                                        elseif($u['role'] == 'admin') { $bg='bg-red-100'; $text='text-red-800'; $icon='fa-shield-alt'; $role_label = 'ผู้ดูแลระบบ'; }
                                    ?>
                                    <span class="px-3 py-1 rounded-full text-xs font-bold <?= $bg ?> <?= $text ?> inline-flex items-center gap-1">
                                        <i class="fas <?= $icon ?>"></i> <?= $role_label ?>
                                    </span>
                                </td>
                                <td class="px-5 py-4 border-b border-gray-100 text-sm text-center">
                                    <?php if (hasAdminPermission($admin_permissions, 'can_manage_users')): ?>
<button onclick="openEditModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['fullname'])) ?>', '<?= htmlspecialchars(addslashes($u['student_code'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($u['email'])) ?>', '<?= $u['role'] ?>')" class="text-blue-500 hover:text-blue-800 bg-blue-50 px-3 py-1.5 rounded transition mx-1" title="แก้ไข">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    
                                    <?php if($u['id'] != $current_user_id): ?>
                                    <button onclick="confirmDelete(<?= $u['id'] ?>)" class="text-red-500 hover:text-red-800 bg-red-50 px-3 py-1.5 rounded transition mx-1" title="ลบ">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if(count($users_list) == 0): ?>
                            <tr>
                                <td colspan="6" class="px-5 py-10 text-center text-gray-500 bg-white">
                                    <i class="fas fa-search text-3xl mb-3 text-gray-300"></i><br>ไม่พบข้อมูลผู้ใช้งานที่ค้นหา
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($users_total > 0): ?>
                <?php
                    $users_start = (($users_page - 1) * $items_per_page) + 1;
                    $users_end = min($users_total, $users_page * $items_per_page);
                ?>
                <div class="px-5 py-4 border-t bg-white flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div class="text-sm text-gray-600">
                        แสดง <?= (int)$users_start ?>-<?= (int)$users_end ?> จาก <?= (int)$users_total ?> รายการ
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <?php if ($users_page > 1): ?>
                            <a href="admin_dashboard.php?<?= htmlspecialchars(http_build_query(array_merge($users_base_query, ['users_page' => 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">หน้าแรก</a>
                            <a href="admin_dashboard.php?<?= htmlspecialchars(http_build_query(array_merge($users_base_query, ['users_page' => $users_page - 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">ก่อนหน้า</a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">หน้าแรก</span>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">ก่อนหน้า</span>
                        <?php endif; ?>

                        <span class="px-3 py-1.5 rounded bg-gray-800 text-white text-sm">
                            หน้า <?= (int)$users_page ?> / <?= (int)$users_total_pages ?>
                        </span>

                        <?php if ($users_page < $users_total_pages): ?>
                            <a href="admin_dashboard.php?<?= htmlspecialchars(http_build_query(array_merge($users_base_query, ['users_page' => $users_page + 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">ถัดไป</a>
                            <a href="admin_dashboard.php?<?= htmlspecialchars(http_build_query(array_merge($users_base_query, ['users_page' => $users_total_pages]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">หน้าสุดท้าย</a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">ถัดไป</span>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">หน้าสุดท้าย</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- การจัดการโครงงาน -->
            <div class="card overflow-hidden mt-8">
                <div class="p-5 border-b bg-white flex flex-col lg:flex-row justify-between items-center gap-4">
                    <h3 class="font-bold text-lg text-gray-800"><i class="fas fa-project-diagram mr-2"></i> จัดการโครงงาน</h3>
                    
                    <div class="flex gap-2">
                        <form method="GET" class="flex gap-2">
                            <input type="hidden" name="projects_page" value="1">
                            <input type="hidden" name="users_page" value="<?= (int)$users_page ?>">
                            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                            <input type="hidden" name="role" value="<?= htmlspecialchars($role_filter) ?>">
                            <input type="text" name="project_search" value="<?= htmlspecialchars($project_search) ?>" placeholder="ค้นหาชื่อโครงงาน หรือ นักศึกษา..." class="border p-2 rounded w-full lg:w-64 focus:ring-2 focus:ring-gray-800 outline-none text-sm">
                            <select name="project_status" class="border p-2 rounded bg-white focus:ring-2 focus:ring-gray-800 outline-none text-sm cursor-pointer">
                                <option value="all" <?= $status_filter=='all'?'selected':'' ?>>ทุกสถานะ</option>
                                <option value="pending" <?= $status_filter=='pending'?'selected':'' ?>>รออนุมัติ</option>
                                <option value="in_progress" <?= $status_filter=='in_progress'?'selected':'' ?>>กำลังดำเนินการ</option>
                                <option value="completed" <?= $status_filter=='completed'?'selected':'' ?>>เสร็จสิ้น</option>
                                <option value="cancelled" <?= $status_filter=='cancelled'?'selected':'' ?>>ยกเลิก</option>
                            </select>
                            <button type="submit" class="bg-gray-800 text-white px-4 py-2 rounded hover:bg-gray-900 transition shadow shrink-0"><i class="fas fa-search"></i></button>
                            
                            <?php if(!empty($project_search) || $status_filter!='all'): ?>
                                <a href="admin_dashboard.php" class="bg-red-100 text-red-600 px-4 py-2 rounded hover:bg-red-200 transition text-sm flex items-center shrink-0">ล้างค่า</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full leading-normal">
                        <thead>
                            <tr>
                                <th class="px-5 py-3 border-b-2 bg-gray-50 text-left text-xs font-bold text-gray-600 uppercase">รหัส</th>
                                <th class="px-5 py-3 border-b-2 bg-gray-50 text-left text-xs font-bold text-gray-600 uppercase">ชื่อโครงงาน</th>
                                <th class="px-5 py-3 border-b-2 bg-gray-50 text-left text-xs font-bold text-gray-600 uppercase">นักศึกษา</th>
                                <th class="px-5 py-3 border-b-2 bg-gray-50 text-left text-xs font-bold text-gray-600 uppercase">อาจารย์ที่ปรึกษา</th>
                                <th class="px-5 py-3 border-b-2 bg-gray-50 text-center text-xs font-bold text-gray-600 uppercase">สถานะ</th>
                                <th class="px-5 py-3 border-b-2 bg-gray-50 text-center text-xs font-bold text-gray-600 uppercase">ความคืบหน้า</th>
                                <th class="px-5 py-3 border-b-2 bg-gray-50 text-center text-xs font-bold text-gray-600 uppercase">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($projects_list as $p): ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-5 py-4 border-b border-gray-100 text-sm text-gray-500">#<?= $p['id'] ?></td>
                                <td class="px-5 py-4 border-b border-gray-100 text-sm">
                                    <div class="font-bold text-gray-800 max-w-xs truncate" title="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></div>
                                    <div class="text-xs text-gray-500 max-w-xs truncate" title="<?= htmlspecialchars($p['description']) ?>"><?= htmlspecialchars(substr($p['description'], 0, 50)) ?>...</div>
                                </td>
                                <td class="px-5 py-4 border-b border-gray-100 text-sm">
                                    <div class="font-medium text-gray-800"><?= htmlspecialchars($p['student_name']) ?></div>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($p['student_email']) ?></div>
                                </td>
                                <td class="px-5 py-4 border-b border-gray-100 text-sm">
                                    <div class="text-gray-800">
                                        <?php if($p['advisor_name']): ?>
                                            <div class="font-medium"><?= htmlspecialchars($p['advisor_name']) ?></div>
                                            <?php if($p['co_advisor_name']): ?>
                                                <div class="text-xs text-gray-500">ร่วม: <?= htmlspecialchars($p['co_advisor_name']) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-gray-400">ยังไม่ได้กำหนด</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-5 py-4 border-b border-gray-100 text-sm text-center">
                                    <?php 
                                        $status_bg = 'bg-gray-100'; $status_text = 'text-gray-800'; $status_icon = 'fa-clock';
                                        if($p['status'] == 'in_progress') { $status_bg='bg-blue-100'; $status_text='text-blue-800'; $status_icon='fa-play'; }
                                        elseif($p['status'] == 'completed') { $status_bg='bg-green-100'; $status_text='text-green-800'; $status_icon='fa-check-circle'; }
                                        elseif($p['status'] == 'cancelled') { $status_bg='bg-red-100'; $status_text='text-red-800'; $status_icon='fa-times-circle'; }
                                        
                                        $status_label = 'รออนุมัติ';
                                        if($p['status'] == 'in_progress') $status_label = 'กำลังดำเนินการ';
                                        elseif($p['status'] == 'completed') $status_label = 'เสร็จสิ้น';
                                        elseif($p['status'] == 'cancelled') $status_label = 'ยกเลิก';
                                    ?>
                                    <span class="px-3 py-1 rounded-full text-xs font-bold <?= $status_bg ?> <?= $status_text ?> inline-flex items-center gap-1">
                                        <i class="fas <?= $status_icon ?>"></i> <?= $status_label ?>
                                    </span>
                                </td>
                                <td class="px-5 py-4 border-b border-gray-100 text-sm text-center">
                                    <div class="flex items-center justify-center">
                                        <div class="w-16 bg-gray-200 rounded-full h-2 mr-2">
                                            <div class="bg-blue-600 h-2 rounded-full" style="width: <?= $p['progress'] ?>%"></div>
                                        </div>
                                        <span class="text-xs font-bold text-gray-700"><?= $p['progress'] ?>%</span>
                                    </div>
                                </td>
                                <td class="px-5 py-4 border-b border-gray-100 text-sm text-center">
                                    <?php if (hasAdminPermission($admin_permissions, 'can_manage_projects')): ?>
                                    <button onclick="openProjectModal(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>', '<?= htmlspecialchars(addslashes($p['description'])) ?>')" class="text-blue-500 hover:text-blue-800 bg-blue-50 px-3 py-1.5 rounded transition mx-1" title="แก้ไข">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    
                                    <button onclick="confirmDeleteProject(<?= $p['id'] ?>)" class="text-red-500 hover:text-red-800 bg-red-50 px-3 py-1.5 rounded transition mx-1" title="ลบ">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                    <?php else: ?>
                                    <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if(count($projects_list) == 0): ?>
                            <tr>
                                <td colspan="7" class="px-5 py-10 text-center text-gray-500 bg-white">
                                    <i class="fas fa-search text-3xl mb-3 text-gray-300"></i><br>ไม่พบข้อมูลโครงงานที่ค้นหา
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($projects_total > 0): ?>
                <?php
                    $projects_start = (($projects_page - 1) * $items_per_page) + 1;
                    $projects_end = min($projects_total, $projects_page * $items_per_page);
                ?>
                <div class="px-5 py-4 border-t bg-white flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div class="text-sm text-gray-600">
                        แสดง <?= (int)$projects_start ?>-<?= (int)$projects_end ?> จาก <?= (int)$projects_total ?> รายการ
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <?php if ($projects_page > 1): ?>
                            <a href="admin_dashboard.php?<?= htmlspecialchars(http_build_query(array_merge($projects_base_query, ['projects_page' => 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">หน้าแรก</a>
                            <a href="admin_dashboard.php?<?= htmlspecialchars(http_build_query(array_merge($projects_base_query, ['projects_page' => $projects_page - 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">ก่อนหน้า</a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">หน้าแรก</span>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">ก่อนหน้า</span>
                        <?php endif; ?>

                        <span class="px-3 py-1.5 rounded bg-gray-800 text-white text-sm">
                            หน้า <?= (int)$projects_page ?> / <?= (int)$projects_total_pages ?>
                        </span>

                        <?php if ($projects_page < $projects_total_pages): ?>
                            <a href="admin_dashboard.php?<?= htmlspecialchars(http_build_query(array_merge($projects_base_query, ['projects_page' => $projects_page + 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">ถัดไป</a>
                            <a href="admin_dashboard.php?<?= htmlspecialchars(http_build_query(array_merge($projects_base_query, ['projects_page' => $projects_total_pages]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">หน้าสุดท้าย</a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">ถัดไป</span>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">หน้าสุดท้าย</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- การตั้งค่าระบบ -->
            <div class="card overflow-hidden mt-8">
                <div class="p-5 border-b bg-white flex items-center justify-between gap-3">
                    <h3 class="font-bold text-lg text-gray-800"><i class="fas fa-cog mr-2"></i> การตั้งค่าระบบ</h3>
                    <span class="text-xs px-2 py-1 rounded-full bg-blue-50 text-blue-700 border border-blue-100">โหมดใช้งานง่าย</span>
                </div>
                
                <form method="POST" class="p-6">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="action" value="save_settings">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">อีเมลระบบ</label>
                            <input type="email" name="system_email" value="<?= htmlspecialchars($system_settings['system_email'] ?? 'admin@rmutp.ac.th') ?>" 
                                   class="w-full border p-3 rounded focus:ring-2 focus:ring-blue-500 outline-none">
                            <p class="text-xs text-gray-500 mt-1">ใช้เป็นอีเมลสำหรับติดต่อและแจ้งเตือน</p>
                        </div>

                        <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <label class="flex items-start gap-3 p-4 border rounded-lg bg-blue-50/40 border-blue-100 cursor-pointer">
                                <input type="checkbox" name="registration_open" value="1" 
                                       <?= ($system_settings['registration_open'] ?? '1') == '1' ? 'checked' : '' ?> 
                                       class="mt-0.5 w-5 h-5 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500">
                                <span>
                                    <span class="block text-sm font-semibold text-gray-800">เปิดรับสมัครผู้ใช้ใหม่</span>
                                    <span class="block text-xs text-gray-600 mt-1">ปิดไว้เมื่อยังไม่ต้องการให้สมัครสมาชิกเพิ่ม</span>
                                </span>
                            </label>

                            <label class="flex items-start gap-3 p-4 border rounded-lg bg-rose-50/40 border-rose-100 cursor-pointer">
                                <input type="checkbox" name="maintenance_mode" value="1" 
                                       <?= ($system_settings['maintenance_mode'] ?? '0') == '1' ? 'checked' : '' ?> 
                                       class="mt-0.5 w-5 h-5 text-rose-600 bg-gray-100 border-gray-300 rounded focus:ring-rose-500">
                                <span>
                                    <span class="block text-sm font-semibold text-gray-800">โหมดบำรุงรักษา</span>
                                    <span class="block text-xs text-gray-600 mt-1">เปิดเมื่อระบบต้องหยุดปรับปรุงชั่วคราว</span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <details class="mt-6 border border-gray-200 rounded-lg bg-gray-50">
                        <summary class="px-4 py-3 cursor-pointer text-sm font-bold text-gray-700">ตั้งค่าขั้นสูง (ตัวเลือกเพิ่มเติม)</summary>
                        <div class="px-4 pb-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">จำนวนงานสูงสุดต่อโครงงาน</label>
                                <select name="max_tasks_per_project" class="w-full border p-3 rounded focus:ring-2 focus:ring-blue-500 outline-none bg-white cursor-pointer">
                                    <option value="3" <?= ($system_settings['max_tasks_per_project'] ?? '5') == '3' ? 'selected' : '' ?>>3 งาน</option>
                                    <option value="5" <?= ($system_settings['max_tasks_per_project'] ?? '5') == '5' ? 'selected' : '' ?>>5 งาน</option>
                                    <option value="10" <?= ($system_settings['max_tasks_per_project'] ?? '5') == '10' ? 'selected' : '' ?>>10 งาน</option>
                                    <option value="15" <?= ($system_settings['max_tasks_per_project'] ?? '5') == '15' ? 'selected' : '' ?>>15 งาน</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">แจ้งเตือนก่อนกำหนดส่ง</label>
                                <select name="deadline_reminder_days" class="w-full border p-3 rounded focus:ring-2 focus:ring-blue-500 outline-none bg-white cursor-pointer">
                                    <option value="1" <?= ($system_settings['deadline_reminder_days'] ?? '3') == '1' ? 'selected' : '' ?>>1 วัน</option>
                                    <option value="3" <?= ($system_settings['deadline_reminder_days'] ?? '3') == '3' ? 'selected' : '' ?>>3 วัน</option>
                                    <option value="7" <?= ($system_settings['deadline_reminder_days'] ?? '3') == '7' ? 'selected' : '' ?>>7 วัน</option>
                                    <option value="14" <?= ($system_settings['deadline_reminder_days'] ?? '3') == '14' ? 'selected' : '' ?>>14 วัน</option>
                                </select>
                            </div>

                            <label class="md:col-span-2 flex items-center gap-3 p-3 border rounded bg-white cursor-pointer">
                                <input type="checkbox" name="deadline_reminder_enabled" value="1" 
                                       <?= ($system_settings['deadline_reminder_enabled'] ?? '1') == '1' ? 'checked' : '' ?> 
                                       class="w-5 h-5 text-green-600 bg-gray-100 border-gray-300 rounded focus:ring-green-500">
                                <span class="text-sm font-medium text-gray-700">เปิดใช้งานการแจ้งเตือนก่อนหมดเวลา</span>
                            </label>
                        </div>
                    </details>
                    
                    <div class="mt-8 flex justify-end">
                        <button type="submit" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-bold shadow-md">
                            <i class="fas fa-save mr-2"></i> บันทึกการตั้งค่า
                        </button>
                    </div>
                </form>
            </div>

    <!-- Modal: เพิ่มผู้ใช้ใหม่ -->
    <div id="modal-add-user" class="fixed inset-0 bg-black bg-opacity-60 hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-lg w-full max-w-md shadow-2xl overflow-hidden transform transition-all">
            <div class="bg-gray-800 p-4 text-white flex justify-between items-center">
                <h3 class="font-bold text-lg"><i class="fas fa-user-plus mr-2"></i>เพิ่มผู้ใช้ใหม่</h3>
                <button onclick="closeAddUserModal()" class="text-gray-300 hover:text-white text-xl font-bold">&times;</button>
            </div>
            <form method="POST" class="p-6">
                <?= csrfInputField() ?>
                <input type="hidden" name="action" value="add_user">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">ชื่อ-นามสกุล</label>
                        <input type="text" name="user_fullname" required class="w-full border p-2 rounded focus:ring-2 focus:ring-gray-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">รหัสประจำตัวนักศึกษา</label>
                        <input type="text" name="user_student_code" class="w-full border p-2 rounded focus:ring-2 focus:ring-gray-500 outline-none" placeholder="เช่น 6501234567">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">อีเมล</label>
                        <input type="email" name="user_email" required class="w-full border p-2 rounded focus:ring-2 focus:ring-gray-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">รหัสผ่านเริ่มต้น</label>
                        <input type="password" name="user_password" required minlength="6" class="w-full border p-2 rounded focus:ring-2 focus:ring-gray-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">บทบาท</label>
                        <select name="user_role" required class="w-full border p-2 rounded focus:ring-2 focus:ring-gray-500 outline-none bg-gray-50 cursor-pointer">
                            <option value="student">นักศึกษา</option>
                            <option value="teacher">อาจารย์</option>
                            <option value="admin">ผู้ดูแลระบบ</option>
                        </select>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" onclick="closeAddUserModal()" class="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300 transition text-sm font-bold">ยกเลิก</button>
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 transition text-sm font-bold shadow-md"><i class="fas fa-user-plus mr-1"></i> เพิ่มผู้ใช้</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: แก้ไขผู้ใช้ -->
    <div id="modal-edit" class="fixed inset-0 bg-black bg-opacity-60 hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-lg w-full max-w-md shadow-2xl overflow-hidden transform transition-all">
            <div class="bg-gray-800 p-4 text-white flex justify-between items-center">
                <h3 class="font-bold text-lg"><i class="fas fa-user-edit mr-2"></i>แก้ไขข้อมูลผู้ใช้</h3>
                <button onclick="closeEditModal()" class="text-gray-300 hover:text-white text-xl font-bold">&times;</button>
            </div>
            <form method="POST" class="p-6">
                <?= csrfInputField() ?>
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="edit_id" id="edit_id">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">ชื่อ-นามสกุล</label>
                        <input type="text" name="edit_fullname" id="edit_fullname" required class="w-full border p-2 rounded focus:ring-2 focus:ring-gray-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">รหัสประจำตัวนักศึกษา</label>
                        <input type="text" name="edit_student_code" id="edit_student_code" class="w-full border p-2 rounded focus:ring-2 focus:ring-gray-500 outline-none" placeholder="เช่น 6501234567">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">อีเมล</label>
                        <input type="email" name="edit_email" id="edit_email" required class="w-full border p-2 rounded focus:ring-2 focus:ring-gray-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">บทบาท</label>
                        <select name="edit_role" id="edit_role" class="w-full border p-2 rounded focus:ring-2 focus:ring-gray-500 outline-none bg-gray-50 cursor-pointer">
                            <option value="student">นักศึกษา</option>
                            <option value="teacher">อาจารย์</option>
                            <option value="admin">ผู้ดูแลระบบ</option>
                        </select>
                        <p class="text-[10px] text-red-500 mt-1 hidden" id="admin_warning">* คุณไม่สามารถปลดสิทธิ์แอดมินของตัวเองได้</p>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300 transition text-sm font-bold">ยกเลิก</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition text-sm font-bold shadow-md"><i class="fas fa-save mr-1"></i> บันทึกข้อมูล</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: แก้ไขโครงงาน -->
    <div id="modal-project" class="fixed inset-0 bg-black bg-opacity-60 hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-lg w-full max-w-lg shadow-2xl overflow-hidden transform transition-all">
            <div class="bg-gray-800 p-4 text-white flex justify-between items-center">
                <h3 class="font-bold text-lg"><i class="fas fa-project-diagram mr-2"></i>แก้ไขโครงงาน</h3>
                <button onclick="closeProjectModal()" class="text-gray-300 hover:text-white text-xl font-bold">&times;</button>
            </div>
            <form method="POST" class="p-6">
                <?= csrfInputField() ?>
                <input type="hidden" name="action" value="edit_project">
                <input type="hidden" name="project_id" id="project_id">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">ชื่อโครงงาน</label>
                        <input type="text" name="project_name" id="project_name" required class="w-full border p-2 rounded focus:ring-2 focus:ring-gray-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">คำอธิบาย</label>
                        <textarea name="project_description" id="project_description" rows="3" required class="w-full border p-2 rounded focus:ring-2 focus:ring-gray-500 outline-none"></textarea>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" onclick="closeProjectModal()" class="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300 transition text-sm font-bold">ยกเลิก</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition text-sm font-bold shadow-md"><i class="fas fa-save mr-1"></i> บันทึกข้อมูล</button>
                </div>
            </form>
        </div>
    </div>

    <form id="delete-user-form" method="POST" class="hidden">
        <?= csrfInputField() ?>
        <input type="hidden" name="action" value="delete_user">
        <input type="hidden" name="delete_id" id="delete_user_id">
    </form>

    <form id="delete-project-form" method="POST" class="hidden">
        <?= csrfInputField() ?>
        <input type="hidden" name="action" value="delete_project">
        <input type="hidden" name="delete_project_id" id="delete_project_id">
    </form>
    <script>
        const urlParams = new URLSearchParams(window.location.search);
        const status = urlParams.get('status');
        
        if (status === 'edited') {
            Swal.fire({icon: 'success', title: 'อัปเดตข้อมูลสำเร็จ', showConfirmButton: false, timer: 1500})
                .then(() => window.history.replaceState(null, null, window.location.pathname));
        } else if (status === 'deleted') {
            Swal.fire({icon: 'success', title: 'ลบผู้ใช้เรียบร้อย', showConfirmButton: false, timer: 1500})
                .then(() => window.history.replaceState(null, null, window.location.pathname));
        } else if (status === 'error_self') {
            Swal.fire({icon: 'error', title: 'ไม่อนุญาต', text: 'คุณไม่สามารถลบบัญชีของตัวเองได้!', confirmButtonText: 'ตกลง'})
                .then(() => window.history.replaceState(null, null, window.location.pathname));
        } else if (status === 'csrf_invalid') {
            Swal.fire({icon: 'error', title: 'คำขอไม่ถูกต้อง', text: 'กรุณาลองใหม่อีกครั้ง', confirmButtonText: 'ตกลง'})
                .then(() => window.history.replaceState(null, null, window.location.pathname));
        } else if (status === 'permission_denied') {
            Swal.fire({icon: 'error', title: 'ไม่มีสิทธิ์ใช้งาน', text: 'บัญชีนี้ไม่มีสิทธิ์ทำรายการนี้', confirmButtonText: 'ตกลง'})
                .then(() => window.history.replaceState(null, null, window.location.pathname));
        } else if (status === 'announced') {
            Swal.fire({icon: 'success', title: 'อัปเดตประกาศสำเร็จ', text: 'นักศึกษาและอาจารย์จะเห็นประกาศนี้ทันที', showConfirmButton: false, timer: 2000})
                .then(() => window.history.replaceState(null, null, window.location.pathname));
        } else if (status === 'project_edited') {
            Swal.fire({icon: 'success', title: 'อัปเดตโครงงานสำเร็จ', showConfirmButton: false, timer: 1500})
                .then(() => window.history.replaceState(null, null, window.location.pathname));
        } else if (status === 'project_deleted') {
            Swal.fire({icon: 'success', title: 'ลบโครงงานเรียบร้อย', showConfirmButton: false, timer: 1500})
                .then(() => window.history.replaceState(null, null, window.location.pathname));
        } else if (status === 'user_added') {
            Swal.fire({icon: 'success', title: 'เพิ่มผู้ใช้สำเร็จ', showConfirmButton: false, timer: 1500})
                .then(() => window.history.replaceState(null, null, window.location.pathname));
        } else if (status === 'email_exists') {
            Swal.fire({icon: 'error', title: 'อีเมลซ้ำ', text: 'อีเมลนี้มีผู้ใช้งานแล้ว!', confirmButtonText: 'ตกลง'})
                .then(() => window.history.replaceState(null, null, window.location.pathname));
        } else if (status === 'student_code_exists') {
            Swal.fire({icon: 'error', title: 'รหัสนักศึกษาซ้ำ', text: 'รหัสนักศึกษานี้ถูกใช้ไปแล้ว กรุณาตรวจสอบใหม่', confirmButtonText: 'ตกลง'})
                .then(() => window.history.replaceState(null, null, window.location.pathname));
        } else if (status === 'settings_saved') {
            Swal.fire({icon: 'success', title: 'บันทึกการตั้งค่าเรียบร้อย', text: 'การตั้งค่าระบบได้รับการอัปเดตแล้ว', showConfirmButton: false, timer: 2000})
                .then(() => window.history.replaceState(null, null, window.location.pathname));
        }

        const currentUserId = <?= $current_user_id ?>;
        const deleteUserForm = document.getElementById('delete-user-form');
        const deleteUserInput = document.getElementById('delete_user_id');
        const deleteProjectForm = document.getElementById('delete-project-form');
        const deleteProjectInput = document.getElementById('delete_project_id');
        function openAddUserModal() {
            document.getElementById('modal-add-user').classList.remove('hidden');
        }

        function closeAddUserModal() { document.getElementById('modal-add-user').classList.add('hidden'); }
        function openEditModal(id, fullname, studentCode, email, role) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_fullname').value = fullname;
            document.getElementById('edit_student_code').value = studentCode;
            document.getElementById('edit_email').value = email;
            document.getElementById('edit_role').value = role;
            
            if (id === currentUserId) {
                document.getElementById('admin_warning').classList.remove('hidden');
            } else {
                document.getElementById('admin_warning').classList.add('hidden');
            }
            document.getElementById('modal-edit').classList.remove('hidden');
        }

        function closeEditModal() { document.getElementById('modal-edit').classList.add('hidden'); }

        function confirmDelete(userId) {
            Swal.fire({
                title: 'ยืนยันการลบ?',
                text: "ลบผู้ใช้นี้แล้วจะไม่สามารถกู้คืนได้!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'ใช่, ลบเลย!',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    deleteUserInput.value = userId;
                    deleteUserForm.submit();
                }
            });
        }

        function openProjectModal(id, name, description) {
            document.getElementById('project_id').value = id;
            document.getElementById('project_name').value = name;
            document.getElementById('project_description').value = description;
            document.getElementById('modal-project').classList.remove('hidden');
        }

        function closeProjectModal() { document.getElementById('modal-project').classList.add('hidden'); }

        function confirmDeleteProject(projectId) {
            Swal.fire({
                title: 'ยืนยันการลบโครงงาน?',
                text: "ลบโครงงานนี้แล้วจะไม่สามารถกู้คืนได้ รวมถึงงานย่อยทั้งหมด!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'ใช่, ลบเลย!',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    deleteProjectInput.value = projectId;
                    deleteProjectForm.submit();
                }
            });
        }
    </script>
</body>
</html>

