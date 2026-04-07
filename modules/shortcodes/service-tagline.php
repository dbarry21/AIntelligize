<?php
/**
 * Shortcode: [service_tagline]
 *
 * Returns the service tagline (stored in _myls_service_tagline meta)
 * for the current or specified post.
 *
 * Usage:
 * - [service_tagline]
 * - [service_tagline prefix="– " suffix=" –"]
 * - [service_tagline id="123"]
 */
if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists( 'myls_service_tagline_shortcode' ) ) {

	function myls_service_tagline_shortcode( $atts = [] ) {

		$atts = shortcode_atts(
			[
				'id'     => '',      // optional explicit post ID
				'prefix' => '',
				'suffix' => '',
			],
			$atts,
			'service_tagline'
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

		$tagline = get_post_meta( $post_id, '_myls_service_tagline', true );
		$tagline = ( is_string( $tagline ) && trim( $tagline ) !== '' ) ? trim( $tagline ) : '';

		// Plain-text output (safe for placing inside headings/attributes)
		$tagline = trim( wp_strip_all_tags( $tagline ) );

		if ( $tagline === '' ) return '';

		$prefix = (string) $atts['prefix'];
		$suffix = (string) $atts['suffix'];

		return esc_html( $prefix . $tagline . $suffix );
	}
}

add_shortcode( 'service_tagline', 'myls_service_tagline_shortcode' );
