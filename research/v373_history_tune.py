#!/usr/bin/env python3
"""v3.73 — History mobile polish: shrink title + icons 25%, and
show the date/time between them in muted gray.
"""
idx = '/var/www/transcribe/index.html'
s = open(idx).read()

block = """
<style id="v373HistoryTune">
@media (max-width: 768px) {
    /* -25% title: 22 -> 16 */
    #historyTable tbody tr > td.cell-title {
        font-size: 16px !important;
        line-height: 1.3 !important;
    }
    /* -25% icons: 28 -> 21 frame, 12 -> 9 svg */
    #historyTable td.cell-actions .btn-icon,
    #historyTable td.cell-actions a.btn-icon[data-open-report="1"] {
        min-width: 21px !important;
        min-height: 21px !important;
        width: 21px !important;
        height: 21px !important;
        padding: 3px !important;
        border-radius: 6px !important;
    }
    #historyTable td.cell-actions .btn-icon svg {
        width: 9px !important;
        height: 9px !important;
    }
    #historyTable td.cell-actions a.btn-icon[data-open-report="1"]::before {
        width: 11px !important;
        height: 11px !important;
    }
    /* Show the Date/Time cell between title and actions (first td) */
    #historyTable tbody tr > td:first-child {
        display: block !important;
        order: 2 !important;
        padding: 0 !important;
        font-size: 11px !important;
        color: var(--ink-muted, #8a94a7) !important;
        letter-spacing: 0.3px;
        text-transform: none;
    }
    #historyTable tbody tr > td:first-child .cell-date,
    #historyTable tbody tr > td:first-child .cell-time {
        display: inline;
        font-weight: 500;
    }
    #historyTable tbody tr > td:first-child .cell-date::after {
        content: ' · ';
        opacity: 0.55;
    }
    /* Keep actions last, with smaller gap to date */
    #historyTable tbody tr > td.cell-actions {
        order: 3 !important;
        gap: 6px !important;
        margin-top: 2px !important;
    }
    #historyTable tbody tr {
        gap: 4px !important;
    }
}
</style>
"""
if 'id="v373HistoryTune"' in s:
    print('already applied')
else:
    last = s.rfind('</body>')
    s = s[:last] + block + s[last:]
    s = s.replace('?v=2026041b3', '?v=2026041b4')
    open(idx, 'w').write(s)
    print('v3.73 applied + cache bumped')
