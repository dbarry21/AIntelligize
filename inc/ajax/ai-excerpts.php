<?php
/**
 * MYLS – AI AJAX: Excerpts
 * File: inc/ajax/ai-excerpts.php
 *
 * Endpoints:
 *  - wp_ajax_myls_ai_get_posts_by_type_v1        (shared utility; defined only if missing)
 *  - wp_ajax_myls_ai_excerpt_save_prompt_v1
 *  - wp_ajax_myls_ai_excerpt_generate_v1
 *
 * Saves excerpts to post_excerpt.
 */

if ( ! defined('ABSPATH') ) exit;

/* -------------------------------------------------------------------------
 * Nonce helper (shared name with other AI endpoints)
 * ------------------------------------------------------------------------- */
if ( ! function_exists('myls_ai_check_nonce') ) {
  function myls_ai_check_nonce( string $action = 'myls_ai_ops' ) : void {
    $nonce = isset($_POST['nonce']) ? (string) $_POST['nonce'] : (string) ( $_REQUEST['_ajax_nonce'] ?? '' );
    if ( ! wp_verify_nonce( $nonce, $action ) ) {
      wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }
  }
}

/* -------------------------------------------------------------------------
 * Shared: fetch posts by type (only declare if your plugin doesn't already)
 * ------------------------------------------------------------------------- */
if ( ! function_exists('myls_ai_get_posts_by_type_v1_handler') ) {

  add_action('wp_ajax_myls_ai_get_posts_by_type_v1', 'myls_ai_get_posts_by_type_v1_handler');

  function myls_ai_get_posts_by_type_v1_handler() : void {
    myls_ai_check_nonce('myls_ai_ops');
    if ( ! current_user_can('manage_options') ) {
      wp_send_json_error(['message' => 'Forbidden'], 403);
    }

    $pt = sanitize_key($_POST['post_type'] ?? 'page');
    $posts = get_posts([
      'post_type'       => $pt,
      'post_status'     => ['publish','draft','pending','future','private'],
      'posts_per_page'  => 800,
      'orderby'         => 'title',
      'order'           => 'ASC',
      'fields'          => 'ids',
      'suppress_filters'=> true,
    ]);

    $out = [];
    foreach ($posts as $pid) {
      $out[] = ['id' => (int)$pid, 'title' => (get_the_title($pid) ?: '(no title)')];
    }

    wp_send_json_success(['post_type' => $pt, 'posts' => $out]);
  }
}

/* -------------------------------------------------------------------------
 * OpenAI – generate excerpt text (uses plugin's existing OpenAI wrapper)
 * ------------------------------------------------------------------------- */
if ( ! function_exists('myls_ai_generate_excerpt_text') ) {

  /**
   * Generate excerpt text using MYLS OpenAI wrapper (Chat Completions).
   *
   * Respects:
   *  - option myls_openai_model (string) [fallback 'gpt-4o']
   *  - option myls_ai_excerpt_max_tokens (int) [fallback 180]
   *  - option myls_ai_excerpt_temperature (float) [fallback 0.7]
   */
  function myls_ai_generate_excerpt_text( string $prompt ) : string {

    // Prefer MYLS wrapper if available
    if ( function_exists('myls_ai_chat') ) {
      $model = (string) get_option('myls_openai_model', '');
      $max   = (int) get_option('myls_ai_excerpt_max_tokens', 120);
      $temp  = (float) get_option('myls_ai_excerpt_temperature', 0.7);

      if ( function_exists('myls_ai_set_usage_context') ) {
        myls_ai_set_usage_context( 'excerpts' );
      }
      $text = myls_ai_chat($prompt, [
        'model'       => $model,
        'max_tokens'  => $max,
        'temperature' => $temp,
        'system'      => 'You are an SEO/content assistant. Write clean, accurate, concise excerpts.',
      ]);

      return is_string($text) ? trim(wp_strip_all_tags($text, true)) : '';
    }

    // Hard fallback (should not happen if inc/openai.php is loaded)
    return '';
  }
}

