<?php
/**
 * AJAX Handlers: Search & Replace (Bulk subtab)
 * File: admin/tabs/bulk/_search-replace-ajax.php
 *
 * Endpoints:
 *  - myls_sr_preview           (POST) dry-run: count matches per table
 *  - myls_sr_execute           (POST) apply replacements + capture undo snapshot
 *  - myls_sr_list_snapshots    (POST) list last 5 snapshots for current user
 *  - myls_sr_undo              (POST) restore a snapshot
 *  - myls_sr_delete_snapshot   (POST) delete a snapshot row
 */

if ( ! defined('ABSPATH') ) exit;

/* =========================================================================
 * Shared helpers
 * ========================================================================= */

/** Max size of a single snapshot payload (JSON), in bytes. */
if ( ! defined('MYLS_SR_SNAPSHOT_MAX_BYTES') ) {
	define( 'MYLS_SR_SNAPSHOT_MAX_BYTES', 32 * 1024 * 1024 ); // 32 MB
}

/** Number of snapshots to keep per user. */
if ( ! defined('MYLS_SR_SNAPSHOT_KEEP') ) {
	define( 'MYLS_SR_SNAPSHOT_KEEP', 5 );
}

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
 * Return the qualified snapshots table name.
 */
function myls_sr_snapshots_table() {
	global $wpdb;
	return $wpdb->prefix . 'myls_sr_snapshots';
}

/**
 * Ensure the snapshots table exists. Idempotent; cheap to call.
 */
function myls_sr_ensure_snapshots_table() {
	global $wpdb;
	$table = myls_sr_snapshots_table();

	$cached = wp_cache_get( 'myls_sr_table_exists', 'myls_sr' );
	if ( $cached === 'yes' ) {
		return;
	}

	$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists ) {
		wp_cache_set( 'myls_sr_table_exists', 'yes', 'myls_sr', HOUR_IN_SECONDS );
		return;
	}

	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		created_at      DATETIME NOT NULL,
		user_id         BIGINT(20) UNSIGNED NOT NULL,
		search_term     TEXT NOT NULL,
		replace_term    TEXT NOT NULL,
		case_sensitive  TINYINT(1) NOT NULL DEFAULT 1,
		scope           VARCHAR(255) NOT NULL,
		total_rows      INT(11) NOT NULL DEFAULT 0,
		payload         LONGTEXT NOT NULL,
		undone_at       DATETIME NULL,
		PRIMARY KEY  (id),
		KEY created_at (created_at),
		KEY user_id (user_id)
	) {$charset_collate};";

	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	dbDelta( $sql );

	wp_cache_set( 'myls_sr_table_exists', 'yes', 'myls_sr', HOUR_IN_SECONDS );
}

/**
 * Build a reusable SQL WHERE fragment that restricts wp_posts queries to
 * safe post types and statuses. Always excludes revisions, nav_menu_items,
 * and attachments. Reads post_types / post_statuses from $_POST.
 *
 * @param string $alias Optional table alias prefix (e.g. 'p') for JOINed queries.
 * @return string SQL fragment starting with ' AND …'.
 */
