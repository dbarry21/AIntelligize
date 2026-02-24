<?php
/**
 * Shortcode: [google_reviews_slider]
 *
 * Pulls Google reviews via Places API and displays them in a
 * glassmorphism-styled Swiper slider with star ratings.
 *
 * Requirements:
 *   - Google Places API key saved in plugin settings (myls_google_places_api_key)
 *   - Google Place ID saved in plugin settings (myls_google_places_place_id)
 *
 * Usage:
 *   [google_reviews_slider]                                 // defaults
 *   [google_reviews_slider place_id="ChIJ..."]              // override place ID
 *   [google_reviews_slider min_rating="4"]                   // only show 4+ star reviews
 *   [google_reviews_slider max_reviews="5"]                  // limit number of reviews
 *   [google_reviews_slider cache_hours="24"]                 // cache duration
 *   [google_reviews_slider speed="5000"]                     // autoplay speed in ms
 *   [google_reviews_slider blur="14"]                        // backdrop blur in px
 *   [google_reviews_slider overlay_opacity="0.12"]           // glass overlay opacity
 *   [google_reviews_slider star_color="#FFD700"]             // star color
 *   [google_reviews_slider text_color="#ffffff"]             // review text color
 *   [google_reviews_slider sort="newest"]                    // newest | highest | default (Google's relevance)
 *   [google_reviews_slider excerpt_words="0"]                // 0 = full text, or limit words
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('myls_google_reviews_slider_shortcode') ) {

  /**
   * Fetch reviews from Google Places API with transient caching.
   */
  function myls_google_reviews_fetch( $api_key, $place_id, $cache_hours = 24 ) {

    $transient_key = 'myls_greviews_' . md5( $place_id );
    $cached = get_transient( $transient_key );

    if ( false !== $cached ) {
      return $cached;
    }

    $url = add_query_arg( [
      'place_id' => $place_id,
      'fields'   => 'reviews,rating,user_ratings_total',
      'key'      => $api_key,
    ], 'https://maps.googleapis.com/maps/api/place/details/json' );

    $response = wp_remote_get( $url, [ 'timeout' => 10 ] );

    if ( is_wp_error( $response ) ) {
      return new WP_Error( 'api_error', $response->get_error_message() );
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( empty( $body['result'] ) ) {
      $status = $body['status'] ?? 'UNKNOWN';
      return new WP_Error( 'api_error', 'Google Places API error: ' . $status );
    }

    $data = [
      'reviews'            => $body['result']['reviews'] ?? [],
      'rating'             => $body['result']['rating'] ?? 0,
      'user_ratings_total' => $body['result']['user_ratings_total'] ?? 0,
    ];

    set_transient( $transient_key, $data, absint( $cache_hours ) * HOUR_IN_SECONDS );

    return $data;
  }

  /**
   * Render star icons.
   */
  function myls_render_stars( $rating, $color = '#FFD700' ) {
    $full  = floor( $rating );
    $half  = ( $rating - $full ) >= 0.25 ? 1 : 0;
    $empty = 5 - $full - $half;
    $html  = '<span class="myls-gr-stars" style="color:' . esc_attr( $color ) . ';" aria-label="' . esc_attr( $rating . ' out of 5 stars' ) . '">';
    $html .= str_repeat( '★', $full );
    if ( $half ) $html .= '<span style="opacity:.55;">★</span>';
    $html .= str_repeat( '<span style="opacity:.25;">★</span>', $empty );
    $html .= '</span>';
    return $html;
  }

  /**
   * Shortcode handler.
   */
  function myls_google_reviews_slider_shortcode( $atts = [] ) {

    $a = shortcode_atts( [
      'place_id'        => '',
      'min_rating'      => '0',
      'max_reviews'     => '0',     // 0 = show all
      'cache_hours'     => '24',
      'speed'           => '5000',  // autoplay ms
      'blur'            => '14',
      'overlay_opacity' => '0.12',
      'star_color'      => '#FFD700',
      'text_color'      => '#ffffff',
      'sort'            => 'default', // newest | highest | default
      'excerpt_words'   => '0',       // 0 = full text
    ], $atts, 'google_reviews_slider' );

    // API key
    $api_key = get_option( 'myls_google_places_api_key', '' );
    if ( empty( $api_key ) ) {
      return current_user_can('manage_options')
        ? '<p style="color:red;"><strong>Google Reviews Slider:</strong> No API key found. Add your Google Places API key in AIntelligize → API Integration.</p>'
        : '';
    }

    // Place ID
    $place_id = ! empty( $a['place_id'] ) ? $a['place_id'] : get_option( 'myls_google_places_place_id', '' );
    if ( empty( $place_id ) ) {
      return current_user_can('manage_options')
        ? '<p style="color:red;"><strong>Google Reviews Slider:</strong> No Place ID found. Add your Google Place ID in AIntelligize → API Integration.</p>'
        : '';
    }

    // Fetch reviews
    $data = myls_google_reviews_fetch( $api_key, $place_id, (int) $a['cache_hours'] );

    if ( is_wp_error( $data ) ) {
      return current_user_can('manage_options')
        ? '<p style="color:red;"><strong>Google Reviews Slider:</strong> ' . esc_html( $data->get_error_message() ) . '</p>'
        : '';
    }

    $reviews = $data['reviews'] ?? [];
    if ( empty( $reviews ) ) {
      return current_user_can('manage_options')
        ? '<p><strong>Google Reviews Slider:</strong> No reviews found for this Place ID.</p>'
        : '';
    }

    // Filter by minimum rating
    $min = (int) $a['min_rating'];
    if ( $min > 0 ) {
      $reviews = array_filter( $reviews, function( $r ) use ( $min ) {
        return ( $r['rating'] ?? 0 ) >= $min;
      });
    }

    // Sort
    if ( $a['sort'] === 'newest' ) {
      usort( $reviews, function( $a, $b ) {
        return ( $b['time'] ?? 0 ) - ( $a['time'] ?? 0 );
      });
    } elseif ( $a['sort'] === 'highest' ) {
      usort( $reviews, function( $a, $b ) {
        return ( $b['rating'] ?? 0 ) - ( $a['rating'] ?? 0 );
      });
    }

    // Limit
    $max = (int) $a['max_reviews'];
    if ( $max > 0 ) {
      $reviews = array_slice( $reviews, 0, $max );
    }

    if ( empty( $reviews ) ) {
      return '';
    }

    // Unique ID for multiple instances
    $uid = 'myls-gr-' . wp_unique_id();

    // CSS values
    $blur    = max( 0, (int) $a['blur'] );
    $opacity = floatval( $a['overlay_opacity'] );
    $color   = sanitize_hex_color( $a['text_color'] ) ?: '#ffffff';
    $star_c  = sanitize_hex_color( $a['star_color'] ) ?: '#FFD700';
    $speed   = max( 1000, (int) $a['speed'] );

    ob_start();

    // ── Swiper CSS/JS (enqueue once) ──
    static $assets_loaded = false;
    if ( ! $assets_loaded ) {
      $assets_loaded = true;
      echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css">';
      echo '<script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js" defer></script>';
    }

    // ── Inline CSS (once) ──
    static $css_loaded = false;
    if ( ! $css_loaded ) {
      $css_loaded = true;
      ?>
      <style>
      /* === AIntelligize – Google Reviews Slider === */
      .myls-gr-wrap{position:relative;width:100%;overflow:hidden;padding:0 50px}
      .myls-gr-wrap .swiper{overflow:hidden}
      .myls-gr-wrap .swiper-slide{height:auto}

      .myls-gr-card{
        background:rgba(255,255,255,var(--myls-gr-opacity,.12));
        backdrop-filter:blur(var(--myls-gr-blur,14px));
        -webkit-backdrop-filter:blur(var(--myls-gr-blur,14px));
        border-radius:20px;
        border:1px solid rgba(255,255,255,.15);
        padding:40px 50px;
        color:var(--myls-gr-color,#fff);
        display:flex;flex-direction:column;justify-content:center;
        min-height:200px;
        height:100%;
      }

      .myls-gr-stars{font-size:1.6em;display:block;margin-bottom:12px;letter-spacing:2px}
      .myls-gr-text{font-size:1em;line-height:1.65;margin-bottom:14px}
      .myls-gr-author{font-style:italic;opacity:.85;font-size:.95em}

      /* Navigation arrows */
      .myls-gr-wrap .swiper-button-next,
      .myls-gr-wrap .swiper-button-prev{
        color:var(--myls-gr-color,#fff);
        opacity:.6;transition:opacity .2s;
        width:36px;height:36px;
      }
      .myls-gr-wrap .swiper-button-next:hover,
      .myls-gr-wrap .swiper-button-prev:hover{opacity:1}
      .myls-gr-wrap .swiper-button-next::after,
      .myls-gr-wrap .swiper-button-prev::after{font-size:20px}

      /* Pagination dots */
      .myls-gr-wrap .swiper-pagination{position:relative;margin-top:18px}
      .myls-gr-wrap .swiper-pagination-bullet{
        background:var(--myls-gr-color,#fff);opacity:.35;
        width:8px;height:8px;
      }
      .myls-gr-wrap .swiper-pagination-bullet-active{opacity:.9}
      </style>
      <?php
    }

    // ── HTML ──
    ?>
    <div class="myls-gr-wrap"
         id="<?php echo esc_attr( $uid ); ?>"
         style="--myls-gr-blur:<?php echo esc_attr($blur); ?>px;--myls-gr-opacity:<?php echo esc_attr($opacity); ?>;--myls-gr-color:<?php echo esc_attr($color); ?>;">

      <div class="swiper myls-gr-swiper">
        <div class="swiper-wrapper">
          <?php foreach ( $reviews as $review ) :
            $text   = $review['text'] ?? '';
            $author = $review['author_name'] ?? '';
            $rating = $review['rating'] ?? 5;

            // Excerpt
            $word_limit = (int) $a['excerpt_words'];
            if ( $word_limit > 0 && str_word_count( $text ) > $word_limit ) {
              $text = wp_trim_words( $text, $word_limit, '…' );
            }
          ?>
          <div class="swiper-slide">
            <div class="myls-gr-card">
              <?php echo myls_render_stars( $rating, $star_c ); ?>
              <div class="myls-gr-text"><?php echo esc_html( $text ); ?></div>
              <?php if ( $author ) : ?>
                <div class="myls-gr-author">— <?php echo esc_html( $author ); ?></div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="swiper-button-prev"></div>
        <div class="swiper-button-next"></div>
        <div class="swiper-pagination"></div>
      </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded',function(){
      if(typeof Swiper==='undefined')return;
      new Swiper('#<?php echo esc_js($uid); ?> .myls-gr-swiper',{
        slidesPerView:1,
        spaceBetween:20,
        loop:true,
        autoplay:{delay:<?php echo (int)$speed; ?>,disableOnInteraction:false,pauseOnMouseEnter:true},
        navigation:{nextEl:'#<?php echo esc_js($uid); ?> .swiper-button-next',prevEl:'#<?php echo esc_js($uid); ?> .swiper-button-prev'},
        pagination:{el:'#<?php echo esc_js($uid); ?> .swiper-pagination',clickable:true},
        keyboard:{enabled:true},
      });
    });
    </script>
    <?php

    return ob_get_clean();
  }
}

add_shortcode( 'google_reviews_slider', 'myls_google_reviews_slider_shortcode' );
