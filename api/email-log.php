<?php
/**
 * Email Log - GET endpoint
 * Returns email log entries for a transcription
 */
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_middleware.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$transcriptionId = (int) ($_GET['transcription_id'] ?? 0);

try {
    $db = getDB();

    if ($transcriptionId > 0) {
        $stmt = $db->prepare("SELECT * FROM email_log WHERE transcription_id = :tid ORDER BY sent_at DESC");
        $stmt->execute([':tid' => $transcriptionId]);
    } else {
        $stmt = $db->prepare("SELECT e.*, t.title AS transcription_title
                              FROM email_log e
                              LEFT JOIN transcriptions t ON t.id = e.transcription_id
                              ORDER BY e.sent_at DESC LIMIT 50");
        $stmt->execute();
    }

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id']               = (int) $row['id'];
        $row['transcription_id'] = (int) $row['transcription_id'];
    }

    echo json_encode(['success' => true, 'data' => $rows]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
