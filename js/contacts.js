/**
 * Contacts Management Module
 * Full CRUD for contacts with search, pagination, and CSV import
 */
const Contacts = {
    contacts: [],
    currentPage: 1,
    totalPages: 1,
    searchQuery: '',
    limit: 20,
    _searchTimer: null,

    init() {
        // Search input with debounce
        const searchInput = document.getElementById('contactsSearch');
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                clearTimeout(this._searchTimer);
                this._searchTimer = setTimeout(() => {
                    this.searchQuery = searchInput.value.trim();
                    this.currentPage = 1;
                    this.load();
                }, 300);
            });
        }

        // Add contact button
        document.getElementById('contactsAddBtn')?.addEventListener('click', () => this.openAddModal());

        // Import CSV button → trigger file input
        document.getElementById('contactsImportBtn')?.addEventListener('click', () => {
            document.getElementById('contactsCsvInput')?.click();
        });

        // CSV file selected
        document.getElementById('contactsCsvInput')?.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) this.importCSV(file);
            e.target.value = ''; // reset for re-upload
        });

        // Modal buttons
        document.getElementById('contactModalClose')?.addEventListener('click', () => this.closeModal());
        document.getElementById('contactModalCancelBtn')?.addEventListener('click', () => this.closeModal());
        document.getElementById('contactModalSaveBtn')?.addEventListener('click', () => this.saveContact());

        // Close modal on overlay click
        document.getElementById('contactModal')?.addEventListener('click', (e) => {
            if (e.target.id === 'contactModal') this.closeModal();
        });

        // Back button
        document.getElementById('contactsBackBtn')?.addEventListener('click', () => {
            App.showSection('upload');
        });
    },

    async load() {
        try {
            const res = await API.listContacts(this.currentPage, this.limit, this.searchQuery);
            this.contacts = res.data || [];
            this.totalPages = res.pages || 1;
            this.renderTable();
            this.renderPagination(res.total, res.page, res.limit, res.pages);
        } catch (err) {
            console.error('Contacts load error:', err);
            App.showToast('Failed to load contacts: ' + err.message, 'error');
        }
    },

    renderTable() {
        const tbody = document.getElementById('contactsTableBody');
        if (!tbody) return;

        if (!this.contacts.length) {
            tbody.innerHTML = `<tr><td colspan="5" class="text-center t-muted py-8">
                ${this.searchQuery ? 'No contacts match your search.' : 'No contacts yet. Add contacts or import a CSV file.'}
            </td></tr>`;
            return;
        }

        tbody.innerHTML = this.contacts.map(c => `
            <tr data-id="${c.id}">
                <td class="contact-name-cell">
                    <div class="contact-avatar">${this._initials(c.name)}</div>
                    <span>${this._esc(c.name || '—')}</span>
                </td>
                <td class="contact-email-cell">${this._esc(c.email)}</td>
                <td class="contact-company-cell">${this._esc(c.company || '—')}</td>
                <td class="contact-used-cell">${c.use_count || 0}</td>
                <td class="contact-actions-cell">
                    <button class="contact-action-btn contact-edit-btn" title="Edit" onclick="Contacts.openEditModal(${c.id})">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                    <button class="contact-action-btn contact-delete-btn" title="Delete" onclick="Contacts.deleteContact(${c.id})">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                    </button>
                </td>
            </tr>
        `).join('');
    },

    renderPagination(total, page, limit, pages) {
        const container = document.getElementById('contactsPagination');
        if (!container || pages <= 1) {
            if (container) container.innerHTML = '';
            return;
        }

        let html = '<div class="pagination-controls">';
        html += `<button class="pagination-btn" ${page <= 1 ? 'disabled' : ''} onclick="Contacts.goToPage(${page - 1})">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        </button>`;

        const start = Math.max(1, page - 2);
        const end = Math.min(pages, page + 2);
        for (let i = start; i <= end; i++) {
            html += `<button class="pagination-btn ${i === page ? 'active' : ''}" onclick="Contacts.goToPage(${i})">${i}</button>`;
        }

        html += `<button class="pagination-btn" ${page >= pages ? 'disabled' : ''} onclick="Contacts.goToPage(${page + 1})">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
        </button>`;

        html += `<span class="pagination-info">${total} contact${total !== 1 ? 's' : ''}</span>`;
        html += '</div>';
        container.innerHTML = html;
    },

    goToPage(page) {
        if (page < 1 || page > this.totalPages) return;
        this.currentPage = page;
        this.load();
    },

    openAddModal() {
        document.getElementById('contactModalTitle').textContent = 'Add Contact';
        document.getElementById('contactEditId').value = '';
        document.getElementById('contactName').value = '';
        document.getElementById('contactEmail').value = '';
        document.getElementById('contactCompany').value = '';
        document.getElementById('contactModal').classList.add('active');
        document.getElementById('contactName').focus();
    },

    openEditModal(id) {
        const contact = this.contacts.find(c => c.id === id);
        if (!contact) return;

        document.getElementById('contactModalTitle').textContent = 'Edit Contact';
        document.getElementById('contactEditId').value = id;
        document.getElementById('contactName').value = contact.name || '';
        document.getElementById('contactEmail').value = contact.email || '';
        document.getElementById('contactCompany').value = contact.company || '';
        document.getElementById('contactModal').classList.add('active');
        document.getElementById('contactName').focus();
    },

    closeModal() {
        document.getElementById('contactModal').classList.remove('active');
    },

    async saveContact() {
        const id      = document.getElementById('contactEditId').value;
        const name    = document.getElementById('contactName').value.trim();
        const email   = document.getElementById('contactEmail').value.trim();
        const company = document.getElementById('contactCompany').value.trim();

        if (!email) {
            App.showToast('Email address is required', 'error');
            return;
        }

        try {
            if (id) {
                await API.updateContact({ id: parseInt(id), name, email, company });
                App.showToast('Contact updated', 'success');
            } else {
                await API.createContact({ name, email, company });
                App.showToast('Contact added', 'success');
            }
            this.closeModal();
            this.load();
        } catch (err) {
            App.showToast(err.message || 'Failed to save contact', 'error');
        }
    },

    async deleteContact(id) {
        if (!confirm('Delete this contact? This cannot be undone.')) return;

        try {
            await API.deleteContact(id);
            App.showToast('Contact deleted', 'success');
            this.load();
        } catch (err) {
            App.showToast(err.message || 'Failed to delete contact', 'error');
        }
    },

    async importCSV(file) {
        try {
            App.showToast('Importing contacts...', 'info');
            const res = await API.importContactsCsv(file);
            App.showToast(`Imported ${res.imported} contact${res.imported !== 1 ? 's' : ''}${res.skipped ? ` (${res.skipped} skipped)` : ''}`, 'success');
            this.load();
        } catch (err) {
            App.showToast(err.message || 'Failed to import CSV', 'error');
        }
    },

    // ---- Helpers ----

    _initials(name) {
        if (!name) return '?';
        const parts = name.trim().split(/\s+/);
        if (parts.length >= 2) {
            return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
        }
        return name[0].toUpperCase();
    },

    _esc(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
};
