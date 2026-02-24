<?php
/**
 * AIntelligize - Service Tagline Metabox
 * Path: inc/metaboxes/service-tagline.php
 * 
 * Adds metabox for service taglines (short, benefit-focused subtext)
 * - Manual entry field
 * - AI generation option
 * - Character counter
 * - Preview display
 */

if (!defined('ABSPATH')) exit;

/**
 * Register the Service Tagline metabox
 */
add_action('add_meta_boxes', function() {
    // Get all public post types
    $post_types = get_post_types(['public' => true], 'names');
    
    // Remove attachment
    unset($post_types['attachment']);
    
    // Add metabox to all public post types
    foreach ($post_types as $post_type) {
        add_meta_box(
            'myls_service_tagline_box',
            '<span class="dashicons dashicons-megaphone" style="margin-right:5px;"></span> Service Tagline',
            'myls_service_tagline_render',
            $post_type,
            'side',
            'high'
        );
    }
});

/**
 * Render the Service Tagline metabox
 */
function myls_service_tagline_render($post) {
    wp_nonce_field('myls_service_tagline_save', 'myls_service_tagline_nonce');
    
    // Get current tagline
    $tagline = get_post_meta($post->ID, '_myls_service_tagline', true);
    
    // Check if AI API key is configured (provider-agnostic)
    $has_api_key = function_exists('myls_ai_has_key') ? myls_ai_has_key() : !empty(trim(get_option('myls_openai_api_key', '')));
    
    // Get city/state for context
    $location_data = function_exists('myls_get_city_state_values') 
        ? myls_get_city_state_values($post->ID) 
        : ['city' => '', 'state' => '', 'source' => 'none'];
    
    $char_count = strlen($tagline);
    $char_limit = 120;
    $is_over = $char_count > $char_limit;
    
    ?>
    <div class="myls-service-tagline-box" style="padding: 5px 0;">
        
        <!-- Info Box -->
        <div style="margin-bottom: 15px; padding: 10px; background: #f0f6fc; border-left: 3px solid #2271b1; font-size: 12px;">
            <strong>💡 Tagline Tips:</strong>
            <ul style="margin: 5px 0 0 0; padding-left: 20px;">
                <li>Start with customer benefit</li>
                <li>Include trust signal (licensed, certified)</li>
                <li>Add differentiator (24/7, same-day)</li>
                <li>Use bullets (•) to separate points</li>
                <li>Keep under 120 characters</li>
            </ul>
        </div>
        
        <!-- Tagline Input -->
        <div style="margin-bottom: 15px;">
            <label for="myls_service_tagline" style="display: block; margin-bottom: 5px; font-weight: 600;">
                Tagline:
            </label>
            <textarea 
                id="myls_service_tagline" 
                name="myls_service_tagline" 
                rows="3" 
                class="widefat"
                placeholder="e.g., Emergency Repairs • 24/7 Service • Licensed Experts"
                style="font-size: 13px;"
            ><?php echo esc_textarea($tagline); ?></textarea>
            
            <!-- Character Counter -->
            <div style="margin-top: 5px; font-size: 12px; display: flex; justify-content: space-between; align-items: center;">
                <span id="myls_tagline_counter" style="color: <?php echo $is_over ? '#d63638' : '#666'; ?>">
                    <?php echo $char_count; ?> / <?php echo $char_limit; ?> characters
                </span>
                <?php if ($is_over): ?>
                    <span style="color: #d63638;">⚠️ Over limit</span>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Preview -->
        <?php if (!empty($tagline)): ?>
            <div style="margin-bottom: 15px; padding: 10px; background: #fff; border: 1px solid #ddd; border-radius: 3px;">
                <div style="font-size: 11px; color: #666; margin-bottom: 5px;">Preview:</div>
                <div style="font-size: 13px; color: #2c3338;">
                    <?php echo esc_html($tagline); ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- AI Generation -->
        <?php if ($has_api_key): ?>
            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                <button 
                    type="button" 
                    id="myls_generate_tagline_btn" 
                    class="button button-secondary"
                    style="width: 100%;"
                >
                    <span class="dashicons dashicons-superhero" style="margin-top: 3px;"></span>
                    Generate with AI
                </button>
                <p id="myls_tagline_status" style="margin: 8px 0 0 0; font-size: 12px; color: #666; text-align: center;"></p>
            </div>
        <?php else: ?>
            <div style="margin-top: 15px; padding: 8px; background: #fff3cd; border-left: 3px solid #ffc107; font-size: 12px;">
                <strong>💡 AI Generation Available</strong><br>
                <a href="<?php echo admin_url('admin.php?page=aintelligize&tab=api-integration'); ?>">
                    Configure AI API key
                </a> to auto-generate taglines.
            </div>
        <?php endif; ?>
        
        <!-- Examples -->
        <div style="margin-top: 15px; padding: 10px; background: #f9f9f9; border-radius: 3px; font-size: 11px;">
            <strong>Examples:</strong>
            <ul style="margin: 5px 0 0 0; padding-left: 15px; color: #666;">
                <li>Emergency Repairs • 24/7 • Same-Day Service</li>
                <li>Expert Installation • Free Estimates • Licensed</li>
                <li>Energy Efficient • Zone Control • Save 30%</li>
            </ul>
        </div>
    </div>
    
    <?php if ($has_api_key): ?>
    <script>
    jQuery(function($) {
        var $textarea = $('#myls_service_tagline');
        var $counter = $('#myls_tagline_counter');
        var charLimit = <?php echo $char_limit; ?>;
        
        // Character counter
        $textarea.on('input', function() {
            var length = $(this).val().length;
            var isOver = length > charLimit;
            
            $counter.text(length + ' / ' + charLimit + ' characters')
                    .css('color', isOver ? '#d63638' : '#666');
        });
        
        // AI Generation
        $('#myls_generate_tagline_btn').on('click', function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var $status = $('#myls_tagline_status');
            var postId = <?php echo (int)$post->ID; ?>;
            var originalText = $btn.html();
            
            // Confirm if tagline exists
            if ($textarea.val().trim() !== '') {
                if (!confirm('Replace existing tagline with AI-generated one?')) {
                    return;
                }
            }
            
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Generating...');
            $status.html('<span class="dashicons dashicons-update spin"></span> Analyzing service...').css('color', '#2271b1');
            
            $.post(ajaxurl, {
                action: 'myls_generate_service_tagline',
                post_id: postId,
                nonce: $('#myls_service_tagline_nonce').val()
            }, function(response) {
                if (response.success) {
                    var tagline = response.data.tagline || '';
                    $textarea.val(tagline).trigger('input');
                    $status.html('<span class="dashicons dashicons-yes-alt"></span> Generated! Review and save.').css('color', '#00a32a');
                } else {
                    $status.html('<span class="dashicons dashicons-dismiss"></span> ' + (response.data?.message || 'Generation failed')).css('color', '#d63638');
                }
                $btn.prop('disabled', false).html(originalText);
            }).fail(function() {
                $status.html('<span class="dashicons dashicons-dismiss"></span> Generation failed').css('color', '#d63638');
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
    </style>
    <?php endif; ?>
    <?php
}

/**
 * Save the tagline for all post types
 */
add_action('save_post', function($post_id, $post) {
    // Security checks
    if (!isset($_POST['myls_service_tagline_nonce'])) return;
    if (!wp_verify_nonce($_POST['myls_service_tagline_nonce'], 'myls_service_tagline_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    
    // Skip if not a public post type
    $post_type_obj = get_post_type_object($post->post_type);
    if (!$post_type_obj || !$post_type_obj->public) return;
    
    // Skip attachments
    if ($post->post_type === 'attachment') return;
    
    // Save tagline
    if (isset($_POST['myls_service_tagline'])) {
        $tagline = sanitize_text_field($_POST['myls_service_tagline']);
        update_post_meta($post_id, '_myls_service_tagline', $tagline);
    }
}, 10, 2);

/**
 * AJAX: Generate service tagline
 */
add_action('wp_ajax_myls_generate_service_tagline', function() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'myls_service_tagline_save')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Permission denied'], 403);
    }
    
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id) {
        wp_send_json_error(['message' => 'Invalid post ID']);
    }
    
    $post = get_post($post_id);
    if (!$post || $post->post_type === 'attachment') {
        wp_send_json_error(['message' => 'Invalid post or post type']);
    }
    
    // Get service details
    $title = get_the_title($post_id);
    
    // Get location context
    $location_data = function_exists('myls_get_city_state_values') 
        ? myls_get_city_state_values($post_id) 
        : ['city' => '', 'state' => ''];
    
    $location = '';
    if (!empty($location_data['city']) && !empty($location_data['state'])) {
        $location = $location_data['city'] . ', ' . $location_data['state'];
    }
    
    // Get excerpt or content for context
    $content = get_the_excerpt($post_id);
    if (empty($content)) {
        $content = function_exists('myls_get_post_plain_text') ? myls_get_post_plain_text( $post_id, 50 ) : wp_trim_words(strip_shortcodes($post->post_content), 50);
    }
    
    // Build prompt
    $prompt = "You are writing a service tagline for an HVAC company" . ($location ? " in {$location}" : "") . ".\n\n";
    $prompt .= "Service: {$title}\n";
    if (!empty($content)) {
        $prompt .= "Context: {$content}\n";
    }
    $prompt .= "\nWrite ONE tagline (80-120 characters max) that:\n";
    $prompt .= "1. Starts with the PRIMARY CUSTOMER BENEFIT (not company name)\n";
    $prompt .= "2. Includes a TRUST SIGNAL (licensed, certified, guaranteed, experienced)\n";
    $prompt .= "3. Includes a DIFFERENTIATOR (24/7, same-day, emergency, free estimates)\n";
    $prompt .= "4. Uses bullet points (•) to separate 2-3 key points\n";
    $prompt .= "5. Is action-oriented and scannable\n\n";
    $prompt .= "Examples of good taglines:\n";
    $prompt .= "- Emergency AC Repairs • Available 24/7 • Same-Day Service\n";
    $prompt .= "- Expert Installation • Energy Star Systems • Free Estimates\n";
    $prompt .= "- Ductless Cooling • Zone Control • Save Up to 30%\n\n";
    $prompt .= "Output ONLY the tagline, nothing else. No quotes, no preamble.\n\n";
    $prompt .= "Tagline:";
    
    // Generate with AI
    if (!function_exists('myls_ai_generate_text')) {
        wp_send_json_error(['message' => 'AI function not available']);
    }
    
    $tagline = myls_ai_generate_text($prompt, [
        'max_tokens' => 100,
        'temperature' => 0.7,
        'post_id' => $post_id
    ]);
    
    if (empty($tagline)) {
        wp_send_json_error(['message' => 'AI returned empty response']);
    }
    
    // Clean up response
    $tagline = trim($tagline);
    $tagline = trim($tagline, '"\'');
    $tagline = str_replace(["\n", "\r"], '', $tagline);
    
    // Validate length
    if (strlen($tagline) > 150) {
        $tagline = substr($tagline, 0, 147) . '...';
    }
    
    wp_send_json_success([
        'tagline' => $tagline
    ]);
});
