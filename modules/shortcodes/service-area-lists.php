<?php
/**
 * Shortcode: [service_area_list show_drafts="true"]
 * 
 * Displays list of Service Areas.
 * - Default: published parent-level service_area posts, alphabetical.
 * - show_drafts="true" → show *only drafts* instead of published.
 * - Drafts show plain text (no link, no "(Draft)" suffix).
 */

function service_area_list_shortcode( $atts ) {
    // Shortcode attributes
    $atts = shortcode_atts([
        'show_drafts' => 'false',
    ], $atts, 'service_area_list');

    $show_drafts = filter_var($atts['show_drafts'], FILTER_VALIDATE_BOOLEAN);

    // Get the current post ID (so we can exclude it)
    $current_post_id = is_singular('service_area') ? get_the_ID() : 0;

    // Build query arguments
    $args = [
        'post_type'      => 'service_area',
        'post_parent'    => 0,
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'post_status'    => $show_drafts ? 'draft' : 'publish',
    ];

    if ($current_post_id) {
        $args['post__not_in'] = [$current_post_id];
    }

    // Run query
    $service_areas = new WP_Query($args);

    // Build output
    if ($service_areas->have_posts()) {
        $output = '<H3>Other Service Areas</h3>';
        $output .= '<div class="container service-areas"><div class="row">';
        $output .= '<div class="col-lg-12">';
        $output .= '<ul class="list-unstyled service-area-list">';

        while ($service_areas->have_posts()) {
            $service_areas->the_post();
            $title = get_the_title();

            // No link for drafts
            if ($show_drafts) {
                $output .= '<li><i class="fa fa-map-marker ssseo-icon"></i> ' . esc_html($title) . '</li>';
            } else {
                $output .= '<li><i class="fa fa-map-marker ssseo-icon"></i> <a href="' . esc_url(get_permalink()) . '" class="service-area-link">' . esc_html($title) . '</a></li>';
            }
        }

        $output .= '</ul></div></div></div>';

        wp_reset_postdata();
        return $output;
    }

    return '<p>No service areas found.</p>';
}
add_shortcode('service_area_list', 'service_area_list_shortcode');
