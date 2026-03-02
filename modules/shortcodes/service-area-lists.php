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
 * @since 7.5.29 — added get_related_children
 * @since 7.5.30 — added heading, icon attributes
 */

function service_area_list_shortcode( $atts ) {

    /* ── Normalise attributes ──────────────────────────────────────────── */
    $atts = shortcode_atts([
        'show_drafts'          => 'false',
        'get_related_children' => 'false',
        'heading'              => null,   // null = auto-detect; '' = suppress
        'icon'                 => 'true',
    ], $atts, 'service_area_list');

    $show_drafts          = filter_var( $atts['show_drafts'],          FILTER_VALIDATE_BOOLEAN );
    $get_related_children = filter_var( $atts['get_related_children'], FILTER_VALIDATE_BOOLEAN );
    $show_icon            = filter_var( $atts['icon'],                 FILTER_VALIDATE_BOOLEAN );

    // heading: null means "auto"; empty string means "suppress"
    $heading_override = $atts['heading']; // null | string

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
    if ( is_null( $heading_override ) ) {
        $section_heading = $auto_heading;   // auto
    } else {
        $section_heading = $heading_override; // custom or '' (suppressed)
    }

    /* ── Run query ─────────────────────────────────────────────────────── */
    $service_areas = new WP_Query( $args );

    if ( ! $service_areas->have_posts() ) {
        return '<p>No service areas found.</p>';
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

        $items[] = [
            'title'     => $title,
            'permalink' => get_permalink(),
        ];
    }

    wp_reset_postdata();

    if ( empty( $items ) ) {
        return '<p>No service areas found.</p>';
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
    $output .= '<ul class="list-unstyled service-area-list">';

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
add_shortcode( 'service_area_list', 'service_area_list_shortcode' );
