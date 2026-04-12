<?php
/**
 * AIntelligize – Service Schema Builder (Service, not Product)
 * File: build-service-schema.php
 *
 * FINAL RULES (per your last 2 messages):
 * - serviceType MUST be present and MUST be the page title (string).
 * - Service "name" MUST be the Schema -> Service tab "Service Subtype" (myls_service_subtype) if set,
 *   otherwise fallback to page title.
 * - DO NOT output a second serviceType value (no array).
 *
 * Other requirements kept:
 * - Description processes shortcodes (excerpt preferred, else content).
 * - Provider: LocalBusiness first (Location #1) else fallback to Organization.
 * - areaServed: if assigned (not service CPT) prefer ACF city_state else fallback to org areas served.
 * - Output only when Service schema enabled AND (service CPT OR assigned via myls_service_pages).
 */

if ( ! defined('ABSPATH') ) exit;

/* -------------------------------------------------------------------------
 * Utilities
 * ------------------------------------------------------------------------- */

if ( ! function_exists('myls_opt') ) {
	function myls_opt($key, $default = '') {
		$v = get_option($key, $default);
		return ($v === '' ? $default : $v);
	}
}

if ( ! function_exists('myls_plaintext_from_content') ) {
	function myls_plaintext_from_content(string $html) : string {
		$html = do_shortcode($html);
		$text = wp_strip_all_tags($html);
		$text = trim(preg_replace('/\s+/', ' ', $text));
		return $text;
	}
}

if ( ! function_exists('myls_parse_areas_served') ) {
	/**
	 * Parse a raw areas-served value (string or array) into a clean,
	 * deduplicated flat array of area name strings.
	 *
	 * Handles three storage formats:
	 *   1. Textarea string  — newline- or comma-separated  (current format)
	 *   2. Array of strings — each element already one area (array option)
	 *   3. Array of strings — elements may still contain commas, e.g.
	 *      ["Lithia, Wesley Chapel"] from a legacy import; these are
	 *      sub-split so every city becomes its own entry.
	 *
	 * @param  string|array $raw  Raw option value from get_option / myls_opt.
	 * @return string[]           Flat, trimmed, deduplicated area names.
	 */
	function myls_parse_areas_served( $raw ) : array {
		if ( is_array( $raw ) ) {
			// Sub-split every element in case legacy data stored multiple
			// cities in one array slot (e.g. "Lithia, Wesley Chapel").
			$flat = [];
			foreach ( $raw as $item ) {
				$parts = preg_split( '/\r\n|\r|\n|,/', (string) $item ) ?: [];
				foreach ( $parts as $p ) {
					$flat[] = $p;
				}
			}
			$items = $flat;
		} else {
			// Plain textarea string — split on newline or comma.
			$items = preg_split( '/\r\n|\r|\n|,/', (string) $raw ) ?: [];
		}

		// Trim whitespace and drop empties.
		$items = array_map( 'trim', $items );
		$items = array_filter( $items, fn( $v ) => $v !== '' );

		// Deduplicate while preserving order.
		$out = [];
		foreach ( $items as $v ) {
			if ( ! in_array( $v, $out, true ) ) {
				$out[] = $v;
			}
		}
		return $out;
	}
}

if ( ! function_exists('myls_wrap_areas_as_admin_area') ) {
	/**
	 * Convert a flat array of area name strings (from myls_parse_areas_served)
	 * into properly typed City objects for use in areaServed.
	 *
	 * Schema.org requires areaServed entries to be typed entities, not bare
	 * strings, for AI crawlers and Rich Results to resolve them correctly.
	 *
	 * If a name ends with a 2-letter state abbreviation (e.g. "Bradenton, FL"),
	 * the state is extracted into addressRegion and the city name is cleaned.
	 *
	 * @param  string[] $names  Flat array of area name strings.
	 * @return array[]          Array of City schema objects.
	 */
	function myls_wrap_areas_as_admin_area( array $names ) : array {
		$out = [];
		foreach ( $names as $name ) {
			$name = trim( (string) $name );
			if ( $name === '' ) continue;

			$city = [
				'@type' => 'City',
			];

			// Extract trailing 2-letter state abbreviation if present
			if ( preg_match( '/[,\s]+([A-Z]{2})$/i', $name, $m ) ) {
				$city['name']          = trim( preg_replace( '/[,\s]+[A-Z]{2}$/i', '', $name ) );
				$city['addressRegion'] = strtoupper( $m[1] );
			} else {
				$city['name'] = $name;
			}

			$out[] = $city;
		}
		return $out;
	}
}

