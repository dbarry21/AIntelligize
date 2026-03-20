<?php
/**
 * MYLS – Page Video URL meta box
 * File: inc/metaboxes/page-video-url.php
 *
 * Adds a "YouTube Video URL" meta box on pages so the shortcode
 * [myls_youtube_embed use_page_video="1"] can read the URL from post meta.
 *
 * Storage: _myls_page_video_url (string, full YouTube URL)
 *
 * On save, if the plugin-native key is empty but the legacy ACF field
 * 'video_url' has a value, the value is auto-copied (lazy per-page migration).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'add_meta_boxes', function () {
	add_meta_box(
		'myls_page_video_url',
		'YouTube Video URL',
		'myls_render_page_video_url_metabox',
		'page',
		'side',
		'default'
	);
});

/**
 * Render the metabox.
 */
function myls_render_page_video_url_metabox( $post ) {
	wp_nonce_field( 'myls_page_video_url_save', 'myls_page_video_url_nonce' );
	$post_id = (int) $post->ID;

	// Read plugin-native value first, then legacy ACF value
	$url = get_post_meta( $post_id, '_myls_page_video_url', true );
	$url = is_string( $url ) ? trim( $url ) : '';

	if ( $url === '' ) {
		$acf_url = get_post_meta( $post_id, 'video_url', true );
		if ( is_string( $acf_url ) && trim( $acf_url ) !== '' ) {
			$url = trim( $acf_url );
		}
	}

	// Extract video ID for preview
	$video_id = '';
	if ( $url !== '' && preg_match( '%(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_-]{11})%i', $url, $m ) ) {
		$video_id = $m[1];
	}

	echo '<p><label for="myls_page_video_url"><strong>YouTube URL</strong></label></p>';
	echo '<input type="url" class="widefat" id="myls_page_video_url" name="myls_page_video_url" value="' . esc_attr( $url ) . '" placeholder="https://www.youtube.com/watch?v=..." />';
	echo '<p style="margin-top:4px;"><small>Saved to <code>_myls_page_video_url</code>. Used by <code>[myls_youtube_embed use_page_video="1"]</code>.</small></p>';

	if ( $video_id !== '' ) {
		echo '<p style="margin-top:4px;"><small><strong>Video ID:</strong> <code>' . esc_html( $video_id ) . '</code></small></p>';
		$thumb = 'https://i.ytimg.com/vi/' . rawurlencode( $video_id ) . '/hqdefault.jpg';
		echo '<div style="margin-top:8px;">';
		echo '<img src="' . esc_url( $thumb ) . '" alt="Video thumbnail" style="max-width:100%;height:auto;border-radius:4px;border:1px solid #ddd;" />';
		echo '</div>';
		echo '<p style="margin-top:4px;"><small>';
		echo '<a href="https://www.youtube.com/watch?v=' . esc_attr( $video_id ) . '" target="_blank" rel="noopener">View on YouTube &#8599;</a>';
		echo ' &nbsp;|&nbsp; Shortcode: <code>[myls_youtube_embed use_page_video="1"]</code>';
		echo '</small></p>';
	}
}

/**
 * Save handler.
 */
add_action( 'save_post_page', function ( $post_id ) {
	if ( ! isset( $_POST['myls_page_video_url_nonce'] ) ) return;
	if ( ! wp_verify_nonce( $_POST['myls_page_video_url_nonce'], 'myls_page_video_url_save' ) ) return;
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( ! current_user_can( 'edit_post', $post_id ) ) return;

	$url = isset( $_POST['myls_page_video_url'] ) ? esc_url_raw( trim( (string) $_POST['myls_page_video_url'] ) ) : '';

	// Lazy migration: if user clears the field but ACF value exists, don't re-migrate.
	// Only auto-migrate when the metabox was never saved (native key doesn't exist at all).
	if ( $url === '' ) {
		delete_post_meta( $post_id, '_myls_page_video_url' );
	} else {
		update_post_meta( $post_id, '_myls_page_video_url', $url );
	}
});
