/* ========================================================================
 * MYLS – AI GEO Subtab JS (restored)
 * File: assets/js/myls-ai-geo.js
 *
 * - Loads posts by type
 * - Analyze Selected (Preview + DOCX/HTML downloads)
 * - Convert to GEO Draft
 * - Duplicate to Draft
 *
 * Notes:
 * - The GEO tab UI prints a window.MYLS_AI_GEO config and loads this file.
 * - Download buttons are enabled only after a successful Analyze (or Convert).
 * ======================================================================== */

(function () {
  "use strict";

  if (!window.MYLS_AI_GEO) return;

  const CFG = window.MYLS_AI_GEO;

  // -----------------------------
  // DOM helpers
  // -----------------------------
  const $ = (sel) => document.querySelector(sel);

  const elPT = $("#myls_ai_geo_pt");
  const elPosts = $("#myls_ai_geo_posts");

  const btnSelectAll = $("#myls_ai_geo_select_all");
  const btnClear = $("#myls_ai_geo_clear");
  const btnAnalyze = $("#myls_ai_geo_analyze");
  const btnConvert = $("#myls_ai_geo_convert");
  const btnDuplicate = $("#myls_ai_geo_duplicate");
  const btnStop = $("#myls_ai_geo_stop");

  const cbIncludeFaqHowto = $("#myls_ai_geo_include_faq_howto");
  const cbWithAnchors = $("#myls_ai_geo_with_anchors");

  const btnDocx = $("#myls_ai_geo_docx");
  const btnHtml = $("#myls_ai_geo_html");

  const elSpinner = $("#myls_ai_geo_spinner");
  const elStatus = $("#myls_ai_geo_status");
  const elCount = $("#myls_ai_geo_count");
  const elLoadedHint = $("#myls_ai_geo_loaded_hint");

  const elPreview = $("#myls_ai_geo_preview");
  const elOutput = $("#myls_ai_geo_output");
  const elResults = $("#myls_ai_geo_results");

  const elTemplate = $("#myls_ai_geo_prompt_template");
  const elTokens = $("#myls_ai_geo_tokens");
  const elTemp = $("#myls_ai_geo_temperature");

  let STOP = false;
  let processed = 0;

  // Last successful analyze/convert payload (used for downloads)
  let LAST = {
    postId: 0,
    title: "",
    url: "",
    geoHtml: "",
    docxUrl: "",
    htmlFilename: "",
  };

  // -----------------------------
  // UI state
  // -----------------------------
  function setBusy(isBusy, msg) {
    if (elSpinner) elSpinner.style.display = isBusy ? "inline-flex" : "none";
    if (elStatus) elStatus.textContent = msg || "";

    if (btnStop) btnStop.disabled = !isBusy;
    if (btnAnalyze) btnAnalyze.disabled = isBusy;
    if (btnConvert) btnConvert.disabled = isBusy;
    if (btnDuplicate) btnDuplicate.disabled = isBusy;

    // downloads should never be enabled while busy
    if (isBusy) {
      if (btnDocx) btnDocx.disabled = true;
      if (btnHtml) btnHtml.disabled = true;
    }
  }

  var LOG = window.mylsLog;

  function log(msg) {
    if (!elResults) return;
    if (LOG) {
      LOG.append(msg, elResults);
    } else {
      var t = new Date().toLocaleTimeString();
      var current = elResults.textContent || '';
      if (current === 'Ready.') {
        elResults.textContent = '[' + t + '] ' + msg + '\n';
      } else {
        elResults.textContent += '[' + t + '] ' + msg + '\n';
      }
      elResults.scrollTop = elResults.scrollHeight;
    }
  }

  function getSelectedIDs() {
    if (!elPosts) return [];
    return Array.from(elPosts.selectedOptions || [])
      .map((o) => parseInt(o.value, 10))
      .filter(Boolean);
  }

  function getMode() {
    const radios = document.querySelectorAll('input[name="myls_ai_geo_mode"]');
    for (const r of radios) if (r.checked) return r.value;
    return "partial";
  }

  function includeFaqHowto() {
    return !!(cbIncludeFaqHowto && cbIncludeFaqHowto.checked);
  }

  function withAnchors() {
    return !!(cbWithAnchors && cbWithAnchors.checked);
  }

  function getTemplateValue() {
    return elTemplate ? String(elTemplate.value || "").trim() : "";
  }

  function getTokensValue() {
    const n = elTokens ? parseInt(elTokens.value, 10) : NaN;
    return Number.isFinite(n) && n > 0 ? n : 1200;
  }

  function getTempValue() {
    const f = elTemp ? parseFloat(elTemp.value) : NaN;
    return Number.isFinite(f) ? f : 0.4;
  }

  function resetDownloads() {
    LAST = { postId: 0, title: "", url: "", geoHtml: "", docxUrl: "", htmlFilename: "" };
    if (btnDocx) btnDocx.disabled = true;
    if (btnHtml) btnHtml.disabled = true;
  }

  function enableDownloads() {
    if (btnDocx) btnDocx.disabled = !LAST.docxUrl;
    if (btnHtml) btnHtml.disabled = !LAST.geoHtml;
  }

  function safeFilename(s) {
    return String(s || "")
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "")
      .slice(0, 80) || "geo";
  }

  // -----------------------------
  // AJAX helper
  // -----------------------------
  async function postAJAX(action, data) {
    const body = new URLSearchParams();
    body.set("action", action);
    body.set("_ajax_nonce", CFG.nonce);

    Object.keys(data || {}).forEach((k) => {
      if (data[k] === undefined || data[k] === null) return;
      body.set(k, data[k]);
    });

    const resp = await fetch(CFG.ajaxurl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: body.toString(),
    });

    let json;
    try {
      json = await resp.json();
    } catch (e) {
      throw new Error("Invalid JSON response");
    }

    if (!json || json.success !== true) {
      const msg = json && json.data && json.data.message ? json.data.message : "AJAX error";
      throw new Error(msg);
    }

    return json.data;
  }

  // -----------------------------
  // Load posts by type
  // -----------------------------
  async function loadPostsByType(pt) {
    if (!elLoadedHint || !elPosts) return;
    elLoadedHint.textContent = "Loading…";
    elPosts.innerHTML = "";
    resetDownloads();

    try {
      const data = await postAJAX(CFG.action_get_posts, { post_type: pt });
      const items = data.posts || [];

      items.forEach((p) => {
        const opt = document.createElement("option");
        opt.value = String(p.id);
        opt.textContent = `${p.title} (#${p.id})`;
        elPosts.appendChild(opt);
      });

      elLoadedHint.textContent = `Loaded ${items.length} post(s).`;
      log(`Loaded ${items.length} post(s) for post type "${pt}".`);
    } catch (e) {
      elLoadedHint.textContent = "Error loading posts.";
      log(`Error loading posts: ${e.message}`);
    }
  }

  // -----------------------------
  // Analyze (Preview + downloads)
  // -----------------------------
  async function analyzeSelected() {
    const ids = getSelectedIDs();
    if (!ids.length) {
      alert("Select at least one post.");
      return;
    }

    const template = getTemplateValue();
    if (!template) {
      log("Analyze error: missing_template");
      alert("Prompt template is empty. Save a prompt template first.");
      return;
    }

    STOP = false;
    processed = 0;
    if (elCount) elCount.textContent = "0";
    resetDownloads();

    setBusy(true, "Analyzing…");

    const total = ids.length;
    var tracker = LOG ? LOG.createTracker() : null;
    var stats = { saved: 0, skipped: 0, errors: 0 };

    if (LOG && elResults) {
      LOG.clear(elResults, LOG.batchStart('GEO Analysis', total, {
        tokens: getTokensValue(),
        temperature: getTempValue()
      }));
    } else {
      log('Analyze queued: ' + ids.length + ' post(s).');
    }

    for (const postId of ids) {
      if (STOP) break;
      const idx = processed + 1;

      try {
        log('Analyzing post #' + postId + '…');

        const data = await postAJAX(CFG.action_analyze, {
          post_id: postId,
          mode: getMode(),
          with_anchors: withAnchors() ? "1" : "0",
          include_faq_howto: includeFaqHowto() ? "1" : "0",
          template,
          tokens: String(getTokensValue()),
          temperature: String(getTempValue()),
        });

        const geoHtml = data.geo_html || data.html || "";

        // Preview should render as HTML
        if (elPreview) {
          elPreview.innerHTML = geoHtml || "<p><em>No GEO output returned.</em></p>";
        }

        // Raw combined for debugging
        if (elOutput) {
          elOutput.textContent = data.combined || data.raw || "";
        }

        // Capture downloads
        LAST.postId = postId;
        LAST.title = data.title || "";
        LAST.url = data.url || "";
        LAST.geoHtml = geoHtml;
        LAST.docxUrl = data.doc_url || "";
        LAST.htmlFilename = `${safeFilename(LAST.title)}-${postId}-geo.html`;

        enableDownloads();

        if (data.doc_url) {
          if (elStatus) elStatus.innerHTML = `<a href="${data.doc_url}" target="_blank" rel="noopener">Download .docx</a>`;
        } else {
          if (btnDocx) btnDocx.disabled = true;
          if (elStatus) elStatus.textContent = includeFaqHowto() ? "No .docx returned (prompt must output DOC section)." : "No .docx returned.";
        }

        // Enterprise log entry
        if (LOG && elResults && data.log) {
          var entryData = {
            status: 'ok',
            city_state: data.title || '',
            preview: data.preview || '',
            log: Object.assign({}, data.log, { page_title: data.title || '' })
          };
          LOG.append(LOG.formatEntry(postId, entryData, { index: idx, total: total, handler: 'GEO Analysis' }), elResults);
          if (tracker) tracker.track(entryData);
          stats.saved++;
        }

        processed++;
        if (elCount) elCount.textContent = String(processed);
      } catch (e) {
        if (LOG && elResults) {
          LOG.append(LOG.formatError(postId, { message: e.message }, { index: idx, total: total }), elResults);
        } else {
          log('Analyze error for #' + postId + ': ' + e.message);
        }
        stats.errors++;
      }
    }

    // Batch summary
    if (LOG && elResults) {
      LOG.append(LOG.batchSummary(tracker ? tracker.getSummary(stats) : stats), elResults);
    }

    setBusy(false, STOP ? "Stopped." : "Done.");
  }

  // -----------------------------
  // Convert to GEO Draft
  // -----------------------------
  async function convertToDraft() {
    const ids = getSelectedIDs();
    if (!ids.length) {
      alert("Select at least one post.");
      return;
    }

    const template = getTemplateValue();
    if (!template) {
      log("Convert error: missing_template");
      alert("Prompt template is empty. Save a prompt template first.");
      return;
    }

    STOP = false;
    processed = 0;
    if (elCount) elCount.textContent = "0";
    resetDownloads();

    setBusy(true, "Converting…");
    log(`Convert queued: ${ids.length} post(s).`);

    for (const postId of ids) {
      if (STOP) break;

      try {
        log(`Converting post #${postId} to draft…`);

        const data = await postAJAX(CFG.action_convert, {
          post_id: postId,
          mode: getMode(),
          with_anchors: withAnchors() ? "1" : "0",
          include_faq_howto: includeFaqHowto() ? "1" : "0",
          template,
          tokens: String(getTokensValue()),
          temperature: String(getTempValue()),
        });

        const draftId = data.new_post_id || data.draft_id || 0;
        const editLink = data.edit_url || data.edit_link || "";

        if (elStatus) {
          elStatus.innerHTML = editLink
            ? `<a href="${editLink}" target="_blank" rel="noopener">Open Draft #${draftId}</a>`
            : `Draft #${draftId} created.`;
        }

        // capture downloads from convert if returned
        LAST.postId = postId;
        LAST.title = data.source_title || "";
        LAST.url = data.url || "";
        LAST.geoHtml = data.html || "";
        LAST.docxUrl = data.doc_url || "";
        LAST.htmlFilename = `${safeFilename(LAST.title)}-${postId}-geo.html`;
        enableDownloads();

        processed++;
        if (elCount) elCount.textContent = String(processed);

        log(`Created draft #${draftId} from #${postId}.`);
      } catch (e) {
        log(`Convert error for #${postId}: ${e.message}`);
      }
    }

    setBusy(false, STOP ? "Stopped." : "Done.");
  }

  // -----------------------------
  // Duplicate to draft (no rewrite)
  // -----------------------------
  async function duplicateNoRewrite() {
    const ids = getSelectedIDs();
    if (!ids.length) {
      alert("Select at least one post.");
      return;
    }

    STOP = false;
    processed = 0;
    if (elCount) elCount.textContent = "0";
    resetDownloads();

    setBusy(true, "Duplicating…");
    log("Duplicate queued.");

    for (const postId of ids) {
      if (STOP) break;

      try {
        const data = await postAJAX(CFG.action_duplicate, { post_id: postId });
        const draftId = data.new_post_id || data.draft_id || 0;
        log(`Duplicated #${postId} → draft #${draftId}.`);
        processed++;
        if (elCount) elCount.textContent = String(processed);
      } catch (e) {
        log(`Duplicate error for #${postId}: ${e.message}`);
      }
    }

    setBusy(false, STOP ? "Stopped." : "Done.");
  }

  // -----------------------------
  // Downloads
  // -----------------------------
  function downloadDocx() {
    if (!LAST.docxUrl) return;
    window.open(LAST.docxUrl, "_blank", "noopener");
  }

  function downloadHtml() {
    if (!LAST.geoHtml) return;

    const html = String(LAST.geoHtml);

    // Do NOT wrap in <html>... by default; user wants copy/paste ready markup.
    // Still save as .html for convenience.
    const blob = new Blob([html], { type: "text/html;charset=utf-8" });
    const url = URL.createObjectURL(blob);

    const a = document.createElement("a");
    a.href = url;
    a.download = LAST.htmlFilename || "geo.html";
    document.body.appendChild(a);
    a.click();
    a.remove();

    setTimeout(() => URL.revokeObjectURL(url), 2500);
  }

  // -----------------------------
  // Selection UX: keep selection local, avoid accidental full-page selection
  // -----------------------------
  function installSelectBoxGuards(el) {
    if (!el) return;

    // Focus ring
    el.addEventListener("focus", () => el.classList.add("is-focused"));
    el.addEventListener("blur", () => el.classList.remove("is-focused"));

    // Ctrl/Cmd + A should select within the box, not the whole admin page
    el.addEventListener("keydown", (e) => {
      const isA = (e.key || "").toLowerCase() === "a";
      if (!isA) return;
      if (!(e.ctrlKey || e.metaKey)) return;

      e.preventDefault();
      e.stopPropagation();

      const range = document.createRange();
      range.selectNodeContents(el);
      const sel = window.getSelection();
      if (sel) {
        sel.removeAllRanges();
        sel.addRange(range);
      }
    });
  }

  // -----------------------------
  // Events
  // -----------------------------
  if (elPT) {
    elPT.addEventListener("change", () => loadPostsByType(elPT.value));
  }

  if (btnSelectAll && elPosts) {
    btnSelectAll.addEventListener("click", () => {
      Array.from(elPosts.options).forEach((o) => (o.selected = true));
      resetDownloads();
    });
  }

  if (btnClear && elPosts) {
    btnClear.addEventListener("click", () => {
      Array.from(elPosts.options).forEach((o) => (o.selected = false));
      resetDownloads();
    });
  }

  if (btnAnalyze) btnAnalyze.addEventListener("click", analyzeSelected);
  if (btnConvert) btnConvert.addEventListener("click", convertToDraft);
  if (btnDuplicate) btnDuplicate.addEventListener("click", duplicateNoRewrite);

  if (btnDocx) btnDocx.addEventListener("click", downloadDocx);
  if (btnHtml) btnHtml.addEventListener("click", downloadHtml);

  if (btnStop) {
    btnStop.addEventListener("click", () => {
      STOP = true;
      log("Stop requested.");
      setBusy(true, "Stopping…");
      setTimeout(() => setBusy(false, "Stopped."), 250);
    });
  }

  // Boot
  installSelectBoxGuards(elPreview);
  installSelectBoxGuards(elOutput);
  installSelectBoxGuards(elResults);

  if (CFG.defaultType) loadPostsByType(CFG.defaultType);
})();
