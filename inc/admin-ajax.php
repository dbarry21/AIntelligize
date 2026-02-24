<?php
// File: inc/admin-ajax.php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AJAX: Flush rewrites (matches nonce used in tab-cpt.php)
 */
add_action('wp_ajax_myls_flush_rewrites', function(){
	if ( ! current_user_can('manage_options') ) {
		wp_send_json_error(['message' => 'Permission denied'], 403);
	}
	check_ajax_referer('myls_cpt_ajax');

	if ( function_exists('myls_register_enabled_cpts') ) {
		myls_register_enabled_cpts();
	}
	flush_rewrite_rules(false);

	wp_send_json_success(['message' => 'Rewrite rules flushed.']);
});

/**
 * AJAX: Check if a CPT is registered (with full diagnostics)
 * POST: cpt   (e.g., "service", "service_area", "service-area", "products", "videos")
 * Nonce: myls_cpt_ajax
 */
add_action('wp_ajax_myls_check_cpt', function(){
	if ( ! current_user_can('manage_options') ) {
		wp_send_json_error(['message' => 'Permission denied'], 403);
	}
	check_ajax_referer('myls_cpt_ajax');

	$raw   = isset($_POST['cpt']) ? wp_unslash($_POST['cpt']) : '';
	$given = sanitize_text_field( $raw );
	if ( $given === '' ) {
		wp_send_json_error(['message' => 'Missing CPT id'], 400);
	}

	// --- Normalize ----------------------------------------------------------
	$norm            = strtolower( trim( $given ) );
	$norm            = str_replace(' ', '_', $norm);
	$norm_hyphen     = str_replace('_', '-', $norm);
	$norm_underscore = str_replace('-', '_', $norm);

	$alias_map = [
		'services'       => 'service',
		'service-areas'  => 'service_area',
		'service_areas'  => 'service_area',
		'products'       => 'product',
		'videos'         => 'video',
	];

	$candidates = array_unique(array_filter([
		$norm,
		$norm_hyphen,
		$norm_underscore,
		$alias_map[$norm]            ?? null,
		$alias_map[$norm_hyphen]     ?? null,
		$alias_map[$norm_underscore] ?? null,
	]));

	// --- Re-register inside this request (safe / idempotent) --------------
	if ( function_exists('myls_register_enabled_cpts') ) {
		myls_register_enabled_cpts();
	}

	// --- Probe candidates ---------------------------------------------------
	$found_id = null;
	foreach ( $candidates as $try ) {
		if ( post_type_exists( $try ) ) { $found_id = $try; break; }
	}

	// --- Collect option values for each candidate --------------------------
	$opts_dump = [];
	foreach ( $candidates as $try ) {
		$prefix = "myls_enable_{$try}_cpt";
		$opts_dump[$try] = [
			'enabled'      => get_option( $prefix, null ),
			'slug'         => get_option( "{$prefix}_slug", null ),
			'has_archive'  => get_option( "{$prefix}_hasarchive", null ),
		];
	}

	// --- Snapshot of registered post types beginning with our names --------
	$registered_all = get_post_types([], 'names');
	$registered_myls = array_values(
		array_filter($registered_all, function($pt){
			// show typical names we care about
			return in_array($pt, ['service','service_area','product','video'], true);
		})
	);

	// --- Build response -----------------------------------------------------
	$resp = [
		'requested'        => $given,
		'candidates'       => array_values($candidates),
		'registered'       => $found_id ? 1 : 0,
		'resolved_id'      => $found_id ?: '',
		'options_snapshot' => $opts_dump,
		'registered_types' => $registered_myls,
	];

	if ( $found_id ) {
		$obj = get_post_type_object( $found_id );
		$resp['label']        = $obj && isset($obj->labels->name) ? $obj->labels->name : $found_id;
		$resp['has_archive']  = (bool) ( $obj ? $obj->has_archive : false );
		$resp['rewrite_slug'] = ( $obj && is_array($obj->rewrite) && isset($obj->rewrite['slug']) ) ? $obj->rewrite['slug'] : $found_id;
		$resp['archive_url']  = ( $obj && $obj->has_archive ) ? get_post_type_archive_link( $found_id ) : '';

		// Reflect registrar helpers if present
		if ( function_exists('myls_cpt_slug') ) {
			$resp['settings_slug'] = myls_cpt_slug( $found_id, $resp['rewrite_slug'] );
		}
		if ( function_exists('myls_cpt_has_archive') ) {
			$arch = myls_cpt_has_archive( $found_id, false );
			$resp['settings_has_archive']  = ( $arch === true || (is_string($arch) && $arch !== '') ) ? 1 : 0;
			$resp['settings_archive_slug'] = is_string($arch) ? $arch : ( $arch === true ? $resp['rewrite_slug'] : '' );
		}
	} else {
		$resp['hint'] = 'Not registered. Ensure the CPT is enabled in the CPT tab and click Save (which flushes rewrites).';
	}

	wp_send_json_success( $resp );
});