if ( ! function_exists('myls_get_best_description') ) {
	function myls_get_best_description(int $post_id) : string {
		$excerpt = trim((string) get_the_excerpt($post_id));
		if ( $excerpt !== '' ) {
			return myls_plaintext_from_content($excerpt);
		}

		// Use centralized utility for page builder compatibility.
		if ( function_exists('myls_get_post_plain_text') ) {
			$text = myls_get_post_plain_text( $post_id, 45 );
			if ( $text !== '' ) return $text;
		}

		// Fallback: original approach.
		$post = get_post($post_id);
		if ( ! $post ) return '';

		$content = myls_plaintext_from_content((string) $post->post_content);
		return wp_trim_words($content, 45, '…');
	}
}

if ( ! function_exists('myls_normalize_url_array') ) {
	function myls_normalize_url_array($maybe_array) : array {
		if ( ! is_array($maybe_array) ) return [];
		$urls = array_filter(array_map('esc_url_raw', $maybe_array));
		return array_values(array_unique($urls));
	}
}

if ( ! function_exists('myls_find_primary_localbusiness_id') ) {
	function myls_find_primary_localbusiness_id(array $graph) : string {

		$known = [
			'LocalBusiness',
			'ProfessionalService',
			'HomeAndConstructionBusiness',
			'Plumber',
			'Electrician',
			'HVACBusiness',
			'RoofingContractor',
			'PestControl',
			'LegalService',
			'CleaningService',
			'AutoRepair',
			'MedicalBusiness',
			'Locksmith',
			'MovingCompany',
			'RealEstateAgent',
			'ITService',
			'Dentist',
			'Physician',
			'GeneralContractor',
			'HousePainter',
		];

		foreach ( $graph as $node ) {
			if ( ! is_array($node) ) continue;

			$id   = (string) ($node['@id'] ?? '');
			$type = $node['@type'] ?? '';

			if ( $id === '' ) continue;

			$types = is_array($type) ? $type : [$type];
			foreach ( $types as $t ) {
				$t = (string) $t;
				if ( in_array($t, $known, true) ) return $id;
			}
		}

		foreach ( $graph as $node ) {
			if ( ! is_array($node) ) continue;
			$id = (string) ($node['@id'] ?? '');
			if ( $id && stripos($id, '#localbusiness') !== false ) return $id;
		}

		// Third pass: match merged single-location node.
		// In single-location mode @id=/#organization and @type is an array
		// containing a known LocalBusiness subtype.
		foreach ( $graph as $node ) {
			if ( ! is_array($node) ) continue;
			$id = (string) ($node['@id'] ?? '');
			if ( $id === '' ) continue;
			if ( str_ends_with( $id, '#organization' ) ) {
				$types = is_array($node['@type']) ? $node['@type'] : [(string)$node['@type']];
				foreach ( $types as $t ) {
					if ( in_array( (string)$t, $known, true ) ) return $id;
				}
			}
		}

		return '';
	}
}

