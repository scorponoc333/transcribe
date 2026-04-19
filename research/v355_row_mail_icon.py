#!/usr/bin/env python3
"""v3.55 — All Reports sidebar: row click opens report, envelope
icon on each row opens the email lightbox. Drop the Share-a-Report
button (becomes redundant). Add an attachment chip to the email
modal (Title · Type · PDF).

Files touched:
  /var/www/transcribe/index.html
  /var/www/transcribe/js/app.js
"""
import re

# ══════════════════════════════════════════════════════════════
# index.html — row template, row styles, remove Share button,
# attachment chip markup + id
# ══════════════════════════════════════════════════════════════
idx_path = '/var/www/transcribe/index.html'
s = open(idx_path).read()

# ── 1) New row template (the innerHTML map) ──
old_tmpl = """            this.list.innerHTML = items.map(it => {
                const mode = (it.mode || 'recording').toLowerCase();
                const typeLabel = mode === 'learning' ? 'Learning' : mode === 'meeting' ? 'Meeting' : 'Audio';
                const title = (it.title || '').replace(/</g, '&lt;') || 'Untitled';
                return `<button class="rs-item" data-id="${it.id}" data-title="${title.replace(/"/g,'&quot;')}">
                    <div class="rs-item-head">
                        <span class="rs-type-chip type-${mode}">${typeLabel}</span>
                        <span class="rs-item-date">${fmt(it.created_at)}</span>
                    </div>
                    <div class="rs-item-title">${title}</div>
                </button>`;
            }).join('');"""
new_tmpl = """            this.list.innerHTML = items.map(it => {
                const mode = (it.mode || 'recording').toLowerCase();
                const typeLabel = mode === 'learning' ? 'Learning' : mode === 'meeting' ? 'Meeting' : 'Audio';
                const title = (it.title || '').replace(/</g, '&lt;') || 'Untitled';
                const safeTitle = title.replace(/"/g,'&quot;');
                return `<div class="rs-item" data-id="${it.id}">
                    <button class="rs-item-body" data-id="${it.id}" data-title="${safeTitle}" aria-label="Open report: ${safeTitle}">
                        <div class="rs-item-head">
                            <span class="rs-type-chip type-${mode}">${typeLabel}</span>
                            <span class="rs-item-date">${fmt(it.created_at)}</span>
                        </div>
                        <div class="rs-item-title">${title}</div>
                    </button>
                    <button class="rs-item-mail" data-id="${it.id}" data-title="${safeTitle}" data-type="${typeLabel}" aria-label="Email report: ${safeTitle}" title="Email this report">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    </button>
                </div>`;
            }).join('');"""
if '.rs-item-mail' in s:
    print('1) row template already updated')
elif old_tmpl in s:
    s = s.replace(old_tmpl, new_tmpl, 1)
    print('1) row template updated (body + mail buttons)')
else:
    print('1) WARN row template anchor not found')

