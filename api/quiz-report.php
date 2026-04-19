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
</body>
</html>
