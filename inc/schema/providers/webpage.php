<?php
/**
 * WebPage Schema Provider — JSON-LD output
 *
 * Emits a WebPage node on all singular pages (except video CPT).
 * Links to LocalBusiness via isPartOf and Person via author when enabled.
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

	$node = [
		'@type'        => 'WebPage',
		'@id'          => trailingslashit( $permalink ) . '#webpage',
		'name'         => get_the_title( $post_id ),
		'url'          => $permalink,
		'dateModified' => get_the_modified_date( 'c', $post_id ),
	];

	// isPartOf — link to LocalBusiness if one exists in the graph
	$lb_id = '';
	foreach ( $graph as $gn ) {
		if ( is_array( $gn ) && ! empty( $gn['@id'] ) ) {
			$t = is_array( $gn['@type'] ?? '' ) ? ( $gn['@type'][0] ?? '' ) : ( $gn['@type'] ?? '' );
			if ( stripos( $t, 'LocalBusiness' ) !== false ) {
				$lb_id = $gn['@id'];
				break;
			}
		}
	}
	if ( ! $lb_id ) {
		// Fallback: use site-level LocalBusiness @id convention
		$lb_id = trailingslashit( $site_url ) . '#localbusiness';
	}
	$node['isPartOf'] = [ '@id' => $lb_id ];

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
} );

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
