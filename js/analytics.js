/**
 * Analytics Module
 * Fetches aggregated data and renders Chart.js charts + summary cards
 */
const Analytics = {
    charts: {},

    async load() {
        try {
            const data = await API.getAnalytics();
            this.renderSummaryCards(data.totals);
            this.renderCostCards(data.cost_totals, data.daily_costs);
            this.renderCostBreakdown(data.cost_by_operation);

        // Entrance animation: fade + slide + shine sweep for each card as it
        // scrolls into view. Safe to call multiple times — Assembler dedupes.
        setTimeout(() => {
            if (!window.Assembler) return;
            document.querySelectorAll('#analyticsSection .analytics-card').forEach((el, i) => {
                Assembler.observe(el, { kind: 'card', delay: (i % 4) * 120 });
            });
            document.querySelectorAll('#analyticsSection .analytics-chart-card').forEach((el, i) => {
                Assembler.observe(el, { kind: 'chart', delay: (i % 2) * 180 });
            });
            const cb = document.getElementById('costBreakdownCard');
            if (cb) Assembler.observe(cb, { kind: 'card' });
            document.querySelectorAll('#analyticsSection .analytics-section-title').forEach(el => {
                Assembler.observe(el, { kind: 'text' });
            });
        }, 40);

            this.renderMonthlyTranscriptions(data.monthly_transcriptions);
            this.renderMonthlyEmails(data.monthly_emails);
            this.renderAvgTime(data.avg_time_monthly);
            this.renderModelUsage(data.model_usage);
            this.renderMonthlyCosts(data.monthly_costs);
            this.renderCostByModel(data.cost_by_model);
            this.renderDailyCosts(data.daily_costs);
            this.renderRoiCards(data.roi);
            this.renderUserUsage(data.user_usage);
        } catch (err) {
            console.error('Analytics load error:', err);
            App.showToast('Failed to load analytics: ' + err.message, 'error');
        }
    },

    renderSummaryCards(totals) {
        Assembler.countUpText(document.getElementById('statTotalTranscriptions'), (totals.total_transcriptions || 0).toLocaleString());
        Assembler.countUpText(document.getElementById('statTotalEmails'), (totals.total_emails || 0).toLocaleString());

        const avgSec = totals.avg_timer_seconds || 0;
        if (avgSec > 0) {
            const mins = Math.floor(avgSec / 60);
            const secs = Math.round(avgSec % 60);
            document.getElementById('statAvgTime').textContent = mins > 0 ? `${mins}m ${secs}s` : `${secs}s`;
        } else {
            document.getElementById('statAvgTime').textContent = '\u2014';
        }

        Assembler.countUpText(document.getElementById('statTotalWords'), (totals.total_words || 0).toLocaleString());
    },

    renderMonthlyTranscriptions(data) {
        if (!data || !data.length) {
            this._destroyChart('chartMonthlyTranscriptions');
            return;
        }

        const labels = data.map(d => d.month);
        const recordings = data.map(d => d.recordings || 0);
        const meetings = data.map(d => d.meetings || 0);
        const learning = data.map(d => d.learning || 0);

        this._createChart('chartMonthlyTranscriptions', 'bar', {
            data: {
                labels,
                datasets: [
                    {
                        label: 'Recordings',
                        data: recordings,
                        backgroundColor: this._color(this._brand('600', '#2563eb'), 0.7),
                        borderColor: this._brand('600', '#2563eb'),
                        borderWidth: 1,
                        borderRadius: 4,
                    },
                    {
                        label: 'Meetings',
                        data: meetings,
                        backgroundColor: this._color(this._brand('500', '#3b82f6'), 0.7),
                        borderColor: this._brand('500', '#3b82f6'),
                        borderWidth: 1,
                        borderRadius: 4,
                    },
                    {
                        label: 'Learning',
                        data: learning,
                        backgroundColor: this._color(this._brand('400', '#3b82f6'), 0.7),
                        borderColor: this._brand('400', '#3b82f6'),
                        borderWidth: 1,
                        borderRadius: 4,
                    }
                ]
            },
            options: {
                ...this._barDefaults(),
                scales: {
                    x: { ...this._axisStyle(), stacked: true },
                    y: { ...this._axisStyle(), stacked: true, beginAtZero: true }
                }
            }
        });
    },

    renderMonthlyEmails(data) {
        if (!data || !data.length) {
            this._destroyChart('chartMonthlyEmails');
            return;
        }

        const labels = data.map(d => d.month);
        const counts = data.map(d => d.count || 0);

        this._createChart('chartMonthlyEmails', 'bar', {
            data: {
                labels,
                datasets: [{
                    label: 'Emails Sent',
                    data: counts,
                    backgroundColor: this._color(this._brand('400', '#3b82f6'), 0.7),
                    borderColor: this._brand('400', '#3b82f6'),
                    borderWidth: 1,
                    borderRadius: 4,
                }]
            },
            options: {
                ...this._barDefaults(),
                scales: {
                    x: this._axisStyle(),
                    y: { ...this._axisStyle(), beginAtZero: true }
                }
            }
        });
    },

    renderAvgTime(data) {
        const wrap = document.getElementById('avgTimeChartWrap');
        if (!data || !data.length) {
            this._destroyChart('chartAvgTime');
            if (wrap) wrap.innerHTML = '<div class="analytics-single-value"><span class="single-value-number">\u2014</span><span class="single-value-label">No data yet</span></div>';
            return;
        }

        // For a single data point, show an enriched stat card
        if (data.length === 1) {
            this._destroyChart('chartAvgTime');
            const secs = data[0].avg_seconds || 0;
            const mins = Math.round(secs / 60 * 10) / 10;
            const formattedTime = secs >= 60
                ? `${Math.floor(secs / 60)}m ${Math.round(secs % 60)}s`
                : `${Math.round(secs)}s`;
            if (wrap) {
                wrap.className = 'chart-container';
                wrap.innerHTML = `
                    <div class="analytics-single-value">
                        <div style="width:56px;height:56px;border-radius:16px;background:rgba(var(--brand-300-rgb),0.14);border:1px solid rgba(var(--brand-300-rgb),0.25);display:grid;place-items:center;margin-bottom:12px;color:var(--brand-400)">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <span class="single-value-number">${mins}</span>
                        <span class="single-value-label">minutes average</span>
                        <span class="single-value-sub" style="margin-top:6px;font-size:13px">${formattedTime} per transcription &middot; ${data[0].month}</span>
                    </div>`;
            }
            return;
        }

        // Multi-month data — render proper line chart
        if (wrap) {
            wrap.className = 'chart-container chart-bar';
            wrap.innerHTML = '<canvas id="chartAvgTime"></canvas>';
        }

        const labels = data.map(d => d.month);
        const times = data.map(d => Math.round((d.avg_seconds || 0) / 60 * 10) / 10);

        this._createChart('chartAvgTime', 'line', {
            data: {
                labels,
                datasets: [{
                    label: 'Avg Time (minutes)',
                    data: times,
                    borderColor: this._brand('300', '#93c5fd'),
                    backgroundColor: this._color(this._brand('300', '#93c5fd'), 0.15),
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: this._brand('300', '#93c5fd'),
                    pointRadius: 5,
                    pointHoverRadius: 7,
                }]
            },
            options: {
                ...this._barDefaults(),
                scales: {
                    x: this._axisStyle(),
                    y: { ...this._axisStyle(), beginAtZero: true }
                }
            }
        });
    },

    renderModelUsage(data) {
        const legendEl = document.getElementById('modelUsageLegend');

        if (!data || !data.length) {
            this._destroyChart('chartModelUsage');
            if (legendEl) legendEl.innerHTML = '';
            return;
        }

        // Build descriptive labels
        const modelDescriptions = {
            'turbo': 'Whisper Turbo — Fastest model',
            'large-v3': 'Whisper Large V3 — Most accurate',
            'large-v2': 'Whisper Large V2 — High accuracy',
            'medium': 'Whisper Medium — Balanced',
            'small': 'Whisper Small — Lightweight',
            'base': 'Whisper Base — Minimal',
            'tiny': 'Whisper Tiny — Fastest/smallest',
            'n/a': 'Learning Analysis — No audio model used',
            'null': 'Learning Analysis — No audio model used',
            '': 'Learning Analysis — No audio model used',
        };

        const friendlyNames = {
            'turbo': 'Whisper Turbo',
            'large-v3': 'Large V3',
            'large-v2': 'Large V2',
            'medium': 'Medium',
            'small': 'Small',
            'base': 'Base',
            'tiny': 'Tiny',
            'n/a': 'Learning (no audio)',
            'null': 'Learning (no audio)',
            '': 'Learning (no audio)',
        };

        const labels = data.map(d => {
            const raw = (d.model || '').toLowerCase().trim() || 'n/a';
            return friendlyNames[raw] || d.model || 'Unknown';
        });
        const counts = data.map(d => d.count || 0);
        const colors = this._brandPalette(6);
        const total = counts.reduce((a, b) => a + b, 0);

        this._createChart('chartModelUsage', 'doughnut', {
            data: {
                labels,
                datasets: [{
                    data: counts,
                    backgroundColor: labels.map((_, i) => colors[i % colors.length]),
                    borderColor: 'rgba(255,255,255,0.15)',
                    borderWidth: 3,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '55%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const pct = total > 0 ? ((ctx.raw / total) * 100).toFixed(1) : 0;
                                return `${ctx.label}: ${ctx.raw} (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });

        // Render custom legend with descriptions
        if (legendEl) {
            legendEl.innerHTML = data.map((d, i) => {
                const raw = (d.model || '').toLowerCase().trim() || 'n/a';
                const desc = modelDescriptions[raw] || d.model || 'Unknown model';
                const color = colors[i % colors.length];
                const pct = total > 0 ? ((d.count / total) * 100).toFixed(0) : 0;
                return `<div class="chart-legend-item">
                    <span class="chart-legend-dot" style="background:${color}"></span>
                    <span><span class="chart-legend-count">${d.count}</span> &mdash; ${App.escapeHtml(desc)} <span class="chart-legend-desc">(${pct}%)</span></span>
                </div>`;
            }).join('');
        }
    },

    // ---- Cost Rendering ----

    renderCostCards(costTotals, dailyCosts) {
        if (!costTotals) return;

        const totalCost = costTotals.total_cost_usd || 0;
        const totalOps = costTotals.total_operations || 0;
        const avgCost = costTotals.avg_cost_per_op || 0;
        const totalTokens = costTotals.total_tokens || 0;

        Assembler.countUpText(document.getElementById('statTotalCost'), totalCost > 0 ? `$${totalCost.toFixed(4)}` : '$0.00');
        Assembler.countUpText(document.getElementById('statTotalOps'), totalOps.toLocaleString());
        Assembler.countUpText(document.getElementById('statAvgCostOp'), avgCost > 0 ? `$${avgCost.toFixed(5)}` : '$0.00');
        Assembler.countUpText(document.getElementById('statTotalTokens'), totalTokens.toLocaleString());

        // New cards
        const avgCost1kEl = document.getElementById('statAvgCost1k');
        const estMonthlyEl = document.getElementById('statEstMonthly');

        if (avgCost1kEl) {
            const avg1k = totalTokens > 0 ? (totalCost / (totalTokens / 1000)) : 0;
            Assembler.countUpText(avgCost1kEl, '$' + avg1k.toFixed(4));
        }
        if (estMonthlyEl) {
            const monthCost = (dailyCosts || []).reduce((sum, d) => sum + (d.total_cost || 0), 0);
            Assembler.countUpText(estMonthlyEl, '$' + monthCost.toFixed(4));
        }
    },

    renderCostBreakdown(costByOp) {
        const container = document.getElementById('costBreakdownTable');
        if (!container) return;

        if (!costByOp || !costByOp.length) {
            container.innerHTML = '<p style="color:var(--fg-muted);font-size:14px;text-align:center;padding:16px;">No AI cost data yet. Run a transcription with AI analysis to start tracking costs.</p>';
            return;
        }

        const opLabels = {
            analyze: 'AI Analysis',
            translate: 'Translation',
            learning_analysis: 'Learning Analysis',
            pop_quiz: 'Pop Quiz',
            title_gen: 'Title Generation',
            summarize: 'Summary',
        };
        // Fallback: prettify any unknown snake_case operation into Title Case
        const prettify = (s) => String(s || '')
            .replace(/_/g, ' ')
            .replace(/\b\w/g, c => c.toUpperCase());
        const opColors = { analyze: this._brand('600', '#2563eb'), translate: this._brand('500', '#3b82f6') };

        let html = `<table style="width:100%;border-collapse:collapse;font-size:14px;">
            <thead>
                <tr style="border-bottom:2px solid var(--border-default);">
                    <th style="text-align:left;padding:10px 12px;color:var(--fg-muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;">Operation</th>
                    <th style="text-align:right;padding:10px 12px;color:var(--fg-muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;">Count</th>
                    <th style="text-align:right;padding:10px 12px;color:var(--fg-muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;">Total Cost</th>
                    <th style="text-align:right;padding:10px 12px;color:var(--fg-muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;">Avg Cost</th>
                    <th style="text-align:right;padding:10px 12px;color:var(--fg-muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;">Tokens</th>
                </tr>
            </thead>
            <tbody>`;

        for (const row of costByOp) {
            const label = opLabels[row.operation] || prettify(row.operation);
            const color = opColors[row.operation] || '#64748b';
            html += `<tr style="border-bottom:1px solid var(--border-light);">
                <td style="padding:10px 12px;">
                    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${color};margin-right:8px;vertical-align:middle;"></span>
                    <span style="color:var(--fg-heading);font-weight:500;">${label}</span>
                </td>
                <td style="text-align:right;padding:10px 12px;color:var(--fg-body);">${row.count.toLocaleString()}</td>
                <td style="text-align:right;padding:10px 12px;color:var(--fg-heading);font-weight:600;">$${row.total_cost.toFixed(4)}</td>
                <td style="text-align:right;padding:10px 12px;color:var(--fg-muted);">$${row.avg_cost.toFixed(5)}</td>
                <td style="text-align:right;padding:10px 12px;color:var(--fg-muted);">${row.total_tokens.toLocaleString()}</td>
            </tr>`;
        }

        html += '</tbody></table>';
        container.innerHTML = html;
    },

    renderMonthlyCosts(data) {
        if (!data || !data.length) {
            this._destroyChart('chartMonthlyCosts');
            return;
        }

        const labels = data.map(d => d.month);
        const costs = data.map(d => d.total_cost || 0);

        this._createChart('chartMonthlyCosts', 'bar', {
            data: {
                labels,
                datasets: [{
                    label: 'Cost (USD)',
                    data: costs,
                    backgroundColor: this._color(this._brand('400', '#60a5fa'), 0.7),
                    borderColor: this._brand('400', '#60a5fa'),
                    borderWidth: 1,
                    borderRadius: 6,
                    barPercentage: 0.6,
                }]
            },
            options: {
                ...this._barDefaults(),
                scales: {
                    x: this._axisStyle(),
                    y: {
                        ...this._axisStyle(),
                        beginAtZero: true,
                        ticks: {
                            ...this._axisStyle().ticks,
                            callback: (val) => `$${val.toFixed(4)}`
                        }
                    }
                },
                plugins: {
                    ...this._barDefaults().plugins,
                    tooltip: {
                        callbacks: {
                            label: (ctx) => `Cost: $${ctx.raw.toFixed(5)}`
                        }
                    }
                }
            }
        });
    },

    renderCostByModel(data) {
        const legendEl = document.getElementById('costByModelLegend');

        if (!data || !data.length) {
            this._destroyChart('chartCostByModel');
            if (legendEl) legendEl.innerHTML = '';
            return;
        }

        const labels = data.map(d => {
            const parts = (d.model || 'unknown').split('/');
            return parts[parts.length - 1];
        });
        const costs = data.map(d => d.total_cost || 0);
        const colors = ['#f87171', '#fbbf24', '#22d3ee', '#60a5fa', '#34d399', '#a78bfa'];
        const totalCost = costs.reduce((a, b) => a + b, 0);

        this._createChart('chartCostByModel', 'doughnut', {
            data: {
                labels,
                datasets: [{
                    data: costs,
                    backgroundColor: labels.map((_, i) => colors[i % colors.length]),
                    borderColor: 'rgba(255,255,255,0.15)',
                    borderWidth: 3,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '55%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const pct = totalCost > 0 ? ((ctx.raw / totalCost) * 100).toFixed(1) : 0;
                                return `${ctx.label}: $${ctx.raw.toFixed(5)} (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });

        // Custom legend with cost amounts
        if (legendEl) {
            legendEl.innerHTML = data.map((d, i) => {
                const parts = (d.model || 'unknown').split('/');
                const name = parts[parts.length - 1];
                const color = colors[i % colors.length];
                const pct = totalCost > 0 ? ((d.total_cost / totalCost) * 100).toFixed(0) : 0;
                return `<div class="chart-legend-item">
                    <span class="chart-legend-dot" style="background:${color}"></span>
                    <span><span class="chart-legend-count">$${(d.total_cost || 0).toFixed(4)}</span> &mdash; ${App.escapeHtml(name)} <span class="chart-legend-desc">(${pct}%)</span></span>
                </div>`;
            }).join('');
        }
    },

    // ---- Daily Costs ----

    renderDailyCosts(data) {
        if (!data || !data.length) {
            this._destroyChart('chartDailyCosts');
            return;
        }

        const labels = data.map(d => {
            const date = new Date(d.day);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });
        const costs = data.map(d => d.total_cost || 0);

        this._createChart('chartDailyCosts', 'bar', {
            data: {
                labels,
                datasets: [{
                    label: 'Daily Cost (USD)',
                    data: costs,
                    backgroundColor: this._color(this._brand('300', '#93c5fd'), 0.7),
                    borderColor: this._brand('300', '#93c5fd'),
                    borderWidth: 1,
                    borderRadius: 4,
                }]
            },
            options: {
                ...this._barDefaults(),
                scales: {
                    x: { ...this._axisStyle(), ticks: { ...this._axisStyle().ticks, maxRotation: 45 } },
                    y: {
                        ...this._axisStyle(),
                        beginAtZero: true,
                        ticks: {
                            ...this._axisStyle().ticks,
                            callback: (val) => `$${val.toFixed(4)}`
                        }
                    }
                },
                plugins: {
                    ...this._barDefaults().plugins,
                    tooltip: {
                        callbacks: {
                            label: (ctx) => `Cost: $${ctx.raw.toFixed(5)}`
                        }
                    }
                }
            }
        });
    },

    // ---- ROI Cards ----

    renderRoiCards(roi) {
        if (!roi) return;

        const timeSavedEl = document.getElementById('statTimeSaved');
        const avgSavedEl = document.getElementById('statAvgTimeSaved');
        const estimatedValueEl = document.getElementById('statEstimatedValue');
        const activeUsersEl = document.getElementById('statActiveUsers');
        const tooltipEl = document.getElementById('roiTooltipTrigger');

        if (timeSavedEl) Assembler.countUpText(timeSavedEl, `${roi.total_time_saved_hours || 0}h`);
        if (avgSavedEl) Assembler.countUpText(avgSavedEl, `${roi.avg_saved_per_transcription || 0}m`);
        if (estimatedValueEl) Assembler.countUpText(estimatedValueEl, `$${(roi.estimated_value || 0).toLocaleString()}`);
        if (activeUsersEl) Assembler.countUpText(activeUsersEl, String(roi.active_users || 0));
        if (tooltipEl) tooltipEl.title = roi.methodology || '';
    },

    // ---- User Usage ----

    renderUserUsage(data) {
        if (!data || !data.length) {
            this._destroyChart('chartUserUsage');
            const tableEl = document.getElementById('activeUsersTableBody');
            if (tableEl) tableEl.innerHTML = '<tr><td colspan="3" class="text-center t-muted" style="padding:16px;">No user data available</td></tr>';
            return;
        }

        // Horizontal bar chart
        const labels = data.map(d => d.user_name || 'Unknown');
        const counts = data.map(d => d.transcription_count || 0);
        const colors = this._brandPalette(8);

        this._createChart('chartUserUsage', 'bar', {
            data: {
                labels,
                datasets: [{
                    label: 'Transcriptions',
                    data: counts,
                    backgroundColor: labels.map((_, i) => this._color(colors[i % colors.length], 0.7)),
                    borderColor: labels.map((_, i) => colors[i % colors.length]),
                    borderWidth: 1,
                    borderRadius: 4,
                }]
            },
            options: {
                ...this._barDefaults(),
                indexAxis: 'y',
                scales: {
                    x: { ...this._axisStyle(), beginAtZero: true },
                    y: this._axisStyle()
                }
            }
        });

        // Active users table
        const tableEl = document.getElementById('activeUsersTableBody');
        if (tableEl) {
            tableEl.innerHTML = data.filter(d => d.transcription_count > 0).map(d => {
                const lastActivity = d.last_activity
                    ? new Date(d.last_activity).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                    : 'Never';
                return `<tr>
                    <td style="font-weight:500;color:var(--fg-heading)">${App.escapeHtml(d.user_name)}</td>
                    <td>${d.transcription_count}</td>
                    <td class="t-muted">${lastActivity}</td>
                </tr>`;
            }).join('');
        }
    },

    // ---- Helpers ----

    _createChart(canvasId, type, config) {
        this._destroyChart(canvasId);
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        this.charts[canvasId] = new Chart(canvas, { type, ...config });
    },

    _destroyChart(canvasId) {
        if (this.charts[canvasId]) {
            this.charts[canvasId].destroy();
            delete this.charts[canvasId];
        }
    },

    _isDark() {
        return document.documentElement.getAttribute('data-theme') === 'dark';
    },

    _textColor() {
        // Cards always have dark gradient backgrounds now
        return 'rgba(255,255,255,0.75)';
    },

    _gridColor() {
        return 'rgba(255,255,255,0.10)';
    },

    _color(hex, alpha) {
        const r = parseInt(hex.slice(1, 3), 16);
        const g = parseInt(hex.slice(3, 5), 16);
        const b = parseInt(hex.slice(5, 7), 16);
        return `rgba(${r},${g},${b},${alpha})`;
    },

    /** Get computed brand color from CSS variable, with hex fallback */
    _brand(level, fallback) {
        const val = getComputedStyle(document.documentElement).getPropertyValue(`--brand-${level}`).trim();
        return val || fallback;
    },

    /**
     * Default options for bar/line charts — uses maintainAspectRatio: false
     * so charts respect their container's fixed height instead of bloating.
     */
    /** Palette of brand shades (light -> dark) for multi-series charts */
    _brandPalette(n) {
        const levels = ['200', '300', '400', '500', '600', '700', '800'];
        const out = [];
        for (let i = 0; i < n; i++) out.push(this._brand(levels[i % levels.length], '#3b82f6'));
        return out;
    },

    _barDefaults() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: {
                        color: this._textColor(),
                        font: { family: 'Inter, sans-serif', size: 12 }
                    }
                }
            }
        };
    },

    _axisStyle() {
        return {
            ticks: {
                color: this._textColor(),
                font: { family: 'Inter, sans-serif', size: 11 }
            },
            grid: {
                color: this._gridColor()
            }
        };
    }
};
