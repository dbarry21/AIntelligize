<?php
/**
 * AIntelligize – AI AJAX (About the Area v2, saving to _about_the_area)
 * Path: inc/ajax/ai-about.php
 *
 * Endpoints:
 *  - wp_ajax_myls_ai_about_get_posts_v2
 *  - wp_ajax_myls_ai_about_generate_v2
 */
if ( ! defined('ABSPATH') ) exit;

/** Nonce helper */
if ( ! function_exists('myls_ai_check_nonce') ) {
  function myls_ai_check_nonce( string $action = 'myls_ai_ops' ) : void {
    $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : ( $_REQUEST['_ajax_nonce'] ?? '' );
    if ( ! wp_verify_nonce( $nonce, $action ) ) {
      wp_send_json_error(['marker'=>'about_v2','status'=>'error','message'=>'bad_nonce'], 403);
    }
  }
}

/** AI provider wrapper (must pass through model/max_tokens) */
if ( ! function_exists('myls_ai_generate_text') ) {
  function myls_ai_generate_text( string $prompt, array $opts = [] ) : string {
    // Preferred provider
    $out = apply_filters('myls_ai_complete', '', array_merge([
      'prompt'      => $prompt,
      'model'       => $opts['model']       ?? null,  // ensure downstream provider can use this
      'max_tokens'  => $opts['max_tokens']  ?? null,  // and this
      'temperature' => $opts['temperature'] ?? null,
      'context'     => $opts['context']     ?? null,
      'post_id'     => $opts['post_id']     ?? null,
    ], $opts));
    if ( is_string($out) && $out !== '' ) return $out;

    // Fallback provider
    $resp = apply_filters('myls_ai_generate_text', '', $prompt);
    if ( is_string($resp) && $resp !== '' ) return $resp;

    return '';
  }
}

/** Utilities */
if ( ! function_exists('myls_about_strip_code_fences') ) {
  function myls_about_strip_code_fences( string $s ) : string {
    // remove leading ```lang\n
    $s = preg_replace('/^\s*```[a-zA-Z0-9_-]*\s*\n/u', '', $s);
    // remove trailing ```
    $s = preg_replace('/\n?\s*```\\s*$/u', '', $s);
    // remove any remaining ```
    return str_replace("```", "", $s);
  }
}

/**
 * Convert leftover Markdown formatting to HTML.
 * AI models sometimes return **bold** or *italic* even when told to use HTML.
 *
 * IMPORTANT: Bold conversion MUST run before list-item conversion,
 * otherwise lines starting with **text** get misidentified as list items
 * because the leading * matches the list-item pattern.
 */
if ( ! function_exists('myls_about_markdown_to_html') ) {
  function myls_about_markdown_to_html( string $s ) : string {

    // ── Step 1: Bold / Italic (MUST run first) ──────────────────────
    // **bold** or __bold__  →  <strong>bold</strong>
    // Use non-greedy match, one line at a time to avoid cross-paragraph grabs
    $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
    $s = preg_replace('/__(.+?)__/u', '<strong>$1</strong>', $s);

    // *italic* or _italic_  →  <em>italic</em>
    // Only match single * not preceded/followed by word char (avoids list markers)
    $s = preg_replace('/(?<!\w)\*(?!\s)(.+?)(?<!\s)\*(?!\w)/u', '<em>$1</em>', $s);

    // ── Step 2: Headings ────────────────────────────────────────────
    // ## Heading 2  →  <h2>Heading 2</h2>
    $s = preg_replace('/^##\s*(.+)$/m', '<h2>$1</h2>', $s);
    // ### Heading 3  →  <h3>Heading 3</h3>
    $s = preg_replace('/^###\s*(.+)$/m', '<h3>$1</h3>', $s);

    // ── Step 3: List items (runs AFTER bold is converted) ───────────
    // - item or * item  →  <li>item</li>
    // Only match lines that start with - or * followed by space
    // Skip lines that are already HTML tags
    $s = preg_replace('/^[\-\*]\s+(?!<)(.+)$/m', '<li>$1</li>', $s);

    // Wrap consecutive <li> blocks in <ul> if not already wrapped
    $s = preg_replace_callback('/(<li>.*?<\/li>\s*)+/s', function($m) {
      $block = trim($m[0]);
      // Don't double-wrap
      if (strpos($block, '<ul>') !== false) return $block;
      return '<ul>' . $block . '</ul>';
    }, $s);

    // ── Step 4: Clean up any stray asterisks that survived ──────────
    // Remove lone asterisks that were meant as bold markers but didn't match
    // (e.g., unmatched ** at end of truncated output)
    $s = preg_replace('/(?<!\w)\*{2,}(?!\w)/', '', $s);

    return $s;
  }
}

