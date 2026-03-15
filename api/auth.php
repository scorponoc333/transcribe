<?php
/**
 * Authentication API
 * Handles login, logout, session check, and password reset
 */
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $db = getDB();

    // ─── GET: Session check ───
    if ($method === 'GET' && $action === 'check') {
        if (!empty($_SESSION['user_id'])) {
            echo json_encode([
                'authenticated' => true,
                'user' => [
                    'id'    => $_SESSION['user_id'],
                    'name'  => $_SESSION['user_name'],
                    'email' => $_SESSION['user_email'],
                    'role'  => $_SESSION['user_role'],
                ]
            ]);
        } else {
            echo json_encode(['authenticated' => false]);
        }
        exit;
    }

    // ─── POST actions ───
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    // ─── LOGIN ───
    if ($action === 'login') {
        $email    = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $honeypot = trim($input['honeypot'] ?? '');

        // Honeypot check — bots fill hidden fields
        if (!empty($honeypot)) {
            // Pretend success but don't actually log in (confuse bots)
            sleep(2);
            http_response_code(400);
            echo json_encode(['error' => 'Invalid request']);
            exit;
        }

        if (!$email || !$password) {
            http_response_code(400);
            echo json_encode(['error' => 'Email and password are required']);
            exit;
        }

        $stmt = $db->prepare("SELECT id, name, email, password_hash, role, is_active FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            sleep(1); // Slow down brute force
            http_response_code(401);
            echo json_encode(['error' => 'Invalid email or password']);
            exit;
        }

        if (!$user['is_active']) {
            http_response_code(403);
            echo json_encode(['error' => 'Account is deactivated. Contact your administrator.']);
            exit;
        }

        // Set session
        $_SESSION['user_id']    = (int) $user['id'];
        $_SESSION['user_name']  = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role']  = $user['role'];

        // Update last login
        $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id")
           ->execute([':id' => $user['id']]);

        echo json_encode([
            'success' => true,
            'user' => [
                'id'    => (int) $user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'role'  => $user['role'],
            ]
        ]);
        exit;
    }

    // ─── LOGOUT ───
    if ($action === 'logout') {
        session_unset();
        session_destroy();
        echo json_encode(['success' => true]);
        exit;
    }

    // ─── REQUEST PASSWORD RESET ───
    if ($action === 'request_reset') {
        $email = trim($input['email'] ?? '');
        if (!$email) {
            http_response_code(400);
            echo json_encode(['error' => 'Email is required']);
            exit;
        }

        $stmt = $db->prepare("SELECT id, name FROM users WHERE email = :email AND is_active = 1 LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Always return success to prevent email enumeration
        if (!$user) {
            echo json_encode(['success' => true, 'message' => 'If that email exists, a reset link has been sent.']);
            exit;
        }

        // Generate token
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour

        $db->prepare("UPDATE users SET password_reset_token = :token, password_reset_expires = :expires WHERE id = :id")
           ->execute([':token' => $token, ':expires' => $expires, ':id' => $user['id']]);

        // Try to send reset email using SMTP settings from DB
        $resetSent = false;
        try {
            $smtpSettings = [];
            $settingsStmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('smtpHost','smtpPort','smtpEncryption','smtpUser','smtpPass','senderEmail','senderName')");
            while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
                $smtpSettings[$row['setting_key']] = $row['setting_value'];
            }

            if (!empty($smtpSettings['smtpHost']) && !empty($smtpSettings['smtpUser'])) {
                require_once __DIR__ . '/phpmailer/Exception.php';
                require_once __DIR__ . '/phpmailer/PHPMailer.php';
                require_once __DIR__ . '/phpmailer/SMTP.php';

                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = $smtpSettings['smtpHost'];
                $mail->Port       = (int) ($smtpSettings['smtpPort'] ?? 587);
                $mail->SMTPAuth   = true;
                $mail->Username   = $smtpSettings['smtpUser'];
                $mail->Password   = $smtpSettings['smtpPass'] ?? '';
                $enc = $smtpSettings['smtpEncryption'] ?? 'tls';
                $mail->SMTPSecure = ($enc === 'ssl') ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                                  : (($enc === 'tls') ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : '');
                $mail->Timeout    = 30;

                $senderEmail = $smtpSettings['senderEmail'] ?? $smtpSettings['smtpUser'];
                $senderName  = $smtpSettings['senderName'] ?? 'Transcribe AI';
                $mail->setFrom($senderEmail, $senderName);
                $mail->addAddress($email, $user['name']);
                $mail->isHTML(true);
                $mail->Subject = 'Password Reset — Transcribe AI';
                $mail->CharSet = 'UTF-8';

                $resetUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                          . '://' . $_SERVER['HTTP_HOST']
                          . dirname($_SERVER['REQUEST_URI'], 2)
                          . '/login.php?reset=' . urlencode($token);

                $mail->Body = '
                <div style="font-family:Inter,sans-serif;max-width:480px;margin:0 auto;padding:32px">
                    <h2 style="color:#0f172a;margin-bottom:16px">Password Reset</h2>
                    <p style="color:#475569;line-height:1.6">Hi ' . htmlspecialchars($user['name']) . ',</p>
                    <p style="color:#475569;line-height:1.6">Click the button below to reset your password. This link expires in 1 hour.</p>
                    <div style="text-align:center;margin:28px 0">
                        <a href="' . htmlspecialchars($resetUrl) . '" style="display:inline-block;padding:12px 32px;background:linear-gradient(135deg,#2563eb,#7c3aed);color:#fff;text-decoration:none;border-radius:8px;font-weight:600">Reset Password</a>
                    </div>
                    <p style="color:#94a3b8;font-size:13px">If you didn\'t request this, you can safely ignore this email.</p>
                </div>';
                $mail->AltBody = "Reset your password: $resetUrl";

                $mail->send();
                $resetSent = true;
            }
        } catch (Exception $e) {
            // Silently fail — don't reveal SMTP issues to client
        }

        echo json_encode([
            'success' => true,
            'message' => 'If that email exists, a reset link has been sent.',
            'email_sent' => $resetSent
        ]);
        exit;
    }

    // ─── RESET PASSWORD ───
    if ($action === 'reset_password') {
        $token       = trim($input['token'] ?? '');
        $newPassword = $input['new_password'] ?? '';

        if (!$token || !$newPassword) {
            http_response_code(400);
            echo json_encode(['error' => 'Token and new password are required']);
            exit;
        }

        if (strlen($newPassword) < 8) {
            http_response_code(400);
            echo json_encode(['error' => 'Password must be at least 8 characters']);
            exit;
        }

        $stmt = $db->prepare("SELECT id FROM users WHERE password_reset_token = :token AND password_reset_expires > NOW() AND is_active = 1 LIMIT 1");
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or expired reset token']);
            exit;
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $db->prepare("UPDATE users SET password_hash = :hash, password_reset_token = NULL, password_reset_expires = NULL WHERE id = :id")
           ->execute([':hash' => $hash, ':id' => $user['id']]);

        echo json_encode(['success' => true, 'message' => 'Password has been reset. You can now log in.']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
