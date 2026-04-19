#!/usr/bin/env python3
"""v3.55b — fix: the click-wiring loop in the sidebar was not
replaced in v3.55 because the check matched an unrelated string
elsewhere in the file. Replace the stale block now.

Also apply the deferred request: mobile header logo another -25%
(29 -> 22).
"""
idx_path = '/var/www/transcribe/index.html'
s = open(idx_path).read()

# Replace stale wiring block
old_wire = """            this.list.querySelectorAll('.rs-item').forEach((btn, i) => {
                btn.addEventListener('click', () => this.openReport(btn.dataset.id, btn.dataset.title));
                const sb = document.getElementById('reportsSidebar');
                if (sb && sb.classList.contains('assembling')) {
                    btn.style.opacity = '0';
                    btn.style.transform = 'translateX(18px)';
                    btn.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                    setTimeout(() => {
                        btn.style.opacity = '';
                        btn.style.transform = '';
                    }, 60 + i * 80);
                }
            });"""
new_wire = """            this.list.querySelectorAll('.rs-item-body').forEach((btn) => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.openReport(btn.dataset.id, btn.dataset.title);
                });
            });
            this.list.querySelectorAll('.rs-item-mail').forEach((btn) => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const id = btn.dataset.id;
                    const title = btn.dataset.title || '';
                    const type = btn.dataset.type || '';
                    // Pre-seed attachment chip while the load resolves.
                    try {
                        const chip = document.getElementById('emailAttachmentChip');
                        if (chip) {
                            chip.textContent = title + ' \\u00b7 ' + type + ' \\u00b7 PDF';
                            chip.dataset.pending = '1';
                        }
                    } catch (e) {}
                    if (window.History && typeof window.History.emailTranscript === 'function') {
                        window.History.emailTranscript(id);
                    }
                });
            });
            this.list.querySelectorAll('.rs-item').forEach((row, i) => {
                const sb = document.getElementById('reportsSidebar');
                if (sb && sb.classList.contains('assembling')) {
                    row.style.opacity = '0';
                    row.style.transform = 'translateX(18px)';
                    row.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                    setTimeout(() => {
                        row.style.opacity = '';
                        row.style.transform = '';
                    }, 60 + i * 80);
                }
            });"""

if '.rs-item-body' in s and "querySelectorAll('.rs-item-body')" in s:
    print('wiring already correct')
elif old_wire in s:
    s = s.replace(old_wire, new_wire, 1)
    print('wiring: rs-item -> rs-item-body/mail split')
else:
    print('WARN stale wiring anchor not found')

# ── Mobile logo -25% more: 29 -> 22px ──
old_logo = """    /* 1) Header logo -25%, shift 5px left */
    .header-logo {
        height: 29px !important;
        margin-left: -5px !important;
    }"""
new_logo = """    /* 1) Header logo -25% more (was 29, now 22), shift 5px left */
    .header-logo {
        height: 22px !important;
        margin-left: -5px !important;
    }"""
if 'height: 22px !important;\n        margin-left: -5px !important;' in s:
    print('mobile logo already at 22')
elif old_logo in s:
    s = s.replace(old_logo, new_logo, 1)
    print('mobile header logo: 29 -> 22 (-25%)')
else:
    print('WARN mobile logo anchor not found')

# Cache bump
s = s.replace('?v=2026041a3', '?v=2026041a4')

open(idx_path, 'w').write(s)
print('DONE')