/* -------------------------------------------------------------------------
 * Save prompt template
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_myls_ai_excerpt_save_prompt_v1', function () : void {

  myls_ai_check_nonce('myls_ai_ops');
  if ( ! current_user_can('manage_options') ) {
    wp_send_json_error(['message' => 'Forbidden'], 403);
  }

  $prompt = (string) ($_POST['prompt'] ?? '');
  $prompt = wp_kses_post($prompt); // allow safe text; stored as option

  if ( trim($prompt) === '' ) {
    wp_send_json_error(['message' => 'Prompt cannot be empty.'], 400);
  }

  update_option('myls_ai_prompt_excerpt', $prompt, false);

  wp_send_json_success(['message' => 'Saved.', 'option' => 'myls_ai_prompt_excerpt']);
});

/* -------------------------------------------------------------------------
 * Generate (and optionally save) excerpts
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_myls_ai_excerpt_generate_v1', function () : void {

  myls_ai_check_nonce('myls_ai_ops');
  if ( ! current_user_can('manage_options') ) {
    wp_send_json_error(['message' => 'Forbidden'], 403);
  }
  $batch_start = microtime(true);

  $post_ids  = isset($_POST['post_ids']) ? (array) $_POST['post_ids'] : [];
  $overwrite = ! empty($_POST['overwrite']);
  $dryrun    = ! empty($_POST['dryrun']);

  $post_ids = array_values(array_filter(array_map('intval', $post_ids)));
  if ( empty($post_ids) ) {
    wp_send_json_error(['message' => 'No posts selected.'], 400);
  }

  $tpl = (string) get_option('myls_ai_prompt_excerpt', '');
  if ( trim($tpl) === '' && function_exists('myls_get_default_prompt') ) {
    $tpl = myls_get_default_prompt('excerpt');
  }
  if ( trim($tpl) === '' ) {
    wp_send_json_error(['message' => 'Missing excerpt prompt template (option: myls_ai_prompt_excerpt).'], 400);
  }

  $site_name = (string) get_bloginfo('name');

  $results = [];
  foreach ( $post_ids as $pid ) {
    $item_start = microtime(true);

    // Reset VE log per-item
    if ( class_exists('MYLS_Variation_Engine') ) {
      MYLS_Variation_Engine::reset_log();
    }

    $post = get_post($pid);
    if ( ! $post ) {
      $results[] = ['id' => $pid, 'ok' => false, 'error' => 'Post not found'];
      continue;
    }

    $current_excerpt = (string) $post->post_excerpt;
    if ( $current_excerpt !== '' && ! $overwrite ) {
      $results[] = ['id' => $pid, 'ok' => true, 'skipped' => true, 'reason' => 'Excerpt exists (overwrite disabled)'];
      continue;
    }

    // Primary category (best-effort; safe fallback)
    $primary_cat = '';
    $cats = get_the_category($pid);
    if ( ! empty($cats) && ! is_wp_error($cats) ) {
      $primary_cat = (string) $cats[0]->name;
    }

    // City/State from meta (multiple fallback keys)
    $city_state = '';
    if ( function_exists('get_field') ) $city_state = (string) get_field('city_state', $pid);
    if ( $city_state === '' ) $city_state = (string) get_post_meta($pid, 'city_state', true);
    if ( $city_state === '' ) $city_state = (string) get_post_meta($pid, '_myls_city', true);

    // Content snippet — first 200 words of page content via page builder compat
    $content_snippet = '';
    if ( function_exists('myls_get_post_plain_text') ) {
      $content_snippet = myls_get_post_plain_text( $pid, 200 );
    } else {
      $content_snippet = wp_trim_words( wp_strip_all_tags( $post->post_content ), 200, '…' );
    }

    $prompt = $tpl;
    $prompt = str_replace('{post_title}',       (string) get_the_title($pid), $prompt);
    $prompt = str_replace('{site_name}',        $site_name, $prompt);
    $prompt = str_replace('{excerpt}',          $current_excerpt, $prompt);
    $prompt = str_replace('{primary_category}', $primary_cat, $prompt);
    $prompt = str_replace('{permalink}',        (string) get_permalink($pid), $prompt);
    $prompt = str_replace('{city_state}',       $city_state, $prompt);
    $prompt = str_replace('{content_snippet}',  $content_snippet, $prompt);

    // ── Variation Engine: inject angle + banned phrases for excerpt generation ──
    if ( class_exists('MYLS_Variation_Engine') ) {
      $angle  = MYLS_Variation_Engine::next_angle('excerpt');
      $prompt = MYLS_Variation_Engine::inject_variation( $prompt, $angle, 'excerpt' );
    }

    if ( function_exists('myls_ai_set_usage_context') ) {
      myls_ai_set_usage_context( 'excerpts', $pid );
    }
    $gen = myls_ai_generate_excerpt_text($prompt);

    if ( $gen === '' ) {
      $diag = 'AI returned empty.';
      if ( ! empty( $GLOBALS['myls_ai_last_error'] ) ) {
        $diag .= ' Error: ' . mb_substr( $GLOBALS['myls_ai_last_error'], 0, 300 );
        $GLOBALS['myls_ai_last_error'] = '';
      }
      if ( function_exists('myls_ai_last_call') ) {
        $lc = myls_ai_last_call();
        $diag .= ' | Provider: ' . ($lc['provider'] ?? '?') . ', Model: ' . ($lc['resolved_model'] ?? '?');
      }
      $results[] = ['id' => $pid, 'ok' => false, 'error' => $diag];
      continue;
    }

    // Clean up: strip commentary, options, labels (reuse meta cleanup if available)
    $new_excerpt = trim((string) $gen);
    if ( function_exists('myls_clean_meta_output') ) {
      $new_excerpt = myls_clean_meta_output( $new_excerpt );
    }
    // Always strip HTML for WP excerpt
    $new_excerpt = trim( wp_strip_all_tags( $new_excerpt, true ) );

    // ── Variation Engine: duplicate guard for excerpts ──
    if ( $new_excerpt !== '' && class_exists('MYLS_Variation_Engine') ) {
      $new_excerpt = MYLS_Variation_Engine::guard_duplicates(
        'excerpt',
        $new_excerpt,
        function( $original ) {
          $rewrite = "Rewrite this excerpt to be structurally distinct. Use a completely different opening sentence.\n\nOriginal: " . $original;
          return trim( myls_ai_generate_excerpt_text( $rewrite ) );
        }
      );
    }

if ( $new_excerpt === '' ) {
      $results[] = ['id' => $pid, 'ok' => false, 'error' => 'Model returned empty excerpt'];
      continue;
    }

    $saved = false;
    if ( ! $dryrun ) {
      $update = wp_update_post([
        'ID'           => $pid,
        'post_excerpt' => $new_excerpt,
      ], true);

      if ( is_wp_error($update) ) {
        $results[] = ['id' => $pid, 'ok' => false, 'error' => $update->get_error_message(), 'excerpt' => $new_excerpt];
        continue;
      }
      $saved = true;
    }

    $ve_log = class_exists('MYLS_Variation_Engine') ? MYLS_Variation_Engine::build_item_log($item_start, [
      'output_words' => str_word_count($new_excerpt),
      'output_chars' => strlen($new_excerpt),
      'page_title'   => (string) get_the_title($pid),
      '_html'        => $new_excerpt,
    ]) : ['elapsed_ms' => round((microtime(true) - $item_start) * 1000)];

    $results[] = [
      'id'      => $pid,
      'ok'      => true,
      'saved'   => $saved,
      'dryrun'  => $dryrun,
      'excerpt' => $new_excerpt,
      'old'     => $current_excerpt,
      'new'     => $new_excerpt,
      'title'   => (string) get_the_title($pid),
      'preview' => mb_substr($new_excerpt, 0, 120) . (mb_strlen($new_excerpt) > 120 ? '...' : ''),
      'log'     => $ve_log,
    ];
  }

  wp_send_json_success([
    'count'   => count($results),
    'dryrun'  => $dryrun,
    'results' => $results,
    'batch_elapsed_ms' => round((microtime(true) - $batch_start) * 1000),
  ]);
});
