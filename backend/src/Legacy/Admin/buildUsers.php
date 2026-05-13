<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden: buildUsers can only run from CLI';
    exit(1);
}

require_once __DIR__ . '/../System/tenant_helpers.php';

$options = getopt('', [
    'faculty:',
    'csv:',
    'upsert',
    'host::',
    'port::',
    'user::',
    'password::',
]);

if (isset($options['host'])) {
    putenv('DB_HOST=' . (string)$options['host']);
}
if (isset($options['port'])) {
    putenv('DB_PORT=' . (string)$options['port']);
}
if (isset($options['user'])) {
    putenv('DB_USER=' . (string)$options['user']);
}
if (array_key_exists('password', $options)) {
    putenv('DB_PASS=' . (string)$options['password']);
}

$facultyCode = tenantNormalizeCode((string)($options['faculty'] ?? ''));
$csvPath = trim((string)($options['csv'] ?? ''));
$upsert = array_key_exists('upsert', $options);

if ($facultyCode === '') {
    fwrite(STDERR, "Missing required option: --faculty=<code>" . PHP_EOL);
    exit(1);
}
if ($csvPath === '') {
    fwrite(STDERR, "Missing required option: --csv=<path>" . PHP_EOL);
    exit(1);
}
if (!is_file($csvPath)) {
    fwrite(STDERR, "CSV file not found: {$csvPath}" . PHP_EOL);
    exit(1);
}

try {
    tenantEnsureCoreDatabaseExists();
    $core = tenantCoreConnection();
    tenantEnsureCoreTables($core);

    $faculty = tenantFindFacultyByCode($core, $facultyCode);
    if (!$faculty || (int)($faculty['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('Unknown or inactive faculty: ' . $facultyCode);
    }

    $tenantDbName = trim((string)($faculty['tenant_db_name'] ?? ''));
    if ($tenantDbName === '') {
        throw new RuntimeException('Faculty has no tenant database mapping.');
    }

    $tenant = tenantCreatePdo($tenantDbName);
    $fh = fopen($csvPath, 'rb');
    if ($fh === false) {
        throw new RuntimeException('Cannot open CSV file.');
    }

    $headerRaw = fgetcsv($fh);
    if (!is_array($headerRaw) || count($headerRaw) === 0) {
        fclose($fh);
        throw new RuntimeException('CSV header is missing.');
    }

    $header = array_map(static function ($value): string {
        return strtolower(trim((string)$value));
    }, $headerRaw);
    $headerMap = array_flip($header);

    $requiredColumns = ['fullname', 'email', 'role'];
    foreach ($requiredColumns as $column) {
        if (!array_key_exists($column, $headerMap)) {
            fclose($fh);
            throw new RuntimeException('CSV header missing required column: ' . $column);
        }
    }

    $sourceFile = basename($csvPath);
    $startJobStmt = $core->prepare("
        INSERT INTO user_import_jobs (faculty_id, source_file, status, started_at)
        VALUES (?, ?, 'running', NOW())
    ");
    $startJobStmt->execute([(int)$faculty['id'], $sourceFile]);
    $jobId = (int)$core->lastInsertId();

    $inserted = 0;
    $updated = 0;
    $failed = 0;
    $total = 0;
    $lineNo = 1;

    $roleSet = ['student', 'teacher', 'admin'];
    $logRowStmt = $core->prepare("
        INSERT INTO user_import_job_rows (job_id, row_number, email, action_result, message, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");

    while (($row = fgetcsv($fh)) !== false) {
        $lineNo++;
        if (!is_array($row)) {
            continue;
        }

        $total++;
        $fullname = trim((string)($row[$headerMap['fullname']] ?? ''));
        $email = strtolower(trim((string)($row[$headerMap['email']] ?? '')));
        $role = strtolower(trim((string)($row[$headerMap['role']] ?? '')));
        $studentCode = trim((string)($row[$headerMap['student_code']] ?? ''));
        $plainPassword = trim((string)($row[$headerMap['password']] ?? ''));
        if ($studentCode === '') {
            $studentCode = null;
        }

        $result = 'failed';
        $message = '';
        try {
            if ($fullname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid fullname or email.');
            }
            if (!in_array($role, $roleSet, true)) {
                throw new RuntimeException('Invalid role. Allowed: student, teacher, admin');
            }

            $existingStmt = $tenant->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $existingStmt->execute([$email]);
            $existingId = (int)$existingStmt->fetchColumn();

            if ($existingId > 0) {
                if (!$upsert) {
                    $result = 'skipped';
                    $message = 'Email already exists (use --upsert to update).';
                } else {
                    if ($plainPassword !== '' && strlen($plainPassword) < 10) {
                        throw new RuntimeException('Password must be at least 10 characters for upsert.');
                    }

                    if ($plainPassword !== '') {
                        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
                        $updateStmt = $tenant->prepare("
                            UPDATE users
                            SET fullname = ?, role = ?, student_code = ?, password = ?, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $updateStmt->execute([$fullname, $role, $studentCode, $hash, $existingId]);
                    } else {
                        $updateStmt = $tenant->prepare("
                            UPDATE users
                            SET fullname = ?, role = ?, student_code = ?, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $updateStmt->execute([$fullname, $role, $studentCode, $existingId]);
                    }

                    $updated++;
                    $result = 'updated';
                    $message = 'Updated existing user #' . $existingId;
                }
            } else {
                if ($plainPassword !== '' && strlen($plainPassword) < 10) {
                    throw new RuntimeException('Password must be at least 10 characters.');
                }
                if ($plainPassword === '') {
                    $plainPassword = bin2hex(random_bytes(8)) . 'A!9';
                }
                $hash = password_hash($plainPassword, PASSWORD_DEFAULT);

                $insertStmt = $tenant->prepare("
                    INSERT INTO users (fullname, student_code, email, password, role, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $insertStmt->execute([$fullname, $studentCode, $email, $hash, $role]);
                $newId = (int)$tenant->lastInsertId();

                $inserted++;
                $result = 'inserted';
                $message = 'Created user #' . $newId;
            }
        } catch (Throwable $rowError) {
            $failed++;
            $result = 'failed';
            $message = $rowError->getMessage();
        }

        $logRowStmt->execute([
            $jobId,
            $lineNo,
            $email !== '' ? $email : null,
            $result,
            substr($message, 0, 250),
        ]);
    }

    fclose($fh);

    $finalStatus = $failed > 0 ? 'failed' : 'completed';
    $finalNote = sprintf(
        'upsert=%s, tenant_db=%s',
        $upsert ? 'yes' : 'no',
        $tenantDbName
    );
    $finishStmt = $core->prepare("
        UPDATE user_import_jobs
        SET total_rows = ?, inserted_rows = ?, updated_rows = ?, failed_rows = ?, status = ?, note = ?, finished_at = NOW()
        WHERE id = ?
    ");
    $finishStmt->execute([$total, $inserted, $updated, $failed, $finalStatus, $finalNote, $jobId]);

    echo 'Faculty: ' . $facultyCode . ' (' . $tenantDbName . ')' . PHP_EOL;
    echo 'CSV: ' . $csvPath . PHP_EOL;
    echo 'Job ID: ' . $jobId . PHP_EOL;
    echo 'Total: ' . $total . PHP_EOL;
    echo 'Inserted: ' . $inserted . PHP_EOL;
    echo 'Updated: ' . $updated . PHP_EOL;
    echo 'Failed: ' . $failed . PHP_EOL;
    echo 'Status: ' . strtoupper($finalStatus) . PHP_EOL;
    exit($failed > 0 ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'User import failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
