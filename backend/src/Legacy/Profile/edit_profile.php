<?php
session_start();
require_once __DIR__ . '/../System/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// --------------------------------------------------------
// 🚀 1. AJAX HANDLER: สำหรับเช็คพาสเวิร์ด (ทำงานเบื้องหลัง)
// --------------------------------------------------------
if (isset($_POST['ajax_check_pass'])) {
    header('Content-Type: application/json');
    
    $input_pass = $_POST['current_password'];
    
    // ดึงรหัสผ่านจริงจากฐานข้อมูล
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (password_verify($input_pass, $user['password'])) {
        echo json_encode(['status' => 'valid']);
    } else {
        echo json_encode(['status' => 'invalid']);
    }
    exit; // จบการทำงานส่วน AJAX ไม่ให้โหลดหน้าเว็บต่อ
}
// --------------------------------------------------------

$msg = '';
$msg_type = '';

// กำหนดปุ่ม Back
$back_url = 'student_dashboard.php';
if ($_SESSION['role'] == 'teacher') $back_url = 'teacher_dashboard.php';
elseif ($_SESSION['role'] == 'admin') $back_url = 'admin_dashboard.php';

// --- ACTION: บันทึกข้อมูล (Server-Side Validation) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['ajax_check_pass'])) {
    $fullname = trim($_POST['fullname']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // ดึงข้อมูล User อีกครั้งเพื่อความชัวร์
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (empty($fullname)) {
        $msg = "กรุณากรอกชื่อ-นามสกุล";
        $msg_type = "error";
    } else {
        try {
            if (!empty($password)) {
                // เช็คซ้ำอีกรอบฝั่ง Server เพื่อความปลอดภัยสูงสุด
                $current_password_check = $_POST['current_password'];
                if (!password_verify($current_password_check, $user['password'])) {
                    $msg = "รหัสผ่านเดิมไม่ถูกต้อง (ตรวจสอบแล้ว)";
                    $msg_type = "error";
                } elseif ($password !== $confirm_password) {
                    $msg = "รหัสผ่านใหม่ไม่ตรงกัน";
                    $msg_type = "error";
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET fullname = ?, password = ? WHERE id = ?");
                    $stmt->execute([$fullname, $hashed_password, $user_id]);
                    $_SESSION['fullname'] = $fullname;
                    $msg = "เปลี่ยนรหัสผ่านสำเร็จ";
                    $msg_type = "success";
                }
            } else {
                $stmt = $conn->prepare("UPDATE users SET fullname = ? WHERE id = ?");
                $stmt->execute([$fullname, $user_id]);
                $_SESSION['fullname'] = $fullname;
                $msg = "บันทึกข้อมูลสำเร็จ";
                $msg_type = "success";
            }
        } catch (PDOException $e) {
            $msg = "Error: " . $e->getMessage();
            $msg_type = "error";
        }
    }
}

