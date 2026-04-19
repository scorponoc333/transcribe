#!/usr/bin/env python3
"""v3.58 — scroll-in staggers for the remaining report sections.

v3.44 covered chart re-entry, section-title typewriter, TL;DR type,
learning-report-table rows, and concept-card / exercise-header tiles.
What was still missing (per user): core_insights, statistics (with
gradient flash), products_tools, roadmap, action_items, key_quotes,
practical_exercises (as a whole card), further_learning.

This adds one unified "assemble" pattern with per-section selectors
and a brand-gradient flash overlay on stat cards.
"""
import re
rp = '/var/www/transcribe/api/report.php'
s = open(rp).read()

block = r"""
<style id="v358SectionStaggers">
@media screen {
/* Generic assemble: fade + rise + unblur, staggered. */
.v358-asm {
    opacity: 0;
    transform: translateY(14px);
    filter: blur(6px);
    transition:
        opacity 0.55s ease,
        transform 0.7s cubic-bezier(0.22, 1, 0.36, 1),
        filter 0.55s ease;
    transition-delay: var(--v358-delay, 0ms);
    position: relative;
}
.v358-asm.v358-in {
    opacity: 1;
    transform: translateY(0);
    filter: blur(0);
}
@media (prefers-reduced-motion: reduce) {
    .v358-asm { opacity: 1 !important; transform: none !important; filter: none !important; }
}

/* Gradient flash — applied to stat cards on reveal */
.v358-flash::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(120deg,
        transparent 0%,
        rgba(var(--brand-400-rgb, 96,165,250), 0.0) 35%,
        rgba(var(--brand-400-rgb, 96,165,250), 0.55) 50%,
        rgba(var(--brand-400-rgb, 96,165,250), 0.0) 65%,
        transparent 100%);
    transform: translateX(-130%) skewX(-18deg);
    opacity: 0;
    pointer-events: none;
    border-radius: inherit;
    transition:
        transform 0.85s cubic-bezier(0.4, 0, 0.2, 1),
        opacity 0.3s ease;
    transition-delay: calc(var(--v358-delay, 0ms) + 180ms);
}
.v358-asm.v358-in.v358-flash::after {
    transform: translateX(130%) skewX(-18deg);
    opacity: 1;
}
/* After the flash finishes, fade it fully out so it doesn't sit there */
.v358-asm.v358-in.v358-flash.v358-flash-done::after {
    opacity: 0;
}
}
</style>
<script>
/* v3.58 — observe-and-stagger the remaining sections */
(function () {
    if (!('IntersectionObserver' in window)) return;

    const GROUPS = [
        // selector, staggerStepMs, optional extra class
        { sel: '.report-section ul.bullet-list > li',          step: 90 },
        { sel: '.report-section ul.action-list > li',          step: 90 },
        { sel: '.stats-report-grid .stat-report-card',         step: 110, extra: 'v358-flash' },
        { sel: '.report-section .resource-report-card',        step: 110 },
        { sel: '.report-section .roadmap-report-item',         step: 130 },
        { sel: '.report-section .report-quote',                step: 140 },
        { sel: '.report-section .exercise-card',               step: 140 },
        { sel: '.report-section .timeline-report-item',        step: 120 },
    ];

    const io = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting && !entry.target._v358Done) {
                entry.target._v358Done = true;
                entry.target.classList.add('v358-in');
                // flash cleanup
                if (entry.target.classList.contains('v358-flash')) {
                    setTimeout(() => entry.target.classList.add('v358-flash-done'), 1400);
                }
                io.unobserve(entry.target);
            }
        });
    }, { rootMargin: '0px 0px -60px 0px', threshold: 0.08 });

    function group(els, step, extraCls) {
        // Group by the closest .report-section so stagger resets per-section.
        const perParent = new Map();
        els.forEach((el) => {
            const parent = el.closest('.report-section') || el.parentNode;
            if (!perParent.has(parent)) perParent.set(parent, []);
            perParent.get(parent).push(el);
        });
        perParent.forEach((list) => {
            list.forEach((el, idx) => {
                if (el._v358Applied) return;
                el._v358Applied = true;
                el.classList.add('v358-asm');
                if (extraCls) el.classList.add(extraCls);
                el.style.setProperty('--v358-delay', (idx * step) + 'ms');
                io.observe(el);
            });
        });
    }

    function init() {
        GROUPS.forEach((g) => {
            try { group(document.querySelectorAll(g.sel), g.step, g.extra || null); }
            catch (e) {}
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
"""

if 'id="v358SectionStaggers"' in s:
    print('v3.58 already present')
else:
    idx = s.rfind('</body>')
    s = s[:idx] + block + s[idx:]
    open(rp, 'w').write(s)
    print('v3.58 section staggers appended')

print('DONE')
