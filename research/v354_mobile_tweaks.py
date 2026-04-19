#!/usr/bin/env python3
"""v3.54 — mobile fine-tuning.

1) Header logo: -25% (38 -> 29), nudge 5px to the left.
2) Hamburger button: nudge 10px to the right.
3) Start a Learning Analysis button: +25% size + 10px bottom margin.
4) Off-canvas logo: -25% more (39 -> 29).
"""
idx_path = '/var/www/transcribe/index.html'
s = open(idx_path).read()

block = """
<style id="v354MobileTweaks">
@media (max-width: 768px) {
    /* 1) Header logo -25%, shift 5px left */
    .header-logo {
        height: 29px !important;
        margin-left: -5px !important;
    }
    /* 2) Hamburger nudged 10px right */
    #mobileMenuBtn {
        margin-right: -10px !important;
        transform: translateX(10px) !important;
    }
    /* 3) "Start a Learning Analysis" +25% + 10px bottom margin */
    #startLearningBtn {
        padding: 18px 26px !important;
        font-size: 17px !important;
        margin-bottom: 10px !important;
    }
    #startLearningBtn .learn-btn-label {
        font-size: 17px !important;
    }
    #startLearningBtn svg {
        width: 22px !important;
        height: 22px !important;
    }
    /* 4) Off-canvas logo -25% more (39 -> 29) */
    .offcanvas-logo {
        height: 29px !important;
    }
}
</style>
"""

if 'id="v354MobileTweaks"' in s:
    print('v3.54 already present')
else:
    last_body = s.rfind('</body>')
    s = s[:last_body] + block + s[last_body:]
    s = s.replace('?v=2026041a1', '?v=2026041a2')
    open(idx_path, 'w').write(s)
    print('v3.54 mobile tweaks applied + cache bumped')
