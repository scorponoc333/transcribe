/**
 * Email Template Generator
 * Creates beautiful HTML email templates for transcript delivery
 */

const EmailTemplate = {
    logoUrl: 'https://jasonhogan.ca/jason/img/jh-white.png',

    /**
     * Generate a polished HTML email for a transcription
     * @param {Object} opts
     * @param {string} opts.title - AI-generated title for the transcription
     * @param {string} opts.summary - Executive summary text
     * @param {string[]} opts.keyPoints - Key points array
     * @param {string[]} opts.actionItems - Action items array
     * @param {string} opts.mode - 'meeting' or 'recording'
     */
    generate(opts) {
        const { title, summary, keyPoints, actionItems, mode } = opts;
        const modeLabel = mode === 'meeting' ? 'Meeting' : 'Recording';
        const date = new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });

        const keyPointsHtml = keyPoints?.length
            ? keyPoints.map(p => `
                <tr>
                    <td style="padding:0 12px 0 0;vertical-align:top;color:#2563eb;font-size:18px;line-height:24px;">&#8226;</td>
                    <td style="padding:0 0 10px 0;color:#334155;font-size:15px;line-height:24px;">${this._esc(p)}</td>
                </tr>`).join('')
            : '';

        const actionItemsHtml = actionItems?.length
            ? actionItems.map(a => `
                <tr>
                    <td style="padding:0 10px 0 0;vertical-align:top;color:#10b981;font-size:16px;line-height:24px;">&#9744;</td>
                    <td style="padding:0 0 10px 0;color:#334155;font-size:15px;line-height:24px;">${this._esc(a)}</td>
                </tr>`).join('')
            : '';

        return `<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#d0d8e2;font-family:'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#d0d8e2;">
<tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;box-shadow:0 4px 24px rgba(0,0,0,0.12),0 1px 4px rgba(0,0,0,0.06);">

<!-- HEADER -->
<tr><td style="background:linear-gradient(135deg,#1e3a5f 0%,#2563eb 50%,#7c3aed 100%);border-radius:16px 16px 0 0;padding:36px 40px 32px;text-align:center;">
    <img src="${this.logoUrl}" alt="Jason Hogan" width="200" style="display:block;margin:0 auto 20px;max-width:200px;height:auto;">
    <p style="margin:0;font-size:13px;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,0.7);font-weight:600;">${modeLabel} Transcription</p>
    <h1 style="margin:10px 0 0;font-size:24px;font-weight:700;color:#ffffff;line-height:1.3;">${this._esc(title)}</h1>
    <p style="margin:12px 0 0;font-size:13px;color:rgba(255,255,255,0.6);">${date}</p>
</td></tr>

<!-- BODY -->
<tr><td style="background:#ffffff;padding:0;">

    ${this._greetingSection(`In this email you can find your transcription for <strong style="color:#1e293b;">${this._esc(title)}</strong>. The full transcript is attached as a PDF report. Below is the AI-generated summary and insights.`)}

    <!-- Blue divider -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td style="padding:28px 40px 0;">
        <div style="height:3px;background:linear-gradient(90deg,#2563eb,#7c3aed);border-radius:2px;"></div>
    </td></tr>
    </table>

    <!-- Executive Summary -->
    ${summary ? `
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td style="padding:28px 40px 0;">
        <h2 style="margin:0 0 16px;font-size:18px;font-weight:700;color:#1e293b;letter-spacing:-0.3px;">
            <span style="display:inline-block;width:4px;height:18px;background:#2563eb;border-radius:2px;margin-right:10px;vertical-align:middle;"></span>
            Executive Summary
        </h2>
        <div style="background:#f8fafc;border-left:3px solid #2563eb;border-radius:0 8px 8px 0;padding:20px 24px;">
            <p style="margin:0;font-size:15px;color:#334155;line-height:26px;">${this._esc(summary)}</p>
        </div>
    </td></tr>
    </table>` : ''}

    <!-- Key Points -->
    ${keyPointsHtml ? `
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td style="padding:28px 40px 0;">
        <h2 style="margin:0 0 16px;font-size:18px;font-weight:700;color:#1e293b;letter-spacing:-0.3px;">
            <span style="display:inline-block;width:4px;height:18px;background:#7c3aed;border-radius:2px;margin-right:10px;vertical-align:middle;"></span>
            Key Points
        </h2>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            ${keyPointsHtml}
        </table>
    </td></tr>
    </table>` : ''}

    <!-- Action Items -->
    ${actionItemsHtml ? `
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td style="padding:28px 40px 0;">
        <h2 style="margin:0 0 16px;font-size:18px;font-weight:700;color:#1e293b;letter-spacing:-0.3px;">
            <span style="display:inline-block;width:4px;height:18px;background:#10b981;border-radius:2px;margin-right:10px;vertical-align:middle;"></span>
            Action Items
        </h2>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            ${actionItemsHtml}
        </table>
    </td></tr>
    </table>` : ''}

    <!-- CTA -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td style="padding:32px 40px;">
        <p style="margin:0;font-size:14px;color:#64748b;line-height:22px;text-align:center;font-style:italic;">The complete transcript and detailed AI analysis are included in the attached PDF report.</p>
    </td></tr>
    </table>

</td></tr>

${this._contactSection()}

${this._consultationBanner()}

<!-- FOOTER -->
<tr><td style="background:#0f172a;border-radius:0 0 16px 16px;padding:28px 40px;text-align:center;">
    <p style="margin:0;font-size:13px;color:rgba(255,255,255,0.5);line-height:20px;">Sent via <strong style="color:rgba(255,255,255,0.7);">Botson Transcribe</strong> by Jason Hogan</p>
    <p style="margin:8px 0 0;font-size:12px;color:rgba(255,255,255,0.3);">${date}</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>`;
    },

    /**
     * Generate a simplified email for recording-only transcriptions (no AI analysis)
     */
    generateRecording(opts) {
        const { title } = opts;
        const date = new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });

        return `<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#d0d8e2;font-family:'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#d0d8e2;">
<tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;box-shadow:0 4px 24px rgba(0,0,0,0.12),0 1px 4px rgba(0,0,0,0.06);">

<!-- HEADER -->
<tr><td style="background:linear-gradient(135deg,#1e3a5f 0%,#2563eb 50%,#7c3aed 100%);border-radius:16px 16px 0 0;padding:36px 40px 32px;text-align:center;">
    <img src="${this.logoUrl}" alt="Jason Hogan" width="200" style="display:block;margin:0 auto 20px;max-width:200px;height:auto;">
    <p style="margin:0;font-size:13px;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,0.7);font-weight:600;">Audio Transcription</p>
    <h1 style="margin:10px 0 0;font-size:24px;font-weight:700;color:#ffffff;line-height:1.3;">${this._esc(title)}</h1>
    <p style="margin:12px 0 0;font-size:13px;color:rgba(255,255,255,0.6);">${date}</p>
</td></tr>

<!-- BODY -->
<tr><td style="background:#ffffff;padding:0;">

    ${this._greetingSection(`Please find attached the transcription for <strong style="color:#1e293b;">${this._esc(title)}</strong>. The full transcript has been compiled into a professional PDF report for your convenience.`)}

    <!-- Blue divider -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td style="padding:28px 40px 0;">
        <div style="height:3px;background:linear-gradient(90deg,#2563eb,#7c3aed);border-radius:2px;"></div>
    </td></tr>
    </table>

    <!-- Info box -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td style="padding:28px 40px;">
        <div style="background:#f8fafc;border-left:3px solid #2563eb;border-radius:0 8px 8px 0;padding:20px 24px;">
            <p style="margin:0;font-size:15px;color:#334155;line-height:26px;">The attached PDF contains the complete transcript of your audio recording. Open the attachment to read the full text.</p>
        </div>
    </td></tr>
    </table>

</td></tr>

${this._contactSection()}

${this._consultationBanner()}

<!-- FOOTER -->
<tr><td style="background:#0f172a;border-radius:0 0 16px 16px;padding:28px 40px;text-align:center;">
    <p style="margin:0;font-size:13px;color:rgba(255,255,255,0.5);line-height:20px;">Sent via <strong style="color:rgba(255,255,255,0.7);">Botson Transcribe</strong> by Jason Hogan</p>
    <p style="margin:8px 0 0;font-size:12px;color:rgba(255,255,255,0.3);">${date}</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>`;
    },

    /**
     * Contact information grid section — Phone, Email, Website, LinkedIn
     */
    _contactSection() {
        return `
<!-- CONTACT INFO -->
<tr><td style="background:#ffffff;padding:0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td style="padding:28px 40px 8px;text-align:center;">
        <h2 style="margin:0 0 4px;font-size:20px;font-weight:800;color:#1e293b;">My contact information</h2>
        <p style="margin:0 0 20px;font-size:13px;color:#64748b;">Make sure to connect with me on <a href="https://www.linkedin.com/in/jasonhogan333/" style="color:#2563eb;text-decoration:underline;font-weight:600;">Linkedin</a></p>
    </td></tr>
    </table>

    <!-- 2x2 Contact Grid -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td style="padding:0 40px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <!-- Phone -->
            <td width="50%" valign="top" style="padding:0 6px 12px 0;">
                <a href="tel:587-983-7066" style="text-decoration:none;display:block;">
                    <img src="https://cdn.dragitstorage.com/workspaces/c8336b20-381f-4e80-b2fd-c08814dda406/files/JH-Phone.jpg" alt="Phone: 587-983-7066" width="260" style="display:block;width:100%;max-width:260px;height:auto;border-radius:8px;">
                </a>
                <p style="margin:8px 0 0;text-align:center;">
                    <a href="tel:587-983-7066" style="color:#1e293b;font-size:14px;font-weight:600;text-decoration:none;">587-983-7066</a>
                </p>
            </td>
            <!-- Email -->
            <td width="50%" valign="top" style="padding:0 0 12px 6px;">
                <a href="mailto:me@jasonhogan.ca" style="text-decoration:none;display:block;">
                    <img src="https://cdn.dragitstorage.com/workspaces/c8336b20-381f-4e80-b2fd-c08814dda406/files/jh-email.jpg" alt="Email: me@jasonhogan.ca" width="260" style="display:block;width:100%;max-width:260px;height:auto;border-radius:8px;">
                </a>
                <p style="margin:8px 0 0;text-align:center;">
                    <a href="mailto:me@jasonhogan.ca" style="color:#1e293b;font-size:14px;font-weight:600;text-decoration:none;">me@jasonhogan.ca</a>
                </p>
            </td>
        </tr>
        <tr>
            <!-- Website -->
            <td width="50%" valign="top" style="padding:0 6px 0 0;">
                <a href="https://jasonhogan.ca" style="text-decoration:none;display:block;">
                    <img src="https://cdn.dragitstorage.com/workspaces/c8336b20-381f-4e80-b2fd-c08814dda406/files/wesbite.jpg" alt="Website: jasonhogan.ca" width="260" style="display:block;width:100%;max-width:260px;height:auto;border-radius:8px;">
                </a>
                <p style="margin:8px 0 0;text-align:center;">
                    <a href="https://jasonhogan.ca" style="color:#1e293b;font-size:14px;font-weight:600;text-decoration:none;">jasonhogan.ca</a>
                </p>
            </td>
            <!-- LinkedIn -->
            <td width="50%" valign="top" style="padding:0 0 0 6px;">
                <a href="https://www.linkedin.com/in/jasonhogan333/" style="text-decoration:none;display:block;">
                    <img src="https://cdn.dragitstorage.com/workspaces/c8336b20-381f-4e80-b2fd-c08814dda406/files/JH-Linkedin.jpg" alt="LinkedIn: /in/jasonhogan333" width="260" style="display:block;width:100%;max-width:260px;height:auto;border-radius:8px;">
                </a>
                <p style="margin:8px 0 0;text-align:center;">
                    <a href="https://www.linkedin.com/in/jasonhogan333/" style="color:#1e293b;font-size:14px;font-weight:600;text-decoration:none;">/in/jasonhogan333</a>
                </p>
            </td>
        </tr>
        </table>
    </td></tr>
    </table>
</td></tr>`;
    },

    /**
     * Consultation booking banner image
     */
    _consultationBanner() {
        return `
<!-- CONSULTATION BANNER -->
<tr><td style="background:#ffffff;padding:24px 40px 28px;text-align:center;">
    <a href="https://bookings.jasonhogan.ca" style="text-decoration:none;display:block;">
        <img src="https://cdn.dragitstorage.com/workspaces/c8336b20-381f-4e80-b2fd-c08814dda406/files/Dropped%20Images/consultation.jpg" alt="Book a Consultation" width="520" style="display:block;width:100%;max-width:520px;height:auto;border-radius:10px;margin:0 auto;">
    </a>
</td></tr>`;
    },

    /**
     * Normalise an item that might be a string or an object into a string.
     * AI models sometimes return list items as objects instead of strings.
     */
    _toText(item) {
        if (typeof item === 'string') return item;
        if (item === null || item === undefined) return '';
        if (typeof item !== 'object') return String(item);
        const keys = ['text', 'task', 'description', 'item', 'point', 'suggestion',
                      'recommendation', 'content', 'summary', 'action', 'title', 'name'];
        for (const k of keys) {
            if (item[k] && typeof item[k] === 'string') {
                const extras = [];
                if (item.assignee) extras.push(`Assignee: ${item.assignee}`);
                if (item.owner) extras.push(`Owner: ${item.owner}`);
                if (item.responsible) extras.push(`Responsible: ${item.responsible}`);
                if (item.deadline) extras.push(`Deadline: ${item.deadline}`);
                if (item.due) extras.push(`Due: ${item.due}`);
                if (item.priority) extras.push(`Priority: ${item.priority}`);
                if (item.status) extras.push(`Status: ${item.status}`);
                const main = item[k];
                return extras.length ? `${main} (${extras.join(' | ')})` : main;
            }
        }
        const vals = Object.values(item).filter(v => typeof v === 'string' && v.length > 0);
        return vals.length ? vals.join(' — ') : JSON.stringify(item);
    },

    /**
     * Botson greeting section — mascot on left delivering the message, text on right
     */
    _greetingSection(bodyText) {
        return `
    <!-- Greeting with Botson -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td style="padding:36px 40px 0;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <!-- Botson Image -->
            <td width="110" valign="top" style="padding-right:24px;">
                <img src="https://jasonhogan.ca/botson/botson-email.jpg" alt="Botson" width="100" style="display:block;width:100px;height:100px;border-radius:16px;object-fit:cover;">
            </td>
            <!-- Greeting Text -->
            <td valign="middle" style="padding:0;">
                <p style="margin:0;font-size:20px;font-weight:700;color:#1e293b;line-height:28px;font-family:Georgia,'Times New Roman',serif;">Hello there!</p>
                <p style="margin:10px 0 0;font-size:15px;color:#475569;line-height:26px;">${bodyText}</p>
            </td>
        </tr>
        </table>
    </td></tr>
    </table>`;
    },

    /**
     * Generate a polished HTML email for a learning mode analysis
     * Dynamic blocks — only renders sections that exist in the analysis
     */
    generateLearning(opts) {
        const { title, analysis } = opts;
        const date = new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
        const a = analysis || {};

        // Build dynamic content blocks
        let blocks = '';

        // Difficulty badge
        if (a.difficulty_level) {
            const colors = { beginner: '#10b981', intermediate: '#f59e0b', advanced: '#ef4444' };
            const color = colors[a.difficulty_level] || '#2563eb';
            blocks += `
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            <tr><td style="padding:8px 40px 0;text-align:center;">
                <span style="display:inline-block;background:${color};color:#ffffff;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;padding:5px 18px;border-radius:20px;">${this._esc(a.difficulty_level)}</span>
            </td></tr>
            </table>`;
        }

        // Executive Summary
        if (a.executive_summary) {
            blocks += this._emailSection('Executive Summary', '#2563eb', `
                <div style="background:#f8fafc;border-left:3px solid #2563eb;border-radius:0 8px 8px 0;padding:20px 24px;">
                    <p style="margin:0;font-size:15px;color:#334155;line-height:26px;">${this._esc(a.executive_summary)}</p>
                </div>`);
        }

        // Key Concepts
        if (a.key_concepts?.length) {
            const items = a.key_concepts.map(c => `
                <tr>
                    <td style="padding:0 12px 0 0;vertical-align:top;color:#2563eb;font-size:18px;line-height:24px;">&#9670;</td>
                    <td style="padding:0 0 12px 0;color:#334155;font-size:15px;line-height:24px;">
                        <strong style="color:#1e293b;">${this._esc(c.term)}</strong>
                        <br><span style="color:#475569;">${this._esc(c.explanation)}</span>
                        ${c.importance ? `<br><span style="font-size:11px;text-transform:uppercase;color:${c.importance === 'high' ? '#ef4444' : c.importance === 'medium' ? '#f59e0b' : '#10b981'};font-weight:600;">${this._esc(c.importance)} importance</span>` : ''}
                    </td>
                </tr>`).join('');
            blocks += this._emailSection('Key Concepts', '#7c3aed', `
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">${items}</table>`);
        }

        // Core Insights
        if (a.core_insights?.length) {
            const items = a.core_insights.map(p => `
                <tr>
                    <td style="padding:0 12px 0 0;vertical-align:top;color:#10b981;font-size:18px;line-height:24px;">&#10004;</td>
                    <td style="padding:0 0 10px 0;color:#334155;font-size:15px;line-height:24px;">${this._esc(this._toText(p))}</td>
                </tr>`).join('');
            blocks += this._emailSection('Core Insights', '#10b981', `
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">${items}</table>`);
        }

        // Glossary
        if (a.glossary?.length) {
            const rows = a.glossary.map(g => `
                <tr>
                    <td style="padding:8px 12px;border-bottom:1px solid #e2e8f0;font-weight:600;color:#1e293b;font-size:14px;width:30%;vertical-align:top;">${this._esc(g.term)}</td>
                    <td style="padding:8px 12px;border-bottom:1px solid #e2e8f0;color:#475569;font-size:14px;line-height:22px;">${this._esc(g.definition)}</td>
                </tr>`).join('');
            blocks += this._emailSection('Glossary', '#7c3aed', `
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                    <tr>
                        <th style="padding:10px 12px;background:#f1f5f9;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;border-bottom:2px solid #e2e8f0;">Term</th>
                        <th style="padding:10px 12px;background:#f1f5f9;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;border-bottom:2px solid #e2e8f0;">Definition</th>
                    </tr>
                    ${rows}
                </table>`);
        }

        // Important People
        if (a.important_people?.length) {
            const items = a.important_people.map(p => `
                <tr>
                    <td style="padding:0 12px 0 0;vertical-align:top;color:#2563eb;font-size:16px;line-height:24px;">&#128100;</td>
                    <td style="padding:0 0 10px 0;color:#334155;font-size:15px;line-height:24px;">
                        <strong>${this._esc(p.name)}</strong>${p.role ? ` — <em style="color:#64748b;">${this._esc(p.role)}</em>` : ''}
                        ${p.relevance ? `<br><span style="color:#475569;font-size:13px;">${this._esc(p.relevance)}</span>` : ''}
                    </td>
                </tr>`).join('');
            blocks += this._emailSection('Important People', '#2563eb', `
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">${items}</table>`);
        }

        // Statistics
        if (a.statistics?.length) {
            const items = a.statistics.map(s => `
                <tr><td style="padding:0 0 12px 0;">
                    <div style="background:#fffbeb;border-left:3px solid #f59e0b;border-radius:0 8px 8px 0;padding:14px 20px;">
                        <p style="margin:0;font-size:16px;font-weight:700;color:#92400e;">${this._esc(s.stat)}</p>
                        <p style="margin:4px 0 0;font-size:13px;color:#78716c;">${this._esc(s.context)}${s.source ? ` (${this._esc(s.source)})` : ''}</p>
                    </div>
                </td></tr>`).join('');
            blocks += this._emailSection('Key Statistics', '#f59e0b', `
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">${items}</table>`);
        }

        // Timeline
        if (a.dates_timeline?.length) {
            const items = a.dates_timeline.map(d => `
                <tr>
                    <td style="padding:0 16px 10px 0;vertical-align:top;white-space:nowrap;">
                        <span style="display:inline-block;background:#e0e7ff;color:#3730a3;font-size:12px;font-weight:700;padding:4px 10px;border-radius:12px;">${this._esc(d.date)}</span>
                    </td>
                    <td style="padding:0 0 10px 0;color:#334155;font-size:14px;line-height:22px;">
                        <strong>${this._esc(d.event)}</strong>
                        ${d.significance ? `<br><span style="color:#64748b;font-size:13px;">${this._esc(d.significance)}</span>` : ''}
                    </td>
                </tr>`).join('');
            blocks += this._emailSection('Timeline & Dates', '#4f46e5', `
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">${items}</table>`);
        }

        // Products & Tools
        if (a.products_tools?.length) {
            const items = a.products_tools.map(p => `
                <tr>
                    <td style="padding:0 12px 0 0;vertical-align:top;color:#7c3aed;font-size:16px;line-height:24px;">&#9881;</td>
                    <td style="padding:0 0 10px 0;color:#334155;font-size:15px;line-height:24px;">
                        <strong>${this._esc(p.name)}</strong>
                        ${p.description ? `<br><span style="color:#475569;font-size:13px;">${this._esc(p.description)}</span>` : ''}
                        ${p.url ? `<br><a href="${this._esc(p.url)}" style="color:#2563eb;font-size:13px;">${this._esc(p.url)}</a>` : ''}
                    </td>
                </tr>`).join('');
            blocks += this._emailSection('Products & Tools', '#7c3aed', `
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">${items}</table>`);
        }

        // Resources & URLs
        if (a.resources_urls?.length) {
            const items = a.resources_urls.map(r => `
                <tr>
                    <td style="padding:0 12px 0 0;vertical-align:top;color:#2563eb;font-size:16px;line-height:24px;">&#128279;</td>
                    <td style="padding:0 0 10px 0;color:#334155;font-size:15px;line-height:24px;">
                        ${r.url ? `<a href="${this._esc(r.url)}" style="color:#2563eb;font-weight:600;text-decoration:underline;">${this._esc(r.name)}</a>` : `<strong>${this._esc(r.name)}</strong>`}
                        ${r.description ? `<br><span style="color:#475569;font-size:13px;">${this._esc(r.description)}</span>` : ''}
                    </td>
                </tr>`).join('');
            blocks += this._emailSection('Resources & URLs', '#2563eb', `
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">${items}</table>`);
        }

        // Action Items
        if (a.action_items?.length) {
            const items = a.action_items.map(item => `
                <tr>
                    <td style="padding:0 10px 0 0;vertical-align:top;color:#10b981;font-size:16px;line-height:24px;">&#9744;</td>
                    <td style="padding:0 0 10px 0;color:#334155;font-size:15px;line-height:24px;">
                        ${this._esc(item.task)}
                        ${item.priority ? ` <span style="font-size:11px;font-weight:600;text-transform:uppercase;color:${item.priority === 'high' ? '#ef4444' : item.priority === 'medium' ? '#f59e0b' : '#10b981'};">[${this._esc(item.priority)}]</span>` : ''}
                    </td>
                </tr>`).join('');
            blocks += this._emailSection('Action Items', '#10b981', `
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">${items}</table>`);
        }

        // Roadmap
        if (a.roadmap?.length) {
            const items = a.roadmap.map((r, i) => `
                <tr>
                    <td style="padding:0 14px 12px 0;vertical-align:top;">
                        <span style="display:inline-block;background:#2563eb;color:#fff;font-size:11px;font-weight:700;width:24px;height:24px;line-height:24px;text-align:center;border-radius:50%;">${i + 1}</span>
                    </td>
                    <td style="padding:0 0 12px 0;color:#334155;font-size:14px;line-height:22px;">
                        <strong>${this._esc(r.phase)}</strong>${r.timeline ? ` <span style="color:#64748b;font-size:12px;">(${this._esc(r.timeline)})</span>` : ''}
                        <br><span style="color:#475569;">${this._esc(r.description)}</span>
                    </td>
                </tr>`).join('');
            blocks += this._emailSection('Roadmap', '#2563eb', `
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">${items}</table>`);
        }

        // Further Learning
        if (a.further_learning?.length) {
            const items = a.further_learning.map(f => `
                <tr>
                    <td style="padding:0 12px 0 0;vertical-align:top;color:#7c3aed;font-size:18px;line-height:24px;">&#127891;</td>
                    <td style="padding:0 0 10px 0;color:#334155;font-size:15px;line-height:24px;">
                        <strong>${this._esc(f.topic)}</strong>
                        <br><span style="color:#475569;font-size:13px;">${this._esc(f.why)}</span>
                        ${f.resource ? `<br><span style="color:#2563eb;font-size:13px;">${this._esc(f.resource)}</span>` : ''}
                    </td>
                </tr>`).join('');
            blocks += this._emailSection('Further Learning', '#7c3aed', `
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">${items}</table>`);
        }

        // Learning Objectives Addressed
        if (a.learning_objectives_addressed) {
            blocks += this._emailSection('Learning Objectives', '#10b981', `
                <div style="background:#f0fdf4;border-left:3px solid #10b981;border-radius:0 8px 8px 0;padding:20px 24px;">
                    <p style="margin:0;font-size:15px;color:#334155;line-height:26px;">${this._esc(a.learning_objectives_addressed)}</p>
                </div>`);
        }

        return `<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#d0d8e2;font-family:'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#d0d8e2;">
<tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;box-shadow:0 4px 24px rgba(0,0,0,0.12),0 1px 4px rgba(0,0,0,0.06);">

<!-- HEADER -->
<tr><td style="background:linear-gradient(135deg,#1e3a5f 0%,#2563eb 50%,#7c3aed 100%);border-radius:16px 16px 0 0;padding:36px 40px 32px;text-align:center;">
    <img src="${this.logoUrl}" alt="Jason Hogan" width="200" style="display:block;margin:0 auto 20px;max-width:200px;height:auto;">
    <p style="margin:0;font-size:13px;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,0.7);font-weight:600;">Learning Analysis Report</p>
    <h1 style="margin:10px 0 0;font-size:24px;font-weight:700;color:#ffffff;line-height:1.3;">${this._esc(title)}</h1>
    <p style="margin:12px 0 0;font-size:13px;color:rgba(255,255,255,0.6);">${date}</p>
</td></tr>

<!-- BODY -->
<tr><td style="background:#ffffff;padding:0;">

    ${this._greetingSection(`Here is your learning analysis report for <strong style="color:#1e293b;">${this._esc(title)}</strong>. The full report is attached as a PDF. Below you'll find the AI-generated insights and analysis.`)}

    <!-- Gradient divider -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td style="padding:28px 40px 0;">
        <div style="height:3px;background:linear-gradient(90deg,#2563eb,#7c3aed);border-radius:2px;"></div>
    </td></tr>
    </table>

    ${blocks}

    <!-- CTA -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td style="padding:32px 40px;">
        <p style="margin:0;font-size:14px;color:#64748b;line-height:22px;text-align:center;font-style:italic;">The complete learning analysis is included in the attached PDF report.</p>
    </td></tr>
    </table>

</td></tr>

${this._contactSection()}

${this._consultationBanner()}

<!-- FOOTER -->
<tr><td style="background:#0f172a;border-radius:0 0 16px 16px;padding:28px 40px;text-align:center;">
    <p style="margin:0;font-size:13px;color:rgba(255,255,255,0.5);line-height:20px;">Sent via <strong style="color:rgba(255,255,255,0.7);">Botson Transcribe</strong> by Jason Hogan</p>
    <p style="margin:8px 0 0;font-size:12px;color:rgba(255,255,255,0.3);">${date}</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>`;
    },

    /**
     * Helper: render an email section block with colored accent bar + title
     */
    _emailSection(title, accentColor, content) {
        return `
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td style="padding:28px 40px 0;">
        <h2 style="margin:0 0 16px;font-size:18px;font-weight:700;color:#1e293b;letter-spacing:-0.3px;">
            <span style="display:inline-block;width:4px;height:18px;background:${accentColor};border-radius:2px;margin-right:10px;vertical-align:middle;"></span>
            ${title}
        </h2>
        ${content}
    </td></tr>
    </table>`;
    },

    _esc(str) {
        if (!str) return '';
        const text = (typeof str === 'object') ? this._toText(str) : String(str);
        return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
};
