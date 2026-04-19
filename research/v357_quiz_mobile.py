#!/usr/bin/env python3
"""v3.57 — quiz-report.php mobile parity with report.php:
  * Hide topbar buttons on mobile (Back to Report + app-nav).
  * Add hamburger top-right + off-canvas drawer with app nav links.
  * Add a stacked full-width "Back to Report" button below the
    cover tile on mobile (the hero's equivalent).
  * Shrink qr-cover-logo so it doesn't crowd the tile.
"""
qr = '/var/www/transcribe/api/quiz-report.php'
s = open(qr).read()

# 1) Inject the mobile "Back to Report" below the cover tile.
#    Anchor: the closing of the first .report-card that contains .qr-cover.
old_cover = """<div class="report-card">
        <div class="qr-cover">
            <img src="<?= e($logoPath) ?>" alt="Logo" class="qr-cover-logo">"""
new_cover = """<div class="report-card">
        <div class="qr-cover">
            <img src="<?= e($logoPath) ?>" alt="Logo" class="qr-cover-logo">"""
# (no change to the hero itself — we just anchor for insertion below it)

# Find the first `</div>` that closes the cover report-card, then inject
# a stacked button block right after it.
cover_open = s.find('<!-- Cover -->')
if cover_open == -1:
    print('WARN cover anchor not found')
else:
    # Find end of cover card: first `</div>\n    </div>` after cover_open
    probe = s.find('<!-- Summary -->', cover_open)
    if probe == -1:
        # fallback: insert after the cover card's last </div></div>
        probe = s.find('</div>\n    </div>', cover_open)
    if probe != -1 and 'class="qr-mob-actions"' not in s:
        insert = """
    <!-- v3.57 — mobile-only stacked page actions below cover -->
    <div class="qr-mob-actions">
        <a href="/api/report.php?id=<?= (int) $attempt['transcription_id'] ?>" class="qmpa-btn">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><polyline points="15 18 9 12 15 6"/></svg>
            <span>Back to Report</span>
        </a>
    </div>

"""
        s = s[:probe] + insert + s[probe:]
        print('1) mobile "Back to Report" injected below cover')
    else:
        print('1) mobile action already present or anchor missing')

