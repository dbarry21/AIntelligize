<?php
/**
 * MYLS – BlogPosting / Article JSON-LD (single posts) in <head>
 * - Respects global toggle: myls_schema_blog_enabled === '1'
 * - Uses Organization options from MYLS: myls_org_name, myls_org_logo_id, myls_org_url
 * - Falls back gracefully to site name / no logo if missing
 */

if ( ! defined('ABSPATH') ) exit;

/** Should we render on this request? */
if ( ! function_exists('myls_blogposting_should_render') ) {
	function myls_blogposting_should_render( WP_Post $post ) : bool {
		// Global toggle from Schema → Blog subtab
		if ( get_option('myls_schema_blog_enabled','0') !== '1' ) return false;
		// Only single standard blog posts here
		return is_singular('post');
	}
}

/** Build the BlogPosting/Article schema array */
if ( ! function_exists('myls_blogposting_build_schema') ) {
	function myls_blogposting_build_schema( WP_Post $post ) : array {
		$permalink = get_permalink( $post );
		$title     = get_the_title( $post );
		$excerpt   = get_the_excerpt( $post ); // WP handles smart fallback if no manual excerpt

		// Author
		$author_name = get_the_author_meta( 'display_name', $post->post_author );

		// Publisher (from MYLS Organization settings, with fallbacks)
		$org_name   = get_option( 'myls_org_name', get_bloginfo('name') );
		$org_url    = get_option( 'myls_org_url', home_url('/') );
		$logo_id    = (int) get_option( 'myls_org_logo_id', 0 );
		$logo_url   = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';
		$logo_meta  = $logo_id ? wp_get_attachment_image_src( $logo_id, 'full' ) : false;

		// Featured image
		$feat_img = has_post_thumbnail( $post ) ? get_the_post_thumbnail_url( $post, 'full' ) : '';

		// Categories / Tags (optional, nice to have)
		$cats = get_the_terms( $post, 'category' );
		$tags = get_the_terms( $post, 'post_tag' );
		$sections = is_array($cats) ? array_map( fn($t)=> $t->name, $cats ) : [];
		$keywords = is_array($tags) ? array_map( fn($t)=> $t->name, $tags ) : [];

		// Build base
		$schema = [
			'@context'          => 'https://schema.org',
			'@type'             => 'BlogPosting', // more specific than Article for blogs
			'@id'               => trailingslashit($permalink) . '#article',
			'mainEntityOfPage'  => $permalink,
			'url'               => $permalink,
			'headline'          => wp_strip_all_tags( $title ),
			'description'       => wp_strip_all_tags( $excerpt ),
			'datePublished'     => get_the_date( DATE_W3C, $post ),
			'dateModified'      => get_the_modified_date( DATE_W3C, $post ),
			'author'            => array_filter([
				'@type' => 'Person',
				'name'  => $author_name ? wp_strip_all_tags($author_name) : null,
				// Optionally: 'url' => get_author_posts_url( $post->post_author ),
			]),
			'publisher'         => array_filter([
				'@type' => 'Organization',
				'name'  => wp_strip_all_tags( $org_name ),
				'url'   => esc_url( $org_url ),
				'logo'  => $logo_url ? array_filter([
					'@type'  => 'ImageObject',
					'url'    => esc_url( $logo_url ),
					'width'  => is_array($logo_meta) ? (int) $logo_meta[1] : null,
					'height' => is_array($logo_meta) ? (int) $logo_meta[2] : null,
				]) : null,
			]),
		];

		if ( $feat_img ) {
			// Google prefers an array of images; one is fine.
			$schema['image'] = [ esc_url( $feat_img ) ];
		}

		if ( ! empty($sections) ) $schema['articleSection'] = $sections;
		if ( ! empty($keywords) ) $schema['keywords']       = implode(', ', $keywords);

		// Optional: rough wordCount (not required)
		$content_text = function_exists('myls_get_post_plain_text') ? myls_get_post_plain_text( $post->ID ) : wp_strip_all_tags( get_post_field( 'post_content', $post ) );
		if ( $content_text ) {
			$word_count = str_word_count( $content_text );
			if ( $word_count > 0 ) $schema['wordCount'] = $word_count;
		}

		/**
		 * Allow customization of the outgoing BlogPosting JSON-LD.
		 * @param array  $schema
		 * @param WP_Post $post
		 */
		return apply_filters( 'myls_blogposting_schema', $schema, $post );
	}
}

/** Print JSON-LD into <head> for single posts */
if ( ! function_exists('myls_blogposting_print_head') ) {
	function myls_blogposting_print_head() {
		if ( is_admin() || wp_doing_ajax() || ! is_singular('post') ) return;

		$post = get_queried_object();
		if ( ! ($post instanceof WP_Post) ) return;
		if ( ! myls_blogposting_should_render( $post ) ) return;

		$schema = myls_blogposting_build_schema( $post );
		if ( empty($schema) ) return;

		echo "\n<!-- MYLS BlogPosting JSON-LD (head) -->\n";
		echo '<script type="application/ld+json">' . "\n";
		echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n";
		echo "</script>\n";
	}
	add_action( 'wp_head', 'myls_blogposting_print_head', 20 );
}
