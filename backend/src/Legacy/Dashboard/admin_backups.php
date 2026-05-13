<?php
session_start();
require_once __DIR__ . '/../System/db_connect.php';
require_once __DIR__ . '/../System/app_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentUserName = (string)($_SESSION['fullname'] ?? 'ผู้ดูแลระบบ');

$permissions = defaultAdminPermissions();
try {
    $permissions = getAdminPermissions($conn, $currentUserId);
} catch (Throwable $e) {
    $permissions = defaultAdminPermissions();
}

if (!hasAdminPermission($permissions, 'can_backup_restore')) {
    header('Location: admin_dashboard.php?status=permission_denied');
    exit;
}

$redirect = static function (string $status): void {
    header('Location: admin_backups.php?status=' . urlencode($status));
    exit;
};

$downloadName = trim((string)($_GET['download'] ?? ''));
if ($downloadName !== '') {
    $safeName = normalizeDatabaseBackupFileName($downloadName);
    if ($safeName === null) {
        $redirect('invalid_file');
    }

    $path = databaseBackupDirectory() . DIRECTORY_SEPARATOR . $safeName;
    if (!is_file($path)) {
        $redirect('file_not_found');
    }

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . rawurlencode($safeName) . '"');
    header('Content-Length: ' . (string)((int)filesize($path)));
    readfile($path);
    exit;
}

csrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensureValidCsrfOrRedirect('admin_backups.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'create_backup') {
    $backup = createDatabaseBackup($conn, $currentUserId);
    if (!empty($backup['success'])) {
        $redirect('backup_created');
    }
    $redirect('backup_error');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_backup_settings') {
    $autoEnabled = isset($_POST['backup_auto_enabled']) ? '1' : '0';
    $intervalHours = (int)($_POST['backup_auto_interval_hours'] ?? 24);
    if ($intervalHours < 6) {
        $intervalHours = 6;
    }
    if ($intervalHours > 168) {
        $intervalHours = 168;
    }

    saveSystemSetting($conn, 'backup_auto_enabled', $autoEnabled, $currentUserId);
    saveSystemSetting($conn, 'backup_auto_interval_hours', (string)$intervalHours, $currentUserId);
    writeAuditLog($conn, $currentUserId, 'admin.backup.settings.update', 'อัปเดตการตั้งค่าสำรองฐานข้อมูลอัตโนมัติ', 'system', null);

    $redirect('settings_saved');
}

$autoRun = maybeRunAutomaticDatabaseBackup($conn, $currentUserId);
$backupFiles = listDatabaseBackupFiles(50);
$latestBackup = latestDatabaseBackupFile();

$autoEnabled = systemSettingInt($conn, 'backup_auto_enabled', 1, 0, 1) === 1;
$intervalHours = systemSettingInt($conn, 'backup_auto_interval_hours', 24, 6, 168);
$lastGeneratedAt = systemSetting($conn, 'backup_last_generated_at', '-');
$lastFileName = systemSetting($conn, 'backup_last_file_name', '-');
$lastSizeBytes = systemSettingInt($conn, 'backup_last_size_bytes', 0, 0, 2000000000);
$lastError = systemSetting($conn, 'backup_last_error', '');

