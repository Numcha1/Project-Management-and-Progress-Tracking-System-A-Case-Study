<?php

declare(strict_types=1);

if (!function_exists('tenantBaseConfig')) {
    function tenantBaseConfig(): array
    {
        $password = getenv('DB_PASS');
        if ($password === false) {
            $password = '';
        }

        return [
            'host' => (string)(getenv('DB_HOST') ?: '127.0.0.1'),
            'port' => (int)(getenv('DB_PORT') ?: 3306),
            'username' => (string)(getenv('DB_USER') ?: 'root'),
            'password' => (string)$password,
            'charset' => (string)(getenv('DB_CHARSET') ?: 'utf8mb4'),
            'default_db' => (string)(getenv('DB_NAME') ?: 'rmutp'),
            'core_db' => (string)(getenv('CORE_DB_NAME') ?: 'rmutp_core'),
            'tenant_mode' => strtolower((string)(getenv('TENANT_MODE') ?: 'single')),
            'default_tenant_code' => tenantNormalizeCode((string)(getenv('DEFAULT_TENANT_CODE') ?: '')),
        ];
    }
}

if (!function_exists('tenantNormalizeCode')) {
    function tenantNormalizeCode(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        return (string)preg_replace('/[^a-z0-9_-]/', '', $value);
    }
}

if (!function_exists('tenantQuoteIdentifier')) {
    function tenantQuoteIdentifier(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }
}

if (!function_exists('tenantCreatePdo')) {
    function tenantCreatePdo(?string $databaseName = null): PDO
    {
        $cfg = tenantBaseConfig();
        $dsn = 'mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'];
        if ($databaseName !== null && $databaseName !== '') {
            $dsn .= ';dbname=' . $databaseName;
        }
        $dsn .= ';charset=' . $cfg['charset'];

        return new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}

if (!function_exists('tenantCoreConnection')) {
    function tenantCoreConnection(): PDO
    {
        static $corePdo = null;
        if ($corePdo instanceof PDO) {
            return $corePdo;
        }

        $cfg = tenantBaseConfig();
        $corePdo = tenantCreatePdo($cfg['core_db']);
        return $corePdo;
    }
}

