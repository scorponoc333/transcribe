#!/usr/bin/env python3
"""v3.74 — Cost Breakdown table on mobile: make the colored dot sit
to the LEFT of the label as a proper flex column so the label text
can wrap cleanly onto two lines without the dot floating mid-text.
"""
idx = '/var/www/transcribe/index.html'
s = open(idx).read()

block = """
<style id="v374CostOpMobile">
@media (max-width: 768px) {
    /* Cost breakdown table — mobile only */
    #costBreakdownTable { overflow-x: visible !important; }
    #costBreakdownTable table { font-size: 12px !important; }

    /* Shrink column headers so the Operation column keeps some width */
    #costBreakdownTable thead th {
        padding: 6px 4px !important;
        font-size: 9px !important;
        letter-spacing: 0.3px !important;
    }
    #costBreakdownTable tbody td {
        padding: 8px 4px !important;
        font-size: 12px !important;
    }

    /* The Operation cell is the first td — flex with dot on the left,
       label flex:1 so it wraps cleanly below the dot column. */
    #costBreakdownTable tbody td:first-child {
        display: flex !important;
        align-items: flex-start !important;
        gap: 8px !important;
        min-width: 0 !important;
    }
    #costBreakdownTable tbody td:first-child > span:first-child {
        /* The dot */
        flex: 0 0 8px !important;
        margin: 6px 0 0 0 !important;
        vertical-align: unset !important;
    }
    #costBreakdownTable tbody td:first-child > span:last-child {
        /* The label */
        flex: 1 1 auto !important;
        min-width: 0 !important;
        word-break: break-word !important;
        line-height: 1.25 !important;
    }
}
</style>
"""
if 'id="v374CostOpMobile"' in s:
    print('already applied')
else:
    last = s.rfind('</body>')
    s = s[:last] + block + s[last:]
    s = s.replace('?v=2026041b4', '?v=2026041b5')
    open(idx, 'w').write(s)
    print('v3.74 cost-op mobile applied + cache bumped')
