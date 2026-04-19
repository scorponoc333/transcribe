#!/usr/bin/env python3
"""v3.78 — report.php mobile: consolidated fixes.

1) Kill the floating rp-mobile-menu-btn. Put the hamburger INSIDE
   the topbar, right-aligned, plain white glyph (no dark backdrop).
2) Hide every other topbar button on mobile (share/quiz/transcribe/
   more/settings/signout) — they live in the off-canvas now.
3) Double the topbar-brand-logo on mobile (26px -> 52px).
4) Mobile Public/Private + Quiz History stacked buttons get a black
   brand gradient with simple labels ("Public" / "Private").
5) Quiz History lightbox gets a "Take a New Quiz" action button.
6) Bottom action bar: force-show 1.2s after page load on mobile so
   PDF + Share are always reachable (scroll trigger kept as backup).
"""
rp = '/var/www/transcribe/api/report.php'
s = open(rp).read()

# 1) Add an inline hamburger button at the end of .topbar-actions,
#    mobile-only visibility.
old_nav_end = """            <button type="button" class="btn app-nav-btn" onclick="tbSignOut()" title="Sign Out">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span>Sign Out</span>
            </button>"""
new_nav_end = """            <button type="button" class="btn app-nav-btn" onclick="tbSignOut()" title="Sign Out">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span>Sign Out</span>
            </button>
            <button type="button" id="rpInlineMenu" class="rp-inline-menu" aria-label="Menu" onclick="rpOpenOffcanvas()">
                <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>"""
if 'id="rpInlineMenu"' in s:
    print('1) inline menu already present')
elif old_nav_end in s:
    s = s.replace(old_nav_end, new_nav_end, 1)
    print('1) inline hamburger added to topbar-actions')
else:
    print('1) WARN sign-out anchor not found')

# 5) Quiz History — add "Take a New Quiz" button in its footer
old_qh_foot = """        <div style="margin-top:26px;padding-top:10px">
            <button class="quiz-history-close" style="padding:12px 32px;font-size:14px" onclick="var bd=this.closest('.share-backdrop');var m=bd.querySelector('.quiz-history-modal');if(m)m.style.animation='quizZoomOut 1s cubic-bezier(0.55,0,1,0.45) forwards';bd.style.transition='opacity 1.5s ease';bd.style.opacity='0';setTimeout(function(){bd.remove()},1500)">Close</button>
        </div>`;"""
new_qh_foot = """        <div style="margin-top:26px;padding-top:10px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
            <button class="qh-take-new-btn" style="padding:12px 28px;font-size:14px;font-weight:700;border:none;border-radius:12px;background:linear-gradient(135deg,var(--brand-500),var(--brand-700));color:#fff;cursor:pointer;font-family:inherit;box-shadow:0 6px 20px rgba(var(--brand-500-rgb),0.35)" onclick="var bd=this.closest('.share-backdrop');if(bd)bd.remove();setTimeout(function(){startPopQuiz()},250)">Take a New Quiz</button>
            <button class="quiz-history-close" style="padding:12px 28px;font-size:14px" onclick="var bd=this.closest('.share-backdrop');var m=bd.querySelector('.quiz-history-modal');if(m)m.style.animation='quizZoomOut 1s cubic-bezier(0.55,0,1,0.45) forwards';bd.style.transition='opacity 1.5s ease';bd.style.opacity='0';setTimeout(function(){bd.remove()},1500)">Close</button>
        </div>`;"""
if 'qh-take-new-btn' in s:
    print('5) Take a New Quiz already present')
elif old_qh_foot in s:
    s = s.replace(old_qh_foot, new_qh_foot, 1)
    print('5) Take a New Quiz button added to history modal')
else:
    print('5) WARN quiz history foot anchor not found')

# 6) Bottom bar — auto-show 1.2s after load (belt-and-suspenders)
old_scroll = """    // Bottom bar scroll-trigger (mobile only)
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
    }"""
new_scroll = """    // Bottom bar scroll-trigger (mobile only) + hard fallback
    if (bar && window.matchMedia('(max-width: 768px)').matches) {
        let shown = false;
        const show = () => {
            if (shown) return;
            shown = true;
            bar.classList.add('open');
            bar.setAttribute('aria-hidden', 'false');
        };
        window.addEventListener('scroll', () => {
            if (window.scrollY > 60) show();
        }, { passive: true });
        // Always show after 1.2s so the bar is reachable even without scroll
        setTimeout(show, 1200);
    }"""
if 'Always show after 1.2s' in s:
    print('6) bottom bar auto-show already in place')
elif old_scroll in s:
    s = s.replace(old_scroll, new_scroll, 1)
    print('6) bottom bar now auto-shows 1.2s after load')
else:
    print('6) WARN scroll-trigger anchor not found')

# CSS block covering all mobile-only structural tweaks
block = """
<style id="v378ReportMobileFix">
@media (max-width: 768px) {
    /* 1+2) Header on mobile: only logo + inline hamburger */
    .topbar .topbar-actions > button:not(#rpInlineMenu),
    .topbar .topbar-actions > a.btn,
    .topbar .topbar-actions > .tb-more { display: none !important; }
    .rp-inline-menu {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 44px; height: 44px;
        padding: 0;
        border: 0 !important;
        background: transparent !important;
        color: #ffffff !important;
        cursor: pointer;
        margin-left: auto;
    }
    .rp-inline-menu:hover { opacity: 0.85; }
    .rp-inline-menu svg { stroke: #ffffff; }

    /* Kill the floating hamburger — inline version takes over */
    .rp-mobile-menu-btn { display: none !important; }

    /* 3) Double the topbar brand logo: 26 -> 52 */
    .topbar-brand-logo {
        height: 52px !important;
        max-width: 240px !important;
    }
    .topbar { padding: 6px 14px !important; }

    /* 4) Black brand gradient for Public/Private + Quiz History */
    .mpa-btn {
        background: linear-gradient(135deg,
            #0f172a 0%,
            #111827 40%,
            var(--brand-grad-dark, #0a1f40) 100%) !important;
        border: 1px solid rgba(255,255,255,0.14) !important;
        color: #ffffff !important;
        box-shadow:
            0 10px 28px rgba(0,0,0,0.35),
            inset 0 1px 0 rgba(255,255,255,0.10) !important;
    }
    .mpa-btn:hover, .mpa-btn:active {
        background: linear-gradient(135deg,
            #111827 0%,
            #1e293b 40%,
            var(--brand-grad-dark, #0a1f40) 100%) !important;
        border-color: rgba(255,255,255,0.24) !important;
    }
    .mpa-btn svg { stroke: #ffffff !important; }
    .mpa-share-btn.is-public {
        background: linear-gradient(135deg, #065f46 0%, #0f172a 80%) !important;
        border-color: rgba(16,185,129,0.45) !important;
    }

    /* Keep the inline Public label concise ("Public" / "Private") */
}
[data-theme="dark"] .rp-inline-menu svg { stroke: #ffffff; }
</style>
"""
if 'id="v378ReportMobileFix"' in s:
    print('CSS block already present')
else:
    last_body = s.rfind('</body>')
    s = s[:last_body] + block + s[last_body:]
    print('CSS appended')

# Replace verbose "Public — manage / Private — share" labels with plain ones
s = s.replace(
    "<?= $isPublic ? 'Public — manage' : 'Private — share' ?>",
    "<?= $isPublic ? 'Public' : 'Private' ?>",
    1
)
print('mpa-share-btn label simplified to Public/Private')

open(rp, 'w').write(s)
print('DONE')