if ( ! function_exists('myls_build_primary_localbusiness_node_fallback') ) {
	/**
	 * Build a best-effort LocalBusiness node when no assigned location is found
	 * in the schema graph. Used as the Service schema provider on pages not
	 * assigned to any LocalBusiness location.
	 *
	 * Pulls as many fields as possible from org options and location #0 so the
	 * injected node is as rich as the fully-assigned path.
	 */
	function myls_build_primary_localbusiness_node_fallback() : ?array {

		$maybe = apply_filters('myls_primary_localbusiness_node', null);
		if ( is_array($maybe) && ! empty($maybe['@id']) ) return $maybe;

		// ── Shared enrichment data (same for both branches) ──────────────────
		// Image: per-location URL → org logo attachment → org image URL
		$logo_id   = absint( myls_opt('myls_org_logo_id', 0) );
		$logo_url  = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';
		$org_img   = trim( (string) myls_opt('myls_org_image_url', '') );
		$image_url = $logo_url !== '' ? $logo_url : ( $org_img !== '' ? $org_img : '' );

		// priceRange: site-wide default
		$price_range = trim( (string) get_option('myls_lb_default_price_range', '') );

		// Opening hours: pull from location #0 in myls_lb_locations if available
		$hours_spec = [];
		$lb_locs = (array) get_option('myls_lb_locations', []);
		if ( ! empty($lb_locs[0]['hours']) && is_array($lb_locs[0]['hours']) ) {
			foreach ( $lb_locs[0]['hours'] as $h ) {
				$d = trim( (string) ($h['day']   ?? '') );
				$o = trim( (string) ($h['open']  ?? '') );
				$c = trim( (string) ($h['close'] ?? '') );
				if ( $d && $o && $c ) {
					$hours_spec[] = [
						'@type'     => 'OpeningHoursSpecification',
						'dayOfWeek' => $d,
						'opens'     => $o,
						'closes'    => $c,
					];
				}
			}
		}

		// aggregateRating
		$agg_rating = function_exists('myls_schema_build_aggregate_rating')
			? myls_schema_build_aggregate_rating()
			: null;

		// Awards and certifications
		$awards = get_option('myls_org_awards', []);
		if ( ! is_array($awards) ) $awards = [];
		$awards = array_values( array_filter( array_map( 'myls_parse_award_name', $awards ) ) );

		$certs = get_option('myls_org_certifications', []);
		if ( ! is_array($certs) ) $certs = [];
		$certs = array_values( array_filter( array_map('sanitize_text_field', $certs) ) );

		// knowsAbout + memberOf (same data localbusiness.php uses)
		$knows_about = function_exists('myls_get_knows_about') ? myls_get_knows_about() : [];
		$member_of   = function_exists('myls_lb_build_member_of') ? myls_lb_build_member_of() : null;

		// ── Helper: apply shared enrichment to a node ────────────────────────
		$enrich = function( array $node ) use (
			$image_url, $price_range, $hours_spec, $agg_rating, $awards, $certs,
			$knows_about, $member_of
		) : array {
			if ( $image_url !== '' )     $node['image']      = esc_url_raw($image_url);
			if ( $price_range !== '' )   $node['priceRange'] = $price_range;
			if ( $hours_spec )           $node['openingHoursSpecification'] = $hours_spec;
			if ( is_array($agg_rating) ) $node['aggregateRating'] = $agg_rating;
			if ( $awards )               $node['award'] = $awards;
			if ( $certs )                $node['hasCertification'] = array_map(
				function($c) { return ['@type' => 'Certification', 'name' => $c]; },
				$certs
			);
			// v7.8.97: match localbusiness.php enrichment
			$phone = $node['telephone'] ?? '';
			if ( $phone !== '' ) {
				$node['contactPoint'] = [[
					'@type'       => 'ContactPoint',
					'telephone'   => $phone,
					'contactType' => 'customer service',
				]];
			}
			$node['parentOrganization'] = [ '@id' => home_url( '/#organization' ) ];
			if ( ! empty( $knows_about ) ) $node['knowsAbout'] = $knows_about;
			if ( is_array( $member_of ) )  $node['memberOf']   = $member_of;
			return $node;
		};

		// ── Branch A: no saved location data, build from org options ─────────
		$locations = get_option('myls_localbusiness_locations', null);
		if ( ! is_array($locations) || empty($locations) ) {
			$locations = get_option('myls_localbusiness', null);
		}

		$loc0 = null;
		if ( is_array($locations) ) {
			if ( isset($locations[0]) && is_array($locations[0]) ) $loc0 = $locations[0];
			if ( $loc0 === null && isset($locations['name']) ) $loc0 = $locations;
		}

		if ( ! is_array($loc0) ) {
			$org_name  = myls_opt('myls_org_name',  myls_opt('ssseo_organization_name', get_bloginfo('name')));
			$org_url   = myls_opt('myls_org_url',   myls_opt('ssseo_organization_url',  home_url()));
			$org_phone = myls_opt('myls_org_phone', myls_opt('ssseo_organization_phone',''));

			if ( $org_name === '' || $org_url === '' ) return null;

			$lb_id = trailingslashit($org_url) . '#localbusiness';

			$node = [
				'@type' => 'LocalBusiness',
				'@id'   => $lb_id,
				'name'  => $org_name,
				'url'   => $org_url,
			];
			if ( $org_phone ) $node['telephone'] = $org_phone;

			$addr = [
				'streetAddress'   => myls_opt('myls_org_address',     myls_opt('ssseo_organization_address', '')),
				'addressLocality' => myls_opt('myls_org_locality',    myls_opt('ssseo_organization_locality', '')),
				'addressRegion'   => myls_opt('myls_org_region',      myls_opt('ssseo_organization_state', '')),
				'postalCode'      => myls_opt('myls_org_postal_code', myls_opt('ssseo_organization_postal_code', '')),
				'addressCountry'  => myls_opt('myls_org_country',     myls_opt('ssseo_organization_country', '')),
			];
			$addr = array_filter($addr);
			if ( ! empty($addr) ) $node['address'] = array_merge(['@type'=>'PostalAddress'], $addr);

			return $enrich($node);
		}

		// ── Branch B: use saved location #0 data ─────────────────────────────
		$name  = (string) ($loc0['name'] ?? $loc0['business_name'] ?? '');
		$url   = (string) ($loc0['url'] ?? $loc0['website'] ?? myls_opt('myls_org_url', home_url()));
		$phone = (string) ($loc0['telephone'] ?? $loc0['phone'] ?? '');

		if ( $name === '' ) $name = (string) myls_opt('myls_org_name', get_bloginfo('name'));
		if ( $url === '' )  $url  = home_url();

		$lb_id = trailingslashit($url) . '#localbusiness';

		$type = (string) ($loc0['type'] ?? $loc0['@type'] ?? 'LocalBusiness');
		if ( $type === '' ) $type = 'LocalBusiness';

		$node = [
			'@type' => $type,
			'@id'   => $lb_id,
			'name'  => $name,
			'url'   => $url,
		];

		if ( $phone ) $node['telephone'] = $phone;

		// Per-location image takes priority over shared fallback
		$loc_img = trim( (string) ($loc0['image_url'] ?? '') );
		if ( $loc_img !== '' ) $node['image'] = esc_url_raw($loc_img);

		// Per-location priceRange takes priority over site-wide default
		$loc_price = trim( (string) ($loc0['price'] ?? '') );
		if ( $loc_price !== '' ) $node['priceRange'] = $loc_price;

		$addr = [
			'streetAddress'   => (string) ($loc0['streetAddress'] ?? $loc0['street'] ?? ''),
			'addressLocality' => (string) ($loc0['addressLocality'] ?? $loc0['locality'] ?? $loc0['city'] ?? ''),
			'addressRegion'   => (string) ($loc0['addressRegion'] ?? $loc0['region'] ?? $loc0['state'] ?? ''),
			'postalCode'      => (string) ($loc0['postalCode'] ?? $loc0['zip'] ?? ''),
			'addressCountry'  => (string) ($loc0['addressCountry'] ?? $loc0['country'] ?? ''),
		];
		$addr = array_filter($addr);
		if ( ! empty($addr) ) $node['address'] = array_merge(['@type'=>'PostalAddress'], $addr);

		// Apply shared enrichment — $enrich skips image/price if already set above
		// by checking the node keys; re-wrap to avoid overwriting per-location values.
		$enrich_b = function( array $node ) use (
			$image_url, $price_range, $hours_spec, $agg_rating, $awards, $certs,
			$knows_about, $member_of, $loc_img, $loc_price
		) : array {
			// Only apply fallback image/price if per-location value wasn't set
			if ( $loc_img === '' && $image_url !== '' )   $node['image']      = esc_url_raw($image_url);
			if ( $loc_price === '' && $price_range !== '' ) $node['priceRange'] = $price_range;
			if ( $hours_spec )           $node['openingHoursSpecification'] = $hours_spec;
			if ( is_array($agg_rating) ) $node['aggregateRating'] = $agg_rating;
			if ( $awards )               $node['award'] = $awards;
			if ( $certs )                $node['hasCertification'] = array_map(
				function($c) { return ['@type' => 'Certification', 'name' => $c]; },
				$certs
			);
			// v7.8.97: match localbusiness.php enrichment
			$phone = $node['telephone'] ?? '';
			if ( $phone !== '' ) {
				$node['contactPoint'] = [[
					'@type'       => 'ContactPoint',
					'telephone'   => $phone,
					'contactType' => 'customer service',
				]];
			}
			$node['parentOrganization'] = [ '@id' => home_url( '/#organization' ) ];
			if ( ! empty( $knows_about ) ) $node['knowsAbout'] = $knows_about;
			if ( is_array( $member_of ) )  $node['memberOf']   = $member_of;
			return $node;
		};

		return $enrich_b($node);
	}
}

