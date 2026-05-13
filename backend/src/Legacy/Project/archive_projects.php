<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();
require_once __DIR__ . '/../System/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['role'] ?? 'student';
$user_fullname = $_SESSION['fullname'] ?? '';

$back_url = 'student_dashboard.php';
$nav_class = 'bg-purple-800';
$edit_class = 'text-purple-800';
$archive_chip_class = 'bg-purple-50 text-purple-800 border-purple-100';

if ($user_role === 'teacher') {
    $back_url = 'teacher_dashboard.php';
    $nav_class = 'bg-blue-900';
    $edit_class = 'text-blue-900';
    $archive_chip_class = 'bg-blue-50 text-blue-800 border-blue-100';
} elseif ($user_role === 'admin') {
    $back_url = 'admin_dashboard.php';
    $nav_class = 'bg-gray-800';
    $edit_class = 'text-gray-800';
    $archive_chip_class = 'bg-gray-100 text-gray-800 border-gray-200';
}

$scope_note = 'ค้นหาโครงงานที่เสร็จสมบูรณ์ (100%) ได้จากชื่อโครงงาน ชื่อสมาชิก หรืออาจารย์ที่ปรึกษา';
if ($user_role === 'teacher') {
    $scope_note = 'แสดงเฉพาะโครงงานที่คุณเป็นอาจารย์ที่ปรึกษาหลักหรือที่ปรึกษาร่วม';
} elseif ($user_role === 'student') {
    $scope_note = 'แสดงเฉพาะโครงงานของคุณ และโครงงานที่คุณเป็นสมาชิกในกลุ่ม';
}

$script_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$upload_web_base = preg_match('#/frontend/public$#', $script_dir)
    ? $script_dir . '/uploads'
    : $script_dir . '/frontend/public/uploads';

$search = trim($_GET['search'] ?? '');
$filter_year = $_GET['year'] ?? 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$visibility_sql = '';
$visibility_params = [];
if ($user_role === 'student') {
    $visibility_sql = "
        AND (
            p.student_id = ?
            OR EXISTS (
                SELECT 1
                FROM project_members pm_self
                WHERE pm_self.project_id = p.id
                  AND pm_self.user_id = ?
                  AND pm_self.status = 'accepted'
            )
        )
    ";
    $visibility_params = [$user_id, $user_id];
} elseif ($user_role === 'teacher') {
    $visibility_sql = " AND (p.advisor_id = ? OR p.co_advisor_id = ?) ";
    $visibility_params = [$user_id, $user_id];
}

