<?php
/**
 * AJAX Handlers: Import / Export (Bulk subtab)
 * File: admin/tabs/bulk/_import-export-ajax.php
 *
 * Endpoints:
 *  - myls_ie_export_csv       (GET)  streams all FAQs as CSV download
 *  - myls_ie_import_preview   (POST) parses uploaded CSV, returns change summary
 *  - myls_ie_import_confirm   (POST) applies previously previewed import
 */

if ( ! defined('ABSPATH') ) exit;

/* =========================================================================
 * EXPORT — stream all FAQs as CSV
 * ========================================================================= */
add_action( 'wp_ajax_myls_ie_export_csv', function () {

	if (
		empty( $_GET['nonce'] ) ||
		! wp_verify_nonce( $_GET['nonce'], 'myls_bulk_ops' ) ||
		! current_user_can( 'manage_options' )
	) {
		wp_die( 'Unauthorized', 403 );
	}

	// Gather every post that has _myls_faq_items meta.
	global $wpdb;
	$post_ids = $wpdb->get_col(
		"SELECT DISTINCT pm.post_id
		   FROM {$wpdb->postmeta} pm
		   JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		  WHERE pm.meta_key = '_myls_faq_items'
		    AND p.post_status IN ('publish','draft','pending','future','private')
		  ORDER BY p.post_title ASC"
	);

	if ( empty( $post_ids ) ) {
		wp_die( 'No FAQ data found.', 404 );
	}

	// Stream CSV headers.
	$filename = 'faqs-export-' . gmdate( 'Y-m-d' ) . '.csv';
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );

	$out = fopen( 'php://output', 'w' );

	// UTF-8 BOM for Excel.
	fwrite( $out, "\xEF\xBB\xBF" );

	// Header row.
	fputcsv( $out, [ 'post_id', 'post_title', 'permalink', 'faq_index', 'question', 'answer' ] );

	foreach ( $post_ids as $pid ) {
		$pid   = (int) $pid;
		$items = function_exists( 'myls_get_faq_items' )
			? myls_get_faq_items( $pid )
			: get_post_meta( $pid, '_myls_faq_items', true );

		if ( ! is_array( $items ) || empty( $items ) ) continue;

		$title     = get_the_title( $pid ) ?: ( 'Post #' . $pid );
		$permalink = get_permalink( $pid ) ?: '';

		foreach ( $items as $idx => $item ) {
			if ( empty( $item['q'] ) && empty( $item['a'] ) ) continue;
			fputcsv( $out, [
				$pid,
				$title,
				$permalink,
				(int) $idx,
				$item['q'] ?? '',
				$item['a'] ?? '',
			] );
		}
	}

	fclose( $out );
	exit;
} );

/* =========================================================================
 * IMPORT PREVIEW — parse CSV, diff against current, store in transient
 * ========================================================================= */
