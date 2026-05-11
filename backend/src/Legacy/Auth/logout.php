<?php
session_start();
$logoutUserId = (int)($_SESSION['user_id'] ?? 0);
if ($logoutUserId > 0) {
    require_once __DIR__ . '/../System/db_connect.php';
    require_once __DIR__ . '/../System/app_helpers.php';
    writeAuditLog($conn, $logoutUserId, 'auth.logout', 'logout', 'auth', $logoutUserId);
}
session_destroy();
header("Location: login.php");
exit;
?>
