/**
 * File: assets/js/myls-yoast.js
 * Location: your-plugin/assets/js/myls-yoast.js
 *
 * Yoast Bulk subtab initializer
 * - Populates Source/Target lists from posts_by_type
 * - Independent filtering and post type switches
 * - Sends AJAX with `post_ids` (matches PHP handlers)
 */
(function($){
  function debounce(fn, wait){ let t; return function(){ clearTimeout(t); t = setTimeout(()=>fn.apply(this, arguments), wait||160); }; }

  $(function(){
    // Prefer subtab-scoped JSON; fallback to window.MYLS
    let data = null;
    const bootEl = document.getElementById('mylsYoastBootstrap');
    if (bootEl) {
      try { data = JSON.parse(bootEl.textContent || '{}'); } catch(e){ console.warn('mylsYoastBootstrap JSON parse error', e); }
    }
    if (!data || !data.posts_by_type) {
      if (typeof window.MYLS !== 'undefined' && window.MYLS && window.MYLS.postsByType) {
        data = {
          posts_by_type: window.MYLS.postsByType,
          selectors: {
            source_pt:     '#myls_yoast_source_pt',
            source_search: '#myls_yoast_source_search',
            source_post:   '#myls_yoast_source_post',
            target_pt:     '#myls_yoast_target_pt',
            target_search: '#myls_yoast_target_search',
            target_posts:  '#myls_yoast_target_posts',
            result_box:    '#myls_bulk_result',
          },
          actions: {
            indexfollow:     'myls_set_index_follow',
            reset_canonical: 'myls_reset_canonical',
            clear_canonical: 'myls_clear_canonical',
          },
          nonce: (window.MYLS && window.MYLS.bulkNonce) ? window.MYLS.bulkNonce : ''
        };
      }
    }
    if (!data || !data.posts_by_type) return; // not on Yoast subtab

    const postsByType  = data.posts_by_type || {};
    const sel          = data.selectors     || {};
    const actions      = data.actions       || {};
    const nonce        = data.nonce         || '';

    const $sourcePT     = $(sel.source_pt);
    const $sourceSearch = $(sel.source_search);
    const $sourcePost   = $(sel.source_post);
    const $targetPT     = $(sel.target_pt);
    const $targetSearch = $(sel.target_search);
    const $targetPosts  = $(sel.target_posts);
    const $resultBox    = $(sel.result_box);

    // Bail silently if markup isn't present
    if (!$sourcePT.length || !$sourcePost.length || !$targetPT.length || !$targetPosts.length) return;

    // Helpers to ensure we always have a post type value
    function firstPostType(obj){ const k = Object.keys(obj || {}); return k.length ? k[0] : ''; }
    function ensureSelectHasValue($sel, fallbackKey){ if ($sel && $sel.length && !$sel.val()) { $sel.val(fallbackKey); } }

    function normalize(list){
      if (!Array.isArray(list)) return [];
      return list.map(p => ({ ID: parseInt(p.ID || p.id || 0, 10), title: String(p.title || p.post_title || '') }))
                 .filter(p => p.ID && p.title);
    }
    const getList = (pt)=> normalize(postsByType[pt] || []);
    const filter  = (items, q)=> { if (!q) return items; const n = String(q).toLowerCase(); return items.filter(i => i.title.toLowerCase().includes(n)); };

    // Render options — normalize selected values to an array to avoid .map() on strings
    function toArray(v){ return Array.isArray(v) ? v : (v == null ? [] : [v]); }
    function render($select, items){
      const selected = toArray($select.val()).map(String);
      $select.empty();
      const frag = document.createDocumentFragment();
      items.forEach(i => {
        const opt = document.createElement('option');
        opt.value = String(i.ID);
        opt.textContent = i.title;
        if (selected.includes(String(i.ID))) opt.selected = true;
        frag.appendChild(opt);
      });
      if ($select[0]) $select[0].appendChild(frag);
    }

    function refreshSourceList(){
      let pt = $sourcePT.val();
      if (!pt) { pt = firstPostType(postsByType); ensureSelectHasValue($sourcePT, pt); }
      render($sourcePost, filter(getList(pt), $sourceSearch.val()));
    }
    function refreshTargetList(){
      let pt = $targetPT.val();
      if (!pt) { pt = firstPostType(postsByType); ensureSelectHasValue($targetPT, pt); }
      render($targetPosts, filter(getList(pt), $targetSearch.val()));
    }

    // Ensure PT selects have values, then paint
    ensureSelectHasValue($sourcePT, firstPostType(postsByType));
    ensureSelectHasValue($targetPT, firstPostType(postsByType));
    refreshSourceList();
    refreshTargetList();

    // Events
    $sourcePT.on('change', refreshSourceList);
    $targetPT.on('change', refreshTargetList);
    $sourceSearch.on('input', debounce(refreshSourceList, 150));
    $targetSearch.on('input', debounce(refreshTargetList, 150));

    // Logger
    function showLog(){ if ($resultBox.length && $resultBox.is(':hidden')) $resultBox.show(); }
    function log(line){
      showLog();
      if (!$resultBox.length) return;
      const ts = new Date().toLocaleTimeString();
      $resultBox.append($('<div/>').text(`[${ts}] ${line}`));
      $resultBox.scrollTop($resultBox[0].scrollHeight);
    }

    // Bulk action buttons (apply to selected Targets)
    $(document).on('click', '[data-action="indexfollow"],[data-action="reset_canonical"],[data-action="clear_canonical"],[data-action="noindex_nofollow"],[data-action="copy_canonical"]', function(e){
      e.preventDefault();
      const actionKey  = $(this).data('action');
      const ajaxAction = actions[actionKey];
      if (!ajaxAction) return log(`Unknown action: ${actionKey}`);

      const targets = ($targetPosts.val() || []).map(v => parseInt(v, 10)).filter(Boolean);
      if (!targets.length) return log('Select at least one Target post.');

      const source = parseInt($sourcePost.val() || 0, 10) || 0;
      if (actionKey === 'copy_canonical' && !source) return log('Select a Source post before copying canonical.');

      log(`Running "${actionKey}" on ${targets.length} target(s)…`);
      $.ajax({
        url: (typeof ajaxurl !== 'undefined' ? ajaxurl : window.location.href),
        method: 'POST',
        dataType: 'json',
        data: {
          action:   ajaxAction,
          nonce:    nonce,
          post_ids: targets,
          source:   source
        }
      })
      .done(function(resp){
        if (resp && resp.success) {
          log(`${actionKey}: ✅ Success.`);
          if (resp.data && resp.data.message) log(` • ${resp.data.message}`);
          if (resp.data && Array.isArray(resp.data.details)) resp.data.details.forEach(d => log(` • ${d}`));
        } else {
          const msg = resp && resp.data ? (resp.data.message || resp.data) : 'Unknown error';
          log(`${actionKey}: ❌ ${msg}`);
        }
      })
      .fail(function(xhr){
        log(`${actionKey}: ❌ AJAX failed (${xhr.status})`);
      });
    });
  });
})(jQuery);
