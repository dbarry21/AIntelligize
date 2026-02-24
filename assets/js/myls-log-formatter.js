/**
 * MYLS Enterprise Log Formatter v2
 * Shared across all AI subtab JS files for consistent terminal-style output.
 *
 * Displays: request tracking, AI config, content quality analysis,
 * variation engine, duplicate guard, cost estimation, and batch summaries.
 *
 * @since 6.3.0
 */
window.mylsLog = (function(){
  'use strict';

  var SEP  = '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━';
  var LINE = '──────────────────────────────────────────────────────────────';

  /* ── Helpers ──────────────────────────────────────────────────────── */

  function pad(label, width) {
    width = width || 22;
    while (label.length < width) label += ' ';
    return label;
  }

  function fmtTime(ms) {
    if (!ms && ms !== 0) return 'n/a';
    if (ms < 1000) return ms + 'ms';
    return (ms / 1000).toFixed(1) + 's';
  }

  function fmtCost(usd) {
    if (!usd && usd !== 0) return '$0.00';
    if (usd < 0.01) return '<$0.01';
    return '$' + usd.toFixed(4);
  }

  function simBar(pct, threshold) {
    threshold = threshold || 60;
    var filled = Math.round(pct / 5);
    var bar = '';
    for (var i = 0; i < 20; i++) {
      bar += i < filled ? '█' : '░';
    }
    var warn = pct >= threshold ? ' ⚠ ABOVE THRESHOLD' : '';
    return bar + ' ' + pct.toFixed(1) + '%' + warn;
  }

  function batchHeaderLine(index, total, title, postId) {
    var hdr = '━━━ [' + index + '/' + total + '] Post #' + postId;
    if (title) hdr += ': ' + title;
    hdr += ' ';
    while (hdr.length < 62) hdr += '━';
    return hdr;
  }

  function section(label) {
    return '  ─── ' + label + ' ───';
  }

  function row(icon, label, value) {
    return '  ' + icon + ' ' + pad(label) + value;
  }

  /* ── Format: full entry ──────────────────────────────────────────── */

  function formatEntry(postId, data, opts) {
    opts = opts || {};
    var log = data.log || {};
    var q   = log.quality || {};
    var c   = log.cost || {};
    var lines = [];

    var title = log.page_title || data.city_state || data.title || '';
    lines.push('');
    lines.push(batchHeaderLine(opts.index || 1, opts.total || 1, title, postId));

    // ── Request Tracking ──
    if (log.request_id || log.timestamp) {
      lines.push(section('Request'));
      if (log.request_id) lines.push(row('🆔', 'Request ID:', log.request_id));
      if (log.timestamp)  lines.push(row('🕐', 'Timestamp:', log.timestamp));
    }

    // Status
    var status = (data.status || 'unknown').toUpperCase();
    var statusIcon = (status === 'SAVED' || status === 'GENERATED' || status === 'OK') ? '✅' : status === 'SKIPPED' ? '⏭️' : '❌';
    lines.push(row(statusIcon, 'Status:', status));

    if (data.city_state) lines.push(row('📍', 'Location:', data.city_state));

    // Handler context
    if (log.focus_keyword && log.focus_keyword !== '(none)') {
      lines.push(row('🔑', 'Focus Keyword:', log.focus_keyword));
    }
    if (log.service_subtype && log.service_subtype !== '(none)') {
      lines.push(row('🏷️ ', 'Service Type:', log.service_subtype));
    }
    if (log.kind) lines.push(row('📋', 'Kind:', log.kind));

    // ── AI Configuration ──
    lines.push('');
    lines.push(section('AI Configuration'));
    lines.push(row('🤖', 'Model:', log.model || 'default'));
    if (log.temperature != null) lines.push(row('🔧', 'Temperature:', String(log.temperature)));
    if (log.tokens)     lines.push(row('📊', 'Max Tokens:', String(log.tokens)));
    if (log.prompt_chars) lines.push(row('📝', 'Prompt Size:', log.prompt_chars.toLocaleString() + ' chars'));

    // ── Output Metrics ──
    lines.push('');
    lines.push(section('Output Metrics'));
    if (log.output_words)  lines.push(row('📏', 'Words:', log.output_words.toLocaleString()));
    if (log.output_chars)  lines.push(row('📐', 'Characters:', log.output_chars.toLocaleString()));
    else if (log.char_count != null) lines.push(row('📐', 'Characters:', String(log.char_count)));
    if (log.has_h3 != null)     lines.push(row('🔖', 'Has <h3>:', log.has_h3 ? 'Yes ✓' : 'No ✗'));
    if (log.retry_used != null) lines.push(row('🔄', 'Retry Used:', log.retry_used ? 'Yes (short/missing h3)' : 'No'));

    // Handler-specific output
    if (log.faq_count != null)     lines.push(row('❓', 'FAQ Count:', String(log.faq_count)));
    if (log.tagline_count != null) lines.push(row('💬', 'Tagline Options:', String(log.tagline_count)));
    if (log.image_count != null)   lines.push(row('🖼️ ', 'Images:', String(log.image_count)));
    if (log.include_faq)           lines.push(row('📑', 'FAQ/HowTo:', log.include_faq));
    if (log.has_doc != null)       lines.push(row('📄', 'DOCX Generated:', log.has_doc ? 'Yes' : 'No'));

    // ── Content Quality ──
    if (q && q.words) {
      lines.push('');
      lines.push(section('Content Quality'));
      lines.push(row('📄', 'Paragraphs:', String(q.paragraphs || 0)));
      lines.push(row('📑', 'Headings:', 'H2:' + (q.h2_count || 0) + '  H3:' + (q.h3_count || 0)));
      if (q.ul_count)  lines.push(row('📋', 'Lists:', q.ul_count + ' list(s), ' + (q.li_count || 0) + ' item(s)'));
      if (q.link_count) lines.push(row('🔗', 'Links:', String(q.link_count)));
      lines.push(row('📝', 'Sentences:', String(q.sentences || 0)));
      lines.push(row('📊', 'Avg Sent Len:', (q.avg_sentence_len || 0) + ' words'));
      if (q.readability_grade) {
        var grade = q.readability_grade;
        var level = grade <= 6 ? 'Easy' : grade <= 10 ? 'Standard' : grade <= 14 ? 'Advanced' : 'Complex';
        lines.push(row('📖', 'Readability:', grade.toFixed(1) + ' (' + level + ')'));
      }
      if (q.location_mentions != null) {
        lines.push(row('📍', 'Location Refs:', String(q.location_mentions) + (q.location_mentions === 0 ? ' ⚠ NONE' : '')));
      }
      if (q.keyword_count != null && q.keyword_count > 0) {
        lines.push(row('🔑', 'KW Density:', q.keyword_count + 'x (' + (q.keyword_density || 0) + '%)'));
      }
    }

    // ── Uniqueness ──
    if (q && q.first_sentence) {
      lines.push('');
      lines.push(section('Uniqueness'));
      var opener = q.opening_match || '(none)';
      var openerIcon = opener === '(none)' ? '✅' : '⚠️';
      lines.push(row(openerIcon, 'Stock Opener:', opener === '(none)' ? 'None detected ✓' : '"' + opener + '…" detected'));
      lines.push('  📝 First Sentence:');
      var fs = q.first_sentence;
      while (fs.length > 0) {
        lines.push('     ' + fs.substring(0, 60));
        fs = fs.substring(60);
      }
    }

    // ── Variation Engine ──
    if (log.angle) {
      lines.push('');
      lines.push(section('Variation Engine'));
      lines.push(row('🎯', 'Angle:', log.angle + ' (' + (log.angle_position || '?') + '/' + (log.angle_pool_size || '?') + ')'));
      if (log.banned_count > 0) {
        lines.push(row('🚫', 'Banned Phrases:', log.banned_count + ' injected'));
      }
    }

    // ── Duplicate Guard ──
    if (log.dedup_checked) {
      lines.push('');
      lines.push(section('Duplicate Guard'));
      lines.push(row('🔍', 'Batch Position:', '#' + (log.dedup_batch_pos || 1)));
      lines.push(row('📊', 'Max Similarity:', simBar(log.dedup_max_sim || 0)));
      lines.push(row('🛡️ ', 'Rewrite:', log.dedup_rewrite ? '⚠️ YES — content rewritten' : 'Not needed ✓'));
    }

    // ── Cost Estimate ──
    if (c && c.est_cost_usd != null) {
      lines.push('');
      lines.push(section('Cost Estimate'));
      lines.push(row('📥', 'Input Tokens:', (c.input_tokens || 0).toLocaleString()));
      lines.push(row('📤', 'Output Tokens:', (c.output_tokens || 0).toLocaleString()));
      lines.push(row('💰', 'Est. Cost:', fmtCost(c.est_cost_usd)));
    }

    // ── Timing ──
    lines.push('');
    lines.push(row('⏱️ ', 'Elapsed:', fmtTime(log.elapsed_ms)));

    // ── Preview ──
    if (data.preview) {
      lines.push('');
      lines.push('  📋 Preview:');
      var prev = data.preview;
      while (prev.length > 0) {
        lines.push('     ' + prev.substring(0, 64));
        prev = prev.substring(64);
      }
    }

    // ── Meta Output (old → new) ──
    if (log.old_value != null || log.new_value != null) {
      lines.push('');
      lines.push(section('Meta Output'));
      if (log.old_value != null) {
        var oldVal = String(log.old_value || '(empty)');
        lines.push(row('📤', 'Old:', oldVal));
        if (oldVal !== '(empty)') lines.push(row('📐', 'Old Length:', oldVal.length + ' chars'));
      }
      if (log.new_value != null) {
        var newVal = String(log.new_value || '(empty)');
        lines.push(row('📥', 'New:', ''));
        while (newVal.length > 0) {
          lines.push('     ' + newVal.substring(0, 64));
          newVal = newVal.substring(64);
        }
        newVal = String(log.new_value || '');
        if (newVal) lines.push(row('📐', 'New Length:', newVal.length + ' chars'));
      }
    }

    // Tagline-specific
    if (data.all_taglines && data.all_taglines.length > 1) {
      lines.push('');
      lines.push('  📋 Generated ' + data.all_taglines.length + ' options:');
      data.all_taglines.forEach(function(t, i) {
        var marker = i === 0 ? '★' : '•';
        lines.push('     ' + marker + ' "' + t + '" (' + t.length + ' chars)');
      });
    }

    lines.push(SEP);
    return lines.join('\n');
  }

  /* ── Format: skipped ─────────────────────────────────────────────── */

  function formatSkipped(postId, data, opts) {
    opts = opts || {};
    return '\n  ⏭️  [' + (opts.index || '?') + '/' + (opts.total || '?') + '] Post #' + postId + ' — SKIPPED (' + (data.reason || 'already filled') + ')';
  }

  /* ── Format: error ───────────────────────────────────────────────── */

  function formatError(postId, data, opts) {
    opts = opts || {};
    var lines = [];
    lines.push('');
    lines.push('  ❌ [' + (opts.index || '?') + '/' + (opts.total || '?') + '] Post #' + postId + ' — ERROR');
    lines.push('     Message: ' + (data.message || data.error || 'Unknown error'));
    if (data.debug) {
      lines.push('     Debug: ' + JSON.stringify(data.debug));
    }
    return lines.join('\n');
  }

  /* ── Format: batch start ─────────────────────────────────────────── */

  function batchStart(handler, count, config) {
    config = config || {};
    var lines = [];
    lines.push('╔════════════════════════════════════════════════════════════════╗');
    lines.push('║  MYLS AI Generation — ' + (handler || 'Batch Run'));
    lines.push('║  ' + new Date().toLocaleString());
    lines.push('╠════════════════════════════════════════════════════════════════╣');
    lines.push('║  Posts:        ' + count);
    if (config.model)       lines.push('║  Model:        ' + config.model);
    if (config.temperature) lines.push('║  Temperature:  ' + config.temperature);
    if (config.tokens)      lines.push('║  Max Tokens:   ' + config.tokens);
    if (config.postType)    lines.push('║  Post Type:    ' + config.postType);
    lines.push('╚════════════════════════════════════════════════════════════════╝');
    return lines.join('\n');
  }

  /* ── Format: batch summary ───────────────────────────────────────── */

  function batchSummary(stats) {
    var lines = [];
    lines.push('');
    lines.push('╔════════════════════════════════════════════════════════════════╗');
    lines.push('║  BATCH COMPLETE');
    lines.push('╠════════════════════════════════════════════════════════════════╣');

    if (stats.saved != null)   lines.push('║  ✅ Saved:         ' + stats.saved);
    if (stats.skipped != null) lines.push('║  ⏭️  Skipped:       ' + stats.skipped);
    if (stats.errors != null)  lines.push('║  ❌ Errors:        ' + stats.errors);
    if (stats.rewrites != null && stats.rewrites > 0) lines.push('║  🔄 Rewrites:      ' + stats.rewrites);

    lines.push('║');

    // Timing
    if (stats.total_ms != null) lines.push('║  ⏱️  Total Time:    ' + fmtTime(stats.total_ms));
    if (stats.avg_ms != null)   lines.push('║  ⏱️  Avg/Post:      ' + fmtTime(stats.avg_ms));
    if (stats.min_ms != null)   lines.push('║  ⚡ Fastest:       ' + fmtTime(stats.min_ms));
    if (stats.max_ms != null)   lines.push('║  🐢 Slowest:       ' + fmtTime(stats.max_ms));

    // Content quality
    if (stats.avg_words != null) {
      lines.push('║');
      lines.push('║  📊 Content Quality Averages:');
      lines.push('║     Words/post:     ' + stats.avg_words.toFixed(0));
      if (stats.avg_sentences != null) lines.push('║     Sentences/post:  ' + stats.avg_sentences.toFixed(0));
      if (stats.avg_readability != null) {
        var grade = stats.avg_readability;
        var level = grade <= 6 ? 'Easy' : grade <= 10 ? 'Standard' : grade <= 14 ? 'Advanced' : 'Complex';
        lines.push('║     Readability:     ' + grade.toFixed(1) + ' (' + level + ')');
      }
      if (stats.stock_opener_count != null) {
        lines.push('║     Stock Openers:   ' + stats.stock_opener_count + '/' + (stats.saved || 0) + (stats.stock_opener_count > 0 ? ' ⚠' : ' ✓'));
      }
    }

    // Angle distribution
    if (stats.angles && Object.keys(stats.angles).length > 0) {
      lines.push('║');
      lines.push('║  🎯 Angle Distribution:');
      Object.keys(stats.angles).forEach(function(angle) {
        lines.push('║     ' + angle + ': ' + stats.angles[angle] + 'x');
      });
    }

    // Cost
    if (stats.total_cost != null) {
      lines.push('║');
      lines.push('║  💰 Total Est. Cost: ' + fmtCost(stats.total_cost));
    }

    lines.push('╚════════════════════════════════════════════════════════════════╝');
    return lines.join('\n');
  }

  /* ── Batch stats tracker ─────────────────────────────────────────── */

  function createTracker() {
    var items = [];
    return {
      track: function(data) {
        var log = data.log || {};
        var q   = log.quality || {};
        var c   = log.cost || {};
        items.push({
          elapsed_ms:    log.elapsed_ms || 0,
          words:         q.words || log.output_words || 0,
          sentences:     q.sentences || 0,
          readability:   q.readability_grade || 0,
          stock_opener:  (q.opening_match && q.opening_match !== '(none)') ? 1 : 0,
          angle:         log.angle || null,
          dedup_rewrite: log.dedup_rewrite || false,
          cost:          c.est_cost_usd || 0,
        });
      },
      getSummary: function(stats) {
        stats = stats || {};
        if (items.length === 0) return stats;

        var times = items.map(function(i){ return i.elapsed_ms; }).filter(function(t){ return t > 0; });
        var totalMs = times.reduce(function(a, b){ return a + b; }, 0);
        stats.total_ms = totalMs;
        if (times.length > 0) {
          stats.avg_ms = Math.round(totalMs / times.length);
          stats.min_ms = Math.min.apply(null, times);
          stats.max_ms = Math.max.apply(null, times);
        }

        var wordList = items.map(function(i){ return i.words; }).filter(function(w){ return w > 0; });
        if (wordList.length > 0) {
          stats.avg_words = wordList.reduce(function(a, b){ return a + b; }, 0) / wordList.length;
        }
        var sentList = items.map(function(i){ return i.sentences; }).filter(function(s){ return s > 0; });
        if (sentList.length > 0) {
          stats.avg_sentences = sentList.reduce(function(a, b){ return a + b; }, 0) / sentList.length;
        }
        var readList = items.map(function(i){ return i.readability; }).filter(function(r){ return r > 0; });
        if (readList.length > 0) {
          stats.avg_readability = readList.reduce(function(a, b){ return a + b; }, 0) / readList.length;
        }

        stats.stock_opener_count = items.reduce(function(a, i){ return a + i.stock_opener; }, 0);
        stats.rewrites = items.reduce(function(a, i){ return a + (i.dedup_rewrite ? 1 : 0); }, 0);

        var angles = {};
        items.forEach(function(i){
          if (i.angle) angles[i.angle] = (angles[i.angle] || 0) + 1;
        });
        if (Object.keys(angles).length > 0) stats.angles = angles;

        var totalCost = items.reduce(function(a, i){ return a + i.cost; }, 0);
        if (totalCost > 0) stats.total_cost = totalCost;

        return stats;
      }
    };
  }

  /* ── Append / Clear ──────────────────────────────────────────────── */

  function append(text, selector) {
    var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
    if (!el) return;
    var current = el.textContent || '';
    if (current === 'Ready.') {
      el.textContent = text + '\n';
    } else {
      el.textContent += text + '\n';
    }
    el.scrollTop = el.scrollHeight;
  }

  function clear(selector, text) {
    var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
    if (!el) return;
    el.textContent = text || '';
    el.scrollTop = 0;
  }

  return {
    formatEntry:    formatEntry,
    formatSkipped:  formatSkipped,
    formatError:    formatError,
    batchStart:     batchStart,
    batchSummary:   batchSummary,
    createTracker:  createTracker,
    append:         append,
    clear:          clear,
    SEP:            SEP,
    LINE:           LINE
  };

})();
