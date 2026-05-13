<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../System/db_connect.php';
require_once __DIR__ . '/../System/app_helpers.php';
require_once __DIR__ . '/../System/tenant_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

$adminId = (int)($_SESSION['user_id'] ?? 0);
$adminName = (string)($_SESSION['fullname'] ?? '');

csrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensureValidCsrfOrRedirect('tenant_admin.php');
}

$status = '';
$error = '';
$projectRoot = dirname(__DIR__, 4);
$tenantSqlPath = $projectRoot . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'rmutp_database.sql';

try {
    tenantEnsureCoreDatabaseExists();
    $core = tenantCoreConnection();
    tenantEnsureCoreTables($core);
} catch (Throwable $e) {
    $core = null;
    $error = 'ไม่สามารถเชื่อมต่อฐานข้อมูลกลางได้: ' . $e->getMessage();
}

$importUsersFromCsv = static function (PDO $tenantPdo, array $file, bool $upsert): array {
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ');
    }
    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('ไฟล์ที่อัปโหลดไม่ถูกต้อง');
    }

    $fh = fopen($tmpPath, 'rb');
    if ($fh === false) {
        throw new RuntimeException('ไม่สามารถอ่านไฟล์ CSV ที่อัปโหลดได้');
    }

    $headerRaw = fgetcsv($fh);
    if (!is_array($headerRaw) || count($headerRaw) === 0) {
        fclose($fh);
        throw new RuntimeException('ไม่พบส่วนหัว (header) ของไฟล์ CSV');
    }

    $header = array_map(static fn($value): string => strtolower(trim((string)$value)), $headerRaw);
    $map = array_flip($header);
    foreach (['fullname', 'email', 'role'] as $required) {
        if (!array_key_exists($required, $map)) {
            fclose($fh);
            throw new RuntimeException('คอลัมน์ที่จำเป็นหายไป: ' . $required);
        }
    }

    $stats = [
        'total' => 0,
        'inserted' => 0,
        'updated' => 0,
        'failed' => 0,
        'messages' => [],
    ];
    $validRoles = ['student', 'teacher', 'admin'];

    while (($row = fgetcsv($fh)) !== false) {
        $stats['total']++;
        $lineNo = $stats['total'] + 1;
        try {
            $fullname = trim((string)($row[$map['fullname']] ?? ''));
            $email = strtolower(trim((string)($row[$map['email']] ?? '')));
            $role = strtolower(trim((string)($row[$map['role']] ?? '')));
            $studentCode = trim((string)($row[$map['student_code']] ?? ''));
            $plainPassword = trim((string)($row[$map['password']] ?? ''));
            if ($studentCode === '') {
                $studentCode = null;
            }

            if ($fullname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('ชื่อหรืออีเมลไม่ถูกต้อง');
            }
            if (!in_array($role, $validRoles, true)) {
                throw new RuntimeException('บทบาทไม่ถูกต้อง');
            }
            if ($plainPassword !== '' && strlen($plainPassword) < 10) {
                throw new RuntimeException('รหัสผ่านต้องยาวอย่างน้อย 10 ตัวอักษร');
            }

            $existingStmt = $tenantPdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $existingStmt->execute([$email]);
            $existingId = (int)$existingStmt->fetchColumn();

            if ($existingId > 0) {
                if (!$upsert) {
                    throw new RuntimeException('อีเมลนี้มีอยู่แล้ว (ให้เปิดโหมด upsert หากต้องการอัปเดต)');
                }

                if ($plainPassword !== '') {
                    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
                    $updateStmt = $tenantPdo->prepare("
                        UPDATE users
                        SET fullname = ?, role = ?, student_code = ?, password = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$fullname, $role, $studentCode, $hash, $existingId]);
                } else {
                    $updateStmt = $tenantPdo->prepare("
                        UPDATE users
                        SET fullname = ?, role = ?, student_code = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$fullname, $role, $studentCode, $existingId]);
                }

                $stats['updated']++;
                $stats['messages'][] = 'L' . $lineNo . ': อัปเดต ' . $email;
            } else {
                if ($plainPassword === '') {
                    $plainPassword = bin2hex(random_bytes(8)) . 'A!9';
                }
                $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
                $insertStmt = $tenantPdo->prepare("
                    INSERT INTO users (fullname, student_code, email, password, role, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $insertStmt->execute([$fullname, $studentCode, $email, $hash, $role]);

                $stats['inserted']++;
                $stats['messages'][] = 'L' . $lineNo . ': เพิ่ม ' . $email;
            }
        } catch (Throwable $rowError) {
            $stats['failed']++;
            $stats['messages'][] = 'L' . $lineNo . ': ' . $rowError->getMessage();
        }
    }
    fclose($fh);
    return $stats;
};

