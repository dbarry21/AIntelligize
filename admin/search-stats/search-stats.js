/**
 * AIntelligize — Search Stats Dashboard
 * ======================================
 * Standalone submenu page. Renders inside #myls-search-stats-root.
 * Loads stored keyword data from DB, provides action buttons to
 * scan/refresh, writes results back to DB.
 *
 * Features: KPI cards, per-post SERP rank, AC suggestions, GSC metrics,
 * AI Overview detection, expandable sub-grids, print support.
 *
 * Config: window.MYLS_SS
 * @since 6.3.2.7
 */
(function ($) {
  "use strict";
  if (!window.MYLS_SS) return;

  var CFG  = window.MYLS_SS;
  var ROOT = document.getElementById("myls-search-stats-root");
  if (!ROOT) return;

  /* ── Helpers ── */
  function esc(s) { var d = document.createElement("div"); d.textContent = s; return d.innerHTML; }
  function el(id) { return document.getElementById(id); }
  function show(e, v) { if (e) e.style.display = v ? "" : "none"; }
  function num(n) { n = parseInt(n, 10) || 0; return n >= 1000 ? (n / 1000).toFixed(1).replace(/\.0$/, "") + "k" : String(n); }
  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  function post(action, data) {
    var fd = new FormData();
    fd.append("action", action);
    fd.append("nonce", CFG.nonce);
    if (data) Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(CFG.ajaxurl, { method: "POST", body: fd }).then(function (r) { return r.json(); });
  }

  /* ── State ── */
  var allRows  = [];
  var stats    = {};
  var activePT = "all";
  var STOP     = false;

  /* AC query types */
  var QTYPES = [
    { id: "exact",    prefix: "",      suffix: ""  },
    { id: "expanded", prefix: "",      suffix: " " },
    { id: "how",      prefix: "how ",  suffix: ""  },
    { id: "what",     prefix: "what ", suffix: ""  },
    { id: "best",     prefix: "best ", suffix: ""  }
  ];

  /* ══════════════════════════════════════════════════════════════════
   * RENDER SHELL
   * ══════════════════════════════════════════════════════════════════ */
  function renderShell() {
    ROOT.innerHTML = [
      '<div class="ms-dashboard">',

      // Header
      '<div class="ms-header">',
        '<div>',
          '<h1><span class="ms-logo">📊</span> Search Stats</h1>',
          '<div class="ms-subtitle">Focus keyword &amp; FAQ tracking &bull; Autocomplete suggestions &bull; GSC metrics &bull; AI Overview &bull; Per-post SERP rank</div>',
        '</div>',
        '<div class="ms-period-selector" id="ss_pt_filter"></div>',
      '</div>',

      // KPI cards — 6 cards
      '<div class="ms-kpi-grid" style="grid-template-columns:repeat(6,1fr)" id="ss_kpis">',
        kpiHtml("ss_kpi_kw",    "🔑", "Keywords Tracked"),
        kpiHtml("ss_kpi_ac",    "💡", "AC Suggestions"),
        kpiHtml("ss_kpi_gsc",   "🔍", "GSC Queries"),
        kpiHtml("ss_kpi_rank",  "📍", "Avg Rank"),
        kpiHtml("ss_kpi_ai",    "✨", "AI Overview"),
        kpiHtml("ss_kpi_fresh", "🕐", "Last Refreshed"),
      '</div>',

      // Action card
      '<div class="ms-card ms-mb-24" id="ss_action_card">',
        '<div class="ms-card-title">⚡ Actions</div>',
        '<div class="ss-actions">',

          '<button class="button button-primary" id="ss_btn_all">',
            '<i class="bi bi-arrow-repeat"></i> Refresh All',
          '</button>',

          '<div class="ss-actions-sep">',
            '<button class="button" id="ss_btn_scan"><i class="bi bi-search"></i> Scan Keywords</button>',
            '<button class="button" id="ss_btn_ac" disabled><i class="bi bi-lightbulb"></i> Refresh AC</button>',
          '</div>',

          '<div class="ss-actions-sep">',
            '<select id="ss_gsc_days" class="form-select" style="min-width:130px;">',
              '<option value="30">Last 30 days</option>',
              '<option value="60">Last 60 days</option>',
              '<option value="90" selected>Last 90 days</option>',
            '</select>',
            '<button class="button" id="ss_btn_gsc" ' + (CFG.gsc_connected ? '' : 'disabled') + '>',
              '<i class="bi bi-google"></i> Refresh GSC + Rank',
            '</button>',
            (!CFG.gsc_connected ? '<span class="ss-small ss-text-muted"><i class="bi bi-exclamation-triangle" style="color:#d97706"></i> <a href="' + esc(CFG.api_tab_url) + '">Connect GSC</a></span>' : ''),
          '</div>',

          '<div class="ss-actions-right">',
            '<span id="ss_phase"></span>',
            '<button class="button" id="ss_btn_stop" disabled><i class="bi bi-stop-circle"></i> Stop</button>',
            '<button class="button" id="ss_btn_print" style="display:none"><i class="bi bi-printer"></i> Print</button>',
          '</div>',

        '</div>',

        // Progress bar
        '<div id="ss_prog_wrap" style="display:none;margin-top:12px;">',
          '<div class="ms-progress-track" style="height:10px;">',
            '<div id="ss_prog_bar" class="ms-progress-fill ms-progress-blue" style="width:0%"></div>',
          '</div>',
          '<div style="display:flex;justify-content:space-between;margin-top:4px;font-size:12px;color:var(--ms-text-muted)">',
            '<span id="ss_prog_text">0 / 0</span>',
            '<span id="ss_prog_pct">0%</span>',
          '</div>',
        '</div>',

      '</div>',

      // Data table card
      '<div class="ms-card" id="ss_table_card">',
        '<div class="ms-card-title">',
          '📋 Keyword Data',
          '<span id="ss_table_count" class="ms-badge ms-badge-default" style="margin-left:8px;display:none">0</span>',
        '</div>',
        '<div id="ss_table_wrap">',
          '<div class="ms-empty-state" id="ss_empty">',
            '<div class="ms-empty-state-icon">📭</div>',
            '<div class="ms-empty-state-title">No keywords tracked yet</div>',
            '<div class="ms-empty-state-desc">Click "Scan Keywords" or "Refresh All" to find all focus keywords and FAQ questions across your site.</div>',
          '</div>',
        '</div>',
      '</div>',

      '</div>'
    ].join("");

    buildPTFilter();
    bindEvents();
  }

  function kpiHtml(id, icon, label) {
    return '<div class="ms-kpi">' +
      '<div class="ms-kpi-icon">' + icon + '</div>' +
      '<div class="ms-kpi-label">' + label + '</div>' +
      '<div class="ms-kpi-value" id="' + id + '_val">\u2014</div>' +
      '<div class="ms-kpi-sub" id="' + id + '_sub">&nbsp;</div>' +
    '</div>';
  }

  /* ── Post-type filter pills ── */
  function buildPTFilter() {
    var wrap = el("ss_pt_filter");
    if (!wrap) return;
    wrap.innerHTML = '<button class="ms-period-btn active" data-pt="all">All Types</button>';
  }

  function updatePTFilter(rows) {
    var wrap = el("ss_pt_filter");
    if (!wrap) return;
    var types = {};
    rows.forEach(function (r) { if (r.post_type) types[r.post_type] = true; });
    var html = '<button class="ms-period-btn' + (activePT === "all" ? " active" : "") + '" data-pt="all">All Types</button>';
    Object.keys(types).sort().forEach(function (pt) {
      html += '<button class="ms-period-btn' + (activePT === pt ? " active" : "") + '" data-pt="' + esc(pt) + '">' + esc(pt) + '</button>';
    });
    wrap.innerHTML = html;

    wrap.querySelectorAll(".ms-period-btn").forEach(function (btn) {
      btn.addEventListener("click", function () {
        wrap.querySelectorAll(".ms-period-btn").forEach(function (b) { b.classList.remove("active"); });
        btn.classList.add("active");
        activePT = btn.getAttribute("data-pt");
        renderTable();
      });
    });
  }

  /* ══════════════════════════════════════════════════════════════════
   * BIND EVENTS
   * ══════════════════════════════════════════════════════════════════ */
  function bindEvents() {
    el("ss_btn_scan").addEventListener("click", doScan);
    el("ss_btn_ac").addEventListener("click", function () { doAC(); });
    el("ss_btn_gsc").addEventListener("click", function () { doGSC(); });
    el("ss_btn_all").addEventListener("click", doRefreshAll);
    el("ss_btn_stop").addEventListener("click", function () { STOP = true; });
    el("ss_btn_print").addEventListener("click", doPrint);

    document.addEventListener("click", function (e) {
      var link = e.target.closest(".ss-expand");
      if (!link) return;
      e.preventDefault();
      var dbid   = link.getAttribute("data-dbid");
      var detail = el("ss_detail_" + dbid);
      if (!detail) return;
      var vis = detail.style.display !== "none";
      detail.style.display = vis ? "none" : "table-row";
      var icon = link.querySelector("i");
      if (icon) icon.className = vis ? "bi bi-chevron-up" : "bi bi-chevron-down";
    });

    // History button
    document.addEventListener("click", function (e) {
      var btn = e.target.closest(".ss-history-btn");
      if (!btn) return;
      e.preventDefault();
      var dbid = btn.getAttribute("data-dbid");
      var panel = el("ss_history_" + dbid);
      if (!panel) return;

      // Toggle
      if (panel.style.display !== "none" && panel.innerHTML !== "") {
        panel.style.display = "none";
        return;
      }

      panel.innerHTML = '<div class="ss-text-muted ss-small" style="padding:8px;">Loading history...</div>';
      panel.style.display = "";

      post("myls_sd_db_history", { row_id: dbid }).then(function (j) {
        if (j.success && j.data && j.data.history) {
          panel.innerHTML = buildHistoryHtml(j.data.history);
        } else {
          panel.innerHTML = '<div class="ss-text-muted ss-small" style="padding:8px;">No history yet.</div>';
        }
      }).catch(function () {
        panel.innerHTML = '<div class="ss-text-muted ss-small" style="padding:8px;">Error loading history.</div>';
      });
    });
  }

  /* ══════════════════════════════════════════════════════════════════
   * PROGRESS / STATE
   * ══════════════════════════════════════════════════════════════════ */
  function setProgress(done, total, label) {
    var pct = total > 0 ? Math.round((done / total) * 100) : 0;
    var bar = el("ss_prog_bar"); if (bar) bar.style.width = pct + "%";
    var txt = el("ss_prog_text"); if (txt) txt.textContent = done + " / " + total + (label ? "  " + label : "");
    var ptxt = el("ss_prog_pct"); if (ptxt) ptxt.textContent = pct + "%";
  }

  function setPhase(phase) {
    var span = el("ss_phase");
    if (!span) return;
    if (!phase) { span.innerHTML = ""; return; }
    var cls = "ss-phase-" + phase.toLowerCase();
    span.innerHTML = '<span class="ss-phase-badge ' + cls + '">' + esc(phase) + '</span>';
  }

  function setBtns(busy) {
    var b = !!busy;
    el("ss_btn_scan").disabled = b;
    el("ss_btn_ac").disabled   = b || allRows.length === 0;
    el("ss_btn_gsc").disabled  = b || allRows.length === 0 || !CFG.gsc_connected;
    el("ss_btn_all").disabled  = b;
    el("ss_btn_stop").disabled = !b;
  }

  /* ══════════════════════════════════════════════════════════════════
   * RANK BADGE — color coded by position with movement arrow
   * ══════════════════════════════════════════════════════════════════ */
  function rankBadge(rank, delta) {
    if (rank === null || rank === undefined || rank === "") return '<span class="ss-text-muted">\u2014</span>';
    var r = parseFloat(rank);
    var cls;
    if (r <= 3)       cls = "ss-rank-top3";
    else if (r <= 10) cls = "ss-rank-top10";
    else if (r <= 20) cls = "ss-rank-top20";
    else              cls = "ss-rank-low";

    var arrow = "";
    if (delta !== null && delta !== undefined && delta !== "") {
      var d = parseFloat(delta);
      if (d < 0)      arrow = ' <span class="ss-rank-up" title="Improved by ' + Math.abs(d) + '">\u25B2' + Math.abs(d) + '</span>';
      else if (d > 0) arrow = ' <span class="ss-rank-down" title="Dropped by ' + d + '">\u25BC' + d + '</span>';
      else            arrow = ' <span class="ss-rank-flat" title="No change">\u25C6</span>';
    }
    return '<span class="ss-rank-badge ' + cls + '">#' + r + '</span>' + arrow;
  }

  /* ══════════════════════════════════════════════════════════════════
   * KPI RENDERING
   * ══════════════════════════════════════════════════════════════════ */
  function timeAgo(d) {
    if (!d) return "Never";
    var ms = new Date() - new Date(d.replace(" ", "T") + "Z");
    var s = Math.floor(ms / 1000);
    if (s < 60)    return s + "s ago";
    if (s < 3600)  return Math.floor(s / 60) + "m ago";
    if (s < 86400) return Math.floor(s / 3600) + "h ago";
    return Math.floor(s / 86400) + "d ago";
  }

  function renderKPIs(st) {
    stats = st || {};
    el("ss_kpi_kw_val").textContent  = num(st.total_keywords);
    el("ss_kpi_kw_sub").innerHTML    = (st.ac_checked || 0) + " AC &bull; " + (st.gsc_checked || 0) + " GSC checked";
    el("ss_kpi_ac_val").textContent  = num(st.total_ac);
    el("ss_kpi_ac_sub").innerHTML    = (st.ac_checked || 0) + " of " + (st.total_keywords || 0) + " keywords";
    el("ss_kpi_gsc_val").textContent = num(st.total_gsc);
    el("ss_kpi_gsc_sub").innerHTML   = (st.gsc_checked || 0) + " of " + (st.total_keywords || 0) + " keywords";

    // Rank KPI
    var avgRank = st.avg_rank ? parseFloat(st.avg_rank).toFixed(1) : "\u2014";
    el("ss_kpi_rank_val").textContent = avgRank;
    var rankChecked = parseInt(st.rank_checked) || 0;
    var top3  = parseInt(st.rank_top3) || 0;
    var top10 = parseInt(st.rank_top10) || 0;
    el("ss_kpi_rank_sub").innerHTML = rankChecked > 0
      ? top3 + " top 3 &bull; " + top10 + " top 10 &bull; " + rankChecked + " ranked"
      : "Run GSC to detect";

    // AI KPI
    el("ss_kpi_ai_val").textContent  = num(st.total_ai);
    el("ss_kpi_ai_sub").innerHTML    = st.total_ai > 0 ? "Queries in AI Overviews" : "Run GSC to detect";

    // Freshness KPI
    var newest = st.newest_gsc || st.newest_ac;
    el("ss_kpi_fresh_val").textContent = newest ? timeAgo(newest) : "Never";
    el("ss_kpi_fresh_val").style.fontSize = "18px";
    el("ss_kpi_fresh_sub").innerHTML = newest
      ? "GSC: " + (st.newest_gsc ? timeAgo(st.newest_gsc) : "\u2014") + " &bull; AC: " + (st.newest_ac ? timeAgo(st.newest_ac) : "\u2014")
      : 'Click "Refresh All" to start';
  }

  /* ══════════════════════════════════════════════════════════════════
   * TABLE RENDERING
   * ══════════════════════════════════════════════════════════════════ */
  function freshBadge(d) {
    if (!d) return '<span class="ms-badge ss-notrun">NOT RUN</span>';
    var days = Math.floor((new Date() - new Date(d.replace(" ", "T") + "Z")) / 86400000);
    if (days < 7)  return '<span class="ms-badge ss-fresh">FRESH</span>';
    if (days < 30) return '<span class="ms-badge ss-stale">STALE ' + days + 'd</span>';
    return '<span class="ms-badge ss-old">OLD ' + days + 'd</span>';
  }

  function renderTable() {
    var filtered = activePT === "all" ? allRows : allRows.filter(function (r) { return r.post_type === activePT; });
    var wrap = el("ss_table_wrap");
    var cnt  = el("ss_table_count");
    var emp  = el("ss_empty");

    if (filtered.length === 0) {
      wrap.innerHTML = "";
      if (emp) { wrap.appendChild(emp); show(emp, true); }
      show(cnt, false);
      show(el("ss_btn_print"), false);
      return;
    }

    show(cnt, true);
    cnt.textContent = filtered.length + " keywords";

    var hasGSC = filtered.some(function (r) { return r.gsc_total > 0; });
    show(el("ss_btn_print"), hasGSC);

    var html = '<table class="ms-table" id="ss_main_table">';
    html += '<thead><tr>';
    html += '<th style="width:3%">#</th>';
    html += '<th style="width:20%">Post</th>';
    html += '<th style="width:16%">Keyword</th>';
    html += '<th style="width:6%">Source</th>';
    html += '<th style="width:6%">Rank</th>';
    html += '<th style="width:5%">AC</th>';
    html += '<th style="width:5%">GSC</th>';
    html += '<th style="width:5%">AI</th>';
    html += '<th style="width:9%">AC Fresh</th>';
    html += '<th style="width:9%">GSC Fresh</th>';
    html += '<th style="width:3%"></th>';
    html += '</tr></thead><tbody>';

    filtered.forEach(function (r, i) {
      html += '<tr id="ss_row_' + r.id + '">';
      html += '<td>' + (i + 1) + '</td>';
      html += '<td class="ss-small">' + esc(r.post_title) + ' <span class="ss-text-muted">#' + r.post_id + '</span><br><span class="ss-text-muted">' + esc(r.post_type) + '</span></td>';
      html += '<td><strong>' + esc(r.keyword) + '</strong></td>';
      html += '<td class="ss-small ss-text-muted">' + esc(r.source) + '</td>';

      // Rank
      html += '<td>' + rankBadge(r.post_rank, r.rank_delta) + '</td>';

      // AC
      html += '<td>' + (r.ac_count > 0
        ? '<span class="ms-badge ms-badge-ok">' + r.ac_count + '</span>'
        : '<span class="ms-badge ss-notrun">' + (r.ac_refreshed_at ? '0' : '\u2014') + '</span>') + '</td>';

      // GSC
      html += '<td>' + (r.gsc_total > 0
        ? '<span class="ms-badge ms-badge-default">' + r.gsc_total + '</span>'
        : '<span class="ms-badge ss-notrun">' + (r.gsc_refreshed_at ? '0' : '\u2014') + '</span>') + '</td>';

      // AI
      html += '<td>' + (r.ai_count > 0
        ? '<span class="ms-badge ms-badge-custom">' + r.ai_count + '</span>'
        : '<span class="ss-text-muted">\u2014</span>') + '</td>';

      html += '<td>' + freshBadge(r.ac_refreshed_at) + '</td>';
      html += '<td>' + freshBadge(r.gsc_refreshed_at) + '</td>';

      var hasDetail = (r.ac_count > 0 || r.gsc_total > 0);
      html += '<td>' + (hasDetail ? '<a href="#" class="ss-expand" data-dbid="' + r.id + '"><i class="bi bi-chevron-down"></i></a>' : '') + '</td>';
      html += '</tr>';

      if (hasDetail) {
        html += '<tr id="ss_detail_' + r.id + '" class="ss-detail-row" style="display:none;">';
        html += '<td colspan="11" style="padding:0;">' + buildDetailHtml(r) + '</td>';
        html += '</tr>';
      }
    });

    html += '</tbody></table>';
    wrap.innerHTML = html;
  }

  /* ══════════════════════════════════════════════════════════════════
   * DETAIL SUB-GRID
   * ══════════════════════════════════════════════════════════════════ */
  function buildDetailHtml(r) {
    var acGroups = r.ac_suggestions || [];
    var gscData  = r.gsc_data || [];
    var aiMap    = r.ai_overview || {};
    var gscMap   = {};
    gscData.forEach(function (g) { gscMap[g.query.toLowerCase().trim()] = g; });

    var html = '<div class="ss-detail-inner">';

    // Per-post rank summary with movement
    if (r.post_rank_data && r.post_rank_data.url) {
      var rd = r.post_rank_data;
      html += '<div class="ss-rank-summary">';
      html += '<strong>📍 Post Rank:</strong> ' + rankBadge(rd.avg, r.rank_delta) +
              ' &nbsp;&nbsp;' + num(rd.impr) + ' impressions &bull; ' +
              rd.total + ' matching queries';

      // Previous rank comparison
      if (r.prev_rank !== null && r.prev_rank !== undefined) {
        html += ' &bull; <span class="ss-text-muted">was #' + r.prev_rank + ' on ' + esc(r.prev_date || "prev run") + '</span>';
      }

      html += '<br><span class="ss-text-muted ss-small">' + esc(rd.url) + '</span>';

      // History button
      if (r.snapshot_count > 0) {
        html += ' &nbsp;<a href="#" class="ss-history-btn" data-dbid="' + r.id + '" title="View ' + r.snapshot_count + ' snapshots">' +
                '<i class="bi bi-clock-history"></i> ' + r.snapshot_count + ' snapshots</a>';
      }

      html += '</div>';
      html += '<div id="ss_history_' + r.id + '" class="ss-history-panel" style="display:none;"></div>';
    }

    html += '<table class="ss-subgrid"><thead><tr>';
    html += '<th>Suggestion</th><th>Type</th>';
    html += '<th class="ss-text-end">Impr.</th><th class="ss-text-end">Clicks</th>';
    html += '<th class="ss-text-end">CTR</th><th class="ss-text-end">Avg Pos</th>';
    html += '<th class="ss-text-end">AI Overview</th>';
    html += '</tr></thead><tbody>';

    acGroups.forEach(function (g) {
      if (!g.suggestions) return;
      g.suggestions.forEach(function (s) {
        var key = s.toLowerCase().trim();
        var gsc = gscMap[key];
        var ai  = aiMap[key];
        var cls = gsc ? ' class="ss-gsc-match"' : '';

        html += '<tr' + cls + '>';
        html += '<td>' + esc(s) + '</td>';
        html += '<td><span class="ss-type-tag">' + esc(g.label || g.id || "") + '</span></td>';

        if (gsc) {
          html += '<td class="ss-text-end ss-fw-semi">' + num(gsc.impressions) + '</td>';
          html += '<td class="ss-text-end ss-fw-semi">' + num(gsc.clicks) + '</td>';
          html += '<td class="ss-text-end">' + gsc.ctr + '%</td>';
          html += '<td class="ss-text-end">' + gsc.position + '</td>';
        } else {
          html += '<td class="ss-text-end ss-text-muted">\u2014</td>'.repeat(4);
        }

        html += '<td class="ss-text-end">' + (ai ? '<span class="ss-ai-tag">\u2728 ' + num(ai.impressions) + '</span>' : '<span class="ss-text-muted">\u2014</span>') + '</td>';
        html += '</tr>';
      });
    });

    // Bonus GSC rows
    if (gscData.length > 0 && acGroups.length > 0) {
      var acSet = {};
      acGroups.forEach(function (g) {
        if (g.suggestions) g.suggestions.forEach(function (s) { acSet[s.toLowerCase().trim()] = true; });
      });
      var bonus = gscData.filter(function (g) { return !acSet[g.query.toLowerCase().trim()]; });
      if (bonus.length > 0) {
        html += '<tr class="ss-bonus-header"><td colspan="7"><strong><i class="bi bi-plus-circle"></i> ' + bonus.length + ' additional GSC queries</strong></td></tr>';
        bonus.forEach(function (g) {
          var ai = aiMap[g.query.toLowerCase().trim()];
          html += '<tr class="ss-gsc-bonus">';
          html += '<td>' + esc(g.query) + '</td>';
          html += '<td><span class="ss-type-tag ss-type-gsc">GSC</span></td>';
          html += '<td class="ss-text-end ss-fw-semi">' + num(g.impressions) + '</td>';
          html += '<td class="ss-text-end ss-fw-semi">' + num(g.clicks) + '</td>';
          html += '<td class="ss-text-end">' + g.ctr + '%</td>';
          html += '<td class="ss-text-end">' + g.position + '</td>';
          html += '<td class="ss-text-end">' + (ai ? '<span class="ss-ai-tag">\u2728 ' + num(ai.impressions) + '</span>' : '\u2014') + '</td>';
          html += '</tr>';
        });
      }
    }

    html += '</tbody></table></div>';
    return html;
  }

  /* ══════════════════════════════════════════════════════════════════
   * HISTORY TABLE (loaded on demand)
   * ══════════════════════════════════════════════════════════════════ */
  function buildHistoryHtml(history) {
    if (!history || !history.length) return '<div class="ss-text-muted ss-small" style="padding:8px;">No snapshots recorded yet.</div>';

    // Reverse to chronological for movement calc
    var sorted = history.slice().reverse();

    var html = '<div class="ss-history-inner">';
    html += '<div style="margin-bottom:8px;"><strong><i class="bi bi-clock-history"></i> Rank History</strong> <span class="ss-text-muted ss-small">(' + history.length + ' snapshots)</span></div>';

    html += '<table class="ss-subgrid"><thead><tr>';
    html += '<th>Date</th>';
    html += '<th class="ss-text-end">Rank</th>';
    html += '<th class="ss-text-end">Change</th>';
    html += '<th class="ss-text-end">Impr.</th>';
    html += '<th class="ss-text-end">Clicks</th>';
    html += '<th class="ss-text-end">CTR</th>';
    html += '<th class="ss-text-end">GSC Queries</th>';
    html += '<th class="ss-text-end">AI Overview</th>';
    html += '</tr></thead><tbody>';

    sorted.forEach(function (snap, i) {
      var rank = snap.post_rank !== null ? parseFloat(snap.post_rank) : null;

      // Calculate change from previous snapshot
      var change = "";
      if (i > 0 && rank !== null) {
        var prevRank = sorted[i - 1].post_rank !== null ? parseFloat(sorted[i - 1].post_rank) : null;
        if (prevRank !== null) {
          var diff = rank - prevRank;
          if (diff < 0)      change = '<span class="ss-rank-up">\u25B2' + Math.abs(diff) + '</span>';
          else if (diff > 0) change = '<span class="ss-rank-down">\u25BC' + diff + '</span>';
          else               change = '<span class="ss-rank-flat">\u25C6</span>';
        }
      }

      html += '<tr>';
      html += '<td>' + esc(snap.snapshot_date) + '</td>';
      html += '<td class="ss-text-end">' + (rank !== null ? rankBadge(rank) : '<span class="ss-text-muted">\u2014</span>') + '</td>';
      html += '<td class="ss-text-end">' + (change || '<span class="ss-text-muted">\u2014</span>') + '</td>';
      html += '<td class="ss-text-end">' + num(snap.impressions) + '</td>';
      html += '<td class="ss-text-end">' + num(snap.clicks) + '</td>';
      html += '<td class="ss-text-end">' + (parseFloat(snap.ctr || 0).toFixed(1)) + '%</td>';
      html += '<td class="ss-text-end">' + num(snap.gsc_total) + '</td>';
      html += '<td class="ss-text-end">' + (parseInt(snap.ai_count) > 0 ? '<span class="ss-ai-tag">' + snap.ai_count + '</span>' : '<span class="ss-text-muted">\u2014</span>') + '</td>';
      html += '</tr>';
    });

    html += '</tbody></table></div>';
    return html;
  }

  /* ══════════════════════════════════════════════════════════════════
   * LOAD FROM DB
   * ══════════════════════════════════════════════════════════════════ */
  async function loadDashboard() {
    try {
      var j = await post("myls_sd_db_load", { post_type: "all" });
      if (j.success && j.data) {
        allRows = j.data.rows || [];
        renderKPIs(j.data.stats || {});
        updatePTFilter(allRows);
        renderTable();
        setBtns(false);
      }
    } catch (e) { console.error("SS load:", e); }
  }

  /* ══════════════════════════════════════════════════════════════════
   * SCAN KEYWORDS
   * ══════════════════════════════════════════════════════════════════ */
  async function doScan() {
    STOP = false;
    setBtns(true);
    setPhase("Scan");
    show(el("ss_prog_wrap"), false);

    try {
      var j = await post("myls_sd_db_scan", { post_type: activePT });
      if (j.success && j.data) {
        allRows = j.data.rows || [];
        renderKPIs(j.data.stats || {});
        updatePTFilter(allRows);
        renderTable();
      } else {
        alert(j.data && j.data.message ? j.data.message : "Scan failed.");
      }
    } catch (e) { alert("Error: " + e.message); }

    setPhase("");
    setBtns(false);
  }

  /* ══════════════════════════════════════════════════════════════════
   * REFRESH AC
   * ══════════════════════════════════════════════════════════════════ */
  async function doAC() {
    if (!allRows.length) return;
    STOP = false;
    setBtns(true);
    setPhase("AC");
    show(el("ss_prog_wrap"), true);
    await acLoop(getFiltered());
    await loadDashboard();
    show(el("ss_prog_wrap"), false);
    setPhase("");
    setBtns(false);
  }

  /* ══════════════════════════════════════════════════════════════════
   * REFRESH GSC + RANK + AI OVERVIEW
   * ══════════════════════════════════════════════════════════════════ */
  async function doGSC() {
    if (!allRows.length) return;
    STOP = false;
    setBtns(true);
    setPhase("GSC");
    show(el("ss_prog_wrap"), true);
    await gscLoop(getFiltered());
    await loadDashboard();
    show(el("ss_prog_wrap"), false);
    setPhase("");
    setBtns(false);
  }

  /* ══════════════════════════════════════════════════════════════════
   * REFRESH ALL — Scan → AC → GSC in sequence
   * ══════════════════════════════════════════════════════════════════ */
  async function doRefreshAll() {
    STOP = false;
    setBtns(true);

    // Step 1: Scan
    setPhase("Scan");
    show(el("ss_prog_wrap"), false);
    try {
      var j = await post("myls_sd_db_scan", { post_type: activePT });
      if (j.success && j.data) {
        allRows = j.data.rows || [];
        renderKPIs(j.data.stats || {});
        updatePTFilter(allRows);
        renderTable();
      }
    } catch (e) { /* continue */ }

    if (STOP) { finish(); return; }

    // Step 2: AC
    setPhase("AC");
    show(el("ss_prog_wrap"), true);
    await acLoop(getFiltered());

    if (STOP) { finish(); return; }

    // Step 3: GSC + Rank (only if connected)
    if (CFG.gsc_connected) {
      setPhase("GSC");
      await gscLoop(getFiltered());
    }

    finish();
  }

  /* ── AC loop ── */
  async function acLoop(rows) {
    var total = rows.length, done = 0;

    for (var i = 0; i < rows.length; i++) {
      if (STOP) break;
      var row = rows[i];
      done++;
      setProgress(done, total, "AC — " + row.keyword);
      highlightRow(row.id, true);

      var allSugs = [], groups = [], seen = {};

      for (var qi = 0; qi < QTYPES.length; qi++) {
        if (STOP) break;
        var qt = QTYPES[qi];
        var query = qt.prefix + row.keyword + qt.suffix;
        var sugs = [];
        try {
          var j = await post("myls_faq_search_check_single_v1", { question: query });
          if (j.success && j.data && j.data.suggestions) sugs = j.data.suggestions;
        } catch (e) { /* skip */ }

        var unique = [];
        sugs.forEach(function (s) {
          var k = s.toLowerCase().trim();
          if (!seen[k]) { seen[k] = true; unique.push(s); allSugs.push(s); }
        });
        groups.push({ id: qt.id, label: qt.id.charAt(0).toUpperCase() + qt.id.slice(1), suggestions: unique });

        if (qi < QTYPES.length - 1 && !STOP) await sleep(300);
      }

      try {
        await post("myls_sd_db_save_ac", {
          row_id: row.id,
          ac_suggestions: JSON.stringify(groups),
          ac_count: allSugs.length
        });
      } catch (e) { /* skip */ }

      row.ac_suggestions = groups;
      row.ac_count = allSugs.length;
      row.ac_refreshed_at = nowStr();

      highlightRow(row.id, false);
      if (i < rows.length - 1 && !STOP) await sleep(500);
    }
  }

  /* ── GSC loop (includes per-post rank) ── */
  async function gscLoop(rows) {
    var days = el("ss_gsc_days") ? el("ss_gsc_days").value : "90";
    var total = rows.length, done = 0;

    for (var i = 0; i < rows.length; i++) {
      if (STOP) break;
      var row = rows[i];
      done++;
      setProgress(done, total, "GSC — " + row.keyword);
      highlightRow(row.id, true);

      var gscRows = [], aiOverview = {}, postRank = null, postRankData = null;
      try {
        var j = await post("myls_sd_gsc_query_v1", {
          keyword: row.keyword,
          days:    days,
          post_id: row.post_id
        });
        if (j.success && j.data) {
          gscRows      = j.data.rows || [];
          aiOverview   = j.data.ai_overview || {};
          postRank     = j.data.post_rank;
          postRankData = j.data.post_rank_data;
        }
      } catch (e) { /* skip */ }

      try {
        await post("myls_sd_db_save_gsc", {
          row_id:         row.id,
          gsc_data:       JSON.stringify(gscRows),
          gsc_total:      gscRows.length,
          ai_overview:    JSON.stringify(aiOverview),
          ai_count:       Object.keys(aiOverview).length,
          days:           days,
          post_rank:      postRank !== null ? postRank : "",
          post_rank_data: postRankData ? JSON.stringify(postRankData) : ""
        });
      } catch (e) { /* skip */ }

      row.gsc_data         = gscRows;
      row.gsc_total        = gscRows.length;
      row.ai_overview      = aiOverview;
      row.ai_count         = Object.keys(aiOverview).length;
      row.post_rank        = postRank;
      row.post_rank_data   = postRankData;
      row.gsc_refreshed_at = nowStr();

      highlightRow(row.id, false);

      // 1s between GSC calls (3 API calls per keyword — sitewide, AI overview, per-post rank)
      if (i < rows.length - 1 && !STOP) await sleep(1000);
    }
  }

  function finish() {
    loadDashboard();
    show(el("ss_prog_wrap"), false);
    setPhase("");
    setBtns(false);
  }

  /* ── Utilities ── */
  function getFiltered() {
    return activePT === "all" ? allRows : allRows.filter(function (r) { return r.post_type === activePT; });
  }

  function highlightRow(id, on) {
    var tr = el("ss_row_" + id);
    if (tr) tr.style.background = on ? "var(--ms-accent-light)" : "";
  }

  function nowStr() {
    return new Date().toISOString().replace("T", " ").substr(0, 19);
  }

  function doPrint() {
    document.querySelectorAll(".ss-detail-row").forEach(function (r) { r.style.display = "table-row"; });
    window.print();
  }

  /* ══════════════════════════════════════════════════════════════════
   * INIT
   * ══════════════════════════════════════════════════════════════════ */
  renderShell();
  loadDashboard();

})(jQuery);
