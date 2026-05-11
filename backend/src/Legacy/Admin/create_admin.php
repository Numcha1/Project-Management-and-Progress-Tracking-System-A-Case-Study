<?php
if (PHP_SAPI === 'cli') {
    require __DIR__ . '/buildAdmin.php';
    exit;
}

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

header('Location: admin_dashboard.php');
exit;
