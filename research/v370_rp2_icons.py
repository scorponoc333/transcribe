#!/usr/bin/env python3
"""v3.70 — Report Pages mobile: icons alongside title, not above.

- Kill the gradient eye pill (and its stacked-above-title layout).
- Replace the anchor's fill with a subtle outlined eye that matches
  the existing envelope icon style (same border, tint, size).
- Put title + icons on the same row: title flex:1, can wrap up to
  two lines and then truncates.
"""
idx = '/var/www/transcribe/index.html'
s = open(idx).read()

block = """
<style id="v370Rp2Icons">
@media (max-width: 768px) {
    /* Rewire the row layout: horizontal, not stacked. */
    #rp2Table tbody tr {
        flex-direction: row !important;
        align-items: flex-start !important;
        gap: 10px !important;
    }
    /* Title reclaims first position and fills available space. */
    #rp2Table tbody tr > td:nth-child(2) {
        order: 1 !important;
        flex: 1 1 auto !important;
        min-width: 0 !important;
        display: -webkit-box !important;
        -webkit-box-orient: vertical !important;
        -webkit-line-clamp: 2;
        overflow: hidden;
    }
    /* Actions go to the right side. */
    #rp2Table tbody tr > td:nth-child(5) {
        order: 2 !important;
        flex: 0 0 auto !important;
        gap: 6px !important;
        align-items: center !important;
    }

    /* Kill the gradient pill + switch to outlined icon matching the mail btn */
    #rp2Table .rp2-open-btn {
        background: transparent !important;
        border: 1.5px solid rgba(var(--brand-500-rgb), 0.28) !important;
        color: var(--brand-500, #2557b3) !important;
        width: 40px !important;
        height: 40px !important;
        min-height: 40px !important;
        border-radius: 10px !important;
        padding: 0 !important;
        font-size: 0 !important;
        box-shadow: none !important;
    }
    #rp2Table .rp2-open-btn > svg { display: none !important; }
    #rp2Table .rp2-open-btn::before {
        content: '';
        display: block;
        width: 18px; height: 18px;
        background: currentColor;
        -webkit-mask: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z'/><circle cx='12' cy='12' r='3'/></svg>") center/contain no-repeat;
                mask: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z'/><circle cx='12' cy='12' r='3'/></svg>") center/contain no-repeat;
    }

    /* Make the envelope button on rp2 match the exact same size */
    #rp2Table .rp2-email-btn {
        width: 40px !important;
        height: 40px !important;
        min-height: 40px !important;
        padding: 0 !important;
        border-radius: 10px !important;
    }

    [data-theme="dark"] #rp2Table .rp2-open-btn {
        border-color: rgba(var(--brand-300-rgb, 147,197,253), 0.35) !important;
        color: var(--brand-300, #93c5fd) !important;
    }
}
</style>
"""

if 'id="v370Rp2Icons"' in s:
    print('v3.70 already present')
else:
    last = s.rfind('</body>')
    s = s[:last] + block + s[last:]
    s = s.replace('?v=2026041b0', '?v=2026041b1')
    open(idx, 'w').write(s)
    print('v3.70 rp2 icon alignment applied + cache bumped')
