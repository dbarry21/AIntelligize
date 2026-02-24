<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Sync post meta based on the saved myls_lb_locations option.
 * - Sets _myls_lb_loc_index (int) and _myls_lb_assigned ('1') on assigned posts
 * - Removes those meta keys from posts that are no longer assigned
 * - Keeps a reverse index (post_id => loc_index) to make emitter fast & stable
 */
if ( ! function_exists('myls_lb_sync_postmeta_from_locations') ) {
    function myls_lb_sync_postmeta_from_locations( array $locations ) : void {
        // Build a map of assigned post IDs => location index
        $assigned_map = [];
        foreach ( $locations as $idx => $loc ) {
            $pages = array_map( 'absint', (array) ( $loc['pages'] ?? [] ) );
            foreach ( $pages as $pid ) {
                if ( $pid > 0 ) {
                    $assigned_map[$pid] = (int) $idx;
                }
            }
        }

        // Fetch any posts that previously had assignments (so we can clean up)
        // We query by presence of _myls_lb_assigned meta to keep it tight.
        $prev_posts = get_posts([
            'post_type'      => ['page','post','service_area'],
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'meta_key'       => '_myls_lb_assigned',
            'fields'         => 'ids',
        ]);

        $prev_set = array_map('absint', (array) $prev_posts);
        $new_set  = array_map('absint', array_keys($assigned_map));

        // Remove meta from posts no longer assigned
        $to_unset = array_diff( $prev_set, $new_set );
        foreach ( $to_unset as $pid ) {
            delete_post_meta( $pid, '_myls_lb_assigned' );
            delete_post_meta( $pid, '_myls_lb_loc_index' );
        }

        // Set/update meta for currently assigned posts
        foreach ( $assigned_map as $pid => $loc_index ) {
            update_post_meta( $pid, '_myls_lb_assigned', '1' );
            update_post_meta( $pid, '_myls_lb_loc_index', (int) $loc_index );
        }

        // (Optional) cache the full locations array for quick emitter lookup
        // This lets us avoid re-reading options repeatedly in a request.
        wp_cache_set( 'myls_lb_locations_cache', $locations, 'myls', 300 );
    }
}
