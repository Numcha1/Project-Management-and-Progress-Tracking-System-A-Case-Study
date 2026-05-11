<?php
// db_connect.php
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('DB_PORT') ?: 3306);
$dbname = getenv('DB_NAME') ?: 'rmutp';
$username = getenv('DB_USER') ?: 'root'; // หรือ username ของคุณ
$password = getenv('DB_PASS');
if ($password === false) {
    $password = ''; // หรือ password ของคุณ
}
$charset = getenv('DB_CHARSET') ?: 'utf8mb4';

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $host,
        $port,
        $dbname,
        $charset
    );

    $conn = new PDO($dsn, $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Connection failed: ' . $e->getMessage());
}
?>
