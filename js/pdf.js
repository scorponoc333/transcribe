/**
 * PDF Generator Module
 * Creates a corporate, professionally formatted PDF report
 * with cover page, TOC, AI analysis sections, and full transcript
 */

const PDFGenerator = {
    /**
     * Normalise an item (string or object) to a displayable string.
     * AI models sometimes return array items as objects.
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

    // Corporate color palette (blue branding)
    colors: {
        primary: [37, 99, 235],          // #2563eb
        primaryDark: [29, 78, 216],      // #1d4ed8
        navy: [15, 23, 42],             // #0f172a
        navyMid: [30, 41, 59],          // #1e293b
        text: [51, 65, 85],             // #334155
        textLight: [100, 116, 139],     // #64748b
        white: [255, 255, 255],
        offWhite: [248, 250, 252],      // #f8fafc
        cardBg: [241, 245, 249],        // #f1f5f9
        divider: [226, 232, 240],       // #e2e8f0
        green: [16, 185, 129],          // #10b981
        greenDark: [5, 150, 105],       // #059669
        amber: [217, 119, 6],           // #d97706
        purple: [124, 58, 237],         // #7c3aed
    },

    // A4 dimensions
    pageW: 210,
    pageH: 297,
    marginL: 25,
    marginR: 25,
    headerH: 22,

    get contentW() { return this.pageW - this.marginL - this.marginR; },

    /**
     * Get the current logo path (custom if uploaded, default otherwise)
     */
    getLogoPath() {
        const custom = localStorage.getItem('customLogoUrl');
        return custom || 'img/logo.png';
    },

    /**
     * Generate the complete PDF document
     */
    async generate(transcript, analysis, filename = 'transcript', coverImagePath) {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

        // Load images — use dynamic cover page path
        const coverPath = coverImagePath || App?.selectedCoverPage || 'img/covers/default-cover.png';
        const [coverImg, logoImg] = await Promise.all([
            this.loadImage(coverPath),
            this.loadImage(this.getLogoPath())
        ]);

        const sections = {};

        // --- PAGE 1: COVER ---
        this.addCoverPage(doc, coverImg, logoImg);

        // --- PAGE 2: TOC (placeholder) ---
        doc.addPage();
        const tocPageNum = doc.getCurrentPageInfo().pageNumber;

        // --- CONTENT PAGES ---
        const hasAnalysis = analysis && (analysis.summary || analysis.keyPoints?.length || analysis.actionItems?.length || analysis.suggestions?.length);

        if (hasAnalysis) {
            doc.addPage();
            sections.summary = doc.getCurrentPageInfo().pageNumber;
            this.currentY = this.headerH + 10;
            this.addSummarySection(doc, analysis);

            // Key Points on new page
            doc.addPage();
            sections.keyPoints = doc.getCurrentPageInfo().pageNumber;
            this.currentY = this.headerH + 10;
            this.addKeyPointsSection(doc, analysis);

            // Action Items — check if we need a new page
            if (this.currentY > this.pageH - 90) {
                doc.addPage();
                this.currentY = this.headerH + 10;
            }
            sections.actionItems = doc.getCurrentPageInfo().pageNumber;
            this.addActionItemsSection(doc, analysis);

            // Suggestions — check if we need a new page
            if (this.currentY > this.pageH - 90) {
                doc.addPage();
                this.currentY = this.headerH + 10;
            }
            sections.suggestions = doc.getCurrentPageInfo().pageNumber;
            this.addSuggestionsSection(doc, analysis);
        }

        // Transcript always on new page
        doc.addPage();
        sections.transcript = doc.getCurrentPageInfo().pageNumber;
        this.currentY = this.headerH + 10;
        this.addTranscriptSection(doc, transcript);

        // --- Fill TOC ---
        doc.setPage(tocPageNum);
        this.addTableOfContents(doc, sections, logoImg, hasAnalysis);

        // --- Headers & Footers on all pages except cover ---
        const totalPages = doc.getNumberOfPages();
        for (let i = 2; i <= totalPages; i++) {
            doc.setPage(i);
            this.addPageHeader(doc, logoImg);
            this.addPageFooter(doc, i, totalPages);
        }

        const safeName = filename.replace(/[^a-zA-Z0-9_-]/g, '_');
        doc.save(`${safeName}_transcript.pdf`);
        return true;
    },

    // =========================================================================
    //  COVER PAGE
    // =========================================================================
    addCoverPage(doc, coverImg, logoImg) {
        if (coverImg) {
            const imgRatio = coverImg.width / coverImg.height;
            const pageRatio = this.pageW / this.pageH;
            let drawW, drawH, drawX, drawY;
            if (imgRatio > pageRatio) {
                drawH = this.pageH;
                drawW = drawH * imgRatio;
                drawX = -(drawW - this.pageW) / 2;
                drawY = 0;
            } else {
                drawW = this.pageW;
                drawH = drawW / imgRatio;
                drawX = 0;
                drawY = -(drawH - this.pageH) / 2;
            }
            doc.addImage(coverImg.data, 'PNG', drawX, drawY, drawW, drawH);
        } else {
            // Fallback: dark navy cover with blue accent
            doc.setFillColor(...this.colors.navy);
            doc.rect(0, 0, this.pageW, this.pageH, 'F');

            // Blue accent bar at top
            doc.setFillColor(...this.colors.primary);
            doc.rect(0, 0, this.pageW, 6, 'F');

            // Logo
            if (logoImg) {
                const logoH = 30;
                const logoW = logoH * (logoImg.width / logoImg.height);
                doc.addImage(logoImg.data, 'PNG', this.pageW / 2 - logoW / 2, 80, logoW, logoH);
            }

            // Title
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(32);
            doc.setTextColor(...this.colors.white);
            doc.text('Transcription Report', this.pageW / 2, 150, { align: 'center' });

            // Thin blue line
            doc.setDrawColor(...this.colors.primary);
            doc.setLineWidth(0.8);
            doc.line(this.pageW / 2 - 30, 158, this.pageW / 2 + 30, 158);

            // Date
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(13);
            doc.setTextColor(160, 170, 190);
            doc.text(new Date().toLocaleDateString('en-US', {
                year: 'numeric', month: 'long', day: 'numeric'
            }), this.pageW / 2, 170, { align: 'center' });

            // Bottom blue bar
            doc.setFillColor(...this.colors.primary);
            doc.rect(0, this.pageH - 6, this.pageW, 6, 'F');
        }
    },

    // =========================================================================
    //  TABLE OF CONTENTS
    // =========================================================================
    addTableOfContents(doc, sections, logoImg, hasAnalysis) {
        let y = 40;

        // "CONTENTS" header bar
        doc.setFillColor(...this.colors.primary);
        doc.rect(this.marginL, y, this.contentW, 14, 'F');
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(14);
        doc.setTextColor(...this.colors.white);
        doc.text('CONTENTS', this.marginL + 6, y + 9.5);
        y += 26;

        // Build TOC items
        const tocItems = [];
        let num = 1;
        if (hasAnalysis) {
            if (sections.summary) tocItems.push({ num: num++, title: 'Executive Summary', page: sections.summary });
            if (sections.keyPoints) tocItems.push({ num: num++, title: 'Key Points', page: sections.keyPoints });
            if (sections.actionItems) tocItems.push({ num: num++, title: 'Action Items', page: sections.actionItems });
            if (sections.suggestions) tocItems.push({ num: num++, title: 'Suggestions & Recommendations', page: sections.suggestions });
        }
        if (sections.transcript) tocItems.push({ num: num++, title: 'Full Transcript', page: sections.transcript });

        tocItems.forEach((item, idx) => {
            // Alternating row background
            if (idx % 2 === 0) {
                doc.setFillColor(...this.colors.offWhite);
                doc.rect(this.marginL, y - 5, this.contentW, 14, 'F');
            }

            // Number
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(11);
            doc.setTextColor(...this.colors.primary);
            doc.text(String(item.num).padStart(2, '0'), this.marginL + 6, y + 3);

            // Title
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(11);
            doc.setTextColor(...this.colors.text);
            doc.text(item.title, this.marginL + 20, y + 3);

            // Dot leader
            const titleEnd = this.marginL + 20 + doc.getTextWidth(item.title) + 3;
            const pageNumX = this.pageW - this.marginR - 5;
            doc.setFontSize(8);
            doc.setTextColor(...this.colors.divider);
            let dotX = titleEnd;
            while (dotX < pageNumX - 5) {
                doc.text('.', dotX, y + 3);
                dotX += 2.5;
            }

            // Page number
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(11);
            doc.setTextColor(...this.colors.primary);
            doc.text(String(item.page), this.pageW - this.marginR, y + 3, { align: 'right' });

            // Bottom border
            doc.setDrawColor(...this.colors.divider);
            doc.setLineWidth(0.2);
            doc.line(this.marginL, y + 9, this.pageW - this.marginR, y + 9);

            y += 16;
        });
    },

    // =========================================================================
    //  EXECUTIVE SUMMARY
    // =========================================================================
    addSummarySection(doc, analysis) {
        this.addSectionBanner(doc, '01', 'Executive Summary');

        if (analysis?.summary) {
            // Render summary in a clean card
            const cardX = this.marginL;
            const textX = this.marginL + 8;
            const textW = this.contentW - 16;

            doc.setFont('helvetica', 'normal');
            doc.setFontSize(10.5);
            doc.setTextColor(...this.colors.text);
            const lines = doc.splitTextToSize(this._toText(analysis.summary), textW);
            const lineH = 5.8;

            // Calculate card height
            const cardH = lines.length * lineH + 16;

            // Draw card bg
            this.ensureSpace(doc, cardH + 5);
            doc.setFillColor(...this.colors.offWhite);
            doc.roundedRect(cardX, this.currentY, this.contentW, cardH, 2, 2, 'F');

            // Left blue accent on card
            doc.setFillColor(...this.colors.primary);
            doc.roundedRect(cardX, this.currentY, 3, cardH, 1.5, 1.5, 'F');

            let textY = this.currentY + 10;
            lines.forEach(line => {
                doc.text(line, textX, textY);
                textY += lineH;
            });

            this.currentY += cardH + 10;
        } else {
            this.addNoContentNote(doc, 'No executive summary available.');
        }
    },

    // =========================================================================
    //  KEY POINTS
    // =========================================================================
    addKeyPointsSection(doc, analysis) {
        this.addSectionBanner(doc, '02', 'Key Points');

        if (analysis?.keyPoints?.length) {
            analysis.keyPoints.forEach((point, i) => {
                this.addNumberedCard(doc, this._toText(point), i + 1, this.colors.primary);
            });
        } else {
            this.addNoContentNote(doc, 'No key points available.');
        }
        this.currentY += 8;
    },

    // =========================================================================
    //  ACTION ITEMS
    // =========================================================================
    addActionItemsSection(doc, analysis) {
        this.addSectionBanner(doc, '03', 'Action Items');

        if (analysis?.actionItems?.length) {
            analysis.actionItems.forEach((item, i) => {
                this.addCheckboxCard(doc, this._toText(item), i + 1);
            });
        } else {
            this.addNoContentNote(doc, 'No action items identified.');
        }
        this.currentY += 8;
    },

    // =========================================================================
    //  SUGGESTIONS
    // =========================================================================
    addSuggestionsSection(doc, analysis) {
        this.addSectionBanner(doc, '04', 'Suggestions & Recommendations');

        if (analysis?.suggestions?.length) {
            analysis.suggestions.forEach((suggestion, i) => {
                this.addNumberedCard(doc, this._toText(suggestion), i + 1, this.colors.amber);
            });
        } else {
            this.addNoContentNote(doc, 'No suggestions available.');
        }
    },

    // =========================================================================
    //  FULL TRANSCRIPT
    // =========================================================================
    addTranscriptSection(doc, transcript) {
        this.addSectionBanner(doc, '05', 'Full Transcript');

        if (!transcript) {
            this.addNoContentNote(doc, 'No transcript available.');
            return;
        }

        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
        doc.setTextColor(...this.colors.text);

        const textW = this.contentW;
        const lines = doc.splitTextToSize(transcript, textW);
        const lineH = 5.2;

        lines.forEach(line => {
            this.ensureSpace(doc, lineH + 2);
            doc.text(line, this.marginL, this.currentY);
            this.currentY += lineH;
        });
    },

    // =========================================================================
    //  HELPER: Section banner (blue gradient bar with number and title)
    // =========================================================================
    addSectionBanner(doc, number, title) {
        this.ensureSpace(doc, 30);

        const bannerH = 14;
        const bannerY = this.currentY;

        // Blue banner background
        doc.setFillColor(...this.colors.primary);
        doc.roundedRect(this.marginL, bannerY, this.contentW, bannerH, 2, 2, 'F');

        // Darker accent on left edge
        doc.setFillColor(...this.colors.primaryDark);
        doc.roundedRect(this.marginL, bannerY, 22, bannerH, 2, 0, 'F');
        // Fix corner overlap
        doc.setFillColor(...this.colors.primary);
        doc.rect(this.marginL + 20, bannerY, 4, bannerH, 'F');

        // Number
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(11);
        doc.setTextColor(...this.colors.white);
        doc.text(number, this.marginL + 11, bannerY + 9.5, { align: 'center' });

        // Title
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(13);
        doc.setTextColor(...this.colors.white);
        doc.text(title, this.marginL + 28, bannerY + 9.5);

        this.currentY = bannerY + bannerH + 10;
    },

    // =========================================================================
    //  HELPER: Numbered card item (for key points, suggestions)
    // =========================================================================
    addNumberedCard(doc, text, num, accentColor) {
        const textW = this.contentW - 20;
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
        const lines = doc.splitTextToSize(text, textW);
        const lineH = 5.2;
        const cardH = Math.max(lines.length * lineH + 12, 18);

        this.ensureSpace(doc, cardH + 4);

        // Card background
        doc.setFillColor(...this.colors.cardBg);
        doc.roundedRect(this.marginL, this.currentY, this.contentW, cardH, 1.5, 1.5, 'F');

        // Left accent stripe
        doc.setFillColor(...accentColor);
        doc.roundedRect(this.marginL, this.currentY, 2.5, cardH, 1, 1, 'F');

        // Number circle
        const circleX = this.marginL + 10;
        const circleY = this.currentY + 9;
        doc.setFillColor(...accentColor);
        doc.circle(circleX, circleY, 4, 'F');
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(8);
        doc.setTextColor(...this.colors.white);
        doc.text(String(num), circleX, circleY + 1.2, { align: 'center' });

        // Text content
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
        doc.setTextColor(...this.colors.text);
        let textY = this.currentY + 8;
        lines.forEach(line => {
            doc.text(line, this.marginL + 20, textY);
            textY += lineH;
        });

        this.currentY += cardH + 4;
    },

    // =========================================================================
    //  HELPER: Checkbox card item (for action items)
    // =========================================================================
    addCheckboxCard(doc, text, num) {
        const textW = this.contentW - 20;
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
        const lines = doc.splitTextToSize(text, textW);
        const lineH = 5.2;
        const cardH = Math.max(lines.length * lineH + 12, 18);

        this.ensureSpace(doc, cardH + 4);

        // Card background
        doc.setFillColor(...this.colors.cardBg);
        doc.roundedRect(this.marginL, this.currentY, this.contentW, cardH, 1.5, 1.5, 'F');

        // Green left accent
        doc.setFillColor(...this.colors.green);
        doc.roundedRect(this.marginL, this.currentY, 2.5, cardH, 1, 1, 'F');

        // Checkbox
        const cbX = this.marginL + 8;
        const cbY = this.currentY + 6;
        doc.setDrawColor(...this.colors.green);
        doc.setLineWidth(0.6);
        doc.roundedRect(cbX, cbY, 5, 5, 0.8, 0.8, 'S');

        // Text content
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
        doc.setTextColor(...this.colors.text);
        let textY = this.currentY + 8;
        lines.forEach(line => {
            doc.text(line, this.marginL + 20, textY);
            textY += lineH;
        });

        this.currentY += cardH + 4;
    },

    // =========================================================================
    //  HELPER: No content placeholder
    // =========================================================================
    addNoContentNote(doc, message) {
        doc.setFont('helvetica', 'italic');
        doc.setFontSize(10);
        doc.setTextColor(...this.colors.textLight);
        doc.text(message, this.marginL + 4, this.currentY + 4);
        this.currentY += 16;
    },

    // =========================================================================
    //  HELPER: Ensure vertical space, add page if needed
    // =========================================================================
    ensureSpace(doc, needed) {
        if (this.currentY + needed > this.pageH - 22) {
            doc.addPage();
            this.currentY = this.headerH + 10;
        }
    },

    // =========================================================================
    //  PAGE HEADER
    // =========================================================================
    addPageHeader(doc, logoImg) {
        // White header band
        doc.setFillColor(...this.colors.white);
        doc.rect(0, 0, this.pageW, this.headerH, 'F');

        // Logo
        if (logoImg) {
            const logoH = 10;
            const logoW = logoH * (logoImg.width / logoImg.height);
            doc.addImage(logoImg.data, 'PNG', this.marginL, 6, Math.min(logoW, 35), logoH);
        }

        // Blue accent line under header
        doc.setDrawColor(...this.colors.primary);
        doc.setLineWidth(0.6);
        doc.line(this.marginL, this.headerH, this.pageW - this.marginR, this.headerH);
    },

    // =========================================================================
    //  PAGE FOOTER
    // =========================================================================
    addPageFooter(doc, pageNum, totalPages) {
        const y = this.pageH - 10;

        // Thin line
        doc.setDrawColor(...this.colors.divider);
        doc.setLineWidth(0.2);
        doc.line(this.marginL, y - 4, this.pageW - this.marginR, y - 4);

        doc.setFont('helvetica', 'normal');
        doc.setFontSize(8);
        doc.setTextColor(...this.colors.textLight);

        // Date left
        doc.text(new Date().toLocaleDateString('en-US', {
            year: 'numeric', month: 'long', day: 'numeric'
        }), this.marginL, y);

        // Page center
        doc.setFont('helvetica', 'bold');
        doc.setTextColor(...this.colors.primary);
        doc.text(`${pageNum}`, this.pageW / 2, y, { align: 'center' });
        doc.setFont('helvetica', 'normal');
        doc.setTextColor(...this.colors.textLight);
        const numW = doc.getTextWidth(String(pageNum));
        doc.text(` / ${totalPages}`, this.pageW / 2 + numW / 2, y);

        // Brand right
        doc.setTextColor(...this.colors.primary);
        doc.setFont('helvetica', 'bold');
        doc.text('Transcribe AI', this.pageW - this.marginR, y, { align: 'right' });
    },

    // =========================================================================
    //  LEARNING REPORT PDF
    // =========================================================================
    async generateLearning(analysis, filename = 'learning-report', coverImagePath) {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

        const coverPath = coverImagePath || App?.selectedCoverPage || 'img/covers/default-cover.png';
        const [coverImg, logoImg] = await Promise.all([
            this.loadImage(coverPath),
            this.loadImage(this.getLogoPath())
        ]);

        // --- PAGE 1: COVER ---
        this.addCoverPage(doc, coverImg, logoImg);

        // --- PAGE 2: TOC (placeholder) ---
        doc.addPage();
        const tocPageNum = doc.getCurrentPageInfo().pageNumber;

        // --- CONTENT PAGES ---
        const sections = {};
        let sectionNum = 1;

        // Executive Summary
        if (analysis.executive_summary) {
            doc.addPage();
            sections.summary = doc.getCurrentPageInfo().pageNumber;
            this.currentY = this.headerH + 10;
            this.addSectionBanner(doc, String(sectionNum++).padStart(2, '0'), 'Executive Summary');
            this._addLearningTextBlock(doc, analysis.executive_summary);
        }

        // Key Concepts
        if (analysis.key_concepts?.length) {
            doc.addPage();
            sections.keyConcepts = doc.getCurrentPageInfo().pageNumber;
            this.currentY = this.headerH + 10;
            this.addSectionBanner(doc, String(sectionNum++).padStart(2, '0'), 'Key Concepts');
            analysis.key_concepts.forEach((c, i) => {
                const text = `${c.term}: ${c.explanation}${c.importance ? ' [' + c.importance.toUpperCase() + ']' : ''}`;
                this.addNumberedCard(doc, text, i + 1, this.colors.primary);
            });
        }

        // Core Insights
        if (analysis.core_insights?.length) {
            if (this.currentY > this.pageH - 80) { doc.addPage(); this.currentY = this.headerH + 10; }
            sections.insights = doc.getCurrentPageInfo().pageNumber;
            this.addSectionBanner(doc, String(sectionNum++).padStart(2, '0'), 'Core Insights');
            analysis.core_insights.forEach((insight, i) => {
                this.addNumberedCard(doc, this._toText(insight), i + 1, this.colors.green);
            });
        }

        // Glossary
        if (analysis.glossary?.length) {
            if (this.currentY > this.pageH - 80) { doc.addPage(); this.currentY = this.headerH + 10; }
            sections.glossary = doc.getCurrentPageInfo().pageNumber;
            this.addSectionBanner(doc, String(sectionNum++).padStart(2, '0'), 'Glossary');
            analysis.glossary.forEach((g, i) => {
                const text = `${g.term} — ${g.definition}`;
                this.addNumberedCard(doc, text, i + 1, this.colors.purple);
            });
        }

        // Important People
        if (analysis.important_people?.length) {
            if (this.currentY > this.pageH - 80) { doc.addPage(); this.currentY = this.headerH + 10; }
            sections.people = doc.getCurrentPageInfo().pageNumber;
            this.addSectionBanner(doc, String(sectionNum++).padStart(2, '0'), 'Important People');
            analysis.important_people.forEach((p, i) => {
                const text = `${p.name}${p.role ? ' — ' + p.role : ''}${p.relevance ? ': ' + p.relevance : ''}`;
                this.addNumberedCard(doc, text, i + 1, this.colors.primary);
            });
        }

        // Statistics
        if (analysis.statistics?.length) {
            if (this.currentY > this.pageH - 80) { doc.addPage(); this.currentY = this.headerH + 10; }
            sections.statistics = doc.getCurrentPageInfo().pageNumber;
            this.addSectionBanner(doc, String(sectionNum++).padStart(2, '0'), 'Key Statistics');
            analysis.statistics.forEach((s, i) => {
                const text = `${s.stat} — ${s.context}${s.source ? ' (Source: ' + s.source + ')' : ''}`;
                this.addNumberedCard(doc, text, i + 1, this.colors.amber);
            });
        }

        // Timeline
        if (analysis.dates_timeline?.length) {
            if (this.currentY > this.pageH - 80) { doc.addPage(); this.currentY = this.headerH + 10; }
            sections.timeline = doc.getCurrentPageInfo().pageNumber;
            this.addSectionBanner(doc, String(sectionNum++).padStart(2, '0'), 'Timeline & Dates');
            analysis.dates_timeline.forEach((d, i) => {
                const text = `${d.date}: ${d.event}${d.significance ? ' — ' + d.significance : ''}`;
                this.addNumberedCard(doc, text, i + 1, this.colors.primary);
            });
        }

        // Resources
        if (analysis.resources_urls?.length) {
            if (this.currentY > this.pageH - 80) { doc.addPage(); this.currentY = this.headerH + 10; }
            sections.resources = doc.getCurrentPageInfo().pageNumber;
            this.addSectionBanner(doc, String(sectionNum++).padStart(2, '0'), 'Resources & URLs');
            analysis.resources_urls.forEach((r, i) => {
                const text = `${r.name}${r.description ? ' — ' + r.description : ''}${r.url ? '\n' + r.url : ''}`;
                this.addNumberedCard(doc, text, i + 1, this.colors.green);
            });
        }

        // Action Items
        if (analysis.action_items?.length) {
            if (this.currentY > this.pageH - 80) { doc.addPage(); this.currentY = this.headerH + 10; }
            sections.actions = doc.getCurrentPageInfo().pageNumber;
            this.addSectionBanner(doc, String(sectionNum++).padStart(2, '0'), 'Action Items');
            analysis.action_items.forEach((item, i) => {
                const text = `${item.task}${item.priority ? ' [' + item.priority.toUpperCase() + ']' : ''}`;
                this.addCheckboxCard(doc, text, i + 1);
            });
        }

        // Further Learning
        if (analysis.further_learning?.length) {
            if (this.currentY > this.pageH - 80) { doc.addPage(); this.currentY = this.headerH + 10; }
            sections.furtherLearning = doc.getCurrentPageInfo().pageNumber;
            this.addSectionBanner(doc, String(sectionNum++).padStart(2, '0'), 'Further Learning');
            analysis.further_learning.forEach((f, i) => {
                const text = `${f.topic}: ${f.why}${f.resource ? '\nResource: ' + f.resource : ''}`;
                this.addNumberedCard(doc, text, i + 1, this.colors.purple);
            });
        }

        // --- Fill TOC ---
        doc.setPage(tocPageNum);
        this._addLearningTOC(doc, sections, logoImg);

        // --- Headers & Footers ---
        const totalPages = doc.getNumberOfPages();
        for (let i = 2; i <= totalPages; i++) {
            doc.setPage(i);
            this.addPageHeader(doc, logoImg);
            this.addPageFooter(doc, i, totalPages);
        }

        const safeName = filename.replace(/[^a-zA-Z0-9_-]/g, '_');
        doc.save(`${safeName}_learning_report.pdf`);
        return true;
    },

    _addLearningTextBlock(doc, text) {
        const textW = this.contentW - 16;
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10.5);
        doc.setTextColor(...this.colors.text);
        const lines = doc.splitTextToSize(this._toText(text), textW);
        const lineH = 5.8;
        const cardH = lines.length * lineH + 16;

        this.ensureSpace(doc, cardH + 5);
        doc.setFillColor(...this.colors.offWhite);
        doc.roundedRect(this.marginL, this.currentY, this.contentW, cardH, 2, 2, 'F');
        doc.setFillColor(...this.colors.primary);
        doc.roundedRect(this.marginL, this.currentY, 3, cardH, 1.5, 1.5, 'F');

        let textY = this.currentY + 10;
        lines.forEach(line => {
            doc.text(line, this.marginL + 8, textY);
            textY += lineH;
        });
        this.currentY += cardH + 10;
    },

    _addLearningTOC(doc, sections, logoImg) {
        let y = 40;
        doc.setFillColor(...this.colors.primary);
        doc.rect(this.marginL, y, this.contentW, 14, 'F');
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(14);
        doc.setTextColor(...this.colors.white);
        doc.text('CONTENTS', this.marginL + 6, y + 9.5);
        y += 26;

        const tocMap = {
            summary: 'Executive Summary',
            keyConcepts: 'Key Concepts',
            insights: 'Core Insights',
            glossary: 'Glossary',
            people: 'Important People',
            statistics: 'Key Statistics',
            timeline: 'Timeline & Dates',
            resources: 'Resources & URLs',
            actions: 'Action Items',
            furtherLearning: 'Further Learning',
        };

        let num = 1;
        Object.entries(tocMap).forEach(([key, title], idx) => {
            if (!sections[key]) return;
            if (idx % 2 === 0) {
                doc.setFillColor(...this.colors.offWhite);
                doc.rect(this.marginL, y - 5, this.contentW, 14, 'F');
            }
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(11);
            doc.setTextColor(...this.colors.primary);
            doc.text(String(num++).padStart(2, '0'), this.marginL + 6, y + 3);
            doc.setFont('helvetica', 'normal');
            doc.setTextColor(...this.colors.text);
            doc.text(title, this.marginL + 20, y + 3);

            const titleEnd = this.marginL + 20 + doc.getTextWidth(title) + 3;
            const pageNumX = this.pageW - this.marginR - 5;
            doc.setFontSize(8);
            doc.setTextColor(...this.colors.divider);
            let dotX = titleEnd;
            while (dotX < pageNumX - 5) { doc.text('.', dotX, y + 3); dotX += 2.5; }
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(11);
            doc.setTextColor(...this.colors.primary);
            doc.text(String(sections[key]), this.pageW - this.marginR, y + 3, { align: 'right' });
            doc.setDrawColor(...this.colors.divider);
            doc.setLineWidth(0.2);
            doc.line(this.marginL, y + 9, this.pageW - this.marginR, y + 9);
            y += 16;
        });
    },

    // =========================================================================
    //  IMAGE LOADER
    // =========================================================================
    loadImage(src) {
        return new Promise((resolve) => {
            const img = new Image();
            img.onload = () => {
                const canvas = document.createElement('canvas');
                canvas.width = img.naturalWidth;
                canvas.height = img.naturalHeight;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0);
                resolve({
                    data: canvas.toDataURL('image/png'),
                    width: img.naturalWidth,
                    height: img.naturalHeight
                });
            };
            img.onerror = () => resolve(null);
            img.crossOrigin = 'anonymous';
            img.src = src;
        });
    }
};
