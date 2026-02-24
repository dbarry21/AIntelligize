<?php
/**
 * Shortcode: [custom_service_cards]
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('custom_service_cards_shortcode') ) {
  function custom_service_cards_shortcode( $atts ) {

    $atts = shortcode_atts(
      [
        'posts_per_page' => -1,
      ],
      $atts,
      'custom_service_cards'
    );

    $query_args = [
      'post_type'      => 'service',
      'post_parent'    => 0,
      'posts_per_page' => intval($atts['posts_per_page']),
      'post_status'    => 'publish',
      'no_found_rows'  => true,
    ];

    $custom_query = new WP_Query( $query_args );

    ob_start();

    if ( $custom_query->have_posts() ) : ?>
      <div class="row">
        <?php while ( $custom_query->have_posts() ) : $custom_query->the_post(); ?>
          <div class="col-md-4 mb-4">
            <a href="<?php the_permalink(); ?>" class="card h-100 text-decoration-none text-reset">
              <?php if ( has_post_thumbnail() ) : ?>
                <img
                  src="<?php echo esc_url( get_the_post_thumbnail_url( get_the_ID(), 'full' ) ); ?>"
                  class="card-img-top rounded"
                  alt="<?php the_title_attribute(); ?>"
                  loading="lazy"
                  decoding="async"
                >
              <?php endif; ?>

              <div class="card-body">
                <h3 class="card-title"><?php the_title(); ?></h3>
                <p class="card-text"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 20 ) ); ?></p>
              </div>
            </a>
          </div>
        <?php endwhile; ?>
      </div>
      <?php
      wp_reset_postdata();
    else : ?>
      <p><?php esc_html_e( 'Sorry, no posts found.', 'your-text-domain' ); ?></p>
    <?php endif;

    return ob_get_clean();
  }
}

add_shortcode( 'custom_service_cards', 'custom_service_cards_shortcode' );
