<?php
// Force fresh page load
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
/**
 * Branded Report Landing Page
 *
 * Serves a beautifully branded HTML page for a transcription, optimized for
 * browser Print→Save as PDF via CSS @page rules and page-break controls.
 * No server-side PDF generation required — the browser does it perfectly.
 *
 * Access modes:
 *   1. Public (signed URL):  ?id={transcriptionId}&exp={unix_ts}&sig={hmac_sha256}
 *      Used by emailed links. HMAC signed over "{id}|{exp}" using a secret
 *      in api/.pdf_secret (auto-generated).
 *
 *   2. Authenticated (in-app): ?id={transcriptionId}
 *      Used by the app's "View Report" / "Export PDF" buttons. Session auth.
 *
 * Renders one of three layouts based on transcriptions.mode:
 *   - recording  → minimal cover + scrollable transcript
 *   - meeting    → cover + stat cards + exec summary + key points + action items + transcript
 *   - learning   → cover + learning objectives + concepts + takeaways + transcript
 */

require_once __DIR__ . '/db.php';

// ─── Resolve access mode ─────────────────────────────────────────────────
$id      = (int) ($_GET['id']  ?? 0);
$exp     = $_GET['exp'] ?? '';
$sig     = $_GET['sig'] ?? '';
$orgP    = isset($_GET['org']) ? (int) $_GET['org'] : 0;
$pubTok  = isset($_GET['t']) ? (string) $_GET['t'] : '';

// If a public token is provided, resolve the id from it before any other check
$isPublicToken = false;
if ($pubTok !== '' && preg_match('/^[a-f0-9]{32,64}$/', $pubTok)) {
    try {
        $tdb = getDB();
        $tq = $tdb->prepare("SELECT id, organization_id FROM transcriptions
                             WHERE public_token = :t AND is_public = 1 LIMIT 1");
        $tq->execute([':t' => $pubTok]);
        $tRow = $tq->fetch(PDO::FETCH_ASSOC);
        if ($tRow) {
            $id = (int) $tRow['id'];
            $orgP = (int) $tRow['organization_id'];
            $isPublicToken = true;
        } else {
            http_response_code(404);
            render_error('Report not available', 'This share link is no longer active. The owner may have made the report private.');
            exit;
        }
    } catch (PDOException $e) {
        http_response_code(500);
        render_error('Database error', 'We could not load the report right now. Please try again in a minute.');
        exit;
    }
}

if ($id <= 0) {
    http_response_code(400);
    render_error('Invalid report link', 'The link is missing a report ID. Please check the URL or request a new copy from the sender.');
    exit;
}

$isSigned = ($exp !== '' && $sig !== '');
$signedOrgId = 0;     // org that the signed URL is bound to
$sessionOrgId = 0;    // org from the logged-in session

if ($isPublicToken) {
    // Public-token access — already resolved above, signedOrgId is set, skip auth
    $signedOrgId = $orgP;
} elseif ($isSigned) {
    // Public access via signed URL — verify signature + expiration + org binding
    if (!ctype_digit((string) $exp) || !preg_match('/^[a-f0-9]{64}$/', $sig) || $orgP <= 0) {
        http_response_code(400);
        render_error('Invalid signature', 'The link format is corrupted. Please request a new copy from the sender.');
        exit;
    }

    if ((int) $exp < time()) {
        http_response_code(410);
        render_error('Link expired', 'This report link has expired. Please request a new copy from the sender.');
        exit;
    }

    $secretFile = __DIR__ . '/.pdf_secret';
    if (!file_exists($secretFile)) {
        $newSecret = bin2hex(random_bytes(32));
        file_put_contents($secretFile, $newSecret);
        @chmod($secretFile, 0600);
    }
    $secret = trim(file_get_contents($secretFile));

    // New (org-bound) signature format — and fall back to legacy if that fails
    $expected_new    = hash_hmac('sha256', $id . '|' . $exp . '|' . $orgP, $secret);
    $expected_legacy = hash_hmac('sha256', $id . '|' . $exp, $secret);
    if (hash_equals($expected_new, $sig)) {
        $signedOrgId = $orgP;
    } elseif (hash_equals($expected_legacy, $sig)) {
        // Legacy URL (pre-Phase 1) — trust it but log and bind to the transcription's org
        $signedOrgId = $orgP; // will be verified in the DB query below
    } else {
        http_response_code(403);
        render_error('Invalid signature', 'This link appears to have been tampered with. Please request a new copy from the sender.');
        exit;
    }
} else {
    // In-app access — require session auth
    require_once __DIR__ . '/auth_middleware.php';
    requireAuth();
    $sessionOrgId = getCurrentOrgId();
}

// ─── Load transcription + related data (tenant-filtered) ───────────────
// The org we scope by depends on how we were accessed:
//   - signed URL   → use the org bound to the signature
//   - in-app auth  → use the session org
$effectiveOrgId = ($isSigned || $isPublicToken) ? $signedOrgId : $sessionOrgId;
$canManageShare = (!$isSigned && !$isPublicToken && $sessionOrgId > 0);

try {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, organization_id, user_id, title, mode, language, transcript_text, transcript_english,
               analysis_json, whisper_model, word_count, char_count, created_at,
               timer_seconds, audio_duration_seconds, transcript_source,
               is_public, public_token
        FROM transcriptions
        WHERE id = :id AND organization_id = :org_id LIMIT 1
    ");
    $stmt->execute([':id' => $id, ':org_id' => $effectiveOrgId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    render_error('Database error', 'We could not load the report right now. Please try again in a minute.');
    exit;
}

if (!$row) {
    http_response_code(404);
    render_error('Report not found', 'This report may have been removed or you do not have access. Please request a new copy from the sender.');
    exit;
}

// ─── Load brand settings (scoped to the transcription's org) ───────────
$settings = [];
try {
    $s = $db->prepare("SELECT setting_key, setting_value FROM settings
                       WHERE organization_id = :org_id
                         AND setting_key IN ('brandColor','footerText','senderName')");
    $s->execute([':org_id' => (int) $row['organization_id']]);
    while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
} catch (PDOException $e) { /* non-fatal */ }

$brandColor = $settings['brandColor'] ?? 'var(--brand-500)';
$footerText = $settings['footerText'] ?? '';
$senderName = $settings['senderName'] ?? '';

// Derive RGB for rgba() gradients
$hex = ltrim($brandColor, '#');
if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
$pR = hexdec(substr($hex, 0, 2));
$pG = hexdec(substr($hex, 2, 2));
$pB = hexdec(substr($hex, 4, 2));
$primaryRgb = "{$pR}, {$pG}, {$pB}";
// Derive full brand palette (mirrors app.js logic) for use inside :root CSS
function hexToHsl($hex) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    $r = hexdec(substr($hex,0,2))/255; $g = hexdec(substr($hex,2,2))/255; $b = hexdec(substr($hex,4,2))/255;
    $max=max($r,$g,$b); $min=min($r,$g,$b); $l=($max+$min)/2;
    if ($max === $min) return [0,0,$l*100];
    $d = $max-$min;
    $sVal = $l > 0.5 ? $d/(2-$max-$min) : $d/($max+$min);
    switch($max){
        case $r: $h = ($g-$b)/$d + ($g<$b?6:0); break;
        case $g: $h = ($b-$r)/$d + 2; break;
        default: $h = ($r-$g)/$d + 4;
    }
    return [round($h*60), round($sVal*100), round($l*100)];
}
function hslToRgbStr($h, $s, $l) {
    $s/=100; $l/=100;
    $c = (1 - abs(2*$l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h/60, 2) - 1));
    $m = $l - $c/2;
    if     ($h <  60) [$r,$g,$b] = [$c,$x,0];
    elseif ($h < 120) [$r,$g,$b] = [$x,$c,0];
    elseif ($h < 180) [$r,$g,$b] = [0,$c,$x];
    elseif ($h < 240) [$r,$g,$b] = [0,$x,$c];
    elseif ($h < 300) [$r,$g,$b] = [$x,0,$c];
    else              [$r,$g,$b] = [$c,0,$x];
    return [ round(($r+$m)*255), round(($g+$m)*255), round(($b+$m)*255) ];
}
[$brandH, $brandS, $brandL] = hexToHsl($brandColor);
$brandSat = min($brandS, 85);
$shades = [
    '50'  => hslToRgbStr($brandH, min($brandSat, 65), 94),
    '100' => hslToRgbStr($brandH, min($brandSat, 70), 87),
    '200' => hslToRgbStr($brandH, min($brandSat, 75), 76),
    '300' => hslToRgbStr($brandH, min($brandSat, 80), 62),
    '400' => hslToRgbStr($brandH, $brandSat, 50),
    '500' => hslToRgbStr($brandH, $brandSat, 40),
    '600' => hslToRgbStr($brandH, $brandSat, 33),
    '700' => hslToRgbStr($brandH, $brandSat, 26),
    '800' => hslToRgbStr($brandH, $brandSat, 19),
    '900' => hslToRgbStr($brandH, $brandSat, 12),
    '950' => hslToRgbStr($brandH, $brandSat, 6),
    'grad-light' => hslToRgbStr($brandH, min($brandSat, 70), 35),
    'grad-mid'   => hslToRgbStr($brandH, min($brandSat, 80), 25),
    'grad-dark'  => hslToRgbStr($brandH, min($brandSat, 75), 17),
];
$shadeCss = '';
foreach ($shades as $k => $rgb) {
    $hex = sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    $shadeCss .= "    --brand-$k: $hex;
";
    $shadeCss .= "    --brand-$k-rgb: {$rgb[0]}, {$rgb[1]}, {$rgb[2]};
";
}


// Parse analysis JSON
$analysis = [];
if (!empty($row['analysis_json'])) {
    $decoded = json_decode($row['analysis_json'], true);
    if (is_array($decoded)) $analysis = $decoded;
}

// Resolve display values
$mode          = $row['mode'] ?? 'recording';
$title         = trim($row['title'] ?? '') ?: ($analysis['title'] ?? 'Transcription');
$transcript    = $row['transcript_text'] ?? '';
$wordCount     = (int) ($row['word_count'] ?? 0);
$charCount     = (int) ($row['char_count'] ?? 0);
$duration      = (int) ($row['audio_duration_seconds'] ?? $row['timer_seconds'] ?? 0);
$whisperModel  = $row['whisper_model'] ?? 'turbo';
$language      = strtoupper($row['language'] ?? 'en');
$createdAt     = $row['created_at'] ?? date('Y-m-d H:i:s');
$createdDate   = date('F j, Y', strtotime($createdAt));
$createdTime   = date('g:i A', strtotime($createdAt));

// Share state
$isPublic    = (int) ($row['is_public'] ?? 0) === 1;
$publicToken = (string) ($row['public_token'] ?? '');
$publicUrl   = $publicToken ? ('https://' . $_SERVER['HTTP_HOST'] . '/api/report.php?t=' . $publicToken) : '';

// Resolve logo — use custom logo if it exists, fall back to default
$logoPath = file_exists(__DIR__ . '/../img/custom-logo.png') ? '/img/custom-logo.png' : '/img/logo.png';

// Mode-specific labels + hero background image
$modeLabels = [
    'recording' => ['label' => 'Audio Recording',      'icon' => '&#127908;', 'accent' => 'var(--brand-300)', 'hero' => '/img/brand/hero-microphone.jpg'],
    'meeting'   => ['label' => 'Meeting Transcription', 'icon' => '&#128722;', 'accent' => 'var(--brand-500)', 'hero' => '/img/brand/hero-meeting.jpg'],
    'learning'  => ['label' => 'Learning Analysis',    'icon' => '&#127891;', 'accent' => 'var(--brand-600)', 'hero' => '/img/brand/hero-learning.jpg'],
];
$modeInfo = $modeLabels[$mode] ?? $modeLabels['recording'];

// Format duration
$durationDisplay = '—';
if ($duration > 0) {
    $h = floor($duration / 3600);
    $m = floor(($duration % 3600) / 60);
    $s = $duration % 60;
    $durationDisplay = $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}

// ─── Render ─────────────────────────────────────────────────────────────
function e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function render_error(string $heading, string $message) {
    $logoPath = file_exists(__DIR__ . '/../img/custom-logo.png') ? '/img/custom-logo.png' : '/img/logo.png';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($heading) . '</title>';
    echo '<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:linear-gradient(165deg,#0f172a 0%,var(--brand-grad-mid) 50%,var(--brand-500) 100%);min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;color:#fff;overflow:hidden;position:relative}
canvas{position:fixed;inset:0;width:100%;height:100%;z-index:0;pointer-events:none;opacity:0.25}
.logo{position:relative;z-index:1;margin-bottom:28px}
.logo img{height:36px;filter:brightness(0) invert(1);opacity:0.8}
.card{position:relative;z-index:1;max-width:560px;width:100%;background:rgba(255,255,255,0.06);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.12);border-radius:24px;padding:52px 48px;text-align:center;box-shadow:0 24px 64px rgba(0,0,0,0.4)}
.icon{width:80px;height:80px;margin:0 auto 28px;border-radius:50%;background:linear-gradient(135deg,#ef4444,#dc2626);display:grid;place-items:center;font-size:36px;box-shadow:0 8px 24px rgba(220,38,38,0.4)}
h1{margin:0 0 14px;font-size:42px;font-weight:800;letter-spacing:-0.5px}
p{margin:0 0 32px;color:rgba(255,255,255,0.7);line-height:1.6;font-size:14px}
.btn{display:inline-block;padding:18px 40px;background:linear-gradient(135deg,var(--brand-300),var(--brand-500));border-radius:12px;color:#fff;text-decoration:none;font-weight:700;font-size:15px;letter-spacing:0.5px;text-transform:uppercase;box-shadow:0 8px 28px rgba(var(--brand-500-rgb), 0.45),inset 0 1px 0 rgba(255,255,255,0.18);position:relative;overflow:hidden;transition:all 0.3s}
.btn::before{content:"";position:absolute;top:0;left:-100%;width:60%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,0.25),transparent);transform:skewX(-20deg);animation:errShine 3.5s ease-in-out infinite}
@keyframes errShine{0%,70%{left:-100%}100%{left:160%}}
.btn:hover{transform:translateY(-2px);box-shadow:0 14px 40px rgba(var(--brand-500-rgb), 0.55)}
</style>';
    echo '</head><body>
<canvas id="matrixCanvas"></canvas>
<div class="logo"><img src="' . $logoPath . '" alt="Jason AI"></div>
<div class="card"><div class="icon">&#9888;</div><h1>' . htmlspecialchars($heading) . '</h1><p>' . htmlspecialchars($message) . '</p><a class="btn" href="https://jasonai.ca/">Go to Jason AI</a></div>
<script>
const c=document.getElementById("matrixCanvas"),x=c.getContext("2d");
c.width=window.innerWidth;c.height=window.innerHeight;
const cols=Math.floor(c.width/16),drops=Array(cols).fill(1);
const chars="JASONAI01アイウエオカキクケコ";
function draw(){x.fillStyle="rgba(var(--brand-950-rgb, 15,23,42), 0.06)";x.fillRect(0,0,c.width,c.height);
x.fillStyle="rgba(var(--brand-400-rgb), 0.35)";x.font="14px monospace";
for(let i=0;i<drops.length;i++){const t=chars[Math.floor(Math.random()*chars.length)];
x.fillText(t,i*16,drops[i]*16);if(drops[i]*16>c.height&&Math.random()>0.975)drops[i]=0;drops[i]++}}
setInterval(draw,45);
</script></body></html>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?></title>
<link rel="icon" href="/img/fav%20icon.png">
<style>
:root {
    --primary: <?= e($brandColor) ?>;
    --primary-rgb: <?= $primaryRgb ?>;
<?= $shadeCss ?>
    --primary-dark: var(--brand-grad-mid);
    --primary-light: var(--brand-300);
    --accent: <?= e($modeInfo['accent']) ?>;
    --bg: #eef2f7;
    --card: #ffffff;
    --ink: #0f172a;
    --ink-soft: #334155;
    --ink-muted: #64748b;
    --line: #e2e8f0;
    --line-soft: #f1f5f9;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    background: var(--bg);
    color: var(--ink);
    line-height: 1.6;
    -webkit-font-smoothing: antialiased;
}

/* ── Interactive top bar (hidden on print) ────────────────────────────── */
.topbar {
    position: sticky; top: 0; z-index: 50;
    background: linear-gradient(to bottom, var(--brand-grad-light) 0%, var(--brand-grad-mid) 50%, var(--brand-grad-dark) 100%);
    border-bottom: 1px solid rgba(255,255,255,0.08);
    padding: 12px max(24px, calc((100% - 1200px) / 2 + 24px));
    display: flex; align-items: center; justify-content: space-between;
    gap: 16px;
    box-shadow: 0 2px 16px rgba(0,0,0,0.18);
}
.topbar-brand { display: flex; align-items: center; gap: 14px; }
.topbar-brand-logo {
    height: 26px;
    max-width: 140px;
    width: auto;
    object-fit: contain;
    filter: brightness(0) invert(1);
    opacity: 0.96;
}
.topbar-brand-fallback {
    font-size: 18px; font-weight: 800; letter-spacing: 2px;
    text-transform: uppercase; color: #fff;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
.topbar-actions { display: flex; align-items: center; gap: 6px; }
.btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 9px 16px;
    border-radius: 9px;
    font-size: 13px; font-weight: 500; letter-spacing: 0.2px;
    text-decoration: none; cursor: pointer;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    border: 1px solid rgba(255,255,255,0.1);
    background: rgba(255,255,255,0.06);
    color: rgba(255,255,255,0.82);
    position: relative;
    overflow: hidden;
}
.btn svg {
    width: 16px; height: 16px;
    flex-shrink: 0;
    stroke: currentColor;
}
.btn:hover {
    background: rgba(255,255,255,0.15);
    color: #fff;
    border-color: rgba(255,255,255,0.25);
    transform: scale(1.05);
    box-shadow: 0 4px 16px rgba(0,0,0,0.22);
}
.btn-primary {
    background: linear-gradient(135deg, rgba(var(--brand-400-rgb), 0.22), rgba(var(--brand-500-rgb), 0.22));
    border-color: rgba(255,255,255,0.22);
    color: #fff;
}
.btn-primary:hover {
    background: linear-gradient(135deg, rgba(var(--brand-400-rgb), 0.4), rgba(var(--brand-500-rgb), 0.4));
    border-color: rgba(255,255,255,0.38);
}

/* Share button states */
.share-btn .share-icon-unlocked { display: none; }
.share-btn.is-public {
    background: linear-gradient(135deg, rgba(var(--brand-200-rgb), 0.35), rgba(var(--brand-300-rgb), 0.28));
    border-color: rgba(var(--brand-200-rgb), 0.55);
    color: #fff;
    box-shadow: 0 0 0 1px rgba(var(--brand-200-rgb), 0.25), 0 4px 14px rgba(var(--brand-300-rgb), 0.25);
}
.share-btn.is-public .share-icon-locked   { display: none; }
.share-btn.is-public .share-icon-unlocked { display: inline; }
.share-btn.is-private {
    background: rgba(255,255,255,0.06);
    color: rgba(255,255,255,0.85);
}

/* Share modal */
.share-backdrop {
    display: none;
    position: fixed; inset: 0;
    background: rgba(6, 13, 32, 0.65);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    z-index: 1000;
    align-items: center; justify-content: center;
    padding: 24px;
    animation: shareFadeBg 0.25s ease-out;
}
.share-backdrop.open { display: flex; }
@keyframes shareFadeBg { from { opacity: 0; } to { opacity: 1; } }

.share-modal {
    width: 100%;
    max-width: 540px;
    background:
        linear-gradient(165deg, var(--brand-grad-light) 0%, var(--brand-grad-mid) 45%, var(--brand-grad-dark) 80%, var(--brand-950) 100%);
    border: 1px solid rgba(255,255,255,0.18);
    border-radius: 22px;
    padding: 36px 36px 30px;
    color: #fff;
    box-shadow:
        0 30px 80px rgba(0,0,0,0.55),
        0 12px 30px rgba(0,0,0,0.35),
        inset 0 1px 0 rgba(255,255,255,0.18);
    position: relative;
    overflow: hidden;
    animation: shareCardIn 0.45s cubic-bezier(0.16, 1, 0.3, 1);
}
.share-modal::before {
    content: '';
    position: absolute; inset: 0;
    background:
        radial-gradient(circle at 18% 12%, rgba(var(--brand-400-rgb), 0.30) 0%, transparent 55%),
        radial-gradient(circle at 82% 88%, rgba(124,58,237,0.18) 0%, transparent 55%);
    pointer-events: none;
}
@keyframes shareCardIn {
    from { opacity: 0; transform: translateY(18px) scale(0.96); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
.share-modal > * { position: relative; z-index: 1; }
.share-modal h2 {
    margin: 0 0 6px;
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -0.3px;
}
.share-modal .share-sub {
    margin: 0 0 22px;
    font-size: 14px;
    color: rgba(255,255,255,0.72);
    line-height: 1.55;
}
.share-status {
    display: flex; align-items: center; gap: 14px;
    padding: 16px 18px;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.16);
    border-radius: 14px;
    margin-bottom: 20px;
}
.share-status-icon {
    width: 42px; height: 42px;
    border-radius: 12px;
    display: grid; place-items: center;
    background: linear-gradient(135deg, rgba(248,113,113,0.25), rgba(220,38,38,0.25));
    border: 1px solid rgba(248,113,113,0.4);
    flex-shrink: 0;
}
.share-status-icon svg { width: 22px; height: 22px; stroke: #fff; }
.share-status.is-public .share-status-icon {
    background: linear-gradient(135deg, rgba(var(--brand-200-rgb), 0.35), rgba(var(--brand-300-rgb), 0.30));
    border-color: rgba(var(--brand-200-rgb), 0.55);
}
.share-status-text { flex: 1; }
.share-status-title {
    font-size: 14px; font-weight: 700;
    margin-bottom: 2px;
}
.share-status-desc {
    font-size: 12px;
    color: rgba(255,255,255,0.68);
    line-height: 1.45;
}

/* Toggle switch */
.share-toggle {
    position: relative;
    width: 52px; height: 30px;
    flex-shrink: 0;
    cursor: pointer;
}
.share-toggle input { display: none; }
.share-toggle-track {
    position: absolute; inset: 0;
    background: rgba(255,255,255,0.18);
    border: 1px solid rgba(255,255,255,0.25);
    border-radius: 999px;
    transition: background 0.3s, border-color 0.3s;
}
.share-toggle-thumb {
    position: absolute;
    top: 3px; left: 3px;
    width: 22px; height: 22px;
    border-radius: 50%;
    background: #fff;
    transition: transform 0.32s cubic-bezier(0.34, 1.56, 0.64, 1);
    box-shadow: 0 2px 6px rgba(0,0,0,0.35);
}
.share-toggle input:checked ~ .share-toggle-track {
    background: linear-gradient(135deg, var(--brand-200), var(--brand-300));
    border-color: rgba(var(--brand-200-rgb), 0.65);
}
.share-toggle input:checked ~ .share-toggle-thumb {
    transform: translateX(22px);
}

.share-link-row {
    display: flex; gap: 8px;
    margin-bottom: 18px;
}
.share-link-input {
    flex: 1;
    background: rgba(0,0,0,0.30);
    border: 1px solid rgba(255,255,255,0.18);
    border-radius: 10px;
    padding: 11px 14px;
    color: #fff;
    font-size: 13px;
    font-family: 'SFMono-Regular', Consolas, Menlo, monospace;
    outline: none;
}
.share-link-input:focus { border-color: rgba(var(--brand-400-rgb), 0.7); }
.share-copy-btn {
    padding: 11px 16px;
    background: linear-gradient(135deg, var(--brand-300), var(--brand-500));
    color: #fff;
    border: 1px solid rgba(255,255,255,0.25);
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.share-copy-btn:hover { transform: scale(1.04); box-shadow: 0 6px 18px rgba(var(--brand-500-rgb), 0.5); }
.share-copy-btn.copied { background: linear-gradient(135deg, var(--brand-200), var(--brand-300)); }

.share-actions {
    display: flex; justify-content: flex-end; gap: 10px;
    margin-top: 6px;
}
.share-actions .btn-close-share {
    padding: 10px 22px;
    background: rgba(255,255,255,0.10);
    border: 1px solid rgba(255,255,255,0.22);
    border-radius: 10px;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.share-actions .btn-close-share:hover {
    background: rgba(255,255,255,0.18);
}

.share-link-disabled { opacity: 0.4; pointer-events: none; }

/* Charts grid */
.charts-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    margin-top: 8px;
}
.chart-card {
    background: linear-gradient(165deg, #ffffff 0%, #f8fafc 100%);
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 18px 20px 14px;
    box-shadow: 0 2px 10px rgba(var(--brand-950-rgb, 15,23,42), 0.04);
    position: relative;
    min-height: 240px;
}
.chart-card.chart-wide { grid-column: 1 / -1; }
.chart-card h3 {
    margin: 0 0 10px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.7px;
    color: var(--ink-muted);
}
.chart-card canvas { max-height: 220px !important; }
@media (max-width: 720px) {
    .charts-grid { grid-template-columns: 1fr; }
    .chart-card.chart-wide { grid-column: auto; }
}
@media (max-width: 600px) {
    .share-modal { padding: 28px 22px 22px; border-radius: 18px; }
    .share-modal h2 { font-size: 19px; }
    .share-link-row { flex-direction: column; }
}

/* Title edit button */
/* Constellation canvas on cover */
/* Constellation canvas — disabled */
.cover-constellation {
    display: none !important;
}
/* Edit tooltip */
.title-edit-wrap {
    position: relative; display: inline-block; margin-top: 8px; margin-bottom: 15px;
}
.title-edit-tooltip {
    position: absolute; bottom: calc(100% + 10px); left: 50%;
    transform: translateX(-50%) scale(0.8); opacity: 0;
    background: #fff; color: #334155; font-size: 13px; font-weight: 500;
    padding: 10px 18px; border-radius: 10px; white-space: nowrap;
    box-shadow: 0 8px 24px rgba(0,0,0,0.2);
    pointer-events: none;
    transition: opacity 0.4s ease, transform 0.5s cubic-bezier(0.22, 1, 0.36, 1);
}
.title-edit-tooltip::after {
    content: ''; position: absolute; top: 100%; left: 50%;
    transform: translateX(-50%);
    border: 6px solid transparent; border-top-color: #fff;
}
.title-edit-wrap:hover .title-edit-tooltip {
    opacity: 1; transform: translateX(-50%) scale(1);
}

.title-edit-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px; height: 32px;
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.2);
    background: rgba(255,255,255,0.08);
    color: rgba(255,255,255,0.7);
    cursor: pointer;
    transition: all 0.2s;
    margin-top: 8px;
}
.title-edit-btn:hover {
    background: rgba(255,255,255,0.18);
    color: #fff;
    transform: scale(1.08);
}
.title-edit-input {
    width: 96%;
    max-width: 1100px;
    min-width: 360px;
    padding: 16px 22px;
    background: rgba(255,255,255,0.12);
    border: 2px solid rgba(255,255,255,0.4);
    border-radius: 12px;
    color: #fff;
    font-size: 26px;
    font-weight: 800;
    text-align: center;
    font-family: inherit;
    letter-spacing: -0.3px;
    line-height: 1.3;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.title-edit-input:focus {
    border-color: rgba(var(--brand-300-rgb), 0.7);
    box-shadow: 0 0 0 4px rgba(var(--brand-400-rgb), 0.2);
}
.title-edit-hint {
    font-size: 12px;
    color: rgba(255,255,255,0.5);
    margin-top: 6px;
}

/* ── Report frame ─────────────────────────────────────────────────────── */
.report-wrapper {
    max-width: 880px;
    margin: 32px auto 64px;
    padding: 0 24px;
}
.report-section {
    background: var(--card);
    border-radius: 20px;
    box-shadow:
        0 4px 28px rgba(var(--primary-rgb), 0.06),
        0 1px 3px rgba(var(--brand-900-rgb, 15,31,64), 0.05);
    margin-bottom: 24px;
    overflow: hidden;
    position: relative;
    border: 1px solid rgba(var(--primary-rgb), 0.06);
}
/* Unifying brand accent strip at top of each content card */
.report-section:not(.metrics-section):not(:first-child)::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg,
        rgba(var(--primary-rgb), 0.85) 0%,
        rgba(var(--primary-rgb), 0.35) 45%,
        rgba(var(--primary-rgb), 0) 100%);
    pointer-events: none;
}
.metrics-section {
    overflow: visible !important;
}
.metrics-section::before { display: none !important; }
}

/* ── Cover page ───────────────────────────────────────────────────────── */
.cover {
    position: relative !important;
    padding: 72px 48px 56px !important;
    text-align: center !important;
    color: #fff !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: center !important;
    background-color: var(--brand-grad-dark) !important;
    background-image:
        linear-gradient(165deg,
            rgba(var(--brand-950-rgb, 15,23,42), 0.80) 0%,
            rgba(var(--brand-700-rgb), 0.72) 35%,
            rgba(var(--brand-500-rgb), 0.65) 70%,
            rgba(var(--brand-400-rgb), 0.58) 100%),
        url('<?= e($modeInfo['hero']) ?>') !important;
    background-size: cover, cover !important;
    background-position: center, center !important;
    background-repeat: no-repeat, no-repeat !important;
}
.cover > * { text-align: center !important; align-self: center !important; }
.cover-logo { margin: 0 auto 32px !important; }
.cover-badge { margin: 0 auto 24px !important; }
.cover-title { margin-left: auto !important; margin-right: auto !important; }
.cover-meta { margin: 32px auto 0 !important; justify-content: center !important; }
.cover::before {
    content: '';
    position: absolute; inset: 0;
    background-image: radial-gradient(circle at 25% 35%, rgba(var(--brand-400-rgb), 0.18) 0%, transparent 55%),
                      radial-gradient(circle at 75% 65%, rgba(var(--brand-500-rgb), 0.12) 0%, transparent 55%);
    pointer-events: none;
}
.cover > * { position: relative; z-index: 1; }

.cover-logo {
    margin: 0 auto 28px;
    height: 56px;
    display: flex; align-items: center; justify-content: center;
}
.cover-logo img { height: 100%; width: auto; opacity: 0.95; }
.cover-logo-text {
    font-size: 22px; font-weight: 800; letter-spacing: 2px;
    text-transform: uppercase; color: rgba(255,255,255,0.95);
}

.cover-badge {
    display: inline-block;
    padding: 8px 22px;
    background: rgba(0,0,0,0.25);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 100px;
    font-size: 11px; font-weight: 700; letter-spacing: 2.5px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.92);
    margin-bottom: 24px;
}

.cover-divider {
    width: 56px; height: 2px; margin: 0 auto 22px;
    background: rgba(255,255,255,0.3);
    border-radius: 1px;
}

.cover-title {
    margin: 0 0 14px;
    font-size: 36px; font-weight: 800;
    letter-spacing: -0.6px; line-height: 1.2;
    color: #fff;
    max-width: 640px; margin-left: auto; margin-right: auto;
}

.cover-subtitle {
    font-size: 14px;
    color: rgba(255,255,255,0.65);
    letter-spacing: 0.3px;
    margin-bottom: 36px;
}

.cover-url {
    margin-top: 50px;
    font-size: 11px;
    color: rgba(255,255,255,0.7);
    letter-spacing: 0.3px;
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.2);
    padding: 8px 24px;
    border-radius: 20px;
    text-align: center;
    display: none;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}
@media print {
    .cover-url { display: inline-block; }
}

.cover-meta {
    display: flex; justify-content: center; gap: 48px;
    flex-wrap: wrap;
    padding-top: 28px;
    border-top: 1px solid rgba(255,255,255,0.15);
}
.cover-meta-item { text-align: center; }
.cover-meta-label {
    font-size: 10px; font-weight: 700; letter-spacing: 1.5px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.5);
    margin-bottom: 6px;
}
.cover-meta-value {
    font-size: 14px; color: rgba(255,255,255,0.95); font-weight: 600;
}

