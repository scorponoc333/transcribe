#!/usr/bin/env python3
"""v3.71 — History mobile: title above icons, title 50% bigger,
icons 50% smaller. (Report Pages is unaffected.)
"""
idx = '/var/www/transcribe/index.html'
s = open(idx).read()

block = """
<style id="v371HistoryMobile">
@media (max-width: 768px) {
    /* Title -> top, Actions -> bottom (reverse the v3.65 order) */
    #historyTable tbody tr > td.cell-title { order: 1 !important; }
    #historyTable tbody tr > td.cell-actions { order: 2 !important; }

    /* Title 50% larger (15 -> 22) */
    #historyTable tbody tr > td.cell-title {
        font-size: 22px !important;
        line-height: 1.3 !important;
    }

    /* Icons ~50% smaller: 44 -> 28 frame, svg 15 -> 12 */
    #historyTable td.cell-actions .btn-icon {
        min-width: 28px !important;
        min-height: 28px !important;
        width: 28px !important;
        height: 28px !important;
        padding: 4px !important;
        border-radius: 8px !important;
    }
    #historyTable td.cell-actions .btn-icon svg {
        width: 12px !important;
        height: 12px !important;
    }
    /* And the eye (Open Full Report) button matches the new size */
    #historyTable td.cell-actions a.btn-icon[data-open-report="1"] {
        width: 28px !important;
        min-height: 28px !important;
        border-radius: 8px !important;
    }
    #historyTable td.cell-actions a.btn-icon[data-open-report="1"]::before {
        width: 14px !important; height: 14px !important;
    }
}
</style>
"""
if 'id="v371HistoryMobile"' in s:
    print('v3.71 already present')
else:
    last = s.rfind('</body>')
    s = s[:last] + block + s[last:]
    s = s.replace('?v=2026041b1', '?v=2026041b2')
    open(idx, 'w').write(s)
    print('v3.71 history mobile applied + cache bumped')
