<?php
/**
 * Shortcode: [service_grid]
 *
 * Features:
 * - Centers incomplete rows (great for last row with fewer items)
 * - Optional excerpt support (on by default in this refactor)
 * - Optional fixed image height w/ object-fit cover
 * - Optional "featured first card" layout (bigger first card)
 * - Flexible column count control (2, 3, 4, or 6 columns on desktop)
 *
 * Usage:
 *   [service_grid]                                       // defaults (4 columns, taglines)
 *   [service_grid columns="3"]                           // 3-column layout on desktop
 *   [service_grid columns="2"]                           // 2-column layout on desktop
 *   [service_grid columns="6"]                           // 6-column layout on desktop
 *   [service_grid subtext="excerpt"]                     // use excerpts instead of taglines
 *   [service_grid subtext="tagline"]                     // use taglines (default)
 *   [service_grid show_excerpt="0"]                      // hide subtext entirely
 *   [service_grid excerpt_words="18"]                    // change excerpt length
 *   [service_grid center="0"]                            // disable row centering
 *   [service_grid image_crop="1" image_height="220"]     // uniform image crop
 *   [service_grid featured_first="1"]                    // first item larger (6 cols on desktop)
 *   [service_grid aspect_ratio="1/1"]                    // square images (any CSS aspect-ratio value)
 *   [service_grid aspect_ratio="4/3"]                    // landscape images
 *   [service_grid aspect_ratio="3/4"]                    // portrait images
 *   
 * Column Options:
 *   columns="2"  // 2 per row on desktop (col-lg-6)
 *   columns="3"  // 3 per row on desktop (col-lg-4)
 *   columns="4"  // 4 per row on desktop (col-lg-3) - DEFAULT
 *   columns="6"  // 6 per row on desktop (col-lg-2)
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('ssseo_service_grid_shortcode_v2') ) {
  function ssseo_service_grid_shortcode_v2( $atts = [] ) {

    $a = shortcode_atts( [
      'posts_per_page' => -1,
      'orderby'        => 'menu_order title',
      'order'          => 'ASC',

      // Layout classes
      // NOTE: g-4 remains, but CSS will override gutters to 10px via --bs-gutter-x/y.
      'row_class'      => 'row g-4 justify-content-center',
      'col_class'      => 'col-md-6 col-lg-3 mb-4',
      'box_class'      => 'service-box h-100',
      'image_size'     => 'large',

      // Centering toggles
      'center'         => '1',  // 1|0 : adds justify-content-center to row
      'text_center'    => '0',  // 1|0 : adds text-center to box

      // Button
      'button'         => '1',
      'button_text'    => 'Learn More',
      'button_class'   => 'btn btn-primary mt-2',
      'button_target'  => '',   // e.g. _blank
      'button_rel'     => '',   // e.g. nofollow

      // Excerpt (NOW ON BY DEFAULT)
      'show_excerpt'   => '1',  // <-- changed default to 1
      'excerpt_words'  => '20',
      'subtext'        => 'tagline', // NEW: 'tagline'|'excerpt' - what to show below title
      'show_tagline'   => '1',  // NEW: show tagline between image and title (default: on)

      // Layout/style toggles (CSS-driven, no inline styles)
      'image_crop'     => '0',  // 1|0 : add class that crops images via CSS
      'image_height'   => '220',// used as CSS variable (--myls-img-h) in px
      'featured_first' => '0',  // 1|0 : first card spans wider columns (markup-level)
      
      // Column count control (NEW - replaces two_col)
      'columns'        => '4',  // 2|3|4|6 : number of columns on desktop (lg breakpoint)
      'two_col'        => '0',  // DEPRECATED: kept for backward compatibility, use columns="2" instead
      
      // Aspect ratio for images
      'aspect_ratio'   => '',   // e.g. '1/1', '4/3', '3/4', '16/9' – blank = natural ratio
    ], $atts, 'service_grid' );

    // Normalize row classes if centering disabled
    $row_class = trim((string) $a['row_class']);
    if ( $a['center'] !== '1' ) {
      $row_class = trim(preg_replace('/\bjustify-content-center\b/', '', $row_class));
    } else {
      if ( ! preg_match('/\bjustify-content-center\b/', $row_class) ) {
        $row_class .= ' justify-content-center';
      }
      $row_class = trim($row_class);
    }

    // Box class
    $box_class = trim((string) $a['box_class']);
    if ( $a['text_center'] === '1' && ! preg_match('/\btext-center\b/', $box_class) ) {
      $box_class .= ' text-center';
    }

    // Wrapper classes + CSS var for image height
    $wrap_classes = ['myls-service-grid'];
    if ( $a['image_crop'] === '1' ) $wrap_classes[] = 'myls-sg-crop';
    if ( $a['featured_first'] === '1' ) $wrap_classes[] = 'myls-sg-featured-first';
    
    // Backward compatibility: two_col="1" sets columns to 2
    if ( $a['two_col'] === '1' ) {
      $a['columns'] = '2';
    }
    
    // Validate and normalize columns value
    $columns = intval( $a['columns'] );
    $valid_columns = [2, 3, 4, 6]; // Bootstrap 12-column grid divisors
    if ( ! in_array( $columns, $valid_columns, true ) ) {
      $columns = 4; // default fallback
    }
    
    // Calculate Bootstrap column class for lg breakpoint
    // Bootstrap uses 12-column grid: 12/columns = col-lg-X
    $col_lg_num = 12 / $columns;
    
    // Add column-specific class to wrapper for targeting
    $wrap_classes[] = 'myls-sg-cols-' . $columns;
    
    // Aspect ratio support
    $aspect_ratio = sanitize_text_field( trim( $a['aspect_ratio'] ) );
    if ( $aspect_ratio ) {
      $wrap_classes[] = 'myls-sg-has-ratio';
    }

    $img_h = max( 80, (int) $a['image_height'] ); // safety min

    // Query services
    $q = new WP_Query( [
      'post_type'      => 'service',
      'posts_per_page' => intval( $a['posts_per_page'] ),
      'post_status'    => 'publish',
      'orderby'        => $a['orderby'],
      'order'          => $a['order'],
      'no_found_rows'  => true,
    ] );

    ob_start();

    if ( $q->have_posts() ) {

      // ------ Inline CSS (printed once) ------
      static $css_printed = false;
      if ( ! $css_printed ) {
        $css_printed = true;
        echo '<style>
/* === AIntelligize – Service Grid === */
.myls-service-grid{--bs-gutter-x:10px;--bs-gutter-y:10px}
.myls-service-grid .service-box{display:flex;flex-direction:column}
.myls-service-grid .myls-sg-img-link{display:block;overflow:hidden;border-radius:.375rem}
.myls-service-grid .myls-sg-img{width:100%;height:auto;display:block;border-radius:.375rem;transition:transform .3s ease}
.myls-service-grid .myls-sg-img-link:hover .myls-sg-img{transform:scale(1.03)}