$stmt_years = $conn->prepare("
    SELECT DISTINCT YEAR(p.created_at) AS y
    FROM projects p
    WHERE p.progress = 100
      AND p.created_at IS NOT NULL
      {$visibility_sql}
    ORDER BY y DESC
");
$stmt_years->execute($visibility_params);
$available_years = $stmt_years->fetchAll(PDO::FETCH_COLUMN);

$from_sql = "
    FROM projects p
    JOIN users leader ON leader.id = p.student_id
    LEFT JOIN users adv ON adv.id = p.advisor_id
    LEFT JOIN users co ON co.id = p.co_advisor_id
    WHERE p.progress = 100
    {$visibility_sql}
";

$params = $visibility_params;
if ($search !== '') {
    $from_sql .= "
        AND (
            p.name LIKE ?
            OR p.description LIKE ?
            OR p.case_study LIKE ?
            OR leader.fullname LIKE ?
            OR COALESCE(adv.fullname, '') LIKE ?
            OR COALESCE(co.fullname, '') LIKE ?
            OR EXISTS (
                SELECT 1
                FROM project_members pm2
                JOIN users mu2 ON mu2.id = pm2.user_id
                WHERE pm2.project_id = p.id
                  AND pm2.status = 'accepted'
                  AND mu2.fullname LIKE ?
            )
        )
    ";
    $search_like = '%' . $search . '%';
    array_push($params, $search_like, $search_like, $search_like, $search_like, $search_like, $search_like, $search_like);
}

if ($filter_year !== 'all' && ctype_digit((string)$filter_year)) {
    $from_sql .= " AND YEAR(p.created_at) = ? ";
    $params[] = (int)$filter_year;
}

$count_sql = "SELECT COUNT(*) " . $from_sql;
$stmt_count = $conn->prepare($count_sql);
$stmt_count->execute($params);
$total_projects = (int)$stmt_count->fetchColumn();
$total_pages = max(1, (int)ceil($total_projects / $perPage));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $perPage;

$sql = "
    SELECT
        p.id,
        p.name,
        p.description,
        p.case_study,
        p.progress,
        p.created_at,
        leader.fullname AS leader_name,
        adv.fullname AS advisor_name,
        co.fullname AS co_advisor_name,
        (
            SELECT GROUP_CONCAT(mu.fullname SEPARATOR ', ')
            FROM project_members pm
            JOIN users mu ON mu.id = pm.user_id
            WHERE pm.project_id = p.id AND pm.status = 'accepted'
        ) AS member_names,
        (
            SELECT COUNT(*)
            FROM tasks t
            WHERE t.project_id = p.id
        ) AS task_count,
        (
            SELECT COUNT(*)
            FROM tasks t
            WHERE t.project_id = p.id
              AND t.file_path IS NOT NULL
              AND t.file_path <> ''
        ) AS file_count
    " . $from_sql . "
    ORDER BY p.created_at DESC, p.id DESC
    LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$files_by_project = [];
$project_ids = array_map(static function ($p) {
    return (int)$p['id'];
}, $projects);

if (!empty($project_ids)) {
    $placeholders = implode(',', array_fill(0, count($project_ids), '?'));
    $sql_files = "
        SELECT project_id, name AS task_name, file_path
        FROM tasks
        WHERE project_id IN ($placeholders)
          AND file_path IS NOT NULL
          AND file_path <> ''
        ORDER BY id DESC
    ";
    $stmt_files = $conn->prepare($sql_files);
    $stmt_files->execute($project_ids);
    while ($row = $stmt_files->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['project_id'];
        if (!isset($files_by_project[$pid])) {
            $files_by_project[$pid] = [];
        }
        $files_by_project[$pid][] = $row;
    }
}

function file_icon_class(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($ext, ['doc', 'docx'], true)) {
        return 'fas fa-file-word text-blue-600';
    }
    if (in_array($ext, ['xls', 'xlsx'], true)) {
        return 'fas fa-file-excel text-green-600';
    }
    if (in_array($ext, ['ppt', 'pptx'], true)) {
        return 'fas fa-file-powerpoint text-orange-500';
    }
    if ($ext === 'pdf') {
        return 'fas fa-file-pdf text-red-600';
    }
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        return 'fas fa-file-image text-cyan-600';
    }
    return 'fas fa-file text-gray-500';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>คลังโครงงานเก่า</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="assets/js/rmutp-ui.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;700&display=swap');
        body { font-family: 'Sarabun', sans-serif; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800">
    <nav class="<?= $nav_class ?> text-white p-4 shadow-lg sticky top-0 z-40">
        <div class="max-w-7xl mx-auto flex justify-between items-center gap-3">
            <div class="font-bold text-xl">RMUTP Project</div>
            <div class="flex items-center gap-2 md:gap-3">
                <a href="<?= $back_url ?>" class="bg-white/20 px-3 py-1 rounded text-sm hover:bg-white/30 transition">
                    <i class="fas fa-arrow-left"></i> กลับ
                </a>
                <span class="hidden md:inline text-sm"><?= htmlspecialchars($user_fullname) ?></span>
                <a href="edit_profile.php" class="bg-white <?= $edit_class ?> px-3 py-1 rounded text-sm hover:bg-gray-100 font-bold transition">
                    <i class="fas fa-user-cog"></i> แก้ไขส่วนตัว
                </a>
                <a href="logout.php" class="bg-yellow-500 text-black px-3 py-1 rounded text-sm hover:bg-yellow-400 transition">ออกจากระบบ</a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto p-4 md:p-6">
        <div class="bg-white rounded-xl shadow p-5 border border-gray-200">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-archive mr-2"></i> คลังโครงงานเก่าทั้งหมด
                    </h1>
                    <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars($scope_note) ?></p>
                </div>
                <div class="px-3 py-2 text-sm rounded-lg border <?= $archive_chip_class ?>">
                    พบทั้งหมด <?= (int)$total_projects ?> โครงงาน
                </div>
            </div>

            <form method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-3 bg-gray-50 rounded-lg p-3 border border-gray-100">
                <input type="hidden" name="page" value="1">
                <div class="md:col-span-8">
                    <label class="block text-xs text-gray-600 mb-1">ค้นหา</label>
                    <input
                        type="text"
                        name="search"
                        value="<?= htmlspecialchars($search) ?>"
                        placeholder="ชื่อโครงงาน / ผู้นำกลุ่ม / สมาชิก / อาจารย์ที่ปรึกษา"
                        class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-purple-400"
                    >
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs text-gray-600 mb-1">ปีการสร้าง</label>
                    <select name="year" class="w-full border rounded px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-purple-400">
                        <option value="all" <?= $filter_year === 'all' ? 'selected' : '' ?>>ทุกปี</option>
                        <?php foreach ($available_years as $year): ?>
                            <?php $thai_year = (int)$year + 543; ?>
                            <option value="<?= (int)$year ?>" <?= ((string)$filter_year === (string)$year) ? 'selected' : '' ?>>
                                พ.ศ. <?= $thai_year ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-2 flex gap-2 items-end">
                    <button type="submit" class="w-full bg-gray-900 text-white px-3 py-2 rounded hover:bg-black transition">
                        <i class="fas fa-search mr-1"></i> ค้นหา
                    </button>
                    <a href="archive_projects.php" class="px-3 py-2 border rounded bg-white hover:bg-gray-100 transition text-sm">
                        ล้าง
                    </a>
                </div>
            </form>
        </div>

        <?php if (empty($projects)): ?>
            <div class="bg-white mt-6 rounded-xl shadow p-10 text-center text-gray-500 border border-gray-200">
                <i class="fas fa-folder-open text-4xl text-gray-300 mb-3"></i>
                <div>ไม่พบโครงงานที่ตรงกับเงื่อนไขที่ค้นหา</div>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mt-6">
                <?php foreach ($projects as $project): ?>
                    <?php
                        $project_id = (int)$project['id'];
                        $files = $files_by_project[$project_id] ?? [];
                    ?>
                    <article class="bg-white rounded-xl shadow border border-gray-200 p-5 hover:shadow-lg transition">
                        <div class="flex justify-between items-start gap-3">
                            <div>
                                <h2 class="font-bold text-lg text-gray-800"><?= htmlspecialchars($project['name']) ?></h2>
                                <p class="text-sm text-gray-500 mt-1">กรณีศึกษา: <?= htmlspecialchars($project['case_study']) ?></p>
                            </div>
                            <span class="bg-green-100 text-green-800 text-xs px-2 py-1 rounded-full font-bold border border-green-200 whitespace-nowrap">100%</span>
                        </div>

                        <p class="text-sm text-gray-700 mt-3 leading-relaxed">
                            <?= nl2br(htmlspecialchars($project['description'])) ?>
                        </p>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-4 text-sm">
                            <div class="bg-gray-50 rounded p-3 border border-gray-100">
                                <div class="text-gray-500 text-xs mb-1">หัวหน้าโครงงาน</div>
                                <div class="font-semibold text-gray-800"><?= htmlspecialchars($project['leader_name']) ?></div>
                            </div>
                            <div class="bg-gray-50 rounded p-3 border border-gray-100">
                                <div class="text-gray-500 text-xs mb-1">อาจารย์ที่ปรึกษา</div>
                                <div class="font-semibold text-gray-800">
                                    <?= htmlspecialchars($project['advisor_name'] ?: '-') ?>
                                    <?php if (!empty($project['co_advisor_name'])): ?>
                                        <span class="text-gray-500 font-normal"> / <?= htmlspecialchars($project['co_advisor_name']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="bg-gray-50 rounded p-3 border border-gray-100 md:col-span-2">
                                <div class="text-gray-500 text-xs mb-1">สมาชิกในกลุ่ม</div>
                                <div class="font-semibold text-gray-800"><?= htmlspecialchars($project['member_names'] ?: '-') ?></div>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2 text-xs mt-4">
                            <span class="px-2 py-1 rounded bg-gray-100 border border-gray-200">
                                <i class="fas fa-tasks mr-1"></i> งานทั้งหมด <?= (int)$project['task_count'] ?> งาน
                            </span>
                            <span class="px-2 py-1 rounded bg-gray-100 border border-gray-200">
                                <i class="fas fa-paperclip mr-1"></i> ไฟล์ที่แนบ <?= (int)$project['file_count'] ?> ไฟล์
                            </span>
                            <span class="px-2 py-1 rounded bg-gray-100 border border-gray-200">
                                <i class="fas fa-calendar mr-1"></i> ปี <?= date('Y', strtotime($project['created_at'])) + 543 ?>
                            </span>
                        </div>

                        <?php if (!empty($files)): ?>
                            <div class="mt-4 pt-4 border-t border-gray-100">
                                <div class="text-sm font-semibold text-gray-700 mb-2">ไฟล์ที่ส่ง</div>
                                <div class="flex flex-wrap gap-2">
                                    <?php foreach (array_slice($files, 0, 8) as $file): ?>
                                        <?php
                                            $file_url = $upload_web_base . '/' . rawurlencode($file['file_path']);
                                            $file_label = $file['file_path'];
                                            $icon = file_icon_class($file['file_path']);
                                        ?>
                                        <a
                                            href="<?= htmlspecialchars($file_url) ?>"
                                            target="_blank"
                                            class="group flex items-center gap-2 rounded border border-gray-200 px-2 py-1.5 bg-white hover:bg-gray-50"
                                            title="<?= htmlspecialchars($file_label) ?>"
                                        >
                                            <span class="w-7 h-7 rounded border border-gray-200 bg-gray-50 flex items-center justify-center">
                                                <i class="<?= $icon ?>"></i>
                                            </span>
                                            <span class="text-xs text-gray-700 max-w-[165px] truncate"><?= htmlspecialchars($file_label) ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ($total_projects > 0): ?>
                <?php
                    $startRow = (($page - 1) * $perPage) + 1;
                    $endRow = min($total_projects, $page * $perPage);
                    $baseQuery = [
                        'search' => $search,
                        'year' => $filter_year,
                    ];
                ?>
                <div class="bg-white mt-4 rounded-xl shadow p-4 border border-gray-200 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div class="text-sm text-gray-600">
                        แสดง <?= (int)$startRow ?>-<?= (int)$endRow ?> จาก <?= (int)$total_projects ?> รายการ
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <?php if ($page > 1): ?>
                            <a href="archive_projects.php?<?= htmlspecialchars(http_build_query(array_merge($baseQuery, ['page' => 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">หน้าแรก</a>
                            <a href="archive_projects.php?<?= htmlspecialchars(http_build_query(array_merge($baseQuery, ['page' => $page - 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">ก่อนหน้า</a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">หน้าแรก</span>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">ก่อนหน้า</span>
                        <?php endif; ?>

                        <span class="px-3 py-1.5 rounded <?= $nav_class ?> text-white text-sm font-bold">หน้า <?= (int)$page ?> / <?= (int)$total_pages ?></span>

                        <?php if ($page < $total_pages): ?>
                            <a href="archive_projects.php?<?= htmlspecialchars(http_build_query(array_merge($baseQuery, ['page' => $page + 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">ถัดไป</a>
                            <a href="archive_projects.php?<?= htmlspecialchars(http_build_query(array_merge($baseQuery, ['page' => $total_pages]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">หน้าสุดท้าย</a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">ถัดไป</span>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">หน้าสุดท้าย</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
