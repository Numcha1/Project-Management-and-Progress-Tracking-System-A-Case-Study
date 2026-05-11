<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden: buildAdmin can only run from CLI';
    exit;
}

require_once __DIR__ . '/../System/db_connect.php';
require_once __DIR__ . '/../System/app_helpers.php';

$messages = [];
$status = 'error';

$options = getopt('', ['email::', 'password::', 'name::']);
$fullname = trim((string)($options['name'] ?? getenv('ADMIN_FULLNAME') ?: 'Super Admin'));
$email = trim((string)($options['email'] ?? getenv('ADMIN_EMAIL') ?: 'admin@rmutp.ac.th'));
$password = trim((string)($options['password'] ?? getenv('ADMIN_PASSWORD') ?: ''));
$role = 'admin';
$studentCode = null;

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid admin email: {$email}" . PHP_EOL);
    exit(1);
}

if ($password === '') {
    $password = bin2hex(random_bytes(8)) . 'A!9';
    $messages[] = 'No password provided; generated a strong password automatically.';
}

if (strlen($password) < 10) {
    fwrite(STDERR, 'Password must be at least 10 characters.' . PHP_EOL);
    exit(1);
}

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

$check = $conn->prepare('SELECT id FROM users WHERE email = ?');
$check->execute([$email]);
$existingId = $check->fetchColumn();

if ($existingId) {
    $sql = 'UPDATE users SET fullname = ?, password = ?, role = ?, student_code = ? WHERE id = ?';
    $stmt = $conn->prepare($sql);

    if ($stmt->execute([$fullname, $hashedPassword, $role, $studentCode, $existingId])) {
        ensureAdminPermissionRow($conn, (int)$existingId);
        $status = 'updated';
        $messages[] = 'Admin account updated successfully.';
    } else {
        $messages[] = 'Failed to update admin account.';
    }
} else {
    $sql = 'INSERT INTO users (fullname, email, password, role, student_code) VALUES (?, ?, ?, ?, ?)';
    $stmt = $conn->prepare($sql);

    if ($stmt->execute([$fullname, $email, $hashedPassword, $role, $studentCode])) {
        $newAdminId = (int)$conn->lastInsertId();
        ensureAdminPermissionRow($conn, $newAdminId);
        $status = 'created';
        $messages[] = 'Admin account created successfully.';
    } else {
        $messages[] = 'Failed to create admin account.';
    }
}

foreach ($messages as $line) {
    echo $line . PHP_EOL;
}
echo 'Email: ' . $email . PHP_EOL;
echo 'Password: ' . $password . PHP_EOL;
echo 'Role: ' . $role . PHP_EOL;
exit($status === 'error' ? 1 : 0);
