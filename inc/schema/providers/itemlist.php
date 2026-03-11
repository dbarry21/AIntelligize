<?php
/**
 * ItemList Schema Provider
 * ------------------------------------------------------------
 * Emits ItemList nodes into the unified @graph for:
 *   1. Services   — from the `service` CPT
 *   2. Service Areas — from root-level `service_area` CPT posts
 *
 * Fires on the front page only (where the business entity lives).
 * AI systems use ItemList to understand structured offerings and
 * geographic coverage, improving AI Overview eligibility.
 *
 * Toggle: disable via filter
 *   add_filter('myls_itemlist_services_enabled', '__return_false');
 *   add_filter('myls_itemlist_service_areas_enabled', '__return_false');
 *
 * @since 7.9.0
 */

if ( ! defined('ABSPATH') ) exit;

add_filter( 'myls_schema_graph', function ( array $graph ) : array {

	if ( is_admin() || is_feed() || is_preview() ) return $graph;
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return $graph;

	// Only emit on the front page — the business entity hub.
	if ( ! is_front_page() ) return $graph;

	// ── 1. Services ItemList ─────────────────────────────────────────
	if ( apply_filters( 'myls_itemlist_services_enabled', true ) && post_type_exists( 'service' ) ) {

		$services = get_posts( [
			'post_type'        => 'service',
			'post_status'      => 'publish',
			'post_parent'      => 0,
			'posts_per_page'   => 100,
			'orderby'          => 'menu_order title',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		] );

		if ( ! empty( $services ) ) {
			$svc_items = [];
			$pos = 0;
			foreach ( $services as $svc ) {
				$pos++;
				$svc_items[] = [
					'@type'    => 'ListItem',
					'position' => $pos,
					'item'     => [
						'@type' => 'Service',
						'name'  => wp_specialchars_decode( get_the_title( $svc->ID ), ENT_QUOTES ),
						'url'   => get_permalink( $svc->ID ),
					],
				];
			}

			$org_name = wp_specialchars_decode(
				get_option( 'myls_org_name', get_bloginfo( 'name' ) ),
				ENT_QUOTES
			);

			$graph[] = [
				'@type'           => 'ItemList',
				'@id'             => home_url( '/#services-list' ),
				'name'            => $org_name . ' Services',
				'itemListElement' => $svc_items,
			];
		}
	}

	// ── 2. Service Areas ItemList ────────────────────────────────────
	if ( apply_filters( 'myls_itemlist_service_areas_enabled', true ) && post_type_exists( 'service_area' ) ) {

		$areas = get_posts( [
			'post_type'        => 'service_area',
			'post_status'      => 'publish',
			'post_parent'      => 0,
			'posts_per_page'   => 100,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		] );

		if ( ! empty( $areas ) ) {
			$area_items = [];
			$pos = 0;
			foreach ( $areas as $sa ) {
				$pos++;
				$city_name = wp_specialchars_decode( get_the_title( $sa->ID ), ENT_QUOTES );
				// Strip trailing state abbreviation
				// Handles "Bradenton FL", "Bradenton, FL", "Apollo Beach, FL"
				$city_clean = preg_replace( '/[,\s]+[A-Z]{2}$/i', '', $city_name );

				$area_items[] = [
					'@type'    => 'ListItem',
					'position' => $pos,
					'item'     => [
						'@type' => 'City',
						'name'  => $city_clean,
						'url'   => get_permalink( $sa->ID ),
					],
				];
			}

			$graph[] = [
				'@type'           => 'ItemList',
				'@id'             => home_url( '/#service-areas-list' ),
				'name'            => 'Service Areas',
				'itemListElement' => $area_items,
			];
		}
	}

	return $graph;

}, 55 ); // Priority 55: after Service (50), before other late providers
