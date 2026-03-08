<?php
/**
 * Shortcode: [service_posts]
 * 
 * Display service posts in card grid matching original design.
 * Bootstrap-based version of the Divi theme shortcode.
 *
 * Features:
 * - Individual bordered cards for each service
 * - Centered images/icons
 * - Tagline between image and title (not linked)
 * - Button for "View Details"
 * - Responsive grid (2, 3, 4, 5, or 6 columns)
 * - Heat/AC type styling support
 *
 * Usage:
 *   [service_posts]                                      // defaults (3 columns, 6 posts)
 *   [service_posts columns="3" limit="6"]                // 3 cols x 2 rows
 *   [service_posts columns="4" limit="8"]                // 4 cols x 2 rows
 *   [service_posts heading="Our Services"]               // custom heading
 *   [service_posts show_tagline="0"]                     // hide taglines
 *   [service_posts button_text="Learn More"]             // custom button text
 * 
 * Column Options:
 *   columns="2"  // 2 per row
 *   columns="3"  // 3 per row - DEFAULT
 *   columns="4"  // 4 per row
 *   columns="5"  // 5 per row (custom)
 *   columns="6"  // 6 per row
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('mlseo_service_posts_shortcode')) {
    function mlseo_service_posts_shortcode($atts) {
        
        $atts = shortcode_atts([
            'post_type'      => 'service',
            'parent_id'      => 0,
            'columns'        => 3,
            'limit'          => 6,
            'heading'        => '',
            'show_tagline'   => '1',
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'show_icon'      => '1',
            'show_image'     => '1',
            'button_text'    => 'Request Service NOW!',
        ], $atts, 'service_posts');

        // Validate and sanitize
        $columns = max(1, min(6, absint($atts['columns'])));
        $limit = max(1, absint($atts['limit']));
        $parent_id = absint($atts['parent_id']);

        // Map columns to Bootstrap classes
        $col_class_map = [
            1 => 'col-12',
            2 => 'col-md-6',
            3 => 'col-md-4',
            4 => 'col-md-3',
            5 => 'col-md-custom-5',
            6 => 'col-md-2',
        ];
        
        $col_class = $col_class_map[$columns] ?? 'col-md-4';

        // Query arguments
        $args = [
            'post_type'      => sanitize_key($atts['post_type']),
            'posts_per_page' => $limit,
            'post_status'    => 'publish',
            'orderby'        => sanitize_text_field($atts['orderby']),
            'order'          => sanitize_text_field($atts['order']),
        ];

        if ($parent_id > 0) {
            $args['post_parent'] = $parent_id;
        }

        $posts = get_posts($args);

        if (empty($posts)) {
            return '<p>No services found.</p>';
        }

        ob_start();
        
        // 5-column CSS now lives in assets/frontend.css

        // Wrapper
        echo '<div class="mlseo-service-posts-grid">';

        // Optional heading
        $heading = trim($atts['heading']);
        if (!empty($heading)) {
            echo '<div class="row mb-4">';
            echo '<div class="col-12 text-center">';
            echo '<h2 class="service-posts-heading">' . esc_html($heading) . '</h2>';
            echo '</div>';
            echo '</div>';
        }

        echo '<div class="row g-3 justify-content-center">';

        foreach ($posts as $post) {
            setup_postdata($post);

            $post_id = $post->ID;
            $title = get_the_title($post);
            $url = get_permalink($post);

            // Get service type (for heat/ac styling)
            $service_type = function_exists('get_field') ? get_field('service_type', $post_id) : '';
            $type_class = ($service_type === 'Heat') ? 'service-type-heat' : 'service-type-ac';

            // Get icon/image
            $image_url = '';
            $icon = '';
            
            if ($atts['show_image'] === '1' && function_exists('get_field')) {
                $image_url = get_field('service_area_icon-image', $post_id);
            }
            
            if ($atts['show_icon'] === '1' && empty($image_url)) {
                $icon = get_post_meta($post_id, 'custom_icon', true);
            }

            // Get tagline
            $tagline = '';
            if ($atts['show_tagline'] === '1') {
                $tagline = get_post_meta($post_id, '_myls_service_tagline', true);
            }

            // Start column
            echo '<div class="' . esc_attr($col_class) . ' mb-3">';
            echo '<div class="service-post-card ' . esc_attr($type_class) . ' h-100">';

            // Image or Icon (centered)
            if (!empty($image_url)) {
                echo '<div class="service-post-image-wrapper">';
                echo '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($title) . '" class="service-post-image" loading="lazy">';
                echo '</div>';
            } elseif (!empty($icon)) {
                echo '<div class="service-post-icon-wrapper">';
                echo '<span class="service-post-icon">' . esc_html($icon) . '</span>';
                echo '</div>';
            }

            // Title (linked)
            echo '<h4 class="service-post-title">';
            echo '<a href="' . esc_url($url) . '">' . esc_html($title) . '</a>';
            echo '</h4>';

            // Tagline below title
            if (!empty($tagline)) {
                echo '<p class="service-post-tagline">' . esc_html($tagline) . '</p>';
            }

            // Button
            if (!empty($atts['button_text'])) {
                echo '<a href="' . esc_url($url) . '" class="btn btn-primary service-post-button">';
                echo esc_html($atts['button_text']);
                echo '</a>';
            }
            
            echo '</div>'; // .service-post-card
            echo '</div>'; // .col
        }

        echo '</div>'; // .row
        echo '</div>'; // .mlseo-service-posts-grid

        wp_reset_postdata();

        return ob_get_clean();
    }
}

add_shortcode('service_posts', 'mlseo_service_posts_shortcode');

/** Service Posts Grid CSS now lives in assets/frontend.css */
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style('myls-frontend', plugins_url('assets/frontend.css', MYLS_MAIN_FILE), [], MYLS_VERSION);
});
