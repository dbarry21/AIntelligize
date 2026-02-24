<?php
/**
 * Shortcode: [city_state]
 */
if ( ! defined('ABSPATH') ) exit;

/* ── Helper: read a meta / ACF field value as a string ────────────── */
if ( ! function_exists('ssseo_city_value_read_field') ) {
  function ssseo_city_value_read_field( $post_id, $field_name ) {
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

/* ── Helper: normalize "city, state" formatting ───────────────────── */
if ( ! function_exists('ssseo_city_state_normalize') ) {
  function ssseo_city_state_normalize( $value, $delimiter = ',', $do_normalize = false, $state_upper = false ) {
    $value = trim( (string) $value );
    if ( $value === '' ) return '';
    if ( ! $do_normalize ) return $value;

    // Split on the delimiter
    if ( $delimiter !== '' && strpos( $value, $delimiter ) !== false ) {
      $parts = array_map( 'trim', explode( $delimiter, $value, 2 ) );
    } else {
      // Try to split "City ST" by last whitespace + 2-letter state
      if ( preg_match( '/^(.*?)[\s,]+([A-Z]{2})$/i', $value, $m ) ) {
        $parts = [ trim($m[1]), trim($m[2]) ];
      } else {
        return $value;
      }
    }

    // Capitalize city portion
    $city = ucwords( strtolower( $parts[0] ) );

    if ( isset($parts[1]) && $parts[1] !== '' ) {
      $state = $state_upper ? strtoupper( $parts[1] ) : $parts[1];
      return $city . ', ' . $state;
    }

    return $city;
  }
}

/* ── Helper: walk ancestors to find one with a field value ────────── */
if ( ! function_exists('ssseo_city_value_find_nearest_ancestor_with_value') ) {
  function ssseo_city_value_find_nearest_ancestor_with_value( $post_id, $field_name = 'city_state' ) {
    $seen = [];
    $pid  = (int) $post_id;

    while ( $pid > 0 && ! isset($seen[$pid]) ) {
      $seen[$pid] = true;
      $parent_id  = (int) get_post_field( 'post_parent', $pid );
      if ( $parent_id <= 0 ) break;

      $val = ssseo_city_value_read_field( $parent_id, $field_name );
      if ( trim((string)$val) !== '' ) return $parent_id;

      $pid = $parent_id;
    }
    return 0;
  }
}

/* ── Shortcode callback ───────────────────────────────────────────── */
if ( ! function_exists('ssseo_shortcode_city_state') ) {
  function ssseo_shortcode_city_state( $atts = [], $content = null, $tag = '' ) {

    $atts = shortcode_atts([
      'post_id'     => 0,
      'from'        => 'self',      // self|parent|ancestor
      'field'       => 'city_state',
      'delimiter'   => ',',
      'normalize'   => 0,
      'state_upper' => 0,
      'fallback'    => '',

      // ✅ NEW: formatting helpers
      'prefix'      => '',
      'suffix'      => '',
    ], $atts, $tag );

    $post_id = (int) $atts['post_id'];
    if ( $post_id <= 0 ) $post_id = get_the_ID();
    if ( ! $post_id ) return esc_html( (string) $atts['fallback'] );

    $target_id = $post_id;
    if ( $atts['from'] === 'parent' || $atts['from'] === 'ancestor' ) {
      $ancestor = ($atts['from'] === 'parent')
        ? (int) get_post_field('post_parent', $post_id)
        : (int) ssseo_city_value_find_nearest_ancestor_with_value( $post_id, $atts['field'] );

      if ( $ancestor > 0 ) $target_id = $ancestor;
    }

    // Cache only the BASE normalized city/state (not prefix/suffix)
    $ckey = 'ssseo_city_state:' . md5( implode('|', [
      $target_id, $atts['field'], $atts['delimiter'],
      (int) !empty($atts['normalize']), (int) !empty($atts['state_upper'])
    ]) );

    $cached = wp_cache_get( $ckey, 'ssseo' );
    if ( is_string($cached) ) {
      $base = trim($cached);
      if ( $base === '' ) return esc_html( (string) $atts['fallback'] );

      $out = (string) $atts['prefix'] . $base . (string) $atts['suffix'];
      return esc_html( $out );
    }

    $raw = ssseo_city_value_read_field( $target_id, $atts['field'] );

    $val = ssseo_city_state_normalize(
      $raw,
      $atts['delimiter'],
      !empty($atts['normalize']),
      !empty($atts['state_upper'])
    );

    $val = apply_filters('ssseo_city_state_output', $val, [
      'raw'       => $raw,
      'target_id' => $target_id,
      'atts'      => $atts,
    ]);

    $base = is_string($val) ? trim($val) : '';
    wp_cache_set( $ckey, $base, 'ssseo', 5 * MINUTE_IN_SECONDS );

    if ( $base === '' ) return esc_html( (string) $atts['fallback'] );

    $out = (string) $atts['prefix'] . $base . (string) $atts['suffix'];
    return esc_html( $out );
  }
}

// Register shortcode tag
add_shortcode( 'city_state', 'ssseo_shortcode_city_state' );
