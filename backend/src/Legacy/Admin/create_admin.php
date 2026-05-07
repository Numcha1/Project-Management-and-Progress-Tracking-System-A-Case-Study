<?php
// create_admin.php
require_once __DIR__ . '/../System/db_connect.php';

// ตั้งค่าข้อมูล Admin ที่ต้องการ
$fullname = "Super Admin";
$email = "admin@rmutp.ac.th";
$password = "admin1234"; // รหัสผ่านที่ต้องการ
$role = "admin";

// 1. ตรวจสอบก่อนว่ามี email นี้หรือยัง
$check = $conn->prepare("SELECT id FROM users WHERE email = ?");
$check->execute([$email]);

if ($check->rowCount() > 0) {
    echo "<h3>มีบัญชี $email อยู่แล้ว ไม่สามารถสร้างซ้ำได้</h3>";
} else {
    // 2. เข้ารหัสรหัสผ่าน (สำคัญมาก! ต้องใช้ password_hash)
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // 3. บันทึกลงฐานข้อมูล
    $sql = "INSERT INTO users (fullname, email, password, role) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    
    if ($stmt->execute([$fullname, $email, $hashed_password, $role])) {
        echo "<h3 style='color: green;'>สร้างบัญชี Admin สำเร็จ!</h3>";
        echo "<p>Email: <b>$email</b></p>";
        echo "<p>Password: <b>$password</b></p>";
        echo "<p><a href='login.php'>คลิกที่นี่เพื่อไปหน้าเข้าสู่ระบบ</a></p>";
    } else {
        echo "เกิดข้อผิดพลาดในการสร้าง";
    }
}
?>