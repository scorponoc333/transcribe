#!/usr/bin/env python3
"""v3.51 — halve the "Share a Report" button width+height, keep font.
Was 100% width, 125px tall -> 50% width (centered), 63px tall.
"""
idx_path = '/var/www/transcribe/index.html'
s = open(idx_path).read()

override = """
<style id="v351ShareBtnHalf">
.rs-share-btn {
    width: 50% !important;
    height: 63px !important;
    margin-left: auto !important;
    margin-right: auto !important;
}
</style>
"""
if 'id="v351ShareBtnHalf"' in s:
    print('already applied')
else:
    last_body = s.rfind('</body>')
    s = s[:last_body] + override + s[last_body:]
    s = s.replace('?v=20260419y', '?v=20260419z')
    open(idx_path, 'w').write(s)
    print('v3.51 share button halved + cache bumped')
