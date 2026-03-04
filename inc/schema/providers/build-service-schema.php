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
	function myls_parse_areas_served($raw) : array {
		$items = is_array($raw) ? $raw : (preg_split('/\r\n|\r|\n|,/', (string) $raw) ?: []);
		$items = array_map('trim', $items);
		$items = array_filter($items, fn($v) => $v !== '');

		$out = [];
		foreach ( $items as $v ) {
			if ( ! in_array($v, $out, true) ) $out[] = $v;
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

		return '';
	}
}

if ( ! function_exists('myls_build_primary_localbusiness_node_fallback') ) {
	function myls_build_primary_localbusiness_node_fallback() : ?array {

		$maybe = apply_filters('myls_primary_localbusiness_node', null);
		if ( is_array($maybe) && ! empty($maybe['@id']) ) return $maybe;

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

			$lb_id = trailingslashit($org_url) . '#localbusiness-1';

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

			return $node;
		}

		$name  = (string) ($loc0['name'] ?? $loc0['business_name'] ?? '');
		$url   = (string) ($loc0['url'] ?? $loc0['website'] ?? myls_opt('myls_org_url', home_url()));
		$phone = (string) ($loc0['telephone'] ?? $loc0['phone'] ?? '');

		if ( $name === '' ) $name = (string) myls_opt('myls_org_name', get_bloginfo('name'));
		if ( $url === '' )  $url  = home_url();

		$lb_id = trailingslashit($url) . '#localbusiness-1';

		$type = (string) ($loc0['type'] ?? $loc0['@type'] ?? 'LocalBusiness');
		if ( $type === '' ) $type = 'LocalBusiness';

		$node = [
			'@type' => $type,
			'@id'   => $lb_id,
			'name'  => $name,
			'url'   => $url,
		];

		if ( $phone ) $node['telephone'] = $phone;

		$addr = [
			'streetAddress'   => (string) ($loc0['streetAddress'] ?? $loc0['street'] ?? ''),
			'addressLocality' => (string) ($loc0['addressLocality'] ?? $loc0['locality'] ?? $loc0['city'] ?? ''),
			'addressRegion'   => (string) ($loc0['addressRegion'] ?? $loc0['region'] ?? $loc0['state'] ?? ''),
			'postalCode'      => (string) ($loc0['postalCode'] ?? $loc0['zip'] ?? ''),
			'addressCountry'  => (string) ($loc0['addressCountry'] ?? $loc0['country'] ?? ''),
		];
		$addr = array_filter($addr);
		if ( ! empty($addr) ) $node['address'] = array_merge(['@type'=>'PostalAddress'], $addr);

		return $node;
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

	$areas_raw = myls_opt(
		'myls_org_areas_served',
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

	// ✅ Service "name": subtype option -> fallback to page title
	$service_subtype = trim((string) get_option('myls_service_subtype', ''));
	$service_subtype = trim(wp_strip_all_tags($service_subtype));
	$service_name    = ($service_subtype !== '') ? $service_subtype : $service_type;

	// serviceOutput: excerpt (process shortcodes) as Thing
	$excerpt_raw = (string) get_the_excerpt($post_id);
	$service_output_text = trim($excerpt_raw) !== '' ? myls_plaintext_from_content($excerpt_raw) : '';

	$service_output = null;
	if ( $service_output_text !== '' ) {
		$service_output = [
			'@type' => 'Thing',
			'name'  => $service_output_text,
		];
	}

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
			$area_served = [[ '@type' => 'AdministrativeArea', 'name' => $city_state ]];
		} elseif ( ! empty($org_areas_served) ) {
			$area_served = array_values($org_areas_served);
		}
	} else {
		if ( ! empty($org_areas_served) ) $area_served = array_values($org_areas_served);
	}

	/* ----------------------------
	 * Build Service node
	 * ---------------------------- */

	$service = [
		'@type'       => 'Service',
		'@id'         => $service_id,
		'name'        => $service_name,   // ✅ subtype drives name
		'url'         => $service_url,
		'description' => wp_strip_all_tags($description),
		'provider'    => $provider,       // ✅ LocalBusiness first
		'serviceType' => $service_type,   // ✅ ALWAYS present
	];

	if ( $image_url ) $service['image'] = esc_url_raw($image_url);
	if ( ! empty($area_served) ) $service['areaServed'] = $area_served;
	if ( is_array($service_output) ) $service['serviceOutput'] = $service_output;

	// AggregateRating — Service schema supports it; pull from Google Places
	$agg_rating = function_exists('myls_schema_build_aggregate_rating') ? myls_schema_build_aggregate_rating() : null;
	if ( is_array($agg_rating) ) $service['aggregateRating'] = $agg_rating;

	$graph[] = $service;

	return $graph;

}, 50, 1);
