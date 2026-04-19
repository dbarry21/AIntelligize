<?php
/**
 * AI Visibility — Crawler Hits Table + Helpers
 * Path: inc/db/ai-visibility-table.php
 *
 * Custom table: {prefix}myls_ai_crawler_hits
 * Stores per-day, per-bot, per-path counters for AI-crawler visits.
 * Aggregated (not per-request) to keep the table small.
 *
 * @since 7.9.18.107
 */

if ( ! defined('ABSPATH') ) exit;

define( 'MYLS_AIV_DB_VERSION', '1.0' );

function myls_aiv_table_name() : string {
	global $wpdb;
	return $wpdb->prefix . 'myls_ai_crawler_hits';
}

/**
 * Create/update the table on admin_init when version changes.
 */
function myls_aiv_maybe_create_table() : void {
	$installed = get_option('myls_aiv_db_version', '');
	if ( $installed === MYLS_AIV_DB_VERSION ) return;

	global $wpdb;
	$charset = $wpdb->get_charset_collate();
	$table   = myls_aiv_table_name();

	$sql = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		hit_date date NOT NULL,
		bot_name varchar(64) NOT NULL DEFAULT '',
		url_path varchar(191) NOT NULL DEFAULT '',
		hits int unsigned NOT NULL DEFAULT 0,
		first_seen datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		last_seen datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY day_bot_path (hit_date, bot_name, url_path),
		KEY day (hit_date),
		KEY bot_day (bot_name, hit_date)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	update_option('myls_aiv_db_version', MYLS_AIV_DB_VERSION);
}
add_action('admin_init', 'myls_aiv_maybe_create_table');

/* ═══════════════════════════════════════════════════════
 *  Write path
 * ═══════════════════════════════════════════════════════ */

/**
 * Increment the per-day counter for a bot + url_path. Creates the row on first
 * hit, bumps `hits` and `last_seen` thereafter.
 */
function myls_aiv_upsert_hit( string $bot_name, string $url_path ) : void {
	global $wpdb;
	$table = myls_aiv_table_name();
	$now   = current_time('mysql');
	$today = current_time('Y-m-d');

	$sql = $wpdb->prepare(
		"INSERT INTO {$table} (hit_date, bot_name, url_path, hits, first_seen, last_seen)
		 VALUES (%s, %s, %s, 1, %s, %s)
		 ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen)",
		$today, $bot_name, $url_path, $now, $now
	);

	$wpdb->query( $sql );
}

/* ═══════════════════════════════════════════════════════
 *  Read path (for the admin UI)
 * ═══════════════════════════════════════════════════════ */

/**
 * Get aggregate stats for the last N days.
 *
 * @return array {
 *   total:      int,
 *   by_day:     array<int, array{day:string, bot_name:string, hits:int}>,
 *   by_bot:     array<int, array{bot_name:string, hits:int}>,
 *   top_paths:  array<int, array{url_path:string, bot_name:string, hits:int}>,
 * }
 */
function myls_aiv_get_range( int $days = 28 ) : array {
	global $wpdb;
	$table = myls_aiv_table_name();
	$days  = max( 1, min( 365, $days ) );
	$since = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );

	$total = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COALESCE(SUM(hits),0) FROM {$table} WHERE hit_date >= %s",
		$since
	) );

	$by_day = $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE_FORMAT(hit_date, '%%Y-%%m-%%d') AS day, bot_name, SUM(hits) AS hits
		 FROM {$table}
		 WHERE hit_date >= %s
		 GROUP BY hit_date, bot_name
		 ORDER BY hit_date ASC",
		$since
	), ARRAY_A ) ?: [];

	$by_bot = $wpdb->get_results( $wpdb->prepare(
		"SELECT bot_name, SUM(hits) AS hits
		 FROM {$table}
		 WHERE hit_date >= %s
		 GROUP BY bot_name
		 ORDER BY hits DESC",
		$since
	), ARRAY_A ) ?: [];

	$top_paths = $wpdb->get_results( $wpdb->prepare(
		"SELECT url_path, bot_name, SUM(hits) AS hits
		 FROM {$table}
		 WHERE hit_date >= %s
		 GROUP BY url_path, bot_name
		 ORDER BY hits DESC
		 LIMIT 25",
		$since
	), ARRAY_A ) ?: [];

	return [
		'total'     => $total,
		'by_day'    => $by_day,
		'by_bot'    => $by_bot,
		'top_paths' => $top_paths,
	];
}

/**
 * Delete rows older than the retention window. Called by the nightly cron.
 */
function myls_aiv_purge( int $retention_days = 180 ) : int {
	global $wpdb;
	$table = myls_aiv_table_name();
	$retention_days = max( 7, min( 3650, $retention_days ) );
	$cutoff = gmdate( 'Y-m-d', time() - ( $retention_days * DAY_IN_SECONDS ) );

	return (int) $wpdb->query( $wpdb->prepare(
		"DELETE FROM {$table} WHERE hit_date < %s",
		$cutoff
	) );
}
