<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * AIntelligize – Meta History Endpoints
 * Satisfies assets/js/myls-meta.js actions:
 *   - myls_meta_history_posts
 *   - myls_meta_history_get
 *   - myls_meta_history_clear
 *
 * Uses the custom table created by MYLS_Meta_History_Logger.
 */

if ( ! function_exists('myls_meta_history_table') ) {
  function myls_meta_history_table() {
    global $wpdb;
    return $wpdb->prefix . 'myls_meta_history';
  }
}

/** Common nonce/cap check */
function myls_meta_history_guard() {
  if ( empty($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'myls_meta_ops') ) {
    wp_send_json_error(['message'=>'Bad nonce'], 400);
  }
  if ( ! current_user_can('edit_posts') ) {
    wp_send_json_error(['message'=>'Unauthorized'], 403);
  }
}

/**
 * 1) Return posts for a given post type (for left-side dropdown)
 *    Response: { success:true, data:{ items:[{id, title}] } }
 */
add_action('wp_ajax_myls_meta_history_posts', function(){
  myls_meta_history_guard();

  $pt = sanitize_key($_POST['post_type'] ?? 'post');

  // Pull a lot, but not unlimited
  $q = new WP_Query([
    'post_type'      => $pt,
    'post_status'    => ['publish','draft','pending','private'],
    'posts_per_page' => 500,
    'orderby'        => 'date',
    'order'          => 'DESC',
    'no_found_rows'  => true,
    'fields'         => 'ids',
  ]);

  $items = [];
  foreach ( $q->posts as $pid ) {
    $items[] = [
      'id'    => (int) $pid,
      'title' => get_the_title($pid),
    ];
  }

  wp_send_json_success(['items' => $items]);
});

/**
 * 2) Return history rows for a post
 *    Response: { success:true, data:{ log:[{ts,user,key,old,new}, ...] } }
 */
add_action('wp_ajax_myls_meta_history_get', function(){
  myls_meta_history_guard();

  global $wpdb;
  $post_id = max(0, intval($_POST['post_id'] ?? 0));
  if ( ! $post_id ) wp_send_json_success(['log'=>[]]);

  $table = myls_meta_history_table();

  // Fetch most recent first
  $rows = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT post_id, field, old_value, new_value, user_id, created_at
       FROM {$table}
       WHERE post_id = %d
       ORDER BY created_at DESC, id DESC
       LIMIT 500",
      $post_id
    ),
    ARRAY_A
  );

  // Map our internal "field" to the keys your JS expects
  // title       -> _yoast_wpseo_title
  // description -> _yoast_wpseo_metadesc
  // focus_keyword -> _yoast_wpseo_focuskw
  $map_to_key = [
    'title'         => '_yoast_wpseo_title',
    'description'   => '_yoast_wpseo_metadesc',
    'focus_keyword' => '_yoast_wpseo_focuskw',
    // if you also log 'tags' etc., they will pass through as-is below
  ];

  $log = [];
  foreach ( (array) $rows as $r ) {
    $key   = $map_to_key[ $r['field'] ] ?? $r['field']; // pass unknowns (e.g., 'tags') through
    $uname = 'System/Unknown';
    if ( ! empty($r['user_id']) ) {
      $u = get_user_by('id', (int) $r['user_id']);
      if ( $u && ! is_wp_error($u) ) $uname = $u->display_name;
    }

    $log[] = [
      'ts'   => strtotime($r['created_at']) ?: time(),
      'user' => $uname,
      'key'  => $key,
      'old'  => (string) $r['old_value'],
      'new'  => (string) $r['new_value'],
    ];
  }

  wp_send_json_success(['log' => $log]);
});

/**
 * 3) Clear history for a post
 *    Response: { success:true }
 */
add_action('wp_ajax_myls_meta_history_clear', function(){
  myls_meta_history_guard();

  global $wpdb;
  $post_id = max(0, intval($_POST['post_id'] ?? 0));
  if ( ! $post_id ) wp_send_json_error(['message'=>'Missing post_id'], 400);

  $table = myls_meta_history_table();
  $wpdb->query( $wpdb->prepare("DELETE FROM {$table} WHERE post_id = %d", $post_id) );

  wp_send_json_success();
});
