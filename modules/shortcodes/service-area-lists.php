<?php
/**
 * Shortcode: [service_area_list]
 *
 * Displays a linked list of Service Area posts.
 *
 * Attributes:
 * - show_drafts="true|false|1|0"
 *     Show draft posts only instead of published. Drafts render as plain text (no link).
 *     Default: false.
 *
 * - get_related_children="true|false|1|0"
 *     Switch into related-children mode: queries service_area posts whose title STARTS
 *     WITH the current page's title (case-insensitive prefix match).
 *     Example — on "Pressure Washing" it surfaces:
 *       • "Pressure Washing in Clearwater"
 *       • "Pressure Washing in Tampa"
 *     No taxonomy or parent/child hierarchy required.
 *     Default: false.
 *
 * - heading="My Custom Heading"
 *     Override the section heading above the list.
 *     Falls back to "Related Service Areas" (get_related_children mode) or
 *     "Other Service Areas" (default mode) when omitted or empty.
 *     Set heading="" to suppress the heading entirely.
 *     Default: (auto).
 *
 * - icon="true|false|1|0"
 *     Show or hide the Font Awesome map-marker icon before each list item.
 *     Default: true.
 *
 * - use_city_state="true|false|1|0"
 *     Display the city_state custom field value instead of the post title.
 *     Falls back to the post title when the field is empty.
 *     Default: false.
 *
 * - bullets="true|false|1|0"
 *     Show default browser bullet markers on the list.
 *     When false, the Bootstrap list-unstyled class is applied.
 *     Default: false.
 *
 * @since 7.5.29 — added get_related_children
 * @since 7.5.30 — added heading, icon attributes
 * @since 7.9.10 — added use_city_state, bullets attributes
 */

if ( ! function_exists( 'service_area_list_shortcode' ) ) {
function service_area_list_shortcode( $atts ) {

    /* ── Normalise attributes ──────────────────────────────────────────── */
    $atts = shortcode_atts([
        'show_drafts'          => 'false',
        'get_related_children' => 'false',
        'heading'              => '__auto__',  // __auto__ = auto-detect; '' = suppress
        'icon'                 => 'true',
        'use_city_state'       => 'false',
        'bullets'              => 'false',
    ], $atts, 'service_area_list');

    $show_drafts          = filter_var( $atts['show_drafts'],          FILTER_VALIDATE_BOOLEAN );
    $get_related_children = filter_var( $atts['get_related_children'], FILTER_VALIDATE_BOOLEAN );
    $show_icon            = filter_var( $atts['icon'],                 FILTER_VALIDATE_BOOLEAN );
    $use_city_state       = filter_var( $atts['use_city_state'],       FILTER_VALIDATE_BOOLEAN );
    $show_bullets         = filter_var( $atts['bullets'],              FILTER_VALIDATE_BOOLEAN );

    // heading: null means "auto"; empty string means "suppress"
    $heading_override = $atts['heading']; // '__auto__' | '' | custom string

    /* ── Current post context ──────────────────────────────────────────── */
    $current_post_id    = get_the_ID() ?: 0;
    $current_post_title = $current_post_id ? get_the_title( $current_post_id ) : '';

    /* ── Build WP_Query arguments ──────────────────────────────────────── */

    if ( $get_related_children && ! empty( $current_post_title ) ) {
        /*
         * Related-children mode:
         * Fetch ALL service_area posts then filter in PHP to those whose
         * title begins with the current page title (case-insensitive).
         *
         * Example: current page = "Pressure Washing"
         *   → keeps  "Pressure Washing in Clearwater"
         *   → keeps  "Pressure Washing in Tampa"
         *   → skips  "Soft Washing" / "House Washing"
         */
        $args = [
            'post_type'      => 'service_area',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'post_status'    => $show_drafts ? 'draft' : 'publish',
            'post__not_in'   => $current_post_id ? [ $current_post_id ] : [],
        ];

        $auto_heading = 'Related Service Areas';

    } else {
        /*
         * Default mode (original behaviour preserved):
         * All published (or draft) parent-level service_area posts,
         * excluding the current page.
         */
        $args = [
            'post_type'      => 'service_area',
            'post_parent'    => 0,
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'post_status'    => $show_drafts ? 'draft' : 'publish',
        ];

        if ( $current_post_id ) {
            $args['post__not_in'] = [ $current_post_id ];
        }

        $auto_heading = 'Other Service Areas';
    }

    /* ── Resolve final heading ─────────────────────────────────────────── */
    // null  → use auto heading
    // ''    → suppress heading (user passed heading="")
    // 'foo' → use custom string
    if ( $heading_override === '__auto__' ) {
        $section_heading = $auto_heading;          // auto-detect
    } else {
        $section_heading = $heading_override;      // custom string or '' (suppressed)
    }

    /* ── Run query ─────────────────────────────────────────────────────── */
    $service_areas = new WP_Query( $args );

    if ( ! $service_areas->have_posts() ) {
        return '';
    }

    /* ── Collect matching posts ────────────────────────────────────────── */
    $items = [];

    while ( $service_areas->have_posts() ) {
        $service_areas->the_post();

        $title = get_the_title();

        // Related-children mode: enforce exact prefix in PHP
        if ( $get_related_children ) {
            $prefix = strtolower( $current_post_title );
            if ( strpos( strtolower( $title ), $prefix ) !== 0 ) {
                continue; // skip — title does not start with origin title
            }
        }

        $display_title = $title;
        if ( $use_city_state ) {
            $post_id_for_meta = get_the_ID();
            $city_state = get_post_meta( $post_id_for_meta, '_myls_city_state', true );
            if ( empty( $city_state ) ) {
                $city_state = get_post_meta( $post_id_for_meta, 'city_state', true );
            }
            if ( ! empty( $city_state ) ) {
                $display_title = $city_state;
            }
        }

        $items[] = [
            'title'     => $display_title,
            'permalink' => get_permalink(),
        ];
    }

    wp_reset_postdata();

    if ( empty( $items ) ) {
        return '';
    }

    /* ── Build HTML output ─────────────────────────────────────────────── */
    $icon_html = $show_icon ? '<i class="fa fa-map-marker ssseo-icon"></i> ' : '';

    $output = '';

    // Only render the <h3> when heading is not explicitly suppressed
    if ( $section_heading !== '' ) {
        $output .= '<h3>' . esc_html( $section_heading ) . '</h3>';
    }

    $output .= '<div class="container service-areas"><div class="row">';
    $output .= '<div class="col-lg-12">';
    $ul_class = $show_bullets ? 'service-area-list' : 'list-unstyled service-area-list';
    $ul_style = $show_bullets ? ' style="list-style-type:disc !important;padding-left:1.5em !important"' : '';
    $output .= '<ul class="' . $ul_class . '"' . $ul_style . '>';

    foreach ( $items as $item ) {
        if ( $show_drafts ) {
            // Drafts: plain text, no link
            $output .= '<li>' . $icon_html . esc_html( $item['title'] ) . '</li>';
        } else {
            $output .= '<li>' . $icon_html . '<a href="' . esc_url( $item['permalink'] ) . '" class="service-area-link">' . esc_html( $item['title'] ) . '</a></li>';
        }
    }

    $output .= '</ul></div></div></div>';

    return $output;
}
} // end function_exists

add_shortcode( 'service_area_list', 'service_area_list_shortcode' );
