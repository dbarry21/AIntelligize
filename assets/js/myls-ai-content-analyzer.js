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

/* =========================================================================
 * AI Deep Analysis — v7.7.0
 *
 * - Card-based rich UI (renders section blocks with labels, colored headers)
 * - Raw terminal log (collapsible, for the Print Log button)
 * - Download PDF Report button (POSTs results to PHP, streams binary PDF)
 * ========================================================================= */
(function($){
  'use strict';
  if (!window.MYLS_CONTENT_ANALYZER) return;
  var CFG = window.MYLS_CONTENT_ANALYZER;
  var LOG = window.mylsLog;

  // UI elements
  var $pt        = $('#myls_ca_pt');
  var $posts     = $('#myls_ca_posts');
  var $deepRun   = $('#myls_ca_deep_run');
  var $deepStop  = $('#myls_ca_deep_stop');
  var $cards     = $('#myls_ca_deep_cards');
  var $log       = $('#myls_ca_deep_log');
  var $logWrap   = $('#myls_ca_deep_log_wrap');
  var $logToggle = $('#myls_ca_deep_log_toggle');
  var $pdfBtn    = $('#myls_ca_deep_pdf');
  var $count     = $('#myls_ca_count');
  var $status    = $('#myls_ca_status');

  var deepStopping = false;
  var allDeepResults = [];   // accumulated for PDF download

  /* ── Section styling map ───────────────────────────────────────────── */
  var sectionStyles = [
    { match: 'WRITING',   label: 'WRITING QUALITY', bg: '#edf7ee', color: '#007017', text: '#1d2327' },
    { match: 'CITATION',  label: 'AI CITATION',     bg: '#f5f0ff', color: '#6f42c1', text: '#1d2327' },
    { match: 'COMPETITOR',label: 'COMPETITOR GAPS', bg: '#fffbeb', color: '#996800', text: '#1d2327' },
    { match: 'REWRITE',   label: 'REWRITES',        bg: '#fff5f5', color: '#d63638', text: '#1d2327' },
  ];

  function getSectionStyle(heading) {
    var h = heading.toUpperCase();
    for (var i = 0; i < sectionStyles.length; i++) {
      if (h.indexOf(sectionStyles[i].match) !== -1) return sectionStyles[i];
    }
    return { label: 'ANALYSIS', bg: '#f8f9fa', color: '#2271b1', text: '#1d2327' };
  }

  /* ── Parsing the AI markdown response ─────────────────────────────── */
  function parseAnalysisSections(analysisText) {
    var sections = [];
    var lines = analysisText.split('\n');
    var current = null;
    var bodyLines = [];

    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      // Detect section headers: "### 1. WRITING QUALITY..." or "## ..."
      var m = line.match(/^#{1,4}\s*\d*\.?\s*(.+)/);
      if (m) {
        if (current !== null) {
          sections.push({ heading: current, body: bodyLines.join('\n').trim() });
        }
        current = m[1].trim();
        bodyLines = [];
      } else if (current !== null) {
        bodyLines.push(line);
      }
    }
    if (current !== null && bodyLines.length > 0) {
      sections.push({ heading: current, body: bodyLines.join('\n').trim() });
    }
    // If no sections, one big block
    if (sections.length === 0 && analysisText.trim()) {
      sections.push({ heading: 'Analysis', body: analysisText.trim() });
    }
    return sections;
  }

  /* ── Render a single post result as a card ─────────────────────────── */
  function renderResultCard(idx, total, data) {
    var m = data.meta || {};
    var sections = parseAnalysisSections(data.analysis || '');

    var metaChips = [
      '<span class="myls-deep-chip"><strong>' + esc(String(m.word_count || 0)) + '</strong> words</span>',
      '<span class="myls-deep-chip">KW: <strong>' + esc(m.focus_keyword || '(none)') + '</strong></span>',
      '<span class="myls-deep-chip">Location: <strong>' + esc(m.city_state || '(none)') + '</strong></span>',
      '<span class="myls-deep-chip">Schema: <strong>' + (m.has_schema ? '<span style="color:#007017">&#10003; Detected</span>' : '<span style="color:#d63638">&#10005; None</span>') + '</strong></span>',
    ].join('');

    var sectionsHtml = '';
    for (var i = 0; i < sections.length; i++) {
      var sec = sections[i];
      var style = getSectionStyle(sec.heading);
      sectionsHtml += (
        '<div class="myls-deep-section" style="background:' + style.bg + '">' +
          '<span class="myls-deep-section-label" style="background:' + style.color + ';color:#fff;">' +
            esc(style.label) +
          '</span>' +
          '<div class="myls-deep-section-heading">' + esc(sec.heading) + '</div>' +
          '<div class="myls-deep-section-body">' + esc(sec.body) + '</div>' +
        '</div>'
      );
    }

    var card = (
      '<div class="myls-deep-post-card">' +
        '<div class="myls-deep-post-header">' +
          '<span class="myls-deep-post-num">' + idx + ' / ' + total + '</span>' +
          '<div style="flex:1;min-width:0;">' +
            '<div class="myls-deep-post-title">' + esc(data.title || '') + '</div>' +
            '<span class="myls-deep-post-url">' + esc(data.url || '') + '</span>' +
          '</div>' +
        '</div>' +
        '<div class="myls-deep-meta-strip">' + metaChips + '</div>' +
        '<div class="myls-deep-sections">' + sectionsHtml + '</div>' +
      '</div>'
    );
    return card;
  }

  /* ── Append to raw log terminal ────────────────────────────────────── */
  function logDeep(idx, total, data) {
    var m = data.meta || {};
    var lines = [];
    lines.push('');
    var hdr = '=== [' + idx + '/' + total + '] ' + (data.title || '') + ' ===';
    lines.push(hdr);
    lines.push('    Words: ' + (m.word_count || 0) + '   KW: ' + (m.focus_keyword || '(none)'));
    lines.push('    Schema: ' + (m.has_schema ? 'Detected' : 'Not found'));
    lines.push('');
    // Render sections
    var sections = parseAnalysisSections(data.analysis || '');
    for (var i = 0; i < sections.length; i++) {
      lines.push('--- ' + sections[i].heading + ' ---');
      lines.push(sections[i].body);
      lines.push('');
    }
    lines.push('='.repeat(62));
    LOG.append(lines.join('\n'), $log[0]);
  }

  /* ── Busy state ────────────────────────────────────────────────────── */
  function setDeepBusy(on) {
    $deepRun.prop('disabled', !!on);
    $deepStop.prop('disabled', !on);
    $pt.prop('disabled', !!on);
    $posts.prop('disabled', !!on);
    $status.text(on ? 'AI analyzing…' : '');
    $deepRun.css({
      background: on ? '#4a2d8a' : '#6f42c1',
      'border-color': on ? '#4a2d8a' : '#6f42c1'
    });
  }

  /* ── HTML escape ────────────────────────────────────────────────────── */
  function esc(s) {
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  /* ── Run AI Deep Analysis ──────────────────────────────────────────── */
  function runDeep() {
    var ids = ($posts.val() || []).map(function(v){ return parseInt(v,10); }).filter(Boolean);
    if (!ids.length) {
      $cards.show().html('<div class="myls-deep-empty"><i class="bi bi-exclamation-triangle"></i> Select at least one post first.</div>');
      return;
    }

    deepStopping = false;
    allDeepResults = [];
    $count.text('0');
    setDeepBusy(true);
    $pdfBtn.hide();

    // Show card area with loading state; reset log
    $cards.show().html(
      '<div class="myls-deep-empty" id="myls_ca_deep_loading">' +
      '<div class="spinner-border spinner-border-sm text-purple me-2" style="color:#6f42c1" role="status"></div>' +
      'Sending to AI… (this may take 15-30s per page)' +
      '</div>'
    );
    LOG.clear($log[0],
      '★ AI DEEP ANALYSIS STARTED\n' +
      '  ' + new Date().toLocaleString() + '\n' +
      '  Pages: ' + ids.length
    );

    var total = ids.length;
    var done  = 0;

    (function next() {
      if (deepStopping || !ids.length) {
        setDeepBusy(false);
        $('#myls_ca_deep_loading').remove();
        $status.text(deepStopping ? 'Stopped.' : 'Complete — ' + done + ' page(s) analyzed.');

        if (allDeepResults.length > 0) {
          $pdfBtn.show();
          LOG.append('\n★ Complete — ' + done + '/' + total + ' pages. Use Download PDF Report to export.', $log[0]);
        }
        return;
      }

      var id  = ids.shift();
      var idx = total - ids.length;

      $.post(CFG.ajaxurl, {
        action:  'myls_content_analyze_ai_deep_v1',
        nonce:   CFG.nonce,
        post_id: id
      })
      .done(function(resp) {
        $('#myls_ca_deep_loading').remove();
        if (resp && resp.success && resp.data) {
          allDeepResults.push(resp.data);
          $cards.append(renderResultCard(idx, total, resp.data));
          logDeep(idx, total, resp.data);
        } else {
          var msg = (resp && resp.data && resp.data.message) || 'Unknown error';
          $cards.append(
            '<div class="alert alert-danger mb-3">&#10005; [' + idx + '/' + total + '] Post #' + id + ' — ' + esc(msg) + '</div>'
          );
          LOG.append('\n[ERROR] [' + idx + '/' + total + '] Post #' + id + ': ' + msg, $log[0]);
        }
      })
      .fail(function(xhr) {
        $('#myls_ca_deep_loading').remove();
        var msg = 'AJAX error (' + (xhr && xhr.status) + ')';
        $cards.append('<div class="alert alert-danger mb-3">&#10005; ' + esc(msg) + '</div>');
        LOG.append('\n[ERROR] ' + msg, $log[0]);
      })
      .always(function() {
        done++;
        $count.text(String(done));
        next();
      });
    })();
  }

  /* ── PDF Download ──────────────────────────────────────────────────── */
  function downloadPdf() {
    if (!allDeepResults.length) return;

    $pdfBtn.prop('disabled', true).text('Generating PDF…');

    var form = document.createElement('form');
    form.method = 'POST';
    form.action = CFG.ajaxurl;
    form.style.display = 'none';

    function addField(name, value) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      form.appendChild(input);
    }

    addField('action',  'myls_ca_deep_pdf_v1');
    addField('nonce',   CFG.nonce);
    addField('results', JSON.stringify(allDeepResults));

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);

    // Re-enable after short delay
    setTimeout(function() {
      $pdfBtn.prop('disabled', false).html('<i class="bi bi-file-earmark-arrow-down"></i> Download PDF Report');
    }, 3000);
  }

  /* ── Log toggle ────────────────────────────────────────────────────── */
  $logToggle.on('click', function() {
    var visible = $log.is(':visible');
    $log.toggle(!visible);
    $logToggle.html(visible
      ? '<i class="bi bi-terminal"></i> Show Raw Log'
      : '<i class="bi bi-terminal-x"></i> Hide Raw Log'
    );
  });

  /* ── Event bindings ────────────────────────────────────────────────── */
  $deepRun.on('click',  function(e) { e.preventDefault(); runDeep(); });
  $deepStop.on('click', function(e) { e.preventDefault(); deepStopping = true; });
  $pdfBtn.on('click',   function(e) { e.preventDefault(); downloadPdf(); });

})(jQuery);
