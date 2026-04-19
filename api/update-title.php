<?php
/**
 * Update transcription title
 * POST: id, title → updates title for the given transcription (tenant-isolated)
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_middleware.php';
requireAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$orgId = getCurrentOrgId();
$id    = (int) ($_POST['id'] ?? 0);
$title = trim($_POST['title'] ?? '');

if ($id <= 0 || $title === '') {
    http_response_code(400);
    echo json_encode(['error' => 'id and title required']);
    exit;
}

try {
    $db = getDB();

    // First verify the row exists + belongs to caller's org.
    // rowCount() on UPDATE returns 0 when title is unchanged (same
    // string as DB), which previously produced a spurious 404 — this
    // SELECT separates 'does the record exist?' from 'did MySQL touch
    // any bytes?'.
    $check = $db->prepare("SELECT title FROM transcriptions WHERE id = :id AND organization_id = :org LIMIT 1");
    $check->execute([':id' => $id, ':org' => $orgId]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Transcription not found or you do not have access to it.']);
        exit;
    }

    // If the title is already exactly this, short-circuit success.
    if ($row['title'] === $title) {
        echo json_encode(['success' => true, 'title' => $title, 'unchanged' => true]);
        exit;
    }

    $stmt = $db->prepare("UPDATE transcriptions SET title = :title WHERE id = :id AND organization_id = :org");
    $stmt->execute([':title' => $title, ':id' => $id, ':org' => $orgId]);
    echo json_encode(['success' => true, 'title' => $title]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error — please try again.']);
}
