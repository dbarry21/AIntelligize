<?php
/**
 * YouTube helpers shared by all modules/shortcodes.
 * - Options getters (API key, channel)
 * - Robust HTTP (with IPv4 retry)
 * - Channel -> uploads playlist resolver (cached)
 * - Playlist items fetch (cached)
 * - Map videoId -> local CPT URL (meta or slug)
 * - Embed HTML (Gutenberg-friendly)
 * - Video description fetch (optional)
 * - Transcript fetch (best-effort via timedtext)
 *
 * Safe to include multiple times (function_exists guards).
 */

if ( ! defined('ABSPATH') ) exit;

/** ----------------------------------------------------------------
 * Options
 * ---------------------------------------------------------------- */
if ( ! function_exists('myls_yt_get_api_key') ) {
	function myls_yt_get_api_key() : string {
		// Prefer the tiny getters from API Integration tab if present
		if ( function_exists('myls_get_youtube_api_key') ) {
			return (string) myls_get_youtube_api_key();
		}
		return (string) get_option('myls_youtube_api_key', '');
	}
}
if ( ! function_exists('myls_yt_get_channel_id') ) {
	function myls_yt_get_channel_id() : string {
		if ( function_exists('myls_get_youtube_channel_id') ) {
			return (string) myls_get_youtube_channel_id();
		}
		return (string) get_option('myls_youtube_channel_id', '');
	}
}

/** ----------------------------------------------------------------
 * HTTP (robust + IPv4 retry)
 * ---------------------------------------------------------------- */
