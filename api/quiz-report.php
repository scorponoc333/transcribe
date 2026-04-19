<?php
/**
 * Quiz Report Page
 * Displays a styled quiz attempt with questions, answers, AI summary.
 * Supports Print → Save as PDF via @media print rules.
 *
 * GET ?id=<quiz_attempt_id>
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_middleware.php';
requireAuth();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$attemptId = (int) ($_GET['id'] ?? 0);
$userId = getCurrentUserId();
$orgId = getCurrentOrgId();

if ($attemptId <= 0) {
    http_response_code(400);
    echo 'Invalid quiz ID';
    exit;
}

try {
    $db = getDB();
    $stmt = $db->prepare("SELECT qa.*, t.title as transcription_title, t.mode
                          FROM quiz_attempts qa
                          JOIN transcriptions t ON t.id = qa.transcription_id
                          WHERE qa.id = :id AND qa.user_id = :uid AND qa.organization_id = :oid
                          LIMIT 1");
    $stmt->execute([':id' => $attemptId, ':uid' => $userId, ':oid' => $orgId]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo 'Database error';
    exit;
}

if (!$attempt) {
    http_response_code(404);
    echo 'Quiz attempt not found';
    exit;
}

$questions = json_decode($attempt['questions_json'] ?? '[]', true);
$answers = json_decode($attempt['answers_json'] ?? '[]', true);
$score = (int) $attempt['score'];
$total = (int) $attempt['total_questions'];
$pct = $total > 0 ? round(($score / $total) * 100) : 0;
$date = date('F j, Y', strtotime($attempt['created_at']));
$time = date('g:i A', strtotime($attempt['created_at']));
$title = $attempt['transcription_title'] ?: 'Quiz Report';

// Load brand settings
$settings = [];
try {
    $s = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE organization_id = :org AND setting_key IN ('brandColor','senderName')");
    $s->execute([':org' => $orgId]);
    while ($r = $s->fetch(PDO::FETCH_ASSOC)) $settings[$r['setting_key']] = $r['setting_value'];
} catch (PDOException $e) {}
$brandColor = $settings['brandColor'] ?? '#2563eb';
// Derive full brand palette (mirrors report.php / app.js)
if (!function_exists('qrHexToHsl')) {
    function qrHexToHsl($hex) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        $r=hexdec(substr($hex,0,2))/255; $g=hexdec(substr($hex,2,2))/255; $b=hexdec(substr($hex,4,2))/255;
        $max=max($r,$g,$b); $min=min($r,$g,$b); $l=($max+$min)/2;
        if ($max===$min) return [0,0,$l*100];
        $d=$max-$min;
        $sVal = $l>0.5 ? $d/(2-$max-$min) : $d/($max+$min);
        switch($max){
            case $r: $h=($g-$b)/$d + ($g<$b?6:0); break;
            case $g: $h=($b-$r)/$d + 2; break;
            default: $h=($r-$g)/$d + 4;
        }
        return [round($h*60), round($sVal*100), round($l*100)];
    }
    function qrHslToRgbStr($h,$sp,$lp) {
        $sp/=100; $lp/=100;
        $c=(1-abs(2*$lp-1))*$sp;
        $x=$c*(1-abs(fmod($h/60,2)-1));
        $m=$lp-$c/2;
        if ($h<60)        [$r,$g,$b]=[$c,$x,0];
        elseif ($h<120)   [$r,$g,$b]=[$x,$c,0];
        elseif ($h<180)   [$r,$g,$b]=[0,$c,$x];
        elseif ($h<240)   [$r,$g,$b]=[0,$x,$c];
        elseif ($h<300)   [$r,$g,$b]=[$x,0,$c];
        else              [$r,$g,$b]=[$c,0,$x];
        return [ round(($r+$m)*255), round(($g+$m)*255), round(($b+$m)*255) ];
    }
}
[$brandH, $brandS, $brandL] = qrHexToHsl($brandColor);
$brandSat = min($brandS, 85);
$qrShades = [
    '50'  => qrHslToRgbStr($brandH, min($brandSat,65), 94),
    '100' => qrHslToRgbStr($brandH, min($brandSat,70), 87),
    '200' => qrHslToRgbStr($brandH, min($brandSat,75), 76),
    '300' => qrHslToRgbStr($brandH, min($brandSat,80), 62),
    '400' => qrHslToRgbStr($brandH, $brandSat, 50),
    '500' => qrHslToRgbStr($brandH, $brandSat, 40),
    '600' => qrHslToRgbStr($brandH, $brandSat, 33),
    '700' => qrHslToRgbStr($brandH, $brandSat, 26),
    '800' => qrHslToRgbStr($brandH, $brandSat, 19),
    '900' => qrHslToRgbStr($brandH, $brandSat, 12),
    '950' => qrHslToRgbStr($brandH, $brandSat, 6),
    'grad-light' => qrHslToRgbStr($brandH, min($brandSat,70), 35),
    'grad-mid'   => qrHslToRgbStr($brandH, min($brandSat,80), 25),
    'grad-dark'  => qrHslToRgbStr($brandH, min($brandSat,75), 17),
];
$qrShadeCss = '';
foreach ($qrShades as $k => $rgb) {
    $hex = sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    $qrShadeCss .= "    --brand-$k: $hex;\n";
    $qrShadeCss .= "    --brand-$k-rgb: {$rgb[0]}, {$rgb[1]}, {$rgb[2]};\n";
}


// Resolve logo
$logoPath = file_exists(__DIR__ . '/../img/custom-logo.png') ? '/img/custom-logo.png' : '/img/logo.png';

// Categorize right/wrong
$correctQs = [];
$wrongQs = [];
foreach ($questions as $i => $q) {
    $userAnswer = $answers[$i] ?? -1;
    if ($userAnswer === ($q['correct'] ?? -1)) {
        $correctQs[] = $q;
    } else {
        $wrongQs[] = ['question' => $q, 'userAnswer' => $userAnswer];
    }
}

function e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pop Quiz Report — <?= e($title) ?></title>
<link rel="icon" href="/img/fav%20icon.png">
<style>
:root {
    --primary: <?= e($brandColor) ?>;
<?= $qrShadeCss ?>
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #eef2f7;
    color: #0f172a;
    line-height: 1.6;
}
.topbar {
    position: sticky; top: 0; z-index: 50;
    background: linear-gradient(to bottom, var(--brand-grad-light) 0%, var(--brand-grad-mid) 50%, var(--brand-grad-dark) 100%);
    padding: 12px max(24px, calc((100% - 1200px) / 2 + 24px));
    display: flex; align-items: center; justify-content: space-between;
    box-shadow: 0 2px 16px rgba(0,0,0,0.18);
}
.topbar-logo { height: 26px; max-width: 140px; filter: brightness(0) invert(1); }
.topbar-actions { display: flex; gap: 8px; }
.btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 9px 16px; border-radius: 9px; font-size: 13px; font-weight: 500;
    border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.06);
    color: rgba(255,255,255,0.82); cursor: pointer; text-decoration: none;
    font-family: inherit; transition: all 0.2s;
}
.btn:hover { background: rgba(255,255,255,0.15); color: #fff; }
.btn svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

.report-wrap { max-width: 800px; margin: 32px auto 64px; padding: 0 24px; }
.report-card { background: #fff; border-radius: 20px; box-shadow: 0 4px 28px rgba(0,0,0,0.06); margin-bottom: 24px; overflow: hidden; }

/* Cover */
.qr-cover {
    background:
        linear-gradient(165deg,
            rgba(var(--brand-grad-light-rgb), 0.92) 0%,
            rgba(var(--brand-grad-mid-rgb), 0.90) 35%,
            rgba(var(--brand-grad-dark-rgb), 0.88) 70%,
            rgba(var(--brand-950-rgb), 0.92) 100%),
        url('https://images.unsplash.com/photo-1516321318423-f06f85e504b3?w=1200&q=80') center/cover no-repeat;
    padding: 80px 48px 60px; text-align: center; color: #fff;
    position: relative;
}
.qr-cover::before {
    content: '';
    position: absolute; inset: 0;
    background:
        radial-gradient(circle at 25% 35%, rgba(var(--brand-400-rgb), 0.15) 0%, transparent 55%),
        radial-gradient(circle at 75% 65%, rgba(var(--brand-500-rgb), 0.10) 0%, transparent 55%);
    pointer-events: none;
}
.qr-cover > * { position: relative; z-index: 1; }
.qr-cover-logo { height: 44px; filter: brightness(0) invert(1); margin-bottom: 24px; display: block; margin-left: auto; margin-right: auto; }
.qr-cover-badge {
    display: inline-block; padding: 8px 24px; border-radius: 20px;
    background: linear-gradient(135deg, var(--brand-500), var(--brand-700));
    border: 1px solid rgba(167,139,250,0.4);
    font-size: 11px; font-weight: 700; letter-spacing: 1.5px;
    text-transform: uppercase; margin-bottom: 20px; color: #fff;
    box-shadow: 0 4px 14px rgba(var(--brand-500-rgb), 0.35);
}
.qr-cover h1 { font-size: 28px; font-weight: 800; margin: 0 0 8px; }
.qr-cover p { color: rgba(255,255,255,0.6); margin: 0 0 4px; font-size: 14px; }
.qr-score-circle {
    width: 130px; height: 130px; border-radius: 50%; margin: 28px auto 0;
    background: linear-gradient(135deg, var(--brand-700), var(--brand-600), var(--brand-500));
    border: 3px solid rgba(var(--brand-300-rgb), 0.35);
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    box-shadow: 0 8px 28px rgba(var(--brand-600-rgb), 0.4), inset 0 1px 0 rgba(255,255,255,0.15);
}
.qr-score-num { font-size: 42px; font-weight: 800; color: #fff; text-shadow: 0 2px 8px rgba(0,0,0,0.2); }
.qr-score-num span { font-size: 18px; font-weight: 400; color: rgba(255,255,255,0.6); }
.qr-score-pct { font-size: 13px; color: rgba(255,255,255,0.65); margin-top: 2px; }

.qr-cover-url {
    display: none;
}
@media print {
    .qr-cover { padding-bottom: 96px !important; }
    .qr-cover-url {
        display: inline-flex !important;
        position: absolute;
        left: 50%;
        bottom: 32px;
        transform: translateX(-50%);
        padding: 9px 22px;
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
        align-items: center;
        gap: 8px;
    }
    .qr-cover-url::before { content: 'F517'; font-size: 12px; opacity: 0.7; }
}
/* Sections */
.qr-section { padding: 32px 40px; }
.qr-section-title { font-size: 24px; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase; color: #64748b; margin: 0 0 20px; display: flex; align-items: center; gap: 10px; }
.qr-section-title::before { content: ''; width: 5px; height: 28px; border-radius: 3px; background: var(--primary); }

/* Questions */
.qr-question { margin-bottom: 16px; padding: 16px 20px; border-radius: 12px; border: 1px solid #e2e8f0; }
.qr-question.correct { border-color: #86efac; background: #f0fdf4; }
.qr-question.wrong { border-color: #fca5a5; background: #fef2f2; }
.qr-q-text { font-weight: 600; margin: 0 0 10px; font-size: 14px; }
.qr-answer { font-size: 13px; padding: 8px 12px; border-radius: 8px; margin-bottom: 4px; }
.qr-answer.selected-correct { background: #dcfce7; color: #166534; font-weight: 600; }
.qr-answer.selected-wrong { background: #fee2e2; color: #991b1b; font-weight: 600; }
.qr-answer.correct-answer { background: #dcfce7; color: #166534; }
.qr-explanation { font-size: 12px; color: #64748b; margin-top: 8px; font-style: italic; padding-top: 8px; border-top: 1px solid #e2e8f0; }

/* Summary cards */
.qr-summary-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.qr-summary-card { padding: 20px; border-radius: 14px; }
.qr-summary-card.strengths { background: #f0fdf4; border: 1px solid #bbf7d0; }
.qr-summary-card.weaknesses { background: #fef3c7; border: 1px solid #fde68a; }
.qr-summary-card h3 { font-size: 13px; font-weight: 700; margin: 0 0 10px; text-transform: uppercase; letter-spacing: 0.5px; }
.qr-summary-card.strengths h3 { color: #166534; }
.qr-summary-card.weaknesses h3 { color: #92400e; }
.qr-summary-card li { font-size: 13px; margin-bottom: 4px; }
.qr-recommendation { padding: 20px; background: linear-gradient(135deg, rgba(var(--brand-600-rgb), 0.04), rgba(var(--brand-500-rgb), 0.03)); border: 1px solid rgba(var(--brand-600-rgb), 0.12); border-radius: 14px; margin-top: 16px; }
.qr-recommendation p { margin: 0; font-size: 14px; line-height: 1.7; color: #334155; }

/* Footer */
.qr-footer { text-align: center; padding: 24px 40px; background: linear-gradient(to bottom, var(--brand-grad-light), var(--brand-grad-dark)); color: rgba(255,255,255,0.6); font-size: 12px; }

/* Floating PDF button */
.qr-pdf-btn {
    position: fixed; bottom: 32px; right: 32px; z-index: 90;
    display: inline-flex; align-items: center; gap: 10px;
    padding: 16px 28px; border: none; border-radius: 14px;
    background: linear-gradient(135deg, var(--brand-700), var(--brand-600), var(--brand-500));
    color: #fff; font-size: 15px; font-weight: 700; cursor: pointer;
    font-family: inherit; box-shadow: 0 8px 28px rgba(var(--brand-600-rgb), 0.4);
    transition: all 0.3s; overflow: hidden; position: fixed;
}
.qr-pdf-btn:hover { transform: translateY(-3px); box-shadow: 0 14px 40px rgba(var(--brand-600-rgb), 0.5); }
.qr-pdf-btn::before { content: ''; position: absolute; top: 0; left: -100%; width: 60%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent); transform: skewX(-20deg); animation: qrShine 4s ease-in-out infinite; }
@keyframes qrShine { 0%, 75% { left: -100%; } 100% { left: 160%; } }

@media print {
    *, *::before, *::after { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }
    .topbar, .qr-pdf-btn, .no-print { display: none !important; }
    @page { size: letter; margin: 10mm 12mm 12mm 12mm; }
    @page :first { margin: 0; }
    body { background: #fff; }
    .report-wrap { margin: 0; padding: 0; max-width: none; }
    .report-card { box-shadow: none; border-radius: 0; margin-bottom: 0; break-inside: auto; }
    /* Cover — full bleed on first page (no margins via @page :first) */
    .qr-cover {
        page-break-after: always;
        min-height: 100vh;
        padding: 60px 48px;
        display: flex; flex-direction: column;
        align-items: center; justify-content: center;
    }
    /* Content pages get padding for printer margins */
    .qr-section { padding: 24px 32px; break-inside: auto; }
    .qr-section-title { break-after: avoid; page-break-after: avoid; }
    .qr-question { break-inside: avoid; }
    .qr-summary-card { break-inside: avoid; }
    .report-card { break-inside: auto; overflow: visible; }
    .qr-footer { break-inside: avoid; margin-bottom: 0; }
    h2, h3, h4 { break-after: avoid; page-break-after: avoid; }
}
@media (max-width: 640px) {
    .qr-section { padding: 24px 20px; }
    .qr-summary-grid { grid-template-columns: 1fr; }
    .qr-cover { padding: 40px 24px; }
    .qr-pdf-btn { display: none; }
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

<div class="topbar no-print">
    <div class="topbar-inner">
        <img src="<?= e($logoPath) ?>" alt="Logo" class="topbar-logo">
        <div class="topbar-actions">
            <!-- Page-specific: Back to Report -->
            <a href="/api/report.php?id=<?= (int) $attempt['transcription_id'] ?>" class="btn" title="Back to Report">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                <span>Back to Report</span>
            </a>
            <!-- Standard app nav -->
            <a href="/index.html" class="btn app-nav-btn" title="Transcribe">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                <span>Transcribe</span>
            </a>
            <div class="tb-more" id="tbMoreDropdown">
                <button type="button" class="btn app-nav-btn" onclick="tbToggleMore(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                    <span>More</span>
                    <svg class="tb-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div class="tb-more-menu">
                    <a href="/index.html#history" class="tb-more-item">History</a>
                    <a href="/index.html#reports" class="tb-more-item">Reports</a>
                    <a href="/index.html#analytics" class="tb-more-item">Analytics</a>
                    <a href="/index.html#contacts" class="tb-more-item">Contacts</a>
                    <div class="tb-more-divider"></div>
                    <a href="/index.html#feedback" class="tb-more-item">Feedback</a>
                </div>
            </div>
            <a href="/index.html#settings" class="btn app-nav-btn btn-icon-only" title="Settings">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            </a>
            <button type="button" class="btn app-nav-btn" onclick="tbSignOut()" title="Sign Out">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span>Sign Out</span>
            </button>
        </div>
    </div>
</div>

<style>
.topbar-inner { max-width: 1200px; margin: 0 auto; width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.app-nav-btn { background: transparent !important; border: 1px solid transparent !important; color: rgba(255,255,255,0.82); }
.app-nav-btn:hover { background: rgba(255,255,255,0.10) !important; border-color: rgba(255,255,255,0.18) !important; color: #fff; }
.btn-icon-only { padding: 9px 10px !important; }
.tb-more { position: relative; }
.tb-more.open .tb-chevron { transform: rotate(180deg); }
.tb-chevron { transition: transform 0.25s ease; opacity: 0.7; }
.tb-more-menu { position: absolute; top: calc(100% + 8px); right: 0; min-width: 200px; background: linear-gradient(165deg, var(--brand-grad-light) 0%, var(--brand-grad-mid) 45%, var(--brand-grad-dark) 80%, var(--brand-950) 100%); border: 1px solid rgba(255,255,255,0.18); border-radius: 14px; padding: 6px; opacity: 0; max-height: 0; overflow: hidden; visibility: hidden; transition: opacity 0.28s ease, max-height 0.5s cubic-bezier(0.22, 1, 0.36, 1), visibility 0s 0.5s; z-index: 60; box-shadow: 0 16px 48px rgba(0,0,0,0.45); }
.tb-more.open .tb-more-menu { opacity: 1; max-height: 500px; visibility: visible; transition: opacity 0.28s ease, max-height 0.5s cubic-bezier(0.22, 1, 0.36, 1), visibility 0s 0s; }
.tb-more-item { display: flex; align-items: center; gap: 10px; width: 100%; padding: 10px 14px; border: 1px solid transparent; border-radius: 10px; background: transparent; color: rgba(255,255,255,0.82); cursor: pointer; font-size: 13px; font-weight: 500; text-decoration: none; transition: all 0.15s ease; }
.tb-more-item:hover { background: rgba(255,255,255,0.12); border-color: rgba(255,255,255,0.18); color: #fff; }
.tb-more-divider { height: 1px; background: rgba(255,255,255,0.12); margin: 6px 4px; }
</style>
<script>
function tbToggleMore(e) { e.stopPropagation(); document.getElementById('tbMoreDropdown')?.classList.toggle('open'); }
document.addEventListener('click', function(e) { const dd = document.getElementById('tbMoreDropdown'); if (dd && !dd.contains(e.target)) dd.classList.remove('open'); });
async function tbSignOut() { try { await fetch('/api/auth.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ action: 'logout' }) }); } catch (e) {} window.location.href = '/login.php'; }
</script>

<button type="button" class="qr-pdf-btn no-print" onclick="window.print()">
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
    Download PDF
</button>

<div class="report-wrap">

    <!-- Cover -->
    <div class="report-card">
        <div class="qr-cover">
            <img src="<?= e($logoPath) ?>" alt="Logo" class="qr-cover-logo">
            <div class="qr-cover-badge">Pop Quiz Report</div>
            <h1><?= e($title) ?></h1>
            <p><?= e($date) ?> at <?= e($time) ?></p>
            <div class="qr-score-circle">
                <div class="qr-score-num"><?= $score ?><span>/<?= $total ?></span></div>
                <div class="qr-score-pct"><?= $pct ?>%</div>
            </div>
            <div class="qr-cover-url" aria-hidden="true"><?= e('https://' . $_SERVER['HTTP_HOST'] . '/api/quiz-report.php?id=' . $attemptId) ?></div>
        </div>
    </div>

    
<!-- Summary -->
    <?php if ($correctQs || $wrongQs): ?>
    <div class="report-card">
        <div class="qr-section">
            <div class="qr-section-title">Performance Summary</div>
            <div class="qr-summary-grid">
                <div class="qr-summary-card strengths">
                    <h3>✓ Strengths (<?= count($correctQs) ?>)</h3>
                    <ul style="margin:0;padding-left:18px">
                        <?php foreach ($correctQs as $q): ?>
                            <li><?= e(substr($q['question'] ?? '', 0, 80)) ?><?= strlen($q['question'] ?? '') > 80 ? '...' : '' ?></li>
                        <?php endforeach; ?>
                        <?php if (!$correctQs): ?><li style="color:#64748b">None this attempt</li><?php endif; ?>
                    </ul>
                </div>
                <div class="qr-summary-card weaknesses">
                    <h3>⚡ Areas to Review (<?= count($wrongQs) ?>)</h3>
                    <ul style="margin:0;padding-left:18px">
                        <?php foreach ($wrongQs as $w): ?>
                            <li><?= e(substr($w['question']['question'] ?? '', 0, 80)) ?><?= strlen($w['question']['question'] ?? '') > 80 ? '...' : '' ?></li>
                        <?php endforeach; ?>
                        <?php if (!$wrongQs): ?><li style="color:#64748b">Perfect score!</li><?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- All Questions -->
    <div class="report-card">
        <div class="qr-section">
            <div class="qr-section-title">Questions & Answers</div>
            <?php foreach ($questions as $qi => $q):
                $userAnswer = $answers[$qi] ?? -1;
                $isCorrect = $userAnswer === ($q['correct'] ?? -1);
            ?>
                <div class="qr-question <?= $isCorrect ? 'correct' : 'wrong' ?>">
                    <div style="font-size:10px;font-weight:700;letter-spacing:1px;color:<?= $isCorrect ? '#166534' : '#991b1b' ?>;margin-bottom:6px">Q<?= $qi + 1 ?> — <?= $isCorrect ? '✓ CORRECT' : '✗ INCORRECT' ?></div>
                    <p class="qr-q-text"><?= e($q['question'] ?? '') ?></p>
                    <?php foreach ($q['options'] ?? [] as $oi => $opt): ?>
                        <div class="qr-answer <?= $oi === $userAnswer && !$isCorrect ? 'selected-wrong' : '' ?> <?= $oi === $userAnswer && $isCorrect ? 'selected-correct' : '' ?> <?= $oi === ($q['correct'] ?? -1) && !$isCorrect ? 'correct-answer' : '' ?>">
                            <?= e($opt) ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!empty($q['explanation'])): ?>
                        <div class="qr-explanation"><?= e($q['explanation']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Footer -->
    <div class="report-card">
        <div class="qr-footer">
            <strong>Created by Jason AI</strong> · <?= e($date) ?>
        </div>
    </div>

</div>

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
</script>


<style id="reportDarkFx">
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
</style>


<style id="quizReportDarkFx">
@media screen {
/* Quiz-report.php uses .report-card and qr-* classes — none of which were
   targeted by the earlier report-page dark overrides. This block covers
   all the big white tiles in the pop-quiz layout. */
[data-theme="dark"] body {
    background: linear-gradient(165deg, #050816 0%, #0a1128 45%, #0f1f40 100%) !important;
    color: #e2e8f0 !important;
}
[data-theme="dark"] .report-card {
    background: rgba(15, 23, 42, 0.72) !important;
    border: 1px solid rgba(255, 255, 255, 0.08) !important;
    box-shadow: 0 4px 28px rgba(0, 0, 0, 0.35) !important;
    color: #e2e8f0 !important;
}
[data-theme="dark"] .qr-section-title {
    color: #f1f5f9 !important;
}
[data-theme="dark"] .qr-summary-card {
    background: rgba(15, 23, 42, 0.55) !important;
    border: 1px solid rgba(255, 255, 255, 0.10) !important;
}
[data-theme="dark"] .qr-summary-card.strengths {
    background: rgba(16, 185, 129, 0.12) !important;
    border-color: rgba(16, 185, 129, 0.30) !important;
}
[data-theme="dark"] .qr-summary-card.weaknesses {
    background: rgba(245, 158, 11, 0.12) !important;
    border-color: rgba(245, 158, 11, 0.30) !important;
}
[data-theme="dark"] .qr-summary-card h3,
[data-theme="dark"] .qr-summary-card li,
[data-theme="dark"] .qr-summary-card p { color: #e2e8f0 !important; }
[data-theme="dark"] .qr-question {
    background: rgba(15, 23, 42, 0.55) !important;
    border: 1px solid rgba(255, 255, 255, 0.10) !important;
}
[data-theme="dark"] .qr-question.correct {
    background: rgba(16, 185, 129, 0.10) !important;
    border-color: rgba(16, 185, 129, 0.30) !important;
}
[data-theme="dark"] .qr-question.wrong {
    background: rgba(239, 68, 68, 0.10) !important;
    border-color: rgba(239, 68, 68, 0.30) !important;
}
[data-theme="dark"] .qr-q-text,
[data-theme="dark"] .qr-question p,
[data-theme="dark"] .qr-question li { color: #e2e8f0 !important; }
[data-theme="dark"] .qr-answer {
    background: rgba(255, 255, 255, 0.04) !important;
    border: 1px solid rgba(255, 255, 255, 0.10) !important;
    color: #cbd5e1 !important;
}
[data-theme="dark"] .qr-answer.selected-wrong {
    background: rgba(239, 68, 68, 0.18) !important;
    border-color: rgba(239, 68, 68, 0.45) !important;
    color: #fecaca !important;
}
[data-theme="dark"] .qr-answer.selected-correct,
[data-theme="dark"] .qr-answer.correct-answer {
    background: rgba(16, 185, 129, 0.18) !important;
    border-color: rgba(16, 185, 129, 0.45) !important;
    color: #bbf7d0 !important;
}
[data-theme="dark"] .qr-explanation {
    background: rgba(255, 255, 255, 0.04) !important;
    border-left: 3px solid rgba(var(--brand-400-rgb, 96, 165, 250), 0.55) !important;
    color: #cbd5e1 !important;
}
[data-theme="dark"] .qr-footer {
    color: rgba(255, 255, 255, 0.55) !important;
    border-top: 1px solid rgba(255, 255, 255, 0.08) !important;
}
}  /* end @media screen — PDF stays light */
</style>

<style id="quizReportAssembleFx">
@media screen {
/* Cover card entrance */
.report-card:first-of-type {
    clip-path: inset(50% 8% 50% 8% round 20px);
    filter: blur(10px);
    transform: scale(0.95);
    animation: qrCardIn 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.05s forwards;
}
@keyframes qrCardIn {
    0%   { opacity: 0; clip-path: inset(50% 8% 50% 8% round 20px); filter: blur(10px); transform: scale(0.95); }
    55%  { opacity: 1; clip-path: inset(0 0 0 0 round 20px); filter: blur(0); }
    100% { opacity: 1; clip-path: inset(0 0 0 0 round 20px); filter: blur(0); transform: scale(1); }
}

.qr-cover-logo, .qr-cover-badge, .qr-cover-title, .qr-score-circle, .qr-cover-url {
    opacity: 0;
    will-change: opacity, transform;
}
.qr-cover-logo { transform: translateY(-22px) scale(0.85); animation: qrLogoDrop 0.55s cubic-bezier(0.34,1.56,0.64,1) 0.9s forwards; }
.qr-cover-badge { transform: scale(0.5); animation: qrBadgePop 0.5s cubic-bezier(0.34,1.56,0.64,1) 1.3s forwards; }
.qr-cover-title { transform: translateY(16px); clip-path: inset(0 100% 0 0); animation: qrTitleSweep 0.85s cubic-bezier(0.22,1,0.36,1) 1.65s forwards; }
.qr-score-circle { transform: scale(0.3) rotate(-180deg); animation: qrScoreSpin 0.9s cubic-bezier(0.22,1,0.36,1) 2.1s forwards; }
.qr-cover-url { animation: qrFadeIn 0.6s ease 2.9s forwards; }

@keyframes qrLogoDrop { 0% { opacity:0; transform:translateY(-22px) scale(0.85); } 70% { opacity:1; transform:translateY(4px) scale(1.04); } 100% { opacity:1; transform:translateY(0) scale(1); } }
@keyframes qrBadgePop { 0% { opacity:0; transform:scale(0.5); } 65% { opacity:1; transform:scale(1.08); } 100% { opacity:1; transform:scale(1); } }
@keyframes qrTitleSweep { 0% { opacity:0; transform:translateY(16px); clip-path:inset(0 100% 0 0); } 30% { opacity:1; transform:translateY(0); } 100% { opacity:1; clip-path:inset(0 0 0 0); } }
@keyframes qrScoreSpin { 0% { opacity:0; transform:scale(0.3) rotate(-180deg); } 60% { opacity:1; transform:scale(1.06) rotate(10deg); } 100% { opacity:1; transform:scale(1) rotate(0); } }
@keyframes qrFadeIn { to { opacity: 1; } }

/* Scroll-in for the performance-summary + Q&A cards */
.report-card.asm {
    opacity: 0;
    transform: translateY(26px) scale(0.985);
    filter: blur(4px);
    transition: opacity 1.0s cubic-bezier(0.22, 1, 0.36, 1), transform 1.0s cubic-bezier(0.22, 1, 0.36, 1), filter 1.0s ease;
    transition-delay: var(--asm-delay, 0ms);
    position: relative;
    overflow: hidden;
}
.report-card.asm.is-assembled { opacity: 1; transform: none; filter: none; }
.report-card.asm::after {
    content: '';
    position: absolute; top: 0; left: -140%;
    width: 60%; height: 100%;
    background: linear-gradient(100deg, transparent 0%, rgba(var(--brand-300-rgb, 147, 197, 253), 0.0) 20%, rgba(var(--brand-300-rgb, 147, 197, 253), 0.22) 50%, rgba(var(--brand-300-rgb, 147, 197, 253), 0.0) 80%, transparent 100%);
    transform: skewX(-18deg);
    pointer-events: none;
    z-index: 2;
    opacity: 0;
    transition: left 1.2s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.2s;
}
.report-card.asm.is-assembled::after { opacity: 0; left: 140%; transition: left 1.2s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease 0.9s; transition-delay: calc(var(--asm-delay, 0ms) + 320ms); }

.qr-summary-card.asm, .qr-question.asm {
    opacity: 0;
    transform: translateY(18px);
    transition: opacity 0.7s ease, transform 0.7s cubic-bezier(0.22, 1, 0.36, 1);
    transition-delay: var(--asm-delay, 0ms);
}
.qr-summary-card.asm.is-assembled, .qr-question.asm.is-assembled { opacity: 1; transform: none; }

@media (prefers-reduced-motion: reduce) {
    .report-card, .report-card::after,
    .qr-cover-logo, .qr-cover-badge, .qr-cover-title, .qr-score-circle, .qr-cover-url,
    .qr-summary-card.asm, .qr-question.asm {
        animation: none !important; opacity: 1 !important; transform: none !important; clip-path: none !important; filter: none !important;
    }
}
}  /* end @media screen */
</style>
<script src="/js/assembler.js?v=20260419n"></script>
<script>
(function () {
    if (!window.Assembler) return;
    function init() {
        // Skip the very first .report-card (the cover) — it has its own
        // scripted entrance. Observe the rest for scroll-in.
        const cards = document.querySelectorAll('.report-card');
        cards.forEach((el, i) => {
            if (i === 0) return;
            Assembler.observe(el, { kind: 'card', delay: Math.min((i - 1) * 120, 400) });
        });

        // Score circle number + percentage count-ups (fire when cover has finished)
        setTimeout(() => {
            const num = document.querySelector('.qr-score-num');
            const pct = document.querySelector('.qr-score-pct');
            if (num) {
                const raw = num.cloneNode(true);
                // Split out the big number vs the "/total" suffix
                const firstTextNode = raw.firstChild;
                if (firstTextNode && firstTextNode.nodeType === 3) {
                    const target = parseInt(firstTextNode.textContent.trim(), 10);
                    if (!isNaN(target)) {
                        const suffixHtml = Array.from(raw.childNodes).slice(1).map(n => n.outerHTML || n.textContent).join('');
                        const scoreSpan = document.createElement('span');
                        scoreSpan.textContent = '0';
                        num.innerHTML = '';
                        num.appendChild(scoreSpan);
                        num.insertAdjacentHTML('beforeend', suffixHtml);
                        Assembler.countUp(scoreSpan, target, { duration: 2200 });
                    }
                }
            }
            if (pct) {
                const targetPct = parseInt(pct.textContent.trim(), 10);
                if (!isNaN(targetPct)) {
                    Assembler.countUp(pct, targetPct, { duration: 2200, suffix: '%' });
                }
            }
        }, 2150);

        // Staggered inner-card reveals for summary + question lists
        document.querySelectorAll('.qr-summary-card').forEach((el, i) => {
            Assembler.observe(el, { kind: 'card', delay: i * 160 });
        });
        document.querySelectorAll('.qr-question').forEach((el, i) => {
            Assembler.observe(el, { kind: 'card', delay: Math.min(i * 90, 400) });
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>

<style id="qrTitleTypewriter">
@media screen {
.qr-cover-title.typewriting::after {
    content: '';
    display: inline-block;
    width: 2px;
    height: 0.9em;
    margin-left: 4px;
    background: currentColor;
    vertical-align: text-bottom;
    animation: qrTitleCaret 0.85s steps(1) infinite;
}
.qr-cover-title.typewriting.done::after { animation: none; opacity: 0; }
@keyframes qrTitleCaret { 0%, 50% { opacity: 1; } 51%, 100% { opacity: 0; } }
}
</style>
<script>
(function () {
    function run() {
        const el = document.querySelector('.qr-cover-title');
        if (!el) return;
        const full = el.textContent.trim();
        if (!full) return;
        el.dataset.fullTitle = full;
        el.textContent = '';
        el.classList.add('typewriting');
        setTimeout(() => {
            let i = 0;
            const typeSpeed = 28;
            function step() {
                if (i < full.length) {
                    el.textContent += full[i];
                    i++;
                    const ch = full[i - 1];
                    const pause = (ch === ',' || ch === '.') ? 180 : 0;
                    setTimeout(step, typeSpeed + pause);
                } else {
                    el.classList.add('done');
                }
            }
            step();
        }, 1750);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
    else run();
})();
</script>

<style id="v344QuizAnimations">
@media screen {
.qr-section-title.v344-typewriting::after {
    content: '';
    display: inline-block;
    width: 2px;
    height: 0.9em;
    margin-left: 3px;
    background: currentColor;
    vertical-align: text-bottom;
    animation: v344QuizCaret 0.85s steps(1) infinite;
}
.qr-section-title.v344-typewriting.v344-done::after { animation: none; opacity: 0; }
@keyframes v344QuizCaret { 0%, 50% { opacity: 1; } 51%, 100% { opacity: 0; } }

.qr-answer.v344-item {
    opacity: 0;
    transform: translateX(-14px);
    transition: opacity 0.5s ease, transform 0.5s cubic-bezier(0.22, 1, 0.36, 1);
    transition-delay: var(--v344-delay, 0ms);
}
.qr-answer.v344-item.v344-assembled { opacity: 1; transform: none; }

.qr-summary-card li.v344-item,
.qr-summary-card p.v344-item {
    opacity: 0;
    transform: translateX(-10px);
    transition: opacity 0.5s ease, transform 0.5s cubic-bezier(0.22, 1, 0.36, 1);
    transition-delay: var(--v344-delay, 0ms);
}
.qr-summary-card li.v344-item.v344-assembled,
.qr-summary-card p.v344-item.v344-assembled { opacity: 1; transform: none; }

@media (prefers-reduced-motion: reduce) {
    .qr-section-title.v344-typewriting::after,
    .qr-answer.v344-item,
    .qr-summary-card li.v344-item,
    .qr-summary-card p.v344-item {
        animation: none !important;
        opacity: 1 !important;
        transform: none !important;
    }
}
}
</style>
<script>
(function () {
    if (!window.IntersectionObserver) return;
    const io = new IntersectionObserver((entries) => {
        for (const e of entries) {
            if (e.isIntersecting && !e.target._v344Done) {
                e.target._v344Done = true;
                if (e.target._v344Fn) { try { e.target._v344Fn(e.target); } catch (x) {} }
                io.unobserve(e.target);
            }
        }
    }, { threshold: 0.18, rootMargin: '0px 0px -40px 0px' });
    const observe = (el, fn) => { if (!el || el._v344Obs) return; el._v344Obs = true; el._v344Fn = fn; io.observe(el); };

    function typeInto(el, full, speed) {
        speed = speed || 28;
        el.textContent = '';
        el.classList.add('v344-typewriting');
        let i = 0;
        (function step() {
            if (i >= full.length) { el.classList.add('v344-done'); return; }
            el.textContent += full[i];
            i++;
            const ch = full[i - 1];
            setTimeout(step, speed + (ch === ',' || ch === '.' ? 180 : 0));
        })();
    }

    function init() {
        // Section title typewriter
        document.querySelectorAll('.qr-section-title').forEach((el) => {
            const full = el.textContent.trim();
            if (!full) return;
            el.style.minHeight = el.offsetHeight + 'px';
            el.textContent = '';
            observe(el, () => typeInto(el, full, 25));
        });
        // Answer option items stagger
        document.querySelectorAll('.qr-question').forEach((q, qi) => {
            q.querySelectorAll('.qr-answer').forEach((a, ai) => {
                a.classList.add('v344-item');
                a.style.setProperty('--v344-delay', (ai * 120) + 'ms');
                observe(a, () => a.classList.add('v344-assembled'));
            });
        });
        // Summary lists stagger
        document.querySelectorAll('.qr-summary-card li, .qr-summary-card p').forEach((el, i) => {
            el.classList.add('v344-item');
            el.style.setProperty('--v344-delay', (i * 90) + 'ms');
            observe(el, () => el.classList.add('v344-assembled'));
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
</script>

<style id="printAlwaysLightReset">
@media print {
    html, body {
        background: #ffffff !important;
        color: #0f172a !important;
    }
    /* Nullify every dark-mode variable so no cascade leak. */
    [data-theme="dark"] {
        --card: #ffffff !important;
        --ink: #0f172a !important;
        --ink-soft: #334155 !important;
        --ink-muted: #64748b !important;
        --bg: #ffffff !important;
        --bg-surface: #ffffff !important;
        --fg: #0f172a !important;
        --fg-heading: #0f172a !important;
    }
    [data-theme="dark"] body {
        background: #ffffff !important;
        color: #0f172a !important;
    }
    /* Force any report/quiz content card back to light */
    [data-theme="dark"] .report-section,
    [data-theme="dark"] .report-card,
    [data-theme="dark"] .transcript-box,
    [data-theme="dark"] .qr-summary-card,
    [data-theme="dark"] .qr-question,
    [data-theme="dark"] .qr-answer,
    [data-theme="dark"] .qr-explanation,
    [data-theme="dark"] .learning-concept-card,
    [data-theme="dark"] .concept-card,
    [data-theme="dark"] .callout {
        background: #ffffff !important;
        border-color: rgba(0,0,0,0.10) !important;
        color: #0f172a !important;
    }
    [data-theme="dark"] .report-section p,
    [data-theme="dark"] .report-section li,
    [data-theme="dark"] .report-card p,
    [data-theme="dark"] .report-card li {
        color: #334155 !important;
    }
}
</style>


<!-- v3.89 — unified off-canvas (matches index.html) -->
<style id="v389Offcanvas">
.offcanvas-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(4, 8, 20, 0.55);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.6s cubic-bezier(0.16, 1, 0.3, 1);
    z-index: 199;
}
.offcanvas-backdrop.open {
    opacity: 1;
    pointer-events: auto;
}

/* ─── Drawer ────────────────────────────────────────────────────── */
.offcanvas {
    position: fixed;
    top: 0;
    right: 0;
    width: 320px;
    max-width: 88vw;
    height: 100vh;
    height: 100dvh;
    background: linear-gradient(180deg, var(--brand-grad-light) 0%, var(--brand-grad-mid) 50%, var(--brand-grad-dark) 100%);
    border-left: 1px solid rgba(255, 255, 255, 0.12);
    box-shadow:
        -24px 0 64px rgba(0, 0, 0, 0.55),
        -2px 0 8px rgba(var(--brand-400-rgb), 0.18);
    transform: translateX(105%);
    transition: transform 1.2s cubic-bezier(0.16, 1, 0.3, 1);
    z-index: 200;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    overflow-x: hidden;
}
.offcanvas.open {
    transform: translateX(0);
}

/* Subtle ambient grid behind everything */
.offcanvas::before {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    background-image:
        linear-gradient(rgba(var(--brand-400-rgb), 0.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(var(--brand-400-rgb), 0.05) 1px, transparent 1px);
    background-size: 30px 30px;
    mask-image: radial-gradient(ellipse at top, rgba(0, 0, 0, 0.6), transparent 70%);
    -webkit-mask-image: radial-gradient(ellipse at top, rgba(0, 0, 0, 0.6), transparent 70%);
}
.offcanvas > * { position: relative; z-index: 1; }

/* ─── Header (logo + close) ────────────────────────────────────── */
.offcanvas-header {
    padding: 28px 24px 26px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: relative;
}
.offcanvas-header::after {
    content: '';
    position: absolute;
    bottom: 0; left: 0; right: 0; height: 1px;
    background: linear-gradient(90deg, transparent, rgba(var(--brand-300-rgb), 0.55), transparent);
}
.offcanvas-logo {
    height: 39px;
    width: auto;
    object-fit: contain;
    filter: brightness(0) invert(1) drop-shadow(0 0 14px rgba(var(--brand-300-rgb), 0.45));
    opacity: 0;
    transform: translateY(-6px);
    transition: opacity 0.5s ease 0.5s, transform 0.5s ease 0.5s;
}
.offcanvas.open .offcanvas-logo {
    opacity: 0.96;
    transform: translateY(0);
}
.offcanvas-close {
    width: 40px;
    height: 40px;
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: rgba(255, 255, 255, 0.85);
    border-radius: 11px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    opacity: 0;
    transform: rotate(-90deg) scale(0.8);
    transition: opacity 0.5s ease 0.55s, transform 0.5s ease 0.55s, background 0.2s, border-color 0.2s;
}
.offcanvas.open .offcanvas-close {
    opacity: 1;
    transform: rotate(0) scale(1);
}
.offcanvas-close:hover {
    background: rgba(255, 255, 255, 0.16);
    border-color: rgba(255, 255, 255, 0.3);
}

/* ─── Menu items ───────────────────────────────────────────────── */
.offcanvas-nav {
    padding: 20px 16px 24px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex: 1;
}
.offcanvas-item {
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 18px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: rgba(255, 255, 255, 0.92);
    border-radius: 12px;
    font-size: 15px;
    font-weight: 500;
    font-family: inherit;
    text-align: left;
    cursor: pointer;
    transition: background 0.25s ease, border-color 0.25s ease, transform 0.25s ease;
    opacity: 0;
    transform: translateX(28px);
}
.offcanvas-item svg {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
    stroke: var(--brand-300);
    fill: none;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
    filter: drop-shadow(0 0 6px rgba(var(--brand-300-rgb), 0.35));
}
.offcanvas-item span {
    flex: 1;
    letter-spacing: 0.2px;
}
.offcanvas-item .offcanvas-arrow {
    width: 14px;
    height: 14px;
    stroke: rgba(255, 255, 255, 0.4);
    filter: none;
    stroke-width: 2.5;
}
.offcanvas-item:hover,
.offcanvas-item:active {
    background: rgba(255, 255, 255, 0.13);
    border-color: rgba(var(--brand-300-rgb), 0.4);
    transform: translateX(2px);
}
.offcanvas-item:hover .offcanvas-arrow {
    stroke: #fff;
    transform: translateX(2px);
}

/* ─── Stagger items in when drawer opens ───────────────────────── */
.offcanvas.open .offcanvas-item {
    animation: jai-offcanvas-item-in 0.55s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
.offcanvas.open .offcanvas-item:nth-child(1) { animation-delay: 0.45s; }
.offcanvas.open .offcanvas-item:nth-child(2) { animation-delay: 0.55s; }
.offcanvas.open .offcanvas-item:nth-child(3) { animation-delay: 0.65s; }
.offcanvas.open .offcanvas-item:nth-child(4) { animation-delay: 0.75s; }
.offcanvas.open .offcanvas-item:nth-child(5) { animation-delay: 0.85s; }
.offcanvas.open .offcanvas-item:nth-child(6) { animation-delay: 0.95s; }
.offcanvas.open .offcanvas-item:nth-child(7) { animation-delay: 1.05s; }
@keyframes jai-offcanvas-item-in {
    to { opacity: 1; transform: translateX(0); }
}

/* ─── Glare sweep across each item as it lands ─────────────────── */
.offcanvas.open .offcanvas-item::after {
    content: '';
    position: absolute;
    top: 0;
    left: -120%;
    width: 78%;
    height: 100%;
    background: linear-gradient(
        100deg,
        transparent 22%,
        rgba(255, 255, 255, 0.10) 38%,
        rgba(255, 255, 255, 0.55) 50%,
        rgba(255, 255, 255, 0.10) 62%,
        transparent 78%
    );
    transform: skewX(-22deg);
    pointer-events: none;
    z-index: 2;
    mix-blend-mode: screen;
    animation: jai-offcanvas-glare 0.7s cubic-bezier(0.4, 0, 0.2, 1) forwards;
}
.offcanvas.open .offcanvas-item:nth-child(1)::after { animation-delay: 0.65s; }
.offcanvas.open .offcanvas-item:nth-child(2)::after { animation-delay: 0.75s; }
.offcanvas.open .offcanvas-item:nth-child(3)::after { animation-delay: 0.85s; }
.offcanvas.open .offcanvas-item:nth-child(4)::after { animation-delay: 0.95s; }
.offcanvas.open .offcanvas-item:nth-child(5)::after { animation-delay: 1.05s; }
.offcanvas.open .offcanvas-item:nth-child(6)::after { animation-delay: 1.15s; }
.offcanvas.open .offcanvas-item:nth-child(7)::after { animation-delay: 1.25s; }
@keyframes jai-offcanvas-glare {
    0%   { left: -120%; opacity: 0; }
    18%  { opacity: 1; }
    82%  { opacity: 1; }
    100% { left: 140%; opacity: 0; }
}

/* ─── Footer ───────────────────────────────────────────────────── */
.offcanvas-foot {
    padding: 18px 24px;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.4);
    opacity: 0;
    transition: opacity 0.5s ease 1.3s;
}
.offcanvas-foot::before {
    /* v3.49 — pulsating green dot was overlapping Sign Out; hidden. */
    display: none !important;
}
.offcanvas-foot-version { color: rgba(255, 255, 255, 0.55); }
.offcanvas.open .offcanvas-foot { opacity: 1; }

@keyframes jai-pulse-dot {
    0%, 100% { opacity: 0.6; transform: scale(0.85); }
    50%      { opacity: 1;   transform: scale(1.1); }
}

/* ─── Mobile polish: tighten paddings/sizes elsewhere ─────────── */
@media (max-width: 768px) {
    main { padding: 16px !important; }
    .upload-zone { padding: 50px 24px !important; border-radius: 22px !important; }
    .upload-icon svg { width: 56px !important; height: 56px !important; }
    .upload-zone h2 { font-size: 18px !important; }
    .upload-zone p { font-size: 14px !important; }
    .format-badge { font-size: 11px !important; padding: 5px 12px !important; }


/* Hamburger: mobile-only, plain white glyph, no backdrop box */
.unified-menu-btn {
    display: none;
    background: transparent;
    border: 0;
    color: #ffffff;
    width: 44px; height: 44px;
    align-items: center; justify-content: center;
    cursor: pointer;
    padding: 0;
    margin-left: auto;
}
.unified-menu-btn:hover { opacity: 0.85; }
.unified-menu-btn svg { stroke: #ffffff; }
@media (max-width: 768px) {
    .unified-menu-btn { display: inline-flex !important; }
    /* Hide every other topbar action — the off-canvas has them all */
    .topbar .topbar-actions > *:not(.unified-menu-btn) { display: none !important; }
}
/* Kill the old rp-* hamburger + drawer from prior versions */
.rp-mobile-menu-btn,
#rpMobileMenu,
#rpInlineMenu,
.rp-inline-menu { display: none !important; }
</style>

<button type="button" id="unifiedMenuBtn" class="unified-menu-btn no-print" aria-label="Menu">
    <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
</button>

<div id="mobileOffcanvasBackdrop" class="offcanvas-backdrop" onclick="closeMobileMenu()"></div>
<aside id="mobileOffcanvas" class="offcanvas" aria-hidden="true">
    <div class="offcanvas-header">
        <img id="offcanvasLogo" src="<?= e($logoPath) ?>" alt="JAI" class="offcanvas-logo">
        <button class="offcanvas-close" onclick="closeMobileMenu()" aria-label="Close menu">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <nav class="offcanvas-nav">
        <a class="offcanvas-item" href="/index.html">
            <svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
            <span>Transcribe</span>
            <svg class="offcanvas-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a class="offcanvas-item" href="/index.html#reports">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            <span>Reports</span>
            <svg class="offcanvas-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a class="offcanvas-item" href="/index.html#history">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <span>History</span>
            <svg class="offcanvas-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a class="offcanvas-item" href="/index.html#analytics">
            <svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            <span>Analytics</span>
            <svg class="offcanvas-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a class="offcanvas-item" href="/index.html#contacts">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span>Contacts</span>
            <svg class="offcanvas-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a class="offcanvas-item offcanvas-item-admin" href="/index.html#users" style="display:none">
            <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <span>Users</span>
            <svg class="offcanvas-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a class="offcanvas-item" href="/index.html#feedback">
            <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <span>Feedback</span>
            <svg class="offcanvas-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
    </nav>
    <div class="offcanvas-foot">
        <button id="offcanvasSignOut" class="offcanvas-signout-btn" type="button">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <span>Sign Out</span>
        </button>
        <button id="offcanvasSettings" class="offcanvas-gear-btn" type="button" aria-label="Settings" title="Settings">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        </button>
    </div>
</aside>

<script>
(function(){
    const offcanvas = document.getElementById('mobileOffcanvas');
    const backdrop  = document.getElementById('mobileOffcanvasBackdrop');
    window.openMobileMenu = function () {
        offcanvas.classList.add('open');
        backdrop.classList.add('open');
        offcanvas.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        
            // Sync admin-only Users row visibility from localStorage role hint
            try {
                const role = (localStorage.getItem('user_role') || '').toLowerCase();
                const ocU = document.querySelector('.offcanvas-item-admin');
                if (ocU) ocU.style.display = (role === 'admin' || role === 'owner') ? '' : 'none';
            } catch (e) {}
    };
    window.closeMobileMenu = function () {
        offcanvas.classList.remove('open');
        backdrop.classList.remove('open');
        offcanvas.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    };
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && offcanvas.classList.contains('open')) closeMobileMenu();
    });
    document.getElementById('unifiedMenuBtn')?.addEventListener('click', openMobileMenu);
    
            // Sign out
            document.getElementById('offcanvasSignOut')?.addEventListener('click', async () => {
                try { await fetch('/api/auth.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ action: 'logout' }) }); }
                catch (e) {}
                window.location.href = '/login.php';
            });
            // Gear -> Settings
            document.getElementById('offcanvasSettings')?.addEventListener('click', () => {
                window.location.href = '/index.html#settings';
            });
})();
</script>


<!-- v3.81 — quiz-report bottom action bar (mobile) -->
<div id="qrBottomBar" class="rp-bottom-bar no-print" aria-hidden="true">
    <button type="button" class="rpbb-btn rpbb-pdf" onclick="window.print()">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <span>Download PDF</span>
    </button>
    <button type="button" class="rpbb-btn rpbb-share" onclick="qrShareOpen()">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
        <span>Share</span>
    </button>
</div>

<div id="qrEmailModal" class="rp-email-modal" aria-hidden="true">
    <div class="rp-email-backdrop" onclick="qrShareClose()"></div>
    <div class="rp-email-card">
        <div class="rp-email-head">
            <h3>Share this quiz report</h3>
            <button type="button" class="rp-email-close" onclick="qrShareClose()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="rp-email-body">
            <label>To <input type="email" id="qrEmailTo" placeholder="recipient@email.com" autocomplete="email"></label>
            <label>Subject <input type="text" id="qrEmailSubject" value="Pop Quiz Report | <?= e($title) ?>"></label>
            <label>Message <textarea id="qrEmailMsg" rows="3" placeholder="Optional personal note"></textarea></label>
            <div class="rp-email-chip">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                <span><?= e($title) ?> &middot; Pop Quiz &middot; link</span>
            </div>
        </div>
        <div class="rp-email-foot">
            <button type="button" class="rp-email-cancel" onclick="qrShareClose()">Cancel</button>
            <button type="button" id="qrEmailSendBtn" class="rp-email-send" onclick="qrShareSend()">Send</button>
        </div>
    </div>
</div>

<style id="v381QrBottomBar">
/* Reuse the .rp-bottom-bar / .rp-email-modal styles from report.php. */
.rp-bottom-bar {
    display: none;
    position: fixed;
    left: 12px; right: 12px;
    bottom: 14px;
    z-index: 1250;
    padding: 10px 10px;
    border-radius: 16px;
    background: linear-gradient(135deg,
        var(--brand-500, #2557b3) 0%,
        var(--brand-700, #1a3a7a) 55%,
        var(--brand-grad-dark, #0a1f40) 100%);
    box-shadow:
        0 18px 40px rgba(0, 15, 40, 0.45),
        inset 0 1px 0 rgba(255,255,255,0.18);
    transform: translateY(120%);
    opacity: 0;
    transition: transform 0.55s cubic-bezier(0.22,1,0.36,1),
                opacity 0.3s ease;
    gap: 8px;
    grid-template-columns: 1fr 1fr;
}
@media (max-width: 768px) { .rp-bottom-bar { display: grid; } }
.rp-bottom-bar.open {
    transform: translateY(0);
    opacity: 1;
}
.rpbb-btn {
    display: inline-flex; align-items: center; justify-content: center;
    gap: 8px;
    padding: 14px 12px;
    min-height: 48px;
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.22);
    background: rgba(255,255,255,0.08);
    color: #fff;
    font-size: 14px; font-weight: 700;
    letter-spacing: 0.4px;
    cursor: pointer;
    opacity: 0;
    transform: translateY(4px);
    transition: opacity 0.35s ease, transform 0.35s ease, background 0.2s ease;
}
.rp-bottom-bar.open .rpbb-btn { opacity: 1; transform: translateY(0); }
.rp-bottom-bar.open .rpbb-pdf   { transition-delay: 0.35s; }
.rp-bottom-bar.open .rpbb-share { transition-delay: 0.5s; }
.rpbb-btn:hover, .rpbb-btn:active {
    background: rgba(255,255,255,0.18);
    border-color: rgba(255,255,255,0.45);
}

.rp-email-modal { position: fixed; inset: 0; display: none; align-items: flex-end; justify-content: center; z-index: 1400; }
.rp-email-modal.open { display: flex; }
.rp-email-backdrop { position: absolute; inset: 0; background: rgba(0,10,30,0.5); backdrop-filter: blur(4px); }
.rp-email-card {
    position: relative; width: 100%; max-width: 520px;
    margin: 0 12px 12px;
    background: var(--card, #fff);
    border-radius: 18px;
    box-shadow: 0 30px 60px rgba(0,15,40,0.4);
    overflow: hidden;
    animation: rpEmailIn 0.35s cubic-bezier(0.22,1,0.36,1);
}
@keyframes rpEmailIn { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
@media (min-width: 769px) {
    .rp-email-modal { align-items: center; }
    .rp-email-card { margin: 0 auto; }
}
.rp-email-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; background: linear-gradient(135deg, var(--brand-500, #2557b3) 0%, var(--brand-700, #1a3a7a) 100%); color: #fff; }
.rp-email-head h3 { margin: 0; font-size: 16px; font-weight: 700; }
.rp-email-close { width: 34px; height: 34px; background: rgba(255,255,255,0.14); border: 1px solid rgba(255,255,255,0.25); border-radius: 10px; color: #fff; cursor: pointer; }
.rp-email-body { padding: 16px 20px 4px; display: flex; flex-direction: column; gap: 10px; color: var(--ink, #0f172a); }
.rp-email-body label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 700; letter-spacing: 0.6px; text-transform: uppercase; color: var(--ink-muted, #64748b); }
.rp-email-body input, .rp-email-body textarea {
    padding: 10px 12px; border-radius: 10px;
    border: 1px solid rgba(0,0,0,0.12);
    font-family: inherit; font-size: 14px; font-weight: 500;
    color: var(--ink, #0f172a); background: var(--bg-surface, #fff);
}
.rp-email-body input:focus, .rp-email-body textarea:focus { outline: 2px solid var(--brand-400, #60a5fa); border-color: transparent; }
.rp-email-chip { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; margin-top: 4px; border-radius: 10px; background: rgba(var(--brand-500-rgb), 0.08); color: var(--brand-700, #1a3a7a); font-size: 12px; font-weight: 600; }
.rp-email-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 20px 18px; }
.rp-email-cancel, .rp-email-send { padding: 10px 18px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; border: 0; font-family: inherit; }
.rp-email-cancel { background: transparent; color: var(--ink-muted, #64748b); border: 1px solid rgba(0,0,0,0.12); }
.rp-email-send { background: linear-gradient(135deg, var(--brand-500, #2557b3) 0%, var(--brand-700, #1a3a7a) 100%); color: #fff; }
.rp-email-send[disabled] { opacity: 0.6; cursor: not-allowed; }
[data-theme="dark"] .rp-email-card { background: #0f172a; }
[data-theme="dark"] .rp-email-body input, [data-theme="dark"] .rp-email-body textarea { background: #1e293b; border-color: rgba(255,255,255,0.12); color: #f1f5f9; }
[data-theme="dark"] .rp-email-chip { background: rgba(var(--brand-400-rgb), 0.18); color: var(--brand-200, #bfdbfe); }
@media (max-width: 768px) {
    body { padding-bottom: 100px; }
}
</style>
<script>
/* v3.81 — quiz-report bottom bar + share lightbox */
(function () {
    const bar = document.getElementById('qrBottomBar');
    const modal = document.getElementById('qrEmailModal');

    window.qrShareOpen = function () {
        if (!modal) return;
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    };
    window.qrShareClose = function () {
        if (!modal) return;
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    };
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && modal && modal.classList.contains('open')) qrShareClose();
    });

    if (bar) {
        let shown = false;
        const show = () => {
            if (shown) return;
            shown = true;
            bar.classList.add('open');
            bar.setAttribute('aria-hidden', 'false');
        };
        window.addEventListener('scroll', () => { if (window.scrollY > 60) show(); }, { passive: true });
        setTimeout(show, 1200);
    }

    window.qrShareSend = async function () {
        const to = document.getElementById('qrEmailTo')?.value.trim();
        const subject = document.getElementById('qrEmailSubject')?.value.trim();
        const note = document.getElementById('qrEmailMsg')?.value.trim();
        const btn = document.getElementById('qrEmailSendBtn');
        if (!to) { alert('Please enter a recipient email.'); return; }

        // No session cookie -> use mailto: fallback so public viewers
        // can share the quiz-report URL with no backend call.
        const hasSession = /PHPSESSID=/.test(document.cookie) || /transcribe_session=/.test(document.cookie);
        if (!hasSession) {
            const quizUrl = window.location.href;
            const body = (note ? (note + '\n\n') : 'Sharing a pop quiz report.\n\n') + 'Open the quiz report here:\n' + quizUrl;
            const mailto = 'mailto:' + encodeURIComponent(to)
                + '?subject=' + encodeURIComponent(subject || 'Pop Quiz Report')
                + '&body=' + encodeURIComponent(body);
            window.location.href = mailto;
            qrShareClose();
            return;
        }
        if (btn) { btn.disabled = true; btn.textContent = 'Sending...'; }

        let senderName = 'Jason AI', senderEmail = '';
        try {
            const raw = localStorage.getItem('transcribe_settings');
            if (raw) {
                const set = JSON.parse(raw);
                senderName  = (set.senderName  || senderName).trim();
                senderEmail = (set.senderEmail || '').trim();
            }
        } catch (e) {}
        if (!senderEmail) senderEmail = 'noreply@jasonhogan.ca';

        const attemptId = <?= (int) $attempt['id'] ?>;
        const transcriptionId = <?= (int) $attempt['transcription_id'] ?>;
        const quizUrl = window.location.origin + '/api/quiz-report.php?id=' + attemptId;
        const reportTitle = <?= json_encode($title) ?>;
        const safeNote = (note || '').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');

        const html = `<!doctype html><html><body style="margin:0;padding:0;background:#f5f8fc;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
<table role="presentation" width="100%" style="background:#f5f8fc;padding:32px 12px;">
  <tr><td align="center">
    <table role="presentation" width="560" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 6px 24px rgba(15,31,64,0.08);">
      <tr><td style="background:linear-gradient(135deg,#2557b3,#1a3a7a);padding:28px 24px;color:#fff;">
        <div style="font-size:11px;letter-spacing:2px;text-transform:uppercase;opacity:0.8;">Pop Quiz Report</div>
        <div style="font-size:22px;font-weight:800;margin-top:6px;">${reportTitle}</div>
      </td></tr>
      <tr><td style="padding:24px;color:#0f172a;font-size:15px;line-height:1.55;">
        ${safeNote ? '<p style="margin:0 0 18px;">' + safeNote + '</p>' : '<p style="margin:0 0 18px;">Sharing a pop quiz report from my learning analysis.</p>'}
        <p style="margin:0 0 24px;">Open the quiz report to see the questions, answers and scoring breakdown.</p>
        <a href="${quizUrl}" style="display:inline-block;background:linear-gradient(135deg,#2557b3,#1a3a7a);color:#fff;font-weight:700;text-decoration:none;padding:14px 26px;border-radius:10px;">Open Quiz Report</a>
      </td></tr>
      <tr><td style="padding:18px 24px;background:#f8fafc;color:#64748b;font-size:12px;text-align:center;">
        Report created by <a href="https://jasonai.ca" style="color:#2557b3;text-decoration:none;font-weight:600;">Jason AI</a>
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>`;

        try {
            // Flip the transcription public so the quiz-report URL loads for recipients
            try {
                const fd = new FormData();
                fd.append('id', transcriptionId);
                fd.append('public', '1');
                await fetch('/api/transcription-share.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            } catch (e) {}

            const r = await fetch('/api/send-smtp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    transcription_id: transcriptionId,
                    from: senderEmail,
                    from_name: senderName,
                    to: to,
                    subject: subject || ('Pop Quiz Report | ' + reportTitle),
                    html: html
                })
            });
            const d = await r.json().catch(() => ({}));
            if (!r.ok || d.error) throw new Error(d.error || ('HTTP ' + r.status));
            qrShareClose();
            alert('Email sent.');
        } catch (err) {
            alert('Send failed: ' + err.message);
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = 'Send'; }
        }
    };
})();
</script>

<style id="v3107BarMobileOnly">
/* v3.107 — quiz-report: hide bottom bar on desktop entirely.
   Desktop users already have the floating Download PDF on the
   right side; they don't need the centered share pill. */
@media (min-width: 769px) {
    .rp-bottom-bar { display: none !important; }
}
</style>

<style id="v391QrLogosMobile">
@media (max-width: 768px) {
    /* Header brand logo -50% */
    .topbar-logo {
        height: 13px !important;
        max-width: 70px !important;
    }
    /* Cover tile logo -50% so it fits inside the card */
    .qr-cover-logo {
        height: 22px !important;
        max-width: 120px !important;
        margin-bottom: 14px !important;
    }
}
</style>

<style id="v399QrPrintHide">
@media print {
    .qr-mob-actions,
    .qr-mob-actions * { display: none !important; }
}
</style>

<style id="v3104QrPrintLogo">
@media print {
    .qr-cover-logo {
        width: auto !important;
        height: 132px !important;
        max-width: none !important;
        aspect-ratio: auto !important;
        object-fit: contain !important;
        margin-top: -50px !important;
        margin-bottom: 18px !important;
    }
}
</style>

<style id="v3109MatrixHeader">
@media (max-width: 768px) {
    .topbar {
        position: relative;
        overflow: hidden;
    }
    .topbar > * {
        position: relative;
        z-index: 1;
    }
    .tb-matrix {
        display: block;
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
        z-index: 0;
        opacity: 0.14;
    }
    /* Unified logo size on mobile (matches index.html) */
    .topbar-brand-logo,
    .topbar-logo {
        height: 22px !important;
        max-width: 200px !important;
        margin-left: -5px !important;
        opacity: 1 !important;
    }
}
@media (min-width: 769px) {
    .tb-matrix { display: none; }
}
</style>
<script id="v3109MatrixScript">
(function () {
    if (!window.matchMedia('(max-width: 768px)').matches) return;
    const tb = document.querySelector('.topbar');
    if (!tb) return;
    // Create + mount the canvas
    const cvs = document.createElement('canvas');
    cvs.className = 'tb-matrix';
    cvs.setAttribute('aria-hidden', 'true');
    tb.insertBefore(cvs, tb.firstChild);
    const ctx = cvs.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const GLYPHS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789ｱｲｳｴｵｶｷｸｹｺｻｼｽｾｿﾀﾁﾂﾃﾄﾅﾆﾇﾈﾉ<>/';
    let cols = 0, drops = [], cellW = 12 * dpr, cellH = 16 * dpr;
    let W = 0, H = 0;

    function resize() {
        const rect = cvs.getBoundingClientRect();
        W = Math.max(1, Math.floor(rect.width * dpr));
        H = Math.max(1, Math.floor(rect.height * dpr));
        cvs.width = W; cvs.height = H;
        cols = Math.ceil(W / cellW);
        drops = new Array(cols).fill(0).map(() => Math.random() * -H);
    }
    resize();
    window.addEventListener('resize', resize);

    let running = true;
    document.addEventListener('visibilitychange', () => {
        running = !document.hidden;
        if (running) tick();
    });
    function tick() {
        if (!running) return;
        ctx.fillStyle = 'rgba(0, 8, 24, 0.08)';
        ctx.fillRect(0, 0, W, H);
        ctx.font = 'bold ' + (13 * dpr) + "px 'Courier New', monospace";
        ctx.textBaseline = 'top';
        for (let i = 0; i < cols; i++) {
            const ch = GLYPHS[(Math.random() * GLYPHS.length) | 0];
            const x = i * cellW;
            const y = drops[i];
            ctx.fillStyle = 'rgba(220, 235, 255, 0.95)';
            ctx.fillText(ch, x, y);
            drops[i] += cellH * 0.11;
            if (drops[i] > H + Math.random() * 40) {
                drops[i] = -cellH * (Math.random() * 6 + 1);
            }
        }
        requestAnimationFrame(tick);
    }
    tick();
})();
</script>

<style id="v3110UnifiedMenu">
.unified-menu-btn {
    display: none;
    background: transparent;
    border: 0;
    color: #ffffff;
    width: 60px; height: 60px;
    align-items: center; justify-content: center;
    cursor: pointer;
    padding: 14px;
    border-radius: 12px;
    position: absolute;
    top: 50%;
    right: 14px;
    transform: translateY(-50%);
    z-index: 3;
}
.unified-menu-btn:hover { opacity: 0.85; }
.unified-menu-btn svg { stroke: #ffffff; }
@media (max-width: 768px) {
    .unified-menu-btn { display: inline-flex !important; }
    .topbar .topbar-actions > * { display: none !important; }
    .topbar {
        padding-top: 12px !important;
        padding-bottom: 12px !important;
        padding-left: 18px !important;
        min-height: 84px;
    }
}
</style>
<button type="button" id="unifiedMenuBtn" class="unified-menu-btn no-print" aria-label="Menu">
    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
</button>
<script id="v3110MountMenu">
(function () {
    function mount() {
        const btn = document.getElementById('unifiedMenuBtn');
        const tb  = document.querySelector('.topbar');
        if (btn && tb && btn.parentNode !== tb) tb.appendChild(btn);
        btn?.addEventListener('click', () => { if (typeof openMobileMenu === 'function') openMobileMenu(); });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else { mount(); }
})();
</script>

<style id="v3112OffcanvasParity">
/* Pixel-match to index.html's off-canvas footer buttons */
.offcanvas-foot {
    display: flex !important;
    gap: 10px !important;
    align-items: stretch !important;
    padding: 18px 24px !important;
}
.offcanvas-signout-btn {
    flex: 1 1 auto !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 10px !important;
    padding: 14px 18px !important;
    border-radius: 12px !important;
    border: 1px solid rgba(255,255,255,0.18) !important;
    background: linear-gradient(135deg,
        var(--brand-600) 0%,
        var(--brand-700) 55%,
        var(--brand-grad-mid) 100%) !important;
    color: #fff !important;
    font-family: inherit !important;
    font-size: 14px !important;
    font-weight: 700 !important;
    letter-spacing: 0.4px !important;
    text-transform: uppercase !important;
    cursor: pointer !important;
    transition: all 0.25s cubic-bezier(0.22, 1, 0.36, 1) !important;
    box-shadow:
        0 6px 20px rgba(var(--brand-500-rgb), 0.28),
        inset 0 1px 0 rgba(255,255,255,0.14) !important;
}
.offcanvas-signout-btn:hover {
    transform: translateY(-1px) !important;
    box-shadow:
        0 10px 28px rgba(var(--brand-500-rgb), 0.4),
        inset 0 1px 0 rgba(255,255,255,0.18) !important;
}
.offcanvas-signout-btn svg { stroke: #fff !important; flex-shrink: 0 !important; }
.offcanvas-gear-btn {
    flex: 0 0 auto !important;
    width: 52px !important;
    min-height: 46px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border-radius: 12px !important;
    border: 1px solid rgba(255,255,255,0.18) !important;
    background: rgba(255,255,255,0.06) !important;
    color: rgba(255,255,255,0.85) !important;
    cursor: pointer !important;
    transition: background 0.2s ease, transform 0.2s ease !important;
}
.offcanvas-gear-btn:hover {
    background: rgba(255,255,255,0.14) !important;
    transform: rotate(30deg) !important;
    color: #fff !important;
}
/* Force the drawer logo to match index */
.offcanvas-logo {
    height: 39px !important;
    width: auto !important;
    object-fit: contain !important;
    opacity: 0.96 !important;
    filter: brightness(0) invert(1) drop-shadow(0 0 14px rgba(var(--brand-300-rgb), 0.45)) !important;
}
/* Hamburger: right offset mirrors the logo's visual left (13px) */
@media (max-width: 768px) {
    .unified-menu-btn {
        right: 13px !important;
    }
}
</style>
</body>
</html>
