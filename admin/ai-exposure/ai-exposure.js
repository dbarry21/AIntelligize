/**
 * AIntelligize — AI Exposure Dashboard
 * ======================================
 * Standalone submenu page. Renders inside #myls-ai-exposure-root.
 * Checks whether ChatGPT and Claude cite/mention the user's domain
 * when asked about tracked keywords.
 *
 * Features: KPI cards, platform citation tracking, competitor detection,
 * expandable detail rows, trend history, competitor comparison, Chart.js charts.
 *
 * Config: window.MYLS_AE
 * @since 7.9.0
 */
(function ($) {
  "use strict";
  if (!window.MYLS_AE) return;

  var CFG  = window.MYLS_AE;
  var ROOT = document.getElementById("myls-ai-exposure-root");
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
  var allResults   = [];
  var keywords     = [];
  var stats        = {};
  var competitors  = { manual: [], auto_detected: {} };
  var currentView  = "results";
  var STOP         = false;
  var charts       = {};
  var sourceFilter = "all";
  var selected     = {};  // keyword → true

  /* ══════════════════════════════════════════════════════════════════
   * RENDER SHELL
   * ══════════════════════════════════════════════════════════════════ */
  function renderShell() {
    var today = new Date().toLocaleDateString("en-US", { weekday: "long", year: "numeric", month: "long", day: "numeric" });

    ROOT.innerHTML = [
      '<div class="ms-dashboard">',

      // Header
      '<div class="ms-header">',
      '  <div>',
      '    <h1><span class="ms-logo">🌐</span> AI Exposure</h1>',
      '    <div class="ms-subtitle">Chatbot Visibility &amp; Citation Tracking &middot; ' + today + '</div>',
      '  </div>',
      '</div>',

      // KPI Grid
      '<div class="ms-kpi-grid" style="--ms-kpi-cols:6;" id="ae_kpi_grid">',
        kpiCard("Total Citations", "—", "🏆", ""),
        kpiCard("Platforms Active", "—", "🌐", ""),
        kpiCard("Exposure Score", "—", "📊", ""),
        kpiCard("Avg Position", "—", "📍", ""),
        kpiCard("Keywords Checked", "—", "🔑", ""),
        kpiCard("Last Checked", "—", "🔍", ""),
      '</div>',

      // Tabs
      '<div class="ae-tabs" id="ae_tabs">',
        '<button class="ae-tab active" data-view="results">Results</button>',
        '<button class="ae-tab" data-view="competitors">Competitors</button>',
        '<button class="ae-tab" data-view="trends">Trends</button>',
      '</div>',

      // Action bar
      '<div class="ae-actions" id="ae_action_bar">',
        '<select class="ae-source-filter" id="ae_source_filter"><option value="all">All Sources</option></select>',
        '<button class="button button-primary" id="ae_btn_check_all"><i class="bi bi-arrow-repeat"></i> Check All</button>',
        '<button class="button" id="ae_btn_check_chatgpt"><i class="bi bi-chat-dots"></i> Check ChatGPT</button>',
        '<button class="button" id="ae_btn_check_claude"><i class="bi bi-robot"></i> Check Claude</button>',
        '<span class="ae-sep"></span>',
        '<button class="button" id="ae_btn_add_kw"><i class="bi bi-plus-circle"></i> Add Keyword</button>',
        '<span class="ae-right">',
          '<span class="ae-selection-info" id="ae_sel_info"></span>',
          '<span class="ae-cost-estimate" id="ae_cost_est"></span>',
          '<span id="ae_phase_badge"></span>',
          '<button class="button" id="ae_btn_stop" style="display:none;color:#d63638;"><i class="bi bi-stop-circle"></i> Stop</button>',
        '</span>',
      '</div>',

      // Add keyword inline form (hidden)
      '<div class="ae-add-kw-wrap" id="ae_add_kw_wrap" style="display:none;">',
        '<input type="text" id="ae_add_kw_input" placeholder="Enter keyword to track..." />',
        '<button class="button button-primary" id="ae_add_kw_save">Add</button>',
        '<button class="button" id="ae_add_kw_cancel">Cancel</button>',
      '</div>',

      // Progress bar (hidden)
      '<div class="ae-progress-wrap" id="ae_prog_wrap" style="display:none;">',
        '<div class="ae-progress-bar"><div class="ae-progress-fill" id="ae_prog_fill"></div></div>',
        '<div class="ae-progress-text" id="ae_prog_text">Starting...</div>',
      '</div>',

      // Content
      '<div id="ae_content">',
        '<div class="ms-loading" id="ae_loading">',
        '  <div class="ms-spinner"></div>',
        '  <div>Loading AI Exposure data&hellip;</div>',
        '</div>',
      '</div>',

      '</div>',
    ].join("\n");

    bindEvents();
  }

  /* ══════════════════════════════════════════════════════════════════
   * EVENT BINDING
   * ══════════════════════════════════════════════════════════════════ */
  function bindEvents() {
    // Tabs
    $(ROOT).on("click", ".ae-tab", function () {
      $(".ae-tab").removeClass("active");
      $(this).addClass("active");
      currentView = $(this).data("view");
      renderCurrentView();
    });

    // Source filter
    $(ROOT).on("change", "#ae_source_filter", function () {
      sourceFilter = $(this).val();
      selected = {};
      renderCurrentView();
    });

    // Checkbox selection
    $(ROOT).on("change", "#ae_select_all", function () {
      var checked = this.checked;
      var visible = getVisibleKeywords();
      selected = {};
      if (checked) {
        for (var i = 0; i < visible.length; i++) selected[visible[i].keyword] = true;
      }
      $(".ae-row-cb").prop("checked", checked);
      updateSelectionInfo();
    });
    $(ROOT).on("change", ".ae-row-cb", function () {
      var kw = $(this).data("keyword");
      if (this.checked) selected[kw] = true;
      else delete selected[kw];
      // Update header checkbox state
      var visible = getVisibleKeywords();
      var allChecked = visible.length > 0 && Object.keys(selected).length === visible.length;
      $("#ae_select_all").prop("checked", allChecked);
      updateSelectionInfo();
    });

    // Action buttons
    $(ROOT).on("click", "#ae_btn_check_all", function () { doCheckAll(); });
    $(ROOT).on("click", "#ae_btn_check_chatgpt", function () { doCheckPlatform("chatgpt"); });
    $(ROOT).on("click", "#ae_btn_check_claude", function () { doCheckPlatform("claude"); });
    $(ROOT).on("click", "#ae_btn_stop", function () { STOP = true; });

    // Add keyword
    $(ROOT).on("click", "#ae_btn_add_kw", function () {
      show(el("ae_add_kw_wrap"), true);
      el("ae_add_kw_input").focus();
    });
    $(ROOT).on("click", "#ae_add_kw_cancel", function () {
      show(el("ae_add_kw_wrap"), false);
      el("ae_add_kw_input").value = "";
    });
    $(ROOT).on("click", "#ae_add_kw_save", doAddKeyword);
    $(ROOT).on("keydown", "#ae_add_kw_input", function (e) {
      if (e.key === "Enter") doAddKeyword();
      if (e.key === "Escape") { show(el("ae_add_kw_wrap"), false); el("ae_add_kw_input").value = ""; }
    });

    // Expand detail rows
    $(ROOT).on("click", ".ae-expand-link", function () {
      var kw = $(this).data("keyword");
      var row = el("ae_detail_" + hashKw(kw));
      if (row) {
        $(row).toggleClass("ae-show");
        $(this).text($(row).hasClass("ae-show") ? "▾" : "▸");
      }
    });

    // History button
    $(ROOT).on("click", ".ae-history-btn", function () {
      var kw = $(this).data("keyword");
      var panel = el("ae_hist_" + hashKw(kw));
      if (panel && panel.innerHTML) {
        $(panel).toggle();
        return;
      }
      loadHistory(kw);
    });

    // Delete custom keyword
    $(ROOT).on("click", ".ae-delete-kw", function () {
      var kw = $(this).data("keyword");
      if (confirm("Remove custom keyword \"" + kw + "\" from tracking?")) {
        post("myls_ae_delete_keyword", { keyword: kw }).then(function () {
          loadDashboard();
        });
      }
    });

    // Competitor management
    $(ROOT).on("click", "#ae_comp_save", doSaveCompetitors);
    $(ROOT).on("click", ".ae-comp-remove", function () {
      $(this).closest(".ae-comp-tag").remove();
    });
  }

  /* ══════════════════════════════════════════════════════════════════
   * KPI RENDERING
   * ══════════════════════════════════════════════════════════════════ */
  function renderKPIs() {
    var st = stats;
    var grid = el("ae_kpi_grid");
    if (!grid) return;

    var totalCitations = parseInt(st.total_citations) || 0;
    var platforms = parseInt(st.platforms_active) || 0;
    var score = parseFloat(st.exposure_score) || 0;
    var avgPos = st.avg_position ? parseFloat(st.avg_position).toFixed(1) : "N/A";
    var totalKw = parseInt(st.total_keywords) || 0;
    var lastChecked = st.last_checked || null;

    grid.innerHTML = [
      kpiCard("Total Citations", num(totalCitations), "🏆", totalCitations > 0 ? "Across all platforms" : "No citations yet"),
      kpiCard("Platforms Active", platforms + " / 2", "🌐", "ChatGPT + Claude"),
      kpiCard("Exposure Score", score.toFixed(0) + "%", "📊", scoreBadge(score)),
      kpiCard("Avg Position", avgPos, "📍", avgPos !== "N/A" ? "When cited as source" : ""),
      kpiCard("Keywords Checked", num(totalKw), "🔑", "Unique keywords"),
      kpiCard("Last Checked", timeAgo(lastChecked), "🔍", lastChecked ? shortDate(lastChecked) : "Never"),
    ].join("");
  }

  /* ══════════════════════════════════════════════════════════════════
   * VIEW DISPATCH
   * ══════════════════════════════════════════════════════════════════ */
  function renderCurrentView() {
    var ct = el("ae_content");
    if (!ct) return;

    // Show/hide action bar based on view
    show(el("ae_action_bar"), currentView === "results");

    switch (currentView) {
      case "results":     renderResults(ct);     break;
      case "competitors": renderCompetitors(ct); break;
      case "trends":      renderTrends(ct);      break;
    }
  }

  /* ══════════════════════════════════════════════════════════════════
   * RESULTS TAB
   * ══════════════════════════════════════════════════════════════════ */
  function getVisibleKeywords() {
    if (sourceFilter === "all") return keywords;
    return keywords.filter(function (kw) { return (kw.source || "Custom") === sourceFilter; });
  }

  function getCheckTargets() {
    var visible = getVisibleKeywords();
    var selKeys = Object.keys(selected);
    if (selKeys.length) {
      return visible.filter(function (kw) { return selected[kw.keyword]; });
    }
    return visible;
  }

  function populateSourceFilter() {
    var sel = el("ae_source_filter");
    if (!sel) return;
    var sources = {};
    for (var i = 0; i < keywords.length; i++) {
      var s = keywords[i].source || "Custom";
      sources[s] = (sources[s] || 0) + 1;
    }
    var html = '<option value="all">All Sources (' + keywords.length + ')</option>';
    var keys = Object.keys(sources).sort();
    for (var j = 0; j < keys.length; j++) {
      var s = keys[j];
      html += '<option value="' + esc(s) + '"' + (sourceFilter === s ? ' selected' : '') + '>' + esc(s) + ' (' + sources[s] + ')</option>';
    }
    sel.innerHTML = html;
  }

  function updateSelectionInfo() {
    var info = el("ae_sel_info");
    if (!info) return;
    var count = Object.keys(selected).length;
    info.textContent = count ? count + " selected" : "";
    setBtns(false);
    updateCostEstimate();
  }

  function renderResults(ct) {
    // Group results by keyword
    var grouped = groupByKeyword(allResults);
    var kwList = getVisibleKeywords();

    populateSourceFilter();

    if (!keywords.length) {
      ct.innerHTML = emptyState("No keywords to check",
        "Scan keywords in Search Demand first, or add custom keywords using the button above.");
      return;
    }

    if (!kwList.length) {
      ct.innerHTML = emptyState("No keywords for this source",
        "Try selecting a different source filter or add custom keywords.");
      return;
    }

    var allChecked = kwList.length > 0 && Object.keys(selected).length === kwList.length;

    var html = '<div class="ms-card" style="padding:0;overflow:auto;">';
    html += '<table class="ae-table">';
    html += '<thead><tr>';
    html += '<th style="width:32px;"><input type="checkbox" id="ae_select_all"' + (allChecked ? ' checked' : '') + ' /></th>';
    html += '<th style="width:30px;">#</th>';
    html += '<th>Keyword</th>';
    html += '<th>Source</th>';
    html += '<th class="ae-text-center">ChatGPT</th>';
    html += '<th class="ae-text-center">Claude</th>';
    html += '<th class="ae-text-center">Competitors</th>';
    html += '<th>Last Checked</th>';
    html += '<th style="width:30px;"></th>';
    html += '</tr></thead><tbody>';

    for (var i = 0; i < kwList.length; i++) {
      var kw = kwList[i];
      var kwData = grouped[kw.keyword] || {};
      var chatgpt = kwData.chatgpt || null;
      var claude = kwData.claude || null;
      var hash = hashKw(kw.keyword);
      var compCount = 0;
      if (chatgpt) compCount += (chatgpt.competitor_domains || []).length;
      if (claude) compCount += (claude.competitor_domains || []).length;
      var lastCheck = chatgpt ? chatgpt.checked_at : (claude ? claude.checked_at : null);
      var isChecked = !!selected[kw.keyword];

      html += '<tr id="ae_row_' + hash + '">';
      html += '<td><input type="checkbox" class="ae-row-cb" data-keyword="' + esc(kw.keyword) + '"' + (isChecked ? ' checked' : '') + ' /></td>';
      html += '<td class="ae-muted">' + (i + 1) + '</td>';
      html += '<td class="ae-fw-semi">' + esc(kw.keyword);
      if (kw.custom) html += ' <span class="ae-cited-na" style="font-size:9px;">CUSTOM</span>';
      if (kw.custom) html += ' <span class="ae-delete-kw" data-keyword="' + esc(kw.keyword) + '" style="cursor:pointer;color:#d63638;font-size:11px;margin-left:4px;" title="Remove">✕</span>';
      html += '</td>';
      html += '<td class="ae-small ae-muted">' + esc(kw.source || "Custom") + '</td>';
      html += '<td class="ae-text-center">' + citedBadge(chatgpt) + '</td>';
      html += '<td class="ae-text-center">' + citedBadge(claude) + '</td>';
      html += '<td class="ae-text-center">' + (compCount > 0 ? '<span class="ae-fw-semi">' + compCount + '</span>' : '<span class="ae-muted">—</span>') + '</td>';
      html += '<td class="ae-small ae-muted">' + (lastCheck ? timeAgo(lastCheck) : "—") + '</td>';
      html += '<td><span class="ae-expand-link" data-keyword="' + esc(kw.keyword) + '">▸</span></td>';
      html += '</tr>';

      // Detail row
      html += '<tr class="ae-detail-row" id="ae_detail_' + hash + '"><td colspan="9">';
      html += '<div class="ae-detail-inner">';
      html += renderDetailContent(kw, chatgpt, claude);
      html += '</div></td></tr>';
    }

    html += '</tbody></table></div>';

    // Cost estimate
    updateCostEstimate();

    ct.innerHTML = html;
  }

  function renderDetailContent(kw, chatgpt, claude) {
    var html = '';

    // Platform results side by side
    var platforms = [
      { label: "ChatGPT", data: chatgpt, badgeClass: "ae-badge-chatgpt" },
      { label: "Claude", data: claude, badgeClass: "ae-badge-claude" },
    ];

    for (var p = 0; p < platforms.length; p++) {
      var plat = platforms[p];
      var d = plat.data;
      html += '<div class="ae-detail-section">';
      html += '<h4><span class="' + plat.badgeClass + '">' + plat.label + '</span>';
      if (d) {
        html += ' &mdash; ' + citedBadge(d);
        if (d.source_position) html += ' <span class="ae-position">#' + d.source_position + '</span>';
        html += ' <span class="ae-small ae-muted">(' + esc(d.model_used || "") + ')</span>';
      }
      html += '</h4>';

      if (!d) {
        html += '<p class="ae-muted ae-small">Not checked yet.</p>';
      } else if (d.error_message) {
        html += '<p style="color:var(--ms-red);">Error: ' + esc(d.error_message) + '</p>';
      } else {
        // Response excerpt
        if (d.response_text) {
          html += '<div class="ae-response-excerpt">' + esc(d.response_text) + '</div>';
        }

        // Citations found
        var cites = d.citations_found || [];
        if (cites.length) {
          html += '<strong class="ae-small">URLs found (' + cites.length + '):</strong>';
          html += '<ul class="ae-citation-list">';
          for (var c = 0; c < cites.length; c++) {
            var url = cites[c];
            var cls = "ae-neutral";
            if (urlMatchesDomain(url, CFG.site_domain)) cls = "ae-own-domain";
            else if (isCompetitor(url)) cls = "ae-competitor";
            html += '<li class="' + cls + '">' + esc(url) + '</li>';
          }
          html += '</ul>';
        }

        // Competitor domains
        var comps = d.competitor_domains || [];
        if (comps.length) {
          html += '<strong class="ae-small">Competitor domains (' + comps.length + '):</strong> ';
          for (var j = 0; j < comps.length; j++) {
            html += '<span class="ae-comp-tag ae-is-auto">' + esc(comps[j]) + '</span>';
          }
        }
      }
      html += '</div>';
    }

    // History link
    html += '<div style="margin-top:8px;">';
    html += '<span class="ae-history-btn" data-keyword="' + esc(kw.keyword) + '"><i class="bi bi-clock-history"></i> View History</span>';
    html += '<div id="ae_hist_' + hashKw(kw.keyword) + '" style="display:none;"></div>';
    html += '</div>';

    return html;
  }

  /* ══════════════════════════════════════════════════════════════════
   * COMPETITORS TAB
   * ══════════════════════════════════════════════════════════════════ */
  function renderCompetitors(ct) {
    var html = '';

    // Manual competitors management
    html += '<div class="ms-card">';
    html += '<h3 style="margin-top:0;">Manual Competitor Domains</h3>';
    html += '<p class="ae-small ae-muted">Add competitor domains to flag when they appear in AI responses alongside your site.</p>';
    html += '<div class="ae-comp-input-wrap">';
    html += '<input type="text" id="ae_comp_input" placeholder="competitor.com" />';
    html += '<button class="button button-primary" id="ae_comp_add">Add</button>';
    html += '</div>';
    html += '<div id="ae_comp_manual_tags">';
    var manual = competitors.manual || [];
    for (var i = 0; i < manual.length; i++) {
      html += compTag(manual[i], true);
    }
    if (!manual.length) html += '<span class="ae-muted ae-small">No manual competitors added yet.</span>';
    html += '</div>';
    html += '<div style="margin-top:12px;">';
    html += '<button class="button button-primary" id="ae_comp_save"><i class="bi bi-check-lg"></i> Save Competitors</button>';
    html += '</div>';
    html += '</div>';

    // Auto-detected competitors
    html += '<div class="ms-card" style="margin-top:16px;">';
    html += '<h3 style="margin-top:0;">Auto-Detected Competitors</h3>';
    html += '<p class="ae-small ae-muted">Domains that appeared in AI responses when checking your keywords (last 30 days).</p>';
    var auto = competitors.auto_detected || {};
    var autoKeys = Object.keys(auto).sort(function (a, b) { return auto[b] - auto[a]; });

    if (autoKeys.length) {
      html += '<table class="ae-table"><thead><tr>';
      html += '<th>Domain</th><th class="ae-text-end">Mentions</th><th>Status</th>';
      html += '</tr></thead><tbody>';
      for (var j = 0; j < autoKeys.length; j++) {
        var d = autoKeys[j];
        var isManual = manual.indexOf(d) !== -1;
        html += '<tr>';
        html += '<td style="font-family:monospace;font-size:12px;">' + esc(d) + '</td>';
        html += '<td class="ae-text-end ae-fw-semi">' + auto[d] + '</td>';
        html += '<td>' + (isManual ? '<span class="ae-cited-yes">Tracked</span>' : '<span class="ae-cited-na">Auto</span>') + '</td>';
        html += '</tr>';
      }
      html += '</tbody></table>';
    } else {
      html += '<p class="ae-muted">No competitor domains detected yet. Run AI checks to discover competitors.</p>';
    }
    html += '</div>';

    ct.innerHTML = html;

    // Bind add competitor
    $(ROOT).off("click", "#ae_comp_add").on("click", "#ae_comp_add", function () {
      var input = el("ae_comp_input");
      var val = (input.value || "").trim().toLowerCase();
      if (!val) return;
      var tags = el("ae_comp_manual_tags");
      // Check if already exists
      if (tags.querySelector('[data-domain="' + val + '"]')) return;
      tags.innerHTML += compTag(val, true);
      input.value = "";
      input.focus();
    });
  }

  function compTag(domain, isManual) {
    return '<span class="ae-comp-tag ' + (isManual ? "ae-is-manual" : "ae-is-auto") + '" data-domain="' + esc(domain) + '">'
         + esc(domain) + ' <span class="ae-comp-remove">&times;</span></span>';
  }

  function doSaveCompetitors() {
    var tags = document.querySelectorAll("#ae_comp_manual_tags .ae-comp-tag");
    var domains = [];
    tags.forEach(function (t) { domains.push(t.getAttribute("data-domain")); });
    post("myls_ae_save_competitors", { domains: JSON.stringify(domains) }).then(function (r) {
      if (r.success) {
        competitors.manual = r.data.domains || [];
        alert("Competitors saved.");
      }
    });
  }

  /* ══════════════════════════════════════════════════════════════════
   * TRENDS TAB
   * ══════════════════════════════════════════════════════════════════ */
  function renderTrends(ct) {
    if (!allResults.length) {
      ct.innerHTML = emptyState("No data yet", "Run AI checks first to build trend data.");
      return;
    }

    var html = '';

    // Trend chart
    html += '<div class="ms-card">';
    html += '<h3 style="margin-top:0;">Citation Trend</h3>';
    html += '<div class="ae-chart-container"><canvas id="ae_chart_trend"></canvas></div>';
    html += '</div>';

    // Per-keyword summary table
    html += '<div class="ms-card" style="margin-top:16px;">';
    html += '<h3 style="margin-top:0;">Per-Keyword Summary</h3>';
    html += '<table class="ae-table"><thead><tr>';
    html += '<th>Keyword</th><th class="ae-text-center">ChatGPT</th><th class="ae-text-center">Claude</th>';
    html += '<th class="ae-text-center">Score</th><th class="ae-text-center">Checks</th>';
    html += '</tr></thead><tbody>';

    var grouped = groupByKeyword(allResults);
    var kwNames = Object.keys(grouped).sort();
    for (var i = 0; i < kwNames.length; i++) {
      var kw = kwNames[i];
      var g = grouped[kw];
      var chatgpt = g.chatgpt;
      var claude = g.claude;
      var checks = (chatgpt ? 1 : 0) + (claude ? 1 : 0);
      var cited = (chatgpt && chatgpt.cited ? 1 : 0) + (claude && claude.cited ? 1 : 0);
      var score = checks > 0 ? Math.round((cited / checks) * 100) : 0;

      html += '<tr>';
      html += '<td class="ae-fw-semi">' + esc(kw) + '</td>';
      html += '<td class="ae-text-center">' + citedBadge(chatgpt) + '</td>';
      html += '<td class="ae-text-center">' + citedBadge(claude) + '</td>';
      html += '<td class="ae-text-center">' + scoreBadge(score) + '</td>';
      html += '<td class="ae-text-center ae-muted">' + checks + '</td>';
      html += '</tr>';
    }
    html += '</tbody></table></div>';

    ct.innerHTML = html;

    // Render chart
    renderTrendChart();
  }

  function renderTrendChart() {
    var canvas = document.getElementById("ae_chart_trend");
    if (!canvas) return;

    // Build daily data from results (group by date)
    var dayMap = {};
    for (var i = 0; i < allResults.length; i++) {
      var r = allResults[i];
      var day = (r.checked_at || "").substring(0, 10);
      if (!day) continue;
      if (!dayMap[day]) dayMap[day] = { citations: 0, checks: 0 };
      dayMap[day].checks++;
      if (r.cited) dayMap[day].citations++;
    }

    var days = Object.keys(dayMap).sort();
    var citations = days.map(function (d) { return dayMap[d].citations; });
    var checks = days.map(function (d) { return dayMap[d].checks; });
    var labels = days.map(function (d) { return d.substring(5); }); // "MM-DD"

    if (charts.trend) charts.trend.destroy();

    charts.trend = new Chart(canvas, {
      type: "line",
      data: {
        labels: labels,
        datasets: [
          {
            label: "Citations",
            data: citations,
            borderColor: "#00a32a",
            backgroundColor: "rgba(0,163,42,0.1)",
            fill: true,
            tension: 0.3,
          },
          {
            label: "Total Checks",
            data: checks,
            borderColor: "#2271b1",
            backgroundColor: "rgba(34,113,177,0.1)",
            fill: true,
            tension: 0.3,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: "top" } },
        scales: {
          y: { beginAtZero: true, ticks: { stepSize: 1 } },
        },
      },
    });
  }

  /* ══════════════════════════════════════════════════════════════════
   * CHECKING LOOPS
   * ══════════════════════════════════════════════════════════════════ */
  async function doCheckAll() {
    var targets = getCheckTargets();
    if (!targets.length) { alert("No keywords to check. Adjust your filter or selection."); return; }

    STOP = false;
    setBtns(true);
    show(el("ae_prog_wrap"), true);

    // ChatGPT first
    if (CFG.has_openai) {
      setPhase("chatgpt");
      await checkLoop("chatgpt", targets);
      if (STOP) { finish(); return; }
    }

    // Then Claude
    if (CFG.has_anthropic) {
      setPhase("claude");
      await checkLoop("claude", targets);
    }

    finish();
  }

  async function doCheckPlatform(platform) {
    var targets = getCheckTargets();
    if (!targets.length) { alert("No keywords to check. Adjust your filter or selection."); return; }

    STOP = false;
    setBtns(true);
    show(el("ae_prog_wrap"), true);
    setPhase(platform);
    await checkLoop(platform, targets);
    finish();
  }

  async function checkLoop(platform, kws) {
    var total = kws.length;
    var action = platform === "chatgpt" ? "myls_ae_check_chatgpt" : "myls_ae_check_claude";
    var delay = platform === "chatgpt" ? 2000 : 1000;

    for (var i = 0; i < kws.length; i++) {
      if (STOP) break;
      var kw = kws[i];
      setProgress(i + 1, total, platform.toUpperCase() + " — " + kw.keyword);
      highlightRow(kw.keyword, true);

      try {
        var result = await post(action, {
          keyword: kw.keyword,
          sd_id:   kw.sd_id || "",
          post_id: kw.post_id || 0,
        });
        // result logged server-side
      } catch (e) {
        // skip individual errors
      }

      highlightRow(kw.keyword, false);

      if (i < kws.length - 1 && !STOP) {
        await sleep(delay);
      }
    }
  }

  function finish() {
    setBtns(false);
    show(el("ae_prog_wrap"), false);
    el("ae_phase_badge").innerHTML = "";
    loadDashboard();
  }

  /* ══════════════════════════════════════════════════════════════════
   * KEYWORD MANAGEMENT
   * ══════════════════════════════════════════════════════════════════ */
  function doAddKeyword() {
    var input = el("ae_add_kw_input");
    var kw = (input.value || "").trim();
    if (!kw) return;

    post("myls_ae_add_keyword", { keyword: kw }).then(function (r) {
      if (r.success) {
        input.value = "";
        show(el("ae_add_kw_wrap"), false);
        loadDashboard();
      }
    });
  }

  /* ══════════════════════════════════════════════════════════════════
   * HISTORY
   * ══════════════════════════════════════════════════════════════════ */
  function loadHistory(keyword) {
    var panel = el("ae_hist_" + hashKw(keyword));
    if (!panel) return;

    panel.innerHTML = '<span class="ae-muted ae-small">Loading history...</span>';
    $(panel).show();

    post("myls_ae_history", { keyword: keyword }).then(function (r) {
      if (!r.success || !r.data.history || !r.data.history.length) {
        panel.innerHTML = '<span class="ae-muted ae-small">No history snapshots yet.</span>';
        return;
      }

      var rows = r.data.history;
      var html = '<div class="ae-history-panel">';
      html += '<table class="ae-history-table"><thead><tr>';
      html += '<th>Date</th><th>ChatGPT</th><th>Claude</th><th>Score</th><th>Competitors</th>';
      html += '</tr></thead><tbody>';

      for (var i = 0; i < rows.length; i++) {
        var h = rows[i];
        html += '<tr>';
        html += '<td>' + esc(h.snapshot_date) + '</td>';
        html += '<td>' + (h.chatgpt_cited === null ? '—' : (parseInt(h.chatgpt_cited) ? '<span class="ae-cited-yes">Yes</span>' : '<span class="ae-cited-no">No</span>')) + '</td>';
        html += '<td>' + (h.claude_cited === null ? '—' : (parseInt(h.claude_cited) ? '<span class="ae-cited-yes">Yes</span>' : '<span class="ae-cited-no">No</span>')) + '</td>';
        html += '<td>' + (h.exposure_score !== null ? h.exposure_score + '%' : '—') + '</td>';
        html += '<td>' + (parseInt(h.competitor_count) || 0) + '</td>';
        html += '</tr>';
      }
      html += '</tbody></table></div>';
      panel.innerHTML = html;
    });
  }

  /* ══════════════════════════════════════════════════════════════════
   * DATA LOADING
   * ══════════════════════════════════════════════════════════════════ */
  async function loadDashboard() {
    show(el("ae_loading"), true);

    try {
      var results = await Promise.all([
        post("myls_ae_load", { days: 30 }),
        post("myls_ae_get_keywords", {}),
        post("myls_ae_get_competitors", { days: 30 }),
      ]);

      var loadRes = results[0];
      var kwRes   = results[1];
      var compRes = results[2];

      if (loadRes.success) {
        allResults = loadRes.data.rows || [];
        stats = loadRes.data.stats || {};
      }
      if (kwRes.success) {
        keywords = kwRes.data.keywords || [];
      }
      if (compRes.success) {
        competitors = compRes.data || { manual: [], auto_detected: {} };
      }
    } catch (e) {
      // graceful degradation
    }

    show(el("ae_loading"), false);
    renderKPIs();
    populateSourceFilter();
    updateSelectionInfo();
    renderCurrentView();
  }

  /* ══════════════════════════════════════════════════════════════════
   * UI HELPERS
   * ══════════════════════════════════════════════════════════════════ */

  function kpiCard(label, value, icon, sub) {
    return '<div class="ms-kpi">'
      + '<div class="ms-kpi-icon">' + icon + '</div>'
      + '<div class="ms-kpi-label">' + esc(label) + '</div>'
      + '<div class="ms-kpi-value">' + value + '</div>'
      + '<div class="ms-kpi-sub">' + (sub || "") + '</div>'
      + '</div>';
  }

  function citedBadge(result) {
    if (!result) return '<span class="ae-cited-na">—</span>';
    if (result.error_message) return '<span class="ae-cited-na" title="' + esc(result.error_message) + '">Err</span>';
    if (result.cited) {
      var html = '<span class="ae-cited-yes">Cited</span>';
      if (result.source_position) html += ' <span class="ae-position">#' + result.source_position + '</span>';
      return html;
    }
    return '<span class="ae-cited-no">Not cited</span>';
  }

  function scoreBadge(score) {
    score = parseFloat(score) || 0;
    var cls = "ae-score-none";
    if (score >= 70) cls = "ae-score-high";
    else if (score >= 30) cls = "ae-score-med";
    else if (score > 0) cls = "ae-score-low";
    return '<span class="ae-score ' + cls + '">' + score.toFixed(0) + '%</span>';
  }

  function emptyState(title, desc) {
    return '<div class="ms-empty-state">'
      + '<div class="ms-empty-icon">🔍</div>'
      + '<div class="ms-empty-title">' + esc(title) + '</div>'
      + '<div class="ms-empty-desc">' + esc(desc) + '</div>'
      + '</div>';
  }

  function setProgress(done, total, text) {
    var fill = el("ae_prog_fill");
    var txt = el("ae_prog_text");
    if (fill) fill.style.width = Math.round((done / total) * 100) + "%";
    if (txt) txt.textContent = done + " / " + total + " — " + text;
  }

  function setPhase(platform) {
    var badge = el("ae_phase_badge");
    if (!badge) return;
    var cls = platform === "chatgpt" ? "ae-phase-chatgpt" : "ae-phase-claude";
    var label = platform === "chatgpt" ? "ChatGPT" : "Claude";
    badge.innerHTML = '<span class="ae-phase-badge ' + cls + '">' + label + '</span>';
  }

  function setBtns(running) {
    var btns = ["ae_btn_check_all", "ae_btn_check_chatgpt", "ae_btn_check_claude", "ae_btn_add_kw"];
    for (var i = 0; i < btns.length; i++) {
      var b = el(btns[i]);
      if (b) b.disabled = running;
    }
    show(el("ae_btn_stop"), running);

    // Update button labels based on selection
    if (!running) {
      var selCount = Object.keys(selected).length;
      var allBtn = el("ae_btn_check_all");
      var cgBtn = el("ae_btn_check_chatgpt");
      var clBtn = el("ae_btn_check_claude");
      if (allBtn) allBtn.innerHTML = '<i class="bi bi-arrow-repeat"></i> ' + (selCount ? 'Check Selected (' + selCount + ')' : 'Check All');
      if (cgBtn) cgBtn.innerHTML = '<i class="bi bi-chat-dots"></i> ' + (selCount ? 'ChatGPT (' + selCount + ')' : 'Check ChatGPT');
      if (clBtn) clBtn.innerHTML = '<i class="bi bi-robot"></i> ' + (selCount ? 'Claude (' + selCount + ')' : 'Check Claude');
    }

    // Disable unavailable platforms
    if (!CFG.has_openai) {
      var b1 = el("ae_btn_check_chatgpt");
      if (b1) { b1.disabled = true; b1.title = "OpenAI API key not configured"; }
    }
    if (!CFG.has_anthropic) {
      var b2 = el("ae_btn_check_claude");
      if (b2) { b2.disabled = true; b2.title = "Anthropic API key not configured"; }
    }
    if (!CFG.has_openai && !CFG.has_anthropic) {
      var b3 = el("ae_btn_check_all");
      if (b3) { b3.disabled = true; b3.title = "No API keys configured"; }
    }
  }

  function highlightRow(keyword, on) {
    var row = el("ae_row_" + hashKw(keyword));
    if (row) {
      if (on) row.classList.add("ae-highlight");
      else row.classList.remove("ae-highlight");
    }
  }

  function updateCostEstimate() {
    var est = el("ae_cost_est");
    if (!est) return;
    var targets = getCheckTargets();
    var kwCount = targets.length;
    if (!kwCount) { est.textContent = ""; return; }

    // gpt-4o-mini ~$0.001/check, claude-haiku ~$0.008/check
    var platforms = 0;
    if (CFG.has_openai) platforms++;
    if (CFG.has_anthropic) platforms++;
    var costPerKw = 0;
    if (CFG.has_openai) costPerKw += 0.001;
    if (CFG.has_anthropic) costPerKw += 0.008;
    var total = kwCount * costPerKw;
    est.textContent = "Est. cost: ~$" + total.toFixed(3) + " (" + kwCount + " keywords × " + platforms + " platforms)";
  }

  /* ── Grouping & Data Helpers ── */

  function groupByKeyword(results) {
    var map = {};
    for (var i = 0; i < results.length; i++) {
      var r = results[i];
      if (!map[r.keyword]) map[r.keyword] = {};
      map[r.keyword][r.platform] = r;
    }
    return map;
  }

  function urlMatchesDomain(url, domain) {
    try {
      var u = new URL(url);
      var host = u.hostname.replace(/^www\./, "").toLowerCase();
      return host === domain || host.indexOf(domain) !== -1;
    } catch (e) {
      return url.toLowerCase().indexOf(domain) !== -1;
    }
  }

  function isCompetitor(url) {
    var manual = competitors.manual || [];
    for (var i = 0; i < manual.length; i++) {
      if (url.toLowerCase().indexOf(manual[i]) !== -1) return true;
    }
    return false;
  }

  function hashKw(kw) {
    // Simple hash for element IDs
    var h = 0;
    for (var i = 0; i < kw.length; i++) {
      h = ((h << 5) - h) + kw.charCodeAt(i);
      h = h & h;
    }
    return Math.abs(h).toString(36);
  }

  function timeAgo(dt) {
    if (!dt) return "Never";
    var d = new Date(dt);
    var now = new Date();
    var diff = Math.floor((now - d) / 1000);
    if (diff < 60) return "Just now";
    if (diff < 3600) return Math.floor(diff / 60) + "m ago";
    if (diff < 86400) return Math.floor(diff / 3600) + "h ago";
    var days = Math.floor(diff / 86400);
    if (days === 1) return "Yesterday";
    if (days < 30) return days + "d ago";
    return Math.floor(days / 30) + "mo ago";
  }

  function shortDate(dt) {
    if (!dt) return "";
    var d = new Date(dt);
    return d.toLocaleDateString("en-US", { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" });
  }

  /* ══════════════════════════════════════════════════════════════════
   * INIT
   * ══════════════════════════════════════════════════════════════════ */
  renderShell();
  setBtns(false); // Disable unavailable platforms on load
  loadDashboard();

})(jQuery);
