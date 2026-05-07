<?php
session_start();
require_once __DIR__ . '/../System/db_connect.php';

// 1. เช็คสิทธิ์ (ต้องเป็น Admin เท่านั้น)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$user_name = $_SESSION['fullname'];
$current_user_id = $_SESSION['user_id'];

// --- ACTION: อัปเดตประกาศ (Announcement) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_announcement') {
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
    header("Location: admin_dashboard.php?status=announced");
    exit;
}

// --- ACTION: แก้ไขข้อมูลผู้ใช้ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit_user') {
    $e_id = $_POST['edit_id'];
    $e_name = trim($_POST['edit_fullname']);
    $e_email = trim($_POST['edit_email']);
    $e_role = $_POST['edit_role'];

    if ($e_id == $current_user_id && $e_role != 'admin') { $e_role = 'admin'; }

    $stmt = $conn->prepare("UPDATE users SET fullname = ?, email = ?, role = ? WHERE id = ?");
    $stmt->execute([$e_name, $e_email, $e_role, $e_id]);
    header("Location: admin_dashboard.php?status=edited");
    exit;
}

// --- ACTION: ลบผู้ใช้ ---
if (isset($_GET['delete_id'])) {
    $del_id = $_GET['delete_id'];
    if ($del_id != $current_user_id) {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$del_id]);
        header("Location: admin_dashboard.php?status=deleted");
    } else {
        header("Location: admin_dashboard.php?status=error_self");
    }
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
$sql = "SELECT * FROM users WHERE (fullname LIKE ? OR email LIKE ?)";
$params = ["%$search%", "%$search%"];
if ($role_filter !== 'all') { $sql .= " AND role = ?"; $params[] = $role_filter; }
$sql .= " ORDER BY id DESC";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$users_list = $stmt->fetchAll();
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
                <div><h1 class="text-lg font-bold">System Admin</h1></div>
            </div>
            <div class="flex items-center space-x-4">
                <span class="font-medium text-sm hidden md:inline"><?= htmlspecialchars($user_name) ?></span>
                <span class="px-3 py-1 bg-red-600 text-white text-xs font-bold rounded-full shadow">Admin</span>
                <a href="logout.php" class="text-sm bg-gray-700 px-3 py-1.5 rounded hover:bg-gray-600 transition"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
            </div>
        </div>
    </nav>

    <div class="flex-1 p-4 md:p-8">
        <div class="max-w-7xl mx-auto">
            
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-gray-800">แดชบอร์ดการจัดการ</h2>
            </div>

            <!-- กล่องจัดการประกาศข่าวสาร (ใหม่) -->
            <div class="card p-6 border-l-4 border-yellow-500 mb-8 bg-yellow-50">
                <form method="POST">
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
                    
                    <form method="GET" class="flex w-full lg:w-auto gap-2">
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหาชื่อ หรือ อีเมล..." class="border p-2 rounded w-full lg:w-64 focus:ring-2 focus:ring-gray-800 outline-none text-sm">
                        <select name="role" class="border p-2 rounded bg-white focus:ring-2 focus:ring-gray-800 outline-none text-sm cursor-pointer">
                            <option value="all" <?= $role_filter=='all'?'selected':'' ?>>ทุกบทบาท</option>
                            <option value="student" <?= $role_filter=='student'?'selected':'' ?>>นักศึกษา (Student)</option>
                            <option value="teacher" <?= $role_filter=='teacher'?'selected':'' ?>>อาจารย์ (Teacher)</option>
                            <option value="admin" <?= $role_filter=='admin'?'selected':'' ?>>แอดมิน (Admin)</option>
                        </select>
                        <button type="submit" class="bg-gray-800 text-white px-4 py-2 rounded hover:bg-gray-900 transition shadow shrink-0"><i class="fas fa-search"></i></button>
                        
                        <?php if(!empty($search) || $role_filter!='all'): ?>
                            <a href="admin_dashboard.php" class="bg-red-100 text-red-600 px-4 py-2 rounded hover:bg-red-200 transition text-sm flex items-center shrink-0">ล้างค่า</a>
                        <?php endif; ?>
                    </form>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full leading-normal">
                        <thead>
                            <tr>
                                <th class="px-5 py-3 border-b-2 bg-gray-50 text-left text-xs font-bold text-gray-600 uppercase">ID</th>
                                <th class="px-5 py-3 border-b-2 bg-gray-50 text-left text-xs font-bold text-gray-600 uppercase">ชื่อ-นามสกุล</th>
                                <th class="px-5 py-3 border-b-2 bg-gray-50 text-left text-xs font-bold text-gray-600 uppercase">อีเมล</th>
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
                                <td class="px-5 py-4 border-b border-gray-100 text-sm text-center">
                                    <?php 
                                        $bg = 'bg-gray-100'; $text = 'text-gray-800'; $icon = 'fa-user';
                                        if($u['role'] == 'student') { $bg='bg-green-100'; $text='text-green-800'; $icon='fa-user-graduate'; }
                                        elseif($u['role'] == 'teacher') { $bg='bg-yellow-100'; $text='text-yellow-800'; $icon='fa-chalkboard-teacher'; }
                                        elseif($u['role'] == 'admin') { $bg='bg-red-100'; $text='text-red-800'; $icon='fa-shield-alt'; }
                                    ?>
                                    <span class="px-3 py-1 rounded-full text-xs font-bold <?= $bg ?> <?= $text ?> inline-flex items-center gap-1">
                                        <i class="fas <?= $icon ?>"></i> <?= ucfirst($u['role']) ?>
                                    </span>
                                </td>
                                <td class="px-5 py-4 border-b border-gray-100 text-sm text-center">
                                    <button onclick="openEditModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['fullname'])) ?>', '<?= htmlspecialchars(addslashes($u['email'])) ?>', '<?= $u['role'] ?>')" class="text-blue-500 hover:text-blue-800 bg-blue-50 px-3 py-1.5 rounded transition mx-1" title="แก้ไข">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    
                                    <?php if($u['id'] != $current_user_id): ?>
                                    <button onclick="confirmDelete(<?= $u['id'] ?>)" class="text-red-500 hover:text-red-800 bg-red-50 px-3 py-1.5 rounded transition mx-1" title="ลบ">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if(count($users_list) == 0): ?>
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-gray-500 bg-white">
                                    <i class="fas fa-search text-3xl mb-3 text-gray-300"></i><br>ไม่พบข้อมูลผู้ใช้งานที่ค้นหา
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
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
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="edit_id" id="edit_id">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">ชื่อ-นามสกุล</label>
                        <input type="text" name="edit_fullname" id="edit_fullname" required class="w-full border p-2 rounded focus:ring-2 focus:ring-gray-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">อีเมล</label>
                        <input type="email" name="edit_email" id="edit_email" required class="w-full border p-2 rounded focus:ring-2 focus:ring-gray-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">บทบาท (Role)</label>
                        <select name="edit_role" id="edit_role" class="w-full border p-2 rounded focus:ring-2 focus:ring-gray-500 outline-none bg-gray-50 cursor-pointer">
                            <option value="student">นักศึกษา (Student)</option>
                            <option value="teacher">อาจารย์ (Teacher)</option>
                            <option value="admin">ผู้ดูแลระบบ (Admin)</option>
                        </select>
                        <p class="text-[10px] text-red-500 mt-1 hidden" id="admin_warning">* คุณไม่สามารถปลดสิทธิ์ Admin ของตัวเองได้</p>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300 transition text-sm font-bold">ยกเลิก</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition text-sm font-bold shadow-md"><i class="fas fa-save mr-1"></i> บันทึกข้อมูล</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Script สำหรับทำงานต่างๆ -->
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
        } else if (status === 'announced') {
            Swal.fire({icon: 'success', title: 'อัปเดตประกาศสำเร็จ', text: 'นักศึกษาและอาจารย์จะเห็นประกาศนี้ทันที', showConfirmButton: false, timer: 2000})
                .then(() => window.history.replaceState(null, null, window.location.pathname));
        }

        const currentUserId = <?= $current_user_id ?>;
        function openEditModal(id, fullname, email, role) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_fullname').value = fullname;
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
                    window.location.href = `?delete_id=${userId}`;
                }
            });
        }
    </script>
</body>
</html>