if (!function_exists('tenantEnsureCoreDatabaseExists')) {
    function tenantEnsureCoreDatabaseExists(): void
    {
        $cfg = tenantBaseConfig();
        $serverPdo = tenantCreatePdo(null);
        $serverPdo->exec(
            'CREATE DATABASE IF NOT EXISTS ' . tenantQuoteIdentifier($cfg['core_db']) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    }
}

if (!function_exists('tenantEnsureCoreTables')) {
    function tenantEnsureCoreTables(PDO $corePdo): void
    {
        $corePdo->exec("
            CREATE TABLE IF NOT EXISTS faculties (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(40) NOT NULL,
                name_th VARCHAR(180) NOT NULL,
                name_en VARCHAR(180) DEFAULT NULL,
                tenant_db_name VARCHAR(120) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_faculties_code (code),
                UNIQUE KEY uq_faculties_tenant_db_name (tenant_db_name),
                KEY idx_faculties_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $corePdo->exec("
            CREATE TABLE IF NOT EXISTS user_import_jobs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                faculty_id INT UNSIGNED NOT NULL,
                source_file VARCHAR(255) NOT NULL,
                total_rows INT UNSIGNED NOT NULL DEFAULT 0,
                inserted_rows INT UNSIGNED NOT NULL DEFAULT 0,
                updated_rows INT UNSIGNED NOT NULL DEFAULT 0,
                failed_rows INT UNSIGNED NOT NULL DEFAULT 0,
                status ENUM('running', 'completed', 'failed') NOT NULL DEFAULT 'running',
                started_by INT UNSIGNED DEFAULT NULL,
                note VARCHAR(255) DEFAULT NULL,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                finished_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_user_import_jobs_faculty_id (faculty_id),
                KEY idx_user_import_jobs_status (status),
                CONSTRAINT fk_user_import_jobs_faculty
                    FOREIGN KEY (faculty_id) REFERENCES faculties(id)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $corePdo->exec("
            CREATE TABLE IF NOT EXISTS user_import_job_rows (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                job_id BIGINT UNSIGNED NOT NULL,
                row_number INT UNSIGNED NOT NULL,
                email VARCHAR(191) DEFAULT NULL,
                action_result ENUM('inserted', 'updated', 'failed', 'skipped') NOT NULL DEFAULT 'skipped',
                message VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user_import_job_rows_job_id (job_id),
                KEY idx_user_import_job_rows_result (action_result),
                CONSTRAINT fk_user_import_job_rows_job
                    FOREIGN KEY (job_id) REFERENCES user_import_jobs(id)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('tenantFetchActiveFaculties')) {
    function tenantFetchActiveFaculties(PDO $corePdo): array
    {
        $stmt = $corePdo->query("
            SELECT id, code, name_th, name_en, tenant_db_name
            FROM faculties
            WHERE is_active = 1
            ORDER BY name_th ASC, code ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('tenantFindFacultyByCode')) {
    function tenantFindFacultyByCode(PDO $corePdo, string $facultyCode): ?array
    {
        $facultyCode = tenantNormalizeCode($facultyCode);
        if ($facultyCode === '') {
            return null;
        }

        $stmt = $corePdo->prepare("
            SELECT id, code, name_th, name_en, tenant_db_name, is_active
            FROM faculties
            WHERE code = ?
            LIMIT 1
        ");
        $stmt->execute([$facultyCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('tenantResolveRuntimeContext')) {
    function tenantResolveRuntimeContext(): array
    {
        $cfg = tenantBaseConfig();
        $context = [
            'tenant_mode' => $cfg['tenant_mode'],
            'tenant_code' => '',
            'tenant_name' => 'Default',
            'database' => $cfg['default_db'],
            'source' => 'default',
        ];

        if ($cfg['tenant_mode'] !== 'multi') {
            return $context;
        }

        $candidate = '';
        if (PHP_SAPI !== 'cli') {
            $postTenant = $_POST['tenant'] ?? '';
            $getTenant = $_GET['tenant'] ?? '';
            if (is_string($postTenant) && trim($postTenant) !== '') {
                $candidate = $postTenant;
                $context['source'] = 'request-post';
            } elseif (is_string($getTenant) && trim($getTenant) !== '') {
                $candidate = $getTenant;
                $context['source'] = 'request-get';
            }
        }

        if ($candidate === '' && session_status() === PHP_SESSION_ACTIVE) {
            $sessionTenant = $_SESSION['tenant_code'] ?? '';
            if (is_string($sessionTenant) && trim($sessionTenant) !== '') {
                $candidate = $sessionTenant;
                $context['source'] = 'session';
            }
        }

        if ($candidate === '' && $cfg['default_tenant_code'] !== '') {
            $candidate = $cfg['default_tenant_code'];
            $context['source'] = 'env-default';
        }

        $candidate = tenantNormalizeCode((string)$candidate);
        if ($candidate === '') {
            return $context;
        }

        try {
            $corePdo = tenantCoreConnection();
            tenantEnsureCoreTables($corePdo);
            $faculty = tenantFindFacultyByCode($corePdo, $candidate);
            if (!$faculty || (int)($faculty['is_active'] ?? 0) !== 1) {
                return $context;
            }

            $tenantDb = trim((string)($faculty['tenant_db_name'] ?? ''));
            if ($tenantDb === '') {
                return $context;
            }

            $context['tenant_code'] = (string)$faculty['code'];
            $context['tenant_name'] = (string)($faculty['name_th'] ?: $faculty['name_en'] ?: $faculty['code']);
            $context['database'] = $tenantDb;

            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['tenant_code'] = $context['tenant_code'];
                $_SESSION['tenant_name'] = $context['tenant_name'];
            }
        } catch (Throwable $e) {
            return $context;
        }

        return $context;
    }
}

if (!function_exists('tenantRuntimeContext')) {
    function tenantRuntimeContext(): array
    {
        $ctx = $GLOBALS['tenant_context'] ?? null;
        if (is_array($ctx)) {
            return $ctx;
        }
        return tenantResolveRuntimeContext();
    }
}

if (!function_exists('splitSqlStatementsRaw')) {
    function splitSqlStatementsRaw(string $sql): array
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
}

if (!function_exists('applySqlFileToConnection')) {
    function applySqlFileToConnection(PDO $pdo, string $filePath): int
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('SQL file not found: ' . $filePath);
        }

        $sql = file_get_contents($filePath);
        if ($sql === false) {
            throw new RuntimeException('Cannot read SQL file: ' . $filePath);
        }
        if (strncmp($sql, "\xEF\xBB\xBF", 3) === 0) {
            $sql = substr($sql, 3);
        }

        $statements = splitSqlStatementsRaw($sql);
        $count = 0;
        foreach ($statements as $statement) {
            $pdo->exec($statement);
            $count++;
        }
        return $count;
    }
}

if (!function_exists('tenantResolveDbNameByCode')) {
    function tenantResolveDbNameByCode(PDO $corePdo, string $facultyCode): ?string
    {
        $faculty = tenantFindFacultyByCode($corePdo, $facultyCode);
        if (!$faculty) {
            return null;
        }
        if ((int)($faculty['is_active'] ?? 0) !== 1) {
            return null;
        }
        $dbName = trim((string)($faculty['tenant_db_name'] ?? ''));
        return $dbName !== '' ? $dbName : null;
    }
}

if (!function_exists('tenantUpsertFacultyRecord')) {
    function tenantUpsertFacultyRecord(PDO $corePdo, string $facultyCode, string $facultyName, string $tenantDbName): int
    {
        $facultyCode = tenantNormalizeCode($facultyCode);
        if ($facultyCode === '') {
            throw new InvalidArgumentException('Invalid faculty code.');
        }
        $tenantDbName = trim($tenantDbName);
        if ($tenantDbName === '') {
            throw new InvalidArgumentException('Tenant database name is required.');
        }
        $facultyName = trim($facultyName);
        if ($facultyName === '') {
            $facultyName = strtoupper($facultyCode);
        }

        $stmt = $corePdo->prepare("
            INSERT INTO faculties (code, name_th, tenant_db_name, is_active, created_at, updated_at)
            VALUES (?, ?, ?, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                name_th = VALUES(name_th),
                tenant_db_name = VALUES(tenant_db_name),
                is_active = 1,
                updated_at = NOW()
        ");
        $stmt->execute([$facultyCode, $facultyName, $tenantDbName]);

        $lookup = $corePdo->prepare("SELECT id FROM faculties WHERE code = ? LIMIT 1");
        $lookup->execute([$facultyCode]);
        $facultyId = (int)$lookup->fetchColumn();
        if ($facultyId <= 0) {
            throw new RuntimeException('Unable to resolve faculty id after upsert.');
        }
        return $facultyId;
    }
}
