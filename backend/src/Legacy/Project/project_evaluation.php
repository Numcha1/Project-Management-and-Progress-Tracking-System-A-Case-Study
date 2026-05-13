<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();
require_once __DIR__ . '/../System/db_connect.php';
require_once __DIR__ . '/../System/app_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = (string)($_SESSION['role'] ?? 'student');
$project_id = (int)($_GET['id'] ?? 0);

if ($project_id <= 0) {
    http_response_code(400);
    echo 'Invalid project id';
    exit;
}

$projectStmt = $conn->prepare("
    SELECT
        p.*,
        u.fullname AS leader_name,
        a.fullname AS advisor_name,
        c.fullname AS co_advisor_name
    FROM projects p
    LEFT JOIN users u ON u.id = p.student_id
    LEFT JOIN users a ON a.id = p.advisor_id
    LEFT JOIN users c ON c.id = p.co_advisor_id
    WHERE p.id = ?
    LIMIT 1
");
$projectStmt->execute([$project_id]);
$project = $projectStmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    http_response_code(404);
    echo 'Project not found';
    exit;
}

$is_leader = ((int)$project['student_id'] === $user_id);
$is_main_advisor = ((int)$project['advisor_id'] === $user_id);
$is_co_advisor = ((int)$project['co_advisor_id'] === $user_id);
$is_advisor_team = ($is_main_advisor || $is_co_advisor);

$memberCheckStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM project_members
    WHERE project_id = ? AND user_id = ? AND status = 'accepted'
");
$memberCheckStmt->execute([$project_id, $user_id]);
$is_member = ((int)$memberCheckStmt->fetchColumn()) > 0;

$can_view = ($user_role === 'admin' || $is_leader || $is_member || $is_advisor_team);
if (!$can_view && $user_role === 'teacher' && (int)$project['progress'] === 100) {
    $can_view = true;
}
if (!$can_view) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$can_evaluate = ($user_role === 'admin' || $is_advisor_team);

