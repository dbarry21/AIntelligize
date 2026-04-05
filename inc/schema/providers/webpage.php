<?php
/**
 * WebPage Schema Provider — JSON-LD output
 *
 * Emits a WebPage node on all singular pages (except video CPT).
 * Links to WebSite via isPartOf, LocalBusiness via about, Person via author.
 *
 * Toggle: myls_schema_webpage_enabled === '1'
 *
 * @since 7.8.77
 */

if ( ! defined('ABSPATH') ) exit;

add_filter( 'myls_schema_graph', function ( array $graph ) {

	// Toggle
	if ( get_option( 'myls_schema_webpage_enabled', '0' ) !== '1' ) {
		return $graph;
	}

	// Only singular front-end pages, skip video CPT
	if ( is_admin() || wp_doing_ajax() || ! is_singular() ) {
		return $graph;
	}
	if ( is_singular( 'video' ) ) {
		return $graph;
	}

	$post_id = (int) get_queried_object_id();
	if ( $post_id <= 0 ) return $graph;

	$permalink = get_permalink( $post_id );
	$site_url  = home_url( '/' );

	// Description: post excerpt → page meta description (Yoast/RankMath) → omit
	$page_desc = '';
	if ( has_excerpt( $post_id ) ) {
		$page_desc = wp_strip_all_tags( get_the_excerpt( $post_id ) );
	}
	if ( $page_desc === '' ) {
		// Try Yoast meta description
		$page_desc = trim( (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) );
	}
	if ( $page_desc === '' ) {
		// Try RankMath meta description
		$page_desc = trim( (string) get_post_meta( $post_id, 'rank_math_description', true ) );
	}

	$node = [
		'@type'         => 'WebPage',
		'@id'           => trailingslashit( $permalink ) . '#webpage',
		'name'          => get_the_title( $post_id ),
		'url'           => $permalink,
		'datePublished' => get_the_date( 'c', $post_id ),
		'dateModified'  => get_the_modified_date( 'c', $post_id ),
	];

	if ( $page_desc !== '' ) {
		$node['description'] = $page_desc;
	}

	// isPartOf — link to WebSite (correct range for CreativeWork property)
	$node['isPartOf'] = [ '@id' => home_url( '/#website' ) ];

	// about — link to Service entity on service pages, else LocalBusiness
	$about_id = '';

	// First: check if there's a Service node in the graph for this page
	foreach ( $graph as $gn ) {
		if ( is_array( $gn ) && ( $gn['@type'] ?? '' ) === 'Service' && ! empty( $gn['@id'] ) ) {
			$about_id = $gn['@id'];
			break;
		}
	}

	// Fallback: link to LocalBusiness by @id (not @type string match).
	// This correctly handles RoofingContractor and all other LocalBusiness subtypes
	// since @id is always /#localbusiness regardless of @type value.
	if ( ! $about_id ) {
		$lb_id = home_url( '/#localbusiness' );
		foreach ( $graph as $gn ) {
			if ( is_array( $gn ) && isset( $gn['@id'] ) && $gn['@id'] === $lb_id ) {
				$about_id = $lb_id;
				break;
			}
		}
	}

	// Final fallback to Organization if LocalBusiness not in graph
	if ( ! $about_id ) {
		$org_id = home_url( '/#organization' );
		foreach ( $graph as $gn ) {
			if ( is_array( $gn ) && isset( $gn['@id'] ) && $gn['@id'] === $org_id ) {
				$about_id = $org_id;
				break;
			}
		}
	}

	if ( $about_id ) {
		$node['about'] = [ '@id' => $about_id ];
	}

	// author — link to Person if Person schema is enabled and profiles exist
	$person_profiles = get_option( 'myls_person_profiles', [] );
	if ( is_array( $person_profiles ) && ! empty( $person_profiles ) ) {
		// Use the first enabled person as the author
		foreach ( $person_profiles as $p ) {
			if ( empty( $p['name'] ) ) continue;
			if ( ( $p['enabled'] ?? '1' ) !== '1' ) continue;

			$person_slug = sanitize_title( $p['name'] );
			$person_id   = home_url( '/#person-' . $person_slug );
			$node['author'] = [ '@id' => $person_id ];
			break;
		}
	}

	$graph[] = apply_filters( 'myls_webpage_schema_node', $node, $post_id );
	return $graph;
}, 60 ); // Priority 60: run after all entity providers (LB 8, Org 10, Service 50)

/**
 * Override Yoast og:type for service pages.
 *
 * Yoast defaults all non-homepage pages to og:type = 'article'.
 * Service pages should be 'website', not 'article'.
 * Blog posts remain 'article'. Homepage remains 'website'.
 *
 * @since 7.8.77
 */
add_filter( 'wpseo_opengraph_type', function ( $type ) {
	// Service CPT pages → 'website'
	if ( is_singular( 'service' ) ) {
		return 'website';
	}

	// Pages assigned a LocalBusiness location (service-like pages) → 'website'
	if ( is_singular( 'page' ) && function_exists( 'myls_localbusiness_is_assigned_to_post' ) ) {
		$post_id = (int) get_queried_object_id();
		if ( $post_id > 0 && myls_localbusiness_is_assigned_to_post( $post_id ) ) {
			return 'website';
		}
	}

	return $type;
} );
