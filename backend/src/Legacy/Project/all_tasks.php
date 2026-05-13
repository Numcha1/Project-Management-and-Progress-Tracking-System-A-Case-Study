<?php
session_start();
require_once __DIR__ . '/../System/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
if (($_SESSION['role'] ?? '') !== 'student') {
    header("Location: index.php");
    exit;
}

$user_fullname = $_SESSION['fullname'];
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

// ดึงงานทั้งหมด (ไม่จำกัดจำนวน) ที่ยังไม่เสร็จ
$countStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    WHERE t.assignee_name = ? AND t.status != 'done'
");
$countStmt->execute([$user_fullname]);
$totalTasks = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalTasks / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$stmt = $conn->prepare("
    SELECT t.*, p.name AS project_name 
    FROM tasks t 
    JOIN projects p ON t.project_id = p.id 
    WHERE t.assignee_name = ? AND t.status != 'done'
    ORDER BY t.due_date ASC
    LIMIT " . (int)$perPage . " OFFSET " . (int)$offset
);
$stmt->execute([$user_fullname]);
$tasks = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>งานทั้งหมดที่ต้องส่ง</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="assets/js/rmutp-ui.js"></script>
    <style>@import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;700&display=swap'); body{font-family:'Sarabun',sans-serif;}</style>
</head>
<body class="bg-gray-100 text-gray-800">

<nav class="bg-purple-800 text-white p-4 shadow mb-6">
    <div class="container mx-auto flex items-center gap-4">
        <a href="student_dashboard.php" class="hover:text-gray-300"><i class="fas fa-arrow-left"></i> กลับ Dashboard</a>
        <h1 class="font-bold text-xl">รายการงานค้างส่งทั้งหมด (<?= (int)$totalTasks ?>)</h1>
    </div>
</nav>

<div class="container mx-auto p-4 max-w-4xl">
    <div class="bg-white p-6 rounded shadow">
        <?php if(count($tasks) > 0): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-100 border-b">
                            <th class="p-3 text-sm font-bold text-gray-600">ชื่องาน</th>
                            <th class="p-3 text-sm font-bold text-gray-600">วิชา/โครงงาน</th>
                            <th class="p-3 text-sm font-bold text-gray-600 text-center">กำหนดส่ง</th>
                            <th class="p-3 text-sm font-bold text-gray-600 text-center">สถานะ</th>
                            <th class="p-3 text-sm font-bold text-gray-600 text-center">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($tasks as $t): 
                             $due = strtotime($t['due_date']);
                             $days = ceil(($due - time())/86400);
                             $is_late = $days < 0;
                        ?>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="p-3"><?= htmlspecialchars($t['name']) ?></td>
                            <td class="p-3 text-sm text-gray-500"><?= htmlspecialchars($t['project_name']) ?></td>
                            <td class="p-3 text-center text-sm <?= $is_late ? 'text-red-500 font-bold' : '' ?>">
                                <?= date('d/m/Y', $due) ?> <br>
                                <span class="text-xs">(<?= $is_late ? 'เลยกำหนด' : "เหลือ $days วัน" ?>)</span>
                            </td>
                            <td class="p-3 text-center">
                                <?php if($t['teacher_status'] == 'rejected'): ?>
                                    <span class="bg-red-100 text-red-800 text-xs px-2 py-1 rounded-full">แก้ไขงาน</span>
                                <?php else: ?>
                                    <span class="bg-yellow-100 text-yellow-800 text-xs px-2 py-1 rounded-full">รอดำเนินการ</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-3 text-center">
                                <a href="project_detail.php?id=<?= $t['project_id'] ?>" class="bg-purple-600 text-white text-xs px-3 py-1 rounded hover:bg-purple-700">
                                    ไปที่งาน
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalTasks > 0): ?>
                <?php
                    $startRow = (($page - 1) * $perPage) + 1;
                    $endRow = min($totalTasks, $page * $perPage);
                ?>
                <div class="mt-4 pt-4 border-t flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div class="text-sm text-gray-600">
                        แสดง <?= (int)$startRow ?>-<?= (int)$endRow ?> จาก <?= (int)$totalTasks ?> รายการ
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <?php if ($page > 1): ?>
                            <a href="all_tasks.php?page=1" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">หน้าแรก</a>
                            <a href="all_tasks.php?page=<?= (int)($page - 1) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">ก่อนหน้า</a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">หน้าแรก</span>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">ก่อนหน้า</span>
                        <?php endif; ?>

                        <span class="px-3 py-1.5 rounded bg-purple-700 text-white text-sm font-bold">หน้า <?= (int)$page ?> / <?= (int)$totalPages ?></span>

                        <?php if ($page < $totalPages): ?>
                            <a href="all_tasks.php?page=<?= (int)($page + 1) ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">ถัดไป</a>
                            <a href="all_tasks.php?page=<?= (int)$totalPages ?>" class="px-3 py-1.5 rounded border bg-white hover:bg-gray-50 text-sm">หน้าสุดท้าย</a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">ถัดไป</span>
                            <span class="px-3 py-1.5 rounded border bg-gray-100 text-gray-400 text-sm cursor-not-allowed">หน้าสุดท้าย</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="text-center py-10 text-gray-400">
                <i class="fas fa-check-circle text-4xl mb-2 text-green-400"></i><br>
                ยอดเยี่ยม! คุณไม่มีงานค้างส่งแล้ว
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
