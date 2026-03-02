<?php
/**
 * AIntelligize – AI AJAX: FAQs
 * File: inc/ajax/ai-faqs.php
 *
 * Endpoints:
 *  - wp_ajax_myls_ai_faqs_get_posts_v1
 *  - wp_ajax_myls_ai_faqs_generate_v1
 *  - wp_ajax_myls_ai_faqs_insert_acf_v1
 *  - wp_ajax_myls_ai_faqs_delete_auto_acf_v1
 *
 * Generates conversion-focused FAQ HTML for selected posts, using:
 *  - on-site rendered permalink text (primary)
 *  - reputable public sources (generic service facts), optional external links
 *
 * Also produces downloadable:
 *  - .html (sanitized HTML)
 *  - .docx (Word headings/bullets – created from HTML)
 */

if ( ! defined('ABSPATH') ) exit;

/* -------------------------------------------------------------------------
 * Nonce helper (shared name with other AI endpoints)
 * ------------------------------------------------------------------------- */
if ( ! function_exists('myls_ai_check_nonce') ) {
  function myls_ai_check_nonce( string $action = 'myls_ai_ops' ) : void {
    $nonce = isset($_POST['nonce']) ? (string) $_POST['nonce'] : (string) ( $_REQUEST['_ajax_nonce'] ?? '' );
    if ( ! wp_verify_nonce( $nonce, $action ) ) {
      wp_send_json_error(['status'=>'error','message'=>'bad_nonce'], 403);
    }
  }
}

/* -------------------------------------------------------------------------
 * AI provider wrapper (prefer existing plugin plumbing if present)
 * ------------------------------------------------------------------------- */
if ( ! function_exists('myls_ai_generate_text') ) {
  function myls_ai_generate_text( string $prompt, array $opts = [] ) : string {

    // IMPORTANT:
    // Do NOT call myls_openai_complete() directly here.
    // myls_openai_complete() is a FILTER callback with signature ( $out, $args ).
    // Calling it directly with ($prompt, $opts) returns the prompt unchanged and skips the API call.
    // Always route through the filter so the correct OpenAI plumbing executes.

    // Filter-based fallback (allows you to swap providers)
    $out = apply_filters('myls_ai_complete', '', array_merge([
      'prompt'      => $prompt,
      'model'       => $opts['model']       ?? null,
      'max_tokens'  => $opts['max_tokens']  ?? null,
      'temperature' => $opts['temperature'] ?? null,
      'context'     => $opts['context']     ?? null,
      'post_id'     => $opts['post_id']     ?? null,
    ], $opts));

    if ( is_string($out) && trim($out) !== '' ) return $out;

    // Legacy / secondary filter
    $out2 = apply_filters('myls_ai_generate_text', '', $prompt);
    if ( is_string($out2) && trim($out2) !== '' ) return $out2;

    return '';
  }
}

/* -------------------------------------------------------------------------
 * Fetch permalink -> extract readable text
 * ------------------------------------------------------------------------- */
if ( ! function_exists('myls_ai_fetch_permalink_text') ) {
  function myls_ai_fetch_permalink_text( string $url ) : string {
    $url = trim($url);
    if ($url === '') return '';

    $resp = wp_remote_get($url, [
      'timeout' => 20,
      'headers' => [
        'User-Agent' => 'MYLS-AI-FAQS/1.0; ' . home_url('/'),
      ],
    ]);

    if ( is_wp_error($resp) ) return '';
    $code = (int) wp_remote_retrieve_response_code($resp);
    if ( $code < 200 || $code >= 300 ) return '';

    $html = (string) wp_remote_retrieve_body($resp);
    if ( $html === '' ) return '';

    // ── Phase 1: Strip non-content elements BEFORE extracting main ──
    $html = preg_replace('#<script[^>]*>.*?</script>#si', ' ', $html);
    $html = preg_replace('#<style[^>]*>.*?</style>#si', ' ', $html);
    $html = preg_replace('#<!--.*?-->#s', ' ', $html);
    // Strip nav, header, footer, sidebar, and form elements (noisy for AI)
    $html = preg_replace('#<nav[^>]*>.*?</nav>#si', ' ', $html);
    $html = preg_replace('#<header[^>]*>.*?</header>#si', ' ', $html);
    $html = preg_replace('#<footer[^>]*>.*?</footer>#si', ' ', $html);
    $html = preg_replace('#<aside[^>]*>.*?</aside>#si', ' ', $html);
    $html = preg_replace('#<form[^>]*>.*?</form>#si', ' ', $html);
    // Strip select/option elements (schema dropdowns, filters, etc.)
    $html = preg_replace('#<select[^>]*>.*?</select>#si', ' ', $html);
    // Strip noscript
    $html = preg_replace('#<noscript[^>]*>.*?</noscript>#si', ' ', $html);
    // Strip SVG and canvas
    $html = preg_replace('#<svg[^>]*>.*?</svg>#si', ' ', $html);
    $html = preg_replace('#<canvas[^>]*>.*?</canvas>#si', ' ', $html);
    // Strip hidden elements
    $html = preg_replace('#<[^>]+(?:display\s*:\s*none|visibility\s*:\s*hidden)[^>]*>.*?</[^>]+>#si', ' ', $html);

    // ── Phase 2: Extract main content area ──
    $main = '';
    if ( preg_match('#<main[^>]*>(.*)</main>#si', $html, $m) ) {
      $main = (string) $m[1];
    } elseif ( preg_match('#<article[^>]*>(.*)</article>#si', $html, $m) ) {
      $main = (string) $m[1];
    } elseif ( preg_match('#<div[^>]*(?:id|class)\s*=\s*["\'][^"\']*(?:content|entry|post-body|main)[^"\']*["\'][^>]*>(.*)</div>#si', $html, $m) ) {
      $main = (string) $m[1];
    } else {
      // Fallback: strip <head> and use body
      $main = preg_replace('#<head[^>]*>.*?</head>#si', '', $html);
    }

    // ── Phase 3: Insert spaces at tag boundaries BEFORE stripping ──
    // This is CRITICAL — prevents <li>word1</li><li>word2</li> → "word1word2"
    // Insert space BEFORE every opening tag
    $main = preg_replace('#<(?!/)#', ' <', $main);
    // Insert space AFTER every closing tag
    $main = preg_replace('#(</[^>]+>)#', '$1 ', $main);
    // Ensure <br> produces space
    $main = preg_replace('#<br\s*/?\s*>#i', ' ', $main);

    // ── Phase 4: Strip tags and normalize ──
    $text = wp_strip_all_tags($main);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
    // Normalize all whitespace (including non-breaking spaces) to single spaces
    $text = str_replace(["\xC2\xA0", '&nbsp;'], ' ', $text);
    $text = preg_replace('/\s+/u', ' ', trim($text));

    // ── Phase 5: Cap length to keep prompts under token limits ──
    // The FAQ prompt template is ~2000 tokens; 12k chars ≈ 3000 tokens of page text
    // leaves plenty of headroom for the model's output.
    if ( strlen($text) > 12000 ) $text = substr($text, 0, 12000);

    return (string) $text;
  }
}

/* -------------------------------------------------------------------------
 * Output cleanup
 * ------------------------------------------------------------------------- */
if ( ! function_exists('myls_ai_strip_code_fences') ) {
  function myls_ai_strip_code_fences( string $s ) : string {
    $s = preg_replace('/^\s*```[a-zA-Z0-9_-]*\s*\n/u', '', $s);
    $s = preg_replace('/\n?\s*```\s*$/u', '', $s);
    return str_replace("```", "", (string)$s);
  }
}

/* -------------------------------------------------------------------------
 * Markdown → HTML fallback converter (NEW)
 *
 * If the AI returns markdown despite being told to produce HTML, detect it
 * and convert the most common markdown patterns to the allowed HTML tags.
 * This prevents wp_kses() from stripping everything and leaving garbage.
 * ------------------------------------------------------------------------- */
if ( ! function_exists('myls_ai_detect_markdown') ) {
  /**
   * Returns true if the string looks like it contains markdown rather than HTML.
   */
  function myls_ai_detect_markdown( string $s ) : bool {
    $s = trim($s);
    if ( $s === '' ) return false;

    // Count markdown indicators vs HTML indicators
    $md_score  = 0;
    $html_score = 0;

    // Markdown headings: ## or ###
    if ( preg_match_all('/^#{1,6}\s+/m', $s) > 0 ) $md_score += 3;
    // Markdown bold: **text**
    if ( preg_match_all('/\*\*[^*]+\*\*/', $s) > 0 ) $md_score += 2;
    // Markdown italic: *text* (not **)
    if ( preg_match_all('/(?<!\*)\*(?!\*)[^*]+\*(?!\*)/', $s) > 0 ) $md_score += 1;
    // Markdown list items: - item or * item at start of line
    if ( preg_match_all('/^[\-\*]\s+/m', $s) > 1 ) $md_score += 2;
    // Markdown numbered lists: 1. item
    if ( preg_match_all('/^\d+\.\s+/m', $s) > 1 ) $md_score += 2;

    // HTML tags
    if ( preg_match_all('/<(h[1-6]|p|ul|ol|li|strong|em)\b/i', $s) > 0 ) $html_score += 3;
    if ( preg_match_all('/<\/(h[1-6]|p|ul|ol|li)>/i', $s) > 0 ) $html_score += 2;

    return ( $md_score > $html_score );
  }
}

if ( ! function_exists('myls_ai_markdown_to_html') ) {
  /**
   * Convert common markdown patterns to HTML.
   * Handles: headings, bold, italic, unordered lists, ordered lists, paragraphs.
   */
  function myls_ai_markdown_to_html( string $md ) : string {
    $md = trim($md);
    if ( $md === '' ) return '';

    // Normalize line endings
    $md = str_replace(["\r\n", "\r"], "\n", $md);

    $lines  = explode("\n", $md);
    $html   = '';
    $in_ul  = false;
    $in_ol  = false;

    foreach ( $lines as $line ) {
      $trimmed = trim($line);

      // Skip empty lines (close any open list)
      if ( $trimmed === '' ) {
        if ( $in_ul ) { $html .= "</ul>\n"; $in_ul = false; }
        if ( $in_ol ) { $html .= "</ol>\n"; $in_ol = false; }
        continue;
      }

      // Headings: ## Heading → <h2>Heading</h2>
      if ( preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $m) ) {
        if ( $in_ul ) { $html .= "</ul>\n"; $in_ul = false; }
        if ( $in_ol ) { $html .= "</ol>\n"; $in_ol = false; }
        $level = strlen($m[1]);
        $text  = myls_ai_md_inline($m[2]);
        $html .= "<h{$level}>{$text}</h{$level}>\n";
        continue;
      }

      // Unordered list: - item or * item
      if ( preg_match('/^[\-\*]\s+(.+)$/', $trimmed, $m) ) {
        if ( $in_ol ) { $html .= "</ol>\n"; $in_ol = false; }
        if ( ! $in_ul ) { $html .= "<ul>\n"; $in_ul = true; }
        $html .= "<li>" . myls_ai_md_inline($m[1]) . "</li>\n";
        continue;
      }

      // Ordered list: 1. item
      if ( preg_match('/^\d+\.\s+(.+)$/', $trimmed, $m) ) {
        if ( $in_ul ) { $html .= "</ul>\n"; $in_ul = false; }
        if ( ! $in_ol ) { $html .= "<ol>\n"; $in_ol = true; }
        $html .= "<li>" . myls_ai_md_inline($m[1]) . "</li>\n";
        continue;
      }

      // Regular paragraph
      if ( $in_ul ) { $html .= "</ul>\n"; $in_ul = false; }
      if ( $in_ol ) { $html .= "</ol>\n"; $in_ol = false; }
      $html .= "<p>" . myls_ai_md_inline($trimmed) . "</p>\n";
    }

    // Close any still-open list
    if ( $in_ul ) $html .= "</ul>\n";
    if ( $in_ol ) $html .= "</ol>\n";

    return $html;
  }
}

