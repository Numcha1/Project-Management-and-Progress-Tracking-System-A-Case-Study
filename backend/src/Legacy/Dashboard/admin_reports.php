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

$selectedYear = (int)($_GET['year'] ?? date('Y'));
$q = trim((string)($_GET['q'] ?? ''));
$export = trim((string)($_GET['export'] ?? ''));
$perPage = 25;
$projectPage = max(1, (int)($_GET['project_page'] ?? 1));
$pendingPage = max(1, (int)($_GET['pending_page'] ?? 1));

if ($selectedYear < 2000 || $selectedYear > 2600) {
    $selectedYear = (int)date('Y');
}

function csvDownload(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $fp = fopen('php://output', 'w');
    fputcsv($fp, $headers);
    foreach ($rows as $row) {
        fputcsv($fp, $row);
    }
    fclose($fp);
    exit;
}

function projectProgressRows(PDO $conn, int $year): array
{
    $stmt = $conn->prepare("
        SELECT
            p.id,
            p.name,
            p.status,
            p.progress,
            p.created_at,
            s.fullname AS student_name,
            a.fullname AS advisor_name,
            c.fullname AS co_advisor_name,
            COUNT(t.id) AS total_tasks,
            SUM(CASE WHEN t.teacher_status = 'approved' THEN 1 ELSE 0 END) AS approved_tasks
        FROM projects p
        LEFT JOIN users s ON s.id = p.student_id
        LEFT JOIN users a ON a.id = p.advisor_id
        LEFT JOIN users c ON c.id = p.co_advisor_id
        LEFT JOIN tasks t ON t.project_id = p.id
        WHERE YEAR(p.created_at) = ?
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$year]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pendingTaskRows(PDO $conn, int $year): array
{
    $stmt = $conn->prepare("
        SELECT
            t.id,
            t.name,
            t.assignee_name,
            t.status,
            t.teacher_status,
            t.due_date,
            p.id AS project_id,
            p.name AS project_name,
            u.fullname AS student_name
        FROM tasks t
        INNER JOIN projects p ON p.id = t.project_id
        LEFT JOIN users u ON u.id = p.student_id
        WHERE YEAR(t.due_date) = ?
          AND (t.teacher_status IN ('pending', 'rejected') OR t.status = 'todo')
        ORDER BY t.due_date ASC
    ");
    $stmt->execute([$year]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function projectStatusText(string $status): string
{
    if ($status === 'pending') {
        return 'รออนุมัติ';
    }
    if ($status === 'in_progress') {
        return 'กำลังดำเนินการ';
    }
    if ($status === 'completed') {
        return 'เสร็จสิ้น';
    }
    if ($status === 'cancelled') {
        return 'ยกเลิก';
    }
    return $status;
}

function taskStatusText(string $status): string
{
    if ($status === 'todo') {
        return 'ยังไม่เริ่ม';
    }
    if ($status === 'doing') {
        return 'กำลังดำเนินการ';
    }
    if ($status === 'done') {
        return 'เสร็จสิ้น';
    }
    return $status;
}

function teacherStatusText(string $status): string
{
    if ($status === 'pending') {
        return 'รอตรวจ';
    }
    if ($status === 'approved') {
        return 'อนุมัติ';
    }
    if ($status === 'rejected') {
        return 'ตีกลับ';
    }
    return $status;
}

$projectRows = projectProgressRows($conn, $selectedYear);
$pendingRows = pendingTaskRows($conn, $selectedYear);
if ($q !== '') {
    $projectRows = array_values(array_filter($projectRows, static function (array $row) use ($q): bool {
        return
            stripos((string)($row['name'] ?? ''), $q) !== false
            || stripos((string)($row['student_name'] ?? ''), $q) !== false
            || stripos((string)($row['advisor_name'] ?? ''), $q) !== false
            || stripos((string)($row['co_advisor_name'] ?? ''), $q) !== false;
    }));

    $pendingRows = array_values(array_filter($pendingRows, static function (array $row) use ($q): bool {
        return
            stripos((string)($row['name'] ?? ''), $q) !== false
            || stripos((string)($row['project_name'] ?? ''), $q) !== false
            || stripos((string)($row['student_name'] ?? ''), $q) !== false
            || stripos((string)($row['assignee_name'] ?? ''), $q) !== false;
    }));
}

if ($export === 'projects_csv') {
    $csvRows = [];
    foreach ($projectRows as $r) {
        $csvRows[] = [
            (int)$r['id'],
            (string)$r['name'],
            (string)$r['student_name'],
            (string)$r['advisor_name'],
            (string)$r['co_advisor_name'],
            (string)$r['status'],
            (int)$r['progress'],
            (int)$r['total_tasks'],
            (int)$r['approved_tasks'],
            (string)$r['created_at'],
        ];
    }
    writeAuditLog($conn, $current_user_id, 'admin.report.export.projects', 'ส่งออก CSV ความคืบหน้าโครงงานปี ' . $selectedYear, 'report', null);
    csvDownload(
        'project_progress_' . $selectedYear . '.csv',
        ['รหัสโครงงาน', 'ชื่อโครงงาน', 'นักศึกษา', 'อาจารย์ที่ปรึกษา', 'อาจารย์ที่ปรึกษาร่วม', 'สถานะ', 'ความคืบหน้า(%)', 'งานทั้งหมด', 'งานที่อนุมัติแล้ว', 'วันที่สร้าง'],
        $csvRows
    );
}

if ($export === 'pending_tasks_csv') {
    $csvRows = [];
    foreach ($pendingRows as $r) {
        $csvRows[] = [
            (int)$r['id'],
            (string)$r['name'],
            (string)$r['project_id'],
            (string)$r['project_name'],
            (string)$r['student_name'],
            (string)$r['assignee_name'],
            (string)$r['status'],
            (string)$r['teacher_status'],
            (string)$r['due_date'],
        ];
    }
    writeAuditLog($conn, $current_user_id, 'admin.report.export.pending_tasks', 'ส่งออก CSV งานค้างตรวจปี ' . $selectedYear, 'report', null);
    csvDownload(
        'pending_tasks_' . $selectedYear . '.csv',
        ['รหัสงาน', 'ชื่องาน', 'รหัสโครงงาน', 'ชื่อโครงงาน', 'นักศึกษา', 'ผู้รับผิดชอบ', 'สถานะงาน', 'สถานะการตรวจ', 'กำหนดส่ง'],
        $csvRows
    );
}

$projectTotal = count($projectRows);
$projectTotalPages = max(1, (int)ceil($projectTotal / $perPage));
if ($projectPage > $projectTotalPages) {
    $projectPage = $projectTotalPages;
}
$projectRowsPaged = array_slice($projectRows, ($projectPage - 1) * $perPage, $perPage);

$pendingTotal = count($pendingRows);
$pendingTotalPages = max(1, (int)ceil($pendingTotal / $perPage));
if ($pendingPage > $pendingTotalPages) {
    $pendingPage = $pendingTotalPages;
}
$pendingRowsPaged = array_slice($pendingRows, ($pendingPage - 1) * $perPage, $perPage);

$summary = [
    'projects_total' => count($projectRows),
    'projects_completed' => 0,
    'projects_in_progress' => 0,
    'tasks_pending' => count($pendingRows),
];
foreach ($projectRows as $row) {
    if ((int)$row['progress'] >= 100 || (string)$row['status'] === 'completed') {
        $summary['projects_completed']++;
    } else {
        $summary['projects_in_progress']++;
    }
}

writeAuditLog($conn, $current_user_id, 'admin.report.view', 'เปิดหน้ารายงานปี ' . $selectedYear, 'report', null);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานผู้ดูแลระบบ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="assets/js/rmutp-ui.js"></script>
    <style>
        body { font-family: "Sarabun", sans-serif; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 min-h-screen">
    <nav class="bg-emerald-700 text-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <i class="fas fa-file-export"></i>
                <span class="font-bold">รายงานผู้ดูแลระบบ</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-sm hidden md:inline"><?= htmlspecialchars($current_user_name) ?></span>
                <a href="admin_dashboard.php" class="bg-emerald-800 hover:bg-emerald-900 px-3 py-1.5 rounded text-sm">
                    <i class="fas fa-arrow-left mr-1"></i> กลับหน้าผู้ดูแล
                </a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto p-4 md:p-8">
        <div class="bg-white rounded-xl shadow p-4 mb-6">
            <form method="GET" class="flex flex-wrap gap-2 items-center">
                <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="ค้นหาโครงงาน/งาน/ผู้เกี่ยวข้อง..." class="border rounded px-3 py-2 min-w-[260px]">
                <label for="year" class="text-sm font-bold text-gray-700">ปี (ค.ศ.)</label>
                <input id="year" name="year" type="number" min="2000" max="2600" value="<?= (int)$selectedYear ?>" class="border rounded px-3 py-2 w-36">
                <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded">โหลดรายงาน</button>
                <?php if ($q !== ''): ?>
                    <a href="admin_reports.php?<?= htmlspecialchars(http_build_query(['year' => $selectedYear])) ?>" class="bg-gray-200 hover:bg-gray-300 px-4 py-2 rounded">ล้างค้นหา</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-blue-500">
                <div class="text-sm text-gray-500">โครงงานทั้งหมด</div>
                <div class="text-2xl font-bold"><?= (int)$summary['projects_total'] ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-green-500">
                <div class="text-sm text-gray-500">โครงงานเสร็จสิ้น</div>
                <div class="text-2xl font-bold"><?= (int)$summary['projects_completed'] ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-amber-500">
                <div class="text-sm text-gray-500">โครงงานกำลังดำเนินการ</div>
                <div class="text-2xl font-bold"><?= (int)$summary['projects_in_progress'] ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-rose-500">
                <div class="text-sm text-gray-500">งานค้างตรวจ</div>
                <div class="text-2xl font-bold"><?= (int)$summary['tasks_pending'] ?></div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-4 mb-6">
            <h2 class="font-bold mb-3">ส่งออกข้อมูล</h2>
            <div class="flex flex-wrap gap-2">
                <a href="admin_reports.php?<?= htmlspecialchars(http_build_query(['year' => $selectedYear, 'q' => $q, 'export' => 'projects_csv'])) ?>"
                    class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                    <i class="fas fa-file-csv mr-2"></i> ส่งออก CSV ความคืบหน้าโครงงาน
                </a>
                <a href="admin_reports.php?<?= htmlspecialchars(http_build_query(['year' => $selectedYear, 'q' => $q, 'export' => 'pending_tasks_csv'])) ?>"
                    class="inline-flex items-center bg-rose-600 hover:bg-rose-700 text-white px-4 py-2 rounded">
                    <i class="fas fa-file-csv mr-2"></i> ส่งออก CSV งานค้างตรวจ
                </a>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow overflow-hidden mb-6">
            <div class="px-4 py-3 border-b font-bold">ความคืบหน้าโครงงาน (<?= (int)$projectTotal ?>)</div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 border-b text-left">โครงงาน</th>
                            <th class="px-4 py-3 border-b text-left">นักศึกษา</th>
                            <th class="px-4 py-3 border-b text-left">อาจารย์ที่ปรึกษา</th>
                            <th class="px-4 py-3 border-b text-center">สถานะ</th>
                            <th class="px-4 py-3 border-b text-center">ความคืบหน้า</th>
                            <th class="px-4 py-3 border-b text-center">งาน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($projectRowsPaged)): ?>
                            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">ไม่พบข้อมูลโครงงานในปีที่เลือก</td></tr>
                        <?php else: ?>
                            <?php foreach ($projectRowsPaged as $row): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 border-b">
                                        <div class="font-medium"><?= htmlspecialchars((string)$row['name']) ?></div>
                                        <div class="text-xs text-gray-500">#<?= (int)$row['id'] ?></div>
                                    </td>
                                    <td class="px-4 py-3 border-b"><?= htmlspecialchars((string)$row['student_name']) ?></td>
                                    <td class="px-4 py-3 border-b">
                                        <div><?= htmlspecialchars((string)$row['advisor_name']) ?></div>
                                        <div class="text-xs text-gray-500"><?= htmlspecialchars((string)$row['co_advisor_name']) ?></div>
                                    </td>
                                    <td class="px-4 py-3 border-b text-center"><?= htmlspecialchars(projectStatusText((string)$row['status'])) ?></td>
                                    <td class="px-4 py-3 border-b text-center font-bold"><?= (int)$row['progress'] ?>%</td>
                                    <td class="px-4 py-3 border-b text-center"><?= (int)$row['approved_tasks'] ?>/<?= (int)$row['total_tasks'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($projectTotal > 0): ?>
                <?php
                    $projectStart = (($projectPage - 1) * $perPage) + 1;
                    $projectEnd = min($projectTotal, $projectPage * $perPage);
                    $projectQuery = ['year' => $selectedYear, 'q' => $q, 'pending_page' => $pendingPage];
                ?>
                <div class="px-4 py-3 border-t bg-white flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div class="text-sm text-gray-600">แสดง <?= (int)$projectStart ?>-<?= (int)$projectEnd ?> จาก <?= (int)$projectTotal ?> รายการ</div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <?php if ($projectPage > 1): ?>
                            <a href="admin_reports.php?<?= htmlspecialchars(http_build_query(array_merge($projectQuery, ['project_page' => 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">หน้าแรก</a>
                            <a href="admin_reports.php?<?= htmlspecialchars(http_build_query(array_merge($projectQuery, ['project_page' => $projectPage - 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">ก่อนหน้า</a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">หน้าแรก</span>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">ก่อนหน้า</span>
                        <?php endif; ?>
                        <span class="px-3 py-1.5 rounded bg-emerald-600 text-white text-sm font-bold">หน้า <?= (int)$projectPage ?> / <?= (int)$projectTotalPages ?></span>
                        <?php if ($projectPage < $projectTotalPages): ?>
                            <a href="admin_reports.php?<?= htmlspecialchars(http_build_query(array_merge($projectQuery, ['project_page' => $projectPage + 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">ถัดไป</a>
                            <a href="admin_reports.php?<?= htmlspecialchars(http_build_query(array_merge($projectQuery, ['project_page' => $projectTotalPages]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">หน้าสุดท้าย</a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">ถัดไป</span>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">หน้าสุดท้าย</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-bold">งานค้างตรวจ (<?= (int)$pendingTotal ?>)</div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 border-b text-left">งาน</th>
                            <th class="px-4 py-3 border-b text-left">โครงงาน</th>
                            <th class="px-4 py-3 border-b text-left">นักศึกษา</th>
                            <th class="px-4 py-3 border-b text-center">สถานะ</th>
                            <th class="px-4 py-3 border-b text-center">กำหนดส่ง</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pendingRowsPaged)): ?>
                            <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">ไม่พบงานค้างตรวจ</td></tr>
                        <?php else: ?>
                            <?php foreach ($pendingRowsPaged as $row): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 border-b">
                                        <div class="font-medium"><?= htmlspecialchars((string)$row['name']) ?></div>
                                        <div class="text-xs text-gray-500">#<?= (int)$row['id'] ?> / <?= htmlspecialchars((string)$row['assignee_name']) ?></div>
                                    </td>
                                    <td class="px-4 py-3 border-b"><?= htmlspecialchars((string)$row['project_name']) ?></td>
                                    <td class="px-4 py-3 border-b"><?= htmlspecialchars((string)$row['student_name']) ?></td>
                                    <td class="px-4 py-3 border-b text-center">
                                        <?= htmlspecialchars(taskStatusText((string)$row['status'])) ?> / <?= htmlspecialchars(teacherStatusText((string)$row['teacher_status'])) ?>
                                    </td>
                                    <td class="px-4 py-3 border-b text-center whitespace-nowrap"><?= htmlspecialchars((string)$row['due_date']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pendingTotal > 0): ?>
                <?php
                    $pendingStart = (($pendingPage - 1) * $perPage) + 1;
                    $pendingEnd = min($pendingTotal, $pendingPage * $perPage);
                    $pendingQuery = ['year' => $selectedYear, 'q' => $q, 'project_page' => $projectPage];
                ?>
                <div class="px-4 py-3 border-t bg-white flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div class="text-sm text-gray-600">แสดง <?= (int)$pendingStart ?>-<?= (int)$pendingEnd ?> จาก <?= (int)$pendingTotal ?> รายการ</div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <?php if ($pendingPage > 1): ?>
                            <a href="admin_reports.php?<?= htmlspecialchars(http_build_query(array_merge($pendingQuery, ['pending_page' => 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">หน้าแรก</a>
                            <a href="admin_reports.php?<?= htmlspecialchars(http_build_query(array_merge($pendingQuery, ['pending_page' => $pendingPage - 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">ก่อนหน้า</a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">หน้าแรก</span>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">ก่อนหน้า</span>
                        <?php endif; ?>
                        <span class="px-3 py-1.5 rounded bg-rose-600 text-white text-sm font-bold">หน้า <?= (int)$pendingPage ?> / <?= (int)$pendingTotalPages ?></span>
                        <?php if ($pendingPage < $pendingTotalPages): ?>
                            <a href="admin_reports.php?<?= htmlspecialchars(http_build_query(array_merge($pendingQuery, ['pending_page' => $pendingPage + 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">ถัดไป</a>
                            <a href="admin_reports.php?<?= htmlspecialchars(http_build_query(array_merge($pendingQuery, ['pending_page' => $pendingTotalPages]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">หน้าสุดท้าย</a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">ถัดไป</span>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">หน้าสุดท้าย</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
