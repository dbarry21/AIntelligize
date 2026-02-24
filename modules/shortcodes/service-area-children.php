<?php
/**
 * Shortcode: [service_area_children]
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists( 'ssseo_service_area_children_shortcode' ) ) {
  function ssseo_service_area_children_shortcode( $atts = [] ) {

    $atts = shortcode_atts( [
      'parent_id'     => 0,
      'orderby'       => 'title',
      'order'         => 'ASC',
      'show_parent'   => 'no',
      'wrapper_class' => '',
      'list_class'    => 'list-unstyled service-area-list',
      'empty_text'    => 'No service areas found.',
    ], $atts, 'service_area_children' );

    $parent_id     = (int) $atts['parent_id'];
    $orderby       = sanitize_key( $atts['orderby'] );
    $order         = ( strtoupper( $atts['order'] ) === 'DESC' ) ? 'DESC' : 'ASC';
    $show_parent   = ( strtolower( $atts['show_parent'] ) === 'yes' );
    $wrapper_class = trim( preg_replace( '/[^A-Za-z0-9 _-]/', '', (string) $atts['wrapper_class'] ) );
    $list_class    = trim( preg_replace( '/[^A-Za-z0-9 _-]/', '', (string) $atts['list_class'] ) );
    $empty_text    = wp_kses_post( $atts['empty_text'] );

    if ( $parent_id <= 0 && is_singular( 'service_area' ) ) {
      $parent_id = (int) get_the_ID();
    }

    if ( $parent_id <= 0 ) {
      return '<!-- [service_area_children]: No parent context available. Provide parent_id or place on a single service_area. -->';
    }

    $children = get_posts( [
      'post_type'        => 'service_area',
      'post_status'      => 'publish',
      'posts_per_page'   => -1,
      'post_parent'      => $parent_id,
      'orderby'          => $orderby,
      'order'            => $order,
      'no_found_rows'    => true,
      'suppress_filters' => true,
    ] );

    if ( empty( $children ) ) {
      return '<p class="service-area-list-empty" style="margin:0;">' . $empty_text . '</p>';
    }

    $wrapper_classes = trim( 'container service-areas ' . $wrapper_class );

    $out  = '<div class="' . esc_attr( $wrapper_classes ) . '"><div class="row">';
    $out .= '<div class="col-lg-12">';

    if ( $show_parent ) {
      $parent_title = get_the_title( $parent_id );
      $parent_link  = get_permalink( $parent_id );
      $out .= '<div class="service-area-parent mb-2">';
      $out .= '  <a class="service-area-parent-link fw-semibold" href="' . esc_url( $parent_link ) . '">' . esc_html( $parent_title ) . '</a>';
      $out .= '</div>';
    }

    $out .= '<ul class="' . esc_attr( $list_class ) . '">';

    foreach ( $children as $child ) {
      $child_id    = (int) $child->ID;
      $child_title = get_the_title( $child_id );
      $child_link  = get_permalink( $child_id );

      $out .= '<li class="service-area-child">';
      $out .= '  <i class="fa fa-map-marker ssseo-icon"></i>';
      $out .= '  <a class="service-area-link" href="' . esc_url( $child_link ) . '">' . esc_html( $child_title ) . '</a>';
      $out .= '</li>';
    }

    $out .= '</ul>';
    $out .= '</div></div></div>';

    return $out;
  }
}

add_shortcode( 'service_area_children', 'ssseo_service_area_children_shortcode' );