if (!function_exists('ensureDefaultRubricCriteria')) {
    function ensureDefaultRubricCriteria(PDO $conn): void
    {
        $count = (int)$conn->query("SELECT COUNT(*) FROM rubric_criteria WHERE is_active = 1")->fetchColumn();
        if ($count > 0) {
            return;
        }

        $defaultCriteria = [
            ['problem_analysis', 'การวิเคราะห์ปัญหาและโจทย์', 'ความชัดเจนของปัญหา วัตถุประสงค์ และขอบเขตโครงงาน', 20.00],
            ['method_design', 'การออกแบบวิธีดำเนินงาน', 'ความเหมาะสมของแนวทาง เครื่องมือ และแผนการดำเนินงาน', 20.00],
            ['implementation_quality', 'คุณภาพการพัฒนาและผลงาน', 'ความถูกต้อง ความสมบูรณ์ และความเสถียรของระบบ', 25.00],
            ['documentation_presentation', 'เอกสารและการนำเสนอ', 'ความครบถ้วนของเอกสารและการสื่อสารผลงาน', 15.00],
            ['impact_and_improvement', 'ผลกระทบและแนวทางต่อยอด', 'การประยุกต์ใช้จริงและข้อเสนอแนะในการพัฒนาต่อ', 20.00],
        ];

        $insertStmt = $conn->prepare("
            INSERT INTO rubric_criteria (criterion_key, title, description, max_score, is_active)
            VALUES (?, ?, ?, ?, 1)
        ");
        foreach ($defaultCriteria as $item) {
            $insertStmt->execute([$item[0], $item[1], $item[2], $item[3]]);
        }
    }
}

$status = (string)($_GET['status'] ?? '');
$errors = [];
$criteria = [];

try {
    ensureDefaultRubricCriteria($conn);
    $criteriaStmt = $conn->query("
        SELECT id, criterion_key, title, description, max_score
        FROM rubric_criteria
        WHERE is_active = 1
        ORDER BY id ASC
    ");
    $criteria = $criteriaStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $errors[] = 'ไม่สามารถโหลดเกณฑ์ประเมินได้ กรุณารัน buildDatabase.bat เพื่ออัปเดตโครงสร้างฐานข้อมูล';
}

csrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensureValidCsrfOrRedirect('project_evaluation.php?id=' . (int)$project_id);
}

if ($can_evaluate && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_evaluation') {
    if (empty($criteria)) {
        $errors[] = 'ยังไม่มีเกณฑ์ประเมิน กรุณาตรวจสอบตาราง rubric_criteria';
    }

    $evaluationRound = (int)($_POST['evaluation_round'] ?? 1);
    if ($evaluationRound < 1) {
        $evaluationRound = 1;
    } elseif ($evaluationRound > 10) {
        $evaluationRound = 10;
    }

    $result = (string)($_POST['result'] ?? 'pending');
    $allowedResults = ['pending', 'pass', 'revise', 'fail'];
    if (!in_array($result, $allowedResults, true)) {
        $result = 'pending';
    }

    $comment = trim((string)($_POST['comment'] ?? ''));
    $scoreRows = [];
    $totalScore = 0.0;
    $maxScore = 0.0;

    foreach ($criteria as $criterion) {
        $criterionId = (int)$criterion['id'];
        $max = (float)$criterion['max_score'];
        $scoreField = 'score_' . $criterionId;
        $noteField = 'note_' . $criterionId;
        $rawScore = trim((string)($_POST[$scoreField] ?? ''));
        $note = trim((string)($_POST[$noteField] ?? ''));

        if ($rawScore === '' || !is_numeric($rawScore)) {
            $errors[] = 'กรุณากรอกคะแนนให้ครบทุกหัวข้อ';
            break;
        }

        $score = (float)$rawScore;
        if ($score < 0 || $score > $max) {
            $errors[] = 'คะแนนต้องอยู่ระหว่าง 0 ถึง ' . number_format($max, 2) . ' สำหรับหัวข้อ ' . $criterion['title'];
            break;
        }

        $scoreRows[] = [
            'criterion_id' => $criterionId,
            'score' => $score,
            'note' => $note === '' ? null : $note,
        ];

        $totalScore += $score;
        $maxScore += $max;
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            $insertEvaluationStmt = $conn->prepare("
                INSERT INTO project_evaluations (
                    project_id,
                    evaluator_id,
                    evaluation_round,
                    total_score,
                    max_score,
                    result,
                    comment,
                    evaluated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $insertEvaluationStmt->execute([
                $project_id,
                $user_id,
                $evaluationRound,
                $totalScore,
                $maxScore,
                $result,
                $comment === '' ? null : $comment,
            ]);

            $evaluationId = (int)$conn->lastInsertId();
            $insertScoreStmt = $conn->prepare("
                INSERT INTO evaluation_scores (evaluation_id, criterion_id, score, note, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            foreach ($scoreRows as $row) {
                $insertScoreStmt->execute([
                    $evaluationId,
                    (int)$row['criterion_id'],
                    (float)$row['score'],
                    $row['note'],
                ]);
            }

            $conn->commit();

            $participantIds = getProjectParticipantUserIds($conn, $project_id);
            $participantIds = array_values(array_filter($participantIds, function ($uid) use ($user_id) {
                return (int)$uid > 0 && (int)$uid !== (int)$user_id;
            }));
            createNotificationForUsers(
                $conn,
                $participantIds,
                'มีผลประเมินโครงงานรอบที่ ' . $evaluationRound,
                'อาจารย์บันทึกผลประเมินโครงงาน: ' . (string)$project['name'],
                'info',
                $project_id
            );

            writeAuditLog(
                $conn,
                $user_id,
                'project.evaluation.create',
                'บันทึกผลประเมินโครงงาน ID: ' . $project_id . ' รอบ ' . $evaluationRound,
                'project',
                $project_id
            );

            header('Location: project_evaluation.php?id=' . $project_id . '&status=saved');
            exit;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $errors[] = 'ไม่สามารถบันทึกผลประเมินได้ในขณะนี้';
        }
    }
}

$evaluations = [];
$latestEvaluationScores = [];
try {
    $evaluationStmt = $conn->prepare("
        SELECT
            pe.id,
            pe.evaluation_round,
            pe.total_score,
            pe.max_score,
            pe.result,
            pe.comment,
            pe.evaluated_at,
            u.fullname AS evaluator_name
        FROM project_evaluations pe
        LEFT JOIN users u ON u.id = pe.evaluator_id
        WHERE pe.project_id = ?
        ORDER BY pe.evaluated_at DESC, pe.id DESC
        LIMIT 20
    ");
    $evaluationStmt->execute([$project_id]);
    $evaluations = $evaluationStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!empty($evaluations)) {
        $latestEvaluationId = (int)$evaluations[0]['id'];
        $latestScoreStmt = $conn->prepare("
            SELECT
                es.score,
                es.note,
                rc.title,
                rc.max_score
            FROM evaluation_scores es
            INNER JOIN rubric_criteria rc ON rc.id = es.criterion_id
            WHERE es.evaluation_id = ?
            ORDER BY rc.id ASC
        ");
        $latestScoreStmt->execute([$latestEvaluationId]);
        $latestEvaluationScores = $latestScoreStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $errors[] = 'ไม่สามารถโหลดประวัติการประเมินได้ กรุณารัน buildDatabase.bat เพื่ออัปเดตโครงสร้างฐานข้อมูล';
}

$resultMap = [
    'pending' => 'รอพิจารณา',
    'pass' => 'ผ่าน',
    'revise' => 'ต้องแก้ไข',
    'fail' => 'ไม่ผ่าน',
];

$resultColorMap = [
    'pending' => 'bg-gray-100 text-gray-700',
    'pass' => 'bg-green-100 text-green-700',
    'revise' => 'bg-amber-100 text-amber-700',
    'fail' => 'bg-red-100 text-red-700',
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rubric ประเมินโครงงาน</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="assets/js/rmutp-ui.js"></script>
    <style>
        body { font-family: "Sarabun", sans-serif; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 min-h-screen">
    <nav class="bg-white shadow">
        <div class="max-w-7xl mx-auto px-4 py-3 flex flex-col md:flex-row gap-3 md:gap-0 items-start md:items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="project_detail.php?id=<?= (int)$project_id ?>" class="text-gray-600 hover:text-indigo-700">
                    <i class="fas fa-arrow-left"></i> กลับหน้าโครงงาน
                </a>
                <h1 class="font-bold text-lg text-indigo-700">Rubric ประเมินโครงงาน</h1>
            </div>
            <span class="text-sm bg-indigo-100 text-indigo-700 px-3 py-1 rounded-full">
                <?= htmlspecialchars((string)$project['name']) ?>
            </span>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto p-4 md:p-8 space-y-6">
        <?php if ($status === 'saved'): ?>
            <div class="bg-green-50 text-green-700 border border-green-200 rounded-lg p-4">
                บันทึกผลประเมินเรียบร้อยแล้ว
            </div>
        <?php elseif ($status === 'csrf_invalid'): ?>
            <div class="bg-red-50 text-red-700 border border-red-200 rounded-lg p-4">
                โทเคนคำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="bg-red-50 text-red-700 border border-red-200 rounded-lg p-4">
                <?php foreach ($errors as $error): ?>
                    <div><?= htmlspecialchars((string)$error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="bg-white rounded-xl shadow p-5 lg:col-span-2">
                <h2 class="font-bold text-lg mb-4">สรุปโครงงาน</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div>
                        <div class="text-gray-500">หัวหน้าโครงงาน</div>
                        <div class="font-semibold"><?= htmlspecialchars((string)$project['leader_name']) ?></div>
                    </div>
                    <div>
                        <div class="text-gray-500">ความคืบหน้า</div>
                        <div class="font-semibold"><?= (int)$project['progress'] ?>%</div>
                    </div>
                    <div>
                        <div class="text-gray-500">อาจารย์ที่ปรึกษาหลัก</div>
                        <div class="font-semibold"><?= htmlspecialchars((string)($project['advisor_name'] ?? '-')) ?></div>
                    </div>
                    <div>
                        <div class="text-gray-500">อาจารย์ที่ปรึกษารอง</div>
                        <div class="font-semibold"><?= htmlspecialchars((string)($project['co_advisor_name'] ?? '-')) ?></div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow p-5">
                <h2 class="font-bold text-lg mb-4">สรุปผลล่าสุด</h2>
                <?php if (empty($evaluations)): ?>
                    <div class="text-gray-500 text-sm">ยังไม่มีผลประเมิน</div>
                <?php else: ?>
                    <?php $latest = $evaluations[0]; ?>
                    <?php
                        $latestPercent = ((float)$latest['max_score'] > 0)
                            ? round(((float)$latest['total_score'] / (float)$latest['max_score']) * 100, 2)
                            : 0.0;
                    ?>
                    <div class="text-3xl font-bold text-indigo-700">
                        <?= number_format((float)$latest['total_score'], 2) ?>/<?= number_format((float)$latest['max_score'], 2) ?>
                    </div>
                    <div class="text-sm text-gray-500 mt-1"><?= number_format($latestPercent, 2) ?>%</div>
                    <div class="mt-3">
                        <span class="text-xs px-2 py-1 rounded-full <?= $resultColorMap[$latest['result']] ?? 'bg-gray-100 text-gray-700' ?>">
                            <?= htmlspecialchars($resultMap[$latest['result']] ?? $latest['result']) ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($can_evaluate): ?>
            <section class="bg-white rounded-xl shadow p-5">
                <h2 class="font-bold text-lg mb-4">บันทึกผลประเมินใหม่</h2>
                <?php if (empty($criteria)): ?>
                    <div class="text-red-600 text-sm">ไม่พบเกณฑ์ประเมินในระบบ</div>
                <?php else: ?>
                    <form method="POST" class="space-y-5">
                        <?= csrfInputField() ?>
                        <input type="hidden" name="action" value="save_evaluation">

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-bold mb-1">รอบการประเมิน</label>
                                <input type="number" name="evaluation_round" min="1" max="10" value="<?= (int)($evaluations[0]['evaluation_round'] ?? 0) + 1 ?>" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-bold mb-1">ผลการประเมิน</label>
                                <select name="result" class="w-full border rounded px-3 py-2">
                                    <option value="pass">ผ่าน</option>
                                    <option value="revise">ต้องแก้ไข</option>
                                    <option value="fail">ไม่ผ่าน</option>
                                    <option value="pending">รอพิจารณา</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-bold mb-1">ผู้ประเมิน</label>
                                <div class="w-full border rounded px-3 py-2 bg-gray-50 text-gray-700 text-sm">
                                    <?= htmlspecialchars((string)($_SESSION['fullname'] ?? '')) ?>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <?php foreach ($criteria as $criterion): ?>
                                <div class="border rounded-lg p-4">
                                    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-2">
                                        <div>
                                            <div class="font-semibold text-gray-800"><?= htmlspecialchars((string)$criterion['title']) ?></div>
                                            <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)$criterion['description']) ?></div>
                                        </div>
                                        <div class="text-xs font-bold text-indigo-700">เต็ม <?= number_format((float)$criterion['max_score'], 2) ?></div>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                                        <div>
                                            <label class="block text-xs font-bold mb-1">คะแนน</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                max="<?= htmlspecialchars((string)$criterion['max_score']) ?>"
                                                name="score_<?= (int)$criterion['id'] ?>"
                                                required
                                                class="w-full border rounded px-3 py-2"
                                            >
                                        </div>
                                        <div>
                                            <label class="block text-xs font-bold mb-1">หมายเหตุ (ถ้ามี)</label>
                                            <input type="text" name="note_<?= (int)$criterion['id'] ?>" class="w-full border rounded px-3 py-2" placeholder="บันทึกข้อเสนอแนะหัวข้อนี้">
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div>
                            <label class="block text-sm font-bold mb-1">ข้อเสนอแนะรวม</label>
                            <textarea name="comment" rows="4" class="w-full border rounded px-3 py-2" placeholder="สรุปข้อเสนอแนะต่อโครงงาน"></textarea>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded font-bold">
                                <i class="fas fa-save mr-1"></i> บันทึกผลประเมิน
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (!empty($evaluations)): ?>
            <section class="bg-white rounded-xl shadow overflow-hidden">
                <div class="px-5 py-4 border-b font-bold">ประวัติผลประเมินล่าสุด</div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 border-b text-left">รอบ</th>
                                <th class="px-4 py-3 border-b text-left">ผู้ประเมิน</th>
                                <th class="px-4 py-3 border-b text-center">คะแนน</th>
                                <th class="px-4 py-3 border-b text-center">ผล</th>
                                <th class="px-4 py-3 border-b text-left">ข้อเสนอแนะ</th>
                                <th class="px-4 py-3 border-b text-left">วันที่</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($evaluations as $row): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 border-b"><?= (int)$row['evaluation_round'] ?></td>
                                    <td class="px-4 py-3 border-b"><?= htmlspecialchars((string)($row['evaluator_name'] ?? '-')) ?></td>
                                    <td class="px-4 py-3 border-b text-center">
                                        <?= number_format((float)$row['total_score'], 2) ?>/<?= number_format((float)$row['max_score'], 2) ?>
                                    </td>
                                    <td class="px-4 py-3 border-b text-center">
                                        <span class="text-xs px-2 py-1 rounded-full <?= $resultColorMap[$row['result']] ?? 'bg-gray-100 text-gray-700' ?>">
                                            <?= htmlspecialchars($resultMap[$row['result']] ?? $row['result']) ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 border-b"><?= nl2br(htmlspecialchars((string)($row['comment'] ?? '-'))) ?></td>
                                    <td class="px-4 py-3 border-b"><?= htmlspecialchars((string)$row['evaluated_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <?php if (!empty($latestEvaluationScores)): ?>
                <section class="bg-white rounded-xl shadow overflow-hidden">
                    <div class="px-5 py-4 border-b font-bold">รายละเอียดคะแนนของการประเมินล่าสุด</div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 border-b text-left">หัวข้อ</th>
                                    <th class="px-4 py-3 border-b text-center">คะแนนที่ได้</th>
                                    <th class="px-4 py-3 border-b text-center">คะแนนเต็ม</th>
                                    <th class="px-4 py-3 border-b text-left">หมายเหตุ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($latestEvaluationScores as $row): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 border-b"><?= htmlspecialchars((string)$row['title']) ?></td>
                                        <td class="px-4 py-3 border-b text-center font-semibold"><?= number_format((float)$row['score'], 2) ?></td>
                                        <td class="px-4 py-3 border-b text-center"><?= number_format((float)$row['max_score'], 2) ?></td>
                                        <td class="px-4 py-3 border-b"><?= htmlspecialchars((string)($row['note'] ?? '-')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
