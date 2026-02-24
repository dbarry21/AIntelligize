/* AIntelligize – AI Tab JS
 * Path: assets/js/myls-ai.js
 *
 * Handles:
 *  - Meta Titles & Descriptions subtab:
 *      - Populate posts list by post type (bootstrap JSON / localized / AJAX)
 *      - Filter
 *      - Select All / Clear
 *      - Reset prompt
 *      - Generate titles/descriptions with dry-run + overwrite
 *
 *  - Excerpts subtab:
 *      - Populate posts list by post type (bootstrap JSON / localized / AJAX)
 *      - Filter
 *      - Select All / Clear
 *      - Reset + Save excerpt prompt template
 *      - Generate excerpts with dry-run + overwrite
 */
(function($){
  'use strict';

  /* -------------------------------------------------------------
   * Shared utilities
   * ------------------------------------------------------------- */

  function hasLocalized(){
    return (typeof window.MYLS_AI === 'object' && window.MYLS_AI !== null);
  }

  function getAjaxUrl(){
    if (hasLocalized() && window.MYLS_AI.ajaxurl) return window.MYLS_AI.ajaxurl;
    if (typeof window.ajaxurl === 'string') return window.ajaxurl;
    return '';
  }

  function getNonce($fallbackInput){
    // Prefer per-tab hidden nonce input if provided; fallback to localized nonce.
    var v = ($fallbackInput && $fallbackInput.length) ? ($fallbackInput.val() || '') : '';
    if (v) return v;
    if (hasLocalized() && window.MYLS_AI.nonce) return window.MYLS_AI.nonce;
    return '';
  }

  function optionRow(id, title){
    return $('<option>').val(String(id)).text(title || '(no title)');
  }

  function safeParseJSONFromEl(elId){
    var el = document.getElementById(elId);
    if (!el) return null;
    try {
      var txt = el.textContent || el.innerText || '';
      var data = JSON.parse(txt || '{}');
      return data || null;
    } catch(e){
      return null;
    }
  }

  function applyOptionFilter($select, query){
    var q = (query || '').toLowerCase().trim();
    $select.find('option').each(function(){
      var txt = (this.textContent || '').toLowerCase();
      // Use "hidden" attribute (works reliably for <option> filtering)
      this.hidden = !!(q && txt.indexOf(q) === -1);
    });
  }

  function selectAllVisibleOptions($select){
    $select.find('option').each(function(){
      if (!this.hidden) this.selected = true;
    });
    $select.trigger('change');
  }

  function clearAllOptions($select){
    $select.find('option').prop('selected', false);
    $select.trigger('change');
  }

  function ajaxPostJSON(ajaxurl, data){
    return $.ajax({
      url: ajaxurl,
      method: 'POST',
      dataType: 'json',
      data: data
    });
  }

  /* -------------------------------------------------------------
   * Meta subtab
   * ------------------------------------------------------------- */
  function initMetaSubtab(){
    const $pt        = $('#myls_ai_pt');
    const $filter    = $('#myls_ai_filter');
    const $posts     = $('#myls_ai_posts');
    const $selectAll = $('#myls_ai_select_all');
    const $clear     = $('#myls_ai_clear');
    const $overwrite = $('#myls_ai_overwrite');
    const $dryrun    = $('#myls_ai_dryrun');

    const $titlePrompt = $('#myls_ai_title_prompt');
    const $descPrompt  = $('#myls_ai_desc_prompt');
    const $resetTitle  = $('#myls_ai_reset_title_prompt');
    const $resetDesc   = $('#myls_ai_reset_desc_prompt');

    const $btnGenTitle = $('#myls_ai_gen_title');
    const $btnGenDesc  = $('#myls_ai_gen_desc');
    const $btnGenBoth  = $('#myls_ai_gen_both');
    const $btnStop     = $('#myls_ai_stop');
    const $results     = $('#myls_ai_results');
    const $progress    = $('#myls_ai_progress');

    // Not on meta subtab
    if (!$pt.length || !$posts.length || !$results.length) return;

    const ajaxurl = getAjaxUrl();
    const nonce   = getNonce($('#myls_ai_nonce'));
    const CHUNK   = 5; // posts per AJAX call
    let stopping  = false;

    if (!ajaxurl) console.warn('[MYLS AI] ajaxurl not set; check localization/admin context.');

    // Cache posts per postType: { pt: [{id,title}] }
    const cache = Object.create(null);

    function log(msg){
      try {
        const now = new Date().toLocaleTimeString();
        $results.append(`[${now}] ${msg}\n`);
        $results.scrollTop($results[0].scrollHeight);
      } catch(e){}
    }

    function fillPosts(items){
      $posts.empty();
      (items || []).forEach(it => $posts.append(optionRow(it.id, it.title)));
    }

    function preloadFromLocalized(pt){
      if (!hasLocalized() || !window.MYLS_AI.posts_by_type) return null;
      const arr = window.MYLS_AI.posts_by_type[pt];
      if (Array.isArray(arr) && arr.length) return arr;
      return null;
    }

    function fromBootstrapJSON(){
      const data = safeParseJSONFromEl('myls_ai_bootstrap_posts');
      if (!data || !Array.isArray(data.posts)) return null;
      return data;
    }

    function fetchPosts(pt){
      if (!ajaxurl) { log('AJAX URL missing; cannot load posts.'); return $.Deferred().reject(); }

      if (cache[pt]) {
        fillPosts(cache[pt]);
        return $.Deferred().resolve(cache[pt]);
      }

      const loc = preloadFromLocalized(pt);
      if (loc) {
        cache[pt] = loc;
        fillPosts(loc);
        return $.Deferred().resolve(loc);
      }

      log(`Loading posts for post type "${pt}"...`);

      return ajaxPostJSON(ajaxurl, { action: 'myls_ai_posts_by_type', pt: pt, nonce: nonce })
        .done(function(res){
          if (res && res.ok && Array.isArray(res.posts)) {
            cache[pt] = res.posts;
            fillPosts(res.posts);
            log(`Loaded ${res.posts.length} post(s).`);
          } else {
            log('Failed to load posts (invalid response).');
          }
        })
        .fail(function(xhr){
          log('Failed to load posts (AJAX error). Check ajaxurl/nonce.');
          try {
            if (xhr && xhr.responseText) log(String(xhr.responseText).slice(0, 300));
          } catch(e){}
        });
    }

    function collectSelectedIds(){
      const ids = [];
      $posts.find('option:selected').each(function(){
        const v = parseInt($(this).val(), 10);
        if (v) ids.push(v);
      });
      return ids;
    }

    function setBusy(busy){
      $btnGenTitle.prop('disabled', busy);
      $btnGenDesc.prop('disabled', busy);
      $btnGenBoth.prop('disabled', busy);
      $pt.prop('disabled', busy);
      $posts.prop('disabled', busy);
      $btnStop.toggle(!!busy);
      if (!busy) { $progress.text(''); stopping = false; }
    }

    /* ── Chunked generator: sends CHUNK posts at a time ── */
    function runGenerate(kind, opts){
      opts = opts || {};
      const allIds = collectSelectedIds();
      if (!allIds.length) { log('Select at least one post.'); return $.Deferred().reject(); }
      if (!ajaxurl) { log('AJAX URL missing; cannot generate.'); return $.Deferred().reject(); }

      const pt        = $pt.val();
      const overwrite = $overwrite.is(':checked') ? 1 : 0;
      const dryrun    = $dryrun.is(':checked') ? 1 : 0;
      const prompt    = (kind === 'title') ? ($titlePrompt.val() || '') : ($descPrompt.val() || '');

      const LOG   = window.mylsLog;
      const total = allIds.length;
      let processed = 0;
      let stats = { saved: 0, skipped: 0, errors: 0 };
      let tracker = LOG ? LOG.createTracker() : null;

      stopping = false;
      setBusy(true);

      // Header
      if (opts.appendLog) {
        if (LOG) {
          LOG.append('\n' + LOG.SEP + '\n', $results[0]);
          LOG.append(LOG.batchStart('Meta ' + (kind === 'title' ? 'Titles' : 'Descriptions'), total), $results[0]);
        } else {
          log('\n--- ' + (kind === 'title' ? 'Titles' : 'Descriptions') + ' ---');
        }
      } else if (LOG) {
        LOG.clear($results[0], LOG.batchStart('Meta ' + (kind === 'title' ? 'Titles' : 'Descriptions'), total));
      } else {
        log(`Starting ${kind === 'title' ? 'Title' : 'Description'} generation for ${total} post(s)...`);
      }

      // Split into chunks
      const chunks = [];
      for (let i = 0; i < allIds.length; i += CHUNK) {
        chunks.push(allIds.slice(i, i + CHUNK));
      }

      const deferred = $.Deferred();

      function processChunk(ci) {
        if (stopping) {
          $progress.text('Stopped.');
          log('⏹ Stopped by user at ' + processed + '/' + total + '.');
          finish();
          return;
        }
        if (ci >= chunks.length) {
          finish();
          return;
        }

        const chunk = chunks[ci];
        const from  = processed + 1;
        const to    = Math.min(processed + chunk.length, total);
        $progress.text('Processing ' + from + '–' + to + ' of ' + total + '…');

        ajaxPostJSON(ajaxurl, {
          action: 'myls_ai_generate_meta',
          kind: kind,
          pt: pt,
          ids: chunk,
          prompt: prompt,
          overwrite: overwrite,
          dryrun: dryrun,
          nonce: nonce
        }).done(function(res){
          if (!res || !res.ok) {
            log('Chunk failed or returned invalid response.');
            stats.errors += chunk.length;
            processed += chunk.length;
            return;
          }
          (res.items || []).forEach(function(row){
            processed++;
            const id = row.id, title = row.post_title || '(no title)';
            if (row.error) {
              if (LOG) {
                LOG.append(LOG.formatError(id, { message: row.error }, { index: processed, total: total }), $results[0]);
              } else {
                log(`#${id} ${title} — ERROR: ${row.error}`);
              }
              stats.errors++;
              return;
            }
            if (!row.saved && !row.dryrun) {
              if (LOG) {
                LOG.append(LOG.formatSkipped(id, { reason: row.msg || 'exists' }, { index: processed, total: total }), $results[0]);
              } else {
                log(`#${id} ${title} — SKIPPED: ${row.msg || ''}`);
              }
              stats.skipped++;
              return;
            }
            if (LOG && row.log) {
              const entryData = {
                status: row.saved ? 'saved' : (row.dryrun ? 'dryrun' : 'skipped'),
                post_id: id,
                preview: row.new || '',
                log: Object.assign({}, row.log, {
                  page_title: title,
                  old_value: row.old || '(empty)',
                  new_value: row.new || '(empty)',
                })
              };
              LOG.append(LOG.formatEntry(id, entryData, { index: processed, total: total, handler: 'Meta ' + kind }), $results[0]);
              if (tracker) tracker.track(entryData);
            } else {
              const savedTxt = row.saved ? 'SAVED' : (row.dryrun ? 'PREVIEW' : 'SKIPPED');
              log(`#${id} ${title} — ${savedTxt}\n  old: ${row.old || '(empty)'}\n  new: ${row.new || '(empty)'}\n`);
            }
            if (row.saved) stats.saved++;
          });
        }).fail(function(xhr){
          var httpStatus = xhr && xhr.status ? xhr.status : '?';
          log('AJAX error on chunk ' + (ci+1) + ' (HTTP ' + httpStatus + '). Continuing...');
          stats.errors += chunk.length;
          processed += chunk.length;
        }).always(function(){
          // Yield to browser before next chunk
          setTimeout(function(){ processChunk(ci + 1); }, 50);
        });
      }

      function finish() {
        if (LOG) {
          LOG.append(LOG.batchSummary(tracker ? tracker.getSummary(stats) : stats), $results[0]);
        } else if (stats) {
          log('Done. Saved: ' + stats.saved + ', Skipped: ' + stats.skipped + ', Errors: ' + stats.errors);
        }
        if (!opts.skipBusyReset) setBusy(false);
        deferred.resolve(stats);
      }

      // Start first chunk
      processChunk(0);
      return deferred.promise();
    }

    // ---------- Init (first paint) ----------
    (function initFirstPaint(){
      const boot = fromBootstrapJSON();
      const initialPT = $pt.val();

      if (boot && boot.post_type === initialPT) {
        cache[initialPT] = boot.posts || [];
        fillPosts(cache[initialPT]);
        return;
      }
      fetchPosts(initialPT);
    })();

    // ---------- Events ----------
    $pt.on('change', function(){
      $filter.val('');
      fetchPosts($(this).val());
    });

    $filter.on('input', function(){
      applyOptionFilter($posts, $(this).val());
    });

    $selectAll.on('click', function(){ selectAllVisibleOptions($posts); });
    $clear.on('click', function(){ clearAllOptions($posts); });

    $resetTitle.on('click', function(){ $titlePrompt.val($titlePrompt[0].defaultValue || $titlePrompt.val()); });
    $resetDesc.on('click', function(){ $descPrompt.val($descPrompt[0].defaultValue  || $descPrompt.val()); });

    $btnGenTitle.on('click', function(){ runGenerate('title'); });
    $btnGenDesc.on('click',  function(){ runGenerate('desc'); });
    $btnStop.on('click', function(){ stopping = true; $btnStop.prop('disabled', true).text('Stopping…'); });
    $btnGenBoth.on('click', function(){
      // Run titles first (clear log), then descriptions (append to log)
      var titlePromise = runGenerate('title', { skipBusyReset: true });
      if (titlePromise && titlePromise.always) {
        titlePromise.always(function(){
          if (stopping) { setBusy(false); return; }
          runGenerate('desc', { appendLog: true });
        });
      }
    });
  }

  /* -------------------------------------------------------------
   * Excerpts subtab
   * ------------------------------------------------------------- */
  function initExcerptsSubtab(){

    const $exPt       = $('#myls_ai_ex_pt');
    const $exFilter   = $('#myls_ai_ex_filter');
    const $exPosts    = $('#myls_ai_ex_posts');
    const $exSelectAll= $('#myls_ai_ex_select_all');
    const $exClear    = $('#myls_ai_ex_clear');

    const $exOverwrite= $('#myls_ai_ex_overwrite');
    const $exDryrun   = $('#myls_ai_ex_dryrun');
    const $exPrompt   = $('#myls_ai_ex_prompt');
    const $exReset    = $('#myls_ai_ex_reset_prompt');
    const $exSave     = $('#myls_ai_ex_save_prompt');
    const $exGen      = $('#myls_ai_ex_gen');

    const $exResults  = $('#myls_ai_ex_results');
    const $exNonceInp = $('#myls_ai_ex_nonce');

    // Not on Excerpts subtab
    if (!$exPt.length || !$exPosts.length || !$exResults.length) return;

    const ajaxurl = getAjaxUrl();
    const nonce   = getNonce($exNonceInp);

    function exLog(msg){
      // Always log to console as well (helps when the results box is collapsed/hidden by CSS).
      try { console.log('[MYLS Excerpts]', msg); } catch(e){}
      try {
        const now = new Date().toLocaleTimeString();
        $exResults.append(`[${now}] ${msg}\n`);
        $exResults.scrollTop($exResults[0].scrollHeight);
      } catch(e){}
    }

    function exFillPosts(items){
      $exPosts.empty();
      (items || []).forEach(function(row){
        $exPosts.append(optionRow(row.id, row.title));
      });
    }

    function exSelectedIds(){
      // Keep as integers for server-side comfort
      const ids = [];
      $exPosts.find('option:selected').each(function(){
        const v = parseInt($(this).val(), 10);
        if (v) ids.push(v);
      });
      return ids;
    }

    function exFetchPosts(pt){
      if (!ajaxurl) { $exResults.text(''); exLog('ERROR: ajaxurl missing.'); return; }
      if (!nonce)   { $exResults.text(''); exLog('ERROR: nonce missing.'); return; }

      $exResults.text('');
      exLog(`Loading posts for post type "${pt}"...`);

      ajaxPostJSON(ajaxurl, {
        action: 'myls_ai_posts_by_type',
        pt: pt,
        nonce: nonce
      }).done(function(res){
        if (!res || !res.ok) {
          exLog('Failed to load posts (invalid response).');
          if (res && res.error) exLog(String(res.error));
          return;
        }
        exFillPosts(res.posts || []);
        exLog('Loaded ' + ((res.posts || []).length) + ' posts.');
      }).fail(function(xhr){
        exLog('AJAX error loading posts.');
        try {
          if (xhr && xhr.responseText) exLog(String(xhr.responseText).slice(0, 300));
        } catch(e){}
      });
    }

    // ---------- Init (first paint) ----------
    (function initExFirstPaint(){
      const boot = safeParseJSONFromEl('myls_ai_ex_bootstrap_posts');
      if (boot && Array.isArray(boot.posts)) {
        exFillPosts(boot.posts);
        // Do NOT auto-fetch here; user dropdown change should also work now.
      }
    })();

    // ---------- Events ----------
    $exPt.on('change', function(){
      $exFilter.val('');
      exFetchPosts($exPt.val());
    });

    $exFilter.on('input', function(){
      applyOptionFilter($exPosts, $(this).val());
    });

    $exSelectAll.on('click', function(){ selectAllVisibleOptions($exPosts); });
    $exClear.on('click', function(){ clearAllOptions($exPosts); });

    $exReset.on('click', function(){
      const def = $(this).data('default') || '';
      $exPrompt.val(def);
    });

    $exSave.on('click', function(){
      if (!ajaxurl) { $exResults.text(''); exLog('ERROR: ajaxurl missing.'); return; }
      if (!nonce)   { $exResults.text(''); exLog('ERROR: nonce missing.'); return; }

      $exResults.text('');
      exLog('Saving prompt template...');

      ajaxPostJSON(ajaxurl, {
        action: 'myls_ai_excerpt_save_prompt_v1',
        nonce: nonce,
        prompt: ($exPrompt.val() || '')
      }).done(function(res){
        // wp_send_json_success => { success:true, data:{...} }
        if (!res || !res.success) {
          exLog('Save failed.');
          if (res && res.data && res.data.message) exLog(String(res.data.message));
          return;
        }
        exLog('Saved.');
      }).fail(function(){
        exLog('AJAX error saving prompt.');
      });
    });

    $exGen.on('click', function(e){
      e.preventDefault();
      if (!ajaxurl) { $exResults.text(''); exLog('ERROR: ajaxurl missing.'); return; }
      if (!nonce)   { $exResults.text(''); exLog('ERROR: nonce missing.'); return; }

      const allIds = exSelectedIds();
      if (!allIds.length) { $exResults.text(''); exLog('Select at least one post.'); return; }

      const overwrite = $exOverwrite.is(':checked') ? 1 : 0;
      const dryrun    = $exDryrun.is(':checked') ? 1 : 0;
      const LOG = window.mylsLog;
      const total = allIds.length;
      const CHUNK = 1;  // Process one at a time to avoid server timeout
      let exStopping = false;
      let processed = 0;
      let stats = { saved: 0, skipped: 0, errors: 0 };
      let exTracker = LOG ? LOG.createTracker() : null;

      if (LOG) {
        LOG.clear($exResults[0], LOG.batchStart('Excerpts', total, { dryrun: dryrun ? 'Yes' : 'No' }));
      } else {
        $exResults.text('');
        exLog('Processing... generating excerpts for ' + total + ' posts...');
      }

      const oldTxt = $exGen.text();
      $exGen.prop('disabled', true).text('Processing...');

      // Build chunks
      const chunks = [];
      for (let i = 0; i < allIds.length; i += CHUNK) {
        chunks.push(allIds.slice(i, i + CHUNK));
      }

      function processExChunk(ci) {
        if (exStopping || ci >= chunks.length) {
          if (LOG) {
            LOG.append(LOG.batchSummary(exTracker ? exTracker.getSummary(stats) : stats), $exResults[0]);
          }
          $exGen.prop('disabled', false).text(oldTxt);
          return;
        }

        const chunk = chunks[ci];
        const idx = processed + 1;
        $exGen.text('Processing ' + idx + ' of ' + total + '…');

        ajaxPostJSON(ajaxurl, {
          action: 'myls_ai_excerpt_generate_v1',
          nonce: nonce,
          post_ids: chunk,
          overwrite: overwrite,
          dryrun: dryrun
        }).done(function(res){
          if (!res || !res.success) {
            var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Server returned error';
            exLog('Post failed: ' + errMsg);
            stats.errors += chunk.length;
            processed += chunk.length;
            return;
          }
          const rows = (res.data && Array.isArray(res.data.results)) ? res.data.results : [];
          rows.forEach(function(r){
            processed++;
            if (r.skipped) {
              if (LOG) {
                LOG.append(LOG.formatSkipped(r.id, { reason: r.reason || 'exists' }, { index: processed, total: total }), $exResults[0]);
              } else {
                exLog('#' + r.id + ' SKIPPED — ' + (r.reason || ''));
              }
              stats.skipped++;
              return;
            }
            if (!r.ok) {
              if (LOG) {
                LOG.append(LOG.formatError(r.id, { message: r.error || 'Unknown' }, { index: processed, total: total }), $exResults[0]);
              } else {
                exLog('#' + r.id + ' ERROR — ' + (r.error || 'Unknown'));
              }
              stats.errors++;
              return;
            }
            if (LOG && r.log) {
              var entryData = {
                status: r.saved ? 'saved' : (r.dryrun ? 'dryrun' : 'ok'),
                preview: r.preview || r.excerpt || '',
                log: Object.assign({}, r.log, { page_title: r.title || '' })
              };
              LOG.append(LOG.formatEntry(r.id, entryData, { index: processed, total: total, handler: 'Excerpts' }), $exResults[0]);
              if (exTracker) exTracker.track(entryData);
            } else {
              const savedTxt = r.saved ? 'SAVED' : (r.dryrun ? 'PREVIEW' : 'OK');
              exLog('#' + r.id + ' ' + (r.title || '') + ' — ' + savedTxt);
              exLog('  ' + (r.excerpt || ''));
            }
            if (r.saved) stats.saved++;
          });
        }).fail(function(xhr){
          var httpStatus = xhr && xhr.status ? xhr.status : '?';
          var errText = xhr && xhr.responseText ? xhr.responseText.substring(0, 200) : '';
          exLog('AJAX error (HTTP ' + httpStatus + '). ' + (errText ? errText : 'Server may have timed out.'));
          stats.errors += chunk.length;
          processed += chunk.length;
        }).always(function(){
          setTimeout(function(){ processExChunk(ci + 1); }, 50);
        });
      }

      processExChunk(0);
    });
  }

  /* -------------------------------------------------------------
   * HTML Excerpts subtab (Column 3 on Excerpts tab)
   * ------------------------------------------------------------- */
  function initHtmlExcerptsSubtab(){

    const $hexResetPrompt = $('#myls_ai_hex_reset_prompt');
    const $hexSavePrompt  = $('#myls_ai_hex_save_prompt');
    const $hexPrompt      = $('#myls_ai_hex_prompt');
    const $hexGen         = $('#myls_ai_hex_gen');
    const $hexResults     = $('#myls_ai_hex_results');

    // Not on Excerpts subtab or column 3 not present
    if (!$hexPrompt.length || !$hexGen.length || !$hexResults.length) return;

    const ajaxurl = getAjaxUrl();
    const nonce   = getNonce($('#myls_ai_ex_nonce'));

    function hexLog(msg){
      try { console.log('[MYLS HTML Excerpts]', msg); } catch(e){}
      try {
        const now = new Date().toLocaleTimeString();
        $hexResults.append('[' + now + '] ' + msg + '\n');
        $hexResults.scrollTop($hexResults[0].scrollHeight);
      } catch(e){}
    }

    // Reuse the shared post selector from column 1
    function hexSelectedIds(){
      const ids = [];
      $('#myls_ai_ex_posts').find('option:selected').each(function(){
        const v = parseInt($(this).val(), 10);
        if (v) ids.push(v);
      });
      return ids;
    }

    // Reset prompt
    $hexResetPrompt.on('click', function(){
      var def = $(this).data('default') || '';
      $hexPrompt.val(def);
    });

    // Save prompt
    $hexSavePrompt.on('click', function(){
      if (!ajaxurl || !nonce) { hexLog('ERROR: ajaxurl or nonce missing.'); return; }

      $hexResults.text('');
      hexLog('Saving HTML excerpt prompt template...');

      ajaxPostJSON(ajaxurl, {
        action: 'myls_ai_html_excerpt_save_prompt',
        nonce: nonce,
        prompt: ($hexPrompt.val() || '')
      }).done(function(res){
        if (!res || !res.success) {
          hexLog('Save failed.');
          if (res && res.data && res.data.message) hexLog(String(res.data.message));
          return;
        }
        hexLog('Saved.');
      }).fail(function(){
        hexLog('AJAX error saving prompt.');
      });
    });

    // Generate bulk (chunked)
    $hexGen.on('click', function(e){
      e.preventDefault();
      if (!ajaxurl || !nonce) { hexLog('ERROR: ajaxurl or nonce missing.'); return; }

      var allIds = hexSelectedIds();
      if (!allIds.length) { $hexResults.text(''); hexLog('Select at least one post (left column).'); return; }

      var overwrite = $('#myls_ai_ex_overwrite').is(':checked') ? 1 : 0;
      var dryrun    = $('#myls_ai_ex_dryrun').is(':checked') ? 1 : 0;
      var LOG = window.mylsLog;
      var total = allIds.length;
      var CHUNK = 1;  // Process one at a time to avoid server timeout
      var processed = 0;
      var stats = { saved: 0, skipped: 0, errors: 0 };
      var hexTracker = LOG ? LOG.createTracker() : null;

      if (LOG) {
        LOG.clear($hexResults[0], LOG.batchStart('HTML Excerpts', total, { dryrun: dryrun ? 'Yes' : 'No' }));
      } else {
        $hexResults.text('');
        hexLog('Generating HTML excerpts for ' + total + ' post(s)...');
      }

      var oldTxt = $hexGen.text();
      $hexGen.prop('disabled', true).text('Processing...');

      var chunks = [];
      for (var i = 0; i < allIds.length; i += CHUNK) {
        chunks.push(allIds.slice(i, i + CHUNK));
      }

      function processHexChunk(ci) {
        if (ci >= chunks.length) {
          if (LOG) {
            LOG.append(LOG.batchSummary(hexTracker ? hexTracker.getSummary(stats) : stats), $hexResults[0]);
          }
          $hexGen.prop('disabled', false).text(oldTxt);
          return;
        }

        var chunk = chunks[ci];
        var idx = processed + 1;
        $hexGen.text('Processing ' + idx + ' of ' + total + '…');

        ajaxPostJSON(ajaxurl, {
          action: 'myls_ai_html_excerpt_generate_bulk',
          nonce: nonce,
          post_ids: chunk,
          overwrite: overwrite,
          dryrun: dryrun
        }).done(function(res){
          if (!res || !res.success) {
            var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Server returned error';
            hexLog('Post failed: ' + errMsg);
            stats.errors += chunk.length;
            processed += chunk.length;
            return;
          }
          var rows = (res.data && Array.isArray(res.data.results)) ? res.data.results : [];
          rows.forEach(function(r){
            processed++;
            if (r.skipped) {
              if (LOG) {
                LOG.append(LOG.formatSkipped(r.id, { reason: r.reason || 'exists' }, { index: processed, total: total }), $hexResults[0]);
              } else {
                hexLog('#' + r.id + ' SKIPPED — ' + (r.reason || ''));
              }
              stats.skipped++;
              return;
            }
            if (!r.ok) {
              if (LOG) {
                LOG.append(LOG.formatError(r.id, { message: r.error || 'Unknown' }, { index: processed, total: total }), $hexResults[0]);
              } else {
                hexLog('#' + r.id + ' ERROR — ' + (r.error || 'Unknown'));
              }
              stats.errors++;
              return;
            }
            if (LOG && r.log) {
              var entryData = {
                status: r.saved ? 'saved' : (r.dryrun ? 'dryrun' : 'ok'),
                preview: r.preview || '',
                log: Object.assign({}, r.log, { page_title: r.title || '' })
              };
              LOG.append(LOG.formatEntry(r.id, entryData, { index: processed, total: total, handler: 'HTML Excerpts' }), $hexResults[0]);
              if (hexTracker) hexTracker.track(entryData);
            } else {
              var savedTxt = r.saved ? 'SAVED' : (r.dryrun ? 'PREVIEW' : 'OK');
              hexLog('#' + r.id + ' ' + (r.title || '') + ' — ' + savedTxt);
            }
            if (r.saved) stats.saved++;
          });
        }).fail(function(xhr){
          var httpStatus = xhr && xhr.status ? xhr.status : '?';
          var errText = xhr && xhr.responseText ? xhr.responseText.substring(0, 200) : '';
          hexLog('AJAX error (HTTP ' + httpStatus + '). ' + (errText ? errText : 'Server may have timed out.'));
          stats.errors += chunk.length;
          processed += chunk.length;
        }).always(function(){
          setTimeout(function(){ processHexChunk(ci + 1); }, 50);
        });
      }

      processHexChunk(0);
    });
  }

  /* -------------------------------------------------------------
   * Boot once DOM is ready (critical for subtabs)
   * ------------------------------------------------------------- */
  $(document).ready(function(){
    initMetaSubtab();
    initExcerptsSubtab();
    initHtmlExcerptsSubtab();

    /* ── Global Ctrl+A: select only within results terminals ──────
     * Applies to every .myls-results-terminal <pre> across all tabs.
     * ─────────────────────────────────────────────────────────── */
    $(document).on('keydown', '.myls-results-terminal', function(e){
      var isMac = (navigator.platform || '').toUpperCase().indexOf('MAC') > -1;
      var mod   = isMac ? e.metaKey : e.ctrlKey;
      if ( mod && ( e.key === 'a' || e.key === 'A' ) ) {
        e.preventDefault();
        e.stopPropagation();
        var sel = window.getSelection();
        if (!sel) return;
        var range = document.createRange();
        range.selectNodeContents(this);
        sel.removeAllRanges();
        sel.addRange(range);
      }
    });

    // Make all results terminals focusable so they receive keydown events
    $('.myls-results-terminal').each(function(){
      if ( !this.hasAttribute('tabindex') ) this.setAttribute('tabindex', '0');
    });
  });

})(jQuery);
