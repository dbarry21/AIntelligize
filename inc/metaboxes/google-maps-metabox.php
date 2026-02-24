<?php
/**
 * AIntelligize - Google Maps Metabox (Enhanced)
 * Path: inc/metaboxes/google-maps-metabox.php
 * 
 * Version: 2.0
 * - Preview map before saving
 * - Adjustable zoom level (1-20)
 * - Pulls from MYLS fields (city/state) with ACF fallback (city_state)
 * - Real-time preview updates
 */

if (!defined('ABSPATH')) exit;

/**
 * Helper to get public post types (excluding attachments)
 */
if (!function_exists('myls_get_public_post_types_no_attachments')) {
    function myls_get_public_post_types_no_attachments() {
        $pts = get_post_types(['public' => true], 'names');
        unset($pts['attachment']);
        return array_values($pts);
    }
}

/**
 * Register the Google Maps metabox
 * Appears on all public post types where MYLS City, State field exists
 */
add_action('add_meta_boxes', function() {
    foreach (myls_get_public_post_types_no_attachments() as $pt) {
        add_meta_box(
            'myls_google_maps_metabox',
            '<span class="dashicons dashicons-location" style="margin-right:5px;"></span> Google Maps Featured Image',
            'myls_google_maps_metabox_render',
            $pt,
            'side',
            'low'  // Below City/State metabox
        );
    }
});

/**
 * Get city and state from post - MYLS fields first, ACF fallback
 */
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

/**
 * Render the metabox content
 */
