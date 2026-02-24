/* AIntelligize – Meta Tab JS
 * Path: assets/js/myls-meta.js
 * Handles the "Meta History" subtab interactions, including post filtering.
 */
(function($){
  'use strict';

  if (!window.MYLS_META || typeof MYLS_META.ajaxurl !== 'string') return;

  const $pt     = $('#myls_mh_pt');
  const $filter = $('#myls_mh_filter');
  const $post   = $('#myls_mh_post');
  const $load   = $('#myls_mh_load');
  const $clear  = $('#myls_mh_clear');
  const $export = $('#myls_mh_export');
  const $status = $('#myls_mh_status');
  const $tbody  = $('#myls_mh_table tbody');

  // Cache of post lists per post type: { postType: [{id, title}, ...] }
  const cache = Object.create(null);

  function setStatus(msg){ $status.text(msg || ''); }
  function formatDate(ts){
    try { return new Date(ts * 1000).toLocaleString(); }
    catch(e){ return String(ts); }
  }
  function escapeCSV(val){
    if (val == null) return '';
    val = String(val);
    return /[",\n]/.test(val) ? '"' + val.replace(/"/g, '""') + '"' : val;
  }

  /** Rebuild the post <select> options from a provided array */
  function populatePostSelect(items){
    $post.empty().append($('<option>').val('').text('— Select a post —'));
    items.forEach(function(it){
      $post.append(
        $('<option>').val(String(it.id)).text(it.title + ' (ID ' + it.id + ')')
      );
    });
  }

  /** Filter the cached items for current post type by query, then rebuild select */
  function applyFilter(){
    const pt  = $pt.val();
    const q   = ($filter.val() || '').trim().toLowerCase();
    const all = cache[pt] || [];
    if (!q){ populatePostSelect(all); return; }

    const filtered = all.filter(function(it){
      return (it.title || '').toLowerCase().indexOf(q) !== -1;
    });
    populatePostSelect(filtered);
  }

  /** Debounce helper */
  function debounce(fn, wait){
    let t = null;
    return function(){
      const ctx = this, args = arguments;
      clearTimeout(t);
      t = setTimeout(function(){ fn.apply(ctx, args); }, wait);
    };
  }

  // Load posts for a post type (cached)
  function loadPostsForPT(pt){
    // If cached, rebuild immediately and done
    if (cache[pt]){
      setStatus('Loaded from cache.');
      applyFilter();
      return;
    }

    $post.empty().append($('<option>').val('').text('Loading...'));
    setStatus('Loading posts…');
    $.post(MYLS_META.ajaxurl, {
      action: 'myls_meta_history_posts',
      post_type: pt,
      nonce: MYLS_META.nonce
    }).done(function(resp){
      if (resp && resp.success && resp.data && Array.isArray(resp.data.items)) {
        cache[pt] = resp.data.items; // cache the full list
        setStatus('Posts loaded.');
        applyFilter(); // will build from cache + current filter
      } else {
        cache[pt] = [];
        populatePostSelect([]);
        setStatus('No posts found for this type.');
      }
    }).fail(function(){
      cache[pt] = [];
      populatePostSelect([]);
      setStatus('Error loading posts.');
    });
  }

  // Events
  $pt.on('change', function(){
    // Clear selection and filter when changing PT
    $post.val('');
    $filter.val('');
    loadPostsForPT($pt.val());
  });

  $filter.on('input', debounce(applyFilter, 150));

  // Initial load
  if ($pt.length) $pt.trigger('change');

  // Load history
  $load.on('click', function(e){
    e.preventDefault();
    const pid = parseInt($post.val() || '0', 10);
    if (!pid) { setStatus('Pick a post first.'); return; }

    setStatus('Loading history…');
    $tbody.empty().append('<tr><td colspan="5"><em>Loading…</em></td></tr>');
    $.post(MYLS_META.ajaxurl, {
      action: 'myls_meta_history_get',
      post_id: pid,
      nonce: MYLS_META.nonce
    }).done(function(resp){
      if (!resp || !resp.success || !resp.data || !Array.isArray(resp.data.log)) {
        $tbody.html('<tr><td colspan="5"><em>No history found.</em></td></tr>');
        $export.hide(); $clear.hide();
        setStatus('No history found.');
        return;
      }
      const log = resp.data.log;
      if (log.length === 0) {
        $tbody.html('<tr><td colspan="5"><em>No history entries.</em></td></tr>');
        $export.hide(); $clear.show();
        setStatus('No history entries.');
        return;
      }

      $tbody.empty();
      log.forEach(function(row){
        const keyLabel = row.key === '_yoast_wpseo_title' ? 'Yoast Title'
                       : row.key === '_yoast_wpseo_metadesc' ? 'Yoast Description'
                       : row.key;
        const tr = $('<tr>');
        tr.append($('<td>').text(formatDate(row.ts)));
        tr.append($('<td>').text(row.user || 'System/Unknown'));
        tr.append($('<td>').text(keyLabel));
        tr.append($('<td>').text(row.old || ''));
        tr.append($('<td>').text(row.new || ''));
        $tbody.append(tr);
      });

      $export.show(); $clear.show();
      setStatus('History loaded: ' + log.length + ' change(s).');

      // CSV export
      $export.off('click').on('click', function(ev){
        ev.preventDefault();
        const headers = ['Timestamp','User','Field','Old Value','New Value'];
        const lines = [headers.map(escapeCSV).join(',')];
        log.forEach(function(r){
          const field = (r.key === '_yoast_wpseo_title') ? 'Yoast Title'
                      : (r.key === '_yoast_wpseo_metadesc') ? 'Yoast Description'
                      : r.key;
          lines.push([
            escapeCSV(formatDate(r.ts)),
            escapeCSV(r.user || 'System/Unknown'),
            escapeCSV(field),
            escapeCSV(r.old || ''),
            escapeCSV(r.new || '')
          ].join(','));
        });
        const blob = new Blob([lines.join('\n')], {type:'text/csv;charset=utf-8;'});
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url;
        a.download = 'meta-history-' + pid + '.csv';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
      });
    }).fail(function(){
      $tbody.html('<tr><td colspan="5"><em>Error loading history.</em></td></tr>');
      $export.hide(); $clear.hide();
      setStatus('Error loading history.');
    });
  });

  // Clear history
  $clear.on('click', function(e){
    e.preventDefault();
    const pid = parseInt($post.val() || '0', 10);
    if (!pid) { setStatus('Pick a post first.'); return; }
    if (!confirm('Clear all saved history for this post?')) return;

    setStatus('Clearing…');
    $.post(MYLS_META.ajaxurl, {
      action: 'myls_meta_history_clear',
      post_id: pid,
      nonce: MYLS_META.nonce
    }).done(function(resp){
      if (resp && resp.success) {
        $tbody.html('<tr><td colspan="5"><em>History cleared.</em></td></tr>');
        $export.hide();
        setStatus('History cleared.');
      } else {
        setStatus('Failed to clear history.');
      }
    }).fail(function(){
      setStatus('Error clearing history.');
    });
  });

})(jQuery);
