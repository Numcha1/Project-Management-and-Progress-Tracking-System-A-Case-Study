<?php
// db_connect.php
$host = 'sql302.infinityfree.com';
$dbname = 'if0_41797513_rmutp';
$username = 'if0_41797513'; // หรือ username ของคุณ
$password = 'buwH4JfV6QCZqf6';     // หรือ password ของคุณ

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>