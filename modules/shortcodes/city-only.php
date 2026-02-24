<?php
/**
 * Shortcode: [city_only]
 * (Function exists as ssseo_shortcode_city_only in your codebase)
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('ssseo_shortcode_city_only') ) {
  function ssseo_shortcode_city_only( $atts = [], $content = null, $tag = '' ) {

    $atts = shortcode_atts([
      'post_id'   => 0,
      'from'      => 'self',     // self|parent|ancestor
      'field'     => 'city_state',
      'delimiter' => ',',
      'fallback'  => '',
    ], $atts, $tag );

    $post_id = (int) $atts['post_id'];
    if ( $post_id <= 0 ) $post_id = get_the_ID();
    if ( ! $post_id ) return esc_html( $atts['fallback'] );

    $target_id = $post_id;
    if ( $atts['from'] === 'parent' || $atts['from'] === 'ancestor' ) {
      $ancestor = ($atts['from'] === 'parent')
        ? (int) get_post_field('post_parent', $post_id)
        : ssseo_city_only_find_nearest_ancestor_with_value( $post_id, $atts['field'] );

      if ( $ancestor > 0 ) $target_id = $ancestor;
    }

    $ckey = 'ssseo_city_only:' . md5( implode('|', [
      $target_id, $atts['field'], $atts['delimiter']
    ]) );

    $cached = wp_cache_get( $ckey, 'ssseo' );
    if ( is_string($cached) ) {
      return $cached !== '' ? $cached : esc_html( $atts['fallback'] );
    }

    $raw  = ssseo_city_only_read_field_value( $target_id, $atts['field'] );
    $city = ssseo_city_only_parse_city( $raw, $atts['delimiter'] );

    $city = apply_filters( 'ssseo_city_only_output', $city, [
      'raw'       => $raw,
      'target_id' => $target_id,
      'atts'      => $atts
    ]);

    $safe = is_string($city) ? $city : '';
    wp_cache_set( $ckey, $safe, 'ssseo', 5 * MINUTE_IN_SECONDS );

    if ( $safe === '' ) return esc_html( $atts['fallback'] );

    return esc_html( $safe );
  }
}

if ( ! function_exists('ssseo_city_only_read_field_value') ) {
  function ssseo_city_only_read_field_value( $post_id, $field_name ) {

    $normalize = function($v) {
      if ( is_array($v) ) {
        $flat = array_filter(array_map(function($x){
          if ( is_scalar($x) ) return (string) $x;
          if ( is_array($x) || is_object($x) ) return wp_json_encode($x);
          return '';
        }, $v));
        return implode(', ', $flat);
      }
      return is_scalar($v) ? (string) $v : '';
    };

    if ( function_exists('get_field') ) {
      $v = get_field( $field_name, $post_id, false );
      $s = $normalize( $v );
      if ( $s !== '' ) return $s;

      $v2 = get_field( $field_name, $post_id, true );
      $s2 = $normalize( $v2 );
      if ( $s2 !== '' ) return $s2;
    }

    $m = get_post_meta( $post_id, $field_name, true );
    return $normalize( $m );
  }
}

if ( ! function_exists('ssseo_city_only_parse_city') ) {
  function ssseo_city_only_parse_city( $value, $delimiter = ',' ) {
    $value = trim( (string) $value );
    if ( $value === '' ) return '';

    if ( $delimiter !== '' && strpos( $value, $delimiter ) !== false ) {
      $parts = explode( $delimiter, $value, 2 );
      return trim( $parts[0] );
    }

    if ( preg_match( '/^(.*?)[\s,]+([A-Z]{2})$/', $value, $m ) ) {
      $maybe_city = trim( $m[1] );
      if ( $maybe_city !== '' ) return $maybe_city;
    }

    return $value;
  }
}

if ( ! function_exists('ssseo_city_only_find_nearest_ancestor_with_value') ) {
  function ssseo_city_only_find_nearest_ancestor_with_value( $post_id, $field_name = 'city_state' ) {
    $seen = [];
    $pid  = (int) $post_id;

    while ( $pid > 0 && ! isset($seen[$pid]) ) {
      $seen[$pid] = true;

      $parent_id = (int) get_post_field( 'post_parent', $pid );
      if ( $parent_id <= 0 ) break;

      $val = ssseo_city_only_read_field_value( $parent_id, $field_name );
      if ( trim((string)$val) !== '' ) return $parent_id;

      $pid = $parent_id;
    }

    return 0;
  }
}

// Register shortcode tag (choose your preferred tag name)
add_shortcode( 'city_only', 'ssseo_shortcode_city_only' );
