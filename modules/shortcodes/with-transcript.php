<?php
/**
 * [youtube_with_transcript url="https://www.youtube.com/watch?v=..."]
 * - Embeds responsive video
 * - Best-effort public transcript (no auth)
 * - Optional: description via API if key present
 */

if ( ! defined('ABSPATH') ) exit;

myls_register_shortcode('youtube_with_transcript', function($atts){
	$atts = shortcode_atts(['url' => ''], $atts, 'youtube_with_transcript');
	if ( empty($atts['url']) ) return '<p><em>No YouTube URL provided.</em></p>';

	if ( ! preg_match('%(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_-]{11})%i', $atts['url'], $m) ) {
		return '<p><em>Invalid YouTube URL.</em></p>';
	}
	$video_id = $m[1];
	$html  = '<div class="myls-youtube-wrapper">';
	$html .= myls_yt_embed_html($video_id);

	// Description via API (if configured)
	$api_key = myls_yt_get_api_key();
	if ( $api_key ) {
		$resp = myls_yt_http_get( add_query_arg([
			'part' => 'snippet',
			'id'   => $video_id,
			'key'  => $api_key,
		], 'https://www.googleapis.com/youtube/v3/videos') );
		if ( ! is_wp_error($resp) && 200 === (int) wp_remote_retrieve_response_code($resp) ) {
			$data = json_decode( wp_remote_retrieve_body($resp), true );
			$desc = $data['items'][0]['snippet']['description'] ?? '';
			if ( $desc ) $html .= '<div class="myls-youtube-description">'. wpautop( esc_html($desc) ) .'</div>';
		}
	}

	// Public transcript (best-effort)
	$lines = [];
	$list  = wp_remote_get( "https://video.google.com/timedtext?type=list&v={$video_id}" );
	if ( ! is_wp_error($list) && 200 === (int) wp_remote_retrieve_response_code($list) ) {
		$xml = simplexml_load_string( wp_remote_retrieve_body($list) );
		if ( isset($xml->track[0]['lang_code']) ) {
			$lang = (string) $xml->track[0]['lang_code'];
			$tts  = wp_remote_get( "https://video.google.com/timedtext?lang={$lang}&v={$video_id}" );
			if ( ! is_wp_error($tts) && 200 === (int) wp_remote_retrieve_response_code($tts) ) {
				$tts_xml = simplexml_load_string( wp_remote_retrieve_body($tts) );
				foreach ( $tts_xml->text as $text ) {
					$lines[] = esc_html( html_entity_decode( (string) $text ) );
				}
			}
		}
	}
	if ( $lines ) {
		$html .= '<div class="accordion mt-3" id="mylsTranscriptAccordion">'
		      .    '<div class="accordion-item">'
		      .      '<h2 class="accordion-header" id="mylsTranscriptHeading">'
		      .        '<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#mylsTranscriptCollapse" aria-expanded="false" aria-controls="mylsTranscriptCollapse">'
		      .          esc_html__('Transcript','aintelligize')
		      .        '</button>'
		      .      '</h2>'
		      .      '<div id="mylsTranscriptCollapse" class="accordion-collapse collapse" aria-labelledby="mylsTranscriptHeading" data-bs-parent="#mylsTranscriptAccordion">'
		      .        '<div class="accordion-body"><p>' . implode('</p><p>', $lines) . '</p></div>'
		      .      '</div>'
		      .    '</div>'
		      .  '</div>';
	}
	$html .= '</div>';
	return $html;
}, [
	'tag'         => 'youtube_with_transcript',
	'description' => 'Embed a YouTube URL with optional description and a best-effort transcript.',
	'params'      => [
		'url' => 'YouTube URL (watch/embed/shorts). Required.',
	],
]);
