<?php
/**
 * AIntelligize - Google Maps AJAX Handlers (Enhanced)
 * Path: inc/ajax/google-maps.php
 * 
 * Version: 2.0
 * Handles:
 * 1. myls_sa_all_published - Get all published Service Area posts
 * 2. myls_bulk_generate_maps - Generate Google Static Maps as featured images
 * 3. Supports zoom parameter and ACF field fallback
 */

if (!defined('ABSPATH')) exit;

/**
 * Get city and state from post - MYLS fields first, ACF fallback
 * Reusable helper function
 */
if (!function_exists('myls_get_city_state_values')) {
    function myls_get_city_state_values($post_id) {
        $result = [
            'city' => '',
            'state' => '',
            'source' => 'none'
        ];
        
        // Try MYLS native field first: _myls_city_state (format: "City, State")
        $myls_city_state = get_post_meta($post_id, '_myls_city_state', true);
        
        if (!empty($myls_city_state) && is_string($myls_city_state)) {
            // Parse "City, State" format
            $parts = array_map('trim', explode(',', $myls_city_state));
            if (count($parts) >= 2) {
                $result['city'] = $parts[0];
                $result['state'] = $parts[1];
                $result['source'] = 'myls';
                return $result;
            }
        }
        
        // Fallback to ACF field 'city_state' in 'Service Area' field group
        if (function_exists('get_field')) {
            $city_state = get_field('city_state', $post_id);
            
            if (!empty($city_state) && is_string($city_state)) {
                // Parse "City, State" format
                $parts = array_map('trim', explode(',', $city_state));
                if (count($parts) >= 2) {
                    $result['city'] = $parts[0];
                    $result['state'] = $parts[1];
                    $result['source'] = 'acf';
                    return $result;
                }
            }
        }
        
        return $result;
    }
}

/**
 * AJAX: Get all published Service Area posts
 * Returns array of {id, title} for the multi-select list
 */
add_action('wp_ajax_myls_sa_all_published', function() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'myls_bulk_ops')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Permission denied'], 403);
    }
    
    // Get all published Service Area posts
    $args = [
        'post_type'      => 'service_area',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids'
    ];
    
    $post_ids = get_posts($args);
    
    $items = [];
    foreach ($post_ids as $id) {
        $items[] = [
            'id'    => $id,
            'title' => get_the_title($id) ?: "(no title) #{$id}"
        ];
    }
    
    wp_send_json_success([
        'items' => $items,
        'count' => count($items)
    ]);
});

/**
 * AJAX: Generate Google Static Maps for selected posts
 * 
 * Expected POST data:
 * - post_ids: array of post IDs
 * - force: 1 to regenerate even if featured image exists, 0 otherwise
 * - nonce: security nonce
 */
add_action('wp_ajax_myls_bulk_generate_maps', function() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'myls_bulk_ops')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Permission denied'], 403);
    }
    
    // Get parameters
    $post_ids = isset($_POST['post_ids']) ? (array) $_POST['post_ids'] : [];
    $force = isset($_POST['force']) && $_POST['force'] == 1;
    
    if (empty($post_ids)) {
        wp_send_json_error(['message' => 'No post IDs provided']);
    }
    
    // Get API key from options
    $api_key = trim(get_option('myls_google_static_maps_api_key', ''));
    if (empty($api_key)) {
        wp_send_json_error([
            'message' => 'Google Static Maps API key not configured. Please add it in API Integration tab.'
        ]);
    }
    
    // Process each post
    $log = [];
    $ok_ids = [];
    $err_ids = [];
    
    foreach ($post_ids as $post_id) {
        $post_id = intval($post_id);
        if (!$post_id) continue;
        
        $title = get_the_title($post_id);
        
        // Skip if featured image exists and force is not set
        if (!$force && has_post_thumbnail($post_id)) {
            $log[] = "#{$post_id} ({$title}): Skipped - already has featured image";
            continue;
        }
        
        // Get city and state with fallback
        $location = myls_get_city_state_values($post_id);
        $city = $location['city'];
        $state = $location['state'];
        
        if (empty($city) || empty($state)) {
            $log[] = "#{$post_id} ({$title}): Error - Missing city or state (checked MYLS and ACF fields)";
            $err_ids[] = $post_id;
            continue;
        }
        
        // Generate the map with default zoom 12 for bulk operations
        $result = myls_generate_static_map($post_id, $city, $state, $api_key, 12);
        
        if ($result['success']) {
            $log[] = "#{$post_id} ({$title}): ✓ Map generated successfully";
            $ok_ids[] = $post_id;
        } else {
            $log[] = "#{$post_id} ({$title}): ✗ " . $result['message'];
            $err_ids[] = $post_id;
        }
    }
    
    wp_send_json_success([
        'ok' => count($ok_ids),
        'err' => count($err_ids),
        'ok_ids' => $ok_ids,
        'err_ids' => $err_ids,
        'log' => $log
    ]);
});

