<?php
/**
 * Save Transcription - POST endpoint
 * Inserts a new transcription record into the database
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
if (!$input || empty($input['transcript_text'])) {
    http_response_code(400);
    echo json_encode(['error' => 'transcript_text is required']);
    exit;
}

try {
    $db = getDB();
    $userId = getCurrentUserId();
    $stmt = $db->prepare("INSERT INTO transcriptions
        (user_id, title, mode, language, transcript_text, transcript_english, analysis_json, whisper_model, word_count, char_count, timer_seconds, audio_duration_seconds, transcript_source)
        VALUES (:user_id, :title, :mode, :language, :transcript_text, :transcript_english, :analysis_json, :whisper_model, :word_count, :char_count, :timer_seconds, :audio_duration_seconds, :transcript_source)");

    $stmt->execute([
        ':user_id'                => $userId,
        ':title'                  => $input['title'] ?? null,
        ':mode'                   => $input['mode'] ?? 'recording',
        ':language'               => $input['language'] ?? 'en',
        ':transcript_text'        => $input['transcript_text'],
        ':transcript_english'     => $input['transcript_english'] ?? null,
        ':analysis_json'          => isset($input['analysis_json']) ? json_encode($input['analysis_json']) : null,
        ':whisper_model'          => $input['whisper_model'] ?? 'turbo',
        ':word_count'             => $input['word_count'] ?? 0,
        ':char_count'             => $input['char_count'] ?? 0,
        ':timer_seconds'          => $input['timer_seconds'] ?? null,
        ':audio_duration_seconds' => $input['audio_duration_seconds'] ?? null,
        ':transcript_source'      => $input['transcript_source'] ?? 'audio',
    ]);

    echo json_encode([
        'success' => true,
        'id'      => (int) $db->lastInsertId(),
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
