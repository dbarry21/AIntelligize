<?php
/**
 * AJAX: Fetch YouTube Transcript
 *
 * Endpoint: wp_ajax_myls_fetch_youtube_transcript
 * Accepts: video_id (YouTube video ID)
 * Returns: JSON { success: true, data: { transcript: "..." } }
 *
 * Priority chain:
 *   1. Supadata API (reliable hosted service, requires API key)
 *   2. YouTube page scrape (extract captionTracks from ytInitialPlayerResponse)
 *   3. Legacy timedtext list API (deprecated, kept as last resort)
 *
 * @since 7.8.77
 * @updated 7.8.83 — Supadata API as primary, page-scrape + timedtext as fallbacks
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

	// ── Method 1: Supadata API (primary) ──
	$transcript = _myls_fetch_transcript_supadata( $video_id );

	// ── Method 2: YouTube page scrape (fallback) ──
	if ( $transcript === null ) {
		$caption_url = _myls_get_caption_url_from_page( $video_id );
		if ( $caption_url ) {
			$transcript = _myls_fetch_caption_xml( $caption_url );
		}
	}

	// ── Method 3: Legacy timedtext API (last resort) ──
	if ( $transcript === null ) {
		$caption_url = _myls_get_caption_url_from_timedtext( $video_id );
		if ( $caption_url ) {
			$transcript = _myls_fetch_caption_xml( $caption_url );
		}
	}

	if ( $transcript === null ) {
		wp_send_json_error( [ 'message' => 'No captions found for this video — enter transcript manually.' ] );
	}

	wp_send_json_success( [ 'transcript' => $transcript ] );
} );

/**
 * Fetch transcript via Supadata API.
 *
 * @see https://supadata.ai/youtube-transcript-api
 */
function _myls_fetch_transcript_supadata( string $video_id ) : ?string {
	$api_key = function_exists( 'myls_get_supadata_api_key' )
		? myls_get_supadata_api_key()
		: (string) get_option( 'myls_supadata_api_key', '' );

	if ( $api_key === '' ) {
		return null;
	}

	$url = add_query_arg( 'url', 'https://youtu.be/' . rawurlencode( $video_id ),
		'https://api.supadata.ai/v1/transcript'
	);

	$response = wp_remote_get( $url, [
		'timeout' => 20,
		'headers' => [
			'x-api-key' => $api_key,
		],
	] );

	if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
		return null;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) || empty( $data['content'] ) || ! is_array( $data['content'] ) ) {
		return null;
	}

	$lines = [];
	foreach ( $data['content'] as $segment ) {
		$text = trim( (string) ( $segment['text'] ?? '' ) );
		if ( $text !== '' ) {
			$lines[] = $text;
		}
	}

	if ( empty( $lines ) ) {
		return null;
	}

	$transcript = implode( ' ', $lines );
	return trim( preg_replace( '/\s+/', ' ', $transcript ) );
}

/**
 * Extract caption track URL from YouTube video page HTML.
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
 * Fetch caption XML/JSON from a baseUrl and return clean plain text.
 */
function _myls_fetch_caption_xml( string $url ) : ?string {
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

	// XML format (srv1)
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
			return trim( preg_replace( '/\s+/', ' ', implode( ' ', $lines ) ) );
		}
	}

	// JSON format (srv3)
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
			return trim( preg_replace( '/\s+/', ' ', implode( ' ', $lines ) ) );
		}
	}

	return null;
}
