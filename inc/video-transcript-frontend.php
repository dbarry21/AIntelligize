<?php
/**
 * Video Transcript Frontend — Collapsible Accordion
 * Path: inc/video-transcript-frontend.php
 *
 * Appends a Bootstrap 5 collapsible accordion with the cached transcript
 * below the video content on single video CPT pages.
 *
 * @since 7.8.86
 */

if ( ! defined('ABSPATH') ) exit;

add_filter('the_content', 'myls_vt_append_transcript_accordion', 50);

function myls_vt_append_transcript_accordion( $content ) {
	if ( ! is_singular('video') || ! is_main_query() || ! in_the_loop() ) {
		return $content;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) return $content;

	// Find the YouTube video ID from post meta (try multiple keys)
	$video_id = '';
	$meta_keys = ['_myls_youtube_video_id', '_myls_video_id', '_ssseo_video_id'];
	foreach ( $meta_keys as $key ) {
		$val = trim( (string) get_post_meta($post_id, $key, true) );
		if ( $val !== '' ) {
			$video_id = $val;
			break;
		}
	}

	if ( $video_id === '' || ! function_exists('myls_vt_get_by_id') ) {
		return $content;
	}

	$row = myls_vt_get_by_id( $video_id );
	if ( ! $row || $row['status'] !== 'ok' || empty($row['transcript']) ) {
		return $content;
	}

	// Enqueue accordion CSS only when transcript renders.
	wp_enqueue_style( 'myls-accordion' );

	$accordion_id = 'myls-vt-accordion-' . esc_attr($video_id);
	$collapse_id  = 'myls-vt-collapse-' . esc_attr($video_id);
	$transcript   = wpautop( esc_html($row['transcript']) );

	$accordion = '
<div class="ssseo-accordion accordion myls-vt-frontend-accordion" id="' . $accordion_id . '" style="margin-top:1.5rem;">
	<div class="accordion-item">
		<h2 class="accordion-header">
			<button class="accordion-button collapsed" type="button"
				data-bs-toggle="collapse"
				data-bs-target="#' . $collapse_id . '"
				aria-expanded="false"
				aria-controls="' . $collapse_id . '">
				Video Transcript
			</button>
		</h2>
		<div id="' . $collapse_id . '" class="accordion-collapse collapse"
			data-bs-parent="#' . $accordion_id . '">
			<div class="accordion-body" style="font-size:14px;line-height:1.7;max-height:400px;overflow-y:auto;">
				' . $transcript . '
			</div>
		</div>
	</div>
</div>';

	return $content . $accordion;
}
