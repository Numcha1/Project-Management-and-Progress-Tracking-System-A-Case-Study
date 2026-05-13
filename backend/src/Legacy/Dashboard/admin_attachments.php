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

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$current_user_name = (string)($_SESSION['fullname'] ?? 'ผู้ดูแลระบบ');

$permissions = defaultAdminPermissions();
try {
    $permissions = getAdminPermissions($conn, $current_user_id);
} catch (Throwable $e) {
    $permissions = defaultAdminPermissions();
}

if (!hasAdminPermission($permissions, 'can_manage_projects')) {
    header('Location: admin_dashboard.php?status=permission_denied');
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$type = trim((string)($_GET['type'] ?? 'all'));
$year = trim((string)($_GET['year'] ?? 'all'));
$export = trim((string)($_GET['export'] ?? ''));
$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));

$script_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$upload_web_base = preg_match('#/frontend/public$#', $script_dir)
    ? $script_dir . '/uploads'
    : $script_dir . '/frontend/public/uploads';
$upload_local_base = realpath(__DIR__ . '/../../../../frontend/public/uploads');
if ($upload_local_base === false) {
    $upload_local_base = __DIR__ . '/../../../../frontend/public/uploads';
}

function buildAttachmentRows(PDO $conn, string $q, string $type, string $year, ?int $limit = null, int $offset = 0): array
{
    $sql = "
        SELECT *
        FROM (
            SELECT
                'submission' AS source_type,
                t.id AS source_id,
                t.project_id AS project_id,
                p.name AS project_name,
                t.id AS task_id,
                t.name AS task_name,
                COALESCE(u.fullname, t.assignee_name, '-') AS owner_name,
                t.file_path AS file_name,
                t.updated_at AS uploaded_at
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN users u ON u.id = p.student_id
            WHERE t.file_path IS NOT NULL AND t.file_path <> ''

            UNION ALL

            SELECT
                'return' AS source_type,
                h.id AS source_id,
                h.project_id AS project_id,
                p.name AS project_name,
                h.task_id AS task_id,
                COALESCE(t.name, '-') AS task_name,
                COALESCE(u.fullname, '-') AS owner_name,
                h.attachment_path AS file_name,
                h.created_at AS uploaded_at
            FROM task_return_history h
            INNER JOIN projects p ON p.id = h.project_id
            LEFT JOIN tasks t ON t.id = h.task_id
            LEFT JOIN users u ON u.id = h.reviewer_id
            WHERE h.attachment_path IS NOT NULL AND h.attachment_path <> ''
        ) files
        WHERE 1=1
    ";

    $params = [];
    if ($q !== '') {
        $sql .= " AND (
            files.project_name LIKE ?
            OR files.task_name LIKE ?
            OR files.owner_name LIKE ?
            OR files.file_name LIKE ?
        )";
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if (in_array($type, ['submission', 'return'], true)) {
        $sql .= " AND files.source_type = ?";
        $params[] = $type;
    }
    if ($year !== 'all' && preg_match('/^\d{4}$/', $year) === 1) {
        $sql .= " AND YEAR(files.uploaded_at) = ?";
        $params[] = (int)$year;
    }

    $sql .= " ORDER BY files.uploaded_at DESC";
    if ($limit !== null) {
        $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function countAttachmentRows(PDO $conn, string $q, string $type, string $year): int
{
    $sql = "
        SELECT COUNT(*)
        FROM (
            SELECT
                'submission' AS source_type,
                p.name AS project_name,
                t.name AS task_name,
                COALESCE(u.fullname, t.assignee_name, '-') AS owner_name,
                t.file_path AS file_name,
                t.updated_at AS uploaded_at
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN users u ON u.id = p.student_id
            WHERE t.file_path IS NOT NULL AND t.file_path <> ''

            UNION ALL

            SELECT
                'return' AS source_type,
                p.name AS project_name,
                COALESCE(t.name, '-') AS task_name,
                COALESCE(u.fullname, '-') AS owner_name,
                h.attachment_path AS file_name,
                h.created_at AS uploaded_at
            FROM task_return_history h
            INNER JOIN projects p ON p.id = h.project_id
            LEFT JOIN tasks t ON t.id = h.task_id
            LEFT JOIN users u ON u.id = h.reviewer_id
            WHERE h.attachment_path IS NOT NULL AND h.attachment_path <> ''
        ) files
        WHERE 1=1
    ";

    $params = [];
    if ($q !== '') {
        $sql .= " AND (
            files.project_name LIKE ?
            OR files.task_name LIKE ?
            OR files.owner_name LIKE ?
            OR files.file_name LIKE ?
        )";
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if (in_array($type, ['submission', 'return'], true)) {
        $sql .= " AND files.source_type = ?";
        $params[] = $type;
    }
    if ($year !== 'all' && preg_match('/^\d{4}$/', $year) === 1) {
        $sql .= " AND YEAR(files.uploaded_at) = ?";
        $params[] = (int)$year;
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

$rows = [];
$totalRows = 0;
$totalPages = 1;
$yearSeedRows = [];
$error = '';
try {
    $totalRows = countAttachmentRows($conn, $q, $type, $year);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $rows = buildAttachmentRows($conn, $q, $type, $year, $perPage, $offset);
    $yearSeedRows = buildAttachmentRows($conn, $q, $type, 'all', 600, 0);
} catch (Throwable $e) {
    $error = 'ไม่สามารถโหลดข้อมูลไฟล์แนบได้ในขณะนี้ กรุณาตรวจสอบโครงสร้างฐานข้อมูล';
}

$years = [];
foreach ($yearSeedRows as $row) {
    $ts = strtotime((string)($row['uploaded_at'] ?? ''));
    if ($ts !== false) {
        $years[(string)date('Y', $ts)] = true;
    }
}
krsort($years);
$yearOptions = array_keys($years);

if ($export === 'csv' && $error === '') {
    $exportRows = buildAttachmentRows($conn, $q, $type, $year, null, 0);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="attachments_export.csv"');
    echo "\xEF\xBB\xBF";
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['ประเภทไฟล์', 'รหัสโครงงาน', 'ชื่อโครงงาน', 'รหัสงาน', 'ชื่องาน', 'ผู้เกี่ยวข้อง', 'ชื่อไฟล์', 'วันที่อัปโหลด']);
    foreach ($exportRows as $row) {
        fputcsv($fp, [
            ((string)$row['source_type'] === 'return') ? 'ไฟล์ส่งกลับอาจารย์' : 'งานนักศึกษา',
            (string)$row['project_id'],
            (string)$row['project_name'],
            (string)$row['task_id'],
            (string)$row['task_name'],
            (string)$row['owner_name'],
            (string)$row['file_name'],
            (string)$row['uploaded_at'],
        ]);
    }
    fclose($fp);
    exit;
}

writeAuditLog($conn, $current_user_id, 'admin.attachments.view', 'เปิดศูนย์จัดการไฟล์แนบ', 'file', null);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ศูนย์จัดการไฟล์แนบ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="assets/js/rmutp-ui.js"></script>
    <style>
        body { font-family: "Sarabun", sans-serif; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 min-h-screen">
    <nav class="bg-blue-700 text-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <i class="fas fa-paperclip"></i>
                <span class="font-bold">ศูนย์จัดการไฟล์แนบ</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-sm hidden md:inline"><?= htmlspecialchars($current_user_name) ?></span>
                <a href="admin_dashboard.php" class="bg-blue-800 hover:bg-blue-900 px-3 py-1.5 rounded text-sm">
                    <i class="fas fa-arrow-left mr-1"></i> กลับหน้าผู้ดูแล
                </a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto p-4 md:p-8">
        <div class="bg-white rounded-xl shadow p-4 mb-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-3">
                <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="ค้นหาโครงงาน/งาน/ไฟล์/ผู้ส่ง" class="md:col-span-2 border rounded px-3 py-2">
                <select name="type" class="border rounded px-3 py-2">
                    <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>ทุกประเภท</option>
                    <option value="submission" <?= $type === 'submission' ? 'selected' : '' ?>>งานนักศึกษา</option>
                    <option value="return" <?= $type === 'return' ? 'selected' : '' ?>>ไฟล์ส่งกลับอาจารย์</option>
                </select>
                <select name="year" class="border rounded px-3 py-2">
                    <option value="all">ทุกปี</option>
                    <?php foreach ($yearOptions as $yy): ?>
                    <option value="<?= htmlspecialchars($yy) ?>" <?= $year === $yy ? 'selected' : '' ?>><?= htmlspecialchars($yy) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="flex gap-2">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                        <i class="fas fa-search mr-1"></i> ค้นหา
                    </button>
                    <a href="admin_attachments.php" class="bg-gray-200 hover:bg-gray-300 px-4 py-2 rounded">ล้าง</a>
                </div>
            </form>
            <div class="mt-3">
                <a href="admin_attachments.php?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"
                    class="inline-flex items-center text-sm bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded">
                    <i class="fas fa-file-csv mr-1"></i> ส่งออก CSV
                </a>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-bold">
                รายการไฟล์แนบทั้งหมด (<?= (int)$totalRows ?>)
            </div>
            <?php if ($error !== ''): ?>
                <div class="p-4 text-red-700 bg-red-50"><?= htmlspecialchars($error) ?></div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-[1080px] w-full text-sm table-fixed">
                        <colgroup>
                            <col class="w-44">
                            <col class="w-40">
                            <col class="w-64">
                            <col class="w-64">
                            <col class="w-56">
                            <col>
                        </colgroup>
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 border-b border-gray-200 text-left text-gray-700 whitespace-nowrap">เวลา</th>
                                <th class="px-4 py-3 border-b border-gray-200 text-center text-gray-700 whitespace-nowrap">ประเภท</th>
                                <th class="px-4 py-3 border-b border-gray-200 text-left text-gray-700">โครงงาน</th>
                                <th class="px-4 py-3 border-b border-gray-200 text-left text-gray-700">งาน</th>
                                <th class="px-4 py-3 border-b border-gray-200 text-left text-gray-700">ผู้เกี่ยวข้อง</th>
                                <th class="px-4 py-3 border-b border-gray-200 text-left text-gray-700">ไฟล์</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="6" class="px-4 py-10 text-center text-gray-500">ไม่พบไฟล์แนบตามเงื่อนไข</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $fileName = basename((string)$row['file_name']);
                                $fileUrl = $upload_web_base . '/' . rawurlencode($fileName);
                                $localPath = rtrim((string)$upload_local_base, '\\/') . DIRECTORY_SEPARATOR . $fileName;
                                $fileSize = is_file($localPath) ? filesize($localPath) : 0;
                                $sizeText = $fileSize > 0 ? number_format(((float)$fileSize) / 1024, 1) . ' KB' : '-';
                                $isReturn = ((string)$row['source_type'] === 'return');
                                ?>
                                <tr class="odd:bg-white even:bg-slate-50/40 hover:bg-blue-50/60 transition">
                                    <td class="px-4 py-3 border-b border-gray-100 whitespace-nowrap align-top text-gray-700"><?= htmlspecialchars((string)$row['uploaded_at']) ?></td>
                                    <td class="px-4 py-3 border-b border-gray-100 text-center align-top whitespace-nowrap">
                                        <span class="px-2 py-1 rounded text-xs font-bold <?= $isReturn ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800' ?>">
                                            <?= $isReturn ? 'ไฟล์ส่งกลับอาจารย์' : 'งานนักศึกษา' ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 border-b border-gray-100 align-top">
                                        <div class="font-medium text-gray-900 break-words"><?= htmlspecialchars((string)$row['project_name']) ?></div>
                                        <div class="text-xs text-gray-500 mt-0.5">#<?= (int)$row['project_id'] ?></div>
                                    </td>
                                    <td class="px-4 py-3 border-b border-gray-100 align-top">
                                        <div class="break-words text-gray-800"><?= htmlspecialchars((string)$row['task_name']) ?></div>
                                        <div class="text-xs text-gray-500 mt-0.5">งาน #<?= (int)$row['task_id'] ?></div>
                                    </td>
                                    <td class="px-4 py-3 border-b border-gray-100 align-top break-words text-gray-800"><?= htmlspecialchars((string)$row['owner_name']) ?></td>
                                    <td class="px-4 py-3 border-b border-gray-100 align-top">
                                        <a href="<?= htmlspecialchars($fileUrl) ?>" target="_blank" class="text-blue-600 hover:underline break-all font-medium">
                                            <?= htmlspecialchars($fileName) ?>
                                        </a>
                                        <div class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($sizeText) ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalRows > 0): ?>
                    <?php
                        $startRow = (($page - 1) * $perPage) + 1;
                        $endRow = min($totalRows, $page * $perPage);
                        $pageQuery = $_GET;
                        unset($pageQuery['page'], $pageQuery['export']);
                    ?>
                    <div class="px-4 py-3 border-t bg-white flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                        <div class="text-sm text-gray-600">
                            แสดง <?= (int)$startRow ?>-<?= (int)$endRow ?> จาก <?= (int)$totalRows ?> รายการ
                        </div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <?php if ($page > 1): ?>
                                <a href="admin_attachments.php?<?= htmlspecialchars(http_build_query(array_merge($pageQuery, ['page' => 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">หน้าแรก</a>
                                <a href="admin_attachments.php?<?= htmlspecialchars(http_build_query(array_merge($pageQuery, ['page' => $page - 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">ก่อนหน้า</a>
                            <?php else: ?>
                                <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">หน้าแรก</span>
                                <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">ก่อนหน้า</span>
                            <?php endif; ?>

                            <span class="px-3 py-1.5 rounded bg-blue-600 text-white text-sm font-bold">หน้า <?= (int)$page ?> / <?= (int)$totalPages ?></span>

                            <?php if ($page < $totalPages): ?>
                                <a href="admin_attachments.php?<?= htmlspecialchars(http_build_query(array_merge($pageQuery, ['page' => $page + 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">ถัดไป</a>
                                <a href="admin_attachments.php?<?= htmlspecialchars(http_build_query(array_merge($pageQuery, ['page' => $totalPages]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">หน้าสุดท้าย</a>
                            <?php else: ?>
                                <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">ถัดไป</span>
                                <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">หน้าสุดท้าย</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
