<?php
// Force fresh page load (no CDN / browser caching)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/**
 * Report Pages — index of all the user's branded reports.
 * Design brief from user: "like the transcription history page, except it will
 * be the All Reports panel, and that will be where the transcription history
 * is. It'll have the search and the tabs, and then beneath it, that's where
 * the table will be with all the reports."
 *
 * NOTE: this is a separate page — it is not report.php and does not replace it.
 *
 * Data is fetched client-side from /api/list.php. Each row links to
 * /api/report.php?id={id} which is the existing branded report page.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_middleware.php';
requireAuth();

// Resolve header logo (custom if present, else default)
$logoPath = '/img/logo.png';
if (file_exists(__DIR__ . '/../img/custom-logo.png')) {
    $logoPath = '/img/custom-logo.png?t=' . filemtime(__DIR__ . '/../img/custom-logo.png');
}

// Tiny escape helper
function rp_e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Pages — Jason AI</title>
    <link rel="icon" type="image/png" href="/img/fav%20icon.png">
    <link rel="stylesheet" href="/css/style.css">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at 20% 0%, rgba(var(--brand-500-rgb), 0.08) 0%, transparent 60%),
                radial-gradient(circle at 80% 100%, rgba(var(--brand-400-rgb), 0.06) 0%, transparent 60%),
                var(--bg-page, #f5f7fb);
        }

        /* ===== Topbar (mirrors /api/report.php) ===== */
        .topbar {
            position: relative;
            background: linear-gradient(165deg, var(--brand-grad-light) 0%, var(--brand-grad-mid) 45%, var(--brand-grad-dark) 80%, var(--brand-950) 100%);
            color: #fff;
            padding: 12px max(24px, calc((100% - 1200px) / 2 + 24px));
            box-shadow: 0 2px 12px rgba(0,0,0,0.18);
        }
        .topbar-inner {
            max-width: 1200px; margin: 0 auto; width: 100%;
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
        }
        .topbar-brand { display: flex; align-items: center; gap: 10px; }
        .topbar-brand-logo { height: 28px; width: auto; display: block; }
        .topbar-actions { display: flex; align-items: center; gap: 4px; }
        .btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 12px; border-radius: 8px;
            border: 1px solid transparent; background: transparent;
            color: rgba(255,255,255,0.82);
            font-family: inherit; font-size: 13px; font-weight: 500;
            cursor: pointer; text-decoration: none;
            transition: all 0.25s ease;
        }
        .btn:hover { background: rgba(255,255,255,0.10); border-color: rgba(255,255,255,0.18); color: #fff; }
        .btn svg { width: 18px; height: 18px; stroke: currentColor; }
        .app-nav-btn { background: transparent; border: 1px solid transparent; color: rgba(255,255,255,0.82); }
        .app-nav-btn:hover { background: rgba(255,255,255,0.10); border-color: rgba(255,255,255,0.18); color: #fff; }
        .btn-icon-only { padding: 9px 10px; }

        /* More-dropdown in topbar (same pattern as report.php) */
        .tb-more { position: relative; }
        .tb-more.open .tb-chevron { transform: rotate(180deg); }
        .tb-chevron { transition: transform 0.25s ease; opacity: 0.7; }
        .tb-more-menu {
            position: absolute; top: calc(100% + 8px); right: 0;
            min-width: 200px;
            background: linear-gradient(165deg, var(--brand-grad-light) 0%, var(--brand-grad-mid) 45%, var(--brand-grad-dark) 80%, var(--brand-950) 100%);
            border: 1px solid rgba(255,255,255,0.18);
            border-radius: 14px; padding: 6px;
            opacity: 0; max-height: 0; overflow: hidden; visibility: hidden;
            transition: opacity 0.28s ease, max-height 0.5s cubic-bezier(0.22, 1, 0.36, 1), visibility 0s 0.5s;
            z-index: 60;
            box-shadow: 0 16px 48px rgba(0,0,0,0.45);
        }
        .tb-more.open .tb-more-menu { opacity: 1; max-height: 500px; visibility: visible; transition: opacity 0.28s ease, max-height 0.5s cubic-bezier(0.22, 1, 0.36, 1), visibility 0s 0s; }
        .tb-more-item {
            display: flex; align-items: center; gap: 10px;
            width: 100%; padding: 10px 14px;
            border: 1px solid transparent; border-radius: 10px;
            background: transparent; color: rgba(255,255,255,0.82);
            cursor: pointer; font-size: 13px; font-weight: 500;
            text-decoration: none; transition: all 0.15s ease;
        }
        .tb-more-item:hover { background: rgba(255,255,255,0.12); border-color: rgba(255,255,255,0.18); color: #fff; }
        .tb-more-divider { height: 1px; background: rgba(255,255,255,0.12); margin: 6px 4px; }

        /* ===== Page body ===== */
        .rp-page {
            max-width: 1200px; margin: 0 auto;
            padding: 32px max(24px, calc((100% - 1200px) / 2 + 24px));
        }
        .rp-hero {
            background: linear-gradient(165deg, var(--brand-grad-light) 0%, var(--brand-grad-mid) 45%, var(--brand-grad-dark) 80%, var(--brand-950) 100%);
            border-radius: 18px;
            padding: 24px 28px;
            margin-bottom: 24px;
            color: #fff;
            box-shadow: 0 10px 40px rgba(0,0,0,0.25);
            display: flex; align-items: center; justify-content: space-between; gap: 20px;
            flex-wrap: wrap;
        }
        .rp-hero-title {
            display: flex; align-items: center; gap: 12px;
            font-size: 20px; font-weight: 800; letter-spacing: 0.3px;
            color: #fff;
        }
        .rp-hero-title svg { stroke: #fff; opacity: 0.9; width: 22px; height: 22px; }
        .rp-hero-back {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 14px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.22);
            background: rgba(255,255,255,0.10);
            color: #fff; text-decoration: none;
            font-size: 13px; font-weight: 500;
            transition: all 0.2s ease;
        }
        .rp-hero-back:hover { background: rgba(255,255,255,0.18); border-color: rgba(255,255,255,0.35); }

        /* Toolbar — search + tab filters (mirrors the All Reports sidebar styling) */
        .rp-toolbar {
            display: flex; align-items: center; gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }
        .rp-search-wrap { position: relative; flex: 1; min-width: 260px; }
        .rp-search-icon {
            position: absolute; left: 10px; top: 50%;
            transform: translateY(-50%);
            width: 16px; height: 16px;
            stroke: var(--fg-muted, #7b8599);
            pointer-events: none;
        }
        .rp-search {
            width: 100%;
            padding: 11px 14px 11px 34px;
            border: 1px solid rgba(var(--brand-500-rgb), 0.25);
            border-radius: 10px;
            background: var(--fg-card, #fff);
            font-size: 14px;
            color: var(--fg-heading);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            font-family: inherit;
        }
        .rp-search:focus { outline: none; border-color: var(--brand-500); box-shadow: 0 0 0 3px rgba(var(--brand-500-rgb), 0.15); }
        .rp-filters { display: flex; gap: 6px; background: rgba(var(--brand-500-rgb), 0.08); border-radius: 10px; padding: 4px; }
        .rp-filter {
            padding: 8px 14px;
            border: 1px solid transparent;
            border-radius: 8px;
            background: transparent;
            color: var(--fg-muted, #556);
            cursor: pointer;
            font-size: 13px; font-weight: 600;
            font-family: inherit;
            transition: all 0.2s ease;
        }
        .rp-filter:hover { color: var(--fg-heading); background: rgba(255,255,255,0.6); }
        .rp-filter.active {
            background: var(--fg-card, #fff);
            color: var(--brand-700);
            border-color: rgba(var(--brand-500-rgb), 0.25);
            box-shadow: 0 2px 8px rgba(var(--brand-500-rgb), 0.18);
        }

        /* Table styles — match history-table pattern */
        .rp-table-wrap {
            background: var(--fg-card, #fff);
            border: 1px solid rgba(var(--brand-500-rgb), 0.15);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 8px 28px rgba(0,0,0,0.08);
        }
        .rp-table { width: 100%; border-collapse: collapse; }
        .rp-table thead th {
            text-align: left;
            padding: 14px 18px;
            font-size: 11px; font-weight: 800;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--fg-muted, #6b7280);
            background: rgba(var(--brand-500-rgb), 0.05);
            border-bottom: 1px solid rgba(var(--brand-500-rgb), 0.18);
        }
        .rp-table tbody td {
            padding: 14px 18px;
            font-size: 14px;
            color: var(--fg-heading);
            border-bottom: 1px solid rgba(var(--brand-500-rgb), 0.08);
            vertical-align: middle;
        }
        .rp-table tbody tr:last-child td { border-bottom: none; }
        .rp-table tbody tr { transition: background 0.15s ease; }
        .rp-table tbody tr:hover { background: rgba(var(--brand-500-rgb), 0.04); }
        .rp-title-cell { font-weight: 700; color: var(--fg-heading); }
        .rp-type-chip {
            display: inline-block;
            padding: 3px 10px; border-radius: 999px;
            font-size: 11px; font-weight: 700;
            letter-spacing: 0.4px; text-transform: uppercase;
        }
        .rp-type-recording { background: rgba(16,185,129,0.12); color: #047857; }
        .rp-type-meeting   { background: rgba(245,158,11,0.12); color: #b45309; }
        .rp-type-learning  { background: rgba(99,102,241,0.14); color: #4338ca; }
        .rp-date-cell { color: var(--fg-muted, #6b7280); font-size: 13px; }
        .rp-open-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px;
            border-radius: 8px;
            border: 1px solid rgba(var(--brand-500-rgb), 0.3);
            background: linear-gradient(135deg, var(--brand-700), var(--brand-600), var(--brand-500));
            color: #fff;
            font-size: 12px; font-weight: 600;
            text-decoration: none;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .rp-open-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(var(--brand-500-rgb), 0.35); }

        .rp-empty {
            padding: 48px 24px;
            text-align: center;
            color: var(--fg-muted, #6b7280);
        }
        .rp-empty svg { stroke: var(--fg-muted, #9ca3af); opacity: 0.7; margin-bottom: 12px; }

        .rp-loading {
            padding: 48px 24px; text-align: center;
            color: var(--fg-muted, #6b7280);
            font-size: 14px;
        }

        .rp-pagination {
            display: flex; justify-content: center; gap: 6px;
            margin-top: 20px;
        }
        .rp-pagination button {
            padding: 7px 12px;
            border: 1px solid rgba(var(--brand-500-rgb), 0.2);
            border-radius: 8px;
            background: var(--fg-card, #fff);
            color: var(--fg-heading);
            font-size: 13px; font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .rp-pagination button:hover:not(:disabled) { background: rgba(var(--brand-500-rgb), 0.08); }
        .rp-pagination button.active {
            background: var(--brand-600);
            color: #fff;
            border-color: var(--brand-600);
        }
        .rp-pagination button:disabled { opacity: 0.4; cursor: not-allowed; }

        @media (max-width: 640px) {
            .rp-page { padding: 20px 16px; }
            .rp-hero { padding: 18px 20px; }
            .rp-toolbar { flex-direction: column; align-items: stretch; }
            .rp-table thead { display: none; }
            .rp-table tbody td { display: block; padding: 6px 14px; border-bottom: none; }
            .rp-table tbody tr { display: block; padding: 12px 4px; border-bottom: 1px solid rgba(var(--brand-500-rgb), 0.12); }
        }
    </style>
</head>
<body>
    <!-- Topbar (mirrors api/report.php for visual consistency) -->
    <div class="topbar">
        <div class="topbar-inner">
            <div class="topbar-brand">
                <img src="<?= rp_e($logoPath) ?>" alt="Logo" class="topbar-brand-logo"
                     onerror="this.style.display='none';">
            </div>
            <div class="topbar-actions">
                <a href="/index.html" class="btn app-nav-btn" title="Transcribe">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                    <span>Transcribe</span>
                </a>
                <div class="tb-more" id="tbMoreDropdown">
                    <button type="button" class="btn app-nav-btn" onclick="tbToggleMore(event)">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                        <span>More</span>
                        <svg class="tb-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="tb-more-menu">
                        <a href="/index.html#history" class="tb-more-item">History</a>
                        <a href="/api/report-pages.php" class="tb-more-item">Reports</a>
                        <a href="/index.html#analytics" class="tb-more-item">Analytics</a>
                        <a href="/index.html#contacts" class="tb-more-item">Contacts</a>
                        <div class="tb-more-divider"></div>
                        <a href="/index.html#feedback" class="tb-more-item">Feedback</a>
                    </div>
                </div>
                <a href="/index.html#settings" class="btn app-nav-btn btn-icon-only" title="Settings">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                </a>
                <button type="button" class="btn app-nav-btn" onclick="tbSignOut()" title="Sign Out">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    <span>Sign Out</span>
                </button>
            </div>
        </div>
    </div>

    <div class="rp-page">
        <!-- Hero banner -->
        <div class="rp-hero">
            <div class="rp-hero-title">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                Report Pages
            </div>
            <a href="/index.html" class="rp-hero-back">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                Back to Transcribe
            </a>
        </div>

        <!-- Search + tab filters -->
        <div class="rp-toolbar">
            <div class="rp-search-wrap">
                <svg class="rp-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="text" id="rpSearch" class="rp-search" placeholder="Search reports..." autocomplete="off">
            </div>
            <div class="rp-filters" role="tablist">
                <button class="rp-filter active" data-type="">All</button>
                <button class="rp-filter" data-type="recording">Audio</button>
                <button class="rp-filter" data-type="meeting">Meeting</button>
                <button class="rp-filter" data-type="learning">Learning</button>
            </div>
        </div>

        <!-- Reports table -->
        <div class="rp-table-wrap">
            <table class="rp-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Words</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody id="rpTbody">
                    <tr><td colspan="5"><div class="rp-loading">Loading reports…</div></td></tr>
                </tbody>
            </table>
        </div>

        <div class="rp-pagination" id="rpPagination"></div>
    </div>

<script>
(function() {
    const state = { page: 1, perPage: 20, search: '', type: '', total: 0 };
    const tbody = document.getElementById('rpTbody');
    const searchInput = document.getElementById('rpSearch');
    const pagination = document.getElementById('rpPagination');

    function fmtDate(iso) {
        if (!iso) return '';
        const d = new Date(iso);
        if (isNaN(d)) return iso;
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    async function fetchReports() {
        tbody.innerHTML = '<tr><td colspan="5"><div class="rp-loading">Loading reports…</div></td></tr>';
        const params = new URLSearchParams({
            page: String(state.page),
            per_page: String(state.perPage),
            _t: Date.now()
        });
        if (state.search) params.set('search', state.search);
        if (state.type)   params.set('mode', state.type);
        try {
            const r = await fetch('/api/list.php?' + params.toString(), { credentials: 'same-origin', cache: 'no-store' });
            const data = await r.json();
            const items = Array.isArray(data.data) ? data.data : (Array.isArray(data.items) ? data.items : (data.transcriptions || []));
            state.total = data.total || items.length;
            render(items);
            renderPagination();
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="5"><div class="rp-empty">Could not load reports.</div></td></tr>';
            pagination.innerHTML = '';
        }
    }

    function render(items) {
        if (!items.length) {
            tbody.innerHTML = `<tr><td colspan="5"><div class="rp-empty">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <div>No reports match your filters.</div>
            </div></td></tr>`;
            return;
        }
        tbody.innerHTML = items.map(it => {
            const mode  = (it.mode || 'recording').toLowerCase();
            const label = mode === 'learning' ? 'Learning' : mode === 'meeting' ? 'Meeting' : 'Audio';
            const title = esc(it.title || 'Untitled');
            const words = (it.word_count != null) ? Number(it.word_count).toLocaleString() : '—';
            return `<tr>
                <td class="rp-date-cell">${esc(fmtDate(it.created_at))}</td>
                <td class="rp-title-cell">${title}</td>
                <td><span class="rp-type-chip rp-type-${mode}">${label}</span></td>
                <td>${words}</td>
                <td style="text-align:right">
                    <a href="/api/report.php?id=${encodeURIComponent(it.id)}" class="rp-open-btn">
                        Open
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </a>
                </td>
            </tr>`;
        }).join('');
    }

    function renderPagination() {
        const pages = Math.max(1, Math.ceil(state.total / state.perPage));
        if (pages <= 1) { pagination.innerHTML = ''; return; }
        const buttons = [];
        buttons.push(`<button ${state.page === 1 ? 'disabled' : ''} data-page="${state.page - 1}">Prev</button>`);
        for (let i = 1; i <= pages; i++) {
            if (i === 1 || i === pages || Math.abs(i - state.page) <= 2) {
                buttons.push(`<button class="${i === state.page ? 'active' : ''}" data-page="${i}">${i}</button>`);
            } else if (Math.abs(i - state.page) === 3) {
                buttons.push('<span style="padding:7px 6px;color:#94a3b8">…</span>');
            }
        }
        buttons.push(`<button ${state.page === pages ? 'disabled' : ''} data-page="${state.page + 1}">Next</button>`);
        pagination.innerHTML = buttons.join('');
    }

    // Events
    let searchTimer;
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            state.search = searchInput.value.trim();
            state.page = 1;
            fetchReports();
        }, 250);
    });

    document.querySelectorAll('.rp-filter').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.rp-filter').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            state.type = btn.dataset.type;
            state.page = 1;
            fetchReports();
        });
    });

    pagination.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-page]');
        if (!btn || btn.disabled) return;
        const p = parseInt(btn.dataset.page, 10);
        if (!isNaN(p) && p !== state.page) {
            state.page = p;
            fetchReports();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });

    // Topbar More dropdown
    window.tbToggleMore = function(e) {
        e.stopPropagation();
        document.getElementById('tbMoreDropdown')?.classList.toggle('open');
    };
    document.addEventListener('click', function(e) {
        const dd = document.getElementById('tbMoreDropdown');
        if (dd && !dd.contains(e.target)) dd.classList.remove('open');
    });

    window.tbSignOut = async function() {
        try {
            await fetch('/api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'logout' })
            });
        } catch (e) {}
        window.location.href = '/login.php';
    };

    // Go
    fetchReports();
})();
</script>
</body>
</html>
