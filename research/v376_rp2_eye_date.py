#!/usr/bin/env python3
"""v3.76 — Report Pages mobile:
  * Fix the eye button: it was blank (CSS mask trick didn't paint).
    Match the envelope button's exact look (gray bg, brand-tinted
    border, brand-colored icon) but with an eye glyph painted via
    background-image data URI.
  * Show the date cell underneath the title.
"""
idx = '/var/www/transcribe/index.html'
s = open(idx).read()

block = """
<style id="v376Rp2EyeDate">
@media (max-width: 768px) {
    /* Eye button: fully restyle to mirror the envelope btn look.
       (The anchor uses .btn-primary on desktop — we kill that on mobile.) */
    #rp2Table .rp2-open-btn {
        background: var(--btn-icon-bg, rgba(var(--brand-500-rgb), 0.06)) !important;
        border: 1px solid var(--btn-icon-border, rgba(var(--brand-500-rgb), 0.28)) !important;
        color: var(--brand-500, #2557b3) !important;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%232557b3' stroke-width='2.1' stroke-linecap='round' stroke-linejoin='round'><path d='M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z'/><circle cx='12' cy='12' r='3'/></svg>") !important;
        background-repeat: no-repeat !important;
        background-position: center !important;
        background-size: 18px 18px !important;
        width: 40px !important;
        height: 40px !important;
        min-height: 40px !important;
        border-radius: 10px !important;
        padding: 0 !important;
        font-size: 0 !important;
        box-shadow: none !important;
    }
    #rp2Table .rp2-open-btn::before { content: none !important; }
    #rp2Table .rp2-open-btn > svg { display: none !important; }
    #rp2Table .rp2-open-btn:hover {
        background-color: var(--btn-icon-hover, rgba(var(--brand-500-rgb), 0.12)) !important;
        border-color: var(--brand-500, #2557b3) !important;
        transform: translateY(-1px);
    }

    /* Dark-mode variants — tint the icon light */
    [data-theme="dark"] #rp2Table .rp2-open-btn {
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2393c5fd' stroke-width='2.1' stroke-linecap='round' stroke-linejoin='round'><path d='M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z'/><circle cx='12' cy='12' r='3'/></svg>") !important;
        border-color: rgba(var(--brand-300-rgb, 147,197,253), 0.35) !important;
    }

    /* Show the date cell (first td) below title */
    #rp2Table tbody tr > td:nth-child(1) {
        display: block !important;
        order: 3 !important;
        flex: 1 1 100% !important;
        padding: 0 !important;
        font-size: 11px !important;
        color: var(--ink-muted, #8a94a7) !important;
        letter-spacing: 0.3px;
    }
    /* Put the actions on row 1 right-side of the title; date below as its own row */
    #rp2Table tbody tr {
        flex-wrap: wrap !important;
        row-gap: 2px !important;
    }
    #rp2Table tbody tr > td:nth-child(2) { flex: 1 1 auto !important; order: 1 !important; }
    #rp2Table tbody tr > td:nth-child(5) { order: 2 !important; }
}
</style>
"""
if 'id="v376Rp2EyeDate"' in s:
    print('already applied')
else:
    last = s.rfind('</body>')
    s = s[:last] + block + s[last:]
    s = s.replace('?v=2026041b6', '?v=2026041b7')
    open(idx, 'w').write(s)
    print('v3.76 applied + cache bumped')
