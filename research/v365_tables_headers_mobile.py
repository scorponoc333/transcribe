#!/usr/bin/env python3
"""v3.65 — mobile table + page-header overhaul.

A) Mobile: turn Transcription History and Report Pages tables into
   stacked cards. Only Title + Actions show; actions sit ABOVE the
   title so long titles never collide with icons.

B) Mobile: replace "Open Report" text+arrow with an eye icon inside
   the gradient pill (on both tables).

C) Mobile: center all .page-header-bar headings. Stack heading on
   top, "Back to Transcribe" button centered below. Shrinks the
   gap under the app header by half.
"""
idx = '/var/www/transcribe/index.html'
css = '/var/www/transcribe/css/style.css'
s = open(idx).read()

block = r"""
<style id="v365MobileTables">
/* ═══════ v3.65 ═══════ */
@media (max-width: 768px) {
    /* A — Transcription History + Report Pages as stacked cards */
    #historyTable,
    #rp2Table {
        display: block;
        width: 100%;
        border-collapse: collapse;
    }
    #historyTable thead,
    #rp2Table thead { display: none; }
    #historyTable tbody,
    #rp2Table tbody { display: block; width: 100%; }

    #historyTable tbody tr,
    #rp2Table tbody tr {
        display: flex;
        flex-direction: column;
        gap: 6px;
        padding: 14px 12px;
        border-bottom: 1px solid rgba(var(--primary-rgb), 0.08);
    }
    #historyTable tbody tr > td,
    #rp2Table tbody tr > td { display: none; }

    /* Show the Title cell (rp2: 2nd child, history: .cell-title) */
    #rp2Table tbody tr > td:nth-child(2),
    #historyTable tbody tr > td.cell-title {
        display: block;
        order: 2;
        font-size: 15px;
        font-weight: 700;
        line-height: 1.35;
        color: var(--fg-heading);
        padding: 0;
    }
    #historyTable tbody tr > td.cell-title a { color: inherit; }

    /* Show the Actions cell ABOVE the title */
    #rp2Table tbody tr > td:nth-child(5),
    #historyTable tbody tr > td.cell-actions {
        display: flex;
        order: 1;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
        padding: 0;
    }

    /* Loading / empty placeholder rows span the full row */
    #historyTable tbody tr > td[colspan],
    #rp2Table tbody tr > td[colspan] { display: block; order: 0; text-align: center; }

    /* B — Replace "Open Report" text+arrow with eye icon (gradient pill) */
    #rp2Table .rp2-open-btn {
        font-size: 0 !important;
        padding: 0 !important;
        width: 44px !important;
        height: 44px !important;
        min-height: 44px !important;
        border-radius: 12px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
    }
    #rp2Table .rp2-open-btn > svg { display: none !important; }
    #rp2Table .rp2-open-btn::before {
        content: '';
        display: block;
        width: 22px; height: 22px;
        background: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'><path d='M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z'/><circle cx='12' cy='12' r='3'/></svg>") center/contain no-repeat;
    }
    /* Same eye treatment for history Open Full Report icon button */
    #historyTable td.cell-actions a.btn-icon[data-open-report="1"] {
        background: linear-gradient(135deg, var(--brand-500, #2557b3), var(--brand-700, #1a3a7a)) !important;
        border-color: transparent !important;
        width: 44px !important;
        min-height: 44px !important;
        border-radius: 12px !important;
    }
    #historyTable td.cell-actions a.btn-icon[data-open-report="1"] > svg { display: none !important; }
    #historyTable td.cell-actions a.btn-icon[data-open-report="1"]::before {
        content: '';
        display: block;
        width: 20px; height: 20px;
        background: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'><path d='M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z'/><circle cx='12' cy='12' r='3'/></svg>") center/contain no-repeat;
    }

    /* Action buttons on history table: keep 44px tap targets */
    #historyTable td.cell-actions .btn-icon {
        min-width: 44px;
        min-height: 44px;
    }

    /* C — Center page-header-bar tiles + stack back button below */
    .page-header-bar {
        flex-direction: column !important;
        align-items: center !important;
        text-align: center !important;
        gap: 10px !important;
    }
    .page-header-bar h1,
    .page-header-bar h2 {
        text-align: center !important;
        width: 100% !important;
    }
    .page-header-bar .btn-secondary,
    .page-header-bar .btn-icon {
        margin: 0 auto !important;
    }

    /* Halve the gap above the first tile on every page */
    main { padding-top: 30px !important; }
    /* Keep the hero lift on uploadSection but adjust to the new padding */
    #uploadSection .typewriter-hero {
        padding-top: 4px !important;
        margin-top: 0 !important;
    }
}
</style>
"""

if 'id="v365MobileTables"' in s:
    print('v3.65 already present')
else:
    last_body = s.rfind('</body>')
    s = s[:last_body] + block + s[last_body:]
    s = s.replace('?v=2026041ab', '?v=2026041ac')
    open(idx, 'w').write(s)
    print('v3.65 mobile tables + page headers appended + cache bumped')

# Drop the earlier hardcoded `main { padding: 60px 16px 24px !important }` so
# our new 30px rule isn't overridden by it sitting later in the cascade.
c = open(css).read()
old = 'main { padding: 60px 16px 24px !important; }'
new = 'main { padding: 16px !important; }'  # let our v3.65 rule handle top
if old in c:
    c = c.replace(old, new, 1)
    open(css, 'w').write(c)
    print('css: decoupled main vertical padding from v3.62')
else:
    print('css: padding anchor not found (already adjusted?)')
print('DONE')
