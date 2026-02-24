<?php
// File: inc/schema/providers/about-page.php
if ( ! defined('ABSPATH') ) exit;

/**
 * Provider: AboutPage / "About Us" Schema
 *
 * Output rules:
 * - Only outputs when enabled AND the current page matches the selected About Page.
 * - Emits an @graph with:
 *    - WebSite (minimal)
 *    - AboutPage (points to the primary entity)
 *    - Primary entity as an embedded Organization/LocalBusiness object (so it validates
 *      even if your Organization/LocalBusiness nodes are not assigned to this page).
 *
 * Settings (saved by Schema > About Us subtab):
 * - myls_about_enabled ("0"/"1")
 * - myls_about_page_id (int)
 * - myls_about_headline (string)
 * - myls_about_description (string)
 * - myls_about_primary_image (url)
 */

if ( ! function_exists('myls_about_get_primary_entity') ) {
	/**
	 * Build an embedded entity object from existing MYLS Organization / LocalBusiness options.
	 *
	 * Preference order:
	 *  1) LocalBusiness locations (Location #1) if available
	 *  2) Organization fields
	 */
	function myls_about_get_primary_entity() : array {
		$site_url = home_url('/');

		// ---- Try LocalBusiness first (Location #1), if present ----
		$lb_locations = get_option('myls_lb_locations', []);
		if ( is_array($lb_locations) && ! empty($lb_locations) && is_array($lb_locations[0]) ) {
			$loc = $lb_locations[0];

			$name = trim( (string) ($loc['name'] ?? '') );
			if ( $name !== '' ) {
				$entity = [
					'@type' => 'LocalBusiness',
					'name'  => $name,
					'url'   => esc_url_raw( $site_url ),
				];

				$tel   = trim( (string) ($loc['telephone'] ?? '') );
				$email = trim( (string) get_option('myls_org_email', '') ); // org email is global
				$desc  = trim( (string) get_option('myls_org_description', '') );
				$img   = trim( (string) ($loc['image_url'] ?? '') );

				if ( $desc !== '' )  $entity['description'] = $desc;
				if ( $tel !== '' )   $entity['telephone']   = $tel;
				if ( $email !== '' ) $entity['email']       = $email;
				if ( $img !== '' )   $entity['image']       = esc_url_raw($img);

				// Address (if present)
				$address = array_filter([
					'@type'           => 'PostalAddress',
					'streetAddress'   => trim((string)($loc['street'] ?? '')) ?: null,
					'addressLocality' => trim((string)($loc['city'] ?? '')) ?: null,
					'addressRegion'   => trim((string)($loc['region'] ?? '')) ?: null,
					'postalCode'      => trim((string)($loc['postal'] ?? '')) ?: null,
					'addressCountry'  => trim((string)($loc['country'] ?? '')) ?: null,
				]);
				if ( count($address) > 1 ) {
					$entity['address'] = $address;
				}

				// Social profiles
				$socials = get_option('myls_org_social_profiles', []);
				if ( is_array($socials) ) {
					$socials = array_values(array_filter(array_map('trim', $socials)));
					if ( $socials ) $entity['sameAs'] = array_map('esc_url_raw', $socials);
				}

				return $entity;
			}
		}

		// ---- Fall back to Organization ----
		$name = trim( (string) get_option('myls_org_name', get_bloginfo('name')) );
		$entity = [
			'@type' => 'Organization',
			'name'  => $name,
			'url'   => esc_url_raw( $site_url ),
		];

		$desc  = trim( (string) get_option('myls_org_description', '') );
		$email = trim( (string) get_option('myls_org_email', '') );
		$tel   = trim( (string) get_option('myls_org_tel', '') );
		$logo_id = (int) get_option('myls_org_logo_id', 0);
		$logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';
		$image_url = trim( (string) get_option('myls_org_image_url', '') );

		if ( $desc !== '' )  $entity['description'] = $desc;
		if ( $email !== '' ) $entity['email']       = $email;
		if ( $tel !== '' )   $entity['telephone']   = $tel;
		if ( $logo_url )     $entity['logo']        = esc_url_raw($logo_url);
		if ( $image_url )    $entity['image']       = esc_url_raw($image_url);

		$socials = get_option('myls_org_social_profiles', []);
		if ( is_array($socials) ) {
			$socials = array_values(array_filter(array_map('trim', $socials)));
			if ( $socials ) $entity['sameAs'] = array_map('esc_url_raw', $socials);
		}

		// Address (optional)
		$address = array_filter([
			'@type'           => 'PostalAddress',
			'streetAddress'   => trim((string)get_option('myls_org_street','')) ?: null,
			'addressLocality' => trim((string)get_option('myls_org_locality','')) ?: null,
			'addressRegion'   => trim((string)get_option('myls_org_region','')) ?: null,
			'postalCode'      => trim((string)get_option('myls_org_postal','')) ?: null,
			'addressCountry'  => trim((string)get_option('myls_org_country','')) ?: null,
		]);
		if ( count($address) > 1 ) $entity['address'] = $address;

		return $entity;
	}
}

add_filter('myls_schema_graph', function(array $graph) {

	$enabled = (string) get_option('myls_about_enabled', '0') === '1';
	$page_id = (int) get_option('myls_about_page_id', 0);

	if ( ! $enabled || ! $page_id ) return $graph;

	$current_id = get_queried_object_id();
	if ( ! $current_id || (int) $current_id !== (int) $page_id ) return $graph;

	// Build page data
	$url = get_permalink($page_id);
	if ( ! $url ) return $graph;

	$default_name = get_the_title($page_id) ?: 'About Us';
	$headline = trim( (string) get_option('myls_about_headline', '') );
	$desc     = trim( (string) get_option('myls_about_description', '') );
	$img      = trim( (string) get_option('myls_about_primary_image', '') );

	$page_name = $headline !== '' ? $headline : $default_name;
	$page_desc = $desc !== '' ? $desc : '';

	$site_url  = home_url('/');
	$site_name = get_bloginfo('name');

	// Minimal WebSite node (safe to duplicate)
	$graph[] = [
		'@type' => 'WebSite',
		'@id'   => trailingslashit($site_url) . '#website',
		'url'   => esc_url_raw($site_url),
		'name'  => $site_name,
	];

	$about_node = [
		'@type'        => 'AboutPage',
		'@id'          => untrailingslashit($url) . '#about',
		'url'          => esc_url_raw($url),
		'name'         => $page_name,
		'isPartOf'     => [ '@id' => trailingslashit($site_url) . '#website' ],
		'about'        => myls_about_get_primary_entity(),
	];

	if ( $page_desc !== '' ) {
		$about_node['description'] = $page_desc;
	}

	if ( $img !== '' ) {
		$about_node['primaryImageOfPage'] = [
			'@type' => 'ImageObject',
			'url'   => esc_url_raw($img),
		];
	}

	// Let other code adjust the node
	$about_node = apply_filters('myls_schema_about_node', $about_node, $page_id);

	if ( is_array($about_node) && ! empty($about_node) ) {
		$graph[] = $about_node;
	}

	return $graph;
}, 20);