function myls_sr_post_where_clause( $alias = '' ) {
	global $wpdb;

	$pfx = $alias !== '' ? $alias . '.' : '';

	// ── Post types ──
	$always_excluded = array( 'revision', 'nav_menu_item', 'attachment' );
	$raw_types       = isset( $_POST['post_types'] ) ? sanitize_text_field( wp_unslash( $_POST['post_types'] ) ) : '';

	if ( $raw_types !== '' ) {
		$types = array_filter( array_map( 'sanitize_key', explode( ',', $raw_types ) ) );
		$types = array_values( array_diff( $types, $always_excluded ) );
		if ( empty( $types ) ) {
			$types = array( 'post', 'page' );
		}
		$ph         = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$type_sql   = $wpdb->prepare( "{$pfx}post_type IN ({$ph})", $types ); // phpcs:ignore WordPress.DB.PreparedSQL
	} else {
		$ph       = implode( ',', array_fill( 0, count( $always_excluded ), '%s' ) );
		$type_sql = $wpdb->prepare( "{$pfx}post_type NOT IN ({$ph})", $always_excluded ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	// ── Post statuses ──
	$allowed_statuses = array( 'publish', 'draft', 'pending', 'future', 'private' );
	$raw_statuses     = isset( $_POST['post_statuses'] ) ? sanitize_text_field( wp_unslash( $_POST['post_statuses'] ) ) : '';
	$statuses         = array();

	if ( $raw_statuses !== '' ) {
		$statuses = array_values( array_intersect(
			array_filter( array_map( 'sanitize_key', explode( ',', $raw_statuses ) ) ),
			$allowed_statuses
		) );
	}
	if ( empty( $statuses ) ) {
		$statuses = $allowed_statuses;
	}

	$sph        = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$status_sql = $wpdb->prepare( "{$pfx}post_status IN ({$sph})", $statuses ); // phpcs:ignore WordPress.DB.PreparedSQL

	return " AND {$type_sql} AND {$status_sql}";
}

/**
 * Return per-post-type match counts for a given wp_posts column + LIKE term.
 *
 * @param string $column     Column name (post_content, post_title, post_excerpt).
 * @param string $search_esc Already-escaped LIKE value (with % wildcards).
 * @return array  Associative array: post_type slug => int count.
 */
function myls_sr_post_breakdown( $column, $search_esc ) {
	global $wpdb;
	$where_extra = myls_sr_post_where_clause();

	// phpcs:ignore WordPress.DB.PreparedSQL
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT post_type, COUNT(*) AS cnt FROM {$wpdb->posts}
			 WHERE {$column} LIKE %s {$where_extra}
			 GROUP BY post_type",
			$search_esc
		)
	);

	$out = array();
	foreach ( (array) $rows as $r ) {
		$out[ $r->post_type ] = (int) $r->cnt;
	}
	return $out;
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
		'post_excerpt' => ! empty( $_POST['scope_post_excerpt'] ),
		'meta_value'   => ! empty( $_POST['scope_meta_value'] ),
		'options'      => ! empty( $_POST['scope_options'] ),
	];

	$case_sensitive = empty( $_POST['case_insensitive'] );

	return compact( 'search', 'replace', 'scope', 'case_sensitive' );
}

/**
 * Shared auth guard for the secondary snapshot endpoints.
 */
function myls_sr_snapshot_guard() {
	if (
		empty( $_POST['nonce'] ) ||
		! wp_verify_nonce( $_POST['nonce'], 'myls_bulk_ops' ) ||
		! current_user_can( 'manage_options' )
	) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
	}
	myls_sr_ensure_snapshots_table();
}

/* =========================================================================
 * PREVIEW — dry-run match counts
 * ========================================================================= */
add_action( 'wp_ajax_myls_sr_preview', function () {

	$p = myls_sr_parse_params();
	global $wpdb;

	$search_esc  = '%' . $wpdb->esc_like( $p['search'] ) . '%';
	$counts      = [];
	$where_posts = myls_sr_post_where_clause();
	$where_meta  = myls_sr_post_where_clause( 'p' );

	// ── wp_posts.post_content ──
	if ( $p['scope']['post_content'] ) {
		$counts['post_content'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s {$where_posts}", // phpcs:ignore WordPress.DB.PreparedSQL
				$search_esc
			)
		);
		$counts['post_content_breakdown'] = myls_sr_post_breakdown( 'post_content', $search_esc );
	}

	// ── wp_posts.post_title ──
	if ( $p['scope']['post_title'] ) {
		$counts['post_title'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE %s {$where_posts}", // phpcs:ignore WordPress.DB.PreparedSQL
				$search_esc
			)
		);
		$counts['post_title_breakdown'] = myls_sr_post_breakdown( 'post_title', $search_esc );
	}

	// ── wp_posts.post_excerpt ──
	if ( $p['scope']['post_excerpt'] ) {
		$counts['post_excerpt'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_excerpt LIKE %s {$where_posts}", // phpcs:ignore WordPress.DB.PreparedSQL
				$search_esc
			)
		);
		$counts['post_excerpt_breakdown'] = myls_sr_post_breakdown( 'post_excerpt', $search_esc );
	}

	// ── wp_postmeta.meta_value (non-Elementor) ──
	if ( $p['scope']['meta_value'] ) {
		$counts['meta_value'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
				  INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				  WHERE pm.meta_value LIKE %s
				    AND pm.meta_key != '_elementor_data'
				    {$where_meta}", // phpcs:ignore WordPress.DB.PreparedSQL
				$search_esc
			)
		);

		// Elementor JSON separately.
		$counts['elementor'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
				  INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				  WHERE pm.meta_key = '_elementor_data'
				    AND pm.meta_value LIKE %s
				    {$where_meta}", // phpcs:ignore WordPress.DB.PreparedSQL
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

	// Total counts only (exclude breakdown arrays from sum).
	$total = 0;
	foreach ( $counts as $k => $v ) {
		if ( is_int( $v ) ) {
			$total += $v;
		}
	}

	wp_send_json_success( array_merge( $counts, [ 'total' => $total ] ) );
} );

