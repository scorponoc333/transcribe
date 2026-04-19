#!/usr/bin/env python3
"""v3.112 — pixel-match the off-canvas footer buttons + tighten
hamburger spacing.

Root cause for why Sign Out / gear looked different: my v3.89 copy
grabbed the .offcanvas* CSS from style.css but the actual
.offcanvas-signout-btn and .offcanvas-gear-btn rules live inline
in index.html, not style.css. So report/quiz-report never got
those styles at all — they fell back to default button rendering.

This patch:
  - Appends the full signout-btn + gear-btn + foot flex rules
    (copied verbatim from index.html) to report.php and
    quiz-report.php with !important so any upstream conflict loses.
  - Normalizes the offcanvas-logo opacity and height with
    !important in case a stale rule wins.
  - Nudges the unified hamburger to right:13px to mirror the
    logo's 13px visual left offset.
"""
CSS = """
<style id="v3112OffcanvasParity">
/* Pixel-match to index.html's off-canvas footer buttons */
.offcanvas-foot {
    display: flex !important;
    gap: 10px !important;
    align-items: stretch !important;
    padding: 18px 24px !important;
}
.offcanvas-signout-btn {
    flex: 1 1 auto !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 10px !important;
    padding: 14px 18px !important;
    border-radius: 12px !important;
    border: 1px solid rgba(255,255,255,0.18) !important;
    background: linear-gradient(135deg,
        var(--brand-600) 0%,
        var(--brand-700) 55%,
        var(--brand-grad-mid) 100%) !important;
    color: #fff !important;
    font-family: inherit !important;
    font-size: 14px !important;
    font-weight: 700 !important;
    letter-spacing: 0.4px !important;
    text-transform: uppercase !important;
    cursor: pointer !important;
    transition: all 0.25s cubic-bezier(0.22, 1, 0.36, 1) !important;
    box-shadow:
        0 6px 20px rgba(var(--brand-500-rgb), 0.28),
        inset 0 1px 0 rgba(255,255,255,0.14) !important;
}
.offcanvas-signout-btn:hover {
    transform: translateY(-1px) !important;
    box-shadow:
        0 10px 28px rgba(var(--brand-500-rgb), 0.4),
        inset 0 1px 0 rgba(255,255,255,0.18) !important;
}
.offcanvas-signout-btn svg { stroke: #fff !important; flex-shrink: 0 !important; }
.offcanvas-gear-btn {
    flex: 0 0 auto !important;
    width: 52px !important;
    min-height: 46px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border-radius: 12px !important;
    border: 1px solid rgba(255,255,255,0.18) !important;
    background: rgba(255,255,255,0.06) !important;
    color: rgba(255,255,255,0.85) !important;
    cursor: pointer !important;
    transition: background 0.2s ease, transform 0.2s ease !important;
}
.offcanvas-gear-btn:hover {
    background: rgba(255,255,255,0.14) !important;
    transform: rotate(30deg) !important;
    color: #fff !important;
}
/* Force the drawer logo to match index */
.offcanvas-logo {
    height: 39px !important;
    width: auto !important;
    object-fit: contain !important;
    opacity: 0.96 !important;
    filter: brightness(0) invert(1) drop-shadow(0 0 14px rgba(var(--brand-300-rgb), 0.45)) !important;
}
/* Hamburger: right offset mirrors the logo's visual left (13px) */
@media (max-width: 768px) {
    .unified-menu-btn {
        right: 13px !important;
    }
}
</style>
"""

for path in ('/var/www/transcribe/api/report.php', '/var/www/transcribe/api/quiz-report.php'):
    s = open(path).read()
    if 'id="v3112OffcanvasParity"' in s:
        print(f'{path}: already applied')
        continue
    cb = s.rfind('</body>')
    s = s[:cb] + CSS + s[cb:]
    open(path, 'w').write(s)
    print(f'{path}: signout/gear/foot/logo parity applied + hamburger right:13')
