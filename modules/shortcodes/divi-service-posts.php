<?php
/**
 * Shortcode: [divi_service_posts]
 * 
 * PRESERVED ORIGINAL - Divi Theme Version
 * This is the original theme function, preserved in the plugin for backward compatibility.
 * 
 * Display service posts in dynamic columns/rows using Divi classes.
 *
 * Usage:
 *   [divi_service_posts columns="3" limit="6"]   => 3 cols x 2 rows
 *   [divi_service_posts columns="4" limit="8"]   => 4 cols x 2 rows
 *   [divi_service_posts columns="2" limit="6"]   => 2 cols x 3 rows
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('mlseo_divi_service_posts_shortcode')) {
    function mlseo_divi_service_posts_shortcode($atts) {
        global $post;
        
        $atts = shortcode_atts([
            'post_type' => 'service',
            'parent_id' => 0,
            'columns'   => 3,
            'limit'     => 6,
            'heading'   => 'Your AC and Heating Services',
            'orderby'   => [
                'menu_order' => 'ASC',
                'title'      => 'ASC',
            ],
        ], $atts, 'divi_service_posts');
        
        $columns = max(1, min(6, absint($atts['columns'])));
        $limit   = max(1, absint($atts['limit']));
        
        // Map "columns per row" to Divi column class
        $divi_col_class_map = [
            1 => 'et_pb_column_4_4',
            2 => 'et_pb_column_1_2',
            3 => 'et_pb_column_1_3',
            4 => 'et_pb_column_1_4',
            5 => 'et_pb_column_1_5',
            6 => 'et_pb_column_1_6',
        ];
        $col_class = $divi_col_class_map[$columns] ?? 'et_pb_column_1_3';
        
        $args = [
            'post_type'      => sanitize_key($atts['post_type']),
            'posts_per_page' => $limit,
            'post_parent'    => absint($atts['parent_id']),
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ];
        
        $posts = get_posts($args);
        if (!$posts) return '';
        
        $heading = trim((string) $atts['heading']);
        $output = '';
        
        // Optional heading row
        if ($heading !== '') {
            $output .= '<div class="et_pb_row"><h2>' . esc_html($heading) . '</h2></div>';
        }
        
        $count = 0;
        foreach ($posts as $post) {
            setup_postdata($post);
            
            // Open a new row at the start and after every N items
            if ($count % $columns === 0) {
                $output .= '<div class="et_pb_row">';
            }
            
            $title = get_the_title($post);
            $url   = get_permalink($post);
            
            // ACF field (if ACF exists)
            $image = function_exists('get_field') ? get_field('service_area_icon-image', $post->ID) : '';
            $icon  = get_post_meta($post->ID, 'custom_icon', true);
            $type  = function_exists('get_field') ? get_field('service_type', $post->ID) : '';
            
            $class = ($type === 'Heat') ? 'child-post-box-heat' : 'child-post-box-ac';
            
            $visual = $image
                ? '<img src="' . esc_url($image) . '" alt="' . esc_attr($title) . '" class="child-post-img" />'
                : '<span class="child-post-icon et-pb-icon">' . esc_html($icon) . '</span>';
            
            $output .= '
                <div class="et_pb_column ' . esc_attr($col_class) . '">
                    <div class="et_pb_blurb et_pb_module et_pb_bg_layout_light ' . esc_attr($class) . '">
                        <a href="' . esc_url($url) . '">
                            <div class="et_pb_main_blurb_image">' . $visual . '</div>
                            <div class="et_pb_blurb_container">
                                <h4 class="et_pb_module_header">' . esc_html($title) . '</h4>
                            </div>
                        </a>
                    </div>
                </div>
            ';
            
            $count++;
            
            // Close row after every N items
            if ($count % $columns === 0) {
                $output .= '</div>';
            }
        }
        
        // Close an open row if the total isn't divisible by columns
        if ($count % $columns !== 0) {
            $output .= '</div>';
        }
        
        wp_reset_postdata();
        return $output;
    }
}

add_shortcode('divi_service_posts', 'mlseo_divi_service_posts_shortcode');
