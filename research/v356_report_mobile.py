#!/usr/bin/env python3
"""v3.56 — report.php mobile polish:

A) Shrink hero logo (65 -> 48px).

B) Mobile restructure:
   - Hide the topbar's Public + Quiz History (+ copy-transcript)
     buttons on mobile.
   - Hide the topbar's app-nav buttons on mobile.
   - Add a hamburger button in the top-right of the topbar.
   - Inject an off-canvas drawer with the standard app nav links
     (href back to /index.html#section).
   - Add a mobile-only block below the hero tile with Public +
     Quiz History (if learning mode) as stacked full-width buttons.

C) Floating bottom action bar (mobile only):
   - Two buttons: Download PDF + Share.
   - Brand gradient (brand-500 -> brand-grad-dark).
   - Slides up when user starts scrolling; buttons fade in after.
   - Share opens an inline email lightbox that uses API.sendSmtpEmail
     against /api/send-smtp.php, same flow as History.emailTranscript
     (auto-publicises the report via send-smtp.php behaviour).
"""
rp = '/var/www/transcribe/api/report.php'
s = open(rp).read()

# ── A) Shrink hero logo ──
old_logo = """        #jaiHeroSection .jaihero-logo {
            display: block;
            height: 65px;
            width: auto;
            max-width: 350px;
            object-fit: contain;
            margin: 0 auto 32px auto;
            filter: brightness(0) invert(1);
            opacity: 0.95;
        }"""
new_logo = """        #jaiHeroSection .jaihero-logo {
            display: block;
            height: 48px;
            width: auto;
            max-width: 260px;
            aspect-ratio: 2.5 / 1;
            object-fit: contain;
            margin: 0 auto 28px auto;
            filter: brightness(0) invert(1);
            opacity: 0.95;
        }"""
if 'aspect-ratio: 2.5 / 1;\n            object-fit: contain;\n            margin: 0 auto 28px' in s:
    print('A) hero logo already shrunk')
elif old_logo in s:
    s = s.replace(old_logo, new_logo, 1)
    print('A) hero logo 65 -> 48 + aspect-ratio locked')
else:
    print('A) WARN hero-logo anchor not found')

# ── B2) Mobile-only block below hero with stacked action buttons ──
# Inject right AFTER the closing </section> of the jaiHeroSection.
hero_actions_markup = """
<?php if ($logged): ?>
<!-- v3.56 mobile-only stacked page actions, shown below the hero -->
<div class="mob-page-actions" aria-label="Report actions">
    <button type="button" class="mpa-btn mpa-share-btn <?= $isPublic ? 'is-public' : 'is-private' ?>" onclick="openShareModal()">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <span><?= $isPublic ? 'Public — manage' : 'Private — share' ?></span>
    </button>
<?php if ($mode === 'learning'): ?>
    <button type="button" class="mpa-btn mpa-quiz-btn" onclick="showQuizHistory()">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <span>Quiz History</span>
    </button>
<?php endif; ?>
</div>
<?php endif; ?>
"""

# Anchor: the closing </section> of jaiHeroSection
anchor = '</section>\n<?php\n            $heroReportUrl'
# Find a stable anchor — look for the first </section> immediately following jaihero-meta block.
# Easier: insert right after the last </section> before the .report-wrapper. We match on:
anchor_txt = '</section>'
if 'class="mob-page-actions"' in s:
    print('B2) mobile page actions already injected')
else:
    # Insert right after the FIRST </section> that follows the jaiHeroSection opening.
    hero_open_pos = s.find('<section id="jaiHeroSection"')
    if hero_open_pos == -1:
        print('B2) WARN jaiHeroSection not found')
    else:
        close_pos = s.find('</section>', hero_open_pos)
        if close_pos == -1:
            print('B2) WARN hero closing tag not found')
        else:
            insert_at = close_pos + len('</section>')
            s = s[:insert_at] + '\n' + hero_actions_markup + s[insert_at:]
            print('B2) mobile page actions injected below hero')

