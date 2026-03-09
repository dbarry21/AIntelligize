<?php
/**
 * AJAX: Fetch YouTube Transcript
 *
 * Endpoint: wp_ajax_myls_fetch_youtube_transcript
 * Accepts: video_id (YouTube video ID)
 * Returns: JSON { success: true, data: { transcript: "..." } }
 *
 * Uses the public timedtext API (no auth required).
 *
 * @since 7.8.77
 */

if ( ! defined('ABSPATH') ) exit;

add_action( 'wp_ajax_myls_fetch_youtube_transcript', function () {

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
	}

	// Nonce check
	if ( ! check_ajax_referer( 'myls_fetch_transcript', '_nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
	}

	$video_id = sanitize_text_field( wp_unslash( $_POST['video_id'] ?? '' ) );
	if ( $video_id === '' || ! preg_match( '/^[a-zA-Z0-9_\-]{11}$/', $video_id ) ) {
		wp_send_json_error( [ 'message' => 'Invalid video ID.' ] );
	}

	// Fetch available caption tracks
	$list_url  = 'https://video.google.com/timedtext?type=list&v=' . rawurlencode( $video_id );
	$list_resp = wp_remote_get( $list_url, [ 'timeout' => 10 ] );

	if ( is_wp_error( $list_resp ) || (int) wp_remote_retrieve_response_code( $list_resp ) !== 200 ) {
		wp_send_json_error( [ 'message' => 'No auto-captions available — enter transcript manually.' ] );
	}

	$list_body = wp_remote_retrieve_body( $list_resp );
	$xml       = @simplexml_load_string( $list_body );
	if ( ! $xml || ! isset( $xml->track[0]['lang_code'] ) ) {
		wp_send_json_error( [ 'message' => 'No auto-captions available — enter transcript manually.' ] );
	}

	// Fetch the first available track
	$lang     = (string) $xml->track[0]['lang_code'];
	$text_url = 'https://video.google.com/timedtext?lang=' . rawurlencode( $lang ) . '&v=' . rawurlencode( $video_id );
	$text_resp = wp_remote_get( $text_url, [ 'timeout' => 10 ] );

	if ( is_wp_error( $text_resp ) || (int) wp_remote_retrieve_response_code( $text_resp ) !== 200 ) {
		wp_send_json_error( [ 'message' => 'Failed to fetch caption track.' ] );
	}

	$text_xml = @simplexml_load_string( wp_remote_retrieve_body( $text_resp ) );
	if ( ! $text_xml ) {
		wp_send_json_error( [ 'message' => 'Failed to parse caption data.' ] );
	}

	// Strip tags, collapse whitespace into clean plain text
	$lines = [];
	foreach ( $text_xml->text as $text ) {
		$line = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$line = wp_strip_all_tags( $line );
		$line = trim( $line );
		if ( $line !== '' ) {
			$lines[] = $line;
		}
	}

	if ( empty( $lines ) ) {
		wp_send_json_error( [ 'message' => 'Caption track was empty — enter transcript manually.' ] );
	}

	$transcript = implode( ' ', $lines );
	// Collapse multiple spaces
	$transcript = preg_replace( '/\s+/', ' ', $transcript );

	wp_send_json_success( [ 'transcript' => $transcript ] );
} );