/**
 * Full AI response cleanup: strip fences, convert markdown to HTML.
 */
if ( ! function_exists('myls_about_clean_ai_response') ) {
  function myls_about_clean_ai_response( string $s ) : string {
    $s = myls_about_strip_code_fences( $s );
    $s = myls_about_markdown_to_html( $s );
    return $s;
  }
}
if ( ! function_exists('myls_about_has_h3') ) {
  function myls_about_has_h3( string $s ) : bool {
    return (bool) preg_match('/<h3[^>]*>/i', $s);
  }
}
if ( ! function_exists('myls_about_word_count') ) {
  function myls_about_word_count( string $s ) : int {
    $plain = wp_strip_all_tags($s);
    $plain = preg_replace('/\s+/u',' ', trim($plain));
    if ($plain === '') return 0;
    return count( preg_split('/\s+/u', $plain) );
  }
}

/** v2: list posts */
add_action('wp_ajax_myls_ai_about_get_posts_v2', function(){
  myls_ai_check_nonce();

  $pt = isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : 'service_area';
  if ( ! post_type_exists($pt) ) {
    wp_send_json_error(['marker'=>'about_v2','status'=>'error','message'=>'invalid_post_type'], 400);
  }
  $ptype_obj = get_post_type_object($pt);
  $cap = $ptype_obj && isset($ptype_obj->cap->edit_posts) ? $ptype_obj->cap->edit_posts : 'edit_posts';
  if ( ! current_user_can($cap) ) {
    wp_send_json_error(['marker'=>'about_v2','status'=>'error','message'=>'cap_denied'], 403);
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
    $about_content = (string) get_post_meta($pid, '_about_the_area', true);
    $has_content   = trim(wp_strip_all_tags($about_content)) !== '';
    $out[] = ['id'=>(int)$pid, 'title'=> get_the_title($pid), 'has_content' => $has_content];
  }

  wp_send_json_success(['marker'=>'about_v2','status'=>'ok','posts'=>$out]);
});

