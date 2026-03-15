<?php
/**
 * Delete Transcription - POST endpoint
 * Deletes a transcription and its associated email logs
 */
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_middleware.php';
requireRole(['admin','manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$id = (int) ($input['id'] ?? 0);

if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'id is required']);
    exit;
}

try {
    $db = getDB();

    // Delete email logs first (foreign key constraint)
    $stmt = $db->prepare("DELETE FROM email_log WHERE transcription_id = :id");
    $stmt->execute([':id' => $id]);

    // Delete transcription
    $stmt = $db->prepare("DELETE FROM transcriptions WHERE id = :id");
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Transcription not found']);
        exit;
    }

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
