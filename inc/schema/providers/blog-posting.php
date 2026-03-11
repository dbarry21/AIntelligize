<?php
/**
 * MYLS – BlogPosting JSON-LD — unified @graph
 *
 * Emits a BlogPosting node on single blog posts.
 * Uses @id references for publisher (Organization) and author (Person if matched).
 *
 * Toggle: myls_schema_blog_enabled === '1'
 *
 * @since 7.8.93 Moved from standalone wp_head emitter to myls_schema_graph filter.
 */

if ( ! defined('ABSPATH') ) exit;

/** Should we render on this request? */
if ( ! function_exists('myls_blogposting_should_render') ) {
	function myls_blogposting_should_render( WP_Post $post ) : bool {
		if ( get_option('myls_schema_blog_enabled','0') !== '1' ) return false;
		return is_singular('post');
	}
}

/** Build the BlogPosting schema array (graph node, no @context). */
if ( ! function_exists('myls_blogposting_build_schema') ) {
	function myls_blogposting_build_schema( WP_Post $post ) : array {
		$permalink = get_permalink( $post );
		$title     = get_the_title( $post );
		$excerpt   = get_the_excerpt( $post );

		// Author — try to match WordPress author to a Person profile for @id ref
		$author_name = get_the_author_meta( 'display_name', $post->post_author );
		$author_node = null;

		$person_profiles = get_option( 'myls_person_profiles', [] );
		if ( is_array( $person_profiles ) ) {
			foreach ( $person_profiles as $p ) {
				if ( empty( $p['name'] ) ) continue;
				if ( ( $p['enabled'] ?? '1' ) !== '1' ) continue;
				// Match by name (case-insensitive)
				if ( strcasecmp( trim( $p['name'] ), trim( $author_name ) ) === 0 ) {
					$person_slug = sanitize_title( $p['name'] );
					$author_node = [ '@id' => home_url( '/#person-' . $person_slug ) ];
					break;
				}
			}
		}
		if ( ! $author_node && $author_name ) {
			$author_node = [
				'@type' => 'Person',
				'name'  => wp_strip_all_tags( $author_name ),
			];
		}

		// Publisher — @id reference to Organization
		$publisher = [ '@id' => home_url( '/#organization' ) ];

		// Featured image
		$feat_img = has_post_thumbnail( $post ) ? get_the_post_thumbnail_url( $post, 'full' ) : '';

		// Categories / Tags
		$cats = get_the_terms( $post, 'category' );
		$tags = get_the_terms( $post, 'post_tag' );
		$sections = is_array($cats) ? array_map( fn($t)=> $t->name, $cats ) : [];
		$keywords = is_array($tags) ? array_map( fn($t)=> $t->name, $tags ) : [];

		$schema = [
			'@type'             => 'BlogPosting',
			'@id'               => trailingslashit($permalink) . '#article',
			'mainEntityOfPage'  => [ '@id' => trailingslashit($permalink) . '#webpage' ],
			'url'               => $permalink,
			'headline'          => wp_strip_all_tags( $title ),
			'description'       => wp_strip_all_tags( $excerpt ),
			'datePublished'     => get_the_date( DATE_W3C, $post ),
			'dateModified'      => get_the_modified_date( DATE_W3C, $post ),
			'author'            => $author_node,
			'publisher'         => $publisher,
			'isPartOf'          => [ '@id' => home_url( '/#website' ) ],
		];

		if ( $feat_img ) {
			$schema['image'] = [ esc_url( $feat_img ) ];
		}

		if ( ! empty($sections) ) $schema['articleSection'] = $sections;
		if ( ! empty($keywords) ) $schema['keywords']       = implode(', ', $keywords);

		// Word count
		$content_text = function_exists('myls_get_post_plain_text')
			? myls_get_post_plain_text( $post->ID )
			: wp_strip_all_tags( get_post_field( 'post_content', $post ) );
		if ( $content_text ) {
			$word_count = str_word_count( $content_text );
			if ( $word_count > 0 ) $schema['wordCount'] = $word_count;
		}

		return apply_filters( 'myls_blogposting_schema', $schema, $post );
	}
}

/**
 * BlogPosting → unified @graph
 */
add_filter( 'myls_schema_graph', function ( array $graph ) {

	if ( is_admin() || wp_doing_ajax() || ! is_singular('post') ) return $graph;
	if ( is_feed() || is_preview() ) return $graph;
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return $graph;

	$post = get_queried_object();
	if ( ! ( $post instanceof WP_Post ) ) return $graph;
	if ( ! myls_blogposting_should_render( $post ) ) return $graph;

	$node = myls_blogposting_build_schema( $post );
	if ( ! empty( $node ) ) {
		$graph[] = $node;
	}

	return $graph;
}, 55 ); // Priority 55: after Service (50), before WebPage/FAQ (60)
