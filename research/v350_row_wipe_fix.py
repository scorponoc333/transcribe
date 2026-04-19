#!/usr/bin/env python3
"""v3.50 — fix the broken row-wipe from v3.45.

Problem: v3.45 added a brand-tinted gradient highlight on
td:first-child::before that was supposed to sweep across the row.
But since the pseudo is absolute-positioned inside the first TD
(Date column) with inset:0, translateX(110%) lands it sitting over
the Title column and stays there because opacity stays at 1. Result:
a stuck vertical blue strip on every row.

User wants: each row's text reveals left-to-right, fast (~0.25s per
row), staggered. No gradient overlay at all. Just a clean wipe.

Fix:
1. Kill the ::before overlay (display:none).
2. Speed up the clip-path transition to 0.25s.
3. Keep the stagger at 70ms/row, applied to all list tables.
"""
idx_path = '/var/www/transcribe/index.html'
s = open(idx_path).read()

override = """
<style id="v350RowWipeFix">
@media screen {
/* ── v3.50 — override v3.45 row-wipe ──
   Remove the stuck gradient overlay entirely, and speed up the
   clip-path reveal so each row wipes in fast. Stagger stays. */
.v345-row {
    clip-path: inset(0 100% 0 0) !important;
    opacity: 0 !important;
    transition:
        clip-path 0.28s cubic-bezier(0.22, 1, 0.36, 1),
        opacity 0.2s ease !important;
    transition-delay: var(--v345-delay, 0ms) !important;
}
.v345-row.v345-assembled {
    clip-path: inset(0 0 0 0) !important;
    opacity: 1 !important;
}
/* Nuke the stuck gradient overlay from v3.45 */
.v345-row > td:first-child::before,
.v345-row.v345-assembled > td:first-child::before {
    display: none !important;
    content: none !important;
    background: none !important;
    transform: none !important;
    opacity: 0 !important;
    transition: none !important;
    animation: none !important;
}
@media (prefers-reduced-motion: reduce) {
    .v345-row { clip-path: none !important; opacity: 1 !important; }
}
}
</style>
"""

if 'id="v350RowWipeFix"' in s:
    print('v3.50 override already present')
else:
    last_body = s.rfind('</body>')
    s = s[:last_body] + override + s[last_body:]
    s = s.replace('?v=20260419x', '?v=20260419y')
    open(idx_path, 'w').write(s)
    print('v3.50 row-wipe fix applied + cache bumped')
