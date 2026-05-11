<?php
session_start();
require_once __DIR__ . '/../System/db_connect.php';
require_once __DIR__ . '/../System/app_helpers.php';

$script_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$app_base_path = $script_dir;
if (preg_match('#^(.*)/frontend/public$#', $script_dir, $m) === 1) {
    $app_base_path = $m[1];
} elseif (preg_match('#^(.*)/backend/src/Legacy/Auth$#', $script_dir, $m) === 1) {
    $app_base_path = $m[1];
}
$logo_src = rtrim($app_base_path, '/') . '/frontend/public/Image/IS.png';
if ($logo_src === '/frontend/public/Image/IS.png') {
    $logo_src = 'Image/IS.png';
}

$message = '';
// ข้อความสำหรับการสมัครสมาชิกใหม่
if(isset($_GET['registered'])) {
    $message = "สมัครสมาชิกเรียบร้อย กรุณาเข้าสู่ระบบ";
}
// ✅ เพิ่มข้อความสำหรับการเปลี่ยนรหัสผ่านใหม่
if(isset($_GET['reset'])) {
    $message = "เปลี่ยนรหัสผ่านเรียบร้อยแล้ว กรุณาเข้าสู่ระบบ";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        // Login สำเร็จ -> เก็บ Session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['fullname'] = $user['fullname'];
        $_SESSION['role'] = $user['role'];
        writeAuditLog($conn, (int)$user['id'], 'auth.login.success', 'เข้าสู่ระบบสำเร็จ', 'auth', (int)$user['id']);

        header("Location: index.php"); // ไปยังหน้า Dashboard
        exit;
    } else {
        if ($user) {
            writeAuditLog($conn, (int)$user['id'], 'auth.login.failed_password', 'เข้าสู่ระบบไม่สำเร็จ: รหัสผ่านไม่ถูกต้อง', 'auth', (int)$user['id']);
        }
        $message = "อีเมลหรือรหัสผ่านไม่ถูกต้อง";
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - RMUTP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">

    <div class="bg-white p-8 rounded-xl shadow-xl w-96">
        
        <div class="text-center mb-6">
            <img src="<?= htmlspecialchars($logo_src) ?>" alt="IS Logo" class="h-24 w-auto mx-auto mb-4 object-contain drop-shadow-md">
            <h1 class="mt-2 text-xl font-bold text-gray-800">RMUTP Project Tracker</h1>
        </div>

        <?php if($message): ?>
            <div class="<?= strpos($message, 'เรียบร้อย') !== false ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?> p-2 rounded mb-4 text-sm text-center font-medium shadow-sm">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">อีเมล</label>
                <input type="email" name="email" required class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition">
            </div>
            
            <div class="mb-5">
                <div class="flex justify-between items-center mb-2">
                    <label class="block text-gray-700 text-sm font-bold">รหัสผ่าน</label>
                    <a href="forgot_password.php" class="text-sm text-purple-600 hover:text-purple-800 hover:underline transition">ลืมรหัสผ่าน?</a>
                </div>
                <input type="password" name="password" required class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition">
            </div>
            
            <button type="submit" class="w-full bg-purple-800 text-white py-2 rounded-lg hover:bg-purple-900 transition font-bold shadow">เข้าสู่ระบบ</button>
        </form>

        <div class="mt-5 text-center text-sm text-gray-600">
            ยังไม่มีบัญชีผู้ใช้? <a href="register.php" class="text-purple-800 font-bold hover:underline transition">สมัครสมาชิก</a>
        </div>
    </div>

</body>
</html>
