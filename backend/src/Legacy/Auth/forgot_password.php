<?php
session_start();
require_once __DIR__ . '/../System/db_connect.php';
require_once __DIR__ . '/../System/config.php';
require_once __DIR__ . '/../System/app_helpers.php';

$script_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$app_base_path = $script_dir;
if (preg_match('#^(.*)/frontend/public$#', $script_dir, $m) === 1) {
    $app_base_path = $m[1];
} elseif (preg_match('#^(.*)/backend/src/Legacy/Auth$#', $script_dir, $m) === 1) {
    $app_base_path = $m[1];
}
$logo_src = rtrim($app_base_path, '/') . '/frontend/public/Image/IS.png';
if ($logo_src === '/frontend/public/Image/IS.png') {
    $logo_src = 'Image/IS.png';
}

// โหลดไฟล์ PHPMailer (แบบ Manual)
require __DIR__ . '/../../../libs/PHPMailer/Exception.php';
require __DIR__ . '/../../../libs/PHPMailer/PHPMailer.php';
require __DIR__ . '/../../../libs/PHPMailer/SMTP.php';

$status = '';
$msg_text = '';

if (($_GET['status'] ?? '') === 'csrf_invalid') {
    $status = 'error';
    $msg_text = 'Invalid request token. Please try again.';
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    ensureValidCsrfOrRedirect('forgot_password.php');
    $user_email = $_POST['email']; 

    $stmt = $conn->prepare("SELECT id, fullname FROM users WHERE email = ?");
    $stmt->execute([$user_email]);
    $user = $stmt->fetch();

    if ($user) {
        $token = bin2hex(random_bytes(32));

        // อัปเดต Token และเวลาหมดอายุ 1 ชั่วโมง
        $update = $conn->prepare("UPDATE users SET reset_token = ?, token_expire = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?");
        $update->execute([$token, $user['id']]);

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true); 

        try {
            if (trim(SMTP_USER) === '' || trim(SMTP_PASS) === '') {
                throw new RuntimeException('SMTP credentials are not configured.');
            }

            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = strtolower(SMTP_ENCRYPTION) === 'ssl'
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
            $mail->addAddress($user_email, $user['fullname']); 

            // ใช้ APP_BASE_URL ถ้าตั้งไว้; ถ้าไม่ตั้งจะสร้าง URL อัตโนมัติจากโดเมนปัจจุบัน
            $baseUrl = APP_BASE_URL;
            if ($baseUrl === '') {
                $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
                $scheme = $isHttps ? 'https' : 'http';
                $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
                $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
                $baseUrl = $scheme . '://' . $host . ($scriptDir === '' ? '' : $scriptDir);
            }
            $link = $baseUrl . '/reset_password.php?token=' . rawurlencode($token);
            
            $mail->isHTML(true);
            $mail->Subject = 'แจ้งกู้คืนรหัสผ่าน (Reset Password) - RMUTP Project Tracker';
            $mail->Body    = "
                <div style='font-family: sans-serif; line-height: 1.6; color: #333;'>
                    <h3 style='color: #6b21a8;'>เรียนคุณ {$user['fullname']}</h3>
                    <p>เราได้รับคำขอเพื่อตั้งรหัสผ่านใหม่สำหรับบัญชีของคุณ</p>
                    <p>กรุณาคลิกที่ปุ่มด้านล่างนี้เพื่อดำเนินการตั้งรหัสผ่านใหม่ (ลิงก์นี้มีอายุ 1 ชั่วโมง):</p>
                    <p style='margin: 20px 0;'>
                        <a href='$link' style='background-color: #6b21a8; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold;'>ตั้งรหัสผ่านใหม่</a>
                    </p>
                    <p><small>หากปุ่มกดไม่ได้ ให้ก๊อปปี้ลิงก์นี้ไปวางในช่องค้นหาของ Browser:<br><a href='$link'>$link</a></small></p>
                </div>
            ";

            $mail->send();
            $status = 'success';
            $msg_text = 'ส่งลิงก์ไปยังอีเมลของคุณเรียบร้อยแล้ว! (โปรดตรวจสอบในกล่องจดหมายหรือ Junk Mail)';
        
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            $status = 'error';
            $msg_text = 'เกิดข้อผิดพลาดในการส่งอีเมล: ' . $mail->ErrorInfo;
        }

    } else {
        $status = 'error';
        $msg_text = 'ไม่พบอีเมลนี้ในระบบ กรุณาตรวจสอบความถูกต้อง';
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ลืมรหัสผ่าน - RMUTP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style> body { font-family: 'Sarabun', sans-serif; } </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen p-4">

    <div class="bg-white p-8 rounded-xl shadow-xl w-full max-w-sm border-t-4 border-purple-800">
        
        <div class="text-center mb-6">
            <img src="<?= htmlspecialchars($logo_src) ?>" alt="IS Logo" class="h-20 w-auto mx-auto mb-3 object-contain drop-shadow-md">
            <h2 class="text-xl font-bold text-gray-800">ลืมรหัสผ่าน?</h2>
            <p class="text-sm text-gray-500 mt-1">กรอกอีเมลของคุณเพื่อรับลิงก์ตั้งรหัสผ่านใหม่</p>
        </div>

        <?php if($status !== 'success'): ?>
        <form method="POST" class="space-y-4">
            <?= csrfInputField() ?>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">อีเมลที่ใช้สมัครสมาชิก</label>
                <input type="email" name="email" required placeholder="example@rmutp.ac.th" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition">
            </div>
            
            <button type="submit" class="w-full bg-purple-800 text-white py-2.5 rounded-lg hover:bg-purple-900 transition font-bold shadow mt-2">
                ส่งลิงก์รีเซ็ตรหัสผ่าน
            </button>
        </form>
        <?php endif; ?>

        <div class="mt-6 text-center text-sm">
            <a href="login.php" class="text-gray-500 hover:text-purple-800 transition"><i class="fas fa-arrow-left"></i> กลับไปหน้าเข้าสู่ระบบ</a>
        </div>
    </div>

    <script>
        <?php if($status == 'success'): ?>
            Swal.fire({
                icon: 'success',
                title: 'ส่งอีเมลสำเร็จ!',
                text: '<?= $msg_text ?>',
                confirmButtonColor: '#6b21a8',
                confirmButtonText: 'ตกลง'
            }).then(() => {
                // หลังจากส่งเสร็จอาจจะให้กดตกลงแล้วกลับไปหน้า login ก็ได้ (หรือจะให้อยู่หน้านี้ก็ได้)
                window.location.href = 'login.php';
            });
        <?php elseif($status == 'error'): ?>
            Swal.fire({
                icon: 'error',
                title: 'ไม่สำเร็จ!',
                text: '<?= $msg_text ?>',
                confirmButtonColor: '#d33',
                confirmButtonText: 'ลองอีกครั้ง'
            });
        <?php endif; ?>
    </script>

</body>
</html>