# 2) Hamburger + off-canvas + CSS block, injected before </body>
block = r"""
<!-- v3.57 mobile hamburger + offcanvas (mirrors report.php) -->
<button type="button" id="qrMobileMenu" class="rp-mobile-menu-btn no-print" aria-label="Menu" onclick="qrOpenOffcanvas()">
    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
</button>
<div id="qrOffcanvasBackdrop" class="rp-offcanvas-backdrop" onclick="qrCloseOffcanvas()"></div>
<aside id="qrOffcanvas" class="rp-offcanvas" aria-hidden="true">
    <div class="rp-oc-head">
        <img src="<?= e($logoPath) ?>" alt="Logo" class="rp-oc-logo">
        <button type="button" class="rp-oc-close" onclick="qrCloseOffcanvas()" aria-label="Close menu">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <nav class="rp-oc-nav">
        <a class="rp-oc-item" href="/api/report.php?id=<?= (int) $attempt['transcription_id'] ?>"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg><span>Back to Report</span></a>
        <a class="rp-oc-item" href="/index.html"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/></svg><span>Transcribe</span></a>
        <a class="rp-oc-item" href="/index.html#history"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><span>History</span></a>
        <a class="rp-oc-item" href="/index.html#reports"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><span>All Reports</span></a>
        <a class="rp-oc-item" href="/index.html#settings"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/></svg><span>Settings</span></a>
        <a class="rp-oc-item" href="/api/logout.php"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg><span>Sign Out</span></a>
    </nav>
</aside>

<style id="v357QrMobile">
.qr-mob-actions { display: none; }
@media (max-width: 768px) {
    .qr-mob-actions {
        display: flex; flex-direction: column; gap: 10px;
        margin: 14px 16px 8px;
    }
    .qmpa-btn {
        display: inline-flex; align-items: center; justify-content: center;
        gap: 10px;
        padding: 14px 18px;
        min-height: 50px;
        border-radius: 14px;
        border: 1.5px solid rgba(var(--brand-500-rgb), 0.25);
        background: rgba(var(--brand-500-rgb), 0.06);
        color: var(--brand-700, #1a3a7a);
        font-size: 15px;
        font-weight: 700;
        letter-spacing: 0.4px;
        text-decoration: none;
    }
    .qmpa-btn:hover, .qmpa-btn:active {
        background: rgba(var(--brand-500-rgb), 0.12);
        border-color: var(--brand-500, #2557b3);
    }
    /* Hide the topbar's page-specific + app-nav buttons on mobile */
    .topbar .topbar-actions > a.btn,
    .topbar .topbar-actions > button.btn,
    .topbar .topbar-actions > .tb-more,
    .topbar .qr-pdf-btn { /* the PDF floating btn is desktop-only */ }
    .topbar-actions > a.btn,
    .topbar-actions > button.btn,
    .topbar-actions > .tb-more { display: none !important; }
    /* Shrink the cover logo a bit on mobile */
    .qr-cover-logo {
        height: 44px !important;
        max-width: 220px !important;
        aspect-ratio: 2.5 / 1;
        object-fit: contain;
    }
}
/* hamburger + drawer — reuse classes from report.php via identical
   CSS. Duplicated here so quiz-report is self-contained. */
.rp-mobile-menu-btn {
    display: none;
    position: fixed;
    top: 14px; right: 14px;
    z-index: 1200;
    width: 44px; height: 44px;
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.22);
    background: rgba(0,0,0,0.55);
    color: #fff;
    cursor: pointer;
    align-items: center; justify-content: center;
    backdrop-filter: blur(8px);
}
@media (max-width: 768px) {
    .rp-mobile-menu-btn { display: inline-flex; }
}
.rp-offcanvas-backdrop {
    position: fixed; inset: 0;
    background: rgba(0,8,24,0.55);
    opacity: 0; pointer-events: none;
    z-index: 1290;
    transition: opacity 0.3s ease;
}
.rp-offcanvas-backdrop.open { opacity: 1; pointer-events: auto; }
.rp-offcanvas {
    position: fixed; top: 0; right: 0;
    width: 86%;
    max-width: 340px;
    height: 100dvh;
    background: linear-gradient(160deg, var(--brand-500, #2557b3) 0%, var(--brand-700, #1a3a7a) 55%, var(--brand-grad-dark, #0a1f40) 100%);
    color: #fff;
    z-index: 1300;
    transform: translateX(100%);
    transition: transform 0.4s cubic-bezier(0.22,1,0.36,1);
    display: flex; flex-direction: column;
    box-shadow: -20px 0 60px rgba(0,0,0,0.35);
}
.rp-offcanvas.open { transform: translateX(0); }
.rp-oc-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 22px 20px 18px;
    border-bottom: 1px solid rgba(255,255,255,0.12);
}
.rp-oc-logo {
    height: 28px; max-width: 180px;
    aspect-ratio: 2.5 / 1;
    object-fit: contain;
    filter: brightness(0) invert(1);
}
.rp-oc-close {
    width: 40px; height: 40px;
    border-radius: 11px;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.15);
    color: #fff; cursor: pointer;
}
.rp-oc-nav {
    display: flex; flex-direction: column;
    padding: 14px 10px; gap: 4px;
    overflow-y: auto;
}
.rp-oc-item {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 16px;
    border-radius: 12px;
    color: rgba(255,255,255,0.88);
    text-decoration: none;
    font-size: 15px; font-weight: 600;
    transition: background 0.2s ease;
}
.rp-oc-item:hover, .rp-oc-item:active {
    background: rgba(255,255,255,0.10);
    color: #fff;
}
</style>
<script>
/* v3.57 — quiz-report offcanvas glue */
(function () {
    const d = document.getElementById('qrOffcanvas');
    const b = document.getElementById('qrOffcanvasBackdrop');
    window.qrOpenOffcanvas = function () {
        if (!d) return;
        d.classList.add('open');
        if (b) b.classList.add('open');
        d.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    };
    window.qrCloseOffcanvas = function () {
        if (!d) return;
        d.classList.remove('open');
        if (b) b.classList.remove('open');
        d.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    };
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && d && d.classList.contains('open')) qrCloseOffcanvas();
    });
})();
</script>
"""
if 'id="v357QrMobile"' in s:
    print('2) v3.57 quiz mobile block already present')
else:
    close_body = s.rfind('</body>')
    if close_body == -1:
        print('WARN </body> not found in quiz-report.php')
    else:
        s = s[:close_body] + block + s[close_body:]
        print('2) quiz-report mobile block injected')

open(qr, 'w').write(s)
print('DONE')
