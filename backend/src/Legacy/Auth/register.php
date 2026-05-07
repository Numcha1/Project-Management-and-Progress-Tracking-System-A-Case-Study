<?php
session_start();
require_once __DIR__ . '/../System/db_connect.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fullname = $_POST['fullname'];
    $email = $_POST['email'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = $_POST['role'];

    if ($password !== $confirm_password) {
        $message = "รหัสผ่านไม่ตรงกัน";
    } else {
        // ตรวจสอบอีเมลซ้ำ
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        
        if ($stmt->rowCount() > 0) {
            $message = "อีเมลนี้ถูกใช้งานแล้ว";
        } else {
            // Hash Password เพื่อความปลอดภัย
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            $sql = "INSERT INTO users (fullname, email, password, role) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if ($stmt->execute([$fullname, $email, $hashed_password, $role])) {
                header("Location: login.php?registered=success");
                exit;
            } else {
                $message = "เกิดข้อผิดพลาดในการลงทะเบียน";
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
<body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="bg-white p-8 rounded-lg shadow-lg w-full max-w-md border-t-4 border-yellow-400">
        <h2 class="text-2xl font-bold text-purple-800 mb-6 text-center">ลงทะเบียนสมาชิกใหม่</h2>
        
        <?php if($message): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4 text-sm text-center"><?= $message ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">ชื่อ-นามสกุล</label>
                <input type="text" name="fullname" required class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:border-purple-500">
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">อีเมล</label>
                <input type="email" name="email" required class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:border-purple-500">
            </div>
            <div class="mb-4 grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2">รหัสผ่าน</label>
                    <input type="password" name="password" required class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:border-purple-500">
                </div>
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2">ยืนยันรหัสผ่าน</label>
                    <input type="password" name="confirm_password" required class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:border-purple-500">
                </div>
            </div>
            <div class="mb-6">
                <label class="block text-gray-700 text-sm font-bold mb-2">บทบาท</label>
                <select name="role" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:border-purple-500">
                    <option value="student">นักศึกษา</option>
                    <option value="teacher">อาจารย์ที่ปรึกษา</option>
                </select>
            </div>
            <button type="submit" class="w-full bg-purple-800 text-white py-2 rounded-lg hover:bg-purple-900 transition font-bold">สมัครสมาชิก</button>
        </form>
        <div class="mt-4 text-center">
            <a href="login.php" class="text-sm text-gray-500 hover:text-purple-800">มีบัญชีอยู่แล้ว? เข้าสู่ระบบ</a>
        </div>
    </div>
</body>
</html>