/* ── Table of Contents (PDF only) ────────────────────────────────────── */
.toc-section {
    display: none; /* Hidden on screen — only shows in PDF */
}
@media print {
    .toc-section {
        display: flex !important;
        flex-direction: column;
        page-break-after: always;
        break-after: page;
        background:
            linear-gradient(165deg,
                rgba(var(--brand-950-rgb, 15,23,42), 0.94) 0%,
                rgba(var(--brand-700-rgb), 0.90) 35%,
                rgba(var(--brand-500-rgb), 0.84) 70%,
                rgba(var(--brand-400-rgb), 0.78) 100%) !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color: #fff;
        padding: 28px 48px;
        height: calc(100vh - 26mm);
        max-height: calc(100vh - 26mm);
        overflow: hidden;
    }
    .toc-logo {
        height: 44px !important;
        width: auto !important;
        max-width: 280px !important;
        object-fit: contain;
        filter: brightness(0) invert(1);
        opacity: 0.7;
        margin-bottom: 20px;
        display: block;
    }
    .toc-title { font-size: 28px; font-weight: 800; letter-spacing: -0.5px; margin: 0 0 6px; color: #fff; }
    .toc-subtitle { font-size: 13px; color: rgba(255,255,255,0.55); margin: 0 0 18px; }
    .toc-list { list-style: none; padding: 0; margin: 0; flex: 1; display: flex; flex-direction: column; justify-content: flex-start; }
    .toc-item {
        display: block;
        border-bottom: 1px solid rgba(255,255,255,0.08);
    }
    .toc-item:last-child { border-bottom: none; }
    .toc-item-link {
        display: flex; justify-content: space-between; align-items: baseline;
        color: rgba(255,255,255,0.9);
        text-decoration: none;
        width: 100%;
    }
    .toc-item-num { color: rgba(255,255,255,0.4); font-weight: 700; margin-right: 14px; letter-spacing: 0.5px; flex-shrink: 0; }
    .toc-item-label { flex-shrink: 0; }
    .toc-item-dots { flex: 1; border-bottom: 1px dotted rgba(255,255,255,0.22); margin: 0 12px; min-width: 30px; transform: translateY(-3px); }
    .toc-item-link::after {
        content: target-counter(attr(href), page);
        color: rgba(255,255,255,0.75);
        font-weight: 600;
        font-variant-numeric: tabular-nums;
        flex-shrink: 0;
        min-width: 24px;
        text-align: right;
    }

    /* Size tiers — auto-picked by PHP based on item count */
    .toc-roomy .toc-item { padding: 8px 0; font-size: 14px; }
    .toc-roomy .toc-item-num { font-size: 12px; }
    .toc-medium .toc-item { padding: 5px 0; font-size: 12.5px; }
    .toc-medium .toc-item-num { font-size: 11px; }
    .toc-medium .toc-title { font-size: 24px; }
    .toc-medium .toc-logo { margin-bottom: 12px; }
    .toc-medium .toc-subtitle { margin-bottom: 12px; }
    .toc-tight .toc-item { padding: 3px 0; font-size: 11.5px; }
    .toc-tight .toc-item-num { font-size: 10px; }
    .toc-tight .toc-title { font-size: 22px; }
    .toc-tight .toc-logo { margin-bottom: 10px; height: 18px !important; max-width: 120px !important; }
    .toc-tight .toc-subtitle { margin-bottom: 10px; font-size: 12px; }

    /* Report Created pill at the bottom of the TOC page */
    .toc-created-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        align-self: center;
        margin: auto auto 0 auto;
        padding: 9px 22px;
        background: rgba(255,255,255,0.10);
        border: 1px solid rgba(255,255,255,0.25);
        border-radius: 100px;
        color: rgba(255,255,255,0.88);
        font-size: 11px;
        font-weight: 500;
        letter-spacing: 0.3px;
        white-space: nowrap;
    }
    .toc-created-icon {
        font-size: 12px;
        opacity: 0.75;
    }
}
/* Hide the pill on screen — PDF only */
.toc-created-pill { display: none; }


/* Chart card subtitle */
.chart-card .chart-subtitle {
    font-size: 12px;
    color: #64748b;
    line-height: 1.5;
    margin: 2px 0 14px;
}
.chart-card .chart-subtitle strong { color: var(--brand-700); font-weight: 700; }

/* ── Stat cards grid ──────────────────────────────────────────────────── */
.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 14px;
    padding: 40px 48px;
}
.metric-card {
    background: linear-gradient(to bottom, var(--brand-grad-light) 0%, var(--brand-grad-mid) 50%, var(--brand-grad-dark) 100%);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 14px;
    padding: 22px 20px;
    text-align: center;
    box-shadow:
        0 4px 16px rgba(var(--brand-900-rgb, 15,31,64), 0.30),
        inset 0 1px 0 rgba(255,255,255,0.10);
    position: relative;
    overflow: visible;
    cursor: default;
    transition: transform 1s cubic-bezier(0.22, 1, 0.36, 1),
                box-shadow 1s ease,
                filter 1s ease;
    z-index: 1;
    color: #ffffff;
}
.metric-card:hover {
    transform: translateY(-4px) scale(1.04);
    box-shadow:
        0 14px 34px rgba(var(--brand-900-rgb, 15,31,64), 0.45),
        inset 0 1px 0 rgba(255,255,255,0.18);
    z-index: 5;
}
/* Blur siblings when one card is hovered */
.metrics-grid:hover .metric-card:not(:hover) {
    filter: blur(2px);
    opacity: 0.7;
}
/* Tooltip */
.metric-tooltip {
    position: absolute;
    bottom: calc(100% + 14px);
    left: 50%; transform: translateX(-50%) scale(0.85);
    background: #0f172a;
    color: #e2e8f0;
    font-size: 12px; font-weight: 500;
    padding: 10px 16px;
    border-radius: 10px;
    width: max-content;
    max-width: 280px;
    min-width: 180px;
    white-space: normal;
    text-align: center;
    line-height: 1.5;
    box-shadow: 0 8px 24px rgba(0,0,0,0.3);
    opacity: 0;
    pointer-events: none;
    transition: opacity 1s ease, transform 1s cubic-bezier(0.22, 1, 0.36, 1);
    z-index: 100;
}
.metric-tooltip::after {
    content: ''; position: absolute; top: 100%; left: 50%;
    transform: translateX(-50%);
    border: 6px solid transparent; border-top-color: #0f172a;
}
.metric-card:hover .metric-tooltip {
    opacity: 1; transform: translateX(-50%) scale(1);
}
.metric-value {
    font-size: 28px; font-weight: 800;
    color: #ffffff;
    letter-spacing: -0.6px;
    line-height: 1.1;
    margin-bottom: 4px;
    text-shadow: 0 1px 2px rgba(0,0,0,0.18);
}
.metric-label {
    font-size: 11px; font-weight: 700; letter-spacing: 1.2px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.82);
}

/* ── Content sections ─────────────────────────────────────────────────── */
.section-pad { padding: 40px 48px; }
.section-title {
    font-size: 22px; font-weight: 800;
    letter-spacing: 1.2px; text-transform: uppercase;
    color: var(--primary-dark);
    margin: 0 0 24px;
    padding-bottom: 14px;
    display: flex; align-items: center; gap: 14px;
    border-bottom: 1px solid rgba(var(--primary-rgb), 0.10);
}
.section-title-bar {
    width: 4px; height: 22px; border-radius: 2px;
    background: linear-gradient(180deg,
        var(--brand-grad-light) 0%,
        var(--brand-grad-mid) 50%,
        var(--brand-grad-dark) 100%);
    flex-shrink: 0;
}
.section-title-h1 {
    font-size: 24px; font-weight: 800; letter-spacing: -0.4px;
    color: var(--ink);
    margin: 0 0 20px;
}

.callout {
    background: linear-gradient(135deg,
        rgba(var(--primary-rgb), 0.04) 0%,
        rgba(var(--primary-rgb), 0.01) 100%);
    border: 1px solid rgba(var(--primary-rgb), 0.10);
    border-left: 3px solid var(--primary);
    border-radius: 0 14px 14px 0;
    padding: 22px 26px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(var(--primary-rgb), 0.04);
}
.callout p { margin: 0; color: var(--ink-soft); font-size: 15px; line-height: 1.7; }

.bullet-list { list-style: none; padding: 0; margin: 0; }
.bullet-list li {
    position: relative;
    padding: 14px 18px 14px 42px;
    color: var(--ink-soft);
    font-size: 15px; line-height: 1.6;
    border-bottom: 1px solid var(--line-soft);
}
.bullet-list li:last-child { border-bottom: none; }
.bullet-list li::before {
    content: '';
    position: absolute; left: 18px; top: 22px;
    width: 8px; height: 8px; border-radius: 50%;
    background: linear-gradient(135deg, var(--primary-light), var(--primary));
    box-shadow: 0 2px 6px rgba(var(--primary-rgb), 0.4);
}

.action-list { list-style: none; padding: 0; margin: 0; }
.action-list li {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 20px;
    background: linear-gradient(135deg, rgba(var(--brand-500-rgb),0.05), rgba(var(--brand-500-rgb),0.02));
    border: 1px solid rgba(var(--brand-500-rgb),0.12);
    border-left: 3px solid var(--primary);
    border-radius: 12px;
    margin-bottom: 10px;
    color: var(--ink-soft);
    font-size: 15px; line-height: 1.6;
}
/* Formatted transcript paragraphs */

.transcript-formatted .ts-block,
.transcript-formatted .sp-block {
    margin-bottom: 18px;
    padding-left: 0;
}
.transcript-formatted .ts-label,
.transcript-formatted .sp-label {
    display: block;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: var(--brand-600);
    margin-bottom: 4px;
    font-variant-numeric: tabular-nums;
}
.transcript-formatted .sp-label {
    letter-spacing: 0.4px;
    text-transform: none;
    font-size: 13px;
}

.transcript-formatted {
    white-space: normal !important;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
}
.transcript-formatted p {
    margin: 0 0 18px;
    text-align: left;
    line-height: 1.8;
    color: var(--ink-soft);
}
.transcript-formatted p:last-child { margin-bottom: 0; }
.transcript-formatted p:first-child strong.ts-title { font-weight: 800; color: var(--ink); }

/* ── Quiz Modal — Light Theme ─────────────────────────────────── */
.quiz-backdrop {
    display: none;
    position: fixed; inset: 0;
    background: rgba(var(--brand-950-rgb, 15,23,42), 0.5);
    backdrop-filter: blur(8px);
    z-index: 10000;
    align-items: center; justify-content: center;
    padding: 24px;
}
.quiz-backdrop.open { display: flex; }

.quiz-modal {
    width: 100%; max-width: 640px; max-height: 88vh;
    background: linear-gradient(165deg, var(--brand-grad-light) 0%, var(--brand-grad-mid) 45%, var(--brand-grad-dark) 80%, var(--brand-950) 100%);
    border: 1px solid rgba(var(--brand-300-rgb), 0.22);
    border-radius: 22px;
    box-shadow: 0 32px 80px rgba(0,0,0,0.4);
    display: flex; flex-direction: column;
    overflow: hidden;
    transform: scale(0.3); opacity: 0;
    animation: quizZoomIn 1s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}
.quiz-modal.closing {
    animation: quizZoomOut 1s cubic-bezier(0.55, 0, 1, 0.45) forwards;
}
.quiz-backdrop.fading {
    opacity: 0;
    transition: opacity 1.5s ease;
}
@keyframes quizZoomIn {
    0%   { transform: scale(0.3); opacity: 0; }
    55%  { opacity: 1; transform: scale(1.03); }
    75%  { transform: scale(0.98); }
    100% { opacity: 1; transform: scale(1); }
}
@keyframes quizZoomOut {
    0%   { transform: scale(1); opacity: 1; }
    20%  { transform: scale(1.03); }
    100% { transform: scale(0.3); opacity: 0; }
}

.quiz-header {
    padding: 28px 32px 20px;
    background: transparent;
    text-align: center;
    position: relative;
    flex-shrink: 0;
}
.quiz-header::after {
    content: '';
    position: absolute; left: 0; right: 0; bottom: 0; height: 1px;
    background: linear-gradient(90deg, transparent, rgba(var(--brand-300-rgb), 0.4), transparent);
}
.quiz-header-icon {
    font-size: 42px; font-weight: 900;
    color: var(--brand-300);
    margin-bottom: 6px;
    text-shadow: 0 0 20px rgba(var(--brand-300-rgb), 0.4);
}
.quiz-header h2 {
    font-size: 36px; font-weight: 800; color: #ffffff;
    letter-spacing: -0.5px; margin: 0 0 4px;
}
.quiz-header p {
    font-size: 14px; color: rgba(255,255,255,0.6); margin: 0;
}
/* Progress bar in quiz header */
.quiz-progress-wrap {
    margin-top: 16px;
    height: 6px;
    background: rgba(255,255,255,0.12);
    border-radius: 6px;
    overflow: hidden;
}
.quiz-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--brand-400), #a78bfa);
    border-radius: 6px;
    transition: width 0.5s cubic-bezier(0.22, 1, 0.36, 1);
}
.quiz-progress-text {
    margin-top: 8px;
    font-size: 12px;
    color: rgba(255,255,255,0.45);
    font-weight: 500;
}
.quiz-close {
    position: absolute; top: 16px; right: 16px;
    width: 34px; height: 34px; border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.15); background: rgba(255,255,255,0.08);
    color: rgba(255,255,255,0.7); cursor: pointer; font-size: 18px;
    display: flex; align-items: center; justify-content: center;
    transition: all 0.2s;
}
.quiz-close:hover { background: rgba(255,255,255,0.18); color: #fff; }

.quiz-body {
    flex: 1; overflow-y: auto; padding: 24px 32px 32px;
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 transparent;
}
.quiz-body::-webkit-scrollbar { width: 5px; }
.quiz-body::-webkit-scrollbar-track { background: transparent; }
.quiz-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 5px; }
.quiz-body::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.25); }

/* Loading state */
.quiz-loading {
    text-align: center;
    padding: 10px 20px 20px;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    min-height: 280px;
    position: relative;
}
.quiz-brain-wrap {
    position: relative;
    width: 200px; height: 200px;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 20px;
}
.quiz-brain-wrap canvas {
    position: absolute; inset: -30px;
    width: calc(100% + 60px); height: calc(100% + 60px);
    pointer-events: none;
}
.quiz-loading-icon {
    font-size: 160px;
    line-height: 1;
    animation: quizPulse 1.6s ease-in-out infinite;
    filter: drop-shadow(0 0 30px rgba(255,255,255,0.3));
    position: relative;
    z-index: 1;
}
@keyframes quizPulse {
    0%, 100% { transform: scale(1); filter: drop-shadow(0 0 30px rgba(255,255,255,0.25)); }
    50% { transform: scale(1.06); filter: drop-shadow(0 0 50px rgba(255,255,255,0.5)); }
}
.quiz-loading p { color: rgba(255,255,255,0.75); font-size: 16px; font-weight: 500; margin: 0; }
.quiz-loading-dots { display: flex; gap: 8px; justify-content: center; margin-top: 20px; }
.quiz-loading-dots span {
    width: 10px; height: 10px; border-radius: 50%; background: rgba(255,255,255,0.5);
    animation: quizDotBounce 1.4s ease-in-out infinite;
}
.quiz-loading-dots span:nth-child(2) { animation-delay: 0.16s; }
.quiz-loading-dots span:nth-child(3) { animation-delay: 0.32s; }
@keyframes quizDotBounce {
    0%, 80%, 100% { transform: translateY(0); opacity: 0.4; }
    40% { transform: translateY(-14px); opacity: 1; }
}

/* Questions — blur unanswered, animate answers */
.quiz-question { margin-bottom: 24px; transition: filter 0.5s ease, opacity 0.5s ease; }
.quiz-question.blurred {
    filter: blur(6px);
    opacity: 0.4;
    pointer-events: none;
    user-select: none;
}
.quiz-question.current { filter: none; opacity: 1; pointer-events: auto; }
.quiz-question.answered { filter: none; opacity: 1; pointer-events: none; }

/* Correct answer celebration */
@keyframes quizCorrectBounce {
    0% { transform: scale(1); }
    30% { transform: scale(1.03); }
    50% { transform: scale(0.98); }
    70% { transform: scale(1.01); }
    100% { transform: scale(1); }
}
@keyframes quizCorrectGlow {
    0% { box-shadow: 0 0 0 0 rgba(34,197,94,0.4); }
    50% { box-shadow: 0 0 20px 4px rgba(34,197,94,0.3); }
    100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); }
}
.quiz-question.answer-correct {
    animation: quizCorrectBounce 0.5s ease, quizCorrectGlow 1s ease;
    border-radius: 14px;
}

/* Wrong answer shake */
@keyframes quizWrongShake {
    0%, 100% { transform: translateX(0); }
    15% { transform: translateX(-6px); }
    30% { transform: translateX(6px); }
    45% { transform: translateX(-4px); }
    60% { transform: translateX(4px); }
    75% { transform: translateX(-2px); }
    90% { transform: translateX(2px); }
}
@keyframes quizWrongGlow {
    0% { box-shadow: 0 0 0 0 rgba(239,68,68,0.4); }
    50% { box-shadow: 0 0 20px 4px rgba(239,68,68,0.3); }
    100% { box-shadow: 0 0 0 0 rgba(239,68,68,0); }
}
.quiz-question.answer-wrong {
    animation: quizWrongShake 0.5s ease, quizWrongGlow 1s ease;
    border-radius: 14px;
}

/* Explanation slide-open */
.quiz-explanation {
    margin-top: 10px; padding: 12px 16px; border-radius: 10px;
    font-size: 13px; line-height: 1.6;
    max-height: 0; overflow: hidden; opacity: 0;
    transition: max-height 0.5s ease, opacity 0.4s ease, padding 0.4s ease;
    padding: 0 16px;
}
.quiz-explanation.visible {
    max-height: 200px; opacity: 1; padding: 12px 16px;
}
.quiz-q-num {
    display: inline-block; font-size: 10px; font-weight: 800;
    letter-spacing: 1px; color: var(--brand-300);
    background: rgba(var(--brand-300-rgb), 0.12); padding: 4px 12px;
    border-radius: 6px; margin-bottom: 10px;
}
.quiz-q-text {
    color: #ffffff; font-size: 16px; font-weight: 600;
    line-height: 1.5; margin: 0 0 14px;
}
.quiz-options { display: flex; flex-direction: column; gap: 8px; }
.quiz-option {
    width: 100%; text-align: left;
    padding: 13px 18px; border-radius: 11px;
    background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.14);
    color: rgba(255,255,255,0.88); font-size: 14px; font-weight: 500;
    cursor: pointer; transition: all 0.2s; font-family: inherit;
}
.quiz-option:hover { background: rgba(255,255,255,0.12); border-color: rgba(255,255,255,0.25); }
.quiz-option.quiz-correct {
    background: rgba(34,197,94,0.18); border-color: rgba(74,222,128,0.5); color: #4ade80;
}
.quiz-option.quiz-wrong {
    background: rgba(239,68,68,0.18); border-color: rgba(248,113,113,0.5); color: #f87171;
}
.quiz-explanation {
    margin-top: 10px; padding: 12px 16px; border-radius: 10px;
    font-size: 13px; line-height: 1.6;
}
.quiz-exp-correct { background: rgba(34,197,94,0.1); color: rgba(255,255,255,0.8); border: 1px solid rgba(74,222,128,0.25); }
.quiz-exp-wrong { background: rgba(239,68,68,0.1); color: rgba(255,255,255,0.8); border: 1px solid rgba(248,113,113,0.25); }

