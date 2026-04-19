#!/usr/bin/env python3
"""v3.56b — fix the Share lightbox send flow + add constellation
animation behind the index.html header logo on mobile only.

1) rpShareSend was posting { message } which send-smtp.php ignores.
   Correct path: flip is_public=1 on the transcription first, then
   POST html (with {{REPORT_URL}} placeholder) + to + subject +
   from + transcription_id to /api/send-smtp.php. Server substitutes
   the public link for {{REPORT_URL}}.

2) Read senderName / senderEmail out of localStorage (same store
   the main app uses) to set the From header.

3) Mobile-only: subtle white-dot constellation canvas behind the
   header logo in index.html. Not on desktop.
"""
# ═══════════════════════════════════════════════════════════════
# report.php — rewrite rpShareSend
# ═══════════════════════════════════════════════════════════════
rp = '/var/www/transcribe/api/report.php'
s = open(rp).read()

old_send = """    window.rpShareSend = async function () {
        const to = document.getElementById('rpEmailTo')?.value.trim();
        const subject = document.getElementById('rpEmailSubject')?.value.trim();
        const message = document.getElementById('rpEmailMsg')?.value.trim();
        const btn = document.getElementById('rpEmailSendBtn');
        if (!to) { alert('Please enter a recipient email.'); return; }
        if (btn) { btn.disabled = true; btn.textContent = 'Sending...'; }
        try {
            const body = {
                transcription_id: <?= (int)$row['id'] ?>,
                to, subject,
                message: message || '',
                include_pdf: true
            };
            const r = await fetch('/api/send-smtp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(body)
            });
            const d = await r.json().catch(() => ({}));
            if (!r.ok || d.error) throw new Error(d.error || ('HTTP ' + r.status));
            rpShareClose();
            if (typeof jaiToast === 'function') jaiToast('Email sent!', { kind: 'success' });
            else alert('Email sent.');
        } catch (err) {
            if (typeof jaiToast === 'function') jaiToast(err.message || 'Send failed', { kind: 'error' });
            else alert('Send failed: ' + err.message);
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = 'Send'; }
        }
    };"""

new_send = r"""    window.rpShareSend = async function () {
        const to = document.getElementById('rpEmailTo')?.value.trim();
        const subject = document.getElementById('rpEmailSubject')?.value.trim();
        const note = document.getElementById('rpEmailMsg')?.value.trim();
        const btn = document.getElementById('rpEmailSendBtn');
        if (!to) { alert('Please enter a recipient email.'); return; }
        if (btn) { btn.disabled = true; btn.textContent = 'Sending...'; }

        // Pull the sender identity out of localStorage (same key the main
        // app writes — `transcribe_settings` as a JSON blob).
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
        const fromHeader = senderName + ' <' + senderEmail + '>';

        const reportTitle  = <?= json_encode($title) ?>;
        const reportTypeLabel = <?= json_encode($modeInfo['label']) ?>;
        const transcriptionId = <?= (int)$row['id'] ?>;

        // Tiny branded HTML body with a {{REPORT_URL}} placeholder that
        // send-smtp.php replaces with the public / signed link.
        const safeNote = (note || '').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
        const html = `<!doctype html><html><body style="margin:0;padding:0;background:#f5f8fc;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
<table role="presentation" width="100%" style="background:#f5f8fc;padding:32px 12px;">
  <tr><td align="center">
    <table role="presentation" width="560" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 6px 24px rgba(15,31,64,0.08);">
      <tr><td style="background:linear-gradient(135deg,#2557b3,#1a3a7a);padding:28px 24px;color:#fff;">
        <div style="font-size:11px;letter-spacing:2px;text-transform:uppercase;opacity:0.8;">${reportTypeLabel}</div>
        <div style="font-size:22px;font-weight:800;margin-top:6px;">${reportTitle}</div>
      </td></tr>
      <tr><td style="padding:24px;color:#0f172a;font-size:15px;line-height:1.55;">
        ${safeNote ? '<p style="margin:0 0 18px;">' + safeNote + '</p>' : '<p style="margin:0 0 18px;">I thought you\\'d find this report useful.</p>'}
        <p style="margin:0 0 24px;">Click the button below to open the full report with charts, transcript, and key concepts.</p>
        <a href="{{REPORT_URL}}" style="display:inline-block;background:linear-gradient(135deg,#2557b3,#1a3a7a);color:#fff;font-weight:700;text-decoration:none;padding:14px 26px;border-radius:10px;">View Full Report</a>
      </td></tr>
      <tr><td style="padding:18px 24px;background:#f8fafc;color:#64748b;font-size:12px;text-align:center;">
        Report created by <a href="https://jasonai.ca" style="color:#2557b3;text-decoration:none;font-weight:600;">Jason AI</a>
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>`;

        try {
            // Emailing == sharing — flip is_public=1 so the public link works.
            try {
                const fd = new FormData();
                fd.append('id', transcriptionId);
                fd.append('public', '1');
                await fetch('/api/transcription-share.php', {
                    method: 'POST', body: fd, credentials: 'same-origin'
                });
            } catch (e) { console.warn('share toggle failed (non-fatal)', e); }

            const r = await fetch('/api/send-smtp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    transcription_id: transcriptionId,
                    from: senderEmail,
                    from_name: senderName,
                    to: to,
                    subject: subject || ('Transcription | ' + reportTitle),
                    html: html
                })
            });
            const d = await r.json().catch(() => ({}));
            if (!r.ok || d.error) throw new Error(d.error || ('HTTP ' + r.status));
            rpShareClose();
            if (typeof jaiToast === 'function') jaiToast('Email sent!', { kind: 'success' });
            else alert('Email sent.');
        } catch (err) {
            if (typeof jaiToast === 'function') jaiToast(err.message || 'Send failed', { kind: 'error' });
            else alert('Send failed: ' + err.message);
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = 'Send'; }
        }
    };"""
