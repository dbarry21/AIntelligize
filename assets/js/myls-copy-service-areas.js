/* AIntelligize – Clone Service Areas (robust filtering + auto-hydrate)
 * Path: assets/js/myls-copy-service-areas.js
 */
(function ($) {
  'use strict';

  function getNonce() {
    try { if (window.MYLS && (MYLS.bulkNonce || MYLS.nonce)) return (MYLS.bulkNonce || MYLS.nonce); } catch (e) {}
    return $('#myls_bulk_ops_nonce').val() || '';
  }

  function parseJSONScript(id) {
    var el = document.getElementById(id);
    if (!el) return null;
    try { return JSON.parse(el.textContent || el.innerText || '[]'); }
    catch(e) { return null; }
  }

  function optionRow(id, title) {
    return $('<option>').val(String(id)).text((title || '(no title)') + ' (ID ' + id + ')');
  }
  function optionRowIndented(id, title, depth) {
    var indent = new Array((depth || 0) + 1).join('— ');
    return $('<option>')
      .val(String(id))
      .attr('data-depth', depth || 0)
      .text(indent + (title || '(no title)') + ' (ID ' + id + ')');
  }

  function renderSource($select, cache, filter) {
    var needle = (filter || '').toLowerCase();
    var prev = $select.val();
    $select.empty();
    var found = 0;
    cache.forEach(function (it) {
      if (!needle || (it.title || '').toLowerCase().indexOf(needle) !== -1) {
        $select.append(optionRow(it.id, it.title));
        found++;
      }
    });
    if (!found) $select.append($('<option disabled>').text('— No matches —'));
    if (prev) $select.val(prev);
  }

  function renderTargets($select, cache, filter) {
    var needle = (filter || '').toLowerCase();
    var prev = $select.val() || [];
    $select.empty();
    var found = 0;
    cache.forEach(function (it) {
      if (!needle || (it.title || '').toLowerCase().indexOf(needle) !== -1) {
        $select.append(optionRowIndented(it.id, it.title, it.depth || 0));
        found++;
      }
    });
    if (!found) $select.append($('<option disabled>').text('— No matches —'));
    if (prev.length) {
      // keep selections that are still visible
      $select.val(prev.filter(function (id) {
        return cache.some(function (it) {
          var ok = (!needle || (it.title || '').toLowerCase().indexOf(needle) !== -1);
          return ok && String(it.id) === String(id);
        });
      }));
    }
  }

  function hydrateSourceCache(cb) {
    $.post(ajaxurl, { action: 'myls_sa_all_published', nonce: getNonce() })
      .done(function (res) {
        if (res && res.success && res.data && Array.isArray(res.data.items)) {
          cb(res.data.items.map(function (it) {
            return { id: it.id, title: it.title || '(no title)' };
          }));
        } else { cb([]); }
      })
      .fail(function(){ cb([]); });
  }

  function hydrateTargetCache(cb) {
    $.post(ajaxurl, { action: 'myls_sa_tree_published', nonce: getNonce() })
      .done(function (res) {
        if (res && res.success && res.data && Array.isArray(res.data.items)) {
          cb(res.data.items.map(function (it) {
            return { id: it.id, title: it.title || '(no title)', depth: it.depth || 0 };
          }));
        } else { cb([]); }
      })
      .fail(function(){ cb([]); });
  }

  $(function () {
    var $container = $('#myls-bulk-clone-sa');
    if (!$container.length) return;

    var $src   = $('#myls-clone-sa-source');
    var $tgt   = $('#myls-clone-sa-targets');
    var $srcF  = $('#myls-clone-sa-source-filter');
    var $tgtF  = $('#myls-clone-sa-target-filter');
    var $btnR1 = $('#myls-reload-source');
    var $btnR2 = $('#myls-reload-targets');

    var sourceCache = parseJSONScript('myls-source-cache') || [];
    var targetCache = parseJSONScript('myls-target-cache') || [];

    function paint() {
      renderSource($src, sourceCache, $srcF.val());
      renderTargets($tgt, targetCache, $tgtF.val());
    }

    // If either cache is empty, auto-hydrate from AJAX once
    var needHydrate = (!Array.isArray(sourceCache) || sourceCache.length === 0) ||
                      (!Array.isArray(targetCache) || targetCache.length === 0);

    if (needHydrate) {
      $src.prop('disabled', true);
      $tgt.prop('disabled', true);
      var pending = 0;

      if (!Array.isArray(sourceCache) || sourceCache.length === 0) {
        pending++;
        hydrateSourceCache(function (arr) {
          sourceCache = arr;
          pending--; if (pending === 0) { paint(); $src.prop('disabled', false); $tgt.prop('disabled', false); }
        });
      }
      if (!Array.isArray(targetCache) || targetCache.length === 0) {
        pending++;
        hydrateTargetCache(function (arr) {
          targetCache = arr;
          pending--; if (pending === 0) { paint(); $src.prop('disabled', false); $tgt.prop('disabled', false); }
        });
      }
      if (pending === 0) { paint(); $src.prop('disabled', false); $tgt.prop('disabled', false); }
    } else {
      paint();
    }

    // Live filtering
    $srcF.on('input', function () { renderSource($src, sourceCache, $srcF.val()); });
    $tgtF.on('input', function () { renderTargets($tgt, targetCache, $tgtF.val()); });

    // Reload buttons (manual refresh)
    $btnR1.on('click', function () {
      $src.prop('disabled', true);
      hydrateSourceCache(function (arr) {
        sourceCache = arr; renderSource($src, sourceCache, $srcF.val());
        $src.prop('disabled', false);
      });
    });

    $btnR2.on('click', function () {
      $tgt.prop('disabled', true);
      hydrateTargetCache(function (arr) {
        targetCache = arr; renderTargets($tgt, targetCache, $tgtF.val());
        $tgt.prop('disabled', false);
      });
    });

    // Clone action
    $('#myls-clone-sa-run').on('click', function () {
      var $btn = $(this),
          $spin = $('#myls-clone-sa-spinner'),
          $results = $('#myls-clone-sa-results');
      var source_id = $src.val();
      var target_ids = $tgt.val() || [];

      if (!source_id) { alert('Select a source Service Area.'); return; }
      if (!target_ids.length) { alert('Select at least one target parent.'); return; }

      var payload = {
        action: 'myls_clone_sa_to_parents',
        nonce: getNonce(),
        source_id: source_id,
        target_parent_ids: target_ids,
        as_draft: $('#myls-clone-sa-draft').is(':checked') ? 1 : 0,
        skip_existing: $('#myls-clone-sa-skip-existing').is(':checked') ? 1 : 0,
        debug: $('#myls-clone-sa-debug').is(':checked') ? 1 : 0,
        new_slug: $('#myls-clone-sa-slug').val() || '',
        focus_base: $('#myls-clone-sa-focus-base').val() || ''
      };

      $btn.prop('disabled', true);
      $spin.show();
      $results.html('<div>Running…</div>');

      $.post(ajaxurl, payload)
        .done(function (res) {
          if (res && res.success) {
            var lines = (res.data && Array.isArray(res.data.log)) ? res.data.log : [];
            if (lines.length) {
              var list = $('<ul class="mb-0"></ul>');
              lines.forEach(function (line) { $('<li>').text(line).appendTo(list); });
              $results.html(list);
            } else {
              $results.html('<div class="text-success">Done (no log returned).</div>');
            }
          } else {
            $results.html('<div class="text-danger">Error: ' + (res && res.data ? res.data : 'Unknown error.') + '</div>');
          }
        })
        .fail(function () {
          $results.html('<div class="text-danger">Network error.</div>');
        })
        .always(function () {
          $btn.prop('disabled', false);
          $spin.hide();
        });
    });
  });
})(jQuery);
