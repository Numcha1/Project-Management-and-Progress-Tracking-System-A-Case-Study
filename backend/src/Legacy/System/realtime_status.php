<?php
session_start();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/app_helpers.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'unauthorized',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? '');
$userFullname = (string)($_SESSION['fullname'] ?? '');

if (!in_array($role, ['student', 'teacher', 'admin'], true)) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'forbidden',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $status = buildRealtimeStatus($conn, $userId, $role, $userFullname);
    $status['tenant'] = tenantRuntimeContext();
    echo json_encode([
        'ok' => true,
        'data' => $status,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
    ], JSON_UNESCAPED_UNICODE);
}
