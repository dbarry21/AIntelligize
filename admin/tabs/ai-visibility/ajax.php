<?php
/**
 * AI Visibility — AJAX endpoints
 * Path: admin/tabs/ai-visibility/ajax.php
 *
 * Three endpoints for the three subtabs. All guarded by capability +
 * MYLS_AIV_NONCE_ACTION. GSC results cached in a 1-hour transient to
 * avoid hammering the API.
 *
 * @since 7.9.18.107
 */

if ( ! defined('ABSPATH') ) exit;

/** Shared: verify nonce + cap, return the requested day-range. */
function myls_aiv_check_request() : int {
	if ( ! current_user_can('manage_options') ) {
		wp_send_json_error([ 'message' => 'Forbidden' ], 403);
	}
	check_ajax_referer( MYLS_AIV_NONCE_ACTION, 'nonce' );

	$days = isset($_POST['days']) ? (int) $_POST['days'] : 28;
	return max( 1, min( 365, $days ) );
}

/* -------------------------------------------------------------------------
 * Crawlers
 * ------------------------------------------------------------------------- */

add_action( 'wp_ajax_myls_aiv_crawlers', function () {
	$days = myls_aiv_check_request();
	$data = function_exists('myls_aiv_get_range') ? myls_aiv_get_range( $days ) : [
		'total' => 0, 'by_day' => [], 'by_bot' => [], 'top_paths' => [],
	];

	$bots   = [];
	$paths  = [];
	foreach ( $data['by_bot']   as $row ) { $bots[]  = (string) $row['bot_name']; }
	foreach ( $data['top_paths'] as $row ) { $paths[] = (string) $row['url_path']; }

	wp_send_json_success( [
		'days'       => $days,
		'total'      => (int) $data['total'],
		'by_day'     => $data['by_day'],
		'by_bot'     => $data['by_bot'],
		'top_paths'  => $data['top_paths'],
		'bot_count'  => count( array_unique( $bots ) ),
		'path_count' => count( array_unique( $paths ) ),
	] );
} );

/* -------------------------------------------------------------------------
 * Referrers (reads the existing table via myls_ai_referral_get_stats)
 * ------------------------------------------------------------------------- */

add_action( 'wp_ajax_myls_aiv_referrers', function () {
	$days = myls_aiv_check_request();

	if ( ! function_exists('myls_ai_referral_get_stats') ) {
		wp_send_json_error([ 'message' => 'AI Referral tracker not loaded.' ], 500);
	}

	$stats = myls_ai_referral_get_stats( $days );

	// Build a title-augmented list of top landing pages.
	$top_pages = [];
	foreach ( (array) ( $stats['top_pages'] ?? [] ) as $row ) {
		$title = $row['post_id'] ? get_the_title( (int) $row['post_id'] ) : '';
		if ( ! $title ) $title = (string) ( $row['landing'] ?? '' );
		$top_pages[] = [
			'post_id' => (int) ( $row['post_id'] ?? 0 ),
			'title'   => $title,
			'landing' => (string) ( $row['landing'] ?? '' ),
			'source'  => (string) ( $row['source']  ?? '' ),
			'visits'  => (int)    ( $row['visits']  ?? 0 ),
		];
	}

	$sources_unique = count( array_unique( array_column( (array) $stats['by_source'], 'source' ) ) );
	$pages_unique   = count( $top_pages );

	wp_send_json_success( [
		'days'           => $days,
		'total'          => (int) ( $stats['total'] ?? 0 ),
		'by_day'         => $stats['by_day']    ?? [],
		'by_source'      => $stats['by_source'] ?? [],
		'top_pages'      => $top_pages,
		'sources_count'  => $sources_unique,
		'pages_count'    => $pages_unique,
	] );
} );

/* -------------------------------------------------------------------------
 * GSC helpers
 * ------------------------------------------------------------------------- */

/** Canonicalised inputs for the GSC endpoints: site, dates, rows, filters. */
function myls_aiv_gsc_inputs( int $days ) : array {
	$site_prop = trim( (string) get_option('myls_gsc_site_property', '') );
	if ( $site_prop === '' ) $site_prop = home_url('/');

	$rows = isset($_POST['rows']) ? (int) $_POST['rows'] : 100;
	if ( ! in_array( $rows, [ 25, 100, 500 ], true ) ) $rows = 100;

	$path_prefix = isset($_POST['path_prefix'])
		? substr( sanitize_text_field( wp_unslash( (string) $_POST['path_prefix'] ) ), 0, 100 )
		: '';

	$ai_overview = ! empty($_POST['ai_overview']) && $_POST['ai_overview'] !== '0';

	return [
		'site'        => $site_prop,
		'days'        => $days,
		'start'       => gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) ),
		'end'         => gmdate( 'Y-m-d' ),
		'api'         => 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( trailingslashit($site_prop) ) . '/searchAnalytics/query',
		'rows'        => $rows,
		'path_prefix' => $path_prefix,
		'ai_overview' => $ai_overview,
	];
}