/* Score + actions */
.quiz-score-box {
    text-align: center; padding: 32px 28px; margin-top: 20px;
    background: linear-gradient(to bottom, var(--brand-300) 0%, var(--brand-500) 50%, var(--brand-grad-mid) 100%);
    border: 1px solid rgba(var(--brand-300-rgb), 0.35);
    border-radius: 16px;
    box-shadow: 0 8px 28px rgba(var(--brand-500-rgb), 0.35), inset 0 1px 0 rgba(255,255,255,0.15);
    position: relative;
    overflow: hidden;
}
.quiz-score-box::before {
    content: '';
    position: absolute; top: 0; left: -100%;
    width: 60%; height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
    transform: skewX(-20deg);
    animation: quizScoreShine 3s ease-in-out infinite;
    pointer-events: none;
}
@keyframes quizScoreShine {
    0%, 70% { left: -100%; }
    100% { left: 160%; }
}
.quiz-score-num { font-size: 48px; font-weight: 800; color: #ffffff; text-shadow: 0 2px 12px rgba(0,0,0,0.2); }
.quiz-score-label { font-size: 14px; color: rgba(255,255,255,0.7); margin: 6px 0 22px; }
.quiz-actions { display: flex; gap: 10px; justify-content: center; }
.quiz-btn-retake {
    padding: 12px 24px; border-radius: 10px; border: none;
    background: linear-gradient(135deg, var(--brand-400), var(--brand-500), var(--brand-600));
    color: #fff; font-size: 14px; font-weight: 600;
    cursor: pointer; font-family: inherit; transition: all 0.2s;
    box-shadow: 0 4px 14px rgba(var(--brand-600-rgb), 0.35), inset 0 1px 0 rgba(255,255,255,0.18);
    position: relative; overflow: hidden;
}
.quiz-btn-retake::before {
    content: ''; position: absolute; top: 0; left: -100%;
    width: 60%; height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
    transform: skewX(-20deg); pointer-events: none;
}
.quiz-btn-retake:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(var(--brand-600-rgb), 0.45); }
.quiz-btn-retake:hover::before { animation: jai-btn-shine 0.8s ease forwards; }
.quiz-btn-close {
    padding: 12px 24px; border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.2); background: rgba(255,255,255,0.08);
    color: #fff; font-size: 14px; font-weight: 600;
    cursor: pointer; font-family: inherit; transition: all 0.2s;
}
.quiz-btn-close:hover { background: rgba(255,255,255,0.15); }

/* History modal (dark theme) */
.quiz-history-item {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px; border-radius: 10px;
    background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12);
    margin-bottom: 8px; cursor: pointer;
    transition: all 0.3s ease;
    color: rgba(255,255,255,0.88);
}
.quiz-history-item:hover { background: rgba(255,255,255,0.12); border-color: rgba(255,255,255,0.25); }
.quiz-history-item strong { color: #fff; }
.quiz-history-date { font-size: 13px; color: rgba(255,255,255,0.5); }
.quiz-history-score { font-size: 16px; font-weight: 700; }
/* Smooth height transition for quiz history modal */
.share-modal {
    transition: max-height 1s cubic-bezier(0.22, 1, 0.36, 1);
}
/* History modal close button */
.quiz-history-close {
    display: inline-flex; align-items: center; justify-content: center;
    padding: 10px 24px; border-radius: 10px;
    background: rgba(255,255,255,0.10); border: 1px solid rgba(255,255,255,0.22);
    color: #fff; font-size: 13px; font-weight: 600;
    cursor: pointer; font-family: inherit; transition: all 0.2s;
}
.quiz-history-close:hover { background: rgba(255,255,255,0.18); }

/* ── Celebration Overlay ──────────────────────────────────────── */
.celebration-overlay {
    position: fixed; inset: 0; z-index: 99999;
    background: linear-gradient(165deg, var(--brand-grad-light) 0%, var(--brand-grad-mid) 45%, var(--brand-grad-dark) 80%, var(--brand-950) 100%);
    display: flex; align-items: center; justify-content: center;
    opacity: 0; transform: scale(0.8);
    transition: opacity 0.8s ease, transform 1s cubic-bezier(0.22, 1, 0.36, 1);
}
.celebration-overlay.active { opacity: 1; transform: scale(1); }
.celebration-overlay canvas {
    position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; z-index: 0;
}
.celebration-content {
    position: relative; z-index: 1; text-align: center;
    max-height: 90vh; overflow-y: auto;
    scrollbar-width: thin; scrollbar-color: rgba(0,0,0,0.1) transparent;
}
.celebration-content::-webkit-scrollbar { width: 5px; }
.celebration-content::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.1); border-radius: 5px; }
/* Light-themed results card in the center */
.celebration-card {
    background: #f1f5f9;
    border-radius: 24px;
    max-width: 520px;
    margin: 0 auto;
    padding: 40px 36px 36px;
    box-shadow: 0 24px 64px rgba(0,0,0,0.3);
    text-align: center;
}
/* Celebration close — slide up like a door */
.celebration-overlay.closing {
    transform: translateY(-100vh) !important;
    transition: transform 1.2s cubic-bezier(0.55, 0, 1, 0.45) !important;
    opacity: 1 !important;
}
.celebration-card {
    background: linear-gradient(165deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.02) 100%) !important;
    backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,0.18);
    box-shadow: 0 24px 64px rgba(0,0,0,0.45), inset 0 1px 0 rgba(255,255,255,0.12) !important;
    padding: 48px 36px 36px !important;
    text-align: center;
    color: #fff;
}
.celebration-icon-wrap {
    display: flex; justify-content: center; align-items: center;
    margin-bottom: 18px;
    animation: celebIcon 0.9s cubic-bezier(0.34, 1.56, 0.64, 1) both;
}
.celebration-icon {
    color: #ffffff;
    filter: drop-shadow(0 6px 20px rgba(255,255,255,0.35)) drop-shadow(0 0 24px rgba(var(--brand-300-rgb), 0.55));
}
.tier-perfect    .celebration-icon { color: #fde68a; filter: drop-shadow(0 6px 20px rgba(253,224,71,0.55)); }
.tier-excellent  .celebration-icon { color: #fcd34d; filter: drop-shadow(0 6px 20px rgba(253,224,71,0.45)); }
.tier-good       .celebration-icon { color: #e5e7eb; filter: drop-shadow(0 6px 20px rgba(255,255,255,0.35)); }
.tier-okay       .celebration-icon { color: #d8b4fe; filter: drop-shadow(0 6px 20px rgba(216,180,254,0.35)); }
.tier-tryagain   .celebration-icon { color: #86efac; filter: drop-shadow(0 6px 20px rgba(134,239,172,0.35)); }

@keyframes celebIcon { from { transform: scale(0.4) rotate(-15deg); opacity: 0; } to { transform: scale(1) rotate(0); opacity: 1; } }

.celebration-stars {
    display: flex; justify-content: center; gap: 6px;
    margin: 0 auto 14px;
    color: #fcd34d;
    animation: celebSlideUp 0.6s ease 0.2s both;
}
.celebration-star.off { color: rgba(255,255,255,0.22); }
.celebration-star.on { filter: drop-shadow(0 0 6px rgba(252,211,77,0.7)); }

.celebration-title {
    font-size: 38px !important; font-weight: 800;
    color: #ffffff !important;
    margin: 0 0 10px !important;
    letter-spacing: -0.5px;
    text-shadow: 0 2px 18px rgba(0,0,0,0.3);
    animation: celebSlideUp 0.6s ease 0.3s both;
}
.celebration-subtitle {
    font-size: 15px; color: rgba(255,255,255,0.80) !important;
    max-width: 420px; margin: 0 auto 26px !important; line-height: 1.65;
    animation: celebSlideUp 0.6s ease 0.4s both;
}
.celebration-score-circle {
    width: 150px; height: 150px; border-radius: 50%; margin: 0 auto 24px;
    background: linear-gradient(135deg, rgba(255,255,255,0.10), rgba(255,255,255,0.02)) !important;
    border: 3px solid rgba(255,255,255,0.30) !important;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    position: relative;
    box-shadow: 0 0 0 6px rgba(255,255,255,0.04), 0 12px 36px rgba(0,0,0,0.35), inset 0 2px 0 rgba(255,255,255,0.18);
    animation: celebScore 0.8s cubic-bezier(0.34, 1.56, 0.64, 1) 0.5s both;
}
.celebration-score-num {
    font-size: 52px !important; font-weight: 800 !important;
    color: #ffffff !important;
    line-height: 1;
    text-shadow: 0 2px 10px rgba(0,0,0,0.25);
}
.celebration-score-num span {
    font-size: 22px !important; font-weight: 500 !important;
    color: rgba(255,255,255,0.55) !important;
}
.celebration-score-pct {
    font-size: 13px; font-weight: 700; letter-spacing: 1.5px;
    color: rgba(255,255,255,0.70);
    margin-top: 4px;
    text-transform: uppercase;
}
.celebration-card .quiz-btn-close {
    background: rgba(255,255,255,0.10) !important;
    border-color: rgba(255,255,255,0.22) !important;
    color: rgba(255,255,255,0.85) !important;
}
.celebration-ai-summary { text-align: left; margin-top: 4px; }
.celebration-ai-summary:empty { display: none; }
.celebration-ai-summary > div {
    background: rgba(255,255,255,0.94) !important;
    border: 1px solid rgba(255,255,255,0.25) !important;
    color: #0f172a;
}
.celebration-card .quiz-btn-close:hover {
    background: rgba(255,255,255,0.18) !important;
}
.celebration-emoji { font-size: 72px; margin-bottom: 16px; }
.celebration-title {
    font-size: 32px; font-weight: 800; color: #0f172a;
    margin: 0 0 10px; letter-spacing: -0.5px;
}
.celebration-subtitle {
    font-size: 15px; color: #64748b;
    max-width: 400px; margin: 0 auto 24px; line-height: 1.7;
}
.celebration-actions { display: flex; gap: 12px; justify-content: center; margin-top: 24px; }
/* Override buttons for light card */
.celebration-card .quiz-btn-retake {
    background: linear-gradient(135deg, var(--brand-700), var(--brand-600), var(--brand-500));
    box-shadow: 0 4px 14px rgba(var(--brand-600-rgb), 0.3);
}
.celebration-card .quiz-btn-close {
    background: #f1f5f9; border-color: #e2e8f0; color: #475569;
}
.celebration-ai-summary { text-align: left; margin-top: 4px; }
.celebration-ai-summary:empty { display: none; }
.celebration-ai-summary > div {
    background: rgba(255,255,255,0.94) !important;
    border: 1px solid rgba(255,255,255,0.25) !important;
    color: #0f172a;
}
.celebration-card .quiz-btn-close:hover { background: #e2e8f0; }
@keyframes celebEmoji { from { transform: scale(0) rotate(-20deg); opacity: 0; } to { transform: scale(1) rotate(0); opacity: 1; } }
@keyframes celebScore { from { transform: scale(0.5); opacity: 0; } to { transform: scale(1); opacity: 1; } }
@keyframes celebSlideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

/* ── TL;DR Box ────────────────────────────────────────────────── */
.tldr-box {
    padding: 24px 28px;
    background: linear-gradient(135deg,
        rgba(var(--primary-rgb), 0.05) 0%,
        rgba(var(--primary-rgb), 0.02) 100%);
    border: 1px solid rgba(var(--primary-rgb), 0.14);
    border-left: 3px solid var(--primary);
    border-radius: 14px;
}
.tldr-label {
    display: inline-block;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--primary);
    background: rgba(var(--brand-500-rgb),0.08);
    padding: 4px 12px;
    border-radius: 6px;
    margin-bottom: 12px;
}
.tldr-text {
    font-size: 18px;
    font-weight: 500;
    line-height: 1.7;
    color: var(--ink);
    margin: 0;
}
.study-time-badge {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 13px;
    color: var(--ink-soft);
    margin: 0 auto 16px;
    padding: 10px 20px;
    background: rgba(var(--primary-rgb), 0.10);
    border: 1px solid rgba(var(--primary-rgb), 0.22);
    border-radius: 999px;
    width: fit-content;
    font-weight: 500;
}
.study-time-badge svg { stroke: var(--primary); flex-shrink: 0; }
.study-time-badge strong { color: var(--ink); }

/* ── Key Quotes ───────────────────────────────────────────────── */
.report-quote {
    margin: 0 0 16px;
    padding: 24px 28px 24px 32px;
    background: linear-gradient(135deg, rgba(var(--brand-500-rgb),0.05), rgba(var(--brand-500-rgb),0.02));
    border-left: 4px solid var(--primary);
    border-radius: 0 14px 14px 0;
    position: relative;
}
.report-quote p {
    font-size: 17px;
    font-style: italic;
    line-height: 1.7;
    color: var(--ink);
    margin: 0 0 10px;
}
.report-quote footer {
    font-size: 13px;
    color: var(--ink-muted);
}
.report-quote footer strong { color: var(--ink-soft); }

/* ── Exercise Cards ───────────────────────────────────────────── */
.exercise-card {
    padding: 18px 22px;
    background: linear-gradient(135deg, rgba(var(--brand-500-rgb),0.05), rgba(var(--brand-500-rgb),0.02));
    border: 1px solid rgba(var(--brand-500-rgb),0.12);
    border-left: 3px solid var(--primary);
    border-radius: 12px;
    margin-bottom: 12px;
}
.exercise-header {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 10px;
}
.exercise-number {
    width: 32px; height: 32px;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 800;
    flex-shrink: 0;
}
.exercise-time-pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px;
    margin-left: 8px;
    background: rgba(var(--brand-500-rgb), 0.08);
    border: 1px solid rgba(var(--brand-500-rgb), 0.18);
    border-radius: 12px;
    font-size: 11px; font-weight: 600;
    color: var(--brand-700);
    letter-spacing: 0.2px;
    vertical-align: middle;
}
.exercise-time-pill svg { stroke: var(--brand-600); flex-shrink: 0; }
.exercise-card p {
    margin: 0;
    color: var(--ink-soft);
    font-size: 14px;
    line-height: 1.6;
}

/* ── Floating Pop Quiz Button ─────────────────────────────────── */
.floating-quiz-btn {
    position: fixed;
    bottom: 100px;
    right: 32px;
    z-index: 90;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 16px 28px;
    border: none;
    border-radius: 14px;
    background: linear-gradient(135deg, #6b7280 0%, #27272a 55%, #000000 100%);
    color: #fff;
    font-size: 15px;
    font-weight: 700;
    font-family: inherit;
    cursor: pointer;
    letter-spacing: 0.3px;
    text-align: center;
    justify-content: center;
    min-width: 200px;
    box-shadow:
        0 8px 28px rgba(0,0,0,0.45),
        0 2px 8px rgba(0,0,0,0.25),
        inset 0 1px 0 rgba(255,255,255,0.12);
    transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    overflow: hidden;
}
.floating-quiz-btn::before {
    content: '';
    position: absolute;
    top: 0; left: -100%;
    width: 60%; height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
    transform: skewX(-20deg);
    pointer-events: none;
    transition: left 0.5s ease;
}
.floating-quiz-btn:hover {
    transform: translateY(-3px) scale(1.04);
    box-shadow:
        0 14px 40px rgba(0,0,0,0.55),
        0 4px 12px rgba(0,0,0,0.25),
        inset 0 1px 0 rgba(255,255,255,0.18);
}
.floating-quiz-btn:hover::before { left: 160%; }
@media (max-width: 768px) {
    .floating-quiz-btn { display: none !important; }
}

/* ── Floating Download PDF Button (desktop) ───────────────────── */
.floating-pdf-btn {
    position: fixed;
    bottom: 32px;
    right: 32px;
    z-index: 90;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 16px 28px;
    border: none;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--brand-700), var(--brand-600), var(--brand-500));
    color: #fff;
    font-size: 15px;
    font-weight: 700;
    font-family: inherit;
    cursor: pointer;
    letter-spacing: 0.3px;
    text-align: center;
    min-width: 200px;
    box-shadow:
        0 8px 28px rgba(var(--brand-600-rgb), 0.4),
        0 2px 8px rgba(0,0,0,0.12),
        inset 0 1px 0 rgba(255,255,255,0.2);
    transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    overflow: hidden;
    position: fixed;
}
.floating-pdf-btn::before {
    content: '';
    position: absolute;
    top: 0; left: -100%;
    width: 60%; height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    transform: skewX(-20deg);
    pointer-events: none;
    animation: pdfShineAuto 4s ease-in-out infinite;
}
@keyframes pdfShineAuto {
    0%, 75% { left: -100%; }
    100%    { left: 160%; }
}
.floating-pdf-btn:hover {
    transform: translateY(-3px) scale(1.03);
    box-shadow:
        0 14px 40px rgba(var(--brand-600-rgb), 0.5),
        0 4px 12px rgba(0,0,0,0.15),
        inset 0 1px 0 rgba(255,255,255,0.25);
}
.floating-pdf-btn:hover::before {
    animation: pdfShineHover 0.7s ease forwards;
}
@keyframes pdfShineHover {
    from { left: -100%; }
    to   { left: 160%; }
}
.floating-pdf-btn:active {
    transform: translateY(-1px) scale(1);
}
.floating-pdf-btn svg {
    flex-shrink: 0;
}

/* Mobile: hide floating btn, show topbar PDF btn */
.topbar-pdf-mobile { display: none !important; }

@media (max-width: 768px) {
    .floating-pdf-btn { display: none !important; }
    .topbar-pdf-mobile { display: inline-flex !important; }
}

/* ── Learning Report Components ────────────────────────────────── */
/* Learning Objectives — clean prose paragraphs */
.learning-objectives-body {
    padding: 28px 32px;
    background: linear-gradient(135deg, rgba(var(--brand-500-rgb),0.05), rgba(var(--brand-500-rgb),0.02));
    border: 1px solid rgba(var(--brand-500-rgb),0.12);
    border-left: 4px solid var(--primary);
    border-radius: 14px;
}
.learning-objectives-body p,
.learning-objectives-body .lo-intro {
    color: var(--ink-soft);
    font-size: 15px;
    line-height: 1.8;
    margin: 0 0 20px;
}
.lo-item {
    padding: 18px 22px;
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 12px;
    margin-bottom: 12px;
}
.lo-item:last-child { margin-bottom: 0; }
.lo-title {
    font-size: 15px;
    font-weight: 700;
    color: var(--ink);
    margin-bottom: 6px;
    letter-spacing: -0.2px;
}
.lo-desc {
    font-size: 14px;
    color: var(--ink-soft);
    line-height: 1.7;
}

.learning-concept-card {
    padding: 16px 20px;
    background: linear-gradient(135deg, rgba(var(--brand-500-rgb),0.05), rgba(var(--brand-500-rgb),0.02));
    border: 1px solid rgba(var(--brand-500-rgb),0.12);
    border-left: 3px solid var(--primary);
    border-radius: 12px;
    margin-bottom: 10px;
}
.learning-concept-card .concept-header {
    display: flex; align-items: center; gap: 10px; margin-bottom: 6px;
}
.learning-concept-card p { margin: 0; color: var(--ink-soft); line-height: 1.6; }
.importance-pill {
    font-size: 10px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;
    padding: 3px 10px; border-radius: 12px;
}
.importance-pill.importance-high { background: rgba(239,68,68,0.1); color: #ef4444; }
.importance-pill.importance-medium { background: rgba(217,119,6,0.1); color: #d97706; }
.importance-pill.importance-low { background: rgba(16,185,129,0.1); color: #10b981; }

.learning-report-table {
    width: 100%; border-collapse: collapse; font-size: 14px;
}
.learning-report-table th {
    text-align: left; padding: 10px 14px; font-size: 10px; font-weight: 800;
    letter-spacing: 1px; text-transform: uppercase; color: var(--ink-muted);
    border-bottom: 2px solid var(--line);
}
.learning-report-table td {
    padding: 12px 14px; border-bottom: 1px solid var(--line-soft);
    color: var(--ink-soft); line-height: 1.5;
}
.learning-report-table tr:last-child td { border-bottom: none; }

.stats-report-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px;
}
.stat-report-card {
    padding: 18px; border-radius: 12px;
    background: linear-gradient(135deg, rgba(var(--brand-500-rgb,59,130,246),0.06), rgba(var(--brand-500-rgb,59,130,246),0.02));
    border: 1px solid rgba(var(--brand-500-rgb,59,130,246),0.12);
    text-align: center;
}
.stat-report-value { font-size: 22px; font-weight: 800; color: var(--primary); margin-bottom: 4px; }
.stat-report-context { font-size: 13px; color: var(--ink-soft); line-height: 1.5; }
.stat-report-source { font-size: 11px; color: var(--ink-muted); margin-top: 6px; font-style: italic; }

.timeline-report { position: relative; padding-left: 24px; }
.timeline-report::before {
    content: ''; position: absolute; left: 6px; top: 0; bottom: 0; width: 2px;
    background: linear-gradient(to bottom, var(--primary), rgba(var(--brand-500-rgb),0.2));
    border-radius: 2px;
}
.timeline-report-item { position: relative; padding: 0 0 20px 20px; }
.timeline-report-item::before {
    content: ''; position: absolute; left: -21px; top: 5px;
    width: 10px; height: 10px; border-radius: 50%;
    background: var(--primary); border: 2px solid #fff;
    box-shadow: 0 0 0 2px rgba(var(--brand-500-rgb),0.2);
}
.timeline-report-date {
    font-size: 12px; font-weight: 700; color: var(--primary);
    letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 4px;
}
.timeline-report-body strong { color: var(--ink); display: block; margin-bottom: 4px; }
.timeline-report-body p { margin: 0; color: var(--ink-soft); font-size: 14px; line-height: 1.6; }

.resource-report-card {
    padding: 14px 18px; border-radius: 10px;
    background: linear-gradient(135deg, rgba(var(--brand-500-rgb),0.05), rgba(var(--brand-500-rgb),0.02));
    border: 1px solid rgba(var(--brand-500-rgb),0.12);
    border-left: 3px solid var(--primary);
    margin-bottom: 8px;
}
.resource-report-card strong { color: var(--ink); display: block; margin-bottom: 4px; }
.resource-report-card p { margin: 0 0 6px; color: var(--ink-soft); font-size: 14px; line-height: 1.5; }
.resource-report-card a { color: var(--primary); font-size: 13px; word-break: break-all; }

.roadmap-report-item {
    padding: 16px 20px; border-radius: 12px; margin-bottom: 10px;
    background: linear-gradient(135deg, rgba(var(--brand-500-rgb),0.05), rgba(var(--brand-500-rgb),0.02));
    border: 1px solid rgba(var(--brand-500-rgb),0.12);
    border-left: 4px solid var(--primary);
}
.roadmap-report-phase {
    font-size: 11px; font-weight: 700; color: var(--primary);
    letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 6px;
}
.roadmap-report-item strong { color: var(--ink); display: block; margin-bottom: 4px; }
.roadmap-report-item p { margin: 0; color: var(--ink-soft); font-size: 14px; line-height: 1.6; }

.action-assignee {
    flex-shrink: 0;
    font-weight: 700;
    color: var(--ink);
    white-space: nowrap;
    padding-right: 10px;
    border-right: 2px solid rgba(16,185,129,0.35);
    margin-right: 2px;
    min-width: 0;
}
.action-task {
    flex: 1;
    min-width: 0;
}
.action-list li::before {
    content: '\2713';
    flex-shrink: 0;
    width: 24px; height: 24px; border-radius: 7px;
    background: linear-gradient(135deg, #10b981, #059669);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700;
    box-shadow: 0 3px 8px rgba(16,185,129,0.35);
    margin-top: 2px;
}

/* ── Transcript container (scrollable in web, paginated in print) ─────── */
.transcript-wrap { padding: 0 48px 40px; }
.transcript-head {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 14px;
}
.transcript-head .section-title { margin: 0; }
.transcript-copy-btn {
    padding: 8px 16px;
    background: var(--line-soft);
    border: 1px solid var(--line);
    border-radius: 8px;
    font-size: 12px; font-weight: 600;
    color: var(--ink-soft); cursor: pointer;
    transition: all 0.15s;
    font-family: inherit;
}
.transcript-copy-btn:hover {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}
.transcript-box {
    max-height: 520px;
    overflow-y: auto;
    padding: 28px 32px;
    background: #fafbfc;
    border: 1px solid var(--line);
    border-radius: 14px;
    color: var(--ink-soft);
    font-size: 15px; line-height: 1.8;
    white-space: pre-wrap;
    font-family: 'SF Mono', 'Monaco', 'Consolas', 'Cascadia Code', 'Liberation Mono', monospace;
    position: relative;
    scrollbar-width: thin;
    scrollbar-color: var(--primary) var(--line-soft);
}
.transcript-box::-webkit-scrollbar { width: 8px; }
.transcript-box::-webkit-scrollbar-track { background: var(--line-soft); border-radius: 4px; }
.transcript-box::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, var(--primary-light), var(--primary));
    border-radius: 4px;
}

/* ── Footer ──────────────────────────────────────────────────────────── */
.report-footer {
    text-align: center;
    padding: 36px 48px 44px;
    color: rgba(255,255,255,0.65);
    font-size: 13px;
    background: linear-gradient(to bottom, var(--brand-grad-light) 0%, var(--brand-grad-mid) 50%, var(--brand-grad-dark) 100%);
    border-top: none;
    margin-top: 0;
    letter-spacing: 0.2px;
}
.report-footer strong { color: #fff; }

/* ── Print rules ─────────────────────────────────────────────────────── */
@page {
    size: letter;
    margin: 10mm 12mm 16mm 12mm;
    @bottom-left {
        content: "jasonai.ca";
        font: 700 9pt -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        color: #475569;
        padding-left: 4mm;
    }
    @bottom-right {
        content: "Page " counter(page) " of " counter(pages);
        font: 500 9pt -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        color: #64748b;
        padding-right: 4mm;
    }
}
@page :first {
    margin: 0;
    @bottom-left { content: ""; }
    @bottom-right { content: ""; }
}
@media print {
    html, body {
        background: #ffffff !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    .topbar, .no-print, .transcript-copy-btn { display: none !important; }

    /* Strip screen-only brand accents from content cards in PDF */
    .report-section {
        border: none !important;
        box-shadow: none !important;
    }
    .report-section::before {
        display: none !important;
    }
    .report-wrapper { max-width: none; margin: 0; padding: 0; }
    .report-section {
        border-radius: 0;
        box-shadow: none;
        margin-bottom: 0;
        break-inside: auto;
    }

    /* Cover — full bleed on first page (no margins via @page :first) */
    .cover {
        min-height: 100vh;
        padding: 80px 48px;
        page-break-after: always;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    }
    .cover-title { font-size: 34px; }
    .cover-logo { margin-bottom: 36px; }
    .cover-logo img { height: 64px !important; }
    .cover-badge { margin-bottom: 16px; }
    .cover-meta { margin-top: 40px; }

    /* Let content flow naturally — no forced page breaks between sections.
       Content will break across pages as needed, avoiding big white gaps. */
    .print-new-page {
        /* Removed forced page-break-before — content flows continuously */
    }

    /* ── Print layout strategy ──
       Rule 1: Headings ALWAYS stay with their content (never orphaned on a page alone)
       Rule 2: Individual cards/items don't split, but lists CAN split between items
       Rule 3: No forced page breaks except the cover — content flows naturally
       Rule 4: Minimum 3 lines on each side of a page break */

    .metrics-grid {
        padding: 24px 40px 20px;
        gap: 10px;
        break-inside: avoid;
    }
    .metric-card { padding: 16px 14px; }
    .metric-value { font-size: 22px; }
    .metric-label { font-size: 10px; }

    .section-pad { padding: 22px 40px; }

    /* Headings must stay with the content that follows — NEVER orphaned */
    .section-title {
        font-size: 22px; margin-bottom: 14px;
        page-break-after: avoid !important;
        break-after: avoid !important;
    }
    .section-title-h1 { font-size: 20px; margin-bottom: 14px; page-break-after: avoid; break-after: avoid; }
    h2, h3, h4 { page-break-after: avoid; break-after: avoid; }

    /* Individual items stay together — but lists/sections CAN split between items */
    .bullet-list li, .action-list li, .learning-concept-card,
    .resource-report-card, .roadmap-report-item, .exercise-card,
    .timeline-report-item, .stat-report-card, .lo-item,
    .report-quote, .qr-question {
        break-inside: avoid;
        page-break-inside: avoid;
    }

    /* Callouts stay together if small enough */
    .callout { break-inside: avoid; }

    /* Section pads — do NOT use break-inside:avoid (causes big white gaps).
       Let content flow; headings use break-after:avoid to stay with first items */
    .section-pad { break-inside: auto; }

    /* Lists CAN split between items — individual items stay whole */
    .bullet-list, .action-list { break-inside: auto; }

    .bullet-list li { padding: 10px 16px 10px 38px; }
    .action-list li { padding: 12px 16px; margin-bottom: 8px; }

    /* Glossary table — rows stay together, table can split */
    .learning-report-table tr { break-inside: avoid; }

    /* Stats grid and score boxes — small enough to keep together */
    .stats-report-grid { break-inside: avoid; }
    .quiz-score-box { break-inside: avoid; }

    /* Summary grids (strengths/weaknesses) — keep each card together */
    .qr-summary-card { break-inside: avoid; }

    /* Report sections — allow splitting so content fills pages */
    .report-section { break-inside: auto; }
    .report-section:first-child { overflow: hidden; }

    /* Text blocks — orphan/widow control */
    p, .lo-desc, .callout p, .transcript-box {
        orphans: 3;
        widows: 3;
    }

    /* Learning objectives cards */
    .learning-objectives-body { break-inside: auto; }

    /* Charts section — each chart card stays together */
    .chart-card { break-inside: avoid; margin-bottom: 12px; }

    /* Transcript */
    .transcript-wrap { padding: 0 40px 24px; }
    .transcript-head {
        page-break-after: avoid;
        break-after: avoid;
    }
    .transcript-box {
        max-height: none;
        overflow: visible;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 28px 32px;
        font-size: 13px;
        line-height: 1.75;
        orphans: 4;
        widows: 4;
        white-space: normal;
    }
    .transcript-formatted p {
        margin: 0 0 16px;
        text-indent: 0;
        text-align: justify;
    }
    .transcript-formatted p:last-child { margin-bottom: 0; }

    /* Charts section in print */
    #chartsSection {
        page-break-before: always;
    }
    #chartsSection .section-pad { padding: 28px 32px; }
    .charts-grid {
        gap: 12px;
        grid-template-columns: 1fr 1fr;
        width: 100%;
        box-sizing: border-box;
    }
    .chart-card {
        page-break-inside: avoid;
        break-inside: avoid;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 14px 14px 10px;
        min-width: 0;
        overflow: hidden;
        box-sizing: border-box;
    }
    .chart-card.chart-wide {
        grid-column: 1 / -1;
    }
    .chart-card canvas {
        max-height: 160px !important;
        width: 100% !important;
        box-sizing: border-box;
    }
    .chart-card h3 {
        font-size: 10px;
        margin-bottom: 6px;
    }

    /* Footer: small, glued to content end */
    .report-footer {
        padding: 20px 40px;
        page-break-inside: avoid;
        break-inside: avoid;
    }
}

/* Responsive */
@media (max-width: 640px) {
    .report-wrapper { padding: 0 12px; margin: 16px auto 32px; }
    .cover { padding: 48px 24px 40px; }
    .cover-title { font-size: 26px; }
    .cover-meta { gap: 24px; }
    .metrics-grid { padding: 28px 24px; }
    .section-pad { padding: 28px 24px; }
    .transcript-wrap { padding: 0 24px 32px; }
    .topbar { padding: 12px 16px; }
    .btn { padding: 9px 14px; font-size: 12px; }
}
</style>
<script>
(function () {
    try {
        if (localStorage.getItem('theme') === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    } catch (e) {}
})();
</script>
</head>
<body>

<!-- Topbar (hidden on print) — matches Jason AI app header -->
<?php
// Unified topbar — full app nav for logged-in users, minimal for public viewers
$logged = !!$canManageShare;
?>
<div class="topbar no-print">
    <div class="topbar-inner">
        <div class="topbar-brand">
            <img src="<?= e($logoPath) ?>" alt="Logo" class="topbar-brand-logo"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='inline-block';">
            <span class="topbar-brand-fallback" style="display:none;"><?= e($senderName ?: 'Jason AI') ?></span>
        </div>
        <div class="topbar-actions">
<?php if ($logged): ?>
            <!-- Page-specific buttons (added to the standard app nav) -->
            <button type="button" id="shareBtn" class="btn share-btn <?= $isPublic ? 'is-public' : 'is-private' ?>" onclick="openShareModal()" title="<?= $isPublic ? 'This report is public — click to manage' : 'This report is private — click to share' ?>">
                <svg class="share-icon-locked" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <svg class="share-icon-unlocked" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
                <span class="share-label"><?= $isPublic ? 'Public' : 'Private' ?></span>
            </button>
<?php if ($mode === 'learning'): ?>
            <button type="button" class="btn" onclick="showQuizHistory()" title="Quiz History">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <span>Quiz History</span>
            </button>
<?php else: ?>
            <button type="button" class="btn" onclick="copyTranscript()" title="Copy Transcript">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                <span>Copy Text</span>
            </button>
<?php endif; ?>
            <!-- Standard app nav (mirrors index.html) -->
            <a href="/index.html" class="btn app-nav-btn" title="Transcribe">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                <span>Transcribe</span>
            </a>
            <div class="tb-more" id="tbMoreDropdown">
                <button type="button" class="btn app-nav-btn" id="tbMoreBtn" onclick="tbToggleMore(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                    <span>More</span>
                    <svg class="tb-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div class="tb-more-menu" id="tbMoreMenu">
                    <a href="/index.html#history" class="tb-more-item">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        History
                    </a>
                    <a href="/index.html#reports" class="tb-more-item">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        Reports
                    </a>
                    <a href="/index.html#analytics" class="tb-more-item">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                        Analytics
                    </a>
                    <a href="/index.html#contacts" class="tb-more-item">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Contacts
                    </a>
                    <div class="tb-more-divider"></div>
                    <a href="/index.html#feedback" class="tb-more-item">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        Feedback
                    </a>
                </div>
            </div>
            <a href="/index.html#settings" class="btn app-nav-btn btn-icon-only" title="Settings">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            </a>
            <button type="button" class="btn app-nav-btn" onclick="tbSignOut()" title="Sign Out">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span>Sign Out</span>
            </button>
<?php else: ?>
            <!-- Public-link viewer — minimal toolbar -->
            <a href="https://jasonai.ca/" class="btn" title="Visit Jason AI">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>Jason AI</span>
            </a>
<?php endif; ?>
        </div>
    </div>
</div>

<style>
/* Unified topbar — extra styles for the app-nav dropdown (logged-in users) */
.topbar-inner {
    max-width: 1200px;
    margin: 0 auto;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}
.app-nav-btn { background: transparent; border: 1px solid transparent; color: rgba(255,255,255,0.82); }
.app-nav-btn:hover { background: rgba(255,255,255,0.10); border-color: rgba(255,255,255,0.18); color: #fff; }
.btn-icon-only { padding: 9px 10px; }
.tb-more { position: relative; }
.tb-more.open .tb-chevron { transform: rotate(180deg); }
.tb-chevron { transition: transform 0.25s ease; opacity: 0.7; }
.tb-more-menu {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    min-width: 200px;
    background: linear-gradient(165deg, var(--brand-grad-light) 0%, var(--brand-grad-mid) 45%, var(--brand-grad-dark) 80%, var(--brand-950) 100%);
    border: 1px solid rgba(255,255,255,0.18);
    border-radius: 14px;
    padding: 6px;
    opacity: 0;
    max-height: 0;
    overflow: hidden;
    visibility: hidden;
    transition: opacity 0.28s ease, max-height 0.5s cubic-bezier(0.22, 1, 0.36, 1), visibility 0s 0.5s;
    z-index: 60;
    box-shadow: 0 16px 48px rgba(0,0,0,0.45), 0 6px 16px rgba(0,0,0,0.25), inset 0 1px 0 rgba(255,255,255,0.15);
}
.tb-more.open .tb-more-menu {
    opacity: 1;
    max-height: 500px;
    visibility: visible;
    transition: opacity 0.28s ease, max-height 0.5s cubic-bezier(0.22, 1, 0.36, 1), visibility 0s 0s;
}
.tb-more-item {
    display: flex; align-items: center; gap: 10px;
    width: 100%;
    padding: 10px 14px;
    border: 1px solid transparent;
    border-radius: 10px;
    background: transparent;
    color: rgba(255,255,255,0.82);
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.15s ease;
}
.tb-more-item:hover {
    background: rgba(255,255,255,0.12);
    border-color: rgba(255,255,255,0.18);
    color: #fff;
}
.tb-more-item svg { flex-shrink: 0; }
.tb-more-divider {
    height: 1px;
    background: rgba(255,255,255,0.12);
    margin: 6px 4px;
}
</style>
<script>
function tbToggleMore(e) {
    e.stopPropagation();
    document.getElementById('tbMoreDropdown')?.classList.toggle('open');
}
document.addEventListener('click', function(e) {
    const dd = document.getElementById('tbMoreDropdown');
    if (!dd) return;
    if (!dd.contains(e.target)) dd.classList.remove('open');
});
async function tbSignOut() {
    try {
        await fetch('/api/auth.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ action: 'logout' })
        });
    } catch (e) {}
    window.location.href = '/login.php';
}
</script>


<?php if ($mode === 'learning' && $canManageShare): ?>
<!-- Floating Pop Quiz button — learning mode, logged-in users only -->
<button type="button" class="floating-quiz-btn no-print" onclick="startPopQuiz()" title="Take a Pop Quiz">
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <span>Pop Quiz</span>
</button>
<?php endif; ?>

<!-- Floating Download PDF button — desktop only, hidden on print -->
<button type="button" class="floating-pdf-btn no-print" onclick="window.print()" title="Download PDF">
    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
    <span>Download PDF</span>
</button>

<div class="report-wrapper">

    <!-- Cover page — rebuilt with isolated jaihero-* classes -->
    <style>
        #jaiHeroSection {
            background-color: var(--brand-grad-dark);
            border-radius: 20px;
            overflow: hidden;
            margin-bottom: 24px;
            box-shadow: 0 4px 28px rgba(0,0,0,0.08);
            position: relative;
        }
        /* Layer 0: desaturated hero image so any brand color tints it cleanly */
        #jaiHeroSection::after {
            content: '';
            position: absolute;
            inset: 0;
            background: url('<?= e($modeInfo['hero']) ?>') center center / cover no-repeat;
            filter: grayscale(1) contrast(1.05) brightness(0.95);
            z-index: 0;
            pointer-events: none;
        }
        /* Layer 1: brand gradient overlay — top bright, bottom dark, all in brand hue */
        #jaiHeroSection::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse 70% 55% at 50% 22%,
                    rgba(var(--brand-400-rgb), 0.55) 0%,
                    rgba(var(--brand-500-rgb), 0.25) 45%,
                    rgba(var(--brand-500-rgb), 0) 75%),
                linear-gradient(180deg,
                    rgba(var(--brand-400-rgb), 0.45) 0%,
                    rgba(var(--brand-500-rgb), 0.65) 30%,
                    rgba(var(--brand-grad-mid-rgb), 0.85) 70%,
                    rgba(var(--brand-grad-dark-rgb), 0.95) 100%);
            z-index: 1;
            pointer-events: none;
        }
        /* Dark mode: thicker brand overlay so the hero doesn't read
           'too blue' — user wanted ~75% tint rather than the default
           45-55% that let too much of the hero image + sparks through.
           Light-mode rule at the top of this file is unchanged. */
        @media screen {
        [data-theme="dark"] #jaiHeroSection::before {
            background:
                radial-gradient(ellipse 70% 55% at 50% 22%,
                    rgba(var(--brand-400-rgb), 0.68) 0%,
                    rgba(var(--brand-500-rgb), 0.42) 45%,
                    rgba(var(--brand-500-rgb), 0) 75%),
                linear-gradient(180deg,
                    rgba(var(--brand-500-rgb), 0.70) 0%,
                    rgba(var(--brand-grad-mid-rgb), 0.85) 30%,
                    rgba(var(--brand-grad-dark-rgb), 0.95) 70%,
                    rgba(5, 8, 22, 0.98) 100%) !important;
        }
        /* Also tighten the hero image desaturation in dark mode so the
           undertones go near-black rather than muted blue. */
        [data-theme="dark"] #jaiHeroSection::after {
            filter: grayscale(1) contrast(1.15) brightness(0.55) !important;
        }
        }

        /* Meeting mode: darker cover overlay so the boardroom image doesn't overwhelm the title */
        #jaiHeroSection[data-mode="meeting"]::before {
            background:
                radial-gradient(ellipse 70% 55% at 50% 22%,
                    rgba(var(--brand-400-rgb), 0.68) 0%,
                    rgba(var(--brand-500-rgb), 0.35) 45%,
                    rgba(var(--brand-500-rgb), 0) 75%),
                linear-gradient(180deg,
                    rgba(var(--brand-400-rgb), 0.62) 0%,
                    rgba(var(--brand-500-rgb), 0.80) 30%,
                    rgba(var(--brand-grad-mid-rgb), 0.95) 70%,
                    rgba(var(--brand-grad-dark-rgb), 1.0) 100%);
        }

        #jaiHeroSection .jaihero-inner {
            position: relative;
            z-index: 2;
            padding: 72px 48px 56px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #ffffff;
        }
        #jaiHeroSection .jaihero-logo {
            display: block;
            height: 65px;
            width: auto;
            max-width: 350px;
            object-fit: contain;
            margin: 0 auto 32px auto;
            filter: brightness(0) invert(1);
            opacity: 0.95;
        }
        #jaiHeroSection .jaihero-badge {
            display: inline-block;
            margin: 0 auto 28px auto;
            padding: 8px 22px;
            background: rgba(0,0,0,0.28);
            border: 1px solid rgba(255,255,255,0.18);
            border-radius: 100px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            color: #ffffff;
        }
        #jaiHeroSection .jaihero-divider {
            width: 56px;
            height: 2px;
            margin: 0 auto 24px auto;
            background: rgba(255,255,255,0.35);
            border-radius: 1px;
        }
        #jaiHeroSection .jaihero-title {
            margin: 0 auto 16px auto;
            max-width: 720px;
            font-size: 28px;
            font-weight: 800;
            line-height: 1.25;
            letter-spacing: -0.4px;
            color: #ffffff;
            text-align: center;
        }
        #jaiHeroSection .jaihero-edit-wrap {
            position: relative;
            display: inline-block;
            margin: 4px auto 8px auto;
        }
        #jaiHeroSection .jaihero-edit-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px; height: 32px;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.22);
            background: rgba(255,255,255,0.10);
            color: rgba(255,255,255,0.85);
            cursor: pointer;
            transition: all 0.2s;
        }
        #jaiHeroSection .jaihero-edit-btn:hover {
            background: rgba(255,255,255,0.2);
            color: #fff;
        }
        #jaiHeroSection .jaihero-edit-tooltip {
            position: absolute;
            bottom: calc(100% + 10px);
            left: 50%;
            transform: translateX(-50%) scale(0.85);
            opacity: 0;
            background: #fff;
            color: #334155;
            font-size: 12px;
            padding: 8px 14px;
            border-radius: 8px;
            white-space: nowrap;
            pointer-events: none;
            transition: opacity 0.25s ease, transform 0.3s ease;
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
        }
        #jaiHeroSection .jaihero-edit-wrap:hover .jaihero-edit-tooltip {
            opacity: 1;
            transform: translateX(-50%) scale(1);
        }
        #jaiHeroSection .jaihero-meta {
            display: flex;
            justify-content: center;
            align-items: flex-start;
            gap: 56px;
            flex-wrap: wrap;
            margin: 36px auto 0 auto;
            padding-top: 28px;
            border-top: 1px solid rgba(255,255,255,0.18);
            width: 100%;
            max-width: 640px;
        }
        #jaiHeroSection .jaihero-meta-item {
            text-align: center;
        }
        #jaiHeroSection .jaihero-meta-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.6);
            margin-bottom: 6px;
        }
        #jaiHeroSection .jaihero-meta-value {
            font-size: 14px;
            font-weight: 600;
            color: rgba(255,255,255,0.96);
        }
        @media (max-width: 600px) {
            #jaiHeroSection .jaihero-inner { padding: 48px 24px 40px; }
            #jaiHeroSection .jaihero-title { font-size: 26px; }
            #jaiHeroSection .jaihero-meta { gap: 28px; }
        }
        @media print {
            #jaiHeroSection {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                border-radius: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                page-break-after: always;
                break-after: page;
                width: 100%;
                height: 100vh;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                justify-content: center;
                box-shadow: none !important;
            }
            #jaiHeroSection .jaihero-inner {
                flex: 1;
                width: 100%;
                height: 100%;
                min-height: 100%;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 60px 48px 110px;
                position: relative;
            }
            #jaiHeroSection .jaihero-logo {
                height: 98px !important;
                max-width: 520px !important;
            }
            #jaiHeroSection .jaihero-edit-wrap { display: none !important; }
            #jaiHeroConstellation { display: none !important; }
            #jaiHeroSection .jaihero-url {
                display: inline-flex !important;
            }
        }
        /* URL pill at bottom of PDF cover — hidden on screen */
        #jaiHeroSection .jaihero-url {
            display: none;
            position: absolute;
            left: 50%;
            bottom: 40px;
            transform: translateX(-50%);
            padding: 10px 26px;
            background: rgba(255,255,255,0.10);
            border: 1px solid rgba(255,255,255,0.25);
            border-radius: 100px;
            color: rgba(255,255,255,0.85);
            font-size: 11px;
            font-weight: 500;
            letter-spacing: 0.3px;
            white-space: nowrap;
            max-width: calc(100% - 60px);
            overflow: hidden;
            text-overflow: ellipsis;
            z-index: 3;
            align-items: center;
            gap: 8px;
        }
        #jaiHeroSection .jaihero-url::before {
            content: '🔗';
            font-size: 12px;
            opacity: 0.7;
        }
    </style>
    <section id="jaiHeroSection" data-mode="<?= e($mode) ?>">
        <canvas id="jaiHeroConstellation" class="no-print" aria-hidden="true"
                style="position:absolute;inset:0;width:100%;height:100%;z-index:2;pointer-events:none;opacity:0.29;"></canvas>
        <div class="jaihero-inner" style="z-index:3;position:relative;">
            <img class="jaihero-logo" src="<?= e($logoPath) ?>" alt="Logo">
            <div class="jaihero-badge"><?= $modeInfo['label'] ?></div>
            <div class="jaihero-divider"></div>
            <h1 class="jaihero-title" id="reportTitle"><?= e($title) ?></h1>
