<?php
/**
 * AJAX Handlers: Search & Replace (Bulk subtab)
 * File: admin/tabs/bulk/_search-replace-ajax.php
 *
 * Endpoints:
 *  - myls_sr_preview   (POST) dry-run: count matches per table
 *  - myls_sr_execute   (POST) apply replacements
 */

if ( ! defined('ABSPATH') ) exit;

/* =========================================================================
 * Shared helpers
 * ========================================================================= */

/**
 * Recursively replace strings inside an array/object structure (for JSON data).
 * Only touches string values — leaves keys, booleans, numbers untouched.
 */
function myls_sr_deep_replace( $data, $search, $replace, $case_sensitive = true ) {
	if ( is_array( $data ) ) {
		foreach ( $data as $key => &$value ) {
			$value = myls_sr_deep_replace( $value, $search, $replace, $case_sensitive );
		}
		unset( $value );
		return $data;
	}
	if ( is_string( $data ) ) {
		return $case_sensitive
			? str_replace( $search, $replace, $data )
			: str_ireplace( $search, $replace, $data );
	}
	return $data;
}

/**
 * Parse and validate the common request parameters.
 */
function myls_sr_parse_params() {
	if (
		empty( $_POST['nonce'] ) ||
		! wp_verify_nonce( $_POST['nonce'], 'myls_bulk_ops' ) ||
		! current_user_can( 'manage_options' )
	) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
	}

	$search  = isset( $_POST['search'] )  ? wp_unslash( $_POST['search'] )  : '';
	$replace = isset( $_POST['replace'] ) ? wp_unslash( $_POST['replace'] ) : '';

	if ( $search === '' ) {
		wp_send_json_error( [ 'message' => 'Search string cannot be empty.' ] );
	}

	$scope = [
		'post_content' => ! empty( $_POST['scope_post_content'] ),
		'post_title'   => ! empty( $_POST['scope_post_title'] ),
		'meta_value'   => ! empty( $_POST['scope_meta_value'] ),
		'options'      => ! empty( $_POST['scope_options'] ),
	];

	$case_sensitive = empty( $_POST['case_insensitive'] );

	return compact( 'search', 'replace', 'scope', 'case_sensitive' );
}

/* =========================================================================
 * PREVIEW — dry-run match counts
 * ========================================================================= */
add_action( 'wp_ajax_myls_sr_preview', function () {

	$p = myls_sr_parse_params();
	global $wpdb;

	$search_esc = '%' . $wpdb->esc_like( $p['search'] ) . '%';
	$counts     = [];

	// ── wp_posts.post_content ──
	if ( $p['scope']['post_content'] ) {
		$counts['post_content'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s",
				$search_esc
			)
		);
	}

	// ── wp_posts.post_title ──
	if ( $p['scope']['post_title'] ) {
		$counts['post_title'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE %s",
				$search_esc
			)
		);
	}

	// ── wp_postmeta.meta_value (non-Elementor) ──
	if ( $p['scope']['meta_value'] ) {
		$counts['meta_value'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta}
				  WHERE meta_value LIKE %s
				    AND meta_key != '_elementor_data'",
				$search_esc
			)
		);

		// Elementor JSON separately.
		$counts['elementor'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta}
				  WHERE meta_key = '_elementor_data'
				    AND meta_value LIKE %s",
				$search_esc
			)
		);
	}

	// ── wp_options.option_value ──
	if ( $p['scope']['options'] ) {
		$counts['options'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options}
				  WHERE option_value LIKE %s
				    AND option_name NOT LIKE %s
				    AND option_name NOT LIKE %s",
				$search_esc,
				'_transient%',
				'_site_transient%'
			)
		);
	}

	$total = array_sum( $counts );

	wp_send_json_success( array_merge( $counts, [ 'total' => $total ] ) );
} );

/* =========================================================================
 * EXECUTE — apply replacements
 * ========================================================================= */