// ดึงข้อมูลแสดงผล
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แก้ไขข้อมูลส่วนตัว</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/rmutp-ui.js"></script>
    <style>@import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;700&display=swap'); body{font-family:'Sarabun',sans-serif;}</style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">

    <div class="bg-white p-8 rounded-lg shadow-lg w-full max-w-md">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-gray-800"><i class="fas fa-user-edit"></i> แก้ไขข้อมูลส่วนตัว</h2>
            <a href="<?= $back_url ?>" class="text-gray-500 hover:text-red-500"><i class="fas fa-times text-xl"></i></a>
        </div>

        <form method="POST" id="profileForm">
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">อีเมล</label>
                <input type="text" value="<?= htmlspecialchars($user['email']) ?>" disabled class="w-full px-3 py-2 border bg-gray-100 rounded text-gray-500 cursor-not-allowed">
            </div>

            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">ชื่อ-นามสกุล</label>
                <input type="text" name="fullname" value="<?= htmlspecialchars($user['fullname']) ?>" required class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-purple-600">
            </div>

            <hr class="my-6 border-gray-200">
            
            <h3 class="font-bold text-gray-600 mb-3 flex items-center gap-2">
                <i class="fas fa-key"></i> เปลี่ยนรหัสผ่าน 
                <span id="passStatus" class="text-xs font-normal text-gray-400">(ต้องยืนยันรหัสเดิมก่อน)</span>
            </h3>

            <div class="mb-4 relative">
                <label class="block text-gray-700 text-sm font-bold mb-2">รหัสผ่านเดิม</label>
                <input type="password" id="current_password" name="current_password" placeholder="กรอกรหัสเดิมเพื่อปลดล็อก" class="w-full px-3 py-2 border border-yellow-400 bg-yellow-50 rounded focus:outline-none focus:ring-2 focus:ring-yellow-500 transition-colors">
                <div id="checkIcon" class="absolute right-3 top-9 hidden text-green-600"><i class="fas fa-check-circle"></i></div>
            </div>

            <div class="flex gap-2 mb-6">
                <div class="flex-1">
                    <label class="block text-gray-700 text-sm font-bold mb-2">รหัสผ่านใหม่</label>
                    <input type="password" id="new_password" name="password" placeholder="********" disabled class="w-full px-3 py-2 border bg-gray-100 text-gray-400 rounded cursor-not-allowed focus:outline-none transition-all">
                </div>
                <div class="flex-1">
                    <label class="block text-gray-700 text-sm font-bold mb-2">ยืนยันรหัสใหม่</label>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="********" disabled class="w-full px-3 py-2 border bg-gray-100 text-gray-400 rounded cursor-not-allowed focus:outline-none transition-all">
                </div>
            </div>

            <button type="submit" class="w-full bg-purple-800 text-white font-bold py-2 px-4 rounded hover:bg-purple-900 transition shadow-lg">
                บันทึกการเปลี่ยนแปลง
            </button>
        </form>
    </div>

    <script>
        const currentPassInput = document.getElementById('current_password');
        const newPassInput = document.getElementById('new_password');
        const confirmPassInput = document.getElementById('confirm_password');
        const passStatus = document.getElementById('passStatus');
        const checkIcon = document.getElementById('checkIcon');

        // เมื่อพิมพ์เสร็จแล้วกดออกจากช่อง (Blur event)
        currentPassInput.addEventListener('blur', function() {
            const password = this.value;

            if (password.length > 0) {
                // ส่งค่าไปเช็คที่ไฟล์เดิม (edit_profile.php) ผ่าน AJAX
                const formData = new FormData();
                formData.append('ajax_check_pass', '1');
                formData.append('current_password', password);

                fetch('edit_profile.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'valid') {
                        // ✅ รหัสถูก: ปลดล็อกช่อง
                        unlockFields();
                        passStatus.innerHTML = '<span class="text-green-600 font-bold">ถูกต้อง! กรอกรหัสใหม่ได้เลย</span>';
                        checkIcon.classList.remove('hidden');
                        currentPassInput.classList.replace('border-yellow-400', 'border-green-500');
                        currentPassInput.classList.replace('bg-yellow-50', 'bg-green-50');
                    } else {
                        // ❌ รหัสผิด: แจ้งเตือนและล็อกเหมือนเดิม
                        lockFields();
                        Swal.fire({
                            icon: 'error',
                            title: 'รหัสผ่านไม่ถูกต้อง',
                            text: 'กรุณาลองใหม่อีกครั้ง',
                            confirmButtonColor: '#d33'
                        });
                        this.value = ''; // ล้างค่า
                        passStatus.innerHTML = '<span class="text-red-500">รหัสผิด! กรุณาลองใหม่</span>';
                    }
                })
                .catch(error => console.error('Error:', error));
            } else {
                // ถ้าลบจนว่าง ให้ล็อกกลับ
                lockFields();
                passStatus.innerHTML = '(ต้องยืนยันรหัสเดิมก่อน)';
                checkIcon.classList.add('hidden');
                resetStyle();
            }
        });

        function unlockFields() {
            newPassInput.disabled = false;
            confirmPassInput.disabled = false;
            newPassInput.classList.remove('bg-gray-100', 'text-gray-400', 'cursor-not-allowed');
            confirmPassInput.classList.remove('bg-gray-100', 'text-gray-400', 'cursor-not-allowed');
            newPassInput.classList.add('bg-white', 'focus:ring-2', 'focus:ring-purple-600');
            confirmPassInput.classList.add('bg-white', 'focus:ring-2', 'focus:ring-purple-600');
        }

        function lockFields() {
            newPassInput.disabled = true;
            confirmPassInput.disabled = true;
            newPassInput.value = '';
            confirmPassInput.value = '';
            newPassInput.classList.add('bg-gray-100', 'text-gray-400', 'cursor-not-allowed');
            confirmPassInput.classList.add('bg-gray-100', 'text-gray-400', 'cursor-not-allowed');
            newPassInput.classList.remove('bg-white', 'focus:ring-2', 'focus:ring-purple-600');
            confirmPassInput.classList.remove('bg-white', 'focus:ring-2', 'focus:ring-purple-600');
        }

        function resetStyle() {
            currentPassInput.classList.replace('border-green-500', 'border-yellow-400');
            currentPassInput.classList.replace('bg-green-50', 'bg-yellow-50');
        }

        // Popup สำหรับการบันทึก (PHP Logic)
        <?php if ($msg): ?>
            Swal.fire({
                icon: '<?= $msg_type ?>',
                title: '<?= $msg_type == "success" ? "สำเร็จ!" : "แจ้งเตือน" ?>',
                text: '<?= $msg ?>',
                confirmButtonColor: '#581c87'
            }).then(() => {
                <?php if($msg_type == 'success'): ?>
                    window.location.href = '<?= $back_url ?>';
                <?php endif; ?>
            });
        <?php endif; ?>
    </script>

</body>
</html>
