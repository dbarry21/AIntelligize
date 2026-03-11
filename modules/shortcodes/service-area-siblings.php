<?php
/**
 * Shortcode: [service_area_siblings]
 *
 * On a parent service_area page: shows child service_area posts.
 * On a child  service_area page: shows sibling posts (excluding self).
 *
 * Uses the same Bootstrap card-grid layout as [service_grid].
 *
 * Usage:
 *   [service_area_siblings]                          // auto-detect parent/child context
 *   [service_area_siblings columns="3"]              // 3 columns on desktop
 *   [service_area_siblings button="1"]               // show Learn More button
 *   [service_area_siblings show_excerpt="0"]         // hide excerpts
 *   [service_area_siblings orderby="title" order="ASC"]
 *   [service_area_siblings empty_text="No areas found."]
 *
 * @since 7.8.99
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('ssseo_service_area_siblings_shortcode') ) {
  function ssseo_service_area_siblings_shortcode( $atts = [] ) {

    $a = shortcode_atts( [
      'post_id'        => 0,
      'orderby'        => 'title',
      'order'          => 'ASC',
      'columns'        => '4',
      'image_size'     => 'large',

      // Centering
      'center'         => '1',

      // Button
      'button'         => '0',
      'button_text'    => 'Learn More',
      'button_class'   => 'btn btn-primary mt-2',

      // Excerpt
      'show_excerpt'   => '1',
      'excerpt_words'  => '20',

      // Image crop
      'image_crop'     => '0',
      'image_height'   => '220',
      'aspect_ratio'   => '',

      // Empty state
      'empty_text'     => '',

      // Wrapper
      'wrapper_class'  => '',
    ], $atts, 'service_area_siblings' );

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
    $is_parent = false;
    $query_parent = 0;

    // Check if current post has children
    $children_check = get_posts( [
      'post_type'      => 'service_area',
      'post_status'    => 'publish',
      'post_parent'    => $post_id,
      'posts_per_page' => 1,
      'no_found_rows'  => true,
      'fields'         => 'ids',
    ] );

    if ( ! empty( $children_check ) ) {
      // Current post is a parent — show its children
      $is_parent    = true;
      $query_parent = $post_id;
    } elseif ( $parent_id > 0 ) {
      // Current post is a child — show siblings
      $query_parent = $parent_id;
    } else {
      // No children, no parent — nothing to show
      return esc_html( $a['empty_text'] );
    }

    $orderby = sanitize_key( $a['orderby'] );
    $order   = ( strtoupper( $a['order'] ) === 'DESC' ) ? 'DESC' : 'ASC';

    $posts = get_posts( [
      'post_type'      => 'service_area',
      'post_status'    => 'publish',
      'post_parent'    => $query_parent,
      'posts_per_page' => -1,
      'orderby'        => $orderby,
      'order'          => $order,
      'no_found_rows'  => true,
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

    // Validate columns
    $columns = (int) $a['columns'];
    $valid   = [ 2, 3, 4, 6 ];
    if ( ! in_array( $columns, $valid, true ) ) $columns = 4;
    $col_lg = 12 / $columns;

    // Build wrapper classes
    $wrap_classes = [ 'myls-service-grid', 'myls-sa-siblings' ];
    if ( $a['image_crop'] === '1' ) $wrap_classes[] = 'myls-sg-crop';
    $wrap_classes[] = 'myls-sg-cols-' . $columns;

    $aspect_ratio = sanitize_text_field( trim( $a['aspect_ratio'] ) );
    if ( $aspect_ratio ) $wrap_classes[] = 'myls-sg-has-ratio';

    $extra_class = trim( preg_replace( '/[^A-Za-z0-9 _-]/', '', (string) $a['wrapper_class'] ) );
    if ( $extra_class ) $wrap_classes[] = $extra_class;

    $img_h = max( 80, (int) $a['image_height'] );

    // Row classes
    $row_class = 'row g-4';
    if ( $a['center'] === '1' ) $row_class .= ' justify-content-center';

    // Enqueue CSS
    wp_enqueue_style( 'myls-frontend', plugins_url( 'assets/frontend.css', MYLS_MAIN_FILE ), [], MYLS_VERSION );

    // Inline CSS vars
    $inline_vars = '--myls-img-h:' . esc_attr( $img_h ) . 'px;';
    if ( $aspect_ratio ) {
      $inline_vars .= '--myls-sg-ratio:' . esc_attr( $aspect_ratio ) . ';';
    }

    ob_start();

    echo '<div class="' . esc_attr( implode( ' ', $wrap_classes ) ) . '" style="' . $inline_vars . '">';
    echo '<div class="' . esc_attr( $row_class ) . '">';

    foreach ( $posts as $p ) {
      $pid       = (int) $p->ID;
      $title     = get_the_title( $pid );
      $permalink = get_permalink( $pid );
      $thumb_url = get_the_post_thumbnail_url( $pid, $a['image_size'] );

      $col_class = 'col-md-6 col-lg-' . $col_lg . ' mb-4';

      echo '<div class="' . esc_attr( $col_class ) . '">';
      echo   '<div class="service-box h-100">';

      if ( $thumb_url ) {
        echo '<a href="' . esc_url( $permalink ) . '" class="myls-sg-img-link">';
        echo '<img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( $title ) . '" class="img-fluid mb-3 rounded myls-sg-img" loading="lazy" decoding="async">';
        echo '</a>';
      }

      echo '<h4 class="mb-2 myls-sg-title"><a href="' . esc_url( $permalink ) . '">' . esc_html( $title ) . '</a></h4>';

      // Excerpt
      if ( $a['show_excerpt'] === '1' ) {
        $excerpt = get_the_excerpt( $pid );
        if ( ! $excerpt ) {
          $excerpt = wp_trim_words(
            wp_strip_all_tags( strip_shortcodes( get_post_field( 'post_content', $pid ) ) ),
            max( 1, (int) $a['excerpt_words'] )
          );
        }
        if ( $excerpt ) {
          echo '<p class="mb-2 myls-sg-excerpt">' . esc_html( $excerpt ) . '</p>';
        }
      }

      if ( $a['button'] === '1' ) {
        echo '<a href="' . esc_url( $permalink ) . '" class="' . esc_attr( $a['button_class'] ) . ' myls-sg-btn">'
          . esc_html( $a['button_text'] ) . '</a>';
      }

      echo   '</div>';
      echo '</div>';
    }

    echo '</div>';
    echo '</div>';

    return ob_get_clean();
  }
}

add_shortcode( 'service_area_siblings', 'ssseo_service_area_siblings_shortcode' );