if ($core && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'provision_faculty') {
            $code = tenantNormalizeCode((string)($_POST['faculty_code'] ?? ''));
            $name = trim((string)($_POST['faculty_name'] ?? ''));
            $tenantDbName = trim((string)($_POST['tenant_db_name'] ?? ''));
            if ($code === '' || $tenantDbName === '') {
                throw new RuntimeException('ต้องระบุรหัสคณะและชื่อฐานข้อมูลเทนเนนต์');
            }
            if ($name === '') {
                $name = strtoupper($code);
            }

            $facultyId = tenantUpsertFacultyRecord($core, $code, $name, $tenantDbName);
            $serverPdo = tenantCreatePdo(null);
            $serverPdo->exec('CREATE DATABASE IF NOT EXISTS ' . tenantQuoteIdentifier($tenantDbName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $tenantPdo = tenantCreatePdo($tenantDbName);
            $appliedCount = applySqlFileToConnection($tenantPdo, $tenantSqlPath);

            writeAuditLog($conn, $adminId, 'tenant.provision', 'Provisioned faculty ' . $code . ' with DB ' . $tenantDbName, 'system', $facultyId);
            $status = 'สร้างเทนเนนต์คณะสำเร็จ จำนวนคำสั่ง SQL: ' . $appliedCount;
        }

        if ($action === 'set_current_tenant') {
            $code = tenantNormalizeCode((string)($_POST['tenant_code'] ?? ''));
            if ($code === '') {
                throw new RuntimeException('ต้องระบุรหัสเทนเนนต์');
            }

            $faculty = tenantFindFacultyByCode($core, $code);
            if (!$faculty || (int)($faculty['is_active'] ?? 0) !== 1) {
                throw new RuntimeException('ไม่พบเทนเนนต์ หรือเทนเนนต์ถูกปิดใช้งาน');
            }

            $_SESSION['tenant_code'] = (string)$faculty['code'];
            $_SESSION['tenant_name'] = (string)($faculty['name_th'] ?: $faculty['name_en'] ?: $faculty['code']);
            $status = 'สลับเซสชันไปยังเทนเนนต์ ' . $_SESSION['tenant_code'];
        }

        if ($action === 'import_users_csv') {
            $targetCode = tenantNormalizeCode((string)($_POST['target_faculty_code'] ?? ''));
            $useUpsert = isset($_POST['upsert']) && (string)$_POST['upsert'] === '1';
            if ($targetCode === '') {
                throw new RuntimeException('ต้องระบุรหัสคณะที่ต้องการนำเข้า');
            }

            $faculty = tenantFindFacultyByCode($core, $targetCode);
            if (!$faculty || (int)($faculty['is_active'] ?? 0) !== 1) {
                throw new RuntimeException('ไม่พบคณะเป้าหมาย หรือคณะถูกปิดใช้งาน');
            }
            $tenantDbName = trim((string)($faculty['tenant_db_name'] ?? ''));
            if ($tenantDbName === '') {
                throw new RuntimeException('คณะเป้าหมายยังไม่ได้ผูกฐานข้อมูลเทนเนนต์');
            }

            $sourceName = basename((string)($_FILES['csv_file']['name'] ?? 'unknown.csv'));
            $jobStart = $core->prepare("
                INSERT INTO user_import_jobs (faculty_id, source_file, status, started_by, started_at)
                VALUES (?, ?, 'running', ?, NOW())
            ");
            $jobStart->execute([(int)$faculty['id'], $sourceName, $adminId]);
            $jobId = (int)$core->lastInsertId();

            $tenantPdo = tenantCreatePdo($tenantDbName);
            $stats = $importUsersFromCsv($tenantPdo, $_FILES['csv_file'] ?? [], $useUpsert);
            $finalStatus = $stats['failed'] > 0 ? 'failed' : 'completed';

            $jobFinish = $core->prepare("
                UPDATE user_import_jobs
                SET total_rows = ?, inserted_rows = ?, updated_rows = ?, failed_rows = ?, status = ?, finished_at = NOW()
                WHERE id = ?
            ");
            $jobFinish->execute([
                $stats['total'],
                $stats['inserted'],
                $stats['updated'],
                $stats['failed'],
                $finalStatus,
                $jobId
            ]);

            $rowLog = $core->prepare("
                INSERT INTO user_import_job_rows (job_id, row_number, email, action_result, message, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $rowNo = 1;
            foreach ($stats['messages'] as $message) {
                $actionResult = 'skipped';
                if (str_contains($message, ': เพิ่ม ')) {
                    $actionResult = 'inserted';
                } elseif (str_contains($message, ': อัปเดต ')) {
                    $actionResult = 'updated';
                } elseif (str_contains($message, ': ')) {
                    $actionResult = str_contains($message, 'inserted') || str_contains($message, 'updated') ? 'skipped' : 'failed';
                }
                $rowLog->execute([$jobId, $rowNo, null, $actionResult, substr($message, 0, 250)]);
                $rowNo++;
            }

            writeAuditLog($conn, $adminId, 'tenant.user_import', 'Imported users to ' . $targetCode . ' (job=' . $jobId . ')', 'system', (int)$faculty['id']);
            $status = 'นำเข้าเสร็จสิ้น: ทั้งหมด=' . $stats['total'] . ', เพิ่มใหม่=' . $stats['inserted'] . ', อัปเดต=' . $stats['updated'] . ', ล้มเหลว=' . $stats['failed'];
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$faculties = [];
$recentJobs = [];
if ($core) {
    $faculties = tenantFetchActiveFaculties($core);
    $jobsStmt = $core->query("
        SELECT j.*, f.code AS faculty_code, f.name_th AS faculty_name
        FROM user_import_jobs j
        INNER JOIN faculties f ON f.id = j.faculty_id
        ORDER BY j.id DESC
        LIMIT 15
    ");
    $recentJobs = $jobsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$tenantContext = tenantRuntimeContext();
$activeTenantCode = trim((string)($tenantContext['tenant_code'] ?? ''));
$importStatusLabel = static function (string $status): string {
    $map = [
        'running' => 'กำลังทำงาน',
        'completed' => 'เสร็จสิ้น',
        'failed' => 'ล้มเหลว',
    ];
    return $map[$status] ?? $status;
};
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ผู้ดูแลเทนเนนต์</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="assets/js/rmutp-ui.js"></script>
</head>
<body class="bg-slate-100 text-slate-900">
    <div class="max-w-7xl mx-auto px-4 py-6">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <div>
                <h1 class="text-2xl font-bold">ผู้ดูแลเทนเนนต์</h1>
                <p class="text-sm text-slate-600">ผู้ดูแล: <?= htmlspecialchars($adminName) ?> | เทนเนนต์ปัจจุบัน: <?= $activeTenantCode !== '' ? htmlspecialchars($activeTenantCode) : 'ค่าเริ่มต้น' ?></p>
            </div>
            <div>
                <a href="admin_dashboard.php" class="px-3 py-2 rounded bg-slate-800 text-white text-sm">กลับหน้าแดชบอร์ดผู้ดูแล</a>
            </div>
        </div>

        <?php if ($status !== ''): ?>
            <div class="mb-4 rounded border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><?= htmlspecialchars($status) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="mb-4 rounded border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-800"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!$core): ?>
            <div class="bg-white rounded-xl shadow p-6 text-sm text-slate-600">
                ยังไม่พร้อมใช้งานฐานข้อมูลกลาง กรุณาตั้งค่า `CORE_DB_NAME` และข้อมูลเชื่อมต่อฐานข้อมูลก่อน
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="lg:col-span-2 space-y-4">
                    <div class="bg-white rounded-xl shadow p-4">
                        <h2 class="font-bold mb-3">สร้างเทนเนนต์คณะ</h2>
                        <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-2">
                            <?= csrfInputField() ?>
                            <input type="hidden" name="action" value="provision_faculty">
                            <input type="text" name="faculty_code" placeholder="รหัสคณะ (เช่น fst)" class="border rounded px-3 py-2 text-sm" required>
                            <input type="text" name="faculty_name" placeholder="ชื่อคณะ" class="border rounded px-3 py-2 text-sm" required>
                            <input type="text" name="tenant_db_name" placeholder="ชื่อฐานข้อมูลเทนเนนต์ (เช่น rmutp_fst)" class="border rounded px-3 py-2 text-sm" required>
                            <button type="submit" class="rounded bg-emerald-700 text-white text-sm px-3 py-2">สร้าง</button>
                        </form>
                    </div>

                    <div class="bg-white rounded-xl shadow p-4">
                        <h2 class="font-bold mb-3">นำเข้าผู้ใช้จาก CSV</h2>
                        <form method="POST" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-4 gap-2">
                            <?= csrfInputField() ?>
                            <input type="hidden" name="action" value="import_users_csv">
                            <select name="target_faculty_code" class="border rounded px-3 py-2 text-sm" required>
                                <option value="">เลือกคณะ</option>
                                <?php foreach ($faculties as $faculty): ?>
                                    <option value="<?= htmlspecialchars((string)$faculty['code']) ?>"><?= htmlspecialchars((string)$faculty['code']) ?> - <?= htmlspecialchars((string)$faculty['name_th']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="file" name="csv_file" accept=".csv,text/csv" class="border rounded px-3 py-2 text-sm md:col-span-2" required>
                            <label class="text-sm flex items-center gap-2">
                                <input type="checkbox" name="upsert" value="1" class="h-4 w-4"> อัปเดตผู้ใช้ที่มีอยู่ (Upsert)
                            </label>
                            <button type="submit" class="rounded bg-blue-700 text-white text-sm px-3 py-2 md:col-span-4">เริ่มนำเข้า</button>
                        </form>
                        <p class="text-xs text-slate-500 mt-2">คอลัมน์ CSV ที่รองรับ: fullname,email,role,student_code,password</p>
                    </div>

                    <div class="bg-white rounded-xl shadow p-4">
                        <h2 class="font-bold mb-3">งานนำเข้าล่าสุด</h2>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm border">
                                <thead class="bg-slate-100">
                                    <tr>
                                        <th class="text-left px-2 py-2 border">ID</th>
                                        <th class="text-left px-2 py-2 border">คณะ</th>
                                        <th class="text-left px-2 py-2 border">ไฟล์</th>
                                        <th class="text-left px-2 py-2 border">เพิ่มใหม่</th>
                                        <th class="text-left px-2 py-2 border">อัปเดต</th>
                                        <th class="text-left px-2 py-2 border">ล้มเหลว</th>
                                        <th class="text-left px-2 py-2 border">สถานะ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentJobs as $job): ?>
                                        <tr>
                                            <td class="px-2 py-1 border"><?= (int)$job['id'] ?></td>
                                            <td class="px-2 py-1 border"><?= htmlspecialchars((string)$job['faculty_code']) ?></td>
                                            <td class="px-2 py-1 border"><?= htmlspecialchars((string)$job['source_file']) ?></td>
                                            <td class="px-2 py-1 border"><?= (int)$job['inserted_rows'] ?></td>
                                            <td class="px-2 py-1 border"><?= (int)$job['updated_rows'] ?></td>
                                            <td class="px-2 py-1 border"><?= (int)$job['failed_rows'] ?></td>
                                            <td class="px-2 py-1 border"><?= htmlspecialchars($importStatusLabel((string)$job['status'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($recentJobs)): ?>
                                        <tr><td colspan="7" class="px-2 py-2 border text-slate-500">ยังไม่มีงานนำเข้า</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="bg-white rounded-xl shadow p-4">
                        <h2 class="font-bold mb-3">คณะที่เปิดใช้งาน</h2>
                        <div class="space-y-2">
                            <?php foreach ($faculties as $faculty): ?>
                                <div class="border rounded p-2">
                                    <div class="text-sm font-medium"><?= htmlspecialchars((string)$faculty['name_th']) ?></div>
                                    <div class="text-xs text-slate-600"><?= htmlspecialchars((string)$faculty['code']) ?> | ฐานข้อมูล: <?= htmlspecialchars((string)$faculty['tenant_db_name']) ?></div>
                                    <form method="POST" class="mt-2">
                                        <?= csrfInputField() ?>
                                        <input type="hidden" name="action" value="set_current_tenant">
                                        <input type="hidden" name="tenant_code" value="<?= htmlspecialchars((string)$faculty['code']) ?>">
                                        <button type="submit" class="px-2 py-1 rounded bg-slate-800 text-white text-xs">ใช้เทนเนนต์นี้</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($faculties)): ?>
                                <div class="text-sm text-slate-500">ยังไม่พบเทนเนนต์คณะ</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
