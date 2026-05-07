<?php
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
header('Location: ' . $basePath . '/frontend/public/teacher_dashboard.php');
exit;