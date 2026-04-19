#!/usr/bin/env python3
"""v3.77 — Report Pages mobile: clean grid layout.

Per row:
  [ title                       ][ eye mail ]
  [ date                        ][  (span)  ]
  ------- single hr --------

- Icons anchored to the right column, spanning both text rows.
- Title + date stack in the left column; title wraps freely.
- Only ONE horizontal rule per row (5px pad before + after).
"""
idx = '/var/www/transcribe/index.html'
s = open(idx).read()

block = """
<style id="v377Rp2Grid">
@media (max-width: 768px) {
    /* Remove prior row gap + border strategies */
    #rp2Table tbody tr {
        display: grid !important;
        grid-template-columns: 1fr auto !important;
        grid-template-areas:
            "title actions"
            "date  actions" !important;
        column-gap: 10px !important;
        row-gap: 2px !important;
        padding: 5px 12px 5px !important;
        border-bottom: 1px solid rgba(var(--primary-rgb), 0.10) !important;
        flex-direction: initial !important;
        flex-wrap: initial !important;
    }
    /* No cell borders inherited from history-table td */
    #rp2Table tbody tr > td,
    #rp2Table tbody td { border: 0 !important; }

    /* Title (2nd td) */
    #rp2Table tbody tr > td:nth-child(2) {
        grid-area: title !important;
        order: initial !important;
        flex: initial !important;
        -webkit-line-clamp: initial !important;
        display: block !important;
        overflow: visible !important;
        padding: 0 !important;
        font-size: 15px !important;
        font-weight: 700 !important;
        line-height: 1.3 !important;
        color: var(--fg-heading);
        word-break: break-word !important;
    }

    /* Date (1st td) under title */
    #rp2Table tbody tr > td:nth-child(1) {
        grid-area: date !important;
        order: initial !important;
        flex: initial !important;
        display: block !important;
        padding: 0 !important;
        font-size: 11px !important;
        color: var(--ink-muted, #8a94a7) !important;
        letter-spacing: 0.3px !important;
    }

    /* Actions spanning both text rows on the right */
    #rp2Table tbody tr > td:nth-child(5) {
        grid-area: actions !important;
        align-self: center !important;
        order: initial !important;
        flex: initial !important;
        display: flex !important;
        gap: 6px !important;
        padding: 0 !important;
    }

    /* Keep the loading / empty row spanning the full grid */
    #rp2Table tbody tr > td[colspan] {
        grid-column: 1 / -1 !important;
        text-align: center !important;
    }
}
</style>
"""
if 'id="v377Rp2Grid"' in s:
    print('already applied')
else:
    last = s.rfind('</body>')
    s = s[:last] + block + s[last:]
    s = s.replace('?v=2026041b7', '?v=2026041b8')
    open(idx, 'w').write(s)
    print('v3.77 grid layout applied + cache bumped')