/* -------------------------------------------------------------------------
 * Main graph injection
 * ------------------------------------------------------------------------- */

add_filter('myls_schema_graph', function(array $graph) {

	if ( ! is_singular() ) return $graph;

	$enabled = (string) get_option('myls_service_enabled', '0');
	if ( $enabled !== '1' ) return $graph;

	$post_id = (int) get_queried_object_id();
	if ( ! $post_id ) return $graph;

	$assigned_ids = array_map('absint', (array) get_option('myls_service_pages', []));
	$assigned_ids = array_values(array_unique(array_filter($assigned_ids, fn($id) => $id > 0)));

	$is_service_cpt = is_singular('service');
	$is_assigned    = in_array($post_id, $assigned_ids, true);

	if ( ! $is_service_cpt && ! $is_assigned ) return $graph;

	/* ----------------------------
	 * Organization fallback provider
	 * ---------------------------- */

	$org_name    = myls_opt('myls_org_name',  myls_opt('ssseo_organization_name', get_bloginfo('name')));
	$org_url     = myls_opt('myls_org_url',   myls_opt('ssseo_organization_url',  home_url()));
	$org_phone   = myls_opt('myls_org_phone', myls_opt('ssseo_organization_phone',''));
	$org_logo_id = absint( myls_opt('myls_org_logo_id', myls_opt('ssseo_organization_logo', 0)) );
	$org_logo    = $org_logo_id ? wp_get_attachment_image_url($org_logo_id, 'full') : '';

	$org_address_raw = [
		'streetAddress'   => myls_opt('myls_org_address',     myls_opt('ssseo_organization_address', '')),
		'addressLocality' => myls_opt('myls_org_locality',    myls_opt('ssseo_organization_locality', '')),
		'addressRegion'   => myls_opt('myls_org_region',      myls_opt('ssseo_organization_state', '')),
		'postalCode'      => myls_opt('myls_org_postal_code', myls_opt('ssseo_organization_postal_code', '')),
		'addressCountry'  => myls_opt('myls_org_country',     myls_opt('ssseo_organization_country', '')),
	];
	$org_address = array_filter($org_address_raw);
	if ( ! empty($org_address) ) $org_address = array_merge(['@type' => 'PostalAddress'], $org_address);

	$same_as = myls_opt('myls_org_sameas', myls_opt('ssseo_organization_social_profiles', []));
	$same_as = myls_normalize_url_array($same_as);

	// Fix: option key is 'myls_org_areas' (saved by org subtab).
	// 'myls_org_areas_served' was a typo that caused the lookup to miss and
	// fall through to legacy ssseo_* keys where areas were sometimes stored
	// as a single comma-joined string, causing "Lithia, Wesley Chapel" to
	// appear as one fused entry instead of two separate AdministrativeArea nodes.
	$areas_raw = myls_opt(
		'myls_org_areas',
		myls_opt('ssseo_organization_areas_served', myls_opt('ssseo_areas_served', ''))
	);
	$org_areas_served = myls_parse_areas_served($areas_raw);

	$org_id = trailingslashit($org_url) . '#organization';

	$org_provider = [
		'@type' => 'Organization',
		'@id'   => $org_id,
		'name'  => $org_name,
		'url'   => $org_url,
	];
	if ( $org_phone )            $org_provider['telephone'] = $org_phone;
	if ( $org_logo )             $org_provider['logo']      = $org_logo;
	if ( ! empty($org_address) ) $org_provider['address']   = $org_address;
	if ( ! empty($same_as) )     $org_provider['sameAs']    = $same_as;

	// Awards, certifications, and aggregateRating on the fallback provider node.
	// When a LocalBusiness @id IS found these are already on the full LB node in
	// the graph; we only need them here for service pages that aren't assigned to
	// a location and therefore get the inline org_provider instead of an @id ref.
	$fb_awards = get_option('myls_org_awards', []);
	if ( ! is_array($fb_awards) ) $fb_awards = [];
	$fb_awards = array_values( array_filter( array_map( 'myls_parse_award_name', $fb_awards ) ) );
	if ( $fb_awards ) $org_provider['award'] = $fb_awards;

	$fb_certs = get_option('myls_org_certifications', []);
	if ( ! is_array($fb_certs) ) $fb_certs = [];
	$fb_certs = array_values( array_filter( array_map('sanitize_text_field', $fb_certs) ) );
	if ( $fb_certs ) $org_provider['hasCertification'] = array_map(
		function( $c ) { return [ '@type' => 'Certification', 'name' => $c ]; },
		$fb_certs
	);

	$fb_rating = function_exists('myls_schema_build_aggregate_rating') ? myls_schema_build_aggregate_rating() : null;
	if ( is_array($fb_rating) ) $org_provider['aggregateRating'] = $fb_rating;

	/* ----------------------------
	 * Provider: LocalBusiness first
	 * ---------------------------- */

	$localbiz_id = myls_find_primary_localbusiness_id($graph);

	if ( ! $localbiz_id ) {
		$lb_node = myls_build_primary_localbusiness_node_fallback();
		if ( is_array($lb_node) && ! empty($lb_node['@id']) ) {
			$localbiz_id = (string) $lb_node['@id'];

			$exists = false;
			foreach ( $graph as $n ) {
				if ( is_array($n) && (string)($n['@id'] ?? '') === $localbiz_id ) { $exists = true; break; }
			}
			if ( ! $exists ) $graph[] = $lb_node;
		}
	}

	$provider = $localbiz_id ? [ '@id' => $localbiz_id ] : $org_provider;

	/* ----------------------------
	 * Service basics
	 * ---------------------------- */

	$service_url = get_permalink($post_id);
	if ( ! $service_url ) return $graph;

	$service_id = $service_url . '#service';

	foreach ( $graph as $node ) {
		if ( is_array($node) && ($node['@type'] ?? '') === 'Service' && ($node['@id'] ?? '') === $service_id ) {
			return $graph;
		}
	}

	$page_title  = get_the_title($post_id);
	$description = myls_get_best_description($post_id);

	$image_url = get_the_post_thumbnail_url($post_id, 'full');
	if ( ! $image_url && $org_logo ) $image_url = $org_logo;

	// ✅ serviceType MUST be present: ALWAYS page title (string)
	$service_type = wp_strip_all_tags($page_title);

	// ✅ Service "name": per-page meta → global subtype option → page title
	// Per-page meta (_myls_service_name) takes highest priority so individual
	// pages can override the global setting without affecting all service pages.
	$per_page_name   = trim( wp_strip_all_tags( (string) get_post_meta( $post_id, '_myls_service_name', true ) ) );
	$service_subtype = trim( wp_strip_all_tags( (string) get_option( 'myls_service_subtype', '' ) ) );
	$service_name    = $per_page_name !== '' ? $per_page_name : ( $service_subtype !== '' ? $service_subtype : $service_type );

	// serviceOutput: noun-phrase describing the tangible deliverable.
	// Priority: 1) explicit admin field  2) smart default derived from service type
	// Never uses the post excerpt — that is a process description, not a deliverable.
	$service_output_text = trim( (string) get_option( 'myls_service_output', '' ) );

	if ( $service_output_text === '' ) {
		// Derive a sensible noun-phrase from the page title (already used as serviceType).
		// Strip stop words so "Pressure Washing Services" → "Cleaned pressure washing surfaces".
		// Simple map: title → noun phrase. Falls back to generic if no match.
		$title_lower = strtolower( wp_strip_all_tags( $page_title ) );
		$noun_map    = [
			'wash'     => 'Professionally cleaned and washed exterior surfaces',
			'clean'    => 'Professionally cleaned exterior surfaces',
			'seal'     => 'Professionally sealed and protected hard surfaces',
			'pressure' => 'Restored, mold-free pressure-washed surfaces',
			'soft'     => 'Gently soft-washed, stain-free exterior surfaces',
			'paver'    => 'Sealed, color-enhanced paver surfaces',
			'travert'  => 'Cleaned and sealed travertine surfaces',
			'roof'     => 'Clean, algae-free roof surface',
			'gutter'   => 'Clear, flow-tested gutters and downspouts',
			'fence'    => 'Clean, mold-free fence panels',
			'concrete' => 'Clean, stain-free concrete surfaces',
			'driveway' => 'Clean, restored driveway surface',
			'sidewalk' => 'Clean, safe sidewalk surface',
			'pool'     => 'Clean, sanitized pool deck surface',
			'window'   => 'Streak-free, clean window surfaces',
			'hvac'     => 'Tested, serviced HVAC system',
			'plumb'    => 'Repaired, functional plumbing system',
			'electr'   => 'Inspected, functional electrical system',
		];
		foreach ( $noun_map as $keyword => $phrase ) {
			if ( str_contains( $title_lower, $keyword ) ) {
				$service_output_text = $phrase;
				break;
			}
		}
		if ( $service_output_text === '' ) {
			$service_output_text = 'Completed, professionally delivered ' . strtolower( $service_type ) . ' service';
		}
	}

	$service_output = [
		'@type' => 'Thing',
		'name'  => $service_output_text,
	];

	/* ----------------------------
	 * areaServed rules
	 * ---------------------------- */

	$area_served = [];

	if ( ! $is_service_cpt && $is_assigned ) {
		$city_state = '';

		if ( function_exists('get_field') ) {
			$city_state = (string) get_field('city_state', $post_id);
		}
		if ( $city_state === '' ) {
			$city_state = (string) get_post_meta($post_id, 'city_state', true);
		}

		$city_state = trim(wp_strip_all_tags($city_state));

		if ( $city_state !== '' ) {
			$city_obj = [ '@type' => 'City' ];
			if ( preg_match( '/[,\s]+([A-Z]{2})$/i', $city_state, $csm ) ) {
				$city_obj['name']          = trim( preg_replace( '/[,\s]+[A-Z]{2}$/i', '', $city_state ) );
				$city_obj['addressRegion'] = strtoupper( $csm[1] );
			} else {
				$city_obj['name'] = $city_state;
			}
			$area_served = [ $city_obj ];
		} elseif ( ! empty($org_areas_served) ) {
			// Wrap plain strings as typed AdministrativeArea objects.
			$area_served = myls_wrap_areas_as_admin_area($org_areas_served);
		}
	} else {
		// Service CPT or unassigned: prefer service_area CPT permalinks.
		// This mirrors the pattern in localbusiness.php areaServed block.
		$sa_posts = get_posts( [
			'post_type'        => 'service_area',
			'post_status'      => 'publish',
			'post_parent'      => 0,
			'posts_per_page'   => 100,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		] );

		if ( ! empty( $sa_posts ) ) {
			foreach ( $sa_posts as $sa ) {
				// Read city/state from MYLS-native meta key
				$cs_raw = trim( (string) get_post_meta( $sa->ID, '_myls_city_state', true ) );

				if ( $cs_raw === '' ) {
					// Fallback: strip trailing state abbreviation from post title
					$cs_raw = preg_replace(
						'/[,\s]+[A-Z]{2}$/i', '',
						html_entity_decode(
							get_the_title( $sa->ID ),
							ENT_QUOTES | ENT_HTML5,
							'UTF-8'
						)
					);
				}

				$cs_raw    = trim( $cs_raw );
				$area_type = ( stripos( $cs_raw, 'county' ) !== false )
					? 'AdministrativeArea'
					: 'City';

				// Strip trailing state abbreviation for clean city name
				$city_name = trim( preg_replace( '/[,\s]+[A-Z]{2}$/i', '', $cs_raw ) );

				if ( $city_name === '' ) continue;

				$area_served[] = [
					'@type' => $area_type,
					'name'  => $city_name,
					'url'   => get_permalink( $sa->ID ),
				];
			}
		}

		// Fallback: no service_area CPTs — use org-level areas (no URLs).
		if ( empty( $area_served ) && ! empty( $org_areas_served ) ) {
			$area_served = myls_wrap_areas_as_admin_area( $org_areas_served );
		}
	}

	/* ----------------------------
	 * Build Service node
	 * ---------------------------- */

	$service = [
		'@type'            => 'Service',
		'@id'              => $service_id,
		'name'             => $service_name,   // ✅ subtype drives name
		'url'              => $service_url,
		'description'      => wp_strip_all_tags($description),
		'provider'         => $provider,       // ✅ LocalBusiness first
		'serviceType'      => $service_type,   // ✅ ALWAYS present
		'mainEntityOfPage' => [ '@id' => trailingslashit( $service_url ) . '#webpage' ],
	];

	// dateModified: helps search engines understand content freshness
	$date_modified = get_the_modified_date( 'c', $post_id );
	if ( $date_modified ) {
		$service['dateModified'] = $date_modified;
	}

	if ( $image_url ) $service['image'] = esc_url_raw($image_url);
	if ( ! empty($area_served) ) $service['areaServed'] = $area_served;
	$service['serviceOutput'] = $service_output; // always present — noun-phrase deliverable

	if ( is_array( $agg_rating ) ) $service['aggregateRating'] = $agg_rating;

	// ── Price Ranges (hasOfferCatalog → OfferCatalog → Offer) ────────────
	// Look up myls_service_price_ranges for any entry whose post_ids contains
	// the current post.  If found, attach as hasOfferCatalog with OfferCatalog
	// wrapper so AI and search engines see the price range directly in schema.
	$price_ranges = (array) get_option( 'myls_service_price_ranges', [] );
	$offer_items  = [];
	foreach ( $price_ranges as $pr ) {
		if ( ! is_array($pr) ) continue;

		$range_ids = array_map( 'absint', (array) ( $pr['post_ids'] ?? [] ) );
		if ( ! in_array( $post_id, $range_ids, true ) ) continue;

		$low      = trim( (string) ( $pr['low']      ?? '' ) );
		$high     = trim( (string) ( $pr['high']     ?? '' ) );
		$currency = strtoupper( trim( (string) ( $pr['currency'] ?? 'USD' ) ) );
		$label    = trim( (string) ( $pr['label']    ?? '' ) );

		// Need at least one price value to output valid schema
		if ( $low === '' && $high === '' ) continue;

		$price_spec = [ '@type' => 'UnitPriceSpecification', 'priceCurrency' => $currency ];
		if ( $low  !== '' ) $price_spec['minPrice'] = $low;
		if ( $high !== '' ) $price_spec['maxPrice'] = $high;

		$offer = [
			'@type'              => 'Offer',
			'priceCurrency'      => $currency,
			'priceSpecification' => $price_spec,
		];
		if ( $label !== '' ) $offer['name'] = $label;

		$offer_items[] = $offer;
	}

	if ( ! empty( $offer_items ) ) {
		$service['hasOfferCatalog'] = [
			'@type'          => 'OfferCatalog',
			'name'           => $service_name . ' Pricing',
			'itemListElement' => $offer_items,
		];
	}

	$graph[] = $service;

	return $graph;

}, 50, 1);
