<?php
/**
 * Module: Meta History (Yoast title/description)
 * Path: modules/meta/meta-history.php
 *
 * What it does:
 * - Hooks post meta updates for _yoast_wpseo_title and _yoast_wpseo_metadesc
 * - Appends a compact log entry on each change to post meta key "myls_meta_history"
 * - Exposes AJAX endpoints to:
 *     • get posts by type          (myls_meta_history_posts)
 *     • get history for a post     (myls_meta_history_get)
 *     • clear history for a post   (myls_meta_history_clear)  [optional]
 *
 * Storage shape (post meta: myls_meta_history): array of associative arrays
 * [
 *   [
 *     'ts'   => 1738186452,         // unix timestamp
 *     'uid'  => 1,                  // user id who triggered change (0 if none)
 *     'key'  => '_yoast_wpseo_title' | '_yoast_wpseo_metadesc',
 *     'old'  => 'Old value',
 *     'new'  => 'New value',
 *   ],
 *   ...
 * ]
 */

if ( ! defined('ABSPATH') ) exit;

add_action('plugins_loaded', function(){
	// Register hooks only in admin (logger still useful everywhere, but we want to be safe)
	add_action('updated_post_meta', 'myls_mh_capture_update', 10, 4);
	add_action('added_post_meta',   'myls_mh_capture_add',    10, 4);

	// AJAX endpoints (admin only)
	if ( is_admin() ) {
		add_action('wp_ajax_myls_meta_history_posts',  'myls_mh_ajax_posts_by_type');
		add_action('wp_ajax_myls_meta_history_get',    'myls_mh_ajax_get_history');
		add_action('wp_ajax_myls_meta_history_clear',  'myls_mh_ajax_clear_history'); // optional button in UI
	}
});

/**
 * Capture UPDATEs to Yoast title/description.
 */
function myls_mh_capture_update( $meta_id, $post_id, $meta_key, $meta_value ) {
	if ( $meta_key !== '_yoast_wpseo_title' && $meta_key !== '_yoast_wpseo_metadesc' ) return;

	$old = get_metadata( 'post', $post_id, $meta_key, true );
	// $meta_value is the NEW value post-update when this hook runs, so grab pre-update with get_metadata.
	// However, in 'updated_post_meta', WP passes $meta_value as the NEW value, so swap:
	$new = $meta_value;

	myls_mh_append_log( $post_id, $meta_key, $old, $new );
}

/**
 * Capture ADDs (first time) to Yoast title/description.
 */
function myls_mh_capture_add( $meta_id, $post_id, $meta_key, $meta_value ) {
	if ( $meta_key !== '_yoast_wpseo_title' && $meta_key !== '_yoast_wpseo_metadesc' ) return;
	$old = '';    // no previous value when adding
	$new = $meta_value;
	myls_mh_append_log( $post_id, $meta_key, $old, $new );
}

/**
 * Append a history record on the post.
 */
function myls_mh_append_log( $post_id, $key, $old, $new ) {
	// No-op if nothing changed (avoid noise)
	if ( wp_unslash((string)$old) === wp_unslash((string)$new) ) return;

	$uid  = get_current_user_id();
	$log  = get_post_meta( $post_id, 'myls_meta_history', true );
	$log  = is_array($log) ? $log : [];

	$log[] = [
		'ts'  => time(),
		'uid' => $uid ? (int)$uid : 0,
		'key' => (string)$key,
		'old' => (string)$old,
		'new' => (string)$new,
	];

	// Keep last 500 entries per post (prevent unbounded growth)
	if ( count($log) > 500 ) {
		$log = array_slice($log, -500);
	}
	update_post_meta( $post_id, 'myls_meta_history', $log );
}

/* =========================
 * AJAX: Posts by type
 * ========================= */
function myls_mh_ajax_posts_by_type() {
	check_ajax_referer('myls_meta_ops', 'nonce');
	if ( ! current_user_can('edit_posts') ) wp_send_json_error('Unauthorized');

	$pt = sanitize_key( $_POST['post_type'] ?? '' );
	if ( ! $pt || ! post_type_exists($pt) ) wp_send_json_error('Invalid post type');

	$posts = get_posts([
		'post_type'      => $pt,
		'posts_per_page' => 500, // sane cap for dropdown
		'post_status'    => ['publish','draft','future','private','pending'],
		'orderby'        => 'title',
		'order'          => 'ASC',
		'suppress_filters' => true,
	]);

	$out = array_map(function($p){
		return ['id' => (int)$p->ID, 'title' => get_the_title($p->ID)];
	}, $posts);

	wp_send_json_success(['items' => $out]);
}

/* =========================
 * AJAX: Get history for a post
 * ========================= */
function myls_mh_ajax_get_history() {
	check_ajax_referer('myls_meta_ops', 'nonce');
	if ( ! current_user_can('edit_posts') ) wp_send_json_error('Unauthorized');

	$post_id = intval( $_POST['post_id'] ?? 0 );
	if ( ! $post_id || ! get_post($post_id) ) wp_send_json_error('Invalid post ID');

	$log = get_post_meta( $post_id, 'myls_meta_history', true );
	$log = is_array($log) ? $log : [];

	// Include friendly user display name
	foreach ( $log as &$row ) {
		$u = $row['uid'] ? get_user_by('id', (int)$row['uid']) : null;
		$row['user'] = $u ? $u->display_name : 'System/Unknown';
	}
	unset($row);

	// reverse chronological
	usort($log, function($a, $b){ return ($b['ts'] <=> $a['ts']); });

	wp_send_json_success(['log' => $log]);
}

/* =========================
 * AJAX: Clear history (optional)
 * ========================= */
function myls_mh_ajax_clear_history() {
	check_ajax_referer('myls_meta_ops', 'nonce');
	if ( ! current_user_can('delete_posts') ) wp_send_json_error('Unauthorized');

	$post_id = intval( $_POST['post_id'] ?? 0 );
	if ( ! $post_id || ! get_post($post_id) ) wp_send_json_error('Invalid post ID');

	delete_post_meta( $post_id, 'myls_meta_history' );
	wp_send_json_success(['ok' => true]);
}
