#!/usr/bin/env python3
"""v3.49 — mobile off-canvas + drop-tile polish.

A) Kill the pulsing green dot at the bottom of the off-canvas menu.
   .offcanvas-foot::before creates an 8px pulsating #10b981 circle
   that sits right next to the Sign Out button and looks glitchy.
   Replace the rule with display:none so nothing renders.

B) Shrink the off-canvas Jason AI logo by 30%.
   Current height: 56px -> 39px (56 * 0.7 rounded).

C) Mobile-only: when the user touches/hovers the #dropZone upload
   tile, outline it with a 3px brand-color border and add a soft
   brand-color glow that pulsates while the touch/hover is held. As
   soon as the touch/hover ends, it fades away.
"""
css_path = '/var/www/transcribe/css/style.css'
s = open(css_path).read()

# ── A) Remove the green pulse dot ──
old_green = """.offcanvas-foot::before {
    content: '';
    width: 8px; height: 8px;
    border-radius: 50%;
    background: #10b981;
    box-shadow: 0 0 10px #10b981;
    margin-right: auto;
    margin-left: 0;
    animation: jai-pulse-dot 2s ease-in-out infinite;
}"""
new_green = """.offcanvas-foot::before {
    /* v3.49 — pulsating green dot was overlapping Sign Out; hidden. */
    display: none !important;
}"""
if 'v3.49 — pulsating green dot was overlapping' in s:
    print('A) green dot already suppressed')
elif old_green in s:
    s = s.replace(old_green, new_green, 1)
    print('A) green pulse dot suppressed')
else:
    print('A) WARN anchor not found — appending override')
    s += """\n/* v3.49 A - kill the offcanvas green pulse dot */\n.offcanvas-foot::before { display: none !important; }\n"""

# ── B) Shrink offcanvas logo 56 -> 39 ──
old_logo = """.offcanvas-logo {
    height: 56px;"""
new_logo = """.offcanvas-logo {
    height: 39px;"""
if '.offcanvas-logo {\n    height: 39px;' in s:
    print('B) offcanvas logo already 39px')
elif old_logo in s:
    s = s.replace(old_logo, new_logo, 1)
    print('B) offcanvas logo shrunk 56 -> 39')
else:
    print('B) WARN offcanvas-logo anchor not found')

# ── C) Mobile-only drop-tile glow ──
glow_block = """

/* ═══════════════════════════════════════════════════════════════
   v3.49 — Mobile-only drop-tile touch/hover glow
   3px brand border + pulsating outer glow while pressed/hovered
   ═══════════════════════════════════════════════════════════════ */
@media (max-width: 768px) {
    #dropZone {
        position: relative;
        transition: border-color 0.25s ease, box-shadow 0.3s ease;
    }
    /* Hover / press state */
    #dropZone:hover,
    #dropZone:active,
    #dropZone.is-touching {
        border: 3px solid var(--brand-500, #2557b3) !important;
        box-shadow:
            0 0 0 3px rgba(var(--brand-500-rgb), 0.18),
            0 0 24px 6px rgba(var(--brand-500-rgb), 0.42),
            0 0 48px 12px rgba(var(--brand-400-rgb), 0.28) !important;
        animation: v349DropPulse 1.6s ease-in-out infinite;
    }
    @keyframes v349DropPulse {
        0%, 100% {
            box-shadow:
                0 0 0 3px rgba(var(--brand-500-rgb), 0.18),
                0 0 20px 4px rgba(var(--brand-500-rgb), 0.35),
                0 0 40px 10px rgba(var(--brand-400-rgb), 0.22);
        }
        50% {
            box-shadow:
                0 0 0 3px rgba(var(--brand-500-rgb), 0.28),
                0 0 32px 10px rgba(var(--brand-500-rgb), 0.55),
                0 0 64px 18px rgba(var(--brand-400-rgb), 0.38);
        }
    }
}
"""
if 'v3.49 — Mobile-only drop-tile touch/hover glow' in s:
    print('C) drop-tile glow already present')
else:
    s += glow_block
    print('C) drop-tile mobile glow appended')

open(css_path, 'w').write(s)

# ── C') Tiny JS in index.html to add .is-touching during touchstart ──
# Touchstart/touchend feels more reliable than :hover on mobile.
idx_path = '/var/www/transcribe/index.html'
i = open(idx_path).read()
touch_script = """
<script id="v349DropTouch">
/* v3.49 — reliable touch feedback on #dropZone for mobile */
(function () {
    function attach() {
        const dz = document.getElementById('dropZone');
        if (!dz) return;
        const on = () => dz.classList.add('is-touching');
        const off = () => dz.classList.remove('is-touching');
        dz.addEventListener('touchstart', on, { passive: true });
        dz.addEventListener('touchend', off, { passive: true });
        dz.addEventListener('touchcancel', off, { passive: true });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attach);
    } else attach();
})();
</script>
"""
if 'v349DropTouch' in i:
    print("C') touch script already present")
else:
    last_body = i.rfind('</body>')
    i = i[:last_body] + touch_script + i[last_body:]
    # Cache bump for css + html
    i = i.replace('?v=20260419w', '?v=20260419x')
    open(idx_path, 'w').write(i)
    print("C') touch script added + cache bumped")

print('DONE')
