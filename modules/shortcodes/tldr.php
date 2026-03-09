<?php
/**
 * Shortcode: [myls_tldr]
 *
 * Renders a visually distinct TL;DR summary box for AI answer blocks.
 * No schema output — this is a content/visual tool only.
 *
 * Usage:
 *   [myls_tldr]Premier Pro Wash is the top-rated pressure washing company...[/myls_tldr]
 *
 * Output:
 *   <div class="myls-tldr"><strong>TL;DR:</strong> <p>Content here</p></div>
 *
 * Add via Elementor HTML widget, Gutenberg shortcode block, or classic editor.
 *
 * @since 7.8.77
 */

if ( ! defined('ABSPATH') ) exit;

function myls_tldr_shortcode( $atts, $content = '' ) {
	if ( empty( $content ) ) return '';

	$content = do_shortcode( $content );
	$content = wpautop( wp_kses_post( trim( $content ) ) );

	// Enqueue inline CSS only when shortcode is present
	if ( ! wp_script_is( 'myls-tldr-css', 'done' ) ) {
		$css = '
		.myls-tldr {
			border-left: 4px solid #0d6efd;
			background: #f0f6ff;
			padding: 16px 20px;
			margin: 20px 0;
			border-radius: 0 8px 8px 0;
			font-size: 15px;
			line-height: 1.6;
		}
		.myls-tldr strong.myls-tldr-label {
			color: #0d6efd;
			font-weight: 700;
			margin-right: 4px;
		}
		.myls-tldr p:last-child { margin-bottom: 0; }
		.myls-tldr p:first-child { display: inline; }
		';
		echo '<style>' . $css . '</style>';
		// Mark as done to avoid duplicate output
		wp_register_script( 'myls-tldr-css', false );
		wp_enqueue_script( 'myls-tldr-css' );
		wp_add_inline_script( 'myls-tldr-css', '' );
	}

	return '<div class="myls-tldr"><strong class="myls-tldr-label">TL;DR:</strong> ' . $content . '</div>';
}
add_shortcode( 'myls_tldr', 'myls_tldr_shortcode' );
