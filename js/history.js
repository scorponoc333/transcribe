/**
 * History Module - Browse past transcriptions with filters, pagination, and modals
 */
const History = {
    currentPage: 1,
    totalPages: 1,

    init() {
        this.bindEvents();
    },

    bindEvents() {
        document.getElementById('historyFilterBtn').addEventListener('click', () => this.search());
        document.getElementById('historySearch').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') this.search();
        });

        // View modal
        document.getElementById('viewModalClose').addEventListener('click', () => this.closeViewModal());
        document.getElementById('transcriptViewModal').addEventListener('click', (e) => {
            if (e.target.id === 'transcriptViewModal') this.closeViewModal();
        });

        // View modal tabs
        document.getElementById('viewModalTabs').addEventListener('click', (e) => {
            const btn = e.target.closest('.tab-btn');
            if (!btn) return;
            const tab = btn.dataset.viewtab;
            document.querySelectorAll('#viewModalTabs .tab-btn').forEach(b => b.classList.toggle('active', b.dataset.viewtab === tab));
            document.getElementById('viewModalTranscript').style.display = tab === 'transcript' ? '' : 'none';
            document.getElementById('viewModalInsights').style.display = tab === 'insights' ? '' : 'none';
        });

        // Email log modal
        document.getElementById('emailLogClose').addEventListener('click', () => this.closeEmailLogModal());
        document.getElementById('emailLogModal').addEventListener('click', (e) => {
            if (e.target.id === 'emailLogModal') this.closeEmailLogModal();
        });

        // Escape to close modals
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeViewModal();
                this.closeEmailLogModal();
            }
        });
    },

    search() {
        this.currentPage = 1;
        this.load();
    },

    async load(page = 1) {
        this.currentPage = page;
        const tbody = document.getElementById('historyTableBody');
        tbody.innerHTML = '<tr><td colspan="5" class="text-center t-muted py-8">Loading...</td></tr>';

        try {
            const params = { page };
            const search = document.getElementById('historySearch').value.trim();
            const dateFrom = document.getElementById('historyDateFrom').value;
            const dateTo = document.getElementById('historyDateTo').value;
            const mode = document.getElementById('historyMode').value;

            if (search) params.search = search;
            if (dateFrom) params.date_from = dateFrom;
            if (dateTo) params.date_to = dateTo;
            if (mode) params.mode = mode;

            const result = await API.listTranscriptions(params);
            this.totalPages = result.total_pages || 1;
            this.renderTable(result.data);
            this.renderPagination(result);
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="5" class="text-center t-muted py-8">Error: ${App.escapeHtml(err.message)}</td></tr>`;
        }
    },

    renderTable(rows) {
        const tbody = document.getElementById('historyTableBody');
        const userRole = App.currentUser?.role || 'user';
        const canDelete = userRole === 'admin' || userRole === 'manager';
        const showUserCol = userRole === 'admin' || userRole === 'manager';

        if (!rows || rows.length === 0) {
            const colspan = showUserCol ? 6 : 5;
            tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-center t-muted py-8">No transcriptions found</td></tr>`;
            return;
        }

        // Update table header for user column
        const thead = tbody.closest('table')?.querySelector('thead tr');
        if (thead && showUserCol && !thead.querySelector('.user-col-header')) {
            const wordsTh = thead.querySelectorAll('th');
            if (wordsTh.length >= 4) {
                const th = document.createElement('th');
                th.textContent = 'User';
                th.className = 'user-col-header';
                wordsTh[3].parentNode.insertBefore(th, wordsTh[3]);
            }
        }

        tbody.innerHTML = rows.map(row => {
            const date = new Date(row.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
            const time = new Date(row.created_at).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
            const modeLabel = row.mode === 'meeting'
                ? '<span class="badge badge-meeting">Meeting</span>'
                : row.mode === 'learning'
                ? '<span class="badge badge-learning">Learning</span>'
                : '<span class="badge badge-recording">Recording</span>';
            const title = App.escapeHtml(row.title || 'Untitled');
            const userName = showUserCol ? `<td class="t-muted text-sm">${App.escapeHtml(row.user_name || '—')}</td>` : '';

            const reportUrl = `api/report.php?id=${row.id}`;
            return `<tr>
                <td><div class="cell-date">${date}</div><div class="cell-time">${time}</div></td>
                <td class="cell-title"><a href="${reportUrl}" target="_blank" rel="noopener" data-open-report="1" data-id="${row.id}" data-title="${title.replace(/"/g,'&quot;')}" style="color:inherit;text-decoration:none;border-bottom:1px dashed transparent;transition:border-color 0.2s" onmouseover="this.style.borderColor='currentColor'" onmouseout="this.style.borderColor='transparent'" title="Open full report">${title}</a></td>
                <td>${modeLabel}</td>
                ${userName}
                <td>${row.word_count.toLocaleString()}</td>
                <td class="cell-actions">
                    <a href="${reportUrl}" target="_blank" rel="noopener" class="btn-icon btn-xs" title="Open Full Report" data-open-report="1" data-id="${row.id}" data-title="${title.replace(/"/g,'&quot;')}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    </a>
                    ${row.has_pdf ? `<button class="btn-icon btn-xs" onclick="History.downloadPdf(${row.id})" title="Download PDF">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    </button>` : ''}
                    <button class="btn-icon btn-xs" onclick="History.emailTranscript(${row.id})" title="Send Email">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </button>
                    ${row.email_count > 0 ? `<button class="btn-icon btn-xs" onclick="History.viewEmailLog(${row.id})" title="Email log (${row.email_count})">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        <span class="badge-count">${row.email_count}</span>
                    </button>` : ''}
                    ${canDelete ? `<button class="btn-icon btn-xs btn-danger-icon" onclick="History.deleteTranscript(${row.id})" title="Delete">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                    </button>` : ''}
                </td>
            </tr>`;
        }).join('');

        // Intercept left-clicks on Open Report links so they play the same
        // gradient/logo/title animation used by the All Reports sidebar.
        (function () {
            if (History._openReportHooked) return;
            History._openReportHooked = true;
            const tbody = document.getElementById('historyTableBody');
            if (!tbody) return;
            tbody.addEventListener('click', function (e) {
                // Allow modifier-click / middle-click to open in a new tab normally
                if (e.defaultPrevented) return;
                if (e.button && e.button !== 0) return;
                if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
                const link = e.target.closest('a[data-open-report="1"]');
                if (!link) return;
                const id = link.dataset.id;
                const rawTitle = link.dataset.title || '';
                // data-title was HTML-escaped when rendered; decode for the overlay
                const div = document.createElement('div');
                div.innerHTML = rawTitle;
                const title = div.textContent || div.innerText || '';
                if (!id) return;
                if (window.__rsApi && typeof window.__rsApi.openReport === 'function') {
                    e.preventDefault();
                    try { window.__rsApi.openReport(id, title); return; }
                    catch (err) { console.warn('openReport animation failed, falling back to nav', err); }
                }
                // Fallback: let the default <a href> navigation happen.
            });
        })();

    },

    renderPagination(result) {
        const container = document.getElementById('historyPagination');
        if (result.total_pages <= 1) {
            container.innerHTML = `<span class="t-muted text-sm">${result.total} transcription${result.total !== 1 ? 's' : ''}</span>`;
            return;
        }

        const prevDisabled = this.currentPage <= 1;
        const nextDisabled = this.currentPage >= result.total_pages;

        let html = `<span class="t-muted text-sm">${result.total} results</span><div class="pagination-btns">`;

        html += `<button class="pagination-btn${prevDisabled ? ' disabled' : ''}" ${prevDisabled ? 'disabled' : `onclick="History.load(${this.currentPage - 1})"`}>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            Previous
        </button>`;

        // Page numbers
        for (let i = 1; i <= result.total_pages; i++) {
            if (result.total_pages > 7 && i > 2 && i < result.total_pages - 1 && Math.abs(i - this.currentPage) > 1) {
                if (i === 3 || i === result.total_pages - 2) html += `<span class="pagination-dots">&hellip;</span>`;
                continue;
            }
            html += `<button class="pagination-num${i === this.currentPage ? ' active' : ''}" onclick="History.load(${i})">${i}</button>`;
        }

        html += `<button class="pagination-btn${nextDisabled ? ' disabled' : ''}" ${nextDisabled ? 'disabled' : `onclick="History.load(${this.currentPage + 1})"`}>
            Next
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
        </button>`;

        html += '</div>';
        container.innerHTML = html;
    },

    _viewingId: null,

    async emailFromView() {
        if (!this._viewingId) return;
        this.closeViewModal();
        // Load the transcription data and open email modal
        try {
            const data = await API.getTranscription(this._viewingId);
            App.transcript = data.transcript_text;
            App.analysis = data.analysis_json;
            App.audioMode = data.mode || 'recording';
            App.transcriptionId = this._viewingId;
            App.currentFile = { name: data.title || 'transcript' };
            App.openEmailModal();
        } catch (err) {
            App.showToast('Failed to load transcription: ' + err.message, 'error');
        }
    },

    async viewTranscript(id) {
        // Always navigate to the full report page — no more quick preview modal
        if (typeof App !== 'undefined' && App._showReportTransition) {
            App._showReportTransition(id);
        } else {
            window.location.href = '/api/report.php?id=' + id;
        }
        return;

        // Legacy modal code below (kept but unreachable)
        this._viewingId = id;
        const modal = document.getElementById('transcriptViewModal');
        const titleEl = document.getElementById('viewModalTitle');
        const metaEl = document.getElementById('viewModalMeta');
        const transcriptEl = document.getElementById('viewModalTranscript');
        const insightsEl = document.getElementById('viewModalInsights');
        const tabsEl = document.getElementById('viewModalTabs');

        titleEl.textContent = 'Loading...';
        metaEl.textContent = '';
        transcriptEl.textContent = '';
        insightsEl.innerHTML = '';
        insightsEl.style.display = 'none';
        transcriptEl.style.display = '';
        tabsEl.style.display = 'none';
        modal.classList.add('active');

        try {
            const data = await API.getTranscription(id);
            const date = new Date(data.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' });
            titleEl.textContent = data.title || 'Untitled';
            metaEl.textContent = `${date} | ${data.mode} | ${data.word_count.toLocaleString()} words`;
            transcriptEl.innerHTML = App._formatTranscriptParagraphs(data.transcript_text);

            // Show insights tab if analysis exists
            if (data.analysis_json) {
                tabsEl.style.display = '';
                const a = data.analysis_json;

                if (data.mode === 'learning') {
                    // Learning mode: show dynamic blocks using App's builder
                    insightsEl.innerHTML = App._buildLearningBlocksHtml ? App._buildLearningBlocksHtml(a) : this._buildLearningInsights(a);
                    // For learning mode, default to insights tab and hide transcript if empty
                    if (!data.transcript_text || data.transcript_text.trim().length === 0) {
                        transcriptEl.style.display = 'none';
                        insightsEl.style.display = '';
                        document.querySelectorAll('#viewModalTabs .tab-btn').forEach(b => b.classList.toggle('active', b.dataset.viewtab === 'insights'));
                    } else {
                        document.querySelectorAll('#viewModalTabs .tab-btn').forEach(b => b.classList.toggle('active', b.dataset.viewtab === 'insights'));
                        transcriptEl.style.display = 'none';
                        insightsEl.style.display = '';
                    }
                } else {
                    insightsEl.innerHTML = `
                        ${a.summary ? `<div class="insight-card"><div class="insight-card-header"><h3 class="insight-card-title">Summary</h3></div><div class="insight-card-body"><p>${App.escapeHtml(App.itemToText(a.summary))}</p></div></div>` : ''}
                        ${a.keyPoints?.length ? `<div class="insight-card"><div class="insight-card-header"><h3 class="insight-card-title">Key Points</h3></div><ul class="insight-list keypoints">${a.keyPoints.map(p => `<li>${App.escapeHtml(App.itemToText(p))}</li>`).join('')}</ul></div>` : ''}
                        ${a.actionItems?.length ? `<div class="insight-card"><div class="insight-card-header"><h3 class="insight-card-title">Action Items</h3></div><ul class="insight-list actions">${a.actionItems.map(p => `<li>${App.escapeHtml(App.itemToText(p))}</li>`).join('')}</ul></div>` : ''}
                        ${a.suggestions?.length ? `<div class="insight-card"><div class="insight-card-header"><h3 class="insight-card-title">Suggestions</h3></div><ul class="insight-list suggestions">${a.suggestions.map(p => `<li>${App.escapeHtml(App.itemToText(p))}</li>`).join('')}</ul></div>` : ''}
                    `;
                    // Reset to transcript tab
                    document.querySelectorAll('#viewModalTabs .tab-btn').forEach(b => b.classList.toggle('active', b.dataset.viewtab === 'transcript'));
                }
            }

            // Load and display attendees for meeting mode
            this.loadViewModalAttendees(id, data.mode);

        } catch (err) {
            titleEl.textContent = 'Error';
            transcriptEl.textContent = err.message;
        }
    },

    closeViewModal() {
        App._bounceCloseModal(document.getElementById('transcriptViewModal'));
    },

    downloadPdf(id) {
        // Open the real branded report page with ?print=1 — it auto-fires
        // window.print() on load so the user gets the same Save-as-PDF
        // experience as clicking Download PDF on the report itself.
        window.open(`api/report.php?id=${id}&print=1`, '_blank');
    },

    async viewEmailLog(id) {
        const modal = document.getElementById('emailLogModal');
        const content = document.getElementById('emailLogContent');
        content.innerHTML = '<p class="t-muted text-center py-8">Loading...</p>';
        modal.classList.add('active');

        try {
            const logs = await API.getEmailLog(id);
            if (!logs.length) {
                content.innerHTML = '<p class="t-muted text-center py-8">No emails sent for this transcription.</p>';
                return;
            }

            content.innerHTML = `<table class="history-table email-log-table">
                <thead><tr><th>Sent To</th><th>Subject</th><th>Date</th><th>Status</th></tr></thead>
                <tbody>${logs.map(log => {
                    const date = new Date(log.sent_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                    const statusClass = log.status === 'sent' ? 'badge-success' : 'badge-error';
                    return `<tr>
                        <td>${App.escapeHtml(log.sent_to)}</td>
                        <td>${App.escapeHtml(log.subject)}</td>
                        <td>${date}</td>
                        <td><span class="badge ${statusClass}">${log.status}</span></td>
                    </tr>`;
                }).join('')}</tbody>
            </table>`;
        } catch (err) {
            content.innerHTML = `<p class="t-muted text-center py-8">Error: ${App.escapeHtml(err.message)}</p>`;
        }
    },

    closeEmailLogModal() {
        App._bounceCloseModal(document.getElementById('emailLogModal'));
    },

    async deleteTranscript(id) {
        if (!confirm('Are you sure you want to delete this transcription? This action cannot be undone.')) return;

        try {
            await API.deleteTranscription(id);
            App.showToast('Transcription deleted', 'success');
            this.load(this.currentPage);
        } catch (err) {
            App.showToast('Failed to delete: ' + err.message, 'error');
        }
    },

    // ---- View Modal Attendees ----
    _viewModalAttendees: [],
    _viewModalTranscriptionId: null,

    async loadViewModalAttendees(id, mode) {
        const section = document.getElementById('viewModalAttendees');
        const countEl = document.getElementById('viewModalAttendeesCount');
        const chipsEl = document.getElementById('viewModalAttendeesChips');

        if (mode !== 'meeting') {
            section.style.display = 'none';
            return;
        }

        this._viewModalTranscriptionId = id;
        section.style.display = '';

        try {
            const attendees = await API.getAttendees(id);
            this._viewModalAttendees = attendees.map(a => ({ name: a.name, email: a.email || '', source: a.source }));
        } catch {
            this._viewModalAttendees = [];
        }

        this.renderViewModalAttendees();

        // Wire toggle
        const toggle = document.getElementById('viewModalAttendeesToggle');
        const body = document.getElementById('viewModalAttendeesBody');
        toggle.onclick = () => {
            const isOpen = body.style.display !== 'none';
            body.style.display = isOpen ? 'none' : '';
            toggle.closest('.attendees-card-compact').classList.toggle('open', !isOpen);
        };

        countEl.textContent = this._viewModalAttendees.length;
    },

    renderViewModalAttendees() {
        const chipsEl = document.getElementById('viewModalAttendeesChips');
        const countEl = document.getElementById('viewModalAttendeesCount');
        countEl.textContent = this._viewModalAttendees.length;

        if (!this._viewModalAttendees.length) {
            chipsEl.innerHTML = '<span class="t-muted text-sm">No attendees. Add below.</span>';
            return;
        }

        chipsEl.innerHTML = this._viewModalAttendees.map((a, i) => `
            <div class="attendee-chip">
                <span class="attendee-chip-name">${App.escapeHtml(a.name)}</span>
                ${a.email ? `<span class="attendee-chip-email">${App.escapeHtml(a.email)}</span>` : ''}
                <button class="attendee-chip-remove" onclick="History.removeViewModalAttendee(${i})" title="Remove">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
        `).join('');
    },

    addViewModalAttendee() {
        const nameInput = document.getElementById('viewModalAttendeeNameInput');
        const emailInput = document.getElementById('viewModalAttendeeEmailInput');
        const name = nameInput.value.trim();
        if (!name) return;

        this._viewModalAttendees.push({ name, email: emailInput.value.trim(), source: 'manual' });
        nameInput.value = '';
        emailInput.value = '';
        nameInput.focus();
        this.renderViewModalAttendees();

        if (this._viewModalTranscriptionId) {
            API.saveAttendees(this._viewModalTranscriptionId, this._viewModalAttendees)
                .catch(err => console.error('Attendees save error:', err));
        }
    },

    removeViewModalAttendee(index) {
        this._viewModalAttendees.splice(index, 1);
        this.renderViewModalAttendees();
        if (this._viewModalTranscriptionId) {
            API.saveAttendees(this._viewModalTranscriptionId, this._viewModalAttendees)
                .catch(err => console.error('Attendees save error:', err));
        }
    },

    /**
     * Fallback builder for learning mode insights in view modal
     */
    _buildLearningInsights(a) {
        let html = '';
        const esc = (s) => App.escapeHtml(typeof s === 'object' ? App.itemToText(s) : String(s || ''));
        const block = (icon, title, content) => `<div class="insight-card"><div class="insight-card-header"><h3 class="insight-card-title">${icon} ${title}</h3></div><div class="insight-card-body">${content}</div></div>`;

        if (a.difficulty_level) html += block('', 'Difficulty', `<span class="difficulty-badge difficulty-${esc(a.difficulty_level)}">${esc(a.difficulty_level)}</span>`);
        if (a.executive_summary) html += block('', 'Executive Summary', `<p>${esc(a.executive_summary)}</p>`);
        if (a.key_concepts?.length) html += block('', 'Key Concepts', `<ul class="insight-list">${a.key_concepts.map(c => `<li><strong>${esc(c.term)}</strong>: ${esc(c.explanation)}</li>`).join('')}</ul>`);
        if (a.core_insights?.length) html += block('', 'Core Insights', `<ul class="insight-list">${a.core_insights.map(i => `<li>${esc(i)}</li>`).join('')}</ul>`);
        if (a.glossary?.length) html += block('', 'Glossary', `<ul class="insight-list">${a.glossary.map(g => `<li><strong>${esc(g.term)}</strong>: ${esc(g.definition)}</li>`).join('')}</ul>`);
        if (a.important_people?.length) html += block('', 'Important People', `<ul class="insight-list">${a.important_people.map(p => `<li><strong>${esc(p.name)}</strong>${p.role ? ' — ' + esc(p.role) : ''}${p.relevance ? ': ' + esc(p.relevance) : ''}</li>`).join('')}</ul>`);
        if (a.statistics?.length) html += block('', 'Statistics', `<ul class="insight-list">${a.statistics.map(s => `<li><strong>${esc(s.stat)}</strong> — ${esc(s.context)}</li>`).join('')}</ul>`);
        if (a.dates_timeline?.length) html += block('', 'Timeline', `<ul class="insight-list">${a.dates_timeline.map(d => `<li><strong>${esc(d.date)}</strong>: ${esc(d.event)}</li>`).join('')}</ul>`);
        if (a.action_items?.length) html += block('', 'Action Items', `<ul class="insight-list actions">${a.action_items.map(i => `<li>${esc(i.task)}${i.priority ? ' [' + esc(i.priority) + ']' : ''}</li>`).join('')}</ul>`);
        if (a.further_learning?.length) html += block('', 'Further Learning', `<ul class="insight-list">${a.further_learning.map(f => `<li><strong>${esc(f.topic)}</strong>: ${esc(f.why)}</li>`).join('')}</ul>`);
        if (a.learning_objectives_addressed) html += block('', 'Learning Objectives', `<p>${esc(a.learning_objectives_addressed)}</p>`);

        return html || '<p class="t-muted">No analysis data available.</p>';
    },

    async emailTranscript(id) {
        const smtpHost = App.getSetting('smtpHost');
        if (!smtpHost) {
            App.showToast('Please configure your SMTP email settings first.', 'warning');
            App.openSettings();
            // Auto-switch to SMTP tab
            document.querySelectorAll('.settings-tab').forEach(b => b.classList.toggle('active', b.dataset.stab === 'smtp'));
            document.querySelectorAll('.settings-panel').forEach(p => p.classList.toggle('active', p.dataset.stabPanel === 'smtp'));
            return;
        }

        App.showLoading('Loading transcription...');

        try {
            const data = await API.getTranscription(id);

            // Inject data into App so the existing email flow works
            App.transcript = data.transcript_text;
            App.analysis = data.analysis_json || null;
            App.audioMode = data.mode;
            App.transcriptionId = id;
            App.currentFile = { name: (data.title || 'transcript') + '.mp3' };

            // Load attendees for pre-populating email To field
            try {
                const attendees = await API.getAttendees(id);
                App.attendees = attendees.map(a => ({ name: a.name, email: a.email || '', source: a.source }));
            } catch { App.attendees = []; }

            App.hideLoading();
            App.openEmailModal();
        } catch (err) {
            App.hideLoading();
            App.showToast('Failed to load transcription: ' + err.message, 'error');
        }
    }
};
/* v3.82 — expose History on window so callers that use window.History can reach it (the native window.History object is the browser's history, which shadows any  namespace access). */
try { window.__AppHistory = History; } catch (e) {}