function myls_google_maps_metabox_render($post) {
    wp_nonce_field('myls_google_maps_metabox', 'myls_google_maps_nonce');
    
    // Get API key status
    $api_key = trim(get_option('myls_google_static_maps_api_key', ''));
    $has_api_key = !empty($api_key);
    
    // Get city and state with fallback
    $location = myls_get_city_state_values($post->ID);
    $city = $location['city'];
    $state = $location['state'];
    $source = $location['source'];
    
    // Get current featured image status
    $has_thumbnail = has_post_thumbnail($post->ID);
    
    // Default zoom level
    $default_zoom = 12;
    
    ?>
    <div class="myls-gmaps-metabox" style="padding: 5px 0;">
        <?php if (!$has_api_key): ?>
            <div class="notice notice-warning inline" style="margin: 10px 0; padding: 8px;">
                <p style="margin: 0;">
                    <strong>API Key Required:</strong><br>
                    Configure your Google Static Maps API key in 
                    <a href="<?php echo admin_url('admin.php?page=aintelligize&tab=api-integration'); ?>">
                        API Integration
                    </a>.
                </p>
            </div>
        <?php else: ?>
            <?php if (empty($city) || empty($state)): ?>
                <div class="notice notice-info inline" style="margin: 10px 0; padding: 8px;">
                    <p style="margin: 0;">
                        <strong>Location Required:</strong><br>
                        <?php if ($source === 'none'): ?>
                            Fill in "MYLS City, State" field above or ACF 'city_state' field.
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
            
            <div style="margin: 10px 0;">
                <p style="margin-bottom: 8px;">
                    <strong>Location:</strong><br>
                    <?php if ($city && $state): ?>
                        <span style="color: #2271b1;"><?php echo esc_html($city . ', ' . $state); ?></span>
                        <?php if ($source !== 'none'): ?>
                            <br><small style="color: #999;">Source: <?php echo $source === 'myls' ? 'MYLS City, State' : 'ACF city_state'; ?></small>
                        <?php endif; ?>
                    <?php else: ?>
                        <em style="color: #999;">Not set</em>
                    <?php endif; ?>
                </p>
                
                <?php if ($has_thumbnail): ?>
                    <p style="margin-bottom: 8px; color: #2c3338;">
                        <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                        Featured image exists
                    </p>
                <?php endif; ?>
            </div>
            
            <?php if ($city && $state): ?>
                <!-- Zoom Control -->
                <div style="margin: 15px 0;">
                    <label for="myls_map_zoom" style="display: block; margin-bottom: 5px; font-weight: 600;">
                        Zoom Level: <span id="myls_zoom_value"><?php echo $default_zoom; ?></span>
                    </label>
                    <input 
                        type="range" 
                        id="myls_map_zoom" 
                        min="1" 
                        max="20" 
                        value="<?php echo $default_zoom; ?>" 
                        step="1"
                        style="width: 100%;"
                    >
                    <div style="display: flex; justify-content: space-between; margin-top: 3px;">
                        <small style="color: #999;">City/Region</small>
                        <small style="color: #999;">Street</small>
                    </div>
                </div>
                
                <!-- Preview Container -->
                <div style="margin: 15px 0;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                        <strong>Preview:</strong>
                        <button 
                            type="button" 
                            id="myls_refresh_preview" 
                            class="button button-small"
                            style="padding: 2px 8px; height: auto;"
                        >
                            <span class="dashicons dashicons-update" style="font-size: 13px; margin-top: 2px;"></span>
                            Refresh
                        </button>
                    </div>
                    <div id="myls_map_preview" style="border: 1px solid #ddd; background: #f5f5f5; min-height: 200px; position: relative; overflow: hidden;">
                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; color: #999;">
                            <span class="dashicons dashicons-format-image" style="font-size: 48px; opacity: 0.3;"></span>
                            <p style="margin: 10px 0 0 0;">Click refresh to preview</p>
                        </div>
                    </div>
                    <small style="color: #666; display: block; margin-top: 5px;">
                        Preview size: 300×200 (actual: 600×400)
                    </small>
                </div>
                
                <!-- Action Buttons -->
                <div style="margin-top: 15px;">
                    <button 
                        type="button" 
                        id="myls_save_map_btn" 
                        class="button button-primary button-large"
                        style="width: 100%; height: auto; padding: 10px;"
                        disabled
                    >
                        <span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
                        <?php echo $has_thumbnail ? 'Replace Featured Image' : 'Set as Featured Image'; ?>
                    </button>
                    
                    <p id="myls_map_status" style="margin: 8px 0 0 0; font-size: 12px; color: #666; text-align: center;"></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <?php if ($has_api_key && $city && $state): ?>
    <script>
    jQuery(function($) {
        var postId = <?php echo (int)$post->ID; ?>;
        var apiKey = '<?php echo esc_js($api_key); ?>';
        var city = '<?php echo esc_js($city); ?>';
        var state = '<?php echo esc_js($state); ?>';
        var currentZoom = <?php echo $default_zoom; ?>;
        var previewLoaded = false;
        
        // Update zoom value display
        $('#myls_map_zoom').on('input change', function() {
            currentZoom = parseInt($(this).val());
            $('#myls_zoom_value').text(currentZoom);
        });
        
        // Build Google Static Maps URL
        function buildMapUrl(width, height) {
            var params = {
                center: city + ', ' + state,
                zoom: currentZoom,
                size: width + 'x' + height,
                maptype: 'roadmap',
                markers: 'color:red|' + city + ', ' + state,
                key: apiKey
            };
            
            var queryString = $.param(params);
            return 'https://maps.googleapis.com/maps/api/staticmap?' + queryString;
        }
        
        // Load preview
        function loadPreview() {
            var $preview = $('#myls_map_preview');
            var $saveBtn = $('#myls_save_map_btn');
            
            // Show loading
            $preview.html('<div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">' +
                '<span class="dashicons dashicons-update spin" style="font-size: 32px; color: #2271b1;"></span>' +
                '<p style="margin: 10px 0 0 0; color: #666;">Loading preview...</p>' +
                '</div>');
            
            // Build preview URL (smaller size)
            var previewUrl = buildMapUrl(300, 200);
            
            // Create image
            var $img = $('<img>')
                .attr('src', previewUrl)
                .css({
                    'width': '100%',
                    'height': 'auto',
                    'display': 'block'
                })
                .on('load', function() {
                    $preview.empty().append($img);
                    $saveBtn.prop('disabled', false);
                    previewLoaded = true;
                })
                .on('error', function() {
                    $preview.html('<div style="padding: 20px; text-align: center; color: #d63638;">' +
                        '<span class="dashicons dashicons-dismiss" style="font-size: 32px;"></span>' +
                        '<p style="margin: 10px 0 0 0;">Failed to load preview</p>' +
                        '<small>Check your API key and quota</small>' +
                        '</div>');
                    $saveBtn.prop('disabled', true);
                });
        }
        
        // Refresh preview button
        $('#myls_refresh_preview').on('click', function(e) {
            e.preventDefault();
            loadPreview();
        });
        
        // Auto-refresh on zoom change (debounced)
        var zoomTimer;
        $('#myls_map_zoom').on('input', function() {
            if (!previewLoaded) return; // Don't auto-load until first manual refresh
            
            clearTimeout(zoomTimer);
            zoomTimer = setTimeout(function() {
                loadPreview();
            }, 500);
        });
        
        // Save map as featured image
        $('#myls_save_map_btn').on('click', function(e) {
            e.preventDefault();
            
            if (!previewLoaded) {
                alert('Please preview the map first');
                return;
            }
            
            var $btn = $(this);
            var $status = $('#myls_map_status');
            var originalText = $btn.html();
            
            // Disable button and show loading
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Generating...');
            $status.html('<span class="dashicons dashicons-update spin"></span> Generating map...').css('color', '#2271b1');
            
            // Make AJAX request
            $.post(ajaxurl, {
                action: 'myls_generate_single_map',
                post_id: postId,
                zoom: currentZoom,
                nonce: $('#myls_google_maps_nonce').val()
            }, function(response) {
                if (response.success) {
                    $status.html('<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span> ' + response.data.message).css('color', '#00a32a');
                    
                    // Reload the page after 1.5 seconds to show the new featured image
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    $status.html('<span class="dashicons dashicons-dismiss" style="color: #d63638;"></span> ' + (response.data.message || 'Failed to generate map')).css('color', '#d63638');
                    $btn.prop('disabled', false).html(originalText);
                }
            }).fail(function(xhr) {
                var errorMsg = 'Network error';
                if (xhr.status === 403) {
                    errorMsg = 'Permission denied';
                }
                $status.html('<span class="dashicons dashicons-dismiss" style="color: #d63638;"></span> ' + errorMsg).css('color', '#d63638');
                $btn.prop('disabled', false).html(originalText);
            });
        });
    });
    </script>
    
    <style>
    @keyframes rotation {
        from { transform: rotate(0deg); }
        to { transform: rotate(359deg); }
    }
    .spin {
        display: inline-block;
        animation: rotation 2s infinite linear;
    }
    #myls_map_zoom {
        cursor: pointer;
    }
    #myls_map_zoom::-webkit-slider-thumb {
        cursor: pointer;
    }
    </style>
    <?php endif; ?>
    <?php
}

