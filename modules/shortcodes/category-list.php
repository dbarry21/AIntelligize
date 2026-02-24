<?php
/**
 * Shortcode: [ssseo_category_list include_empty="0|1" min_count="0"]
 * Outputs a Bootstrap list-group of post categories, filtered by minimum post count.
 * 
 * @param array $atts {
 *     Shortcode attributes.
 *
 *     @type string $include_empty '1' to include empty categories, '0' (default) to hide them.
 *     @type int    $min_count     Minimum number of posts in a category to display (default 0).
 * }
 * @return string HTML markup for the category list.
 */
function ssseo_category_list_shortcode( $atts ) {
    // Merge user attributes with defaults.
    $atts = shortcode_atts( array(
        'include_empty' => '0',
        'min_count'     => 0,
    ), $atts, 'ssseo_category_list' );

    // Determine whether to hide empty categories.
    $hide_empty = ( '1' !== $atts['include_empty'] );

    // Ensure min_count is an integer ≥ 0.
    $min_count = max( 0, intval( $atts['min_count'] ) );

    // Fetch all categories based on hide_empty setting.
    $categories = get_categories( array(
        'hide_empty' => $hide_empty,
    ) );

    // Filter out those below min_count.
    $filtered = array_filter( $categories, function( $cat ) use ( $min_count ) {
        return $cat->count >= $min_count;
    } );

    if ( empty( $filtered ) ) {
        return '';
    }

    // Build the Bootstrap list-group.
    $output  = '<div class="list-group ssseo">';
    foreach ( $filtered as $category ) {
        $link = esc_url( get_category_link( $category->term_id ) );
        $name = esc_html( $category->name );

        $output .= sprintf(
            '<a href="%1$s" class="list-group-item list-group-item-action ssseo" style="background-color: var(--e-global-color-primary); color: var(--e-global-color-secondary);">%2$s <span class="badge badge-light">%3$d posts</span></a>',
            $link,
            $name,
            intval( $category->count )
        );
    }
    $output .= '</div>';

    return $output;
}
add_shortcode( 'ssseo_category_list', 'ssseo_category_list_shortcode' );
