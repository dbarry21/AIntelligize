<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * LocalBusiness Schema Provider + Emitter (meta-aware)
 * ------------------------------------------------------------
 * - Provider respects per-post assignment via post meta:
 *     _myls_lb_assigned = '1'
 *     _myls_lb_loc_index = {int}
 * - Falls back to scanning the saved option if meta is missing/stale.
 * - Emits a <meta name="myls-localbusiness" ...> flag in <head>
 *   so you can easily detect assignment in templates or scripts.
 * - Emits JSON-LD in <head> only for assigned pages.
 *
 * Recommended: also include the sync utility:
 *   require_once MYLS_PATH . 'inc/schema/localbusiness-sync.php';
 * That utility mirrors option assignments to the post meta above.
 */

/**
 * Build LocalBusiness schema array from a single saved location.
 *
 * @param array   $loc  A single location array (from myls_lb_locations).
 * @param WP_Post $post The current singular post object.
 * @return array JSON-LD array for LocalBusiness
 */
// inc/schema/providers/localbusiness.php

if ( ! function_exists('myls_lb_build_member_of') ) {
	/**
	 * Build memberOf array from saved memberships option.
	 * Returns array of Organization objects or null.
	 */
	function myls_lb_build_member_of() : ?array {
		$memberships = get_option('myls_org_memberships', []);
		if ( ! is_array($memberships) || empty($memberships) ) return null;

		$out = [];
		foreach ( $memberships as $m ) {
			if ( ! is_array($m) || empty($m['name']) ) continue;
			$org = [
				'@type' => 'Organization',
				'name'  => sanitize_text_field( $m['name'] ),
			];
			if ( ! empty($m['url']) )         $org['url']         = esc_url_raw( $m['url'] );
			if ( ! empty($m['logo_url']) )    $org['logo']        = esc_url_raw( $m['logo_url'] );
			if ( ! empty($m['description']) ) $org['description'] = sanitize_text_field( $m['description'] );
			$out[] = $org;
		}
		return ! empty($out) ? $out : null;
	}
}

