<?php
add_shortcode('about_the_area', 'ssseo_shortcode_about_the_area');
function ssseo_shortcode_about_the_area($atts) {
    global $post;
    if (! $post || $post->post_type !== 'service_area') return '';

    $html = (string) get_post_meta($post->ID, '_about_the_area', true);
    if ( trim($html) === '' ) return '';

    // The stored content is already structured HTML from the AI generator.
    // Do NOT run wpautop() — it inserts rogue <p> tags inside/around
    // <h2>, <h3>, <ul>, and <strong> elements, breaking the layout.
    $content  = '<div class="container about-the-area">';
    $content .= do_shortcode( $html );
    $content .= '</div>';
    return $content;
}
