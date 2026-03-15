<?php
/**
 * Save PDF - POST endpoint
 * Updates a transcription record with the generated PDF blob
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
if (!$input || empty($input['id']) || empty($input['pdf_base64'])) {
    http_response_code(400);
    echo json_encode(['error' => 'id and pdf_base64 are required']);
    exit;
}

try {
    $db = getDB();
    $pdfData = base64_decode($input['pdf_base64']);
    if ($pdfData === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid base64 data']);
        exit;
    }

    $stmt = $db->prepare("UPDATE transcriptions SET pdf_blob = :pdf_blob, pdf_filename = :pdf_filename WHERE id = :id");
    $stmt->execute([
        ':pdf_blob'     => $pdfData,
        ':pdf_filename' => $input['filename'] ?? 'transcript.pdf',
        ':id'           => (int) $input['id'],
    ]);

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
