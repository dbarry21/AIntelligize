<?php
/**
 * AI AJAX endpoints
 * Path: admin/ajax/ai-endpoints.php
 *
 * Provides:
 *  - myls_ai_posts_by_type   → returns [{id,title}, ...]
 *  - myls_ai_about_generate  → generates/saves about_the_area
 */

if ( ! defined('ABSPATH') ) exit;

/** Small helper */
if ( ! function_exists('myls_json_error') ) {
	function myls_json_error($msg, $extra = []) {
		wp_send_json_error( array_merge(['message'=>$msg], $extra) );
	}
}

/**
 * Posts-by-type for the multiselect
 * Accepts: pt (post type), nonce (myls_ai_ops)
 * Returns: { success:true, data:{ pt, count, posts:[{id,title},...] } }
 */
add_action('wp_ajax_myls_ai_posts_by_type', function(){
	if ( ! current_user_can('manage_options') ) {
		wp_send_json_error(['message'=>'insufficient_caps']);
	}
	check_ajax_referer('myls_ai_ops','nonce');

	$pt = sanitize_key($_POST['pt'] ?? 'page');

	$args = [
		'post_type'       => $pt,
		'post_status'     => ['publish','draft','pending','future','private','inherit'],
		'posts_per_page'  => 500,
		'orderby'         => 'title',
		'order'           => 'ASC',
		'fields'          => 'ids',
		'suppress_filters'=> true,
	];
	$ids = get_posts($args);

	$out = [];
	foreach ($ids as $pid) {
		$out[] = ['id' => (int)$pid, 'title' => (get_the_title($pid) ?: '(no title)')];
	}

	// Optional: log to error_log for quick verification
	if ( function_exists('error_log') ) {
		error_log('[MYLS AI] posts_by_type pt=' . $pt . ' count=' . count($out));
	}

	wp_send_json_success([
		'pt'    => $pt,
		'count' => count($out),
		'posts' => $out,
	]);
});

/**
 * Generate “About the Area” for a single post_id
 * Accepts: post_id, skip_filled (1/0), nonce
 * Returns: standard success payload (used by your JS)
 */
add_action('wp_ajax_myls_ai_about_generate', function(){
	if ( ! current_user_can('manage_options') ) myls_json_error('Insufficient permissions.');
	check_ajax_referer('myls_ai_ops','nonce');

	$post_id     = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
	$skip_filled = !empty($_POST['skip_filled']);

	if ( $post_id <= 0 ) myls_json_error('Missing post_id.');
	$post = get_post($post_id);
	if ( ! $post ) myls_json_error('Post not found.');

	// Pull city_state from ACF or meta
	$city_state = '';
	if ( function_exists('get_field') ) $city_state = (string) get_field('city_state', $post_id);
	if ( $city_state === '' ) $city_state = (string) get_post_meta($post_id, 'city_state', true);

	// Skip if already filled
	$existing = (string) get_post_meta($post_id, 'about_the_area', true);
	if ( $skip_filled && trim( wp_strip_all_tags($existing) ) !== '' ) {
		wp_send_json_success([
			'post_id'    => $post_id,
			'title'      => get_the_title($post_id),
			'city_state' => $city_state,
			'skipped'    => true,
			'reason'     => 'about_the_area is already populated.',
			'saved'      => false,
			'debug'      => 'skip_if_filled=on; len=' . strlen($existing),
		]);
	}

	// Load template + params
	$template    = (string) get_option('myls_ai_about_prompt_template', '');
	$tokens      = (int) get_option('myls_ai_about_tokens', 600);
	$temperature = (float) get_option('myls_ai_about_temperature', 0.7);

	// Generate via helper
	if ( ! function_exists('myls_ai_generate_about_area_content') ) {
		require_once dirname(__DIR__,2) . '/modules/ai.php';
	}
	$result = myls_ai_generate_about_area_content($post_id, $city_state, $template, $tokens, $temperature);

	if ( is_wp_error($result) ) {
		myls_json_error('AI generation failed', ['error'=>$result->get_error_message()]);
	}

	$html  = (string) ($result['html'] ?? '');
	$debug = (string) ($result['debug'] ?? '');

	$saved = false;
	if ( $html !== '' ) {
		$saved = (bool) update_post_meta($post_id, 'about_the_area', wp_kses_post($html));
	}

	wp_send_json_success([
		'post_id'    => $post_id,
		'title'      => get_the_title($post_id),
		'city_state' => $city_state,
		'skipped'    => false,
		'saved'      => $saved,
		'debug'      => $debug,
	]);
});