<?php if ($canManageShare): ?>
            <div class="jaihero-edit-wrap no-print">
                <button type="button" class="jaihero-edit-btn" id="titleEditBtn" onclick="startEditTitle()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <div class="jaihero-edit-tooltip">Edit report name</div>
            </div>
<?php endif; ?>
            <div class="jaihero-meta">
                <div class="jaihero-meta-item">
                    <div class="jaihero-meta-label">Date</div>
                    <div class="jaihero-meta-value"><?= e($createdDate) ?></div>
                </div>
                <?php if ($mode !== 'learning' && $duration > 0): ?>
                <div class="jaihero-meta-item">
                    <div class="jaihero-meta-label">Duration</div>
                    <div class="jaihero-meta-value"><?= e($durationDisplay) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($mode === 'learning' && !empty($analysis['estimated_study_time_minutes'])): ?>
                <div class="jaihero-meta-item">
                    <div class="jaihero-meta-label">Study Time</div>
                    <div class="jaihero-meta-value"><?= (int)$analysis['estimated_study_time_minutes'] ?> min</div>
                </div>
                <?php endif; ?>
                <div class="jaihero-meta-item">
                    <div class="jaihero-meta-label">Language</div>
                    <div class="jaihero-meta-value"><?= e($language) ?></div>
                </div>
            </div>
            <?php
            $heroReportUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/api/report.php?id=' . (int) $row['id'];
            if ($isPublic && $publicToken) {
                $heroReportUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/api/report.php?t=' . urlencode($publicToken);
            }
            ?>
            <div class="jaihero-url" aria-hidden="true"><?= e($heroReportUrl) ?></div>
        </div>
    </section>

    <?php
    // Determine the AI model display — only for audio modes (not learning)
    $aiModelDisplay = '';
    if ($whisperModel && $whisperModel !== 'n/a' && $whisperModel !== 'null' && $mode !== 'learning') {
        $aiModelDisplay = strtoupper($whisperModel);
    }
    // Reading time for learning mode
    $readingTimeMin = $wordCount > 0 ? max(1, round($wordCount / 200)) : 0;
    // Quiz count for learning mode
    $quizCount = 0;
    if ($mode === 'learning' && $canManageShare) {
        try {
            $qcStmt = $db->prepare("SELECT COUNT(*) as cnt FROM quiz_attempts WHERE transcription_id = :tid AND user_id = :uid");
            $qcStmt->execute([':tid' => (int) $row['id'], ':uid' => getCurrentUserId()]);
            $qcRow = $qcStmt->fetch(PDO::FETCH_ASSOC);
            $quizCount = (int) ($qcRow['cnt'] ?? 0);
        } catch (PDOException $e) {}
    }
    ?>
    <!-- Table of Contents (visible only in PDF print) -->
    <?php
    // Build TOC items — numbered, linked to section IDs, page numbers via target-counter()
    $tocItems = [];
    $tocItems[] = ['Overview & Statistics', 'sec-overview'];
    if ($mode === 'meeting' || $mode === 'learning') {
        if (!empty($analysis['summary'])) $tocItems[] = ['Executive Summary', 'sec-executive-summary'];
        if (!empty($analysis['keyPoints'])) $tocItems[] = ['Key Points', 'sec-key-points'];
        if (!empty($analysis['actionItems'])) $tocItems[] = ['Action Items', 'sec-action-items'];
    }
    if ($mode === 'learning') {
        if (!empty($analysis['tldr'])) $tocItems[] = ['TL;DR', 'sec-tldr'];
        if (!empty($analysis['learning_objectives_addressed'])) $tocItems[] = ['Learning Objectives', 'sec-learning-objectives-addressed'];
        if (!empty($analysis['key_concepts'])) $tocItems[] = ['Key Concepts', 'sec-key-concepts'];
        if (!empty($analysis['glossary'])) $tocItems[] = ['Glossary', 'sec-glossary'];
        if (!empty($analysis['core_insights'])) $tocItems[] = ['Core Insights', 'sec-core-insights'];
        if (!empty($analysis['statistics'])) $tocItems[] = ['Key Statistics', 'sec-key-statistics'];
        if (!empty($analysis['products_tools'])) $tocItems[] = ['Products & Tools', 'sec-products-tools'];
        if (!empty($analysis['resources_urls'])) $tocItems[] = ['Resources & References', 'sec-resources-references'];
        if (!empty($analysis['roadmap'])) $tocItems[] = ['Roadmap', 'sec-roadmap'];
        if (!empty($analysis['action_items'])) $tocItems[] = ['Action Items', 'sec-action-items'];
        if (!empty($analysis['practical_exercises'])) $tocItems[] = ['Practical Exercises', 'sec-practical-exercises'];
        if (!empty($analysis['further_learning'])) $tocItems[] = ['Further Learning', 'sec-further-learning'];
    }
    $tocItems[] = ['Visual Insights', 'sec-visual-insights'];
    if ($transcript) $tocItems[] = ['Full Transcript', 'sec-full-transcript'];
    $tocItemCount = count($tocItems);
    // Auto-scale font size so it fits on one page (tighter for more items)
    $tocFontClass = $tocItemCount > 14 ? 'toc-tight' : ($tocItemCount > 10 ? 'toc-medium' : 'toc-roomy');
    ?>
    <div class="toc-section <?= $tocFontClass ?>">
        <img src="<?= e($logoPath) ?>" alt="Logo" class="toc-logo">
        <h2 class="toc-title">Table of Contents</h2>
        <p class="toc-subtitle"><?= e($title) ?></p>
        <ul class="toc-list">
            <?php foreach ($tocItems as $idx => $item):
                $num = str_pad((string)($idx + 1), 2, '0', STR_PAD_LEFT);
            ?>
            <li class="toc-item">
                <a class="toc-item-link" href="#<?= e($item[1]) ?>">
                    <span class="toc-item-num"><?= $num ?></span>
                    <span class="toc-item-label"><?= e($item[0]) ?></span>
                    <span class="toc-item-dots"></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <div class="toc-created-pill" aria-hidden="true">
            <span class="toc-created-icon">&#128197;</span>
            Report Created: <?= e($createdDate) ?>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="report-section metrics-section">
        <div class="metrics-grid">
            <div class="metric-card metric-tooltip-wrap">
                <div class="metric-value"><?= number_format($wordCount) ?></div>
                <div class="metric-label">Words</div>
                <div class="metric-tooltip">Words in the transcript</div>
            </div>
            <div class="metric-card metric-tooltip-wrap">
                <div class="metric-value"><?= number_format($charCount) ?></div>
                <div class="metric-label">Characters</div>
                <div class="metric-tooltip">Characters including spaces</div>
            </div>
            <?php if ($mode !== 'learning' && $duration > 0): ?>
            <div class="metric-card metric-tooltip-wrap">
                <div class="metric-value"><?= e($durationDisplay) ?></div>
                <div class="metric-label">Duration</div>
                <div class="metric-tooltip">Audio recording length</div>
            </div>
            <?php elseif ($mode === 'learning' && $readingTimeMin > 0): ?>
            <div class="metric-card metric-tooltip-wrap">
                <div class="metric-value"><?= $readingTimeMin ?> min</div>
                <div class="metric-label">Reading Time</div>
                <div class="metric-tooltip">Reading time at 200 wpm</div>
            </div>
            <?php endif; ?>
            <?php if ($aiModelDisplay): ?>
            <div class="metric-card metric-tooltip-wrap">
                <div class="metric-value" style="font-size:<?= strlen($aiModelDisplay) > 10 ? '18' : '22' ?>px"><?= e($aiModelDisplay) ?></div>
                <div class="metric-label">AI Model</div>
                <div class="metric-tooltip">Transcription AI model</div>
            </div>
            <?php elseif ($mode === 'learning'): ?>
            <div class="metric-card metric-tooltip-wrap">
                <div class="metric-value"><?= $quizCount ?></div>
                <div class="metric-label">Pop Quizzes</div>
                <div class="metric-tooltip">Quizzes taken on this report</div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($mode === 'meeting' || $mode === 'learning'): ?>
    <!-- Executive Summary — page 2 in PDF -->
    <?php if (!empty($analysis['summary'])): ?>
    <div class="report-section print-new-page">
        <div class="section-pad">
            <h2 class="section-title" id="sec-executive-summary"><span class="section-title-bar"></span>Executive Summary</h2>
            <div class="callout">
                <p><?= nl2br(e($analysis['summary'])) ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Key Points — page 3 in PDF -->
    <?php if (!empty($analysis['keyPoints']) && is_array($analysis['keyPoints'])): ?>
    <div class="report-section print-new-page">
        <div class="section-pad">
            <h2 class="section-title" id="sec-key-points"><span class="section-title-bar"></span>Key Points</h2>
            <ul class="bullet-list">
                <?php foreach ($analysis['keyPoints'] as $point): ?>
                    <li><?= e(is_string($point) ? $point : ($point['text'] ?? $point['point'] ?? (is_array($point) ? implode(' — ', array_values($point)) : json_encode($point)))) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <!-- Action Items — page 4 in PDF -->
    <?php if (!empty($analysis['actionItems']) && is_array($analysis['actionItems'])): ?>
    <div class="report-section print-new-page">
        <div class="section-pad">
            <h2 class="section-title" id="sec-action-items"><span class="section-title-bar"></span>Action Items</h2>
            <ul class="action-list">
                <?php foreach ($analysis['actionItems'] as $item): ?>
                    <?php if (is_string($item)): ?>
                        <li><span class="action-task"><?= e($item) ?></span></li>
                    <?php elseif (is_array($item)): ?>
                        <li>
                            <?php $assignee = $item['assignee'] ?? $item['owner'] ?? ''; ?>
                            <?php $task = $item['task'] ?? $item['action'] ?? $item['description'] ?? ''; ?>
                            <?php if ($assignee): ?><span class="action-assignee"><?= e($assignee) ?></span><?php endif; ?>
                            <span class="action-task"><?= e($task) ?></span>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($mode === 'learning'): ?>
    <!-- ═══════════════ LEARNING ANALYSIS — Full Report ═══════════════ -->

    <?php // TL;DR + Estimated Study Time — right at the top ?>
    <?php if (!empty($analysis['tldr']) || !empty($analysis['estimated_study_time_minutes'])): ?>
    <div class="report-section">
        <div class="section-pad">
            <?php if (!empty($analysis['estimated_study_time_minutes'])): ?>
                <div class="study-time-badge">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Estimated study time: <strong><?php
                        $mins = (int) $analysis['estimated_study_time_minutes'];
                        echo $mins >= 60 ? floor($mins/60) . 'h ' . ($mins%60) . 'min' : $mins . ' minutes';
                    ?></strong>
                </div>
            <?php endif; ?>
            <?php if (!empty($analysis['tldr'])): ?>
                <div class="tldr-box">
                    <div class="tldr-label">TL;DR</div>
                    <p class="tldr-text"><?= e($analysis['tldr']) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php // Difficulty Level ?>
    <?php if (!empty($analysis['difficulty_level'])): ?>
    <div class="report-section">
        <div class="section-pad" style="text-align:center;padding:20px 48px;">
            <?php
            $dl = $analysis['difficulty_level'];
            $dlColors = ['beginner' => '#10b981', 'intermediate' => '#d97706', 'advanced' => '#ef4444'];
            $dlColor = $dlColors[strtolower($dl)] ?? '#64748b';
            ?>
            <span style="display:inline-block;padding:8px 24px;border-radius:20px;font-weight:700;font-size:13px;letter-spacing:1px;text-transform:uppercase;background:<?= $dlColor ?>15;color:<?= $dlColor ?>;border:1px solid <?= $dlColor ?>30"><?= e(ucfirst($dl)) ?> Level</span>
        </div>
    </div>
    <?php endif; ?>

    <?php // Learning Objectives Addressed ?>
    <?php if (!empty($analysis['learning_objectives_addressed'])): ?>
    <div class="report-section print-new-page">
        <div class="section-pad">
            <h2 class="section-title" id="sec-learning-objectives-addressed"><span class="section-title-bar"></span>Learning Objectives Addressed</h2>
            <div class="learning-objectives-body">
            <?php
            $loText = $analysis['learning_objectives_addressed'];
            // Strip markdown bold/italic
            $loText = preg_replace('/\*\*([^*]+)\*\*/', '$1', $loText);
            $loText = preg_replace('/\*([^*]+)\*/', '$1', $loText);
            $loText = preg_replace('/^#+\s*/m', '', $loText);

            // Split on patterns: "Title: description" or "1. Title: description" or double newlines
            // First try splitting on numbered items
            $items = preg_split('/(?:^|\n)\s*\d+\.\s*/m', trim($loText), -1, PREG_SPLIT_NO_EMPTY);

            if (count($items) <= 1) {
                // No numbered items — try splitting on "Title: description" pattern within text
                // Look for "SomeTitle: some description" separated by periods or newlines
                $items = preg_split('/(?<=[.!?])\s+(?=[A-Z][^:]{3,40}:)/', trim($loText), -1, PREG_SPLIT_NO_EMPTY);
            }

            if (count($items) > 1) {
                // We have structured items — render as objective cards
                $introShown = false;
                foreach ($items as $item):
                    $item = trim($item);
                    if (!$item) continue;
                    // Check if item has a "Title: description" pattern
                    if (preg_match('/^([^:]{3,60}):\s*(.+)$/s', $item, $parts)) {
                        $objTitle = trim($parts[1]);
                        $objDesc = trim($parts[2]);
                        ?>
                        <div class="lo-item">
                            <div class="lo-title"><?= e($objTitle) ?></div>
                            <div class="lo-desc"><?= e($objDesc) ?></div>
                        </div>
                        <?php
                    } else {
                        // No colon pattern — might be intro text or plain paragraph
                        if (!$introShown && strlen($item) < 200) {
                            echo '<p class="lo-intro">' . e($item) . '</p>';
                            $introShown = true;
                        } else {
                            echo '<div class="lo-item"><div class="lo-desc">' . e($item) . '</div></div>';
                        }
                    }
                endforeach;
            } else {
                // Single block of text — try to split on colon patterns within it
                $text = trim($loText);
                // Try splitting on sentences that start with a capitalized phrase followed by colon
                if (preg_match_all('/([A-Z][^:]{3,50}):\s*([^.!?]+[.!?])/s', $text, $matches, PREG_SET_ORDER) && count($matches) > 1) {
                    // Extract intro (text before first match)
                    $firstPos = strpos($text, $matches[0][0]);
                    if ($firstPos > 10) {
                        echo '<p class="lo-intro">' . e(trim(substr($text, 0, $firstPos))) . '</p>';
                    }
                    foreach ($matches as $m) {
                        echo '<div class="lo-item"><div class="lo-title">' . e(trim($m[1])) . '</div><div class="lo-desc">' . e(trim($m[2])) . '</div></div>';
                    }
                } else {
                    // Truly unstructured — just render as paragraphs
                    $paras = preg_split('/\n{2,}/', $text);
                    foreach ($paras as $p) {
                        $p = trim($p);
                        if ($p) echo '<p>' . nl2br(e($p)) . '</p>';
                    }
                }
            }
            ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php // Key Concepts ?>
    <?php if (!empty($analysis['key_concepts']) && is_array($analysis['key_concepts'])): ?>
    <div class="report-section print-new-page">
        <div class="section-pad">
            <h2 class="section-title" id="sec-key-concepts"><span class="section-title-bar"></span>Key Concepts</h2>
            <?php foreach ($analysis['key_concepts'] as $c): ?>
                <?php if (is_string($c)): ?>
                    <div class="learning-concept-card"><p><?= e($c) ?></p></div>
                <?php elseif (is_array($c)): ?>
                    <div class="learning-concept-card">
                        <div class="concept-header">
                            <strong><?= e($c['term'] ?? $c['name'] ?? '') ?></strong>
                            <?php if (!empty($c['importance'])): ?>
                                <span class="importance-pill importance-<?= e(strtolower($c['importance'])) ?>"><?= e(ucfirst($c['importance'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($c['explanation'])): ?><p><?= e($c['explanation']) ?></p><?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php // Glossary ?>
    <?php if (!empty($analysis['glossary']) && is_array($analysis['glossary'])): ?>
    <div class="report-section print-new-page">
        <div class="section-pad">
            <h2 class="section-title" id="sec-glossary"><span class="section-title-bar"></span>Glossary</h2>
            <table class="learning-report-table">
                <thead><tr><th>Term</th><th>Definition</th></tr></thead>
                <tbody>
                <?php foreach ($analysis['glossary'] as $g): ?>
                    <tr>
                        <td><strong><?= e(is_string($g) ? $g : ($g['term'] ?? '')) ?></strong></td>
                        <td><?= e(is_string($g) ? '' : ($g['definition'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php // Core Insights ?>
    <?php if (!empty($analysis['core_insights']) && is_array($analysis['core_insights'])): ?>
    <div class="report-section">
        <div class="section-pad">
            <h2 class="section-title" id="sec-core-insights"><span class="section-title-bar"></span>Core Insights</h2>
            <ul class="bullet-list">
                <?php foreach ($analysis['core_insights'] as $i): ?>
                    <li><?= e(is_string($i) ? $i : ($i['text'] ?? json_encode($i))) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <?php // Important People ?>
    <?php if (!empty($analysis['important_people']) && is_array($analysis['important_people'])): ?>
    <div class="report-section">
        <div class="section-pad">
            <h2 class="section-title" id="sec-important-people"><span class="section-title-bar"></span>Important People</h2>
            <table class="learning-report-table">
                <thead><tr><th>Name</th><th>Role</th><th>Relevance</th></tr></thead>
                <tbody>
                <?php foreach ($analysis['important_people'] as $p): ?>
                    <tr>
                        <td><strong><?= e($p['name'] ?? '') ?></strong></td>
                        <td><?= e($p['role'] ?? '') ?></td>
                        <td><?= e($p['relevance'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php // Key Statistics ?>
    <?php if (!empty($analysis['statistics']) && is_array($analysis['statistics'])): ?>
    <div class="report-section">
        <div class="section-pad">
            <h2 class="section-title" id="sec-key-statistics"><span class="section-title-bar"></span>Key Statistics</h2>
            <div class="stats-report-grid">
                <?php foreach ($analysis['statistics'] as $s): ?>
                    <div class="stat-report-card">
                        <div class="stat-report-value"><?= e($s['stat'] ?? '') ?></div>
                        <div class="stat-report-context"><?= e($s['context'] ?? '') ?></div>
                        <?php if (!empty($s['source'])): ?><div class="stat-report-source">Source: <?= e($s['source']) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php // Timeline & Dates ?>
    <?php if (!empty($analysis['dates_timeline']) && is_array($analysis['dates_timeline'])): ?>
    <div class="report-section">
        <div class="section-pad">
            <h2 class="section-title" id="sec-timeline-dates"><span class="section-title-bar"></span>Timeline & Dates</h2>
            <div class="timeline-report">
                <?php foreach ($analysis['dates_timeline'] as $d): ?>
                    <div class="timeline-report-item">
                        <div class="timeline-report-date"><?= e($d['date'] ?? '') ?></div>
                        <div class="timeline-report-body">
                            <strong><?= e($d['event'] ?? '') ?></strong>
                            <?php if (!empty($d['significance'])): ?><p><?= e($d['significance']) ?></p><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php // Products & Tools ?>
    <?php if (!empty($analysis['products_tools']) && is_array($analysis['products_tools'])): ?>
    <div class="report-section">
        <div class="section-pad">
            <h2 class="section-title" id="sec-products-tools"><span class="section-title-bar"></span>Products & Tools</h2>
            <?php foreach ($analysis['products_tools'] as $p): ?>
                <div class="resource-report-card">
                    <strong><?= e($p['name'] ?? '') ?></strong>
                    <?php if (!empty($p['description'])): ?><p><?= e($p['description']) ?></p><?php endif; ?>
                    <?php $pSearch = $p['search_query'] ?? $p['name'] ?? ''; ?>
                    <?php if ($pSearch): ?>
                    <a href="https://www.google.com/search?q=<?= urlencode($pSearch) ?>" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:4px;color:var(--primary);font-size:13px;margin-top:4px">🔍 Search this tool</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php // Resources & URLs ?>
    <?php if (!empty($analysis['resources_urls']) && is_array($analysis['resources_urls'])): ?>
    <div class="report-section print-new-page">
        <div class="section-pad">
            <h2 class="section-title" id="sec-resources-references"><span class="section-title-bar"></span>Resources & References</h2>
            <?php foreach ($analysis['resources_urls'] as $r): ?>
                <div class="resource-report-card">
                    <strong><?= e($r['name'] ?? '') ?></strong>
                    <?php if (!empty($r['description'])): ?><p><?= e($r['description']) ?></p><?php endif; ?>
                    <?php
                    $searchQuery = $r['search_query'] ?? $r['name'] ?? '';
                    if ($searchQuery):
                    ?>
                    <a href="https://www.google.com/search?q=<?= urlencode($searchQuery) ?>" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:4px;color:var(--primary);font-size:13px;margin-top:4px">🔍 Search this topic</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php // Roadmap ?>
    <?php if (!empty($analysis['roadmap']) && is_array($analysis['roadmap'])): ?>
    <div class="report-section">
        <div class="section-pad">
            <h2 class="section-title" id="sec-roadmap"><span class="section-title-bar"></span>Roadmap</h2>
            <?php foreach ($analysis['roadmap'] as $i => $r): ?>
                <div class="roadmap-report-item">
                    <div class="roadmap-report-phase">Phase <?= $i + 1 ?><?= !empty($r['timeline']) ? ' · ' . e($r['timeline']) : '' ?></div>
                    <strong><?= e($r['phase'] ?? '') ?></strong>
                    <?php if (!empty($r['description'])): ?><p><?= e($r['description']) ?></p><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php // Action Items ?>
    <?php if (!empty($analysis['action_items']) && is_array($analysis['action_items'])): ?>
    <div class="report-section">
        <div class="section-pad">
            <h2 class="section-title" id="sec-action-items"><span class="section-title-bar"></span>Action Items</h2>
            <ul class="action-list">
                <?php foreach ($analysis['action_items'] as $item): ?>
                    <?php if (is_string($item)): ?>
                        <li><span class="action-task"><?= e($item) ?></span></li>
                    <?php elseif (is_array($item)): ?>
                        <li>
                            <?php $pri = $item['priority'] ?? 'medium'; $pColor = ['high'=>'#ef4444','medium'=>'#d97706','low'=>'#10b981'][$pri] ?? '#64748b'; ?>
                            <span style="width:8px;height:8px;border-radius:50%;background:<?= $pColor ?>;flex-shrink:0;margin-top:6px"></span>
                            <span class="action-task"><?= e($item['task'] ?? $item['action'] ?? '') ?></span>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <?php // Contact Info ?>
    <?php if (!empty($analysis['contact_info']) && is_array($analysis['contact_info'])): ?>
    <div class="report-section">
        <div class="section-pad">
            <h2 class="section-title" id="sec-contact-information"><span class="section-title-bar"></span>Contact Information</h2>
            <?php foreach ($analysis['contact_info'] as $c): ?>
                <div class="resource-report-card"><strong><?= e($c['name'] ?? '') ?></strong> — <?= e($c['detail'] ?? '') ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php // Key Quotes ?>
    <?php if (!empty($analysis['key_quotes']) && is_array($analysis['key_quotes'])): ?>
    <div class="report-section">
        <div class="section-pad">
            <h2 class="section-title" id="sec-key-quotes"><span class="section-title-bar"></span>Key Quotes</h2>
            <?php foreach ($analysis['key_quotes'] as $q): ?>
                <blockquote class="report-quote">
                    <p>&ldquo;<?= e($q['quote'] ?? '') ?>&rdquo;</p>
                    <?php if (!empty($q['speaker']) || !empty($q['context'])): ?>
                        <footer>
                            <?php if (!empty($q['speaker'])): ?><strong><?= e($q['speaker']) ?></strong><?php endif; ?>
                            <?php if (!empty($q['context'])): ?><span> — <?= e($q['context']) ?></span><?php endif; ?>
                        </footer>
                    <?php endif; ?>
                </blockquote>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php // Prerequisites ?>
    <?php if (!empty($analysis['prerequisites']) && is_array($analysis['prerequisites'])): ?>
    <div class="report-section">
        <div class="section-pad">
            <h2 class="section-title" id="sec-prerequisites"><span class="section-title-bar"></span>Prerequisites</h2>
            <ul class="bullet-list">
                <?php foreach ($analysis['prerequisites'] as $pr): ?>
                    <li><?= e(is_string($pr) ? $pr : ($pr['text'] ?? json_encode($pr))) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <?php // Practical Exercises ?>
    <?php if (!empty($analysis['practical_exercises']) && is_array($analysis['practical_exercises'])): ?>
    <div class="report-section">
        <div class="section-pad">
            <h2 class="section-title" id="sec-practical-exercises"><span class="section-title-bar"></span>Practical Exercises</h2>
            <?php foreach ($analysis['practical_exercises'] as $i => $ex): ?>
                <div class="exercise-card">
                    <div class="exercise-header">
                        <span class="exercise-number"><?= $i + 1 ?></span>
                        <div>
                            <strong><?= e($ex['title'] ?? 'Exercise ' . ($i+1)) ?></strong>
                            <?php if (!empty($ex['difficulty'])): ?>
                                <span class="importance-pill importance-<?= e(strtolower($ex['difficulty'])) ?>"><?= e(ucfirst($ex['difficulty'])) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($ex['time_estimate'])): ?>
                                <span class="exercise-time-pill">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    <?= e($ex['time_estimate']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <p><?= e($ex['description'] ?? '') ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php // Further Learning Path ?>
    <?php if (!empty($analysis['further_learning']) && is_array($analysis['further_learning'])): ?>
    <div class="report-section">
        <div class="section-pad">
            <h2 class="section-title" id="sec-further-learning"><span class="section-title-bar"></span>Further Learning</h2>
            <?php foreach ($analysis['further_learning'] as $fl): ?>
                <div class="resource-report-card">
                    <strong><?= e(is_string($fl) ? $fl : ($fl['topic'] ?? '')) ?></strong>
                    <?php if (!empty($fl['why'])): ?><p><?= e($fl['why']) ?></p><?php endif; ?>
                    <?php if (!empty($fl['resource'])): ?><span style="font-size:13px;color:var(--primary)">📚 <?= e($fl['resource']) ?></span><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; /* end learning */ ?>

    <!-- Visual Insights — mode-specific charts (before transcript) -->
    <div class="report-section" id="chartsSection">
        <div class="section-pad">
            <h2 class="section-title" id="sec-visual-insights"><span class="section-title-bar"></span>Visual Insights</h2>
            <div class="charts-grid">
                <?php if ($mode === 'meeting'): ?>
                    <div class="chart-card"><h3>Speaker Talk Time</h3><canvas id="chart_speakers"></canvas></div>
                    <div class="chart-card"><h3>Sentiment Distribution</h3><canvas id="chart_sentiment"></canvas></div>
                    <div class="chart-card chart-wide"><h3>Action Items by Owner</h3><canvas id="chart_actions"></canvas></div>
                <?php elseif ($mode === 'learning'): ?>
                    <div class="chart-card chart-wide">
                        <h3>Concept Coverage</h3>
                        <p class="chart-subtitle">How thoroughly each key concept is explored in the material. Higher score = more depth and detail on that topic.</p>
                        <canvas id="chart_concepts"></canvas>
                    </div>
                    <div class="chart-card">
                        <h3>Learning Domain Mix</h3>
                        <p class="chart-subtitle">The type of thinking this material trains: <strong>Conceptual</strong> (what), <strong>Procedural</strong> (how), <strong>Strategic</strong> (when / why), <strong>Reflective</strong> (evaluate).</p>
                        <canvas id="chart_domain"></canvas>
                    </div>
                    <div class="chart-card">
                        <h3>Difficulty Spread</h3>
                        <p class="chart-subtitle">How many concepts sit at each difficulty tier. Tells you at a glance whether this is foundational, intermediate, or advanced material.</p>
                        <canvas id="chart_difficulty"></canvas>
                    </div>
                <?php else: ?>
                    <div class="chart-card"><h3>Words per Minute</h3><canvas id="chart_wpm"></canvas></div>
                    <div class="chart-card"><h3>Speech Pace Profile</h3><canvas id="chart_pace"></canvas></div>
                    <div class="chart-card chart-wide"><h3>Top Words</h3><canvas id="chart_topwords"></canvas></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Full Transcript — after visual insights, last content section -->
    <?php if ($transcript): ?>
    <?php
    // ── Smart transcript formatter ───────────────────────────────
    $transcriptBlocks = [];
    $pre = trim($transcript);
    $pre = preg_replace('/\r\n?/', "\n", $pre);
    // Unglue timestamps from surrounding text
    $pre = preg_replace('/(\S)\s+(\d{1,2}:\d{2,3}(?::\d{2})?\s+(?:seconds?|minutes?|min|sec)\b)/i', "$1\n$2", $pre);
    $pre = preg_replace('/(\d{1,2}:\d{2,3}(?::\d{2})?\s+(?:seconds?|minutes?|min|sec))(?=[A-Za-z])/i', "$1 ", $pre);
    $pre = preg_replace('/(\d+:\d+\s+minutes?,\s+\d+\s+seconds?)([A-Za-z])/i', "$1\n$2", $pre);
    $pre = preg_replace('/(\d+:\d+\s+seconds?)([A-Za-z])/i', "$1\n$2", $pre);
    $pre = preg_replace('/[ \t]{2,}/', ' ', $pre);

    $lines = preg_split('/\n+/', $pre);
    $lines = array_values(array_filter(array_map('trim', $lines), function($l){ return $l !== ''; }));

    $tsRegex = '/^\[?(\d{1,2}:\d{1,3}(?::\d{2})?)\]?(?:\s+(?:seconds?|minutes?|min|sec))?\b[\s:.,\-]*(.*)$/iu';
    $spRegex = "/^([A-Z][A-Za-z.'\-]+(?:\s+[A-Z][A-Za-z.'\-]+){0,2})\s*:\s*(.+)$/u";

    $hasTimestamps = false; $hasSpeakers = false;
    foreach ($lines as $line) {
        if (preg_match($tsRegex, $line)) { $hasTimestamps = true; }
        elseif (preg_match($spRegex, $line)) { $hasSpeakers = true; }
    }

    if ($hasTimestamps) {
        $cur = null;
        foreach ($lines as $line) {
            if (preg_match($tsRegex, $line, $m)) {
                if ($cur) $transcriptBlocks[] = $cur;
                $cur = ['type'=>'timestamped', 'label'=>$m[1], 'text'=>trim($m[2] ?? '')];
            } else {
                if ($cur) { $cur['text'] = trim($cur['text'].' '.$line); }
                else { $transcriptBlocks[] = ['type'=>'plain','text'=>$line]; }
            }
        }
        if ($cur) $transcriptBlocks[] = $cur;
    } elseif ($hasSpeakers) {
        $cur = null;
        foreach ($lines as $line) {
            if (preg_match($spRegex, $line, $m)) {
                if ($cur) $transcriptBlocks[] = $cur;
                $cur = ['type'=>'speaker', 'label'=>trim($m[1]), 'text'=>trim($m[2])];
            } else {
                if ($cur) { $cur['text'] = trim($cur['text'].' '.$line); }
                else { $transcriptBlocks[] = ['type'=>'plain','text'=>$line]; }
            }
        }
        if ($cur) $transcriptBlocks[] = $cur;
    } else {
        $joined = implode(' ', $lines);
        $joined = preg_replace('/\s{2,}/', ' ', $joined);
        $sentences = preg_split('/(?<=[.!?])\s+/', $joined, -1, PREG_SPLIT_NO_EMPTY);
        $cueRegex = '/^(Yeah|Right|Okay|Ok|Well|So|And|But|Actually|I mean|You know|Thanks|Thank you|Alright|Great|Sure)\b[,.]?\s/i';
        $chunk = [];
        foreach ($sentences as $i => $sentence) {
            $chunk[] = $sentence;
            $isCue = preg_match($cueRegex, $sentence) && count($chunk) >= 3;
            if (count($chunk) >= 4 || $isCue || $i === count($sentences) - 1) {
                $transcriptBlocks[] = ['type'=>'plain','text'=>implode(' ', $chunk)];
                $chunk = [];
            }
        }
    }
    ?>
    <div class="report-section print-new-page">
        <div class="section-pad" style="padding-bottom:20px;">
            <div class="transcript-head">
                <h2 class="section-title" style="margin:0;"><span class="section-title-bar"></span>Full Transcript</h2>
                <button type="button" class="transcript-copy-btn no-print" onclick="copyTranscript()">Copy Text</button>
            </div>
        </div>
        <div class="transcript-wrap">
            <div class="transcript-box transcript-formatted" id="transcriptBox">
                <?php foreach ($transcriptBlocks as $blk): ?>
                    <?php if ($blk['type'] === 'timestamped'): ?>
                        <p class="ts-block"><span class="ts-label"><?= e($blk['label']) ?></span><?= e($blk['text']) ?></p>
                    <?php elseif ($blk['type'] === 'speaker'): ?>
                        <p class="sp-block"><span class="sp-label"><?= e($blk['label']) ?></span><?= e($blk['text']) ?></p>
                    <?php else: ?>
                        <p><?= e($blk['text']) ?></p>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="report-section">
        <div class="report-footer">
            <strong>Created by Jason AI</strong> &middot; <?= e($createdDate) ?>
            <?php if ($footerText): ?><br><?= e($footerText) ?><?php endif; ?>
        </div>
    </div>

</div>

<?php if ($canManageShare): ?>
<!-- Share modal -->
<div class="share-backdrop" id="shareBackdrop" onclick="if(event.target===this)closeShareModal()">
    <div class="share-modal">
        <h2>Share this report</h2>
        <p class="share-sub">Reports are private by default — only people in your organization who are signed in can view them. Make it public to generate a link anyone can open.</p>

        <div class="share-status <?= $isPublic ? 'is-public' : '' ?>" id="shareStatus">
            <div class="share-status-icon">
                <svg id="shareStatusIcon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
            </div>
            <div class="share-status-text">
                <div class="share-status-title" id="shareStatusTitle"><?= $isPublic ? 'Public — anyone with the link' : 'Private — sign-in required' ?></div>
                <div class="share-status-desc" id="shareStatusDesc"><?= $isPublic ? 'This report is visible to anyone who has the link below.' : 'Only members of your organization who are signed in can view this report.' ?></div>
            </div>
            <label class="share-toggle" title="Toggle public access">
                <input type="checkbox" id="shareToggleInput" <?= $isPublic ? 'checked' : '' ?>>
                <div class="share-toggle-track"></div>
                <div class="share-toggle-thumb"></div>
            </label>
        </div>

        <div class="share-link-row <?= $isPublic ? '' : 'share-link-disabled' ?>" id="shareLinkRow">
            <input type="text" class="share-link-input" id="shareLinkInput" readonly value="<?= e($publicUrl) ?>" placeholder="Make the report public to generate a link">
            <button type="button" class="share-copy-btn" id="shareCopyBtn" onclick="copyShareLink()">Copy</button>
        </div>

        <div class="share-actions">
            <button type="button" class="btn-close-share" onclick="closeShareModal()">Done</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
/* ── Share Modal ─────────────────────────────────────────────────── */
const TRANSCRIPTION_ID = <?= (int) $row['id'] ?>;
function openShareModal()  { document.getElementById('shareBackdrop')?.classList.add('open'); }
function closeShareModal() {
    const bd = document.getElementById('shareBackdrop');
    if (bd) {
        bd.style.transition = 'opacity 1.5s ease';
        bd.style.opacity = '0';
        setTimeout(() => {
            bd.classList.remove('open');
            bd.style.transition = '';
            bd.style.opacity = '';
        }, 1500);
    }
}
function copyShareLink() {
    const inp = document.getElementById('shareLinkInput');
    const btn = document.getElementById('shareCopyBtn');
    if (!inp || !inp.value) return;
    navigator.clipboard.writeText(inp.value).then(() => {
        btn.textContent = 'Copied!';
        btn.classList.add('copied');
        setTimeout(() => { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 1800);
    });
}
document.getElementById('shareToggleInput')?.addEventListener('change', async (e) => {
    const makePublic = e.target.checked ? 1 : 0;
    try {
        const fd = new FormData();
        fd.append('id', TRANSCRIPTION_ID);
        fd.append('public', makePublic);
        const r = await fetch('/api/transcription-share.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await r.json();
        if (!r.ok) throw new Error(data.error || 'Failed');
        // Update UI
        const status = document.getElementById('shareStatus');
        const title  = document.getElementById('shareStatusTitle');
        const desc   = document.getElementById('shareStatusDesc');
        const row    = document.getElementById('shareLinkRow');
        const input  = document.getElementById('shareLinkInput');
        const shareBtn = document.getElementById('shareBtn');
        if (data.is_public === 1) {
            status.classList.add('is-public');
            title.textContent = 'Public — anyone with the link';
            desc.textContent  = 'This report is visible to anyone who has the link below.';
            row.classList.remove('share-link-disabled');
            input.value = data.public_url || '';
            shareBtn?.classList.remove('is-private');
            shareBtn?.classList.add('is-public');
            shareBtn?.querySelector('.share-label') && (shareBtn.querySelector('.share-label').textContent = 'Public');
        } else {
            status.classList.remove('is-public');
            title.textContent = 'Private — sign-in required';
            desc.textContent  = 'Only members of your organization who are signed in can view this report.';
            row.classList.add('share-link-disabled');
            shareBtn?.classList.remove('is-public');
            shareBtn?.classList.add('is-private');
            shareBtn?.querySelector('.share-label') && (shareBtn.querySelector('.share-label').textContent = 'Private');
        }
    } catch (err) {
        alert('Could not update sharing: ' + err.message);
        e.target.checked = !e.target.checked;
    }
});

/* ── Charts (Visual Insights) ────────────────────────────────────── */

<?php
// Fetch OpenRouter API key for quiz generation (only for authenticated users)
$orApiKey = '';
if ($canManageShare) {
    try {
        $ks = $db->prepare("SELECT setting_value FROM settings WHERE organization_id = :org AND setting_key = 'openRouterApiKey' LIMIT 1");
        $ks->execute([':org' => (int) $row['organization_id']]);
        $kr = $ks->fetch(PDO::FETCH_ASSOC);
        if ($kr) $orApiKey = $kr['setting_value'];
    } catch (PDOException $e) { /* non-fatal */ }
}
?>
const REPORT = {
    mode: <?= json_encode($mode) ?>,
    transcript: <?= json_encode($transcript) ?>,
    duration: <?= (int) $duration ?>,
    wordCount: <?= (int) $wordCount ?>,
    charCount: <?= (int) $charCount ?>,
    analysis: <?= json_encode($analysis) ?>,
    apiKey: <?= json_encode($orApiKey) ?>,
};

(function initCharts() {
    if (typeof Chart === 'undefined') return;

    // Brand palette
    const palette = [brandColor('200'), brandColor('300'), brandColor('400'), brandColor('500'), brandColor('600'), brandColor('700'), brandColor('800'), brandColor('900')];
    Chart.defaults.font.family = "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
    Chart.defaults.font.size = 11;
    Chart.defaults.color = '#475569';
    Chart.defaults.plugins.legend.labels.boxWidth = 12;
    Chart.defaults.plugins.legend.labels.padding = 12;

    /** Resolve --brand-XXX CSS var to an actual hex color for Chart.js */
    function brandColor(level, fallback) {
        const v = getComputedStyle(document.documentElement).getPropertyValue('--brand-' + level).trim();
        return v || fallback || '#3b82f6';
    }
    function brandRgba(level, alpha) {
        const h = brandColor(level);
        const r = parseInt(h.slice(1,3),16), g = parseInt(h.slice(3,5),16), b = parseInt(h.slice(5,7),16);
        return `rgba(${r},${g},${b},${alpha})`;
    }
    function gradient(ctx, top, bottom) {
        const g = ctx.createLinearGradient(0, 0, 0, 220);
        g.addColorStop(0, top);
        g.addColorStop(1, bottom);
        return g;
    }

    // ---------- Recording (default) ----------
    if (REPORT.mode === 'recording' || (REPORT.mode !== 'meeting' && REPORT.mode !== 'learning')) {
        // Words per minute
        const wpm = REPORT.duration > 0 ? Math.round((REPORT.wordCount / REPORT.duration) * 60) : REPORT.wordCount;
        const c1 = document.getElementById('chart_wpm');
        if (c1) new Chart(c1, {
            type: 'doughnut',
            data: {
                labels: ['Your pace', 'Headroom to 200 WPM'],
                datasets: [{
                    data: [Math.min(wpm, 200), Math.max(0, 200 - wpm)],
                    backgroundColor: [brandColor('300'), '#e2e8f0'],
                    borderWidth: 0,
                }],
            },
            options: {
                cutout: '72%',
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (c) => c.dataIndex === 0 ? `${wpm} WPM` : 'Headroom' } },
                },
            },
            plugins: [{
                id: 'centerText',
                afterDraw(chart) {
                    const { ctx, chartArea: { width, height } } = chart;
                    ctx.save();
                    ctx.font = '700 28px Inter, sans-serif';
                    ctx.fillStyle = '#0f172a';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(wpm, width / 2, height / 2 + chart.chartArea.top - 8);
                    ctx.font = '600 11px Inter, sans-serif';
                    ctx.fillStyle = '#64748b';
                    ctx.fillText('WPM', width / 2, height / 2 + chart.chartArea.top + 14);
                    ctx.restore();
                },
            }],
        });

        // Pace profile — split transcript into 8 chunks, count words per chunk
        const c2 = document.getElementById('chart_pace');
        if (c2 && REPORT.transcript) {
            const words = REPORT.transcript.trim().split(/\s+/);
            const buckets = 8;
            const chunkSize = Math.max(1, Math.ceil(words.length / buckets));
            const data = [];
            const labels = [];
            for (let i = 0; i < buckets; i++) {
                data.push(words.slice(i * chunkSize, (i + 1) * chunkSize).length);
                labels.push(`${Math.round((i / buckets) * 100)}%`);
            }
            const ctx = c2.getContext('2d');
            new Chart(c2, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label: 'Words',
                        data,
                        fill: true,
                        backgroundColor: gradient(ctx, brandRgba('400', 0.45), brandRgba('400', 0.02)),
                        borderColor: brandColor('500'),
                        borderWidth: 2.5,
                        tension: 0.4,
                        pointRadius: 4,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: brandColor('500'),
                        pointBorderWidth: 2,
                    }],
                },
                options: {
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' } },
                        x: { grid: { display: false } },
                    },
                },
            });
        }

        // Top words
        const c3 = document.getElementById('chart_topwords');
        if (c3 && REPORT.transcript) {
            const stop = new Set(('a an and are as at be but by do for from has have he her hers him his i if in is it its me my of on or our she that the their them they this to was we were will with you your yours its').split(' '));
            const counts = {};
            REPORT.transcript.toLowerCase().match(/[a-z']+/g)?.forEach(w => {
                if (w.length < 3 || stop.has(w)) return;
                counts[w] = (counts[w] || 0) + 1;
            });
            const top = Object.entries(counts).sort((a,b) => b[1]-a[1]).slice(0, 10);
            const ctx = c3.getContext('2d');
            new Chart(c3, {
                type: 'bar',
                data: {
                    labels: top.map(t => t[0]),
                    datasets: [{
                        label: 'Mentions',
                        data: top.map(t => t[1]),
                        backgroundColor: gradient(ctx, brandColor('300'), brandColor('700')),
                        borderRadius: 8,
                        borderSkipped: false,
                    }],
                },
                options: {
                    indexAxis: 'y',
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { beginAtZero: true, grid: { color: '#f1f5f9' } },
                        y: { grid: { display: false } },
                    },
                },
            });
        }
    }

    // ---------- Meeting ----------
    if (REPORT.mode === 'meeting') {
        const speakers = (REPORT.analysis.speakers || REPORT.analysis.attendees || []);
        const c1 = document.getElementById('chart_speakers');
        if (c1) {
            const labels = speakers.length ? speakers.map((s,i) => s.name || s.label || `Speaker ${i+1}`) : ['Host','Guest 1','Guest 2'];
            const data   = speakers.length ? speakers.map((s) => s.talk_time || s.percentage || 1) : [55, 30, 15];
            new Chart(c1, {
                type: 'doughnut',
                data: { labels, datasets: [{ data, backgroundColor: palette, borderWidth: 0 }] },
                options: { plugins: { legend: { position: 'bottom' } }, cutout: '60%' },
            });
        }
        const c2 = document.getElementById('chart_sentiment');
        if (c2) {
            const sent = REPORT.analysis.sentiment || { positive: 60, neutral: 30, negative: 10 };
            new Chart(c2, {
                type: 'polarArea',
                data: {
                    labels: ['Positive','Neutral','Negative'],
                    datasets: [{
                        data: [sent.positive || 60, sent.neutral || 30, sent.negative || 10],
                        backgroundColor: ['rgba(34,197,94,0.65)','rgba(148,163,184,0.65)','rgba(239,68,68,0.65)'],
                        borderColor: ['#22c55e','#94a3b8','#ef4444'],
                        borderWidth: 2,
                    }],
                },
                options: { plugins: { legend: { position: 'bottom' } } },
            });
        }
        const c3 = document.getElementById('chart_actions');
        if (c3) {
            const actions = REPORT.analysis.action_items || REPORT.analysis.actionItems || [];
            const owners = {};
            actions.forEach(a => {
                const o = (typeof a === 'string') ? 'Team' : (a.owner || a.assignee || 'Team');
                owners[o] = (owners[o] || 0) + 1;
            });
            if (Object.keys(owners).length === 0) { owners['Team'] = actions.length || 3; }
            const ctx = c3.getContext('2d');
            new Chart(c3, {
                type: 'bar',
                data: {
                    labels: Object.keys(owners),
                    datasets: [{
                        label: 'Action items',
                        data: Object.values(owners),
                        backgroundColor: gradient(ctx, brandColor('300'), brandColor('grad-mid')),
                        borderRadius: 8,
                        borderSkipped: false,
                    }],
                },
                options: {
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f1f5f9' } },
                        x: { grid: { display: false } },
                    },
                },
            });
        }
    }

    // ---------- Learning ----------
    if (REPORT.mode === 'learning') {
        // Concepts come from AI analysis under key_concepts (objects with term, importance, explanation)
        const concepts = REPORT.analysis.key_concepts || REPORT.analysis.concepts || [];
        const c1 = document.getElementById('chart_concepts');
        if (c1) {
            const conceptData = concepts.length ? concepts.slice(0, 7) : [];
            const labels = conceptData.length
                ? conceptData.map(c => {
                    if (typeof c === 'string') return c;
                    const name = c.term || c.name || c.concept || 'Concept';
                    // Radar labels get cramped — truncate long terms with ellipsis
                    return name.length > 24 ? name.slice(0, 22) + '…' : name;
                  })
                : ['Topic A','Topic B','Topic C','Topic D','Topic E'];
            // Use importance if provided, else derive deterministic score from concept name length
            const data = conceptData.length
                ? conceptData.map(c => {
                    const imp = (typeof c === 'object' && c.importance) ? c.importance : 'medium';
                    return imp === 'high' ? 90 : imp === 'medium' ? 65 : 40;
                  })
                : labels.map((_, i) => 50 + ((i * 13) % 40));
            new Chart(c1, {
                type: 'radar',
                data: {
                    labels,
                    datasets: [{
                        label: 'Coverage',
                        data,
                        backgroundColor: brandRgba('400', 0.30),
                        borderColor: brandColor('500'),
                        borderWidth: 2,
                        pointBackgroundColor: brandColor('600'),
                        pointBorderColor: '#fff',
                        pointRadius: 4,
                    }],
                },
                options: {
                    plugins: { legend: { display: false } },
                    scales: { r: {
                        beginAtZero: true, max: 100,
                        ticks: { stepSize: 20, color: '#94a3b8', backdropColor: 'transparent' },
                        grid: { color: 'rgba(100,116,139,0.15)' },
                        angleLines: { color: 'rgba(100,116,139,0.15)' },
                        pointLabels: { color: '#475569', font: { size: 12, weight: '600' } }
                    } },
                },
            });
        }
        const c2 = document.getElementById('chart_domain');
        if (c2) {
            new Chart(c2, {
                type: 'doughnut',
                data: {
                    labels: ['Conceptual','Procedural','Strategic','Reflective'],
                    datasets: [{
                        data: [40, 25, 20, 15],
                        backgroundColor: [
                            brandColor('300'),
                            brandColor('500'),
                            brandColor('700'),
                            brandColor('400'),
                        ],
                        borderColor: '#ffffff',
                        borderWidth: 2,
                    }],
                },
                options: {
                    plugins: {
                        legend: { position: 'bottom', labels: { color: '#475569', font: { size: 11 }, boxWidth: 12, padding: 10 } }
                    },
                    cutout: '62%'
                },
            });
        }
        const c3 = document.getElementById('chart_difficulty');
        if (c3) {
            const ctx = c3.getContext('2d');
            // Count concepts by importance tier for a real Difficulty Spread
            let easy = 0, medium = 0, hard = 0, expert = 0;
            if (concepts.length) {
                concepts.forEach(c => {
                    const imp = (typeof c === 'object' && c.importance) ? String(c.importance).toLowerCase() : 'medium';
                    if (imp === 'low' || imp === 'easy') easy++;
                    else if (imp === 'high' || imp === 'hard') hard++;
                    else if (imp === 'expert' || imp === 'advanced') expert++;
                    else medium++;
                });
            }
            // Sensible default if nothing tagged
            if (easy + medium + hard + expert === 0) {
                const n = concepts.length || (REPORT.analysis.objectives || []).length || 8;
                easy = Math.max(1, Math.round(n * 0.25));
                medium = Math.max(1, Math.round(n * 0.40));
                hard = Math.max(1, Math.round(n * 0.25));
                expert = Math.max(0, n - easy - medium - hard);
            }
            new Chart(c3, {
                type: 'bar',
                data: {
                    labels: ['Easy','Medium','Hard','Expert'],
                    datasets: [{
                        label: 'Items',
                        data: [easy, medium, hard, expert],
                        backgroundColor: [
                            brandColor('200'),
                            brandColor('400'),
                            brandColor('600'),
                            brandColor('800'),
                        ],
                        borderRadius: 8,
                        borderSkipped: false,
                    }],
                },
                options: {
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1, color: '#94a3b8' }, grid: { color: 'rgba(100,116,139,0.10)' } },
                        x: { ticks: { color: '#475569', font: { weight: '600' } }, grid: { display: false } },
                    },
                },
            });
        }
    }
})();

