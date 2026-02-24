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
		$awards = array_values(array_filter(array_map('sanitize_text_field', $awards)));

		$certs = get_option('myls_org_certifications', []);
		if ( ! is_array($certs) ) $certs = [];
		$certs = array_values(array_filter(array_map('sanitize_text_field', $certs)));

		// Resolve only these two (in order): Business Image URL -> Org Logo
		$loc_img  = trim( (string) ( $loc['image_url'] ?? '' ) );
		$logo_id  = (int) get_option( 'myls_org_logo_id', 0 );
		$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';

		$image_prop = $loc_img ? esc_url( $loc_img ) : ( $logo_url ? esc_url( $logo_url ) : null );

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

		return array_filter( [
			'@context' => 'https://schema.org',
			'@type'    => 'LocalBusiness',
			'@id'      => trailingslashit( get_permalink( $post ) ) . '#localbusiness',

			// Only Business Image URL, else Org Logo
			'image'    => $image_prop,

			'name'       => sanitize_text_field( $loc['name'] ?? $org_name ),
			'telephone'  => sanitize_text_field( $loc['phone'] ?? '' ),
			'priceRange' => sanitize_text_field( $loc['price'] ?? '' ),
			'award'      => ( $awards ? $awards : null ),
			'hasCertification' => ( $certs ? array_map(function($c){ return ['@type'=>'Certification','name'=>$c]; }, $certs) : null ),
			'memberOf' => myls_lb_build_member_of(),
			'address'  => array_filter( [
				'@type'           => 'PostalAddress',
				'streetAddress'   => sanitize_text_field( $loc['street'] ?? '' ),
				'addressLocality' => sanitize_text_field( $loc['city'] ?? '' ),
				'addressRegion'   => sanitize_text_field( $loc['state'] ?? '' ),
				'postalCode'      => sanitize_text_field( $loc['zip'] ?? '' ),
				'addressCountry'  => sanitize_text_field( $loc['country'] ?? 'US' ),
			] ),
			'geo' => ( ! empty( $loc['lat'] ) || ! empty( $loc['lng'] ) ) ? array_filter( [
				'@type'    => 'GeoCoordinates',
				'latitude' => sanitize_text_field( $loc['lat'] ?? '' ),
				'longitude'=> sanitize_text_field( $loc['lng'] ?? '' ),
			] ) : null,
			'openingHoursSpecification' => $hours ?: null,


			// schema.org: LocalBusiness does NOT support `publisher`.
			// Use parentOrganization to link the location to the primary Organization entity.
			'parentOrganization' => array_filter( [
				'@type' => 'Organization',
				'name'  => $org_name,
				'url'   => esc_url_raw( $org_url ),
				'logo'  => $logo_url ? [
					'@type' => 'ImageObject',
					'url'   => esc_url( $logo_url ),
				] : null,
			] ),
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
 * JSON-LD EMITTER (head)
 * ------------------------------------------------------------
 * Calls the LocalBusiness provider on singular pages and prints
 * <script type="application/ld+json">...</script> if data is returned.
 *
 * Guards:
 * - Skips admin, feeds, REST, previews.
 * - Respects a kill switch constant or filter if you want to disable.
 */
add_action( 'wp_head', function () {
	// Basic front-end guards
	if ( is_admin() || is_feed() || ( defined('REST_REQUEST') && REST_REQUEST ) ) return;
	if ( is_preview() ) return;
	if ( ! is_singular() ) return;

	// Optional kill-switch
	if ( defined('MYLS_DISABLE_LOCALBUSINESS_EMIT') && MYLS_DISABLE_LOCALBUSINESS_EMIT ) return;
	if ( false === apply_filters( 'myls_allow_localbusiness_emit', true ) ) return;

	$post = get_queried_object();
	if ( ! ( $post instanceof WP_Post ) ) return;

	$data = myls_schema_localbusiness_for_post( $post );
	if ( empty( $data ) || ! is_array( $data ) ) return;

	// Print JSON-LD
	echo "\n<script type=\"application/ld+json\">" .
		wp_kses_post( wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) .
		"</script>\n";
}, 12 );

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
