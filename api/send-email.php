<?php
/**
 * Email Proxy - Forwards email requests to EmailIt API via cURL to avoid CORS
 */
header('Content-Type: application/json');
require_once __DIR__ . '/auth_middleware.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$apiKey = $input['apiKey'] ?? '';
if (!$apiKey) {
    http_response_code(400);
    echo json_encode(['error' => 'API key is required']);
    exit;
}

// Build the payload for EmailIt (exclude apiKey from body)
$payload = [];
foreach (['from', 'to', 'cc', 'bcc', 'subject', 'html', 'attachments'] as $field) {
    if (isset($input[$field]) && $input[$field] !== '' && $input[$field] !== null) {
        $payload[$field] = $input[$field];
    }
}

$ch = curl_init('https://api.emailit.com/v2/emails');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to reach EmailIt: ' . $curlError]);
    exit;
}

http_response_code($httpCode);
echo $response;