/* ── Title Editing ─────────────────────────────────────────────── */
function startEditTitle() {
    const h1 = document.getElementById('reportTitle');
    const btn = document.getElementById('titleEditBtn');
    if (!h1 || h1.dataset.editing === 'true') return;
    h1.dataset.editing = 'true';
    const currentTitle = h1.textContent.trim();
    const input = document.createElement('input');
    input.type = 'text';
    input.value = currentTitle;
    input.className = 'title-edit-input';
    input.maxLength = 200;
    const hint = document.createElement('div');
    hint.className = 'title-edit-hint';
    hint.textContent = 'Press Enter to save · Escape to cancel';
    h1.textContent = '';
    h1.appendChild(input);
    h1.appendChild(hint);
    if (btn) btn.style.display = 'none';
    input.focus();
    input.select();

    const save = async () => {
        const newTitle = input.value.trim();
        if (!newTitle || newTitle === currentTitle) { cancel(); return; }
        try {
            const fd = new FormData();
            fd.append('id', TRANSCRIPTION_ID);
            fd.append('title', newTitle);
            const r = await fetch('/api/update-title.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const d = await r.json();
            if (!r.ok) throw new Error(d.error || 'Failed');
            h1.textContent = newTitle;
        } catch (err) {
            alert('Could not save title: ' + err.message);
            h1.textContent = currentTitle;
        }
        h1.dataset.editing = '';
        if (btn) btn.style.display = '';
    };
    const cancel = () => {
        h1.textContent = currentTitle;
        h1.dataset.editing = '';
        if (btn) btn.style.display = '';
    };
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); save(); }
        if (e.key === 'Escape') cancel();
    });
    input.addEventListener('blur', () => setTimeout(save, 150));
}

