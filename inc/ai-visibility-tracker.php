<?php
/**
 * AI Visibility — Crawler Hit Tracker
 * Path: inc/ai-visibility-tracker.php
 *
 * Detects AI crawler User-Agents on public requests and bumps a daily counter
 * in {prefix}myls_ai_crawler_hits. Fast-fails on almost all human traffic so
 * normal requests pay ~1 strpos scan.
 *
 * Toggle: myls_aiv_tracking_enabled (default '1')
 *
 * @since 7.9.18.107
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Ordered pattern → canonical bot-name map. Order matters: more specific UA
 * tokens must come before broader ones (e.g. 'Google-Extended' before 'GoogleOther').
 *
 * Baseline from inc/robots-txt-ai.php; extended with anthropic-ai, Amazonbot, Diffbot.
 *
 * @return array<string,string>  pattern => canonical bot name
 */
if ( ! function_exists('myls_aiv_bot_patterns') ) {
	function myls_aiv_bot_patterns() : array {
		$map = [
			'GPTBot'            => 'GPTBot',
			'ChatGPT-User'      => 'ChatGPT-User',
			'OAI-SearchBot'     => 'OAI-SearchBot',
			'ClaudeBot'         => 'ClaudeBot',
			'Claude-Web'        => 'Claude-Web',
			'anthropic-ai'      => 'anthropic-ai',
			'PerplexityBot'     => 'PerplexityBot',
			'Google-Extended'   => 'Google-Extended',
			'GoogleOther'       => 'GoogleOther',
			'Applebot-Extended' => 'Applebot-Extended',
			'cohere-ai'         => 'cohere-ai',
			'Bytespider'        => 'Bytespider',
			'CCBot'             => 'CCBot',
			'Amazonbot'         => 'Amazonbot',
			'Diffbot'           => 'Diffbot',
			'YouBot'            => 'YouBot',
			'Meta-ExternalAgent'=> 'Meta-ExternalAgent',
		];
		return apply_filters( 'myls_aiv_bot_patterns', $map );
	}
}

/**
 * Return the canonical bot name matching this User-Agent, or null if none.
 * Called per-request — kept deliberately lean.
 */
if ( ! function_exists('myls_aiv_match_bot') ) {
	function myls_aiv_match_bot( string $ua ) : ?string {
		if ( $ua === '' ) return null;
		foreach ( myls_aiv_bot_patterns() as $needle => $canonical ) {
			if ( stripos( $ua, $needle ) !== false ) {
				return $canonical;
			}
		}
		return null;
	}
}

/* -------------------------------------------------------------------------
 * Capture hook
 * ------------------------------------------------------------------------- */

add_action( 'template_redirect', function () {

	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) return;
	if ( defined('REST_REQUEST') && REST_REQUEST ) return;
	if ( get_option('myls_aiv_tracking_enabled', '1') !== '1' ) return;

	$ua = isset($_SERVER['HTTP_USER_AGENT'])
		? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
		: '';
	if ( $ua === '' ) return;

	// Fast-fail. One strpos scan of a short signature string skips virtually
	// all real browsers in a single loop iteration (they contain "Mozilla" +
	// "Safari" but none of these tokens).
	$fast = ['GPT','Bot','bot','Claude','Perplex','Applebot','cohere','CCBot','Bytespider','Amazon','Diffbot','anthropic','OAI'];
	$hit  = false;
	foreach ( $fast as $n ) {
		if ( strpos( $ua, $n ) !== false ) { $hit = true; break; }
	}
	if ( ! $hit ) return;

	$bot = myls_aiv_match_bot( $ua );
	if ( ! $bot ) return;

	// Normalise the request path: drop querystring, trim to varchar(191).
	$raw_path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
	$path = parse_url( $raw_path, PHP_URL_PATH );
	if ( ! is_string($path) || $path === '' ) $path = '/';
	$path = substr( $path, 0, 191 );

	if ( function_exists('myls_aiv_upsert_hit') ) {
		myls_aiv_upsert_hit( $bot, $path );
	}
}, 5 );
