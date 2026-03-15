<?php
/**
 * Attendees API
 * GET  ?transcription_id=123 → list attendees for a transcription
 * POST { transcription_id, attendees: [{name, email, source}] } → replace all attendees
 */
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_middleware.php';
requireAuth();

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();

    if ($method === 'GET') {
        $tid = (int) ($_GET['transcription_id'] ?? 0);
        if (!$tid) {
            http_response_code(400);
            echo json_encode(['error' => 'transcription_id is required']);
            exit;
        }

        $stmt = $db->prepare("SELECT id, name, email, source, created_at FROM attendees WHERE transcription_id = :tid ORDER BY id ASC");
        $stmt->execute([':tid' => $tid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $rows]);

    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $tid = (int) ($input['transcription_id'] ?? 0);
        $attendees = $input['attendees'] ?? [];

        if (!$tid) {
            http_response_code(400);
            echo json_encode(['error' => 'transcription_id is required']);
            exit;
        }

        // Delete existing attendees and re-insert
        $db->beginTransaction();

        $stmt = $db->prepare("DELETE FROM attendees WHERE transcription_id = :tid");
        $stmt->execute([':tid' => $tid]);

        $stmt = $db->prepare("INSERT INTO attendees (transcription_id, name, email, source) VALUES (:tid, :name, :email, :source)");
        foreach ($attendees as $a) {
            $name = trim($a['name'] ?? '');
            if (!$name) continue;
            $stmt->execute([
                ':tid'    => $tid,
                ':name'   => $name,
                ':email'  => trim($a['email'] ?? '') ?: null,
                ':source' => ($a['source'] === 'manual') ? 'manual' : 'ai',
            ]);
        }

        $db->commit();
        echo json_encode(['success' => true]);

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }

} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
