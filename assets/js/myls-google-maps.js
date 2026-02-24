/* AIntelligize – Bulk → Google Maps
 * Populates Service Area list, adds filter, and runs bulk map generation.
 * Assumes admin-ajax and Bootstrap 5 are available.
 */
(function ($) {
  'use strict';

  // ---------- Helpers ----------
  function postURL() {
    if (typeof ajaxurl !== 'undefined' && ajaxurl) return ajaxurl;
    return (window.MYLS && MYLS.ajaxurl) ? MYLS.ajaxurl : (window.ssseo_ajaxurl || '/wp-admin/admin-ajax.php');
  }
  function getNonce() {
    try { if (window.MYLS && (MYLS.bulkNonce || MYLS.nonce)) return (MYLS.bulkNonce || MYLS.nonce); } catch(e){}
    const el = document.getElementById('myls_bulk_ops_nonce');
    if (el && el.value) return el.value;
    return ''; // backend will 403 if missing/invalid
  }
  function asArray(x) { return Array.isArray(x) ? x : (x ? [x] : []); }

  // Render utilities
  function renderOptions($list, items, selectedIds) {
    $list.empty();
    if (!items || !items.length) {
      $list.append($('<option>').text('No items found'));
      return;
    }
    const sel = new Set(selectedIds || []);
    items.forEach(function (row) {
      const id = parseInt(row.id, 10) || 0;
      const title = (row.title || '').trim() || ('(no title) #' + id);
      if (!id) return;
      const $opt = $('<option>').val(String(id)).text(title);
      if (sel.has(id)) $opt.prop('selected', true);
      $list.append($opt);
    });
  }
  function updateCount($list, $count) {
    const n = (asArray($list.val())).length;
    $count.text(n ? (n + ' selected') : '');
  }
  function normalizeRespItems(resp) {
    // Accept {success:true,data:{items:[{id,title}]}}
    if (resp && resp.data && Array.isArray(resp.data.items)) return resp.data.items;
    // Accept {success:true,data:[...]}
    if (resp && Array.isArray(resp.data)) return resp.data;
    // Accept raw array
    if (Array.isArray(resp)) return resp;
    return [];
  }

  // ---------- Main ----------
  $(function () {
    const URL     = postURL();
    const nonce   = getNonce();

    const $search = $('#myls_gmaps_search');
    const $list   = $('#myls_gmaps_post_list');
    const $count  = $('#myls_gmaps_count');

    const $selectAll = $('#myls_gmaps_select_all');
    const $clear     = $('#myls_gmaps_clear');

    const $run    = $('#myls_gmaps_run');
    const $force  = $('#myls_gmaps_force');
    const $status = $('#myls_gmaps_status');

    const $empty  = $('#myls_gmaps_empty');
    const $result = $('#myls_gmaps_result');
    const $summary= $('#myls_gmaps_summary');
    const $log    = $('#myls_gmaps_log');

    let ALL_ITEMS = [];       // master list: {id, title}
    let FILTERED  = [];       // current filtered list
    let LOADED    = false;

    function currentSelectedIds() {
      return (asArray($list.val()).map(v => parseInt(v,10) || 0).filter(Boolean));
    }

    function setFilteredAndRender(query) {
      const q = (query || '').toLowerCase();
      const selected = currentSelectedIds();
      FILTERED = !q ? ALL_ITEMS.slice() : ALL_ITEMS.filter(row => (row.title || '').toLowerCase().includes(q));
      renderOptions($list, FILTERED, selected);
      updateCount($list, $count);
    }

    function tryServerProvided() {
      // Option 1: MYLS-provided dataset
      if (window.MYLS && Array.isArray(MYLS.gmapsItems) && MYLS.gmapsItems.length) {
        ALL_ITEMS = MYLS.gmapsItems.map(x => ({ id: parseInt(x.id,10)||0, title: x.title || '' })).filter(x => x.id);
        return true;
      }
      // Option 2: JSON script tag (optional)
      const el = document.getElementById('myls_gmaps_server_items');
      if (el && el.textContent) {
        try {
          const arr = JSON.parse(el.textContent);
          if (Array.isArray(arr) && arr.length) {
            ALL_ITEMS = arr.map(x => ({ id: parseInt(x.id,10)||0, title: x.title || '' })).filter(x => x.id);
            return true;
          }
        } catch(e){}
      }
      return false;
    }

    function loadItemsAjax() {
      // Prefer MYLS endpoint; fallback to SSSEO for legacy compatibility
      const tryActions = ['myls_sa_all_published', 'ssseo_sa_all_published'];
      function callAction(ix) {
        if (ix >= tryActions.length) {
          // give up
          ALL_ITEMS = [];
          setFilteredAndRender('');
          return;
        }
        $.post(URL, { action: tryActions[ix], nonce: nonce })
          .done(function (resp) {
            const items = normalizeRespItems(resp);
            if (items && items.length) {
              ALL_ITEMS = items
                .map(x => ({ id: parseInt(x.id,10)||0, title: x.title || '' }))
                .filter(x => x.id);
              setFilteredAndRender('');
              LOADED = true;
            } else {
              // try next action
              callAction(ix + 1);
            }
          })
          .fail(function () {
            callAction(ix + 1);
          });
      }
      callAction(0);
    }

    // Initialize: populate list
    if (tryServerProvided()) {
      setFilteredAndRender('');
      LOADED = true;
    } else {
      // prefill placeholder
      renderOptions($list, [{id:0,title:'Loading…'}], []);
      loadItemsAjax();
    }

    // Filter typing
    let filterTimer = null;
    $search.on('input', function () {
      const val = this.value;
      clearTimeout(filterTimer);
      filterTimer = setTimeout(function () {
        setFilteredAndRender(val);
      }, 120);
    });

    // Select all / clear
    $selectAll.on('click', function (e) {
      e.preventDefault();
      $('#myls_gmaps_post_list option').prop('selected', true);
      $list.trigger('change');
      updateCount($list, $count);
    });
    $clear.on('click', function (e) {
      e.preventDefault();
      $('#myls_gmaps_post_list option').prop('selected', false);
      $list.trigger('change');
      updateCount($list, $count);
    });
    $list.on('change', function () {
      updateCount($list, $count);
    });

    // Run operation
    $run.on('click', function () {
      const ids = currentSelectedIds();
      if (!ids.length) {
        alert('Select at least one Service Area.');
        return;
      }

      $run.prop('disabled', true).addClass('disabled');
      $status.text('Running…');
      $empty.hide(); $result.hide(); $summary.text(''); $log.text('');

      // Prefer MYLS action; fallback SSSEO legacy
      const actions = ['myls_bulk_generate_maps', 'ssseo_bulk_generate_maps'];
      function callRun(ix) {
        if (ix >= actions.length) {
          $summary.text('No handler found (developer: add myls_bulk_generate_maps).');
          $result.show();
          $status.text('');
          $run.prop('disabled', false).removeClass('disabled');
          return;
        }
        $.post(URL, {
          action:  actions[ix],
          nonce:   nonce,
          post_ids: ids,
          force:   $force.is(':checked') ? 1 : 0
        })
        .done(function (resp) {
          if (resp && resp.success && resp.data) {
            const d = resp.data;
            const ok = typeof d.ok === 'number' ? d.ok : (Array.isArray(d.ok_ids) ? d.ok_ids.length : 0);
            const err= typeof d.err=== 'number' ? d.err: (Array.isArray(d.err_ids)? d.err_ids.length: 0);
            $summary.text('Done: ' + ok + ' succeeded, ' + err + ' failed.');
            const lines = (d.log && Array.isArray(d.log)) ? d.log : [JSON.stringify(resp, null, 2)];
            $log.text(lines.join('\n'));
          } else {
            $summary.text('Operation failed.');
            $log.text(JSON.stringify(resp || {}, null, 2));
          }
          $result.show();
        })
        .fail(function (xhr) {
          if (ix + 1 < actions.length) {
            callRun(ix + 1);
            return;
          }
          $summary.text(xhr && xhr.status === 403 ? 'Forbidden (bad or missing nonce)' : 'Network error during bulk generation.');
          $log.text(xhr && xhr.responseText ? xhr.responseText : 'No details');
          $result.show();
        })
        .always(function () {
          $status.text('');
          $run.prop('disabled', false).removeClass('disabled');
        });
      }
      callRun(0);
    });

  });
})(jQuery);
