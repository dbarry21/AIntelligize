<?php
// File: inc/schema/providers/website.php
if ( ! defined('ABSPATH') ) exit;

/**
 * Provider: WebSite
 *
 * Emits a site-level WebSite node into the unified @graph.
 * Links to Organization via publisher.
 * Includes SearchAction on the front page for sitelinks search box.
 *
 * @since 7.8.92
 */

add_filter( 'myls_schema_graph', function ( array $graph ) {

	// Only front-end pages
	if ( is_admin() || wp_doing_ajax() ) return $graph;
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return $graph;
	if ( is_feed() || is_preview() ) return $graph;

	$name = trim( (string) get_option( 'myls_org_name', get_bloginfo( 'name' ) ) );
	if ( $name === '' ) $name = get_bloginfo( 'name' );

	$node = [
		'@type'     => 'WebSite',
		'@id'       => home_url( '/#website' ),
		'name'      => wp_specialchars_decode( $name, ENT_QUOTES ),
		'url'       => home_url( '/' ),
		'publisher' => [ '@id' => home_url( '/#organization' ) ],
	];

	// inLanguage
	$lang = get_bloginfo( 'language' );
	if ( $lang ) $node['inLanguage'] = $lang;

	// SearchAction — sitelinks search box (front page only)
	if ( is_front_page() ) {
		$node['potentialAction'] = [
			'@type'       => 'SearchAction',
			'target'      => [
				'@type'        => 'EntryPoint',
				'urlTemplate'  => home_url( '/?s={search_term_string}' ),
			],
			'query-input' => 'required name=search_term_string',
		];
	}

	$node = apply_filters( 'myls_website_schema_node', $node );

	if ( is_array( $node ) && ! empty( $node ) ) {
		$graph[] = $node;
	}

	return $graph;
}, 4 ); // Priority 4: WebSite loads before everything else
