#!/usr/bin/env python3
"""v3.61 — off-canvas menu reorder:

1) Add a "Reports" item (targets #reportPagesBtn) right after
   Transcribe, before History.
2) Order: Transcribe, Reports, History, Analytics, Contacts,
   (Users admin), Feedback.
3) Remove Settings row from the list.
4) Footer: Sign Out becomes partial-width + a gear cog sits beside
   it that fires #settingsBtn.
"""
import re
idx = '/var/www/transcribe/index.html'
s = open(idx).read()

# 1) Insert Reports item between Transcribe and History
reports_item = """            <button class="offcanvas-item" data-target="reportPagesBtn">
                <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                <span>Reports</span>
                <svg class="offcanvas-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
"""
transcribe_end = """            <button class="offcanvas-item" data-target="transcribeBtn_nav">
                <svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                <span>Transcribe</span>
                <svg class="offcanvas-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
"""
if 'data-target="reportPagesBtn"' in s:
    print('1) Reports item already present')
elif transcribe_end in s:
    s = s.replace(transcribe_end, transcribe_end + reports_item, 1)
    print('1) Reports item inserted after Transcribe')
else:
    print('1) WARN transcribe anchor not found')

# 2) Remove the Settings row from the nav list
settings_row_pattern = re.compile(
    r'\s*<button class="offcanvas-item" data-target="settingsBtn">\s*'
    r'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/>.*?</svg>\s*'
    r'<span>Settings</span>\s*'
    r'<svg class="offcanvas-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>\s*'
    r'</button>\s*',
    re.DOTALL
)
if settings_row_pattern.search(s):
    s = settings_row_pattern.sub('\n', s, count=1)
    print('2) Settings row removed from nav list')
else:
    print('2) Settings row already gone')

# 3) Footer: Sign Out (flex-1) + gear cog beside it
old_foot = """        <div class="offcanvas-foot">
            <button id="offcanvasSignOut" class="offcanvas-signout-btn" type="button">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span>Sign Out</span>
            </button>
        </div>"""
new_foot = """        <div class="offcanvas-foot">
            <button id="offcanvasSignOut" class="offcanvas-signout-btn" type="button">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span>Sign Out</span>
            </button>
            <button id="offcanvasSettings" class="offcanvas-gear-btn" type="button" aria-label="Settings" title="Settings">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            </button>
        </div>"""
if 'id="offcanvasSettings"' in s:
    print('3) gear cog already in foot')
elif old_foot in s:
    s = s.replace(old_foot, new_foot, 1)
    print('3) gear cog added beside Sign Out in foot')
else:
    print('3) WARN foot anchor not found')

# 4) Wire gear button to click the desktop #settingsBtn (reuses existing flow)
old_wire = """        document.querySelectorAll('.offcanvas-item').forEach(btn => {
            btn.addEventListener('click', () => {
                const targetId = btn.dataset.target;
                const target = document.getElementById(targetId);
                if (target) target.click();
                closeMobileMenu();
            });"""
new_wire = """        document.querySelectorAll('.offcanvas-item').forEach(btn => {
            btn.addEventListener('click', () => {
                const targetId = btn.dataset.target;
                const target = document.getElementById(targetId);
                if (target) target.click();
                closeMobileMenu();
            });
        });
        // Gear cog -> Settings
        document.getElementById('offcanvasSettings')?.addEventListener('click', () => {
            const t = document.getElementById('settingsBtn');
            if (t) t.click();
            closeMobileMenu();"""
if 'Gear cog -> Settings' in s:
    print('4) gear wiring already present')
elif old_wire in s:
    s = s.replace(old_wire, new_wire, 1)
    print('4) gear wired to #settingsBtn')
else:
    print('4) WARN wire anchor not found')

# 5) CSS for foot layout + gear button
css_block = """
<style id="v361OffcanvasFoot">
.offcanvas-foot {
    display: flex !important;
    gap: 10px;
    align-items: stretch;
}
.offcanvas-signout-btn {
    flex: 1 1 auto !important;
    justify-content: center;
}
.offcanvas-gear-btn {
    flex: 0 0 auto;
    width: 52px;
    min-height: 46px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.18);
    background: rgba(255,255,255,0.06);
    color: rgba(255,255,255,0.85);
    cursor: pointer;
    transition: background 0.2s ease, transform 0.2s ease;
}
.offcanvas-gear-btn:hover {
    background: rgba(255,255,255,0.14);
    transform: rotate(30deg);
    color: #fff;
}
</style>
"""
if 'id="v361OffcanvasFoot"' in s:
    print('5) foot CSS already present')
else:
    last = s.rfind('</body>')
    s = s[:last] + css_block + s[last:]
    s = s.replace('?v=2026041a7', '?v=2026041a8')
    print('5) foot CSS appended + cache bumped')

open(idx, 'w').write(s)
print('DONE')
