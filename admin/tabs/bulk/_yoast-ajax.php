<?php
/**
 * AIntelligize – Bulk AJAX: Yoast Operations
 * File: inc/ajax/_yoast-ajax.php
 *
 * Endpoints:
 *  - wp_ajax_myls_set_index_follow
 *  - wp_ajax_myls_set_noindex_nofollow
 *  - wp_ajax_myls_reset_canonical
 *  - wp_ajax_myls_clear_canonical
 *  - wp_ajax_myls_copy_canonical_from_source
 *
 * Notes:
 *  - Uses Yoast meta keys:
 *      _yoast_wpseo_meta-robots-noindex (1 = noindex)
 *      _yoast_wpseo_meta-robots-nofollow (1 = nofollow)
 *      _yoast_wpseo_canonical (string URL)
 */

if ( ! defined('ABSPATH') ) exit;

/* -------------------------------------------------------------------------
 * Nonce helper (Bulk tab)
 * ------------------------------------------------------------------------- */
if ( ! function_exists('myls_bulk_check_nonce') ) {
  function myls_bulk_check_nonce( string $action = 'myls_bulk_ops' ) : void {
    $nonce = isset($_POST['nonce']) ? (string) $_POST['nonce'] : (string) ( $_REQUEST['_ajax_nonce'] ?? '' );
    if ( ! wp_verify_nonce( $nonce, $action ) ) {
      wp_send_json_error(['status'=>'error','message'=>'bad_nonce'], 403);
    }
  }
}

/* -------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------- */
if ( ! function_exists('myls_bulk_parse_ids') ) {
  /**
   * Accepts:
   *  - target_ids[] (array)
   *  - target_ids (comma string)
   *  - targets[] (array)
   *  - targets (comma string)
   */
  function myls_bulk_parse_ids( $raw ) : array {
    if ( is_string($raw) ) {
      $raw = trim($raw);
      if ($raw === '') return [];
      $parts = preg_split('/\s*,\s*/', $raw);
      $ids = array_map('intval', $parts);
      $ids = array_values(array_filter($ids, fn($v) => $v > 0));
      return array_values(array_unique($ids));
    }

    if ( is_array($raw) ) {
      $ids = array_map('intval', $raw);
      $ids = array_values(array_filter($ids, fn($v) => $v > 0));
      return array_values(array_unique($ids));
    }

    return [];
  }
}

if ( ! function_exists('myls_bulk_require_edit_caps') ) {
  function myls_bulk_require_edit_caps( int $post_id ) : void {
    if ( $post_id <= 0 || get_post_status($post_id) === false ) {
      wp_send_json_error(['status'=>'error','message'=>'bad_post','post_id'=>$post_id], 400);
    }
    if ( ! current_user_can('edit_post', $post_id) ) {
      wp_send_json_error(['status'=>'error','message'=>'cap_denied','post_id'=>$post_id], 403);
    }
  }
}

if ( ! function_exists('myls_bulk_get_yoast_canonical') ) {
  function myls_bulk_get_yoast_canonical( int $post_id ) : string {
    $v = (string) get_post_meta($post_id, '_yoast_wpseo_canonical', true);
    $v = trim($v);
    if ($v !== '') return $v;
    return (string) get_permalink($post_id);
  }
}

if ( ! function_exists('myls_bulk_set_result') ) {
  function myls_bulk_set_result( string $message, array $extra = [] ) : void {
    wp_send_json_success(array_merge([
      'status'  => 'ok',
      'message' => $message,
    ], $extra));
  }
}

