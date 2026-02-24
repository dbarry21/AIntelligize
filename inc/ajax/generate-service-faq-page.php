<?php
/**
 * AJAX: Generate / Update the Service FAQ Page
 *
 * Creates (or updates) a WordPress page containing the [service_faq_page]
 * shortcode. The page ID is stored in option `myls_service_faq_page_id`.
 *
 * POST params (via FormData):
 *   page_title  — Page title (default: "Service FAQs")
 *   page_slug   — Page slug / permalink (default: auto from title)
 *   page_status — publish | draft (default: "publish")
 *
 * Nonce: myls_schema_save (reuses the Schema tab nonce)
 *
 * @since 4.15.3
 * @updated 4.15.4 — Added page_slug support, deduped FAQ count in response.
 */

if ( ! defined('ABSPATH') ) exit;

add_action( 'wp_ajax_myls_generate_service_faq_page', function () {

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
	}

	check_ajax_referer( 'myls_schema_save', 'nonce' );

	$page_title  = isset( $_POST['page_title'] )  ? sanitize_text_field( wp_unslash( $_POST['page_title'] ) )  : '';
	$page_slug   = isset( $_POST['page_slug'] )   ? sanitize_title( wp_unslash( $_POST['page_slug'] ) )        : '';
	$page_status = isset( $_POST['page_status'] ) ? sanitize_key( $_POST['page_status'] )                      : 'publish';

	if ( $page_title === '' ) {
		$page_title = 'Service FAQs';
	}
	if ( ! in_array( $page_status, [ 'publish', 'draft' ], true ) ) {
		$page_status = 'publish';
	}

	// Shortcode content for the page.
	$shortcode_content = '[service_faq_page]';

	// Check for existing page.
	$existing_id = (int) get_option( 'myls_service_faq_page_id', 0 );

	if ( $existing_id && get_post_status( $existing_id ) !== false ) {

		// Update existing page.
		$update_args = [
			'ID'           => $existing_id,
			'post_title'   => $page_title,
			'post_content' => $shortcode_content,
			'post_status'  => $page_status,
		];

		// Only set slug if provided (don't blank it out).
		if ( $page_slug !== '' ) {
			$update_args['post_name'] = $page_slug;
		}

		$result = wp_update_post( $update_args, true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => 'Failed to update page: ' . $result->get_error_message() ] );
		}

		$page_id = $existing_id;
		$action  = 'updated';

	} else {

		// Create new page.
		$insert_args = [
			'post_type'    => 'page',
			'post_title'   => $page_title,
			'post_content' => $shortcode_content,
			'post_status'  => $page_status,
			'post_author'  => get_current_user_id(),
		];

		if ( $page_slug !== '' ) {
			$insert_args['post_name'] = $page_slug;
		}

		$page_id = wp_insert_post( $insert_args, true );

		if ( is_wp_error( $page_id ) ) {
			wp_send_json_error( [ 'message' => 'Failed to create page: ' . $page_id->get_error_message() ] );
		}

		update_option( 'myls_service_faq_page_id', (int) $page_id );
		$action = 'created';
	}

	// ── Auto-assign LocalBusiness schema to this page ──
	// Sets post meta so the LB provider picks it up (uses location index 0).
	$lb_locs = (array) get_option( 'myls_lb_locations', [] );
	if ( ! empty( $lb_locs ) ) {
		update_post_meta( (int) $page_id, '_myls_lb_assigned', '1' );
		update_post_meta( (int) $page_id, '_myls_lb_loc_index', '0' );
	}

	// ── Count services + deduped FAQ stats for the response summary ──
	$services = get_posts( [
		'post_type'      => 'service',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	] );

	$total_services    = count( $services );
	$services_w_faqs   = 0;
	$total_faqs_raw    = 0;
	$seen_questions    = [];
	$total_faqs_deduped = 0;

	foreach ( $services as $sid ) {
		$items = function_exists( 'myls_get_faq_items_meta' ) ? myls_get_faq_items_meta( (int) $sid ) : [];
		if ( ! empty( $items ) ) {
			$services_w_faqs++;
			$total_faqs_raw += count( $items );
			foreach ( $items as $row ) {
				$key = mb_strtolower( trim( $row['q'] ?? '' ) );
				if ( $key !== '' && ! isset( $seen_questions[ $key ] ) ) {
					$seen_questions[ $key ] = true;
					$total_faqs_deduped++;
				}
			}
		}
	}

	$dupes_removed = $total_faqs_raw - $total_faqs_deduped;

	// Fetch the actual saved slug (WP may have modified it).
	$saved_post = get_post( $page_id );
	$actual_slug = $saved_post ? $saved_post->post_name : '';

	wp_send_json_success( [
		'message'          => "Page {$action} successfully.",
		'action'           => $action,
		'page_id'          => (int) $page_id,
		'page_slug'        => $actual_slug,
		'edit_url'         => get_edit_post_link( $page_id, 'raw' ),
		'view_url'         => get_permalink( $page_id ),
		'total_services'   => $total_services,
		'services_w_faqs'  => $services_w_faqs,
		'total_faqs'       => $total_faqs_deduped,
		'dupes_removed'    => $dupes_removed,
	] );
});
