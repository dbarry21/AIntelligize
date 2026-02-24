<?php
/**
 * Shortcode: [divi_child_posts]
 *
 * Display child posts of the current page; if none, fall back to sibling posts.
 * Uses the same card markup and CSS as [service_posts] for consistent display.
 *
 * Usage:
 *   [divi_child_posts]                                         // defaults
 *   [divi_child_posts columns="3" limit="6"]                   // 3 cols, 6 posts
 *   [divi_child_posts heading="Our Service Areas"]             // custom heading
 *   [divi_child_posts button_text="View Service"]              // custom button
 *   [divi_child_posts show_tagline="1"]                        // show taglines
 *   [divi_child_posts post_type="service" fallback="none"]     // no sibling fallback
 *
 * Attributes:
 *   post_type      (default: service_area)
 *   parent_id      (default: current post ID)
 *   columns        (default: 3)        — 1–6 columns per row
 *   limit          (default: 6)
 *   heading        (default: '')
 *   button_text    (default: 'View Service')
 *   show_tagline   (default: 1)
 *   show_icon      (default: 1)
 *   show_image     (default: 1)
 *   orderby        (default: menu_order)
 *   order          (default: ASC)
 *   fallback       (default: siblings)  — siblings|none
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('mlseo_divi_child_posts_shortcode')) {

    function mlseo_divi_child_posts_shortcode($atts) {
        global $post;

        $atts = shortcode_atts([
            'post_type'    => 'service_area',
            'parent_id'    => isset($post->ID) ? $post->ID : 0,
            'columns'      => 3,
            'limit'        => 6,
            'heading'      => '',
            'button_text'  => 'View Service',
            'show_tagline' => '1',
            'show_icon'    => '1',
            'show_image'   => '1',
            'orderby'      => 'menu_order',
            'order'        => 'ASC',
            'fallback'     => 'siblings',   // siblings|none
        ], $atts, 'divi_child_posts');

        $parent_id = absint($atts['parent_id']);
        if (!$parent_id) return '';

        $columns   = max(1, min(6, absint($atts['columns'])));
        $limit     = max(1, absint($atts['limit']));

        // Bootstrap column classes (matches service_posts)
        $col_class_map = [
            1 => 'col-12',
            2 => 'col-md-6',
            3 => 'col-md-4',
            4 => 'col-md-3',
            5 => 'col-md-custom-5',
            6 => 'col-md-2',
        ];
        $col_class = $col_class_map[$columns] ?? 'col-md-4';

        // ── Query: children of parent_id ──────────────────────────────
        $args = [
            'post_type'      => sanitize_key($atts['post_type']),
            'posts_per_page' => $limit,
            'post_parent'    => $parent_id,
            'post_status'    => 'publish',
            'orderby'        => sanitize_text_field($atts['orderby']),
            'order'          => sanitize_text_field($atts['order']),
        ];

        $items = get_posts($args);

        // ── Fallback: siblings (same parent), excluding current post ──
        if (empty($items) && $atts['fallback'] === 'siblings') {
            $parent_post = get_post($parent_id);
            if ($parent_post && $parent_post->post_parent) {
                $args['post_parent']  = (int) $parent_post->post_parent;
                $args['post__not_in'] = [$parent_id];
                $items = get_posts($args);
            }
        }

        if (empty($items)) return '';

        // ── Render ────────────────────────────────────────────────────
        ob_start();

        // 5-column custom CSS (matches service_posts)
        if ($columns === 5) {
            echo '<style>
                @media (min-width: 768px) {
                    .col-md-custom-5 { flex: 0 0 20%; max-width: 20%; }
                }
            </style>';
        }

        echo '<div class="mlseo-service-posts-grid">';

        // Optional heading
        $heading = trim($atts['heading']);
        if ($heading !== '') {
            echo '<div class="row mb-4">';
            echo '<div class="col-12 text-center">';
            echo '<h2 class="service-posts-heading">' . esc_html($heading) . '</h2>';
            echo '</div></div>';
        }

        echo '<div class="row g-3 justify-content-center">';

        foreach ($items as $item) {
            setup_postdata($item);

            $item_id = $item->ID;
            $title   = get_the_title($item);
            $url     = get_permalink($item);

            // Service type (heat/ac styling)
            $service_type = function_exists('get_field') ? get_field('service_type', $item_id) : '';
            $type_class   = ($service_type === 'Heat') ? 'service-type-heat' : 'service-type-ac';

            // Image / icon
            $image_url = '';
            $icon      = '';

            if ($atts['show_image'] === '1' && function_exists('get_field')) {
                $image_url = get_field('service_area_icon-image', $item_id);
            }
            if ($atts['show_icon'] === '1' && empty($image_url)) {
                $icon = get_post_meta($item_id, 'custom_icon', true);
            }

            // Tagline
            $tagline = '';
            if ($atts['show_tagline'] === '1') {
                $tagline = get_post_meta($item_id, '_myls_service_tagline', true);
            }

            // ── Card markup (identical to service_posts) ──────────────
            echo '<div class="' . esc_attr($col_class) . ' mb-3">';
            echo '<div class="service-post-card ' . esc_attr($type_class) . ' h-100">';

            if (!empty($image_url)) {
                echo '<div class="service-post-image-wrapper">';
                echo '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($title) . '" class="service-post-image" loading="lazy">';
                echo '</div>';
            } elseif (!empty($icon)) {
                echo '<div class="service-post-icon-wrapper">';
                echo '<span class="service-post-icon">' . esc_html($icon) . '</span>';
                echo '</div>';
            }

            echo '<h4 class="service-post-title">';
            echo '<a href="' . esc_url($url) . '">' . esc_html($title) . '</a>';
            echo '</h4>';

            if (!empty($tagline)) {
                echo '<p class="service-post-tagline">' . esc_html($tagline) . '</p>';
            }

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

add_shortcode('divi_child_posts', 'mlseo_divi_child_posts_shortcode');
