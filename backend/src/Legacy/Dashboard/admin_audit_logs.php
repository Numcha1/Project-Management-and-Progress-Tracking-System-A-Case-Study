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

if (!hasAdminPermission($permissions, 'can_view_audit')) {
    header('Location: admin_dashboard.php?status=permission_denied');
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$actionFilter = trim((string)($_GET['action_key'] ?? ''));
$actorFilter = trim((string)($_GET['actor'] ?? ''));
$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));
$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));

$logs = [];
$topActions = [];
$totalFiltered = 0;
$totalPages = 1;
$summary = [
    'total' => 0,
    'last_24h' => 0,
    'last_7d' => 0,
];
$pageError = '';

try {
    $summaryStmt = $conn->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS last_24h,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS last_7d
        FROM audit_logs
    ");
    $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC);
    if ($summaryRow) {
        $summary['total'] = (int)($summaryRow['total'] ?? 0);
        $summary['last_24h'] = (int)($summaryRow['last_24h'] ?? 0);
        $summary['last_7d'] = (int)($summaryRow['last_7d'] ?? 0);
    }

    $topStmt = $conn->query("
        SELECT action_key, COUNT(*) AS total
        FROM audit_logs
        GROUP BY action_key
        ORDER BY total DESC
        LIMIT 15
    ");
    $topActions = $topStmt->fetchAll(PDO::FETCH_ASSOC);

    $whereSql = " WHERE 1 = 1";
    $params = [];

    if ($q !== '') {
        $whereSql .= " AND (
            l.action_key LIKE ?
            OR l.action_detail LIKE ?
            OR l.target_type LIKE ?
            OR u.fullname LIKE ?
            OR u.email LIKE ?
        )";
        $qLike = '%' . $q . '%';
        $params[] = $qLike;
        $params[] = $qLike;
        $params[] = $qLike;
        $params[] = $qLike;
        $params[] = $qLike;
    }

    if ($actionFilter !== '') {
        $whereSql .= " AND l.action_key = ?";
        $params[] = $actionFilter;
    }
    if ($actorFilter !== '') {
        $whereSql .= " AND l.actor_id = ?";
        $params[] = (int)$actorFilter;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) === 1) {
        $whereSql .= " AND l.created_at >= ?";
        $params[] = $dateFrom . ' 00:00:00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) === 1) {
        $whereSql .= " AND l.created_at <= ?";
        $params[] = $dateTo . ' 23:59:59';
    }

    $countSql = "
        SELECT COUNT(*)
        FROM audit_logs l
        LEFT JOIN users u ON u.id = l.actor_id
        {$whereSql}
    ";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalFiltered = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalFiltered / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $sql = "
        SELECT
            l.id,
            l.actor_id,
            l.action_key,
            l.action_detail,
            l.target_type,
            l.target_id,
            l.ip_address,
            l.created_at,
            u.fullname AS actor_name,
            u.email AS actor_email
        FROM audit_logs l
        LEFT JOIN users u ON u.id = l.actor_id
        {$whereSql}
        ORDER BY l.created_at DESC
        LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $pageError = 'ไม่สามารถโหลดประวัติการใช้งานได้ในขณะนี้ กรุณาตรวจสอบโครงสร้างฐานข้อมูลและสิทธิ์การเข้าถึง';
}