/**
 * Build a dimensionFilterGroups array for a GSC query body. Returns an empty
 * array when no filters apply, so callers can merge without conditionals.
 *
 * @param bool   $include_page_prefix  apply the page contains-prefix filter
 * @param bool   $include_ai_overview  apply the AI_OVERVIEW searchAppearance filter
 * @param string $path_prefix          prefix to match (ignored if first flag is false)
 */
function myls_aiv_gsc_filter_groups( bool $include_page_prefix, bool $include_ai_overview, string $path_prefix ) : array {
	$filters = [];
	if ( $include_page_prefix && $path_prefix !== '' ) {
		$filters[] = [ 'dimension' => 'page', 'operator' => 'contains', 'expression' => $path_prefix ];
	}
	if ( $include_ai_overview ) {
		$filters[] = [ 'dimension' => 'searchAppearance', 'operator' => 'equals', 'expression' => 'AI_OVERVIEW' ];
	}
	if ( empty($filters) ) return [];
	return [ [ 'filters' => $filters ] ];
}

/* -------------------------------------------------------------------------
 * GSC — overview + filtered top queries / pages / combos
 * ------------------------------------------------------------------------- */

add_action( 'wp_ajax_myls_aiv_gsc', function () {
	$days = myls_aiv_check_request();

	if ( ! function_exists('myls_gsc_is_connected') || ! myls_gsc_is_connected() ) {
		wp_send_json_error([ 'message' => 'Google Search Console is not connected.' ], 400);
	}

	$in = myls_aiv_gsc_inputs( $days );

	$cache_key = 'myls_aiv_gsc_' . md5( implode('|', [
		$in['site'], $in['days'], $in['rows'], $in['path_prefix'], $in['ai_overview'] ? '1' : '0',
	] ) );
	$cached = get_transient( $cache_key );
	if ( is_array($cached) ) {
		$cached['cache'] = 'hit';
		wp_send_json_success( $cached );
	}

	$filters_all  = myls_aiv_gsc_filter_groups( true,  $in['ai_overview'], $in['path_prefix'] );
	$filters_aio  = myls_aiv_gsc_filter_groups( false, true,               '' );

	// 1) Totals (unfiltered — always the raw site KPIs for the range).
	$totals_resp = myls_gsc_oauth_call( $in['api'], 'POST', [
		'startDate' => $in['start'],
		'endDate'   => $in['end'],
		'rowLimit'  => 1,
	] );
	if ( is_wp_error($totals_resp) ) {
		wp_send_json_error([ 'message' => $totals_resp->get_error_message() ], 502);
	}
	$totals_row  = $totals_resp['rows'][0] ?? [];
	$impressions = (int)   ( $totals_row['impressions'] ?? 0 );
	$clicks      = (int)   ( $totals_row['clicks']      ?? 0 );
	$ctr         = (float) ( $totals_row['ctr']         ?? 0 );
	$position    = (float) ( $totals_row['position']    ?? 0 );

	// 2) AI Overview totals (always fetched, unfiltered by path/rows so the
	// KPIs stay stable regardless of the user's current filter toggles).
	$aio_resp = myls_gsc_oauth_call( $in['api'], 'POST', [
		'startDate'             => $in['start'],
		'endDate'               => $in['end'],
		'dimensionFilterGroups' => $filters_aio,
		'rowLimit'              => 1,
	] );
	$aio_impressions = 0;
	$aio_clicks      = 0;
	if ( ! is_wp_error($aio_resp) && ! empty($aio_resp['rows'][0]) ) {
		$aio_impressions = (int) ( $aio_resp['rows'][0]['impressions'] ?? 0 );
		$aio_clicks      = (int) ( $aio_resp['rows'][0]['clicks']      ?? 0 );
	}

	// 3) Top queries (filtered by active toggles).
	$q_body = [
		'startDate'  => $in['start'],
		'endDate'    => $in['end'],
		'dimensions' => [ 'query' ],
		'rowLimit'   => $in['rows'],
	];
	if ( ! empty($filters_all) ) $q_body['dimensionFilterGroups'] = $filters_all;
	$q_resp = myls_gsc_oauth_call( $in['api'], 'POST', $q_body );

	$top_queries = [];
	if ( ! is_wp_error($q_resp) && ! empty($q_resp['rows']) ) {
		foreach ( $q_resp['rows'] as $row ) {
			$top_queries[] = [
				'query'       => (string) ( $row['keys'][0] ?? '' ),
				'impressions' => (int)    ( $row['impressions'] ?? 0 ),
				'clicks'      => (int)    ( $row['clicks']      ?? 0 ),
				'position'    => (float)  ( $row['position']    ?? 0 ),
			];
		}
	}

	// 4) Top pages (same filters).
	$p_body = [
		'startDate'  => $in['start'],
		'endDate'    => $in['end'],
		'dimensions' => [ 'page' ],
		'rowLimit'   => $in['rows'],
	];
	if ( ! empty($filters_all) ) $p_body['dimensionFilterGroups'] = $filters_all;
	$p_resp = myls_gsc_oauth_call( $in['api'], 'POST', $p_body );

	$top_pages = [];
	if ( ! is_wp_error($p_resp) && ! empty($p_resp['rows']) ) {
		foreach ( $p_resp['rows'] as $row ) {
			$top_pages[] = [
				'page'        => (string) ( $row['keys'][0] ?? '' ),
				'impressions' => (int)    ( $row['impressions'] ?? 0 ),
				'clicks'      => (int)    ( $row['clicks']      ?? 0 ),
				'position'    => (float)  ( $row['position']    ?? 0 ),
			];
		}
	}

	// 5) Query × Page combos (capped at 50; same filters).
	$c_body = [
		'startDate'  => $in['start'],
		'endDate'    => $in['end'],
		'dimensions' => [ 'query', 'page' ],
		'rowLimit'   => 50,
	];
	if ( ! empty($filters_all) ) $c_body['dimensionFilterGroups'] = $filters_all;
	$c_resp = myls_gsc_oauth_call( $in['api'], 'POST', $c_body );

	$combos = [];
	if ( ! is_wp_error($c_resp) && ! empty($c_resp['rows']) ) {
		foreach ( $c_resp['rows'] as $row ) {
			$combos[] = [
				'query'       => (string) ( $row['keys'][0] ?? '' ),
				'page'        => (string) ( $row['keys'][1] ?? '' ),
				'impressions' => (int)    ( $row['impressions'] ?? 0 ),
				'clicks'      => (int)    ( $row['clicks']      ?? 0 ),
				'position'    => (float)  ( $row['position']    ?? 0 ),
			];
		}
	}

	$out = [
		'days'            => $days,
		'site'            => $in['site'],
		'rows'            => $in['rows'],
		'path_prefix'     => $in['path_prefix'],
		'ai_overview'     => $in['ai_overview'],
		'impressions'     => $impressions,
		'clicks'          => $clicks,
		'ctr'             => $ctr,
		'position'        => $position,
		'aio_impressions' => $aio_impressions,
		'aio_clicks'      => $aio_clicks,
		'top_queries'     => $top_queries,
		'top_pages'       => $top_pages,
		'combos'          => $combos,
		'cache'           => 'miss',
	];

	set_transient( $cache_key, $out, HOUR_IN_SECONDS );
	wp_send_json_success( $out );
} );