if ( ! function_exists('myls_ai_md_inline') ) {
  /**
   * Convert inline markdown: **bold**, *italic*, [text](url)
   */
  function myls_ai_md_inline( string $s ) : string {
    // Bold: **text**
    $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
    // Italic: *text*
    $s = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<em>$1</em>', $s);
    // Links: [text](url)
    $s = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $s);
    return $s;
  }
}

/* -------------------------------------------------------------------------
 * Shared FAQ HTML sanitizer
 *
 * Cleans up common AI-generated HTML malformations. Used by both the
 * main generation handler and the fill pass to ensure consistent cleanup.
 *
 * @since 6.3.2.5
 * ------------------------------------------------------------------------- */
if ( ! function_exists('myls_ai_faqs_sanitize_raw_html') ) {
  function myls_ai_faqs_sanitize_raw_html( string $raw, bool $allow_links = true ) : string {
    // ── Fix malformed HTML tags with spaces ──

    // "< strong >" → "<strong>", "< /li >" → "</li>"
    $raw = preg_replace('#<\s+(/?\s*[a-z][a-z0-9]*)\s*>#i', '<$1>', $raw);
    // "< strong>" or "<strong >" with optional attributes
    $raw = preg_replace('#<\s+(/?\s*[a-z][a-z0-9]*)(\s+[^>]*)?\s*>#i', '<$1$2>', $raw);

    // "< / p >" or "</  p>" → "</p>"
    $raw = preg_replace('#<\s*/\s*([a-z][a-z0-9]*)\s*>#i', '</$1>', $raw);

    // "< h 3 >" → "<h3>", "< / h 3 >" → "</h3>" (space in tag name before digit)
    $raw = preg_replace_callback('#<\s*(/?)\s*([a-z])\s+(\d)\s*>#i', function($m) {
      return '<' . $m[1] . $m[2] . $m[3] . '>';
    }, $raw);

    // 'href = "url"' → 'href="url"' (spaces around = in attributes)
    $raw = preg_replace('#(\w+)\s*=\s*"#', '$1="', $raw);

    // Broken closing tags missing <: ". / p >" or ". /p>" after punctuation
    $raw = preg_replace('#([.!?;])\s*/\s*([a-z][a-z0-9]*)\s*>#i', '$1</$2>', $raw);

    // ── Fix malformed closing tags with wrong bracket ──
    // "</a]" "</a)" "</a}" → "</a>"
    $raw = preg_replace('#</([a-z][a-z0-9]*)\s*[\]\)\}]#i', '</$1>', $raw);
    // "<a href="url"]" → "<a href="url">"
    $raw = preg_replace('#(<[a-z][a-z0-9]*(?:\s+[^>]*?)?)[\]\)\}]\s*#i', '$1>', $raw);

    // ── Fix leaked HTML tag names as text ──
    // "Answer : strong >" → "<strong>Answer:</strong> "
    $raw = preg_replace('/\bAnswer\s*:\s*strong\s*(?:>|&gt;)\s*/i', '<strong>Answer:</strong> ', $raw);
    // Generic bare tag names followed by > as text
    $raw = preg_replace('/(?<![<\/])\b(strong|em)\s*(?:>|&gt;)\s*/i', '', $raw);

    // ── Remove unwanted attributes ──
    $raw = preg_replace('/\s+(style|class|data-[a-z0-9_-]+)="[^"]*"/i', '', $raw);

    // ── Link handling ──
    if ( ! $allow_links ) {
      $raw = preg_replace('#</?a\b[^>]*>#i', '', $raw);
    } else {
      // Ensure rel noopener on any external links
      $raw = preg_replace_callback('#<a\b([^>]*)>#i', function($m){
        $tag = $m[0];
        if ( stripos($tag, 'rel=') === false ) {
          $tag = rtrim(substr($tag,0,-1)) . ' rel="noopener">';
        }
        return $tag;
      }, $raw);
    }

    return $raw;
  }
}

/* -------------------------------------------------------------------------
 * Output validation: check if generated HTML has a reasonable FAQ structure
 * ------------------------------------------------------------------------- */
if ( ! function_exists('myls_ai_faqs_validate_output') ) {
  /**
   * Validate FAQ HTML output quality.
   * Returns an array: ['valid' => bool, 'faq_count' => int, 'reason' => string]
   */
  function myls_ai_faqs_validate_output( string $html ) : array {
    $html = trim($html);
    if ( $html === '' ) return ['valid' => false, 'faq_count' => 0, 'reason' => 'empty_output'];

    // Count question headings (raw)
    $raw_h3_count = (int) preg_match_all('/<h3\b/i', $html);
    if ( $raw_h3_count < 1 ) return ['valid' => false, 'faq_count' => 0, 'reason' => 'no_h3_questions'];

    // Must have at least one paragraph
    $p_count = (int) preg_match_all('/<p\b/i', $html);
    if ( $p_count < 1 ) return ['valid' => false, 'faq_count' => $raw_h3_count, 'reason' => 'no_paragraphs'];

    // Check for garbled text: words longer than 60 chars without spaces.
    // NOTE: This is now a WARNING only — we log it but let processing continue.
    // The per-FAQ validator in extract_pairs() catches garbled text per-FAQ
    // and drops only the bad ones, preserving good FAQs for the fill pass.
    $text_for_garble = preg_replace('#><#', '> <', $html);        // space at every tag boundary
    $text_for_garble = wp_strip_all_tags($text_for_garble);
    $text_for_garble = preg_replace('#https?://\S+#', '', $text_for_garble);  // strip URLs
    $text_for_garble = preg_replace('#[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}#', '', $text_for_garble); // strip emails
    $has_garbled = false;
    if ( preg_match('/[^\s]{60,}/', $text_for_garble, $garble_match) ) {
      $offending = mb_substr($garble_match[0], 0, 80);
      error_log("[MYLS FAQ] Global garbled text detected (will filter per-FAQ): \"{$offending}\"");
      $has_garbled = true;
    }

    // Extract pairs with per-FAQ validation (drops code/errors/garbled individual FAQs)
    $clean_pairs = myls_ai_faqs_extract_pairs($html);
    $faq_count   = count($clean_pairs);

    // Minimum FAQ count for a "good" result
    // 3 is the floor — below this the content isn't useful enough to save.
    // The fill pass will try to bring it up to the variant target (8 or 10).
    if ( $faq_count < 3 ) {
      $dropped = $raw_h3_count - $faq_count;
      $reason  = ($dropped > 0)
        ? "too_few_clean_faqs (raw: {$raw_h3_count}, clean: {$faq_count}, dropped: {$dropped})"
        : 'too_few_faqs';
      return ['valid' => false, 'faq_count' => $faq_count, 'reason' => $reason];
    }

    return ['valid' => true, 'faq_count' => $faq_count, 'reason' => 'ok'];
  }
}

/* -------------------------------------------------------------------------
 * Per-FAQ quality validator
 *
 * Checks an individual question/answer pair for bad content:
 *   - Code / error messages (PHP errors, stack traces, function calls)
 *   - Garbled / concatenated text
 *   - Too-short answers (< 15 words)
 *   - Raw HTML/markdown leaking into plain text
 *   - JSON / data structures
 *   - Repetitive filler content
 *
 * Returns: ['valid' => bool, 'reason' => string]
 * ------------------------------------------------------------------------- */
