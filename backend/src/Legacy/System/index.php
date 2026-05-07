<?php
session_start();

// ถ้ายังไม่ล็อกอิน ให้ไปหน้า Login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// ถ้าล็อกอินแล้ว เช็คบทบาทเพื่อส่งไปหน้าที่ถูกต้อง
$role = $_SESSION['role'];

if ($role == 'student') {
    header("Location: student_dashboard.php");
} elseif ($role == 'teacher') {
    header("Location: teacher_dashboard.php");
} elseif ($role == 'admin') {
    header("Location: admin_dashboard.php");
} else {
    echo "ไม่พบสิทธิ์การเข้าใช้งาน";
}
exit;
?>