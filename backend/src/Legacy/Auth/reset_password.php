<?php
require_once __DIR__ . '/../System/db_connect.php';

$message = '';
$token = $_GET['token'] ?? '';
$valid_token = false;

// ตรวจสอบ Token ว่าถูกต้องและยังไม่หมดอายุ
if ($token) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE reset_token = ? AND token_expire > NOW()");
    $stmt->execute([$token]);
    if ($stmt->rowCount() > 0) {
        $valid_token = true;
    } else {
        $message = "ลิงก์นี้ไม่ถูกต้องหรือหมดอายุแล้ว";
    }
} else {
    $message = "ไม่พบรหัส Token";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $valid_token) {
    $new_pass = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];

    if ($new_pass === $confirm_pass) {
        // Hash รหัสผ่านใหม่
        $hashed_password = password_hash($new_pass, PASSWORD_DEFAULT);
        
        // อัปเดตรหัสผ่าน + ลบ Token ทิ้ง (เพื่อไม่ให้ใช้ลิงก์เดิมซ้ำได้)
        $update = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, token_expire = NULL WHERE reset_token = ?");
        if ($update->execute([$hashed_password, $token])) {
            header("Location: login.php?registered=password_reset_success");
            exit;
        }
    } else {
        $message = "รหัสผ่านไม่ตรงกัน";
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ตั้งรหัสผ่านใหม่</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Sarabun', sans-serif; } </style>
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="bg-white p-8 rounded-lg shadow-lg w-full max-w-sm border-t-4 border-yellow-400">
        <h2 class="text-xl font-bold mb-4 text-purple-800 text-center">ตั้งรหัสผ่านใหม่</h2>

        <?php if ($message): ?>
            <div class="bg-red-100 text-red-700 p-2 rounded mb-4 text-sm text-center"><?= $message ?></div>
        <?php endif; ?>

        <?php if ($valid_token): ?>
        <form method="POST">
            <div class="mb-4">
                <label class="block text-sm font-bold mb-1">รหัสผ่านใหม่</label>
                <input type="password" name="new_password" required class="w-full px-3 py-2 border rounded">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-bold mb-1">ยืนยันรหัสผ่านใหม่</label>
                <input type="password" name="confirm_password" required class="w-full px-3 py-2 border rounded">
            </div>
            <button type="submit" class="w-full bg-purple-800 text-white py-2 rounded font-bold hover:bg-purple-900">บันทึกรหัสผ่าน</button>
        </form>
        <?php else: ?>
            <div class="text-center mt-4">
                <a href="forgot_password.php" class="text-purple-800 underline">ขอลิงก์ใหม่</a> หรือ 
                <a href="login.php" class="text-gray-500">กลับหน้า Login</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>