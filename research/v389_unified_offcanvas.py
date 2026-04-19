#!/usr/bin/env python3
"""v3.89 — use the same off-canvas markup/styles/JS from index.html
on report.php and quiz-report.php. Hamburger is mobile-only (white
three-line glyph, no box) and is the only icon on the right on
mobile. Desktop hides it entirely so the normal topbar buttons
stay visible.
"""
import re

# Read the exported offcanvas CSS
oc_css = open('/tmp/oc.css').read() if __import__('os').path.exists('/tmp/oc.css') \
    else open('C:/xampp/htdocs/transcribe/research/_oc.css').read()

# -------- Shared off-canvas HTML + JS (wrapped in a self-contained block) --
def build_block(is_quiz_report=False):
    # Items use hrefs to index.html#section since report pages don't have
    # the target buttons locally.
    admin_sync = """
            // Sync admin-only Users row visibility from localStorage role hint
            try {
                const role = (localStorage.getItem('user_role') || '').toLowerCase();
                const ocU = document.querySelector('.offcanvas-item-admin');
                if (ocU) ocU.style.display = (role === 'admin' || role === 'owner') ? '' : 'none';
            } catch (e) {}"""
    sign_out_wire = """
            // Sign out
            document.getElementById('offcanvasSignOut')?.addEventListener('click', async () => {
                try { await fetch('/api/auth.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ action: 'logout' }) }); }
                catch (e) {}
                window.location.href = '/login.php';
            });
            // Gear -> Settings
            document.getElementById('offcanvasSettings')?.addEventListener('click', () => {
                window.location.href = '/index.html#settings';
            });"""

    return f"""
<!-- v3.89 — unified off-canvas (matches index.html) -->
<style id="v389Offcanvas">
{oc_css}
/* Hamburger: mobile-only, plain white glyph, no backdrop box */
.unified-menu-btn {{
    display: none;
    background: transparent;
    border: 0;
    color: #ffffff;
    width: 44px; height: 44px;
    align-items: center; justify-content: center;
    cursor: pointer;
    padding: 0;
    margin-left: auto;
}}
.unified-menu-btn:hover {{ opacity: 0.85; }}
.unified-menu-btn svg {{ stroke: #ffffff; }}
@media (max-width: 768px) {{
    .unified-menu-btn {{ display: inline-flex !important; }}
    /* Hide every other topbar action — the off-canvas has them all */
    .topbar .topbar-actions > *:not(.unified-menu-btn) {{ display: none !important; }}
}}
/* Kill the old rp-* hamburger + drawer from prior versions */
.rp-mobile-menu-btn,
#rpMobileMenu,
#rpInlineMenu,
.rp-inline-menu {{ display: none !important; }}
</style>

<button type="button" id="unifiedMenuBtn" class="unified-menu-btn no-print" aria-label="Menu">
    <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
</button>

<div id="mobileOffcanvasBackdrop" class="offcanvas-backdrop" onclick="closeMobileMenu()"></div>
<aside id="mobileOffcanvas" class="offcanvas" aria-hidden="true">
    <div class="offcanvas-header">
        <img id="offcanvasLogo" src="<?= e($logoPath) ?>" alt="JAI" class="offcanvas-logo">
        <button class="offcanvas-close" onclick="closeMobileMenu()" aria-label="Close menu">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <nav class="offcanvas-nav">
        <a class="offcanvas-item" href="/index.html">
            <svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
            <span>Transcribe</span>
            <svg class="offcanvas-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a class="offcanvas-item" href="/index.html#reports">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            <span>Reports</span>
            <svg class="offcanvas-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a class="offcanvas-item" href="/index.html#history">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <span>History</span>
            <svg class="offcanvas-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a class="offcanvas-item" href="/index.html#analytics">
            <svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            <span>Analytics</span>
            <svg class="offcanvas-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a class="offcanvas-item" href="/index.html#contacts">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span>Contacts</span>
            <svg class="offcanvas-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a class="offcanvas-item offcanvas-item-admin" href="/index.html#users" style="display:none">
            <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <span>Users</span>
            <svg class="offcanvas-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a class="offcanvas-item" href="/index.html#feedback">
            <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <span>Feedback</span>
            <svg class="offcanvas-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
    </nav>
    <div class="offcanvas-foot">
        <button id="offcanvasSignOut" class="offcanvas-signout-btn" type="button">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <span>Sign Out</span>
        </button>
        <button id="offcanvasSettings" class="offcanvas-gear-btn" type="button" aria-label="Settings" title="Settings">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        </button>
    </div>
</aside>

<script>
(function(){{
    const offcanvas = document.getElementById('mobileOffcanvas');
    const backdrop  = document.getElementById('mobileOffcanvasBackdrop');
    window.openMobileMenu = function () {{
        offcanvas.classList.add('open');
        backdrop.classList.add('open');
        offcanvas.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        {admin_sync}
    }};
    window.closeMobileMenu = function () {{
        offcanvas.classList.remove('open');
        backdrop.classList.remove('open');
        offcanvas.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }};
    document.addEventListener('keydown', e => {{
        if (e.key === 'Escape' && offcanvas.classList.contains('open')) closeMobileMenu();
    }});
    document.getElementById('unifiedMenuBtn')?.addEventListener('click', openMobileMenu);
    {sign_out_wire}
}})();
</script>
"""


