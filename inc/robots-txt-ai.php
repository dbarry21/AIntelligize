<?php
/**
 * AIntelligize — robots.txt AI bot rules
 *
 * Appends rules for known AI crawlers (GPTBot, ClaudeBot, etc.) to the
 * virtual robots.txt that WordPress generates. By default all AI bots are
 * ALLOWED so the site content can be indexed by generative engines.
 *
 * Toggle: myls_robots_ai_enabled  (default '1')
 * Mode:   myls_robots_ai_mode     'allow' (default) | 'block'
 *
 * @since 7.8.95
 */

if ( ! defined('ABSPATH') ) exit;

add_filter( 'robots_txt', function ( string $output, bool $public ) : string {

	// Respect WP "Discourage search engines" setting
	if ( ! $public ) return $output;

	// Kill switch
	if ( get_option( 'myls_robots_ai_enabled', '1' ) !== '1' ) return $output;

	$mode = get_option( 'myls_robots_ai_mode', 'allow' );
	$rule = ( $mode === 'block' ) ? 'Disallow' : 'Allow';

	$bots = [
		'GPTBot',
		'ChatGPT-User',
		'OAI-SearchBot',
		'ClaudeBot',
		'Claude-Web',
		'PerplexityBot',
		'GoogleOther',
		'Google-Extended',
		'Applebot-Extended',
		'cohere-ai',
		'Bytespider',
		'CCBot',
	];

	/**
	 * Filter the list of AI bot user-agents.
	 *
	 * @param string[] $bots  User-agent names.
	 * @param string   $mode  'allow' or 'block'.
	 */
	$bots = apply_filters( 'myls_robots_ai_bots', $bots, $mode );

	if ( empty( $bots ) ) return $output;

	$lines  = "\n# AIntelligize — AI crawler rules ({$mode})\n";
	foreach ( $bots as $bot ) {
		$bot = sanitize_text_field( $bot );
		$lines .= "User-agent: {$bot}\n{$rule}: /\n\n";
	}

	// Append llms.txt sitemap-style hint for AI crawlers
	$llms_url = home_url( '/llms.txt' );
	$lines .= "# AI content discovery\n";
	$lines .= "Sitemap: {$llms_url}\n";

	return $output . $lines;

}, 100, 2 );