# ── C) Bottom action bar + email lightbox markup + hamburger + offcanvas ──
mobile_block = r"""
<!-- ═══════ v3.56 — MOBILE: hamburger button ═══════ -->
<?php if ($logged): ?>
<button type="button" id="rpMobileMenu" class="rp-mobile-menu-btn no-print" aria-label="Menu" onclick="rpOpenOffcanvas()">
    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
</button>

<!-- ═══════ v3.56 — MOBILE: off-canvas drawer ═══════ -->
<div id="rpOffcanvasBackdrop" class="rp-offcanvas-backdrop" onclick="rpCloseOffcanvas()"></div>
<aside id="rpOffcanvas" class="rp-offcanvas" aria-hidden="true">
    <div class="rp-oc-head">
        <img src="<?= e($logoPath) ?>" alt="Logo" class="rp-oc-logo">
        <button type="button" class="rp-oc-close" onclick="rpCloseOffcanvas()" aria-label="Close menu">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <nav class="rp-oc-nav">
        <a class="rp-oc-item" href="/index.html"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/></svg><span>Transcribe</span></a>
        <a class="rp-oc-item" href="/index.html#history"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><span>History</span></a>
        <a class="rp-oc-item" href="/index.html#reports"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><span>All Reports</span></a>
        <a class="rp-oc-item" href="/index.html#analytics"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg><span>Analytics</span></a>
        <a class="rp-oc-item" href="/index.html#contacts"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><span>Contacts</span></a>
        <a class="rp-oc-item" href="/index.html#settings"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/></svg><span>Settings</span></a>
        <a class="rp-oc-item" href="/api/logout.php"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg><span>Sign Out</span></a>
    </nav>
</aside>

<!-- ═══════ v3.56 — MOBILE: floating bottom action bar ═══════ -->
<div id="rpBottomBar" class="rp-bottom-bar no-print" aria-hidden="true">
    <button type="button" class="rpbb-btn rpbb-pdf" onclick="window.print()">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <span>Download PDF</span>
    </button>
    <button type="button" class="rpbb-btn rpbb-share" onclick="rpShareOpen()">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
        <span>Share</span>
    </button>
</div>

<!-- ═══════ v3.56 — MOBILE: inline email lightbox ═══════ -->
<div id="rpEmailModal" class="rp-email-modal" aria-hidden="true">
    <div class="rp-email-backdrop" onclick="rpShareClose()"></div>
    <div class="rp-email-card">
        <div class="rp-email-head">
            <h3>Share this report</h3>
            <button type="button" class="rp-email-close" onclick="rpShareClose()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="rp-email-body">
            <label>To <input type="email" id="rpEmailTo" placeholder="recipient@email.com" autocomplete="email"></label>
            <label>Subject <input type="text" id="rpEmailSubject" value="Transcription | <?= e($title) ?>"></label>
            <label>Message <textarea id="rpEmailMsg" rows="3" placeholder="Optional personal note"></textarea></label>
            <div class="rp-email-chip">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                <span><?= e($title) ?> &middot; <?= e($modeInfo['label']) ?> &middot; link + PDF</span>
            </div>
        </div>
        <div class="rp-email-foot">
            <button type="button" class="rp-email-cancel" onclick="rpShareClose()">Cancel</button>
            <button type="button" id="rpEmailSendBtn" class="rp-email-send" onclick="rpShareSend()">Send</button>
        </div>
    </div>
</div>
<?php endif; ?>

<style id="v356ReportMobile">
/* ─── Mobile-only stacked page actions below hero ─── */
.mob-page-actions { display: none; }
@media (max-width: 768px) {
    .mob-page-actions {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin: 16px 16px 4px;
        max-width: 880px;
    }
    @media (min-width: 481px) {
        .mob-page-actions { margin-left: auto; margin-right: auto; padding: 0 16px; }
    }
    .mpa-btn {
        width: 100%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
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
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .mpa-btn:hover,
    .mpa-btn:active {
        background: rgba(var(--brand-500-rgb), 0.12);
        border-color: var(--brand-500, #2557b3);
    }
    .mpa-share-btn.is-public {
        background: linear-gradient(135deg, #059669 0%, #10b981 100%);
        border-color: #10b981;
        color: #fff;
    }
    /* Hide the original topbar page-specific + app-nav buttons on mobile */
    .topbar .share-btn,
    .topbar .topbar-actions > button.btn,
    .topbar .topbar-actions > a.btn,
    .topbar .topbar-actions > div.header-more-dropdown,
    .topbar .topbar-actions > .header-more-dropdown {
        display: none !important;
    }
}
[data-theme="dark"] .mpa-btn {
    color: var(--brand-300, #93c5fd);
    border-color: rgba(var(--brand-300-rgb, 147,197,253), 0.3);
    background: rgba(var(--brand-400-rgb), 0.08);
}

/* ─── Hamburger + off-canvas drawer ─── */
.rp-mobile-menu-btn {
    display: none;
    position: fixed;
    top: 14px;
    right: 14px;
    z-index: 1200;
    width: 44px;
    height: 44px;
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.22);
    background: rgba(0,0,0,0.55);
    color: #fff;
    cursor: pointer;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(8px);
}
@media (max-width: 768px) {
    .rp-mobile-menu-btn { display: inline-flex; }
}
.rp-offcanvas-backdrop {
    position: fixed; inset: 0;
    background: rgba(0, 8, 24, 0.55);
    opacity: 0;
    pointer-events: none;
    z-index: 1290;
    transition: opacity 0.3s ease;
}
.rp-offcanvas-backdrop.open { opacity: 1; pointer-events: auto; }
.rp-offcanvas {
    position: fixed;
    top: 0; right: 0;
    width: 86%;
    max-width: 340px;
    height: 100dvh;
    background: linear-gradient(160deg,
        var(--brand-500, #2557b3) 0%,
        var(--brand-700, #1a3a7a) 55%,
        var(--brand-grad-dark, #0a1f40) 100%);
    color: #fff;
    z-index: 1300;
    transform: translateX(100%);
    transition: transform 0.4s cubic-bezier(0.22,1,0.36,1);
    display: flex;
    flex-direction: column;
    box-shadow: -20px 0 60px rgba(0,0,0,0.35);
}
.rp-offcanvas.open { transform: translateX(0); }
.rp-oc-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 22px 20px 18px;
    border-bottom: 1px solid rgba(255,255,255,0.12);
}
.rp-oc-logo {
    height: 28px;
    max-width: 180px;
    aspect-ratio: 2.5 / 1;
    object-fit: contain;
    filter: brightness(0) invert(1);
}
.rp-oc-close {
    width: 40px; height: 40px;
    border-radius: 11px;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.15);
    color: #fff;
    cursor: pointer;
}
.rp-oc-nav {
    display: flex; flex-direction: column;
    padding: 14px 10px;
    gap: 4px;
    overflow-y: auto;
}
.rp-oc-item {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 16px;
    border-radius: 12px;
    color: rgba(255,255,255,0.88);
    text-decoration: none;
    font-size: 15px;
    font-weight: 600;
    transition: background 0.2s ease;
}
.rp-oc-item:hover, .rp-oc-item:active {
    background: rgba(255,255,255,0.10);
    color: #fff;
}

/* ─── Bottom action bar ─── */
.rp-bottom-bar {
    display: none;
    position: fixed;
    left: 12px; right: 12px;
    bottom: 14px;
    z-index: 1250;
    padding: 10px 10px;
    border-radius: 16px;
    background: linear-gradient(135deg,
        var(--brand-500, #2557b3) 0%,
        var(--brand-700, #1a3a7a) 55%,
        var(--brand-grad-dark, #0a1f40) 100%);
    box-shadow:
        0 18px 40px rgba(0, 15, 40, 0.45),
        inset 0 1px 0 rgba(255,255,255,0.18);
    transform: translateY(120%);
    opacity: 0;
    transition: transform 0.55s cubic-bezier(0.22,1,0.36,1),
                opacity 0.3s ease;
    gap: 8px;
    grid-template-columns: 1fr 1fr;
}
@media (max-width: 768px) {
    .rp-bottom-bar { display: grid; }
}
.rp-bottom-bar.open {
    transform: translateY(0);
    opacity: 1;
}
.rpbb-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 14px 12px;
    min-height: 48px;
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.22);
    background: rgba(255,255,255,0.08);
    color: #fff;
    font-size: 14px;
    font-weight: 700;
    letter-spacing: 0.4px;
    cursor: pointer;
    opacity: 0;
    transform: translateY(4px);
    transition: opacity 0.35s ease, transform 0.35s ease, background 0.2s ease;
}
.rp-bottom-bar.open .rpbb-btn { opacity: 1; transform: translateY(0); }
.rp-bottom-bar.open .rpbb-pdf   { transition-delay: 0.35s; }
.rp-bottom-bar.open .rpbb-share { transition-delay: 0.5s; }
.rpbb-btn:hover, .rpbb-btn:active {
    background: rgba(255,255,255,0.18);
    border-color: rgba(255,255,255,0.45);
}

/* ─── Inline email lightbox ─── */
.rp-email-modal {
    position: fixed; inset: 0;
    display: none;
    align-items: flex-end;
    justify-content: center;
    z-index: 1400;
}
.rp-email-modal.open { display: flex; }
.rp-email-backdrop {
    position: absolute; inset: 0;
    background: rgba(0,10,30,0.5);
    backdrop-filter: blur(4px);
}
.rp-email-card {
    position: relative;
    width: 100%;
    max-width: 520px;
    margin: 0 12px 12px;
    background: var(--card, #fff);
    border-radius: 18px 18px 18px 18px;
    box-shadow: 0 30px 60px rgba(0,15,40,0.4);
    overflow: hidden;
    animation: rpEmailIn 0.35s cubic-bezier(0.22,1,0.36,1);
}
@keyframes rpEmailIn {
    from { transform: translateY(30px); opacity: 0; }
    to   { transform: translateY(0); opacity: 1; }
}
@media (min-width: 769px) {
    .rp-email-modal { align-items: center; }
    .rp-email-card { margin: 0 auto; }
}
.rp-email-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px;
    background: linear-gradient(135deg,
        var(--brand-500, #2557b3) 0%,
        var(--brand-700, #1a3a7a) 100%);
    color: #fff;
}
.rp-email-head h3 { margin: 0; font-size: 16px; font-weight: 700; letter-spacing: 0.3px; }
.rp-email-close {
    width: 34px; height: 34px;
    background: rgba(255,255,255,0.14);
    border: 1px solid rgba(255,255,255,0.25);
    border-radius: 10px;
    color: #fff;
    cursor: pointer;
}
.rp-email-body { padding: 16px 20px 4px; display: flex; flex-direction: column; gap: 10px; color: var(--ink, #0f172a); }
.rp-email-body label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 700; letter-spacing: 0.6px; text-transform: uppercase; color: var(--ink-muted, #64748b); }
.rp-email-body input,
.rp-email-body textarea {
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid rgba(0,0,0,0.12);
    font-family: inherit;
    font-size: 14px;
    font-weight: 500;
    color: var(--ink, #0f172a);
    background: var(--bg-surface, #fff);
}
.rp-email-body input:focus,
.rp-email-body textarea:focus {
    outline: 2px solid var(--brand-400, #60a5fa);
    outline-offset: 0;
    border-color: transparent;
}
.rp-email-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 12px;
    margin-top: 4px;
    border-radius: 10px;
    background: rgba(var(--brand-500-rgb), 0.08);
    color: var(--brand-700, #1a3a7a);
    font-size: 12px;
    font-weight: 600;
}
.rp-email-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 20px 18px; }
.rp-email-cancel,
.rp-email-send {
    padding: 10px 18px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    border: 0;
    font-family: inherit;
}
.rp-email-cancel {
    background: transparent;
    color: var(--ink-muted, #64748b);
    border: 1px solid rgba(0,0,0,0.12);
}
.rp-email-send {
    background: linear-gradient(135deg, var(--brand-500, #2557b3) 0%, var(--brand-700, #1a3a7a) 100%);
    color: #fff;
}
.rp-email-send[disabled] { opacity: 0.6; cursor: not-allowed; }

[data-theme="dark"] .rp-email-card { background: #0f172a; }
[data-theme="dark"] .rp-email-body input,
[data-theme="dark"] .rp-email-body textarea {
    background: #1e293b;
    border-color: rgba(255,255,255,0.12);
    color: #f1f5f9;
}
[data-theme="dark"] .rp-email-chip { background: rgba(var(--brand-400-rgb), 0.18); color: var(--brand-200, #bfdbfe); }

@media (max-width: 768px) {
    /* Leave room at the bottom so the bar doesn't cover the transcript */
    .report-wrapper { padding-bottom: 100px; }
}
</style>

<script>
/* ═══════ v3.56 — mobile glue ═══════ */
(function () {
    const bar = document.getElementById('rpBottomBar');
    const drawer = document.getElementById('rpOffcanvas');
    const backdrop = document.getElementById('rpOffcanvasBackdrop');
    const modal = document.getElementById('rpEmailModal');

    // Off-canvas
    window.rpOpenOffcanvas = function () {
        if (!drawer) return;
        drawer.classList.add('open');
        if (backdrop) backdrop.classList.add('open');
        drawer.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    };
    window.rpCloseOffcanvas = function () {
        if (!drawer) return;
        drawer.classList.remove('open');
        if (backdrop) backdrop.classList.remove('open');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    };
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            if (drawer && drawer.classList.contains('open')) rpCloseOffcanvas();
            if (modal && modal.classList.contains('open')) rpShareClose();
        }
    });

    // Bottom bar scroll-trigger (mobile only)
    if (bar && window.matchMedia('(max-width: 768px)').matches) {
        let shown = false;
        const show = () => {
            if (shown) return;
            shown = true;
            bar.classList.add('open');
            bar.setAttribute('aria-hidden', 'false');
        };
        window.addEventListener('scroll', () => {
            if (window.scrollY > 120) show();
        }, { passive: true });
        // Fallback: show after 2s even if they haven't scrolled
        setTimeout(() => { if (window.scrollY > 40) show(); }, 2200);
    }

    // Email lightbox
    window.rpShareOpen = function () {
        if (!modal) return;
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    };
    window.rpShareClose = function () {
        if (!modal) return;
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    };

    window.rpShareSend = async function () {
        const to = document.getElementById('rpEmailTo')?.value.trim();
        const subject = document.getElementById('rpEmailSubject')?.value.trim();
        const message = document.getElementById('rpEmailMsg')?.value.trim();
        const btn = document.getElementById('rpEmailSendBtn');
        if (!to) { alert('Please enter a recipient email.'); return; }
        if (btn) { btn.disabled = true; btn.textContent = 'Sending...'; }
        try {
            const body = {
                transcription_id: <?= (int)$row['id'] ?>,
                to, subject,
                message: message || '',
                include_pdf: true
            };
            const r = await fetch('/api/send-smtp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(body)
            });
            const d = await r.json().catch(() => ({}));
            if (!r.ok || d.error) throw new Error(d.error || ('HTTP ' + r.status));
            rpShareClose();
            if (typeof jaiToast === 'function') jaiToast('Email sent!', { kind: 'success' });
            else alert('Email sent.');
        } catch (err) {
            if (typeof jaiToast === 'function') jaiToast(err.message || 'Send failed', { kind: 'error' });
            else alert('Send failed: ' + err.message);
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = 'Send'; }
        }
    };
})();
</script>
"""

if 'id="v356ReportMobile"' in s:
    print('C) v3.56 block already present')
else:
    # Insert just before </body>
    close_body = s.rfind('</body>')
    if close_body == -1:
        print('C) WARN </body> not found')
    else:
        s = s[:close_body] + mobile_block + s[close_body:]
        print('C) mobile bottom bar + offcanvas + email lightbox injected')

open(rp, 'w').write(s)
print('DONE')
