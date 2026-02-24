<?php
/**
 * Shortcodes:
 * - [yoast_title]
 * - [seo_title] (alias)
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('ssseo_get_yoast_seo_title') ) {
  function ssseo_get_yoast_seo_title( $post_id = 0 ) {
    $post_id = $post_id ? (int) $post_id : (int) get_queried_object_id();
    if ( ! $post_id ) return get_bloginfo('name');

    if ( ! function_exists('wpseo_replace_vars') ) {
      return get_the_title( $post_id );
    }

    $template = (string) get_post_meta( $post_id, '_yoast_wpseo_title', true );

    if ( $template === '' ) {
      $opts = (array) get_option('wpseo_titles', []);
      $pt   = get_post_type( $post_id );
      $key  = "post_types-{$pt}-title";
      if ( ! empty( $opts[$key] ) ) $template = (string) $opts[$key];
    }

    if ( $template === '' ) $template = '%%title%% %%page%% %%sep%% %%sitename%%';

    $post  = get_post( $post_id );
    $title = wpseo_replace_vars( $template, $post );

    if ( ! is_string($title) || $title === '' ) $title = get_the_title( $post_id );

    return $title;
  }
}

if ( ! function_exists('ssseo_yoast_title_shortcode') ) {
  function ssseo_yoast_title_shortcode( $atts = [] ) {
    $a = shortcode_atts( [
      'post_id' => '',
      'wrap'    => '0',
      'before'  => '',
      'after'   => '',
    ], $atts, 'yoast_title' );

    $post_id = (int) $a['post_id'];
    $title   = ssseo_get_yoast_seo_title( $post_id );

    $out = $a['before'] . $title . $a['after'];

    if ( $a['wrap'] === '1' ) {
      return '<span class="yoast-title">' . esc_html( $out ) . '</span>';
    }

    return esc_html( $out );
  }
}

add_shortcode( 'yoast_title', 'ssseo_yoast_title_shortcode' );
add_shortcode( 'seo_title',   'ssseo_yoast_title_shortcode' );