if ( ! function_exists('myls_ai_faq_validate_pair') ) {
  function myls_ai_faq_validate_pair( string $question, string $answer, string $answer_html = '' ) : array {
    $question = trim($question);
    $answer   = trim($answer);

    // ── Basic checks ──
    if ( $question === '' )                       return ['valid' => false, 'reason' => 'empty_question'];
    if ( $answer === '' )                         return ['valid' => false, 'reason' => 'empty_answer'];
    if ( str_word_count($answer) < 15 )           return ['valid' => false, 'reason' => 'answer_too_short'];
    if ( str_word_count($question) < 3 )          return ['valid' => false, 'reason' => 'question_too_short'];

    // ── Code / error detection ──
    // Check both the plain text answer AND the raw HTML for code/error patterns
    $check_text = $answer . ' ' . $answer_html;

    // PHP / server errors
    if ( preg_match('/\b(Fatal error|Parse error|Warning|Notice|Deprecated|Uncaught|Exception|Stack trace|Traceback|Syntax error|undefined variable|undefined index)\s*:/i', $check_text) ) {
      return ['valid' => false, 'reason' => 'error_message'];
    }

    // WordPress-specific errors
    if ( preg_match('/\b(wp_die|WP_Error|is_wp_error|do_action|apply_filters|add_filter|add_action|wp_remote_|wp_send_json|get_option|update_option)\s*\(/i', $check_text) ) {
      return ['valid' => false, 'reason' => 'wp_code'];
    }

    // File paths (Unix or Windows)
    if ( preg_match('#(/var/www/|/home/|/usr/|C:\\\\|wp-content/|\.php\s+on\s+line|\.php:\d)#i', $check_text) ) {
      return ['valid' => false, 'reason' => 'file_path'];
    }

    // Programming constructs — function calls, variable names, code syntax
    if ( preg_match('/\b(function\s*\(|=>|console\.log|var\s+\w|const\s+\w|let\s+\w|\$\w+\s*=|foreach|array\(|new\s+\w+\(|try\s*\{|catch\s*\(|throw\s+new|import\s+\{|require\s*\(|include\s*\(|echo\s+[\'"]|print_r\s*\()\b/', $check_text) ) {
      return ['valid' => false, 'reason' => 'code_syntax'];
    }

    // SQL
    if ( preg_match('/\b(SELECT\s+\w+\s+FROM|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|CREATE\s+TABLE)\b/i', $check_text) ) {
      return ['valid' => false, 'reason' => 'sql_code'];
    }

    // JSON / API response structures
    if ( preg_match('/\{\s*"[^"]+"\s*:\s*["\[\{]/', $check_text) ) {
      return ['valid' => false, 'reason' => 'json_data'];
    }
    if ( preg_match('/\b(status|error|message|response)\s*[=:]\s*["\{]/i', $check_text) && preg_match('/[{}]/', $check_text) ) {
      return ['valid' => false, 'reason' => 'api_response'];
    }

    // Code fences / backtick blocks that survived cleanup
    if ( strpos($check_text, '```') !== false || preg_match('/`[^`]{10,}`/', $check_text) ) {
      return ['valid' => false, 'reason' => 'code_fence'];
    }

    // XML / data structure artifacts
    if ( preg_match('/<\?(?:php|xml)\b/i', $check_text) ) {
      return ['valid' => false, 'reason' => 'xml_code'];
    }

    // Prompt echo-back — AI returning its own instructions
    if ( preg_match('/\b(CRITICAL OUTPUT RULES|Return clean|No markdown|NEVER use markdown|allowed tags|OUTPUT FORMAT)\b/i', $check_text) ) {
      return ['valid' => false, 'reason' => 'prompt_echo'];
    }

    // Markdown heading/list syntax that survived conversion
    if ( preg_match('/^#{1,4}\s+\w/m', $answer) || preg_match('/^\*{1,2}\s+\w/m', $answer) ) {
      return ['valid' => false, 'reason' => 'markdown_syntax'];
    }

    // ── Garbled text ──
    // Words over 40 chars without spaces (URLs are OK, so exclude http)
    $no_urls = preg_replace('#https?://\S+#', '', $answer);
    if ( preg_match('/[^\s]{40,}/', $no_urls) ) {
      return ['valid' => false, 'reason' => 'garbled_text'];
    }

    // ── Repeated content ──
    // Same 8+ word phrase appearing 3+ times in the answer
    $words = preg_split('/\s+/', strtolower($answer));
    if ( count($words) >= 24 ) {
      $chunks = [];
      for ($i = 0; $i <= count($words) - 8; $i++) {
        $chunk = implode(' ', array_slice($words, $i, 8));
        $chunks[$chunk] = ($chunks[$chunk] ?? 0) + 1;
        if ($chunks[$chunk] >= 3) {
          return ['valid' => false, 'reason' => 'repetitive_content'];
        }
      }
    }

    // ── HTML leaking into question text ──
    if ( preg_match('/<[a-z]+[\s>]/i', $question) ) {
      return ['valid' => false, 'reason' => 'html_in_question'];
    }

    // ── Leaked HTML tags / raw markup as plain text ──
    // Catches AI output where HTML tags appear as visible text in the answer.
    // This happens when the AI outputs malformed tags with spaces (< h3 >, < / p >)
    // that survive cleanup, or when tags lose their angle brackets entirely.

    // Pattern 1: Tag names followed by > as text (e.g. "p>" "h3>" "strong>" "em>")
    // Exclude common English words "a" by itself — require either a slash prefix or a compound tag name
    if ( preg_match('/(?:\/\s*(?:p|a|ul|ol|li|em|strong|h[1-6])\s*(?:>|&gt;)|\b(?:strong|em|div|span|h[1-6])\s*(?:>|&gt;))/i', $answer) ) {
      return ['valid' => false, 'reason' => 'leaked_html_tag'];
    }

    // Pattern 2: Raw HTML attributes as text (e.g. 'a href =' or 'href="https://')
    if ( preg_match('/\bhref\s*=\s*["\']?https?:/i', $answer) ) {
      return ['valid' => false, 'reason' => 'leaked_html_attr'];
    }

    // Pattern 3: Multiple consecutive tag fragments (e.g. ". p> h 3 >" or "; a > . p>")
    // Two or more tag-like fragments in close proximity = definitely broken HTML
    if ( preg_match('/(?:[a-z][a-z0-9]*\s*(?:>|&gt;)\s*){2}/i', $answer) ) {
      return ['valid' => false, 'reason' => 'leaked_html_tags_multiple'];
    }

    // ── Malformed HTML tags with wrong brackets ──
    // Catches escaped broken tags like "&lt;/a]" or "</a)" visible as text in the answer
    if ( preg_match('#(?:&lt;|<)\s*/[a-z]+\s*[\]\)\}]#i', $answer) ) {
      return ['valid' => false, 'reason' => 'malformed_html_tag'];
    }
    // Also check the raw answer_html for the same pattern
    if ( $answer_html !== '' && preg_match('#(?:&lt;|<)\s*/[a-z]+\s*[\]\)\}]#i', $answer_html) ) {
      return ['valid' => false, 'reason' => 'malformed_html_tag'];
    }

    // ── Raw HTML in answer_html that survived as text ──
    // If the plain-text answer contains tag-like content that shouldn't be visible
    if ( $answer_html !== '' ) {
      // Check if answer_html has tags rendered as text entities (&lt;h3&gt; etc.)
      if ( preg_match('/&lt;\s*\/?[a-z][a-z0-9]*(?:\s[^&]*)?\s*&gt;/i', $answer_html) ) {
        return ['valid' => false, 'reason' => 'escaped_html_in_answer'];
      }
    }

    // ── Keyword soup / incoherent content ──
    // Detects AI output that strings keywords together without proper
    // sentence structure (missing articles, prepositions, verbs).
    // Normal English prose has ~35-55% function words; keyword soup < 15%.
    if ( count($words) >= 40 ) {
      $function_words = ['the','a','an','is','are','was','were','be','been',
        'being','have','has','had','do','does','did','will','would','shall',
        'should','may','might','can','could','must','to','of','in','for',
        'on','with','at','by','from','as','into','through','during','before',
        'after','above','below','between','under','again','further','then',
        'once','and','but','or','nor','not','so','yet','both','either',
        'neither','each','every','all','any','few','more','most','other',
        'some','such','no','only','own','same','than','too','very','that',
        'this','these','those','it','its','he','she','they','we','you',
        'i','me','my','your','his','her','our','their','who','which','what',
        'when','where','how','why','if','because','while','although','since'];
      $func_count = 0;
      foreach ( $words as $w ) {
        if ( in_array($w, $function_words, true) ) $func_count++;
      }
      $func_ratio = $func_count / count($words);
      if ( $func_ratio < 0.15 ) {
        return ['valid' => false, 'reason' => 'keyword_soup'];
      }
    }

    return ['valid' => true, 'reason' => 'ok'];
  }
}

/* =============================================================================
 * AJAX: Insert generated FAQs into ACF repeater
 *
 * Field Group: "FAQ Schema"
 * Repeater (name: faq_items, key: field_67ecf855edf0b)
 * Subfields:
 *  - question (key: field_67ecf892edf0c)
 *  - answer   (key: field_67ecf8abedf0d)
 *
 * We track which rows were auto-inserted via post meta hashes:
 *   _myls_ai_faqs_auto_hashes (array of sha1 hashes)
 * ============================================================================= */

if ( ! function_exists('myls_ai_faqs_acf_field_map') ) {
  function myls_ai_faqs_acf_field_map() : array {

    // FIELD KEYS (best for update_field)
    $repeater_key = apply_filters('myls_ai_faqs_acf_repeater_key', 'field_67ecf855edf0b');
    $question_key = apply_filters('myls_ai_faqs_acf_question_key', 'field_67ecf892edf0c');
    $answer_key   = apply_filters('myls_ai_faqs_acf_answer_key',   'field_67ecf8abedf0d');

    // FIELD NAME (best for get_field; returns rows keyed by subfield names)
    $repeater_name = apply_filters('myls_ai_faqs_acf_repeater_name', 'faq_items');

    // Marker meta key (hash list of auto rows)
    $auto_meta_key = apply_filters('myls_ai_faqs_auto_meta_key', '_myls_ai_faqs_auto_hashes');

    return [
      'repeater_key'  => is_string($repeater_key)  ? $repeater_key  : 'field_67ecf855edf0b',
      'repeater_name' => is_string($repeater_name) ? $repeater_name : 'faq_items',
      'question_key'  => is_string($question_key)  ? $question_key  : 'field_67ecf892edf0c',
      'answer_key'    => is_string($answer_key)    ? $answer_key    : 'field_67ecf8abedf0d',
      'auto_meta_key' => is_string($auto_meta_key) ? $auto_meta_key : '_myls_ai_faqs_auto_hashes',
    ];
  }
}

if ( ! function_exists('myls_ai_faqs_acf_get_rows') ) {
  function myls_ai_faqs_acf_get_rows( int $post_id, string $repeater_name ) : array {
    if ( ! function_exists('get_field') ) return [];
    $rows = get_field($repeater_name, $post_id);
    return is_array($rows) ? $rows : [];
  }
}

if ( ! function_exists('myls_ai_faqs_acf_update_rows') ) {
  /**
   * Update repeater rows using FIELD KEY (recommended for reliability).
   * $rows_keyed must be formatted with SUBFIELD KEYS.
   */
  function myls_ai_faqs_acf_update_rows( int $post_id, string $repeater_key, array $rows_keyed ) : bool {
    if ( ! function_exists('update_field') ) return false;
    return (bool) update_field($repeater_key, $rows_keyed, $post_id);
  }
}

if ( ! function_exists('myls_ai_faqs_hash_row') ) {
  function myls_ai_faqs_hash_row( string $q, string $a ) : string {
    $q = strtolower(trim(preg_replace('/\s+/u',' ', (string)$q)));
    $a = strtolower(trim(preg_replace('/\s+/u',' ', (string)$a)));
    return sha1($q . '|' . $a);
  }
}

/**
 * Extract Q/A pairs from generated HTML.
 * Expected format:
 *   <p><strong>Question:</strong> ... <strong>Answer:</strong> ...</p>
 */
