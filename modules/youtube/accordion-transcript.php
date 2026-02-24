<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Optionally load Bootstrap 5 (CSS + JS) on pages where our shortcode appears.
 * - If the theme already enqueues Bootstrap 5, set the filter to false:
 *     add_filter('myls_ytvb_enqueue_bootstrap', '__return_false');
 */
add_action('wp_enqueue_scripts', function () {
	$should = apply_filters('myls_ytvb_enqueue_bootstrap', true);
	if ( ! $should ) return;

	// Only enqueue if Bootstrap 5 not present (simple heuristic).
	$has_bootstrap = wp_style_is('bootstrap', 'registered') || wp_style_is('bootstrap', 'enqueued');
	if ( $has_bootstrap ) return;

	wp_enqueue_style(
		'myls-bootstrap-5',
		'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
		array(),
		'5.3.3'
	);
	wp_enqueue_script(
		'myls-bootstrap-5',
		'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
		array(),
		'5.3.3',
		true
	);
}, 20);

/**
 * Fetch video description via YouTube Data API (optional).
 * Falls back to empty string on error.
 */
function myls_ytvb_fetch_description( string $video_id ) : string {
	$key = (string) get_option('myls_youtube_api_key', '');
	if ( $key === '' ) return '';

	$url  = add_query_arg([
		'part' => 'snippet',
		'id'   => $video_id,
		'key'  => $key,
	], 'https://www.googleapis.com/youtube/v3/videos');

	$res = wp_remote_get($url, ['timeout'=>15, 'headers'=>['Accept'=>'application/json']]);
	if ( is_wp_error($res) || 200 !== wp_remote_retrieve_response_code($res) ) return '';
	$data = json_decode( wp_remote_retrieve_body($res), true );
	return (string) ( $data['items'][0]['snippet']['description'] ?? '' );
}

/**
 * Best-effort transcript (public timedtext). Cached with a transient.
 * NOTE: This uses an undocumented endpoint and may stop working at any time.
 *
 * @return array{html:string, source:string, lang:string, error:string|null}
 */
