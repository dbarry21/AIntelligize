<?php
/**
 * AIntelligize — Beaver Builder Site Analyzer
 *
 * Mirror of inc/elementor-site-analyzer.php for the Beaver Builder sub-tab.
 *
 * Surveys the active site for BB-specific context that can be folded into the
 * AI prompt — sample BB-built pages, their headings/text, BB Theme settings,
 * and a compact "prompt block" string ready to append to the user's prompt.
 *
 * All parsing is delegated to AIntelligize_Beaver_Builder_Parser. This file
 * never hits BB postmeta directly.
 *
 * Public API:
 *   myls_bb_analyze_site( $post_type )   — full site context for AI prompt
 *   myls_bb_get_sample_layouts( $limit ) — read N BB-built posts for prompt grounding
 *   myls_bb_get_theme_context()          — BB Theme settings summary
 *
 * @since 7.10.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'AIntelligize_Beaver_Builder_Parser' ) ) {
	require_once __DIR__ . '/class-aintelligize-beaver-builder-parser.php';
}

/**
 * Singleton parser accessor for analyzer functions.
 */
if ( ! function_exists( 'myls_bb_parser' ) ) {
	function myls_bb_parser(): AIntelligize_Beaver_Builder_Parser {
		static $instance = null;
		if ( $instance === null ) {
			$instance = new AIntelligize_Beaver_Builder_Parser();
		}
		return $instance;
	}
}

/**
 * Read up to $limit BB-built posts and extract headings + text content.
 * Used to ground the AI in real on-site patterns.
 */
if ( ! function_exists( 'myls_bb_get_sample_layouts' ) ) {
	function myls_bb_get_sample_layouts( int $limit = 3 ): array {
		$parser = myls_bb_parser();

		// Find posts with _fl_builder_enabled = '1'.
		$q = new WP_Query( array(
			'post_type'              => array( 'page', 'post', 'service' ),
			'post_status'            => 'publish',
			'posts_per_page'         => max( 1, min( 10, $limit ) ),
			'meta_key'               => '_fl_builder_enabled',
			'meta_value'             => '1',
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		) );

		$samples = array();
		foreach ( $q->posts as $p ) {
			$headings  = $parser->get_headings( $p->ID );
			$text_full = $parser->get_text_content( $p->ID );
			$samples[] = array(
				'id'        => (int) $p->ID,
				'title'     => $p->post_title,
				'post_type' => $p->post_type,
				'headings'  => array_slice( $headings, 0, 8 ),
				'text'      => mb_substr( $text_full, 0, 600 ),
			);
		}
		return $samples;
	}
}

/**
 * Compact BB Theme settings dump for prompt grounding.
 * Returns key/value pairs likely to influence schema/SEO output.
 */
if ( ! function_exists( 'myls_bb_get_theme_context' ) ) {
	function myls_bb_get_theme_context(): array {
		$parser = myls_bb_parser();
		if ( ! $parser->is_bb_theme_active() ) {
			return array();
		}
		$theme = $parser->get_bb_theme_settings();
		if ( empty( $theme ) ) {
			return array();
		}

		// Pluck a small set of high-signal fields. Keys vary across BB Theme
		// versions, so we use array_key access defensively.
		$pluck_keys = array(
			'fl-logo-text',
			'fl-logo-image',
			'fl-header-layout',
			'fl-header-content-layout',
			'fl-footer-layout',
			'fl-page-heading-bg-color',
			'fl-accent',
			'fl-body-font-family',
			'fl-body-font-size',
		);
		$out = array();
		foreach ( $pluck_keys as $k ) {
			if ( isset( $theme[ $k ] ) && $theme[ $k ] !== '' ) {
				$out[ $k ] = $theme[ $k ];
			}
		}
		return $out;
	}
}

/**
 * Full site analysis for the AI prompt.
 *
 * Mirrors the shape produced by myls_elb_analyze_site():
 *   [
 *     'kit'           => [ 'container_width' => 1140, ... ],   // BB has no kit; we fake stable defaults
 *     'sample_pages'  => array,
 *     'patterns'      => array,                                  // empty for v1
 *     'prompt_block'  => string,                                 // appended to AI prompt
 *     'log'           => array,
 *   ]
 */
if ( ! function_exists( 'myls_bb_analyze_site' ) ) {
	function myls_bb_analyze_site( string $post_type = 'page' ): array {
		$parser = myls_bb_parser();
		$log    = array();

		// BB has no "kit" the way Elementor does. Pull a few global signals from
		// BB Theme settings if available, else use safe defaults.
		$theme_ctx = myls_bb_get_theme_context();
		$kit = array(
			'container_width' => 1140,                                            // BB default in most themes
			'accent_color'    => $theme_ctx['fl-accent'] ?? '#2271b1',
			'body_font'       => $theme_ctx['fl-body-font-family'] ?? '',
			'is_bb_theme'     => $parser->is_bb_theme_active(),
			'is_child_theme'  => is_child_theme(),
		);
		$log[] = sprintf(
			'BB env detected: theme=%s, child=%s, accent=%s',
			$kit['is_bb_theme'] ? 'yes' : 'no',
			$kit['is_child_theme'] ? 'yes' : 'no',
			$kit['accent_color']
		);

		$samples = myls_bb_get_sample_layouts( 3 );
		$log[]   = 'BB sample layouts read: ' . count( $samples );

		// Build a compact, AI-friendly context string.
		$prompt_lines = array();
		if ( ! empty( $samples ) ) {
			$prompt_lines[] = "\n\n--- Site context (existing Beaver Builder pages, for tone/structure reference only — do not copy verbatim) ---";
			foreach ( $samples as $s ) {
				$prompt_lines[] = '• ' . $s['title'] . ' (' . $s['post_type'] . ')';
				$head_strs = array();
				foreach ( $s['headings'] as $h ) {
					$head_strs[] = strtoupper( $h['tag'] ) . ': ' . $h['text'];
				}
				if ( $head_strs ) {
					$prompt_lines[] = '   headings: ' . implode( ' | ', array_slice( $head_strs, 0, 6 ) );
				}
				if ( $s['text'] ) {
					$prompt_lines[] = '   excerpt: ' . str_replace( "\n", ' ', mb_substr( $s['text'], 0, 240 ) );
				}
			}
		}
		if ( $kit['accent_color'] ) {
			$prompt_lines[] = '• Site accent color (use sparingly in inline styles): ' . $kit['accent_color'];
		}

		return array(
			'kit'          => $kit,
			'sample_pages' => $samples,
			'patterns'     => array(),
			'prompt_block' => $prompt_lines ? implode( "\n", $prompt_lines ) : '',
			'log'          => $log,
		);
	}
}