add_action( 'wp_ajax_myls_ie_import_preview', function () {

	if (
		empty( $_POST['nonce'] ) ||
		! wp_verify_nonce( $_POST['nonce'], 'myls_bulk_ops' ) ||
		! current_user_can( 'manage_options' )
	) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
	}

	if ( empty( $_FILES['csv_file'] ) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
		wp_send_json_error( [ 'message' => 'No file uploaded or upload error.' ] );
	}

	$file = $_FILES['csv_file'];
	$ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
	if ( $ext !== 'csv' ) {
		wp_send_json_error( [ 'message' => 'Only .csv files are accepted.' ] );
	}

	$handle = fopen( $file['tmp_name'], 'r' );
	if ( ! $handle ) {
		wp_send_json_error( [ 'message' => 'Could not open uploaded file.' ] );
	}

	// Strip UTF-8 BOM if present.
	$bom = fread( $handle, 3 );
	if ( $bom !== "\xEF\xBB\xBF" ) {
		rewind( $handle );
	}

	// Validate header row.
	$header = fgetcsv( $handle );
	if ( ! $header || count( $header ) < 5 ) {
		fclose( $handle );
		wp_send_json_error( [ 'message' => 'Invalid CSV header. Expected: post_id, post_title, permalink, faq_index, question, answer' ] );
	}

	// Normalise header (trim whitespace, lowercase).
	$header = array_map( function ( $h ) {
		return strtolower( trim( $h ) );
	}, $header );

	// Support both old 5-column format and new 6-column format with permalink.
	$expected_new = [ 'post_id', 'post_title', 'permalink', 'faq_index', 'question', 'answer' ];
	$expected_old = [ 'post_id', 'post_title', 'faq_index', 'question', 'answer' ];

	if ( array_slice( $header, 0, 6 ) === $expected_new ) {
		$has_permalink = true;
	} elseif ( array_slice( $header, 0, 5 ) === $expected_old ) {
		$has_permalink = false;
	} else {
		fclose( $handle );
		wp_send_json_error( [ 'message' => 'CSV header mismatch. Expected columns: ' . implode( ', ', $expected_new ) ] );
	}

	// Column offsets: permalink column shifts question/answer indices.
	$col_q = $has_permalink ? 4 : 3;
	$col_a = $has_permalink ? 5 : 4;
	$min_cols = $has_permalink ? 6 : 5;

	// Parse rows, group by post_id.
	$by_post   = [];
	$row_count = 0;

	while ( ( $row = fgetcsv( $handle ) ) !== false ) {
		if ( count( $row ) < $min_cols ) continue;
		$row_count++;

		$pid = absint( $row[0] );
		if ( ! $pid ) continue;

		if ( ! isset( $by_post[ $pid ] ) ) {
			$by_post[ $pid ] = [
				'title' => sanitize_text_field( $row[1] ),
				'items' => [],
			];
		}

		$by_post[ $pid ]['items'][] = [
			'q' => sanitize_text_field( $row[ $col_q ] ),
			'a' => wp_kses_post( $row[ $col_a ] ),
		];
	}
	fclose( $handle );

	if ( empty( $by_post ) ) {
		wp_send_json_error( [ 'message' => 'No valid FAQ rows found in CSV.' ] );
	}

	// Diff against current data.
	$summary = [
		'total_rows'  => $row_count,
		'total_posts' => count( $by_post ),
		'added'       => 0,
		'modified'    => 0,
		'unchanged'   => 0,
		'skipped'     => 0,
		'posts'       => [],
	];

	foreach ( $by_post as $pid => &$entry ) {
		$post = get_post( $pid );
		if ( ! $post ) {
			$entry['status'] = 'skipped';
			$summary['skipped']++;
			$summary['posts'][] = [
				'id'     => $pid,
				'title'  => $entry['title'],
				'status' => 'skipped',
				'reason' => 'Post not found',
			];
			continue;
		}

		$current = function_exists( 'myls_get_faq_items' )
			? myls_get_faq_items( $pid )
			: ( get_post_meta( $pid, '_myls_faq_items', true ) ?: [] );

		// Compare item-by-item.
		$post_added    = 0;
		$post_modified = 0;
		$post_same     = 0;

		foreach ( $entry['items'] as $idx => $new_item ) {
			if ( isset( $current[ $idx ] ) ) {
				$cur = $current[ $idx ];
				if ( trim( $cur['q'] ?? '' ) === trim( $new_item['q'] ) && trim( $cur['a'] ?? '' ) === trim( $new_item['a'] ) ) {
					$post_same++;
				} else {
					$post_modified++;
				}
			} else {
				$post_added++;
			}
		}

		// FAQs in current but not in CSV count as removed (full replacement).
		$removed = max( 0, count( $current ) - count( $entry['items'] ) );

		$entry['status'] = ( $post_modified > 0 || $post_added > 0 || $removed > 0 ) ? 'changed' : 'unchanged';

		$summary['added']     += $post_added;
		$summary['modified']  += $post_modified;
		$summary['unchanged'] += $post_same;

		$summary['posts'][] = [
			'id'       => $pid,
			'title'    => get_the_title( $pid ),
			'status'   => $entry['status'],
			'added'    => $post_added,
			'modified' => $post_modified,
			'same'     => $post_same,
			'removed'  => $removed,
		];
	}
	unset( $entry );

	// Store parsed data in transient for confirm step.
	$user_id       = get_current_user_id();
	$transient_key = 'myls_ie_import_' . $user_id;
	set_transient( $transient_key, $by_post, 5 * MINUTE_IN_SECONDS );

	wp_send_json_success( $summary );
} );

/* =========================================================================
 * IMPORT CONFIRM — apply the previewed import
 * ========================================================================= */
add_action( 'wp_ajax_myls_ie_import_confirm', function () {

	if (
		empty( $_POST['nonce'] ) ||
		! wp_verify_nonce( $_POST['nonce'], 'myls_bulk_ops' ) ||
		! current_user_can( 'manage_options' )
	) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
	}

	$user_id       = get_current_user_id();
	$transient_key = 'myls_ie_import_' . $user_id;
	$by_post       = get_transient( $transient_key );

	if ( ! is_array( $by_post ) || empty( $by_post ) ) {
		wp_send_json_error( [ 'message' => 'Import session expired. Please upload the CSV again.' ] );
	}

	$sanitize = function_exists( 'myls_faq_editor_sanitize_items' )
		? 'myls_faq_editor_sanitize_items'
		: null;

	$log     = [];
	$updated = 0;
	$skipped = 0;

	foreach ( $by_post as $pid => $entry ) {
		$pid = (int) $pid;

		if ( ! empty( $entry['status'] ) && $entry['status'] === 'skipped' ) {
			$skipped++;
			$log[] = "Skipped post #{$pid} ({$entry['title']}) — post not found";
			continue;
		}

		if ( ! get_post( $pid ) ) {
			$skipped++;
			$log[] = "Skipped post #{$pid} — post not found";
			continue;
		}

		$items = $entry['items'] ?? [];

		// Sanitize through existing helper if available.
		if ( $sanitize ) {
			$items = $sanitize( $items );
		}

		if ( function_exists( 'myls_set_faq_items' ) ) {
			myls_set_faq_items( $pid, $items );
		} else {
			update_post_meta( $pid, '_myls_faq_items', array_values( $items ) );
		}

		$title = get_the_title( $pid ) ?: ( 'Post #' . $pid );
		$count = count( $items );
		$updated++;
		$log[] = "Updated post #{$pid} ({$title}) — {$count} FAQ(s)";
	}

	// Clean up transient.
	delete_transient( $transient_key );

	wp_send_json_success( [
		'updated' => $updated,
		'skipped' => $skipped,
		'log'     => $log,
	] );
} );
