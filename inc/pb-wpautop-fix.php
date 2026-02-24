<?php
/**
 * Disable wpautop on AI Page Builder generated pages.
 *
 * WordPress's wpautop filter injects <br /> tags into HTML content,
 * which breaks Bootstrap components like accordions, cards, and grids.
 * This removes wpautop on pages that have the _myls_pb_generated meta key.
 *
 * File: inc/pb-wpautop-fix.php
 * @since 6.1.0
 */
if ( ! defined('ABSPATH') ) exit;

add_filter('the_content', 'myls_pb_maybe_disable_wpautop', 0);

/**
 * Remove wpautop for AI Page Builder generated content.
 *
 * Runs at priority 0 (before wpautop at priority 10) to remove the filter
 * before it processes the content, then re-adds it after for other posts.
 */
function myls_pb_maybe_disable_wpautop( string $content ): string {
    // Only act on singular views for the main query
    if ( ! is_singular() || ! is_main_query() ) {
        return $content;
    }

    $post_id = get_the_ID();
    if ( ! $post_id ) {
        return $content;
    }

    // Check if this is an AI Page Builder generated page
    $is_pb = get_post_meta($post_id, '_myls_pb_generated', true);
    if ( ! $is_pb ) {
        return $content;
    }

    // Remove wpautop and shortcode_unautop for this content
    remove_filter('the_content', 'wpautop');
    remove_filter('the_content', 'shortcode_unautop');

    // Re-add them after this post renders (priority 999)
    add_filter('the_content', 'myls_pb_restore_wpautop', 999);

    return $content;
}

/**
 * Re-add wpautop after the AI page content has been rendered.
 * This ensures other posts/pages still get normal wpautop processing.
 */
function myls_pb_restore_wpautop( string $content ): string {
    // Restore the filters
    add_filter('the_content', 'wpautop');
    add_filter('the_content', 'shortcode_unautop');

    // Remove this restore hook so it only runs once
    remove_filter('the_content', 'myls_pb_restore_wpautop', 999);

    return $content;
}
