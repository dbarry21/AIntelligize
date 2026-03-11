/**
 * AIntelligize — AIntelligize Stats Dashboard
 * ======================================
 * Renders AI analytics dashboard inside WordPress admin using Chart.js.
 * Modeled after Intelligize Stats visual patterns.
 *
 * @since 6.3.1.8
 */
(function ($) {
    'use strict';

    var AJAX = MYLSStats.ajaxurl;
    var NONCE = MYLSStats.nonce;

    // Color palette
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
    var CHART_COLORS = [C.accent, C.orange, C.green, C.purple, C.teal, C.yellow, C.red];

    // Handler display names
    var HANDLER_NAMES = {
        'about_area':    'About Area',
        'meta_title':    'Meta Titles',
        'meta_desc':     'Meta Descriptions',
        'meta':          'Meta (Title/Desc)',
        'faqs':          'FAQ Builder',
        'geo':           'GEO Rewrite',
        'html_excerpt':  'HTML Excerpts',
        'excerpt':       'WP Excerpts',
        'excerpts':      'WP Excerpts',
        'taglines':      'Taglines',
        'llms_txt':      'LLMS.txt',
        'page_builder':  'Page Builder',
        'unknown':       'Unknown',
    };

    // ── State ─────────────────────────────────────
    var currentView = 'overview';
    var currentPeriod = 30;
    var data = {};

    // ── Init ──────────────────────────────────────
    $(document).ready(function () {
        var root = document.getElementById('myls-stats-root');
        if (root) {
            renderShell(root);
            loadAllData();
        }
    });

    // ── Data Loading ──────────────────────────────
    function ajaxGet(action, params) {
        params = params || {};
        params.action = action;
        params.nonce = NONCE;
        return $.ajax({ url: AJAX, method: 'GET', data: params, dataType: 'json' });
    }

    function loadAllData() {
        showLoading();
        $.when(
            ajaxGet('myls_stats_overview',   { days: currentPeriod }),
            ajaxGet('myls_stats_timeline',   { days: currentPeriod }),
            ajaxGet('myls_stats_by_handler', { days: currentPeriod }),
            ajaxGet('myls_stats_by_model',   { days: currentPeriod }),
            ajaxGet('myls_stats_hourly',     { days: currentPeriod }),
            ajaxGet('myls_stats_recent',     { limit: 100 })
        ).then(function (ov, tl, hd, md, hr, rc) {
            data.overview  = (ov[0] && ov[0].data) ? ov[0].data : {};
            data.timeline  = (tl[0] && tl[0].data) ? tl[0].data : [];
            data.handlers  = (hd[0] && hd[0].data) ? hd[0].data : [];
            data.models    = (md[0] && md[0].data) ? md[0].data : [];
            data.hourly    = (hr[0] && hr[0].data) ? hr[0].data : [];
            data.recent    = (rc[0] && rc[0].data) ? rc[0].data : [];
            renderCurrentView();
        }).fail(function () {
            hideLoading();
            showError('Failed to load stats data. The AI usage table may not be created yet — run any AI generation first.');
        });
    }

    // ── Shell ─────────────────────────────────────
    function renderShell(root) {
        var html = '<div class="ms-dashboard">';

        // Header
        html += '<div class="ms-header">';
        html += '  <div>';
        html += '    <h1><span class="ms-logo">📊</span> AI Usage</h1>';
        html += '    <div class="ms-subtitle">AI Usage Analytics &amp; Cost Tracking &middot; ' + new Date().toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) + '</div>';
        html += '  </div>';
        html += '  <div class="ms-period-selector">';
        [7, 14, 30, 90].forEach(function (d) {
            html += '<button class="ms-period-btn' + (currentPeriod === d ? ' active' : '') + '" data-days="' + d + '">' + d + 'd</button>';
        });
        html += '  </div>';
        html += '</div>';

        // Tabs
        html += '<div class="ms-tabs">';
        var tabs = [
            { key: 'overview',  label: '◎ Overview' },
            { key: 'costs',     label: '💰 Cost Analysis' },
            { key: 'handlers',  label: '⚙ Handlers' },
            { key: 'log',       label: '📋 Activity Log' },
        ];
        tabs.forEach(function (t) {
            html += '<button class="ms-tab' + (currentView === t.key ? ' active' : '') + '" data-view="' + t.key + '">' + t.label + '</button>';
        });
        html += '</div>';

        // Content
        html += '<div id="ms-content"></div>';
        html += '</div>';

        root.innerHTML = html;

        // Bind events
        $(root).on('click', '.ms-tab', function () {
            currentView = $(this).data('view');
            $('.ms-tab').removeClass('active');
            $(this).addClass('active');
            renderCurrentView();
        });

        $(root).on('click', '.ms-period-btn', function () {
            currentPeriod = $(this).data('days');
            $('.ms-period-btn').removeClass('active');
            $(this).addClass('active');
            loadAllData();
        });
    }

    // ── View Dispatch ─────────────────────────────
    function renderCurrentView() {
        var $el = $('#ms-content');
        $el.empty();
        hideLoading();

        var ov = data.overview || {};
        if ( !ov.overview || parseInt(ov.overview.total_calls || 0) === 0 ) {
            // Check if log table exists at all
            if ( parseInt(ov.total_log || 0) === 0 && currentView !== 'overview' ) {
                showEmptyState($el);
                return;
            }
        }

        switch (currentView) {
            case 'overview':  renderOverview($el);  break;
            case 'costs':     renderCosts($el);     break;
            case 'handlers':  renderHandlers($el);  break;
            case 'log':       renderLog($el);       break;
        }
    }


    // ═══════════════════════════════════════════════
    //  OVERVIEW TAB
    // ═══════════════════════════════════════════════

    function renderOverview($el) {
        var ov = (data.overview && data.overview.overview) || {};
        var cfg = (data.overview && data.overview.config) || {};
        var cov = (data.overview && data.overview.coverage) || {};
        var totalCalls  = parseInt(ov.total_calls || 0);
        var totalCost   = parseFloat(ov.total_cost || 0);
        var totalTokens = parseInt(ov.total_input_tokens || 0) + parseInt(ov.total_output_tokens || 0);
        var avgDuration = Math.round(parseFloat(ov.avg_duration_ms || 0));
        var successRate = totalCalls > 0 ? Math.round( parseInt(ov.success_count || 0) / totalCalls * 100 ) : 0;

        // KPI Row
        var html = '<div class="ms-kpi-grid ms-kpi-grid-5">';
        html += kpiCard('Total AI Calls',  numFmt(totalCalls),        '🤖', numFmt(ov.handlers_used || 0) + ' handlers', '');
        html += kpiCard('Total Cost',      '$' + fmtCost(totalCost),  '💰', '', currentPeriod + '-day total');
        html += kpiCard('Total Tokens',    numFmt(totalTokens),       '📊', '', 'input + output');
        html += kpiCard('Avg Response',    avgDuration + 'ms',        '⚡', '', 'per call');
        html += kpiCard('Success Rate',    successRate + '%',         '✅', numFmt(ov.error_count || 0) + ' errors', '', successRate >= 95);
        html += '</div>';

        // Timeline + Model split
        html += '<div class="ms-grid-2">';
        html += '<div class="ms-card"><h3 class="ms-card-title">📈 AI Calls & Cost Over Time</h3><div class="ms-chart-container"><canvas id="ms-chart-timeline"></canvas></div></div>';
        html += '<div class="ms-card"><h3 class="ms-card-title">🧠 Models Used</h3><div class="ms-chart-container"><canvas id="ms-chart-models"></canvas></div></div>';
        html += '</div>';

        // Content Coverage + Config
        html += '<div class="ms-grid-half">';

        // Coverage card
        html += '<div class="ms-card"><h3 class="ms-card-title">📝 Content Coverage</h3>';
        var total = parseInt(cov.total || 0) || 1;
        var covItems = [
            { label: 'SEO Titles',       count: parseInt(cov.with_title || 0),        color: 'green' },
            { label: 'Meta Descriptions', count: parseInt(cov.with_desc || 0),         color: 'blue' },
            { label: 'WP Excerpts',       count: parseInt(cov.with_excerpt || 0),      color: 'purple' },
            { label: 'HTML Excerpts',     count: parseInt(cov.with_html_excerpt || 0), color: 'orange' },
        ];
        covItems.forEach(function (item) {
            var pct = Math.round(item.count / total * 100);
            html += '<div class="ms-coverage-item">';
            html += '  <div class="ms-coverage-header">';
            html += '    <span class="ms-coverage-label">' + item.label + '</span>';
            html += '    <span class="ms-coverage-count">' + item.count + ' / ' + total + ' (' + pct + '%)</span>';
            html += '  </div>';
            html += '  <div class="ms-progress-track"><div class="ms-progress-fill ms-progress-' + item.color + '" style="width:' + pct + '%"></div></div>';
            html += '</div>';
        });
        html += '</div>';

        // Config card
        html += '<div class="ms-card"><h3 class="ms-card-title">⚙ Configuration</h3>';
        var configItems = [
            ['Provider',       '<span class="ms-badge ms-badge-' + (cfg.provider || 'default') + '">' + escHtml(cfg.provider || 'Not set') + '</span>'],
            ['Default Model',  '<code>' + escHtml(cfg.default_model || 'Auto') + '</code>'],
            ['OpenAI Key',     cfg.has_openai   ? '<span class="ms-badge ms-badge-active">Active</span>' : '<span class="ms-badge ms-badge-error">Missing</span>'],
            ['Anthropic Key',  cfg.has_anthropic ? '<span class="ms-badge ms-badge-active">Active</span>' : '<span class="ms-badge ms-badge-error">Missing</span>'],
            ['Custom Prompts', '<span class="ms-badge ms-badge-custom">' + (cfg.custom_prompts || 0) + ' / ' + (cfg.total_prompts || 0) + '</span>'],
            ['Plugin Version', '<code>' + escHtml(cfg.plugin_version || '?') + '</code>'],
        ];
        configItems.forEach(function (item) {
            html += '<div class="ms-config-item"><span class="ms-config-label">' + item[0] + '</span><span class="ms-config-value">' + item[1] + '</span></div>';
        });
        html += '</div>';

        html += '</div>';

        // Post Types breakdown
        if (cov.by_type && cov.by_type.length) {
            html += '<div class="ms-card ms-mb-24"><h3 class="ms-card-title">📂 Published Content by Post Type</h3>';
            html += '<div style="display:flex; flex-wrap:wrap; gap:12px;">';
            cov.by_type.forEach(function (pt) {
                html += '<div style="background:#f8f9fa; border-radius:6px; padding:12px 20px; text-align:center; min-width:120px;">';
                html += '  <div style="font-size:24px; font-weight:700; color:' + C.accent + ';">' + numFmt(pt.count) + '</div>';
                html += '  <div style="font-size:12px; color:' + C.muted + '; text-transform:uppercase; font-weight:600;">' + escHtml(pt.post_type) + '</div>';
                html += '</div>';
            });
            html += '</div></div>';
        }

        $el.html(html);

        // Render charts
        renderTimelineChart();
        renderModelsPie();
    }


    // ═══════════════════════════════════════════════
    //  COST ANALYSIS TAB
    // ═══════════════════════════════════════════════

    function renderCosts($el) {
        var ov = (data.overview && data.overview.overview) || {};
        var totalCost   = parseFloat(ov.total_cost || 0);
        var totalCalls  = parseInt(ov.total_calls || 0);
        var inputTok    = parseInt(ov.total_input_tokens || 0);
        var outputTok   = parseInt(ov.total_output_tokens || 0);
        var avgCost     = totalCalls > 0 ? totalCost / totalCalls : 0;

        // Project cost if continuing at this rate
        var daysInPeriod = currentPeriod;
        var dailyCost    = totalCost / Math.max(1, daysInPeriod);
        var monthlyCost  = dailyCost * 30;

        // KPIs
        var html = '<div class="ms-kpi-grid">';
        html += kpiCard('Total Spent',      '$' + fmtCost(totalCost),    '💰', '', currentPeriod + '-day period');
        html += kpiCard('Cost per Call',     '$' + fmtCost(avgCost),     '📊', '', 'average');
        html += kpiCard('Projected Monthly', '$' + fmtCost(monthlyCost), '📅', '', 'at current rate');
        html += kpiCard('Input vs Output',   Math.round(inputTok / Math.max(1, inputTok + outputTok) * 100) + '% / ' + Math.round(outputTok / Math.max(1, inputTok + outputTok) * 100) + '%', '⚖', numFmt(inputTok) + ' / ' + numFmt(outputTok), 'tokens');
        html += '</div>';

        // Cost timeline + Cost by handler pie
        html += '<div class="ms-grid-2">';
        html += '<div class="ms-card"><h3 class="ms-card-title">💰 Daily Cost Trend</h3><div class="ms-chart-container"><canvas id="ms-chart-cost-trend"></canvas></div></div>';
        html += '<div class="ms-card"><h3 class="ms-card-title">🍕 Cost by Handler</h3><div class="ms-chart-container"><canvas id="ms-chart-cost-handler"></canvas></div></div>';
        html += '</div>';

        // Cost breakdown table
        html += '<div class="ms-card ms-mb-24"><h3 class="ms-card-title">📋 Cost Breakdown by Handler</h3>';
        html += '<table class="ms-table"><thead><tr>';
        ['Handler', 'Calls', 'Input Tokens', 'Output Tokens', 'Successes', 'Errors', 'Avg Duration', 'Total Cost'].forEach(function (h) {
            html += '<th>' + h + '</th>';
        });
        html += '</tr></thead><tbody>';

        var handlers = data.handlers || [];
        if (handlers.length === 0) {
            html += '<tr><td colspan="8" style="text-align:center; padding:20px; color:' + C.muted + ';">No handler data yet</td></tr>';
        } else {
            handlers.forEach(function (h) {
                html += '<tr>';
                html += '<td><strong>' + handlerName(h.handler) + '</strong></td>';
                html += '<td>' + numFmt(h.calls) + '</td>';
                html += '<td class="ms-right">' + numFmt(h.input_tokens) + '</td>';
                html += '<td class="ms-right">' + numFmt(h.output_tokens) + '</td>';
                html += '<td>' + numFmt(h.successes) + '</td>';
                html += '<td>' + (parseInt(h.errors) > 0 ? '<span style="color:' + C.red + ';">' + h.errors + '</span>' : '0') + '</td>';
                html += '<td class="ms-right">' + Math.round(parseFloat(h.avg_duration || 0)) + 'ms</td>';
                html += '<td class="ms-right"><strong>$' + fmtCost(h.cost) + '</strong></td>';
                html += '</tr>';
            });
        }
        html += '</tbody></table></div>';

        // Model cost table
        html += '<div class="ms-card ms-mb-24"><h3 class="ms-card-title">🧠 Cost by Model</h3>';
        html += '<table class="ms-table"><thead><tr>';
        ['Model', 'Provider', 'Calls', 'Total Tokens', 'Total Cost', 'Cost / Call'].forEach(function (h) {
            html += '<th>' + h + '</th>';
        });
        html += '</tr></thead><tbody>';
        var models = data.models || [];
        if (models.length === 0) {
            html += '<tr><td colspan="6" style="text-align:center; padding:20px; color:' + C.muted + ';">No model data yet</td></tr>';
        } else {
            models.forEach(function (m) {
                var cpc = parseInt(m.calls) > 0 ? parseFloat(m.cost) / parseInt(m.calls) : 0;
                html += '<tr>';
                html += '<td class="ms-mono">' + escHtml(m.model) + '</td>';
                html += '<td><span class="ms-badge ms-badge-' + escHtml(m.provider || 'default') + '">' + escHtml(m.provider || '?') + '</span></td>';
                html += '<td>' + numFmt(m.calls) + '</td>';
                html += '<td class="ms-right">' + numFmt(m.total_tokens) + '</td>';
                html += '<td class="ms-right"><strong>$' + fmtCost(m.cost) + '</strong></td>';
                html += '<td class="ms-right">$' + fmtCost(cpc) + '</td>';
                html += '</tr>';
            });
        }
        html += '</tbody></table></div>';

        $el.html(html);
        renderCostTrendChart();
        renderCostHandlerPie();
    }


    // ═══════════════════════════════════════════════
    //  HANDLERS TAB
    // ═══════════════════════════════════════════════

    function renderHandlers($el) {
        var handlers = data.handlers || [];
        var totalCalls = handlers.reduce(function (a, h) { return a + parseInt(h.calls); }, 0);

        // KPIs
        var html = '<div class="ms-kpi-grid ms-kpi-grid-3">';
        html += kpiCard('Active Handlers', handlers.length, '⚙', '', 'used in period');
        html += kpiCard('Total Calls',     numFmt(totalCalls), '📞', '', 'across all handlers');
        var topHandler = handlers.length > 0 ? handlerName(handlers[0].handler) : '—';
        html += kpiCard('Top Handler',      topHandler, '🏆', '', 'by cost');
        html += '</div>';

        // Calls by handler bar chart + hourly heatmap
        html += '<div class="ms-grid-half">';
        html += '<div class="ms-card"><h3 class="ms-card-title">📊 Calls by Handler</h3><div class="ms-chart-container"><canvas id="ms-chart-handler-calls"></canvas></div></div>';
        html += '<div class="ms-card"><h3 class="ms-card-title">🕐 Call Volume by Hour</h3><div class="ms-chart-container"><canvas id="ms-chart-hourly"></canvas></div>';
        html += '<p style="font-size:12px; color:' + C.muted + '; text-align:center; margin:8px 0 0;">Business hours (9–5) highlighted</p></div>';
        html += '</div>';

        // Ranked handler list
        html += '<div class="ms-card ms-mb-24"><h3 class="ms-card-title">📋 Handler Performance</h3>';
        if (handlers.length === 0) {
            html += '<div class="ms-empty-state"><div class="ms-empty-state-desc">No handler data for this period</div></div>';
        } else {
            handlers.forEach(function (h, i) {
                var callPct = totalCalls > 0 ? Math.round(parseInt(h.calls) / totalCalls * 100) : 0;
                html += '<div class="ms-handler-item">';
                html += '  <div class="ms-handler-rank">' + (i + 1) + '</div>';
                html += '  <div class="ms-handler-name">' + handlerName(h.handler) + '</div>';
                html += '  <div class="ms-handler-meta">' + numFmt(h.calls) + ' calls (' + callPct + '%) &middot; ' + Math.round(parseFloat(h.avg_duration || 0)) + 'ms avg';
                if (parseInt(h.errors) > 0) html += ' &middot; <span style="color:' + C.red + ';">' + h.errors + ' err</span>';
                html += '</div>';
                html += '  <div class="ms-handler-cost">$' + fmtCost(h.cost) + '</div>';
                html += '</div>';
            });
        }
        html += '</div>';

        $el.html(html);
        renderHandlerCallsChart();
        renderHourlyChart();
    }


    // ═══════════════════════════════════════════════
    //  ACTIVITY LOG TAB
    // ═══════════════════════════════════════════════

    function renderLog($el) {
        var recent = data.recent || [];
        var totalLog = parseInt((data.overview && data.overview.total_log) || 0);

        var html = '<div class="ms-card ms-mb-24">';
        html += '<h3 class="ms-card-title">📋 Recent AI Calls <span style="font-weight:400; color:' + C.muted + '; font-size:12px;">(' + numFmt(totalLog) + ' total in database)</span></h3>';

        if (recent.length === 0) {
            html += '<div class="ms-empty-state"><div class="ms-empty-state-icon">📋</div>';
            html += '<div class="ms-empty-state-title">No activity yet</div>';
            html += '<div class="ms-empty-state-desc">AI calls will appear here as you use generation features.</div></div>';
        } else {
            html += '<div style="overflow-x:auto;">';
            html += '<table class="ms-table"><thead><tr>';
            ['Time', 'Handler', 'Model', 'Provider', 'Post ID', 'In Tokens', 'Out Tokens', 'Cost', 'Duration', 'Status'].forEach(function (h) {
                html += '<th>' + h + '</th>';
            });
            html += '</tr></thead><tbody>';

            recent.forEach(function (r) {
                var isErr = r.status === 'error';
                html += '<tr' + (isErr ? ' style="background:#fef2f2;"' : '') + '>';
                html += '<td class="ms-mono" style="font-size:11px; white-space:nowrap;">' + formatDateTime(r.created_at) + '</td>';
                html += '<td><strong>' + handlerName(r.handler) + '</strong></td>';
                html += '<td class="ms-mono" style="font-size:11px;">' + shortModel(r.model) + '</td>';
                html += '<td><span class="ms-badge ms-badge-' + escHtml(r.provider || 'default') + '">' + escHtml(r.provider || '?') + '</span></td>';
                html += '<td>' + (parseInt(r.post_id) > 0 ? '<a href="post.php?post=' + r.post_id + '&action=edit" aria-label="Edit post ' + r.post_id + '">#' + r.post_id + '</a>' : '—') + '</td>';
                html += '<td class="ms-right">' + numFmt(r.input_tokens) + '</td>';
                html += '<td class="ms-right">' + numFmt(r.output_tokens) + '</td>';
                html += '<td class="ms-right">$' + fmtCost(r.est_cost_usd) + '</td>';
                html += '<td class="ms-right">' + numFmt(r.duration_ms) + 'ms</td>';
                html += '<td><span class="ms-badge ms-badge-' + (isErr ? 'error' : 'ok') + '">' + r.status + '</span>';
                if (isErr && r.error_message) html += '<div style="font-size:11px; color:' + C.red + '; margin-top:2px;">' + escHtml(r.error_message) + '</div>';
                html += '</td>';
                html += '</tr>';
            });

            html += '</tbody></table></div>';
        }

        html += '</div>';

        // Data management
        html += '<div class="ms-card"><h3 class="ms-card-title">🗂 Data Management</h3>';
        html += '<div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">';
        html += '<span style="color:' + C.muted + '; font-size:13px;">' + numFmt(totalLog) + ' records in database</span>';
        html += '<button class="button" id="ms-purge-90" style="color:' + C.red + '; border-color:' + C.red + ';">Purge records older than 90 days</button>';
        html += '</div></div>';

        $el.html(html);

        // Purge handler
        $('#ms-purge-90').on('click', function () {
            if (!confirm('Delete all AI usage records older than 90 days? This cannot be undone.')) return;
            var $btn = $(this).prop('disabled', true).text('Purging…');
            $.post(AJAX, { action: 'myls_stats_purge', nonce: NONCE, days: 90 }, function (res) {
                if (res && res.success) {
                    alert('Deleted ' + res.data.deleted + ' records.');
                    loadAllData();
                } else {
                    alert('Purge failed.');
                }
                $btn.prop('disabled', false).text('Purge records older than 90 days');
            });
        });
    }


    // ═══════════════════════════════════════════════
    //  CHART RENDERING
    // ═══════════════════════════════════════════════

    var charts = {};

    function destroyChart(id) {
        if (charts[id]) { charts[id].destroy(); delete charts[id]; }
    }

    // ── Timeline: calls + cost dual axis ──
    function renderTimelineChart() {
        destroyChart('timeline');
        var ctx = document.getElementById('ms-chart-timeline');
        if (!ctx) return;
        var tl = data.timeline || [];
        charts.timeline = new Chart(ctx, {
            type: 'line',
            data: {
                labels: tl.map(function (d) { return d.date.slice(5); }),
                datasets: [
                    { label: 'Calls', data: tl.map(function (d) { return parseInt(d.calls); }), borderColor: C.accent, backgroundColor: C.accent + '22', fill: true, tension: 0.3, yAxisID: 'y' },
                    { label: 'Cost ($)', data: tl.map(function (d) { return parseFloat(d.cost); }), borderColor: C.orange, backgroundColor: C.orange + '22', fill: false, tension: 0.3, yAxisID: 'y1', borderDash: [5, 3] },
                ]
            },
            options: dualAxisOpts('Calls', 'Cost ($)'),
        });
    }

    // ── Models Pie ──
    function renderModelsPie() {
        destroyChart('models');
        var ctx = document.getElementById('ms-chart-models');
        if (!ctx) return;
        var items = data.models || [];
        charts.models = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: items.map(function (m) { return shortModel(m.model); }),
                datasets: [{ data: items.map(function (m) { return parseInt(m.calls); }), backgroundColor: CHART_COLORS }]
            },
            options: pieOpts(),
        });
    }

    // ── Cost Trend ──
    function renderCostTrendChart() {
        destroyChart('costTrend');
        var ctx = document.getElementById('ms-chart-cost-trend');
        if (!ctx) return;
        var tl = data.timeline || [];

        // Cumulative cost
        var cumulative = [];
        var running = 0;
        tl.forEach(function (d) { running += parseFloat(d.cost); cumulative.push(running); });

        charts.costTrend = new Chart(ctx, {
            type: 'line',
            data: {
                labels: tl.map(function (d) { return d.date.slice(5); }),
                datasets: [
                    { label: 'Daily Cost', data: tl.map(function (d) { return parseFloat(d.cost); }), borderColor: C.orange, backgroundColor: C.orange + '33', fill: true, tension: 0.3, yAxisID: 'y' },
                    { label: 'Cumulative', data: cumulative, borderColor: C.accent, borderDash: [5, 3], fill: false, tension: 0.3, yAxisID: 'y1' },
                ]
            },
            options: dualAxisOpts('Daily $', 'Cumulative $'),
        });
    }

    // ── Cost by Handler Pie ──
    function renderCostHandlerPie() {
        destroyChart('costHandler');
        var ctx = document.getElementById('ms-chart-cost-handler');
        if (!ctx) return;
        var items = data.handlers || [];
        charts.costHandler = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: items.map(function (h) { return handlerName(h.handler); }),
                datasets: [{ data: items.map(function (h) { return parseFloat(h.cost); }), backgroundColor: CHART_COLORS }]
            },
            options: pieOpts(),
        });
    }

    // ── Handler Calls Bar ──
    function renderHandlerCallsChart() {
        destroyChart('handlerCalls');
        var ctx = document.getElementById('ms-chart-handler-calls');
        if (!ctx) return;
        var items = data.handlers || [];
        charts.handlerCalls = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: items.map(function (h) { return handlerName(h.handler); }),
                datasets: [{
                    label: 'Calls',
                    data: items.map(function (h) { return parseInt(h.calls); }),
                    backgroundColor: items.map(function (_, i) { return CHART_COLORS[i % CHART_COLORS.length]; }),
                    borderRadius: 4,
                }]
            },
            options: Object.assign(chartOpts(), { indexAxis: 'y' }),
        });
    }

    // ── Hourly ──
    function renderHourlyChart() {
        destroyChart('hourly');
        var ctx = document.getElementById('ms-chart-hourly');
        if (!ctx) return;
        var items = data.hourly || [];

        // Fill all 24 hours
        var hourMap = {};
        items.forEach(function (h) { hourMap[parseInt(h.hour)] = parseInt(h.calls); });
        var labels = [], vals = [], colors = [];
        for (var i = 0; i < 24; i++) {
            labels.push(i + ':00');
            vals.push(hourMap[i] || 0);
            colors.push(i >= 9 && i <= 17 ? C.accent : C.accent + '55');
        }

        charts.hourly = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{ label: 'Calls', data: vals, backgroundColor: colors, borderRadius: 3 }]
            },
            options: chartOpts(),
        });
    }


    // ═══════════════════════════════════════════════
    //  CHART OPTIONS HELPERS
    // ═══════════════════════════════════════════════

    function chartOpts() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { labels: { padding: 16, usePointStyle: true } } },
            scales: {
                x: { grid: { color: '#f0f0f1' }, ticks: { font: { size: 11 }, color: C.muted } },
                y: { grid: { color: '#f0f0f1' }, ticks: { font: { size: 11 }, color: C.muted } },
            },
        };
    }

    function pieOpts() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { padding: 16, usePointStyle: true, font: { size: 12 } } } },
        };
    }

    function dualAxisOpts(leftLabel, rightLabel) {
        return {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { labels: { padding: 16, usePointStyle: true } } },
            scales: {
                x:  { grid: { color: '#f0f0f1' }, ticks: { font: { size: 11 }, color: C.muted } },
                y:  { position: 'left',  grid: { color: '#f0f0f1' }, ticks: { font: { size: 11 }, color: C.muted }, title: { display: true, text: leftLabel, font: { size: 11 } } },
                y1: { position: 'right', grid: { drawOnChartArea: false }, ticks: { font: { size: 11 }, color: C.orange }, title: { display: true, text: rightLabel, font: { size: 11 } } },
            },
        };
    }


    // ═══════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════

    function kpiCard(label, value, icon, trend, sub, trendUp) {
        var html = '<div class="ms-kpi">';
        html += '<div class="ms-kpi-icon">' + icon + '</div>';
        html += '<div class="ms-kpi-label">' + label + '</div>';
        html += '<div class="ms-kpi-value">' + value + '</div>';
        html += '<div class="ms-kpi-sub">';
        if (trend) html += '<span class="' + (trendUp ? 'ms-trend-up' : 'ms-trend-down') + '">' + trend + '</span>';
        if (sub) html += sub;
        html += '</div></div>';
        return html;
    }

    function numFmt(n) {
        return (parseInt(n) || 0).toLocaleString();
    }

    function fmtCost(v) {
        var n = parseFloat(v) || 0;
        if (n === 0) return '0.00';
        if (n < 0.01) return n.toFixed(4);
        return n.toFixed(2);
    }

    function handlerName(key) {
        return HANDLER_NAMES[key] || (key || 'Unknown').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
    }

    function shortModel(model) {
        if (!model) return '?';
        // Shorten long model strings
        return model
            .replace('claude-sonnet-4-20250514', 'Claude Sonnet 4')
            .replace('claude-haiku-4-5-20251001', 'Claude Haiku 4.5')
            .replace('claude-opus-4-20250918', 'Claude Opus 4')
            .replace('gpt-4o-mini', 'GPT-4o Mini')
            .replace('gpt-4o', 'GPT-4o')
            .replace('gpt-4-turbo', 'GPT-4 Turbo')
            .replace('gpt-3.5-turbo', 'GPT-3.5');
    }

    function formatDateTime(d) {
        if (!d) return '—';
        try {
            var dt = new Date(d.replace(' ', 'T'));
            return dt.toLocaleDateString() + ' ' + dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } catch (e) { return d; }
    }

    function escHtml(s) {
        if (!s) return '';
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    function showLoading() {
        $('#ms-content').html('<div class="ms-loading"><div class="ms-loading-spinner"></div><div>Loading analytics…</div></div>');
    }

    function hideLoading() {
        $('.ms-loading').remove();
    }

    function showError(msg) {
        $('#ms-content').html('<div class="ms-card" style="text-align:center; padding:40px; color:' + C.red + ';">' + msg + '</div>');
    }

    function showEmptyState($el) {
        $el.html(
            '<div class="ms-card">' +
            '<div class="ms-empty-state">' +
            '<div class="ms-empty-state-icon">📊</div>' +
            '<div class="ms-empty-state-title">No AI Usage Data Yet</div>' +
            '<div class="ms-empty-state-desc">AI calls will be logged automatically when you use any generation feature (Meta, Excerpts, FAQs, About Area, etc.). Come back after running some AI generations to see your analytics.</div>' +
            '</div></div>'
        );
    }

})(jQuery);
