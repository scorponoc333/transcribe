/**
 * Autocomplete Module
 * Reusable dropdown autocomplete for email/contact inputs
 */
const Autocomplete = {
    _instances: new Map(),
    _debounceTimers: new Map(),

    /**
     * Attach autocomplete to an input element
     * @param {HTMLInputElement} input
     * @param {Object} options
     * @param {Function} options.onSearch - async (query) => [{name, email}]
     * @param {Function} options.onSelect - (contact, input) => void
     * @param {boolean} options.multiValue - treat input as comma-separated
     */
    attach(input, options) {
        if (this._instances.has(input)) this.detach(input);

        const dropdown = document.createElement('div');
        dropdown.className = 'autocomplete-dropdown';
        dropdown.style.display = 'none';
        input.parentElement.style.position = 'relative';
        input.parentElement.appendChild(dropdown);

        let selectedIndex = -1;

        const getQuery = () => {
            if (options.multiValue) {
                const parts = input.value.split(',');
                return (parts[parts.length - 1] || '').trim();
            }
            return input.value.trim();
        };

        const showResults = (results) => {
            if (!results.length) {
                dropdown.style.display = 'none';
                return;
            }
            selectedIndex = -1;
            dropdown.innerHTML = results.map((r, i) => `
                <div class="autocomplete-item" data-index="${i}">
                    <div class="autocomplete-item-name">${App.escapeHtml(r.name || r.email)}</div>
                    ${r.name ? `<div class="autocomplete-item-email">${App.escapeHtml(r.email)}</div>` : ''}
                </div>
            `).join('');
            dropdown.style.display = '';

            // Click handlers
            dropdown.querySelectorAll('.autocomplete-item').forEach((el, i) => {
                el.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    options.onSelect(results[i], input);
                    dropdown.style.display = 'none';
                });
            });
        };

        const updateHighlight = () => {
            dropdown.querySelectorAll('.autocomplete-item').forEach((el, i) => {
                el.classList.toggle('highlighted', i === selectedIndex);
            });
        };

        const onInput = () => {
            const query = getQuery();
            if (query.length < 1) {
                dropdown.style.display = 'none';
                return;
            }

            clearTimeout(this._debounceTimers.get(input));
            this._debounceTimers.set(input, setTimeout(async () => {
                try {
                    const results = await options.onSearch(query);
                    this._instances.get(input).results = results;
                    showResults(results);
                } catch {
                    dropdown.style.display = 'none';
                }
            }, 250));
        };

        const onKeydown = (e) => {
            const results = this._instances.get(input)?.results || [];
            if (!results.length || dropdown.style.display === 'none') return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, results.length - 1);
                updateHighlight();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, 0);
                updateHighlight();
            } else if (e.key === 'Enter' && selectedIndex >= 0) {
                e.preventDefault();
                options.onSelect(results[selectedIndex], input);
                dropdown.style.display = 'none';
            } else if (e.key === 'Escape') {
                dropdown.style.display = 'none';
            }
        };

        const onBlur = () => {
            setTimeout(() => { dropdown.style.display = 'none'; }, 200);
        };

        input.addEventListener('input', onInput);
        input.addEventListener('keydown', onKeydown);
        input.addEventListener('blur', onBlur);

        this._instances.set(input, {
            dropdown,
            results: [],
            handlers: { onInput, onKeydown, onBlur }
        });
    },

    detach(input) {
        const instance = this._instances.get(input);
        if (!instance) return;

        input.removeEventListener('input', instance.handlers.onInput);
        input.removeEventListener('keydown', instance.handlers.onKeydown);
        input.removeEventListener('blur', instance.handlers.onBlur);
        instance.dropdown.remove();
        this._instances.delete(input);
        clearTimeout(this._debounceTimers.get(input));
        this._debounceTimers.delete(input);
    }
};