# ── 2) New click wiring ──
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
                    const title = btn.dataset.title;
                    const type = btn.dataset.type;
                    // Pre-seed attachment chip so it shows while load runs
                    try {
                        const chip = document.getElementById('emailAttachmentChip');
                        if (chip) {
                            chip.textContent = `${title} · ${type} · PDF`;
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
if 'rs-item-body' in s and 'History.emailTranscript' in s:
    print('2) click wiring already updated')
elif old_wire in s:
    s = s.replace(old_wire, new_wire, 1)
    print('2) click wiring split: body -> open, mail -> email')
else:
    print('2) WARN click-wire anchor not found')

# ── 3) Remove Share a Report button from the header ──
old_share = """        <button type="button" id="rsShareBtn" class="rs-share-btn">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
            <span>Share a Report</span>
        </button>
    </div>"""
new_share = """    </div>"""
if 'id="rsShareBtn"' not in s:
    print('3) Share button already removed')
elif old_share in s:
    s = s.replace(old_share, new_share, 1)
    print('3) Share-a-Report button removed from markup')
else:
    print('3) WARN share button anchor not found')

# ── 4) Attachment chip markup in the email modal ──
old_chip_markup = """                <div class="email-attachment-info mb-3" style="margin-top:0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                    <span>PDF report will be attached automatically</span>
                </div>"""
new_chip_markup = """                <div class="email-attachment-info mb-3" style="margin-top:0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                    <span id="emailAttachmentChip">PDF report will be attached automatically</span>
                </div>"""
if 'id="emailAttachmentChip"' in s:
    print('4) attachment chip id already present')
elif old_chip_markup in s:
    s = s.replace(old_chip_markup, new_chip_markup, 1)
    print('4) attachment chip id added to email modal')
else:
    print('4) WARN chip anchor not found')

# ── 5) New CSS block for split row + mail icon + mobile tap target ──
row_css = """
<style id="v355RowMail">
/* Sidebar row: body + trailing mail icon side-by-side. */
.rs-item {
    display: flex !important;
    align-items: stretch !important;
    gap: 4px;
    padding: 0 !important;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 10px;
    margin-bottom: 4px;
    transition: all .18s;
    overflow: hidden;
}
.rs-item:hover {
    background: rgba(var(--brand-500-rgb), 0.06);
    border-color: rgba(var(--brand-500-rgb), 0.15);
    transform: translateX(2px);
}
.rs-item-body {
    flex: 1 1 auto;
    min-width: 0;
    padding: 10px 6px 10px 12px;
    background: transparent;
    border: 0;
    font-family: inherit;
    text-align: left;
    cursor: pointer;
    color: inherit;
}
.rs-item-mail {
    flex: 0 0 auto;
    width: 44px;
    min-height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: 1px solid rgba(var(--brand-500-rgb), 0.18);
    border-radius: 10px;
    color: var(--brand-500, #2557b3);
    margin: 6px 6px 6px 0;
    cursor: pointer;
    transition: all 0.2s ease;
}
.rs-item-mail:hover {
    background: rgba(var(--brand-500-rgb), 0.12);
    border-color: var(--brand-500, #2557b3);
    transform: scale(1.05);
}
.rs-item-mail:active { transform: scale(0.96); }

[data-theme="dark"] .rs-item-mail {
    color: var(--brand-300, #93c5fd);
    border-color: rgba(var(--brand-300-rgb, 147,197,253), 0.3);
}
[data-theme="dark"] .rs-item-mail:hover {
    background: rgba(var(--brand-400-rgb), 0.2);
    border-color: var(--brand-300, #93c5fd);
}

/* Attachment chip style */
#emailAttachmentChip {
    font-weight: 600;
}

/* Mobile: keep 44px tap targets */
@media (max-width: 768px) {
    .rs-item-mail {
        width: 48px;
        min-height: 48px;
    }
}
</style>
"""
if 'id="v355RowMail"' in s:
    print('5) row CSS already present')
else:
    last_body = s.rfind('</body>')
    s = s[:last_body] + row_css + s[last_body:]
    print('5) row + mail icon CSS appended')

# Cache bump
s = s.replace('?v=2026041a2', '?v=2026041a3')

open(idx_path, 'w').write(s)

# ══════════════════════════════════════════════════════════════
# app.js — update attachment chip when email modal opens
# ══════════════════════════════════════════════════════════════
app_path = '/var/www/transcribe/js/app.js'
a = open(app_path).read()

old_open = """        this.dom.emailCc.value = '';
        this.dom.emailBcc.value = '';
        this.dom.emailModal.classList.add('active');"""
new_open = """        this.dom.emailCc.value = '';
        this.dom.emailBcc.value = '';

        // Attachment chip: "Title · Type · PDF"
        const chip = document.getElementById('emailAttachmentChip');
        if (chip) {
            const modeLabel = this.audioMode === 'learning' ? 'Learning'
                            : this.audioMode === 'meeting'  ? 'Meeting'
                            : 'Audio';
            chip.textContent = `${title} · ${modeLabel} · PDF`;
            chip.dataset.pending = '';
        }

        this.dom.emailModal.classList.add('active');"""
if 'emailAttachmentChip' in a:
    print('app.js: chip update already present')
elif old_open in a:
    a = a.replace(old_open, new_open, 1)
    open(app_path, 'w').write(a)
    print('app.js: attachment chip now set on openEmailModal')
else:
    print('app.js: WARN openEmailModal anchor not found')

print('DONE')