/* =========================================================================
 * EXECUTE — apply replacements with snapshot capture
 * ========================================================================= */
add_action( 'wp_ajax_myls_sr_execute', function () {

	$p = myls_sr_parse_params();
	myls_sr_ensure_snapshots_table();
	global $wpdb;

	$search_esc  = '%' . $wpdb->esc_like( $p['search'] ) . '%';
	$where_posts = myls_sr_post_where_clause();
	$where_meta  = myls_sr_post_where_clause( 'p' );
	$log         = [];
	$affected    = [];
	$snapshot    = [];

	// ── wp_posts.post_content ──
	if ( $p['scope']['post_content'] ) {
		// Capture originals for rows that would actually change.
		$orig_rows = $wpdb->get_results(
			$wpdb->prepare(
				$p['case_sensitive']
					? "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s {$where_posts}" // phpcs:ignore WordPress.DB.PreparedSQL
					: "SELECT ID, post_content FROM {$wpdb->posts} WHERE LOWER(post_content) LIKE LOWER(%s) {$where_posts}", // phpcs:ignore WordPress.DB.PreparedSQL
				$search_esc
			)
		);

		$rows = 0;
		foreach ( $orig_rows as $r ) {
			$old = (string) $r->post_content;
			$new = $p['case_sensitive']
				? str_replace( $p['search'], $p['replace'], $old )
				: str_ireplace( $p['search'], $p['replace'], $old );
			if ( $new === $old ) continue;

			$snapshot[] = [
				'type'  => 'post',
				'id'    => (int) $r->ID,
				'field' => 'post_content',
				'before'=> $old,
			];

			$wpdb->update( $wpdb->posts, [ 'post_content' => $new ], [ 'ID' => (int) $r->ID ] );
			$rows++;
		}

		$affected['post_content'] = $rows;
		$log[] = "post_content: {$rows} row(s) updated";
	}

	// ── wp_posts.post_title ──
	if ( $p['scope']['post_title'] ) {
		$orig_rows = $wpdb->get_results(
			$wpdb->prepare(
				$p['case_sensitive']
					? "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_title LIKE %s {$where_posts}" // phpcs:ignore WordPress.DB.PreparedSQL
					: "SELECT ID, post_title FROM {$wpdb->posts} WHERE LOWER(post_title) LIKE LOWER(%s) {$where_posts}", // phpcs:ignore WordPress.DB.PreparedSQL
				$search_esc
			)
		);

		$rows = 0;
		foreach ( $orig_rows as $r ) {
			$old = (string) $r->post_title;
			$new = $p['case_sensitive']
				? str_replace( $p['search'], $p['replace'], $old )
				: str_ireplace( $p['search'], $p['replace'], $old );
			if ( $new === $old ) continue;

			$snapshot[] = [
				'type'  => 'post',
				'id'    => (int) $r->ID,
				'field' => 'post_title',
				'before'=> $old,
			];

			$wpdb->update( $wpdb->posts, [ 'post_title' => $new ], [ 'ID' => (int) $r->ID ] );
			$rows++;
		}

		$affected['post_title'] = $rows;
		$log[] = "post_title: {$rows} row(s) updated";
	}

	// ── wp_posts.post_excerpt ──
	if ( $p['scope']['post_excerpt'] ) {
		$orig_rows = $wpdb->get_results(
			$wpdb->prepare(
				$p['case_sensitive']
					? "SELECT ID, post_excerpt FROM {$wpdb->posts} WHERE post_excerpt LIKE %s {$where_posts}" // phpcs:ignore WordPress.DB.PreparedSQL
					: "SELECT ID, post_excerpt FROM {$wpdb->posts} WHERE LOWER(post_excerpt) LIKE LOWER(%s) {$where_posts}", // phpcs:ignore WordPress.DB.PreparedSQL
				$search_esc
			)
		);

		$rows = 0;
		foreach ( $orig_rows as $r ) {
			$old = (string) $r->post_excerpt;
			$new = $p['case_sensitive']
				? str_replace( $p['search'], $p['replace'], $old )
				: str_ireplace( $p['search'], $p['replace'], $old );
			if ( $new === $old ) continue;

			$snapshot[] = [
				'type'  => 'post',
				'id'    => (int) $r->ID,
				'field' => 'post_excerpt',
				'before'=> $old,
			];

			$wpdb->update( $wpdb->posts, [ 'post_excerpt' => $new ], [ 'ID' => (int) $r->ID ] );
			$rows++;
		}

		$affected['post_excerpt'] = $rows;
		$log[] = "post_excerpt: {$rows} row(s) updated";
	}

	// ── wp_postmeta (non-Elementor) ──
	if ( $p['scope']['meta_value'] ) {
		$orig_meta = $wpdb->get_results(
			$wpdb->prepare(
				$p['case_sensitive']
					? "SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value
					     FROM {$wpdb->postmeta} pm
					     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					     WHERE pm.meta_value LIKE %s
					       AND pm.meta_key != '_elementor_data'
					       {$where_meta}" // phpcs:ignore WordPress.DB.PreparedSQL
					: "SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value
					     FROM {$wpdb->postmeta} pm
					     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					     WHERE LOWER(pm.meta_value) LIKE LOWER(%s)
					       AND pm.meta_key != '_elementor_data'
					       {$where_meta}", // phpcs:ignore WordPress.DB.PreparedSQL
				$search_esc
			)
		);

		$rows = 0;
		foreach ( $orig_meta as $m ) {
			$old = (string) $m->meta_value;
			$new = $p['case_sensitive']
				? str_replace( $p['search'], $p['replace'], $old )
				: str_ireplace( $p['search'], $p['replace'], $old );
			if ( $new === $old ) continue;

			$snapshot[] = [
				'type'     => 'postmeta',
				'meta_id'  => (int) $m->meta_id,
				'post_id'  => (int) $m->post_id,
				'meta_key' => (string) $m->meta_key,
				'before'   => $old,
			];

			$wpdb->update( $wpdb->postmeta, [ 'meta_value' => $new ], [ 'meta_id' => (int) $m->meta_id ] );
			$rows++;
		}

		$affected['meta_value'] = $rows;
		$log[] = "postmeta (non-Elementor): {$rows} row(s) updated";

		// ── Elementor _elementor_data (PHP-level JSON handling) ──
		$elementor_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_id, pm.post_id, pm.meta_value
				   FROM {$wpdb->postmeta} pm
				   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				  WHERE pm.meta_key = '_elementor_data'
				    AND pm.meta_value LIKE %s
				    {$where_meta}", // phpcs:ignore WordPress.DB.PreparedSQL
				$search_esc
			)
		);

		$el_count = 0;
		foreach ( $elementor_rows as $row ) {
			$original_raw = (string) $row->meta_value;
			$data         = json_decode( $original_raw, true );

			if ( ! is_array( $data ) ) {
				// Not valid JSON — fall back to plain string replace.
				$new_val = $p['case_sensitive']
					? str_replace( $p['search'], $p['replace'], $original_raw )
					: str_ireplace( $p['search'], $p['replace'], $original_raw );

				if ( $new_val === $original_raw ) continue;

				$snapshot[] = [
					'type'    => 'elementor_raw',
					'meta_id' => (int) $row->meta_id,
					'post_id' => (int) $row->post_id,
					'before'  => $original_raw,
				];

				$wpdb->update(
					$wpdb->postmeta,
					[ 'meta_value' => $new_val ],
					[ 'meta_id' => (int) $row->meta_id ]
				);
				$el_count++;
				continue;
			}

			// Recursive replace on decoded JSON.
			$new_data = myls_sr_deep_replace( $data, $p['search'], $p['replace'], $p['case_sensitive'] );
			$new_json = wp_json_encode( $new_data );

			if ( $new_json === wp_json_encode( $data ) ) continue;

			// Snapshot: store the raw (pre-decoded) string so restore is byte-identical.
			$snapshot[] = [
				'type'    => 'elementor',
				'post_id' => (int) $row->post_id,
				'before'  => $original_raw,
			];

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

		$affected['elementor'] = $el_count;
		$log[] = "Elementor data: {$el_count} post(s) updated";
	}

	// ── wp_options ──
	if ( $p['scope']['options'] ) {
		$orig_opts = $wpdb->get_results(
			$wpdb->prepare(
				$p['case_sensitive']
					? "SELECT option_id, option_name, option_value FROM {$wpdb->options}
					      WHERE option_value LIKE %s
					        AND option_name NOT LIKE %s
					        AND option_name NOT LIKE %s"
					: "SELECT option_id, option_name, option_value FROM {$wpdb->options}
					      WHERE LOWER(option_value) LIKE LOWER(%s)
					        AND option_name NOT LIKE %s
					        AND option_name NOT LIKE %s",
				$search_esc, '_transient%', '_site_transient%'
			)
		);

		$rows = 0;
		foreach ( $orig_opts as $o ) {
			$old = (string) $o->option_value;
			$new = $p['case_sensitive']
				? str_replace( $p['search'], $p['replace'], $old )
				: str_ireplace( $p['search'], $p['replace'], $old );
			if ( $new === $old ) continue;

			$snapshot[] = [
				'type'        => 'option',
				'option_id'   => (int) $o->option_id,
				'option_name' => (string) $o->option_name,
				'before'      => $old,
			];

			$wpdb->update( $wpdb->options, [ 'option_value' => $new ], [ 'option_id' => (int) $o->option_id ] );
			$rows++;
		}

		$affected['options'] = $rows;
		$log[] = "options: {$rows} row(s) updated";
	}

	// ── Persist snapshot ──
	$total        = array_sum( $affected );
	$snapshot_id  = 0;
	$payload_json = '';

	if ( ! empty( $snapshot ) ) {
		$payload_json = wp_json_encode( $snapshot );
		if ( $payload_json === false ) {
			$log[] = "WARNING: snapshot JSON encode failed — undo unavailable for this run.";
		} elseif ( strlen( $payload_json ) > MYLS_SR_SNAPSHOT_MAX_BYTES ) {
			$log[] = sprintf(
				"WARNING: snapshot exceeds %d MB limit — undo unavailable for this run.",
				(int) ( MYLS_SR_SNAPSHOT_MAX_BYTES / ( 1024 * 1024 ) )
			);
		} else {
			$scope_list = [];
			if ( $p['scope']['post_content'] ) $scope_list[] = 'content';
			if ( $p['scope']['post_title'] )   $scope_list[] = 'title';
			if ( $p['scope']['meta_value'] )   $scope_list[] = 'meta';
			if ( $p['scope']['options'] )      $scope_list[] = 'options';

			$wpdb->insert(
				myls_sr_snapshots_table(),
				[
					'created_at'     => current_time( 'mysql' ),
					'user_id'        => get_current_user_id(),
					'search_term'    => $p['search'],
					'replace_term'   => $p['replace'],
					'case_sensitive' => $p['case_sensitive'] ? 1 : 0,
					'scope'          => implode( ',', $scope_list ),
					'total_rows'     => $total,
					'payload'        => $payload_json,
					'undone_at'      => null,
				],
				[ '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s' ]
			);
			$snapshot_id = (int) $wpdb->insert_id;

			// Auto-prune: keep only the last N snapshots per user.
			$user_id = (int) get_current_user_id();
			$keep    = (int) MYLS_SR_SNAPSHOT_KEEP;
			$table   = myls_sr_snapshots_table();
			$keep_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d",
					$user_id, $keep
				)
			);
			if ( ! empty( $keep_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $keep_ids ), '%d' ) );
				$args = array_merge( [ $user_id ], array_map( 'intval', $keep_ids ) );
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$table} WHERE user_id = %d AND id NOT IN ( {$placeholders} )",
						$args
					)
				);
			}

			$log[] = "Snapshot #{$snapshot_id} saved (" . count( $snapshot ) . " entries).";
		}
	}

	// Clean WP object cache.
	wp_cache_flush();

	$log[] = "---";
	$log[] = "Done. {$total} total replacement(s) applied.";

	wp_send_json_success( [
		'affected'    => $affected,
		'total'       => $total,
		'log'         => $log,
		'snapshot_id' => $snapshot_id,
	] );
} );

