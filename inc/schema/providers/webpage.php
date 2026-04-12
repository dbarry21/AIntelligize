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

	// Homepage: prefer org description as the authoritative schema description.
	if ( is_front_page() ) {
		$org_desc = trim( wp_specialchars_decode(
			(string) get_option( 'myls_org_description',
				get_option( 'ssseo_organization_description', '' ) ),
			ENT_QUOTES
		) );
		if ( $org_desc !== '' ) {
			$page_desc = $org_desc;
		}
	}

	if ( $page_desc === '' && has_excerpt( $post_id ) ) {
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
		'name'          => is_front_page()
			? wp_specialchars_decode(
				trim( (string) get_option( 'myls_org_name',
					get_option( 'ssseo_organization_name', get_bloginfo( 'name' ) )
				) ),
				ENT_QUOTES
			  )
			: wp_specialchars_decode( get_the_title( $post_id ), ENT_QUOTES ),
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

	// ── hasPart: declare sub-entities that are components of this WebPage ──
	$has_part = [];

	// 1. HowTo — scan graph (priority 55, already present)
	foreach ( $graph as $gn ) {
		if ( ! is_array( $gn ) ) continue;
		if ( ( $gn['@type'] ?? '' ) !== 'HowTo' ) continue;
		$howto_id = (string) ( $gn['@id'] ?? '' );
		if ( $howto_id !== '' ) {
			$has_part[] = [ '@id' => $howto_id ];
		}
	}

	// 2. VideoObject — scan graph (priority 46, already present)
	// Collect all VideoObject @ids — detector only runs on current page.
	foreach ( $graph as $gn ) {
		if ( ! is_array( $gn ) ) continue;
		if ( ( $gn['@type'] ?? '' ) !== 'VideoObject' ) continue;
		$vid_id = (string) ( $gn['@id'] ?? '' );
		if ( $vid_id !== '' ) {
			$has_part[] = [ '@id' => $vid_id ];
		}
	}

	// 3. FAQPage — compute @id directly (priority 60, NOT in graph yet)
	// Pattern: trailingslashit( $permalink ) . '#faq'  (confirmed faq.php:169)
	if ( get_option( 'myls_faq_enabled', '0' ) === '1' ) {
		$has_faq_items = false;

		if ( function_exists( 'myls_get_faq_items_meta' ) ) {
			$faq_check     = myls_get_faq_items_meta( $post_id );
			$has_faq_items = is_array( $faq_check ) && ! empty( $faq_check );
		}
		if ( ! $has_faq_items ) {
			$faq_raw       = get_post_meta( $post_id, '_myls_faq_items', true );
			$has_faq_items = is_array( $faq_raw ) && ! empty( $faq_raw );
		}
		if ( ! $has_faq_items && function_exists( 'have_rows' ) ) {
			$has_faq_items = have_rows( 'faq_items', $post_id );
		}

		if ( $has_faq_items ) {
			$has_part[] = [ '@id' => trailingslashit( $permalink ) . '#faq' ];
		}
	}

	// Add hasPart to node only when at least one sub-entity was found.
	if ( ! empty( $has_part ) ) {
		$node['hasPart'] = $has_part;
	}

	// ── breadcrumb property ──────────────────────────────────────────────
	// Add breadcrumb @id reference if breadcrumb schema is enabled.
	// Uses same @id pattern as breadcrumb.php.
	if ( get_option( 'myls_schema_breadcrumb_enabled', '1' ) === '1' ) {
		$breadcrumb_id = is_front_page()
			? home_url( '/#breadcrumb' )
			: trailingslashit( $permalink ) . '#breadcrumb';
		$node['breadcrumb'] = [ '@id' => $breadcrumb_id ];
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
