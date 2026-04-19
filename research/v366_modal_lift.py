#!/usr/bin/env python3
"""v3.66 — mobile: lift the Learning Analysis modal heading up.

Modal overlay centers vertically (align-items:center) + modal-header
has 28px top padding. On mobile that puts 'Learning Analysis Input'
well below the app header bar.

Mobile-only:
  - modal-overlay anchors to the top with 40px offset (was center).
  - modal-header padding-top 28 -> 12 (shaves another 16px).
Net lift ~30px.
"""
idx = '/var/www/transcribe/index.html'
s = open(idx).read()

block = """
<style id="v366ModalLift">
@media (max-width: 768px) {
    .modal-overlay {
        align-items: flex-start !important;
        padding-top: 40px !important;
    }
    .modal-header {
        padding: 12px 20px 14px !important;
    }
    .modal-header h2 {
        font-size: 18px !important;
    }
}
</style>
"""
if 'id="v366ModalLift"' in s:
    print('v3.66 already applied')
else:
    last = s.rfind('</body>')
    s = s[:last] + block + s[last:]
    s = s.replace('?v=2026041ac', '?v=2026041ad')
    open(idx, 'w').write(s)
    print('v3.66 modal heading lift applied + cache bumped')
