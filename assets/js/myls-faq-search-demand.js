/* ========================================================================
 * MYLS – FAQ Search Demand JS
 * File: assets/js/myls-faq-search-demand.js
 *
 * Two sections:
 *  1. Manual search terms (textarea → batch check)
 *  2. Site-wide FAQ audit (load table → check row-by-row)
 *
 * Config: window.MYLS_FAQ_SD
 * ======================================================================== */
(function () {
  "use strict";

  if (!window.MYLS_FAQ_SD) return;
  var CFG = window.MYLS_FAQ_SD;

  var $ = function (id) { return document.getElementById(id); };
  function esc(s) { var d = document.createElement("div"); d.textContent = s; return d.innerHTML; }
  function show(el, v) { if (el) el.style.display = v ? "" : "none"; }

  function ajax(action, data) {
    var fd = new FormData();
    fd.append("action", action);
    fd.append("nonce", CFG.nonce);
    if (data) Object.keys(data).forEach(function (k) {
      var v = data[k];
      if (Array.isArray(v)) v.forEach(function (i) { fd.append(k + "[]", i); });
      else fd.append(k, v);
    });
    return fetch(CFG.ajaxurl, { method: "POST", body: fd }).then(function (r) { return r.json(); });
  }

  /* ── Badges ── */
  var BADGE = {
    high:     '<span class="myls-sd-badge myls-sd-high">\u2705 Strong</span>',
    medium:   '<span class="myls-sd-badge myls-sd-medium">\uD83D\uDFE1 Partial</span>',
    low:      '<span class="myls-sd-badge myls-sd-low">\uD83D\uDFE0 Weak</span>',
    none:     '<span class="myls-sd-badge myls-sd-none">\u274C None</span>',
    error:    '<span class="myls-sd-badge myls-sd-error">\u26A0\uFE0F Error</span>',
    pending:  '<span class="myls-sd-badge myls-sd-pending">\u23F3 Pending</span>',
    checking: '<span class="myls-sd-cell-spinner"><span class="dashicons dashicons-update myls-spin"></span> Checking\u2026</span>'
  };

  function scoreBadge(score, conf) {
    return (BADGE[score] || BADGE.none) + (conf > 0 ? ' <small class="text-muted">(' + conf + '%)</small>' : "");
  }

  function summaryHtml(s, total) {
    var p = [];
    if (s.high > 0)   p.push('<span class="myls-sd-badge myls-sd-high">\u2705 ' + s.high + ' Strong</span>');
    if (s.medium > 0) p.push('<span class="myls-sd-badge myls-sd-medium">\uD83D\uDFE1 ' + s.medium + ' Partial</span>');
    if (s.low > 0)    p.push('<span class="myls-sd-badge myls-sd-low">\uD83D\uDFE0 ' + s.low + ' Weak</span>');
    if (s.none > 0)   p.push('<span class="myls-sd-badge myls-sd-none">\u274C ' + s.none + ' None</span>');
    if (s.error > 0)  p.push('<span class="myls-sd-badge myls-sd-error">\u26A0\uFE0F ' + s.error + ' Errors</span>');
    return '<div class="myls-sd-summary">' + p.join(" ") + ' \u2014 ' + total + ' checked</div>';
  }

  function sugCell(sugs, matched) {
    var html = "";
    if (matched && matched.length) {
      html += '<div class="mb-1">' + matched.map(function (m) { return '<span class="myls-sd-match-tag">' + esc(m) + '</span>'; }).join(" ") + '</div>';
    }
    if (!sugs || !sugs.length) return html + '<span class="text-muted">\u2014</span>';
    var first = sugs.slice(0, 3).map(function (s) { return esc(s); }).join("<br/>");
    html += '<div class="myls-sd-suggestions">' + first;
    if (sugs.length > 3) {
      var rest = sugs.slice(3).map(function (s) { return esc(s); }).join("<br/>");
      html += '<div class="myls-sd-more" style="display:none;">' + rest + '</div>';
      html += '<a href="#" class="myls-sd-toggle small">(+' + (sugs.length - 3) + ' more)</a>';
    }
    return html + '</div>';
  }

  function wireToggles(el) {
    el.querySelectorAll(".myls-sd-toggle").forEach(function (a) {
      a.addEventListener("click", function (e) {
        e.preventDefault();
        var m = this.previousElementSibling;
        if (m && m.classList.contains("myls-sd-more")) {
          var vis = m.style.display !== "none";
          m.style.display = vis ? "none" : "block";
          this.textContent = vis ? "(show less)" : "(+" + (m.innerHTML.split("<br").length - 1) + " more)";
        }
      });
    });
  }


  /* ══════════════════════════════════════════════════════════════════
   * MANUAL SEARCH TERMS
   * ══════════════════════════════════════════════════════════════════ */
  var elTerms     = $("myls_fsd_terms");
  var btnCheck    = $("myls_fsd_check_terms");
  var btnClear    = $("myls_fsd_clear_terms");
  var elManSpin   = $("myls_fsd_manual_spinner");
  var elManSum    = $("myls_fsd_manual_summary");
  var elManRes    = $("myls_fsd_manual_results");

  if (btnCheck) btnCheck.addEventListener("click", async function () {
    var terms = (elTerms.value || "").split("\n").map(function (l) { return l.trim(); }).filter(Boolean);
    if (!terms.length) { elManRes.innerHTML = '<div class="notice notice-warning inline"><p>Enter terms first (one per line).</p></div>'; return; }

    show(elManSpin, true); elManSum.innerHTML = ""; elManRes.innerHTML = ""; btnCheck.disabled = true;
    try {
      var j = await ajax(CFG.action_check_batch, { questions: terms });
      if (j.success && j.data) {
        if (elManSum && j.data.summary) elManSum.innerHTML = summaryHtml(j.data.summary, j.data.total);
        var h = '<table class="widefat striped myls-sd-table"><thead><tr><th>#</th><th>Term</th><th>Demand</th><th>Suggestions</th></tr></thead><tbody>';
        j.data.results.forEach(function (r, i) {
          h += '<tr><td>' + (i+1) + '</td><td><strong>' + esc(r.question) + '</strong></td>';
          h += '<td>' + scoreBadge(r.score, r.confidence) + '</td>';
          h += '<td>' + sugCell(r.suggestions, r.matched) + '</td></tr>';
        });
        elManRes.innerHTML = h + '</tbody></table>';
        wireToggles(elManRes);
      } else {
        elManRes.innerHTML = '<div class="notice notice-error inline"><p>' + esc(j.data?.message || "Error") + '</p></div>';
      }
    } catch (e) { elManRes.innerHTML = '<div class="notice notice-error inline"><p>' + esc(e.message) + '</p></div>'; }
    finally { show(elManSpin, false); btnCheck.disabled = false; }
  });

  if (btnClear) btnClear.addEventListener("click", function () {
    if (elTerms) elTerms.value = "";
    if (elManSum) elManSum.innerHTML = "";
    if (elManRes) elManRes.innerHTML = "";
  });


  /* ══════════════════════════════════════════════════════════════════
   * SITE-WIDE FAQ AUDIT
   * ══════════════════════════════════════════════════════════════════ */
  var btnSLoad   = $("myls_fsd_site_load");
  var btnSCheck  = $("myls_fsd_site_check");
  var btnSStop   = $("myls_fsd_site_stop");
  var elSSpin    = $("myls_fsd_site_spinner");
  var elSSpinTx  = $("myls_fsd_site_spinner_text");
  var elSBadge   = $("myls_fsd_site_badge");
  var elSPostCt  = $("myls_fsd_site_post_count");
  var elSFaqCt   = $("myls_fsd_site_faq_count");
  var elSProgW   = $("myls_fsd_site_progress_wrap");
  var elSProgB   = $("myls_fsd_site_progress_bar");
  var elSProgT   = $("myls_fsd_site_progress_text");
  var elSProgP   = $("myls_fsd_site_progress_pct");
  var elSSumm    = $("myls_fsd_site_summary");
  var elSRes     = $("myls_fsd_site_results");

  var siteQueue = [];
  var STOP = false;

  function setSiteBtns(phase) {
    if (btnSLoad)  btnSLoad.disabled  = (phase === "checking");
    if (btnSCheck) btnSCheck.disabled = (phase !== "loaded" && phase !== "done");
    if (btnSStop)  btnSStop.disabled  = (phase !== "checking");
  }

  /* ── Load ── */
  if (btnSLoad) btnSLoad.addEventListener("click", async function () {
    siteQueue = []; STOP = false;
    show(elSSpin, true); if (elSSpinTx) elSSpinTx.textContent = "Loading all FAQs\u2026";
    setSiteBtns("checking");
    show(elSProgW, false);
    if (elSSumm) elSSumm.innerHTML = "";
    if (elSRes)  elSRes.innerHTML = "";

    try {
      var j = await ajax(CFG.action_get_all_faqs, {});
      if (!j.success || !j.data || !j.data.posts) {
        if (elSRes) elSRes.innerHTML = '<div class="notice notice-warning inline"><p>' + esc(j.data?.message || "No FAQs found.") + '</p></div>';
        show(elSSpin, false); setSiteBtns("idle"); return;
      }

      var data = j.data;
      show(elSBadge, true);
      if (elSPostCt) elSPostCt.textContent = data.post_count;
      if (elSFaqCt)  elSFaqCt.textContent = data.faq_count;

      var idx = 0;
      var html = '<table class="widefat striped myls-sd-table"><thead><tr>';
      html += '<th style="width:4%">#</th><th style="width:25%">Post</th>';
      html += '<th style="width:30%">FAQ Question</th><th style="width:15%">Demand</th>';
      html += '<th style="width:26%">Suggestions</th></tr></thead><tbody>';

      data.posts.forEach(function (p) {
        html += '<tr class="myls-sd-group-header"><td colspan="5">';
        html += '<i class="bi bi-file-earmark-text"></i> ' + esc(p.post_title);
        html += ' <small class="text-muted">(#' + p.post_id + ' \u2014 ' + esc(p.post_type) + ' \u2014 ' + p.questions.length + ' FAQs)</small>';
        html += '</td></tr>';

        p.questions.forEach(function (q) {
          var rid = "myls_fsd_row_" + idx;
          siteQueue.push({ question: q, post_title: p.post_title, post_id: p.post_id, rowId: rid });
          html += '<tr id="' + rid + '">';
          html += '<td>' + (idx + 1) + '</td>';
          html += '<td class="small text-muted">' + esc(p.post_title) + '</td>';
          html += '<td>' + esc(q) + '</td>';
          html += '<td class="myls-sd-demand-cell">' + BADGE.pending + '</td>';
          html += '<td class="myls-sd-sug-cell">\u2014</td></tr>';
          idx++;
        });
      });

      html += '</tbody></table>';
      if (elSRes) elSRes.innerHTML = html;

      show(elSSpin, false);
      setSiteBtns("loaded");

    } catch (e) {
      if (elSRes) elSRes.innerHTML = '<div class="notice notice-error inline"><p>' + esc(e.message) + '</p></div>';
      show(elSSpin, false); setSiteBtns("idle");
    }
  });

  /* ── Check row-by-row ── */
  if (btnSCheck) btnSCheck.addEventListener("click", async function () {
    if (!siteQueue.length) return;
    STOP = false;
    setSiteBtns("checking");
    show(elSProgW, true);
    show(elSSpin, true); if (elSSpinTx) elSSpinTx.textContent = "Starting\u2026";
    if (elSSumm) elSSumm.innerHTML = "";

    // Find pending rows
    var pending = siteQueue.filter(function (item) {
      var row = document.getElementById(item.rowId);
      if (!row) return false;
      return row.querySelector(".myls-sd-demand-cell").innerHTML.indexOf("Pending") !== -1;
    });

    if (!pending.length) {
      siteQueue.forEach(function (item) {
        var row = document.getElementById(item.rowId);
        if (!row) return;
        row.querySelector(".myls-sd-demand-cell").innerHTML = BADGE.pending;
        row.querySelector(".myls-sd-sug-cell").innerHTML = "\u2014";
        row.classList.remove("myls-sd-row-active");
      });
      pending = siteQueue.slice();
    }

    var total = pending.length, done = 0;
    var summary = { high: 0, medium: 0, low: 0, none: 0, error: 0 };

    for (var i = 0; i < pending.length; i++) {
      if (STOP) break;
      var item = pending[i];
      var row = document.getElementById(item.rowId);
      done++;

      var pct = Math.round((done / total) * 100);
      if (elSProgB) elSProgB.style.width = pct + "%";
      if (elSProgT) elSProgT.textContent = done + " / " + total;
      if (elSProgP) elSProgP.textContent = pct + "%";
      if (elSSpinTx) elSSpinTx.textContent = "Checking " + done + " of " + total + "\u2026";

      if (row) {
        row.classList.add("myls-sd-row-active");
        var dc = row.querySelector(".myls-sd-demand-cell");
        if (dc) dc.innerHTML = BADGE.checking;
        row.scrollIntoView({ behavior: "smooth", block: "nearest" });
      }

      var score = "error", conf = 0, matched = [], sugs = [];
      try {
        var j = await ajax(CFG.action_check_single, { question: item.question });
        if (j.success && j.data) { score = j.data.score; conf = j.data.confidence; matched = j.data.matched || []; sugs = j.data.suggestions || []; }
      } catch (e) { /* stays error */ }

      summary[score] = (summary[score] || 0) + 1;

      if (row) {
        row.classList.remove("myls-sd-row-active");
        var d2 = row.querySelector(".myls-sd-demand-cell");
        var s2 = row.querySelector(".myls-sd-sug-cell");
        if (d2) d2.innerHTML = scoreBadge(score, conf);
        if (s2) { s2.innerHTML = sugCell(sugs, matched); wireToggles(s2); }
      }

      if (elSSumm) elSSumm.innerHTML = summaryHtml(summary, done);

      if (i < pending.length - 1 && !STOP) {
        await new Promise(function (r) { setTimeout(r, 300); });
      }
    }

    show(elSSpin, false);
    setSiteBtns("done");

    if (STOP) {
      show(elSSpin, true);
      if (elSSpinTx) elSSpinTx.textContent = "Stopped at " + done + " / " + total + ". Click \u201CCheck Demand\u201D to resume.";
      var icon = elSSpin.querySelector(".dashicons");
      if (icon) icon.className = "dashicons dashicons-info";
      if (btnSCheck) btnSCheck.disabled = false;
    }
  });

  if (btnSStop) btnSStop.addEventListener("click", function () { STOP = true; });

})();