/** v2: generate + save one post (SAVE TO _about_the_area) */
add_action('wp_ajax_myls_ai_about_generate_v2', function(){
  myls_ai_check_nonce();
  $start_time = microtime(true);

  // Reset variation engine log for this request
  if ( class_exists('MYLS_Variation_Engine') ) {
    MYLS_Variation_Engine::reset_log();
  }

  $post_id     = (int) ($_POST['post_id'] ?? 0);
  $skip_filled = !empty($_POST['skip_filled']);
  // bump default to allow full sectioned HTML
  $tokens      = max(1, (int) ($_POST['tokens'] ?? 1600));
  $temperature = (float) ($_POST['temperature'] ?? 0.7);
  // allow a model override here if your provider supports it; default to gpt-4o
  $model       = isset($_POST['model']) && is_string($_POST['model']) ? trim($_POST['model']) : '';

  if ( $post_id <= 0 || get_post_status($post_id) === false ) {
    wp_send_json_error(['marker'=>'about_v2','status'=>'error','message'=>'bad_post'], 400);
  }
  if ( ! current_user_can('edit_post', $post_id) ) {
    wp_send_json_error(['marker'=>'about_v2','status'=>'error','message'=>'cap_denied'], 403);
  }

  // If skipping when already filled
  $existing = (string) get_post_meta($post_id, '_about_the_area', true);
  if ( $skip_filled && trim($existing) !== '' ) {
    wp_send_json_success([
      'marker'=>'about_v2','status'=>'skipped','post_id'=>$post_id,'reason'=>'already_filled'
    ]);
  }

  // Get city/state best-effort
  $city_state = '';
  if ( function_exists('get_field') ) $city_state = (string) get_field('city_state', $post_id);
  if ( $city_state === '' ) $city_state = (string) get_post_meta($post_id, 'city_state', true);
  if ( $city_state === '' ) $city_state = get_the_title($post_id);

  // Page title
  $page_title = get_the_title($post_id);

  // Yoast focus keyword (fall back to empty string)
  $focus_keyword = (string) get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
  if ( $focus_keyword === '' ) {
    // Try Rank Math as fallback
    $focus_keyword = (string) get_post_meta($post_id, 'rank_math_focus_keyword', true);
  }

  // Service Subtype from Schema settings (fall back to page title)
  $service_subtype = (string) get_option('myls_service_subtype', '');
  if ( $service_subtype === '' ) {
    $service_subtype = (string) get_option('myls_service_default_type', '');
  }

  // Strong, shape-enforcing prompt
  $base_prompt = myls_get_default_prompt('about-area');

  $template = isset($_POST['template']) ? wp_unslash($_POST['template']) : '';
  if ($template === '') {
    $template = get_option('myls_ai_about_prompt_template', $base_prompt);
  }
  // ensure {{CITY_STATE}} present
  if ( strpos($template, '{{CITY_STATE}}') === false ) {
    $template .= "\n\n(Location: {{CITY_STATE}})";
  }

  // Replace all placeholders
  $prompt_1 = str_replace(
    ['{{CITY_STATE}}', '{{PAGE_TITLE}}', '{{FOCUS_KEYWORD}}', '{{SERVICE_SUBTYPE}}'],
    [$city_state, $page_title, $focus_keyword ?: $page_title, $service_subtype ?: $page_title],
    $template
  );

  // ── Variation Engine: inject angle rotation + banned phrases ──────
  // This prevents batch runs from producing "Nestled in the heart of..." on every page.
  // Each call gets a different opening angle (homeowner, climate, growth, etc.)
  // and a list of banned stock phrases appended to the prompt.
  if ( class_exists('MYLS_Variation_Engine') ) {
    $angle   = MYLS_Variation_Engine::next_angle('about_the_area');
    $prompt_1 = MYLS_Variation_Engine::inject_variation( $prompt_1, $angle, 'about_the_area' );
  }

  // ----- First pass
  $html_1 = myls_ai_generate_text($prompt_1, [
    'model'       => $model,
    'max_tokens'  => $tokens,
    'temperature' => $temperature,
    'context'     => 'about_the_area',
    'post_id'     => $post_id,
  ]);

  if ( is_wp_error($html_1) ) {
    wp_send_json_error(['marker'=>'about_v2','status'=>'error','message'=>'ai_failed','error'=>$html_1->get_error_message()], 500);
  }

  $html_1 = myls_about_clean_ai_response( (string)$html_1 );

  // Check length & structure
  $ok_len  = myls_about_word_count($html_1) >= 380;   // tolerate a bit under 400
  $ok_h3   = myls_about_has_h3($html_1);

  $final_html = $html_1;

  // ----- Retry once if too short or missing <h3>
  if ( ! $ok_len || ! $ok_h3 ) {
    $retry_prompt = myls_get_default_prompt('about-area-retry');
    $retry_prompt = str_replace(
      ['{{CITY_STATE}}', '{{PAGE_TITLE}}', '{{FOCUS_KEYWORD}}', '{{SERVICE_SUBTYPE}}'],
      [$city_state, $page_title, $focus_keyword ?: $page_title, $service_subtype ?: $page_title],
      $retry_prompt
    );

    $html_2 = myls_ai_generate_text($retry_prompt, [
      'model'       => $model,
      'max_tokens'  => max($tokens, 1600),
      'temperature' => max(0.3, min(1.0, $temperature)), // nudge up a bit
      'context'     => 'about_the_area',
      'post_id'     => $post_id,
    ]);

    if ( is_wp_error($html_2) ) {
      wp_send_json_error(['marker'=>'about_v2','status'=>'error','message'=>'ai_failed_retry','error'=>$html_2->get_error_message()], 500);
    }

    $html_2 = myls_about_clean_ai_response( (string)$html_2 );

    // choose the better one
    $cand = myls_about_word_count($html_2) >= myls_about_word_count($html_1) ? $html_2 : $html_1;
    $final_html = $cand;
  }

  // ── Variation Engine: duplicate guard ───────────────────────────────
  // Compares this output's first 300 chars against all previous outputs in the
  // current batch. If similarity > 60%, triggers an automatic rewrite pass
  // that replaces the first two sentences to ensure structural uniqueness.
  if ( class_exists('MYLS_Variation_Engine') ) {
    $final_html = MYLS_Variation_Engine::guard_duplicates(
      'about_the_area',
      $final_html,
      function( $original_html ) use ( $model, $tokens, $temperature, $post_id, $city_state ) {
        // Build a rewrite prompt that forces structural differentiation
        $rewrite_prompt  = "Rewrite the following About the Area HTML to be structurally distinct.\n";
        $rewrite_prompt .= "Replace the first two sentences entirely with a different opening angle.\n";
        $rewrite_prompt .= "Do NOT begin with: 'Nestled in', 'Located in', 'Welcome to', 'Situated in', 'Known for', 'In the heart of'.\n";
        $rewrite_prompt .= "Keep the same city ({$city_state}), same sections, same HTML formatting.\n";
        $rewrite_prompt .= "Return clean HTML only, no markdown, no code fences.\n\n";
        $rewrite_prompt .= "Original HTML:\n" . $original_html;

        $rewritten = myls_ai_generate_text( $rewrite_prompt, [
          'model'       => $model,
          'max_tokens'  => max( $tokens, 1600 ),
          'temperature' => min( 1.0, $temperature + 0.1 ), // slightly higher for diversity
          'context'     => 'about_the_area',
          'post_id'     => $post_id,
        ] );

        if ( is_string( $rewritten ) && trim( $rewritten ) !== '' ) {
          return myls_about_clean_ai_response( $rewritten );
        }
        return $original_html; // fallback: keep original if rewrite fails
      }
    );
  }

  // Sanitize and save to _about_the_area
  $allowed = wp_kses_allowed_html('post');
  // Ensure <h2> allows style="text-align:center" for the section header
  if ( isset($allowed['h2']) ) {
    $allowed['h2']['style'] = true;
  } else {
    $allowed['h2'] = ['style' => true, 'id' => true, 'class' => true];
  }
  $clean   = wp_kses( wp_unslash($final_html), $allowed );

  $saved = (bool) update_post_meta($post_id, '_about_the_area', $clean);

  // Verify by reading same key
  $verify = (string) get_post_meta($post_id, '_about_the_area', true);

  if ( ! $saved || trim( wp_strip_all_tags($verify) ) === '' ) {
    wp_send_json_error([
      'marker'=>'about_v2','status'=>'error','message'=>'save_failed_or_empty_after_write',
      'debug'=>[
        'saved'=>$saved?'true':'false',
        'verify_len'=>strlen($verify),
        'wcount'=>myls_about_word_count($final_html),
        'has_h3'=>myls_about_has_h3($final_html) ? '1' : '0',
      ]
    ], 500);
  }

  // ── Build enterprise log ─────────────────────────────────────────
  $output_plain = wp_strip_all_tags( $clean );

  // Get what model/provider actually ran (resolved by router, not input param)
  $ai_call_info = function_exists('myls_ai_last_call') ? myls_ai_last_call() : [];
  $resolved_model    = $ai_call_info['resolved_model'] ?? $model;
  $resolved_provider = $ai_call_info['provider'] ?? 'openai';

  $ve_log = class_exists('MYLS_Variation_Engine') ? MYLS_Variation_Engine::build_item_log($start_time, [
    'model'           => $resolved_model,
    'provider'        => $resolved_provider,
    'tokens'          => $tokens,
    'temperature'     => $temperature,
    'prompt_chars'    => mb_strlen( $prompt_1 ),
    'output_words'    => str_word_count( $output_plain ),
    'output_chars'    => strlen( $clean ),
    'page_title'      => $page_title,
    'city_state'      => $city_state,
    'focus_keyword'   => $focus_keyword ?: '(none)',
    '_html'           => $clean,
    // About-specific extras
    'has_h3'          => myls_about_has_h3( $clean ),
    'retry_used'      => isset($html_2),
    'service_subtype' => $service_subtype ?: '(none)',
  ]) : ['elapsed_ms' => round((microtime(true) - $start_time) * 1000)];

  wp_send_json_success([
    'marker'     => 'about_v2',
    'status'     => 'saved',
    'post_id'    => $post_id,
    'city_state' => $city_state,
    'length'     => strlen($clean),
    'preview'    => mb_substr( $output_plain, 0, 120 ) . ( mb_strlen($output_plain) > 120 ? '...' : '' ),
    'log'        => $ve_log,
  ]);
});
