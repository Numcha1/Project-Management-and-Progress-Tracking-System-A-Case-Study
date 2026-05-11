<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden: buildDatabase can only run from CLI';
    exit(1);
}

/**
 * @return list<string>
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $length = strlen($sql);
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';
        $prev = $i > 0 ? $sql[$i - 1] : '';

        if ($inLineComment) {
            if ($char === "\n") {
                $inLineComment = false;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($char === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if (!$inSingle && !$inDouble && !$inBacktick) {
            if ($char === '-' && $next === '-') {
                $inLineComment = true;
                $i++;
                continue;
            }
            if ($char === '#') {
                $inLineComment = true;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
        }

        if ($char === "'" && !$inDouble && !$inBacktick) {
            if ($inSingle && $next === "'") {
                $buffer .= "''";
                $i++;
                continue;
            }
            if ($prev !== '\\') {
                $inSingle = !$inSingle;
            }
            $buffer .= $char;
            continue;
        }

        if ($char === '"' && !$inSingle && !$inBacktick) {
            if ($inDouble && $next === '"') {
                $buffer .= '""';
                $i++;
                continue;
            }
            if ($prev !== '\\') {
                $inDouble = !$inDouble;
            }
            $buffer .= $char;
            continue;
        }

        if ($char === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
            $buffer .= $char;
            continue;
        }

        if ($char === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $statement = trim($buffer);
    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}

function quoteIdentifier(string $value): string
{
    return '`' . str_replace('`', '``', $value) . '`';
}

function applySqlFile(PDO $pdo, string $filePath, string $label): int
{
    if (!is_file($filePath)) {
        throw new RuntimeException("Missing {$label} file: {$filePath}");
    }

    $rawSql = file_get_contents($filePath);
    if ($rawSql === false) {
        throw new RuntimeException("Unable to read {$label} file: {$filePath}");
    }
    if (strncmp($rawSql, "\xEF\xBB\xBF", 3) === 0) {
        $rawSql = substr($rawSql, 3);
    }

    $statements = splitSqlStatements($rawSql);
    $applied = 0;

    foreach ($statements as $statement) {
        $pdo->exec($statement);
        $applied++;
    }

    return $applied;
}

$options = getopt('', [
    'host::',
    'port::',
    'database::',
    'user::',
    'password::',
    'sql::',
]);

$projectRoot = dirname(__DIR__, 4);
$host = (string)($options['host'] ?? getenv('DB_HOST') ?: '127.0.0.1');
$port = (int)($options['port'] ?? getenv('DB_PORT') ?: 3306);
$database = (string)($options['database'] ?? getenv('DB_NAME') ?: 'rmutp');
$username = (string)($options['user'] ?? getenv('DB_USER') ?: 'root');
$password = $options['password'] ?? getenv('DB_PASS');
if ($password === false || $password === null) {
    $password = '';
}
$sqlPath = (string)($options['sql'] ?? ($projectRoot . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'rmutp_database.sql'));

try {
    $serverDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
    $serverPdo = new PDO($serverDsn, $username, (string)$password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $serverPdo->exec(
        'CREATE DATABASE IF NOT EXISTS ' . quoteIdentifier($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $dbDsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
    $pdo = new PDO($dbDsn, $username, (string)$password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo '[1/2] Database ready: ' . $database . PHP_EOL;
    $appliedCount = applySqlFile($pdo, $sqlPath, 'sql');
    echo '[2/2] SQL applied statements: ' . $appliedCount . PHP_EOL;

    echo 'Database setup completed successfully.' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Database setup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