/* Aspect-ratio mode */
.myls-sg-has-ratio .myls-sg-img{aspect-ratio:var(--myls-sg-ratio,auto);object-fit:cover;height:auto!important}

/* Cropped-image mode (fixed height) */
.myls-sg-crop .myls-sg-img{height:var(--myls-img-h,220px);object-fit:cover}

/* Title links */
.myls-service-grid .myls-sg-title a{text-decoration:none;color:inherit}
.myls-service-grid .myls-sg-title a:hover{opacity:.8}

/* Tagline / excerpt */
.myls-service-grid .myls-sg-tagline,
.myls-service-grid .myls-sg-excerpt{font-size:.92em;opacity:.85}

/* Featured first card */
.myls-sg-featured-first .col-md-6.col-lg-6:first-child .myls-sg-img{min-height:280px;object-fit:cover}
</style>';
      }

      // Build inline style with CSS variables
      $inline_vars = '--myls-img-h:' . esc_attr($img_h) . 'px;';
      if ( $aspect_ratio ) {
        $inline_vars .= '--myls-sg-ratio:' . esc_attr($aspect_ratio) . ';';
      }

      // Use a CSS variable for image height without hardcoding in CSS
      echo '<div class="' . esc_attr( implode(' ', $wrap_classes ) ) . '" style="' . $inline_vars . '">';
      echo '<div class="' . esc_attr( $row_class ) . '">';

      $i = 0;

      while ( $q->have_posts() ) {
        $q->the_post();
        $i++;

        $post_id   = get_the_ID();
        $title     = get_the_title();
        $permalink = get_permalink();
        // Icon Image → fallback to Featured Image
        $thumb_url = function_exists( 'myls_get_icon_image_url' )
            ? myls_get_icon_image_url( $post_id, $a['image_size'] )
            : get_the_post_thumbnail_url( $post_id, $a['image_size'] );

        // Determine column class per-item
        // Default: responsive sizing based on columns attribute
        $col_class = 'col-md-6 col-lg-' . $col_lg_num . ' mb-4';
        
        // Override for featured first card (if enabled)
        if ( $a['featured_first'] === '1' && $i === 1 ) {
          // First card spans wider: half width on md, full 6 columns on lg
          $col_class = 'col-md-6 col-lg-6 mb-4';
        }

        echo '<div class="' . esc_attr( $col_class ) . '">';
        echo   '<div class="' . esc_attr( $box_class ) . '">';

        if ( $thumb_url ) {
          echo '<a href="' . esc_url( $permalink ) . '" class="myls-sg-img-link">';
          echo '<img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( $title ) . '" class="img-fluid mb-3 rounded myls-sg-img" loading="lazy" decoding="async">';
          echo '</a>';
        }

        echo '<h4 class="mb-2 myls-sg-title"><a href="' . esc_url( $permalink ) . '">' . esc_html( $title ) . '</a></h4>';

        // Subtext: Tagline or Excerpt
        if ( $a['show_excerpt'] === '1' ) {
          $subtext = '';
          
          // Use tagline if available and subtext mode is tagline
          if ( $a['subtext'] === 'tagline' ) {
            $tagline = get_post_meta( $post_id, '_myls_service_tagline', true );
            if ( ! empty( $tagline ) ) {
              $subtext = $tagline;
            }
          }
          
          // Fallback to excerpt if no tagline or if subtext mode is excerpt
          if ( empty( $subtext ) ) {
            $excerpt = get_the_excerpt( $post_id );

            // If empty excerpt, fallback to trimmed content
            if ( ! $excerpt ) {
              $excerpt = wp_trim_words(
                wp_strip_all_tags( strip_shortcodes( get_post_field( 'post_content', $post_id ) ) ),
                max( 1, intval( $a['excerpt_words'] ) )
              );
            }
            
            $subtext = $excerpt;
          }

          // Output only if we have content
          if ( $subtext ) {
            $class = $a['subtext'] === 'tagline' ? 'myls-sg-tagline' : 'myls-sg-excerpt';
            echo '<p class="mb-2 ' . esc_attr( $class ) . '">' . esc_html( $subtext ) . '</p>';
          }
        }

        if ( $a['button'] === '1' ) {
          echo '<a href="' . esc_url( $permalink ) . '"'
            . ( $a['button_class'] ? ' class="' . esc_attr( $a['button_class'] ) . ' myls-sg-btn"' : ' class="myls-sg-btn"' )
            . ( $a['button_target'] ? ' target="' . esc_attr( $a['button_target'] ) . '"' : '' )
            . ( $a['button_rel'] ? ' rel="' . esc_attr( $a['button_rel'] ) . '"' : '' )
            . '>' . esc_html( $a['button_text'] ) . '</a>';
        }

        echo   '</div>';
        echo '</div>';
      }

      echo '</div>';
      echo '</div>';

      wp_reset_postdata();

    } else {
      echo '<p>No services found.</p>';
    }

    return ob_get_clean();
  }
}

add_shortcode( 'service_grid', 'ssseo_service_grid_shortcode_v2' );
