<?php
/**
 * AIntelligize — AI Referral Traffic Tracker
 *
 * Detects visits referred from AI engines (ChatGPT, Perplexity, Claude,
 * Gemini, etc.) and logs them to a lightweight custom DB table. Also sets
 * a first-party cookie so subsequent page views within the same session
 * are attributed to the AI source.
 *
 * Toggle: myls_ai_referral_enabled (default '1')
 *
 * @since 7.8.95
 */

if ( ! defined('ABSPATH') ) exit;

/* -------------------------------------------------------------------------
 * DB table creation (runs on plugin activation / upgrade)
 * ------------------------------------------------------------------------- */

if ( ! function_exists( 'myls_ai_referral_ensure_table' ) ) {
	function myls_ai_referral_ensure_table() : void {
		global $wpdb;

		$table   = $wpdb->prefix . 'myls_ai_referrals';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source     VARCHAR(50)  NOT NULL DEFAULT '',
			referrer   VARCHAR(500) NOT NULL DEFAULT '',
			landing    VARCHAR(500) NOT NULL DEFAULT '',
			post_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_agent VARCHAR(500) NOT NULL DEFAULT '',
			created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_source     (source),
			KEY idx_created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}

register_activation_hook( MYLS_PLUGIN_FILE ?? __FILE__, 'myls_ai_referral_ensure_table' );

// Also ensure on upgrade (version change)
add_action( 'plugins_loaded', function () {
	$installed = get_option( 'myls_ai_referral_db_ver', '0' );
	if ( $installed !== '1' ) {
		myls_ai_referral_ensure_table();
		update_option( 'myls_ai_referral_db_ver', '1', true );
	}
}, 20 );

/* -------------------------------------------------------------------------
 * Known AI referrer domains → source label map
 * ------------------------------------------------------------------------- */

if ( ! function_exists( 'myls_ai_referral_sources' ) ) {
	function myls_ai_referral_sources() : array {
		$sources = [
			'chatgpt.com'          => 'ChatGPT',
			'chat.openai.com'      => 'ChatGPT',
			'perplexity.ai'        => 'Perplexity',
			'claude.ai'            => 'Claude',
			'gemini.google.com'    => 'Gemini',
			'copilot.microsoft.com'=> 'Copilot',
			'bing.com/chat'        => 'Copilot',
			'you.com'              => 'You.com',
			'phind.com'            => 'Phind',
			'poe.com'              => 'Poe',
			'meta.ai'              => 'MetaAI',
		];
		return apply_filters( 'myls_ai_referral_sources', $sources );
	}
}

/* -------------------------------------------------------------------------
 * Detection + logging (front-end only, non-admin, non-bot)
 * ------------------------------------------------------------------------- */

add_action( 'template_redirect', function () {

	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) return;
	if ( get_option( 'myls_ai_referral_enabled', '1' ) !== '1' ) return;
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;

	$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

	// Check cookie first (session continuity)
	$cookie_source = isset( $_COOKIE['myls_ai_ref'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['myls_ai_ref'] ) ) : '';

	$source = '';

	if ( $referrer !== '' ) {
		$ref_host = strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) );
		$ref_path = strtolower( (string) wp_parse_url( $referrer, PHP_URL_PATH ) );

		foreach ( myls_ai_referral_sources() as $domain => $label ) {
			// Handle domains with path (e.g. bing.com/chat)
			if ( str_contains( $domain, '/' ) ) {
				$parts = explode( '/', $domain, 2 );
				if ( $ref_host === $parts[0] || str_ends_with( $ref_host, '.' . $parts[0] ) ) {
					if ( str_starts_with( $ref_path, '/' . $parts[1] ) ) {
						$source = $label;
						break;
					}
				}
			} else {
				if ( $ref_host === $domain || str_ends_with( $ref_host, '.' . $domain ) ) {
					$source = $label;
					break;
				}
			}
		}
	}

	// Fall back to cookie if referrer didn't match but session was AI-sourced
	if ( $source === '' && $cookie_source !== '' ) {
		$source = $cookie_source;
	}

	if ( $source === '' ) return;

	// Set cookie for 30-minute session attribution window
	if ( $cookie_source !== $source ) {
		setcookie( 'myls_ai_ref', $source, time() + 1800, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
	}

	// Log the visit
	global $wpdb;
	$table   = $wpdb->prefix . 'myls_ai_referrals';
	$post_id = is_singular() ? (int) get_queried_object_id() : 0;

	$wpdb->insert( $table, [
		'source'     => $source,
		'referrer'   => mb_substr( $referrer, 0, 500 ),
		'landing'    => mb_substr( esc_url_raw( home_url( $_SERVER['REQUEST_URI'] ?? '/' ) ), 0, 500 ),
		'post_id'    => $post_id,
		'user_agent' => mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 500 ),
	], [ '%s', '%s', '%s', '%d', '%s' ] );

}, 1 );

/* -------------------------------------------------------------------------
 * Query helpers (for stats dashboard)
 * ------------------------------------------------------------------------- */

if ( ! function_exists( 'myls_ai_referral_get_stats' ) ) {
	/**
	 * Get AI referral stats for a given period.
	 *
	 * @param int $days Number of days to look back (default 30).
	 * @return array { total: int, by_source: array, by_day: array, top_pages: array }
	 */
	function myls_ai_referral_get_stats( int $days = 30 ) : array {
		global $wpdb;
		$table = $wpdb->prefix . 'myls_ai_referrals';
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $since
		) );

		$by_source = $wpdb->get_results( $wpdb->prepare(
			"SELECT source, COUNT(*) AS visits FROM {$table} WHERE created_at >= %s GROUP BY source ORDER BY visits DESC", $since
		), ARRAY_A ) ?: [];

		$by_day = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) AS day, source, COUNT(*) AS visits FROM {$table} WHERE created_at >= %s GROUP BY day, source ORDER BY day ASC", $since
		), ARRAY_A ) ?: [];

		$top_pages = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, landing, source, COUNT(*) AS visits FROM {$table} WHERE created_at >= %s AND post_id > 0 GROUP BY post_id, source ORDER BY visits DESC LIMIT 20", $since
		), ARRAY_A ) ?: [];

		return [
			'total'     => $total,
			'by_source' => $by_source,
			'by_day'    => $by_day,
			'top_pages' => $top_pages,
		];
	}
}