if ( ! function_exists('myls_lb_build_schema_from_location') ) {
	function myls_lb_build_schema_from_location( array $loc, WP_Post $post ) : array {
		$org_name = get_option( 'myls_org_name', get_bloginfo( 'name' ) );

		$awards = get_option('myls_org_awards', []);
		if ( ! is_array($awards) ) $awards = [];
		$awards = array_values( array_filter( array_map( function( $a ) {
			return wp_specialchars_decode( trim( $a ), ENT_QUOTES );
		}, $awards ) ) );

		$certs = get_option('myls_org_certifications', []);
		if ( ! is_array($certs) ) $certs = [];
		$certs = array_values( array_filter( array_map( function( $c ) {
			return wp_specialchars_decode( trim( $c ), ENT_QUOTES );
		}, $certs ) ) );

		// Image fallback chain (first non-empty wins):
		//   1. Per-location Business Image URL
		//   2. Org logo (WordPress attachment)
		//   3. Org image URL (direct URL field from Organization settings)
		$loc_img  = trim( (string) ( $loc['image_url'] ?? '' ) );
		$logo_id  = (int) get_option( 'myls_org_logo_id', 0 );
		$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';
		// Business photo: check dedicated business photo field first, then legacy org_image_url
		$biz_photo_url = trim( (string) get_option( 'myls_lb_business_photo_url', '' ) );
		$org_image_url = $biz_photo_url !== '' ? $biz_photo_url
			: trim( (string) get_option( 'myls_org_image_url', '' ) );

		// image: business photo — NOT the logo. Logo goes in the logo property separately.
		// Priority: per-location Business Image URL → site-wide Business Photo URL → omit.
		// Never fall back to the logo — using the logo as image sends a conflicting signal.
		$image_prop = null;
		if ( $loc_img !== '' ) {
			$image_prop = esc_url( $loc_img );
		} elseif ( $org_image_url !== '' ) {
			$image_prop = esc_url( $org_image_url );
		}
		// If neither is set, image_prop remains null and is excluded by array_filter().

		// logo: always use the org logo attachment (ImageObject with dimensions).
		// This is separate from `image` — logo is the brand mark, image is a photo.
		$logo_prop = null;
		if ( $logo_id ) {
			$logo_obj = [ '@type' => 'ImageObject', 'url' => esc_url( $logo_url ) ];
			$logo_meta = wp_get_attachment_metadata( $logo_id );
			if ( is_array( $logo_meta ) ) {
				if ( ! empty( $logo_meta['width'] ) )  $logo_obj['width']  = (int) $logo_meta['width'];
				if ( ! empty( $logo_meta['height'] ) ) $logo_obj['height'] = (int) $logo_meta['height'];
			}
			$logo_prop = $logo_obj;
		} elseif ( $org_image_url !== '' ) {
			// Fallback: use org image URL string if no attachment ID
			$logo_prop = esc_url( $org_image_url );
		}

		// priceRange fallback chain:
		//   1. Per-location price field
		//   2. Site-wide default (myls_lb_default_price_range)
		$loc_price     = sanitize_text_field( $loc['price'] ?? '' );
		$default_price = trim( (string) get_option( 'myls_lb_default_price_range', '' ) );
		$price_prop    = $loc_price !== '' ? $loc_price : $default_price;

		// Opening hours
		$hours = [];
		foreach ( (array) ( $loc['hours'] ?? [] ) as $h ) {
			$d = trim( (string) ( $h['day']   ?? '' ) );
			$o = trim( (string) ( $h['open']  ?? '' ) );
			$c = trim( (string) ( $h['close'] ?? '' ) );
			if ( $d && $o && $c ) {
				$hours[] = [
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => $d,
					'opens'     => $o,
					'closes'    => $c,
				];
			}
		}

		$org_url = get_option( 'myls_org_url', home_url('/') );

		// knowsAbout: merged Service CPT titles + Service schema name field.
		// Tells AI crawlers exactly which topics/services this business covers.
		$knows_about = function_exists('myls_get_knows_about') ? myls_get_knows_about() : [];

		// employee + founder — Person @id references on all pages (not front-page-only)
		$employee = null;
		$person_profiles = get_option( 'myls_person_profiles', [] );
		if ( is_array( $person_profiles ) && ! empty( $person_profiles ) ) {
			$emp_refs = [];
			foreach ( $person_profiles as $fp ) {
				if ( empty( $fp['name'] ) || ( $fp['enabled'] ?? '1' ) !== '1' ) continue;
				$emp_refs[] = [ '@id' => home_url( '/#person-' . sanitize_title( $fp['name'] ) ) ];
			}
			if ( ! empty( $emp_refs ) ) {
				$employee = count( $emp_refs ) === 1 ? $emp_refs[0] : $emp_refs;
			}
		}

		// areaServed: pull root-level service_area CPT posts as City objects.
		// Tells AI systems exactly which cities/areas the business covers.
		$area_served = null;
		$sa_roots = get_posts( [
			'post_type'        => 'service_area',
			'post_status'      => 'publish',
			'post_parent'      => 0,
			'posts_per_page'   => 100,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		] );
		if ( ! empty( $sa_roots ) ) {
			$area_served = [];
			foreach ( $sa_roots as $sa ) {
				$city_name = html_entity_decode(
				get_the_title( $sa->ID ),
				ENT_QUOTES | ENT_HTML5,
				'UTF-8'
			);
				// Strip trailing state abbreviation for cleaner city name
				// Handles "Bradenton FL", "Bradenton, FL", "Apollo Beach, FL"
				$city_clean = preg_replace( '/[,\s]+[A-Z]{2}$/i', '', $city_name );
				$area_served[] = [
					'@type' => 'City',
					'name'  => $city_clean,
					'url'   => get_permalink( $sa->ID ),
				];
			}
		}

		// hasOfferCatalog: structured service catalog from the service CPT.
		// Tells AI systems exactly what services this business offers.
		$offer_catalog = null;
		if ( post_type_exists( 'service' ) ) {
			$svc_posts = get_posts( [
				'post_type'        => 'service',
				'post_status'      => 'publish',
				'post_parent'      => 0,
				'posts_per_page'   => 100,
				'orderby'          => 'menu_order title',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			] );
			if ( ! empty( $svc_posts ) ) {
				$offer_items = [];
				foreach ( $svc_posts as $svc ) {
					$offer_items[] = [
						'@type'        => 'Offer',
						'itemOffered'  => [
							'@type' => 'Service',
							'name'  => wp_specialchars_decode( get_the_title( $svc->ID ), ENT_QUOTES ),
							'url'   => get_permalink( $svc->ID ),
						],
					];
				}
				$offer_catalog = [
					'@type'           => 'OfferCatalog',
					'name'            => wp_specialchars_decode( trim( $loc['name'] ?? $org_name ), ENT_QUOTES ) . ' Services',
					'itemListElement' => $offer_items,
				];
			}
		}

		// Decode HTML entities — JSON-LD strings must be plain text, not HTML-encoded.
		$lb_name = wp_specialchars_decode( trim( $loc['name'] ?? $org_name ), ENT_QUOTES );

		return array_filter( [
			'@type'    => 'LocalBusiness',
			'@id'      => trailingslashit( home_url( '/' ) ) . '#localbusiness',

			// Only Business Image URL, else Org Logo
			'image'    => $image_prop,
			'logo'     => $logo_prop,

			'name'       => $lb_name,
			'url'        => esc_url( $org_url ),
			'telephone'  => function_exists('myls_normalize_phone_e164')
				? myls_normalize_phone_e164( trim( $loc['phone'] ?? '' ) )
				: trim( $loc['phone'] ?? '' ),
			'priceRange' => $price_prop,
			'award'      => ( $awards ? $awards : null ),
			'hasCertification' => ( $certs ? array_map(function($c){ return ['@type'=>'Certification','name'=>$c]; }, $certs) : null ),
			'knowsAbout' => $knows_about ?: null,
			'areaServed' => $area_served,
			'hasOfferCatalog' => $offer_catalog,
			'memberOf' => myls_lb_build_member_of(),
			'address'  => array_filter( [
				'@type'           => 'PostalAddress',
				'streetAddress'   => wp_specialchars_decode( trim( $loc['street'] ?? '' ), ENT_QUOTES ),
				'addressLocality' => wp_specialchars_decode( trim( $loc['city'] ?? '' ), ENT_QUOTES ),
				'addressRegion'   => trim( $loc['state'] ?? '' ),
				'postalCode'      => trim( $loc['zip'] ?? '' ),
				'addressCountry'  => trim( $loc['country'] ?? 'US' ),
			] ),
			'geo' => function_exists('myls_build_geo_coordinates')
				? myls_build_geo_coordinates( $loc['lat'] ?? '', $loc['lng'] ?? '' )
				: null,
			'openingHoursSpecification' => $hours ?: null,
			'aggregateRating' => function_exists('myls_schema_build_aggregate_rating') ? myls_schema_build_aggregate_rating() : null,

			// employee + founder: Person @id references (all pages)
			'employee' => $employee,
			'founder'  => $employee,  // owners are founders — same @id refs

			// Link to Organization entity by @id reference (not inline duplicate)
			'parentOrganization' => [ '@id' => home_url( '/#organization' ) ],

			// ContactPoint for customer service (mirrors Organization pattern)
			'contactPoint' => ( trim( $loc['phone'] ?? '' ) !== '' ) ? [[
				'@type'       => 'ContactPoint',
				'telephone'   => function_exists('myls_normalize_phone_e164')
					? myls_normalize_phone_e164( trim( $loc['phone'] ?? '' ) )
					: trim( $loc['phone'] ?? '' ),
				'contactType' => 'customer service',
			]] : null,
		] );
	}
}


