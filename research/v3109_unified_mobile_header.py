#!/usr/bin/env python3
"""v3.109 — unify the mobile header across index / report / quiz-report.

Per Jason: report.php and quiz-report.php on mobile must look
identical to the transcribe page — same matrix-rain canvas behind
the header, same logo size, same hamburger. Off-canvas was already
unified in v3.89.

Changes:
  * Inject a matrix canvas + CSS + JS into report.php's .topbar
    and quiz-report.php's .topbar. Mobile-only, 14% opacity,
    0.11 cells/frame (matches index's tuned values).
  * Force mobile .topbar-brand-logo / .topbar-logo to 22px to
    match index.html's mobile header-logo.
  * Hero on report.php mobile: bump logo 88 -> 110 (+25%), badge
    margin-top -70 -> +15 so there's a real 15px gap.
"""
import re

# --- Matrix block (injects into a .topbar) ---
MATRIX_BLOCK = r"""
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
        max-width: 90px !important;
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
"""

# --- report.php ---
p = '/var/www/transcribe/api/report.php'
s = open(p).read()

if 'id="v3109MatrixHeader"' not in s:
    cb = s.rfind('</body>')
    s = s[:cb] + MATRIX_BLOCK + s[cb:]
    print('report.php: matrix header injected')
else:
    print('report.php: matrix header already present')

# Hero mobile logo: 88 -> 110 (+25%), max-width 260 -> 325
s = s.replace(
    'height: 88px !important;\n        max-width: 260px !important;',
    'height: 110px !important;\n        max-width: 325px !important;'
)
print('report.php: mobile hero logo 88 -> 110 (+25%)')

# Mobile badge: margin-top -70 -> 15 (real gap, no overlap)
s = s.replace(
    'margin-top: -70px !important;',
    'margin-top: 15px !important;'
)
print('report.php: mobile hero badge margin-top -70 -> +15')

open(p, 'w').write(s)

# --- quiz-report.php ---
q_path = '/var/www/transcribe/api/quiz-report.php'
q = open(q_path).read()
if 'id="v3109MatrixHeader"' not in q:
    cb = q.rfind('</body>')
    q = q[:cb] + MATRIX_BLOCK + q[cb:]
    print('quiz-report.php: matrix header injected')
else:
    print('quiz-report.php: already present')
open(q_path, 'w').write(q)
print('DONE')