/* =========================================================================
 * LIST SNAPSHOTS — return the most recent undo entries for this user
 * ========================================================================= */
add_action( 'wp_ajax_myls_sr_list_snapshots', function () {
	myls_sr_snapshot_guard();
	global $wpdb;

	$table   = myls_sr_snapshots_table();
	$user_id = (int) get_current_user_id();
	$keep    = (int) MYLS_SR_SNAPSHOT_KEEP;

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, created_at, search_term, replace_term, case_sensitive, scope, total_rows, undone_at
			   FROM {$table}
			  WHERE user_id = %d
			  ORDER BY created_at DESC, id DESC
			  LIMIT %d",
			$user_id, $keep
		)
	);

	$out = [];
	if ( is_array( $rows ) ) {
		foreach ( $rows as $r ) {
			$out[] = [
				'id'             => (int) $r->id,
				'created_at'     => (string) $r->created_at,
				'search'         => (string) $r->search_term,
				'replace'        => (string) $r->replace_term,
				'case_sensitive' => (bool) $r->case_sensitive,
				'scope'          => (string) $r->scope,
				'total_rows'     => (int) $r->total_rows,
				'undone'         => ! empty( $r->undone_at ),
				'undone_at'      => $r->undone_at ? (string) $r->undone_at : null,
			];
		}
	}

	wp_send_json_success( [ 'snapshots' => $out ] );
} );

