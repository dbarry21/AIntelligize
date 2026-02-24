<?php
/**
 * Provider: Memberships Page schema
 *
 * On the generated Memberships page, outputs an Organization node with
 * memberOf array containing all saved association memberships.
 * This ensures the page has its own structured data even if it's not
 * in the Organization page assignments list.
 *
 * @since 4.15.8
 */

if ( ! defined('ABSPATH') ) exit;

add_filter( 'myls_schema_graph', function ( array $graph ) {

	if ( is_admin() || wp_doing_ajax() || ! is_singular('page') ) {
		return $graph;
	}

	$mem_page_id = (int) get_option( 'myls_memberships_page_id', 0 );
	if ( $mem_page_id <= 0 ) return $graph;

	$current_id = (int) get_queried_object_id();
	if ( $current_id !== $mem_page_id ) return $graph;

	// Check if Organization schema already exists in graph for this page.
	// If so, the Org provider already handled it (including memberOf). Skip.
	foreach ( $graph as $node ) {
		if ( isset($node['@type']) && $node['@type'] === 'Organization' ) {
			return $graph;
		}
	}

	// Build a lightweight Organization node with memberOf.
	$org_name = trim( (string) get_option('myls_org_name', get_bloginfo('name')) );
	$org_url  = trim( (string) get_option('myls_org_url', home_url('/')) );

	if ( $org_name === '' ) return $graph;

	$memberships = (array) get_option( 'myls_org_memberships', [] );
	if ( empty($memberships) ) return $graph;

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

	if ( empty($member_of) ) return $graph;

	$logo_id  = (int) get_option('myls_org_logo_id', 0);
	$logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

	$node = [
		'@type'    => 'Organization',
		'@id'      => trailingslashit( get_permalink( $mem_page_id ) ) . '#organization',
		'name'     => $org_name,
		'url'      => esc_url_raw( $org_url ),
		'memberOf' => $member_of,
	];

	if ( $logo_url ) {
		$node['logo'] = ['@type' => 'ImageObject', 'url' => esc_url_raw($logo_url)];
	}

	$graph[] = apply_filters( 'myls_memberships_page_schema_node', $node, $mem_page_id );

	return $graph;
});
