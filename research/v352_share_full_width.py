#!/usr/bin/env python3
"""v3.52 — Share button: full width, keep the half height (63px).
v3.51 halved both. User wants width back to 100% (flush with the
filter-tab row above), height stays 63px.
"""
p = '/var/www/transcribe/index.html'
s = open(p).read()

old = """.rs-share-btn {
    width: 50% !important;
    height: 63px !important;
    margin-left: auto !important;
    margin-right: auto !important;
}"""
new = """.rs-share-btn {
    width: 100% !important;
    height: 63px !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
}"""
if 'width: 100% !important;\n    height: 63px !important;\n    margin-left: 0' in s:
    print('already full-width')
elif old in s:
    s = s.replace(old, new, 1)
    s = s.replace('?v=20260419z', '?v=2026041a0')
    open(p, 'w').write(s)
    print('v3.52 share button -> full width, half height')
else:
    print('WARN anchor not found')
