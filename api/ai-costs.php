<?php
/**
 * AI Costs API
 * GET  → list costs (optional ?transcription_id=X)
 * POST → save a new cost record { transcription_id, operation, generation_id, model, prompt_tokens, completion_tokens, total_tokens, cost_usd }
 */
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_middleware.php';
requireAuth();

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();

    if ($method === 'GET') {
        $where = '';
        $params = [];

        if (!empty($_GET['transcription_id'])) {
            $where = 'WHERE transcription_id = :tid';
            $params[':tid'] = (int) $_GET['transcription_id'];
        }

        $stmt = $db->prepare("SELECT * FROM ai_costs $where ORDER BY created_at DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cast numeric fields
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['transcription_id'] = $row['transcription_id'] ? (int) $row['transcription_id'] : null;
            $row['prompt_tokens'] = (int) $row['prompt_tokens'];
            $row['completion_tokens'] = (int) $row['completion_tokens'];
            $row['total_tokens'] = (int) $row['total_tokens'];
            $row['cost_usd'] = (float) $row['cost_usd'];
        }

        echo json_encode(['success' => true, 'data' => $rows]);

    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['operation'])) {
            http_response_code(400);
            echo json_encode(['error' => 'operation is required']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO ai_costs
            (transcription_id, operation, generation_id, model, prompt_tokens, completion_tokens, total_tokens, cost_usd)
            VALUES (:tid, :operation, :gen_id, :model, :prompt_tokens, :completion_tokens, :total_tokens, :cost_usd)");

        $stmt->execute([
            ':tid'               => $input['transcription_id'] ?? null,
            ':operation'         => $input['operation'],
            ':gen_id'            => $input['generation_id'] ?? null,
            ':model'             => $input['model'] ?? null,
            ':prompt_tokens'     => $input['prompt_tokens'] ?? 0,
            ':completion_tokens' => $input['completion_tokens'] ?? 0,
            ':total_tokens'      => $input['total_tokens'] ?? 0,
            ':cost_usd'          => $input['cost_usd'] ?? 0,
        ]);

        echo json_encode(['success' => true, 'id' => (int) $db->lastInsertId()]);

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
