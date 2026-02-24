/* MYLS – AJAX Search
 * Path: /assets/js/myls-ajax-search.js
 *
 * Reads per-instance config from data-* attributes on .myls-ajax-search.
 * Sends:
 *  - action=myls_ajax_search
 *  - term, max, post_types, priority
 *
 * Renders:
 *  - list-group results (thumb + title + optional type + date)
 */

(function($){
  'use strict';

  function escHtml(str){
    return String(str || '')
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#039;');
  }

  function getTransport(){
    // Localized by shortcode in ajax-search.php
    if (typeof window.MYLS_AJAX_SEARCH === 'object' && window.MYLS_AJAX_SEARCH) {
      return window.MYLS_AJAX_SEARCH;
    }
    return { ajaxurl: '', nonce: '' };
  }

  function debounce(fn, ms){
    var t = null;
    return function(){
      var ctx = this, args = arguments;
      clearTimeout(t);
      t = setTimeout(function(){ fn.apply(ctx, args); }, ms);
    };
  }

  function closeAll(){
    $('.myls-ajax-search-results').hide().empty();
  }

  function renderItems($wrap, items){
    var $results = $wrap.find('.myls-ajax-search-results');
    $results.empty();

    if (!items || !items.length) {
      $results.hide();
      return;
    }

    var showType = String($wrap.data('show-type') || '0') === '1';

    items.forEach(function(it){
      var title = escHtml(it.title || '(no title)');
      var url   = escHtml(it.url || '#');
      var type  = escHtml(it.type || '');
      var date  = escHtml(it.date || '');
      var thumb = String(it.thumb || '');

      var thumbHtml = '';
      if (thumb) {
        thumbHtml = '<img src="' + escHtml(thumb) + '" alt="" class="myls-ajax-search-thumb" loading="lazy" decoding="async">';
      }

      var meta = [];
      if (showType && type) meta.push('<span class="myls-ajax-search-type">' + type + '</span>');
      if (date) meta.push('<span class="myls-ajax-search-date">' + date + '</span>');

      var metaHtml = meta.length ? ('<div class="myls-ajax-search-meta">' + meta.join(' ') + '</div>') : '';

      var html =
        '<a class="list-group-item list-group-item-action myls-ajax-search-item" href="' + url + '">' +
          '<div class="d-flex align-items-center gap-2">' +
            (thumbHtml ? ('<div class="myls-ajax-search-thumb-wrap">' + thumbHtml + '</div>') : '') +
            '<div class="myls-ajax-search-text">' +
              '<div class="myls-ajax-search-title">' + title + '</div>' +
              metaHtml +
            '</div>' +
          '</div>' +
        '</a>';

      $results.append(html);
    });

    $results.show();
  }

  function attach($wrap){
    var tr = getTransport();
    var ajaxurl = tr.ajaxurl || '';
    var nonce   = tr.nonce || '';

    var postTypes  = String($wrap.data('post-types') || '');
    var priority   = String($wrap.data('priority') || '');
    var max        = parseInt($wrap.data('max') || 5, 10);
    var minChars   = parseInt($wrap.data('min-chars') || 2, 10);
    var debounceMs = parseInt($wrap.data('debounce-ms') || 200, 10);

    max = isNaN(max) ? 5 : Math.max(1, max);
    minChars = isNaN(minChars) ? 2 : Math.max(1, minChars);
    debounceMs = isNaN(debounceMs) ? 200 : Math.max(0, debounceMs);

    var $input = $wrap.find('.myls-ajax-search-input');
    var $results = $wrap.find('.myls-ajax-search-results');

    if (!$input.length || !$results.length) return;

    function doSearch(){
      var term = ($input.val() || '').trim();

      if (term.length < minChars) {
        $results.hide().empty();
        return;
      }

      if (!ajaxurl || !nonce) {
        // fail silently but visible in console
        console.warn('[MYLS AJAX SEARCH] Missing ajaxurl/nonce.');
        $results.hide().empty();
        return;
      }

      $.ajax({
        url: ajaxurl,
        method: 'POST',
        dataType: 'json',
        data: {
          action: 'myls_ajax_search',
          nonce: nonce,
          term: term,
          max: max,
          post_types: postTypes,
          priority: priority
        }
      }).done(function(res){
        if (!res || !res.success || !res.data) {
          renderItems($wrap, []);
          return;
        }
        renderItems($wrap, res.data.items || []);
      }).fail(function(){
        renderItems($wrap, []);
      });
    }

    var debounced = debounce(doSearch, debounceMs);

    $input.on('input', function(){
      debounced();
    });

    $input.on('focus', function(){
      // If already typed, re-open results by triggering search
      if (($input.val() || '').trim().length >= minChars) {
        debounced();
      }
    });

    // Close only this instance when clicking outside
    $(document).on('mousedown', function(e){
      if ($(e.target).closest($wrap).length) return;
      $results.hide().empty();
    });

    // Escape closes
    $input.on('keydown', function(e){
      if (e.key === 'Escape') {
        $results.hide().empty();
        $input.blur();
      }
    });
  }

  $(function(){
    // Multiple instances supported
    $('.myls-ajax-search').each(function(){
      attach($(this));
    });

    // Optional: close all results on navigation events etc.
    $(document).on('click', 'a', function(){
      // Don’t force-close if clicking inside results list
      if ($(this).closest('.myls-ajax-search-results').length) return;
      closeAll();
    });
  });

})(jQuery);
