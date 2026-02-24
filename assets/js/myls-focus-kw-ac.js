/* ========================================================================
 * MYLS – Focus Keyword AC Options JS
 * File: assets/js/myls-focus-kw-ac.js
 *
 * Three-step flow:
 *  1. Load focus keywords by post type
 *  2. Get AC suggestions (5 queries per keyword, progressive)
 *  3. Enrich with GSC data (impressions, clicks, CTR, position)
 *
 * Config: window.MYLS_FKAC
 * ======================================================================== */
(function () {
  "use strict";

  if (!window.MYLS_FKAC) return;
  var CFG = window.MYLS_FKAC;

  var $ = function (id) { return document.getElementById(id); };
  function esc(s) { var d = document.createElement("div"); d.textContent = s; return d.innerHTML; }
  function show(el, v) { if (el) el.style.display = v ? "" : "none"; }
  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function num(n) { return n >= 1000 ? (n / 1000).toFixed(1).replace(/\.0$/, "") + "k" : String(n); }

  function ajax(action, data) {
    var fd = new FormData();
    fd.append("action", action);
    fd.append("nonce", CFG.nonce);
    if (data) Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(CFG.ajaxurl, { method: "POST", body: fd }).then(function (r) { return r.json(); });
  }

  /* ── DOM refs ── */
  var elPT       = $("myls_fkac_pt");
  var btnLoad    = $("myls_fkac_load");
  var btnCheck   = $("myls_fkac_check");
  var btnStop    = $("myls_fkac_stop");
  var btnGSC     = $("myls_fkac_gsc");
  var btnGSCStop = $("myls_fkac_gsc_stop");
  var btnPrint   = $("myls_fkac_print");
  var elGSCDays  = $("myls_fkac_gsc_days");
  var elSpin     = $("myls_fkac_spinner");
  var elSpinTx   = $("myls_fkac_spinner_text");
  var elBadge    = $("myls_fkac_badge");
  var elPostCt   = $("myls_fkac_post_count");
  var elKwCt     = $("myls_fkac_kw_count");
  var elProgW    = $("myls_fkac_progress_wrap");
  var elProgB    = $("myls_fkac_progress_bar");
  var elProgT    = $("myls_fkac_progress_text");
  var elProgP    = $("myls_fkac_progress_pct");
  var elSummary  = $("myls_fkac_summary");
  var elResults  = $("myls_fkac_results");

  /* ── State ── */
  var kwQueue   = [];  // { keyword, post_title, post_id, post_type, source, rowId }
  var kwData    = {};  // rowId → { groups: [], allSugs: [], gscRows: [] }
  var STOP      = false;
  var STOP_GSC  = false;

  /* ── Query types ── */
  var QTYPES = [
    { id: "exact",    label: "Exact",    prefix: "",      suffix: ""  },
    { id: "expanded", label: "Expanded", prefix: "",      suffix: " " },
    { id: "how",      label: "How",      prefix: "how ",  suffix: ""  },
    { id: "what",     label: "What",     prefix: "what ", suffix: ""  },
    { id: "best",     label: "Best",     prefix: "best ", suffix: ""  }
  ];

  /* ── Badges ── */
  var PENDING_BADGE = '<span class="myls-sd-badge myls-sd-pending">\u23F3 Pending</span>';

  function checkingBadge(step) {
    return '<span class="myls-sd-cell-spinner"><span class="dashicons dashicons-update myls-spin"></span> Query ' + step + '/5</span>';
  }
  function gscCheckingBadge() {
    return '<span class="myls-sd-cell-spinner"><span class="dashicons dashicons-update myls-spin"></span> Querying GSC\u2026</span>';
  }
  function resultBadge(count) {
    if (count === 0) return '<span class="myls-sd-badge myls-sd-none">0 suggestions</span>';
    if (count < 5)   return '<span class="myls-sd-badge myls-sd-low">' + count + ' suggestions</span>';
    if (count < 15)  return '<span class="myls-sd-badge myls-sd-medium">' + count + ' suggestions</span>';
    return '<span class="myls-sd-badge myls-sd-high">' + count + ' suggestions</span>';
  }
  function errorBadge(msg) {
    return '<span class="myls-sd-badge myls-sd-error">\u26A0\uFE0F ' + (msg || "Error") + '</span>';
  }

  /* ── Progress helper ── */
  function setProgress(done, total, label) {
    var pct = total > 0 ? Math.round((done / total) * 100) : 0;
    if (elProgB) elProgB.style.width = pct + "%";
    if (elProgT) elProgT.textContent = done + " / " + total + (label ? " " + label : "");
    if (elProgP) elProgP.textContent = pct + "%";
  }

  /* ── Button state helper ── */
  function setBtns(phase) {
    // phases: idle, loaded, checking, ac-done, gsc-checking, gsc-done
    if (btnLoad)    btnLoad.disabled    = (phase === "checking" || phase === "gsc-checking");
    if (btnCheck)   btnCheck.disabled   = (phase !== "loaded" && phase !== "ac-done" && phase !== "gsc-done");
    if (btnStop)    btnStop.disabled    = (phase !== "checking");
    if (btnGSC)     btnGSC.disabled     = (phase !== "ac-done" && phase !== "gsc-done") || !CFG.gsc_connected;
    if (btnGSCStop) btnGSCStop.disabled = (phase !== "gsc-checking");
  }


  /* ══════════════════════════════════════════════════════════════════
   * STEP 1: Load all focus keywords
   * ══════════════════════════════════════════════════════════════════ */
  if (btnLoad) btnLoad.addEventListener("click", async function () {
    kwQueue = []; kwData = {};
    STOP = false; STOP_GSC = false;
    var pt = elPT ? elPT.value : "all";

    show(elSpin, true); if (elSpinTx) elSpinTx.textContent = "Loading focus keywords\u2026";
    setBtns("checking");
    show(elProgW, false);
    show(btnPrint, false);
    if (elSummary) elSummary.innerHTML = "";
    if (elResults) elResults.innerHTML = "";

    try {
      var j = await ajax(CFG.action_get_all_fk, { post_type: pt });

      if (!j.success || !j.data || !j.data.posts) {
        if (elResults) elResults.innerHTML = '<div class="notice notice-warning inline"><p>' + esc(j.data?.message || "No focus keywords found.") + '</p></div>';
        show(elSpin, false); setBtns("idle");
        return;
      }

      var data = j.data;
      show(elBadge, true);
      if (elPostCt) elPostCt.textContent = data.post_count;
      if (elKwCt)   elKwCt.textContent = data.kw_count;

      var idx = 0;
      var html = '<table class="widefat striped myls-sd-table myls-fkac-main-table" id="myls_fkac_table"><thead><tr>';
      html += '<th style="width:4%">#</th>';
      html += '<th style="width:22%">Post</th>';
      html += '<th style="width:12%">Type</th>';
      html += '<th style="width:18%">Focus Keyword</th>';
      html += '<th style="width:8%">Source</th>';
      html += '<th style="width:36%">AC Suggestions</th>';
      html += '</tr></thead><tbody>';

      data.posts.forEach(function (p) {
        p.all_keywords.forEach(function (kw) {
          var rid = "myls_fkac_row_" + idx;
          kwQueue.push({
            keyword: kw, post_title: p.post_title, post_id: p.post_id,
            post_type: p.post_type, source: p.source, rowId: rid
          });
          kwData[rid] = { groups: [], allSugs: [], gscRows: null, aiOverview: null };

          html += '<tr id="' + rid + '">';
          html += '<td>' + (idx + 1) + '</td>';
          html += '<td class="small">' + esc(p.post_title) + ' <span class="text-muted">#' + p.post_id + '</span></td>';
          html += '<td class="small text-muted">' + esc(p.post_type) + '</td>';
          html += '<td><strong>' + esc(kw) + '</strong></td>';
          html += '<td class="small text-muted">' + esc(p.source) + '</td>';
          html += '<td class="myls-fkac-sug-cell">' + PENDING_BADGE + '</td>';
          html += '</tr>';
          idx++;
        });
      });

      html += '</tbody></table>';
      if (elResults) elResults.innerHTML = html;

      show(elSpin, false);
      setBtns("loaded");

    } catch (e) {
      if (elResults) elResults.innerHTML = '<div class="notice notice-error inline"><p>' + esc(e.message) + '</p></div>';
      show(elSpin, false); setBtns("idle");
    }
  });


  /* ══════════════════════════════════════════════════════════════════
   * STEP 2: Progressive AC check — 5 queries per keyword
   * ══════════════════════════════════════════════════════════════════ */
  if (btnCheck) btnCheck.addEventListener("click", async function () {
    if (!kwQueue.length) return;
    STOP = false;
    setBtns("checking");
    show(elProgW, true);
    show(elSpin, true); if (elSpinTx) elSpinTx.textContent = "Starting\u2026";
    if (elSummary) elSummary.innerHTML = "";

    // Find pending rows
    var pending = kwQueue.filter(function (item) {
      var row = document.getElementById(item.rowId);
      if (!row) return false;
      return row.querySelector(".myls-fkac-sug-cell").innerHTML.indexOf("Pending") !== -1;
    });

    if (!pending.length) {
      kwQueue.forEach(function (item) {
        var row = document.getElementById(item.rowId);
        if (!row) return;
        row.querySelector(".myls-fkac-sug-cell").innerHTML = PENDING_BADGE;
        row.classList.remove("myls-sd-row-active");
        kwData[item.rowId] = { groups: [], allSugs: [], gscRows: null, aiOverview: null };
      });
      pending = kwQueue.slice();
    }

    var total = pending.length, done = 0, totalSuggestions = 0;

    for (var i = 0; i < pending.length; i++) {
      if (STOP) break;
      var item = pending[i];
      var row  = document.getElementById(item.rowId);
      done++;

      setProgress(done, total, "keywords");
      if (elSpinTx) elSpinTx.textContent = "Keyword " + done + "/" + total + ": " + item.keyword;

      if (row) {
        row.classList.add("myls-sd-row-active");
        row.scrollIntoView({ behavior: "smooth", block: "nearest" });
      }

      var allSugs = [], groups = [], seen = {}, hadError = false;

      for (var qi = 0; qi < QTYPES.length; qi++) {
        if (STOP) break;
        var qt    = QTYPES[qi];
        var query = qt.prefix + item.keyword + qt.suffix;

        if (row) {
          var cell = row.querySelector(".myls-fkac-sug-cell");
          if (cell) cell.innerHTML = checkingBadge(qi + 1);
        }

        var sugs = [];
        try {
          var j = await ajax(CFG.action_check_single, { question: query });
          if (j.success && j.data && j.data.suggestions) sugs = j.data.suggestions;
        } catch (e) { hadError = true; }

        var unique = [];
        sugs.forEach(function (s) {
          var key = s.toLowerCase().trim();
          if (!seen[key]) { seen[key] = true; unique.push(s); allSugs.push(s); }
        });

        groups.push({ label: qt.label, query: query, suggestions: unique });
        if (qi < QTYPES.length - 1 && !STOP) await sleep(300);
      }

      // Store data for GSC enrichment
      kwData[item.rowId] = { groups: groups, allSugs: allSugs, gscRows: null, aiOverview: null };
      totalSuggestions += allSugs.length;

      if (row) {
        row.classList.remove("myls-sd-row-active");
        var sugCell = row.querySelector(".myls-fkac-sug-cell");
        if (sugCell) {
          sugCell.innerHTML = (hadError && allSugs.length === 0)
            ? errorBadge("AC Error")
            : buildSugCellHtml(item.rowId, allSugs.length, groups, null, null);
        }
      }

      if (elSummary) {
        elSummary.innerHTML = '<div class="myls-sd-summary">' +
          '<span class="myls-sd-badge myls-sd-info">' + done + ' / ' + total + ' keywords</span> ' +
          '<span class="myls-sd-badge myls-sd-high">' + totalSuggestions + ' suggestions</span>' +
          '</div>';
      }

      if (i < pending.length - 1 && !STOP) await sleep(300);
    }

    show(elSpin, false);
    setBtns("ac-done");

    if (STOP) {
      show(elSpin, true);
      if (elSpinTx) elSpinTx.textContent = "Stopped at " + done + " / " + total + ". Click \u201CGet AC Suggestions\u201D to resume.";
      var icon = elSpin.querySelector(".dashicons");
      if (icon) icon.className = "dashicons dashicons-info";
      if (btnCheck) btnCheck.disabled = false;
    }
  });

  if (btnStop) btnStop.addEventListener("click", function () { STOP = true; });


  /* ══════════════════════════════════════════════════════════════════
   * STEP 3: GSC Enrichment — query per keyword, build sub-grids
   * ══════════════════════════════════════════════════════════════════ */
  if (btnGSC) btnGSC.addEventListener("click", async function () {
    if (!kwQueue.length) return;
    STOP_GSC = false;
    setBtns("gsc-checking");
    show(elProgW, true);
    show(elSpin, true); if (elSpinTx) elSpinTx.textContent = "Querying GSC\u2026";

    var days  = elGSCDays ? elGSCDays.value : "90";
    var total = kwQueue.length, done = 0;
    var totalMatched = 0, totalGSCRows = 0, totalAI = 0;

    for (var i = 0; i < kwQueue.length; i++) {
      if (STOP_GSC) break;
      var item = kwQueue[i];
      var row  = document.getElementById(item.rowId);
      var rd   = kwData[item.rowId];
      done++;

      setProgress(done, total, "keywords");
      if (elSpinTx) elSpinTx.textContent = "GSC " + done + "/" + total + ": " + item.keyword;

      if (row) {
        row.classList.add("myls-sd-row-active");
        row.scrollIntoView({ behavior: "smooth", block: "nearest" });

        // Show spinner in the expand area if visible
        var sugCell = row.querySelector(".myls-fkac-sug-cell");
        var badge = sugCell ? sugCell.querySelector(".myls-sd-badge, .myls-sd-cell-spinner") : null;
        // Add a small GSC spinner after the badge
        var gscTag = row.querySelector(".myls-fkac-gsc-status");
        if (!gscTag && sugCell) {
          var span = document.createElement("span");
          span.className = "myls-fkac-gsc-status";
          span.innerHTML = ' ' + gscCheckingBadge();
          sugCell.insertBefore(span, sugCell.firstChild?.nextSibling || null);
        }
      }

      // Query GSC
      var gscRows = [];
      var aiOverview = {};
      try {
        var j = await ajax(CFG.action_gsc_query, { keyword: item.keyword, days: days });
        if (j.success && j.data && j.data.rows) {
          gscRows = j.data.rows;
        }
        if (j.success && j.data && j.data.ai_overview) {
          aiOverview = j.data.ai_overview;
        }
      } catch (e) { /* empty */ }

      rd.gscRows = gscRows;
      rd.aiOverview = aiOverview;
      totalGSCRows += gscRows.length;
      var aiCount = Object.keys(aiOverview).length;
      totalAI += aiCount;

      // Count how many AC suggestions have GSC matches
      if (rd.allSugs.length > 0 && gscRows.length > 0) {
        var gscMap = {};
        gscRows.forEach(function (r) { gscMap[r.query.toLowerCase().trim()] = r; });
        rd.allSugs.forEach(function (s) {
          if (gscMap[s.toLowerCase().trim()]) totalMatched++;
        });
      }

      // Re-render the cell with GSC data
      if (row) {
        row.classList.remove("myls-sd-row-active");
        var sc = row.querySelector(".myls-fkac-sug-cell");
        if (sc) {
          sc.innerHTML = buildSugCellHtml(item.rowId, rd.allSugs.length, rd.groups, rd.gscRows, rd.aiOverview);
        }
      }

      // Live summary
      if (elSummary) {
        elSummary.innerHTML = '<div class="myls-sd-summary">' +
          '<span class="myls-sd-badge myls-sd-info">' + done + ' / ' + total + ' keywords enriched</span> ' +
          '<span class="myls-sd-badge myls-sd-high">' + totalGSCRows + ' GSC queries</span> ' +
          '<span class="myls-sd-badge myls-sd-medium">' + totalMatched + ' AC/GSC matches</span>' +
          (totalAI > 0 ? ' <span class="myls-sd-badge myls-fkac-ai-badge"><i class="bi bi-stars"></i> ' + totalAI + ' in AI Overview</span>' : '') +
          '</div>';
      }

      if (i < kwQueue.length - 1 && !STOP_GSC) await sleep(300);
    }

    show(elSpin, false);
    show(btnPrint, true);
    setBtns("gsc-done");

    if (STOP_GSC) {
      show(elSpin, true);
      if (elSpinTx) elSpinTx.textContent = "Stopped at " + done + " / " + total + ". Click \u201CEnrich with GSC\u201D to resume.";
      var ic = elSpin.querySelector(".dashicons");
      if (ic) ic.className = "dashicons dashicons-info";
      if (btnGSC) btnGSC.disabled = false;
    }
  });

  if (btnGSCStop) btnGSCStop.addEventListener("click", function () { STOP_GSC = true; });

  /* ── Print ── */
  if (btnPrint) btnPrint.addEventListener("click", function () {
    // Expand all details before printing
    document.querySelectorAll(".myls-fkac-detail").forEach(function (d) { d.style.display = "block"; });
    document.querySelectorAll(".myls-fkac-expand").forEach(function (a) { a.textContent = "(hide details)"; });
    window.print();
  });


  /* ══════════════════════════════════════════════════════════════════
   * Build expandable suggestion cell with optional GSC sub-grid
   * ══════════════════════════════════════════════════════════════════ */
  function buildSugCellHtml(rowId, total, groups, gscRows, aiOverview) {
    var id = "fkac_detail_" + rowId;
    var hasGSC = gscRows && gscRows.length > 0;
    var aiMap = aiOverview || {};

    // Build GSC lookup map
    var gscMap = {};
    if (gscRows) {
      gscRows.forEach(function (r) { gscMap[r.query.toLowerCase().trim()] = r; });
    }

    var html = '<div>';
    html += resultBadge(total);
    if (hasGSC) {
      html += ' <span class="myls-sd-badge myls-sd-info"><i class="bi bi-google"></i> ' + gscRows.length + ' GSC</span>';
    }
    var aiCount = Object.keys(aiMap).length;
    if (aiCount > 0) {
      html += ' <span class="myls-sd-badge myls-fkac-ai-badge"><i class="bi bi-stars"></i> ' + aiCount + ' AI</span>';
    }
    if (total > 0) {
      html += ' <a href="#" class="myls-fkac-expand small" data-target="' + id + '">(show details)</a>';
    }
    html += '</div>';

    if (total > 0) {
      html += '<div id="' + id + '" class="myls-fkac-detail" style="display:none;margin-top:6px;">';

      if (hasGSC || gscRows !== null) {
        // ── Sub-grid table with GSC columns ──
        html += '<table class="myls-fkac-subgrid">';
        html += '<thead><tr>';
        html += '<th>Suggestion</th><th>Type</th>';
        html += '<th class="text-end">Impr.</th><th class="text-end">Clicks</th>';
        html += '<th class="text-end">CTR</th><th class="text-end">Avg Pos</th>';
        html += '<th class="text-end">AI Overview</th>';
        html += '</tr></thead><tbody>';

        groups.forEach(function (g) {
          g.suggestions.forEach(function (s) {
            var gsc = gscMap[s.toLowerCase().trim()];
            var hasData = !!gsc;
            var cls = hasData ? ' class="myls-fkac-gsc-match"' : '';

            html += '<tr' + cls + '>';
            html += '<td>' + esc(s) + '</td>';
            html += '<td><span class="myls-fkac-type-tag">' + esc(g.label) + '</span></td>';

            if (hasData) {
              html += '<td class="text-end fw-semibold">' + num(gsc.impressions) + '</td>';
              html += '<td class="text-end fw-semibold">' + num(gsc.clicks) + '</td>';
              html += '<td class="text-end">' + gsc.ctr + '%</td>';
              html += '<td class="text-end">' + gsc.position + '</td>';
            } else {
              html += '<td class="text-end text-muted">\u2014</td>';
              html += '<td class="text-end text-muted">\u2014</td>';
              html += '<td class="text-end text-muted">\u2014</td>';
              html += '<td class="text-end text-muted">\u2014</td>';
            }
            // AI Overview column
            var ai = aiMap[s.toLowerCase().trim()];
            if (ai) {
              html += '<td class="text-end"><span class="myls-fkac-ai-tag">\u2728 ' + num(ai.impressions) + ' impr</span></td>';
            } else {
              html += '<td class="text-end text-muted">\u2014</td>';
            }
            html += '</tr>';
          });
        });

        // GSC queries NOT in AC suggestions (bonus discoveries)
        if (gscRows) {
          var acSet = {};
          groups.forEach(function (g) {
            g.suggestions.forEach(function (s) { acSet[s.toLowerCase().trim()] = true; });
          });

          var bonus = gscRows.filter(function (r) { return !acSet[r.query.toLowerCase().trim()]; });
          if (bonus.length > 0) {
            html += '<tr class="myls-fkac-bonus-header"><td colspan="7">';
            html += '<strong><i class="bi bi-plus-circle"></i> ' + bonus.length + ' additional GSC queries</strong>';
            html += ' <small class="text-muted">(found in GSC but not in AC suggestions)</small>';
            html += '</td></tr>';

            bonus.forEach(function (r) {
              html += '<tr class="myls-fkac-gsc-bonus">';
              html += '<td>' + esc(r.query) + '</td>';
              html += '<td><span class="myls-fkac-type-tag myls-fkac-type-gsc">GSC</span></td>';
              html += '<td class="text-end fw-semibold">' + num(r.impressions) + '</td>';
              html += '<td class="text-end fw-semibold">' + num(r.clicks) + '</td>';
              html += '<td class="text-end">' + r.ctr + '%</td>';
              html += '<td class="text-end">' + r.position + '</td>';
              var ai = aiMap[r.query.toLowerCase().trim()];
              if (ai) {
                html += '<td class="text-end"><span class="myls-fkac-ai-tag">\u2728 ' + num(ai.impressions) + ' impr</span></td>';
              } else {
                html += '<td class="text-end text-muted">\u2014</td>';
              }
              html += '</tr>';
            });
          }
        }

        html += '</tbody></table>';

      } else {
        // ── Simple grouped list (no GSC yet) ──
        groups.forEach(function (g) {
          if (g.suggestions.length === 0) return;
          html += '<div class="myls-fkac-mini-group">';
          html += '<div class="myls-fkac-mini-header">';
          html += '<strong>' + esc(g.label) + '</strong>';
          html += ' <span class="myls-fkac-query-tag">' + esc(g.query) + '</span>';
          html += ' <small class="text-muted">(' + g.suggestions.length + ')</small>';
          html += '</div>';
          g.suggestions.forEach(function (s) {
            html += '<div class="myls-fkac-mini-item">' + esc(s) + '</div>';
          });
          html += '</div>';
        });
      }

      html += '</div>';
    }

    return html;
  }


  /* ── Delegated click for expand/collapse ── */
  document.addEventListener("click", function (e) {
    var link = e.target.closest(".myls-fkac-expand");
    if (!link) return;
    e.preventDefault();

    var targetId = link.getAttribute("data-target");
    var detail = document.getElementById(targetId);
    if (!detail) return;

    var vis = detail.style.display !== "none";
    detail.style.display = vis ? "none" : "block";
    link.textContent = vis ? "(show details)" : "(hide details)";
  });

})();