if ( ! function_exists('myls_yt_http_get') ) {
	function myls_yt_http_get( string $url, array $args = [] ) {
		$args = wp_parse_args(
			apply_filters('myls_yt_request_args', $args, $url),
			[
				'timeout'     => 15,
				'sslverify'   => true,
				'httpversion' => '1.1',
				'headers'     => [ 'Referer' => home_url('/') ],
			]
		);

		$r = wp_remote_get($url, $args);
		if ( ! is_wp_error($r) && wp_remote_retrieve_response_code($r) ) return $r;

		// Retry with IPv4 (some hosts/cURL stacks need it)
		$force_ipv4 = static function($h){
			if ( defined('CURL_IPRESOLVE_V4') ) @curl_setopt($h, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
		};
		add_filter('http_api_curl', $force_ipv4, 10, 1);
		$r2 = wp_remote_get($url, $args);
		remove_filter('http_api_curl', $force_ipv4, 10);

		return $r2;
	}
}

/** ----------------------------------------------------------------
 * API: channels → uploads playlist id (cached)
 * ---------------------------------------------------------------- */
if ( ! function_exists('myls_yt_get_uploads_playlist_id') ) {
	function myls_yt_get_uploads_playlist_id( string $channel_id, string $api_key ) : string {
		if ( $channel_id === '' || $api_key === '' ) return '';
		$cache_key = 'myls_yt_uploads_' . md5($channel_id);
		$cached    = get_transient($cache_key);
		if ( is_string($cached) && $cached !== '' ) return $cached;

		$url = add_query_arg([
			'part' => 'contentDetails',
			'id'   => $channel_id,
			'key'  => $api_key,
		], 'https://www.googleapis.com/youtube/v3/channels');

		$resp = myls_yt_http_get($url);
		if ( is_wp_error($resp) || 200 !== (int) wp_remote_retrieve_response_code($resp) ) {
			return '';
		}

		$data    = json_decode( wp_remote_retrieve_body($resp), true );
		$uploads = $data['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? '';
		if ( $uploads ) {
			set_transient(
				$cache_key,
				$uploads,
				apply_filters('myls_yt_cache_ttl_uploads', HOUR_IN_SECONDS)
			);
		}
		return (string) $uploads;
	}
}

/** ----------------------------------------------------------------
 * API: playlist items (latest uploads; cached)
 * Returns array of:
 *   ['videoId','title','description','publishedAt','thumb','channel']
 * ---------------------------------------------------------------- */
if ( ! function_exists('myls_yt_fetch_uploads_batch') ) {
	function myls_yt_fetch_uploads_batch( string $playlist_id, string $api_key, int $pages = 3 ) : array {
		if ( $playlist_id === '' || $api_key === '' ) return [];
		$cache_key = 'myls_yt_pl_' . md5($playlist_id);
		$cached    = get_transient($cache_key);
		if ( is_array($cached) ) return $cached;

		$out  = [];
		$next = '';
		$loop = 0;

		do {
			$args = [
				'part'       => 'snippet',
				'playlistId' => $playlist_id,
				'maxResults' => 50,
				'key'        => $api_key,
			];
			if ( $next ) $args['pageToken'] = $next;

			$resp = myls_yt_http_get( add_query_arg($args, 'https://www.googleapis.com/youtube/v3/playlistItems') );
			if ( is_wp_error($resp) || 200 !== (int) wp_remote_retrieve_response_code($resp) ) break;

			$data  = json_decode( wp_remote_retrieve_body($resp), true );
			$items = $data['items'] ?? [];
			$next  = $data['nextPageToken'] ?? '';

			foreach ( $items as $row ) {
				$sn  = $row['snippet'] ?? [];
				$vid = $sn['resourceId']['videoId'] ?? '';
				if ( ! $vid ) continue;

				$out[] = [
					'videoId'     => $vid,
					'title'       => $sn['title'] ?? $vid,
					'description' => $sn['description'] ?? '',
					'publishedAt' => $sn['publishedAt'] ?? '',
					'thumb'       => $sn['thumbnails']['medium']['url'] ?? ($sn['thumbnails']['default']['url'] ?? ''),
					'channel'     => $sn['videoOwnerChannelTitle'] ?? '', // may be empty depending on API response
				];
			}

			$loop++;
			if ( $loop >= max(1, $pages) ) break; // safety (default up to 150 items)
		} while ( $next );

		set_transient(
			$cache_key,
			$out,
			apply_filters('myls_yt_cache_ttl_items', 10 * MINUTE_IN_SECONDS)
		);
		return $out;
	}
}

/** ----------------------------------------------------------------
 * Map a YouTube video to local CPT URL if exists
 * ---------------------------------------------------------------- */
if ( ! function_exists('myls_yt_find_video_post_url') ) {
	function myls_yt_find_video_post_url( string $video_id, string $title = '' ) : string {
		// New meta key
		$q = get_posts([
			'post_type'        => 'video',
			'post_status'      => ['publish','draft','pending','future','private'],
			'meta_key'         => '_myls_video_id',
			'meta_value'       => $video_id,
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		]);
		if ( ! empty($q) ) return (string) get_permalink($q[0]);

		// Back-compat: older key
		$q2 = get_posts([
			'post_type'        => 'video',
			'post_status'      => ['publish','draft','pending','future','private'],
			'meta_key'         => '_ssseo_video_id',
			'meta_value'       => $video_id,
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		]);
		if ( ! empty($q2) ) return (string) get_permalink($q2[0]);

		// Fallback: slug by title
		if ( $title !== '' ) {
			$slug = sanitize_title($title);
			$p = get_page_by_path($slug, OBJECT, 'video');
			if ( $p ) return (string) get_permalink($p);
		}
		return '';
	}
}

/** ----------------------------------------------------------------
 * Embed HTML (Gutenberg/oEmbed friendly)
 * - Returns a <figure> with a YouTube URL on its own line, so WP can
 *   turn it into a proper embed block. Degrades gracefully otherwise.
 * ---------------------------------------------------------------- */
if ( ! function_exists('myls_yt_embed_html') ) {
	function myls_yt_embed_html( string $video_id, array $args = [] ) : string {
		$args = wp_parse_args($args, [
			'class'           => '',        // extra classes you might want to add
			'max_width'       => '900px',   // container max width
			'lazy'            => true,      // lazy-load iframe
			'modestbranding'  => 1,
			'rel'             => 0,
			'playsinline'     => 1,
			'autoplay'        => 0,
			'mute'            => 0,
		]);

		// Build YouTube embed URL with a few sensible params
		$q = array_filter([
			'rel'            => $args['rel'],
			'modestbranding' => $args['modestbranding'],
			'playsinline'    => $args['playsinline'],
			'autoplay'       => $args['autoplay'],
			'mute'           => $args['mute'],
		], static fn($v) => $v !== null && $v !== '');
		$src  = 'https://www.youtube.com/embed/' . rawurlencode($video_id);
		$src .= $q ? ('?' . http_build_query($q)) : '';

		$lazy = $args['lazy'] ? ' loading="lazy"' : '';

		// Uses Bootstrap's .ratio if present; inline styles ensure a 16:9 fallback without Bootstrap.
		return '<div class="myls-video-embed ratio ratio-16x9 ' . esc_attr($args['class']) . '"'
			. ' style="--bs-aspect-ratio:56.25%;max-width:' . esc_attr($args['max_width']) . ';width:100%;margin:0 auto 1rem;position:relative;">'
			. '  <iframe'
			. '    src="' . esc_url($src) . '"'
			. '    title="YouTube video player"'
			. '    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"'
			. '    allowfullscreen'
			. '    referrerpolicy="strict-origin-when-cross-origin"'
			.       $lazy
			// Absolute positioning ensures the 16:9 box works even without Bootstrap’s CSS
			. '    style="position:absolute;inset:0;width:100%;height:100%;">'
			. '  </iframe>'
			. '</div>';
	}
}


/** ----------------------------------------------------------------
 * Optional: fetch a video's description via Videos API (snippet)
 * ---------------------------------------------------------------- */
if ( ! function_exists('myls_yt_fetch_description') ) {
	function myls_yt_fetch_description( string $video_id, ?string $api_key = null ) : string {
		$video_id = preg_replace('~[^A-Za-z0-9_-]~', '', $video_id);
		if ( $video_id === '' ) return '';
		$key = $api_key !== null ? $api_key : myls_yt_get_api_key();
		if ( $key === '' ) return '';

		$resp = myls_yt_http_get( add_query_arg([
			'part' => 'snippet',
			'id'   => $video_id,
			'key'  => $key,
		], 'https://www.googleapis.com/youtube/v3/videos') );
		if ( is_wp_error($resp) || 200 !== (int) wp_remote_retrieve_response_code($resp) ) {
		 return '';
		}
		$data = json_decode( wp_remote_retrieve_body($resp), true );
		return (string) ( $data['items'][0]['snippet']['description'] ?? '' );
	}
}

/** ----------------------------------------------------------------
 * Transcript fetch (best-effort via timedtext)
 * - Returns an array of plain text lines (already HTML-escaped).
 * - Works when captions are public or auto-captions are available.
 * ---------------------------------------------------------------- */
if ( ! function_exists('myls_yt_fetch_transcript') ) {
	function myls_yt_fetch_transcript( string $video_id ) : array {
		$video_id = preg_replace('~[^A-Za-z0-9_-]~', '', $video_id);
		if ( $video_id === '' ) return [];

		// Discover available caption tracks
		$list = wp_remote_get("https://video.google.com/timedtext?type=list&v={$video_id}");
		if ( is_wp_error($list) || 200 !== (int) wp_remote_retrieve_response_code($list) ) return [];
		$xml = @simplexml_load_string( wp_remote_retrieve_body($list) );
		if ( ! $xml || ! isset($xml->track[0]['lang_code']) ) return [];

		$lang = (string) $xml->track[0]['lang_code'];

		// Fetch the first track
		$tts  = wp_remote_get("https://video.google.com/timedtext?lang={$lang}&v={$video_id}");
		if ( is_wp_error($tts) || 200 !== (int) wp_remote_retrieve_response_code($tts) ) return [];

		$tts_xml = @simplexml_load_string( wp_remote_retrieve_body($tts) );
		if ( ! $tts_xml ) return [];

		$lines = [];
		foreach ( $tts_xml->text as $t ) {
			$lines[] = esc_html( html_entity_decode( (string) $t ) );
		}
		return $lines;
	}
}
