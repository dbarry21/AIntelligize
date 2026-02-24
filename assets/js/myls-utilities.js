/* global MYLS_UTIL */
(function(){
  'use strict';

  const $ = (sel, ctx=document) => ctx.querySelector(sel);

  const logEl = () => $('#myls-util-log');
  // Button IDs live in admin/views/utilities-acf-migrations.php
  const stopBtn = () => $('#myls-btn-stop');

  let isRunning = false;
  let shouldStop = false;

  function log(line){
    const el = logEl();
    if (!el) return;
    el.textContent += line + "\n";
    el.scrollTop = el.scrollHeight;
  }

  function setRunning(state){
    isRunning = state;
    const s = stopBtn();
    if (s) s.disabled = !state;
  }

  async function ajax(action, data){
    const body = new URLSearchParams();
    body.append('action', action);
    body.append('nonce', MYLS_UTIL.nonce);
    Object.keys(data || {}).forEach(k => body.append(k, data[k]));

    const res = await fetch(MYLS_UTIL.ajax_url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body
    });

    const json = await res.json();
    if (!json || json.success !== true) {
      const msg = (json && json.data && json.data.message) ? json.data.message : 'Request failed';
      throw new Error(msg);
    }
    return json.data;
  }

  async function runBatches(action){
    if (isRunning) return;
    shouldStop = false;

    const batchSize = parseInt($('#myls-util-batch-size')?.value || '25', 10) || 25;
    const overwrite = $('#myls-util-overwrite')?.checked ? 1 : 0;

    // reset output
    const el = logEl();
    if (el) el.textContent = '';

    log('Starting ' + action + ' ...');
    setRunning(true);

    let offset = 0;
    let totals = { scanned:0, migrated:0, skipped:0, empty:0, errors:0 };

    try {
      while (!shouldStop) {
        // PHP expects `limit`, keep JS resilient if we rename server-side.
        const resp = await ajax(action, { offset, limit: batchSize, overwrite });

        // Server response shape (inc/utilities/acf-migrations.php):
        // { offset, limit, next_offset, batch_count, total, done, counts:{migrated,skipped_existing,empty,errors}, logs:[] }
        const batchCount = parseInt(resp.batch_count || 0, 10) || 0;
        const limit = parseInt(resp.limit || batchSize, 10) || batchSize;
        const c = resp.counts || {};
        const migrated = parseInt(c.migrated || 0, 10) || 0;
        const skipped  = parseInt(c.skipped_existing || 0, 10) || 0;
        const empty    = parseInt(c.empty || 0, 10) || 0;
        const errors   = parseInt(c.errors || 0, 10) || 0;

        totals.scanned  += batchCount;
        totals.migrated += migrated;
        totals.skipped  += skipped;
        totals.empty    += empty;
        totals.errors   += errors;

        log(`Batch offset ${resp.offset} / size ${limit} — scanned ${batchCount}, migrated ${migrated}, skipped ${skipped}, empty ${empty}, errors ${errors}`);

        if (resp.done) {
          log('DONE');
          break;
        }
        offset = parseInt(resp.next_offset || (offset + batchCount), 10) || (offset + batchCount);
      }

      if (shouldStop) log('STOPPED by user.');

      log('--- Totals ---');
      log(`Scanned: ${totals.scanned}`);
      log(`Migrated: ${totals.migrated}`);
      log(`Skipped: ${totals.skipped}`);
      log(`Empty/Not Found: ${totals.empty}`);
      log(`Errors: ${totals.errors}`);

    } catch (e) {
      log('ERROR: ' + (e.message || e));
    } finally {
      setRunning(false);
    }
  }

  document.addEventListener('click', function(e){
    const t = e.target;
    if (!t) return;

    if (t.id === 'myls-btn-migrate-faqs') {
      e.preventDefault();
      runBatches('myls_util_migrate_faqs_batch');
      return;
    }

    if (t.id === 'myls-btn-migrate-citystate') {
      e.preventDefault();
      runBatches('myls_util_migrate_city_state_batch');
      return;
    }

    if (t.id === 'myls-btn-clean-faqs') {
      e.preventDefault();
      runBatches('myls_util_clean_myls_faqs_batch');
      return;
    }

    if (t.id === 'myls-btn-stop') {
      e.preventDefault();
      shouldStop = true;
      return;
    }
  });

})();