# -------- patch report.php --------
rp_path = '/var/www/transcribe/api/report.php'
s = open(rp_path).read()

# Remove the v3.56 / v3.78 hamburger + drawer + offcanvas block entirely.
# Strip between <!-- ═══════ v3.56 — MOBILE: hamburger button ═══════ --> and the
# closing </aside> of the old rp-offcanvas. Keep the floating PDF / quiz / bottom-bar
# blocks that follow.
start_m = '<!-- ═══════ v3.56 — MOBILE: hamburger button ═══════ -->'
end_m   = '<!-- ═══════ v3.56 — MOBILE: floating bottom action bar ═══════ -->'
if start_m in s and end_m in s:
    a = s.index(start_m)
    b = s.index(end_m)
    s = s[:a] + build_block() + '\n\n' + s[b:]
    print('report.php: old hamburger/drawer replaced with unified off-canvas')
else:
    print('report.php: anchors not found')

# Also remove the inline #rpInlineMenu button inside the topbar added by v3.78
s = re.sub(r'\s*<button type="button" id="rpInlineMenu".*?</button>', '', s, flags=re.DOTALL)

open(rp_path, 'w').write(s)


# -------- patch quiz-report.php --------
qr_path = '/var/www/transcribe/api/quiz-report.php'
q = open(qr_path).read()

# Look for the v3.57 mobile hamburger + drawer block
start_q = '<!-- v3.57 mobile hamburger + offcanvas (mirrors report.php) -->'
end_q   = '<!-- v3.81 — quiz-report bottom action bar (mobile) -->'
if start_q in q and end_q in q:
    a = q.index(start_q)
    b = q.index(end_q)
    q = q[:a] + build_block(is_quiz_report=True) + '\n\n' + q[b:]
    print('quiz-report.php: old hamburger/drawer replaced with unified off-canvas')
elif start_q in q:
    # Fallback — end at the matching style tag
    a = q.index(start_q)
    # look for next </script> after </aside>
    b = q.find('</script>', a)
    b = q.find('</style>', b) + len('</style>')
    q = q[:a] + build_block(is_quiz_report=True) + '\n\n' + q[b:]
    print('quiz-report.php: replaced with fallback boundary')
else:
    # Not found — just inject at end of body
    cb = q.rfind('</body>')
    q = q[:cb] + build_block(is_quiz_report=True) + q[cb:]
    print('quiz-report.php: injected fresh (no prior block found)')

open(qr_path, 'w').write(q)
print('DONE')
