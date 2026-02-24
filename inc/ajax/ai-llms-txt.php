<?php
/**
 * AJAX Handler: AI llms.txt Generation
 * File: inc/ajax/ai-llms-txt.php
 *
 * Handles:
 *  - myls_ai_llms_txt_get_cities:  Load unique cities from service_area posts
 *  - myls_ai_llms_txt_generate:    Generate city-specific llms.txt via AI
 *
 * @since 6.3.0.7
 */

if ( ! defined('ABSPATH') ) exit;

/* =========================================================================
 * Helper: Get unique cities from service_area posts
 * ========================================================================= */

if ( ! function_exists('myls_ai_llms_txt_get_unique_cities') ) {
  /**
   * Scan all published service_area posts and return unique city_state values.
   *
   * @return array [ [ 'city_state' => 'Tampa, FL', 'count' => 5 ], ... ]
   */
  function myls_ai_llms_txt_get_unique_cities() : array {
    global $wpdb;

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT pm.meta_value AS city_state, COUNT(*) AS cnt
         FROM {$wpdb->postmeta} pm
         JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = %s
           AND pm.meta_value != ''
           AND p.post_status = 'publish'
           AND p.post_type   = 'service_area'
         GROUP BY pm.meta_value
         ORDER BY pm.meta_value ASC",
        '_myls_city_state'
      ),
      ARRAY_A
    );

    $out = [];
    foreach ( $rows as $r ) {
      $cs = trim( sanitize_text_field( $r['city_state'] ) );
      if ( $cs === '' ) continue;
      $out[] = [
        'city_state' => $cs,
        'count'      => (int) $r['cnt'],
      ];
    }
    return $out;
  }
}

/* =========================================================================
 * Helper: Collect city-specific services + FAQs
 * ========================================================================= */

if ( ! function_exists('myls_ai_llms_txt_city_data') ) {
  /**
   * For a given city_state value, gather:
   *  - Service titles (from service_area posts with that city_state)
   *  - FAQs (from those same posts)
   *  - Page URLs for the "Key Local Pages" section
   *
   * @param string $city_state  e.g. "Tampa, FL"
   * @return array
   */
  function myls_ai_llms_txt_city_data( string $city_state ) : array {
    global $wpdb;

    // Find all service_area post IDs with this city_state
    $post_ids = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT pm.post_id
         FROM {$wpdb->postmeta} pm
         JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key   = %s
           AND pm.meta_value = %s
           AND p.post_status = 'publish'
           AND p.post_type   = 'service_area'
         ORDER BY p.post_title ASC",
        '_myls_city_state',
        $city_state
      )
    );

    $services = [];
    $faqs     = [];
    $pages    = [];

    foreach ( $post_ids as $pid ) {
      $pid   = (int) $pid;
      $title = get_the_title( $pid );
      $url   = get_permalink( $pid );

      $services[] = $title;
      $pages[]    = [ 'title' => $title, 'url' => $url ];

      // Collect FAQs from this post
      $items = get_post_meta( $pid, '_myls_faq_items', true );
      if ( is_array($items) ) {
        foreach ( $items as $item ) {
          if ( ! is_array($item) ) continue;
          $q = trim( (string) ( $item['q'] ?? '' ) );
          $a = trim( wp_strip_all_tags( (string) ( $item['a'] ?? '' ) ) );
          if ( $q !== '' && $a !== '' ) {
            $faqs[] = [ 'q' => $q, 'a' => $a ];
          }
        }
      }
    }

    // Dedupe FAQs by normalized question
    $seen     = [];
    $uniq_faqs = [];
    foreach ( $faqs as $faq ) {
      $key = strtolower( preg_replace('/\s+/', ' ', $faq['q']) );
      if ( isset($seen[$key]) ) continue;
      $seen[$key] = true;
      $uniq_faqs[] = $faq;
    }

    // Cap FAQs at 12 per prompt rules
    $uniq_faqs = array_slice( $uniq_faqs, 0, 12 );

    return [
      'services' => $services,
      'faqs'     => $uniq_faqs,
      'pages'    => $pages,
    ];
  }
}

/* =========================================================================
 * Helper: Parse city_state into components
 * ========================================================================= */

if ( ! function_exists('myls_ai_llms_txt_parse_city_state') ) {
  /**
   * Parse "Tampa, FL" → [ 'city' => 'Tampa', 'state' => 'FL' ]
   * Also handles "Tampa, Florida" and "Tampa FL"
   */
  function myls_ai_llms_txt_parse_city_state( string $cs ) : array {
    $cs = trim( $cs );

    // Try "City, State"
    if ( strpos($cs, ',') !== false ) {
      $parts = array_map('trim', explode(',', $cs, 2));
      return [ 'city' => $parts[0], 'state' => $parts[1] ?? '' ];
    }

    // Try "City State" (last word is state)
    $words = explode(' ', $cs);
    if ( count($words) >= 2 ) {
      $state = array_pop($words);
      return [ 'city' => implode(' ', $words), 'state' => $state ];
    }

    return [ 'city' => $cs, 'state' => '' ];
  }
}

/* =========================================================================
 * AJAX: Get cities for dropdown
 * ========================================================================= */

add_action('wp_ajax_myls_ai_llms_txt_get_cities', function() {
  if ( ! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'myls_ai_ops') ) {
    wp_send_json_error(['message' => 'Invalid nonce'], 403);
  }
  if ( ! current_user_can('manage_options') ) {
    wp_send_json_error(['message' => 'Permission denied'], 403);
  }

  $cities = myls_ai_llms_txt_get_unique_cities();
  wp_send_json_success( $cities );
});

