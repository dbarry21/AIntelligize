<?php
/**
 * AIntelligize – Blog Prefix (loader)
 * - Wires the "Custom Post Permalink Prefix" feature ONLY when enabled and prefix is set.
 * - Uses options set from the CPT tab card:
 *     myls_blogprefix_enabled  ('1' or '0')
 *     myls_blogprefix_value    (string; no slashes; e.g., "hvac-blog")
 */

if ( ! defined('ABSPATH') ) exit;

// Read settings
$__myls_blogprefix_enabled = get_option('myls_blogprefix_enabled', '0') === '1';
$__myls_blogprefix_value   = trim( (string) get_option('myls_blogprefix_value', '') );

// Guard: if disabled or empty, do nothing
if ( ! $__myls_blogprefix_enabled || $__myls_blogprefix_value === '' ) {
    return;
}

// =============================
// USE YOUR EXISTING FUNCTIONALITY AS-IS,
// with $custom_post_prefix taken from the option.
// =============================

/**
 * Custom Post Permalink Prefix
 * Applies a custom slug prefix to standard posts (e.g., /hvac-blog/post-name/).
 * Supports permalink rewrite, canonical tags, redirects, and Yoast SEO integration.
 */

$custom_post_prefix = $__myls_blogprefix_value;

// === 1. Modify permalinks for standard posts ===
add_filter( 'post_link', function( $permalink, $post, $leavename ) use ( $custom_post_prefix ) {
	if ( 'post' === $post->post_type ) {
		$permalink = home_url( '/' . $custom_post_prefix . '/' . $post->post_name . '/' );
	}
	return $permalink;
}, 10, 3 );

// === 2. Add custom rewrite rules ===
add_action( 'init', function() use ( $custom_post_prefix ) {
	add_rewrite_rule(
		'^' . $custom_post_prefix . '/([^/]+)/?$',
		'index.php?post_type=post&name=$matches[1]',
		'top'
	);
});

// === 3. Flush rewrite rules on theme activation ===
add_action( 'after_switch_theme', function() use ( $custom_post_prefix ) {
	add_rewrite_rule(
		'^' . $custom_post_prefix . '/([^/]+)/?$',
		'index.php?post_type=post&name=$matches[1]',
		'top'
	);
	flush_rewrite_rules();
});

// === 4. Redirect old /post-slug/ URLs to prefixed version ===
add_action( 'template_redirect', function() use ( $custom_post_prefix ) {
	if ( is_singular( 'post' ) ) {
		if ( false === strpos( $_SERVER['REQUEST_URI'], '/' . $custom_post_prefix . '/' ) ) {
			wp_redirect( get_permalink(), 301 );
			exit;
		}
	}
});

// === 5. Override rel=canonical for non-Yoast setups ===
remove_action( 'wp_head', 'rel_canonical' );
add_action( 'wp_head', function() use ( $custom_post_prefix ) {
	if ( is_singular( 'post' ) ) {
		$post_id = get_queried_object_id();
		$slug    = get_post_field( 'post_name', $post_id );
		$url     = home_url( '/' . $custom_post_prefix . '/' . $slug . '/' );
		echo "<link rel='canonical' href='" . esc_url( $url ) . "' />\n";
	}
});

// === 6. Override canonical in Yoast SEO plugin ===
add_filter( 'wpseo_canonical', function( $canonical ) use ( $custom_post_prefix ) {
	if ( is_singular( 'post' ) ) {
		$post_id = get_queried_object_id();
		$slug    = get_post_field( 'post_name', $post_id );
		$canonical = home_url( '/' . $custom_post_prefix . '/' . $slug . '/' );
	}
	return $canonical;
});

// === 7. Auto-generate unique slug from title for drafts (update on title change) ===
add_filter( 'wp_insert_post_data', function( $data, $postarr ) {
	if (
		$data['post_type'] === 'post' &&
		in_array( $data['post_status'], [ 'draft', 'auto-draft' ], true )
	) {
		if ( ! empty( $data['post_title'] ) ) {
			global $wpdb;

			$base_slug = sanitize_title( $data['post_title'] );
			$slug      = $base_slug;
			$counter   = 2;

			// Ensure uniqueness (exclude this post's ID)
			while ( $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE post_name = %s AND ID != %d LIMIT 1",
				$slug,
				$postarr['ID'] ?? 0
			) ) ) {
				$slug = $base_slug . '-' . $counter;
				$counter++;
			}

			// Always update slug while still in draft
			$data['post_name'] = $slug;
		}
	}
	return $data;
}, 10, 2 );
