/**
 * Transcribe AI - Main Application Controller
 * Manages UI state, event handlers, and user interactions
 */

const App = {
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
        this.loadCoverPages();
    },

    // ---- Auth ----
    async checkAuth() {
        try {
            const result = await API.checkAuth();
            if (result.authenticated) {
                this.currentUser = result.user;
                return true;
            }
        } catch (e) {
            console.warn('Auth check failed:', e);
        }
        window.location.href = 'login.php';
        return false;
    },

    applyRolePermissions() {
        if (!this.currentUser) return;
        const role = this.currentUser.role;

        // Nav buttons visibility
        const usersBtn = document.getElementById('usersBtn');
        const settingsBtn = document.getElementById('settingsBtn');
        const analyticsBtn = document.getElementById('analyticsBtn');
        const contactsBtn = document.getElementById('contactsBtn');

        if (role === 'admin') {
            // Admin: full access
            if (usersBtn) usersBtn.style.display = '';
            if (settingsBtn) settingsBtn.style.display = '';
            if (analyticsBtn) analyticsBtn.style.display = '';
            if (contactsBtn) contactsBtn.style.display = '';
        } else if (role === 'manager') {
            // Manager: no Settings, no Users
            if (usersBtn) usersBtn.style.display = 'none';
            if (settingsBtn) settingsBtn.style.display = 'none';
            if (analyticsBtn) analyticsBtn.style.display = '';
            if (contactsBtn) contactsBtn.style.display = '';
        } else {
            // User: only History visible, no Settings/Analytics/Contacts/Users
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

            // Toast
            toast: document.getElementById('toast'),
        };
    },

    bindEvents() {
        // Upload zone
        const dz = this.dom.dropZone;
        dz.addEventListener('click', () => this.dom.fileInput.click());
        dz.addEventListener('dragover', (e) => { e.preventDefault(); dz.classList.add('dragover'); });
        dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
        dz.addEventListener('drop', (e) => {
            e.preventDefault();
            dz.classList.remove('dragover');
            if (e.dataTransfer.files.length) this.handleFile(e.dataTransfer.files[0]);
        });
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

        // Settings tabs
        document.getElementById('settingsTabs').addEventListener('click', (e) => {
            const btn = e.target.closest('.settings-tab');
            if (!btn) return;
            const tab = btn.dataset.stab;
            document.querySelectorAll('.settings-tab').forEach(b => b.classList.toggle('active', b.dataset.stab === tab));
            document.querySelectorAll('.settings-panel').forEach(p => p.classList.toggle('active', p.dataset.stabPanel === tab));
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
        this.dom.sendEmailSubmit.addEventListener('click', () => this.sendEmail());

        // History
        document.getElementById('historyBtn').addEventListener('click', () => this.showHistory());
        document.getElementById('historyBackBtn').addEventListener('click', () => this.showSection('upload'));

        // Analytics
        document.getElementById('analyticsBtn').addEventListener('click', () => this.showAnalytics());
        document.getElementById('analyticsBackBtn').addEventListener('click', () => this.showSection('upload'));

        // Contacts
        document.getElementById('contactsBtn')?.addEventListener('click', () => this.showContacts());

        // Users (admin only)
        document.getElementById('usersBtn')?.addEventListener('click', () => this.showUsers());
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
        document.getElementById('learningTextPanel').style.display = source === 'text' ? '' : 'none';
        document.getElementById('learningYoutubePanel').style.display = source === 'youtube' ? '' : 'none';
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

        this.currentFile = file;
        this.dom.fileName.textContent = file.name;
        this.dom.fileSize.textContent = this.formatFileSize(file.size);

        const url = URL.createObjectURL(file);
        this.dom.audioPlayer.src = url;

        this.showSection('file');
    },

    // ---- Transcription ----
    async startTranscription() {
        // Learning mode: different flow (no audio upload)
        if (this.audioMode === 'learning') {
            return this.startLearningAnalysis();
        }

        if (!this.currentFile) return;

        API.clearPendingCosts(); // Reset cost tracking for new transcription
        this.showSection('processing');
        this.startTimer();
        this.startWaveformAnimation();
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
            this.showSection('results');
            this.showToast('Transcription complete!', 'success');

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

            // Hide insights tab for recording mode
            this.updateTabVisibility();

            // Auto-save: for recording mode save now; for meeting mode, save after analysis
            if (this.audioMode === 'recording') {
                this.saveToDatabase();
            }

        } catch (error) {
            this.stopTimer();
            this.stopWaveformAnimation();
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

        if (this.learningSource === 'youtube') {
            const url = document.getElementById('learningYoutubeUrl')?.value.trim();
            if (!url) {
                this.showToast('Please enter a YouTube URL', 'error');
                return;
            }
            transcriptSource = 'youtube';

            this.showSection('processing');
            this.startTimer();
            this.dom.processingStatus.textContent = 'Fetching YouTube transcript...';
            this.dom.processingSubstatus.textContent = 'Pulling captions from the video';

            try {
                const ytResult = await API.getYouTubeTranscript(url);
                transcriptText = ytResult.transcript;
                if (!transcriptText) throw new Error('No transcript available for this video');
            } catch (err) {
                this.stopTimer();
                this.showError('YouTube transcript fetch failed: ' + err.message);
                return;
            }
        } else {
            transcriptText = document.getElementById('learningTextInput')?.value.trim();
            if (!transcriptText) {
                this.showToast('Please paste some text content to analyze', 'error');
                return;
            }
            transcriptSource = 'text';
            this.showSection('processing');
            this.startTimer();
        }

        this.transcript = transcriptText;
        this.dom.processingStatus.textContent = 'Analyzing content with AI...';
        this.dom.processingSubstatus.textContent = 'Generating comprehensive learning report — this may take a moment';

        try {
            const objective = document.getElementById('learningObjective')?.value.trim() || '';
            this.analysis = await API.analyzeLearning(transcriptText, objective, apiKey);
            this.stopTimer();
            this.displayTranscript();
            this.displayLearningResults(this.analysis);
            this.showSection('results');
            this.showToast('Learning analysis complete!', 'success');

            // Set title from analysis
            if (this.analysis.title) {
                this.dom.resultTitle && (this.dom.resultTitle.textContent = this.analysis.title);
            }

            // Update tab visibility - show insights, default to insights tab
            this.updateTabVisibility();
            this.switchTab('insights');

            // Save to database
            this.saveToDatabase(transcriptSource);
        } catch (error) {
            this.stopTimer();
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
        this.showLoading('Generating PDF...');
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
        this.dom.emailModal.classList.remove('active');
    },

    async sendEmail() {
        const to = this.dom.emailTo.value.trim();
        const from = this.dom.emailFrom.value.trim();
        const subject = this.dom.emailSubject.value.trim();

        if (!to) { this.showToast('Please enter at least one recipient.', 'error'); return; }
        if (!from) { this.showToast('Please enter a sender address.', 'error'); return; }
        if (!subject) { this.showToast('Please enter a subject.', 'error'); return; }

        this.closeEmailModal();
        this.showLoading('Preparing email & PDF...');

        try {
            // Generate PDF as base64
            const filename = this.currentFile?.name?.replace(/\.[^.]+$/, '') || 'transcript';
            const pdfBase64 = await API.generatePdfBase64(this.transcript, this.analysis, filename, this.audioMode);

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

            // SMTP credentials are now read server-side from the settings DB
            const emailOptions = {
                from: from,
                from_name: this.getSetting('senderName') || '',
                to: toList,
                subject: subject,
                html: html,
                attachments: [{
                    filename: `${filename.replace(/[^a-zA-Z0-9_-]/g, '_')}${this.audioMode === 'learning' ? '_learning_report' : '_transcript'}.pdf`,
                    content: pdfBase64,
                    content_type: 'application/pdf'
                }]
            };

            if (ccVal) emailOptions.cc = ccVal.split(',').map(e => e.trim()).filter(Boolean);
            if (bccVal) emailOptions.bcc = bccVal.split(',').map(e => e.trim()).filter(Boolean);

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
    showHistory() {
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

    resetToUpload() {
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
        };

        Object.entries(sectionMap).forEach(([key, el]) => {
            if (el) el.classList.toggle('active', key === name);
        });

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
        const toast = this.dom.toast;
        const icons = {
            success: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
            error: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
            info: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
            warning: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        };

        toast.className = `toast ${type}`;
        toast.innerHTML = `${icons[type] || ''}${this.escapeHtml(message)}`;
        toast.offsetHeight;
        toast.classList.add('show');

        clearTimeout(this._toastTimer);
        this._toastTimer = setTimeout(() => toast.classList.remove('show'), 4000);
    },

    showLoading(text) {
        this.dom.loadingText.textContent = text;
        this.dom.loadingOverlay.classList.add('active');
    },

    hideLoading() {
        this.dom.loadingOverlay.classList.remove('active');
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

        // Footer text
        const footerInput = document.getElementById('footerTextInput');
        if (footerInput) footerInput.value = settings.footerText || '';

        // Login animation
        const animEnabled = document.getElementById('loginAnimationEnabled');
        const animSelect = document.getElementById('loginAnimationSelect');
        if (animEnabled) animEnabled.checked = settings.loginAnimationEnabled === '1' || settings.loginAnimationEnabled === 'true';
        if (animSelect) animSelect.value = settings.loginAnimation || 'constellations';
    },

    closeSettings() {
        this.dom.settingsModal.classList.remove('active');
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
            footerText: (document.getElementById('footerTextInput')?.value || '').trim(),
            loginAnimationEnabled: document.getElementById('loginAnimationEnabled')?.checked ? '1' : '0',
            loginAnimation: document.getElementById('loginAnimationSelect')?.value || 'constellations',
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
                footerEl.textContent = fields.footerText || 'Powered by Whisper AI & OpenRouter \u00b7 Transcribe AI by Botson';
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
                if (footerEl) footerEl.textContent = settings.footerText;
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

        // Load custom logo
        this.loadCustomLogo();
    },

    /**
     * Get a setting value (from cache, avoids network calls during operations)
     */
    getSetting(key) {
        return this._settingsCache[key] || '';
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
    },

    toggleTheme() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        if (isDark) {
            document.documentElement.removeAttribute('data-theme');
            localStorage.setItem('theme', 'light');
        } else {
            document.documentElement.setAttribute('data-theme', 'dark');
            localStorage.setItem('theme', 'dark');
        }
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
            if (preview) preview.src = result.url + '?t=' + Date.now();
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
    }
};

document.addEventListener('DOMContentLoaded', () => App.init());
