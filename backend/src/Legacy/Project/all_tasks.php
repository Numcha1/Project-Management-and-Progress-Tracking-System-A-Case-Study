<?php
session_start();
require_once __DIR__ . '/../System/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$user_fullname = $_SESSION['fullname'];

// ดึงงานทั้งหมด (ไม่จำกัดจำนวน) ที่ยังไม่เสร็จ
$stmt = $conn->prepare("
    SELECT t.*, p.name AS project_name 
    FROM tasks t 
    JOIN projects p ON t.project_id = p.id 
    WHERE t.assignee_name = ? AND t.status != 'done'
    ORDER BY t.due_date ASC
");
$stmt->execute([$user_fullname]);
$tasks = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>งานทั้งหมดที่ต้องส่ง</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>@import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;700&display=swap'); body{font-family:'Sarabun',sans-serif;}</style>
</head>
<body class="bg-gray-100 text-gray-800">

<nav class="bg-purple-800 text-white p-4 shadow mb-6">
    <div class="container mx-auto flex items-center gap-4">
        <a href="student_dashboard.php" class="hover:text-gray-300"><i class="fas fa-arrow-left"></i> กลับ Dashboard</a>
        <h1 class="font-bold text-xl">รายการงานค้างส่งทั้งหมด (<?= count($tasks) ?>)</h1>
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