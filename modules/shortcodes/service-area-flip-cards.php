<?php
/**
 * Shortcode: [service_area_flip_cards]
 *
 * On a parent service_area page: shows child service_area posts.
 * On a child  service_area page: shows sibling posts (excluding self).
 *
 * Uses the CSS-grid flip-box card layout (same as [myls_card_grid]).
 *
 * Usage:
 *   [service_area_flip_cards]                              // auto-detect context
 *   [service_area_flip_cards button_text="View Area"]      // custom button text
 *   [service_area_flip_cards image_size="medium_large"]    // image size
 *   [service_area_flip_cards use_icons="1" icon_class="fa fa-map-marker"]
 *   [service_area_flip_cards desktop_columns="3"]          // 3 columns on desktop
 *   [service_area_flip_cards empty_text="No areas found."]
 *   [service_area_flip_cards orderby="menu_order title" order="ASC"]
 *
 * @since 7.8.99
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('ssseo_service_area_flip_cards_shortcode') ) {
  function ssseo_service_area_flip_cards_shortcode( $atts = [] ) {

    $a = shortcode_atts( [
      'post_id'          => 0,
      'orderby'          => 'title',
      'order'            => 'ASC',
      'button_text'      => 'Learn More',
      'image_size'       => 'medium_large',
      'use_icons'        => '0',
      'icon_class'       => 'fa fa-map-marker',
      'show_excerpt'     => '1',
      'excerpt_words'    => '24',

      // Grid columns (CSS vars)
      'mobile_columns'   => '1',
      'tablet_columns'   => '2',
      'desktop_columns'  => '3',
      'wide_columns'     => '4',
      'gap'              => '1rem',

      // Empty state
      'empty_text'       => '',

      // Wrapper
      'wrapper_class'    => '',
    ], $atts, 'service_area_flip_cards' );

    // Determine current post
    $post_id = (int) $a['post_id'];
    if ( $post_id <= 0 ) $post_id = (int) get_the_ID();
    if ( $post_id <= 0 ) return esc_html( $a['empty_text'] );

    // Only works on service_area posts
    if ( get_post_type( $post_id ) !== 'service_area' ) {
      return esc_html( $a['empty_text'] );
    }

    $parent_id = (int) get_post_field( 'post_parent', $post_id );

    // Determine query parent:
    //   - If current post HAS children → it's a parent → show its children
    //   - If current post has a parent → it's a child → show siblings
    $is_parent    = false;
    $query_parent = 0;

    $children_check = get_posts( [
      'post_type'      => 'service_area',
      'post_status'    => 'publish',
      'post_parent'    => $post_id,
      'posts_per_page' => 1,
      'no_found_rows'  => true,
      'fields'         => 'ids',
    ] );

    if ( ! empty( $children_check ) ) {
      $is_parent    = true;
      $query_parent = $post_id;
    } elseif ( $parent_id > 0 ) {
      $query_parent = $parent_id;
    } else {
      return esc_html( $a['empty_text'] );
    }

    $orderby = sanitize_text_field( $a['orderby'] );
    $order   = ( strtoupper( $a['order'] ) === 'DESC' ) ? 'DESC' : 'ASC';

    $posts = get_posts( [
      'post_type'        => 'service_area',
      'post_status'      => 'publish',
      'post_parent'      => $query_parent,
      'posts_per_page'   => -1,
      'orderby'          => $orderby,
      'order'            => $order,
      'no_found_rows'    => true,
      'suppress_filters' => true,
    ] );

    // Exclude self when showing siblings
    if ( ! $is_parent ) {
      $posts = array_filter( $posts, function( $p ) use ( $post_id ) {
        return (int) $p->ID !== $post_id;
      } );
      $posts = array_values( $posts );
    }

    if ( empty( $posts ) ) {
      return esc_html( $a['empty_text'] );
    }

    // Enqueue CSS
    $css_rel  = 'aintelligize/assets/frontend.css';
    $css_src  = plugins_url( $css_rel );
    $css_path = WP_PLUGIN_DIR . '/' . $css_rel;
    $css_ver  = file_exists( $css_path ) ? filemtime( $css_path ) : null;
    wp_register_style( 'myls-frontend', $css_src, [], $css_ver );
    wp_enqueue_style( 'myls-frontend' );

    $use_icons    = ( $a['use_icons'] === '1' );
    $show_excerpt = ( $a['show_excerpt'] === '1' );
    $max_words    = max( 1, (int) $a['excerpt_words'] );

    $extra_class = trim( preg_replace( '/[^A-Za-z0-9 _-]/', '', (string) $a['wrapper_class'] ) );

    // CSS grid vars
    $grid_style = sprintf(
      '--gap:%s;--mobile-columns:%s;--tablet-columns:%s;--desktop-columns:%s;--wide-columns:%s;',
      esc_attr( $a['gap'] ),
      esc_attr( $a['mobile_columns'] ),
      esc_attr( $a['tablet_columns'] ),
      esc_attr( $a['desktop_columns'] ),
      esc_attr( $a['wide_columns'] )
    );

    $grid_classes = 'myls-grid myls-sa-flip-cards';
    if ( $extra_class ) $grid_classes .= ' ' . $extra_class;

    ob_start();

    echo '<div class="' . esc_attr( $grid_classes ) . '" style="' . $grid_style . '">';

    foreach ( $posts as $p ) {
      $pid       = (int) $p->ID;
      $title     = get_the_title( $pid );
      $permalink = get_permalink( $pid );
      $thumb     = has_post_thumbnail( $pid )
        ? get_the_post_thumbnail( $pid, $a['image_size'], [ 'class' => 'img-fluid', 'alt' => $title ] )
        : '';

      echo '<div class="myls-flip-box">';
      echo   '<article class="myls-card">';

      if ( $thumb ) {
        echo '<a class="card-media" href="' . esc_url( $permalink ) . '" aria-label="' . esc_attr( $title ) . '">';
        echo $thumb;
        echo '</a>';
      } elseif ( $use_icons ) {
        echo '<div class="card-media d-flex align-items-center justify-content-center">';
        echo '<i class="' . esc_attr( $a['icon_class'] ) . '" aria-hidden="true"></i>';
        echo '<span class="visually-hidden">' . esc_html( $title ) . '</span>';
        echo '</div>';
      }

      echo '<div class="card-body">';
      echo   '<h3 class="flip-title"><a href="' . esc_url( $permalink ) . '">' . esc_html( $title ) . '</a></h3>';

      if ( $show_excerpt ) {
        $excerpt = trim( (string) get_post_field( 'post_excerpt', $pid ) );
        if ( $excerpt === '' ) {
          $excerpt = wp_trim_words(
            wp_strip_all_tags( strip_shortcodes( get_post_field( 'post_content', $pid ) ) ),
            $max_words
          );
        } else {
          $excerpt = wp_trim_words( $excerpt, $max_words );
        }
        if ( $excerpt ) {
          echo '<div class="flip-excerpt">' . wp_kses_post( $excerpt ) . '</div>';
        }
      }

      echo '<a class="flip-button btn btn-primary" href="' . esc_url( $permalink ) . '">'
        . esc_html( $a['button_text'] ) . '</a>';

      echo '</div>'; // .card-body
      echo   '</article>';
      echo '</div>'; // .myls-flip-box
    }

    echo '</div>'; // .myls-grid

    return ob_get_clean();
  }
}

add_shortcode( 'service_area_flip_cards', 'ssseo_service_area_flip_cards_shortcode' );