/* -------------------------------------------------------------------------
 * GSC — click-drill endpoint: one query → top pages, or one page → top queries
 * ------------------------------------------------------------------------- */

add_action( 'wp_ajax_myls_aiv_gsc_drill', function () {
	$days = myls_aiv_check_request();

	if ( ! function_exists('myls_gsc_is_connected') || ! myls_gsc_is_connected() ) {
		wp_send_json_error([ 'message' => 'Google Search Console is not connected.' ], 400);
	}

	$by = isset($_POST['by']) ? sanitize_key( $_POST['by'] ) : '';
	if ( ! in_array( $by, [ 'query', 'page' ], true ) ) {
		wp_send_json_error([ 'message' => 'Invalid drill dimension.' ], 400);
	}
	$value = isset($_POST['value'])
		? substr( sanitize_text_field( wp_unslash( (string) $_POST['value'] ) ), 0, 500 )
		: '';
	if ( $value === '' ) {
		wp_send_json_error([ 'message' => 'Missing drill value.' ], 400);
	}

	$in = myls_aiv_gsc_inputs( $days );

	$cache_key = 'myls_aiv_gsc_drill_' . md5( implode('|', [
		$in['site'], $in['days'], $by, $value, $in['ai_overview'] ? '1' : '0',
	] ) );
	$cached = get_transient( $cache_key );
	if ( is_array($cached) ) {
		$cached['cache'] = 'hit';
		wp_send_json_success( $cached );
	}

	$other = ( $by === 'query' ) ? 'page' : 'query';

	$filters = [ [ 'dimension' => $by, 'operator' => 'equals', 'expression' => $value ] ];
	if ( $in['ai_overview'] ) {
		$filters[] = [ 'dimension' => 'searchAppearance', 'operator' => 'equals', 'expression' => 'AI_OVERVIEW' ];
	}

	$resp = myls_gsc_oauth_call( $in['api'], 'POST', [
		'startDate'             => $in['start'],
		'endDate'               => $in['end'],
		'dimensions'            => [ $other ],
		'dimensionFilterGroups' => [ [ 'filters' => $filters ] ],
		'rowLimit'              => 10,
	] );
	if ( is_wp_error($resp) ) {
		wp_send_json_error([ 'message' => $resp->get_error_message() ], 502);
	}

	$rows = [];
	if ( ! empty($resp['rows']) ) {
		foreach ( $resp['rows'] as $row ) {
			$rows[] = [
				$other         => (string) ( $row['keys'][0] ?? '' ),
				'impressions'  => (int)    ( $row['impressions'] ?? 0 ),
				'clicks'       => (int)    ( $row['clicks']      ?? 0 ),
				'position'     => (float)  ( $row['position']    ?? 0 ),
			];
		}
	}

	$out = [
		'by'    => $by,
		'value' => $value,
		'other' => $other,
		'rows'  => $rows,
		'cache' => 'miss',
	];

	set_transient( $cache_key, $out, HOUR_IN_SECONDS );
	wp_send_json_success( $out );
} );
