<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Video CPT Metabox — YouTube Video ID & Thumbnail URL
 *
 * Displays YouTube video ID (read-only), editable thumbnail URL, and live preview.
 */
add_action( 'add_meta_boxes', function() {
	add_meta_box(
		'myls_video_details',
		'Video Details',
		'myls_render_video_details_metabox',
		'video',
		'side',
		'default'
	);
});

function myls_render_video_details_metabox( $post ) {
	wp_nonce_field( 'myls_video_details_save', 'myls_video_details_nonce' );
	$post_id = (int) $post->ID;

	// YouTube Video ID (read from multiple legacy keys)
	$video_id = '';
	foreach ( [ '_myls_youtube_video_id', '_myls_video_id', '_ssseo_video_id' ] as $key ) {
		$val = get_post_meta( $post_id, $key, true );
		if ( is_string( $val ) && trim( $val ) !== '' ) {
			$video_id = trim( $val );
			break;
		}
	}

	// Thumbnail URL
	$thumb_url = get_post_meta( $post_id, '_myls_video_thumb_url', true );
	$thumb_url = is_string( $thumb_url ) ? $thumb_url : '';

	// If thumb is empty but we have a video ID, show the constructed fallback
	$display_thumb = $thumb_url;
	if ( $display_thumb === '' && $video_id !== '' ) {
		$display_thumb = 'https://i.ytimg.com/vi/' . rawurlencode( $video_id ) . '/hqdefault.jpg';
	}

	echo '<p><label><strong>YouTube Video ID</strong></label></p>';
	echo '<input type="text" class="widefat" value="' . esc_attr( $video_id ) . '" readonly style="background:#f0f0f1;cursor:default;" />';

	if ( $video_id !== '' ) {
		echo '<p style="margin-top:4px;"><small>';
		echo '<a href="https://www.youtube.com/watch?v=' . esc_attr( $video_id ) . '" target="_blank" rel="noopener">View on YouTube &#8599;</a>';
		echo '</small></p>';
	}

	echo '<hr style="margin:12px 0;" />';

	echo '<p><label for="myls_video_thumb_url"><strong>Thumbnail URL</strong></label></p>';
	echo '<input type="url" class="widefat" id="myls_video_thumb_url" name="myls_video_thumb_url" value="' . esc_attr( $thumb_url ) . '" placeholder="Auto-generated from video ID if blank" />';
	echo '<p style="margin-top:4px;"><small>Saved to <code>_myls_video_thumb_url</code>. Used in schema and shortcode display.</small></p>';

	if ( $display_thumb !== '' ) {
		echo '<div style="margin-top:8px;">';
		echo '<img src="' . esc_url( $display_thumb ) . '" alt="Video thumbnail" style="max-width:100%;height:auto;border-radius:4px;border:1px solid #ddd;" />';
		if ( $thumb_url === '' && $video_id !== '' ) {
			echo '<p style="margin-top:4px;"><small style="color:#666;"><em>Preview from YouTube CDN (not saved — save a URL above to persist).</em></small></p>';
		}
		echo '</div>';
	}
}

/**
 * Save handler for video details metabox.
 */
add_action( 'save_post_video', function( $post_id ) {
	if ( ! isset( $_POST['myls_video_details_nonce'] ) ) return;
	if ( ! wp_verify_nonce( $_POST['myls_video_details_nonce'], 'myls_video_details_save' ) ) return;
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( ! current_user_can( 'edit_post', $post_id ) ) return;

	if ( isset( $_POST['myls_video_thumb_url'] ) ) {
		$url = esc_url_raw( trim( (string) $_POST['myls_video_thumb_url'] ) );
		if ( $url === '' ) {
			delete_post_meta( $post_id, '_myls_video_thumb_url' );
		} else {
			update_post_meta( $post_id, '_myls_video_thumb_url', $url );
		}
	}
});