/* -------------------------------------------------------------------------
 * ACTION: Set Index, Follow (Yoast)
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_myls_set_index_follow', function(){
  myls_bulk_check_nonce();

  $targets = myls_bulk_parse_ids($_POST['target_ids'] ?? ($_POST['targets'] ?? []));
  if ( empty($targets) ) {
    wp_send_json_error(['status'=>'error','message'=>'no_targets'], 400);
  }

  $updated = 0;
  $failed  = [];

  foreach ($targets as $post_id) {
    $post_id = (int) $post_id;
    try {
      myls_bulk_require_edit_caps($post_id);

      // Index/follow = remove restriction or set to 0.
      // We do both: set '0' and also delete if you prefer “clean” meta.
      update_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', '0');
      update_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', '0');

      $updated++;
    } catch (Throwable $e) {
      $failed[] = ['post_id'=>$post_id,'error'=>$e->getMessage()];
    }
  }

  myls_bulk_set_result(
    'Updated robots to Index, Follow for selected targets.',
    ['updated'=>$updated,'failed'=>$failed]
  );
});

/* -------------------------------------------------------------------------
 * ACTION: Set Noindex, Nofollow (Yoast)
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_myls_set_noindex_nofollow', function(){
  myls_bulk_check_nonce();

  $targets = myls_bulk_parse_ids($_POST['target_ids'] ?? ($_POST['targets'] ?? []));
  if ( empty($targets) ) {
    wp_send_json_error(['status'=>'error','message'=>'no_targets'], 400);
  }

  $updated = 0;
  $failed  = [];

  foreach ($targets as $post_id) {
    $post_id = (int) $post_id;
    try {
      myls_bulk_require_edit_caps($post_id);

      // Yoast uses '1' to represent enabled noindex/nofollow on the post meta. :contentReference[oaicite:1]{index=1}
      update_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', '1');
      update_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', '1');

      $updated++;
    } catch (Throwable $e) {
      $failed[] = ['post_id'=>$post_id,'error'=>$e->getMessage()];
    }
  }

  myls_bulk_set_result(
    'Updated robots to Noindex, Nofollow for selected targets.',
    ['updated'=>$updated,'failed'=>$failed]
  );
});

/* -------------------------------------------------------------------------
 * ACTION: Reset Canonical (Yoast)
 * - Meaning: remove custom canonical so Yoast falls back to default permalink
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_myls_reset_canonical', function(){
  myls_bulk_check_nonce();

  $targets = myls_bulk_parse_ids($_POST['target_ids'] ?? ($_POST['targets'] ?? []));
  if ( empty($targets) ) {
    wp_send_json_error(['status'=>'error','message'=>'no_targets'], 400);
  }

  $updated = 0;
  $failed  = [];

  foreach ($targets as $post_id) {
    $post_id = (int) $post_id;
    try {
      myls_bulk_require_edit_caps($post_id);

      // Reset = delete custom canonical override
      delete_post_meta($post_id, '_yoast_wpseo_canonical');

      $updated++;
    } catch (Throwable $e) {
      $failed[] = ['post_id'=>$post_id,'error'=>$e->getMessage()];
    }
  }

  myls_bulk_set_result(
    'Reset canonical (deleted Yoast canonical override) for selected targets.',
    ['updated'=>$updated,'failed'=>$failed]
  );
});

/* -------------------------------------------------------------------------
 * ACTION: Clear Canonical (Yoast)
 * - Meaning: explicitly set canonical meta to empty string (not delete)
 * - Useful if you want the field visually cleared in the Yoast UI.
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_myls_clear_canonical', function(){
  myls_bulk_check_nonce();

  $targets = myls_bulk_parse_ids($_POST['target_ids'] ?? ($_POST['targets'] ?? []));
  if ( empty($targets) ) {
    wp_send_json_error(['status'=>'error','message'=>'no_targets'], 400);
  }

  $updated = 0;
  $failed  = [];

  foreach ($targets as $post_id) {
    $post_id = (int) $post_id;
    try {
      myls_bulk_require_edit_caps($post_id);

      update_post_meta($post_id, '_yoast_wpseo_canonical', '');

      $updated++;
    } catch (Throwable $e) {
      $failed[] = ['post_id'=>$post_id,'error'=>$e->getMessage()];
    }
  }

  myls_bulk_set_result(
    'Cleared canonical (set Yoast canonical to empty) for selected targets.',
    ['updated'=>$updated,'failed'=>$failed]
  );
});

/* -------------------------------------------------------------------------
 * ACTION: Copy Canonical from Source → Targets
 * - If source has no custom canonical, we fall back to source permalink.
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_myls_copy_canonical_from_source', function(){
  myls_bulk_check_nonce();

  $source_id = (int) ($_POST['source_id'] ?? ($_POST['source_post_id'] ?? 0));
  if ( $source_id <= 0 ) {
    wp_send_json_error(['status'=>'error','message'=>'missing_source'], 400);
  }
  myls_bulk_require_edit_caps($source_id);

  $targets = myls_bulk_parse_ids($_POST['target_ids'] ?? ($_POST['targets'] ?? []));
  if ( empty($targets) ) {
    wp_send_json_error(['status'=>'error','message'=>'no_targets'], 400);
  }

  $canonical = myls_bulk_get_yoast_canonical($source_id);
  $canonical = esc_url_raw($canonical);

  if ( $canonical === '' ) {
    wp_send_json_error(['status'=>'error','message'=>'bad_source_canonical'], 400);
  }

  $updated = 0;
  $failed  = [];

  foreach ($targets as $post_id) {
    $post_id = (int) $post_id;
    try {
      myls_bulk_require_edit_caps($post_id);

      update_post_meta($post_id, '_yoast_wpseo_canonical', $canonical);

      $updated++;
    } catch (Throwable $e) {
      $failed[] = ['post_id'=>$post_id,'error'=>$e->getMessage()];
    }
  }

  myls_bulk_set_result(
    'Copied canonical from source to selected targets.',
    ['source_id'=>$source_id,'canonical'=>$canonical,'updated'=>$updated,'failed'=>$failed]
  );
});
