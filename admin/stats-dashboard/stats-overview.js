/**
 * AIntelligize — Stats Overview Dashboard (Aggregated)
 * ======================================
 * Renders a summary dashboard pulling KPIs from AI Usage, Search Demand,
 * and AI Exposure sub-pages. Uses the shared ms-* design system.
 *
 * @since 7.9.0
 */
(function ($) {
    'use strict';

    if (!window.MYLSStatsDash) return;

    var CFG   = MYLSStatsDash;
    var AJAX  = CFG.ajaxurl;
    var ROOT;

    // Color palette (same as stats-dashboard.js)
    var C = {
        accent: '#2271b1',
        green:  '#00a32a',
        red:    '#d63638',
        orange: '#d35400',
        yellow: '#dba617',
        purple: '#8c5fc7',
        teal:   '#00838f',
        muted:  '#787c82',
        border: '#e0e0e0',
    };

    var data = {};

    // ── Init ──────────────────────────────────────
    $(document).ready(function () {
        ROOT = document.getElementById('myls-stats-dash-root');
        if (ROOT) {
            renderShell();
            loadAllData();
        }
    });

    // ── AJAX Helpers ──────────────────────────────
    function ajaxGet(action, nonce, params) {
        params = params || {};
        params.action = action;
        params.nonce  = nonce;
        return $.ajax({ url: AJAX, method: 'GET', data: params, dataType: 'json' });
    }

    function ajaxPost(action, nonce, params) {
        params = params || {};
        params.action = action;
        params.nonce  = nonce;
        return $.ajax({ url: AJAX, method: 'POST', data: params, dataType: 'json' });
    }

    // ── Data Loading ──────────────────────────────
    function loadAllData() {
        showLoading();
        $.when(
            ajaxGet('myls_stats_overview', CFG.nonce_stats, { days: 30 }),
            ajaxPost('myls_sd_db_load', CFG.nonce_sd, {}),
            ajaxGet('myls_ae_overview', CFG.nonce_ae, { days: 30 })
        ).then(function (statsRes, sdRes, aeRes) {
            data.ai_usage = (statsRes[0] && statsRes[0].data) ? statsRes[0].data : {};
            data.search   = (sdRes[0] && sdRes[0].data) ? sdRes[0].data : {};
            data.exposure  = (aeRes[0] && aeRes[0].data) ? aeRes[0].data : {};
            hideLoading();
            renderDashboard();
        }).fail(function () {
            hideLoading();
            showError('Failed to load dashboard data. Ensure the plugin is configured and has been used at least once.');
        });
    }

    // ── Shell ─────────────────────────────────────
    function renderShell() {
        var today = new Date().toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });

        var html = '<div class="ms-dashboard">';

        // Header
        html += '<div class="ms-header">';
        html += '  <div>';
        html += '    <h1><span class="ms-logo">📊</span> AIntelligize Stats</h1>';
        html += '    <div class="ms-subtitle">Aggregated Overview &middot; ' + today + '</div>';
        html += '  </div>';
        html += '</div>';

        // Content area
        html += '<div id="msd-content"></div>';

        // Loading
        html += '<div id="msd-loading" class="ms-loading" style="display:none;">';
        html += '  <div class="ms-spinner"></div>';
        html += '  <div>Loading dashboard&hellip;</div>';
        html += '</div>';

        // Error
        html += '<div id="msd-error" class="ms-empty-state" style="display:none;">';
        html += '  <div class="ms-empty-icon">⚠️</div>';
        html += '  <div class="ms-empty-title" id="msd-error-msg"></div>';
        html += '</div>';

        html += '</div>';
        ROOT.innerHTML = html;
    }

    // ── Dashboard Render ──────────────────────────
    function renderDashboard() {
        var el = document.getElementById('msd-content');
        if (!el) return;

        var html = '';

        // ── Section 1: AI Usage ──
        html += sectionHeader('bi-cpu', 'AI Usage');
        html += '<div class="ms-kpi-grid" style="--ms-kpi-cols:4;">';
        var ov = data.ai_usage.overview || {};
        var totalCost  = parseFloat(ov.total_cost) || 0;
        var totalCalls = parseInt(ov.total_calls) || 0;
        var succRate   = totalCalls > 0 ? Math.round(((parseInt(ov.success_count) || 0) / totalCalls) * 100) : 0;
        var topModel   = (ov.models_used || '').split(',')[0] || 'N/A';

        html += kpiCard('Total Cost', '$' + fmtCost(totalCost), '💰', '', 'Last 30 days', false, 'kpi-usage');
        html += kpiCard('Total Calls', numFmt(totalCalls), '📡', '', 'API requests', false, 'kpi-usage');
        html += kpiCard('Top Model', shortModel(topModel), '🤖', '', '', false, 'kpi-usage');
        html += kpiCard('Success Rate', succRate + '%', '✅', '', numFmt(parseInt(ov.error_count) || 0) + ' errors', succRate >= 95, 'kpi-usage');
        html += '</div>';

        // ── Section 2: Search Demand ──
        html += sectionHeader('bi-search', 'Search Demand');
        html += '<div class="ms-kpi-grid" style="--ms-kpi-cols:4;">';
        var sd = data.search.stats || {};
        var totalKw  = parseInt(sd.total_keywords) || 0;
        var avgRank  = sd.avg_rank ? parseFloat(sd.avg_rank).toFixed(1) : 'N/A';
        var aiOv     = parseInt(sd.total_ai) || 0;
        var lastGsc  = sd.newest_gsc || null;

        html += kpiCard('Keywords Tracked', numFmt(totalKw), '🔑', '', 'Focus keywords + FAQs', false, 'kpi-search');
        html += kpiCard('Avg Rank', avgRank, '📈', '', numFmt(parseInt(sd.rank_top10) || 0) + ' in top 10', false, 'kpi-search');
        html += kpiCard('AI Overviews', numFmt(aiOv), '✨', '', 'Google AI Overview appearances', false, 'kpi-search');
        html += kpiCard('Last Refreshed', timeAgo(lastGsc), '🔄', '', lastGsc ? shortDate(lastGsc) : 'Never', false, 'kpi-search');
        html += '</div>';

        // ── Section 3: AI Exposure ──
        html += sectionHeader('bi-globe2', 'AI Exposure');
        html += '<div class="ms-kpi-grid" style="--ms-kpi-cols:4;">';
        var ae = data.exposure || {};
        var aeCitations = parseInt(ae.total_citations) || 0;
        var aeScore     = parseFloat(ae.exposure_score) || 0;
        var aePlatforms = parseInt(ae.platforms_active) || 0;
        var aeLastCheck = ae.last_checked || null;

        html += kpiCard('Citations', numFmt(aeCitations), '🏆', '', 'AI chatbot mentions', false, 'kpi-exposure');
        html += kpiCard('Exposure Score', aeScore.toFixed(0) + '%', '📊', '', 'Citation rate across platforms', aeScore >= 50, 'kpi-exposure');
        html += kpiCard('Platforms', aePlatforms + ' / 2', '🌐', '', 'ChatGPT + Claude', false, 'kpi-exposure');
        html += kpiCard('Last Checked', timeAgo(aeLastCheck), '🔍', '', aeLastCheck ? shortDate(aeLastCheck) : 'Never', false, 'kpi-exposure');
        html += '</div>';

        // ── Quick Links ──
        html += '<div class="ms-quicklink-grid">';

        html += quickLink(
            CFG.ai_usage_url,
            'bi-cpu ql-usage',
            'AI Usage',
            'Token costs, model breakdown, handler analytics, and activity log.',
            'View AI Usage'
        );
        html += quickLink(
            CFG.search_demand_url,
            'bi-search ql-search',
            'Search Demand',
            'Focus keywords, autocomplete suggestions, GSC metrics, and AI overviews.',
            'View Search Demand'
        );
        html += quickLink(
            CFG.ai_exposure_url,
            'bi-globe2 ql-exposure',
            'AI Exposure',
            'Check if ChatGPT and Claude cite your site. Competitor tracking and trends.',
            'View AI Exposure'
        );

        html += '</div>';

        el.innerHTML = html;
    }

    // ── Component Builders ─────────────────────────

    function sectionHeader(icon, label) {
        return '<div class="ms-section-header"><i class="bi ' + icon + '"></i> ' + escHtml(label) + '</div>';
    }

    function kpiCard(label, value, icon, trend, sub, trendUp, extraClass) {
        var cls = 'ms-kpi' + (extraClass ? ' ms-kpi-card ' + extraClass : '');
        var html = '<div class="' + cls + '">';
        html += '<div class="ms-kpi-icon">' + icon + '</div>';
        html += '<div class="ms-kpi-label">' + escHtml(label) + '</div>';
        html += '<div class="ms-kpi-value">' + value + '</div>';
        html += '<div class="ms-kpi-sub">';
        if (trend) html += '<span class="' + (trendUp ? 'ms-trend-up' : 'ms-trend-down') + '">' + trend + '</span>';
        if (sub) html += escHtml(sub);
        html += '</div></div>';
        return html;
    }

    function quickLink(url, iconClass, title, desc, cta) {
        var html = '<a href="' + escHtml(url) + '" class="ms-quicklink ms-card">';
        html += '<span class="ms-quicklink-icon ' + iconClass + '"><i class="bi ' + iconClass.split(' ')[0] + '"></i></span>';
        html += '<div class="ms-quicklink-title">' + escHtml(title) + '</div>';
        html += '<div class="ms-quicklink-desc">' + escHtml(desc) + '</div>';
        html += '<span class="ms-quicklink-arrow">' + escHtml(cta) + ' &rarr;</span>';
        html += '</a>';
        return html;
    }

    // ── Utilities ──────────────────────────────────

    function numFmt(n) {
        return (parseInt(n) || 0).toLocaleString();
    }

    function fmtCost(n) {
        n = parseFloat(n) || 0;
        return n < 1 ? n.toFixed(4) : n.toFixed(2);
    }

    function shortModel(m) {
        if (!m || m === 'N/A') return 'N/A';
        return m.replace('claude-', 'Claude ').replace('gpt-', 'GPT-')
                .replace(/-\d{8}$/, '').replace('-20250514', '');
    }

    function escHtml(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function timeAgo(dt) {
        if (!dt) return 'Never';
        var d = new Date(dt);
        var now = new Date();
        var diff = Math.floor((now - d) / 1000);
        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        var days = Math.floor(diff / 86400);
        if (days === 1) return 'Yesterday';
        if (days < 30) return days + 'd ago';
        return Math.floor(days / 30) + 'mo ago';
    }

    function shortDate(dt) {
        if (!dt) return '';
        var d = new Date(dt);
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    // ── Loading / Error State ──────────────────────

    function showLoading() {
        var el = document.getElementById('msd-loading');
        var ct = document.getElementById('msd-content');
        if (el) el.style.display = 'flex';
        if (ct) ct.style.display = 'none';
    }

    function hideLoading() {
        var el = document.getElementById('msd-loading');
        var ct = document.getElementById('msd-content');
        if (el) el.style.display = 'none';
        if (ct) ct.style.display = 'block';
    }

    function showError(msg) {
        var el = document.getElementById('msd-error');
        var m  = document.getElementById('msd-error-msg');
        var ct = document.getElementById('msd-content');
        if (el) el.style.display = 'block';
        if (m) m.textContent = msg;
        if (ct) ct.style.display = 'none';
    }

})(jQuery);
