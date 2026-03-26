<?php
// File: inc/schema/providers/organization.php
if ( ! defined('ABSPATH') ) exit;

/**
 * Provider: Organization — site-wide identity node
 *
 * Emits on ALL singular front-end pages so every @id reference to
 * /#organization resolves within the same graph (WebSite.publisher,
 * Person.worksFor, LocalBusiness.parentOrganization, etc.).
 *
 * Assumes your Organization subtab saves:
 * - myls_org_name, myls_org_url, myls_org_email, myls_org_tel, myls_org_description
 * - myls_org_street, myls_org_locality, myls_org_region, myls_org_postal, myls_org_country
 * - myls_org_lat, myls_org_lng (optional)
 * - myls_org_logo_id (attachment ID), myls_org_image_url (optional)
 * - myls_org_social_profiles (array of URLs)
 */

add_filter('myls_schema_graph', function(array $graph) {

	// Emit on all front-end pages (not admin, feeds, REST, previews)
	if ( is_admin() || is_feed() || is_preview() ) return $graph;
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return $graph;

	// --- Collect fields ---
	$name  = trim( (string) get_option('myls_org_name', '') );
	if ( $name === '' ) return $graph; // Org requires a name

	$url   = trim( (string) get_option('myls_org_url', home_url('/')) );
	$email = trim( (string) get_option('myls_org_email', '') );
	$tel   = trim( (string) get_option('myls_org_tel', '') );
	$desc  = trim( (string) get_option('myls_org_description', '') );

	$street   = trim( (string) get_option('myls_org_street', '') );
	$locality = trim( (string) get_option('myls_org_locality', '') );
	$region   = trim( (string) get_option('myls_org_region', '') );
	$postal   = trim( (string) get_option('myls_org_postal', '') );
	$country  = trim( (string) get_option('myls_org_country', '') );

	$lat = trim( (string) get_option('myls_org_lat', '') );
	$lng = trim( (string) get_option('myls_org_lng', '') );

	$logo_id  = (int) get_option('myls_org_logo_id', 0 );
	$image_url= trim( (string) get_option('myls_org_image_url', '') );

	$socials = get_option('myls_org_social_profiles', []);
	if ( ! is_array($socials) ) $socials = [];
	$socials = array_values( array_filter( array_map('trim', $socials) ) );

	$awards = get_option('myls_org_awards', []);
	if ( ! is_array($awards) ) $awards = [];
	$awards = array_values( array_filter( array_map( function( $a ) {
		return wp_specialchars_decode( trim( $a ), ENT_QUOTES );
	}, $awards ) ) );

	$certs = get_option('myls_org_certifications', []);
	if ( ! is_array($certs) ) $certs = [];
	$certs = array_values( array_filter( array_map('sanitize_text_field', $certs) ) );

	// --- Build address (only if any field is present) ---
	$address = array_filter([
		'@type'           => 'PostalAddress',
		'streetAddress'   => $street ?: null,
		'addressLocality' => $locality ?: null,
		'addressRegion'   => $region ?: null,
		'postalCode'      => $postal ?: null,
		'addressCountry'  => $country ?: null,
	]);

	// --- Resolve logo (ImageObject if possible) ---
	$logo = null;
	if ( $logo_id ) {
		$logo_url = wp_get_attachment_image_url($logo_id, 'full');
		if ( $logo_url ) {
			$logo = ['@type' => 'ImageObject', 'url' => esc_url_raw($logo_url)];
			$meta = wp_get_attachment_metadata($logo_id);
			if ( is_array($meta) ) {
				if ( ! empty($meta['width']) )  $logo['width']  = (int) $meta['width'];
				if ( ! empty($meta['height']) ) $logo['height'] = (int) $meta['height'];
			}
		}
	}

	// --- Base node ---
	$node = [
		'@type' => 'Organization',
		'@id'   => home_url( '/#organization' ),
		'name'  => wp_specialchars_decode( $name, ENT_QUOTES ),
		'url'   => esc_url_raw( $url ),
	];

	if ( $desc !== '' )  $node['description'] = wp_specialchars_decode( $desc, ENT_QUOTES );
	if ( $email !== '' ) $node['email']       = $email;
	if ( $tel !== '' ) {
		$node['telephone'] = function_exists('myls_normalize_phone_e164')
			? myls_normalize_phone_e164( $tel )
			: $tel;
	}
	if ( $address )      $node['address']     = $address;
	if ( $logo )         $node['logo']        = $logo;
	if ( $image_url )    $node['image']       = esc_url_raw($image_url);
	if ( $socials ) {
		$node['sameAs'] = array_map( function( $url ) {
			return esc_url_raw( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}, $socials );
	}
	if ( $awards )       $node['award']       = $awards;
	if ( $certs )        $node['hasCertification'] = array_map(function($c){ return ['@type'=>'Certification','name'=>$c]; }, $certs);

	// knowsAbout: merged Service CPT titles + Service schema name field.
	// Signals to AI crawlers which topics/services this organization covers.
	$knows_about = function_exists('myls_get_knows_about') ? myls_get_knows_about() : [];
	if ( ! empty( $knows_about ) ) $node['knowsAbout'] = $knows_about;

	// Memberships → memberOf
	$memberships = get_option('myls_org_memberships', []);
	if ( is_array($memberships) && ! empty($memberships) ) {
		$member_of = [];
		foreach ( $memberships as $m ) {
			if ( ! is_array($m) || empty($m['name']) ) continue;
			$org = [
				'@type' => 'Organization',
				'name'  => sanitize_text_field( $m['name'] ),
			];
			if ( ! empty($m['url']) )         $org['url']         = esc_url_raw( $m['url'] );
			if ( ! empty($m['logo_url']) )    $org['logo']        = esc_url_raw( $m['logo_url'] );
			if ( ! empty($m['description']) ) $org['description'] = sanitize_text_field( $m['description'] );
			$member_of[] = $org;
		}
		if ( ! empty($member_of) ) {
			$node['memberOf'] = $member_of;
		}
	}

	// Optional geo (allowed via Place link)
	if ( $lat !== '' && $lng !== '' ) {
		$geo = function_exists('myls_build_geo_coordinates')
			? myls_build_geo_coordinates( $lat, $lng )
			: null;
		if ( $geo ) {
			$node['location'] = [ '@type' => 'Place', 'geo' => $geo ];
		}
	}

	// Recommended ContactPoint if telephone exists
	if ( $tel !== '' ) {
		$normalized_tel = function_exists('myls_normalize_phone_e164')
			? myls_normalize_phone_e164( $tel )
			: $tel;
		$node['contactPoint'] = [[
			'@type'       => 'ContactPoint',
			'telephone'   => $normalized_tel,
			'contactType' => 'customer service',
		]];
	}

	// AggregateRating intentionally omitted from Organization.
	// It lives on LocalBusiness (and Service) — adding it here creates
	// duplicate entity signals since Organization is the site-wide identifier only.

	// founder — @id references to all enabled Person profiles
	$person_profiles = get_option( 'myls_person_profiles', [] );
	if ( is_array( $person_profiles ) && ! empty( $person_profiles ) ) {
		$founder_refs = [];
		foreach ( $person_profiles as $fp ) {
			if ( empty( $fp['name'] ) || ( $fp['enabled'] ?? '1' ) !== '1' ) continue;
			$founder_refs[] = [ '@id' => home_url( '/#person-' . sanitize_title( $fp['name'] ) ) ];
		}
		if ( ! empty( $founder_refs ) ) {
			$node['founder'] = count( $founder_refs ) === 1 ? $founder_refs[0] : $founder_refs;
		}
	}

	// Allow last-mile tweaks
	$current_id = get_queried_object_id();
	$node = apply_filters('myls_schema_org_node', $node, $current_id);

	if ( is_array($node) && ! empty($node) ) {
		$graph[] = $node;
	}

	return $graph;
}, 10);

// Debug info in page source
add_filter('myls_schema_graph', function($graph){
    if ( defined('MYLS_DEBUG_ORG') && MYLS_DEBUG_ORG ) {
        $current_id = get_queried_object_id();
        $has = false;
        foreach ($graph as $node) {
            if ( isset($node['@type']) && $node['@type'] === 'Organization' ) {
                $has = true;
                break;
            }
        }
        echo "\n<!-- MYLS DEBUG: Organization schema "
           . ( $has ? "OUTPUT on page {$current_id}" : "NOT output on page {$current_id}" )
           . " -->\n";
    }
    return $graph;
}, 999);