/* ── Pop Quiz ─────────────────────────────────────────────── */
window._quizQuestions = null;
window._quizAnswers = [];

function closeQuiz() {
    const modal = document.querySelector('.quiz-modal');
    const backdrop = document.querySelector('.quiz-backdrop');
    if (modal) modal.classList.add('closing');
    setTimeout(() => { if (backdrop) backdrop.remove(); }, 1000);
}

// Electric particles around the brain
function startBrainParticles() {
    const canvas = document.getElementById('quizBrainCanvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const w = 260, h = 260, cx = w/2, cy = h/2;
    let sparks = [];
    let t = 0;
    let running = true;

    function spawn() {
        const angle = Math.random() * Math.PI * 2;
        const r = 55 + Math.random() * 15;
        sparks.push({
            x: cx + Math.cos(angle) * r,
            y: cy + Math.sin(angle) * r,
            vx: (Math.random() - 0.5) * 2.5,
            vy: (Math.random() - 0.5) * 2.5,
            life: 0,
            maxLife: 25 + Math.random() * 30,
            size: 1.5 + Math.random() * 2.5,
            color: Math.random() > 0.5 ? [255,255,255] : (Math.random() > 0.5 ? [147,197,253] : [191,219,254]),
        });
    }

    function step() {
        if (!running) return;
        t++;
        ctx.clearRect(0, 0, w, h);

        // Spawn
        for (let i = 0; i < 3; i++) if (sparks.length < 60) spawn();

        // Draw electric arcs
        for (let i = 0; i < 4; i++) {
            const a = (t * 0.03 + i * Math.PI / 2) % (Math.PI * 2);
            const r = 58 + Math.sin(t * 0.08 + i) * 8;
            const x = cx + Math.cos(a) * r;
            const y = cy + Math.sin(a) * r;
            const grad = ctx.createRadialGradient(x, y, 0, x, y, 18);
            grad.addColorStop(0, brandRgba('300', 0.6));
            grad.addColorStop(1, brandRgba('300', 0));
            ctx.fillStyle = grad;
            ctx.beginPath(); ctx.arc(x, y, 18, 0, Math.PI * 2); ctx.fill();
        }

        // Draw + update sparks
        for (let i = sparks.length - 1; i >= 0; i--) {
            const s = sparks[i];
            s.life++; s.x += s.vx; s.y += s.vy;
            if (s.life >= s.maxLife) { sparks.splice(i, 1); continue; }
            const prog = s.life / s.maxLife;
            let alpha = prog < 0.15 ? prog / 0.15 : (prog > 0.6 ? 1 - (prog - 0.6) / 0.4 : 1);
            alpha *= 0.8;
            const c = s.color;
            // Glow
            const g = ctx.createRadialGradient(s.x, s.y, 0, s.x, s.y, s.size * 4);
            g.addColorStop(0, `rgba(${c[0]},${c[1]},${c[2]},${alpha * 0.6})`);
            g.addColorStop(1, `rgba(${c[0]},${c[1]},${c[2]},0)`);
            ctx.fillStyle = g;
            ctx.beginPath(); ctx.arc(s.x, s.y, s.size * 4, 0, Math.PI * 2); ctx.fill();
            // Core
            ctx.fillStyle = `rgba(255,255,255,${alpha * 0.9})`;
            ctx.beginPath(); ctx.arc(s.x, s.y, s.size * 0.5, 0, Math.PI * 2); ctx.fill();
        }

        // Connect nearby sparks with thin lines
        for (let i = 0; i < sparks.length; i++) {
            for (let j = i + 1; j < sparks.length; j++) {
                const dx = sparks[i].x - sparks[j].x, dy = sparks[i].y - sparks[j].y;
                const d2 = dx*dx + dy*dy;
                if (d2 < 2500) {
                    const a = (1 - Math.sqrt(d2) / 50) * 0.15;
                    ctx.strokeStyle = `rgba(255,255,255,${a})`;
                    ctx.lineWidth = 0.6;
                    ctx.beginPath(); ctx.moveTo(sparks[i].x, sparks[i].y); ctx.lineTo(sparks[j].x, sparks[j].y); ctx.stroke();
                }
            }
        }

        requestAnimationFrame(step);
    }

    step();
    return () => { running = false; };
}

async function startPopQuiz() {
    const overlay = document.createElement('div');
    overlay.className = 'quiz-backdrop open';
    overlay.innerHTML = `
        <div class="quiz-modal">
            <div class="quiz-header">
                <button class="quiz-close" onclick="closeQuiz()">&times;</button>
                <div class="quiz-header-icon">A+</div>
                <h2>Pop Quiz</h2>
                <p>Testing your understanding of the content</p>
                <div class="quiz-progress-wrap" id="quizProgressWrap" style="display:none"><div class="quiz-progress-bar" id="quizProgressBar" style="width:0%"></div></div>
                <div class="quiz-progress-text" id="quizProgressText" style="display:none"></div>
            </div>
            <div class="quiz-body" id="quizBody">
                <div class="quiz-loading" id="quizLoadingState">
                    <div class="quiz-brain-wrap">
                        <canvas id="quizBrainCanvas" width="260" height="260"></canvas>
                        <div class="quiz-loading-icon">🧠</div>
                    </div>
                    <p>Generating questions from the content...</p>
                    <div class="quiz-loading-dots"><span></span><span></span><span></span></div>
                </div>
            </div>
        </div>
    `;
    overlay.addEventListener('click', (e) => { if (e.target === overlay) closeQuiz(); });
    document.body.appendChild(overlay);
    // Start electric particles around the brain
    setTimeout(startBrainParticles, 100);

    try {
        const apiKey = REPORT.apiKey;
        if (!apiKey) throw new Error('API key not configured');
        const transcript = REPORT.transcript || '';
        const concepts = (REPORT.analysis?.key_concepts || []).map(c => c.term || c).join(', ');

        let attemptCount = 0;
        try {
            const cr = await fetch(`/api/quiz.php?action=count&transcription_id=${TRANSCRIPTION_ID}`);
            const cd = await cr.json();
            attemptCount = cd.count || 0;
        } catch(e) {}

        const quizPrompt = `Generate a quiz. Create 5-8 multiple choice questions.
${attemptCount > 0 ? `Attempt #${attemptCount + 1} — generate DIFFERENT questions.` : ''}
${attemptCount >= 8 ? `User has taken ${attemptCount} quizzes. If not enough content for new questions, return: [{"exhausted":true}]` : ''}

4 options each, one correct. Mix core concepts, details, and application.
Return ONLY JSON array: [{"question":"...","options":["A)...","B)...","C)...","D)..."],"correct":0,"explanation":"..."}]

KEY CONCEPTS: ${concepts}
CONTENT: ${transcript.substring(0, 6000)}`;

        const minWait = new Promise(r => setTimeout(r, 2500));
        const apiCall = fetch('https://openrouter.ai/api/v1/chat/completions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${apiKey}`, 'HTTP-Referer': window.location.origin },
            body: JSON.stringify({ model: 'google/gemini-2.5-flash', messages: [{ role: 'user', content: quizPrompt }], temperature: 0.4 + (attemptCount * 0.05), max_tokens: 3000 })
        });
        const [, resp] = await Promise.all([minWait, apiCall]);
        const data = await resp.json();
        let qt = data.choices?.[0]?.message?.content || '';
        qt = qt.replace(/```json\n?/g, '').replace(/```\n?/g, '').trim();
        const questions = JSON.parse(qt);

        if (questions[0]?.exhausted) {
            document.getElementById('quizBody').innerHTML = `<div style="text-align:center;padding:40px 20px">
                <div style="width:120px;height:120px;border-radius:50%;background:#fff;border:3px solid rgba(var(--brand-400-rgb), 0.5);display:grid;place-items:center;margin:0 auto 24px;box-shadow:0 8px 24px rgba(var(--brand-400-rgb), 0.2)">
                    <span style="font-size:64px;line-height:1">🎓</span>
                </div>
                <h3 style="color:#fff;font-size:28px;font-weight:800;margin:0 0 10px">Quiz Master!</h3>
                <p style="color:rgba(255,255,255,0.65);margin:0 0 36px;font-size:15px;line-height:1.6">You've completed all unique questions<br>for this content. Great job studying!</p>
                <button onclick="showQuizHistory()" style="display:inline-flex;align-items:center;gap:10px;padding:14px 32px;border:none;border-radius:12px;background:linear-gradient(135deg,var(--brand-700),var(--brand-600),var(--brand-500));color:#fff;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 6px 20px rgba(var(--brand-600-rgb), 0.4),inset 0 1px 0 rgba(255,255,255,0.18);transition:all 0.3s">
                    <svg xmlns='http://www.w3.org/2000/svg' width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='12' r='10'/><polyline points='12 6 12 12 16 14'/></svg>
                    View Quiz History
                </button>
            </div>`;
            return;
        }

        window._quizQuestions = questions;
        window._quizAnswers = new Array(questions.length).fill(-1);
        window._quizTotal = questions.length;
        window._quizScore = 0;
        window._quizAnswered = 0;

        // Show progress bar
        const pw = document.getElementById('quizProgressWrap');
        const pt = document.getElementById('quizProgressText');
        if (pw) pw.style.display = '';
        if (pt) { pt.style.display = ''; pt.textContent = `0 of ${questions.length} answered`; }

        document.getElementById('quizBody').innerHTML = questions.map((q, qi) => `
            <div class="quiz-question ${qi === 0 ? 'current' : 'blurred'}" id="qq${qi}">
                <div class="quiz-q-num">Q${qi+1} of ${questions.length}</div>
                <p class="quiz-q-text">${q.question.replace(/</g,'&lt;')}</p>
                <div class="quiz-options">${q.options.map((opt, oi) => `<button class="quiz-option" onclick="selectQuizAnswer(${qi},${oi},${q.correct})">${opt.replace(/</g,'&lt;')}</button>`).join('')}</div>
                <div class="quiz-explanation" id="qexp${qi}"></div>
            </div>
        `).join('') + '<div id="quizScoreBox" style="display:none"></div>';
    } catch (err) {
        document.getElementById('quizBody').innerHTML = `<div style="text-align:center;padding:40px 20px"><p style="color:#64748b">Could not generate quiz: ${err.message}</p><button class="quiz-btn-close" onclick="closeQuiz()" style="margin-top:16px">Close</button></div>`;
    }
}

