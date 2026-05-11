<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();
require_once __DIR__ . '/../System/db_connect.php';
require_once __DIR__ . '/../System/app_helpers.php';

$message = '';
$fullname = '';
$email = '';
$role = 'student';
$student_code = '';
$registration_open = isRegistrationOpen($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$registration_open) {
        $message = 'ระบบปิดรับสมัครสมาชิกชั่วคราว กรุณาติดต่อผู้ดูแลระบบ';
    }

    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? 'student';
    $student_code = trim($_POST['student_code'] ?? '');

    if (!in_array($role, ['student', 'teacher'], true)) {
        $role = 'student';
    }

    if ($message === '') {
        if ($password !== $confirm_password) {
            $message = 'รหัสผ่านไม่ตรงกัน';
        } elseif ($role === 'student' && $student_code === '') {
            $message = 'กรุณากรอกเลขประจำตัวนักศึกษา';
        } else {
            $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if ($stmt->fetchColumn()) {
                $message = 'อีเมลนี้ถูกใช้งานแล้ว';
            } elseif ($role === 'student') {
                $stmt = $conn->prepare('SELECT id FROM users WHERE student_code = ? LIMIT 1');
                $stmt->execute([$student_code]);
                if ($stmt->fetchColumn()) {
                    $message = 'เลขประจำตัวนักศึกษานี้ถูกใช้งานแล้ว';
                }
            }

            if ($message === '') {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $student_code_for_db = ($role === 'student') ? $student_code : null;

                $sql = 'INSERT INTO users (fullname, email, password, role, student_code) VALUES (?, ?, ?, ?, ?)';
                $stmt = $conn->prepare($sql);
                if ($stmt->execute([$fullname, $email, $hashed_password, $role, $student_code_for_db])) {
                    header('Location: login.php?registered=success');
                    exit;
                }
                $message = 'เกิดข้อผิดพลาดในการลงทะเบียน';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สมัครสมาชิก - RMUTP Project Tracker</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Sarabun', sans-serif; } </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen py-8">
    <div class="bg-white p-8 rounded-lg shadow-lg w-full max-w-md border-t-4 border-yellow-400">
        <h2 class="text-2xl font-bold text-purple-800 mb-6 text-center">ลงทะเบียนสมาชิกใหม่</h2>

        <?php if (!$registration_open): ?>
            <div class="bg-yellow-100 text-yellow-800 p-3 rounded mb-4 text-sm text-center">
                ระบบปิดรับสมัครสมาชิกชั่วคราว กรุณาติดต่อผู้ดูแลระบบ
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4 text-sm text-center"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="role" id="role" value="<?= htmlspecialchars($role) ?>">

            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">สมัครเป็น</label>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" data-role="student" id="role-student-btn" <?= !$registration_open ? 'disabled' : '' ?> class="role-btn px-3 py-2 rounded border text-sm font-bold disabled:bg-gray-200 disabled:text-gray-500 disabled:cursor-not-allowed">
                        นักศึกษา
                    </button>
                    <button type="button" data-role="teacher" id="role-teacher-btn" <?= !$registration_open ? 'disabled' : '' ?> class="role-btn px-3 py-2 rounded border text-sm font-bold disabled:bg-gray-200 disabled:text-gray-500 disabled:cursor-not-allowed">
                        อาจารย์
                    </button>
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">ชื่อ-นามสกุล</label>
                <input type="text" name="fullname" value="<?= htmlspecialchars($fullname) ?>" required <?= !$registration_open ? 'disabled' : '' ?> class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:border-purple-500">
            </div>

            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">อีเมล</label>
                <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required <?= !$registration_open ? 'disabled' : '' ?> class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:border-purple-500">
            </div>

            <div id="student-code-wrap" class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">เลขประจำตัวนักศึกษา</label>
                <input type="text" name="student_code" id="student_code" value="<?= htmlspecialchars($student_code) ?>" <?= !$registration_open ? 'disabled' : '' ?> class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:border-purple-500" placeholder="เช่น 653040123-1">
            </div>

            <div class="mb-4 grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2">รหัสผ่าน</label>
                    <input type="password" name="password" required <?= !$registration_open ? 'disabled' : '' ?> class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:border-purple-500">
                </div>
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2">ยืนยันรหัสผ่าน</label>
                    <input type="password" name="confirm_password" required <?= !$registration_open ? 'disabled' : '' ?> class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:border-purple-500">
                </div>
            </div>

            <button type="submit" id="submit-btn" <?= !$registration_open ? 'disabled' : '' ?> class="w-full bg-purple-800 text-white py-2 rounded-lg hover:bg-purple-900 transition font-bold disabled:bg-gray-300 disabled:text-gray-600 disabled:cursor-not-allowed">
                สมัครสมาชิกนักศึกษา
            </button>
        </form>

        <div class="mt-4 text-center">
            <a href="login.php" class="text-sm text-gray-500 hover:text-purple-800">มีบัญชีอยู่แล้ว? เข้าสู่ระบบ</a>
        </div>
    </div>

    <script>
        const roleInputEl = document.getElementById('role');
        const roleButtons = document.querySelectorAll('.role-btn');
        const studentCodeWrapEl = document.getElementById('student-code-wrap');
        const studentCodeInputEl = document.getElementById('student_code');
        const submitBtnEl = document.getElementById('submit-btn');

        function paintRoleButtons(selectedRole) {
            roleButtons.forEach((btn) => {
                const isActive = btn.getAttribute('data-role') === selectedRole;
                btn.classList.toggle('bg-purple-700', isActive);
                btn.classList.toggle('text-white', isActive);
                btn.classList.toggle('border-purple-700', isActive);
                btn.classList.toggle('bg-white', !isActive);
                btn.classList.toggle('text-gray-700', !isActive);
                btn.classList.toggle('border-gray-300', !isActive);
            });
        }

        function syncRoleUI() {
            const currentRole = roleInputEl.value === 'teacher' ? 'teacher' : 'student';
            const isStudent = currentRole === 'student';

            paintRoleButtons(currentRole);
            studentCodeWrapEl.classList.toggle('hidden', !isStudent);
            studentCodeInputEl.required = isStudent;
            if (!isStudent) {
                studentCodeInputEl.value = '';
            }

            submitBtnEl.textContent = isStudent ? 'สมัครสมาชิกนักศึกษา' : 'สมัครสมาชิกอาจารย์';
        }

        roleButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                roleInputEl.value = btn.getAttribute('data-role');
                syncRoleUI();
            });
        });

        syncRoleUI();
    </script>
</body>
</html>
