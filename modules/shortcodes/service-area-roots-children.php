<?php
/**
 * Shortcode: [service_area_roots_children]
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists( 'ssseo_service_area_roots_children_shortcode' ) ) {
  function ssseo_service_area_roots_children_shortcode( $atts = [] ) {

    $atts = shortcode_atts( [
      'hide_empty'    => 'no',
      'wrapper_class' => '',
    ], $atts, 'service_area_roots_children' );

    $hide_empty    = ( 'yes' === strtolower( (string) $atts['hide_empty'] ) );
    $wrapper_class = trim( preg_replace( '/[^A-Za-z0-9 _-]/', '', (string) $atts['wrapper_class'] ) );

    $roots = get_posts( [
      'post_type'        => 'service_area',
      'post_status'      => 'publish',
      'posts_per_page'   => -1,
      'post_parent'      => 0,
      'orderby'          => 'title',
      'order'            => 'ASC',
      'suppress_filters' => true,
      'no_found_rows'    => true,
    ] );

    if ( empty( $roots ) ) {
      return '<p class="service-area-list" style="margin:0;">' . esc_html__( 'No top-level service areas found.', 'ssseo' ) . '</p>';
    }

    $root_ids = wp_list_pluck( $roots, 'ID' );

    $children = get_posts( [
      'post_type'        => 'service_area',
      'post_status'      => 'publish',
      'posts_per_page'   => -1,
      'post_parent__in'  => array_map( 'intval', $root_ids ),
      'orderby'          => 'title',
      'order'            => 'ASC',
      'suppress_filters' => true,
      'no_found_rows'    => true,
    ] );

    $children_by_parent = [];
    foreach ( $children as $child ) {
      $pid = (int) $child->post_parent;
      if ( ! isset( $children_by_parent[ $pid ] ) ) $children_by_parent[ $pid ] = [];
      $children_by_parent[ $pid ][] = $child;
    }

    $wrapper_classes = trim( 'container service-areas ' . $wrapper_class );

    $out  = '<div class="' . esc_attr( $wrapper_classes ) . '"><div class="row">';
    $out .= '<div class="col-lg-12">';
    $out .= '<ul class="service-area-list list-unstyled">';

    foreach ( $roots as $root ) {
      $root_id    = (int) $root->ID;
      $root_title = get_the_title( $root_id );
      $root_link  = get_permalink( $root_id );

      $root_children = $children_by_parent[ $root_id ] ?? [];

      if ( $hide_empty && empty( $root_children ) ) continue;

      $out .= '<li class="service-area-child">';
      $out .= '  <i class="fa fa-map-marker ssseo-icon"></i>';
      $out .= '  <a class="service-area-root-link" href="' . esc_url( $root_link ) . '">' . esc_html( $root_title ) . '</a>';
      $out .= '</li>';

      if ( ! empty( $root_children ) ) {
        foreach ( $root_children as $child ) {
          $child_id    = (int) $child->ID;
          $child_title = get_the_title( $child_id );
          $child_link  = get_permalink( $child_id );

          $out .= '<li class="service-area-child">';
          $out .= '  <i class="fa fa-map-marker ssseo-icon"></i>';
          $out .= '  <a class="service-area-link" href="' . esc_url( $child_link ) . '">' . esc_html( $child_title ) . '</a>';
          $out .= '</li>';
        }
      }
    }

    $out .= '</ul>';
    $out .= '</div></div></div>';

    return $out;
  }
}

add_shortcode( 'service_area_roots_children', 'ssseo_service_area_roots_children_shortcode' );
