<?php

session_start();
require_once __DIR__ . '/../System/db_connect.php';
require_once __DIR__ . '/../System/app_helpers.php';
require_once __DIR__ . '/../System/tenant_helpers.php';

$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$appBasePath = $scriptDir;
if (preg_match('#^(.*)/frontend/public$#', $scriptDir, $m) === 1) {
    $appBasePath = $m[1];
} elseif (preg_match('#^(.*)/backend/src/Legacy/Auth$#', $scriptDir, $m) === 1) {
    $appBasePath = $m[1];
}
$logoSrc = rtrim($appBasePath, '/') . '/frontend/public/Image/IS.png';
if ($logoSrc === '/frontend/public/Image/IS.png') {
    $logoSrc = 'Image/IS.png';
}

$tenantContext = tenantRuntimeContext();
$selectedTenant = tenantNormalizeCode((string)($_POST['tenant'] ?? ($_GET['tenant'] ?? ($_SESSION['tenant_code'] ?? ($tenantContext['tenant_code'] ?? '')))));

$availableFaculties = [];
try {
    $coreConn = tenantCoreConnection();
    tenantEnsureCoreTables($coreConn);
    $availableFaculties = tenantFetchActiveFaculties($coreConn);
} catch (Throwable $e) {
    $availableFaculties = [];
}

$message = '';
if (isset($_GET['registered'])) {
    $message = 'สมัครสมาชิกสำเร็จ กรุณาเข้าสู่ระบบ';
}
if (isset($_GET['reset'])) {
    $message = 'เปลี่ยนรหัสผ่านสำเร็จ กรุณาเข้าสู่ระบบ';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $selectedTenant = tenantNormalizeCode((string)($_POST['tenant'] ?? $selectedTenant));

    if ($selectedTenant !== '') {
        $_SESSION['tenant_code'] = $selectedTenant;
    }

    $stmt = $conn->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, (string)$user['password'])) {
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['fullname'] = (string)$user['fullname'];
        $_SESSION['role'] = (string)$user['role'];
        if ($selectedTenant !== '') {
            $_SESSION['tenant_code'] = $selectedTenant;
        }

        writeAuditLog($conn, (int)$user['id'], 'auth.login.success', 'Login success', 'auth', (int)$user['id']);
        header('Location: index.php');
        exit;
    }

    if ($user) {
        writeAuditLog($conn, (int)$user['id'], 'auth.login.failed_password', 'Invalid password', 'auth', (int)$user['id']);
    }
    $message = 'อีเมลหรือรหัสผ่านไม่ถูกต้อง';
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
    <script src="assets/js/rmutp-ui.js"></script>
    <style>
        body { font-family: 'Sarabun', sans-serif; }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen p-4">
    <div class="bg-white p-8 rounded-xl shadow-xl w-full max-w-md">
        <div class="text-center mb-6">
            <img src="<?= htmlspecialchars($logoSrc) ?>" alt="IS Logo" class="h-24 w-auto mx-auto mb-4 object-contain drop-shadow-md">
            <h1 class="text-xl font-bold text-gray-800">RMUTP Project Tracker</h1>
        </div>

        <?php if ($message !== ''): ?>
            <div class="<?= str_contains($message, 'สำเร็จ') ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?> p-2 rounded mb-4 text-sm text-center font-medium shadow-sm">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <?php if (!empty($availableFaculties)): ?>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">คณะ (Tenant)</label>
                <select name="tenant" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition">
                    <option value="">-- เลือกคณะ --</option>
                    <?php foreach ($availableFaculties as $faculty): ?>
                        <?php $code = (string)($faculty['code'] ?? ''); ?>
                        <option value="<?= htmlspecialchars($code) ?>" <?= $selectedTenant === $code ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string)($faculty['name_th'] ?? $faculty['name_en'] ?? $code)) ?> (<?= htmlspecialchars($code) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">อีเมล</label>
                <input type="email" name="email" required class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition">
            </div>

            <div>
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

