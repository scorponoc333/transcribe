/**
 * API Module - Handles Whisper transcription (via PHP), OpenRouter AI analysis, and SMTP email sending
 */

const API = {
    TRANSCRIBE_URL: 'api/transcribe.php',
    OPENROUTER_URL: 'https://openrouter.ai/api/v1/chat/completions',
    OPENROUTER_GENERATION_URL: 'https://openrouter.ai/api/v1/generation',
    OPENROUTER_MODEL: 'google/gemini-2.5-pro',
    _configuredModel: null,
    SMTP_URL: 'api/send-smtp.php',

    // Track last generation costs for saving after DB insert
    _lastGenerationCosts: [],

    // Model pricing per 1M tokens (fallback when OpenRouter generation cost unavailable)
    _modelPricing: {
        'google/gemini-2.5-pro': { prompt: 1.25, completion: 10.00 },
        'google/gemini-2.5-flash': { prompt: 0.15, completion: 0.60 },
        'anthropic/claude-sonnet-4': { prompt: 3.00, completion: 15.00 },
        'anthropic/claude-3.5-sonnet': { prompt: 3.00, completion: 15.00 },
        'openai/gpt-4o': { prompt: 2.50, completion: 10.00 },
        'openai/gpt-4o-mini': { prompt: 0.15, completion: 0.60 },
        'meta-llama/llama-3.3-70b-instruct': { prompt: 0.39, completion: 0.39 },
        'deepseek/deepseek-chat-v3-0324': { prompt: 0.14, completion: 0.28 },
    },

    /** Estimate cost from token counts using model pricing (per 1M tokens) */
    _estimateCost(model, promptTokens, completionTokens) {
        // Try exact match first, then partial match
        let pricing = this._modelPricing[model];
        if (!pricing) {
            const key = Object.keys(this._modelPricing).find(k => model && model.includes(k.split('/').pop()));
            pricing = key ? this._modelPricing[key] : null;
        }
        if (!pricing) return 0;
        return ((promptTokens / 1_000_000) * pricing.prompt) + ((completionTokens / 1_000_000) * pricing.completion);
    },

    /** Get the active AI model (configured or default) */
    getModel() { return this._configuredModel || this.OPENROUTER_MODEL; },
    /** Set the active AI model */
    setModel(model) { if (model) this._configuredModel = model; },

    // ─── Auth API ───
    async checkAuth() {
        const res = await fetch('api/auth.php?action=check');
        if (!res.ok) {
            throw new Error(`Auth check returned HTTP ${res.status}`);
        }
        return res.json();
    },
    async logout() {
        await fetch('api/auth.php?action=logout', { method: 'POST' });
    },

    // ─── Settings API ───
    async getSettings() {
        const res = await fetch('api/settings.php', {
            cache: 'no-store',
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Failed to load settings');
        return data.settings || {};
    },
    async saveSettings(settings) {
        const res = await fetch('api/settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ settings }),
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Failed to save settings');
        return data;
    },

    // ─── Users API (admin) ───
    async listUsers(opts = {}) {
        const { page = 1, limit = 20, q = '' } = typeof opts === 'object' ? opts : { page: opts };
        const params = new URLSearchParams({ action: 'list', page, limit, q });
        const res = await fetch('api/users.php?' + params);
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Failed to list users');
        return data;
    },
    async createUser(userData) {
        const res = await fetch('api/users.php?action=create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(userData)
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Failed to create user');
        return data;
    },
    async updateUser(userData) {
        const res = await fetch('api/users.php?action=update', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(userData)
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Failed to update user');
        return data;
    },
    async resetUserPassword(id, newPassword) {
        const res = await fetch('api/users.php?action=reset_password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, new_password: newPassword })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Failed to reset password');
        return data;
    },
    async toggleUserActive(id, isActive) {
        const res = await fetch('api/users.php?action=toggle_active', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, is_active: isActive ? 1 : 0 })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Failed to update user status');
        return data;
    },

    // ─── Cover Pages API ───
    async listCoverPages() {
        const res = await fetch('api/cover-pages.php');
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Failed to list cover pages');
        return data.covers || [];
    },
    async uploadCoverPage(file) {
        const fd = new FormData();
        fd.append('cover_image', file);
        const res = await fetch('api/cover-pages.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Failed to upload cover page');
        return data;
    },
    async deleteCoverPage(id) {
        const res = await fetch('api/cover-pages.php?id=' + id, { method: 'DELETE' });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Failed to delete cover page');
        return data;
    },
    async setDefaultCoverPage(id) {
        const res = await fetch('api/cover-pages.php?action=set_default', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Failed to set default cover');
        return data;
    },

    /**
     * Upload audio file and transcribe via Whisper CLI (PHP backend)
     */
    async transcribe(file, model = 'turbo', onProgress = null) {
        const formData = new FormData();
        formData.append('audio', file);
        formData.append('model', model);

        const xhr = new XMLHttpRequest();

        return new Promise((resolve, reject) => {
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable && onProgress) {
                    onProgress(Math.round((e.loaded / e.total) * 100));
                }
            });

            xhr.addEventListener('load', () => {
                try {
                    const data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        resolve(data);
                    } else {
                        reject(new Error(data.error || 'Transcription failed'));
                    }
                } catch {
                    reject(new Error('Invalid response from server'));
                }
            });

            xhr.addEventListener('error', () => {
                reject(new Error('Network error - is your XAMPP server running?'));
            });

            xhr.addEventListener('timeout', () => {
                reject(new Error('Request timed out. The audio file may be too long.'));
            });

            xhr.open('POST', this.TRANSCRIBE_URL);
            xhr.timeout = 7200000; // 2 hours - needed for long audio transcriptions
            xhr.send(formData);
        });
    },

    /**
     * Send transcript to OpenRouter for AI analysis
     * @param {string} transcript - The transcribed text
     * @param {string} apiKey - OpenRouter API key
     * @param {string} mode - 'meeting' or 'recording'
     */
    async analyzeTranscript(transcript, apiKey, mode = 'recording') {
        if (!apiKey) {
            throw new Error('OpenRouter API key is required. Please add it in Settings.');
        }

        const prompt = mode === 'meeting'
            ? this._buildMeetingPrompt(transcript)
            : this._buildRecordingPrompt(transcript);

        const response = await fetch(this.OPENROUTER_URL, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${apiKey}`,
                'Content-Type': 'application/json',
                'HTTP-Referer': window.location.origin || 'http://localhost',
                'X-Title': 'JAI Transcribe'
            },
            body: JSON.stringify({
                model: this.getModel(),
                messages: [{ role: 'user', content: prompt }],
                temperature: 0.3,
                max_tokens: 4096
            })
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            const msg = errorData?.error?.message || `OpenRouter API error (${response.status})`;
            throw new Error(msg);
        }

        const data = await response.json();
        const content = data.choices?.[0]?.message?.content;

        if (!content) {
            throw new Error('No response from AI model');
        }

        // Capture generation cost data from response
        const generationId = data.id || null;
        const usage = data.usage || {};
        this._lastGenerationCosts.push({
            operation: 'analyze',
            generation_id: generationId,
            model: data.model || this.OPENROUTER_MODEL,
            prompt_tokens: usage.prompt_tokens || 0,
            completion_tokens: usage.completion_tokens || 0,
            total_tokens: (usage.prompt_tokens || 0) + (usage.completion_tokens || 0),
            cost_usd: this._estimateCost(data.model || this.getModel(), usage.prompt_tokens || 0, usage.completion_tokens || 0)
        });

        // Fetch actual cost from OpenRouter generation endpoint (async, non-blocking)
        if (generationId && apiKey) {
            this._fetchGenerationCost(generationId, apiKey, this._lastGenerationCosts.length - 1);
        }

        let cleaned = content.trim();
        if (cleaned.startsWith('```')) {
            cleaned = cleaned.replace(/^```(?:json)?\s*/, '').replace(/\s*```$/, '');
        }

        try {
            const analysis = JSON.parse(cleaned);
            if (!analysis.summary || !analysis.keyPoints || !analysis.actionItems || !analysis.suggestions) {
                throw new Error('Incomplete analysis structure');
            }
            return analysis;
        } catch (e) {
            const jsonMatch = cleaned.match(/\{[\s\S]*\}/);
            if (jsonMatch) {
                try { return JSON.parse(jsonMatch[0]); } catch { /* fall through */ }
            }
            throw new Error('Failed to parse AI analysis. The model returned an invalid response.');
        }
    },

    /** Meeting-specific analysis prompt */
    _buildMeetingPrompt(transcript) {
        return `You are an expert meeting analyst. Analyze the following meeting transcript and provide a professional meeting summary.

Return your response as valid JSON with exactly these keys:
{
  "title": "A short, descriptive title for this meeting (5-10 words)",
  "summary": "A clear, professional executive summary of the meeting (2-3 paragraphs). Cover what was discussed, key decisions made, and overall outcomes.",
  "keyPoints": ["Array of 4-8 key discussion points, decisions, or takeaways from the meeting"],
  "actionItems": ["Array of specific action items, tasks, deadlines, or follow-ups identified in the meeting. Include who is responsible if mentioned. If none are explicitly stated, infer reasonable next steps based on the discussion."],
  "suggestions": ["Array of 3-5 recommendations for follow-up, improvements, or next steps based on the meeting content. These should be practical and relevant to the discussion."],
  "attendees": ["Array of names of people who participated in or were mentioned in the meeting. Extract names from speaker labels, introductions, or references in the transcript. If no names can be identified, return an empty array."]
}

IMPORTANT: Return ONLY valid JSON. No markdown, no code fences, no extra text.

MEETING TRANSCRIPT:
---
${transcript}
---`;
    },

    /** Recording/general audio analysis prompt */
    _buildRecordingPrompt(transcript) {
        return `You are an expert content analyst. Analyze the following audio transcript and provide a comprehensive analysis.

Return your response as valid JSON with exactly these keys:
{
  "title": "A short, descriptive title summarizing this recording (5-10 words)",
  "summary": "A clear, well-written executive summary of the transcript content (2-3 paragraphs). Be specific about what was discussed or communicated.",
  "keyPoints": ["Array of 4-8 key points, themes, or takeaways from the recording"],
  "actionItems": ["Array of specific action items, tasks, or follow-ups identified in the recording. If none are explicitly mentioned, infer reasonable next steps based on the content."],
  "suggestions": ["Array of 3-5 suggestions or recommendations based on the content. These should be insightful and actionable."]
}

IMPORTANT: Return ONLY valid JSON. No markdown, no code fences, no extra text.

TRANSCRIPT:
---
${transcript}
---`;
    },

    /**
     * Detect language and translate if non-English
     * @param {string} transcript - The transcribed text
     * @param {string} apiKey - OpenRouter API key
     * @returns {Object} { language: string, translatedText: string|null }
     */
    async detectAndTranslate(transcript, apiKey) {
        if (!apiKey) return { language: 'en', translatedText: null };

        // Take a sample for detection (first 1000 chars is enough)
        const sample = transcript.substring(0, 1000);

        const response = await fetch(this.OPENROUTER_URL, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${apiKey}`,
                'Content-Type': 'application/json',
                'HTTP-Referer': window.location.origin || 'http://localhost',
                'X-Title': 'JAI Transcribe'
            },
            body: JSON.stringify({
                model: this.getModel(),
                messages: [{ role: 'user', content: `Detect the language of the following text. If the text is NOT in English, translate the ENTIRE text below into English.

Return valid JSON only:
{
  "language": "the ISO language name (e.g. 'English', 'French', 'Spanish', 'Japanese', etc.)",
  "isEnglish": true/false,
  "translatedText": "Full English translation of the text, or null if already English"
}

IMPORTANT: Return ONLY valid JSON. No markdown, no code fences.
If the text IS English, set isEnglish to true and translatedText to null.
If the text is NOT English, provide the COMPLETE translation of the full text below.

TEXT:
---
${transcript}
---` }],
                temperature: 0.2,
                max_tokens: 8192
            })
        });

        if (!response.ok) return { language: 'en', translatedText: null };

        const data = await response.json();
        const content = data.choices?.[0]?.message?.content;
        if (!content) return { language: 'en', translatedText: null };

        // Capture translation cost data
        const generationId = data.id || null;
        const usage = data.usage || {};
        this._lastGenerationCosts.push({
            operation: 'translate',
            generation_id: generationId,
            model: data.model || this.OPENROUTER_MODEL,
            prompt_tokens: usage.prompt_tokens || 0,
            completion_tokens: usage.completion_tokens || 0,
            total_tokens: (usage.prompt_tokens || 0) + (usage.completion_tokens || 0),
            cost_usd: this._estimateCost(data.model || this.getModel(), usage.prompt_tokens || 0, usage.completion_tokens || 0)
        });

        if (generationId && apiKey) {
            this._fetchGenerationCost(generationId, apiKey, this._lastGenerationCosts.length - 1);
        }

        let cleaned = content.trim();
        if (cleaned.startsWith('```')) {
            cleaned = cleaned.replace(/^```(?:json)?\s*/, '').replace(/\s*```$/, '');
        }

        try {
            const result = JSON.parse(cleaned);
            return {
                language: result.language || 'en',
                translatedText: result.isEnglish ? null : (result.translatedText || null)
            };
        } catch {
            return { language: 'en', translatedText: null };
        }
    },

    /**
     * Send email via SMTP (PHPMailer backend)
     * @param {Object} options - SMTP email options
     * @param {string} options.smtp_host - SMTP server hostname
     * @param {number} options.smtp_port - SMTP port (587, 465, 25)
     * @param {string} options.smtp_encryption - 'tls', 'ssl', or 'none'
     * @param {string} options.smtp_user - SMTP username
     * @param {string} options.smtp_pass - SMTP password
     * @param {string} options.from - Sender email (or "Name <email>")
     * @param {string} [options.from_name] - Sender display name
     * @param {string|string[]} options.to - Recipient(s)
     * @param {string|string[]} [options.cc] - CC recipients
     * @param {string|string[]} [options.bcc] - BCC recipients
     * @param {string} options.subject - Subject line
     * @param {string} options.html - HTML body
     * @param {Object[]} [options.attachments] - Array of {filename, content (base64), content_type}
     */
    async sendSmtpEmail(options) {
        // SMTP credentials are read server-side from the settings DB
        // Only validate if explicitly passed (backward compat)

        // Parse "Display Name <email>" format for the from field
        let fromEmail = options.from;
        let fromName = options.from_name || '';
        const match = options.from.match(/^(.+?)\s*<(.+?)>$/);
        if (match) {
            fromName = fromName || match[1].trim();
            fromEmail = match[2].trim();
        }

        const body = {
            smtp_host: options.smtp_host,
            smtp_port: options.smtp_port || 587,
            smtp_encryption: options.smtp_encryption || 'tls',
            smtp_user: options.smtp_user,
            smtp_pass: options.smtp_pass,
            from: fromEmail,
            from_name: fromName,
            to: options.to,
            subject: options.subject,
            html: options.html,
        };

        if (options.cc) body.cc = options.cc;
        if (options.bcc) body.bcc = options.bcc;
        if (options.attachments?.length) body.attachments = options.attachments;
        // Critical: forward transcription_id so send-smtp.php can build the
        // report URL. Without this the URL falls back to '#' and the email
        // button does nothing when clicked.
        if (options.transcription_id) body.transcription_id = options.transcription_id;
        if (options.reply_to) body.reply_to = options.reply_to;

        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 60000); // 60s timeout

        let response;
        try {
            response = await fetch(this.SMTP_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
                signal: controller.signal
            });
        } catch (err) {
            clearTimeout(timeoutId);
            if (err.name === 'AbortError') {
                throw new Error('Email send timed out. Check your SMTP settings (host, port, encryption).');
            }
            throw err;
        }
        clearTimeout(timeoutId);

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            const msg = errorData?.error || errorData?.message || `SMTP error (${response.status})`;
            throw new Error(msg);
        }

        return await response.json();
    },

    // ---- Database API Methods ----

    async saveTranscription(data) {
        const response = await fetch('api/save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to save transcription');
        return result;
    },

    async savePdf(id, pdfBase64, filename) {
        const response = await fetch('api/save-pdf.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, pdf_base64: pdfBase64, filename })
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to save PDF');
        return result;
    },

    async listTranscriptions(params = {}) {
        const query = new URLSearchParams(params).toString();
        const response = await fetch(`api/list.php?${query}`);
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to list transcriptions');
        return result;
    },

    async getTranscription(id) {
        const response = await fetch(`api/get.php?id=${id}`);
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to get transcription');
        return result.data;
    },

    async getEmailLog(transcriptionId) {
        const url = transcriptionId ? `api/email-log.php?transcription_id=${transcriptionId}` : 'api/email-log.php';
        const response = await fetch(url);
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to get email log');
        return result.data;
    },

    async deleteTranscription(id) {
        const response = await fetch('api/delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to delete transcription');
        return result;
    },

    async saveEmailLog(data) {
        const response = await fetch('api/save-email-log.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to save email log');
        return result;
    },

    // ---- Attendees API ----

    async getAttendees(transcriptionId) {
        const response = await fetch(`api/attendees.php?transcription_id=${transcriptionId}`);
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to get attendees');
        return result.data;
    },

    async saveAttendees(transcriptionId, attendees) {
        const response = await fetch('api/attendees.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ transcription_id: transcriptionId, attendees })
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to save attendees');
        return result;
    },

    // ---- Contacts API ----

    async searchContacts(query) {
        const response = await fetch(`api/contacts.php?q=${encodeURIComponent(query)}`);
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to search contacts');
        return result.data;
    },

    async saveContacts(contacts) {
        const response = await fetch('api/contacts.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ contacts })
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to save contacts');
        return result;
    },

    async listContacts(page = 1, limit = 20, query = '') {
        const params = new URLSearchParams({ action: 'list', page, limit });
        if (query) params.set('q', query);
        const response = await fetch(`api/contacts.php?${params}`);
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to list contacts');
        return result;
    },

    async createContact(data) {
        const response = await fetch('api/contacts.php?action=create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to create contact');
        return result;
    },

    async updateContact(data) {
        const response = await fetch('api/contacts.php?action=update', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to update contact');
        return result;
    },

    async deleteContact(id) {
        const response = await fetch('api/contacts.php?action=delete', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to delete contact');
        return result;
    },

    async importContactsCsv(file) {
        const formData = new FormData();
        formData.append('csv_file', file);
        const response = await fetch('api/contacts.php?action=import_csv', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to import CSV');
        return result;
    },

    async matchContactNames(names) {
        const response = await fetch('api/contacts.php?action=match_names', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ names })
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to match names');
        return result.matches;
    },

    // ---- Learning Mode API ----

    async getYouTubeTranscript(url) {
        const idMatch = url.match(/(?:youtube\.com\/watch\?.*v=|youtu\.be\/|youtube\.com\/shorts\/)([a-zA-Z0-9_-]{11})/);
        if (!idMatch) throw new Error('Invalid YouTube URL');
        const videoId = idMatch[1];

        // Method 1: Server-side (may fail due to YouTube IP blocking)
        try {
            const r = await fetch('api/youtube-transcript.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ url })
            });
            const d = await r.json();
            if (r.ok && d.success) return d;
        } catch (e) { console.warn('Server YT fetch failed:', e); }

        // Method 2: Use our server proxy to get caption tracks, then fetch captions
        try {
            const t = await this._ytViaProxy(videoId);
            if (t) return t;
        } catch (e) { console.warn('Proxy method failed:', e); }

        // Method 3: Client-side innertube API (user's browser IP)
        try {
            const t = await this._ytInnertubeClient(videoId);
            if (t) return { success: true, transcript: t, title: 'YouTube Video', video_id: videoId };
        } catch (e) { console.warn('Innertube failed:', e); }

        // Method 4: Client-side page scrape
        try {
            const t = await this._ytCaptionsClient(videoId);
            if (t) return { success: true, transcript: t, title: 'YouTube Video', video_id: videoId };
        } catch (e) { console.warn('Captions scrape failed:', e); }

        throw new Error('Could not fetch transcript. The video may not have captions, or it might be private.');
    },

    async _ytViaProxy(videoId) {
        // Use our proxy which tries innertube player API with different clients
        const resp = await fetch(`api/yt-proxy.php?video_id=${videoId}&action=player`);
        const data = await resp.json();
        if (data?.success && data?.transcript) return data;

        // Fallback: try tracks endpoint
        const trackResp = await fetch(`api/yt-proxy.php?video_id=${videoId}&action=tracks`);
        const trackData = await trackResp.json();
        if (!trackData?.tracks?.length) return null;

        let trackUrl = null;
        for (const t of trackData.tracks) {
            if (t.languageCode === 'en') { trackUrl = t.baseUrl; break; }
        }
        if (!trackUrl) trackUrl = trackData.tracks[0]?.baseUrl;
        if (!trackUrl) return null;

        const capResp = await fetch(`api/yt-proxy.php?action=caption&caption_url=${encodeURIComponent(trackUrl)}`);
        const capData = await capResp.json();
        if (!capData?.success || !capData?.transcript) return null;

        return {
            success: true,
            transcript: capData.transcript,
            title: trackData.title || `YouTube Video (${videoId})`,
            video_id: videoId,
        };
    },

    async _ytInnertubeClient(videoId) {
        // Use no-cors mode won't work for reading response. Instead, try with credentials
        // which sends YouTube cookies if user is logged in — bypasses bot detection
        const r = await fetch('https://www.youtube.com/youtubei/v1/get_transcript?prettyPrint=false', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
                context: { client: { clientName: 'WEB', clientVersion: '2.20241031.00.00' } },
                params: btoa('\n\x0b' + videoId)
            })
        });
        if (!r.ok) return null;
        const data = await r.json();
        for (const action of (data?.actions || [])) {
            const panel = action?.updateEngagementPanelAction?.content?.transcriptRenderer?.content?.transcriptSearchPanelRenderer;
            if (!panel) continue;
            const segs = panel?.body?.transcriptSegmentListRenderer?.initialSegments || [];
            const lines = [];
            for (const s of segs) {
                const text = (s?.transcriptSegmentRenderer?.snippet?.runs || []).map(r => r.text || '').join('').trim();
                if (text && !/^\[.*\]$/.test(text)) lines.push(text);
            }
            if (lines.length) {
                const paras = [];
                for (let i = 0; i < lines.length; i += 5) paras.push(lines.slice(i, i + 5).join(' '));
                return paras.join('\n\n');
            }
        }
        return null;
    },

    async _ytCaptionsClient(videoId) {
        const r = await fetch(`https://www.youtube.com/watch?v=${videoId}`, { credentials: 'omit' });
        if (!r.ok) return null;
        const html = await r.text();
        const m = html.match(/"captionTracks":\s*(\[.*?\])/);
        if (!m) return null;
        let tracks; try { tracks = JSON.parse(m[1]); } catch { return null; }
        if (!tracks?.length) return null;
        let trackUrl = null;
        for (const t of tracks) { if (t.languageCode === 'en') { trackUrl = t.baseUrl; break; } }
        if (!trackUrl) trackUrl = tracks[0]?.baseUrl;
        if (!trackUrl) return null;
        const xr = await fetch(trackUrl);
        if (!xr.ok) return null;
        const xml = await xr.text();
        const doc = new DOMParser().parseFromString(xml, 'text/xml');
        const lines = [];
        for (const n of doc.querySelectorAll('text')) {
            let t = n.textContent.replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>').trim();
            t = t.replace(/<[^>]+>/g,'').replace(/\s+/g,' ').trim();
            if (t && !/^\[.*\]$/.test(t)) lines.push(t);
        }
        if (!lines.length) return null;
        const paras = [];
        for (let i = 0; i < lines.length; i += 5) paras.push(lines.slice(i, i + 5).join(' '));
        return paras.join('\n\n');
    },

    async analyzeLearning(transcript, objective, apiKey) {
        const prompt = this._buildLearningPrompt(transcript, objective);

        const response = await fetch(this.OPENROUTER_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${apiKey}`,
                'HTTP-Referer': window.location.origin,
                'X-Title': 'JAI Transcribe - Learning Analysis'
            },
            body: JSON.stringify({
                model: this.getModel(),
                messages: [{ role: 'user', content: prompt }],
                temperature: 0.3,
                max_tokens: 8000,
            })
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            throw new Error(errorData?.error?.message || `OpenRouter API error (${response.status})`);
        }

        const data = await response.json();
        const content = data.choices?.[0]?.message?.content;
        if (!content) throw new Error('No response from AI model');

        // Track cost
        const generationId = data.id || null;
        const usage = data.usage || {};
        this._lastGenerationCosts.push({
            operation: 'learning_analysis',
            generation_id: generationId,
            model: data.model || this.OPENROUTER_MODEL,
            prompt_tokens: usage.prompt_tokens || 0,
            completion_tokens: usage.completion_tokens || 0,
            total_tokens: (usage.prompt_tokens || 0) + (usage.completion_tokens || 0),
            cost_usd: this._estimateCost(data.model || this.getModel(), usage.prompt_tokens || 0, usage.completion_tokens || 0)
        });
        if (generationId && apiKey) {
            this._fetchGenerationCost(generationId, apiKey, this._lastGenerationCosts.length - 1);
        }

        let cleaned = content.trim();
        if (cleaned.startsWith('```')) {
            cleaned = cleaned.replace(/^```(?:json)?\s*/, '').replace(/\s*```$/, '');
        }

        try {
            return JSON.parse(cleaned);
        } catch (e) {
            const jsonMatch = cleaned.match(/\{[\s\S]*\}/);
            if (jsonMatch) {
                try { return JSON.parse(jsonMatch[0]); } catch { /* fall through */ }
            }
            throw new Error('Failed to parse learning analysis. The AI returned an invalid response.');
        }
    },

    _buildLearningPrompt(transcript, objective) {
        const objectiveSection = objective
            ? `\nThe user's specific learning objective is: "${objective}"\nTailor your analysis to directly address this learning goal.\n`
            : '\nNo specific learning objective was provided. Provide a comprehensive general learning analysis.\n';

        return `You are an expert educational content analyst and learning specialist. Your job is to analyze content and create comprehensive, well-structured learning reports that help people deeply understand any topic.

Analyze the following content and create a thorough learning analysis report.
${objectiveSection}
CRITICAL INSTRUCTIONS:
1. Return ONLY valid JSON — no markdown, no code fences, no extra text
2. Only include blocks where you found meaningful, relevant content. OMIT blocks entirely if they don't apply
3. Be thorough and detailed — this report should help someone truly learn and understand the material
4. Extract ALL relevant information: names, dates, statistics, resources, concepts, relationships
5. Make explanations clear and accessible regardless of the reader's expertise level
6. When statistics are found, present them prominently with context
7. For technical content, explain terms in plain language
8. Identify relationships between concepts to help build understanding
9. NEVER use markdown formatting (no ** or ## or * etc) — write plain text only
10. For the learning_objectives_addressed field, write flowing prose paragraphs. Do NOT use numbered lists or bullet points — write it as natural paragraphs explaining what the learner will gain
11. URLS ARE CRITICAL — READ THIS CAREFULLY: NEVER generate or guess URLs. The ONLY URLs you may include are ones that were EXPLICITLY mentioned in the transcript text (e.g., "visit openai.com" or "check out github.com/user/repo"). If a URL was not literally spoken or written in the source content, DO NOT include any URL at all. Do not try to find URLs for products mentioned — just describe the product without a URL. NEVER fabricate YouTube links, article links, or any other URLs. Omit the "url" field entirely unless the exact URL appeared in the transcript. A resource with no URL is correct; a resource with a fake URL is a critical error.

Return JSON with these OPTIONAL keys (include only what's relevant):
{
  "title": "A descriptive, engaging title for this learning report (8-15 words)",
  "difficulty_level": "beginner OR intermediate OR advanced — based on the content complexity",
  "executive_summary": "A comprehensive overview of the content (3-4 paragraphs). Cover the main themes, why this content matters, and what the reader will gain from it. Plain text only, no markdown.",
  "learning_objectives_addressed": "How this content addresses the user's stated learning objective. Write 2-3 flowing paragraphs in plain prose. No numbered lists, no bullets, no markdown. Explain naturally what the learner will understand after studying this content.",
  "key_concepts": [{"term": "concept name", "explanation": "detailed explanation in plain language", "importance": "high/medium/low"}],
  "glossary": [{"term": "technical term", "definition": "clear definition in simple language"}],
  "core_insights": ["Key insight or takeaway — each should be a complete, actionable thought"],
  "important_people": [{"name": "Person Name", "role": "their role/title", "relevance": "why they matter in this context"}],
  "statistics": [{"stat": "the statistic (e.g., 85%)", "context": "what this statistic means and why it matters", "source": "where it came from if mentioned"}],
  "dates_timeline": [{"date": "date or time period", "event": "what happened", "significance": "why this matters"}],
  "locations": [{"name": "location name", "context": "relevance to the content"}],
  "products_tools": [{"name": "product or tool name", "description": "what it does", "search_query": "a specific Google search query to find this product"}],
  "resources_urls": [{"name": "resource name", "description": "what this resource offers", "search_query": "a specific Google search query to find this resource"}],
  "contact_info": [{"name": "person or organization", "detail": "contact details mentioned"}],
  "roadmap": [{"phase": "phase name or step", "description": "what happens in this phase", "timeline": "when, if mentioned"}],
  "action_items": [{"task": "specific action to take", "priority": "high/medium/low"}],
  "concept_relationships": [{"from": "concept A", "to": "concept B", "relationship": "how A relates to B"}],
  "prerequisites": ["Knowledge or skills needed to fully understand this content — ONLY include if the content genuinely requires prior knowledge. Omit entirely for beginner-friendly content."],
  "further_learning": [{"topic": "suggested topic to explore next", "why": "why this would help deepen understanding", "resource": "suggested resource name if applicable"}],
  "tldr": "A punchy 2-3 sentence summary of the ENTIRE content. Write it like you're explaining to a friend in 30 seconds. Plain text, no markdown.",
  "estimated_study_time_minutes": 15,
  "key_quotes": [{"quote": "exact memorable quote from the transcript", "speaker": "who said it if known", "context": "why this quote matters"}],
  "practical_exercises": [{"title": "exercise name", "description": "what to do — be specific and actionable", "difficulty": "beginner/intermediate/advanced", "time_estimate": "how long it takes"}]
}

SECTION INCLUSION RULES — READ CAREFULLY:
- ONLY include a section if there is genuinely meaningful content for it
- If the transcript has no memorable quotes, OMIT key_quotes entirely
- If no prior knowledge is needed, OMIT prerequisites entirely
- If practical exercises don't make sense for this content, OMIT practical_exercises entirely
- If the content is too short for a roadmap, OMIT roadmap entirely
- estimated_study_time_minutes should be a realistic number (integer) based on content length and complexity
- The AI should be honest — empty sections make the report look bad, so only include what adds real value

CONTENT TO ANALYZE:
---
${transcript}
---`;
    },

    // ---- Analytics API ----

    async getAnalytics() {
        const response = await fetch('api/analytics.php');
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to get analytics');
        return result.data;
    },

    // ---- AI Cost Tracking ----

    /**
     * Fetch actual cost from OpenRouter's generation endpoint
     * Retries with delay since cost data may take a moment to finalize
     */
    async _fetchGenerationCost(generationId, apiKey, costIndex) {
        // Wait a bit for OpenRouter to finalize cost data
        await new Promise(r => setTimeout(r, 2000));

        try {
            const response = await fetch(`${this.OPENROUTER_GENERATION_URL}?id=${generationId}`, {
                headers: { 'Authorization': `Bearer ${apiKey}` }
            });
            if (!response.ok) return;

            const result = await response.json();
            const genData = result.data;
            if (genData && this._lastGenerationCosts[costIndex]) {
                this._lastGenerationCosts[costIndex].cost_usd = genData.total_cost || genData.usage || 0;
                this._lastGenerationCosts[costIndex].prompt_tokens = genData.tokens_prompt || this._lastGenerationCosts[costIndex].prompt_tokens;
                this._lastGenerationCosts[costIndex].completion_tokens = genData.tokens_completion || this._lastGenerationCosts[costIndex].completion_tokens;
                this._lastGenerationCosts[costIndex].total_tokens = (genData.tokens_prompt || 0) + (genData.tokens_completion || 0);
                if (genData.model) this._lastGenerationCosts[costIndex].model = genData.model;
            }
        } catch (err) {
            console.warn('Could not fetch generation cost:', err);
        }
    },

    /**
     * Clear pending cost records (call before a new transcription flow)
     */
    clearPendingCosts() {
        this._lastGenerationCosts = [];
    },

    /**
     * Get pending cost records and optionally attach a transcription ID
     */
    getPendingCosts(transcriptionId = null) {
        return this._lastGenerationCosts.map(c => ({
            ...c,
            transcription_id: transcriptionId
        }));
    },

    /**
     * Save an AI cost record to the database
     */
    async saveAiCost(costData) {
        const response = await fetch('api/ai-costs.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(costData)
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to save AI cost');
        return result;
    },

    /**
     * Save all pending cost records for a given transcription
     */
    async savePendingCosts(transcriptionId) {
        // Wait a moment for async cost fetches to complete
        await new Promise(r => setTimeout(r, 3000));

        const costs = this.getPendingCosts(transcriptionId);
        const results = [];
        for (const cost of costs) {
            try {
                // If OpenRouter didn't return a cost, estimate from tokens
                if (!cost.cost_usd && cost.prompt_tokens > 0) {
                    cost.cost_usd = this._estimateCost(cost.model, cost.prompt_tokens, cost.completion_tokens);
                }
                const result = await this.saveAiCost(cost);
                results.push(result);
            } catch (err) {
                console.warn('Failed to save cost record:', err);
            }
        }
        this.clearPendingCosts();
        return results;
    },

    /**
     * Get all AI cost records (for analytics)
     */
    async getAiCosts(transcriptionId = null) {
        const url = transcriptionId ? `api/ai-costs.php?transcription_id=${transcriptionId}` : 'api/ai-costs.php';
        const response = await fetch(url);
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to get AI costs');
        return result.data;
    },

    // ---- Logo / Branding ----

    async getCurrentLogo() {
        const response = await fetch('api/upload-logo.php');
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to get logo');
        return result;
    },

    async uploadLogo(file) {
        const formData = new FormData();
        formData.append('logo', file);
        const response = await fetch('api/upload-logo.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to upload logo');
        return result;
    },

    async resetLogo() {
        const response = await fetch('api/upload-logo.php', { method: 'DELETE' });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Failed to reset logo');
        return result;
    },

    /**
     * Generate PDF as base64 string (for email attachment)
     * Supports recording/meeting and learning modes
     */
    async generatePdfBase64(transcript, analysis, filename = 'transcript', mode = null) {
        // Learning mode: use dedicated learning PDF generator
        if (mode === 'learning' && analysis) {
            return this._generateLearningPdfBase64(analysis, filename);
        }

        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

        // Reuse PDFGenerator logic but return base64 instead of saving
        const coverPath = App?.selectedCoverPage || 'img/covers/default-cover.png';
        const [coverImg, logoImg] = await Promise.all([
            PDFGenerator.loadImage(coverPath),
            PDFGenerator.loadImage(PDFGenerator.getLogoPath())
        ]);

        const sections = {};
        PDFGenerator.addCoverPage(doc, coverImg, logoImg);

        doc.addPage();
        const tocPageNum = doc.getCurrentPageInfo().pageNumber;

        const hasAnalysis = analysis && (analysis.summary || analysis.keyPoints?.length || analysis.actionItems?.length || analysis.suggestions?.length);

        if (hasAnalysis) {
            doc.addPage();
            sections.summary = doc.getCurrentPageInfo().pageNumber;
            PDFGenerator.currentY = PDFGenerator.headerH + 10;
            PDFGenerator.addSummarySection(doc, analysis);

            doc.addPage();
            sections.keyPoints = doc.getCurrentPageInfo().pageNumber;
            PDFGenerator.currentY = PDFGenerator.headerH + 10;
            PDFGenerator.addKeyPointsSection(doc, analysis);

            if (PDFGenerator.currentY > PDFGenerator.pageH - 90) {
                doc.addPage();
                PDFGenerator.currentY = PDFGenerator.headerH + 10;
            }
            sections.actionItems = doc.getCurrentPageInfo().pageNumber;
            PDFGenerator.addActionItemsSection(doc, analysis);

            if (PDFGenerator.currentY > PDFGenerator.pageH - 90) {
                doc.addPage();
                PDFGenerator.currentY = PDFGenerator.headerH + 10;
            }
            sections.suggestions = doc.getCurrentPageInfo().pageNumber;
            PDFGenerator.addSuggestionsSection(doc, analysis);
        }

        doc.addPage();
        sections.transcript = doc.getCurrentPageInfo().pageNumber;
        PDFGenerator.currentY = PDFGenerator.headerH + 10;
        PDFGenerator.addTranscriptSection(doc, transcript);

        doc.setPage(tocPageNum);
        PDFGenerator.addTableOfContents(doc, sections, logoImg, hasAnalysis);

        const totalPages = doc.getNumberOfPages();
        for (let i = 2; i <= totalPages; i++) {
            doc.setPage(i);
            PDFGenerator.addPageHeader(doc, logoImg);
            PDFGenerator.addPageFooter(doc, i, totalPages);
        }

        // Return as base64 (strip data:application/pdf;base64, prefix)
        const base64 = doc.output('datauristring').split(',')[1];
        return base64;
    },

    /**
     * Generate Learning PDF as base64 (for email attachment & auto-save)
     */
    async _generateLearningPdfBase64(analysis, filename = 'learning-report') {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

        const coverPath = App?.selectedCoverPage || 'img/covers/default-cover.png';
        const [coverImg, logoImg] = await Promise.all([
            PDFGenerator.loadImage(coverPath),
            PDFGenerator.loadImage(PDFGenerator.getLogoPath())
        ]);

        PDFGenerator.addCoverPage(doc, coverImg, logoImg);

        doc.addPage();
        const tocPageNum = doc.getCurrentPageInfo().pageNumber;

        const sections = {};
        let sectionNum = 1;

        if (analysis.executive_summary) {
            doc.addPage(); sections.summary = doc.getCurrentPageInfo().pageNumber;
            PDFGenerator.currentY = PDFGenerator.headerH + 10;
            PDFGenerator.addSectionBanner(doc, String(sectionNum++).padStart(2, '0'), 'Executive Summary');
            PDFGenerator._addLearningTextBlock(doc, analysis.executive_summary);
        }
        if (analysis.key_concepts?.length) {
            doc.addPage(); sections.keyConcepts = doc.getCurrentPageInfo().pageNumber;
            PDFGenerator.currentY = PDFGenerator.headerH + 10;
            PDFGenerator.addSectionBanner(doc, String(sectionNum++).padStart(2, '0'), 'Key Concepts');
            analysis.key_concepts.forEach((c, i) => {
                const text = `${c.term}: ${c.explanation}${c.importance ? ' [' + c.importance.toUpperCase() + ']' : ''}`;
                PDFGenerator.addNumberedCard(doc, text, i + 1, PDFGenerator.colors.primary);
            });
        }
        if (analysis.core_insights?.length) {
            if (PDFGenerator.currentY > PDFGenerator.pageH - 80) { doc.addPage(); PDFGenerator.currentY = PDFGenerator.headerH + 10; }
            sections.insights = doc.getCurrentPageInfo().pageNumber;
            PDFGenerator.addSectionBanner(doc, String(sectionNum++).padStart(2, '0'), 'Core Insights');
            analysis.core_insights.forEach((insight, i) => PDFGenerator.addNumberedCard(doc, PDFGenerator._toText(insight), i + 1, PDFGenerator.colors.green));
        }
        if (analysis.glossary?.length) {
            if (PDFGenerator.currentY > PDFGenerator.pageH - 80) { doc.addPage(); PDFGenerator.currentY = PDFGenerator.headerH + 10; }
            sections.glossary = doc.getCurrentPageInfo().pageNumber;
            PDFGenerator.addSectionBanner(doc, String(sectionNum++).padStart(2, '0'), 'Glossary');
            analysis.glossary.forEach((g, i) => PDFGenerator.addNumberedCard(doc, `${g.term} — ${g.definition}`, i + 1, PDFGenerator.colors.purple));
        }
        if (analysis.important_people?.length) {
            if (PDFGenerator.currentY > PDFGenerator.pageH - 80) { doc.addPage(); PDFGenerator.currentY = PDFGenerator.headerH + 10; }
            sections.people = doc.getCurrentPageInfo().pageNumber;
            PDFGenerator.addSectionBanner(doc, String(sectionNum++).padStart(2, '0'), 'Important People');
            analysis.important_people.forEach((p, i) => PDFGenerator.addNumberedCard(doc, `${p.name}${p.role ? ' — ' + p.role : ''}${p.relevance ? ': ' + p.relevance : ''}`, i + 1, PDFGenerator.colors.primary));
        }
        if (analysis.statistics?.length) {
            if (PDFGenerator.currentY > PDFGenerator.pageH - 80) { doc.addPage(); PDFGenerator.currentY = PDFGenerator.headerH + 10; }
            sections.statistics = doc.getCurrentPageInfo().pageNumber;
            PDFGenerator.addSectionBanner(doc, String(sectionNum++).padStart(2, '0'), 'Key Statistics');
            analysis.statistics.forEach((s, i) => PDFGenerator.addNumberedCard(doc, `${s.stat} — ${s.context}${s.source ? ' (Source: ' + s.source + ')' : ''}`, i + 1, PDFGenerator.colors.amber));
        }
        if (analysis.dates_timeline?.length) {
            if (PDFGenerator.currentY > PDFGenerator.pageH - 80) { doc.addPage(); PDFGenerator.currentY = PDFGenerator.headerH + 10; }
            sections.timeline = doc.getCurrentPageInfo().pageNumber;
            PDFGenerator.addSectionBanner(doc, String(sectionNum++).padStart(2, '0'), 'Timeline & Dates');
            analysis.dates_timeline.forEach((d, i) => PDFGenerator.addNumberedCard(doc, `${d.date}: ${d.event}${d.significance ? ' — ' + d.significance : ''}`, i + 1, PDFGenerator.colors.primary));
        }
        if (analysis.resources_urls?.length) {
            if (PDFGenerator.currentY > PDFGenerator.pageH - 80) { doc.addPage(); PDFGenerator.currentY = PDFGenerator.headerH + 10; }
            sections.resources = doc.getCurrentPageInfo().pageNumber;
            PDFGenerator.addSectionBanner(doc, String(sectionNum++).padStart(2, '0'), 'Resources & URLs');
            analysis.resources_urls.forEach((r, i) => PDFGenerator.addNumberedCard(doc, `${r.name}${r.description ? ' — ' + r.description : ''}${r.url ? '\n' + r.url : ''}`, i + 1, PDFGenerator.colors.green));
        }
        if (analysis.action_items?.length) {
            if (PDFGenerator.currentY > PDFGenerator.pageH - 80) { doc.addPage(); PDFGenerator.currentY = PDFGenerator.headerH + 10; }
            sections.actions = doc.getCurrentPageInfo().pageNumber;
            PDFGenerator.addSectionBanner(doc, String(sectionNum++).padStart(2, '0'), 'Action Items');
            analysis.action_items.forEach((item, i) => PDFGenerator.addCheckboxCard(doc, `${item.task}${item.priority ? ' [' + item.priority.toUpperCase() + ']' : ''}`, i + 1));
        }
        if (analysis.further_learning?.length) {
            if (PDFGenerator.currentY > PDFGenerator.pageH - 80) { doc.addPage(); PDFGenerator.currentY = PDFGenerator.headerH + 10; }
            sections.furtherLearning = doc.getCurrentPageInfo().pageNumber;
            PDFGenerator.addSectionBanner(doc, String(sectionNum++).padStart(2, '0'), 'Further Learning');
            analysis.further_learning.forEach((f, i) => PDFGenerator.addNumberedCard(doc, `${f.topic}: ${f.why}${f.resource ? '\nResource: ' + f.resource : ''}`, i + 1, PDFGenerator.colors.purple));
        }

        doc.setPage(tocPageNum);
        PDFGenerator._addLearningTOC(doc, sections, logoImg);

        const totalPages = doc.getNumberOfPages();
        for (let i = 2; i <= totalPages; i++) {
            doc.setPage(i);
            PDFGenerator.addPageHeader(doc, logoImg);
            PDFGenerator.addPageFooter(doc, i, totalPages);
        }

        const base64 = doc.output('datauristring').split(',')[1];
        return base64;
    }
};
