<?php
/**
 * Shortcode: [page_title]
 *
 * Returns the current page/post title as plain text.
 *
 * Usage:
 * - [page_title]
 * - [page_title prefix="About " suffix=" FAQs"]
 * - [page_title id="123"]
 *
 * Notes:
 * - If used inside another shortcode attribute (like heading="..."), it will still work
 *   because your accordion now runs do_shortcode() on the heading attribute.
 */
if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists( 'myls_page_title_shortcode' ) ) {

	function myls_page_title_shortcode( $atts = [] ) {

		$atts = shortcode_atts(
			[
				'id'     => '',      // optional explicit post ID
				'prefix' => '',
				'suffix' => '',
			],
			$atts,
			'page_title'
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

		$title = get_the_title( $post_id );
		$title = is_string( $title ) ? $title : '';

		// Plain-text output (safe for placing inside headings/attributes)
		$title = trim( wp_strip_all_tags( $title ) );

		if ( $title === '' ) return '';

		$prefix = (string) $atts['prefix'];
		$suffix = (string) $atts['suffix'];

		return esc_html( $prefix . $title . $suffix );
	}
}

add_shortcode( 'page_title', 'myls_page_title_shortcode' );
