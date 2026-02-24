<?php
// File: inc/schema/providers/faq.php
if ( ! defined('ABSPATH') ) exit;

/**
 * Provider: FAQPage
 *
 * Goal: Remove ACF dependency over time.
 *
 * Data sources (in priority order):
 *  1) Native MYLS custom fields:
 *      - _myls_faq_items (array of ['q' => string, 'a' => html])
 *  2) Legacy ACF repeater fallback:
 *      - faq_items (question/answer)
 *
 * Enable toggle:
 *  - myls_faq_enabled === '1' (Schema → FAQ subtab)
 */

/* -------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------- */

if ( ! function_exists('myls_faq_collect_items_native') ) {
	/**
	 * Pull MYLS FAQs from post meta.
	 * Returns normalized array of ['q' => string, 'a' => string(html)]
	 */
	function myls_faq_collect_items_native( int $post_id ) : array {
		$items = null;

		// Prefer the helper from the meta box file if present.
		if ( function_exists('myls_get_faq_items_meta') ) {
			$items = myls_get_faq_items_meta( $post_id );
		} else {
			$items = get_post_meta( $post_id, '_myls_faq_items', true );
		}

		if ( ! is_array($items) ) return [];

		$out = [];
		foreach ( $items as $row ) {
			if ( ! is_array($row) ) continue;
			$q = trim( sanitize_text_field( (string) ( $row['q'] ?? '' ) ) );
			$a = trim( wp_kses_post( (string) ( $row['a'] ?? '' ) ) );
			if ( $q === '' || $a === '' ) continue;
			$out[] = [ 'q' => $q, 'a' => $a ];
		}
		return $out;
	}
}

if ( ! function_exists('myls_faq_collect_items_acf') ) {
	/**
	 * Legacy fallback: ACF repeater faq_items.
	 * Returns normalized array of ['q' => string, 'a' => string(html)]
	 */
	function myls_faq_collect_items_acf( int $post_id ) : array {
		if ( ! function_exists('have_rows') || ! function_exists('get_sub_field') ) return [];
		if ( ! have_rows('faq_items', $post_id) ) return [];

		$out = [];
		while ( have_rows('faq_items', $post_id) ) {
			the_row();
			$q = trim( sanitize_text_field( (string) get_sub_field('question') ) );
			$a = trim( wp_kses_post( (string) get_sub_field('answer') ) );
			if ( $q === '' || $a === '' ) continue;
			$out[] = [ 'q' => $q, 'a' => $a ];
		}
		return $out;
	}
}

if ( ! function_exists('myls_faq_items_to_main_entity') ) {
	/**
	 * Convert normalized items into Schema.org mainEntity.
	 */
	function myls_faq_items_to_main_entity( array $items ) : array {
		$main = [];
		foreach ( $items as $row ) {
			$q = trim( (string)($row['q'] ?? '') );
			$a = trim( (string)($row['a'] ?? '') );
			if ( $q === '' || $a === '' ) continue;

			$main[] = [
				'@type'          => 'Question',
				'name'           => $q,
				'acceptedAnswer' => [
					'@type' => 'Answer',
					'text'  => $a,
				],
			];
		}
		return $main;
	}
}

/* -------------------------------------------------------------------------
 * Provider registration
 * ------------------------------------------------------------------------- */

add_filter('myls_schema_graph', function( array $graph ) {

	// Toggle
	if ( get_option('myls_faq_enabled', '0') !== '1' ) {
		return $graph;
	}

	// Only singular content
	if ( is_admin() || wp_doing_ajax() || ! is_singular() ) {
		return $graph;
	}

	// Skip if this is the Service FAQ Page (shortcode outputs its own FAQPage schema).
	$svc_faq_page_id = (int) get_option( 'myls_service_faq_page_id', 0 );
	if ( $svc_faq_page_id > 0 && $svc_faq_page_id === (int) get_queried_object_id() ) {
		return $graph;
	}

	$post_id = (int) get_queried_object_id();
	if ( $post_id <= 0 ) return $graph;

	// Only public post types (no attachments)
	$public = get_post_types([ 'public' => true ], 'names');
	unset($public['attachment']);
	if ( ! in_array( get_post_type($post_id), $public, true ) ) {
		return $graph;
	}

	// Collect items (MYLS first, then ACF fallback)
	$items = myls_faq_collect_items_native( $post_id );
	if ( empty($items) ) {
		$items = myls_faq_collect_items_acf( $post_id );
	}
	if ( empty($items) ) return $graph;

	$main = myls_faq_items_to_main_entity( $items );
	if ( empty($main) ) return $graph;

	$permalink = get_permalink( $post_id );
	$node = [
		'@type'      => 'FAQPage',
		'@id'        => trailingslashit( $permalink ) . '#faq',
		'mainEntity' => $main,
	];

	$graph[] = apply_filters( 'myls_faq_schema_node', $node, $post_id );
	return $graph;
});
