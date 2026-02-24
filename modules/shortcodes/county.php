<?php
/**
 * MYLS — ACF County Shortcodes
 *
 * Generates:
 *  - [county_name]                      (defaults to ACF field "county" on current post)
 *  - [acf_field field="county"]         (generic helper for any ACF field)
 *
 * Optional attributes:
 *  - post_id="123"                      (pull from a specific post)
 *  - fallback="Hillsborough County"     (used if field is empty)
 *  - wrap="span" class="my-class"       (wrap output in an element)
 *
 * Notes:
 * - Safe for WYSIWYG content (esc_html).
 * - Returns empty string if ACF is not active.
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Generic: [acf_field field="county" post_id="123" fallback="" wrap="" class=""]
 */
if ( ! function_exists( 'myls_acf_field_shortcode' ) ) {
	function myls_acf_field_shortcode( $atts ) {

		if ( ! function_exists( 'get_field' ) ) {
			return '';
		}

		$atts = shortcode_atts(
			[
				'field'    => '',
				'post_id'  => '',
				'fallback' => '',
				'wrap'     => '',   // e.g., "span", "div", "strong"
				'class'    => '',
			],
			$atts,
			'acf_field'
		);

		$field = sanitize_key( $atts['field'] );
		if ( ! $field ) return '';

		$post_id = $atts['post_id'] !== '' ? (int) $atts['post_id'] : (int) get_the_ID();
		if ( ! $post_id ) return '';

		$value = get_field( $field, $post_id );

		// Handle arrays (image fields, etc.) gracefully (basic flatten)
		if ( is_array( $value ) ) {
			if ( isset( $value['url'] ) ) {
				$value = $value['url'];
			} else {
				$value = implode( ', ', array_filter( array_map( 'wp_strip_all_tags', $value ) ) );
			}
		}

		$value = is_string( $value ) ? trim( $value ) : ( $value ? (string) $value : '' );

		if ( $value === '' ) {
			$value = (string) $atts['fallback'];
		}

		$value = trim( $value );
		if ( $value === '' ) return '';

		$out = esc_html( $value );

		$wrap = strtolower( trim( (string) $atts['wrap'] ) );
		if ( $wrap !== '' ) {
			$wrap = preg_replace( '/[^a-z0-9:_-]/', '', $wrap );
			$class = trim( (string) $atts['class'] );
			$class_attr = $class !== '' ? ' class="' . esc_attr( $class ) . '"' : '';
			$out = '<' . $wrap . $class_attr . '>' . $out . '</' . $wrap . '>';
		}

		return $out;
	}
	add_shortcode( 'acf_field', 'myls_acf_field_shortcode' );
}

/**
 * Specific: [county_name] => ACF field "county"
 */
if ( ! function_exists( 'myls_county_name_shortcode' ) ) {
	function myls_county_name_shortcode( $atts ) {
		$atts = shortcode_atts(
			[
				'post_id'  => '',
				'fallback' => '',
				'wrap'     => '',
				'class'    => '',
			],
			$atts,
			'county_name'
		);

		return myls_acf_field_shortcode(
			[
				'field'    => 'county',
				'post_id'  => $atts['post_id'],
				'fallback' => $atts['fallback'],
				'wrap'     => $atts['wrap'],
				'class'    => $atts['class'],
			]
		);
	}
	add_shortcode( 'county_name', 'myls_county_name_shortcode' );
}
