<?php
/**
 * AJAX: Fetch YouTube Transcript
 *
 * Endpoint: wp_ajax_myls_fetch_youtube_transcript
 * Accepts: video_id (YouTube video ID)
 * Returns: JSON { success: true, data: { transcript: "..." } }
 *
 * Extracts caption track URLs from the YouTube video page's embedded
 * player config (ytInitialPlayerResponse). Falls back to the legacy
 * timedtext list API if the page-scrape approach fails.
 *
 * @since 7.8.77
 * @updated 7.8.80 — switched to page-scrape approach for reliability
 */

if ( ! defined('ABSPATH') ) exit;

add_action( 'wp_ajax_myls_fetch_youtube_transcript', function () {

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
	}

	if ( ! check_ajax_referer( 'myls_fetch_transcript', '_nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
	}

	$video_id = sanitize_text_field( wp_unslash( $_POST['video_id'] ?? '' ) );
	if ( $video_id === '' || ! preg_match( '/^[a-zA-Z0-9_\-]{11}$/', $video_id ) ) {
		wp_send_json_error( [ 'message' => 'Invalid video ID.' ] );
	}

	// ── Method 1: Scrape caption tracks from the YouTube video page ──
	$caption_url = _myls_get_caption_url_from_page( $video_id );

	// ── Method 2: Fallback to legacy timedtext list API ──
	if ( ! $caption_url ) {
		$caption_url = _myls_get_caption_url_from_timedtext( $video_id );
	}

	if ( ! $caption_url ) {
		wp_send_json_error( [ 'message' => 'No captions found for this video — enter transcript manually.' ] );
	}

	// ── Fetch and parse the caption XML ──
	$transcript = _myls_fetch_caption_xml( $caption_url );

	if ( $transcript === null ) {
		wp_send_json_error( [ 'message' => 'Failed to parse caption data — enter transcript manually.' ] );
	}

	wp_send_json_success( [ 'transcript' => $transcript ] );
} );

/**
 * Extract caption track URL from YouTube video page HTML.
 *
 * Fetches the watch page, finds ytInitialPlayerResponse JSON,
 * and extracts the first English (or any) caption track baseUrl.
 */
function _myls_get_caption_url_from_page( string $video_id ) : ?string {
	$page_url = 'https://www.youtube.com/watch?v=' . rawurlencode( $video_id );
	$response = wp_remote_get( $page_url, [
		'timeout'    => 15,
		'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
		'headers'    => [
			'Accept-Language' => 'en-US,en;q=0.9',
		],
	] );

	if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
		return null;
	}

	$body = wp_remote_retrieve_body( $response );

	// Extract ytInitialPlayerResponse JSON from the page
	if ( ! preg_match( '/ytInitialPlayerResponse\s*=\s*({.+?})\s*;\s*(?:var\s|<\/script)/s', $body, $matches ) ) {
		return null;
	}

	$player = json_decode( $matches[1], true );
	if ( ! is_array( $player ) ) {
		return null;
	}

	$tracks = $player['captions']['playerCaptionsTracklistRenderer']['captionTracks'] ?? [];
	if ( empty( $tracks ) || ! is_array( $tracks ) ) {
		return null;
	}

	// Prefer English tracks, then fall back to first available
	$best = null;
	foreach ( $tracks as $track ) {
		if ( empty( $track['baseUrl'] ) ) continue;

		$lang = $track['languageCode'] ?? '';
		if ( $best === null ) {
			$best = $track['baseUrl'];
		}
		if ( str_starts_with( $lang, 'en' ) ) {
			return $track['baseUrl'];
		}
	}

	return $best;
}

/**
 * Legacy fallback: get caption URL from video.google.com/timedtext list API.
 */
function _myls_get_caption_url_from_timedtext( string $video_id ) : ?string {
	$list_url  = 'https://video.google.com/timedtext?type=list&v=' . rawurlencode( $video_id );
	$list_resp = wp_remote_get( $list_url, [ 'timeout' => 10 ] );

	if ( is_wp_error( $list_resp ) || (int) wp_remote_retrieve_response_code( $list_resp ) !== 200 ) {
		return null;
	}

	$xml = @simplexml_load_string( wp_remote_retrieve_body( $list_resp ) );
	if ( ! $xml || ! isset( $xml->track[0]['lang_code'] ) ) {
		return null;
	}

	$lang = (string) $xml->track[0]['lang_code'];
	return 'https://video.google.com/timedtext?lang=' . rawurlencode( $lang ) . '&v=' . rawurlencode( $video_id );
}

/**
 * Fetch caption XML from a baseUrl and return clean plain text.
 */
function _myls_fetch_caption_xml( string $url ) : ?string {
	// Ensure we get XML format (some URLs default to JSON with fmt=srv3)
	if ( strpos( $url, 'fmt=' ) === false ) {
		$url .= ( strpos( $url, '?' ) !== false ? '&' : '?' ) . 'fmt=srv1';
	}

	$response = wp_remote_get( $url, [
		'timeout'    => 15,
		'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
	] );

	if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
		return null;
	}

	$body = wp_remote_retrieve_body( $response );

	// Try XML parse first (srv1 format)
	$text_xml = @simplexml_load_string( $body );
	if ( $text_xml ) {
		$lines = [];
		foreach ( $text_xml->text as $text ) {
			$line = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$line = wp_strip_all_tags( $line );
			$line = trim( $line );
			if ( $line !== '' ) {
				$lines[] = $line;
			}
		}
		if ( ! empty( $lines ) ) {
			$transcript = implode( ' ', $lines );
			return trim( preg_replace( '/\s+/', ' ', $transcript ) );
		}
	}

	// Fallback: try JSON format (srv3 / timedtext JSON)
	$json = json_decode( $body, true );
	if ( is_array( $json ) && ! empty( $json['events'] ) ) {
		$lines = [];
		foreach ( $json['events'] as $event ) {
			if ( empty( $event['segs'] ) ) continue;
			foreach ( $event['segs'] as $seg ) {
				$text = trim( $seg['utf8'] ?? '' );
				if ( $text !== '' && $text !== "\n" ) {
					$lines[] = $text;
				}
			}
		}
		if ( ! empty( $lines ) ) {
			$transcript = implode( ' ', $lines );
			return trim( preg_replace( '/\s+/', ' ', $transcript ) );
		}
	}

	return null;
}
