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
 * GSC
 * ------------------------------------------------------------------------- */

add_action( 'wp_ajax_myls_aiv_gsc', function () {
	$days = myls_aiv_check_request();

	if ( ! function_exists('myls_gsc_is_connected') || ! myls_gsc_is_connected() ) {
		wp_send_json_error([ 'message' => 'Google Search Console is not connected.' ], 400);
	}

	$site_prop = trim( (string) get_option('myls_gsc_site_property', '') );
	if ( $site_prop === '' ) $site_prop = home_url('/');

	$cache_key = 'myls_aiv_gsc_' . md5( $site_prop . '|' . $days );
	$cached    = get_transient( $cache_key );
	if ( is_array($cached) ) {
		$cached['cache'] = 'hit';
		wp_send_json_success( $cached );
	}

	$start = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );
	$end   = gmdate( 'Y-m-d' );

	$api = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( trailingslashit($site_prop) ) . '/searchAnalytics/query';

	// 1) Totals (no dimensions).
	$totals_resp = myls_gsc_oauth_call( $api, 'POST', [
		'startDate' => $start,
		'endDate'   => $end,
		'rowLimit'  => 1,
	] );
	if ( is_wp_error($totals_resp) ) {
		wp_send_json_error([ 'message' => $totals_resp->get_error_message() ], 502);
	}
	$totals_row   = $totals_resp['rows'][0] ?? [];
	$impressions  = (int)   ( $totals_row['impressions'] ?? 0 );
	$clicks       = (int)   ( $totals_row['clicks']      ?? 0 );
	$ctr          = (float) ( $totals_row['ctr']         ?? 0 );
	$position     = (float) ( $totals_row['position']    ?? 0 );

	// 2) AI Overview totals (searchAppearance filter).
	$aio_resp = myls_gsc_oauth_call( $api, 'POST', [
		'startDate'  => $start,
		'endDate'    => $end,
		'dimensions' => [ 'searchAppearance' ],
		'rowLimit'   => 50,
	] );
	$aio_impressions = 0;
	$aio_clicks      = 0;
	if ( ! is_wp_error($aio_resp) && ! empty($aio_resp['rows']) ) {
		foreach ( $aio_resp['rows'] as $row ) {
			$key = strtoupper( (string) ( $row['keys'][0] ?? '' ) );
			if ( strpos($key, 'AI_OVERVIEW') !== false || strpos($key, 'AI OVERVIEW') !== false ) {
				$aio_impressions += (int) ( $row['impressions'] ?? 0 );
				$aio_clicks      += (int) ( $row['clicks']      ?? 0 );
			}
		}
	}

	// 3) Top queries.
	$q_resp = myls_gsc_oauth_call( $api, 'POST', [
		'startDate'  => $start,
		'endDate'    => $end,
		'dimensions' => [ 'query' ],
		'rowLimit'   => 25,
	] );
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

	// 4) Top pages.
	$p_resp = myls_gsc_oauth_call( $api, 'POST', [
		'startDate'  => $start,
		'endDate'    => $end,
		'dimensions' => [ 'page' ],
		'rowLimit'   => 25,
	] );
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

	$out = [
		'days'            => $days,
		'site'            => $site_prop,
		'impressions'     => $impressions,
		'clicks'          => $clicks,
		'ctr'             => $ctr,
		'position'        => $position,
		'aio_impressions' => $aio_impressions,
		'aio_clicks'      => $aio_clicks,
		'top_queries'     => $top_queries,
		'top_pages'       => $top_pages,
		'cache'           => 'miss',
	];

	set_transient( $cache_key, $out, HOUR_IN_SECONDS );
	wp_send_json_success( $out );
} );
