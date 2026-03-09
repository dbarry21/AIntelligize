<?php
/**
 * Video Transcripts – Database Table + CRUD
 * Path: inc/db/video-transcripts-table.php
 *
 * Custom table: {prefix}myls_video_transcripts
 * Stores YouTube video transcripts fetched via Supadata / page-scrape / timedtext.
 *
 * @since 7.8.86
 */

if ( ! defined('ABSPATH') ) exit;

define( 'MYLS_VT_DB_VERSION', '1.0' );

/**
 * Get the table name with prefix.
 */
function myls_vt_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'myls_video_transcripts';
}

/**
 * Create or update the table. Called on admin_init if version mismatch.
 */
function myls_vt_maybe_create_table() {
	$installed = get_option('myls_vt_db_version', '');
	if ( $installed === MYLS_VT_DB_VERSION ) return;

	global $wpdb;
	$charset = $wpdb->get_charset_collate();
	$table   = myls_vt_table_name();

	$sql = "CREATE TABLE {$table} (
		video_id varchar(20) NOT NULL,
		title text DEFAULT NULL,
		transcript longtext DEFAULT NULL,
		lang varchar(10) NOT NULL DEFAULT '',
		status varchar(10) NOT NULL DEFAULT 'pending',
		fetched_at datetime DEFAULT NULL,
		source varchar(20) NOT NULL DEFAULT '',
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (video_id),
		KEY status (status)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	update_option('myls_vt_db_version', MYLS_VT_DB_VERSION);
}
add_action('admin_init', 'myls_vt_maybe_create_table');

/* ═══════════════════════════════════════════════════════
 *  CRUD Helpers
 * ═══════════════════════════════════════════════════════ */

/**
 * Get a single row by video_id.
 */
function myls_vt_get_by_id( string $video_id ) {
	global $wpdb;
	$table = myls_vt_table_name();
	return $wpdb->get_row(
		$wpdb->prepare("SELECT * FROM {$table} WHERE video_id = %s", $video_id),
		ARRAY_A
	);
}

/**
 * Get all rows, ordered by created_at DESC.
 */
function myls_vt_get_all() {
	global $wpdb;
	$table = myls_vt_table_name();
	return $wpdb->get_results(
		"SELECT * FROM {$table} ORDER BY created_at DESC",
		ARRAY_A
	);
}

/**
 * Get rows where transcript is missing (status = pending).
 */
function myls_vt_get_missing( int $limit = 5 ) {
	global $wpdb;
	$table = myls_vt_table_name();
	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE status = 'pending' ORDER BY created_at ASC LIMIT %d",
			$limit
		),
		ARRAY_A
	);
}

/**
 * Get summary stats.
 */
function myls_vt_get_stats() {
	global $wpdb;
	$table = myls_vt_table_name();
	return $wpdb->get_row(
		"SELECT
			COUNT(*) as total,
			SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) as ok,
			SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
			SUM(CASE WHEN status = 'none' THEN 1 ELSE 0 END) as none,
			SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error
		FROM {$table}",
		ARRAY_A
	);
}

/**
 * Upsert a video transcript row.
 */
function myls_vt_upsert( array $data ) {
	global $wpdb;
	$table = myls_vt_table_name();

	$existing = myls_vt_get_by_id( $data['video_id'] );

	if ( $existing ) {
		$update = [];
		if ( isset($data['title']) )      $update['title']      = $data['title'];
		if ( isset($data['transcript']) ) $update['transcript'] = $data['transcript'];
		if ( isset($data['lang']) )       $update['lang']       = $data['lang'];
		if ( isset($data['status']) )     $update['status']     = $data['status'];
		if ( isset($data['fetched_at']) ) $update['fetched_at'] = $data['fetched_at'];
		if ( isset($data['source']) )     $update['source']     = $data['source'];

		if ( ! empty($update) ) {
			$wpdb->update($table, $update, ['video_id' => $data['video_id']]);
		}
		return $data['video_id'];
	}

	$wpdb->insert($table, [
		'video_id'   => $data['video_id'],
		'title'      => $data['title'] ?? '',
		'transcript' => $data['transcript'] ?? null,
		'lang'       => $data['lang'] ?? '',
		'status'     => $data['status'] ?? 'pending',
		'fetched_at' => $data['fetched_at'] ?? null,
		'source'     => $data['source'] ?? '',
		'created_at' => current_time('mysql'),
	]);
	return $data['video_id'];
}

/**
 * Delete a single row by video_id.
 */
function myls_vt_delete( string $video_id ) {
	global $wpdb;
	return $wpdb->delete(myls_vt_table_name(), ['video_id' => $video_id]);
}

/**
 * Bulk insert videos (INSERT IGNORE for idempotent sync).
 *
 * @param array $videos Array of ['video_id'=>..., 'title'=>...]
 * @return int Number of rows inserted.
 */
function myls_vt_bulk_insert( array $videos ) {
	if ( empty($videos) ) return 0;
	global $wpdb;
	$table = myls_vt_table_name();
	$now   = current_time('mysql');

	$values  = [];
	$holders = [];
	foreach ( $videos as $v ) {
		$vid   = sanitize_text_field( $v['video_id'] ?? '' );
		$title = sanitize_text_field( $v['title'] ?? '' );
		if ( $vid === '' ) continue;
		$holders[] = '(%s, %s, %s, %s)';
		$values[]  = $vid;
		$values[]  = $title;
		$values[]  = 'pending';
		$values[]  = $now;
	}

	if ( empty($holders) ) return 0;

	$sql = "INSERT IGNORE INTO {$table} (video_id, title, status, created_at) VALUES "
		 . implode(', ', $holders);

	$wpdb->query( $wpdb->prepare($sql, $values) );
	return $wpdb->rows_affected;
}
