<?php
/**
 * Public Feedback submission — user-facing.
 *
 * Flow:
 *   1. Writes to the `feedback` table (DB of record).
 *   2. Fire-and-forget sends a BRANDED HTML email to Jason at
 *      jasonhogan333@gmail.com so he sees the feedback immediately.
 *      Reply-To is set to the submitter so Jason can reply directly.
 *   3. Email styling is category-aware: bug / feature / question /
 *      praise / other — each has its own accent color + icon + eyebrow
 *      headline so the inbox is scannable at a glance.
 *
 * Email sending is non-fatal: if EmailIt isn't configured or fails,
 * the DB insert still counts as a successful submission so the user
 * doesn't see an error.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_middleware.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$body = trim($input['body'] ?? '');
if (strlen($body) < 5) {
    http_response_code(400);
    echo json_encode(['error' => 'Please add a little more detail']);
    exit;
}

$subject  = trim($input['subject'] ?? '');
$category = $input['category'] ?? 'other';
if (!in_array($category, ['bug','feature','question','praise','other'], true)) $category = 'other';
$pageUrl = trim($input['page_url'] ?? '');

$userId    = getCurrentUserId();
$orgId     = getCurrentOrgId();
$userEmail = $_SESSION['user_email'] ?? null;
$userName  = $_SESSION['user_name']  ?? null;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// ─── DB INSERT (source of truth) ────────────────────────────────────────
try {
    $db = getDB();
    $db->prepare("INSERT INTO feedback
        (user_id, organization_id, user_email, user_name, subject, body, category, page_url, user_agent)
        VALUES (:uid, :org, :email, :name, :subject, :body, :category, :url, :ua)")
       ->execute([
           ':uid'      => $userId,
           ':org'      => $orgId,
           ':email'    => $userEmail,
           ':name'     => $userName,
           ':subject'  => $subject ?: null,
           ':body'     => $body,
           ':category' => $category,
           ':url'      => $pageUrl ?: null,
           ':ua'       => substr($userAgent, 0, 500),
       ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not save feedback']);
    exit;
}

// ─── EMAIL NOTIFICATION (fire-and-forget) ───────────────────────────────
// Target admin inbox. Hardcoded to Jason's personal gmail per product spec.
const FEEDBACK_ADMIN_EMAIL = 'jasonhogan333@gmail.com';

function fb_build_email_html($category, $subject, $body, $userName, $userEmail, $pageUrl, $userAgent) {
    // Per-category presentation: accent color, icon SVG, eyebrow label.
    $cats = [
        'bug' => [
            'accent'   => '#dc2626',
            'accent2'  => '#b91c1c',
            'bg_tint'  => '#fef2f2',
            'eyebrow'  => 'Bug Report',
            'emoji'    => '🐛',
            'label'    => 'Bug',
            'blurb'    => 'Something broke or misbehaved in the app.',
            'icon_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="6" width="8" height="14" rx="4"/><path d="M12 2v4M5 8l3 2M19 8l-3 2M2 15h4M18 15h4M5 20l3-2M19 20l-3-2"/></svg>',
        ],
        'feature' => [
            'accent'   => '#7c3aed',
            'accent2'  => '#5b21b6',
            'bg_tint'  => '#faf5ff',
            'eyebrow'  => 'Feature Request',
            'emoji'    => '💡',
            'label'    => 'Feature',
            'blurb'    => 'An idea or feature request.',
            'icon_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6M10 22h4M12 2a7 7 0 0 1 4 12.8V17H8v-2.2A7 7 0 0 1 12 2z"/></svg>',
        ],
        'question' => [
            'accent'   => '#2563eb',
            'accent2'  => '#1d4ed8',
            'bg_tint'  => '#eff6ff',
            'eyebrow'  => 'Question',
            'emoji'    => '❓',
            'label'    => 'Question',
            'blurb'    => 'Needs help or clarification.',
            'icon_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        ],
        'praise' => [
            'accent'   => '#d97706',
            'accent2'  => '#b45309',
            'bg_tint'  => '#fffbeb',
            'eyebrow'  => 'Praise',
            'emoji'    => '❤️',
            'label'    => 'Praise',
            'blurb'    => 'Something they loved!',
            'icon_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="#ffffff" stroke="#ffffff" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
        ],
        'other' => [
            'accent'   => '#2557b3',
            'accent2'  => '#1a3a7a',
            'bg_tint'  => '#eff4ff',
            'eyebrow'  => 'General Feedback',
            'emoji'    => '💬',
            'label'    => 'Other',
            'blurb'    => 'General comment or note.',
            'icon_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        ],
    ];
    $c = $cats[$category] ?? $cats['other'];

    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    // Body preserves newlines (paragraph breaks) but escapes HTML.
    $bodyHtml = nl2br($e($body));

    $from_line = $userName
        ? $e($userName) . ' &lt;' . $e($userEmail) . '&gt;'
        : ($userEmail ? $e($userEmail) : 'Anonymous');

    $subject_block = $subject
        ? '<tr><td style="padding:0 40px 10px;"><div style="font-size:11px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:#94a3b8;margin-bottom:6px;">Subject</div><div style="font-size:17px;font-weight:700;color:#0f172a;line-height:1.35;">' . $e($subject) . '</div></td></tr>'
        : '';

    $page_block = $pageUrl
        ? '<tr><td style="padding:6px 40px 0;"><div style="font-size:11px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:#94a3b8;margin-bottom:6px;">Submitted From</div><div style="font-size:13px;color:#475569;font-family:\'SF Mono\',Monaco,Consolas,monospace;word-break:break-all;">' . $e($pageUrl) . '</div></td></tr>'
        : '';

    $dateStr = date('F j, Y \a\t g:i A T');

    // Build the HTML. Table-based for email client compatibility.
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>JAI Feedback</title></head>'
    . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 16px;">'
    . '<tr><td align="center">'
    . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,0.12);">'

    // ── Header: category-colored gradient ──
    . '<tr><td style="background:linear-gradient(135deg,' . $c['accent'] . ' 0%,' . $c['accent2'] . ' 100%);padding:40px 40px 36px;text-align:center;">'
    . '<div style="display:inline-block;width:72px;height:72px;border-radius:50%;background:rgba(255,255,255,0.18);border:2px solid rgba(255,255,255,0.35);line-height:72px;text-align:center;margin-bottom:16px;">'
    . '<div style="display:inline-block;vertical-align:middle;">' . $c['icon_svg'] . '</div>'
    . '</div>'
    . '<div style="font-size:11px;font-weight:800;letter-spacing:3px;text-transform:uppercase;color:rgba(255,255,255,0.75);margin-bottom:6px;">' . $e($c['eyebrow']) . '</div>'
    . '<h1 style="margin:0;font-size:26px;line-height:1.2;font-weight:800;color:#ffffff;letter-spacing:-0.4px;">New Feedback — ' . $c['emoji'] . ' ' . $e($c['label']) . '</h1>'
    . '<p style="margin:10px 0 0;font-size:14px;color:rgba(255,255,255,0.82);">' . $e($c['blurb']) . '</p>'
    . '</td></tr>'

    // ── Meta row: from + date ──
    . '<tr><td style="padding:28px 40px 8px;background:' . $c['bg_tint'] . ';border-bottom:1px solid rgba(0,0,0,0.05);">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
    . '<td style="padding-right:16px;">'
    . '<div style="font-size:11px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:#64748b;margin-bottom:6px;">From</div>'
    . '<div style="font-size:14px;font-weight:600;color:#0f172a;">' . $from_line . '</div>'
    . '</td>'
    . '<td style="text-align:right;">'
    . '<div style="font-size:11px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:#64748b;margin-bottom:6px;">Received</div>'
    . '<div style="font-size:14px;color:#334155;">' . $e($dateStr) . '</div>'
    . '</td></tr></table>'
    . '</td></tr>'

    . $subject_block

    // ── Body card ──
    . '<tr><td style="padding:22px 40px 8px;">'
    . '<div style="font-size:11px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:#94a3b8;margin-bottom:10px;">Message</div>'
    . '<div style="background:#f8fafc;border-left:4px solid ' . $c['accent'] . ';border-radius:10px;padding:20px 22px;font-size:15px;line-height:1.65;color:#1e293b;">'
    . $bodyHtml
    . '</div>'
    . '</td></tr>'

    . $page_block

    // ── Reply CTA ──
    . '<tr><td style="padding:24px 40px 8px;">'
    . '<div style="background:#f8fafc;border-radius:10px;padding:16px 20px;text-align:center;font-size:13px;color:#475569;">'
    . '&#9993; Just hit <strong>Reply</strong> to respond directly to ' . ($userName ? $e($userName) : ($userEmail ? $e($userEmail) : 'the sender'))
    . '</div>'
    . '</td></tr>'

    // ── Footer ──
    . '<tr><td style="padding:24px 40px 32px;text-align:center;border-top:1px solid #e2e8f0;">'
    . '<p style="margin:0;font-size:12px;color:#94a3b8;letter-spacing:0.3px;">JAI Feedback &mdash; sent from transcribe.jasonhogan.ca</p>'
    . '<p style="margin:8px 0 0;font-size:11px;color:#cbd5e1;">' . $e(substr($userAgent, 0, 160)) . '</p>'
    . '</td></tr>'

    . '</table></td></tr></table></body></html>';
}

try {
    // Fetch EmailIt API key + sender identity. Prefer the submitter's org
    // for scoping (multi-tenant safe). Fall back to any org that has keys
    // configured so admin-inbox notifications don't silently drop.
    $apiKey = null;
    $senderEmail = null;
    $senderName  = null;

    $q = $db->prepare("SELECT setting_key, setting_value FROM settings
                       WHERE organization_id = :org AND setting_key IN ('emailitApiKey','senderEmail','senderName')");
    $q->execute([':org' => $orgId]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['setting_key'] === 'emailitApiKey') $apiKey = $r['setting_value'];
        elseif ($r['setting_key'] === 'senderEmail') $senderEmail = $r['setting_value'];
        elseif ($r['setting_key'] === 'senderName')  $senderName  = $r['setting_value'];
    }

    // Fallback: first org that has an EmailIt key configured
    if (!$apiKey) {
        $fallback = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'emailitApiKey' AND setting_value IS NOT NULL AND setting_value != '' LIMIT 1");
        $apiKey = $fallback ? ($fallback->fetchColumn() ?: null) : null;
    }

    if ($apiKey) {
        $from = $senderEmail ? (($senderName ? $senderName . ' <' : '') . $senderEmail . ($senderName ? '>' : '')) : 'JAI Feedback <feedback@jasonhogan.ca>';

        $labels = ['bug'=>'Bug','feature'=>'Feature','question'=>'Question','praise'=>'Praise','other'=>'Feedback'];
        $emojiMap = ['bug'=>'🐛','feature'=>'💡','question'=>'❓','praise'=>'❤️','other'=>'💬'];
        $emailSubject = '[JAI ' . $labels[$category] . '] ' . ($subject ?: mb_substr($body, 0, 60) . (mb_strlen($body) > 60 ? '...' : ''));

        $html = fb_build_email_html($category, $subject, $body, $userName, $userEmail, $pageUrl, $userAgent);

        $payload = [
            'from'    => $from,
            'to'      => FEEDBACK_ADMIN_EMAIL,
            'subject' => $emojiMap[$category] . ' ' . $emailSubject,
            'html'    => $html,
        ];
        if ($userEmail) {
            // Let Jason just hit Reply and land in the user's inbox.
            $payload['reply_to'] = $userEmail;
        }

        $ch = curl_init('https://api.emailit.com/v2/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
        ]);
        // Fire it. We do NOT surface errors — DB insert already succeeded and
        // the user shouldn't see a fail because of our notification pipeline.
        curl_exec($ch);
        curl_close($ch);
    }
    // If no apiKey, silently skip email. Feedback is still in DB.
} catch (Throwable $e) {
    // Swallow — email is best-effort.
}

echo json_encode(['success' => true]);
