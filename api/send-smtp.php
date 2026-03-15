<?php
/**
 * SMTP Email Sender - Uses PHPMailer for reliable SMTP delivery
 * Accepts JSON POST with SMTP settings and email content
 */
header('Content-Type: application/json');

// Load PHPMailer
require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';
require_once __DIR__ . '/auth_middleware.php';
requireAuth();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

// Load SMTP settings from database (preferred) or fallback to request body
require_once __DIR__ . '/db.php';
$db = getDB();
$smtpSettings = [];
try {
    $sStmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('smtpHost','smtpPort','smtpEncryption','smtpUser','smtpPass','senderEmail','senderName')");
    while ($row = $sStmt->fetch(PDO::FETCH_ASSOC)) {
        $smtpSettings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    // Fall back to request body
}

// Use DB settings if available, otherwise fall back to request body (backward compat)
$smtpHost       = $smtpSettings['smtpHost'] ?? ($input['smtp_host'] ?? '');
$smtpPort       = (int) ($smtpSettings['smtpPort'] ?? ($input['smtp_port'] ?? 587));
$smtpUser       = $smtpSettings['smtpUser'] ?? ($input['smtp_user'] ?? '');
$smtpPass       = $smtpSettings['smtpPass'] ?? ($input['smtp_pass'] ?? '');
$smtpEncryption = $smtpSettings['smtpEncryption'] ?? ($input['smtp_encryption'] ?? 'tls');

if (!$smtpHost || !$smtpUser || !$smtpPass) {
    http_response_code(400);
    echo json_encode(['error' => 'SMTP settings not configured. Please configure email settings in Settings.']);
    exit;
}

// Email fields
$from      = $input['from'] ?? '';
$fromName  = $input['from_name'] ?? '';
$to        = $input['to'] ?? [];
$cc        = $input['cc'] ?? [];
$bcc       = $input['bcc'] ?? [];
$subject   = $input['subject'] ?? '';
$html      = $input['html'] ?? '';
$attachments = $input['attachments'] ?? [];

if (empty($to)) {
    http_response_code(400);
    echo json_encode(['error' => 'At least one recipient is required']);
    exit;
}

try {
    $mail = new PHPMailer(true);

    // SMTP config
    $mail->isSMTP();
    $mail->Host       = $smtpHost;
    $mail->Port       = $smtpPort;
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpUser;
    $mail->Password   = $smtpPass;
    $mail->SMTPSecure = ($smtpEncryption === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS
                       : (($smtpEncryption === 'tls') ? PHPMailer::ENCRYPTION_STARTTLS : '');
    $mail->Timeout    = 30;

    // From
    if ($from) {
        // Parse "Display Name <email>" format
        if (preg_match('/^(.+?)\s*<(.+?)>$/', $from, $m)) {
            $mail->setFrom($m[2], $m[1]);
        } else {
            $mail->setFrom($from, $fromName ?: '');
        }
    } else {
        $mail->setFrom($smtpUser, $fromName ?: '');
    }

    // Recipients
    $toList = is_array($to) ? $to : [$to];
    foreach ($toList as $addr) {
        $addr = trim($addr);
        if ($addr) $mail->addAddress($addr);
    }

    $ccList = is_array($cc) ? $cc : ($cc ? [$cc] : []);
    foreach ($ccList as $addr) {
        $addr = trim($addr);
        if ($addr) $mail->addCC($addr);
    }

    $bccList = is_array($bcc) ? $bcc : ($bcc ? [$bcc] : []);
    foreach ($bccList as $addr) {
        $addr = trim($addr);
        if ($addr) $mail->addBCC($addr);
    }

    // Content
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $html;
    $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));
    $mail->CharSet = 'UTF-8';

    // Attachments (base64 encoded)
    if (!empty($attachments)) {
        foreach ($attachments as $att) {
            $filename    = $att['filename'] ?? 'attachment.pdf';
            $content     = $att['content'] ?? '';
            $contentType = $att['content_type'] ?? 'application/octet-stream';
            $decoded = base64_decode($content);
            if ($decoded !== false) {
                $mail->addStringAttachment($decoded, $filename, 'base64', $contentType);
            }
        }
    }

    $mail->send();

    echo json_encode([
        'success' => true,
        'message' => 'Email sent successfully via SMTP'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'SMTP error: ' . $mail->ErrorInfo
    ]);
}
