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
        
        // Add custom CSS for 5-column layout
        if ($columns === 5) {
            echo '<style>
                @media (min-width: 768px) {
                    .col-md-custom-5 {
                        flex: 0 0 20%;
                        max-width: 20%;
                    }
                }
            </style>';
        }

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

/**
 * Enqueue frontend styles for service posts grid
 */
add_action('wp_enqueue_scripts', function() {
    if (!wp_style_is('mlseo-service-posts', 'registered')) {
        wp_add_inline_style('wp-block-library', '
            /* Service Posts Grid - Matching Original Design */
            .mlseo-service-posts-grid {
                margin: 30px 0;
            }
            
            .service-posts-heading {
                margin-bottom: 30px;
                font-size: 28px;
                font-weight: 600;
                color: #333;
                text-align: center;
            }
            
            .service-post-card {
                background: #fff !important;
                border: 2px solid #e8e8e8 !important;
                border-radius: 8px !important;
                padding: 1em !important;
                text-align: center !important;
                transition: all 0.3s ease !important;
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05) !important;
            }
            
            .service-post-card:hover {
                border-color: #2271b1 !important;
                box-shadow: 0 4px 12px rgba(0,0,0,0.1) !important;
                transform: translateY(-2px) !important;
            }
            
            /* Image/Icon centered */
            .service-post-image-wrapper,
            .service-post-icon-wrapper {
                margin-bottom: 20px !important;
                display: flex !important;
                justify-content: center !important;
                align-items: center !important;
                width: 100% !important;
                text-align: center !important;
            }
            
            .service-post-image {
                max-width: 100% !important;
                max-height: 150px !important;
                width: auto !important;
                height: auto !important;
                display: block !important;
                margin: 0 auto !important;
            }
            
            .service-post-icon {
                font-size: 48px !important;
                color: #2271b1 !important;
                display: block !important;
                text-align: center !important;
                margin: 0 auto !important;
            }
            
            /* Title */
            .service-post-title {
                font-size: 20px !important;
                font-weight: 600 !important;
                margin: 0 0 10px 0 !important;
                line-height: 1.3 !important;
                text-align: center !important;
                width: 100% !important;
            }
            
            .service-post-title a {
                color: #2271b1 !important;
                text-decoration: none !important;
            }
            
            .service-post-title a:hover {
                color: #135e96 !important;
                text-decoration: underline !important;
            }
            
            /* Tagline below title */
            .service-post-tagline {
                font-size: 13px !important;
                color: #666 !important;
                margin: 0 0 20px 0 !important;
                line-height: 1.5 !important;
                text-align: center !important;
                width: 100% !important;
            }
            
            /* Button - centered and forced to bottom */
            .service-post-button {
                display: block !important;
                background: #2271b1 !important;
                color: #fff !important;
                padding: 10px 24px !important;
                border-radius: 4px !important;
                text-decoration: none !important;
                font-weight: 600 !important;
                font-size: 14px !important;
                transition: background 0.3s ease !important;
                border: none !important;
                margin-top: auto !important;
                margin-left: auto !important;
                margin-right: auto !important;
                margin-bottom: 0 !important;
                text-align: center !important;
                width: fit-content !important;
            }
            
            .service-post-button:hover {
                background: #135e96 !important;
                color: #fff !important;
                text-decoration: none !important;
            }
            
            /* Service Type Styling (Heat vs AC) */
            .service-type-heat {
                border-left: 4px solid #ff6b6b !important;
            }
            
            .service-type-ac {
                border-left: 4px solid #4ecdc4 !important;
            }
            
            .service-type-heat:hover {
                border-left-color: #ff6b6b !important;
            }
            
            .service-type-ac:hover {
                border-left-color: #4ecdc4 !important;
            }
            
            .service-type-heat .service-post-icon {
                color: #ff6b6b !important;
            }
            
            .service-type-ac .service-post-icon {
                color: #4ecdc4 !important;
            }
            
            /* Responsive adjustments */
            @media (max-width: 767px) {
                .service-post-card {
                    padding: 1em !important;
                }
                
                .service-post-image {
                    max-height: 120px !important;
                }
                
                .service-post-icon {
                    font-size: 36px !important;
                }
                
                .service-post-title {
                    font-size: 18px !important;
                }
                
                .service-posts-heading {
                    font-size: 24px !important;
                }
            }
        ');
    }
});