/**
 * Read saved LocalBusiness locations (option) with object cache.
 *
 * @return array
 */
function myls_lb_get_locations_cached() : array {
	$locs = wp_cache_get( 'myls_lb_locations_cache', 'myls' );
	if ( ! is_array( $locs ) ) {
		$locs = (array) get_option( 'myls_lb_locations', [] );
		wp_cache_set( 'myls_lb_locations_cache', $locs, 'myls', 300 ); // 5 minutes
	}
	return $locs;
}

/**
 * Provider: LocalBusiness for a singular post (meta-aware, strict by default)
 * Return array (JSON-LD) or null. No output here.
 *
 * @param WP_Post $post
 * @return array|null
 */
function myls_schema_localbusiness_for_post( WP_Post $post ) : ?array {
	if ( ! ( $post instanceof WP_Post ) ) return null;

	// Try post meta fast path
	$is_assigned = get_post_meta( $post->ID, '_myls_lb_assigned', true );
	$loc_index   = get_post_meta( $post->ID, '_myls_lb_loc_index', true );

	$locs = myls_lb_get_locations_cached();
	if ( empty( $locs ) ) return null;

	// If meta states assigned and index looks valid, build from that location
	if ( $is_assigned === '1' && $loc_index !== '' ) {
		$i = (int) $loc_index;
		if ( isset( $locs[ $i ] ) && is_array( $locs[ $i ] ) ) {
			return myls_lb_build_schema_from_location( $locs[ $i ], $post );
		}
		// If index is stale (locations re-ordered), fall through to scan.
	}

	// Fallback: strict scan of assignments stored in the option
	$post_id = (int) $post->ID;
	foreach ( $locs as $loc ) {
		$pages = array_map( 'absint', (array) ( $loc['pages'] ?? [] ) );
		if ( $pages && in_array( $post_id, $pages, true ) ) {
			return myls_lb_build_schema_from_location( $loc, $post );
		}
	}

	// Auto-fallback: use the first/only location when no explicit assignment.
	// If only one location exists, it's the obvious choice.
	// Multi-location: fall back to first; override via filter returning false.
	if ( apply_filters( 'myls_localbusiness_fallback_to_first', true ) && isset( $locs[0] ) ) {
		return myls_lb_build_schema_from_location( $locs[0], $post );
	}

	return null;
}

