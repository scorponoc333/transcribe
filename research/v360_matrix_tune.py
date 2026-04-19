#!/usr/bin/env python3
"""v3.60 — two fixes:
  A) Matrix rain is too fast / too loud. Halve opacity (28 -> 14)
     and slow the fall rate (0.55 -> 0.22) and trail fade alpha
     (0.18 -> 0.08 so the trail lingers longer, making it look
     slower still).
  B) On mobile, main's top padding (90px) leaves a huge gap below
     the header. Reduce to 72px (~header + 20px).
"""
idx = '/var/www/transcribe/index.html'
css = '/var/www/transcribe/css/style.css'
s = open(idx).read()
c = open(css).read()

# A1) Opacity 0.28 -> 0.14
s = s.replace('opacity: 0.28;', 'opacity: 0.14;', 1)
print('A1) matrix canvas opacity 0.28 -> 0.14')

# A2) Fall rate 0.55 -> 0.22
if "drops[i] += cellH * 0.55" in s:
    s = s.replace("drops[i] += cellH * 0.55", "drops[i] += cellH * 0.22", 1)
    print('A2) matrix fall rate 0.55 -> 0.22')
else:
    print('A2) fall rate already tuned')

# A3) Trail fade alpha — fewer overdrawn frames so movement appears slower
if "'rgba(0, 8, 24, 0.18)'" in s:
    s = s.replace("'rgba(0, 8, 24, 0.18)'", "'rgba(0, 8, 24, 0.08)'", 1)
    print('A3) trail fade alpha 0.18 -> 0.08')
else:
    print('A3) trail fade already tuned')

open(idx, 'w').write(s)

# B) Mobile main padding: 90px -> 72px (header ~52 + 20 gap)
old_pad = 'main { padding: 90px 16px 32px !important; }'
new_pad = 'main { padding: 72px 16px 24px !important; }'
if new_pad in c:
    print('B) mobile main padding already 72')
elif old_pad in c:
    c = c.replace(old_pad, new_pad, 1)
    open(css, 'w').write(c)
    print('B) mobile main padding 90 -> 72 (top gap ~20px)')
else:
    print('B) WARN main padding anchor not found')

print('DONE')