$actors = [];
try {
    $actorStmt = $conn->query("
        SELECT DISTINCT u.id, u.fullname
        FROM audit_logs l
        INNER JOIN users u ON u.id = l.actor_id
        ORDER BY u.fullname ASC
    ");
    $actors = $actorStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $actors = [];
}

writeAuditLog($conn, $current_user_id, 'admin.audit.view', 'เปิดหน้าประวัติการใช้งาน', 'audit_logs', null);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ประวัติการใช้งานผู้ดูแลระบบ</title>
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
                <i class="fas fa-clipboard-list text-lg"></i>
                <span class="font-bold">ศูนย์ประวัติการใช้งาน</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-sm hidden md:inline"><?= htmlspecialchars($current_user_name) ?></span>
                <a href="admin_dashboard.php" class="bg-gray-700 hover:bg-gray-600 px-3 py-1.5 rounded text-sm">
                    <i class="fas fa-arrow-left mr-1"></i> กลับหน้าผู้ดูแล
                </a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto p-4 md:p-8">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-blue-500">
                <div class="text-sm text-gray-500">บันทึกทั้งหมด</div>
                <div class="text-2xl font-bold"><?= (int)$summary['total'] ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-emerald-500">
                <div class="text-sm text-gray-500">24 ชั่วโมงล่าสุด</div>
                <div class="text-2xl font-bold"><?= (int)$summary['last_24h'] ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 border-l-4 border-purple-500">
                <div class="text-sm text-gray-500">7 วันล่าสุด</div>
                <div class="text-2xl font-bold"><?= (int)$summary['last_7d'] ?></div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-4 mb-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-3">
                <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="ค้นหาเหตุการณ์/รายละเอียด/ผู้ใช้"
                    class="md:col-span-2 border rounded px-3 py-2">
                <input type="text" name="action_key" value="<?= htmlspecialchars($actionFilter) ?>" placeholder="คีย์เหตุการณ์"
                    class="border rounded px-3 py-2">
                <select name="actor" class="border rounded px-3 py-2">
                    <option value="">ผู้ใช้งานทั้งหมด</option>
                    <?php foreach ($actors as $actor): ?>
                    <option value="<?= (int)$actor['id'] ?>" <?= $actorFilter === (string)$actor['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($actor['fullname']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>" class="border rounded px-3 py-2">
                <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>" class="border rounded px-3 py-2">
                <div class="md:col-span-6 flex gap-2">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                        <i class="fas fa-search mr-1"></i> กรอง
                    </button>
                    <a href="admin_audit_logs.php" class="bg-gray-200 hover:bg-gray-300 px-4 py-2 rounded">
                        ล้างตัวกรอง
                    </a>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-xl shadow p-4 mb-6">
            <h2 class="font-bold mb-3">เหตุการณ์ที่พบมากที่สุด</h2>
            <div class="flex flex-wrap gap-2">
                <?php if (empty($topActions)): ?>
                    <span class="text-sm text-gray-500">ยังไม่มีข้อมูล</span>
                <?php else: ?>
                    <?php foreach ($topActions as $item): ?>
                        <span class="inline-flex items-center bg-gray-100 text-gray-700 rounded-full px-3 py-1 text-xs">
                            <?= htmlspecialchars((string)$item['action_key']) ?> (<?= (int)$item['total'] ?>)
                        </span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-bold">รายการล่าสุด</div>
            <?php if ($pageError !== ''): ?>
                <div class="p-4 text-red-700 bg-red-50 border-t"><?= htmlspecialchars($pageError) ?></div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="text-left px-4 py-3 border-b">เวลา</th>
                                <th class="text-left px-4 py-3 border-b">ผู้ใช้งาน</th>
                                <th class="text-left px-4 py-3 border-b">เหตุการณ์</th>
                                <th class="text-left px-4 py-3 border-b">รายละเอียด</th>
                                <th class="text-left px-4 py-3 border-b">เป้าหมาย</th>
                                <th class="text-left px-4 py-3 border-b">IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="6" class="px-4 py-8 text-center text-gray-500">ไม่พบข้อมูลประวัติการใช้งาน</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 border-b whitespace-nowrap"><?= htmlspecialchars((string)$log['created_at']) ?></td>
                                        <td class="px-4 py-3 border-b">
                                            <div class="font-medium"><?= htmlspecialchars((string)($log['actor_name'] ?? 'ไม่ทราบชื่อ')) ?></div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars((string)($log['actor_email'] ?? '')) ?></div>
                                        </td>
                                        <td class="px-4 py-3 border-b">
                                            <code class="bg-gray-100 px-2 py-1 rounded text-xs"><?= htmlspecialchars((string)$log['action_key']) ?></code>
                                        </td>
                                        <td class="px-4 py-3 border-b"><?= htmlspecialchars((string)($log['action_detail'] ?? '')) ?></td>
                                        <td class="px-4 py-3 border-b">
                                            <?= htmlspecialchars((string)($log['target_type'] ?? '-')) ?>
                                            <?php if (!empty($log['target_id'])): ?>
                                                #<?= (int)$log['target_id'] ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 border-b"><?= htmlspecialchars((string)($log['ip_address'] ?? '-')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalFiltered > 0): ?>
                    <?php
                        $startRow = (($page - 1) * $perPage) + 1;
                        $endRow = min($totalFiltered, $page * $perPage);
                        $pageQuery = $_GET;
                        unset($pageQuery['page']);
                        $pageStart = max(1, $page - 2);
                        $pageEnd = min($totalPages, $page + 2);
                    ?>
                    <div class="px-4 py-3 border-t bg-white flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                        <div class="text-sm text-gray-600">
                            แสดง <?= (int)$startRow ?>-<?= (int)$endRow ?> จาก <?= (int)$totalFiltered ?> รายการ
                        </div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <?php if ($page > 1): ?>
                                <a href="admin_audit_logs.php?<?= htmlspecialchars(http_build_query(array_merge($pageQuery, ['page' => 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">หน้าแรก</a>
                                <a href="admin_audit_logs.php?<?= htmlspecialchars(http_build_query(array_merge($pageQuery, ['page' => $page - 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">ก่อนหน้า</a>
                            <?php else: ?>
                                <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">หน้าแรก</span>
                                <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">ก่อนหน้า</span>
                            <?php endif; ?>

                            <?php for ($p = $pageStart; $p <= $pageEnd; $p++): ?>
                                <?php if ($p === $page): ?>
                                    <span class="px-3 py-1.5 rounded bg-blue-600 text-white text-sm font-bold"><?= (int)$p ?></span>
                                <?php else: ?>
                                    <a href="admin_audit_logs.php?<?= htmlspecialchars(http_build_query(array_merge($pageQuery, ['page' => $p]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm"><?= (int)$p ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="admin_audit_logs.php?<?= htmlspecialchars(http_build_query(array_merge($pageQuery, ['page' => $page + 1]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">ถัดไป</a>
                                <a href="admin_audit_logs.php?<?= htmlspecialchars(http_build_query(array_merge($pageQuery, ['page' => $totalPages]))) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">หน้าสุดท้าย</a>
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
