<?php
/**
 * Save Email Log - POST endpoint
 * Logs an email send event
 */
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_middleware.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['transcription_id']) || empty($input['sent_to'])) {
    http_response_code(400);
    echo json_encode(['error' => 'transcription_id and sent_to are required']);
    exit;
}

try {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO email_log
        (transcription_id, sent_to, cc, bcc, subject, sender, status, error_message)
        VALUES (:tid, :sent_to, :cc, :bcc, :subject, :sender, :status, :error_message)");

    $stmt->execute([
        ':tid'           => (int) $input['transcription_id'],
        ':sent_to'       => $input['sent_to'],
        ':cc'            => $input['cc'] ?? null,
        ':bcc'           => $input['bcc'] ?? null,
        ':subject'       => $input['subject'] ?? '',
        ':sender'        => $input['sender'] ?? null,
        ':status'        => $input['status'] ?? 'sent',
        ':error_message' => $input['error_message'] ?? null,
    ]);

    echo json_encode(['success' => true, 'id' => (int) $db->lastInsertId()]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
