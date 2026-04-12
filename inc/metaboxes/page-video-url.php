<?php
/**
 * MYLS – Page Video URL meta box
 * File: inc/metaboxes/page-video-url.php
 *
 * Adds a "YouTube Video URL" meta box on pages and service CPT so the
 * shortcode [myls_youtube_embed use_page_video="1"] can read the URL
 * from post meta.
 *
 * Storage: _myls_page_video_url (string, full YouTube URL)
 *
 * On save, if the plugin-native key is empty but the legacy ACF field
 * 'video_url' has a value, the value is auto-copied (lazy per-page migration).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'add_meta_boxes', function () {
	// Register on page and service CPT
	foreach ( [ 'page', 'service' ] as $pt ) {
		add_meta_box(
			'myls_page_video_url',
			'YouTube Video URL',
			'myls_render_page_video_url_metabox',
			$pt,
			'side',
			'default'
		);
	}
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

	$sourced_from_acf = false;
	if ( $url === '' ) {
		$acf_url = get_post_meta( $post_id, 'video_url', true );
		if ( is_string( $acf_url ) && trim( $acf_url ) !== '' ) {
			$url = trim( $acf_url );
			$sourced_from_acf = true;
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

	if ( $sourced_from_acf ) {
		echo '<div style="margin-top:6px;padding:7px 9px;background:#fff3cd;'
		   . 'border-left:3px solid #ffc107;font-size:11px;">'
		   . '<strong>&#9888; Migrating from ACF:</strong> Value loaded from legacy '
		   . '<code>video_url</code> field. <strong>Save this post</strong> to '
		   . 'write it to <code>_myls_page_video_url</code> and remove the ACF '
		   . 'dependency.</div>';
	}

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
 * Save handler for pages.
 */
add_action( 'save_post_page', function ( $post_id ) {
	if ( ! isset( $_POST['myls_page_video_url_nonce'] ) ) return;
	if ( ! wp_verify_nonce( $_POST['myls_page_video_url_nonce'], 'myls_page_video_url_save' ) ) return;
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( ! current_user_can( 'edit_post', $post_id ) ) return;

	$url = isset( $_POST['myls_page_video_url'] ) ? esc_url_raw( trim( (string) $_POST['myls_page_video_url'] ) ) : '';

	if ( $url === '' ) {
		delete_post_meta( $post_id, '_myls_page_video_url' );
	} else {
		update_post_meta( $post_id, '_myls_page_video_url', $url );
	}
});

/**
 * Save handler for service CPT.
 * Mirrors save_post_page handler — same logic, different hook.
 * Uses update_post_meta() directly to avoid wp_update_post() which
 * would trigger Elementor Theme Builder save_post and overwrite
 * _elementor_page_settings.
 */
add_action( 'save_post_service', function ( $post_id ) {
	if ( ! isset( $_POST['myls_page_video_url_nonce'] ) ) return;
	if ( ! wp_verify_nonce( $_POST['myls_page_video_url_nonce'],
			'myls_page_video_url_save' ) ) return;
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( ! current_user_can( 'edit_post', $post_id ) ) return;

	$url = isset( $_POST['myls_page_video_url'] )
		? esc_url_raw( trim( (string) $_POST['myls_page_video_url'] ) )
		: '';

	if ( $url === '' ) {
		delete_post_meta( $post_id, '_myls_page_video_url' );
	} else {
		update_post_meta( $post_id, '_myls_page_video_url', $url );
	}
} );

/**
 * One-time migration: copy ACF video_url → _myls_page_video_url
 * for all page and service posts that have the legacy key set.
 * Runs once per plugin version via version-keyed option.
 * Only copies when _myls_page_video_url is not already set.
 */
add_action( 'init', function() {
	$opt_key = 'myls_video_url_migration_v' . ( defined('MYLS_VERSION')
		? MYLS_VERSION : '0' );

	if ( get_option( $opt_key ) ) return;

	global $wpdb;

	// Find all posts with legacy video_url meta but no native key
	$posts = $wpdb->get_results( "
		SELECT p.ID, p.post_type, pm.meta_value AS acf_url
		FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->postmeta} pm
			ON pm.post_id = p.ID AND pm.meta_key = 'video_url'
		LEFT JOIN {$wpdb->postmeta} pm2
			ON pm2.post_id = p.ID AND pm2.meta_key = '_myls_page_video_url'
		WHERE p.post_type IN ('page','service')
		  AND p.post_status NOT IN ('trash','auto-draft')
		  AND pm.meta_value != ''
		  AND pm2.meta_id IS NULL
	" );

	if ( ! empty( $posts ) ) {
		foreach ( $posts as $row ) {
			$clean_url = esc_url_raw( trim( (string) $row->acf_url ) );
			if ( $clean_url !== '' ) {
				update_post_meta( (int) $row->ID,
					'_myls_page_video_url', $clean_url );
			}
		}
	}

	update_option( $opt_key, '1', false );
} );
