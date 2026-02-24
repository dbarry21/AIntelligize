<?php
if ( ! defined('ABSPATH') ) exit;

function myls_get_city_state( int $post_id ) : string {

	$val = get_post_meta( $post_id, '_myls_city_state', true );
	if ( $val !== '' ) return $val;

	$legacy = get_post_meta( $post_id, 'city_state', true );
	return is_string($legacy) ? $legacy : '';
}

function myls_set_city_state( int $post_id, string $value ) : void {
	update_post_meta( $post_id, '_myls_city_state', sanitize_text_field($value) );
}
