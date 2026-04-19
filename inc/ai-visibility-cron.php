<?php
/**
 * AI Visibility — Retention Cron
 * Path: inc/ai-visibility-cron.php
 *
 * Nightly job that trims both:
 *   - {prefix}myls_ai_crawler_hits (via myls_aiv_purge)
 *   - {prefix}myls_ai_referrals    (direct DELETE by created_at)
 *
 * Retention knob: option `myls_aiv_retention_days` (default 180, clamped 7–3650).
 *
 * @since 7.9.18.107
 */

if ( ! defined('ABSPATH') ) exit;

/** Self-heal: schedule the daily event on every init if missing. */
add_action( 'init', function () {
	if ( ! wp_next_scheduled('myls_aiv_purge_cron') ) {
		// Kick off in ~1 hour so activation doesn't immediately trigger a purge.
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'myls_aiv_purge_cron' );
	}
} );

/** Purge handler. */
add_action( 'myls_aiv_purge_cron', function () {
	$retention = (int) get_option('myls_aiv_retention_days', 180);
	$retention = max( 7, min( 3650, $retention ) );

	if ( function_exists('myls_aiv_purge') ) {
		myls_aiv_purge( $retention );
	}

	// Also trim the AI referrals log (separate, pre-existing table).
	global $wpdb;
	$ref_table = $wpdb->prefix . 'myls_ai_referrals';
	$cutoff    = gmdate( 'Y-m-d H:i:s', time() - ( $retention * DAY_IN_SECONDS ) );
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$ref_table} WHERE created_at < %s",
		$cutoff
	) );
} );

/** Unschedule on plugin deactivation (hook fires from the main plugin file). */
if ( ! function_exists('myls_aiv_unschedule_purge') ) {
	function myls_aiv_unschedule_purge() : void {
		$ts = wp_next_scheduled('myls_aiv_purge_cron');
		if ( $ts ) wp_unschedule_event( $ts, 'myls_aiv_purge_cron' );
	}
}
