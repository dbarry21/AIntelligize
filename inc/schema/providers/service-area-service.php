<?php
/**
 * Service Schema on service_area Pages
 * ------------------------------------------------------------
 * Emits Service nodes into the unified @graph for service_area CPT pages.
 *
 * - Parent service_area pages: one Service node per `service` CPT post,
 *   each with areaServed set to the specific city.
 * - Child service_area pages: a single Service node matching the specific
 *   service, with areaServed set to the parent's city.
 *
 * This creates the explicit relationship:
 *   Service → Provider (LocalBusiness) → City
 *
 * Toggle: disable via filter
 *   add_filter('myls_service_area_service_schema_enabled', '__return_false');
 *
 * @since 7.9.1
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Clean a raw "city" string so it's safe to emit as schema City.name.
 *
 * Strips, in order:
 *   1. Trailing primary-service-keyword suffix (myls_service_subtype option) —
 *      e.g. "Tampa Dog Training" → "Tampa" when the subtype is "Dog Training".
 *   2. Trailing 2-letter state abbreviation — e.g. "Wesley Chapel, FL" → "Wesley Chapel".
 *
 * If both strips together would empty the string, the original raw value is
 * preserved (and a notice is logged when WP_DEBUG is on) so we never emit
 * an empty City.name.
 *
 * Result is filterable via `aintelligize_service_area_city_name`:
 *   add_filter( 'aintelligize_service_area_city_name', function( $name, $post_id, $context ) {
 *       return $post_id === 123 ? 'Saint Petersburg' : $name;
 *   }, 10, 3 );
 *
 * @param  string   $raw      Raw value (post title or _myls_city_state meta).
 * @param  int|null $post_id  Originating post ID (passed to filter).
 * @param  array    $context  Optional context for the filter (e.g. ['source' => 'acf_city_state']).
 * @return string             Cleaned city name.
 */
if ( ! function_exists('myls_sa_clean_city_name') ) {
	function myls_sa_clean_city_name( string $raw, ?int $post_id = null, array $context = [] ) : string {
		$raw = trim( wp_specialchars_decode( $raw, ENT_QUOTES ) );
		if ( $raw === '' ) {
			return (string) apply_filters( 'aintelligize_service_area_city_name', '', (int) $post_id, $context );
		}

		$cleaned = $raw;

		// 1. Strip trailing primary-service-keyword (e.g. " Dog Training").
		// Requires at least one whitespace before the keyword so we don't
		// destroy a title that *is* just the service name.
		$primary_service = trim( (string) get_option( 'myls_service_subtype', '' ) );
		if ( $primary_service !== '' ) {
			$pattern = '/\s+' . preg_quote( $primary_service, '/' ) . '$/i';
			$stripped = preg_replace( $pattern, '', $cleaned );
			if ( is_string( $stripped ) ) $cleaned = $stripped;
		}

		// 2. Strip trailing state abbreviation (e.g. ", FL" or " FL").
		$cleaned = preg_replace( '/[,\s]+[A-Z]{2}$/i', '', $cleaned );

		// Collapse whitespace.
		$cleaned = trim( preg_replace( '/\s+/', ' ', $cleaned ) );

		// Empty after stripping → fall back to raw, log notice once per page render.
		if ( $cleaned === '' ) {
			if ( defined('WP_DEBUG') && WP_DEBUG ) {
				error_log( sprintf(
					'[aintelligize] city-name strip emptied result; preserving raw "%s" for post %d.',
					$raw,
					(int) $post_id
				) );
			}
			$cleaned = $raw;
		}

		return (string) apply_filters( 'aintelligize_service_area_city_name', $cleaned, (int) $post_id, $context );
	}
}

/**
 * Resolve a state code from `myls_org_region` or the first LB location, when
 * the per-page source didn't carry one. Returns '' if nothing usable is found.
 */
