#!/usr/bin/env python3
"""v3.59 — replace the constellation behind just the logo with a
full-header matrix-rain effect on mobile only. Subtle.
"""
idx = '/var/www/transcribe/index.html'
s = open(idx).read()

# 1) Unwrap the hdr-logo-wrap canvas — put logo back to sibling
old_wrap = """<div class="hdr-logo-wrap">
                    <canvas id="hdrConstellation" class="hdr-constellation" aria-hidden="true"></canvas>
                    <img id="headerLogo" src="img/logo.png" alt="Logo" class="header-logo">
                </div>"""
new_logo = """<img id="headerLogo" src="img/logo.png" alt="Logo" class="header-logo">"""
if 'hdr-logo-wrap' in s:
    s = s.replace(old_wrap, new_logo, 1)
    print('1) removed per-logo wrap (constellation)')
else:
    print('1) logo wrap already removed')

# 2) Inject a full-width canvas as FIRST child of <header class="header">
anchor = '<header class="header">'
inject = """<header class="header">
        <canvas id="hdrMatrix" class="hdr-matrix" aria-hidden="true"></canvas>"""
if 'id="hdrMatrix"' in s:
    print('2) hdrMatrix canvas already present')
elif anchor in s:
    s = s.replace(anchor, inject, 1)
    print('2) hdrMatrix canvas injected into header')
else:
    print('2) WARN header anchor not found')

# 3) Replace the old constellation <style>+<script> block with the new
#    matrix-rain one. Strip v356bHeaderStars entirely.
import re
old_bh_style = re.search(r'<style id="v356bHeaderStars">.*?</style>\s*<script>.*?header constellation.*?\}\)\(\);\s*</script>',
                         s, flags=re.DOTALL)
if old_bh_style:
    s = s.replace(old_bh_style.group(0), '', 1)
    print('3) v3.56b constellation block removed')
else:
    print('3) v3.56b constellation already removed')

block = """
<style id="v359HeaderMatrix">
.hdr-matrix { display: none; }
@media (max-width: 768px) {
    .header {
        position: relative;
        overflow: hidden;
    }
    .hdr-matrix {
        display: block;
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
        z-index: 0;
        opacity: 0.28;
    }
    .header > nav,
    .header > .mx-auto {
        position: relative;
        z-index: 1;
    }
}
</style>
<script>
/* v3.59 — subtle matrix rain across the mobile header, behind logo + menu */
(function () {
    if (!window.matchMedia('(max-width: 768px)').matches) return;
    const cvs = document.getElementById('hdrMatrix');
    if (!cvs) return;
    const ctx = cvs.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const GLYPHS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789ｱｲｳｴｵｶｷｸｹｺｻｼｽｾｿﾀﾁﾂﾃﾄﾅﾆﾇﾈﾉ<>/';
    let cols = 0, drops = [], cellW = 12 * dpr, cellH = 16 * dpr;
    let W = 0, H = 0;

    function resize() {
        const rect = cvs.getBoundingClientRect();
        W = Math.max(1, Math.floor(rect.width  * dpr));
        H = Math.max(1, Math.floor(rect.height * dpr));
        cvs.width = W;
        cvs.height = H;
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
        // translucent overlay for trail fade
        ctx.fillStyle = 'rgba(0, 8, 24, 0.18)';
        ctx.fillRect(0, 0, W, H);

        ctx.font = 'bold ' + (13 * dpr) + "px 'Courier New', monospace";
        ctx.textBaseline = 'top';

        for (let i = 0; i < cols; i++) {
            const ch = GLYPHS[(Math.random() * GLYPHS.length) | 0];
            const x = i * cellW;
            const y = drops[i];
            // Leading (brightest) glyph
            ctx.fillStyle = 'rgba(220, 235, 255, 0.95)';
            ctx.fillText(ch, x, y);
            // Drop + reset
            drops[i] += cellH * 0.55;
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
if 'id="v359HeaderMatrix"' in s:
    print('4) matrix css/js already present')
else:
    last = s.rfind('</body>')
    s = s[:last] + block + s[last:]
    s = s.replace('?v=2026041a5', '?v=2026041a6')
    print('4) matrix css/js appended + cache bumped')

open(idx, 'w').write(s)
print('DONE')