/**
 * Robust assignment checker (used by meta flag AND any other logic).
 * - Prefers post meta for O(1) checks
 * - Falls back to scanning option if meta missing/stale
 *
 * @param int $post_id
 * @return bool
 */
if ( ! function_exists('myls_localbusiness_is_assigned_to_post') ) {
	function myls_localbusiness_is_assigned_to_post( int $post_id ) : bool {
		if ( $post_id <= 0 ) return false;

		// Fast path
		if ( get_post_meta( $post_id, '_myls_lb_assigned', true ) === '1' ) {
			return true;
		}

		// Fallback scan
		$locs = myls_lb_get_locations_cached();
		if ( empty( $locs ) ) return false;

		foreach ( $locs as $loc ) {
			$pages = array_map( 'absint', (array) ( $loc['pages'] ?? [] ) );
			if ( ! empty( $pages ) && in_array( $post_id, $pages, true ) ) {
				return true;
			}
		}
		return false;
	}
}

/**
 * Emit a meta flag in <head> indicating whether LocalBusiness applies.
 * Example:
 *   <meta name="myls-localbusiness" content="true">
 */
add_action( 'wp_head', function () {
	if ( ! is_singular() ) return;

	$obj = get_queried_object();
	if ( ! ( $obj instanceof WP_Post ) ) return;

	$assigned = myls_localbusiness_is_assigned_to_post( (int) $obj->ID ) ? 'true' : 'false';
	echo "\n<meta name=\"myls-localbusiness\" content=\"{$assigned}\" />\n";
}, 2 );

/**
 * LocalBusiness → unified @graph
 * ------------------------------------------------------------
 * Pushes LocalBusiness node into myls_schema_graph so all schema
 * nodes appear in one JSON-LD block. Replaces the old standalone emitter.
 *
 * Guards mirror the old emitter: skips admin, feeds, REST, previews.
 * Respects a kill switch constant or filter.
 */
add_filter( 'myls_schema_graph', function ( array $graph ) {
	if ( is_admin() || is_feed() || ( defined('REST_REQUEST') && REST_REQUEST ) ) return $graph;
	if ( is_preview() ) return $graph;
	if ( ! is_singular() ) return $graph;

	if ( defined('MYLS_DISABLE_LOCALBUSINESS_EMIT') && MYLS_DISABLE_LOCALBUSINESS_EMIT ) return $graph;
	if ( false === apply_filters( 'myls_allow_localbusiness_emit', true ) ) return $graph;

	$post = get_queried_object();
	if ( ! ( $post instanceof WP_Post ) ) return $graph;

	$data = myls_schema_localbusiness_for_post( $post );
	if ( empty( $data ) || ! is_array( $data ) ) return $graph;

	$graph[] = $data;
	return $graph;
}, 8 ); // Priority 8: ensure LB is in graph before WebPage (10) looks for it

/**
 * Auto-sync hook (optional but helpful)
 * If another process updates myls_lb_locations, mirror to post meta automatically.
 * Will only run if the sync utility is included/available.
 */
add_action( 'update_option_myls_lb_locations', function( $old, $new ) {
	if ( function_exists( 'myls_lb_sync_postmeta_from_locations' ) && is_array( $new ) ) {
		myls_lb_sync_postmeta_from_locations( $new );
	}
	// refresh cache
	wp_cache_set( 'myls_lb_locations_cache', (array) $new, 'myls', 300 );
}, 10, 2 );
