#!/usr/bin/env python3
"""v3.63 — mobile only: lift "Transcribe Your Audio" by 32px. The
.typewriter-hero div has a Tailwind pt-8 (32px) that we're killing
on mobile so the heading sits right under the header.
"""
css = '/var/www/transcribe/css/style.css'
c = open(css).read()

block = """

/* v3.63 — mobile only: remove Tailwind pt-8 on the upload hero so
   "Transcribe Your Audio" sits right under the header. */
@media (max-width: 768px) {
    #uploadSection .typewriter-hero {
        padding-top: 0 !important;
        margin-top: -6px !important;
    }
}
"""
if 'v3.63 — mobile only: remove Tailwind pt-8' in c:
    print('already applied')
else:
    c += block
    open(css, 'w').write(c)
    print('v3.63 hero lift applied')

# Cache bump
idx = '/var/www/transcribe/index.html'
s = open(idx).read()
s = s.replace('?v=2026041a9', '?v=2026041aa')
open(idx, 'w').write(s)
print('cache bumped')
