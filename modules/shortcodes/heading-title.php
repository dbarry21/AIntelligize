<?php
/**
 * Shortcode: [heading_title]
 *
 * Returns the alternate page title (stored in _myls_alt_page_title meta),
 * falling back to the WordPress page title if the field is blank.
 *
 * Designed for use in Elementor Theme Builder heading widgets where a
 * custom heading is needed without affecting [page_title] elsewhere.
 *
 * Usage:
 * - [heading_title]
 * - [heading_title prefix="About " suffix=" FAQs"]
 * - [heading_title id="123"]
 */
if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists( 'myls_heading_title_shortcode' ) ) {

	function myls_heading_title_shortcode( $atts = [] ) {

		$atts = shortcode_atts(
			[
				'id'     => '',      // optional explicit post ID
				'prefix' => '',
				'suffix' => '',
			],
			$atts,
			'heading_title'
		);

		$post_id = absint( $atts['id'] );

		// Default to the current global post, then get_the_ID()
		if ( ! $post_id && isset( $GLOBALS['post'] ) && is_object( $GLOBALS['post'] ) && ! empty( $GLOBALS['post']->ID ) ) {
			$post_id = (int) $GLOBALS['post']->ID;
		}
		if ( ! $post_id ) {
			$post_id = (int) get_the_ID();
		}

		if ( ! $post_id ) return '';

		// Prefer alternate page title if set, otherwise fall back to WP title.
		$alt = get_post_meta( $post_id, '_myls_alt_page_title', true );
		$title = ( is_string( $alt ) && trim( $alt ) !== '' ) ? trim( $alt ) : get_the_title( $post_id );
		$title = is_string( $title ) ? $title : '';

		// Plain-text output (safe for placing inside headings/attributes)
		$title = trim( wp_strip_all_tags( $title ) );

		if ( $title === '' ) return '';

		$prefix = (string) $atts['prefix'];
		$suffix = (string) $atts['suffix'];

		return esc_html( $prefix . $title . $suffix );
	}
}

add_shortcode( 'heading_title', 'myls_heading_title_shortcode' );
