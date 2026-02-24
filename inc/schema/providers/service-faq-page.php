<?php
/**
 * Provider: Service FAQ Page — FAQPage schema
 *
 * Hooks into `myls_schema_graph` to inject a FAQPage node containing all
 * aggregated, deduplicated FAQ items from published Service posts.
 *
 * Only fires on the generated Service FAQ Page (tracked via option
 * `myls_service_faq_page_id`). This runs during wp_head (via registry.php
 * at priority 90) — well before shortcode execution — so the schema is
 * guaranteed to appear in <head>.
 *
 * @since 4.15.5
 */

if ( ! defined('ABSPATH') ) exit;

add_filter( 'myls_schema_graph', function ( array $graph ) {

	// Only on the front-end, singular pages.
	if ( is_admin() || wp_doing_ajax() || ! is_singular('page') ) {
		return $graph;
	}

	// Is this the Service FAQ Page?
	$svc_faq_page_id = (int) get_option( 'myls_service_faq_page_id', 0 );
	if ( $svc_faq_page_id <= 0 ) {
		return $graph;
	}

	$current_id = (int) get_queried_object_id();
	if ( $current_id !== $svc_faq_page_id ) {
		return $graph;
	}

	// ── Collect all FAQs from published services (deduped) ──
	$services = get_posts([
		'post_type'      => 'service',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'menu_order',
		'order'          => 'ASC',
	]);

	if ( empty( $services ) ) {
		return $graph;
	}

	$seen        = [];
	$main_entity = [];

	foreach ( $services as $service ) {
		$post_id = (int) $service->ID;
		$items   = [];

		// MYLS native.
		if ( function_exists( 'myls_faq_collect_items_native' ) ) {
			$items = myls_faq_collect_items_native( $post_id );
		} elseif ( function_exists( 'myls_get_faq_items_meta' ) ) {
			$raw = myls_get_faq_items_meta( $post_id );
			if ( is_array( $raw ) ) {
				foreach ( $raw as $row ) {
					if ( ! is_array( $row ) ) continue;
					$q = trim( sanitize_text_field( (string) ( $row['q'] ?? '' ) ) );
					$a = trim( wp_kses_post( (string) ( $row['a'] ?? '' ) ) );
					if ( $q !== '' && $a !== '' ) {
						$items[] = [ 'q' => $q, 'a' => $a ];
					}
				}
			}
		}

		// ACF fallback.
		if ( empty( $items ) && function_exists( 'myls_faq_collect_items_acf' ) ) {
			$items = myls_faq_collect_items_acf( $post_id );
		}

		// Dedupe across all services.
		foreach ( $items as $row ) {
			$key = mb_strtolower( trim( $row['q'] ?? '' ) );
			if ( $key === '' || isset( $seen[ $key ] ) ) continue;
			$seen[ $key ] = true;

			$main_entity[] = [
				'@type'          => 'Question',
				'name'           => $row['q'],
				'acceptedAnswer' => [
					'@type' => 'Answer',
					'text'  => $row['a'],
				],
			];
		}
	}

	if ( empty( $main_entity ) ) {
		return $graph;
	}

	$permalink = get_permalink( $svc_faq_page_id );

	$node = [
		'@type'      => 'FAQPage',
		'@id'        => trailingslashit( $permalink ) . '#faq',
		'url'        => $permalink,
		'name'       => get_the_title( $svc_faq_page_id ),
		'mainEntity' => $main_entity,
	];

	$graph[] = apply_filters( 'myls_service_faq_page_schema_node', $node, $svc_faq_page_id );

	return $graph;
});