add_action( 'wp_ajax_myls_sr_execute', function () {

	$p = myls_sr_parse_params();
	global $wpdb;

	$search_esc = '%' . $wpdb->esc_like( $p['search'] ) . '%';
	$log        = [];
	$affected   = [];

	// ── wp_posts.post_content ──
	if ( $p['scope']['post_content'] ) {
		if ( $p['case_sensitive'] ) {
			$rows = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts}
					    SET post_content = REPLACE(post_content, %s, %s)
					  WHERE post_content LIKE %s",
					$p['search'], $p['replace'], $search_esc
				)
			);
		} else {
			// Case-insensitive: PHP-level for accuracy.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE LOWER(post_content) LIKE LOWER(%s)",
					$search_esc
				)
			);
			$rows = 0;
			foreach ( $ids as $id ) {
				$content = get_post_field( 'post_content', $id );
				$new     = str_ireplace( $p['search'], $p['replace'], $content );
				if ( $new !== $content ) {
					$wpdb->update( $wpdb->posts, [ 'post_content' => $new ], [ 'ID' => $id ] );
					$rows++;
				}
			}
		}
		$affected['post_content'] = $rows;
		$log[] = "post_content: {$rows} row(s) updated";
	}

	// ── wp_posts.post_title ──
	if ( $p['scope']['post_title'] ) {
		if ( $p['case_sensitive'] ) {
			$rows = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts}
					    SET post_title = REPLACE(post_title, %s, %s)
					  WHERE post_title LIKE %s",
					$p['search'], $p['replace'], $search_esc
				)
			);
		} else {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE LOWER(post_title) LIKE LOWER(%s)",
					$search_esc
				)
			);
			$rows = 0;
			foreach ( $ids as $id ) {
				$title = get_post_field( 'post_title', $id );
				$new   = str_ireplace( $p['search'], $p['replace'], $title );
				if ( $new !== $title ) {
					$wpdb->update( $wpdb->posts, [ 'post_title' => $new ], [ 'ID' => $id ] );
					$rows++;
				}
			}
		}
		$affected['post_title'] = $rows;
		$log[] = "post_title: {$rows} row(s) updated";
	}

	// ── wp_postmeta (non-Elementor) ──
	if ( $p['scope']['meta_value'] ) {
		if ( $p['case_sensitive'] ) {
			$rows = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta}
					    SET meta_value = REPLACE(meta_value, %s, %s)
					  WHERE meta_value LIKE %s
					    AND meta_key != '_elementor_data'",
					$p['search'], $p['replace'], $search_esc
				)
			);
		} else {
			$metas = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, meta_value FROM {$wpdb->postmeta}
					  WHERE LOWER(meta_value) LIKE LOWER(%s)
					    AND meta_key != '_elementor_data'",
					$search_esc
				)
			);
			$rows = 0;
			foreach ( $metas as $m ) {
				$new = str_ireplace( $p['search'], $p['replace'], $m->meta_value );
				if ( $new !== $m->meta_value ) {
					$wpdb->update( $wpdb->postmeta, [ 'meta_value' => $new ], [ 'meta_id' => $m->meta_id ] );
					$rows++;
				}
			}
		}
		$affected['meta_value'] = $rows;
		$log[] = "postmeta (non-Elementor): {$rows} row(s) updated";

		// ── Elementor _elementor_data (PHP-level JSON handling) ──
		$elementor_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_id, pm.post_id, pm.meta_value
				   FROM {$wpdb->postmeta} pm
				  WHERE pm.meta_key = '_elementor_data'
				    AND pm.meta_value LIKE %s",
				$search_esc
			)
		);

		$el_count = 0;
		foreach ( $elementor_rows as $row ) {
			$data = json_decode( $row->meta_value, true );
			if ( ! is_array( $data ) ) {
				// Not valid JSON — fall back to plain string replace.
				$new_val = $p['case_sensitive']
					? str_replace( $p['search'], $p['replace'], $row->meta_value )
					: str_ireplace( $p['search'], $p['replace'], $row->meta_value );
				if ( $new_val !== $row->meta_value ) {
					$wpdb->update(
						$wpdb->postmeta,
						[ 'meta_value' => $new_val ],
						[ 'meta_id' => $row->meta_id ]
					);
					$el_count++;
				}
				continue;
			}

			// Recursive replace on decoded JSON.
			$new_data = myls_sr_deep_replace( $data, $p['search'], $p['replace'], $p['case_sensitive'] );
			$new_json = wp_json_encode( $new_data );

			if ( $new_json !== wp_json_encode( $data ) ) {
				// wp_slash required — WP's update_post_meta strips slashes.
				update_post_meta( (int) $row->post_id, '_elementor_data', wp_slash( $new_json ) );

				// Clear Elementor caches so changes render.
				delete_post_meta( (int) $row->post_id, '_elementor_css' );
				delete_post_meta( (int) $row->post_id, '_elementor_element_cache' );
				delete_post_meta( (int) $row->post_id, '_elementor_page_assets' );

				$title = get_the_title( (int) $row->post_id ) ?: ( 'Post #' . $row->post_id );
				$log[] = "Elementor: updated post #{$row->post_id} ({$title})";
				$el_count++;
			}
		}

		$affected['elementor'] = $el_count;
		$log[] = "Elementor data: {$el_count} post(s) updated";
	}

	// ── wp_options ──
	if ( $p['scope']['options'] ) {
		if ( $p['case_sensitive'] ) {
			$rows = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options}
					    SET option_value = REPLACE(option_value, %s, %s)
					  WHERE option_value LIKE %s
					    AND option_name NOT LIKE %s
					    AND option_name NOT LIKE %s",
					$p['search'], $p['replace'], $search_esc,
					'_transient%', '_site_transient%'
				)
			);
		} else {
			$opts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_id, option_value FROM {$wpdb->options}
					  WHERE LOWER(option_value) LIKE LOWER(%s)
					    AND option_name NOT LIKE %s
					    AND option_name NOT LIKE %s",
					$search_esc, '_transient%', '_site_transient%'
				)
			);
			$rows = 0;
			foreach ( $opts as $o ) {
				$new = str_ireplace( $p['search'], $p['replace'], $o->option_value );
				if ( $new !== $o->option_value ) {
					$wpdb->update( $wpdb->options, [ 'option_value' => $new ], [ 'option_id' => $o->option_id ] );
					$rows++;
				}
			}
		}
		$affected['options'] = $rows;
		$log[] = "options: {$rows} row(s) updated";
	}

	// Clean WP object cache.
	wp_cache_flush();

	$total = array_sum( $affected );
	$log[] = "---";
	$log[] = "Done. {$total} total replacement(s) applied.";

	wp_send_json_success( [
		'affected' => $affected,
		'total'    => $total,
		'log'      => $log,
	] );
} );
