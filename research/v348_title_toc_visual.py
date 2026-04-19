#!/usr/bin/env python3
"""v3.48 — three surgical fixes on report.php:

1) Title-edit input breaks the hero card (width:90vw overflows the
   .jaihero-title's 720px max-width). Switch to width:100% +
   max-width:100% + box-sizing:border-box so it always fits inside
   its h1 container.

2) TOC subtitle (the report name printed under the ToC logo) is stale
   after the user edits the title — PHP server-rendered once at load.
   Give the <p class="toc-subtitle"> an id, and in startEditTitle()'s
   save() handler, update that element too when the server save
   succeeds. Keeps the PDF's ToC in sync with the hero title.

3) PDF only: move Visual Insights to sit just above Full Transcript.
   Hook beforeprint/afterprint to relocate #chartsSection right
   before the transcript section, then put it back afterprint. On-
   screen order stays unchanged.
"""
rp_path = '/var/www/transcribe/api/report.php'
rp = open(rp_path).read()

# ── 1) Title-edit input fix ──
old_input_css = """.title-edit-input {
    width: 90vw;
    max-width: 1100px;
    min-width: 360px;
    padding: 16px 22px;
    background: rgba(255,255,255,0.12);
    border: 2px solid rgba(255,255,255,0.4);
    border-radius: 12px;
    color: #fff;
    font-size: 26px;
    font-weight: 800;
    text-align: center;
    font-family: inherit;
    letter-spacing: -0.3px;
    line-height: 1.3;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}"""
new_input_css = """.title-edit-input {
    width: 100%;
    max-width: 100%;
    min-width: 0;
    box-sizing: border-box;
    padding: 16px 22px;
    background: rgba(255,255,255,0.12);
    border: 2px solid rgba(255,255,255,0.4);
    border-radius: 12px;
    color: #fff;
    font-size: 26px;
    font-weight: 800;
    text-align: center;
    font-family: inherit;
    letter-spacing: -0.3px;
    line-height: 1.3;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}
#jaiHeroSection .jaihero-title[data-editing="true"] {
    max-width: 720px;
    width: 100%;
}"""
if 'width: 100%;\n    max-width: 100%;\n    min-width: 0;\n    box-sizing: border-box;' in rp:
    print('1) title-edit-input already fixed')
elif old_input_css in rp:
    rp = rp.replace(old_input_css, new_input_css, 1)
    print('1) title-edit-input width/overflow fixed')
else:
    print('1) WARN anchor not found — leaving as-is')

# ── 2) TOC subtitle gets an id + JS save handler updates it ──
old_toc_sub = '<p class="toc-subtitle"><?= e($title) ?></p>'
new_toc_sub = '<p class="toc-subtitle" id="tocReportTitle"><?= e($title) ?></p>'
if 'id="tocReportTitle"' in rp:
    print('2a) TOC subtitle already has id')
elif old_toc_sub in rp:
    rp = rp.replace(old_toc_sub, new_toc_sub, 1)
    print('2a) TOC subtitle now has id tocReportTitle')
else:
    print('2a) WARN TOC subtitle anchor not found')

# In startEditTitle()'s save() — after setting h1.textContent = newTitle,
# also update the ToC subtitle so the PDF prints the new name.
old_save_success = """            if (!r.ok) throw new Error(d.error || 'Failed');
            h1.textContent = newTitle;"""
new_save_success = """            if (!r.ok) throw new Error(d.error || 'Failed');
            h1.textContent = newTitle;
            const tocSub = document.getElementById('tocReportTitle');
            if (tocSub) tocSub.textContent = newTitle;
            document.title = newTitle;"""
if "document.getElementById('tocReportTitle')" in rp:
    print('2b) save handler already updates TOC')
elif old_save_success in rp:
    rp = rp.replace(old_save_success, new_save_success, 1)
    print('2b) save handler now syncs TOC + document.title')
else:
    print('2b) WARN save-handler anchor not found')

# ── 3) PDF-only reorder: Visual Insights -> just above Transcript ──
reorder_block = """
<script>
/* v3.48 — PDF print reorder: move Visual Insights to sit just above
   Full Transcript at print time, restore after print. Screen order
   unchanged. */
(function () {
    let _chartsParent = null, _chartsNext = null;
    function relocate() {
        const charts = document.getElementById('chartsSection');
        const transcriptBox = document.getElementById('transcriptBox');
        if (!charts || !transcriptBox) return;
        const transcriptSection = transcriptBox.closest('.report-section');
        if (!transcriptSection) return;
        _chartsParent = charts.parentNode;
        _chartsNext = charts.nextSibling;
        transcriptSection.parentNode.insertBefore(charts, transcriptSection);
    }
    function restore() {
        const charts = document.getElementById('chartsSection');
        if (!charts || !_chartsParent) return;
        _chartsParent.insertBefore(charts, _chartsNext);
        _chartsParent = null; _chartsNext = null;
    }
    window.addEventListener('beforeprint', relocate);
    window.addEventListener('afterprint', restore);
    // Edge/Chrome print-preview reliability: also listen via matchMedia
    if (window.matchMedia) {
        const mq = window.matchMedia('print');
        const handler = (e) => { if (e.matches) relocate(); else restore(); };
        if (mq.addEventListener) mq.addEventListener('change', handler);
        else if (mq.addListener) mq.addListener(handler);
    }
})();
</script>
"""
if 'v3.48 — PDF print reorder' in rp:
    print('3) reorder script already present')
else:
    idx = rp.rfind('</body>')
    rp = rp[:idx] + reorder_block + rp[idx:]
    print('3) visual-insights reorder script appended')

open(rp_path, 'w').write(rp)
print('DONE')
