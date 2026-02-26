/* ========================================================================
 * MYLS – AI FAQs Subtab JS (v1.4)
 * File: assets/js/myls-ai-faqs.js
 *
 * Features:
 * - Loads posts by type (includes drafts)
 * - Client-side search filter
 * - Generate FAQs (Preview)
 * - Download .docx / .html (enabled when URLs returned)
 * - Batch: Generate + Insert into MYLS FAQs (with optional overwrite)
 * - Batch: Delete auto-generated MYLS FAQs
 * - Local Ctrl+A inside preview panes (doesn't select entire admin page)
 *
 * AJAX actions are injected via window.MYLS_AI_FAQS in subtab-faqs.php.
 * 
 * FIXED v1.3:
 * - Now properly passes max_tokens and temperature parameters for EACH post
 *   in the batch loop, ensuring settings are applied consistently across all
 *   posts instead of falling back to saved option values.
 * ======================================================================== */

(function () {
  "use strict";

  if (!window.MYLS_AI_FAQS) return;
  const CFG = window.MYLS_AI_FAQS;

  // -----------------------------
  // DOM helpers
  // -----------------------------
  const $ = (sel) => document.querySelector(sel);

  const elPT = $("#myls_ai_faqs_pt");
  const elSearch = $("#myls_ai_faqs_search");
  const elPosts = $("#myls_ai_faqs_posts");

  const btnSelectAll = $("#myls_ai_faqs_select_all");
  const btnClear = $("#myls_ai_faqs_clear");
  const btnGenerate = $("#myls_ai_faqs_generate");
  const btnInsert = $("#myls_ai_faqs_insert_acf");
  const btnDeleteAuto = $("#myls_ai_faqs_delete_auto");
  const btnStop = $("#myls_ai_faqs_stop");

  const cbAllowLinks = $("#myls_ai_faqs_allow_links");
  const cbReplaceExisting = $("#myls_ai_faqs_acf_replace");
  const cbSkipExisting = $("#myls_ai_faqs_skip_existing");
  const elVariant = $("#myls_ai_faqs_variant");

  const btnDocx = $("#myls_ai_faqs_docx");
  const btnHtml = $("#myls_ai_faqs_html");

  const elSpinner = $("#myls_ai_faqs_spinner");
  const elStatus = $("#myls_ai_faqs_status");
  const elCount = $("#myls_ai_faqs_count");

  const elPreview = $("#myls_ai_faqs_preview");
  const elOutput = $("#myls_ai_faqs_output");
  const elResults = $("#myls_ai_faqs_results");
  const elLoadedHint = $("#myls_ai_faqs_loaded_hint");

  const elPrompt = $("#myls_ai_faqs_prompt_template");
  const elTokens = $("#myls_ai_faqs_tokens");
  const elTemperature = $("#myls_ai_faqs_temperature");

  // -----------------------------
  // State
  // -----------------------------
  let STOP = false;
  let processed = 0;

  // Full (unfiltered) post list returned from the server for the current post type.
  let allPosts = [];

  // Most recent output URLs (enables download buttons)
  let lastDocxUrl = "";
  let lastHtmlUrl = "";
  let lastHtml = "";

  // -----------------------------
  // UI helpers
  // -----------------------------
  function escapeHtml(str) {
    return String(str || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function setBusy(isBusy, msg) {
    if (elSpinner) elSpinner.style.display = isBusy ? "inline-flex" : "none";
    if (elStatus) elStatus.textContent = msg || "";

    if (btnStop) btnStop.disabled = !isBusy;
    if (btnGenerate) btnGenerate.disabled = isBusy;

    // Downloads
    if (btnDocx) btnDocx.disabled = isBusy || !lastDocxUrl;
    if (btnHtml) btnHtml.disabled = isBusy || !lastHtmlUrl;

    // Batch actions require selection
    const hasSel = !!getSelectedIDs().length;
    if (btnInsert) btnInsert.disabled = isBusy || !hasSel;
    if (btnDeleteAuto) btnDeleteAuto.disabled = isBusy || !hasSel;
  }

  /** Update status bar with live progress */
  function setProgress(current, total, title) {
    if (elStatus) {
      const pct = total > 0 ? Math.round((current / total) * 100) : 0;
      elStatus.textContent = `Processing ${current}/${total} (${pct}%) — ${title || ''}`;
    }
  }

  function log(msg, level) {
    if (!elResults) return;
    const t = new Date().toLocaleTimeString();
    const prefix = level === 'error' ? '❌' : level === 'warn' ? '⚠' : level === 'success' ? '✔' : level === 'skip' ? '⏭' : level === 'info' ? 'ℹ' : '';
    elResults.textContent = `[${t}] ${prefix} ${msg}\n` + elResults.textContent;
  }

  /** Detailed log for generation results — shows everything the server returns */
  function logGenerationResult(data, title, postId, index, total) {
    if (!elResults) return;
    const t = new Date().toLocaleTimeString();
    const faqCount   = data.faq_count ?? '?';
    const attempts   = data.attempts ?? 1;
    const cityState  = data.city_state || '';
    const elapsed    = data.log?.elapsed_ms ? `${(data.log.elapsed_ms / 1000).toFixed(1)}s` : '';
    const model      = data.log?.model || '';
    const tokens     = data.log?.tokens || '';
    const temp       = data.log?.temperature || '';
    const words      = data.log?.output_words || '';
    const chars      = data.log?.output_chars || '';
    const variant    = data.log?.variant || currentVariant();
    const retries    = data.retries && data.retries.length ? data.retries : [];

    let lines = [];
    lines.push(`[${t}] ✔ ${title} — ${faqCount} FAQs generated [${index}/${total}]`);

    // Detail line
    let details = [];
    if (model)   details.push(`Model: ${model}`);
    const provider = data.log?.provider || '';
    if (provider) details.push(`Provider: ${provider}`);
    if (elapsed) details.push(`Time: ${elapsed}`);
    if (words)   details.push(`Words: ${words}`);
    if (chars)   details.push(`Chars: ${chars}`);
    if (tokens)  details.push(`Max tokens: ${tokens}`);
    if (temp)    details.push(`Temp: ${temp}`);
    if (variant) details.push(`Variant: ${variant}`);
    if (details.length) lines.push(`    ${details.join(' · ')}`);

    // City/state
    if (cityState) lines.push(`    City: ${cityState}`);

    // Retry info
    if (attempts > 1) {
      lines.push(`    ⚠ Required ${attempts} attempt(s)`);
      retries.forEach(r => lines.push(`      → ${r}`));
    }

    // Dropped FAQs
    const dropped = data.dropped_faqs || 0;
    if (dropped > 0) {
      lines.push(`    ⚠ Dropped ${dropped} bad FAQ(s) (code/error/garbled)`);
    }

    // Filled FAQs (replacements generated)
    const filled = data.filled_faqs || 0;
    if (filled > 0) {
      lines.push(`    ✔ Replaced with ${filled} fresh FAQ(s) via fill pass`);
    } else if (dropped > 0) {
      lines.push(`    ℹ Fill pass did not generate replacements — ${faqCount} FAQs kept`);
    }

    // Preview snippet
    if (data.preview) {
      lines.push(`    Preview: ${data.preview}`);
    }

    elResults.textContent = lines.join('\n') + '\n' + elResults.textContent;
  }

  /** Detailed log for generation errors */
  function logGenerationError(title, postId, errorMsg, index, total, responseData) {
    if (!elResults) return;
    const t = new Date().toLocaleTimeString();
    let lines = [];
    lines.push(`[${t}] ❌ FAILED: ${title} [${index}/${total}]`);
    lines.push(`    Error: ${errorMsg}`);

    const rd = responseData || {};

    // Always log full error data to console for debugging
    console.warn('[MYLS FAQ] Generation failed:', { title, postId, errorMsg, responseData: rd });

    // Retry reasons (per-attempt breakdown)
    const reasons = rd.reasons || [];
    if (reasons.length > 0) {
      lines.push(`    Attempts: ${reasons.length}`);
      reasons.forEach(r => lines.push(`      → ${r}`));
    }

    // Rich diagnostics from the server
    const d = rd.diag || {};
    if (d.model || d.provider) {
      let detail = `    Model: ${d.model || '?'}`;
      if (d.provider) detail += ` · Provider: ${d.provider}`;
      lines.push(detail);
    }
    if (d.raw_h3_count !== undefined) {
      lines.push(`    Raw output: ${d.raw_words || 0} words · ${d.raw_length || 0} chars · ${d.raw_h3_count} h3s · ${d.raw_p_count} p tags · ${d.clean_pairs} clean pairs`);
    }
    if (d.tokens || d.variant) {
      lines.push(`    Settings: tokens=${d.tokens || '?'} · temp=${d.temperature || '?'} · variant=${d.variant || '?'} · page_text=${d.page_text_len || 0} chars`);
    }
    if (d.has_markdown) lines.push(`    ⚠ Raw output contained markdown`);
    if (d.has_code_fence) lines.push(`    ⚠ Raw output contained code fences`);

    // Validation detail
    const v = d.validation || {};
    if (v.reason) {
      lines.push(`    Validation: ${v.reason} (faq_count: ${v.faq_count})`);
    }

    // Raw preview (first 300 chars of what the AI actually returned)
    const preview = rd.raw_preview || '';
    if (preview) {
      lines.push(`    Preview: ${preview.substring(0, 300)}${preview.length > 300 ? '…' : ''}`);
    }

    // Raw HTML preview (shows tags to see if HTML was malformed)
    const rawHtml = (d.raw_first_500 || '').substring(0, 300);
    if (rawHtml && rawHtml !== preview) {
      lines.push(`    Raw HTML: ${rawHtml}${rawHtml.length >= 300 ? '…' : ''}`);
    }

    elResults.textContent = lines.join('\n') + '\n' + elResults.textContent;
  }

  /** Batch summary line */
  function logBatchSummary(stats, totalTime) {
    if (!elResults) return;
    const t = new Date().toLocaleTimeString();
    const parts = [];
    if (stats.generated) parts.push(`${stats.generated} generated`);
    if (stats.inserted)  parts.push(`${stats.inserted} inserted`);
    if (stats.skipped)   parts.push(`${stats.skipped} skipped`);
    if (stats.errors)    parts.push(`${stats.errors} errors`);
    const timeStr = totalTime ? ` in ${(totalTime / 1000).toFixed(1)}s` : '';
    const divider = '═'.repeat(50);
    elResults.textContent = `[${t}] ${divider}\n[${t}] BATCH COMPLETE: ${parts.join(', ')}${timeStr}\n[${t}] ${divider}\n` + elResults.textContent;
  }

  /** Batch start header */
  function logBatchStart(action, count, settings) {
    if (!elResults) return;
    const t = new Date().toLocaleTimeString();
    const divider = '═'.repeat(50);
    let lines = [];
    lines.push(`[${t}] ${divider}`);
    lines.push(`[${t}] BATCH START: ${action} — ${count} post(s)`);

    let details = [];
    if (settings.variant)     details.push(`Variant: ${settings.variant}`);
    if (settings.tokens)      details.push(`Max tokens: ${settings.tokens}`);
    if (settings.temperature) details.push(`Temp: ${settings.temperature}`);
    if (settings.allowLinks)  details.push(`Links: yes`);
    if (settings.replace)     details.push(`Replace existing: yes`);
    if (settings.skipExisting) details.push(`Skip existing: yes`);
    if (details.length) lines.push(`[${t}]    Settings: ${details.join(' · ')}`);

    lines.push(`[${t}] ${divider}`);
    elResults.textContent = lines.join('\n') + '\n' + elResults.textContent;
  }

  function getSelectedIDs() {
    return Array.from(elPosts?.selectedOptions || [])
      .map((o) => parseInt(o.value, 10))
      .filter(Boolean);
  }

  function getTitleForPost(postId) {
    const opt = Array.from(elPosts?.options || []).find((o) => parseInt(o.value, 10) === postId);
    return opt ? opt.textContent : `#${postId}`;
  }

  function clearPreviewPanes() {
    if (elPreview) elPreview.innerHTML = "";
    if (elOutput) elOutput.textContent = "";
  }

  function appendPreviewBlock(postId, title, html, raw) {
    if (elPreview) {
      const wrap = document.createElement("div");
      wrap.innerHTML = `
        <hr/>
        <p><strong>${escapeHtml(title)} (ID ${postId})</strong></p>
        ${(html || "").trim() || "<p><em>No output returned.</em></p>"}
      `;
      elPreview.appendChild(wrap);
    }

    if (elOutput) {
      const header = `\n\n==============================\n${title} (ID ${postId})\n==============================\n`;
      elOutput.textContent += header + String((raw || "").trim()) + "\n";
    }
  }

  function allowLinks() {
    return !!(cbAllowLinks && cbAllowLinks.checked);
  }

  function currentVariant() {
    const v = (elVariant && elVariant.value ? String(elVariant.value) : String(CFG.defaultVariant || "LONG")).toUpperCase();
    return v === "SHORT" ? "SHORT" : "LONG";
  }

  function currentTokens() {
    if (!elTokens) return null;
    const val = parseInt(elTokens.value, 10);
    return val > 0 ? val : null;
  }

  function currentTemperature() {
    if (!elTemperature) return null;
    const val = parseFloat(elTemperature.value);
    return !isNaN(val) ? val : null;
  }

  // Initialize variant selector to saved default
  if (elVariant && CFG.defaultVariant) {
    elVariant.value = currentVariant();
  }

  // -----------------------------
  // Checkbox persistence (localStorage)
  // -----------------------------
  const STORAGE_PREFIX = "myls_ai_faqs_";

  function persistCheckbox(cb, key) {
    if (!cb) return;
    const stored = localStorage.getItem(STORAGE_PREFIX + key);
    if (stored !== null) {
      cb.checked = stored === "1";
    }
    cb.addEventListener("change", () => {
      localStorage.setItem(STORAGE_PREFIX + key, cb.checked ? "1" : "0");
    });
  }

  persistCheckbox(cbAllowLinks, "allow_links");
  persistCheckbox(cbSkipExisting, "skip_existing");
  persistCheckbox(cbReplaceExisting, "acf_replace");

  // -----------------------------
  // Local Ctrl+A inside preview panes
  // -----------------------------
  function wireLocalSelectAll(el) {
    if (!el) return;

    el.addEventListener("focusin", () => el.classList.add("is-focused"));
    el.addEventListener("focusout", () => el.classList.remove("is-focused"));

    // Make focusable for key events
    if (!el.hasAttribute("tabindex")) el.setAttribute("tabindex", "0");

    el.addEventListener("keydown", (e) => {
      const isMac = (navigator.platform || "").toUpperCase().includes("MAC");
      const mod = isMac ? e.metaKey : e.ctrlKey;
      if (mod && (e.key === "a" || e.key === "A")) {
        e.preventDefault();
        e.stopPropagation();

        const sel = window.getSelection();
        if (!sel) return;

        const range = document.createRange();
        range.selectNodeContents(el);
        sel.removeAllRanges();
        sel.addRange(range);
      }
    });
  }

  wireLocalSelectAll(elPreview);
  wireLocalSelectAll(elOutput);
  wireLocalSelectAll(elResults);

  // -----------------------------
  // AJAX helper
  // -----------------------------
  async function postAJAX(action, data) {
    const body = new URLSearchParams();
    body.set("action", action);
    body.set("_ajax_nonce", CFG.nonce);

    Object.keys(data || {}).forEach((k) => {
      const v = data[k];
      body.set(k, v === undefined || v === null ? "" : String(v));
    });

    const resp = await fetch(CFG.ajaxurl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: body.toString(),
    });

    // Capture raw text first so we can diagnose non-JSON responses
    const rawText = await resp.text();
    let json;
    try {
      json = JSON.parse(rawText);
    } catch (parseErr) {
      // Build a useful error message based on what we got back
      const status = resp.status;
      let detail = "Bad JSON response";
      if (status === 504 || status === 524) {
        detail = "Server timeout (HTTP " + status + "). Try reducing batch size or increasing server max_execution_time.";
      } else if (status === 502 || status === 503) {
        detail = "Server unavailable (HTTP " + status + "). The server may be overloaded — try again in a moment.";
      } else if (status === 500) {
        detail = "Server error (HTTP 500). Check PHP error logs for details.";
      } else if (status === 0 || !resp.ok) {
        detail = "HTTP " + status + ": " + (rawText.substring(0, 200) || "(empty response)");
      } else {
        detail = "Non-JSON response (HTTP " + status + "): " + rawText.substring(0, 200);
      }
      throw new Error(detail);
    }

    if (!json || typeof json !== "object") throw new Error("Bad JSON response (HTTP " + resp.status + ").");
    if (!json.success) {
      const err = new Error((json.data && json.data.message) || json.message || "Request failed.");
      err.responseData = json.data || {};  // carry full response for error logging
      throw err;
    }
    return json.data || {};
  }

  // -----------------------------
  // Load + filter posts
  // -----------------------------
  async function loadPostsByType(pt) {
    STOP = false;
    setBusy(true, "Loading posts…");

    try {
      const action = CFG.action_get_posts;
      if (!action) throw new Error("Missing AJAX action for get_posts.");

      const data = await postAJAX(action, { post_type: pt });
      allPosts = Array.isArray(data.posts) ? data.posts : [];

      renderPosts(allPosts);
      if (elLoadedHint) elLoadedHint.textContent = `${allPosts.length} loaded.`;

      // Enable batch buttons if there is a selection
      if (btnInsert) btnInsert.disabled = !getSelectedIDs().length;
      if (btnDeleteAuto) btnDeleteAuto.disabled = !getSelectedIDs().length;
    } catch (e) {
      alert(`Failed to load posts: ${e.message}`);
    }

    setBusy(false, "");
  }

  function renderPosts(posts) {
    if (!elPosts) return;
    elPosts.innerHTML = "";

    (posts || []).forEach((p) => {
      const opt = document.createElement("option");
      opt.value = String(p.id);
      opt.textContent = `${p.has_faqs ? '✓ ' : ''}${p.title} (#${p.id})`;
      elPosts.appendChild(opt);
    });
  }

  function applySearchFilter() {
    const q = String(elSearch?.value || "").trim().toLowerCase();
    if (!q) {
      renderPosts(allPosts);
      if (elLoadedHint) elLoadedHint.textContent = `${allPosts.length} loaded.`;
      return;
    }

    const filtered = allPosts.filter((p) => {
      const hay = `${p.title} ${p.id}`.toLowerCase();
      return hay.includes(q);
    });

    renderPosts(filtered);
    if (elLoadedHint) elLoadedHint.textContent = `${filtered.length} match(es) (of ${allPosts.length}).`;
  }

  // -----------------------------
  // Generate (Preview only)
  // -----------------------------
  async function generateSelected() {
    const ids = getSelectedIDs();
    if (!ids.length) {
      alert("Select at least one post.");
      return;
    }

    STOP = false;
    processed = 0;
    if (elCount) elCount.textContent = "0";

    clearPreviewPanes();
    setBusy(true, "Generating…");

    const total = ids.length;
    let stats = { generated: 0, skipped: 0, errors: 0 };
    const batchStart = Date.now();

    logBatchStart('FAQ Generation (Preview)', total, {
      variant: currentVariant(),
      tokens: currentTokens(),
      temperature: currentTemperature(),
      allowLinks: allowLinks(),
    });

    const genAction = CFG.action_generate;
    if (!genAction) throw new Error("Missing AJAX action for generate.");

    for (const postId of ids) {
      if (STOP) break;

      const title = getTitleForPost(postId);
      const idx = processed + 1;

      log(`Generating FAQs for ${title}… [${idx}/${total}]`, 'info');
      setProgress(idx, total, title);

      try {
        const data = await postAJAX(genAction, {
          post_id: postId,
          allow_links: allowLinks() ? "1" : "0",
          variant: currentVariant(),
          template: elPrompt ? elPrompt.value : "",
          tokens: currentTokens(),
          temperature: currentTemperature(),
        });

        const outHtml = String(data.html || "").trim();
        appendPreviewBlock(postId, title, outHtml, String(data.raw || "").trim());

        lastHtml = outHtml;
        lastDocxUrl = String(data.doc_url || "").trim();
        lastHtmlUrl = String(data.html_url || "").trim();

        logGenerationResult(data, title, postId, idx, total);
        stats.generated++;

        processed++;
        if (elCount) elCount.textContent = String(processed);
      } catch (e) {
        logGenerationError(title, postId, e.message, idx, total, e.responseData);
        stats.errors++;
      }
    }

    logBatchSummary(stats, Date.now() - batchStart);
    setBusy(false, STOP ? "Stopped." : "Done.");
  }

  // -----------------------------
  // Batch: Generate + Insert into MYLS
  // -----------------------------
  async function insertIntoMYLSBatch() {
    const ids = getSelectedIDs();
    if (!ids.length) {
      alert("Select at least one post.");
      return;
    }

    STOP = false;
    processed = 0;
    if (elCount) elCount.textContent = "0";

    clearPreviewPanes();
    setBusy(true, "Generating + inserting…");

    const replaceExisting = !!(cbReplaceExisting && cbReplaceExisting.checked);
    const skipExisting = !!(cbSkipExisting && cbSkipExisting.checked) && !replaceExisting;
    const total = ids.length;
    let stats = { generated: 0, inserted: 0, skipped: 0, errors: 0, totalFaqs: 0 };
    const batchStart = Date.now();

    logBatchStart('FAQ Generate + Insert', total, {
      variant: currentVariant(),
      tokens: currentTokens(),
      temperature: currentTemperature(),
      allowLinks: allowLinks(),
      replace: replaceExisting,
      skipExisting: skipExisting,
    });

    const genAction = CFG.action_generate;
    const insertAction = CFG.action_insert_myls || CFG.action_insert_acf;
    const checkAction = CFG.action_check_existing_myls;
    if (!genAction) throw new Error("Missing AJAX action for generate.");
    if (!insertAction) throw new Error("Missing AJAX action for insert.");

    for (const postId of ids) {
      if (STOP) break;

      const title = getTitleForPost(postId);
      const idx = processed + 1;

      // Optional: skip if existing MYLS FAQs are present
      if (skipExisting && checkAction) {
        try {
          const chk = await postAJAX(checkAction, { post_id: postId });
          if (chk && chk.has_faqs) {
            log(`Skipped ${title}: ${chk.count} existing FAQs found [${idx}/${total}]`, 'skip');
            stats.skipped++;
            processed++;
            if (elCount) elCount.textContent = String(processed);
            continue;
          }
        } catch (e) {
          log(`Existing-FAQ check failed for ${title}: ${e.message}`, 'warn');
        }
      }

      // ── Step 1: Generate ──
      log(`Generating FAQs for ${title}… [${idx}/${total}]`, 'info');
      setProgress(idx, total, title);
      const genStart = Date.now();

      try {
        const data = await postAJAX(genAction, {
          post_id: postId,
          allow_links: allowLinks() ? "1" : "0",
          variant: currentVariant(),
          template: elPrompt ? elPrompt.value : "",
          tokens: currentTokens(),
          temperature: currentTemperature(),
        });

        const outHtml = String(data.html || "").trim();
        appendPreviewBlock(postId, title, outHtml, String(data.raw || "").trim());

        lastHtml = outHtml;
        lastDocxUrl = String(data.doc_url || "").trim();
        lastHtmlUrl = String(data.html_url || "").trim();

        // Log generation details
        logGenerationResult(data, title, postId, idx, total);
        stats.generated++;
        stats.totalFaqs += (data.faq_count || 0);

        // ── Step 2: Insert ──
        log(`Inserting ${data.faq_count || '?'} FAQs into MYLS for ${title}…`, 'info');

        const ins = await postAJAX(insertAction, {
          post_id: postId,
          html: outHtml,
          replace_existing: replaceExisting ? "1" : "0",
        });

        const insertCount = ins.inserted_count ?? 0;
        const skipCount   = ins.skipped_count ?? 0;
        const totalRows   = ins.total_rows ?? 0;

        log(`Inserted ${insertCount} FAQs, skipped ${skipCount}, total rows: ${totalRows} for ${title}`, 'success');
        stats.inserted++;

        processed++;
        if (elCount) elCount.textContent = String(processed);
      } catch (e) {
        logGenerationError(title, postId, e.message, idx, total, e.responseData);
        stats.errors++;
      }
    }

    // Final summary
    const elapsed = Date.now() - batchStart;
    logBatchSummary(stats, elapsed);

    // Extra summary details
    if (elResults) {
      const t = new Date().toLocaleTimeString();
      const avgTime = stats.generated ? ((elapsed / 1000) / stats.generated).toFixed(1) : '0';
      const avgFaqs = stats.generated ? (stats.totalFaqs / stats.generated).toFixed(1) : '0';
      elResults.textContent = `[${t}]    Avg: ${avgTime}s/post · ${avgFaqs} FAQs/post · Total FAQs: ${stats.totalFaqs}\n` + elResults.textContent;
    }

    setBusy(false, STOP ? "Stopped." : "Done.");
  }

  // -----------------------------
  // Batch: Delete auto-generated MYLS FAQs
  // -----------------------------
  async function deleteAutoFromMYLS() {
    const ids = getSelectedIDs();
    if (!ids.length) {
      alert("Select at least one post.");
      return;
    }

    if (!confirm("Delete ALL MYLS FAQ items for the selected post(s)? This cannot be undone.")) return;

    STOP = false;
    processed = 0;
    if (elCount) elCount.textContent = "0";

    setBusy(true, "Deleting MYLS FAQs…");
    log(`Delete FAQs queued: ${ids.length} post(s).`);

    const deleteAction = CFG.action_delete_auto_myls || CFG.action_delete_auto_acf; // back-compat
    if (!deleteAction) throw new Error("Missing AJAX action for delete-auto.");

    for (const postId of ids) {
      if (STOP) break;

      try {
        const data = await postAJAX(deleteAction, { post_id: postId });
        const deleted = data.deleted_count ?? 0;
        const total = data.total_rows ?? 0;
        log(`MYLS delete OK for #${postId}: deleted ${deleted} FAQ(s).`);
        processed++;
        if (elCount) elCount.textContent = String(processed);
      } catch (e) {
        log(`Delete error for #${postId}: ${e.message}`);
      }
    }

    setBusy(false, STOP ? "Stopped." : "Done.");
  }

  // -----------------------------
  // Downloads
  // -----------------------------
  function openUrl(url) {
    if (!url) return;
    window.open(url, "_blank", "noopener");
  }

  if (btnDocx) btnDocx.addEventListener("click", () => openUrl(lastDocxUrl));
  if (btnHtml) btnHtml.addEventListener("click", () => openUrl(lastHtmlUrl));

  // -----------------------------
  // Events
  // -----------------------------
  if (elPT) {
    elPT.addEventListener("change", () => loadPostsByType(elPT.value));
  }

  if (elSearch) {
    let t = null;
    elSearch.addEventListener("input", () => {
      if (t) window.clearTimeout(t);
      t = window.setTimeout(applySearchFilter, 80);
    });
  }

  if (btnSelectAll) {
    btnSelectAll.addEventListener("click", () => {
      Array.from(elPosts?.options || []).forEach((o) => (o.selected = true));
      setBusy(false, "");
    });
  }

  if (btnClear) {
    btnClear.addEventListener("click", () => {
      Array.from(elPosts?.options || []).forEach((o) => (o.selected = false));
      if (btnInsert) btnInsert.disabled = true;
      if (btnDeleteAuto) btnDeleteAuto.disabled = true;
    });
  }

  if (elPosts) {
    elPosts.addEventListener("change", () => {
      const hasSel = !!getSelectedIDs().length;
      if (btnInsert) btnInsert.disabled = STOP || !hasSel;
      if (btnDeleteAuto) btnDeleteAuto.disabled = STOP || !hasSel;
    });
  }

  if (btnGenerate) btnGenerate.addEventListener("click", generateSelected);
  if (btnInsert) btnInsert.addEventListener("click", insertIntoMYLSBatch);
  if (btnDeleteAuto) btnDeleteAuto.addEventListener("click", deleteAutoFromMYLS);

  if (btnStop) {
    btnStop.addEventListener("click", () => {
      STOP = true;
      log("Stop requested.");
      setBusy(true, "Stopping…");
      setTimeout(() => setBusy(false, "Stopped."), 250);
    });
  }

  // Boot
  loadPostsByType(CFG.defaultType);
})();