function selectQuizAnswer(qi, oi, correct) {
    const qq = document.getElementById('qq' + qi);
    if (qq.dataset.answered) return;
    qq.dataset.answered = '1';
    window._quizAnswered++;
    window._quizAnswers[qi] = oi;
    const isCorrect = oi === correct;
    if (isCorrect) window._quizScore++;

    // Update progress bar
    const pctDone = Math.round((window._quizAnswered / window._quizTotal) * 100);
    const pb = document.getElementById('quizProgressBar');
    const pt = document.getElementById('quizProgressText');
    if (pb) pb.style.width = pctDone + '%';
    if (pt) pt.textContent = `${window._quizAnswered} of ${window._quizTotal} answered`;

    // Mark options correct/wrong
    qq.querySelectorAll('.quiz-option').forEach((b, i) => {
        b.style.pointerEvents = 'none';
        if (i === correct) b.classList.add('quiz-correct');
        if (i === oi && oi !== correct) b.classList.add('quiz-wrong');
    });

    // Animate the question — bounce+glow for correct, shake+glow for wrong
    qq.classList.remove('current');
    qq.classList.add('answered', isCorrect ? 'answer-correct' : 'answer-wrong');

    // Slide open the explanation
    const exp = document.getElementById('qexp' + qi);
    exp.innerHTML = `<strong>${isCorrect ? '✓ Correct!' : '✗ Incorrect'}</strong> ${window._quizQuestions[qi].explanation || ''}`;
    exp.className = 'quiz-explanation ' + (isCorrect ? 'quiz-exp-correct' : 'quiz-exp-wrong');
    setTimeout(() => exp.classList.add('visible'), 100);

    // Unblur the next question and scroll to it
    const nextQ = document.getElementById('qq' + (qi + 1));
    if (nextQ) {
        setTimeout(() => {
            nextQ.classList.remove('blurred');
            nextQ.classList.add('current');
            nextQ.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 600);
    }

    if (window._quizAnswered >= window._quizTotal) {
        // Save to database
        fetch('/api/quiz.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ action: 'save', transcription_id: TRANSCRIPTION_ID, score: window._quizScore, total_questions: window._quizTotal, questions_json: JSON.stringify(window._quizQuestions), answers_json: JSON.stringify(window._quizAnswers) })
        }).catch(() => {});

        // Start AI summary generation during celebration
        window._quizAISummaryPromise = generateQuizAISummary();

        // Launch celebration/result screen
        setTimeout(() => showQuizCelebration(window._quizScore, window._quizTotal), 1200);
    }
}

async function showQuizHistory() {
    // Close quiz modal if open, but don't wait
    const existingQuiz = document.querySelector('.quiz-backdrop');
    if (existingQuiz) existingQuiz.remove();
    const ov = document.createElement('div');
    ov.className = 'share-backdrop open';
    ov.innerHTML = `<div class="share-modal quiz-history-modal" style="max-width:540px;max-height:80vh;overflow-y:auto;text-align:center;animation:quizZoomIn 1s cubic-bezier(0.34,1.56,0.64,1) forwards;transform:scale(0.3);opacity:0">
        <h2 style="font-size:36px;font-weight:800;letter-spacing:-0.5px;margin:0 0 4px">Quiz History</h2>
        <p class="share-sub" style="margin-top:0">Your past quiz attempts for this report</p>
        <div id="quizHistoryList" style="margin-top:20px"></div>
        <div id="quizHistoryPagination" style="margin-top:12px"></div>
        <div style="margin-top:26px;padding-top:10px">
            <button class="quiz-history-close" style="padding:12px 32px;font-size:14px" onclick="var bd=this.closest('.share-backdrop');var m=bd.querySelector('.quiz-history-modal');if(m)m.style.animation='quizZoomOut 1s cubic-bezier(0.55,0,1,0.45) forwards';bd.style.transition='opacity 1.5s ease';bd.style.opacity='0';setTimeout(function(){bd.remove()},1500)">Close</button>
        </div>
    </div>`;
    ov.addEventListener('click', (e) => { if (e.target === ov) ov.remove(); });
    document.body.appendChild(ov);

    try {
        const r = await fetch(`/api/quiz.php?action=history&transcription_id=${TRANSCRIPTION_ID}`);
        const d = await r.json();
        const list = document.getElementById('quizHistoryList');
        if (!d.attempts?.length) { list.innerHTML = '<div style="padding:30px 0;color:rgba(255,255,255,0.45)"><div style="font-size:40px;margin-bottom:12px">📝</div><p style="margin:0;font-size:14px">No quiz attempts yet.<br>Take a Pop Quiz to test your knowledge!</p></div>'; return; }
        window._quizHistoryData = d.attempts;
        window._quizHistoryPage = 0;
        renderQuizHistoryPage();
    } catch(e) { document.getElementById('quizHistoryList').innerHTML = '<p style="color:rgba(255,255,255,0.5)">Failed to load</p>'; }
}

function renderQuizHistoryPage() {
    const data = window._quizHistoryData || [];
    const page = window._quizHistoryPage || 0;
    const perPage = 5;
    const start = page * perPage;
    const pageData = data.slice(start, start + perPage);
    const totalPages = Math.ceil(data.length / perPage);

    const list = document.getElementById('quizHistoryList');
    list.innerHTML = pageData.map((a, i) => {
        const idx = start + i;
        const dt = new Date(a.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
        const pct = a.total_questions > 0 ? Math.round((a.score / a.total_questions) * 100) : 0;
        const scoreColor = pct >= 80 ? '#22c55e' : (pct >= 50 ? '#fbbf24' : '#f87171');
        return `<div class="quiz-history-item" onclick="viewQuizAttempt(${idx})" style="text-align:left">
            <div style="display:flex;align-items:center;gap:12px;flex:1">
                <div style="width:36px;height:36px;border-radius:10px;background:rgba(var(--brand-500-rgb),0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:16px">📝</div>
                <div>
                    <strong style="font-size:14px">Attempt #${data.length - idx}</strong>
                    <div class="quiz-history-date">${dt}</div>
                </div>
            </div>
            <div style="text-align:right">
                <div style="font-size:20px;font-weight:800;color:${scoreColor}">${a.score}<span style="font-size:13px;font-weight:500;color:rgba(255,255,255,0.4)">/${a.total_questions}</span></div>
                <div style="font-size:11px;color:rgba(255,255,255,0.4)">${pct}%</div>
            </div>
        </div>`;
    }).join('');

    const pag = document.getElementById('quizHistoryPagination');
    if (totalPages > 1 && pag) {
        pag.innerHTML = `<div style="display:flex;gap:6px;justify-content:center">${Array.from({length: totalPages}, (_, i) =>
            `<button onclick="window._quizHistoryPage=${i};renderQuizHistoryPage()" style="width:32px;height:32px;border-radius:8px;border:1px solid rgba(255,255,255,${i===page?'0.4':'0.12'});background:rgba(255,255,255,${i===page?'0.15':'0.04'});color:${i===page?'#fff':'rgba(255,255,255,0.5)'};cursor:pointer;font-weight:${i===page?'700':'400'};font-family:inherit">${i+1}</button>`
        ).join('')}</div>`;
    } else if (pag) { pag.innerHTML = ''; }
}

function viewQuizAttempt(idx) {
    const a = window._quizHistoryData?.[idx];
    if (!a) return;
    const qs = JSON.parse(a.questions_json || '[]');
    const ans = JSON.parse(a.answers_json || '[]');
    const pct = a.total_questions > 0 ? Math.round((a.score / a.total_questions) * 100) : 0;
    const ov = document.createElement('div');
    ov.className = 'quiz-backdrop open';
    ov.innerHTML = `<div class="quiz-modal">
        <div class="quiz-header">
            <button class="quiz-close" onclick="this.closest('.quiz-backdrop').remove()">&times;</button>
            <div class="quiz-header-icon">${a.score}/${a.total_questions}</div>
            <h2>Quiz Review</h2>
            <p>${new Date(a.created_at).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}</p>
            <div class="quiz-progress-wrap"><div class="quiz-progress-bar" style="width:${pct}%"></div></div>
            <div class="quiz-progress-text">${pct}% correct</div>
            <div style="margin-top:12px"><a href="/api/quiz-report.php?id=${a.id}" target="_blank" class="quiz-btn-close" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;font-size:12px;padding:8px 16px"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> View Full Report</a></div>
        </div>
        <div class="quiz-body">${qs.map((q, qi) => {
            const ua = ans[qi] ?? -1;
            return `<div class="quiz-question">
                <div class="quiz-q-num">Q${qi+1} of ${qs.length}</div>
                <p class="quiz-q-text">${q.question}</p>
                <div class="quiz-options">${q.options.map((opt, oi) =>
                    `<div class="quiz-option ${oi === q.correct ? 'quiz-correct' : ''} ${oi === ua && oi !== q.correct ? 'quiz-wrong' : ''}" style="pointer-events:none">${opt}</div>`
                ).join('')}</div>
                <div class="quiz-explanation ${ua === q.correct ? 'quiz-exp-correct' : 'quiz-exp-wrong'}">
                    <strong>${ua === q.correct ? '✓ Correct' : '✗ Incorrect'}</strong> ${q.explanation || ''}
                </div>
            </div>`;
        }).join('')}</div>
    </div>`;
    ov.addEventListener('click', (e) => { if (e.target === ov) ov.remove(); });
    document.body.appendChild(ov);
}

async function generateQuizAISummary() {
    try {
        const apiKey = REPORT.apiKey;
        if (!apiKey) return null;
        const qs = window._quizQuestions;
        const ans = window._quizAnswers;
        const score = window._quizScore;
        const total = window._quizTotal;

        // Build context about what they got right/wrong
        let rightTopics = [], wrongTopics = [];
        qs.forEach((q, i) => {
            const wasCorrect = ans[i] === q.correct;
            (wasCorrect ? rightTopics : wrongTopics).push(q.question);
        });

        const prompt = `A student just took a quiz on the following content and scored ${score}/${total} (${Math.round(score/total*100)}%).

Questions they answered CORRECTLY:
${rightTopics.map((q,i) => `${i+1}. ${q}`).join('\n') || 'None'}

Questions they answered INCORRECTLY:
${wrongTopics.map((q,i) => `${i+1}. ${q}`).join('\n') || 'None'}

Based on this, provide a brief personalized learning assessment. Return ONLY valid JSON:
{
  "strengths": ["specific concept they understand well"],
  "weaknesses": ["specific concept they need to work on"],
  "recommendation": "2-3 sentence personalized advice",
  "resources": [{"topic": "what to study", "search_query": "a specific Google search query to find resources on this topic", "description": "why this helps"}]
}

Be specific about what concepts they understand vs need to review. CRITICAL: Do NOT include any URLs unless they were explicitly mentioned in the quiz content. NEVER fabricate or guess URLs — they will be verified and fake ones will be removed. Just describe the resource topic without a URL if you don't have one from the source material.`;

        const resp = await fetch('https://openrouter.ai/api/v1/chat/completions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${apiKey}`, 'HTTP-Referer': window.location.origin },
            body: JSON.stringify({ model: 'google/gemini-2.5-flash', messages: [{ role: 'user', content: prompt }], temperature: 0.3, max_tokens: 1500 })
        });
        const data = await resp.json();
        let text = data.choices?.[0]?.message?.content || '';
        text = text.replace(/```json\n?/g, '').replace(/```\n?/g, '').trim();
        return JSON.parse(text);
    } catch (e) {
        console.warn('AI summary failed:', e);
        return null;
    }
}

// Verify URLs exist before displaying them — strips fake/hallucinated URLs
async function verifyAndFilterUrls(resources) {
    if (!resources?.length) return resources;
    const urlsToCheck = resources.filter(r => r.url && r.url.startsWith('http')).map(r => r.url);
    if (!urlsToCheck.length) return resources;
    try {
        const resp = await fetch('/api/verify-urls.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ urls: urlsToCheck })
        });
        const data = await resp.json();
        if (data.results) {
            return resources.map(r => {
                if (r.url && data.results[r.url] === false) {
                    return { ...r, url: null }; // Strip invalid URL
                }
                return r;
            });
        }
    } catch(e) { console.warn('URL verify failed:', e); }
    return resources;
}

function closeCelebration() {
    const ov = document.querySelector('.celebration-overlay');
    if (ov) {
        ov.classList.add('closing');
        setTimeout(() => ov.remove(), 1200);
    }
}

