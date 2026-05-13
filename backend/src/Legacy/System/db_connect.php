<?php

require_once __DIR__ . '/tenant_helpers.php';

$cfg = tenantBaseConfig();
$tenantContext = tenantResolveRuntimeContext();
$dbname = (string)($tenantContext['database'] ?? $cfg['default_db']);

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        (int)$cfg['port'],
        $dbname,
        $cfg['charset']
    );

    $conn = new PDO($dsn, $cfg['username'], $cfg['password']);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $GLOBALS['tenant_context'] = [
        'tenant_mode' => $tenantContext['tenant_mode'] ?? $cfg['tenant_mode'],
        'tenant_code' => $tenantContext['tenant_code'] ?? '',
        'tenant_name' => $tenantContext['tenant_name'] ?? 'Default',
        'database' => $dbname,
        'source' => $tenantContext['source'] ?? 'default',
    ];
} catch (PDOException $e) {
    die('Connection failed: ' . $e->getMessage());
}

