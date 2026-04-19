/**
 * Assembler — reusable "assemble-on-view" animation utility.
 *
 * Philosophy: when the user scrolls to a piece of content it shouldn't
 * just pop in — it should assemble itself over 2.5-3s. Cards slide up
 * and fade in with a diagonal gradient shine sweeping across them.
 * Numbers count up from 0. Charts re-animate as they enter view.
 *
 * Use across the app for any "wow this feels polished" page.
 *
 *   Assembler.observe(el, { kind: 'card' });  // fade + slide + shine
 *   Assembler.countUp(el, 4296, { duration: 2200, suffix: '' });
 *   Assembler.countUpText(el, '$12,430.58');  // auto-parses prefix/decimals
 *
 * Safe to call multiple times for the same element — second call is a no-op.
 */
(function () {
    const Assembler = {
        _io: null,

        _getIO() {
            if (this._io) return this._io;
            this._io = new IntersectionObserver((entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting && !entry.target._asmDone) {
                        entry.target._asmDone = true;
                        entry.target.classList.add('is-assembled');
                        if (typeof entry.target._asmCallback === 'function') {
                            try { entry.target._asmCallback(entry.target); } catch (e) {}
                        }
                        this._io.unobserve(entry.target);
                    }
                }
            }, {
                threshold: 0.12,
                rootMargin: '0px 0px -40px 0px'
            });
            return this._io;
        },

        /**
         * Observe an element for entrance animation.
         * @param {HTMLElement} el
         * @param {Object}  opts
         * @param {string}  opts.kind   'card' | 'chart' | 'row' | 'text'  (just tags a class)
         * @param {number}  opts.delay  extra ms delay before animation starts
         * @param {Function} opts.onEnter callback fired when element enters view
         */
        observe(el, opts = {}) {
            if (!el || el._asmObs) return;
            el._asmObs = true;
            el.classList.add('asm', 'asm-kind-' + (opts.kind || 'card'));
            if (opts.delay) el.style.setProperty('--asm-delay', opts.delay + 'ms');
            if (opts.onEnter) el._asmCallback = opts.onEnter;
            // If the element is already in view when we start observing, the
            // IntersectionObserver will fire synchronously on the next tick.
            this._getIO().observe(el);
        },

        /**
         * Force-trigger the assemble state on an element (bypass scroll).
         * Useful when a section is shown and we want cards to animate in
         * regardless of scroll position.
         */
        trigger(el) {
            if (!el || el._asmDone) return;
            el._asmDone = true;
            el.classList.add('is-assembled');
            if (typeof el._asmCallback === 'function') {
                try { el._asmCallback(el); } catch (e) {}
            }
            if (this._io) this._io.unobserve(el);
        },

        /**
         * Count up a numeric value from 0 -> target over duration.
         */
        countUp(el, target, opts = {}) {
            if (!el || target == null || isNaN(target)) return;
            const from = Number(el.dataset.asmFrom) || 0;
            const to = Number(target);
            const duration = opts.duration || 2400;
            const decimals = opts.decimals != null ? opts.decimals : 0;
            const prefix = opts.prefix || '';
            const suffix = opts.suffix || '';
            const sep = opts.separator === undefined ? ',' : opts.separator;

            // Cancel any prior tween on this element
            if (el._asmRaf) cancelAnimationFrame(el._asmRaf);

            const start = performance.now();
            const easeOut = (t) => 1 - Math.pow(1 - t, 3);
            const format = (v) => {
                let s = decimals > 0 ? v.toFixed(decimals) : String(Math.round(v));
                if (sep && decimals === 0) s = s.replace(/\B(?=(\d{3})+(?!\d))/g, sep);
                else if (sep && decimals > 0) {
                    const [intPart, decPart] = s.split('.');
                    s = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, sep) + '.' + decPart;
                }
                return prefix + s + suffix;
            };

            const step = (now) => {
                const raw = Math.min(1, (now - start) / duration);
                const t = easeOut(raw);
                el.textContent = format(from + (to - from) * t);
                if (raw < 1) {
                    el._asmRaf = requestAnimationFrame(step);
                } else {
                    el._asmRaf = null;
                    el.textContent = format(to);
                }
            };
            el._asmRaf = requestAnimationFrame(step);
        },

        /**
         * Smart-count a formatted string — e.g. "$1,234.56", "4,296", "3h", "$0.00",
         * "21 min", "2m 4s". Parses the first numeric token, counts it up, and
         * reconstructs the surrounding text.
         */
        countUpText(el, fullText, opts = {}) {
            if (!el) return;
            if (fullText == null || fullText === '') { el.textContent = fullText; return; }
            const text = String(fullText);

            // Find the first numeric chunk with optional decimal and commas.
            const match = text.match(/-?\d[\d,]*(?:\.\d+)?/);
            if (!match) { el.textContent = text; return; }

            const before = text.slice(0, match.index);
            const after  = text.slice(match.index + match[0].length);
            const rawNum = match[0].replace(/,/g, '');
            const target = Number(rawNum);
            if (isNaN(target)) { el.textContent = text; return; }

            const decimals = (rawNum.split('.')[1] || '').length;
            this.countUp(el, target, {
                duration: opts.duration,
                decimals,
                prefix: before,
                suffix: after,
                separator: match[0].includes(',') ? ',' : ''
            });
        }
    };

    window.Assembler = Assembler;
})();