if ( ! function_exists('myls_sa_org_state_fallback') ) {
	function myls_sa_org_state_fallback() : string {
		$org_region = strtoupper( trim( (string) get_option( 'myls_org_region', '' ) ) );
		if ( preg_match( '/^[A-Z]{2}$/', $org_region ) ) return $org_region;

		$lb_locs = get_option( 'myls_lb_locations', [] );
		if ( ! empty( $lb_locs[0]['state'] ) ) {
			$candidate = strtoupper( trim( (string) $lb_locs[0]['state'] ) );
			if ( preg_match( '/^[A-Z]{2}$/', $candidate ) ) return $candidate;
		}

		return '';
	}
}

/**
 * Extract city name and state from a service_area post.
 *
 * Reads _myls_city_state meta first (canonical), falls back to post title.
 * State falls back to org-level (myls_org_region / first LB location) when
 * the per-page source doesn't carry a 2-letter code, so Service.name and
 * City.addressRegion stay consistent across pages.
 *
 * Handles: "Bradenton FL", "Bradenton, FL", "Apollo Beach, FL", "Tampa",
 *          "Tampa Dog Training" (with myls_service_subtype = "Dog Training")
 * Returns: ['city' => 'Bradenton', 'state' => 'FL']
 */
if ( ! function_exists('myls_sa_extract_city_state') ) {
	function myls_sa_extract_city_state( int $post_id ) : array {
		$city_state = function_exists( 'myls_get_city_state' )
			? myls_get_city_state( $post_id )
			: trim( (string) get_post_meta( $post_id, '_myls_city_state', true ) );

		$raw = ( $city_state !== '' )
			? $city_state
			: wp_specialchars_decode( get_the_title( $post_id ), ENT_QUOTES );

		// State extraction works against the raw value (before keyword strip).
		$state = '';
		if ( preg_match( '/[,\s]+([A-Z]{2})$/i', $raw, $m ) ) {
			$state = strtoupper( $m[1] );
		}

		// Fall back to org-level state when the per-page source is unstated.
		if ( $state === '' ) {
			$state = myls_sa_org_state_fallback();
		}

		$city = myls_sa_clean_city_name( $raw, $post_id, [ 'source' => 'service_area_post' ] );

		return [
			'city'  => $city,
			'state' => $state,
		];
	}
}

/**
 * Try to match a child service_area title to a service CPT post.
 *
 * Child titles are often: "Paver Sealing Bradenton FL", "Pressure Washing Brandon, FL"
 * We strip the city/state portion and match against service titles.
 */
if ( ! function_exists('myls_sa_match_service') ) {
	function myls_sa_match_service( string $child_title, array $services, string $city_name ) : ?object {
		$child_clean = wp_specialchars_decode( $child_title, ENT_QUOTES );

		// Strip city name and state from child title to isolate the service name
		// e.g. "Paver Sealing Bradenton FL" → "Paver Sealing"
		$child_clean = preg_replace( '/[,\s]+[A-Z]{2}$/i', '', $child_clean );
		if ( $city_name !== '' ) {
			$child_clean = preg_replace( '/\s*' . preg_quote( $city_name, '/' ) . '\s*/i', ' ', $child_clean );
		}
		$child_clean = trim( preg_replace( '/\s+/', ' ', $child_clean ) );

		if ( $child_clean === '' ) return null;

		$child_lower = strtolower( $child_clean );

		// Exact match first
		foreach ( $services as $svc ) {
			$svc_lower = strtolower( wp_specialchars_decode( $svc->post_title, ENT_QUOTES ) );
			if ( $svc_lower === $child_lower ) return $svc;
		}

		// Starts-with match (child title starts with service name)
		foreach ( $services as $svc ) {
			$svc_lower = strtolower( wp_specialchars_decode( $svc->post_title, ENT_QUOTES ) );
			if ( $svc_lower !== '' && str_starts_with( $child_lower, $svc_lower ) ) return $svc;
		}

		// Contains match (service name appears in child title)
		foreach ( $services as $svc ) {
			$svc_lower = strtolower( wp_specialchars_decode( $svc->post_title, ENT_QUOTES ) );
			if ( $svc_lower !== '' && str_contains( $child_lower, $svc_lower ) ) return $svc;
		}

		return null;
	}
}

/**
 * Build a single Service schema node for a service_area page.
 */
