/**
 * JAI Transcribe - Main Application Controller
 * Manages UI state, event handlers, and user interactions
 */

const App = window.App = {
    // State
    state: 'idle',
    currentFile: null,
    transcript: '',
    analysis: null,
    audioMode: 'recording', // 'recording' or 'meeting'
    transcriptionId: null,  // DB id after save
    language: 'en',
    translatedText: null,
    timer: null,
    timerSeconds: 0,
    attendees: [],
    currentUser: null,      // { id, name, email, role }
    coverPages: [],
    selectedCoverPage: null,
    _settingsCache: {},     // DB-backed settings cache

    dom: {},

    async init() {
        this.cacheDom();
        this.loadTheme();

        // Auth check — must pass before anything else renders
        const authed = await this.checkAuth();
        if (!authed) return; // redirected to login

        this.bindEvents();
        await this.loadSettings();
        this.applyRolePermissions();
        this.buildWaveformBars();
        this.showSection('upload');
        History.init();
        if (this.currentUser?.role !== 'user') {
            Contacts.init();
        }
        Users.init();
        this.initAutocomplete();
        this.initWorkflowAutocomplete();
        this.loadCoverPages();

        // Mark init as complete + fire event so the hash router can run
        // AFTER all the async boot work (checkAuth, loadSettings) has
        // finished and showSection('upload') above won't clobber it.
        this._initDone = true;
        try { document.dispatchEvent(new Event('app:ready')); } catch (e) {}
    },

    // ---- Auth ----
    async checkAuth() {
        try {
            const result = await API.checkAuth();
            if (result.authenticated) {
                this.currentUser = result.user;
                return true;
            }
            // Explicitly not authenticated — redirect to login
            window.location.href = 'login.php';
            return false;
        } catch (e) {
            console.warn('Auth check failed:', e);
            // Server error (DB down, network issue, etc.) — do NOT redirect
            // to login.php because that may redirect back here, causing a loop.
            document.body.innerHTML = `
                <div style="display:flex;align-items:center;justify-content:center;height:100vh;font-family:Inter,sans-serif;background:#0f172a;color:#e2e8f0">
                    <div style="text-align:center;max-width:400px;padding:32px">
                        <h2 style="margin-bottom:12px;color:#f87171">Connection Error</h2>
                        <p style="color:#94a3b8;line-height:1.6;margin-bottom:24px">Unable to reach the server. Please check that your database and web server are running.</p>
                        <button onclick="location.reload()" style="padding:10px 24px;background:var(--brand-600,#2563eb);color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:14px">Retry</button>
                    </div>
                </div>`;
            return false;
        }
    },

    applyRolePermissions() {
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
    },

    async logout() {
        try {
            await API.logout();
        } catch (e) {
            console.warn('Logout error:', e);
        }
        window.location.href = 'login.php';
    },

    cacheDom() {
        this.dom = {
            // Sections
            uploadSection: document.getElementById('uploadSection'),
            fileSection: document.getElementById('fileSection'),
            processingSection: document.getElementById('processingSection'),
            resultsSection: document.getElementById('resultsSection'),
            errorSection: document.getElementById('errorSection'),

            // Upload
            dropZone: document.getElementById('dropZone'),
            fileInput: document.getElementById('fileInput'),

            // File info
            fileName: document.getElementById('fileName'),
            fileSize: document.getElementById('fileSize'),
            audioPlayer: document.getElementById('audioPlayer'),
            removeFileBtn: document.getElementById('removeFileBtn'),
            transcribeBtn: document.getElementById('transcribeBtn'),

            // Type toggle
            typeRecording: document.getElementById('typeRecording'),
            typeMeeting: document.getElementById('typeMeeting'),

            // Processing
            waveform: document.getElementById('waveform'),
            processingStatus: document.getElementById('processingStatus'),
            processingSubstatus: document.getElementById('processingSubstatus'),
            processingTimer: document.getElementById('processingTimer'),

            // Results
            transcriptTab: document.getElementById('transcriptTab'),
            insightsTab: document.getElementById('insightsTab'),
            transcriptBody: document.getElementById('transcriptBody'),
            wordCount: document.getElementById('wordCount'),
            charCount: document.getElementById('charCount'),
            copyBtn: document.getElementById('copyBtn'),
            exportPdfBtn: document.getElementById('exportPdfBtn'),
            sendEmailBtn: document.getElementById('sendEmailBtn'),
            newTranscriptionBtn: document.getElementById('newTranscriptionBtn'),

            // Insights
            summaryContent: document.getElementById('summaryContent'),
            keyPointsList: document.getElementById('keyPointsList'),
            actionItemsList: document.getElementById('actionItemsList'),
            suggestionsList: document.getElementById('suggestionsList'),
            insightsLoading: document.getElementById('insightsLoading'),
            insightsContent: document.getElementById('insightsContent'),

            // Error
            errorMessage: document.getElementById('errorMessage'),
            errorDetails: document.getElementById('errorDetails'),
            retryBtn: document.getElementById('retryBtn'),

            // Theme
            themeToggle: document.getElementById('themeToggle'),

            // Settings
            settingsBtn: document.getElementById('settingsBtn'),
            settingsModal: document.getElementById('settingsModal'),
            settingsClose: document.getElementById('settingsClose'),
            apiKeyInput: document.getElementById('apiKeyInput'),
            apiKeyToggle: document.getElementById('apiKeyToggle'),
            whisperModel: document.getElementById('whisperModel'),
            smtpHost: document.getElementById('smtpHost'),
            smtpPort: document.getElementById('smtpPort'),
            smtpEncryption: document.getElementById('smtpEncryption'),
            smtpUser: document.getElementById('smtpUser'),
            smtpPass: document.getElementById('smtpPass'),
            smtpPassToggle: document.getElementById('smtpPassToggle'),
            senderEmail: document.getElementById('senderEmail'),
            senderName: document.getElementById('senderName'),
            saveSettingsBtn: document.getElementById('saveSettingsBtn'),

            // Email modal
            emailModal: document.getElementById('emailModal'),
            emailClose: document.getElementById('emailClose'),
            emailFrom: document.getElementById('emailFrom'),
            emailTo: document.getElementById('emailTo'),
            emailCc: document.getElementById('emailCc'),
            emailBcc: document.getElementById('emailBcc'),
            emailSubject: document.getElementById('emailSubject'),
            sendEmailSubmit: document.getElementById('sendEmailSubmit'),

            // Tabs
            tabBtns: document.querySelectorAll('.tab-btn'),

            // Loading overlay
            loadingOverlay: document.getElementById('loadingOverlay'),
            loadingText: document.getElementById('loadingText'),

            // Notification lightbox
            notificationOverlay: document.getElementById('notificationOverlay'),
            notificationBox: document.getElementById('notificationBox'),
            notificationIcon: document.getElementById('notificationIcon'),
            notificationMessage: document.getElementById('notificationMessage'),
            notificationClose: document.getElementById('notificationClose'),
        };
    },

    bindEvents() {
        // Upload zone
        const dz = this.dom.dropZone;
        dz.addEventListener('click', () => this.dom.fileInput.click());
        dz.addEventListener('dragover', (e) => { e.preventDefault(); dz.classList.add('dragover'); this._startDropZoneVortex(true); });
        dz.addEventListener('dragleave', () => { dz.classList.remove('dragover'); this._startDropZoneVortex(false); });
        dz.addEventListener('drop', (e) => {
            e.preventDefault();
            dz.classList.remove('dragover');
            this._stopDropZoneVortex();
            if (e.dataTransfer.files.length) this.handleFile(e.dataTransfer.files[0]);
        });
        dz.addEventListener('mouseenter', () => this._startDropZoneVortex(false));
        dz.addEventListener('mouseleave', () => { if (!dz.classList.contains('dragover')) this._stopDropZoneVortex(); });
        this.dom.fileInput.addEventListener('change', (e) => {
            if (e.target.files.length) this.handleFile(e.target.files[0]);
        });

        // File actions
        this.dom.removeFileBtn.addEventListener('click', () => this.resetToUpload());
        this.dom.transcribeBtn.addEventListener('click', () => this.startTranscription());

        // Type toggle
        this.dom.typeRecording.addEventListener('click', () => this.setAudioMode('recording'));
        this.dom.typeMeeting.addEventListener('click', () => this.setAudioMode('meeting'));
        document.getElementById('typeLearning')?.addEventListener('click', () => this.setAudioMode('learning'));

        // Learning source tabs
        document.getElementById('learningTabText')?.addEventListener('click', () => this.setLearningSource('text'));
        document.getElementById('learningTabYoutube')?.addEventListener('click', () => this.setLearningSource('youtube'));

        // Results actions
        this.dom.copyBtn.addEventListener('click', () => this.copyTranscript());
        this.dom.exportPdfBtn.addEventListener('click', () => this.exportPdf());
        this.dom.sendEmailBtn.addEventListener('click', () => this.openEmailModal());
        this.dom.newTranscriptionBtn.addEventListener('click', () => this.resetToUpload());

        // Learning mode start from upload screen
        document.getElementById('startLearningBtn')?.addEventListener('click', () => {
            this.setAudioMode('learning');
            this.showSection('file');
        });

        // Error
        this.dom.retryBtn.addEventListener('click', () => this.startTranscription());

        // Tabs
        this.dom.tabBtns.forEach(btn => {
            btn.addEventListener('click', () => this.switchTab(btn.dataset.tab));
        });

        // Theme
        this.dom.themeToggle.addEventListener('click', () => this.toggleTheme());

        // Settings
        this.dom.settingsBtn.addEventListener('click', () => this.openSettings());
        this.dom.settingsClose.addEventListener('click', () => this.closeSettings());
        this.dom.settingsModal.addEventListener('click', (e) => {
            // Only close via the close button, not backdrop click
        });
        this.dom.saveSettingsBtn.addEventListener('click', () => this.saveSettings());
        this.dom.apiKeyToggle.addEventListener('click', () => {
            const input = this.dom.apiKeyInput;
            input.type = input.type === 'password' ? 'text' : 'password';
        });
        this.dom.smtpPassToggle.addEventListener('click', () => {
            const input = this.dom.smtpPass;
            input.type = input.type === 'password' ? 'text' : 'password';
        });
        // Auto-sync encryption when port changes
        this.dom.smtpPort.addEventListener('change', () => {
            const portMap = { '587': 'tls', '465': 'ssl', '25': 'none' };
            const enc = portMap[this.dom.smtpPort.value];
            if (enc) this.dom.smtpEncryption.value = enc;
        });

        // Brand color picker — live preview + hex display
        const brandPicker = document.getElementById('brandColorPicker');
        const brandHex = document.getElementById('brandColorHex');
        if (brandPicker) {
            brandPicker.addEventListener('input', () => {
                if (brandHex) brandHex.textContent = brandPicker.value;
                this.applyBrandColor(brandPicker.value);
            });
        }
        const brandReset = document.getElementById('brandColorReset');
        if (brandReset) {
            brandReset.addEventListener('click', () => {
                if (brandPicker) brandPicker.value = '#1a3366';
                if (brandHex) brandHex.textContent = '#1a3366';
                this.resetBrandColor();
            });
        }

        // Settings tabs — with smooth 1.2s resize animation between tabs
        document.getElementById('settingsTabs').addEventListener('click', (e) => {
            const btn = e.target.closest('.settings-tab');
            if (!btn) return;
            const tab = btn.dataset.stab;
            const modal = document.querySelector('#settingsModal .settings-modal');

            if (modal) {
                // Lock current height so the switch doesn't snap
                const h0 = modal.getBoundingClientRect().height;
                modal.style.transition = 'none';
                modal.style.height = h0 + 'px';
                // Switch active panel (natural height will change once layout runs)
                document.querySelectorAll('.settings-tab').forEach(b => b.classList.toggle('active', b.dataset.stab === tab));
                document.querySelectorAll('.settings-panel').forEach(p => p.classList.toggle('active', p.dataset.stabPanel === tab));
                // Measure new natural height by temporarily clearing height
                modal.style.height = 'auto';
                const h1 = modal.getBoundingClientRect().height;
                // Snap back to start, then animate to target
                modal.style.height = h0 + 'px';
                void modal.offsetHeight; // force reflow
                modal.classList.add('size-animating');
                modal.style.transition = '';
                modal.style.height = h1 + 'px';
                clearTimeout(modal._sizeAnimTimer);
                modal._sizeAnimTimer = setTimeout(() => {
                    modal.classList.remove('size-animating');
                    modal.style.height = '';
                    modal.style.transition = '';
                }, 1250);
            } else {
                document.querySelectorAll('.settings-tab').forEach(b => b.classList.toggle('active', b.dataset.stab === tab));
                document.querySelectorAll('.settings-panel').forEach(p => p.classList.toggle('active', p.dataset.stabPanel === tab));
            }
        });

        // Branding: logo upload
        document.getElementById('brandingLogoInput')?.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) this.uploadLogo(file);
            e.target.value = '';
        });
        document.getElementById('brandingResetBtn')?.addEventListener('click', () => this.resetLogo());

        // Email modal — only close via X button or after send (no click-outside)
        this.dom.emailClose.addEventListener('click', () => this.closeEmailModal());
        document.getElementById('emailCancelBtn')?.addEventListener('click', () => this.closeEmailModal());
        this.dom.sendEmailSubmit.addEventListener('click', () => this.sendEmail());

        // Transcribe home button
        document.getElementById('transcribeBtn_nav')?.addEventListener('click', () => this.resetToUpload());

        // History (inside "More" dropdown)
        document.getElementById('historyBtn')?.addEventListener('click', () => {
            document.getElementById('moreDropdown')?.classList.remove('open');
            this.showHistory();
        });
        document.getElementById('historyBackBtn').addEventListener('click', () => this.showSection('upload'));

        // Analytics (inside "More" dropdown)
        document.getElementById('analyticsBtn')?.addEventListener('click', () => {
            document.getElementById('moreDropdown')?.classList.remove('open');
            this.showAnalytics();
        });
        document.getElementById('analyticsBackBtn').addEventListener('click', () => this.showSection('upload'));
        // Reports — More menu item
        document.getElementById('reportPagesBtn')?.addEventListener('click', () => this.showReportPages());

        // "More" dropdown toggle
        document.getElementById('moreBtn')?.addEventListener('click', (e) => {
            e.stopPropagation();
            document.getElementById('moreDropdown')?.classList.toggle('open');
        });
        document.addEventListener('click', () => {
            document.getElementById('moreDropdown')?.classList.remove('open');
        });

        // Contacts (inside "More" dropdown)
        document.getElementById('contactsBtn')?.addEventListener('click', () => {
            document.getElementById('moreDropdown')?.classList.remove('open');
            this.showContacts();
        });

        // Users (inside "More" dropdown, admin only)
        document.getElementById('usersBtn')?.addEventListener('click', () => {
            document.getElementById('moreDropdown')?.classList.remove('open');
            this.showUsers();
        });
        document.getElementById('usersBackBtn')?.addEventListener('click', () => this.showSection('upload'));

        // Logout
        document.getElementById('logoutBtn')?.addEventListener('click', () => this.logout());

        // Login background upload
        document.getElementById('loginBgInput')?.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) this.uploadLoginBackground(file);
            e.target.value = '';
        });
        document.getElementById('loginBgResetBtn')?.addEventListener('click', () => this.resetLoginBackground());

        // Cover page upload in settings
        document.getElementById('coverPageUploadInput')?.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) this.uploadCoverPage(file);
            e.target.value = '';
        });

        // Attendees toggle
        document.getElementById('attendeesToggle').addEventListener('click', () => {
            const body = document.getElementById('attendeesBody');
            const card = document.getElementById('attendeesToggle').closest('.attendees-card, .attendees-card-compact');
            const isOpen = body.style.display !== 'none';
            body.style.display = isOpen ? 'none' : '';
            card.classList.toggle('open', !isOpen);
        });
        document.getElementById('addAttendeeBtn').addEventListener('click', () => this.addAttendee());
        document.getElementById('attendeeNameInput').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                // Don't add attendee if autocomplete dropdown is visible (let autocomplete handle it)
                const dropdown = document.getElementById('attendeeNameInput').parentElement?.querySelector('.autocomplete-dropdown');
                if (dropdown && dropdown.style.display !== 'none') return;
                this.addAttendee();
            }
        });

        // Keyboard
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeSettings();
                this.closeEmailModal();
            }
        });
    },

    // ---- Audio Mode ----
    setAudioMode(mode) {
        this.audioMode = mode;
        this.dom.typeRecording.classList.toggle('active', mode === 'recording');
        this.dom.typeMeeting.classList.toggle('active', mode === 'meeting');
        document.getElementById('typeLearning')?.classList.toggle('active', mode === 'learning');

        // Toggle audio vs learning input areas
        const audioArea = document.getElementById('audioFileArea');
        const learningArea = document.getElementById('learningInputArea');
        const titleEl = document.getElementById('fileSectionTitle');
        const subtitleEl = document.getElementById('fileSectionSubtitle');
        const btnText = document.getElementById('transcribeBtnText');
        const typeToggleWrapper = document.querySelector('.type-toggle-wrapper');

        // Hide the type toggle when in learning mode (user already chose it)
        if (typeToggleWrapper) typeToggleWrapper.style.display = mode === 'learning' ? 'none' : '';

        if (mode === 'learning') {
            if (audioArea) audioArea.style.display = 'none';
            if (learningArea) learningArea.style.display = '';
            if (titleEl) titleEl.textContent = 'Learning Analysis';
            if (subtitleEl) subtitleEl.textContent = 'Paste content or a YouTube URL and tell us what you want to learn';
            if (btnText) btnText.textContent = 'Analyze Content';
        } else {
            if (audioArea) audioArea.style.display = '';
            if (learningArea) learningArea.style.display = 'none';
            if (titleEl) titleEl.textContent = 'Ready to Transcribe';
            if (subtitleEl) subtitleEl.textContent = 'Preview your audio and start transcription when ready';
            if (btnText) btnText.textContent = 'Start Transcription';
        }
    },

    learningSource: 'text',

    setLearningSource(source) {
        this.learningSource = source;
        document.getElementById('learningTabText')?.classList.toggle('active', source === 'text');
        document.getElementById('learningTabYoutube')?.classList.toggle('active', source === 'youtube');

        // Smooth panel transition
        const textPanel = document.getElementById('learningTextPanel');
        const ytPanel   = document.getElementById('learningYoutubePanel');
        if (source === 'text') {
            if (ytPanel)   { ytPanel.style.opacity = '0'; setTimeout(() => { ytPanel.style.display = 'none'; }, 350); }
            if (textPanel) { textPanel.style.display = ''; requestAnimationFrame(() => { textPanel.style.opacity = '1'; }); }
        } else {
            if (textPanel) { textPanel.style.opacity = '0'; setTimeout(() => { textPanel.style.display = 'none'; }, 350); }
            if (ytPanel)   { ytPanel.style.display = ''; requestAnimationFrame(() => { ytPanel.style.opacity = '1'; }); }
        }
    },

    // ---- File Handling ----
    handleFile(file) {
        const validExts = ['mp3', 'm4a', 'mp4', 'wav'];
        const ext = file.name.split('.').pop().toLowerCase();

        if (!validExts.includes(ext)) {
            this.showToast('Unsupported file format. Use MP3, M4A, MP4, or WAV.', 'error');
            return;
        }

        if (file.size > 500 * 1024 * 1024) {
            this.showToast('File too large. Maximum size is 500MB.', 'error');
            return;
        }

        // Launch the workflow lightbox instead of going to file section
        this.startWorkflow(file);
    },

    // ---- Transcription ----
    async startTranscription() {
        // Learning mode: different flow (no audio upload)
        if (this.audioMode === 'learning') {
            return this.startLearningAnalysis();
        }

        if (!this.currentFile) return;

        // Prevent double-transcription — only one at a time
        if (this._bgTranscribing) {
            this.showToast('A transcription is already in progress. Please wait for it to finish.', 'info');
            this.showSection('processing');
            this.hideFloatingIndicator();
            return;
        }

        this._bgTranscribing = true;
        this._bgResult = null;
        API.clearPendingCosts();
        this.showSection('processing');
        this.startTimer();
        this.startWaveformAnimation();
        // Particles disabled on processing screen — keep waveform + pulse rings only
        this.dom.processingStatus.textContent = 'Uploading audio file...';
        this.dom.processingSubstatus.textContent = 'Sending to Whisper for transcription';

        try {
            const model = this.getSetting('whisperModel') || 'turbo';

            const result = await API.transcribe(this.currentFile, model, (progress) => {
                if (progress < 100) {
                    this.dom.processingStatus.textContent = `Uploading... ${progress}%`;
                } else {
                    this.dom.processingStatus.textContent = 'Transcribing audio...';
                    this.dom.processingSubstatus.textContent = `Using Whisper (${model} model) - this may take a while`;
                }
            });

            this.transcript = result.transcript;
            this.stopTimer();
            this.stopWaveformAnimation();
            this.stopParticleAnimation();

            // Language detection & translation (if OpenRouter key available)
            const apiKey = this.getSetting('openRouterApiKey');
            if (apiKey) {
                try {
                    const langResult = await API.detectAndTranslate(this.transcript, apiKey);
                    this.language = langResult.language || 'en';
                    this.translatedText = langResult.translatedText;
                } catch (err) {
                    console.warn('Language detection failed:', err);
                    this.language = 'en';
                    this.translatedText = null;
                }
            }

            this.displayTranscript();
            this._bgTranscribing = false;

            const wasOnProcessing = (this.state === 'processing');

            // AI analysis only for meeting mode
            if (this.audioMode === 'meeting') {
                const orKey = apiKey || this.getSetting('openRouterApiKey');
                if (orKey) {
                    this.startAnalysis(orKey);
                } else {
                    this.dom.insightsLoading.innerHTML = `
                        <div class="setup-banner">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                            <div class="setup-banner-content">
                                Add your OpenRouter API key in <strong>Settings</strong> to enable AI-powered summaries, key points, action items, and suggestions.
                            </div>
                        </div>`;
                    this.dom.insightsContent.style.display = 'none';
                }
            }

            this.updateTabVisibility();

            // Auto-save first so we have a transcriptionId, then redirect
            if (this.audioMode === 'recording') {
                await this.saveToDatabase();
            }

            this._bgTranscribing = false;
            this.showToast('Transcription complete!', 'success');

            if (!wasOnProcessing) {
                this.showFloatingComplete();
            } else {
                this.hideFloatingIndicator();
                const rid = this._bgResult?.transcriptionId || this.transcriptionId;
                if (rid) {
                    this._showReportTransition(rid);
                } else {
                    this.showSection('results');
                }
            }

        } catch (error) {
            this.stopTimer();
            this.stopWaveformAnimation();
            this.stopParticleAnimation();
            this._bgTranscribing = false;
            this.hideFloatingIndicator();
            this.showError(error.message);
        }
    },

    async startAnalysis(apiKey) {
        this.dom.insightsLoading.style.display = 'block';
        this.dom.insightsContent.style.display = 'none';
        this.dom.insightsLoading.innerHTML = `
            <div class="analyzing-shimmer">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
                    <div class="spinner" style="width:24px;height:24px;border-width:2px"></div>
                    <span style="font-weight:600;color:var(--fg-heading)">AI is analyzing your ${this.audioMode === 'meeting' ? 'meeting' : 'recording'}...</span>
                </div>
                <p style="font-size:13px;color:var(--fg-muted)">Generating summary, key points, action items, and suggestions</p>
            </div>`;

        try {
            this.analysis = await API.analyzeTranscript(this.transcript, apiKey, this.audioMode);
            this.displayAnalysis();
            this.showToast('AI analysis complete!', 'success');
            // Save to database after analysis (meeting mode)
            this.saveToDatabase();
        } catch (error) {
            this.dom.insightsLoading.innerHTML = `
                <div class="setup-banner" style="background:rgba(239,68,68,0.1);border-color:rgba(239,68,68,0.2)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f87171" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <div class="setup-banner-content" style="color:#f87171">
                        AI analysis failed: ${this.escapeHtml(error.message)}<br>
                        <button onclick="App.startAnalysis(App.getSetting('openRouterApiKey'))" class="btn-icon" style="margin-top:10px;color:#f87171;border-color:rgba(239,68,68,0.3)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                            Retry
                        </button>
                    </div>
                </div>`;
            this.dom.insightsContent.style.display = 'none';
        }
    },

    // ---- Learning Mode ----
    async startLearningAnalysis() {
        const apiKey = this.getSetting('openRouterApiKey');
        if (!apiKey) {
            this.showToast('OpenRouter API key required for Learning mode. Add it in Settings.', 'error');
            return;
        }

        API.clearPendingCosts();

        let transcriptText = '';
        let transcriptSource = 'text';

        // YouTube source removed — paste-only flow
        if (false) {
            const url = document.getElementById('learningYoutubeUrl')?.value.trim();
            if (!url) {
                this.showToast('Please enter a YouTube URL', 'error');
                return;
            }
            transcriptSource = 'youtube';

            this._bgTranscribing = true;
            this.showSection('processing');
            this.startTimer();
            this.startWaveformAnimation();
            this.dom.processingStatus.textContent = 'Fetching YouTube transcript...';
            this.dom.processingSubstatus.textContent = 'Pulling captions from the video';

            try {
                const ytResult = await API.getYouTubeTranscript(url);
                transcriptText = ytResult.transcript;
                if (!transcriptText) throw new Error('No transcript available');
                if (ytResult.title) this._ytVideoTitle = ytResult.title;
            } catch (err) {
                this.stopTimer();
                this.stopWaveformAnimation();
                this._bgTranscribing = false;
                this.hideFloatingIndicator();
                this.showSection('upload');
                this.setAudioMode('learning');
                this.setLearningSource('text');
                // Open YouTube in a new tab for the user and show instructions
                window.open(url, '_blank');
                this.showToast(
                    'Opened the YouTube video in a new tab. To get the transcript: click "..." below the video → "Show transcript" → select all text (Ctrl+A) → copy (Ctrl+C) → paste it in the text box here.',
                    'info',
                    15000
                );
                setTimeout(() => {
                    const textInput = document.getElementById('learningTextInput');
                    if (textInput) {
                        textInput.focus();
                        textInput.placeholder = 'Paste the YouTube transcript here (Ctrl+V) — we opened the video in a new tab for you';
                    }
                }, 500);
                return;
            }
        } else {
            transcriptText = document.getElementById('learningTextInput')?.value.trim();
            if (!transcriptText) {
                this.showToast('Please paste some text content to analyze', 'error');
                return;
            }
            transcriptSource = 'text';
            this._bgTranscribing = true;
            this.showSection('processing');
            this.startTimer();
            this.startWaveformAnimation();
        }

        this.transcript = transcriptText;
        this.dom.processingStatus.textContent = 'Analyzing content with AI...';
        this.dom.processingSubstatus.textContent = 'Generating comprehensive learning report — this may take a moment';

        try {
            const objective = document.getElementById('learningObjective')?.value.trim() || '';
            this.analysis = await API.analyzeLearning(transcriptText, objective, apiKey);
            this.stopTimer();
            this.stopWaveformAnimation();

            // Set title from analysis
            if (this.analysis.title) {
                this.dom.resultTitle && (this.dom.resultTitle.textContent = this.analysis.title);
            }

            // Save to database FIRST so we have a transcriptionId for the report
            await this.saveToDatabase(transcriptSource);

            this._bgTranscribing = false;
            this.showToast('Learning analysis complete!', 'success');

            // Redirect to the branded report page
            const rid = this._bgResult?.transcriptionId || this.transcriptionId;
            if (this.state !== 'processing') {
                this.showFloatingComplete();
            } else {
                this.hideFloatingIndicator();
                if (rid) {
                    this._showReportTransition(rid);
                } else {
                    // Fallback only if save somehow failed
                    this.displayTranscript();
                    this.displayLearningResults(this.analysis);
                    this.showSection('results');
                    this.updateTabVisibility();
                    this.switchTab('insights');
                }
            }
        } catch (error) {
            this.stopTimer();
            this.stopWaveformAnimation();
            this._bgTranscribing = false;
            this.hideFloatingIndicator();
            this.showError('Learning analysis failed: ' + error.message);
        }
    },

    displayLearningResults(analysis) {
        if (!analysis) return;

        // Build the learning blocks HTML for the insights content area
        const blocksHtml = this._buildLearningBlocksHtml(analysis);

        this.dom.insightsLoading.style.display = 'none';
        this.dom.insightsContent.style.display = '';
        this.dom.insightsContent.innerHTML = blocksHtml;
    },

    _buildLearningBlocksHtml(a) {
        const blocks = [];

        // Difficulty badge
        if (a.difficulty_level) {
            const colorMap = { beginner: '#10b981', intermediate: '#d97706', advanced: '#ef4444' };
            const color = colorMap[a.difficulty_level] || '#64748b';
            blocks.push(`<div class="learning-difficulty-badge" style="background:${color}15;color:${color};border:1px solid ${color}30">${a.difficulty_level.charAt(0).toUpperCase() + a.difficulty_level.slice(1)} Level</div>`);
        }

        // Executive Summary
        if (a.executive_summary) {
            blocks.push(this._learningBlock('📊', 'Executive Summary', `<p>${this.escapeHtml(a.executive_summary)}</p>`));
        }

        // Learning Objectives Addressed
        if (a.learning_objectives_addressed) {
            blocks.push(this._learningBlock('✨', 'Learning Objectives Addressed', `<p>${this.escapeHtml(a.learning_objectives_addressed)}</p>`));
        }

        // Key Concepts
        if (a.key_concepts?.length) {
            const items = a.key_concepts.map(c => {
                const badge = c.importance === 'high' ? '<span class="importance-badge high">High</span>' : (c.importance === 'medium' ? '<span class="importance-badge medium">Medium</span>' : '<span class="importance-badge low">Low</span>');
                return `<div class="learning-concept-item"><strong>${this.escapeHtml(c.term)}</strong> ${badge}<p>${this.escapeHtml(c.explanation)}</p></div>`;
            }).join('');
            blocks.push(this._learningBlock('💡', 'Key Concepts', items));
        }

        // Glossary
        if (a.glossary?.length) {
            const rows = a.glossary.map(g => `<tr><td class="glossary-term">${this.escapeHtml(g.term)}</td><td>${this.escapeHtml(g.definition)}</td></tr>`).join('');
            blocks.push(this._learningBlock('📖', 'Glossary', `<table class="learning-table"><thead><tr><th>Term</th><th>Definition</th></tr></thead><tbody>${rows}</tbody></table>`));
        }

        // Core Insights
        if (a.core_insights?.length) {
            const items = a.core_insights.map(i => `<li>${this.escapeHtml(i)}</li>`).join('');
            blocks.push(this._learningBlock('🧠', 'Core Insights', `<ul class="learning-list">${items}</ul>`));
        }

        // Important People
        if (a.important_people?.length) {
            const rows = a.important_people.map(p => `<tr><td><strong>${this.escapeHtml(p.name)}</strong></td><td>${this.escapeHtml(p.role || '')}</td><td>${this.escapeHtml(p.relevance || '')}</td></tr>`).join('');
            blocks.push(this._learningBlock('👥', 'Important People', `<table class="learning-table"><thead><tr><th>Name</th><th>Role</th><th>Relevance</th></tr></thead><tbody>${rows}</tbody></table>`));
        }

        // Statistics
        if (a.statistics?.length) {
            const stats = a.statistics.map(s => `<div class="stat-callout"><div class="stat-value">${this.escapeHtml(s.stat)}</div><div class="stat-context">${this.escapeHtml(s.context)}</div>${s.source ? `<div class="stat-source">Source: ${this.escapeHtml(s.source)}</div>` : ''}</div>`).join('');
            blocks.push(this._learningBlock('📈', 'Key Statistics', `<div class="stats-grid">${stats}</div>`));
        }

        // Dates & Timeline
        if (a.dates_timeline?.length) {
            const items = a.dates_timeline.map(d => `<div class="timeline-item"><div class="timeline-date">${this.escapeHtml(d.date)}</div><div class="timeline-event"><strong>${this.escapeHtml(d.event)}</strong>${d.significance ? `<p>${this.escapeHtml(d.significance)}</p>` : ''}</div></div>`).join('');
            blocks.push(this._learningBlock('📅', 'Timeline & Dates', `<div class="timeline-container">${items}</div>`));
        }

        // Locations
        if (a.locations?.length) {
            const items = a.locations.map(l => `<div class="learning-chip"><strong>📍 ${this.escapeHtml(l.name)}</strong> — ${this.escapeHtml(l.context)}</div>`).join('');
            blocks.push(this._learningBlock('📍', 'Locations', items));
        }

        // Products & Tools
        if (a.products_tools?.length) {
            const items = a.products_tools.map(p => `<div class="learning-resource-item"><strong>🛠️ ${this.escapeHtml(p.name)}</strong><p>${this.escapeHtml(p.description)}</p>${p.url ? `<a href="${this.escapeHtml(p.url)}" target="_blank" rel="noopener">${this.escapeHtml(p.url)}</a>` : ''}</div>`).join('');
            blocks.push(this._learningBlock('🛠️', 'Products & Tools', items));
        }

        // Resources & URLs
        if (a.resources_urls?.length) {
            const items = a.resources_urls.map(r => `<div class="learning-resource-item"><strong>🔗 ${this.escapeHtml(r.name)}</strong>${r.description ? `<p>${this.escapeHtml(r.description)}</p>` : ''}${r.url ? `<a href="${this.escapeHtml(r.url)}" target="_blank" rel="noopener">${this.escapeHtml(r.url)}</a>` : ''}</div>`).join('');
            blocks.push(this._learningBlock('🔗', 'Resources & URLs', items));
        }

        // Contact Info
        if (a.contact_info?.length) {
            const items = a.contact_info.map(c => `<div class="learning-chip"><strong>${this.escapeHtml(c.name)}</strong> — ${this.escapeHtml(c.detail)}</div>`).join('');
            blocks.push(this._learningBlock('📞', 'Contact Information', items));
        }

        // Roadmap
        if (a.roadmap?.length) {
            const items = a.roadmap.map((r, i) => `<div class="roadmap-item"><div class="roadmap-phase">Phase ${i + 1}${r.timeline ? ` · ${this.escapeHtml(r.timeline)}` : ''}</div><div class="roadmap-title">${this.escapeHtml(r.phase)}</div><p>${this.escapeHtml(r.description)}</p></div>`).join('');
            blocks.push(this._learningBlock('🗺️', 'Roadmap', `<div class="roadmap-container">${items}</div>`));
        }

        // Action Items
        if (a.action_items?.length) {
            const items = a.action_items.map(item => {
                const pColor = item.priority === 'high' ? '#ef4444' : (item.priority === 'medium' ? '#d97706' : '#10b981');
                return `<div class="learning-action-item"><span class="priority-dot" style="background:${pColor}"></span>${this.escapeHtml(item.task)}</div>`;
            }).join('');
            blocks.push(this._learningBlock('✅', 'Action Items', items));
        }

        // Concept Relationships
        if (a.concept_relationships?.length) {
            const items = a.concept_relationships.map(r => `<div class="concept-rel-item"><span class="concept-from">${this.escapeHtml(r.from)}</span><span class="concept-arrow">→</span><span class="concept-to">${this.escapeHtml(r.to)}</span><span class="concept-rel-label">${this.escapeHtml(r.relationship)}</span></div>`).join('');
            blocks.push(this._learningBlock('🔄', 'Concept Relationships', items));
        }

        // Prerequisites
        if (a.prerequisites?.length) {
            const items = a.prerequisites.map(p => `<li>${this.escapeHtml(p)}</li>`).join('');
            blocks.push(this._learningBlock('📚', 'Prerequisites', `<ul class="learning-list">${items}</ul>`));
        }

        // Further Learning
        if (a.further_learning?.length) {
            const items = a.further_learning.map(f => `<div class="learning-resource-item"><strong>🎓 ${this.escapeHtml(f.topic)}</strong><p>${this.escapeHtml(f.why)}</p>${f.resource ? `<p class="t-muted text-sm">Resource: ${this.escapeHtml(f.resource)}</p>` : ''}</div>`).join('');
            blocks.push(this._learningBlock('🎓', 'Further Learning', items));
        }

        return blocks.join('');
    },

    _learningBlock(icon, title, content) {
        return `<div class="learning-block insight-card glass">
            <div class="learning-block-header">
                <span class="learning-block-icon">${icon}</span>
                <h3 class="learning-block-title">${title}</h3>
            </div>
            <div class="learning-block-content">${content}</div>
        </div>`;
    },

    /**
     * Format raw transcript text into clean HTML paragraphs.
     * Whisper often outputs broken sentences with single newlines — this joins
     * them into coherent paragraphs and outputs <p> tags.
     */
    _formatTranscriptParagraphs(text) {
        if (!text) return '';
        // Split on double+ newlines for paragraph breaks
        const blocks = text.split(/\n{2,}/).filter(Boolean);
        return blocks.map(block => {
            // Within a block, join single newlines with spaces (Whisper broken sentences)
            const cleaned = block.replace(/\n/g, ' ').replace(/\s{2,}/g, ' ').trim();
            return `<p>${this.escapeHtml(cleaned)}</p>`;
        }).join('');
    },

    displayTranscript() {
        const words = this.transcript.split(/\s+/).filter(w => w.length > 0).length;
        const chars = this.transcript.length;
        this.dom.wordCount.textContent = `${words.toLocaleString()} words`;
        this.dom.charCount.textContent = `${chars.toLocaleString()} chars`;

        if (this.translatedText) {
            // Two-column layout for non-English transcripts
            const originalParas = this.transcript.split(/\n\n+/).filter(Boolean);
            const translatedParas = this.translatedText.split(/\n\n+/).filter(Boolean);
            const maxRows = Math.max(originalParas.length, translatedParas.length);

            let html = `<div class="bilingual-header">
                <span class="bilingual-lang">${this.escapeHtml(this.language)} (Original)</span>
                <span class="bilingual-lang">English (Translation)</span>
            </div><table class="bilingual-table"><tbody>`;

            for (let i = 0; i < maxRows; i++) {
                html += `<tr>
                    <td>${this.escapeHtml(originalParas[i] || '')}</td>
                    <td>${this.escapeHtml(translatedParas[i] || '')}</td>
                </tr>`;
            }
            html += '</tbody></table>';
            this.dom.transcriptBody.innerHTML = html;
        } else {
            this.dom.transcriptBody.innerHTML = this._formatTranscriptParagraphs(this.transcript);
        }
    },

    /**
     * Extract readable text from an item that could be a string or an object.
     * AI models sometimes return list items as objects like:
     *   { task: "Do X", assignee: "John", deadline: "Friday" }
     * This normalises them into a single display string.
     */
    itemToText(item) {
        if (typeof item === 'string') return item;
        if (item === null || item === undefined) return '';
        if (typeof item !== 'object') return String(item);

        // Try common single-value keys first
        const singleKeys = ['text', 'task', 'description', 'item', 'point', 'suggestion',
                            'recommendation', 'content', 'summary', 'action', 'title', 'name'];
        for (const key of singleKeys) {
            if (item[key] && typeof item[key] === 'string') {
                // If there are extra useful fields, append them
                const extras = [];
                if (item.assignee) extras.push(`Assignee: ${item.assignee}`);
                if (item.owner) extras.push(`Owner: ${item.owner}`);
                if (item.responsible) extras.push(`Responsible: ${item.responsible}`);
                if (item.deadline) extras.push(`Deadline: ${item.deadline}`);
                if (item.due) extras.push(`Due: ${item.due}`);
                if (item.priority) extras.push(`Priority: ${item.priority}`);
                if (item.status) extras.push(`Status: ${item.status}`);
                const main = item[key];
                return extras.length ? `${main} (${extras.join(' | ')})` : main;
            }
        }

        // Fallback: join all string values
        const vals = Object.values(item).filter(v => typeof v === 'string' && v.length > 0);
        if (vals.length) return vals.join(' — ');

        // Last resort
        return JSON.stringify(item);
    },

    displayAnalysis() {
        this.dom.insightsLoading.style.display = 'none';
        this.dom.insightsContent.style.display = 'block';

        if (this.analysis.summary) {
            const summaryText = this.itemToText(this.analysis.summary);
            this.dom.summaryContent.innerHTML = summaryText
                .split('\n\n')
                .map(p => `<p>${this.escapeHtml(p)}</p>`)
                .join('');
        }

        if (this.analysis.keyPoints?.length) {
            this.dom.keyPointsList.innerHTML = this.analysis.keyPoints
                .map(p => `<li>${this.escapeHtml(this.itemToText(p))}</li>`).join('');
        }

        if (this.analysis.actionItems?.length) {
            this.dom.actionItemsList.innerHTML = this.analysis.actionItems
                .map(p => `<li>${this.escapeHtml(this.itemToText(p))}</li>`).join('');
        }

        if (this.analysis.suggestions?.length) {
            this.dom.suggestionsList.innerHTML = this.analysis.suggestions
                .map(p => `<li>${this.escapeHtml(this.itemToText(p))}</li>`).join('');
        }
    },

    // ---- Copy ----
    async copyTranscript() {
        try {
            await navigator.clipboard.writeText(this.transcript);
            this.dom.copyBtn.classList.add('copied');
            const originalHTML = this.dom.copyBtn.innerHTML;
            this.dom.copyBtn.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                Copied!`;
            this.showToast('Transcript copied to clipboard', 'success');
            setTimeout(() => {
                this.dom.copyBtn.innerHTML = originalHTML;
                this.dom.copyBtn.classList.remove('copied');
            }, 2000);
        } catch {
            this.showToast('Failed to copy. Try selecting the text manually.', 'error');
        }
    },

    // ---- Export PDF ----
    async exportPdf() {
        this.showLoading('Generating PDF...', 'pdf');
        try {
            const filename = this.currentFile?.name?.replace(/\.[^.]+$/, '') || 'transcript';
            const coverPath = this.selectedCoverPage || 'img/covers/default-cover.png';
            if (this.audioMode === 'learning' && this.analysis) {
                await PDFGenerator.generateLearning(this.analysis, filename, coverPath);
            } else {
                await PDFGenerator.generate(this.transcript, this.analysis, filename, coverPath);
            }
            this.hideLoading();
            this.showToast('PDF downloaded successfully!', 'success');
        } catch (error) {
            this.hideLoading();
            this.showToast('Failed to generate PDF: ' + error.message, 'error');
            console.error('PDF generation error:', error);
        }
    },

    // ---- Database Save ----
    async saveToDatabase(transcriptSource) {
        try {
            const words = this.transcript.split(/\s+/).filter(w => w.length > 0).length;
            const chars = this.transcript.length;
            const model = this.getSetting('whisperModel') || 'turbo';
            const title = this.analysis?.title || this.currentFile?.name?.replace(/\.[^.]+$/, '') || 'Untitled';

            const saveResult = await API.saveTranscription({
                title,
                mode: this.audioMode,
                language: this.language || 'en',
                transcript_text: this.transcript,
                transcript_english: this.translatedText || null,
                analysis_json: this.analysis || null,
                whisper_model: this.audioMode === 'learning' ? 'n/a' : model,
                word_count: words,
                char_count: chars,
                timer_seconds: this.timerSeconds || null,
                audio_duration_seconds: this.audioMode === 'learning' ? null : (Math.round(this.dom.audioPlayer.duration || 0) || null),
                transcript_source: transcriptSource || 'audio',
            });

            this.transcriptionId = saveResult.id;
            this._bgResult = { transcriptionId: saveResult.id };

            // Auto-send email if workflow requested it
            if (this._workflowEmailAddress) {
                this._autoSendWorkflowEmail(saveResult.id);
            }

            // Extract attendees from AI analysis (meeting mode) — match against contacts for emails
            if (this.audioMode === 'meeting' && this.analysis?.attendees?.length) {
                const aiNames = this.analysis.attendees.map(name =>
                    typeof name === 'string' ? name : (name.name || String(name))
                );
                try {
                    const matches = await API.matchContactNames(aiNames);
                    this.attendees = aiNames.map(name => {
                        const contact = matches[name];
                        return {
                            name: contact ? contact.name : name,
                            email: contact ? (contact.email || '') : '',
                            source: 'ai',
                            matched: !!contact
                        };
                    });
                } catch (matchErr) {
                    console.warn('Contact matching failed, using names without emails:', matchErr);
                    this.attendees = aiNames.map(name => ({
                        name, email: '', source: 'ai', matched: false
                    }));
                }
                this.displayAttendees();
            }

            // Save attendees to DB
            if (this.transcriptionId && this.attendees.length) {
                API.saveAttendees(this.transcriptionId, this.attendees).catch(err => console.error('Attendees save error:', err));
            }

            // Save AI cost records to DB (wait a bit for generation cost fetch to complete)
            if (this.transcriptionId && API._lastGenerationCosts.length) {
                setTimeout(() => {
                    API.savePendingCosts(this.transcriptionId).catch(err => console.warn('AI cost save error:', err));
                }, 3500);
            }

            // Generate and save PDF in background
            const filename = this.currentFile?.name?.replace(/\.[^.]+$/, '') || 'transcript';
            const pdfBase64 = await API.generatePdfBase64(this.transcript, this.analysis, filename, this.audioMode);
            const pdfSuffix = this.audioMode === 'learning' ? '_learning_report.pdf' : '_transcript.pdf';
            await API.savePdf(this.transcriptionId, pdfBase64, `${filename}${pdfSuffix}`);
        } catch (err) {
            console.error('Auto-save error:', err);
        }
    },

    // ---- Email ----
    openEmailModal() {
        const smtpHost = this.getSetting('smtpHost');
        if (!smtpHost) {
            this.showToast('Please configure your SMTP email settings first.', 'warning');
            this.openSettings();
            // Auto-switch to SMTP tab
            document.querySelectorAll('.settings-tab').forEach(b => b.classList.toggle('active', b.dataset.stab === 'smtp'));
            document.querySelectorAll('.settings-panel').forEach(p => p.classList.toggle('active', p.dataset.stabPanel === 'smtp'));
            return;
        }

        // Pre-fill from settings
        const senderName = this.getSetting('senderName') || 'Botson AI';
        const senderEmail = this.getSetting('senderEmail') || '';
        this.dom.emailFrom.value = senderEmail ? `${senderName} <${senderEmail}>` : '';

        // Generate subject from analysis title or filename
        const title = this.analysis?.title || this.currentFile?.name?.replace(/\.[^.]+$/, '') || 'Audio Transcription';
        this.dom.emailSubject.value = `\u{1F4DD} Transcription | ${title}`;

        // Pre-populate To with attendee emails if available
        const attendeeEmails = this.attendees
            .filter(a => a.email && a.email.trim())
            .map(a => a.email.trim());
        this.dom.emailTo.value = attendeeEmails.length ? attendeeEmails.join(', ') : '';
        this.dom.emailCc.value = '';
        this.dom.emailBcc.value = '';
        this.dom.emailModal.classList.add('active');
    },

    closeEmailModal() {
        this._bounceCloseModal(this.dom.emailModal);
    },

    _setFooterText(el, text) {
        const defaultText = 'Transcription & Learning Tool developed by Jason Hogan';
        const display = text || defaultText;
        // Auto-link "Jason Hogan" to LinkedIn
        const linked = display.replace(/Jason Hogan/g, '<a href="https://www.linkedin.com/in/jasonhogan333/" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline;text-underline-offset:2px">Jason Hogan</a>');
        el.innerHTML = linked;
    },

    _bounceCloseModal(overlay) {
        if (!overlay || !overlay.classList.contains('active')) return;
        overlay.classList.add('closing');
        setTimeout(() => {
            overlay.classList.remove('active', 'closing');
        }, 400);
    },

    async sendEmail() {
        const to = this.dom.emailTo.value.trim();
        const from = this.dom.emailFrom.value.trim();
        const subject = this.dom.emailSubject.value.trim();

        if (!to) { this.showToast('Please enter at least one recipient.', 'error'); return; }
        if (!from) { this.showToast('Please enter a sender address.', 'error'); return; }
        if (!subject) { this.showToast('Please enter a subject.', 'error'); return; }

        this.closeEmailModal();
        this.showLoading('Sending email...');

        try {
            // The email body links to api/report.php (signed URL) — no PDF attachment needed.
            // The landing page renders the full transcription, analysis, and a print-to-PDF
            // button. This avoids EmailIt's ~10MB body limit on big attachments.
            const filename = this.currentFile?.name?.replace(/\.[^.]+$/, '') || 'transcript';

            // Generate email HTML based on mode
            const title = this.analysis?.title || filename;
            let html;
            if (this.audioMode === 'learning' && this.analysis) {
                html = EmailTemplate.generateLearning({
                    title,
                    analysis: this.analysis
                });
            } else if (this.audioMode === 'meeting' && this.analysis) {
                html = EmailTemplate.generate({
                    title,
                    summary: this.analysis.summary || '',
                    keyPoints: this.analysis.keyPoints || [],
                    actionItems: this.analysis.actionItems || [],
                    mode: 'meeting'
                });
            } else {
                html = EmailTemplate.generateRecording({ title });
            }

            // Parse recipients
            const toList = to.split(',').map(e => e.trim()).filter(Boolean);
            const ccVal = this.dom.emailCc.value.trim();
            const bccVal = this.dom.emailBcc.value.trim();

            // EmailIt API key is read server-side. We pass transcription_id so
            // send-smtp.php can mint an HMAC-signed URL to api/report.php and
            // substitute it into the {{REPORT_URL}} placeholder in the HTML.
            const emailOptions = {
                from: from,
                from_name: this.getSetting('senderName') || '',
                to: toList,
                subject: subject,
                html: html,
                transcription_id: this.transcriptionId || null,
            };

            if (ccVal) emailOptions.cc = ccVal.split(',').map(e => e.trim()).filter(Boolean);
            if (bccVal) emailOptions.bcc = bccVal.split(',').map(e => e.trim()).filter(Boolean);

            // Emailing the report == sharing it. Flip to public so the
            // recipient's link actually works without them signing in,
            // and the Share modal also reflects the new public state.
            if (this.transcriptionId) {
                try {
                    const fd = new FormData();
                    fd.append('id', this.transcriptionId);
                    fd.append('public', '1');
                    await fetch('/api/transcription-share.php', {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin'
                    });
                } catch (e) {
                    console.warn('share toggle before send failed (non-fatal)', e);
                }
            }

            await API.sendSmtpEmail(emailOptions);

            // Log email send to database
            if (this.transcriptionId) {
                API.saveEmailLog({
                    transcription_id: this.transcriptionId,
                    sent_to: toList.join(', '),
                    cc: ccVal || null,
                    bcc: bccVal || null,
                    subject: subject,
                    sender: from,
                    status: 'sent',
                }).catch(err => console.error('Email log error:', err));
            }

            // Save contacts to autocomplete history
            const allRecipients = [];
            toList.forEach(e => allRecipients.push({ name: '', email: e }));
            if (ccVal) ccVal.split(',').map(e => e.trim()).filter(Boolean).forEach(e => allRecipients.push({ name: '', email: e }));
            if (bccVal) bccVal.split(',').map(e => e.trim()).filter(Boolean).forEach(e => allRecipients.push({ name: '', email: e }));
            if (allRecipients.length) {
                API.saveContacts(allRecipients).catch(err => console.error('Contact save error:', err));
            }

            this.hideLoading();
            this.showToast('Email sent successfully!', 'success');
        } catch (error) {
            this.hideLoading();
            this.showToast('Failed to send email: ' + error.message, 'error');
            console.error('Email error:', error);
        }
    },

    // ---- History ----
    _showHistoryOld() {
        // Replaced by the new showHistory with loading animation
        this.showSection('history');
        History.load();
    },

    // ---- Error / Reset ----
    showError(message, details = '') {
        this.dom.errorMessage.textContent = message;
        this.dom.errorDetails.textContent = details;
        this.dom.errorDetails.style.display = details ? 'block' : 'none';
        this.showSection('error');
    },

    browseAwayFromProcessing() {
        // Navigate to upload while transcription continues in background
        this.showSection('upload');
        this.showToast('Transcription continues in the background', 'info');
    },

    returnToProcessing() {
        // Clicking the floating pill takes you back to the processing screen
        if (this._bgTranscribing) {
            this.showSection('processing');
        }
    },

    resetToUpload() {
        // Always re-show the All Reports sidebar on a Transcribe click,
        // even if we short-circuit below because we're already on upload.
        (function () {
            const sb = document.getElementById('reportsSidebar');
            if (sb) {
                sb.style.display = '';
                sb.classList.remove('rs-closing');
                document.body.classList.add('has-reports-sidebar');
                // Replay the assembly animation so it feels alive
                if (window.__rsApi && typeof window.__rsApi.playAssembly === 'function') {
                    try { window.__rsApi.playAssembly(); } catch (e) {}
                }
            }
        })();
        // If a transcription is running, go to upload but keep the pill visible
        if (this._bgTranscribing) {
            this.showSection('upload');
            return;
        }
        // If already on upload page with an audio mode, draw attention to the drop zone
        if ((this.state === 'upload' || this.state === 'file') && this.audioMode !== 'learning') {
            const zone = document.getElementById('dropZone');
            if (zone) {
                zone.scrollIntoView({ behavior: 'smooth', block: 'center' });
                zone.classList.remove('attention');
                // Force reflow
                zone.offsetHeight;
                zone.classList.add('attention');
                setTimeout(() => zone.classList.remove('attention'), 1200);
                this.showToast('Drop your audio file in the box above', 'info');
            }
            return;
        }
        // If on Learning Analysis screen, switch out of learning mode and show the
        // audio upload drop zone (no toast — the transition itself is the feedback).
        if (this.audioMode === 'learning') {
            this.audioMode = 'recording';
            this.showSection('upload');
            const zone = document.getElementById('dropZone');
            if (zone) {
                zone.scrollIntoView({ behavior: 'smooth', block: 'center' });
                zone.classList.remove('attention');
                zone.offsetHeight;
                zone.classList.add('attention');
                setTimeout(() => zone.classList.remove('attention'), 1200);
            }
            return;
        }
        this.currentFile = null;
        this.transcript = '';
        this.analysis = null;
        this.audioMode = 'recording';
        this.transcriptionId = null;
        this.language = 'en';
        this.translatedText = null;
        this.attendees = [];
        this.dom.fileInput.value = '';
        this.dom.audioPlayer.src = '';
        this.dom.transcriptBody.textContent = '';
        this.dom.insightsLoading.innerHTML = '';
        this.dom.insightsContent.style.display = 'none';
        document.getElementById('attendeesSection').style.display = 'none';
        this.setAudioMode('recording');
        this.showSection('upload');
    },

    // ---- UI Helpers ----
    showSection(name) {
        const sectionMap = {
            upload: this.dom.uploadSection,
            file: this.dom.fileSection,
            processing: this.dom.processingSection,
            results: this.dom.resultsSection,
            error: this.dom.errorSection,
            history: document.getElementById('historySection'),
            analytics: document.getElementById('analyticsSection'),
            contacts: document.getElementById('contactsSection'),
            users: document.getElementById('usersSection'),
            reportPages: document.getElementById('reportPagesSection'),
        };

        Object.entries(sectionMap).forEach(([key, el]) => {
            if (el) el.classList.toggle('active', key === name);
        });

        // Sync the top-bar nav button highlight so the user can see where they are.
        // For items inside the "More" dropdown, highlight the More trigger button
        // AND the specific dropdown item.
        const navMap = {
            upload:     'transcribeBtn_nav',
            file:       'transcribeBtn_nav',
            processing: 'transcribeBtn_nav',
            results:    'transcribeBtn_nav',
            error:      'transcribeBtn_nav',
            history:     'moreBtn',
            analytics:   'moreBtn',
            contacts:    'moreBtn',
            users:       'moreBtn',
            reportPages: 'moreBtn',
        };
        // Dropdown item ids for sub-highlight inside the More menu
        const moreItemMap = {
            history:     'historyBtn',
            analytics:   'analyticsBtn',
            contacts:    'contactsBtn',
            users:       'usersBtn',
            reportPages: 'reportPagesBtn',
        };
        const activeBtnId = navMap[name];
        document.querySelectorAll('.header-nav-btn').forEach((b) => {
            b.classList.toggle('is-current', b.id === activeBtnId);
        });
        // Highlight the active item inside the More dropdown
        document.querySelectorAll('.header-more-item').forEach((item) => {
            const activeMoreId = moreItemMap[name];
            item.classList.toggle('is-current', item.id === activeMoreId);
        });

        // Show floating indicator if navigating away from processing during transcription
        if (this._bgTranscribing && name !== 'processing') {
            this.showFloatingIndicator();
        } else if (name === 'processing' && this._bgTranscribing) {
            this.hideFloatingIndicator();
        }

        this.state = name;
    },

    switchTab(tab) {
        this.dom.tabBtns.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === tab);
        });
        if (tab === 'transcript') {
            this.dom.transcriptTab.classList.add('active');
            this.dom.insightsTab.classList.remove('active');
        } else {
            this.dom.transcriptTab.classList.remove('active');
            this.dom.insightsTab.classList.add('active');
        }
    },

    updateTabVisibility() {
        const insightsBtn = document.querySelector('.tab-btn[data-tab="insights"]');
        const attendeesSection = document.getElementById('attendeesSection');
        if (this.audioMode === 'recording') {
            // Hide insights tab and attendees for recording mode
            if (insightsBtn) insightsBtn.style.display = 'none';
            if (attendeesSection) attendeesSection.style.display = 'none';
            this.switchTab('transcript');
        } else if (this.audioMode === 'learning') {
            // Learning: show insights (learning blocks), hide attendees
            if (insightsBtn) insightsBtn.style.display = '';
            if (attendeesSection) attendeesSection.style.display = 'none';
        } else {
            if (insightsBtn) insightsBtn.style.display = '';
            // Show attendees section for meetings (will be populated after analysis)
            if (attendeesSection) attendeesSection.style.display = '';
            this.displayAttendees();
        }
    },

    showToast(message, type = 'info') {
        const overlay = this.dom.notificationOverlay;
        const iconEl = this.dom.notificationIcon;
        const msgEl = this.dom.notificationMessage;
        const closeBtn = this.dom.notificationClose;

        const icons = {
            success: '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
            error: '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
            info: '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
            warning: '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        };

        iconEl.className = `notification-icon ${type}`;
        iconEl.innerHTML = icons[type] || icons.info;
        msgEl.textContent = message;
        overlay.className = `notification-overlay active ${type}`;

        // Close handlers
        const close = () => {
            overlay.classList.remove('active');
            closeBtn.removeEventListener('click', close);
            overlay.removeEventListener('click', backdropClose);
            document.removeEventListener('keydown', escClose);
        };
        const backdropClose = (e) => { if (e.target === overlay) close(); };
        const escClose = (e) => { if (e.key === 'Escape') close(); };

        closeBtn.addEventListener('click', close);
        overlay.addEventListener('click', backdropClose);
        document.addEventListener('keydown', escClose);
    },

    _loadingOverlayTextInterval: null,
    _emailLoadingMessages: [
        'This will just take a moment',
        'Generating your PDF report...',
        'Formatting the email template...',
        'Attaching your transcript...',
        'Connecting to mail server...',
        'Sending your email...',
        'Almost done...',
    ],

    showLoading(text, icon) {
        this.dom.loadingText.textContent = text;
        this.dom.loadingOverlay.classList.add('active');
        this.startMiniParticles('loadingOverlayCanvas', 35);

        // Set icon
        const iconEl = document.getElementById('loadingOverlayIcon');
        if (iconEl && icon === 'pdf') {
            iconEl.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>';
        } else if (iconEl) {
            iconEl.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>';
        }

        // Cycle subtitle text
        const subtextEl = document.getElementById('loadingSubtext');
        if (subtextEl) {
            let idx = 0;
            subtextEl.textContent = this._emailLoadingMessages[0];
            this._loadingOverlayTextInterval = setInterval(() => {
                subtextEl.style.opacity = '0';
                setTimeout(() => {
                    idx = (idx + 1) % this._emailLoadingMessages.length;
                    subtextEl.textContent = this._emailLoadingMessages[idx];
                    subtextEl.style.opacity = '1';
                }, 300);
            }, 2000);
        }
    },

    hideLoading() {
        if (this._loadingOverlayTextInterval) {
            clearInterval(this._loadingOverlayTextInterval);
            this._loadingOverlayTextInterval = null;
        }
        this.dom.loadingOverlay.classList.remove('active');
        this.stopMiniParticles('loadingOverlayCanvas');
    },

    // ---- Waveform ----
    buildWaveformBars() {
        const container = this.dom.waveform;
        container.innerHTML = '';
        const barCount = 50;

        for (let i = 0; i < barCount; i++) {
            const bar = document.createElement('div');
            bar.className = 'waveform-bar';
            const center = barCount / 2;
            const dist = Math.abs(i - center) / center;
            const maxH = 20 + (1 - dist * dist) * 80;
            const minH = 4 + Math.random() * 6;
            bar.style.setProperty('--max-h', `${maxH}px`);
            bar.style.setProperty('--min-h', `${minH}px`);
            bar.style.animationDelay = `${(i * 0.05) + Math.random() * 0.2}s`;
            bar.style.height = `${minH}px`;
            container.appendChild(bar);
        }
    },

    startWaveformAnimation() {
        this.dom.waveform.querySelectorAll('.waveform-bar').forEach(bar => bar.classList.add('animating'));
    },

    stopWaveformAnimation() {
        this.dom.waveform.querySelectorAll('.waveform-bar').forEach(bar => bar.classList.remove('animating'));
    },

    // ---- Timer ----
    startTimer() {
        this.timerSeconds = 0;
        this.dom.processingTimer.textContent = '00:00';
        this.timer = setInterval(() => {
            this.timerSeconds++;
            const mins = Math.floor(this.timerSeconds / 60).toString().padStart(2, '0');
            const secs = (this.timerSeconds % 60).toString().padStart(2, '0');
            this.dom.processingTimer.textContent = `${mins}:${secs}`;
        }, 1000);
    },

    stopTimer() {
        if (this.timer) { clearInterval(this.timer); this.timer = null; }
    },

    // ---- Settings (DB-backed) ----
    async openSettings() {
        // Fetch latest settings from server
        try {
            const settings = await API.getSettings();
            this._settingsCache = settings;
            this.populateSettingsForm(settings);
        } catch (err) {
            console.warn('Failed to load settings:', err);
            this.showToast('Could not load settings from server. Showing cached values.', 'warning');
            // Fall back to cached settings so the form isn't blank
            this.populateSettingsForm(this._settingsCache || {});
        }

        // Load cover pages in settings tab
        this.renderSettingsCoverPages();

        // Load login background preview
        this._loadLoginBgPreview();

        // Sync theme buttons
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        document.getElementById('themeLight')?.classList.toggle('active', !isDark);
        document.getElementById('themeDark')?.classList.toggle('active', isDark);

        // Reset to first tab
        document.querySelectorAll('.settings-tab').forEach(b => b.classList.toggle('active', b.dataset.stab === 'ai'));
        document.querySelectorAll('.settings-panel').forEach(p => p.classList.toggle('active', p.dataset.stabPanel === 'ai'));

        this.dom.settingsModal.classList.add('active');
    },

    /**
     * Populate settings form fields from a settings object
     */
    populateSettingsForm(settings) {
        this.dom.apiKeyInput.value = settings.openRouterApiKey || '';
        this.dom.whisperModel.value = settings.whisperModel || 'turbo';
        this.dom.smtpHost.value = settings.smtpHost || '';
        this.dom.smtpPort.value = settings.smtpPort || '587';
        // If encryption was saved, use it; otherwise infer from port
        if (settings.smtpEncryption) {
            this.dom.smtpEncryption.value = settings.smtpEncryption;
        } else {
            const portMap = { '587': 'tls', '465': 'ssl', '25': 'none' };
            this.dom.smtpEncryption.value = portMap[this.dom.smtpPort.value] || 'tls';
        }
        this.dom.smtpUser.value = settings.smtpUser || '';
        this.dom.smtpPass.value = settings.smtpPass || '';
        this.dom.senderEmail.value = settings.senderEmail || '';
        this.dom.senderName.value = settings.senderName || '';

        // AI Model
        const modelSelect = document.getElementById('openRouterModel');
        if (modelSelect) {
            modelSelect.value = settings.openRouterModel || 'google/gemini-2.5-pro';
        }

        // Brand color
        const brandPicker = document.getElementById('brandColorPicker');
        const brandHex = document.getElementById('brandColorHex');
        if (brandPicker) {
            brandPicker.value = settings.brandColor || '#2557b3';
            if (brandHex) brandHex.textContent = brandPicker.value;
        }

        // Footer text
        const footerInput = document.getElementById('footerTextInput');
        if (footerInput) footerInput.value = settings.footerText || '';

        // Login animation
        const animEnabled = document.getElementById('loginAnimationEnabled');
        const animSelect = document.getElementById('loginAnimationSelect');
        if (animEnabled) animEnabled.checked = settings.loginAnimationEnabled === '1' || settings.loginAnimationEnabled === 'true';
        if (animSelect) animSelect.value = settings.loginAnimation || 'constellations';

        // Animation opacity & speed
        const animOpacity = document.getElementById('loginAnimationOpacity');
        const animSpeed = document.getElementById('loginAnimationSpeed');
        if (animOpacity) {
            animOpacity.value = settings.loginAnimationOpacity || '50';
            document.getElementById('animOpacityVal').textContent = animOpacity.value;
        }
        if (animSpeed) {
            animSpeed.value = settings.loginAnimationSpeed || '50';
            document.getElementById('animSpeedVal').textContent = animSpeed.value;
        }
    },

    closeSettings() {
        this._bounceCloseModal(this.dom.settingsModal);
        // If we're embedded inside a parent page's lightbox (e.g. report.php
        // opens us in an iframe), ask the parent to close the lightbox too.
        try {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage('close-settings-lightbox', '*');
            }
        } catch (e) { /* cross-origin — ignore */ }
    },

    async saveSettings() {
        const modelSelect = document.getElementById('openRouterModel');
        const fields = {
            openRouterApiKey: this.dom.apiKeyInput.value.trim(),
            openRouterModel: modelSelect ? modelSelect.value : 'google/gemini-2.5-pro',
            whisperModel: this.dom.whisperModel.value,
            smtpHost: this.dom.smtpHost.value.trim(),
            smtpPort: this.dom.smtpPort.value,
            smtpEncryption: this.dom.smtpEncryption.value,
            smtpUser: this.dom.smtpUser.value.trim(),
            smtpPass: this.dom.smtpPass.value.trim(),
            senderEmail: this.dom.senderEmail.value.trim(),
            senderName: this.dom.senderName.value.trim(),
            brandColor: document.getElementById('brandColorPicker')?.value || '',
            footerText: (document.getElementById('footerTextInput')?.value || '').trim(),
            loginAnimationEnabled: document.getElementById('loginAnimationEnabled')?.checked ? '1' : '0',
            loginAnimation: document.getElementById('loginAnimationSelect')?.value || 'constellations',
            loginAnimationOpacity: document.getElementById('loginAnimationOpacity')?.value || '50',
            loginAnimationSpeed: document.getElementById('loginAnimationSpeed')?.value || '50',
        };

        try {
            await API.saveSettings(fields);
            // Update local cache
            this._settingsCache = { ...this._settingsCache, ...fields };
            // Update API model
            API.setModel(fields.openRouterModel);
            // Apply footer text to DOM
            const footerEl = document.getElementById('appFooterText');
            if (footerEl) {
                this._setFooterText(footerEl, fields.footerText);
            }
            // Apply brand color
            if (fields.brandColor) {
                this.applyBrandColor(fields.brandColor);
            } else {
                this.resetBrandColor();
            }
            this.closeSettings();
            this.showToast('Settings saved successfully', 'success');
        } catch (err) {
            this.showToast('Failed to save settings: ' + err.message, 'error');
        }
    },

    async loadSettings() {
        try {
            const settings = await API.getSettings();
            this._settingsCache = settings;

            // Apply AI model
            if (settings.openRouterModel) {
                API.setModel(settings.openRouterModel);
            }

            // Apply footer text
            if (settings.footerText) {
                const footerEl = document.getElementById('appFooterText');
                if (footerEl) this._setFooterText(footerEl, settings.footerText);
            }

            if (!settings.openRouterApiKey) {
                setTimeout(() => {
                    if (this.state === 'upload' && this.currentUser?.role === 'admin') {
                        this.showToast('Tip: Add your OpenRouter API key in Settings for AI analysis', 'info');
                    }
                }, 3000);
            }
        } catch (err) {
            console.warn('Failed to load settings:', err);
        }

        // Apply brand color (read from cache since `settings` is block-scoped)
        const savedBrandColor = this._settingsCache?.brandColor;
        if (savedBrandColor) {
            this.applyBrandColor(savedBrandColor);
        }

        // Load custom logo
        this.loadCustomLogo();
    },

    /**
     * Get a setting value (from cache, avoids network calls during operations)
     */
    getSetting(key) {
        return this._settingsCache[key] || '';
    },

    /** Convert hex to HSL, derive full brand palette, apply as CSS vars */
    applyBrandColor(hex) {
        if (!hex) return;
        const ri = parseInt(hex.slice(1,3),16)/255;
        const gi = parseInt(hex.slice(3,5),16)/255;
        const bi = parseInt(hex.slice(5,7),16)/255;
        const max = Math.max(ri,gi,bi), min = Math.min(ri,gi,bi);
        let h, s, l = (max+min)/2;
        if (max === min) { h = s = 0; }
        else {
            const d = max - min;
            s = l > 0.5 ? d/(2-max-min) : d/(max+min);
            switch(max){
                case ri: h = ((gi-bi)/d + (gi<bi?6:0))/6; break;
                case gi: h = ((bi-ri)/d + 2)/6; break;
                case bi: h = ((ri-gi)/d + 4)/6; break;
            }
        }
        h = Math.round(h*360);
        const sat = Math.round(s * 100);
        const hsl = (ss, ll) => `hsl(${h}, ${ss}%, ${ll}%)`;

        // Header/footer gradient (darker shades)
        const root = document.documentElement;
        root.style.setProperty('--brand-grad-light', hsl(Math.min(sat, 70), 35));
        root.style.setProperty('--brand-grad-mid', hsl(Math.min(sat, 80), 25));
        root.style.setProperty('--brand-grad-dark', hsl(Math.min(sat, 75), 17));

        // Full brand scale (50-950) — used by buttons, badges, accents, etc.
        // Darkened to sit closer to the header/footer gradient tones
        const sc = Math.min(sat, 85);
        root.style.setProperty('--brand-50',  hsl(sc, 94));
        root.style.setProperty('--brand-100', hsl(sc, 87));
        root.style.setProperty('--brand-200', hsl(sc, 76));
        root.style.setProperty('--brand-300', hsl(sc, 62));
        root.style.setProperty('--brand-400', hsl(sc, 50));
        root.style.setProperty('--brand-500', hsl(sc, 40));
        root.style.setProperty('--brand-600', hsl(sc, 33));
        root.style.setProperty('--brand-700', hsl(sc, 26));
        root.style.setProperty('--brand-800', hsl(sc, 19));
        root.style.setProperty('--brand-900', hsl(sc, 12));
        root.style.setProperty('--brand-950', hsl(sc, 6));

        // RGB triplet of the 500-level accent for rgba() usage in CSS
        // Convert hsl(h, sc, 50%) to RGB
        const hslToRgb = (hh, ss, ll) => {
            hh /= 360; ss /= 100; ll /= 100;
            let rr, gg, bb;
            if (ss === 0) { rr = gg = bb = ll; }
            else {
                const hue2rgb = (p, q, t) => {
                    if (t < 0) t += 1; if (t > 1) t -= 1;
                    if (t < 1/6) return p + (q - p) * 6 * t;
                    if (t < 1/2) return q;
                    if (t < 2/3) return p + (q - p) * (2/3 - t) * 6;
                    return p;
                };
                const q = ll < 0.5 ? ll * (1 + ss) : ll + ss - ll * ss;
                const p = 2 * ll - q;
                rr = hue2rgb(p, q, hh + 1/3);
                gg = hue2rgb(p, q, hh);
                bb = hue2rgb(p, q, hh - 1/3);
            }
            return [Math.round(rr*255), Math.round(gg*255), Math.round(bb*255)];
        };
        const [r5, g5, b5] = hslToRgb(h, sc, 40);
        const [r6, g6, b6] = hslToRgb(h, sc, 33);
        root.style.setProperty('--brand-500-rgb', `${r5},${g5},${b5}`);
        root.style.setProperty('--brand-600-rgb', `${r6},${g6},${b6}`);
        const [r2, g2, b2] = hslToRgb(h, sc, 76);
        const [r3, g3, b3] = hslToRgb(h, sc, 62);
        const [r4, g4, b4] = hslToRgb(h, sc, 50);
        root.style.setProperty('--brand-200-rgb', `${r2},${g2},${b2}`);
        root.style.setProperty('--brand-300-rgb', `${r3},${g3},${b3}`);
                const [r7, g7, b7] = hslToRgb(h, sc, 26);
        root.style.setProperty('--brand-700-rgb', `${r7},${g7},${b7}`);
        root.style.setProperty('--brand-400-rgb', `${r4},${g4},${b4}`);
    },

    /** Reset brand color to defaults */
    resetBrandColor() {
        const root = document.documentElement;
        const props = [
            '--brand-grad-light','--brand-grad-mid','--brand-grad-dark',
            '--brand-50','--brand-100','--brand-200','--brand-300','--brand-400',
            '--brand-500','--brand-600','--brand-700','--brand-800','--brand-900','--brand-950',
            '--brand-500-rgb','--brand-600-rgb'
        ];
        props.forEach(p => root.style.removeProperty(p));
    },

    async loadCustomLogo() {
        try {
            const result = await API.getCurrentLogo();
            const logoUrl = result.url + '?t=' + Date.now(); // cache-bust
            this._applyLogo(logoUrl);
            if (result.custom) {
                localStorage.setItem('customLogoUrl', result.url);
                // Set email template logo to absolute URL of custom logo
                const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^/]*$/, '/');
                EmailTemplate.logoUrl = baseUrl + result.url;
            } else {
                localStorage.removeItem('customLogoUrl');
                EmailTemplate.logoUrl = 'https://jasonhogan.ca/jason/img/jh-white.png';
            }
        } catch (err) {
            console.warn('Logo load error:', err);
        }
    },

    _applyLogo(url) {
        const headerLogo = document.getElementById('headerLogo');
        const previewLogo = document.getElementById('brandingLogoPreview');
        if (headerLogo) headerLogo.src = url;
        if (previewLogo) previewLogo.src = url;
    },

    async uploadLogo(file) {
        try {
            this.showToast('Uploading logo...', 'info');
            const result = await API.uploadLogo(file);
            const logoUrl = result.url + '?t=' + Date.now();
            this._applyLogo(logoUrl);
            localStorage.setItem('customLogoUrl', result.url);
            // Update email template logo URL for absolute path
            EmailTemplate.logoUrl = window.location.origin + window.location.pathname.replace(/\/[^/]*$/, '/') + result.url;
            this.showToast('Logo updated successfully!', 'success');
        } catch (err) {
            this.showToast('Failed to upload logo: ' + err.message, 'error');
        }
    },

    async resetLogo() {
        try {
            const result = await API.resetLogo();
            const logoUrl = result.url + '?t=' + Date.now();
            this._applyLogo(logoUrl);
            localStorage.removeItem('customLogoUrl');
            EmailTemplate.logoUrl = 'https://jasonhogan.ca/jason/img/jh-white.png';
            this.showToast('Logo reset to default', 'success');
        } catch (err) {
            this.showToast('Failed to reset logo: ' + err.message, 'error');
        }
    },

    // ---- Theme ----
    loadTheme() {
        const saved = localStorage.getItem('theme');
        if (saved === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
        // Sync settings UI
        const isDark = saved === 'dark';
        document.getElementById('themeLight')?.classList.toggle('active', !isDark);
        document.getElementById('themeDark')?.classList.toggle('active', isDark);
    },

    toggleTheme() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        this.setThemeFromSettings(isDark ? 'light' : 'dark');
    },

    setThemeFromSettings(theme) {
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
    },

    // ---- Utilities ----
    formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    },

    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    // ---- Attendees ----
    displayAttendees() {
        const section = document.getElementById('attendeesSection');
        const countEl = document.getElementById('attendeesCount');
        const chipsEl = document.getElementById('attendeesChips');

        if (this.audioMode !== 'meeting') {
            section.style.display = 'none';
            return;
        }

        section.style.display = '';
        countEl.textContent = this.attendees.length;

        if (!this.attendees.length) {
            chipsEl.innerHTML = '<span class="t-muted text-sm">No attendees detected. Add manually below.</span>';
            // Auto-open the body so user can add
            document.getElementById('attendeesBody').style.display = '';
            const card = document.getElementById('attendeesToggle').closest('.attendees-card');
            if (card) card.classList.add('open');
            return;
        }

        chipsEl.innerHTML = this.attendees.map((a, i) => {
            const matchClass = a.source === 'ai' ? (a.matched ? 'attendee-chip-matched' : 'attendee-chip-unmatched') : '';
            const matchIcon = a.source === 'ai' && a.matched
                ? '<svg class="attendee-match-icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>'
                : (a.source === 'ai' && !a.matched ? '<svg class="attendee-match-icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' : '');
            return `
            <div class="attendee-chip ${matchClass}">
                ${matchIcon}
                <span class="attendee-chip-name">${this.escapeHtml(a.name)}</span>
                ${a.email ? `<span class="attendee-chip-email">${this.escapeHtml(a.email)}</span>` : ''}
                <button class="attendee-chip-remove" onclick="App.removeAttendee(${i})" title="Remove">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>`;
        }).join('');
    },

    addAttendee() {
        const nameInput = document.getElementById('attendeeNameInput');
        const emailInput = document.getElementById('attendeeEmailInput');
        const name = nameInput.value.trim();
        if (!name) return;

        this.attendees.push({ name, email: emailInput.value.trim(), source: 'manual' });
        nameInput.value = '';
        emailInput.value = '';
        nameInput.focus();
        this.displayAttendees();

        // Auto-save if we have a transcription ID
        if (this.transcriptionId) {
            API.saveAttendees(this.transcriptionId, this.attendees).catch(err => console.error('Attendees save error:', err));
        }
    },

    removeAttendee(index) {
        this.attendees.splice(index, 1);
        this.displayAttendees();
        if (this.transcriptionId) {
            API.saveAttendees(this.transcriptionId, this.attendees).catch(err => console.error('Attendees save error:', err));
        }
    },

    // ---- Users (admin) ----
    showUsers() {
        this.showSection('users');
        Users.load();
    },

    // ---- Contacts ----
    showContacts() {
        this.showSection('contacts');
        Contacts.load();
    },

    // ---- Analytics ----
    showAnalytics() {
        this.showSection('analytics');
        Analytics.load();
    },

    // ---- Cover Pages ----
    async loadCoverPages() {
        try {
            const covers = await API.listCoverPages();
            this.coverPages = Array.isArray(covers) ? covers : (covers.covers || []);
            // Set default cover
            const defaultCover = this.coverPages.find(c => c.is_default);
            this.selectedCoverPage = defaultCover ? defaultCover.url : 'img/covers/default-cover.png';
            this.renderCoverPageSelector();
        } catch (err) {
            console.warn('Cover pages load error:', err);
        }
    },

    renderCoverPageSelector() {
        const container = document.getElementById('coverPageSelector');
        if (!container) return;
        if (!this.coverPages.length) {
            container.innerHTML = '';
            return;
        }

        container.innerHTML = this.coverPages.map(c => {
            const isSelected = (c.url === this.selectedCoverPage);
            return `<div class="cover-thumb ${isSelected ? 'selected' : ''}" onclick="App.selectCoverPage('${c.url}')" title="${App.escapeHtml(c.original_name || c.filename)}">
                <img src="${c.url}" alt="Cover">
                ${c.is_default ? '<span class="cover-default-star" title="Default">&#9733;</span>' : ''}
            </div>`;
        }).join('');
    },

    selectCoverPage(url) {
        this.selectedCoverPage = url;
        this.renderCoverPageSelector();
    },

    async renderSettingsCoverPages() {
        const grid = document.getElementById('coverPageGrid');
        if (!grid) return;

        try {
            const covers = await API.listCoverPages();
            this.coverPages = Array.isArray(covers) ? covers : (covers.covers || []);
        } catch { /* use cached */ }

        if (!this.coverPages.length) {
            grid.innerHTML = '<p class="t-muted text-sm">No cover pages uploaded yet.</p>';
            return;
        }

        grid.innerHTML = this.coverPages.map(c => {
            return `<div class="cover-settings-thumb ${c.is_default ? 'is-default' : ''}">
                <img src="${c.url}" alt="${App.escapeHtml(c.original_name || '')}" onclick="App.openCoverLightbox('${c.url}')">
                <div class="cover-settings-actions">
                    ${!c.is_default ? `<button class="btn-xs btn-secondary" onclick="event.stopPropagation();App.setDefaultCover(${c.id})" title="Set as default">&#9733;</button>` : '<span class="cover-default-label">Default</span>'}
                    ${!c.is_default ? `<button class="btn-xs btn-danger-icon" onclick="event.stopPropagation();App.deleteCoverPage(${c.id})" title="Delete">&times;</button>` : ''}
                </div>
            </div>`;
        }).join('');
    },

    async uploadCoverPage(file) {
        try {
            this.showToast('Uploading cover page...', 'info');
            await API.uploadCoverPage(file);
            await this.loadCoverPages();
            this.renderSettingsCoverPages();
            this.showToast('Cover page uploaded!', 'success');
        } catch (err) {
            this.showToast('Failed to upload: ' + err.message, 'error');
        }
    },

    async setDefaultCover(id) {
        try {
            await API.setDefaultCoverPage(id);
            await this.loadCoverPages();
            this.renderSettingsCoverPages();
            this.showToast('Default cover page updated', 'success');
        } catch (err) {
            this.showToast('Error: ' + err.message, 'error');
        }
    },

    async deleteCoverPage(id) {
        if (!confirm('Delete this cover page?')) return;
        try {
            await API.deleteCoverPage(id);
            await this.loadCoverPages();
            this.renderSettingsCoverPages();
            this.showToast('Cover page deleted', 'success');
        } catch (err) {
            this.showToast('Error: ' + err.message, 'error');
        }
    },

    openCoverLightbox(url) {
        const overlay = document.createElement('div');
        overlay.className = 'cover-lightbox-overlay';
        overlay.innerHTML = `<img src="${url}" alt="Cover Page Preview">`;
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) overlay.remove();
        });
        document.body.appendChild(overlay);
        // ESC to close
        const escHandler = (e) => {
            if (e.key === 'Escape') { overlay.remove(); document.removeEventListener('keydown', escHandler); }
        };
        document.addEventListener('keydown', escHandler);
    },

    // ---- Login Background ----
    _loadLoginBgPreview() {
        const preview = document.getElementById('loginBgPreview');
        const placeholder = document.getElementById('loginBgPlaceholder');
        if (!preview) return;
        // Try common extensions
        const exts = ['png', 'jpg', 'jpeg', 'webp'];
        let found = false;
        const tryNext = (i) => {
            if (i >= exts.length) {
                preview.style.display = 'none';
                if (placeholder) placeholder.style.display = '';
                return;
            }
            const img = new Image();
            img.onload = () => {
                preview.src = `img/login-bg.${exts[i]}?t=${Date.now()}`;
                preview.style.display = '';
                if (placeholder) placeholder.style.display = 'none';
            };
            img.onerror = () => tryNext(i + 1);
            img.src = `img/login-bg.${exts[i]}?t=${Date.now()}`;
        };
        tryNext(0);
    },

    async uploadLoginBackground(file) {
        try {
            this.showToast('Uploading login background...', 'info');
            const formData = new FormData();
            formData.append('logo', file);
            formData.append('type', 'login_bg');
            const response = await fetch('api/upload-logo.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Upload failed');
            this.showToast('Login background updated!', 'success');
            // Update preview
            const preview = document.getElementById('loginBgPreview');
            const placeholder = document.getElementById('loginBgPlaceholder');
            if (preview) {
                preview.src = result.url + '?t=' + Date.now();
                preview.style.display = '';
            }
            if (placeholder) placeholder.style.display = 'none';
        } catch (err) {
            this.showToast('Failed: ' + err.message, 'error');
        }
    },

    async resetLoginBackground() {
        try {
            const response = await fetch('api/upload-logo.php', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ type: 'login_bg' })
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Reset failed');
            this.showToast('Login background reset to default', 'success');
            const preview = document.getElementById('loginBgPreview');
            if (preview) preview.src = '';
        } catch (err) {
            this.showToast('Failed: ' + err.message, 'error');
        }
    },

    // ---- Autocomplete ----
    initAutocomplete() {
        if (typeof Autocomplete === 'undefined') return;

        // Email To/Cc/Bcc fields — multi-value comma-separated
        const emailInputs = ['emailTo', 'emailCc', 'emailBcc'];
        emailInputs.forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                Autocomplete.attach(input, {
                    onSearch: async (query) => {
                        try { return await API.searchContacts(query); }
                        catch { return []; }
                    },
                    onSelect: (contact, input) => {
                        // Multi-value: append to existing comma list
                        const current = input.value.trim();
                        const parts = current.split(',').map(s => s.trim()).filter(Boolean);
                        // Remove the partial query the user was typing
                        parts.pop();
                        parts.push(contact.email);
                        input.value = parts.join(', ') + ', ';
                        input.focus();
                    },
                    multiValue: true
                });
            }
        });

        // Attendee Name fields — single value, fills paired email field on select
        this._attachAttendeeAutocomplete('attendeeNameInput', 'attendeeEmailInput');
        this._attachAttendeeAutocomplete('viewModalAttendeeNameInput', 'viewModalAttendeeEmailInput');
    },

    _attachAttendeeAutocomplete(nameInputId, emailInputId) {
        if (typeof Autocomplete === 'undefined') return;
        const nameInput = document.getElementById(nameInputId);
        const emailInput = document.getElementById(emailInputId);
        if (!nameInput) return;

        Autocomplete.attach(nameInput, {
            onSearch: async (query) => {
                try { return await API.searchContacts(query); }
                catch { return []; }
            },
            onSelect: (contact, input) => {
                input.value = contact.name || contact.email;
                if (emailInput && contact.email) {
                    emailInput.value = contact.email;
                }
            },
            multiValue: false
        });

        // Also attach autocomplete to the email field for searching by email
        if (emailInput) {
            Autocomplete.attach(emailInput, {
                onSearch: async (query) => {
                    try { return await API.searchContacts(query); }
                    catch { return []; }
                },
                onSelect: (contact, input) => {
                    input.value = contact.email || '';
                    if (nameInput && contact.name && !nameInput.value.trim()) {
                        nameInput.value = contact.name;
                    }
                },
                multiValue: false
            });
        }
    },

    // =====================================================
    // DROP ZONE VORTEX ANIMATION
    // =====================================================
    _dropVortexId: null,
    _dropVortexParticles: null,

    _startDropZoneVortex(isDrag) {
        if (this._dropVortexId) return; // already running
        const canvas = document.getElementById('dropZoneCanvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        const dpr = window.devicePixelRatio || 1;
        const rect = canvas.parentElement.getBoundingClientRect();
        canvas.width = rect.width * dpr;
        canvas.height = rect.height * dpr;
        ctx.scale(dpr, dpr);
        const w = rect.width, h = rect.height;
        const cx = w / 2, cy = h / 2;

        const colors = [
            [59,130,246],[96,165,250],[147,197,253],
            [139,92,246],[168,85,247],[192,132,252],
            [236,72,153],[244,114,182],[251,113,133],
            [99,102,241],[75,139,232],[14,165,233],
            [6,182,212],[20,184,166],[245,158,11]
        ];

        // Create vortex particles
        const count = isDrag ? 120 : 75;
        const particles = [];
        for (let i = 0; i < count; i++) {
            const angle = (i / count) * Math.PI * 2;
            const r = 30 + Math.random() * (Math.min(w, h) * 0.45);
            const col = colors[Math.floor(Math.random() * colors.length)];
            particles.push({
                angle: angle,
                radius: r,
                targetRadius: r,
                speed: 0.008 + Math.random() * 0.02,
                size: 1 + Math.random() * 3,
                r: col[0], g: col[1], b: col[2],
                alpha: 0.2 + Math.random() * 0.5,
                phase: Math.random() * Math.PI * 2,
                drift: (Math.random() - 0.5) * 0.3,
            });
        }
        this._dropVortexParticles = particles;
        let time = 0;
        const intensity = isDrag ? 1.5 : 1;

        const animate = () => {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            time += 0.016;

            // Soft radial glow in center
            const glow = ctx.createRadialGradient(cx, cy, 0, cx, cy, Math.min(w, h) * 0.35);
            glow.addColorStop(0, `rgba(59,130,246,${0.06 * intensity})`);
            glow.addColorStop(0.6, `rgba(99,102,241,${0.03 * intensity})`);
            glow.addColorStop(1, 'rgba(0,0,0,0)');
            ctx.fillStyle = glow;
            ctx.fillRect(0, 0, w, h);

            particles.forEach(p => {
                p.angle += p.speed * intensity;
                p.phase += 0.03;

                // Spiral inward slightly during drag
                if (isDrag) {
                    p.targetRadius = Math.max(15, p.targetRadius - 0.15);
                }
                p.radius += (p.targetRadius - p.radius) * 0.02;

                const wobble = Math.sin(time * 2 + p.phase) * 6 * intensity;
                const x = cx + Math.cos(p.angle) * (p.radius + wobble);
                const y = cy + Math.sin(p.angle) * (p.radius + wobble);
                const alpha = p.alpha * (0.5 + 0.5 * Math.sin(p.phase));

                // Outer glow
                ctx.save();
                ctx.globalAlpha = alpha * 0.2;
                ctx.fillStyle = `rgb(${p.r},${p.g},${p.b})`;
                ctx.beginPath();
                ctx.arc(x, y, p.size * 4, 0, Math.PI * 2);
                ctx.fill();

                // Core
                ctx.globalAlpha = alpha * 0.7;
                ctx.beginPath();
                ctx.arc(x, y, p.size, 0, Math.PI * 2);
                ctx.fill();

                // Bright dot
                ctx.globalAlpha = alpha * 0.5;
                ctx.fillStyle = 'rgba(255,255,255,0.8)';
                ctx.beginPath();
                ctx.arc(x, y, p.size * 0.3, 0, Math.PI * 2);
                ctx.fill();
                ctx.restore();
            });

            this._dropVortexId = requestAnimationFrame(animate);
        };
        animate();
    },

    _stopDropZoneVortex() {
        if (this._dropVortexId) {
            cancelAnimationFrame(this._dropVortexId);
            this._dropVortexId = null;
        }
        const canvas = document.getElementById('dropZoneCanvas');
        if (canvas) {
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        }
        this._dropVortexParticles = null;
    },

    // =====================================================
    // PLASMA PARTICLE ANIMATION SYSTEM
    // =====================================================
    _particleAnimId: null,
    _particleColors: [
        [59,130,246],[139,92,246],[236,72,153],[245,158,11],
        [6,182,212],[16,185,129],[244,63,94],[168,85,247],
        [20,184,166],[99,102,241],[251,146,60],[52,211,153]
    ],
    /** Read live brand-shade RGB values from :root CSS vars for animation palettes */
    _brandParticlePalette() {
        const root = document.documentElement;
        const get = (level) => {
            const v = getComputedStyle(root).getPropertyValue('--brand-' + level + '-rgb').trim();
            if (!v) return null;
            const parts = v.split(',').map(n => parseInt(n.trim(), 10));
            return parts.length === 3 && parts.every(n => !isNaN(n)) ? parts : null;
        };
        const shades = ['200','300','400','500','600','700','800']
            .map(get).filter(Boolean);
        return shades.length ? shades : this._particleColors;
    },

    _createPlasmaParticles(w, h, count) {
        const particles = [];
        const cx = w / 2, cy = h / 2;
        const palette = this._brandParticlePalette();
        for (let i = 0; i < count; i++) {
            const angle = (i / count) * Math.PI * 2 + Math.random() * 0.5;
            const radius = 15 + Math.random() * (Math.min(cx, cy) * 0.85);
            const col = palette[Math.floor(Math.random() * palette.length)];
            particles.push({
                x: cx + Math.cos(angle) * radius,
                y: cy + Math.sin(angle) * radius,
                vx: (Math.random() - 0.5) * 0.8,
                vy: (Math.random() - 0.5) * 0.8,
                size: 1 + Math.random() * 4,
                r: col[0], g: col[1], b: col[2],
                alpha: 0.4 + Math.random() * 0.6,
                phase: Math.random() * Math.PI * 2,
                phaseSpeed: 0.015 + Math.random() * 0.035,
                orbitSpeed: (Math.random() - 0.5) * 0.012,
                orbitRadius: radius,
                orbitAngle: angle,
                trail: [],
                trailMax: 4 + Math.floor(Math.random() * 6),
                sparkle: Math.random() > 0.7,
                sparklePhase: Math.random() * Math.PI * 2,
            });
        }
        return particles;
    },

    _drawPlasma(ctx, w, h, particles, time) {
        // Soft radial background glow
        const cx = w / 2, cy = h / 2;
        const bgGrad = ctx.createRadialGradient(cx, cy, 0, cx, cy, Math.min(w, h) * 0.5);
        const _bp = this._brandParticlePalette(); const _bc1 = _bp[2] || [99,102,241]; const _bc2 = _bp[4] || [139,92,246]; bgGrad.addColorStop(0, `rgba(${_bc1[0]},${_bc1[1]},${_bc1[2]},${0.05 + 0.02 * Math.sin(time * 0.5)})`);
        bgGrad.addColorStop(0.5, `rgba(${_bc2[0]},${_bc2[1]},${_bc2[2]},${0.03 + 0.01 * Math.sin(time * 0.7)})`);
        bgGrad.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = bgGrad;
        ctx.fillRect(0, 0, w, h);

        // Draw connections between nearby particles
        for (let i = 0; i < particles.length; i++) {
            for (let j = i + 1; j < particles.length; j++) {
                const dx = particles[i].x - particles[j].x;
                const dy = particles[i].y - particles[j].y;
                const dist = Math.sqrt(dx * dx + dy * dy);
                if (dist < 60) {
                    const alpha = (1 - dist / 60) * 0.12;
                    ctx.save();
                    ctx.globalAlpha = alpha;
                    ctx.strokeStyle = `rgb(${particles[i].r},${particles[i].g},${particles[i].b})`;
                    ctx.lineWidth = 0.5;
                    ctx.beginPath();
                    ctx.moveTo(particles[i].x, particles[i].y);
                    ctx.lineTo(particles[j].x, particles[j].y);
                    ctx.stroke();
                    ctx.restore();
                }
            }
        }

        particles.forEach(p => {
            p.orbitAngle += p.orbitSpeed;
            p.phase += p.phaseSpeed;
            p.sparklePhase += 0.08;

            // Plasma-like orbital motion with wobble
            const wobble = Math.sin(time * 1.5 + p.phase) * 8;
            const breathe = 1 + 0.15 * Math.sin(time * 0.4 + p.phase * 2);
            const ox = cx + Math.cos(p.orbitAngle) * (p.orbitRadius * breathe + wobble);
            const oy = cy + Math.sin(p.orbitAngle) * (p.orbitRadius * breathe + wobble);
            p.x += (ox - p.x) * 0.035 + p.vx;
            p.y += (oy - p.y) * 0.035 + p.vy;

            if (p.x < 0 || p.x > w) p.vx *= -0.8;
            if (p.y < 0 || p.y > h) p.vy *= -0.8;
            p.x = Math.max(0, Math.min(w, p.x));
            p.y = Math.max(0, Math.min(h, p.y));

            // Store trail
            p.trail.push({ x: p.x, y: p.y });
            if (p.trail.length > p.trailMax) p.trail.shift();

            const pulseAlpha = 0.5 + 0.5 * Math.sin(p.phase);
            const alpha = p.alpha * pulseAlpha;
            const col = `${p.r},${p.g},${p.b}`;

            // Trail
            if (p.trail.length > 1) {
                ctx.save();
                for (let t = 0; t < p.trail.length - 1; t++) {
                    const trailAlpha = (t / p.trail.length) * alpha * 0.25;
                    const trailSize = p.size * (t / p.trail.length) * 0.6;
                    ctx.globalAlpha = trailAlpha;
                    ctx.fillStyle = `rgb(${col})`;
                    ctx.beginPath();
                    ctx.arc(p.trail[t].x, p.trail[t].y, trailSize, 0, Math.PI * 2);
                    ctx.fill();
                }
                ctx.restore();
            }

            ctx.save();
            // Outer plasma glow
            ctx.globalAlpha = alpha * 0.15;
            const glow = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, p.size * 6);
            glow.addColorStop(0, `rgba(${col},0.4)`);
            glow.addColorStop(1, `rgba(${col},0)`);
            ctx.fillStyle = glow;
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.size * 6, 0, Math.PI * 2);
            ctx.fill();

            // Mid glow
            ctx.globalAlpha = alpha * 0.4;
            ctx.fillStyle = `rgba(${col},0.6)`;
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.size * 2.5, 0, Math.PI * 2);
            ctx.fill();

            // Core
            ctx.globalAlpha = alpha;
            ctx.fillStyle = `rgb(${col})`;
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
            ctx.fill();

            // Bright center
            ctx.globalAlpha = alpha * 0.8;
            ctx.fillStyle = `rgba(255,255,255,0.7)`;
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.size * 0.35, 0, Math.PI * 2);
            ctx.fill();

            // Sparkle
            if (p.sparkle) {
                const sparkAlpha = Math.max(0, Math.sin(p.sparklePhase)) * alpha * 0.7;
                if (sparkAlpha > 0.1) {
                    ctx.globalAlpha = sparkAlpha;
                    ctx.fillStyle = '#fff';
                    const sLen = p.size * 2.5;
                    ctx.beginPath();
                    ctx.moveTo(p.x - sLen, p.y);
                    ctx.lineTo(p.x, p.y - 1);
                    ctx.lineTo(p.x + sLen, p.y);
                    ctx.lineTo(p.x, p.y + 1);
                    ctx.closePath();
                    ctx.fill();
                    ctx.beginPath();
                    ctx.moveTo(p.x, p.y - sLen);
                    ctx.lineTo(p.x - 1, p.y);
                    ctx.lineTo(p.x, p.y + sLen);
                    ctx.lineTo(p.x + 1, p.y);
                    ctx.closePath();
                    ctx.fill();
                }
            }
            ctx.restore();
        });
    },

    startParticleAnimation(canvasId, count) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        const dpr = window.devicePixelRatio || 1;
        const w = canvas.offsetWidth, h = canvas.offsetHeight;
        canvas.width = w * dpr;
        canvas.height = h * dpr;
        ctx.scale(dpr, dpr);
        const particles = this._createPlasmaParticles(w, h, count || 80);
        let time = 0;

        const animate = () => {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            time += 0.016;
            this._drawPlasma(ctx, w, h, particles, time);
            this._particleAnimId = requestAnimationFrame(animate);
        };
        animate();
    },

    stopParticleAnimation() {
        if (this._particleAnimId) {
            cancelAnimationFrame(this._particleAnimId);
            this._particleAnimId = null;
        }
    },

    // Mini plasma for small canvases (loading overlay, floating indicator)
    _miniAnimIds: {},
    startMiniParticles(canvasId, count = 25) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        const w = canvas.width, h = canvas.height;
        const particles = this._createPlasmaParticles(w, h, count);
        let time = 0;
        const animate = () => {
            ctx.clearRect(0, 0, w, h);
            time += 0.016;
            this._drawPlasma(ctx, w, h, particles, time);
            this._miniAnimIds[canvasId] = requestAnimationFrame(animate);
        };
        animate();
    },

    stopMiniParticles(canvasId) {
        if (this._miniAnimIds[canvasId]) {
            cancelAnimationFrame(this._miniAnimIds[canvasId]);
            delete this._miniAnimIds[canvasId];
        }
    },

    // =====================================================
    // LOADING ANIMATION OVERLAY (for History, etc.)
    // =====================================================
    _loadingTextInterval: null,
    _loadingMessages: [
        'Fetching your transcriptions...',
        'Organizing your data...',
        'Almost there...',
        'Loading records...',
        'Preparing your history...',
        'Crunching the numbers...',
        'Sorting by date...',
        'Gathering insights...',
    ],
    _processingMessages: [
        'Processing audio waves...',
        'Whisper is listening carefully...',
        'Analyzing sound patterns...',
        'Transcribing your content...',
        'Converting speech to text...',
        'Detecting language nuances...',
        'Almost there, hang tight...',
        'AI is hard at work...',
        'Extracting every word...',
        'Fine-tuning the transcript...',
    ],

    showLoadingAnimation(title, subtitle, messages) {
        const overlay = document.getElementById('loadingAnimOverlay');
        const titleEl = document.getElementById('loadingAnimTitle');
        const subtitleEl = document.getElementById('loadingAnimSubtitle');
        if (!overlay) return;
        titleEl.textContent = title || 'Loading...';
        subtitleEl.textContent = subtitle || '';
        overlay.style.display = 'flex';
        overlay.classList.remove('fade-out');
        requestAnimationFrame(() => overlay.classList.add('active'));
        this.startMiniParticles('loadingAnimCanvas', 40);

        // Cycle subtitle text
        const msgs = messages || this._loadingMessages;
        let idx = 0;
        this._loadingTextInterval = setInterval(() => {
            subtitleEl.style.opacity = '0';
            setTimeout(() => {
                idx = (idx + 1) % msgs.length;
                subtitleEl.textContent = msgs[idx];
                subtitleEl.style.opacity = '1';
            }, 300);
        }, 2200);
    },

    hideLoadingAnimation() {
        if (this._loadingTextInterval) {
            clearInterval(this._loadingTextInterval);
            this._loadingTextInterval = null;
        }
        const overlay = document.getElementById('loadingAnimOverlay');
        if (!overlay) return;
        overlay.classList.add('fade-out');
        setTimeout(() => {
            overlay.classList.remove('active', 'fade-out');
            overlay.style.display = 'none';
            this.stopMiniParticles('loadingAnimCanvas');
        }, 500);
    },

    // Show history — lightweight inline loading (no full-screen overlay
    // so background transcription indicator stays accessible)
    showReportPages() {
        this.showSection('reportPages');
        if (window.ReportPages && typeof window.ReportPages.load === 'function') {
            try { window.ReportPages.load(); } catch (e) { console.error('ReportPages.load failed', e); }
        }
    },

    showHistory() {
        this.showSection('history');
        const tbody = document.getElementById('historyTableBody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="text-center py-8 t-muted" style="padding:40px;font-size:14px;">Loading history...</td></tr>';
        History.load().catch((err) => {
            console.error('History load error:', err);
            if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="text-center py-8 t-muted" style="padding:40px;">Failed to load history. Please try again.</td></tr>';
        });
    },

    // =====================================================
    // WORKFLOW LIGHTBOX FLOW (file drop → type → email → learning input)
    // =====================================================
    _workflowFile: null,
    _workflowType: null,
    _workflowEmail: false,
    _workflowEmailAddress: '',

    startWorkflow(file) {
        this._workflowFile = file;
        this._workflowType = null;
        this._workflowEmail = false;
        this._workflowEmailAddress = '';
        // Open type selection lightbox
        document.getElementById('workflowTypeModal').classList.add('active');
    },

    selectWorkflowType(type) {
        this._workflowType = type;
        document.getElementById('workflowTypeModal').classList.remove('active');
        // Open email preference lightbox
        document.getElementById('wfEmailNo')?.classList.add('active');
        document.getElementById('wfEmailYes')?.classList.remove('active');
        document.getElementById('wfEmailField').style.display = 'none';
        document.getElementById('wfEmailAddress').value = '';
        document.getElementById('workflowEmailModal').classList.add('active');
    },

    setWorkflowEmail(wantEmail) {
        this._workflowEmail = wantEmail;
        document.getElementById('wfEmailYes')?.classList.toggle('active', wantEmail);
        document.getElementById('wfEmailNo')?.classList.toggle('active', !wantEmail);
        document.getElementById('wfEmailField').style.display = wantEmail ? '' : 'none';
        if (wantEmail) {
            setTimeout(() => document.getElementById('wfEmailAddress')?.focus(), 100);
        }
    },

    submitWorkflowEmail() {
        if (this._workflowEmail) {
            const email = document.getElementById('wfEmailAddress')?.value.trim();
            if (!email) {
                this.showToast('Please enter an email address', 'error');
                return;
            }
            this._workflowEmailAddress = email;
        }
        document.getElementById('workflowEmailModal').classList.remove('active');

        if (this._workflowType === 'learning') {
            // Show learning input lightbox
            document.getElementById('wfLearnTextInput').value = '';
            document.getElementById('wfLearnYoutubeUrl').value = '';
            document.getElementById('wfLearnObjective').value = '';
            this.setWfLearningSource('text');
            document.getElementById('workflowLearningModal').classList.add('active');
        } else {
            // Start transcription directly
            this._beginWorkflowTranscription();
        }
    },

    setWfLearningSource(source) {
        document.getElementById('wfLearnTabText')?.classList.toggle('active', source === 'text');
        document.getElementById('wfLearnTabYoutube')?.classList.toggle('active', source === 'youtube');
        const textP = document.getElementById('wfLearnTextPanel');
        const ytP   = document.getElementById('wfLearnYoutubePanel');
        if (source === 'text') {
            if (ytP)   { ytP.style.opacity = '0'; setTimeout(() => { ytP.style.display = 'none'; }, 350); }
            if (textP) { textP.style.display = ''; requestAnimationFrame(() => { textP.style.opacity = '1'; }); }
        } else {
            if (textP) { textP.style.opacity = '0'; setTimeout(() => { textP.style.display = 'none'; }, 350); }
            if (ytP)   { ytP.style.display = ''; requestAnimationFrame(() => { ytP.style.opacity = '1'; }); }
        }
        this._wfLearningSource = source;
    },
    _wfLearningSource: 'text',

    submitWorkflowLearning() {
        if (this._wfLearningSource === 'youtube') {
            const url = document.getElementById('wfLearnYoutubeUrl')?.value.trim();
            if (!url) { this.showToast('Please enter a YouTube URL', 'error'); return; }
        } else {
            const text = document.getElementById('wfLearnTextInput')?.value.trim();
            if (!text) { this.showToast('Please paste some text content', 'error'); return; }
        }
        document.getElementById('workflowLearningModal').classList.remove('active');
        this._beginWorkflowTranscription();
    },

    closeWorkflowModals() {
        this._bounceCloseModal(document.getElementById('workflowTypeModal'));
        this._bounceCloseModal(document.getElementById('workflowEmailModal'));
        this._bounceCloseModal(document.getElementById('workflowLearningModal'));
        this._workflowFile = null;
    },

    _beginWorkflowTranscription() {
        // Set the mode
        this.setAudioMode(this._workflowType);

        if (this._workflowType === 'learning') {
            // Populate the learning fields from workflow
            if (this._wfLearningSource === 'youtube') {
                this.learningSource = 'youtube';
                const url = document.getElementById('wfLearnYoutubeUrl')?.value.trim();
                const el = document.getElementById('learningYoutubeUrl');
                if (el) el.value = url;
                this.setLearningSource('youtube');
            } else {
                this.learningSource = 'text';
                const text = document.getElementById('wfLearnTextInput')?.value.trim();
                const el = document.getElementById('learningTextInput');
                if (el) el.value = text;
                this.setLearningSource('text');
            }
            const obj = document.getElementById('wfLearnObjective')?.value.trim();
            const objEl = document.getElementById('learningObjective');
            if (objEl && obj) objEl.value = obj;

            // If there's also an audio file, transcribe it first then do learning
            if (this._workflowFile) {
                this.currentFile = this._workflowFile;
                this.dom.fileName.textContent = this._workflowFile.name;
                this.dom.fileSize.textContent = this.formatFileSize(this._workflowFile.size);
                this.dom.audioPlayer.src = URL.createObjectURL(this._workflowFile);
            }
            this.startTranscription();
        } else {
            // Recording or Meeting — set file and start
            this.currentFile = this._workflowFile;
            this.dom.fileName.textContent = this._workflowFile.name;
            this.dom.fileSize.textContent = this.formatFileSize(this._workflowFile.size);
            this.dom.audioPlayer.src = URL.createObjectURL(this._workflowFile);
            this.startTranscription();
        }
    },

    // =====================================================
    // BACKGROUND TRANSCRIPTION + FLOATING INDICATOR
    // =====================================================
    _bgTranscribing: false,
    _bgResult: null,
    _bgTimerInterval: null,

    showFloatingIndicator() {
        const el = document.getElementById('bgTranscriptionIndicator');
        if (!el) return;
        el.style.display = 'flex';
        el.classList.remove('completed');
        document.getElementById('bgIndicatorViewBtn').style.display = 'none';
        document.querySelector('.bg-indicator-content').style.display = '';
        this.startMiniParticles('bgParticleCanvas', 15);
        // Sync timer
        this._bgTimerInterval = setInterval(() => {
            const el = document.getElementById('bgIndicatorTimer');
            if (el) {
                const mins = Math.floor(this.timerSeconds / 60).toString().padStart(2, '0');
                const secs = (this.timerSeconds % 60).toString().padStart(2, '0');
                el.textContent = `${mins}:${secs}`;
            }
        }, 500);
    },

    hideFloatingIndicator() {
        const el = document.getElementById('bgTranscriptionIndicator');
        if (el) el.style.display = 'none';
        this.stopMiniParticles('bgParticleCanvas');
        if (this._bgTimerInterval) {
            clearInterval(this._bgTimerInterval);
            this._bgTimerInterval = null;
        }
    },

    showFloatingComplete() {
        const el = document.getElementById('bgTranscriptionIndicator');
        if (!el) return;
        el.classList.add('completed');
        document.querySelector('.bg-indicator-content').style.display = 'none';
        document.getElementById('bgIndicatorViewBtn').style.display = '';
        this.stopMiniParticles('bgParticleCanvas');
        if (this._bgTimerInterval) {
            clearInterval(this._bgTimerInterval);
            this._bgTimerInterval = null;
        }
    },

    viewBgTranscriptionResult() {
        this.hideFloatingIndicator();
        const id = this._bgResult?.transcriptionId || this.transcriptionId;
        if (!id) {
            this.showSection('results');
            return;
        }
        // Show full-screen brand transition then navigate to report
        this._showReportTransition(id);
    },

    _showReportTransition(transcriptionId) {
        // Build the full-screen overlay
        const overlay = document.createElement('div');
        overlay.id = 'reportTransitionOverlay';
        overlay.innerHTML = `
            <div class="rt-content">
                <div class="rt-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#93c5fd" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                        <polyline points="10 9 9 9 8 9"/>
                    </svg>
                </div>
                <h2 class="rt-title">Loading Transcription Report</h2>
                <div class="rt-dots"><span></span><span></span><span></span></div>
            </div>
        `;
        document.body.appendChild(overlay);

        // Force reflow then add active class for animation
        requestAnimationFrame(() => {
            overlay.classList.add('active');
        });

        // Navigate after 2.5s
        setTimeout(() => {
            overlay.classList.add('fade-out');
            setTimeout(() => {
                window.location.href = '/api/report.php?id=' + transcriptionId;
            }, 500);
        }, 2500);
    },

    // =====================================================
    // WORKFLOW EMAIL AUTOCOMPLETE INIT
    // =====================================================
    async _autoSendWorkflowEmail(transcriptionId) {
        try {
            const senderEmail = this.getSetting('senderEmail');
            const senderName = this.getSetting('senderName') || 'JAI Transcribe';
            if (!senderEmail) return;

            const filename = this.currentFile?.name?.replace(/\.[^.]+$/, '') || 'transcript';

            // Email body links to api/report.php (signed URL) — no PDF attachment.
            let htmlBody = '';
            if (this.audioMode === 'meeting') {
                htmlBody = EmailTemplate.generate(this.transcript, this.analysis, filename);
            } else if (this.audioMode === 'learning' && this.analysis) {
                htmlBody = EmailTemplate.generateLearning ? EmailTemplate.generateLearning(this.analysis, filename) : EmailTemplate.generate(this.transcript, this.analysis, filename);
            } else {
                htmlBody = EmailTemplate.generateRecording ? EmailTemplate.generateRecording(this.transcript, filename) : EmailTemplate.generate(this.transcript, null, filename);
            }

            await API.sendSmtpEmail({
                from: senderEmail,
                from_name: senderName,
                to: [this._workflowEmailAddress],
                subject: `Transcription: ${this.analysis?.title || filename}`,
                html: htmlBody,
                transcription_id: transcriptionId || null,
            });

            // Log the email
            if (transcriptionId) {
                await API.logEmail(transcriptionId, this._workflowEmailAddress, `Transcription: ${this.analysis?.title || filename}`);
            }

            this.showToast('Email sent successfully!', 'success');
            this._workflowEmailAddress = '';
        } catch (err) {
            console.error('Auto-email failed:', err);
            this.showToast('Email send failed: ' + err.message, 'error');
        }
    },

    initWorkflowAutocomplete() {
        if (typeof Autocomplete === 'undefined') return;
        const emailInput = document.getElementById('wfEmailAddress');
        if (!emailInput) return;
        Autocomplete.attach(emailInput, {
            onSearch: async (query) => {
                try { return await API.searchContacts(query); }
                catch { return []; }
            },
            onSelect: (contact, input) => {
                input.value = contact.email || contact.name;
            },
            multiValue: false
        });
    }
};

document.addEventListener('DOMContentLoaded', () => App.init());
