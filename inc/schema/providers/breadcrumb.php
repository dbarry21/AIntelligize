<?php
/**
 * AIntelligize — BreadcrumbList JSON-LD (unified @graph)
 *
 * Emits a BreadcrumbList node on all singular front-end pages.
 * Builds the trail: Home > [Parent(s)] > Current Page.
 *
 * Toggle: myls_schema_breadcrumb_enabled (default '1')
 *
 * @since 7.8.95
 */

if ( ! defined('ABSPATH') ) exit;

add_filter( 'myls_schema_graph', function ( array $graph ) : array {

	if ( is_admin() || is_feed() || is_preview() ) return $graph;
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return $graph;
	if ( ! is_singular() ) return $graph;
	if ( get_option( 'myls_schema_breadcrumb_enabled', '1' ) !== '1' ) return $graph;

	// Skip on front page — breadcrumb trail is just "Home" which is redundant
	if ( is_front_page() ) return $graph;

	$post = get_queried_object();
	if ( ! ( $post instanceof WP_Post ) ) return $graph;

	$items    = [];
	$position = 1;

	// 1. Home (decode HTML entities — JSON-LD needs plain text)
	// Use myls_org_name for full legal name (e.g. including LLC suffix),
	// with ssseo_ legacy fallback, then get_bloginfo('name') as last resort.
	$root_name = wp_specialchars_decode(
		trim( (string) get_option( 'myls_org_name',
			get_option( 'ssseo_organization_name', get_bloginfo( 'name' ) )
		) ),
		ENT_QUOTES
	);
	$items[] = [
		'@type'    => 'ListItem',
		'position' => $position++,
		'name'     => $root_name,
		'item'     => home_url( '/' ),
	];

	// 2. Post type archive (for CPTs with has_archive)
	$pt_obj = get_post_type_object( $post->post_type );
	if ( $pt_obj && $pt_obj->has_archive ) {
		$archive_url = get_post_type_archive_link( $post->post_type );
		if ( $archive_url ) {
			$items[] = [
				'@type'    => 'ListItem',
				'position' => $position++,
				'name'     => $pt_obj->labels->name ?? $pt_obj->label,
				'item'     => $archive_url,
			];
		}
	}

	// 3. For posts: primary category
	if ( $post->post_type === 'post' ) {
		$cats = get_the_category( $post->ID );
		if ( ! empty( $cats ) ) {
			// Prefer Yoast primary category if available
			$primary_id = (int) get_post_meta( $post->ID, '_yoast_wpseo_primary_category', true );
			$cat = null;
			if ( $primary_id ) {
				foreach ( $cats as $c ) {
					if ( $c->term_id === $primary_id ) { $cat = $c; break; }
				}
			}
			if ( ! $cat ) $cat = $cats[0];

			$cat_url = get_category_link( $cat->term_id );
			if ( $cat_url ) {
				$items[] = [
					'@type'    => 'ListItem',
					'position' => $position++,
					'name'     => $cat->name,
					'item'     => $cat_url,
				];
			}
		}
	}

	// 4. Hierarchical parents (pages, CPTs)
	if ( is_post_type_hierarchical( $post->post_type ) && $post->post_parent ) {
		$ancestors = array_reverse( get_post_ancestors( $post->ID ) );
		foreach ( $ancestors as $anc_id ) {
			$anc_url = get_permalink( $anc_id );
			if ( $anc_url ) {
				$items[] = [
					'@type'    => 'ListItem',
					'position' => $position++,
					'name'     => get_the_title( $anc_id ),
					'item'     => $anc_url,
				];
			}
		}
	}

	// 5. Current page (last item)
	$items[] = [
		'@type'    => 'ListItem',
		'position' => $position,
		'name'     => get_the_title( $post ),
		'item'     => get_permalink( $post ),
	];

	$node = [
		'@type'           => 'BreadcrumbList',
		'@id'             => trailingslashit( get_permalink( $post ) ) . '#breadcrumb',
		'itemListElement' => $items,
	];

	$graph[] = apply_filters( 'myls_breadcrumb_schema_node', $node, $post );

	return $graph;

}, 70 ); // Priority 70: after all content providers
