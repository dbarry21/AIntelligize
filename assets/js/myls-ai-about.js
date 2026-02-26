(function($){
  'use strict';
  if (!window.MYLS_AI_ABOUT) return;
  const CFG = window.MYLS_AI_ABOUT;
  const LOG = window.mylsLog;

  const $pt     = $('#myls_ai_about_pt');
  const $posts  = $('#myls_ai_about_posts');
  const $skip   = $('#myls_ai_about_skip_filled');
  const $gen    = $('#myls_ai_about_generate');
  const $stop   = $('#myls_ai_about_stop');
  const $res    = $('#myls_ai_about_results');
  const $count  = $('#myls_ai_about_count');
  const $status = $('#myls_ai_about_status');
  const $prog   = $('#myls_ai_about_progress');

  let stopping = false;

  function setCount(n){ $count.text(String(n)); }
  function setBusy(on){
    $gen.prop('disabled', !!on);
    $stop.prop('disabled', !on);
    $pt.prop('disabled', !!on);
    $posts.prop('disabled', !!on);
    $skip.prop('disabled', !!on);
    if (!on) {
      $status.text('');
      $prog.text('');
    }
  }

  function setProgress(current, total, title){
    const pct = total > 0 ? Math.round((current / total) * 100) : 0;
    $status.text('Processing ' + current + ' of ' + total + ' (' + pct + '%)');
    $prog.text(title ? '— ' + title : '');
  }

  function loadPosts(){
    $posts.empty();
    $.post(CFG.ajaxurl, {
      action: CFG.action_get_posts || 'myls_ai_about_get_posts_v2',
      nonce:  CFG.nonce,
      post_type: $pt.val()
    }).done(function(resp){
      if (!resp || !resp.success || !resp.data || !Array.isArray(resp.data.posts)) {
        LOG.append('Failed to load posts (bad response).', $res[0]);
        return;
      }
      const posts = resp.data.posts;
      for (let i=0;i<posts.length;i++){
        const p = posts[i];
        $('<option>').val(String(p.id)).text((p.has_content ? '✓ ' : '') + (p.title||'(no title)')+' (ID '+p.id+')').appendTo($posts);
      }
      LOG.append('Loaded '+posts.length+' posts for '+$pt.val()+'.', $res[0]);
    }).fail(function(xhr){
      LOG.append('AJAX error: get_posts ('+(xhr && xhr.status)+')', $res[0]);
    });
  }

  function readTemplateParams(){
    const tpl = $('textarea[name="myls_ai_about_prompt_template"]').val() || '';
    const tok = parseInt($('input[name="myls_ai_about_tokens"]').val() || '600', 10);
    const tmp = parseFloat($('input[name="myls_ai_about_temperature"]').val() || '0.7');
    return { template: tpl, tokens: tok, temperature: tmp };
  }

  function run(){
    const ids = ($posts.val() || []).map(v => parseInt(v, 10)).filter(Boolean);
    if (!ids.length) { LOG.append('\n⚠️  Select at least one post.', $res[0]); return; }

    stopping = false;
    setCount(0);
    setBusy(true);

    const { template, tokens, temperature } = readTemplateParams();
    const skip = $skip.is(':checked');
    let done = 0;
    let stats = { saved: 0, skipped: 0, errors: 0 };
    const tracker = LOG.createTracker();
    const total = ids.length;

    // Build title lookup from the select options
    const titleMap = {};
    $posts.find('option').each(function(){
      titleMap[$(this).val()] = $(this).text().replace(/\s*\(ID \d+\)\s*$/, '');
    });

    LOG.clear($res[0], LOG.batchStart('About the Area', total, {
      model: CFG.model || 'default',
      temperature: temperature,
      tokens: tokens
    }));

    (function next(){
      if (stopping || !ids.length) {
        setBusy(false);
        if (stopping) $status.text('Stopped.');
        LOG.append(LOG.batchSummary(tracker.getSummary(stats)), $res[0]);
        return;
      }
      const id = ids.shift();
      const idx = total - ids.length;
      const title = titleMap[String(id)] || '#' + id;

      setProgress(idx, total, title);

      $.post(CFG.ajaxurl, {
        action:      CFG.action_generate || 'myls_ai_about_generate_v2',
        nonce:       CFG.nonce,
        post_id:     id,
        skip_filled: skip ? 1 : 0,
        template:    template,
        tokens:      tokens,
        temperature: temperature
      })
      .done(function(resp){
        if (!resp || typeof resp !== 'object') {
          LOG.append(LOG.formatError(id, { message: 'Unexpected server response' }, { index: idx, total: total }), $res[0]);
          stats.errors++;
          return;
        }
        if (resp.success) {
          const d = resp.data || {};
          if (d.status === 'skipped') {
            LOG.append(LOG.formatSkipped(id, d, { index: idx, total: total }), $res[0]);
            stats.skipped++;
          } else {
            LOG.append(LOG.formatEntry(id, d, { index: idx, total: total, handler: 'About Area' }), $res[0]);
            stats.saved++;
            tracker.track(d);
          }
        } else {
          LOG.append(LOG.formatError(id, resp.data || {}, { index: idx, total: total }), $res[0]);
          stats.errors++;
        }
      })
      .fail(function(xhr){
        LOG.append(LOG.formatError(id, { message: 'AJAX error (HTTP ' + (xhr && xhr.status) + ')' }, { index: idx, total: total }), $res[0]);
        stats.errors++;
      })
      .always(function(){
        done++; setCount(done); next();
      });

    })();
  }

  $pt.on('change', loadPosts);
  $gen.on('click', function(e){ e.preventDefault(); run(); });
  $stop.on('click', function(e){ e.preventDefault(); stopping = true; });

  if ($pt.val() !== CFG.defaultType) $pt.val(CFG.defaultType);
  loadPosts();

})(jQuery);
