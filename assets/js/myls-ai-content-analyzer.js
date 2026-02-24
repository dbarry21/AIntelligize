/**
 * MYLS Content Analyzer
 * Standalone audit tool — analyzes existing page content and generates
 * actionable improvement plans.
 *
 * @since 6.3.0
 */
(function($){
  'use strict';
  if (!window.MYLS_CONTENT_ANALYZER) return;
  var CFG = window.MYLS_CONTENT_ANALYZER;
  var LOG = window.mylsLog;

  var $pt     = $('#myls_ca_pt');
  var $posts  = $('#myls_ca_posts');
  var $run    = $('#myls_ca_run');
  var $stop   = $('#myls_ca_stop');
  var $res    = $('#myls_ca_results');
  var $count  = $('#myls_ca_count');
  var $status = $('#myls_ca_status');
  var $scorecard = $('#myls_ca_scorecard');

  var stopping = false;

  /* ── Helpers ──────────────────────────────────────────────────────── */

  function setCount(n) { $count.text(String(n)); }

  function setBusy(on) {
    $run.prop('disabled', !!on);
    $stop.prop('disabled', !on);
    $pt.prop('disabled', !!on);
    $posts.prop('disabled', !!on);
    $status.text(on ? 'Analyzing…' : '');
  }

  function pad(label, w) {
    w = w || 22;
    while (label.length < w) label += ' ';
    return label;
  }

  function scoreIcon(pct) {
    if (pct >= 85) return '🟢';
    if (pct >= 60) return '🟡';
    return '🔴';
  }

  function priorityIcon(p) {
    if (p === 'high')   return '🔴';
    if (p === 'medium') return '🟡';
    return '🟢';
  }

  function checkIcon(ok) {
    return ok ? '✅' : '❌';
  }

  /* ── Load posts ──────────────────────────────────────────────────── */

  function loadPosts() {
    $posts.empty();
    $.post(CFG.ajaxurl, {
      action:    'myls_content_analyze_get_posts_v1',
      nonce:     CFG.nonce,
      post_type: $pt.val()
    }).done(function(resp){
      if (!resp || !resp.success || !resp.data || !Array.isArray(resp.data.posts)) {
        LOG.append('Failed to load posts.', $res[0]);
        return;
      }
      var posts = resp.data.posts;
      for (var i = 0; i < posts.length; i++) {
        var p = posts[i];
        var label = (p.title || '(no title)') + ' (ID ' + p.id + ')';
        if (p.status && p.status !== 'publish') label += ' [' + p.status + ']';
        $('<option>').val(String(p.id)).text(label).appendTo($posts);
      }
      LOG.append('Loaded ' + posts.length + ' posts for ' + $pt.val() + '.', $res[0]);
    }).fail(function(){
      LOG.append('AJAX error loading posts.', $res[0]);
    });
  }

  /* ── Format single analysis entry ────────────────────────────────── */

  function formatAnalysis(idx, total, data) {
    var q = data.quality || {};
    var c = data.completeness || {};
    var m = data.meta || {};
    var lines = [];

    // Header
    var hdr = '━━━ [' + idx + '/' + total + '] Post #' + data.post_id + ': ' + (data.title || '') + ' ';
    while (hdr.length < 62) hdr += '━';
    lines.push('');
    lines.push(hdr);

    // Score
    lines.push('');
    lines.push('  ' + scoreIcon(data.score) + ' ' + pad('SCORE:', 22) + data.score + '/100');
    lines.push('     URL: ' + (data.url || ''));

    // ── SEO Completeness Checklist ──
    lines.push('');
    lines.push('  ─── SEO Completeness ───');
    lines.push('  ' + checkIcon(c.has_content)       + ' ' + pad('Content (50+ words)') + (q.words || 0) + ' words');
    lines.push('  ' + checkIcon(c.has_meta_title)    + ' ' + pad('Meta Title')          + (m.yoast_title ? truncate(m.yoast_title, 50) : '(missing)'));
    lines.push('  ' + checkIcon(c.has_meta_desc)     + ' ' + pad('Meta Description')    + (m.yoast_desc ? truncate(m.yoast_desc, 50) : '(missing)'));
    lines.push('  ' + checkIcon(c.has_focus_keyword) + ' ' + pad('Focus Keyword')       + (m.focus_keyword || '(none)'));
    lines.push('  ' + checkIcon(c.has_h2)            + ' ' + pad('H2 Headings')         + (q.h2_count || 0));
    lines.push('  ' + checkIcon(c.has_h3)            + ' ' + pad('H3 Headings')         + (q.h3_count || 0));
    lines.push('  ' + checkIcon(c.has_lists)         + ' ' + pad('Lists')               + (q.ul_count || 0) + ' list(s)');
    lines.push('  ' + checkIcon(c.has_links)         + ' ' + pad('Internal Links')      + (q.link_count || 0));
    lines.push('  ' + checkIcon(c.has_location_ref)  + ' ' + pad('Location Reference')  + (q.location_mentions || 0) + 'x' + (m.city_state ? ' ("' + m.city_state + '")' : ''));
    lines.push('  ' + checkIcon(c.has_excerpt)       + ' ' + pad('Excerpt')             + (m.excerpt_len > 0 ? m.excerpt_len + ' chars' : (m.html_excerpt ? 'HTML excerpt' : '(missing)')));
    lines.push('  ' + checkIcon(c.has_about_area)    + ' ' + pad('About the Area')      + (m.about_words > 0 ? m.about_words + ' words' : '(missing)'));
    lines.push('  ' + checkIcon(c.has_faqs)          + ' ' + pad('FAQs')                + (m.faq_present ? 'Yes' : '(missing)'));
    lines.push('  ' + checkIcon(c.has_tagline)       + ' ' + pad('Service Tagline')     + (m.tagline ? truncate(m.tagline, 40) : '(missing)'));

    // ── Content Quality Metrics ──
    lines.push('');
    lines.push('  ─── Content Quality ───');
    lines.push('  📏 ' + pad('Words:')           + (q.words || 0));
    lines.push('  📄 ' + pad('Paragraphs:')      + (q.paragraphs || 0));
    lines.push('  📝 ' + pad('Sentences:')        + (q.sentences || 0));
    lines.push('  📊 ' + pad('Avg Sentence Len:') + (q.avg_sentence_len || 0) + ' words');

    if (q.readability_grade) {
      var grade = q.readability_grade;
      var level = grade <= 6 ? 'Easy' : grade <= 10 ? 'Standard' : grade <= 14 ? 'Advanced' : 'Complex';
      lines.push('  📖 ' + pad('Readability:')    + grade.toFixed(1) + ' (' + level + ')');
    }

    if (q.keyword_count > 0) {
      lines.push('  🔑 ' + pad('KW Density:')     + q.keyword_count + 'x (' + (q.keyword_density || 0) + '%)');
    }

    // Uniqueness
    if (q.opening_match && q.opening_match !== '(none)') {
      lines.push('  ⚠️  ' + pad('Stock Opener:')   + '"' + q.opening_match + '…" detected');
    } else {
      lines.push('  ✅ ' + pad('Stock Opener:')    + 'None detected');
    }

    if (q.first_sentence) {
      lines.push('  📝 First Sentence:');
      var fs = q.first_sentence;
      while (fs.length > 0) {
        lines.push('     ' + fs.substring(0, 60));
        fs = fs.substring(60);
      }
    }

    // About the Area quality (if present)
    if (data.about_quality) {
      var aq = data.about_quality;
      lines.push('');
      lines.push('  ─── About the Area Quality ───');
      lines.push('  📏 ' + pad('Words:')           + (aq.words || 0));
      lines.push('  📄 ' + pad('Paragraphs:')      + (aq.paragraphs || 0));
      lines.push('  📑 ' + pad('Headings:')         + 'H2:' + (aq.h2_count||0) + '  H3:' + (aq.h3_count||0));
      if (aq.opening_match && aq.opening_match !== '(none)') {
        lines.push('  ⚠️  ' + pad('Stock Opener:')   + '"' + aq.opening_match + '…"');
      }
    }

    // ── Recommendations ──
    var recs = data.recommendations || [];
    if (recs.length > 0) {
      lines.push('');
      lines.push('  ─── Action Items (' + recs.length + ') ───');
      for (var r = 0; r < recs.length; r++) {
        var rec = recs[r];
        lines.push('  ' + priorityIcon(rec.priority) + ' [' + rec.priority.toUpperCase() + '] ' + rec.area);
        lines.push('     → ' + rec.action);
      }
    } else {
      lines.push('');
      lines.push('  🎉 No critical issues found!');
    }

    lines.push('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    return lines.join('\n');
  }

  function truncate(s, max) {
    if (!s) return '';
    return s.length > max ? s.substring(0, max) + '…' : s;
  }

  /* ── Format batch scorecard ──────────────────────────────────────── */

  function formatScorecard(results) {
    var lines = [];
    lines.push('');
    lines.push('╔════════════════════════════════════════════════════════════════╗');
    lines.push('║  CONTENT AUDIT SUMMARY');
    lines.push('╠════════════════════════════════════════════════════════════════╣');
    lines.push('║  Pages Analyzed:   ' + results.length);

    // Avg score
    var scores = results.map(function(r){ return r.score || 0; });
    var avgScore = scores.length > 0 ? Math.round(scores.reduce(function(a,b){ return a+b; }, 0) / scores.length) : 0;
    lines.push('║  Average Score:    ' + scoreIcon(avgScore) + ' ' + avgScore + '/100');

    // Score distribution
    var green = scores.filter(function(s){ return s >= 85; }).length;
    var yellow = scores.filter(function(s){ return s >= 60 && s < 85; }).length;
    var red = scores.filter(function(s){ return s < 60; }).length;
    lines.push('║');
    lines.push('║  🟢 Strong (85+):   ' + green);
    lines.push('║  🟡 Fair (60-84):    ' + yellow);
    lines.push('║  🔴 Needs Work (<60):' + red);

    // Most common missing items
    var missing = {};
    var checkLabels = {
      has_meta_title:   'Meta Title',
      has_meta_desc:    'Meta Description',
      has_focus_keyword:'Focus Keyword',
      has_excerpt:      'Excerpt',
      has_about_area:   'About Area',
      has_faqs:         'FAQs',
      has_tagline:      'Tagline',
      has_h2:           'H2 Headings',
      has_h3:           'H3 Headings',
      has_lists:        'Lists',
      has_links:        'Links',
      has_location_ref: 'Location Ref'
    };

    results.forEach(function(r){
      var c = r.completeness || {};
      Object.keys(checkLabels).forEach(function(k){
        if (!c[k]) {
          missing[k] = (missing[k] || 0) + 1;
        }
      });
    });

    // Sort by most missing
    var missingSorted = Object.keys(missing).sort(function(a,b){ return missing[b] - missing[a]; });
    if (missingSorted.length > 0) {
      lines.push('║');
      lines.push('║  📋 Most Common Gaps:');
      missingSorted.forEach(function(k){
        var pct = Math.round((missing[k] / results.length) * 100);
        lines.push('║     ' + checkLabels[k] + ': ' + missing[k] + '/' + results.length + ' missing (' + pct + '%)');
      });
    }

    // Avg content stats
    var wordList = results.map(function(r){ return (r.quality||{}).words || 0; }).filter(function(w){ return w > 0; });
    if (wordList.length > 0) {
      var avgWords = Math.round(wordList.reduce(function(a,b){ return a+b; }, 0) / wordList.length);
      var minWords = Math.min.apply(null, wordList);
      var maxWords = Math.max.apply(null, wordList);
      lines.push('║');
      lines.push('║  📊 Content Length:');
      lines.push('║     Average:  ' + avgWords + ' words');
      lines.push('║     Shortest: ' + minWords + ' words');
      lines.push('║     Longest:  ' + maxWords + ' words');
    }

    // Stock openers
    var openerCount = results.filter(function(r){ return r.quality && r.quality.opening_match && r.quality.opening_match !== '(none)'; }).length;
    if (openerCount > 0) {
      lines.push('║');
      lines.push('║  ⚠️  Stock Openers: ' + openerCount + '/' + results.length + ' pages');
    }

    // Weakest pages (lowest scores)
    var sorted = results.slice().sort(function(a,b){ return (a.score||0) - (b.score||0); });
    var weakest = sorted.slice(0, Math.min(5, sorted.length));
    if (weakest.length > 0 && weakest[0].score < 85) {
      lines.push('║');
      lines.push('║  🔻 Weakest Pages:');
      weakest.forEach(function(r){
        lines.push('║     ' + scoreIcon(r.score) + ' ' + r.score + '/100 — ' + truncate(r.title, 40) + ' (#' + r.post_id + ')');
      });
    }

    lines.push('╚════════════════════════════════════════════════════════════════╝');
    return lines.join('\n');
  }

  /* ── Run analysis ────────────────────────────────────────────────── */

  function run() {
    var ids = ($posts.val() || []).map(function(v){ return parseInt(v, 10); }).filter(Boolean);
    if (!ids.length) {
      LOG.append('\n⚠️  Select at least one post.', $res[0]);
      return;
    }

    stopping = false;
    setCount(0);
    setBusy(true);

    var total = ids.length;
    var done = 0;
    var allResults = [];

    LOG.clear($res[0],
      '╔════════════════════════════════════════════════════════════════╗\n' +
      '║  MYLS Content Analyzer\n' +
      '║  ' + new Date().toLocaleString() + '\n' +
      '╠════════════════════════════════════════════════════════════════╣\n' +
      '║  Pages to analyze: ' + total + '\n' +
      '║  Post type:        ' + $pt.val() + '\n' +
      '╚════════════════════════════════════════════════════════════════╝'
    );

    (function next(){
      if (stopping || !ids.length) {
        setBusy(false);
        // Render scorecard
        if (allResults.length > 0) {
          LOG.append(formatScorecard(allResults), $res[0]);
        }
        $status.text(stopping ? 'Stopped.' : 'Done.');
        updateScorecardPanel(allResults);
        return;
      }

      var id = ids.shift();
      var idx = total - ids.length;

      $.post(CFG.ajaxurl, {
        action:  'myls_content_analyze_v1',
        nonce:   CFG.nonce,
        post_id: id
      })
      .done(function(resp){
        if (resp && resp.success && resp.data) {
          var d = resp.data;
          allResults.push(d);
          LOG.append(formatAnalysis(idx, total, d), $res[0]);
        } else {
          var msg = (resp && resp.data && resp.data.message) || 'Unknown error';
          LOG.append('\n  ❌ [' + idx + '/' + total + '] Post #' + id + ' — ERROR: ' + msg, $res[0]);
        }
      })
      .fail(function(xhr){
        LOG.append('\n  ❌ [' + idx + '/' + total + '] Post #' + id + ' — AJAX error (' + (xhr && xhr.status) + ')', $res[0]);
      })
      .always(function(){
        done++;
        setCount(done);
        next();
      });
    })();
  }

  /* ── Scorecard panel (HTML summary above results) ────────────────── */

  function updateScorecardPanel(results) {
    if (!$scorecard.length || results.length === 0) {
      $scorecard.html('');
      return;
    }

    var scores = results.map(function(r){ return r.score || 0; });
    var avgScore = Math.round(scores.reduce(function(a,b){ return a+b; }, 0) / scores.length);

    var green  = scores.filter(function(s){ return s >= 85; }).length;
    var yellow = scores.filter(function(s){ return s >= 60 && s < 85; }).length;
    var red    = scores.filter(function(s){ return s < 60; }).length;

    var html = '<div class="d-flex gap-3 flex-wrap align-items-center">';
    html += '<div class="p-3 rounded text-center" style="background:#0b1220;color:#d1e7ff;min-width:120px;">';
    html += '<div style="font-size:2rem;font-weight:700;">' + avgScore + '</div>';
    html += '<div style="font-size:.75rem;opacity:.7;">AVG SCORE</div>';
    html += '</div>';

    html += '<div class="d-flex gap-2">';
    if (green)  html += '<span class="badge bg-success fs-6">' + green + ' Strong</span>';
    if (yellow) html += '<span class="badge bg-warning text-dark fs-6">' + yellow + ' Fair</span>';
    if (red)    html += '<span class="badge bg-danger fs-6">' + red + ' Needs Work</span>';
    html += '</div>';

    html += '<div style="font-size:.85rem;color:#6c757d;">' + results.length + ' pages analyzed</div>';
    html += '</div>';

    $scorecard.html(html);
  }

  /* ── Event bindings ──────────────────────────────────────────────── */

  $pt.on('change', loadPosts);
  $run.on('click', function(e){ e.preventDefault(); run(); });
  $stop.on('click', function(e){ e.preventDefault(); stopping = true; });

  // Initial load
  loadPosts();

})(jQuery);