/* =========================================================================
 * UNDO — restore a snapshot
 * ========================================================================= */
add_action( 'wp_ajax_myls_sr_undo', function () {
	myls_sr_snapshot_guard();
	global $wpdb;

	$table       = myls_sr_snapshots_table();
	$snapshot_id = isset( $_POST['snapshot_id'] ) ? absint( $_POST['snapshot_id'] ) : 0;
	if ( $snapshot_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Invalid snapshot id.' ] );
	}

	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $snapshot_id )
	);
	if ( ! $row ) {
		wp_send_json_error( [ 'message' => 'Snapshot not found.' ] );
	}
	if ( ! empty( $row->undone_at ) ) {
		wp_send_json_error( [ 'message' => 'This snapshot has already been undone.' ] );
	}

	$payload = json_decode( (string) $row->payload, true );
	if ( ! is_array( $payload ) ) {
		wp_send_json_error( [ 'message' => 'Snapshot payload is corrupt.' ] );
	}

	$log      = [];
	$restored = 0;

	// Restore in reverse order.
	for ( $i = count( $payload ) - 1; $i >= 0; $i-- ) {
		$entry = $payload[ $i ];
		if ( ! is_array( $entry ) || empty( $entry['type'] ) ) continue;

		$type = (string) $entry['type'];

		if ( $type === 'post' ) {
			$id    = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
			$field = isset( $entry['field'] ) ? (string) $entry['field'] : '';
			if ( $id <= 0 || ! in_array( $field, [ 'post_content', 'post_title', 'post_excerpt' ], true ) ) continue;

			$wpdb->update(
				$wpdb->posts,
				[ $field => (string) $entry['before'] ],
				[ 'ID' => $id ]
			);
			clean_post_cache( $id );
			$log[] = "Restored {$field} on post #{$id}";
			$restored++;
			continue;
		}

		if ( $type === 'postmeta' ) {
			$meta_id = isset( $entry['meta_id'] ) ? (int) $entry['meta_id'] : 0;
			if ( $meta_id <= 0 ) continue;

			$wpdb->update(
				$wpdb->postmeta,
				[ 'meta_value' => (string) $entry['before'] ],
				[ 'meta_id' => $meta_id ]
			);
			if ( ! empty( $entry['post_id'] ) ) clean_post_cache( (int) $entry['post_id'] );
			$log[] = "Restored postmeta #{$meta_id}";
			$restored++;
			continue;
		}

		if ( $type === 'elementor' ) {
			$post_id = isset( $entry['post_id'] ) ? (int) $entry['post_id'] : 0;
			if ( $post_id <= 0 ) continue;

			update_post_meta( $post_id, '_elementor_data', wp_slash( (string) $entry['before'] ) );
			delete_post_meta( $post_id, '_elementor_css' );
			delete_post_meta( $post_id, '_elementor_element_cache' );
			delete_post_meta( $post_id, '_elementor_page_assets' );
			clean_post_cache( $post_id );

			$log[] = "Restored Elementor data on post #{$post_id}";
			$restored++;
			continue;
		}

		if ( $type === 'elementor_raw' ) {
			$meta_id = isset( $entry['meta_id'] ) ? (int) $entry['meta_id'] : 0;
			if ( $meta_id <= 0 ) continue;

			$wpdb->update(
				$wpdb->postmeta,
				[ 'meta_value' => (string) $entry['before'] ],
				[ 'meta_id' => $meta_id ]
			);
			if ( ! empty( $entry['post_id'] ) ) {
				$pid = (int) $entry['post_id'];
				delete_post_meta( $pid, '_elementor_css' );
				delete_post_meta( $pid, '_elementor_element_cache' );
				delete_post_meta( $pid, '_elementor_page_assets' );
				clean_post_cache( $pid );
			}
			$log[] = "Restored Elementor (raw) meta #{$meta_id}";
			$restored++;
			continue;
		}

		if ( $type === 'option' ) {
			$name = isset( $entry['option_name'] ) ? (string) $entry['option_name'] : '';
			if ( $name === '' ) continue;

			// update_option handles autoload + serialization correctly.
			update_option( $name, (string) $entry['before'] );
			$log[] = "Restored option {$name}";
			$restored++;
			continue;
		}
	}

	$wpdb->update(
		$table,
		[ 'undone_at' => current_time( 'mysql' ) ],
		[ 'id' => $snapshot_id ],
		[ '%s' ],
		[ '%d' ]
	);

	wp_cache_flush();

	$log[] = "---";
	$log[] = "Undo complete. {$restored} entries restored.";

	wp_send_json_success( [
		'restored' => $restored,
		'log'      => $log,
	] );
} );

/* =========================================================================
 * DELETE SNAPSHOT
 * ========================================================================= */
add_action( 'wp_ajax_myls_sr_delete_snapshot', function () {
	myls_sr_snapshot_guard();
	global $wpdb;

	$snapshot_id = isset( $_POST['snapshot_id'] ) ? absint( $_POST['snapshot_id'] ) : 0;
	if ( $snapshot_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Invalid snapshot id.' ] );
	}

	$deleted = $wpdb->delete(
		myls_sr_snapshots_table(),
		[ 'id' => $snapshot_id ],
		[ '%d' ]
	);

	if ( $deleted === false ) {
		wp_send_json_error( [ 'message' => 'Delete failed.' ] );
	}

	wp_send_json_success( [ 'deleted' => (int) $deleted ] );
} );
