<?php
/**
 * Get Single Transcription - GET endpoint
 * Returns full transcription record (no PDF blob)
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

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Valid id is required']);
    exit;
}

try {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, title, mode, language, transcript_text, transcript_english,
                                  analysis_json, pdf_filename, whisper_model, word_count, char_count,
                                  created_at, (pdf_blob IS NOT NULL) AS has_pdf
                           FROM transcriptions WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Transcription not found']);
        exit;
    }

    $row['id']            = (int) $row['id'];
    $row['word_count']    = (int) $row['word_count'];
    $row['char_count']    = (int) $row['char_count'];
    $row['has_pdf']       = (bool) $row['has_pdf'];
    $row['analysis_json'] = $row['analysis_json'] ? json_decode($row['analysis_json'], true) : null;

    echo json_encode(['success' => true, 'data' => $row]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
