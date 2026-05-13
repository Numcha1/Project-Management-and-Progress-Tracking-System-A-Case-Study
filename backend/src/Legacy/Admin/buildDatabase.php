<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden: buildDatabase can only run from CLI';
    exit(1);
}

require_once __DIR__ . '/../System/tenant_helpers.php';

$options = getopt('', [
    'mode::',
    'host::',
    'port::',
    'database::',
    'user::',
    'password::',
    'sql::',
    'core-sql::',
    'faculty::',
    'faculty-name::',
    'tenant-db::',
]);

$projectRoot = dirname(__DIR__, 4);
$tenantSqlPath = (string)($options['sql'] ?? ($projectRoot . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'rmutp_database.sql'));
$coreSqlPath = (string)($options['core-sql'] ?? ($projectRoot . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'rmutp_core_database.sql'));
$mode = strtolower(trim((string)($options['mode'] ?? 'single')));
if ($mode === '') {
    $mode = 'single';
}

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
if (isset($options['database'])) {
    putenv('DB_NAME=' . (string)$options['database']);
}

$cfg = tenantBaseConfig();

$createDatabaseIfNotExists = static function (string $dbName) use ($cfg): void {
    $serverPdo = tenantCreatePdo(null);
    $serverPdo->exec(
        'CREATE DATABASE IF NOT EXISTS ' . tenantQuoteIdentifier($dbName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
};

$applySqlToDatabase = static function (string $dbName, string $sqlPath) use ($createDatabaseIfNotExists): int {
    $createDatabaseIfNotExists($dbName);
    $pdo = tenantCreatePdo($dbName);
    return applySqlFileToConnection($pdo, $sqlPath);
};

try {
    if ($mode === 'core') {
        tenantEnsureCoreDatabaseExists();
        $applied = $applySqlToDatabase($cfg['core_db'], $coreSqlPath);
        echo '[core] Database ready: ' . $cfg['core_db'] . PHP_EOL;
        echo '[core] Applied statements: ' . $applied . PHP_EOL;
        echo 'Core database setup completed successfully.' . PHP_EOL;
        exit(0);
    }

    if ($mode === 'tenant') {
        $facultyCode = tenantNormalizeCode((string)($options['faculty'] ?? ''));
        if ($facultyCode === '') {
            throw new InvalidArgumentException('Missing required option: --faculty=<code>');
        }

        tenantEnsureCoreDatabaseExists();
        $coreApplied = $applySqlToDatabase($cfg['core_db'], $coreSqlPath);
        $corePdo = tenantCreatePdo($cfg['core_db']);
        tenantEnsureCoreTables($corePdo);

        $tenantDbNameRaw = trim((string)($options['tenant-db'] ?? ''));
        if ($tenantDbNameRaw === '') {
            $tenantDbNameRaw = trim((string)($options['database'] ?? ''));
        }
        if ($tenantDbNameRaw === '') {
            $tenantDbNameRaw = 'rmutp_' . $facultyCode;
        }

        $facultyName = trim((string)($options['faculty-name'] ?? ''));
        if ($facultyName === '') {
            $facultyName = strtoupper($facultyCode);
        }

        $facultyId = tenantUpsertFacultyRecord($corePdo, $facultyCode, $facultyName, $tenantDbNameRaw);
        $tenantApplied = $applySqlToDatabase($tenantDbNameRaw, $tenantSqlPath);

        echo '[tenant] Core database: ' . $cfg['core_db'] . PHP_EOL;
        echo '[tenant] Core statements applied: ' . $coreApplied . PHP_EOL;
        echo '[tenant] Faculty upserted: #' . $facultyId . ' (' . $facultyCode . ')' . PHP_EOL;
        echo '[tenant] Tenant database ready: ' . $tenantDbNameRaw . PHP_EOL;
        echo '[tenant] Tenant statements applied: ' . $tenantApplied . PHP_EOL;
        echo 'Tenant provisioning completed successfully.' . PHP_EOL;
        exit(0);
    }

    if ($mode === 'upgrade-all-tenants') {
        tenantEnsureCoreDatabaseExists();
        $coreApplied = $applySqlToDatabase($cfg['core_db'], $coreSqlPath);
        $corePdo = tenantCreatePdo($cfg['core_db']);
        tenantEnsureCoreTables($corePdo);
        $faculties = tenantFetchActiveFaculties($corePdo);

        echo '[upgrade-all-tenants] Core database: ' . $cfg['core_db'] . PHP_EOL;
        echo '[upgrade-all-tenants] Core statements applied: ' . $coreApplied . PHP_EOL;

        $upgraded = 0;
        foreach ($faculties as $faculty) {
            $code = (string)($faculty['code'] ?? '');
            $dbName = (string)($faculty['tenant_db_name'] ?? '');
            if ($code === '' || $dbName === '') {
                continue;
            }

            $applied = $applySqlToDatabase($dbName, $tenantSqlPath);
            $upgraded++;
            echo '  - [' . $code . '] ' . $dbName . ': ' . $applied . ' statements' . PHP_EOL;
        }

        echo '[upgrade-all-tenants] Upgraded tenants: ' . $upgraded . PHP_EOL;
        echo 'Tenant upgrade completed successfully.' . PHP_EOL;
        exit(0);
    }

    if (!in_array($mode, ['single', 'legacy'], true)) {
        throw new InvalidArgumentException('Unsupported mode: ' . $mode);
    }

    $targetDb = (string)($options['database'] ?? $cfg['default_db']);
    $applied = $applySqlToDatabase($targetDb, $tenantSqlPath);
    echo '[single] Database ready: ' . $targetDb . PHP_EOL;
    echo '[single] SQL applied statements: ' . $applied . PHP_EOL;
    echo 'Database setup completed successfully.' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Database setup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

