<?php
/**
 * Serve PDF - GET endpoint
 * Streams the PDF blob for download
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_middleware.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'Valid id is required']);
    exit;
}

try {
    $db = getDB();
    $stmt = $db->prepare("SELECT pdf_blob, pdf_filename FROM transcriptions WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row || !$row['pdf_blob']) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['error' => 'PDF not found']);
        exit;
    }

    $filename = $row['pdf_filename'] ?: 'transcript.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Content-Length: ' . strlen($row['pdf_blob']));
    echo $row['pdf_blob'];
} catch (PDOException $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