if ( ! function_exists('myls_ai_faqs_extract_pairs') ) {
  function myls_ai_faqs_extract_pairs( string $html ) : array {
    $html = (string) $html;
    if ($html === '') return [];

    $norm = str_replace(["\r\n","\r"], "\n", $html);
    $norm = preg_replace('/\s+/u', ' ', $norm);

    $pairs = [];

    /* --------------------------------------------------------------------
     * NEW FORMAT (v2):
     * <h3>Question</h3>
     * <p>...</p>
     * <ul><li>...</li></ul>
     * ...
     * Stops at next <h3> or <h2>Sources</h2>
     * -------------------------------------------------------------------- */
    if (stripos($norm, '<h3') !== false) {
      $doc = new DOMDocument();
      $prev = libxml_use_internal_errors(true);
      // Wrap in a body so DOMDocument can parse fragments.
      $doc->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>');
      libxml_clear_errors();
      libxml_use_internal_errors($prev);

      $body = $doc->getElementsByTagName('body')->item(0);
      if ($body) {
        $children = [];
        foreach ($body->childNodes as $n) {
          if ($n->nodeType === XML_ELEMENT_NODE) $children[] = $n;
        }

        $count = count($children);
        for ($i = 0; $i < $count; $i++) {
          $node = $children[$i];
          $tag  = strtolower($node->nodeName);

          // Stop when Sources section begins.
          if ($tag === 'h2') {
            $t = trim($node->textContent ?? '');
            if (strcasecmp($t, 'Sources') === 0) break;
          }

          if ($tag !== 'h3') continue;

          $q = trim((string)$node->textContent);
          $q = html_entity_decode($q, ENT_QUOTES | ENT_HTML5, 'UTF-8');
          $q = trim(wp_strip_all_tags($q));
          if ($q === '') continue;

          // Collect answer blocks until the next h3 or the Sources h2.
          $answer_html = '';
          for ($j = $i + 1; $j < $count; $j++) {
            $n2 = $children[$j];
            $t2 = strtolower($n2->nodeName);

            if ($t2 === 'h3') break;
            if ($t2 === 'h2') {
              $tt = trim($n2->textContent ?? '');
              if (strcasecmp($tt, 'Sources') === 0) { $j = $count; break; }
            }

            // Keep only allowed tags (p/ul/li/strong/em/a) in the stored answer.
            if (in_array($t2, ['p','ul','li','strong','em','a'], true)) {
              $answer_html .= $doc->saveHTML($n2);
            }
          }

          // Build a plain text variant for hashing.
          $a_plain = wp_strip_all_tags($answer_html, true);
          $a_plain = html_entity_decode($a_plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
          $a_plain = str_replace(["\xC2\xA0", '&nbsp;'], ' ', (string)$a_plain);
          $a_plain = preg_replace('/\s+/u', ' ', (string)$a_plain);
          $a_plain = trim((string)$a_plain);

          if ($a_plain === '') continue;

          $pairs[] = [
            'question'     => $q,
            'answer'       => $a_plain,
            'answer_html'  => $answer_html,
            'hash'         => myls_ai_faqs_hash_row($q, $a_plain),
          ];
        }
      }
    }

    /* --------------------------------------------------------------------
     * LEGACY FORMAT (v1): One-line or two-line <p><strong>Question...</p>
     * -------------------------------------------------------------------- */
    if (empty($pairs)) {
      // 1) Combined format
      $re_one = '#<p[^>]*>\s*(?:<strong>)?\s*Question\s*:\s*(?:</strong>)?\s*(.*?)\s*(?:<strong>)?\s*Answer\s*:\s*(?:</strong>)?\s*(.*?)\s*</p>#i';
      if (preg_match_all($re_one, $norm, $m1, PREG_SET_ORDER)) {
        foreach ($m1 as $row) {
          $q_raw = (string) ($row[1] ?? '');
          $a_raw = (string) ($row[2] ?? '');
          $q = trim(wp_strip_all_tags(html_entity_decode($q_raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
          $a = trim(wp_strip_all_tags(html_entity_decode($a_raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
          if ($q === '' || $a === '') continue;
          $pairs[] = [
            'question'    => $q,
            'answer'      => $a,
            'answer_html' => '',
            'hash'        => myls_ai_faqs_hash_row($q, $a),
          ];
        }
      }
    }

    // 2) Two-line format fallback
    if (empty($pairs)) {
      $re_q = '#<p[^>]*>\s*(?:<strong>)?\s*(?:Question|Q)\s*:\s*(?:</strong>)?\s*(.*?)\s*</p>#i';
      $re_a = '#<p[^>]*>\s*(?:<strong>)?\s*(?:Answer|A)\s*:\s*(?:</strong>)?\s*(.*?)\s*</p>#i';

      $re_all = '#<p[^>]*>.*?</p>#i';
      if (preg_match_all($re_all, $norm, $blocks)) {
        $pending_q = '';
        foreach ((array) ($blocks[0] ?? []) as $p) {
          if ($pending_q === '' && preg_match($re_q, $p, $mq)) {
            $pending_q = trim(wp_strip_all_tags(html_entity_decode((string) ($mq[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            continue;
          }
          if ($pending_q !== '' && preg_match($re_a, $p, $ma)) {
            $a = trim(wp_strip_all_tags(html_entity_decode((string) ($ma[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($pending_q !== '' && $a !== '') {
              $pairs[] = [
                'question'    => $pending_q,
                'answer'      => $a,
                'answer_html' => '',
                'hash'        => myls_ai_faqs_hash_row($pending_q, $a),
              ];
            }
            $pending_q = '';
          }
        }
      }
    }

    // ── Per-FAQ quality filter ──────────────────────────────────────
    // Validate each Q/A pair and drop bad ones (code, errors, garbled, etc.)
    if ( ! empty($pairs) && function_exists('myls_ai_faq_validate_pair') ) {
      $clean_pairs = [];
      $rejected    = [];
      foreach ($pairs as $pair) {
        $check = myls_ai_faq_validate_pair(
          (string) ($pair['question'] ?? ''),
          (string) ($pair['answer'] ?? ''),
          (string) ($pair['answer_html'] ?? '')
        );
        if ( $check['valid'] ) {
          $clean_pairs[] = $pair;
        } else {
          $rejected[] = [
            'question' => mb_substr((string) ($pair['question'] ?? ''), 0, 80),
            'reason'   => $check['reason'],
          ];
        }
      }

      // Log rejections for debugging
      if ( ! empty($rejected) ) {
        $count_r = count($rejected);
        $reasons = implode(', ', array_column($rejected, 'reason'));
        error_log("[MYLS] FAQ pair validation: dropped {$count_r} bad FAQ(s) — reasons: {$reasons}");
      }

      $pairs = $clean_pairs;
    }

    return $pairs;
  }
}

/* =============================================================================
 * AJAX: Insert generated FAQs into ACF repeater
 * Action: myls_ai_faqs_insert_acf_v1
 * Expects:
 *   - post_id
 *   - html
 *   - replace_existing (optional "1")
 * ============================================================================= */
add_action('wp_ajax_myls_ai_faqs_insert_acf_v1', function(){

  myls_ai_check_nonce();

  $post_id = (int) ($_POST['post_id'] ?? 0);
  if ( $post_id <= 0 || get_post_status($post_id) === false ) {
    wp_send_json_error(['marker'=>'faqs','status'=>'error','message'=>'bad_post'], 400);
  }
  if ( ! current_user_can('edit_post', $post_id) ) {
    wp_send_json_error(['marker'=>'faqs','status'=>'error','message'=>'cap_denied'], 403);
  }
  if ( ! function_exists('update_field') || ! function_exists('get_field') ) {
    wp_send_json_error(['marker'=>'faqs','status'=>'error','message'=>'acf_not_active'], 400);
  }

  $html    = (string) wp_unslash($_POST['html'] ?? '');
  $replace = ! empty($_POST['replace_existing']);

  $pairs = myls_ai_faqs_extract_pairs($html);
  if ( empty($pairs) ) {
    wp_send_json_error(['marker'=>'faqs','status'=>'error','message'=>'missing_faqs_for_acf'], 400);
  }

  $map = myls_ai_faqs_acf_field_map();

  $rep_key    = (string) $map['repeater_key'];   // update_field expects key
  $rep_name   = (string) $map['repeater_name'];  // get_field expects name
  $q_key      = (string) $map['question_key'];
  $a_key      = (string) $map['answer_key'];
  $marker_key = (string) $map['auto_meta_key'];

  // Read existing rows by FIELD NAME (rows keyed by subfield names)
  $existing_by_name = $replace ? [] : myls_ai_faqs_acf_get_rows($post_id, $rep_name);

  // Dedupe hashes from existing rows
  $seen = [];
  foreach ($existing_by_name as $row) {
    if (!is_array($row)) continue;
    $q = isset($row['question']) ? (string) $row['question'] : '';
    $a = isset($row['answer'])   ? (string) $row['answer']   : '';
    if (trim($q) === '' || trim($a) === '') continue;
    $seen[myls_ai_faqs_hash_row($q, $a)] = true;
  }

  // Convert existing rows to KEYED format for update_field(field_key,...)
  $rows_keyed = [];
  foreach ($existing_by_name as $row) {
    if (!is_array($row)) continue;
    $q = isset($row['question']) ? (string) $row['question'] : '';
    $a = isset($row['answer'])   ? (string) $row['answer']   : '';
    if (trim($q) === '' && trim($a) === '') continue;

    $rows_keyed[] = [
      $q_key => sanitize_text_field($q),
      $a_key => wp_kses_post($a),
    ];
  }

  // Marker hashes used for bulk delete
  $auto_hashes = get_post_meta($post_id, $marker_key, true);
  if ( ! is_array($auto_hashes) ) $auto_hashes = [];

  $inserted = 0;
  $skipped  = 0;

  foreach ($pairs as $pair) {
    $q = (string) ($pair['question'] ?? '');
    $a = (string) ($pair['answer'] ?? '');
    $h = (string) ($pair['hash'] ?? '');

    if ($h === '') $h = myls_ai_faqs_hash_row($q, $a);
    if ($h === '' || isset($seen[$h])) { $skipped++; continue; }

    $rows_keyed[] = [
      $q_key => sanitize_text_field($q),
      $a_key => wp_kses_post($a),
    ];

    $seen[$h] = true;
    $auto_hashes[] = $h;
    $inserted++;
  }

  $auto_hashes = array_values(array_unique(array_filter($auto_hashes)));
  update_post_meta($post_id, $marker_key, $auto_hashes);

  $ok = myls_ai_faqs_acf_update_rows($post_id, $rep_key, $rows_keyed);
  if ( ! $ok ) {
    wp_send_json_error(['marker'=>'faqs','status'=>'error','message'=>'acf_update_failed'], 500);
  }

  wp_send_json_success([
    'marker'         => 'faqs',
    'status'         => 'ok',
    'post_id'        => $post_id,
    'repeater_key'   => $rep_key,
    'repeater_name'  => $rep_name,
    'inserted_count' => $inserted,
    'skipped_count'  => $skipped,
    'total_rows'     => count($rows_keyed),
  ]);
});

/* =============================================================================
 * AJAX: Delete auto-generated FAQs from ACF repeater
 * Action: myls_ai_faqs_delete_auto_acf_v1
 * ============================================================================= */
add_action('wp_ajax_myls_ai_faqs_delete_auto_acf_v1', function(){

  myls_ai_check_nonce();

  $post_id = (int) ($_POST['post_id'] ?? 0);
  if ( $post_id <= 0 || get_post_status($post_id) === false ) {
    wp_send_json_error(['marker'=>'faqs','status'=>'error','message'=>'bad_post'], 400);
  }
  if ( ! current_user_can('edit_post', $post_id) ) {
    wp_send_json_error(['marker'=>'faqs','status'=>'error','message'=>'cap_denied'], 403);
  }
  if ( ! function_exists('update_field') || ! function_exists('get_field') ) {
    wp_send_json_error(['marker'=>'faqs','status'=>'error','message'=>'acf_not_active'], 400);
  }

  $map = myls_ai_faqs_acf_field_map();

  $rep_key    = (string) $map['repeater_key'];
  $rep_name   = (string) $map['repeater_name'];
  $q_key      = (string) $map['question_key'];
  $a_key      = (string) $map['answer_key'];
  $marker_key = (string) $map['auto_meta_key'];

  // Read existing rows by FIELD NAME
  $rows_by_name = myls_ai_faqs_acf_get_rows($post_id, $rep_name);

  // Load marker hashes
  $auto_hashes = get_post_meta($post_id, $marker_key, true);
  if ( ! is_array($auto_hashes) ) $auto_hashes = [];
  $auto_hashes = array_flip(array_values(array_unique(array_filter($auto_hashes))));

  $kept_keyed = [];
  $deleted    = 0;

  foreach ($rows_by_name as $row) {
    if ( ! is_array($row) ) continue;

    $q = isset($row['question']) ? (string) $row['question'] : '';
    $a = isset($row['answer'])   ? (string) $row['answer']   : '';

    $h = (trim($q) !== '' || trim($a) !== '') ? myls_ai_faqs_hash_row($q, $a) : '';

    if ($h && isset($auto_hashes[$h])) {
      $deleted++;
      continue;
    }

    $kept_keyed[] = [
      $q_key => sanitize_text_field($q),
      $a_key => wp_kses_post($a),
    ];
  }

  $ok = myls_ai_faqs_acf_update_rows($post_id, $rep_key, $kept_keyed);
  if ( ! $ok ) {
    wp_send_json_error(['marker'=>'faqs','status'=>'error','message'=>'acf_update_failed'], 500);
  }

  // Clear marker meta after delete to avoid stale hashes
  delete_post_meta($post_id, $marker_key);

  wp_send_json_success([
    'marker'        => 'faqs',
    'status'        => 'ok',
    'post_id'       => $post_id,
    'deleted_count' => $deleted,
    'total_rows'    => count($kept_keyed),
  ]);
});

/* -------------------------------------------------------------------------
 * Save downloadable files (.html + .docx)
 * ------------------------------------------------------------------------- */
if ( ! function_exists('myls_ai_faqs_save_html') ) {
  function myls_ai_faqs_save_html( int $post_id, string $title, string $permalink, string $html ) : string {
    $uploads = wp_upload_dir();
    if ( empty($uploads['basedir']) || empty($uploads['baseurl']) ) return '';

    $dir = trailingslashit($uploads['basedir']) . 'myls-ai-faqs/';
    if ( ! file_exists($dir) ) wp_mkdir_p($dir);

    $safe = sanitize_title($title ?: 'faqs');
    $filename = sprintf('%s-%d-%s.html', $safe, $post_id, date('Ymd-His'));
    $path = $dir . $filename;

    $charset = get_bloginfo('charset') ?: 'UTF-8';
    $doc  = "<!doctype html>\n<html>\n<head>\n<meta charset=\"".esc_attr($charset)."\">\n";
    $doc .= "<title>".esc_html($title)."</title>\n</head>\n<body>\n";
    $doc .= "<h1>".esc_html($title)."</h1>\n<p><strong>Permalink:</strong> ".esc_html($permalink)."</p>\n<hr/>\n";
    $doc .= $html . "\n</body>\n</html>";

    file_put_contents($path, $doc);

    return trailingslashit($uploads['baseurl']) . 'myls-ai-faqs/' . $filename;
  }
}

/* ---------------- DOCX (minimal, from HTML) ---------------- */

if ( ! function_exists('myls_ai_faqs_docx_escape') ) {
  function myls_ai_faqs_docx_escape( string $s ) : string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
  }
}

if ( ! function_exists('myls_ai_faqs_docx_p') ) {
  function myls_ai_faqs_docx_p( string $text, string $style = '', bool $bold = false ) : string {
    $t = myls_ai_faqs_docx_escape($text);
    $pPr = '';
    if ($style !== '') {
      $s = myls_ai_faqs_docx_escape($style);
      $pPr = "<w:pPr><w:pStyle w:val=\"{$s}\"/></w:pPr>";
    }
    $rPr = $bold ? '<w:rPr><w:b/></w:rPr>' : '';
    return "<w:p>{$pPr}<w:r>{$rPr}<w:t xml:space=\"preserve\">{$t}</w:t></w:r></w:p>";
  }
}

if ( ! function_exists('myls_ai_faqs_html_to_lines') ) {
  /**
   * Naive HTML->lines conversion suitable for our minimal DOCX:
   * - h2 -> ## heading
   * - h3 -> ### heading
   * - li -> - item
   * - p -> paragraph
   */
  function myls_ai_faqs_html_to_lines( string $html ) : array {
    $html = (string)$html;

    $replacements = [
      '#<h1[^>]*>(.*?)</h1>#si' => "\n# $1\n",
      '#<h2[^>]*>(.*?)</h2>#si' => "\n## $1\n",
      '#<h3[^>]*>(.*?)</h3>#si' => "\n### $1\n",
      '#<li[^>]*>(.*?)</li>#si' => "\n- $1\n",
      '#</p>\s*<#si'            => "</p>\n<",
    ];
    foreach ($replacements as $pat => $rep) {
      $html = preg_replace($pat, $rep, $html);
    }

    $html = preg_replace('#<p[^>]*>(.*?)</p>#si', "\n$1\n", $html);

    $text = wp_strip_all_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
    $text = preg_replace('/[ \t]+/u', ' ', $text);
    $text = preg_replace('/\n{3,}/u', "\n\n", $text);
    $text = trim($text);

    return preg_split("/\r\n|\n|\r/", $text);
  }
}

if ( ! function_exists('myls_ai_faqs_docx_content_types_xml') ) {
  function myls_ai_faqs_docx_content_types_xml() : string {
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>
XML;
  }
}

if ( ! function_exists('myls_ai_faqs_docx_rels_xml') ) {
  function myls_ai_faqs_docx_rels_xml() : string {
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>
XML;
  }
}

if ( ! function_exists('myls_ai_faqs_docx_document_rels_xml') ) {
  function myls_ai_faqs_docx_document_rels_xml() : string {
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>
XML;
  }
}

if ( ! function_exists('myls_ai_faqs_docx_core_xml') ) {
  function myls_ai_faqs_docx_core_xml( string $title ) : string {
    $t = myls_ai_faqs_docx_escape($title ?: 'Document');
    $created = gmdate('Y-m-d\TH:i:s\Z');
    return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"
 xmlns:dc="http://purl.org/dc/elements/1.1/"
 xmlns:dcterms="http://purl.org/dc/terms/"
 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:title>{$t}</dc:title>
  <dc:creator>MYLS</dc:creator>
  <cp:lastModifiedBy>MYLS</cp:lastModifiedBy>
  <dcterms:created xsi:type="dcterms:W3CDTF">{$created}</dcterms:created>
  <dcterms:modified xsi:type="dcterms:W3CDTF">{$created}</dcterms:modified>
</cp:coreProperties>
XML;
  }
}

if ( ! function_exists('myls_ai_faqs_docx_app_xml') ) {
  function myls_ai_faqs_docx_app_xml() : string {
    return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"
 xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>MYLS</Application>
</Properties>
XML;
  }
}

if ( ! function_exists('myls_ai_faqs_docx_document_xml') ) {
  function myls_ai_faqs_docx_document_xml( array $lines ) : string {
    $paras = [];

    foreach ($lines as $line) {
      $line = trim((string)$line);

      if ($line === '') {
        $paras[] = myls_ai_faqs_docx_p('');
        continue;
      }

      if (strpos($line, '### ') === 0) {
        $paras[] = myls_ai_faqs_docx_p(substr($line, 4), 'Heading3');
        continue;
      }
      if (strpos($line, '## ') === 0) {
        $paras[] = myls_ai_faqs_docx_p(substr($line, 3), 'Heading2');
        continue;
      }
      if (strpos($line, '# ') === 0) {
        $paras[] = myls_ai_faqs_docx_p(substr($line, 2), 'Heading1', true);
        continue;
      }
      if (strpos($line, '- ') === 0) {
        $paras[] = myls_ai_faqs_docx_p('• ' . substr($line, 2));
        continue;
      }

      $paras[] = myls_ai_faqs_docx_p($line);
    }

    $body = implode("\n", $paras);

    return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    {$body}
    <w:sectPr>
      <w:pgSz w:w="12240" w:h="15840"/>
      <w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/>
    </w:sectPr>
  </w:body>
</w:document>
XML;
  }
}

if ( ! function_exists('myls_ai_faqs_docx_write') ) {
  function myls_ai_faqs_docx_write( string $filepath, array $lines ) : bool {
    if ( ! class_exists('ZipArchive') ) return false;

    $zip = new ZipArchive();
    if ( $zip->open($filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true ) return false;

    $zip->addFromString('[Content_Types].xml', myls_ai_faqs_docx_content_types_xml());
    $zip->addFromString('_rels/.rels', myls_ai_faqs_docx_rels_xml());
    $zip->addFromString('docProps/core.xml', myls_ai_faqs_docx_core_xml('FAQs'));
    $zip->addFromString('docProps/app.xml', myls_ai_faqs_docx_app_xml());
    $zip->addFromString('word/_rels/document.xml.rels', myls_ai_faqs_docx_document_rels_xml());
    $zip->addFromString('word/document.xml', myls_ai_faqs_docx_document_xml($lines));

    $zip->close();
    return true;
  }
}

if ( ! function_exists('myls_ai_faqs_save_docx') ) {
  function myls_ai_faqs_save_docx( int $post_id, string $title, string $html ) : string {
    if ( ! class_exists('ZipArchive') ) return '';

    $uploads = wp_upload_dir();
    if ( empty($uploads['basedir']) || empty($uploads['baseurl']) ) return '';

    $dir = trailingslashit($uploads['basedir']) . 'myls-ai-faqs/';
    if ( ! file_exists($dir) ) wp_mkdir_p($dir);

    $safe = sanitize_title($title ?: 'faqs');
    $filename = sprintf('%s-%d-%s.docx', $safe, $post_id, date('Ymd-His'));
    $path = $dir . $filename;

    $lines = myls_ai_faqs_html_to_lines($html);
    $ok = myls_ai_faqs_docx_write($path, $lines);
    if ( ! $ok ) return '';

    return trailingslashit($uploads['baseurl']) . 'myls-ai-faqs/' . $filename;
  }
}

/* -------------------------------------------------------------------------
 * Endpoint: Get posts for a post type (includes drafts)
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_myls_ai_faqs_get_posts_v1', function(){
  myls_ai_check_nonce();

  $pt = isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : 'page';
  if ( ! post_type_exists($pt) ) {
    wp_send_json_error(['status'=>'error','message'=>'invalid_post_type'], 400);
  }

  $ptype_obj = get_post_type_object($pt);
  $cap = $ptype_obj && isset($ptype_obj->cap->edit_posts) ? $ptype_obj->cap->edit_posts : 'edit_posts';
  if ( ! current_user_can($cap) ) {
    wp_send_json_error(['status'=>'error','message'=>'cap_denied'], 403);
  }

  $ids = get_posts([
    'post_type'       => $pt,
    'post_status'     => ['publish','draft','pending','future','private'],
    'posts_per_page'  => -1,
    'orderby'         => 'title',
    'order'           => 'ASC',
    'fields'          => 'ids',
    'suppress_filters'=> true,
  ]);

  $out = [];
  foreach ($ids as $pid) {
    $faq_items = get_post_meta($pid, '_myls_faq_items', true);
    $has_faqs  = is_array($faq_items) && count($faq_items) > 0;
    $out[] = ['id'=>(int)$pid, 'title'=> get_the_title($pid), 'has_faqs' => $has_faqs];
  }

  wp_send_json_success(['status'=>'ok','posts'=>$out]);
});

/* -------------------------------------------------------------------------
 * Endpoint: Generate FAQs HTML
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_myls_ai_faqs_generate_v1', function(){
  myls_ai_check_nonce();
  $start_time = microtime(true);
  if ( class_exists('MYLS_Variation_Engine') ) { MYLS_Variation_Engine::reset_log(); }

  $post_id     = (int) ($_POST['post_id'] ?? 0);
  $allow_links = ! empty($_POST['allow_links']);
  $variant     = isset($_POST['variant']) ? strtoupper( sanitize_text_field( wp_unslash($_POST['variant']) ) ) : 'LONG';
  if ( $variant !== 'SHORT' ) $variant = 'LONG';

  if ( $post_id <= 0 || get_post_status($post_id) === false ) {
    wp_send_json_error(['status'=>'error','message'=>'bad_post'], 400);
  }
  if ( ! current_user_can('edit_post', $post_id) ) {
    wp_send_json_error(['status'=>'error','message'=>'cap_denied'], 403);
  }

  $p = get_post($post_id);
  if ( ! $p ) {
    wp_send_json_error(['status'=>'error','message'=>'post_not_found'], 404);
  }

  $title = get_the_title($post_id);
  $url   = get_permalink($post_id);

  // Template: prefer request, then v2 option, then legacy option, then built-in safe default.
  // v2 supports LONG/SHORT variants + multi-block answers with <h3> + bullets.
  $template = isset($_POST['template']) ? wp_unslash((string)$_POST['template']) : '';
  if ( trim($template) === '' ) $template = (string) get_option('myls_ai_faqs_prompt_template_v2', '');
  if ( trim($template) === '' ) $template = (string) get_option('myls_ai_faqs_prompt_template', '');

  if ( trim($template) === '' ) {
    $template = function_exists('myls_get_default_prompt') ? myls_get_default_prompt('faqs-builder') : '';
  }
  if ( trim($template) === '' ) {
    $template = "Create FAQs for {{TITLE}} using {{PAGE_TEXT}}. Output clean HTML: <h2>FAQs</h2> then 10 FAQs with <h3>Question</h3> and multi-paragraph answers plus a <ul> checklist, then <h2>Sources</h2> list.";
  }

  // If the provided template is the old one-line format, auto-upgrade it for LONG output.
  // This prevents 'LONG' from still producing single-line Q/A when old templates are saved.
  $is_legacy_one_line = (stripos($template, 'ONE LINE') !== false) || (stripos($template, '<strong>Question') !== false && stripos($template, '<strong>Answer') !== false);
  if ( $is_legacy_one_line && $variant === 'LONG' ) {
    $maybe_v2 = (string) get_option('myls_ai_faqs_prompt_template_v2', '');
    if ( trim($maybe_v2) !== '' ) {
      $template = $maybe_v2;
    }
  }

  // Permalink-based page text
  $page_text = myls_ai_fetch_permalink_text((string)$url);
  if ( $page_text === '' ) {
    $page_text = function_exists('myls_get_post_plain_text') ? myls_get_post_plain_text( $post_id ) : preg_replace('/\s+/u',' ', trim( wp_strip_all_tags( (string) $p->post_content ) ));
  }

  // Get city/state from post meta (try multiple possible meta keys)
  $city_state = '';
  
  // Try various common meta keys for city
  $city = get_post_meta($post_id, '_myls_city', true);
  if ( ! $city ) $city = get_post_meta($post_id, 'city', true);
  if ( ! $city ) $city = get_post_meta($post_id, '_city', true);
  
  // Try various common meta keys for state
  $state = get_post_meta($post_id, '_myls_state', true);
  if ( ! $state ) $state = get_post_meta($post_id, 'state', true);
  if ( ! $state ) $state = get_post_meta($post_id, '_state', true);
  
  // Build city_state string
  if ( $city && $state ) {
    $city_state = trim($city) . ', ' . trim($state);
  } elseif ( $city ) {
    $city_state = trim($city);
  } elseif ( $state ) {
    $city_state = trim($state);
  }
  
  // Allow filtering for custom post meta structures
  $city_state = apply_filters('myls_ai_faqs_city_state', $city_state, $post_id);

  // Get contact URL from option (with fallback)
  $contact_page_id = (int) get_option('myls_contact_page_id', 0);
  $contact_url = $contact_page_id > 0 ? get_permalink($contact_page_id) : home_url('/contact-us/');
  $contact_url = esc_url_raw( $contact_url );

  // Populate prompt vars
  $credentials = function_exists('myls_build_tagline_credentials') ? myls_build_tagline_credentials() : '';

  $prompt = str_replace(
    ['{{TITLE}}','{{URL}}','{{PAGE_TEXT}}','{{ALLOW_LINKS}}','{{VARIANT}}','{{CITY_STATE}}','{{CONTACT_URL}}','{{CREDENTIALS}}'],
    [$title, $url, $page_text, $allow_links ? 'YES' : 'NO', $variant, $city_state, $contact_url, $credentials],
    $template
  );

  // ── Variation Engine: inject angle + banned phrases for FAQ generation ──
  // FAQs are extremely prone to duplication across service area pages.
  // The angle controls question perspective diversity (cost, timing, materials, etc.)
  if ( class_exists('MYLS_Variation_Engine') ) {
    $angle  = MYLS_Variation_Engine::next_angle('faqs_generate');
    $prompt = MYLS_Variation_Engine::inject_variation( $prompt, $angle, 'faqs_generate' );
  }

  // Params (fallback to options)
  $tokens = max(1, (int) ($_POST['tokens'] ?? (int) get_option('myls_ai_faqs_tokens', 10000)));
  $temp   = (float) ($_POST['temperature'] ?? (float) get_option('myls_ai_faqs_temperature', 0.5));
  $model  = isset($_POST['model']) && is_string($_POST['model']) ? trim($_POST['model']) : '';

  $ai_opts = [
    'model'       => $model ?: null,
    'max_tokens'  => $tokens,
    'temperature' => $temp,
    'context'     => 'faqs_generate',
    'post_id'     => $post_id,
  ];

  // ── Retry loop: attempt generation up to 3 times ──
  $max_attempts  = 3;
  $raw           = '';
  $clean         = '';
  $validation    = ['valid' => false, 'faq_count' => 0, 'reason' => 'not_started'];
  $retry_reasons = [];

  for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {

    // On retries, bump temperature slightly to get different output
    $retry_opts = $ai_opts;
    if ( $attempt > 1 ) {
      $retry_opts['temperature'] = min( 1.2, $temp + ( 0.15 * ( $attempt - 1 ) ) );
      error_log("[MYLS FAQ] Retry #{$attempt} for post #{$post_id} (reason: {$validation['reason']}). Temp: {$retry_opts['temperature']}");
    }

    $ai = myls_ai_generate_text($prompt, $retry_opts);
    $raw = myls_ai_strip_code_fences((string)$ai);

    // Detect and convert markdown → HTML if the AI ignored the HTML-only instruction
    if ( $raw !== '' && myls_ai_detect_markdown($raw) ) {
      $raw = myls_ai_markdown_to_html($raw);
      error_log('[MYLS FAQ] AI returned markdown for post #' . $post_id . ' (attempt ' . $attempt . ') — auto-converted to HTML.');
    }

    // ── Variation Engine: duplicate guard for FAQs ──
    if ( $raw !== '' && class_exists('MYLS_Variation_Engine') ) {
      // Only run guard on first attempt to avoid burning extra API calls on retries
      if ( $attempt === 1 ) {
        $raw = MYLS_Variation_Engine::guard_duplicates(
          'faqs_generate',
          $raw,
          function( $original ) use ( $model, $tokens, $temp, $post_id, $city_state ) {
            $rewrite  = "Rewrite these FAQs to be structurally distinct.\n";
            $rewrite .= "Replace the first 3 questions entirely with different topics.\n";
            $rewrite .= "Keep the same city ({$city_state}), same HTML formatting.\n";
            $rewrite .= "Return clean HTML only.\n\nOriginal:\n" . $original;
            $result = myls_ai_generate_text( $rewrite, [
              'model'       => $model ?: null,
              'max_tokens'  => $tokens,
              'temperature' => min( 1.0, $temp + 0.1 ),
              'context'     => 'faqs_generate',
              'post_id'     => $post_id,
            ]);
            return myls_ai_strip_code_fences( (string) $result );
          }
        );
      }
    }

    // Sanitize output HTML
    $allowed = [
      'h2'     => [],
      'h3'     => [],
      'p'      => [],
      'ul'     => [],
      'ol'     => [],
      'li'     => [],
      'strong' => [],
      'em'     => [],
    ];
    if ( $allow_links ) {
      $allowed['a'] = ['href'=>true, 'target'=>true, 'rel'=>true, 'title'=>true];
    }

    // Sanitize raw HTML (shared function handles all tag fixes, attribute cleanup, link handling)
    $raw = myls_ai_faqs_sanitize_raw_html($raw, $allow_links);

    $clean = wp_kses($raw, $allowed);

    // Validate
    $validation = myls_ai_faqs_validate_output($clean);

    if ( $validation['valid'] ) {
      // Good output — break out of retry loop
      if ( $attempt > 1 ) {
        error_log("[MYLS FAQ] Retry #{$attempt} succeeded for post #{$post_id}. FAQs: {$validation['faq_count']}");
      }
      break;
    }

    // Track why this attempt failed — include stats for debugging
    $attempt_h3  = (int) preg_match_all('/<h3\b/i', $clean);
    $attempt_p   = (int) preg_match_all('/<p\b/i', $clean);
    $attempt_wc  = str_word_count(wp_strip_all_tags($clean));
    $retry_reasons[] = "attempt {$attempt}: {$validation['reason']} (FAQs: {$validation['faq_count']}, h3: {$attempt_h3}, p: {$attempt_p}, words: {$attempt_wc}, raw_len: " . strlen($raw) . ")";
  }

  // ── After all attempts: if still invalid, return error ──
  if ( ! $validation['valid'] ) {
    $reasons_str = implode('; ', $retry_reasons);
    error_log("[MYLS FAQ] All {$max_attempts} attempts failed for post #{$post_id}. Reasons: {$reasons_str}");

    // Get what model/provider actually ran
    $ai_call_info = function_exists('myls_ai_last_call') ? myls_ai_last_call() : [];

    // Build rich diagnostics for the frontend log
    $raw_text      = wp_strip_all_tags($raw);
    $raw_h3        = (int) preg_match_all('/<h3\b/i', $raw);
    $raw_p         = (int) preg_match_all('/<p\b/i', $raw);
    $clean_pairs   = myls_ai_faqs_extract_pairs($clean);

    wp_send_json_error([
      'status'       => 'error',
      'message'      => "FAQ generation failed after {$max_attempts} attempts ({$validation['reason']}). Try regenerating this post.",
      'post_id'      => $post_id,
      'title'        => $title,
      'city_state'   => $city_state,
      'attempts'     => $max_attempts,
      'reasons'      => $retry_reasons,
      'raw_preview'  => mb_substr($raw_text, 0, 500),
      // Diagnostics
      'diag' => [
        'model'          => $ai_call_info['resolved_model'] ?? ($model ?: '(default)'),
        'provider'       => $ai_call_info['provider'] ?? '(unknown)',
        'raw_length'     => strlen($raw),
        'raw_words'      => str_word_count($raw_text),
        'raw_h3_count'   => $raw_h3,
        'raw_p_count'    => $raw_p,
        'clean_pairs'    => count($clean_pairs),
        'page_text_len'  => strlen($page_text),
        'tokens'         => $tokens,
        'temperature'    => $temp,
        'variant'        => $variant,
        'validation'     => $validation,
        'has_markdown'   => (bool) preg_match('/^#{1,3}\s|\*\*[^*]+\*\*/m', $raw),
        'has_code_fence' => strpos($raw, '```') !== false,
        'raw_first_500'  => mb_substr($raw, 0, 500),
      ],
    ], 422);
  }

  // ── Post-validation: strip bad FAQs and fill gaps ──────────────
  // Extract validated pairs, ALWAYS rebuild HTML from clean pairs only,
  // and fill if we're below target count.
  $fill_count      = 0;
  $fill_attempts   = 0;
  $dropped_reasons = [];

  // Extract the validated pairs from the clean HTML
  $valid_pairs     = myls_ai_faqs_extract_pairs($clean);
  $raw_h3_count    = (int) preg_match_all('/<h3\b/i', $clean);
  $dropped_count   = max(0, $raw_h3_count - count($valid_pairs));

  // Determine target FAQ count from variant
  $target_count = ($variant === 'SHORT') ? 8 : 10;

  // ── ALWAYS rebuild HTML from validated pairs only ──
  // This ensures bad FAQs are stripped from the output even when fill doesn't run.
  if ( $dropped_count > 0 || $raw_h3_count !== count($valid_pairs) ) {
    error_log("[MYLS FAQ] Post #{$post_id}: Rebuilding HTML — dropped {$dropped_count} bad FAQ(s) from {$raw_h3_count} total.");

    $rebuilt_html = '';

    // Preserve any <h2> header from original output
    if ( preg_match('/<h2\b[^>]*>.*?<\/h2>/is', $clean, $h2_match) ) {
      $rebuilt_html .= $h2_match[0] . "\n";
    }

    foreach ( $valid_pairs as $pair ) {
      $rebuilt_html .= '<h3>' . esc_html($pair['question']) . "</h3>\n";
      $rebuilt_html .= $pair['answer_html'] . "\n";
    }

    // Preserve any <h2>Sources</h2> section from original output
    if ( preg_match('/<h2[^>]*>\s*Sources?\s*<\/h2>.*$/is', $clean, $src_match) ) {
      $rebuilt_html .= $src_match[0];
    }

    $clean = wp_kses($rebuilt_html, $allowed);
  }

  // ── Fill pass: generate replacement FAQs if below target ──
  if ( count($valid_pairs) < $target_count ) {

    $need = min( $target_count - count($valid_pairs), 5 ); // cap fill at 5
    $existing_questions = array_map(function($p) { return $p['question']; }, $valid_pairs);
    $existing_list = implode("\n", array_map(function($q, $i) { return ($i+1) . '. ' . $q; }, $existing_questions, array_keys($existing_questions)));

    // Build targeted fill prompt
    $fill_prompt  = "Generate exactly {$need} additional FAQ(s) about the specific service: \"{$title}\"";
    if ( $city_state !== '' ) $fill_prompt .= " in {$city_state}";
    $fill_prompt .= ".\n\n";
    $fill_prompt .= "IMPORTANT: Every FAQ MUST be directly related to the service topic \"{$title}\". Do NOT generate generic questions about permits, business registration, or unrelated topics.\n\n";
    $fill_prompt .= "EXISTING QUESTIONS (do NOT duplicate these):\n{$existing_list}\n\n";
    $fill_prompt .= "RULES:\n";
    $fill_prompt .= "- Return clean HTML only. No markdown, no code fences, no backticks.\n";
    $fill_prompt .= "- Write every sentence as proper grammatical English with articles, prepositions, and verbs.\n";
    $fill_prompt .= "\n";
    $fill_prompt .= "HTML FORMATTING — CRITICAL:\n";
    $fill_prompt .= "- Tags must be properly formed with NO spaces: <h3> not < h3 > or < h 3 >\n";
    $fill_prompt .= "- Closing tags must be properly formed: </p> not </ p> or / p> or </p]\n";
    $fill_prompt .= "- Links must use proper tags: <a href=\"url\">text</a> — never write the href attribute as plain text\n";
    $fill_prompt .= "- Every <h3> must have </h3>, every <p> must have </p>, every <a> must have </a>\n";
    $fill_prompt .= "- Allowed tags ONLY: <h3> <p> <ul> <ol> <li> <strong> <em> <a>\n";
    $fill_prompt .= "- NEVER nest identical tags (e.g. <strong><strong> is invalid)\n";
    $fill_prompt .= "\n";
    $fill_prompt .= "Each FAQ must follow this EXACT structure:\n";
    $fill_prompt .= "  <h3>Question about {$title}?</h3>\n";
    $fill_prompt .= "  <p><strong>Answer:</strong> Direct answer sentence.</p>\n";
    $fill_prompt .= "  <p>Supporting details for";
    if ( $city_state !== '' ) $fill_prompt .= " {$city_state}";
    $fill_prompt .= " customers. At least 60 words total.</p>\n";
    $fill_prompt .= "  <ul>\n  <li>Benefit or detail one</li>\n  <li>Benefit or detail two</li>\n  <li>Benefit or detail three</li>\n  </ul>\n";
    $fill_prompt .= "- Questions must be different topics from the existing list above.\n";
    $fill_prompt .= "- Write for homeowners/customers searching in this area.\n";
    $fill_prompt .= "- Do NOT include any code, error messages, file paths, or technical syntax.\n";
    if ( $contact_url !== '' ) {
      $fill_prompt .= "- Every FAQ MUST end with this closing paragraph:\n";
      $fill_prompt .= "  <p><em>Helpful next step:</em> [action sentence] <a href=\"{$contact_url}\">Contact us</a> [closing phrase].</p>\n";
    }

    error_log("[MYLS FAQ] Fill pass for post #{$post_id}: need {$need} replacement FAQ(s), {$dropped_count} were dropped from original {$raw_h3_count}.");

    // Try fill up to 2 times
    for ( $fill_try = 1; $fill_try <= 2; $fill_try++ ) {
      $fill_attempts++;
      $fill_opts = $ai_opts;
      $fill_opts['max_tokens'] = min(3000, $need * 600); // ~600 tokens per FAQ
      $fill_opts['temperature'] = min(1.0, $temp + 0.1);

      $fill_raw = myls_ai_generate_text($fill_prompt, $fill_opts);
      $fill_raw = myls_ai_strip_code_fences((string)$fill_raw);

      if ( $fill_raw !== '' && myls_ai_detect_markdown($fill_raw) ) {
        $fill_raw = myls_ai_markdown_to_html($fill_raw);
      }

      // Sanitize same as main output (shared function)
      $fill_raw = myls_ai_faqs_sanitize_raw_html($fill_raw, $allow_links);
      $fill_clean = wp_kses($fill_raw, $allowed);

      // Extract and validate fill pairs
      $fill_pairs = myls_ai_faqs_extract_pairs($fill_clean);

      if ( ! empty($fill_pairs) ) {
        // Merge valid fill pairs into the main set
        foreach ( $fill_pairs as $fp ) {
          // Skip if this question duplicates an existing one
          $dup = false;
          foreach ( $valid_pairs as $vp ) {
            if ( similar_text( strtolower($fp['question']), strtolower($vp['question']) ) / max(1, strlen($fp['question'])) > 0.7 ) {
              $dup = true;
              break;
            }
          }
          if ( ! $dup ) {
            $valid_pairs[] = $fp;
            $fill_count++;
          }
        }

        error_log("[MYLS FAQ] Fill pass attempt {$fill_try}: got " . count($fill_pairs) . " FAQ(s), kept {$fill_count} after dedup.");
        break; // Fill succeeded
      }

      error_log("[MYLS FAQ] Fill pass attempt {$fill_try} produced 0 valid FAQs.");
    }

    // Rebuild the complete HTML from all valid pairs (original + fill)
    if ( $fill_count > 0 ) {
      $rebuilt_html = '';

      // Preserve any <h2> header from original output
      if ( preg_match('/<h2\b[^>]*>.*?<\/h2>/is', $clean, $h2_match) ) {
        $rebuilt_html .= $h2_match[0] . "\n";
      }

      foreach ( $valid_pairs as $pair ) {
        $rebuilt_html .= '<h3>' . esc_html($pair['question']) . "</h3>\n";
        $rebuilt_html .= $pair['answer_html'] . "\n";
      }

      // Preserve any <h2>Sources</h2> section from original output
      if ( preg_match('/<h2[^>]*>\s*Sources?\s*<\/h2>.*$/is', $clean, $src_match) ) {
        $rebuilt_html .= $src_match[0];
      }

      $clean = wp_kses($rebuilt_html, $allowed);

      // Re-validate the rebuilt output
      $validation = myls_ai_faqs_validate_output($clean);

      error_log("[MYLS FAQ] Post #{$post_id} after fill: {$validation['faq_count']} FAQs (was " . ($validation['faq_count'] - $fill_count) . " + {$fill_count} filled).");
    }
  }

  // Save downloads
  $html_url = myls_ai_faqs_save_html($post_id, (string)$title, (string)$url, (string)$clean);
  $doc_url  = myls_ai_faqs_save_docx($post_id, (string)$title, (string)$clean);

  // Count questions generated (validated = clean pairs after per-FAQ filtering + fill)
  $faq_count = $validation['faq_count'];

  // Get what model/provider actually ran
  $ai_call_info = function_exists('myls_ai_last_call') ? myls_ai_last_call() : [];
  $resolved_model    = $ai_call_info['resolved_model'] ?? ($model ?: 'default');
  $resolved_provider = $ai_call_info['provider'] ?? 'openai';

  $ve_log = class_exists('MYLS_Variation_Engine') ? MYLS_Variation_Engine::build_item_log($start_time, [
    'model'          => $resolved_model,
    'provider'       => $resolved_provider,
    'tokens'         => $tokens,
    'temperature'    => $temp,
    'prompt_chars'   => mb_strlen($prompt),
    'output_words'   => str_word_count(wp_strip_all_tags($clean)),
    'output_chars'   => strlen($clean),
    'page_title'     => (string) $title,
    'city_state'     => $city_state,
    '_html'          => $clean,
    'variant'        => $variant,
    'faq_count'      => $faq_count ?: 0,
    'raw_faq_count'  => $raw_h3_count,
    'dropped_faqs'   => $dropped_count,
    'filled_faqs'    => $fill_count,
    'fill_attempts'  => $fill_attempts,
    'has_doc'        => trim($doc_url ?? '') !== '',
    'allow_links'    => $allow_links,
  ]) : ['elapsed_ms' => round((microtime(true) - $start_time) * 1000)];

  wp_send_json_success([
    'status'   => 'ok',
    'post_id'  => (int) $post_id,
    'title'    => (string) $title,
    'url'      => (string) $url,
    'html'     => (string) $clean,
    'raw'      => (string) $raw,
    'html_url' => (string) $html_url,
    'doc_url'  => (string) $doc_url,
    'city_state' => $city_state,
    'faq_count' => $faq_count,
    'dropped_faqs' => $dropped_count,
    'filled_faqs'  => $fill_count,
    'fill_attempts' => $fill_attempts,
    'attempts' => $attempt,
    'retries'  => ! empty($retry_reasons) ? $retry_reasons : [],
    'preview'  => mb_substr(wp_strip_all_tags($clean), 0, 120) . (mb_strlen(wp_strip_all_tags($clean)) > 120 ? '...' : ''),
    'log'      => $ve_log,
  ]);
});

/* =============================================================================
 * MYLS (Native) insertion/deletion for generated FAQs
 *
 * Stores FAQs in:
 *   _myls_faq_items = [ ['q' => string, 'a' => string(HTML)], ... ]
 *
 * We track which rows were auto-inserted via post meta hashes:
 *   _myls_ai_faqs_auto_hashes_myls (array of sha1 hashes)
 * ============================================================================= */

if ( ! function_exists('myls_ai_faqs_myls_auto_meta_key') ) {
  function myls_ai_faqs_myls_auto_meta_key() : string {
    return (string) apply_filters('myls_ai_faqs_myls_auto_meta_key', '_myls_ai_faqs_auto_hashes_myls');
  }
}

if ( ! function_exists('myls_ai_faqs_myls_get_items') ) {
  function myls_ai_faqs_myls_get_items( int $post_id ) : array {
    $items = get_post_meta($post_id, '_myls_faq_items', true);
    return is_array($items) ? $items : [];
  }
}

if ( ! function_exists('myls_ai_faqs_myls_normalize_items') ) {
  /**
   * Normalize existing MYLS FAQ items into safe shape.
   */
  function myls_ai_faqs_myls_normalize_items( array $items ) : array {
    $out = [];
    foreach ($items as $row) {
      if ( ! is_array($row) ) continue;
      $q = isset($row['q']) ? sanitize_text_field((string)$row['q']) : '';
      $a = isset($row['a']) ? wp_kses_post((string)$row['a']) : '';
      if ( trim($q) === '' && trim(wp_strip_all_tags($a)) === '' ) continue;
      $out[] = [ 'q' => $q, 'a' => $a ];
    }
    return $out;
  }
}

if ( ! function_exists('myls_ai_faqs_myls_hash_existing') ) {
  /**
   * Build a hash set for existing MYLS FAQs.
   * We hash question + plain-text answer so it matches the extraction hash.
   */
  function myls_ai_faqs_myls_hash_existing( array $items ) : array {
    $seen = [];
    foreach ($items as $row) {
      if ( ! is_array($row) ) continue;
      $q = isset($row['q']) ? (string)$row['q'] : '';
      $a = isset($row['a']) ? (string)$row['a'] : '';
      $q = trim(wp_strip_all_tags($q));
      $a_plain = trim(wp_strip_all_tags($a));
      if ($q === '' || $a_plain === '') continue;
      $seen[myls_ai_faqs_hash_row($q, $a_plain)] = true;
    }
    return $seen;
  }
}

if ( ! function_exists('myls_ai_faqs_myls_answer_to_html') ) {
  function myls_ai_faqs_myls_answer_to_html( string $answer_plain ) : string {
    $answer_plain = trim((string)$answer_plain);
    if ($answer_plain === '') return '';
    // Store as HTML for wp_editor; keep it clean.
    $answer_plain = wp_strip_all_tags($answer_plain);
    return wpautop(esc_html($answer_plain));
  }
}

/* =============================================================================
 * AJAX: Insert generated FAQs into MYLS native structure
 * Action: myls_ai_faqs_insert_myls_v1
 * Expects:
 *   - post_id
 *   - html
 *   - replace_existing (optional "1")
 * ============================================================================= */
add_action('wp_ajax_myls_ai_faqs_insert_myls_v1', function(){

  myls_ai_check_nonce();

  $post_id = (int) ($_POST['post_id'] ?? 0);
  if ( $post_id <= 0 || get_post_status($post_id) === false ) {
    wp_send_json_error(['marker'=>'faqs','status'=>'error','message'=>'bad_post'], 400);
  }
  if ( ! current_user_can('edit_post', $post_id) ) {
    wp_send_json_error(['marker'=>'faqs','status'=>'error','message'=>'cap_denied'], 403);
  }

  $html    = (string) wp_unslash($_POST['html'] ?? '');
  $replace = ! empty($_POST['replace_existing']);

  $pairs = myls_ai_faqs_extract_pairs($html);
  if ( empty($pairs) ) {
    wp_send_json_error(['marker'=>'faqs','status'=>'error','message'=>'missing_faqs_for_myls'], 400);
  }

  // When replacing, start from a clean slate.
  $existing = $replace ? [] : myls_ai_faqs_myls_get_items($post_id);
  $existing = myls_ai_faqs_myls_normalize_items($existing);

  $seen = myls_ai_faqs_myls_hash_existing($existing);

  $auto_key = myls_ai_faqs_myls_auto_meta_key();

  // IMPORTANT:
  // If we are overwriting existing FAQs, we also want to overwrite the
  // auto-hash marker set. Otherwise the marker list can accumulate stale
  // hashes and make delete-auto confusing.
  $auto_hashes = $replace ? [] : get_post_meta($post_id, $auto_key, true);
  if ( ! is_array($auto_hashes) ) $auto_hashes = [];

  $inserted = 0;
  $skipped  = 0;

  foreach ($pairs as $pair) {
    $q = (string) ($pair['question'] ?? '');
    $a_plain = (string) ($pair['answer'] ?? '');
    $a_html  = (string) ($pair['answer_html'] ?? '');
    $h = (string) ($pair['hash'] ?? '');

    $q = trim(wp_strip_all_tags($q));
    $a_plain = html_entity_decode($a_plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $a_plain = str_replace(["\xC2\xA0", '&nbsp;'], ' ', (string)$a_plain);
    $a_plain = preg_replace('/\s+/u', ' ', (string)$a_plain);
    $a_plain = trim(wp_strip_all_tags((string)$a_plain));

    // Prefer HTML answer (preserve bullets/steps) when available.
    $answer_to_store = '';
    if ( $a_html !== '' ) {
      $answer_to_store = wp_kses_post($a_html);
    } else {
      // Legacy path: we only have plain text; convert to simple paragraph HTML.
      $answer_to_store = wp_kses_post( myls_ai_faqs_myls_answer_to_html( (string)$a_plain ) );
    }

    if ($q === '' || $a_plain === '') { $skipped++; continue; }

    if ($h === '') $h = myls_ai_faqs_hash_row($q, $a_plain);
    if ($h === '' || isset($seen[$h])) { $skipped++; continue; }

    $existing[] = [
      'q' => sanitize_text_field($q),
      'a' => $answer_to_store,
    ];

    $seen[$h] = true;
    $auto_hashes[] = $h;
    $inserted++;
  }

  $existing = array_values($existing);
  $auto_hashes = array_values(array_unique(array_filter($auto_hashes)));

  if ( empty($existing) ) {
    delete_post_meta($post_id, '_myls_faq_items');
  } else {
    update_post_meta($post_id, '_myls_faq_items', $existing);
  }

  update_post_meta($post_id, $auto_key, $auto_hashes);

  wp_send_json_success([
    'marker'         => 'faqs',
    'status'         => 'ok',
    'post_id'        => $post_id,
    'inserted_count' => $inserted,
    'skipped_count'  => $skipped,
    'total_rows'     => count($existing),
  ]);
});

/* =============================================================================
 * AJAX: Check whether a post already has MYLS FAQs
 * Action: myls_ai_faqs_check_existing_myls_v1
 * Expects:
 *   - post_id
 * Returns:
 *   - has_faqs (bool)
 *   - count (int)
 * ============================================================================= */
add_action('wp_ajax_myls_ai_faqs_check_existing_myls_v1', function(){

  myls_ai_check_nonce();

  $post_id = (int) ($_POST['post_id'] ?? 0);
  if ( $post_id <= 0 || get_post_status($post_id) === false ) {
    wp_send_json_error(['marker'=>'faqs','status'=>'error','message'=>'bad_post'], 400);
  }
  if ( ! current_user_can('edit_post', $post_id) ) {
    wp_send_json_error(['marker'=>'faqs','status'=>'error','message'=>'cap_denied'], 403);
  }

  $items = myls_ai_faqs_myls_get_items($post_id);
  $items = myls_ai_faqs_myls_normalize_items(is_array($items) ? $items : []);
  $count = count($items);

  wp_send_json_success([
    'marker'   => 'faqs',
    'status'   => 'ok',
    'post_id'  => $post_id,
    'has_faqs' => $count > 0,
    'count'    => $count,
  ]);
});

/* =============================================================================
 * AJAX: Delete ALL FAQs from MYLS native structure for selected posts
 * Action: myls_ai_faqs_delete_auto_myls_v1
 * ============================================================================= */
add_action('wp_ajax_myls_ai_faqs_delete_auto_myls_v1', function(){

  myls_ai_check_nonce();

  $post_id = (int) ($_POST['post_id'] ?? 0);
  if ( $post_id <= 0 || get_post_status($post_id) === false ) {
    wp_send_json_error(['marker'=>'faqs','status'=>'error','message'=>'bad_post'], 400);
  }
  if ( ! current_user_can('edit_post', $post_id) ) {
    wp_send_json_error(['marker'=>'faqs','status'=>'error','message'=>'cap_denied'], 403);
  }

  // Count existing FAQs before clearing
  $items = myls_ai_faqs_myls_normalize_items(myls_ai_faqs_myls_get_items($post_id));
  $deleted = count($items);

  // Clear the FAQ custom field entirely
  delete_post_meta($post_id, '_myls_faq_items');

  // Also clear auto-generated hash markers
  $auto_key = myls_ai_faqs_myls_auto_meta_key();
  delete_post_meta($post_id, $auto_key);

  wp_send_json_success([
    'marker'        => 'faqs',
    'status'        => 'ok',
    'post_id'       => $post_id,
    'deleted_count' => $deleted,
    'total_rows'    => 0,
  ]);
});