if ( ! function_exists('myls_sa_build_service_node') ) {
	function myls_sa_build_service_node(
		string $service_name,
		string $service_url,
		string $city_name,
		string $state,
		string $page_url,
		string $node_id
	) : array {
		$name_with_city = $city_name !== ''
			? $service_name . ' in ' . $city_name . ( $state !== '' ? ', ' . $state : '' )
			: $service_name;

		$node = [
			'@type'       => 'Service',
			'@id'         => $node_id,
			'name'        => $name_with_city,
			'serviceType' => $service_name,
			'provider'    => [ '@id' => home_url( '/#localbusiness' ) ],
			'url'         => esc_url_raw( $service_url ),
		];

		if ( $city_name !== '' ) {
			$area = [ '@type' => 'City', 'name' => $city_name ];
			if ( $state !== '' ) {
				$area['addressRegion'] = $state;
			}
			$node['areaServed'] = $area;
		}

		$node['availableChannel'] = [
			'@type'      => 'ServiceChannel',
			'serviceUrl' => esc_url_raw( $page_url ),
		];

		return $node;
	}
}

/* ─────────────────────────────────────────────────────────────────────
 * Graph injection — priority 52 (after Service at 50, before ItemList at 55)
 * ───────────────────────────────────────────────────────────────────── */
add_filter( 'myls_schema_graph', function ( array $graph ) : array {

	if ( is_admin() || is_feed() || is_preview() ) return $graph;
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return $graph;
	if ( ! is_singular( 'service_area' ) ) return $graph;
	if ( ! apply_filters( 'myls_service_area_service_schema_enabled', true ) ) return $graph;

	$post_id   = (int) get_queried_object_id();
	$parent_id = (int) get_post_field( 'post_parent', $post_id );
	$page_url  = get_permalink( $post_id );

	// Get all services
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

	if ( empty( $services ) ) return $graph;

	// ── Parent service_area page ─────────────────────────────────────
	if ( $parent_id <= 0 ) {
		$loc = myls_sa_extract_city_state( $post_id );

		foreach ( $services as $svc ) {
			$svc_name = wp_specialchars_decode( get_the_title( $svc->ID ), ENT_QUOTES );
			$svc_slug = sanitize_title( $svc_name );
			$svc_url  = get_permalink( $svc->ID );

			$node_id = esc_url_raw( $page_url ) . '#service-' . $svc_slug;

			$graph[] = myls_sa_build_service_node(
				$svc_name,
				$svc_url,
				$loc['city'],
				$loc['state'],
				$page_url,
				$node_id
			);
		}

		return $graph;
	}

	// ── Child service_area page ──────────────────────────────────────
	// Extract city from parent, match service from child title
	$parent_loc   = myls_sa_extract_city_state( $parent_id );
	$child_title  = wp_specialchars_decode( get_the_title( $post_id ), ENT_QUOTES );
	$matched_svc  = myls_sa_match_service( $child_title, $services, $parent_loc['city'] );

	if ( $matched_svc ) {
		$svc_name = wp_specialchars_decode( get_the_title( $matched_svc->ID ), ENT_QUOTES );
		$svc_url  = get_permalink( $matched_svc->ID );
		$node_id  = esc_url_raw( $page_url ) . '#service';

		$graph[] = myls_sa_build_service_node(
			$svc_name,
			$svc_url,
			$parent_loc['city'],
			$parent_loc['state'],
			$page_url,
			$node_id
		);
	} else {
		// No match — use the child title itself as the service name
		$svc_name = preg_replace( '/[,\s]+[A-Z]{2}$/i', '', $child_title );
		if ( $parent_loc['city'] !== '' ) {
			$svc_name = preg_replace(
				'/\s*' . preg_quote( $parent_loc['city'], '/' ) . '\s*/i',
				' ',
				$svc_name
			);
		}
		$svc_name = trim( preg_replace( '/\s+/', ' ', $svc_name ) );

		if ( $svc_name !== '' ) {
			$node_id = esc_url_raw( $page_url ) . '#service';

			$graph[] = myls_sa_build_service_node(
				$svc_name,
				$page_url,
				$parent_loc['city'],
				$parent_loc['state'],
				$page_url,
				$node_id
			);
		}
	}

	return $graph;

}, 52 ); // Priority 52: after Service (50), before ItemList (55)
