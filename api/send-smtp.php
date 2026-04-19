<?php
/**
 * Email Sender — Proxies to EmailIt HTTP API with signed report links
 *
 * Named "send-smtp.php" for backward compatibility with the frontend, which
 * calls this endpoint via API.sendSmtpEmail(). The underlying transport is
 * EmailIt's v2 HTTP API (not SMTP), which is required on hosting providers
 * that block outbound SMTP ports (e.g. DigitalOcean).
 *
 * PDF attachments are NOT forwarded. Instead, when the client includes a
 * `transcription_id` in the payload, we generate an HMAC-signed URL to
 * api/report.php which renders the transcription as a beautifully branded
 * landing page. The URL replaces a {{REPORT_URL}} placeholder in the email
 * HTML. This keeps the email body tiny (no base64 PDF inflation) and gives
 * recipients a premium branded experience.
 *
 * EmailIt API key is read from the `settings` table under `emailitApiKey`.
 * HMAC secret lives in api/.pdf_secret (auto-generated on first use).
 */
header('Content-Type: application/json');
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/db.php';
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

// ─── EmailIt API key (scoped to caller's org) ───────────────────────────
$orgId = getCurrentOrgId();
try {
    $db = getDB();
    $stmt = $db->prepare("SELECT setting_value FROM settings
                          WHERE organization_id = :org_id AND setting_key = 'emailitApiKey' LIMIT 1");
    $stmt->execute([':org_id' => $orgId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $apiKey = $row ? trim($row['setting_value']) : '';
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error loading email settings']);
    exit;
}

if (!$apiKey) {
    http_response_code(400);
    echo json_encode(['error' => 'EmailIt API key not configured. Add emailitApiKey in Settings.']);
    exit;
}

// ─── Email fields from the frontend ─────────────────────────────────────
$from           = trim($input['from'] ?? '');
$fromName       = trim($input['from_name'] ?? '');
$to             = $input['to'] ?? [];
$cc             = $input['cc'] ?? [];
$bcc            = $input['bcc'] ?? [];
$subject        = $input['subject'] ?? '';
$html           = $input['html'] ?? '';
$transcriptionId = isset($input['transcription_id']) ? (int) $input['transcription_id'] : 0;

if (empty($to)) {
    http_response_code(400);
    echo json_encode(['error' => 'At least one recipient is required']);
    exit;
}

// "Display Name <email>"
if ($from && $fromName && strpos($from, '<') === false) {
    $from = "$fromName <$from>";
}

$normalize = function ($v) {
    if (is_array($v)) return array_values(array_filter(array_map('trim', $v)));
    if (is_string($v) && $v !== '') return array_values(array_filter(array_map('trim', explode(',', $v))));
    return [];
};
$to  = $normalize($to);
$cc  = $normalize($cc);
$bcc = $normalize($bcc);

if (empty($to)) {
    http_response_code(400);
    echo json_encode(['error' => 'At least one valid recipient is required']);
    exit;
}

// ─── Mint HMAC-signed report URL ────────────────────────────────────────
// CRITICAL: verify the transcription belongs to the caller's org before
// issuing a signed URL. Otherwise a user could mint report URLs for other
// orgs' transcriptions.
$reportUrl = '';
if ($transcriptionId > 0) {
    try {
        $check = $db->prepare("SELECT id FROM transcriptions
                               WHERE id = :id AND organization_id = :org_id LIMIT 1");
        $check->execute([':id' => $transcriptionId, ':org_id' => $orgId]);
        $exists = $check->fetchColumn();
    } catch (PDOException $e) {
        $exists = false;
    }

    if ($exists) {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = $_SERVER['HTTP_HOST'] ?? 'transcribe.jasonhogan.ca';

        // Prefer the public-token URL when the transcription has been made
        // public (which is the normal case now — send-email flips it on).
        // Falls back to an HMAC-signed URL otherwise so legacy / non-public
        // flows still work.
        try {
            $pq = $db->prepare('SELECT is_public, public_token FROM transcriptions
                                WHERE id = :id AND organization_id = :org_id LIMIT 1');
            $pq->execute([':id' => $transcriptionId, ':org_id' => $orgId]);
            $pRow = $pq->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $pRow = false;
        }

        if ($pRow && (int) $pRow['is_public'] === 1 && !empty($pRow['public_token'])) {
            $reportUrl = sprintf(
                '%s://%s/api/report.php?t=%s',
                $proto, $host, urlencode($pRow['public_token'])
            );
        } else {
            $secretFile = __DIR__ . '/.pdf_secret';
            if (!file_exists($secretFile)) {
                $newSecret = bin2hex(random_bytes(32));
                file_put_contents($secretFile, $newSecret);
                @chmod($secretFile, 0600);
            }
            $secret = trim(file_get_contents($secretFile));

            $expiresAt = time() + (30 * 24 * 60 * 60);
            $sig       = hash_hmac('sha256', $transcriptionId . '|' . $expiresAt . '|' . $orgId, $secret);
            $reportUrl = sprintf(
                '%s://%s/api/report.php?id=%d&exp=%d&org=%d&sig=%s',
                $proto, $host, $transcriptionId, $expiresAt, $orgId, $sig
            );
        }
    }
}

// Substitute placeholders in the email HTML
if ($reportUrl) {
    $html = str_replace('{{REPORT_URL}}', htmlspecialchars($reportUrl, ENT_QUOTES, 'UTF-8'), $html);
    // Also support the legacy placeholder from earlier iterations
    $html = str_replace('{{PDF_DOWNLOAD_URL}}', htmlspecialchars($reportUrl, ENT_QUOTES, 'UTF-8'), $html);
} else {
    // No transcription_id — fall back so we don't send broken links
    $html = str_replace(['{{REPORT_URL}}', '{{PDF_DOWNLOAD_URL}}'], '#', $html);
}

// ─── Build EmailIt v2 payload ───────────────────────────────────────────
$payload = [
    'from'    => $from,
    'to'      => implode(',', $to),
    'subject' => $subject,
    'html'    => $html,
];
if (!empty($cc))  $payload['cc']  = implode(',', $cc);
if (!empty($bcc)) $payload['bcc'] = implode(',', $bcc);

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

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to reach EmailIt: ' . $curlError]);
    exit;
}

if ($httpCode >= 200 && $httpCode < 300) {
    echo json_encode([
        'success'    => true,
        'message'    => 'Email sent successfully via EmailIt',
        'report_url' => $reportUrl ?: null,
    ]);
    exit;
}

// EmailIt rejected — surface details for debugging
http_response_code($httpCode);
$decoded = json_decode($response, true);
echo json_encode([
    'error'     => 'EmailIt rejected the request',
    'details'   => is_array($decoded) ? $decoded : $response,
    'http_code' => $httpCode,
]);