writeAuditLog($conn, $currentUserId, 'admin.backup.view', 'เปิดหน้าจัดการสำรองฐานข้อมูล', 'system', null);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการสำรองฐานข้อมูล</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: "Sarabun", sans-serif; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 min-h-screen">
    <nav class="bg-gray-800 text-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <i class="fas fa-database"></i>
                <span class="font-bold">จัดการสำรองฐานข้อมูล</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-sm hidden md:inline"><?= htmlspecialchars($currentUserName) ?></span>
                <a href="admin_dashboard.php" class="bg-gray-700 hover:bg-gray-600 px-3 py-1.5 rounded text-sm">
                    <i class="fas fa-arrow-left mr-1"></i> กลับหน้าแอดมิน
                </a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto p-4 md:p-8 space-y-6">
        <?php if (($autoRun['status'] ?? '') === 'created'): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-lg px-4 py-3 text-sm">
                ระบบสร้างสำรองข้อมูลอัตโนมัติแล้วเรียบร้อย (<?= htmlspecialchars((string)($autoRun['backup']['file_name'] ?? '')) ?>)
            </div>
        <?php endif; ?>

        <?php if (($autoRun['status'] ?? '') === 'error'): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-lg px-4 py-3 text-sm">
                ระบบพยายามสำรองข้อมูลอัตโนมัติ แต่เกิดข้อผิดพลาด กรุณาตรวจสอบอีกครั้ง
            </div>
        <?php endif; ?>

        <section class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-blue-500">
                <div class="text-sm text-gray-500">ไฟล์สำรองทั้งหมด</div>
                <div class="text-2xl font-bold"><?= (int)count($backupFiles) ?> ไฟล์</div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-emerald-500">
                <div class="text-sm text-gray-500">สำรองล่าสุด</div>
                <div class="text-sm font-semibold break-all"><?= htmlspecialchars($lastGeneratedAt) ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-indigo-500">
                <div class="text-sm text-gray-500">ขนาดล่าสุด</div>
                <div class="text-xl font-bold"><?= number_format((float)$lastSizeBytes / 1024, 2) ?> KB</div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-amber-500">
                <div class="text-sm text-gray-500">โหมดอัตโนมัติ</div>
                <div class="text-xl font-bold"><?= $autoEnabled ? 'เปิดใช้งาน' : 'ปิดใช้งาน' ?></div>
            </div>
        </section>

        <section class="bg-white rounded-xl shadow p-6">
            <div class="flex flex-wrap gap-3 items-center justify-between">
                <h2 class="font-bold text-lg text-gray-800"><i class="fas fa-bolt mr-2"></i>สำรองทันที</h2>
                <?php if ($latestBackup): ?>
                    <a href="admin_backups.php?download=<?= rawurlencode((string)$latestBackup['name']) ?>"
                       class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded text-sm">
                        <i class="fas fa-download mr-1"></i> ดาวน์โหลดไฟล์ล่าสุด
                    </a>
                <?php endif; ?>
            </div>

            <div class="mt-4 text-sm text-gray-600 space-y-1">
                <p>ไฟล์ล่าสุด: <span class="font-semibold"><?= htmlspecialchars((string)$lastFileName) ?></span></p>
                <p>ที่จัดเก็บ: <code><?= htmlspecialchars(databaseBackupDirectory()) ?></code></p>
                <?php if ($lastError !== ''): ?>
                    <p class="text-rose-600">ข้อผิดพลาดล่าสุด: <?= htmlspecialchars($lastError) ?></p>
                <?php endif; ?>
            </div>

            <form method="POST" class="mt-4">
                <?= csrfInputField() ?>
                <input type="hidden" name="action" value="create_backup">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded font-semibold">
                    <i class="fas fa-database mr-1"></i> สร้างไฟล์สำรองตอนนี้
                </button>
            </form>
        </section>

        <section class="bg-white rounded-xl shadow p-6">
            <h2 class="font-bold text-lg text-gray-800 mb-4"><i class="fas fa-cog mr-2"></i>ตั้งค่าสำรองอัตโนมัติ</h2>
            <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4 items-end">
                <?= csrfInputField() ?>
                <input type="hidden" name="action" value="save_backup_settings">

                <label class="flex items-center gap-3 p-3 border rounded bg-gray-50 md:col-span-2">
                    <input type="checkbox" name="backup_auto_enabled" value="1" <?= $autoEnabled ? 'checked' : '' ?> class="w-5 h-5">
                    <span class="text-sm font-medium">เปิดการสำรองฐานข้อมูลอัตโนมัติ</span>
                </label>

                <div>
                    <label class="block text-sm font-semibold mb-1">ช่วงเวลาสำรอง (ชั่วโมง)</label>
                    <select name="backup_auto_interval_hours" class="border rounded px-3 py-2 w-full">
                        <?php foreach ([6, 12, 24, 48, 72, 168] as $hour): ?>
                            <option value="<?= $hour ?>" <?= $intervalHours === $hour ? 'selected' : '' ?>>
                                ทุก <?= $hour ?> ชั่วโมง
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="text-right">
                    <button type="submit" class="bg-gray-800 hover:bg-gray-900 text-white px-5 py-2 rounded font-semibold">
                        <i class="fas fa-save mr-1"></i> บันทึกการตั้งค่า
                    </button>
                </div>
            </form>
        </section>

        <section class="bg-white rounded-xl shadow p-6">
            <h2 class="font-bold text-lg text-gray-800 mb-4"><i class="fas fa-folder-open mr-2"></i>รายการไฟล์สำรองล่าสุด</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left bg-gray-100 text-gray-700">
                            <th class="px-4 py-2">เวลา</th>
                            <th class="px-4 py-2">ชื่อไฟล์</th>
                            <th class="px-4 py-2">ขนาด</th>
                            <th class="px-4 py-2">ดาวน์โหลด</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($backupFiles)): ?>
                            <tr>
                                <td colspan="4" class="px-4 py-6 text-center text-gray-500">ยังไม่มีไฟล์สำรอง</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($backupFiles as $file): ?>
                                <tr class="border-t">
                                    <td class="px-4 py-2 whitespace-nowrap"><?= htmlspecialchars((string)$file['modified_at']) ?></td>
                                    <td class="px-4 py-2 break-all"><?= htmlspecialchars((string)$file['name']) ?></td>
                                    <td class="px-4 py-2 whitespace-nowrap"><?= number_format(((int)$file['size_bytes']) / 1024, 2) ?> KB</td>
                                    <td class="px-4 py-2">
                                        <a class="text-blue-600 hover:text-blue-800 font-semibold"
                                           href="admin_backups.php?download=<?= rawurlencode((string)$file['name']) ?>">
                                            ดาวน์โหลด
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/rmutp-ui.js"></script>
    <script>
        window.RMUTP.showStatusFromQuery({
            backup_created: { icon: 'success', title: 'สร้างไฟล์สำรองเรียบร้อย', timer: 1500, showConfirmButton: false },
            backup_error: { icon: 'error', title: 'สำรองข้อมูลไม่สำเร็จ', text: 'กรุณาลองใหม่อีกครั้ง' },
            settings_saved: { icon: 'success', title: 'บันทึกการตั้งค่าแล้ว', timer: 1500, showConfirmButton: false },
            invalid_file: { icon: 'error', title: 'ไฟล์ไม่ถูกต้อง' },
            file_not_found: { icon: 'error', title: 'ไม่พบไฟล์สำรองที่เลือก' },
            csrf_invalid: { icon: 'error', title: 'คำขอไม่ถูกต้อง', text: 'กรุณาลองใหม่อีกครั้ง' }
        });
        window.RMUTP.attachFormSubmitGuard();
    </script>
</body>
</html>

