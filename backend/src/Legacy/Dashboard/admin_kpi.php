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
if ($selectedYear < 2000 || $selectedYear > 2600) {
    $selectedYear = (int)date('Y');
}

$kpi = [
    'projects_total' => 0,
    'projects_completed' => 0,
    'projects_active' => 0,
    'projects_cancelled' => 0,
    'avg_project_progress' => 0.0,
    'tasks_total' => 0,
    'tasks_done' => 0,
    'tasks_approved' => 0,
    'tasks_pending_review' => 0,
    'tasks_overdue' => 0,
    'active_students' => 0,
    'active_members' => 0,
    'active_main_advisors' => 0,
    'active_co_advisors' => 0,
];
$monthlyRows = [];
$overdueProjects = [];
$pageError = '';

try {
    $projectSummaryStmt = $conn->prepare("
        SELECT
            COUNT(*) AS projects_total,
            SUM(CASE WHEN p.progress >= 100 OR p.status = 'completed' THEN 1 ELSE 0 END) AS projects_completed,
            SUM(CASE WHEN p.status IN ('pending', 'in_progress') AND p.progress < 100 THEN 1 ELSE 0 END) AS projects_active,
            SUM(CASE WHEN p.status = 'cancelled' THEN 1 ELSE 0 END) AS projects_cancelled,
            AVG(p.progress) AS avg_project_progress
        FROM projects p
        WHERE YEAR(p.created_at) = ?
    ");
    $projectSummaryStmt->execute([$selectedYear]);
    $projectSummary = $projectSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $kpi['projects_total'] = (int)($projectSummary['projects_total'] ?? 0);
    $kpi['projects_completed'] = (int)($projectSummary['projects_completed'] ?? 0);
    $kpi['projects_active'] = (int)($projectSummary['projects_active'] ?? 0);
    $kpi['projects_cancelled'] = (int)($projectSummary['projects_cancelled'] ?? 0);
    $kpi['avg_project_progress'] = round((float)($projectSummary['avg_project_progress'] ?? 0), 2);

    $taskSummaryStmt = $conn->prepare("
        SELECT
            COUNT(*) AS tasks_total,
            SUM(CASE WHEN t.status = 'done' THEN 1 ELSE 0 END) AS tasks_done,
            SUM(CASE WHEN t.teacher_status = 'approved' THEN 1 ELSE 0 END) AS tasks_approved,
            SUM(CASE WHEN t.status = 'done' AND t.teacher_status = 'pending' THEN 1 ELSE 0 END) AS tasks_pending_review,
            SUM(CASE WHEN t.teacher_status <> 'approved' AND t.due_date < CURDATE() THEN 1 ELSE 0 END) AS tasks_overdue
        FROM tasks t
        INNER JOIN projects p ON p.id = t.project_id
        WHERE YEAR(p.created_at) = ?
    ");
    $taskSummaryStmt->execute([$selectedYear]);
    $taskSummary = $taskSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $kpi['tasks_total'] = (int)($taskSummary['tasks_total'] ?? 0);
    $kpi['tasks_done'] = (int)($taskSummary['tasks_done'] ?? 0);
    $kpi['tasks_approved'] = (int)($taskSummary['tasks_approved'] ?? 0);
    $kpi['tasks_pending_review'] = (int)($taskSummary['tasks_pending_review'] ?? 0);
    $kpi['tasks_overdue'] = (int)($taskSummary['tasks_overdue'] ?? 0);

    $peopleSummaryStmt = $conn->prepare("
        SELECT
            COUNT(DISTINCT p.student_id) AS active_students,
            COUNT(DISTINCT pm.user_id) AS active_members,
            COUNT(DISTINCT CASE WHEN p.advisor_id IS NOT NULL THEN p.advisor_id END) AS active_main_advisors,
            COUNT(DISTINCT CASE WHEN p.co_advisor_id IS NOT NULL THEN p.co_advisor_id END) AS active_co_advisors
        FROM projects p
        LEFT JOIN project_members pm ON pm.project_id = p.id AND pm.status = 'accepted'
        WHERE YEAR(p.created_at) = ?
    ");
    $peopleSummaryStmt->execute([$selectedYear]);
    $peopleSummary = $peopleSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $kpi['active_students'] = (int)($peopleSummary['active_students'] ?? 0);
    $kpi['active_members'] = (int)($peopleSummary['active_members'] ?? 0);
    $kpi['active_main_advisors'] = (int)($peopleSummary['active_main_advisors'] ?? 0);
    $kpi['active_co_advisors'] = (int)($peopleSummary['active_co_advisors'] ?? 0);

    $monthlyStmt = $conn->prepare("
        SELECT
            MONTH(p.created_at) AS month_no,
            COUNT(*) AS projects_created,
            SUM(CASE WHEN p.progress >= 100 OR p.status = 'completed' THEN 1 ELSE 0 END) AS projects_completed
        FROM projects p
        WHERE YEAR(p.created_at) = ?
        GROUP BY MONTH(p.created_at)
        ORDER BY MONTH(p.created_at)
    ");
    $monthlyStmt->execute([$selectedYear]);
    $monthlyRows = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $overdueStmt = $conn->prepare("
        SELECT
            p.id,
            p.name,
            p.progress,
            u.fullname AS leader_name,
            COUNT(t.id) AS overdue_count,
            MIN(t.due_date) AS oldest_due_date
        FROM projects p
        INNER JOIN users u ON u.id = p.student_id
        INNER JOIN tasks t ON t.project_id = p.id
        WHERE YEAR(p.created_at) = ?
          AND t.teacher_status <> 'approved'
          AND t.due_date < CURDATE()
        GROUP BY p.id, p.name, p.progress, u.fullname
        ORDER BY overdue_count DESC, oldest_due_date ASC
        LIMIT 10
    ");
    $overdueStmt->execute([$selectedYear]);
    $overdueProjects = $overdueStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $pageError = 'ไม่สามารถโหลดข้อมูล KPI ได้ในขณะนี้ กรุณาตรวจสอบโครงสร้างฐานข้อมูลและสิทธิ์การเข้าถึง';
}

$taskCompletionRate = $kpi['tasks_total'] > 0 ? round(($kpi['tasks_done'] / $kpi['tasks_total']) * 100, 2) : 0.0;
$taskApprovalRate = $kpi['tasks_total'] > 0 ? round(($kpi['tasks_approved'] / $kpi['tasks_total']) * 100, 2) : 0.0;
$projectCompletionRate = $kpi['projects_total'] > 0 ? round(($kpi['projects_completed'] / $kpi['projects_total']) * 100, 2) : 0.0;

$monthMap = [];
for ($m = 1; $m <= 12; $m++) {
    $monthMap[$m] = [
        'month_no' => $m,
        'projects_created' => 0,
        'projects_completed' => 0,
    ];
}
foreach ($monthlyRows as $row) {
    $m = (int)($row['month_no'] ?? 0);
    if ($m >= 1 && $m <= 12) {
        $monthMap[$m]['projects_created'] = (int)($row['projects_created'] ?? 0);
        $monthMap[$m]['projects_completed'] = (int)($row['projects_completed'] ?? 0);
    }
}
$monthlyRows = array_values($monthMap);
$maxMonthlyProjects = 1;
foreach ($monthlyRows as $row) {
    $maxMonthlyProjects = max($maxMonthlyProjects, (int)$row['projects_created']);
}

writeAuditLog($conn, $current_user_id, 'admin.kpi.view', 'เปิดแดชบอร์ด KPI ปี ' . $selectedYear, 'report', null);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แดชบอร์ด KPI ผู้ดูแลระบบ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="assets/js/rmutp-ui.js"></script>
    <style>
        body { font-family: "Sarabun", sans-serif; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 min-h-screen">
    <nav class="bg-indigo-700 text-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <i class="fas fa-chart-line"></i>
                <span class="font-bold">แดชบอร์ด KPI</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-sm hidden md:inline"><?= htmlspecialchars($current_user_name) ?></span>
                <a href="admin_dashboard.php" class="bg-indigo-800 hover:bg-indigo-900 px-3 py-1.5 rounded text-sm">
                    <i class="fas fa-arrow-left mr-1"></i> กลับหน้าผู้ดูแล
                </a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto p-4 md:p-8">
        <div class="bg-white rounded-xl shadow p-4 mb-6">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label for="year" class="text-sm font-bold text-gray-700">ปี (ค.ศ.)</label>
                <input id="year" name="year" type="number" min="2000" max="2600" value="<?= (int)$selectedYear ?>" class="border rounded px-3 py-2 w-36">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded">โหลด KPI</button>
            </form>
        </div>

        <?php if ($pageError !== ''): ?>
            <div class="bg-red-50 text-red-700 border border-red-200 rounded-lg p-4 mb-6">
                <?= htmlspecialchars($pageError) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-blue-500">
                <div class="text-sm text-gray-500">โครงงานทั้งหมด</div>
                <div class="text-2xl font-bold"><?= (int)$kpi['projects_total'] ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-green-500">
                <div class="text-sm text-gray-500">โครงงานเสร็จสิ้น</div>
                <div class="text-2xl font-bold"><?= (int)$kpi['projects_completed'] ?></div>
                <div class="text-xs text-gray-500 mt-1"><?= number_format($projectCompletionRate, 2) ?>%</div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-amber-500">
                <div class="text-sm text-gray-500">โครงงานกำลังดำเนินการ</div>
                <div class="text-2xl font-bold"><?= (int)$kpi['projects_active'] ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-purple-500">
                <div class="text-sm text-gray-500">ความคืบหน้าเฉลี่ย</div>
                <div class="text-2xl font-bold"><?= number_format((float)$kpi['avg_project_progress'], 2) ?>%</div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-cyan-500">
                <div class="text-sm text-gray-500">งานทั้งหมด</div>
                <div class="text-2xl font-bold"><?= (int)$kpi['tasks_total'] ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-teal-500">
                <div class="text-sm text-gray-500">งานที่ส่งแล้ว</div>
                <div class="text-2xl font-bold"><?= (int)$kpi['tasks_done'] ?></div>
                <div class="text-xs text-gray-500 mt-1"><?= number_format($taskCompletionRate, 2) ?>%</div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-emerald-500">
                <div class="text-sm text-gray-500">งานที่อนุมัติแล้ว</div>
                <div class="text-2xl font-bold"><?= (int)$kpi['tasks_approved'] ?></div>
                <div class="text-xs text-gray-500 mt-1"><?= number_format($taskApprovalRate, 2) ?>%</div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-rose-500">
                <div class="text-sm text-gray-500">งานเกินกำหนด</div>
                <div class="text-2xl font-bold"><?= (int)$kpi['tasks_overdue'] ?></div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-slate-500">
                <div class="text-sm text-gray-500">รอตรวจงาน</div>
                <div class="text-2xl font-bold"><?= (int)$kpi['tasks_pending_review'] ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-indigo-500">
                <div class="text-sm text-gray-500">นักศึกษาที่มีโครงงาน</div>
                <div class="text-2xl font-bold"><?= (int)$kpi['active_students'] ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-fuchsia-500">
                <div class="text-sm text-gray-500">สมาชิกโครงงาน</div>
                <div class="text-2xl font-bold"><?= (int)$kpi['active_members'] ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-orange-500">
                <div class="text-sm text-gray-500">อาจารย์ที่ปรึกษา (หลัก/รอง)</div>
                <div class="text-2xl font-bold"><?= (int)$kpi['active_main_advisors'] ?>/<?= (int)$kpi['active_co_advisors'] ?></div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <section class="bg-white rounded-xl shadow p-4">
                <h2 class="font-bold mb-4">โครงงานที่สร้างและเสร็จในแต่ละเดือน</h2>
                <div class="space-y-3">
                    <?php
                    $thaiMonths = [
                        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
                        5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
                        9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
                    ];
                    foreach ($monthlyRows as $row):
                        $monthNo = (int)$row['month_no'];
                        $created = (int)$row['projects_created'];
                        $completed = (int)$row['projects_completed'];
                        $widthCreated = max(2, (int)round(($created / $maxMonthlyProjects) * 100));
                        $widthCompleted = $created > 0 ? max(2, (int)round(($completed / $maxMonthlyProjects) * 100)) : 0;
                    ?>
                    <div>
                        <div class="flex justify-between text-xs mb-1">
                            <span class="font-bold text-gray-600"><?= $thaiMonths[$monthNo] ?></span>
                            <span class="text-gray-500">สร้าง <?= $created ?> | เสร็จ <?= $completed ?></span>
                        </div>
                        <div class="w-full bg-gray-100 rounded h-3 relative overflow-hidden">
                            <div class="bg-blue-400 h-3 rounded" style="width: <?= $widthCreated ?>%"></div>
                            <?php if ($widthCompleted > 0): ?>
                                <div class="bg-green-500 h-3 rounded absolute top-0 left-0" style="width: <?= $widthCompleted ?>%"></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="bg-white rounded-xl shadow overflow-hidden">
                <div class="px-4 py-3 border-b font-bold">Top 10 โครงงานที่มีงานเกินกำหนดมากที่สุด</div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 border-b text-left">โครงงาน</th>
                                <th class="px-4 py-3 border-b text-left">หัวหน้า</th>
                                <th class="px-4 py-3 border-b text-center">งานเกินกำหนด</th>
                                <th class="px-4 py-3 border-b text-center">เก่าสุด</th>
                                <th class="px-4 py-3 border-b text-center">คืบหน้า</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($overdueProjects)): ?>
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-gray-500">ไม่พบงานเกินกำหนดในปีที่เลือก</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($overdueProjects as $row): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 border-b">
                                            <a href="project_detail.php?id=<?= (int)$row['id'] ?>" class="text-indigo-700 hover:text-indigo-900 font-medium">
                                                <?= htmlspecialchars((string)$row['name']) ?>
                                            </a>
                                        </td>
                                        <td class="px-4 py-3 border-b"><?= htmlspecialchars((string)$row['leader_name']) ?></td>
                                        <td class="px-4 py-3 border-b text-center font-bold text-rose-600"><?= (int)$row['overdue_count'] ?></td>
                                        <td class="px-4 py-3 border-b text-center"><?= htmlspecialchars((string)$row['oldest_due_date']) ?></td>
                                        <td class="px-4 py-3 border-b text-center"><?= (int)$row['progress'] ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