function showQuizCelebration(score, total) {
    const pct = Math.round((score / total) * 100);
    const isPerfect = score === total;
    const isPass = pct >= 70;
    const isFail = pct < 50;

    // Close the quiz modal
    const existingQuiz = document.querySelector('.quiz-backdrop');
    if (existingQuiz) existingQuiz.remove();

    // Build full-screen celebration overlay
    const overlay = document.createElement('div');
    overlay.className = 'celebration-overlay';
    // Tier data drives icon, title, subtitle, and color intensity
    const tiers = {
        perfect:  { icon: 'trophy',   title: 'Perfect Score!',   subtitle: 'Flawless performance - you nailed every single question!', tierClass: 'tier-perfect',  medal: '\ud83c\udfc6' },
        excellent:{ icon: 'medal',    title: 'Excellent Work!',  subtitle: 'Top-tier knowledge - you clearly know this material.',      tierClass: 'tier-excellent',medal: '\ud83e\udd47' },
        good:     { icon: 'star',     title: 'Well Done!',       subtitle: 'Solid understanding - keep building on what you know.',     tierClass: 'tier-good',     medal: '\ud83e\udd48' },
        okay:     { icon: 'target',   title: 'Nice Try!',        subtitle: 'You got the basics - another pass will lock it in.',        tierClass: 'tier-okay',     medal: '\ud83e\udd49' },
        tryagain: { icon: 'sprout',   title: 'Keep Going!',      subtitle: 'Every attempt builds mastery - give it another shot.',      tierClass: 'tier-tryagain', medal: '\ud83c\udf31' }
    };
    let tier;
    if (isPerfect)           tier = tiers.perfect;
    else if (pct >= 85)      tier = tiers.excellent;
    else if (pct >= 70)      tier = tiers.good;
    else if (pct >= 50)      tier = tiers.okay;
    else                     tier = tiers.tryagain;

    const filledStars = isPerfect ? 5 : pct >= 85 ? 5 : pct >= 70 ? 4 : pct >= 50 ? 3 : pct >= 30 ? 2 : 1;
    const starsHtml = Array.from({length:5}, (_,i) =>
        `<svg class="celebration-star ${i < filledStars ? 'on' : 'off'}" width="28" height="28" viewBox="0 0 24 24" fill="${i < filledStars ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>`
    ).join('');

    const iconSvg = {
        trophy: '<svg width="84" height="84" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>',
        medal:  '<svg width="84" height="84" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M7.21 15 2.66 7.14a2 2 0 0 1 .13-2.2L4.4 2.8A2 2 0 0 1 6 2h12a2 2 0 0 1 1.6.8l1.6 2.14a2 2 0 0 1 .14 2.2L16.79 15"/><path d="M11 12 5.12 2.2"/><path d="m13 12 5.88-9.8"/><path d="M8 7h8"/><circle cx="12" cy="17" r="5"/></svg>',
        star:   '<svg width="84" height="84" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
        target: '<svg width="84" height="84" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
        sprout: '<svg width="84" height="84" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M7 20h10"/><path d="M10 20c5.5-2.5.8-6.4 3-10"/><path d="M9.5 9.4c1.1.8 1.8 2.2 2.3 3.7-2 .4-3.5.4-4.8-.3-1.2-.6-2.3-1.9-3-4.2 2.8-.5 4.4 0 5.5.8z"/><path d="M14.1 6a7 7 0 0 0-1.1 4c1.9-.1 3.3-.6 4.3-1.4 1-1 1.6-2.3 1.7-4.6-2.7.1-4 1-4.9 2z"/></svg>'
    };

    overlay.innerHTML = `
        <canvas id="celebrationSparks"></canvas>
        <canvas id="celebrationCanvas"></canvas>
        <div class="celebration-content" id="celebrationContent">
            <div class="celebration-card ${tier.tierClass}">
                <div class="celebration-icon-wrap">
                    <div class="celebration-icon">${iconSvg[tier.icon]}</div>
                </div>
                <div class="celebration-stars">${starsHtml}</div>
                <h1 class="celebration-title">${tier.title}</h1>
                <p class="celebration-subtitle">${tier.subtitle}</p>
                <div class="celebration-score-circle">
                    <div class="celebration-score-num">${score}<span>/${total}</span></div>
                    <div class="celebration-score-pct">${pct}%</div>
                </div>
                <div id="celebrationAISummary" class="celebration-ai-summary"></div>
                <div class="celebration-actions">
                    <button class="quiz-btn-retake" onclick="closeCelebration(); setTimeout(() => startPopQuiz(), 600);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                        Try Again
                    </button>
                    <button class="quiz-btn-close" onclick="closeCelebration()">Close</button>
                </div>
            </div>
        </div>
    `;

    setTimeout(() => {
        const sparkC = document.getElementById('celebrationSparks');
        if (!sparkC) return;
        const sctx = sparkC.getContext('2d');
        let sw=0, sh=0;
        function resz() { sw = sparkC.width = window.innerWidth; sh = sparkC.height = window.innerHeight; }
        resz();
        window.addEventListener('resize', resz);
        const sparks = [];
        const N = Math.min(140, Math.round((sw*sh)/9000));
        for (let i=0; i<N; i++) {
            const ang = Math.random() * Math.PI * 2;
            const sp = 0.05 + Math.random() * 0.25;
            sparks.push({
                x: Math.random()*sw, y: Math.random()*sh,
                vx: Math.cos(ang)*sp, vy: Math.sin(ang)*sp,
                r: 0.6 + Math.random()*1.6,
                ph: Math.random()*Math.PI*2, phs: 0.025 + Math.random()*0.06,
                life: Math.random()*400, maxLife: 350 + Math.random()*500
            });
        }
        function drawSparks() {
            if (!document.getElementById('celebrationSparks')) return;
            sctx.clearRect(0,0,sw,sh);
            for (const s of sparks) {
                s.x += s.vx; s.y += s.vy; s.ph += s.phs; s.life++;
                if (s.x < -10) s.x = sw+10; else if (s.x > sw+10) s.x = -10;
                if (s.y < -10) s.y = sh+10; else if (s.y > sh+10) s.y = -10;
                if (s.life > s.maxLife) { s.life = 0; s.maxLife = 350 + Math.random()*500; }
                const r = s.life / s.maxLife;
                const fade = r < 0.15 ? r/0.15 : r > 0.85 ? (1-r)/0.15 : 1;
                const flick = 0.55 + 0.45 * Math.sin(s.ph);
                const a = fade * flick * 0.75;
                const gr = sctx.createRadialGradient(s.x, s.y, 0, s.x, s.y, s.r*4);
                gr.addColorStop(0, `rgba(255,255,255,${a*0.5})`);
                gr.addColorStop(1, 'rgba(255,255,255,0)');
                sctx.fillStyle = gr;
                sctx.beginPath(); sctx.arc(s.x, s.y, s.r*4, 0, Math.PI*2); sctx.fill();
                sctx.fillStyle = `rgba(255,255,255,${Math.min(1, a+0.1)})`;
                sctx.beginPath(); sctx.arc(s.x, s.y, s.r, 0, Math.PI*2); sctx.fill();
            }
            requestAnimationFrame(drawSparks);
        }
        drawSparks();
    }, 50);
    document.body.appendChild(overlay);
    requestAnimationFrame(() => overlay.classList.add('active'));

    // Start particle animation
    const canvas = document.getElementById('celebrationCanvas');
    const ctx = canvas.getContext('2d');
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    let particles = [];
    let rafId = null;

    // Spawn initial particles
    const colors = [brandColor('400'),brandColor('300'),'#ffffff','#a78bfa','#fbbf24','#34d399','#f87171','#c084fc'];
    let t = 0;

    if (isPass) {
        for (let i = 0; i < (isPerfect ? 250 : 120); i++) {
            particles.push({
                x: Math.random() * canvas.width,
                y: -20 - Math.random() * canvas.height,
                vx: (Math.random() - 0.5) * 5,
                vy: 2 + Math.random() * 5,
                size: 3 + Math.random() * 9,
                color: colors[Math.floor(Math.random() * colors.length)],
                rotation: Math.random() * 360,
                rotSpeed: (Math.random() - 0.5) * 10,
                type: Math.random() > 0.25 ? 'confetti' : 'spark',
            });
        }
    } else {
        for (let i = 0; i < 60; i++) {
            particles.push({
                x: Math.random() * canvas.width,
                y: canvas.height + Math.random() * 200,
                vx: (Math.random() - 0.5) * 1.5,
                vy: -(0.3 + Math.random() * 1.5),
                size: 2 + Math.random() * 5,
                color: colors[Math.floor(Math.random() * 4)],
                life: 0, maxLife: 140 + Math.random() * 100,
                type: 'bubble',
            });
        }
    }

    function animateParticles() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        t++;

        // Two moving gradient lights orbiting across the background
        const lx1 = (Math.sin(t * 0.007) * 0.5 + 0.5) * canvas.width;
        const ly1 = (Math.cos(t * 0.005) * 0.5 + 0.5) * canvas.height;
        const g1 = ctx.createRadialGradient(lx1, ly1, 0, lx1, ly1, canvas.width * 0.55);
        g1.addColorStop(0, brandRgba('400', 0.22));
        g1.addColorStop(0.4, brandRgba('400', 0.08));
        g1.addColorStop(1, 'rgba(0, 0, 0, 0)');
        ctx.fillStyle = g1;
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        const lx2 = (Math.cos(t * 0.009) * 0.5 + 0.5) * canvas.width;
        const ly2 = (Math.sin(t * 0.004 + 2) * 0.5 + 0.5) * canvas.height;
        const g2 = ctx.createRadialGradient(lx2, ly2, 0, lx2, ly2, canvas.width * 0.4);
        g2.addColorStop(0, 'rgba(124, 58, 237, 0.15)');
        g2.addColorStop(0.5, 'rgba(167, 139, 250, 0.05)');
        g2.addColorStop(1, 'rgba(0, 0, 0, 0)');
        ctx.fillStyle = g2;
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        // Continuously spawn new particles to keep the animation alive
        if (isPass && particles.length < 100 && t < 300) {
            for (let j = 0; j < 3; j++) {
                particles.push({
                    x: Math.random() * canvas.width, y: -10,
                    vx: (Math.random() - 0.5) * 4, vy: 2 + Math.random() * 3,
                    size: 3 + Math.random() * 7,
                    color: colors[Math.floor(Math.random() * colors.length)],
                    rotation: Math.random() * 360, rotSpeed: (Math.random() - 0.5) * 8,
                    type: Math.random() > 0.3 ? 'confetti' : 'spark',
                });
            }
        } else if (!isPass && particles.length < 30 && t < 300) {
            particles.push({
                x: Math.random() * canvas.width, y: canvas.height + 10,
                vx: (Math.random() - 0.5) * 1, vy: -(0.3 + Math.random() * 1),
                size: 2 + Math.random() * 4,
                color: colors[Math.floor(Math.random() * 4)],
                life: 0, maxLife: 120 + Math.random() * 80, type: 'bubble',
            });
        }

        for (let i = particles.length - 1; i >= 0; i--) {
            const p = particles[i];
            p.x += p.vx; p.y += p.vy;
            if (p.type === 'confetti') {
                p.rotation += p.rotSpeed;
                p.vy += 0.04;
                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate(p.rotation * Math.PI / 180);
                ctx.fillStyle = p.color;
                ctx.fillRect(-p.size/2, -p.size/4, p.size, p.size/2);
                ctx.restore();
                if (p.y > canvas.height + 30) particles.splice(i, 1);
            } else if (p.type === 'spark') {
                // Glowing spark
                const sg = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, p.size * 2);
                sg.addColorStop(0, p.color);
                sg.addColorStop(1, 'transparent');
                ctx.fillStyle = sg;
                ctx.beginPath(); ctx.arc(p.x, p.y, p.size * 2, 0, Math.PI * 2); ctx.fill();
                ctx.fillStyle = '#fff';
                ctx.beginPath(); ctx.arc(p.x, p.y, p.size * 0.3, 0, Math.PI * 2); ctx.fill();
                p.vy += 0.02;
                if (p.y > canvas.height + 30) particles.splice(i, 1);
            } else {
                p.life++;
                if (p.life >= p.maxLife) { particles.splice(i, 1); continue; }
                const alpha = Math.sin((p.life / p.maxLife) * Math.PI) * 0.6;
                const bg = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, p.size * 3);
                bg.addColorStop(0, `rgba(147,197,253,${alpha})`);
                bg.addColorStop(1, 'transparent');
                ctx.fillStyle = bg;
                ctx.beginPath(); ctx.arc(p.x, p.y, p.size * 3, 0, Math.PI * 2); ctx.fill();
            }
        }
        rafId = requestAnimationFrame(animateParticles);
    }
    animateParticles();

    // Populate AI summary inline once ready (card is already rendered)
    setTimeout(() => {
        const summaryBox = document.getElementById('celebrationAISummary');
        if (window._quizAISummaryPromise) {
            window._quizAISummaryPromise.then(async (summary) => {
                if (!summary || !summaryBox) return;
                // Verify all resource URLs actually exist
                if (summary.resources?.length) {
                    summary.resources = await verifyAndFilterUrls(summary.resources);
                }
                let html = '<div style="text-align:left;background:#ffffff;border:1px solid #d1d5db;border-radius:14px;padding:20px 24px;margin-top:8px">';
                if (summary.strengths?.length) {
                    html += '<div style="margin-bottom:14px"><div style="font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#166534;margin-bottom:6px">✓ Strengths</div>';
                    html += summary.strengths.map(s => `<div style="color:#334155;font-size:14px;padding:4px 0">• ${s}</div>`).join('');
                    html += '</div>';
                }
                if (summary.weaknesses?.length) {
                    html += '<div style="margin-bottom:14px"><div style="font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#92400e;margin-bottom:6px">⚡ Areas to Improve</div>';
                    html += summary.weaknesses.map(w => `<div style="color:#334155;font-size:14px;padding:4px 0">• ${w}</div>`).join('');
                    html += '</div>';
                }
                if (summary.recommendation) {
                    html += `<div style="color:#64748b;font-size:13px;font-style:italic;line-height:1.6;border-top:1px solid #e2e8f0;padding-top:12px;margin-top:4px">${summary.recommendation}</div>`;
                }
                if (summary.resources?.length) {
                    html += '<div style="margin-top:12px;border-top:1px solid #e2e8f0;padding-top:12px"><div style="font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#1e3a8a;margin-bottom:6px">📚 Suggested Resources</div>';
                    summary.resources.forEach(r => {
                        const query = encodeURIComponent(r.search_query || r.topic || '');
                        html += `<div style="margin-bottom:10px;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px">`;
                        html += `<strong style="color:#0f172a;font-size:13px">${r.topic}</strong>`;
                        if (r.description) html += `<div style="color:#64748b;font-size:12px;margin-top:2px">${r.description}</div>`;
                        if (query) html += `<a href="https://www.google.com/search?q=${query}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:4px;color:var(--brand-600);font-size:12px;margin-top:4px;text-decoration:none">🔍 Search this topic</a>`;
                        html += '</div>';
                    });
                    html += '</div>';
                }
                html += '</div>';
                summaryBox.innerHTML = html;
            });
        }
    }, 4500);
}

/* ── Cover Constellation Animation ────────────────────────── */
(function initCoverConstellation() {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const canvas = document.getElementById('coverConstellationCanvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const cover = canvas.parentElement;
    let w, h, dots = [];

    function resize() {
        const rect = cover.getBoundingClientRect();
        w = rect.width; h = rect.height;
        canvas.width = w * Math.min(window.devicePixelRatio, 2);
        canvas.height = h * Math.min(window.devicePixelRatio, 2);
        canvas.style.width = w + 'px';
        canvas.style.height = h + 'px';
        ctx.setTransform(Math.min(window.devicePixelRatio, 2), 0, 0, Math.min(window.devicePixelRatio, 2), 0, 0);
    }
    resize();

    // Create constellation dots — more visible
    const count = Math.max(45, Math.min(80, Math.floor((w * h) / 8000)));
    for (let i = 0; i < count; i++) {
        dots.push({
            x: Math.random() * w, y: Math.random() * h,
            vx: (Math.random() - 0.5) * 0.4,
            vy: (Math.random() - 0.5) * 0.4,
            size: 1.2 + Math.random() * 2.5,
            twinkle: Math.random() * Math.PI * 2,
        });
    }

    function step() {
        ctx.clearRect(0, 0, w, h);

        // Update + draw dots with glow
        for (const d of dots) {
            d.x += d.vx; d.y += d.vy;
            d.twinkle += 0.018;
            if (d.x < 0) d.x = w; if (d.x > w) d.x = 0;
            if (d.y < 0) d.y = h; if (d.y > h) d.y = 0;
            const alpha = 0.5 + 0.4 * Math.sin(d.twinkle);
            // Soft glow
            const g = ctx.createRadialGradient(d.x, d.y, 0, d.x, d.y, d.size * 4);
            g.addColorStop(0, `rgba(255,255,255,${alpha * 0.6})`);
            g.addColorStop(1, 'rgba(255,255,255,0)');
            ctx.fillStyle = g;
            ctx.beginPath(); ctx.arc(d.x, d.y, d.size * 4, 0, Math.PI * 2); ctx.fill();
            // Bright core
            ctx.fillStyle = `rgba(255,255,255,${alpha})`;
            ctx.beginPath(); ctx.arc(d.x, d.y, d.size, 0, Math.PI * 2); ctx.fill();
        }

        // Draw constellation lines — larger distance, brighter
        const maxDist = 150;
        for (let i = 0; i < dots.length; i++) {
            for (let j = i + 1; j < dots.length; j++) {
                const dx = dots[i].x - dots[j].x, dy = dots[i].y - dots[j].y;
                const d2 = dx * dx + dy * dy;
                if (d2 < maxDist * maxDist) {
                    const alpha = (1 - Math.sqrt(d2) / maxDist) * 0.3;
                    ctx.strokeStyle = `rgba(255,255,255,${alpha})`;
                    ctx.lineWidth = 0.8;
                    ctx.beginPath();
                    ctx.moveTo(dots[i].x, dots[i].y);
                    ctx.lineTo(dots[j].x, dots[j].y);
                    ctx.stroke();
                }
            }
        }
        requestAnimationFrame(step);
    }
    step();
    window.addEventListener('resize', () => { resize(); });
})();

function copyTranscript() {
    const box = document.getElementById('transcriptBox');
    if (!box) return;
    navigator.clipboard.writeText(box.innerText).then(() => {
        const btns = document.querySelectorAll('.transcript-copy-btn, .btn-ghost');
        btns.forEach(b => {
            const orig = b.innerText;
            b.innerText = 'Copied!';
            setTimeout(() => b.innerText = orig, 1500);
        });
    });
}

/* ── Hero floating sparks (fireflies) ───────────────────────────── */
(function initHeroSparks() {
    const run = () => {
        const canvas = document.getElementById('jaiHeroConstellation');
        const section = document.getElementById('jaiHeroSection');
        if (!canvas || !section) return;
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            console.log('[hero-sparks] reduced motion — skipping');
            return;
        }
        const ctx = canvas.getContext('2d');
        if (!ctx) { console.warn('[hero-sparks] no 2d context'); return; }

        let w = 0, h = 0;
        const dpr = Math.max(1, Math.min(2, window.devicePixelRatio || 1));

        function sizeCanvas() {
            w = section.clientWidth || section.offsetWidth || 800;
            h = section.clientHeight || section.offsetHeight || 400;
            canvas.width = Math.round(w * dpr);
            canvas.height = Math.round(h * dpr);
            canvas.style.width = w + 'px';
            canvas.style.height = h + 'px';
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        }

        function makeSpark(randomLife) {
            const speed = 0.035 + Math.random() * 0.16;
            const ang = Math.random() * Math.PI * 2;
            return {
                x: Math.random() * w,
                y: Math.random() * h,
                vx: Math.cos(ang) * speed,
                vy: Math.sin(ang) * speed,
                r: 0.7 + Math.random() * 2.0,
                ph: Math.random() * Math.PI * 2,
                phs: 0.02 + Math.random() * 0.045,
                life: randomLife ? Math.random() * 300 : 0,
                maxLife: 260 + Math.random() * 420
            };
        }

        let sparks = [];
        function seed() {
            const n = Math.max(100, Math.min(220, Math.round((w * h) / 4800)));
            sparks = [];
            for (let i = 0; i < n; i++) sparks.push(makeSpark(true));
        }

        let stopped = false;
        function frame() {
            if (stopped || !document.body.contains(canvas)) return;
            ctx.clearRect(0, 0, w, h);
            for (let i = 0; i < sparks.length; i++) {
                const s = sparks[i];
                s.x += s.vx; s.y += s.vy; s.ph += s.phs; s.life++;
                if (s.x < -10) s.x = w + 10; else if (s.x > w + 10) s.x = -10;
                if (s.y < -10) s.y = h + 10; else if (s.y > h + 10) s.y = -10;
                if (s.life > s.maxLife) { sparks[i] = makeSpark(false); continue; }
                const r = s.life / s.maxLife;
                const fade = r < 0.15 ? r / 0.15 : r > 0.85 ? (1 - r) / 0.15 : 1;
                const flick = 0.55 + 0.45 * Math.sin(s.ph);
                const a = fade * flick;
                // glow
                const glowR = s.r * 5;
                const grad = ctx.createRadialGradient(s.x, s.y, 0, s.x, s.y, glowR);
                grad.addColorStop(0, 'rgba(220,235,255,' + (a * 0.9) + ')');
                grad.addColorStop(0.6, 'rgba(180,210,255,' + (a * 0.3) + ')');
                grad.addColorStop(1, 'rgba(180,210,255,0)');
                ctx.fillStyle = grad;
                ctx.beginPath(); ctx.arc(s.x, s.y, glowR, 0, Math.PI * 2); ctx.fill();
                // core
                ctx.fillStyle = 'rgba(255,255,255,' + Math.min(1, a * 1.5 + 0.1) + ')';
                ctx.beginPath(); ctx.arc(s.x, s.y, s.r, 0, Math.PI * 2); ctx.fill();
            }
            requestAnimationFrame(frame);
        }

        sizeCanvas();
        seed();
        console.log('[hero-sparks] init', { w, h, dpr, sparks: sparks.length });
        frame();

        // Respond to resize / section growth
        const onResize = () => { sizeCanvas(); seed(); };
        window.addEventListener('resize', onResize);
        if (window.ResizeObserver) {
            try { new ResizeObserver(onResize).observe(section); } catch (e) {}
        }
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => requestAnimationFrame(run));
    } else {
        requestAnimationFrame(run);
    }
})();
</script>

<!-- ====== Settings lightbox (shared across report pages) ====== -->
<style>
#settingsLightbox {
    position: fixed;
    inset: 0;
    z-index: 100000;
    background: rgba(6, 13, 32, 0.78);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s ease;
}
#settingsLightbox.is-open {
    display: flex;
    opacity: 1;
}
#settingsLightbox .sl-frame-wrap {
    position: relative;
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
}
#settingsLightbox iframe {
    width: 100vw;
    height: 100vh;
    border: 0;
    background: transparent;
}
#settingsLightbox .sl-close {
    position: absolute;
    top: 18px;
    right: 22px;
    z-index: 2;
    width: 38px;
    height: 38px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.28);
    background: rgba(255,255,255,0.14);
    color: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    box-shadow: 0 4px 14px rgba(0,0,0,0.3);
}
#settingsLightbox .sl-close:hover {
    background: rgba(255,255,255,0.24);
    border-color: rgba(255,255,255,0.5);
    transform: scale(1.06);
}
</style>
<div id="settingsLightbox" aria-hidden="true">
    <div class="sl-frame-wrap">
        <button type="button" class="sl-close" id="settingsLightboxClose" title="Close settings" aria-label="Close settings">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        <iframe id="settingsLightboxFrame" title="Settings" src="about:blank" loading="lazy"></iframe>
    </div>
</div>
<script>
(function () {
    const box = document.getElementById('settingsLightbox');
    const closeBtn = document.getElementById('settingsLightboxClose');
    const frame = document.getElementById('settingsLightboxFrame');

    function open() {
        // Load on open so the iframe doesn't fetch until needed
        if (!frame.dataset.loaded) {
            frame.src = '/index.html#settings';
            frame.dataset.loaded = '1';
        }
        box.classList.add('is-open');
        box.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
    function close() {
        box.classList.remove('is-open');
        box.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        // Reset iframe to stop music/anim and free memory
        setTimeout(() => {
            if (!box.classList.contains('is-open')) {
                frame.src = 'about:blank';
                delete frame.dataset.loaded;
            }
        }, 350);
    }

    // Intercept every anchor whose href points at /index.html#settings and the
    // dedicated #settings-lightbox-btn id (if added) — open the lightbox instead.
    document.addEventListener('click', function (e) {
        const a = e.target.closest('a[href$="#settings"], a[href*="/index.html#settings"], [data-settings-lightbox]');
        if (!a) return;
        e.preventDefault();
        open();
    }, true);

    closeBtn.addEventListener('click', close);

    // Esc to close
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && box.classList.contains('is-open')) close();
    });

    // If the iframe (same-origin index.html) posts a close request, honour it
    window.addEventListener('message', function (e) {
        if (e.data && e.data === 'close-settings-lightbox') close();
    });

    // Expose for external triggers
    window.openSettingsLightbox = open;
    window.closeSettingsLightbox = close;
})();

/* Auto-print when the page was opened with ?print=1 (history "Download PDF"). */
(function () {
    try {
        const p = new URLSearchParams(window.location.search);
        if (p.get('print') === '1') {
            // Give the browser a beat to render fonts, charts, and the hero
            // sparks canvas before the print dialog snapshots the page.
            window.addEventListener('load', () => setTimeout(() => window.print(), 750));
        }
    } catch (e) {}
})();

</script>


<style id="reportDarkFx">
@media screen {
/* ═══════════════════════════════════════════════════════════════════
   DARK-MODE OVERRIDES for the branded report pages.
   Activated whenever the user set theme=dark in the main app. The
   covers themselves already use a dark brand gradient in both modes —
   we just need to retint the white content cards below.
   ═══════════════════════════════════════════════════════════════════ */
[data-theme="dark"] {
    --card: rgba(15, 23, 42, 0.72) !important;
    --ink: #f1f5f9 !important;
    --ink-soft: #cbd5e1 !important;
    --ink-muted: #94a3b8 !important;
}
[data-theme="dark"] body {
    background: linear-gradient(165deg, #050816 0%, #0a1128 45%, #0f1f40 100%) !important;
    color: var(--ink) !important;
}
[data-theme="dark"] .report-wrapper,
[data-theme="dark"] .quiz-report-wrapper { color: var(--ink) !important; }

[data-theme="dark"] .report-section {
    background: rgba(15, 23, 42, 0.72) !important;
    border: 1px solid rgba(255, 255, 255, 0.08) !important;
    box-shadow: 0 4px 28px rgba(0, 0, 0, 0.35) !important;
    color: var(--ink) !important;
}
[data-theme="dark"] .report-section h1,
[data-theme="dark"] .report-section h2,
[data-theme="dark"] .report-section h3,
[data-theme="dark"] .report-section h4 {
    color: var(--ink) !important;
}
[data-theme="dark"] .report-section p,
[data-theme="dark"] .report-section li,
[data-theme="dark"] .report-section span,
[data-theme="dark"] .report-section td,
[data-theme="dark"] .report-section div {
    color: var(--ink-soft) !important;
}

/* Transcript / code-style blocks */
[data-theme="dark"] .transcript-box,
[data-theme="dark"] .callout,
[data-theme="dark"] .concept-card,
[data-theme="dark"] .learning-concept-card,
[data-theme="dark"] .exercise-header,
[data-theme="dark"] .quiz-header,
[data-theme="dark"] .insight-list,
[data-theme="dark"] .chart-card,
[data-theme="dark"] .metric-card,
[data-theme="dark"] .stat-card {
    background: rgba(15, 23, 42, 0.55) !important;
    border: 1px solid rgba(255, 255, 255, 0.08) !important;
    color: var(--ink-soft) !important;
}
[data-theme="dark"] .transcript-formatted p,
[data-theme="dark"] .transcript-formatted .ts-block,
[data-theme="dark"] .transcript-formatted .sp-block { color: var(--ink-soft) !important; }
[data-theme="dark"] .transcript-formatted p:first-child strong.ts-title { color: var(--ink) !important; }

/* Pop quiz report specifics */
[data-theme="dark"] .quiz-report-wrapper .performance-summary,
[data-theme="dark"] .quiz-report-wrapper .strengths-card,
[data-theme="dark"] .quiz-report-wrapper .review-card,
[data-theme="dark"] .quiz-report-wrapper .question-card,
[data-theme="dark"] .strengths-card,
[data-theme="dark"] .review-card,
[data-theme="dark"] .question-card {
    background: rgba(15, 23, 42, 0.60) !important;
    border: 1px solid rgba(255, 255, 255, 0.10) !important;
    color: var(--ink) !important;
}
[data-theme="dark"] .strengths-card h3,
[data-theme="dark"] .review-card h3,
[data-theme="dark"] .question-card h3 { color: var(--ink) !important; }
[data-theme="dark"] .strengths-card li,
[data-theme="dark"] .review-card li,
[data-theme="dark"] .question-card p,
[data-theme="dark"] .question-card li { color: var(--ink-soft) !important; }

/* Floating buttons (download pdf, pop quiz) — already brand-gradient so
   they work, but ensure text stays white on hover in dark */
[data-theme="dark"] .floating-pdf-btn,
[data-theme="dark"] .pop-quiz-btn { color: #fff !important; }

/* Footer / report-footer */
[data-theme="dark"] .report-footer {
    background: linear-gradient(to bottom, var(--brand-grad-dark), #050816) !important;
    color: rgba(255, 255, 255, 0.75) !important;
    border-top: 1px solid rgba(255, 255, 255, 0.06) !important;
}

/* Any <a> inside report content should stay readable */
[data-theme="dark"] .report-section a { color: var(--brand-300, #93c5fd) !important; }
}  /* end @media screen — PDF always renders light mode */

/* Dark-mode key-concept / difficulty pills — use stronger brand-tinted bg
   and a near-white text so they pop against the dark card. */
[data-theme="dark"] .importance-pill.importance-high {
    background: rgba(239,68,68,0.22);
    color: #fca5a5;
    border: 1px solid rgba(239,68,68,0.35);
}
[data-theme="dark"] .importance-pill.importance-medium {
    background: rgba(217,119,6,0.22);
    color: #fcd34d;
    border: 1px solid rgba(217,119,6,0.35);
}
[data-theme="dark"] .importance-pill.importance-low {
    background: rgba(16,185,129,0.22);
    color: #86efac;
    border: 1px solid rgba(16,185,129,0.35);
}
[data-theme="dark"] .study-time-badge {
    background: rgba(var(--primary-rgb), 0.22);
    color: #e2e8f0;
    border-color: rgba(var(--primary-rgb), 0.45);
}
[data-theme="dark"] .study-time-badge strong { color: #ffffff; }
</style>

</body>
</html>