if "fromHeader = senderName + ' <' + senderEmail" in s:
    print('rp share-send already fixed')
elif old_send in s:
    s = s.replace(old_send, new_send, 1)
    open(rp, 'w').write(s)
    print('rp share-send rewritten (proper payload + public flip)')
else:
    print('WARN rp share-send anchor not found')

# ═══════════════════════════════════════════════════════════════
# index.html — constellation canvas behind the header logo (mobile)
# ═══════════════════════════════════════════════════════════════
idx = '/var/www/transcribe/index.html'
i = open(idx).read()

# Inject a canvas sibling to the header logo (in the brand block)
anchor_img = '<img id="headerLogo" src="img/logo.png" alt="Logo" class="header-logo">'
inject_canvas = """<div class="hdr-logo-wrap">
                    <canvas id="hdrConstellation" class="hdr-constellation" aria-hidden="true"></canvas>
                    <img id="headerLogo" src="img/logo.png" alt="Logo" class="header-logo">
                </div>"""
if 'id="hdrConstellation"' in i:
    print('header constellation already present')
elif anchor_img in i:
    i = i.replace(anchor_img, inject_canvas, 1)
    print('header logo wrapped + constellation canvas added')
else:
    print('WARN header logo anchor not found')

block = """
<style id="v356bHeaderStars">
/* Wrapper (desktop = plain container, mobile = has constellation) */
.hdr-logo-wrap { position: relative; display: inline-flex; align-items: center; }
.hdr-constellation { display: none; }
@media (max-width: 768px) {
    .hdr-constellation {
        display: block;
        position: absolute;
        left: -16px; right: -16px;
        top: 50%;
        transform: translateY(-50%);
        width: calc(100% + 32px);
        height: 48px;
        pointer-events: none;
        opacity: 0.45;
        z-index: 0;
    }
    .header-logo { position: relative; z-index: 1; }
}
</style>
<script>
/* v3.56b — mobile header constellation. Subtle, looped. */
(function () {
    if (!window.matchMedia('(max-width: 768px)').matches) return;
    const cvs = document.getElementById('hdrConstellation');
    if (!cvs) return;
    const dpr = window.devicePixelRatio || 1;
    function resize() {
        const rect = cvs.getBoundingClientRect();
        cvs.width  = Math.max(1, Math.floor(rect.width  * dpr));
        cvs.height = Math.max(1, Math.floor(rect.height * dpr));
    }
    resize();
    window.addEventListener('resize', resize);
    const ctx = cvs.getContext('2d');

    // Precompute node positions
    const NODES = 18;
    const nodes = [];
    function seed() {
        nodes.length = 0;
        for (let i = 0; i < NODES; i++) {
            nodes.push({
                x: Math.random() * cvs.width,
                y: Math.random() * cvs.height,
                vx: (Math.random() - 0.5) * 0.18 * dpr,
                vy: (Math.random() - 0.5) * 0.12 * dpr,
                r: (Math.random() * 1.2 + 0.5) * dpr,
            });
        }
    }
    seed();
    window.addEventListener('resize', seed);

    let running = true;
    document.addEventListener('visibilitychange', () => { running = !document.hidden; if (running) tick(); });

    function tick() {
        if (!running) return;
        ctx.clearRect(0, 0, cvs.width, cvs.height);
        for (const n of nodes) {
            n.x += n.vx; n.y += n.vy;
            if (n.x < 0 || n.x > cvs.width)  n.vx *= -1;
            if (n.y < 0 || n.y > cvs.height) n.vy *= -1;
        }
        // Edges
        ctx.lineWidth = 0.5 * dpr;
        for (let i = 0; i < nodes.length; i++) {
            for (let j = i + 1; j < nodes.length; j++) {
                const a = nodes[i], b = nodes[j];
                const dx = a.x - b.x, dy = a.y - b.y;
                const d = Math.sqrt(dx*dx + dy*dy);
                const maxD = 70 * dpr;
                if (d < maxD) {
                    const alpha = (1 - d / maxD) * 0.55;
                    ctx.strokeStyle = 'rgba(255,255,255,' + alpha.toFixed(3) + ')';
                    ctx.beginPath();
                    ctx.moveTo(a.x, a.y);
                    ctx.lineTo(b.x, b.y);
                    ctx.stroke();
                }
            }
        }
        // Nodes
        for (const n of nodes) {
            ctx.fillStyle = 'rgba(255,255,255,0.85)';
            ctx.beginPath();
            ctx.arc(n.x, n.y, n.r, 0, Math.PI * 2);
            ctx.fill();
        }
        requestAnimationFrame(tick);
    }
    tick();
})();
</script>
"""
if 'id="v356bHeaderStars"' in i:
    print('header stars css/js already present')
else:
    last = i.rfind('</body>')
    i = i[:last] + block + i[last:]
    i = i.replace('?v=2026041a4', '?v=2026041a5')
    print('header stars css/js appended + cache bumped')
open(idx, 'w').write(i)
print('DONE')
