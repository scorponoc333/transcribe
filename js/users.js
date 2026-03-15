/**
 * Users Management Module (Admin only)
 * CRUD operations for user accounts with search, pagination, role management
 */
const Users = {
    currentPage: 1,
    totalPages: 1,
    searchQuery: '',

    init() {
        this.bindEvents();
    },

    bindEvents() {
        // Search
        const searchInput = document.getElementById('usersSearchInput');
        const searchBtn = document.getElementById('usersSearchBtn');
        if (searchBtn) searchBtn.addEventListener('click', () => this.search());
        if (searchInput) searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') this.search();
        });

        // Add user button
        const addBtn = document.getElementById('addUserBtn');
        if (addBtn) addBtn.addEventListener('click', () => this.openAddModal());

        // Modal close
        const closeBtn = document.getElementById('userModalClose');
        if (closeBtn) closeBtn.addEventListener('click', () => this.closeModal());
        const modal = document.getElementById('userModal');
        if (modal) modal.addEventListener('click', (e) => {
            if (e.target === modal) this.closeModal();
        });

        // Save user
        const saveBtn = document.getElementById('saveUserBtn');
        if (saveBtn) saveBtn.addEventListener('click', () => this.saveUser());

        // Back button
        const backBtn = document.getElementById('usersBackBtn');
        if (backBtn) backBtn.addEventListener('click', () => App.showSection('upload'));

        // Reset password modal — click outside to close
        const resetPwModal = document.getElementById('resetPwModal');
        if (resetPwModal) resetPwModal.addEventListener('click', (e) => {
            if (e.target === resetPwModal) this.closeResetModal();
        });

        // Escape key — close any open modal
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeModal();
                this.closeResetModal();
            }
        });
    },

    search() {
        this.currentPage = 1;
        this.searchQuery = document.getElementById('usersSearchInput')?.value.trim() || '';
        this.load();
    },

    async load(page = 1) {
        this.currentPage = page;
        const tbody = document.getElementById('usersTableBody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="5" class="text-center t-muted py-8">Loading...</td></tr>';

        try {
            const result = await API.listUsers({
                page,
                limit: 20,
                q: this.searchQuery || undefined
            });
            this.totalPages = result.total_pages || 1;
            this.renderTable(result.data || []);
            this.renderPagination(result);
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="5" class="text-center t-muted py-8">Error: ${App.escapeHtml(err.message)}</td></tr>`;
        }
    },

    renderTable(rows) {
        const tbody = document.getElementById('usersTableBody');
        if (!rows || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center t-muted py-8">No users found</td></tr>';
            return;
        }

        tbody.innerHTML = rows.map(user => {
            const roleBadge = this._roleBadge(user.role);
            const statusBadge = user.is_active
                ? '<span class="badge badge-active">Active</span>'
                : '<span class="badge badge-inactive">Inactive</span>';
            const lastLogin = user.last_login_at
                ? new Date(user.last_login_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                : '<span class="t-muted">Never</span>';

            return `<tr>
                <td>
                    <div class="user-cell-name">${App.escapeHtml(user.name)}</div>
                    <div class="user-cell-email">${App.escapeHtml(user.email)}</div>
                </td>
                <td>${roleBadge}</td>
                <td>${statusBadge}</td>
                <td>${lastLogin}</td>
                <td class="cell-actions">
                    <button class="btn-icon btn-xs" onclick="Users.openEditModal(${user.id})" title="Edit">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                    <button class="btn-icon btn-xs" onclick="Users.resetPassword(${user.id}, '${App.escapeHtml(user.name)}')" title="Reset Password">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </button>
                    <button class="btn-icon btn-xs ${user.is_active ? 'btn-warning-icon' : 'btn-success-icon'}" onclick="Users.toggleActive(${user.id}, ${user.is_active ? 0 : 1})" title="${user.is_active ? 'Deactivate' : 'Activate'}">
                        ${user.is_active
                            ? '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>'
                            : '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'
                        }
                    </button>
                </td>
            </tr>`;
        }).join('');
    },

    renderPagination(result) {
        const container = document.getElementById('usersPagination');
        if (!container) return;

        if (!result.total_pages || result.total_pages <= 1) {
            container.innerHTML = `<span class="t-muted text-sm">${result.total || 0} user${(result.total || 0) !== 1 ? 's' : ''}</span>`;
            return;
        }

        let html = `<span class="t-muted text-sm">${result.total} users</span><div class="pagination-btns">`;
        if (this.currentPage > 1) {
            html += `<button class="btn-secondary btn-xs" onclick="Users.load(${this.currentPage - 1})">Previous</button>`;
        }
        html += `<span class="pagination-info">Page ${this.currentPage} of ${result.total_pages}</span>`;
        if (this.currentPage < result.total_pages) {
            html += `<button class="btn-secondary btn-xs" onclick="Users.load(${this.currentPage + 1})">Next</button>`;
        }
        html += '</div>';
        container.innerHTML = html;
    },

    // ---- Modal ----
    _editUserId: null,

    openAddModal() {
        this._editUserId = null;
        document.getElementById('userModalTitle').textContent = 'Add User';
        document.getElementById('userModalName').value = '';
        document.getElementById('userModalEmail').value = '';
        document.getElementById('userModalRole').value = 'user';
        document.getElementById('userModalPasswordGroup').style.display = '';
        document.getElementById('userModalPassword').value = '';
        document.getElementById('userModal').classList.add('active');
    },

    openEditModal(id) {
        // Find user data from the table (quick approach)
        this._editUserId = id;
        document.getElementById('userModalTitle').textContent = 'Edit User';
        document.getElementById('userModalPasswordGroup').style.display = 'none';
        document.getElementById('userModalPassword').value = '';

        // Fetch fresh data
        API.listUsers({ page: 1, limit: 100 }).then(result => {
            const user = (result.data || []).find(u => u.id === id);
            if (user) {
                document.getElementById('userModalName').value = user.name;
                document.getElementById('userModalEmail').value = user.email;
                document.getElementById('userModalRole').value = user.role;
            }
        }).catch(() => {});

        document.getElementById('userModal').classList.add('active');
    },

    closeModal() {
        document.getElementById('userModal')?.classList.remove('active');
    },

    async saveUser() {
        const name = document.getElementById('userModalName').value.trim();
        const email = document.getElementById('userModalEmail').value.trim();
        const role = document.getElementById('userModalRole').value;
        const password = document.getElementById('userModalPassword').value;

        if (!name || !email) {
            App.showToast('Name and email are required', 'error');
            return;
        }

        try {
            if (this._editUserId) {
                // Update existing user
                await API.updateUser({ id: this._editUserId, name, email, role });
                App.showToast('User updated successfully', 'success');
            } else {
                // Create new user
                if (!password || password.length < 6) {
                    App.showToast('Password must be at least 6 characters', 'error');
                    return;
                }
                await API.createUser({ name, email, role, password });
                App.showToast('User created successfully', 'success');
            }
            this.closeModal();
            this.load(this.currentPage);
        } catch (err) {
            App.showToast('Error: ' + err.message, 'error');
        }
    },

    // ---- Reset Password Modal ----
    _resetPwUserId: null,
    _resetPwUserName: '',

    resetPassword(id, name) {
        this._resetPwUserId = id;
        this._resetPwUserName = name;
        document.getElementById('resetPwModalTitle').textContent = 'Reset Password';
        document.getElementById('resetPwModalSubtitle').textContent = `Set a new password for ${name}`;
        document.getElementById('resetPwNewPass').value = '';
        document.getElementById('resetPwConfirm').value = '';
        document.getElementById('resetPwError').style.display = 'none';
        document.getElementById('resetPwModal').classList.add('active');
        setTimeout(() => document.getElementById('resetPwNewPass').focus(), 100);
    },

    closeResetModal() {
        document.getElementById('resetPwModal')?.classList.remove('active');
        this._resetPwUserId = null;
    },

    toggleResetPwVisibility() {
        const input = document.getElementById('resetPwNewPass');
        input.type = input.type === 'password' ? 'text' : 'password';
    },

    async submitResetPassword() {
        const pw = document.getElementById('resetPwNewPass').value;
        const confirm = document.getElementById('resetPwConfirm').value;
        const errEl = document.getElementById('resetPwError');

        if (!pw || pw.length < 6) {
            errEl.textContent = 'Password must be at least 6 characters';
            errEl.style.display = 'block';
            return;
        }
        if (pw !== confirm) {
            errEl.textContent = 'Passwords do not match';
            errEl.style.display = 'block';
            return;
        }

        errEl.style.display = 'none';

        try {
            await API.resetUserPassword(this._resetPwUserId, pw);
            this.closeResetModal();
            App.showToast(`Password reset for ${this._resetPwUserName}`, 'success');
        } catch (err) {
            errEl.textContent = 'Error: ' + err.message;
            errEl.style.display = 'block';
        }
    },

    async toggleActive(id, newState) {
        const action = newState ? 'activate' : 'deactivate';
        if (!confirm(`Are you sure you want to ${action} this user?`)) return;

        try {
            await API.toggleUserActive(id, newState);
            App.showToast(`User ${action}d successfully`, 'success');
            this.load(this.currentPage);
        } catch (err) {
            App.showToast('Error: ' + err.message, 'error');
        }
    },

    // ---- Helpers ----
    _roleBadge(role) {
        const map = {
            admin: '<span class="badge badge-role-admin">Admin</span>',
            manager: '<span class="badge badge-role-manager">Manager</span>',
            user: '<span class="badge badge-role-user">User</span>'
        };
        return map[role] || `<span class="badge">${App.escapeHtml(role)}</span>`;
    }
};
