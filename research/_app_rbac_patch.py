#!/usr/bin/env python3
"""v3.114 — patch js/app.js:
  1. Fetch /api/session.php on boot; tag <body> with data-role +
     data-is-master so CSS role-gates apply immediately.
  2. Apply server-side theme preference (overrides localStorage only
     if the server has a value).
  3. setThemeFromSettings now POSTs to /api/settings.php to persist
     the user's choice.
  4. openSettings: for managers, disable every form input except the
     Light/Dark toggle.
"""
p = '/var/www/transcribe/js/app.js'
s = open(p).read()

# ── 1) Rewrite applyRolePermissions to fetch /api/session.php ──
old_apply = '''applyRolePermissions() {
        if (!this.currentUser) return;
        const role = this.currentUser.role;

        const usersBtn = document.getElementById('usersBtn');
        const settingsBtn = document.getElementById('settingsBtn');
        const analyticsBtn = document.getElementById('analyticsBtn');
        const contactsBtn = document.getElementById('contactsBtn');

        if (role === 'admin') {
            if (usersBtn) usersBtn.style.display = '';
            if (settingsBtn) settingsBtn.style.display = '';
            if (analyticsBtn) analyticsBtn.style.display = '';
            if (contactsBtn) contactsBtn.style.display = '';
        } else if (role === 'manager') {
            if (usersBtn) usersBtn.style.display = 'none';
            if (settingsBtn) settingsBtn.style.display = 'none';
            if (analyticsBtn) analyticsBtn.style.display = '';
            if (contactsBtn) contactsBtn.style.display = '';
        } else {
            if (usersBtn) usersBtn.style.display = 'none';
            if (settingsBtn) settingsBtn.style.display = 'none';
            if (analyticsBtn) analyticsBtn.style.display = 'none';
            if (contactsBtn) contactsBtn.style.display = 'none';
        }
    },'''
new_apply = '''applyRolePermissions() {
        // v3.114 — role-based visibility is now driven by <body> data-role
        // and data-is-master. CSS rules in style.css hide role-gated
        // menu items automatically. This method fetches /api/session.php
        // and writes those attributes.
        const applyFromSession = (sess) => {
            const role = (sess && sess.role_in_org) || 'editor';
            const isMaster = !!(sess && sess.is_master_admin);
            document.body.dataset.role = role;
            document.body.dataset.isMaster = isMaster ? '1' : '0';
            this.session = sess || {};
            // If the server has a theme preference, honor it over localStorage.
            if (sess && sess.theme && (sess.theme === 'dark' || sess.theme === 'light')) {
                if (sess.theme === 'dark') {
                    document.documentElement.setAttribute('data-theme', 'dark');
                    localStorage.setItem('theme', 'dark');
                } else {
                    document.documentElement.removeAttribute('data-theme');
                    localStorage.setItem('theme', 'light');
                }
                document.getElementById('themeLight')?.classList.toggle('active', sess.theme === 'light');
                document.getElementById('themeDark')?.classList.toggle('active', sess.theme === 'dark');
            }
        };
        // Fire-and-forget — CSS gates run as soon as attrs are set
        fetch('/api/session.php', { credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : null)
            .then(applyFromSession)
            .catch(() => { /* fail-safe: leave body defaults */ });
    },'''
if 'v3.114 — role-based visibility is now driven' in s:
    print('applyRolePermissions already patched')
elif old_apply in s:
    s = s.replace(old_apply, new_apply, 1)
    print('applyRolePermissions rewritten to use /api/session.php')
else:
    print('WARN applyRolePermissions anchor miss')

# ── 2) setThemeFromSettings — persist to server ──
old_theme = '''setThemeFromSettings(theme) {
        if (theme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            localStorage.setItem('theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-theme');
            localStorage.setItem('theme', 'light');
        }
        // Update settings UI buttons
        document.getElementById('themeLight')?.classList.toggle('active', theme === 'light');
        document.getElementById('themeDark')?.classList.toggle('active', theme === 'dark');
    },'''
new_theme = '''setThemeFromSettings(theme) {
        if (theme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            localStorage.setItem('theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-theme');
            localStorage.setItem('theme', 'light');
        }
        document.getElementById('themeLight')?.classList.toggle('active', theme === 'light');
        document.getElementById('themeDark')?.classList.toggle('active', theme === 'dark');
        // v3.114 — persist per-user to /api/settings.php
        try {
            fetch('/api/settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ settings: { theme } })
            }).catch(() => {});
        } catch (e) {}
    },'''
if 'v3.114 — persist per-user to /api/settings.php' in s:
    print('setThemeFromSettings already patched')
elif old_theme in s:
    s = s.replace(old_theme, new_theme, 1)
    print('setThemeFromSettings now persists to server')
else:
    print('WARN setThemeFromSettings anchor miss')

# ── 3) openSettings — manager read-only except Light/Dark ──
# Find the existing openSettings body and append a lockdown step at the end.
# Simpler: wrap the existing call with a post-open pass that runs after the
# modal is populated. We inject right before the closing `},` of openSettings.
# Because openSettings can be long, use a marker: this.dom.settingsModal.classList.add('active')
marker = "this.dom.settingsModal.classList.add('active');"
if marker in s and 'v3.114 — manager lockdown' not in s:
    s = s.replace(marker,
        marker + '''

        // v3.114 — manager lockdown: disable every setting input except the
        // Light/Dark toggle. Admin + Master still get full access. Editors
        // never open this modal (the gear is hidden via CSS).
        try {
            const role = this.session?.role_in_org || document.body.dataset.role;
            const isMaster = document.body.dataset.isMaster === '1';
            if (role === 'manager' && !isMaster) {
                this.dom.settingsModal.querySelectorAll('input, textarea, select, button').forEach(el => {
                    if (el.id === 'themeLight' || el.id === 'themeDark') return;
                    if (el.classList.contains('modal-close')) return;
                    if (el.classList.contains('settings-tab')) return;
                    if (el.id === 'emailCancelBtn') return;
                    el.disabled = true;
                    el.style.opacity = '0.55';
                    el.style.cursor = 'not-allowed';
                });
                const saveBtn = document.getElementById('saveSettingsBtn');
                if (saveBtn) { saveBtn.style.display = 'none'; }
            }
        } catch (e) {}''', 1)
    print('openSettings: manager lockdown inserted')
else:
    print('openSettings lockdown already present or marker missing')

open(p, 'w').write(s)
print('DONE')
