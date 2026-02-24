/* File: assets/js/myls-bulk.js
 * Handles: Yoast Ops list filtering + AJAX actions; YouTube fix runner
 * Depends: jQuery
 */
/* global jQuery, ajaxurl, MYLS */
jQuery(function ($) {
  if (typeof MYLS === 'undefined') return;
  const nonce = MYLS.bulkNonce || '';

  /* -----------------------
   * Yoast tab: Post list UI
   * ----------------------- */
  (function initYoastOps() {
    const $pt = $('#myls_bulk_pt_filter');
    const $list = $('#myls_bulk_post_id');
    const $search = $('#myls_bulk_post_search');
    if (!$pt.length || !$list.length) return;

    function renderForType(type, filter) {
      const posts = (MYLS.postsByType && MYLS.postsByType[type]) ? MYLS.postsByType[type] : [];
      const f = (filter || '').toLowerCase();
      $list.empty();
      posts.forEach((p) => {
        const t = (p.title || '').toLowerCase();
        if (!f || t.indexOf(f) !== -1) {
          $('<option>').val(p.id).text(p.title || `(no title) #${p.id}`).appendTo($list);
        }
      });
    }

    renderForType($pt.val(), $search.val() || '');
    $pt.on('change', function () { renderForType($(this).val(), $search.val()); });
    $search.on('input', function () { renderForType($pt.val(), $(this).val()); });
  })();

  /* -----------------------
   * Yoast tab: AJAX helpers
   * ----------------------- */
  const $result = $('#myls_bulk_result');

  function getSelectedIds() {
    const ids = [];
    $('#myls_bulk_post_id option:selected').each(function () {
      ids.push(parseInt($(this).val(), 10));
    });
    return ids;
  }

  function runAction(action, button) {
    const ids = getSelectedIds();
    if (!ids.length) {
      $result.text('Select at least one post.').show();
      return;
    }
    $(button).prop('disabled', true);

    $.post(ajaxurl, {
      action,
      nonce,
      post_ids: ids
    })
      .done((resp) => {
        if (resp && resp.success) {
          $result.html('<pre>' + JSON.stringify(resp.data, null, 2) + '</pre>').show();
        } else {
          $result.text((resp && resp.data) ? String(resp.data) : 'Unknown error').show();
        }
      })
      .fail((xhr) => {
        $result.text('Request failed: ' + xhr.status + ' ' + xhr.statusText).show();
      })
      .always(() => {
        $(button).prop('disabled', false);
      });
  }

  $('#myls_bulk_indexfollow').on('click', function () { runAction('myls_set_index_follow', this); });
  $('#myls_bulk_reset_canonical').on('click', function () { runAction('myls_reset_canonical', this); });
  $('#myls_bulk_clear_canonical').on('click', function () { runAction('myls_clear_canonical', this); });

  /* -----------------------
   * YouTube Fix runner
   * ----------------------- */
  $('#myls_fix_youtube_iframes').on('click', function () {
    const $btn = $(this);
    const $info = $('#myls_youtube_result');
    const $log = $('#myls_youtube_log');
    $btn.prop('disabled', true);
    $info.removeClass('d-none').text('Processing…');
    $log.empty().addClass('d-none');

    $.post(ajaxurl, { action: 'myls_fix_youtube_iframes', nonce })
      .done((resp) => {
        if (resp && resp.success) {
          const data = resp.data || {};
          $info.text(`Updated posts: ${data.changed || 0}`);
          const lines = data.log || [];
          if (lines.length) {
            $log.removeClass('d-none');
            lines.forEach((line) => {
              $('<li class="list-group-item">').text(line).appendTo($log);
            });
          }
        } else {
          $info.text((resp && resp.data) ? String(resp.data) : 'Unknown error');
        }
      })
      .fail((xhr) => {
        $info.text('Request failed: ' + xhr.status + ' ' + xhr.statusText);
      })
      .always(() => {
        $btn.prop('disabled', false);
      });
  });
});