/**
 * AJAX handler for single map generation (with zoom parameter)
 */
add_action('wp_ajax_myls_generate_single_map', function() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'myls_google_maps_metabox')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Permission denied'], 403);
    }
    
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id) {
        wp_send_json_error(['message' => 'Invalid post ID']);
    }
    
    // Get zoom level (default 12)
    $zoom = isset($_POST['zoom']) ? intval($_POST['zoom']) : 12;
    $zoom = max(1, min(20, $zoom)); // Clamp between 1-20
    
    // Get API key
    $api_key = trim(get_option('myls_google_static_maps_api_key', ''));
    if (empty($api_key)) {
        wp_send_json_error([
            'message' => 'Google Static Maps API key not configured'
        ]);
    }
    
    // Get city and state with fallback
    $location = myls_get_city_state_values($post_id);
    $city = $location['city'];
    $state = $location['state'];
    
    if (empty($city) || empty($state)) {
        wp_send_json_error([
            'message' => 'City and State fields are required'
        ]);
    }
    
    // Generate the map (reuse function from google-maps.php)
    if (!function_exists('myls_generate_static_map')) {
        wp_send_json_error([
            'message' => 'Map generation function not available'
        ]);
    }
    
    $result = myls_generate_static_map($post_id, $city, $state, $api_key, $zoom);
    
    if ($result['success']) {
        wp_send_json_success([
            'message' => 'Map generated successfully!',
            'attachment_id' => $result['attachment_id']
        ]);
    } else {
        wp_send_json_error([
            'message' => $result['message']
        ]);
    }
});
