#!/usr/bin/env python3
"""v3.47 batched fixes (priority-ordered):

A) Quiz-report PDF ALWAYS light mode — belt-and-suspenders @media print
   reset that undoes any dark-mode vars at print time, regardless of
   the user's current theme.

B) Celebration card:
   - Sprout icon -> Trophy SVG for the tryagain tier.
   - Buttons changed from [Try Again+icon, Close] to
     [View Report (primary), Try Again (text only)].
   - View Report -> 1.5s slide-up overlay, then navigates to
     /api/quiz-report.php?id=<attemptId>. Attempt id captured from
     the save response (quiz.php now returns it; this wires the
     save call to await + remember the id).

C) Hide the Covers tab in the Settings modal per user direction.
"""
# ═══════════════════════════════════════════════════════════════
# A + B — report.php
# ═══════════════════════════════════════════════════════════════
rp_path = '/var/www/transcribe/api/report.php'
rp = open(rp_path).read()

# A) PDF light reset block — append strong @media print override.
print_reset = """
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
"""
if 'id="printAlwaysLightReset"' in rp:
    print('A-report: print reset already present')
else:
    idx = rp.rfind('</body>')
    rp = rp[:idx] + print_reset + rp[idx:]
    print('A-report: print reset appended')

# B1) Swap sprout icon for trophy on the tryagain tier
old_tier = "tryagain: { icon: 'sprout',   title: 'Keep Going!',      subtitle: 'Every attempt builds mastery - give it another shot.',      tierClass: 'tier-tryagain', medal: '\\ud83c\\udf31' }"
new_tier = "tryagain: { icon: 'trophy',   title: 'Keep Going!',      subtitle: 'Every attempt builds mastery - give it another shot.',      tierClass: 'tier-tryagain', medal: '\\ud83c\\udfc6' }"
if old_tier in rp:
    rp = rp.replace(old_tier, new_tier, 1)
    print('B1: tryagain tier now uses trophy icon')

# B2) Celebration buttons — replace Try Again + Close with View Report + Try Again
old_buttons = """                <div class="celebration-actions">
                    <button class="quiz-btn-retake" onclick="closeCelebration(); setTimeout(() => startPopQuiz(), 600);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                        Try Again
                    </button>
                    <button class="quiz-btn-close" onclick="closeCelebration()">Close</button>
                </div>"""
new_buttons = """                <div class="celebration-actions" style="flex-direction:column;gap:12px;align-items:stretch;">
                    <button class="quiz-btn-retake quiz-btn-view-report" onclick="viewQuizReportSlideUp()" style="width:100%;justify-content:center;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        View Report
                    </button>
                    <button class="quiz-btn-close" onclick="closeCelebration(); setTimeout(() => startPopQuiz(), 600);" style="width:100%;">Try Again</button>
                </div>"""
if 'quiz-btn-view-report' in rp:
    print('B2: buttons already swapped')
elif old_buttons in rp:
    rp = rp.replace(old_buttons, new_buttons, 1)
    print('B2: celebration buttons -> [View Report, Try Again]')
else:
    print('B2: WARN buttons anchor not found')

# B3) Capture attempt id from /api/quiz.php save response + wire View Report
old_save = """        // Save to database
        fetch('/api/quiz.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ action: 'save', transcription_id: TRANSCRIPTION_ID, score: window._quizScore, total_questions: window._quizTotal, questions_json: JSON.stringify(window._quizQuestions), answers_json: JSON.stringify(window._quizAnswers) })
        }).catch(() => {});"""
new_save = """        // Save to database + capture attempt id for the View Report button
        fetch('/api/quiz.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ action: 'save', transcription_id: TRANSCRIPTION_ID, score: window._quizScore, total_questions: window._quizTotal, questions_json: JSON.stringify(window._quizQuestions), answers_json: JSON.stringify(window._quizAnswers) })
        })
            .then(r => r.ok ? r.json() : null)
            .then(d => { if (d && d.id) window._lastQuizAttemptId = d.id; })
            .catch(() => {});"""
if '_lastQuizAttemptId' in rp:
    print('B3: attempt id already captured')
elif old_save in rp:
    rp = rp.replace(old_save, new_save, 1)
    print('B3: attempt id captured on save')
else:
    print('B3: save pattern not found')

# B4) viewQuizReportSlideUp function
view_report_fn = """
<script>
/* v3.47 — View Report slide-up */
function viewQuizReportSlideUp() {
    const attemptId = window._lastQuizAttemptId;
    if (!attemptId) {
        // Fallback — no id captured, navigate to quiz-history.
        window.location.href = '/api/report.php?id=' + TRANSCRIPTION_ID;
        return;
    }
    const url = '/api/quiz-report.php?id=' + encodeURIComponent(attemptId);
    // Preload the quiz-report under the celebration overlay
    const frame = document.createElement('iframe');
    frame.src = url;
    frame.style.cssText = 'position:fixed;inset:0;width:100vw;height:100vh;border:0;background:#fff;z-index:99997;opacity:0;transition:opacity 0.5s ease;pointer-events:none;';
    document.body.appendChild(frame);
    // After a brief preload window, reveal iframe + slide overlay up
    setTimeout(() => { frame.style.opacity = '1'; }, 350);
    const ov = document.querySelector('.celebration-overlay');
    if (ov) {
        ov.style.transition = 'transform 1.5s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.3s ease 1.2s';
        ov.style.transform = 'translateY(-110vh)';
    }
    // Navigate once the lift is ~done so the real page paints behind paint-hold
    setTimeout(() => { window.location.href = url; }, 1400);
}
</script>
"""
if 'viewQuizReportSlideUp' in rp:
    print('B4: view-report slide-up already present')
else:
    idx = rp.rfind('</body>')
    rp = rp[:idx] + view_report_fn + rp[idx:]
    print('B4: view-report slide-up function added')

open(rp_path, 'w').write(rp)

# ═══════════════════════════════════════════════════════════════
# A (quiz-report) — same print reset
# ═══════════════════════════════════════════════════════════════
q_path = '/var/www/transcribe/api/quiz-report.php'
q = open(q_path).read()
if 'id="printAlwaysLightReset"' in q:
    print('A-quiz: print reset already present')
else:
    idx = q.rfind('</body>')
    q = q[:idx] + print_reset + q[idx:]
    open(q_path, 'w').write(q)
    print('A-quiz: print reset appended')

# ═══════════════════════════════════════════════════════════════
# C — Hide Covers tab in settings (index.html)
# ═══════════════════════════════════════════════════════════════
idx_path = '/var/www/transcribe/index.html'
s = open(idx_path).read()
old_cov = '<button class="settings-tab" data-stab="covers">'
new_cov = '<button class="settings-tab" data-stab="covers" style="display:none !important;">'
if 'data-stab="covers" style="display:none' in s:
    print('C: Covers tab already hidden')
elif old_cov in s:
    s = s.replace(old_cov, new_cov, 1)
    # Also hide the settings-panel[data-stab-panel="covers"]
    s = s.replace('data-stab-panel="covers"', 'data-stab-panel="covers" style="display:none !important;"', 1)
    # Cache bump
    s = s.replace('?v=20260419v', '?v=20260419w')
    open(idx_path, 'w').write(s)
    print('C: Covers tab + panel hidden + cache bumped')
else:
    print('C: Covers tab anchor not found')
print('DONE')
