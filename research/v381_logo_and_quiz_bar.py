#!/usr/bin/env python3
"""v3.81 —
  A) Undo the v3.78 mistake: topbar-brand-logo back to default
     (26px). Double the HERO logo (#jaiHeroSection .jaihero-logo)
     on mobile instead — 48 -> 96px.
  B) Quiz-report.php bottom action bar: Download PDF + Share,
     matching report.php's UX (auto-slides up on mobile, brand
     gradient). Share opens a mini email lightbox that posts the
     quiz-report URL.
"""
# ── A) report.php logo swap ──
rp = '/var/www/transcribe/api/report.php'
s = open(rp).read()

# Remove the topbar-brand-logo oversize rule from v3.78
old_rule = """    /* 3) Double the topbar brand logo: 26 -> 52 */
    .topbar-brand-logo {
        height: 52px !important;
        max-width: 240px !important;
    }
    .topbar { padding: 6px 14px !important; }"""
new_rule = """    /* Topbar brand logo stays default size */
    /* Hero logo doubled on mobile (see jaihero-logo rule below) */"""
if 'Hero logo doubled on mobile' in s:
    print('A) topbar rule already reverted')
elif old_rule in s:
    s = s.replace(old_rule, new_rule, 1)
    print('A1) topbar-brand-logo reverted to default')
else:
    print('A1) WARN topbar oversize anchor not found')

# Add hero logo doubled on mobile via a new override block
hero_dbl = """
<style id="v381HeroLogoMobile">
@media (max-width: 768px) {
    #jaiHeroSection .jaihero-logo {
        height: 96px !important;
        max-width: 320px !important;
    }
}
</style>
"""
if 'id="v381HeroLogoMobile"' in s:
    print('A2) hero logo override already present')
else:
    close_body = s.rfind('</body>')
    s = s[:close_body] + hero_dbl + s[close_body:]
    print('A2) hero logo doubled on mobile (48 -> 96)')

open(rp, 'w').write(s)

# ── B) Quiz-report bottom bar ──
qr = '/var/www/transcribe/api/quiz-report.php'
q = open(qr).read()

quiz_block = r"""
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
@media (max-width: 768px) {
    .rp-bottom-bar { display: grid; }
}
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

    if (bar && window.matchMedia('(max-width: 768px)').matches) {
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
"""

if 'id="v381QrBottomBar"' in q:
    print('B) quiz-report bottom bar already present')
else:
    close_body = q.rfind('</body>')
    q = q[:close_body] + quiz_block + q[close_body:]
    open(qr, 'w').write(q)
    print('B) quiz-report bottom bar + share lightbox added')

print('DONE')
