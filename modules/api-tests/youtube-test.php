<?php
/**
 * AJAX: Test YouTube API key + Channel ID
 * Action: myls_test_youtube_api
 * Nonce:  myls_api_tab_ajax  (passed as "nonce" in POST)
 */
if ( ! defined('ABSPATH') ) exit;

add_action('wp_ajax_myls_test_youtube_api', function () {
	// Auth + CSRF
	if ( ! current_user_can('manage_options') ) {
		wp_send_json_error('Insufficient permissions.');
	}
	check_ajax_referer('myls_api_tab_ajax', 'nonce');

	$key     = trim( (string) get_option('myls_youtube_api_key', '') );
	$channel = trim( (string) get_option('myls_youtube_channel_id', '') );

	if ( $key === '' || $channel === '' ) {
		$msg = 'Missing YouTube API key or Channel ID.';
		update_option('myls_youtube_test_result', $msg, false);
		wp_send_json_error($msg);
	}

	// Hit Channels.list to resolve the uploads playlist (good sanity check)
	$url  = add_query_arg([
		'part' => 'contentDetails',
		'id'   => $channel,
		'key'  => $key,
	], 'https://www.googleapis.com/youtube/v3/channels');

	$resp = wp_remote_get($url, ['timeout' => 20]);

	// Network/transport error
	if ( is_wp_error($resp) ) {
		$msg = 'Request error: ' . $resp->get_error_message();
		update_option('myls_youtube_test_result', $msg, false);
		wp_send_json_error($msg);
	}

	$code = wp_remote_retrieve_response_code($resp);
	$body = wp_remote_retrieve_body($resp);
	$data = json_decode($body, true);

	// Success path: uploads playlist present
	if ( $code === 200 && is_array($data) && ! empty($data['items'][0]['contentDetails']['relatedPlaylists']['uploads']) ) {
		$uploads = $data['items'][0]['contentDetails']['relatedPlaylists']['uploads'];
		$msg = 'OK (uploads playlist: ' . sanitize_text_field($uploads) . ')';
		update_option('myls_youtube_test_result', $msg, false);
		wp_send_json_success($msg);
	}

	// Try to surface a helpful API error
	$api_msg = '';
	if ( isset($data['error']['message']) ) {
		$api_msg = (string) $data['error']['message'];
	} elseif ( isset($data['error']['errors'][0]['message']) ) {
		$api_msg = (string) $data['error']['errors'][0]['message'];
	}

	$msg = 'YouTube API error (HTTP ' . intval($code) . '): ' . ( $api_msg !== '' ? $api_msg : 'Unknown response' );
	update_option('myls_youtube_test_result', $msg, false);
	wp_send_json_error($msg);
});
