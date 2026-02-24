<?php
/**
 * AJAX: Generate / Update the Association Memberships Page
 *
 * Creates (or updates) a WordPress page containing the [association_memberships]
 * shortcode. The page ID is stored in option `myls_memberships_page_id`.
 *
 * POST params:
 *   page_title  — default: "Our Memberships & Associations"
 *   page_slug   — default: auto from title
 *   page_status — publish | draft (default: "publish")
 *
 * @since 4.15.8
 */

if ( ! defined('ABSPATH') ) exit;

add_action( 'wp_ajax_myls_generate_memberships_page', function () {

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
	}

	check_ajax_referer( 'myls_schema_save', 'nonce' );

	$page_title  = isset( $_POST['page_title'] )  ? sanitize_text_field( wp_unslash( $_POST['page_title'] ) )  : '';
	$page_slug   = isset( $_POST['page_slug'] )   ? sanitize_title( wp_unslash( $_POST['page_slug'] ) )        : '';
	$page_status = isset( $_POST['page_status'] ) ? sanitize_key( $_POST['page_status'] )                      : 'publish';

	if ( $page_title === '' ) $page_title = 'Our Memberships & Associations';
	if ( ! in_array( $page_status, [ 'publish', 'draft' ], true ) ) $page_status = 'publish';

	$shortcode_content = '[association_memberships]';

	$existing_id = (int) get_option( 'myls_memberships_page_id', 0 );

	if ( $existing_id && get_post_status( $existing_id ) !== false ) {

		$update_args = [
			'ID'           => $existing_id,
			'post_title'   => $page_title,
			'post_content' => $shortcode_content,
			'post_status'  => $page_status,
		];
		if ( $page_slug !== '' ) $update_args['post_name'] = $page_slug;

		$result = wp_update_post( $update_args, true );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => 'Failed to update page: ' . $result->get_error_message() ] );
		}

		$page_id = $existing_id;
		$action  = 'updated';

	} else {

		$insert_args = [
			'post_type'    => 'page',
			'post_title'   => $page_title,
			'post_content' => $shortcode_content,
			'post_status'  => $page_status,
			'post_author'  => get_current_user_id(),
		];
		if ( $page_slug !== '' ) $insert_args['post_name'] = $page_slug;

		$page_id = wp_insert_post( $insert_args, true );
		if ( is_wp_error( $page_id ) ) {
			wp_send_json_error( [ 'message' => 'Failed to create page: ' . $page_id->get_error_message() ] );
		}

		update_option( 'myls_memberships_page_id', (int) $page_id );
		$action = 'created';
	}

	// Auto-assign LocalBusiness schema.
	$lb_locs = (array) get_option( 'myls_lb_locations', [] );
	if ( ! empty( $lb_locs ) ) {
		update_post_meta( (int) $page_id, '_myls_lb_assigned', '1' );
		update_post_meta( (int) $page_id, '_myls_lb_loc_index', '0' );
	}

	$memberships = (array) get_option( 'myls_org_memberships', [] );
	$count = count( array_filter( $memberships, function($m) { return is_array($m) && ! empty($m['name']); } ) );

	$saved_post  = get_post( $page_id );
	$actual_slug = $saved_post ? $saved_post->post_name : '';

	wp_send_json_success( [
		'message'          => "Page {$action} successfully.",
		'action'           => $action,
		'page_id'          => (int) $page_id,
		'page_slug'        => $actual_slug,
		'edit_url'         => get_edit_post_link( $page_id, 'raw' ),
		'view_url'         => get_permalink( $page_id ),
		'membership_count' => $count,
	] );
});