/* =========================================================================
 * AJAX: Generate llms.txt for a city
 * ========================================================================= */

add_action('wp_ajax_myls_ai_llms_txt_generate', function() {
  if ( ! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'myls_ai_ops') ) {
    wp_send_json_error(['message' => 'Invalid nonce'], 403);
  }
  if ( ! current_user_can('manage_options') ) {
    wp_send_json_error(['message' => 'Permission denied'], 403);
  }

  $city_state = isset($_POST['city_state']) ? sanitize_text_field( wp_unslash($_POST['city_state']) ) : '';
  if ( $city_state === '' ) {
    wp_send_json_error(['message' => 'No city selected.']);
  }

  $county = isset($_POST['county']) ? sanitize_text_field( wp_unslash($_POST['county']) ) : '';

  // -----------------------------------------------------------------
  // Gather business data
  // -----------------------------------------------------------------
  $biz_name = trim( (string) get_option('myls_org_name', get_bloginfo('name')) );
  $biz_url  = trim( (string) get_option('myls_org_url', home_url('/')) );
  $biz_tel  = trim( (string) get_option('myls_org_tel', '') );

  // Fallback to LocalBusiness location #1
  if ( ! $biz_tel ) {
    $locs = function_exists('myls_lb_get_locations_cached')
      ? (array) myls_lb_get_locations_cached()
      : (array) get_option('myls_lb_locations', []);
    $loc0 = ( ! empty($locs) && is_array($locs[0] ?? null) ) ? (array) $locs[0] : [];
    $biz_tel = trim( (string) ($loc0['phone'] ?? '') );
  }

  // Parse city / state
  $parsed = myls_ai_llms_txt_parse_city_state( $city_state );
  $city   = $parsed['city'];
  $state  = $parsed['state'];

  // -----------------------------------------------------------------
  // Gather city-specific data
  // -----------------------------------------------------------------
  $city_data = myls_ai_llms_txt_city_data( $city_state );

  // Build service list string
  $svc_list = '';
  if ( ! empty($city_data['services']) ) {
    foreach ( $city_data['services'] as $svc ) {
      $svc_list .= '- ' . $svc . "\n";
    }
  } else {
    $svc_list = '(No services found for this city)';
  }

  // Build FAQ list string
  $faq_list = '';
  if ( ! empty($city_data['faqs']) ) {
    foreach ( $city_data['faqs'] as $faq ) {
      $faq_list .= 'Q: ' . $faq['q'] . "\n";
      $faq_list .= 'A: ' . $faq['a'] . "\n\n";
    }
  } else {
    $faq_list = '(No FAQs found for this city)';
  }

  // -----------------------------------------------------------------
  // Load and populate prompt template
  // -----------------------------------------------------------------
  $prompt_template = get_option('myls_ai_llms_txt_prompt_template', '');
  if ( ! is_string($prompt_template) || trim($prompt_template) === '' ) {
    $prompt_template = myls_get_default_prompt('llms-txt');
  }

  if ( trim($prompt_template) === '' ) {
    wp_send_json_error(['message' => 'Prompt template is empty. Please save a template first.']);
  }

  // Variable replacements
  $replacements = [
    '{{BUSINESS_NAME}}'    => $biz_name,
    '{{WEBSITE_URL}}'      => $biz_url,
    '{{PHONE}}'            => $biz_tel ?: '(not set)',
    '{{CITY_NAME}}'        => $city,
    '{{STATE}}'            => $state,
    '{{COUNTY}}'           => $county ?: '(not specified)',
    '{{CITY_SERVICE_LIST}}'=> trim($svc_list),
    '{{CITY_FAQ_LIST}}'    => trim($faq_list),
    '{{DATE}}'             => gmdate('Y-m-d'),
  ];

  $prompt = str_replace(
    array_keys($replacements),
    array_values($replacements),
    $prompt_template
  );

  // -----------------------------------------------------------------
  // Call AI
  // -----------------------------------------------------------------
  $model  = isset($_POST['model']) && is_string($_POST['model']) ? trim($_POST['model']) : '';
  $tokens = isset($_POST['max_tokens']) ? (int) $_POST['max_tokens'] : 4000;
  $temp   = isset($_POST['temperature']) ? (float) $_POST['temperature'] : 0.7;

  $start = microtime(true);

  $result = myls_ai_generate_text($prompt, [
    'model'       => $model ?: null,
    'max_tokens'  => max(1000, min(8000, $tokens)),
    'temperature' => max(0.3, min(1.0, $temp)),
    'context'     => 'llms_txt_generate',
  ]);

  $elapsed = round(microtime(true) - $start, 1);

  if ( $result === '' ) {
    $last = function_exists('myls_ai_last_call') ? myls_ai_last_call() : [];
    wp_send_json_error([
      'message'  => 'AI returned empty output. Check your API key and balance.',
      'provider' => $last['provider'] ?? 'unknown',
      'model'    => $last['resolved_model'] ?? 'unknown',
    ]);
  }

  // Clean: strip code fences if AI wraps in ```markdown ... ```
  $result = preg_replace('/^```(?:markdown|md)?\s*\n/i', '', $result);
  $result = preg_replace('/\n```\s*$/', '', $result);

  $last  = function_exists('myls_ai_last_call') ? myls_ai_last_call() : [];
  $words = str_word_count(strip_tags($result));

  wp_send_json_success([
    'content'    => $result,
    'city_state' => $city_state,
    'words'      => $words,
    'time'       => $elapsed,
    'model'      => $last['resolved_model'] ?? 'unknown',
    'provider'   => $last['provider'] ?? 'unknown',
  ]);
});
