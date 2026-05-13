<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden: runWorker can only run from CLI';
    exit(1);
}

require_once __DIR__ . '/../System/db_connect.php';
require_once __DIR__ . '/../System/app_helpers.php';
require_once __DIR__ . '/../System/ops_helpers.php';

$options = getopt('', [
    'help',
    'worker-id::',
    'job-type::',
    'limit::',
    'actor-id::',
    'schedule-recurring',
    'loop',
    'interval-seconds::',
    'max-loops::',
]);

$showUsage = static function (): void {
    echo 'RMUTP Worker CLI' . PHP_EOL;
    echo 'Usage:' . PHP_EOL;
    echo '  php backend/src/Legacy/Admin/runWorker.php [options]' . PHP_EOL;
    echo PHP_EOL;
    echo 'Options:' . PHP_EOL;
    echo '  --worker-id=<id>            Worker identifier (default auto-generated)' . PHP_EOL;
    echo '  --job-type=<type>           deadline_reminder|auto_backup|retention_cleanup|recalculate_progress' . PHP_EOL;
    echo '  --limit=<n>                 Max jobs per run (default 10, max 500)' . PHP_EOL;
    echo '  --actor-id=<user_id>        Audit actor id for worker-triggered actions' . PHP_EOL;
    echo '  --schedule-recurring        Enqueue recurring jobs before processing queue' . PHP_EOL;
    echo '  --loop                      Keep running in a loop' . PHP_EOL;
    echo '  --interval-seconds=<n>      Sleep seconds between loop runs (default 15)' . PHP_EOL;
    echo '  --max-loops=<n>             Stop after N loops (0 = unlimited, default 0)' . PHP_EOL;
    echo '  --help                      Show this help text' . PHP_EOL;
};

if (array_key_exists('help', $options)) {
    $showUsage();
    exit(0);
}

$workerId = trim((string)($options['worker-id'] ?? ''));
$jobType = trim((string)($options['job-type'] ?? ''));
$limit = (int)($options['limit'] ?? 10);
$actorId = isset($options['actor-id']) ? (int)$options['actor-id'] : null;
$scheduleRecurring = array_key_exists('schedule-recurring', $options);
$loop = array_key_exists('loop', $options);
$intervalSeconds = (int)($options['interval-seconds'] ?? 15);
$maxLoops = (int)($options['max-loops'] ?? 0);

if ($intervalSeconds < 1) {
    $intervalSeconds = 1;
}
if ($intervalSeconds > 3600) {
    $intervalSeconds = 3600;
}
if ($maxLoops < 0) {
    $maxLoops = 0;
}

$allowedJobTypes = [
    'deadline_reminder',
    'auto_backup',
    'retention_cleanup',
    'recalculate_progress',
];
if ($jobType !== '' && !in_array($jobType, $allowedJobTypes, true)) {
    fwrite(STDERR, 'Invalid --job-type. Allowed: ' . implode(', ', $allowedJobTypes) . PHP_EOL);
    exit(1);
}

if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}

$loopCount = 0;
$exitCode = 0;

while (true) {
    $loopCount++;

    try {
        $result = opsRunWorker($conn, [
            'worker_id' => $workerId !== '' ? $workerId : null,
            'job_type' => $jobType !== '' ? $jobType : null,
            'limit' => $limit,
            'actor_id' => $actorId,
            'schedule_recurring' => $scheduleRecurring,
        ]);

        $stamp = date('Y-m-d H:i:s');
        echo sprintf(
            '[%s] worker=%s processed=%d done=%d failed=%d',
            $stamp,
            (string)($result['worker_id'] ?? '-'),
            (int)($result['processed'] ?? 0),
            (int)($result['done'] ?? 0),
            (int)($result['failed'] ?? 0)
        ) . PHP_EOL;

        $errors = $result['errors'] ?? [];
        if (is_array($errors) && !empty($errors)) {
            foreach ($errors as $error) {
                $jobId = (int)($error['job_id'] ?? 0);
                $message = (string)($error['message'] ?? 'unknown error');
                echo '  - job #' . $jobId . ': ' . $message . PHP_EOL;
            }
            $exitCode = 2;
        }
    } catch (Throwable $e) {
        fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
        $exitCode = 1;
    }

    if (!$loop) {
        break;
    }
    if ($maxLoops > 0 && $loopCount >= $maxLoops) {
        break;
    }
    sleep($intervalSeconds);
}

exit($exitCode);