/**
 * Generate Google Static Map and set as featured image
 * 
 * @param int $post_id Post ID
 * @param string $city City name
 * @param string $state State name
 * @param string $api_key Google Maps API key
 * @param int $zoom Zoom level (1-20, default 12)
 * @return array {success: bool, message: string, attachment_id?: int}
 */
function myls_generate_static_map($post_id, $city, $state, $api_key, $zoom = 12) {
    // Validate and clamp zoom level
    $zoom = max(1, min(20, intval($zoom)));
    
    // Build the Static Maps API URL
    // Using 600x400 as a good featured image size
    $map_url = "https://maps.googleapis.com/maps/api/staticmap?" . http_build_query([
        'center'  => "{$city}, {$state}",
        'zoom'    => $zoom,
        'size'    => '600x400',
        'maptype' => 'roadmap',
        'markers' => "color:red|{$city}, {$state}",
        'key'     => $api_key
    ]);
    
    // Download the image
    $response = wp_remote_get($map_url, [
        'timeout' => 30,
        'sslverify' => true
    ]);
    
    if (is_wp_error($response)) {
        return [
            'success' => false,
            'message' => 'Failed to download map: ' . $response->get_error_message()
        ];
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        $body = wp_remote_retrieve_body($response);
        // Try to parse error from Google's response
        $error_msg = "HTTP {$response_code}";
        if (strpos($body, 'error_message') !== false) {
            $json = json_decode($body, true);
            if (isset($json['error_message'])) {
                $error_msg .= ": " . $json['error_message'];
            }
        }
        return [
            'success' => false,
            'message' => $error_msg
        ];
    }
    
    $image_data = wp_remote_retrieve_body($response);
    if (empty($image_data)) {
        return [
            'success' => false,
            'message' => 'Empty response from Google Maps API'
        ];
    }
    
    // Prepare upload
    $upload_dir = wp_upload_dir();
    $filename = sanitize_file_name("map-{$city}-{$state}-z{$zoom}.png");
    $file_path = $upload_dir['path'] . '/' . $filename;
    
    // Save the file
    $saved = file_put_contents($file_path, $image_data);
    if (!$saved) {
        return [
            'success' => false,
            'message' => 'Failed to save image file'
        ];
    }
    
    // Create attachment
    $attachment = [
        'post_mime_type' => 'image/png',
        'post_title'     => "Map: {$city}, {$state} (Zoom {$zoom})",
        'post_content'   => '',
        'post_status'    => 'inherit'
    ];
    
    $attachment_id = wp_insert_attachment($attachment, $file_path, $post_id);
    
    if (is_wp_error($attachment_id)) {
        return [
            'success' => false,
            'message' => 'Failed to create attachment: ' . $attachment_id->get_error_message()
        ];
    }
    
    // Generate attachment metadata
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attach_data = wp_generate_attachment_metadata($attachment_id, $file_path);
    wp_update_attachment_metadata($attachment_id, $attach_data);
    
    // Set as featured image
    set_post_thumbnail($post_id, $attachment_id);
    
    return [
        'success' => true,
        'message' => 'Map generated and set as featured image',
        'attachment_id' => $attachment_id
    ];
}
