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
		$org_image_url = trim( (string) get_option( 'myls_org_image_url', '' ) );

		$image_prop = null;
		if ( $loc_img !== '' )        $image_prop = esc_url( $loc_img );
		elseif ( $logo_url !== '' )   $image_prop = esc_url( $logo_url );
		elseif ( $org_image_url !== '' ) $image_prop = esc_url( $org_image_url );

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

		// employee: reference Person @id on front page when Person schema is enabled
		$employee = null;
		if ( is_front_page() ) {
			$person_profiles = get_option( 'myls_person_profiles', [] );
			if ( is_array( $person_profiles ) && ! empty( $person_profiles ) ) {
				$emp_refs = [];
				foreach ( $person_profiles as $p ) {
					if ( empty( $p['name'] ) ) continue;
					if ( ( $p['enabled'] ?? '1' ) !== '1' ) continue;
					$person_slug = sanitize_title( $p['name'] );
					$emp_refs[]  = [ '@id' => home_url( '/#person-' . $person_slug ) ];
				}
				if ( ! empty( $emp_refs ) ) {
					$employee = count( $emp_refs ) === 1 ? $emp_refs[0] : $emp_refs;
				}
			}
		}

		// Decode HTML entities — JSON-LD strings must be plain text, not HTML-encoded.
		$lb_name = wp_specialchars_decode( trim( $loc['name'] ?? $org_name ), ENT_QUOTES );

		return array_filter( [
			'@type'    => 'LocalBusiness',
			'@id'      => trailingslashit( get_permalink( $post ) ) . '#localbusiness',

			// Only Business Image URL, else Org Logo
			'image'    => $image_prop,

			'name'       => $lb_name,
			'telephone'  => trim( $loc['phone'] ?? '' ),
			'priceRange' => $price_prop,
			'award'      => ( $awards ? $awards : null ),
			'hasCertification' => ( $certs ? array_map(function($c){ return ['@type'=>'Certification','name'=>$c]; }, $certs) : null ),
			'knowsAbout' => $knows_about ?: null,
			'memberOf' => myls_lb_build_member_of(),
			'address'  => array_filter( [
				'@type'           => 'PostalAddress',
				'streetAddress'   => wp_specialchars_decode( trim( $loc['street'] ?? '' ), ENT_QUOTES ),
				'addressLocality' => wp_specialchars_decode( trim( $loc['city'] ?? '' ), ENT_QUOTES ),
				'addressRegion'   => trim( $loc['state'] ?? '' ),
				'postalCode'      => trim( $loc['zip'] ?? '' ),
				'addressCountry'  => trim( $loc['country'] ?? 'US' ),
			] ),
			'geo' => ( ! empty( $loc['lat'] ) || ! empty( $loc['lng'] ) ) ? array_filter( [
				'@type'    => 'GeoCoordinates',
				'latitude' => trim( $loc['lat'] ?? '' ),
				'longitude'=> trim( $loc['lng'] ?? '' ),
			] ) : null,
			'openingHoursSpecification' => $hours ?: null,
			'aggregateRating' => function_exists('myls_schema_build_aggregate_rating') ? myls_schema_build_aggregate_rating() : null,

			// employee: Person @id reference (front page only)
			'employee' => $employee,

			// Link to Organization entity by @id reference (not inline duplicate)
			'parentOrganization' => [ '@id' => home_url( '/#organization' ) ],
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

	/**
	 * Strict by default: only assigned pages get LocalBusiness JSON-LD.
	 * If you want a fallback to Location #1, enable via filter below:
	 *
	 * add_filter('myls_localbusiness_fallback_to_first', '__return_true');
	 */
	if ( apply_filters( 'myls_localbusiness_fallback_to_first', false ) && isset( $locs[0] ) ) {
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