function myls_ytvb_fetch_transcript( string $video_id, string $prefer_lang = 'en' ) : array {
	$cache_key = 'myls_ytvb_tt_' . $video_id;
	$cached = get_transient($cache_key);
	if ( is_array($cached) && isset($cached['html']) ) return $cached;

	$result = ['html'=>'', 'source'=>'', 'lang'=>'', 'error'=>null];

	// 1) List caption tracks
	$list = wp_remote_get( "https://video.google.com/timedtext?type=list&v=" . rawurlencode($video_id), ['timeout'=>12] );
	if ( is_wp_error($list) || 200 !== wp_remote_retrieve_response_code($list) ) {
		$result['error'] = 'No caption list';
		set_transient($cache_key, $result, HOUR_IN_SECONDS);
		return $result;
	}

	$xml = @simplexml_load_string( wp_remote_retrieve_body($list) );
	if ( ! $xml || empty($xml->track) ) {
		$result['error'] = 'No tracks';
		set_transient($cache_key, $result, HOUR_IN_SECONDS);
		return $result;
	}

	// 2) Choose a track (prefer requested lang, else first)
	$track = null;
	foreach ($xml->track as $t) {
		if ( (string)$t['lang_code'] === $prefer_lang ) { $track = $t; break; }
	}
	if ( ! $track ) $track = $xml->track[0];

	$lang  = (string) $track['lang_code'];
	$kind  = (string) $track['kind']; // "asr" indicates auto-generated
	$src   = "https://video.google.com/timedtext?lang={$lang}&v=" . rawurlencode($video_id);

	$tts = wp_remote_get($src, ['timeout'=>12]);
	if ( is_wp_error($tts) || 200 !== wp_remote_retrieve_response_code($tts) ) {
		$result['error'] = 'No caption file';
		set_transient($cache_key, $result, HOUR_IN_SECONDS);
		return $result;
	}

	$tts_xml = @simplexml_load_string( wp_remote_retrieve_body($tts) );
	if ( ! $tts_xml || empty($tts_xml->text) ) {
		$result['error'] = 'Empty transcript';
		set_transient($cache_key, $result, HOUR_IN_SECONDS);
		return $result;
	}

	$lines = [];
	foreach ( $tts_xml->text as $t ) {
		// Decode and strip any tags; produce plain text paragraphs
		$lines[] = esc_html( html_entity_decode( (string) $t, ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
	}
	// Build HTML block
	$html = '<div class="myls-ytvb-transcript">';
	$html .= '<p class="small text-muted mb-2">Transcript'
	      .  ( $kind === 'asr' ? ' (auto-generated)' : '' )
	      .  ' • Language: '. esc_html($lang) .'</p>';
	$html .= '<p>' . implode('</p><p>', $lines) . '</p>';
	$html .= '</div>';

	$result = ['html'=>$html, 'source'=> ($kind === 'asr' ? 'auto' : 'manual'), 'lang'=>$lang, 'error'=>null];
	set_transient($cache_key, $result, 12 * HOUR_IN_SECONDS);
	return $result;
}

/**
 * Build the embed (nocookie for privacy) + Bootstrap accordion for
 * Description and Transcript.
 *
 * @param string $video_id     11-char YouTube ID
 * @param string $desc_html    Optional (we'll fetch if empty)
 * @param bool   $with_transcript  Whether to attempt transcript
 * @return string HTML
 */
function myls_ytvb_video_with_accordion( string $video_id, string $desc_html = '', bool $with_transcript = true ) : string {
	$vid  = preg_replace('/[^A-Za-z0-9_\-]/','', $video_id);
	$uid  = 'ytvbAcc-' . $vid . '-' . wp_generate_uuid4(); // unique per instance

	$embed = sprintf(
		'<div class="ratio ratio-16x9 mb-3"><iframe src="https://www.youtube-nocookie.com/embed/%s" title="YouTube video" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen loading="lazy"></iframe></div>',
		esc_attr($vid)
	);

	// Description: fetch if not supplied
	if ( $desc_html === '' ) {
		$desc_html = myls_ytvb_fetch_description($vid);
	}
	$desc_html = $desc_html !== ''
		? wpautop( wp_kses_post( $desc_html ) )
		: '<p><em>No description available.</em></p>';

	// Transcript (best effort)
	$trans = ['html'=>'<p><em>No transcript available.</em></p>', 'source'=>'', 'lang'=>'', 'error'=>null];
	if ( $with_transcript ) {
		$got = myls_ytvb_fetch_transcript($vid, 'en');
		if ( empty($got['error']) && $got['html'] !== '' ) $trans = $got;
	}

	$acc  = '<div class="accordion" id="'. esc_attr($uid) .'">';

	// Item 1: Description
	$acc .= '<div class="accordion-item">';
	$acc .=   '<h2 class="accordion-header" id="'. esc_attr($uid) .'-desc-h">';
	$acc .=     '<button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#'. esc_attr($uid) .'-desc" aria-expanded="true" aria-controls="'. esc_attr($uid) .'-desc">';
	$acc .=       esc_html__('Description', 'myls');
	$acc .=     '</button>';
	$acc .=   '</h2>';
	$acc .=   '<div id="'. esc_attr($uid) .'-desc" class="accordion-collapse collapse show" aria-labelledby="'. esc_attr($uid) .'-desc-h" data-bs-parent="#'. esc_attr($uid) .'">';
	$acc .=     '<div class="accordion-body">'. $desc_html .'</div>';
	$acc .=   '</div>';
	$acc .= '</div>';

	// Item 2: Transcript
	$acc .= '<div class="accordion-item">';
	$acc .=   '<h2 class="accordion-header" id="'. esc_attr($uid) .'-tt-h">';
	$acc .=     '<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#'. esc_attr($uid) .'-tt" aria-expanded="false" aria-controls="'. esc_attr($uid) .'-tt">';
	$acc .=       esc_html__('Transcript', 'myls');
	$acc .=     '</button>';
	$acc .=   '</h2>';
	$acc .=   '<div id="'. esc_attr($uid) .'-tt" class="accordion-collapse collapse" aria-labelledby="'. esc_attr($uid) .'-tt-h" data-bs-parent="#'. esc_attr($uid) .'">';
	$acc .=     '<div class="accordion-body">'. $trans['html'] .'</div>';
	$acc .=   '</div>';
	$acc .= '</div>';

	$acc .= '</div>';

	return $embed . $acc;
}

/**
 * Shortcode: [myls_youtube_panel url="..." transcript="1"]
 * - Renders the embed + accordion (Description + Transcript)
 */
add_shortcode('myls_youtube_panel', function( $atts ){
	$atts = shortcode_atts([
		'url'        => '',
		'transcript' => '1',
	], $atts, 'myls_youtube_panel');

	$url = trim($atts['url']);
	if ( $url === '' ) return '<p><em>No YouTube URL provided.</em></p>';
	if ( ! preg_match('%(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_-]{11})%i', $url, $m) ) {
		return '<p><em>Invalid YouTube URL.</em></p>';
	}
	$video_id = $m[1];
	$with_tt  = $atts['transcript'] === '1';

	return myls_ytvb_video_with_accordion( $video_id, '', $with_tt );
});
