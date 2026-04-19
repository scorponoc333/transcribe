#!/usr/bin/env python3
"""v3.53 — fix Opening-Report overlay logo proportions.

Source logo.png is 1600x640 (aspect 2.5:1). The overlay currently
uses height:56px; width:auto; max-width:280px. 56*2.5 = 140px so
max-width shouldn't kick in, but Jason reports the logo looking
stretched. Belt-and-suspenders:
- Add object-fit: contain to reject any stretching.
- Add explicit aspect-ratio: 2.5 / 1 so the browser computes width
  strictly from the source aspect regardless of surrounding rules.
- Bump size slightly (height 64, max-width 200) for a cleaner look.
"""
p = '/var/www/transcribe/index.html'
s = open(p).read()

old = """    #reportOpenLogo {
        height: 56px;
        width: auto;
        max-width: 280px;
        margin: 0 auto 28px;
        filter: brightness(0) invert(1);
        display: block;
    }"""
new = """    #reportOpenLogo {
        height: 64px;
        width: auto;
        max-width: 220px;
        aspect-ratio: 2.5 / 1;
        object-fit: contain;
        margin: 0 auto 28px;
        filter: brightness(0) invert(1);
        display: block;
    }"""
if 'aspect-ratio: 2.5 / 1' in s and '#reportOpenLogo' in s:
    print('already fixed')
elif old in s:
    s = s.replace(old, new, 1)
    s = s.replace('?v=2026041a0', '?v=2026041a1')
    open(p, 'w').write(s)
    print('v3.53 overlay logo aspect locked + bumped')
else:
    print('WARN anchor not found')
