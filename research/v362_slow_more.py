#!/usr/bin/env python3
"""v3.62 — matrix rain twice as slow, mobile top padding halved
again (72 -> 60 so the visible gap below header is ~halved).
"""
idx = '/var/www/transcribe/index.html'
css = '/var/www/transcribe/css/style.css'
s = open(idx).read()
c = open(css).read()

# 1) Matrix fall rate 0.22 -> 0.11
if "drops[i] += cellH * 0.22" in s:
    s = s.replace("drops[i] += cellH * 0.22", "drops[i] += cellH * 0.11", 1)
    print('1) matrix fall rate 0.22 -> 0.11 (2x slower)')
else:
    print('1) fall rate already tuned below 0.22')

# 2) Mobile main padding 72 -> 60
old_pad = 'main { padding: 72px 16px 24px !important; }'
new_pad = 'main { padding: 60px 16px 24px !important; }'
if new_pad in c:
    print('2) mobile main padding already 60')
elif old_pad in c:
    c = c.replace(old_pad, new_pad, 1)
    open(css, 'w').write(c)
    print('2) mobile main padding 72 -> 60')
else:
    print('2) WARN anchor not found')

s = s.replace('?v=2026041a8', '?v=2026041a9')
open(idx, 'w').write(s)
print('DONE')
