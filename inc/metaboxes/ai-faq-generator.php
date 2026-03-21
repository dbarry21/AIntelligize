<?php
/**
 * AIntelligize - AI FAQ Generator Metabox
 * Path: inc/metaboxes/ai-faq-generator.php
 * 
 * Adds metabox to post editor for generating FAQs for individual posts
 * - Uses the same prompts from AI → FAQs tab
 * - Option to clear existing MYLS FAQs
 * - Shows current FAQ count
 */

if (!defined('ABSPATH')) exit;

/**
 * Helper to get public post types
 */
if (!function_exists('myls_get_public_post_types_no_attachments')) {
    function myls_get_public_post_types_no_attachments() {
        $pts = get_post_types(['public' => true], 'names');
        unset($pts['attachment']);
        return array_values($pts);
    }
}

/**
 * Register the AI FAQ Generator metabox
 */
add_action('add_meta_boxes', function() {
    foreach (myls_get_public_post_types_no_attachments() as $pt) {
        add_meta_box(
            'myls_ai_faq_generator',
            '<span class="dashicons dashicons-superhero" style="margin-right:5px;"></span> AI FAQ Generator',
            'myls_ai_faq_generator_render',
            $pt,
            'side',
            'low'
        );
    }
});

/**
 * Render the FAQ Generator metabox
 */
function myls_ai_faq_generator_render($post) {
    wp_nonce_field('myls_ai_faq_gen', 'myls_ai_faq_gen_nonce');
    
    // The generate endpoint uses the shared AI nonce (myls_ai_ops).
    $ai_nonce = wp_create_nonce('myls_ai_ops');
    
    // Check if AI API key is configured (provider-agnostic)
    $has_api_key = function_exists('myls_ai_has_key') ? myls_ai_has_key() : !empty(trim(get_option('myls_openai_api_key', '')));
    
    // Get current FAQ count
    $faqs = get_post_meta($post->ID, '_myls_faq_items', true);
    $faq_count = is_array($faqs) ? count($faqs) : 0;
    
    // Get saved prompt template (v3 is current, v2 and v1 are legacy fallbacks)
    $template = get_option('myls_ai_faqs_prompt_template_v3', '');
    if (empty($template)) {
        $template = get_option('myls_ai_faqs_prompt_template_v2', '');
    }
    if (empty($template)) {
        $template = get_option('myls_ai_faqs_prompt_template', '');
    }
    $has_template = !empty($template);
    
    ?>
    <div class="myls-ai-faq-gen" style="padding: 5px 0;">
        <?php if (!$has_api_key): ?>
            <div class="notice notice-warning inline" style="margin: 10px 0; padding: 8px;">
                <p style="margin: 0;">
                    <strong>AI API Key Required:</strong><br>
                    Configure your API key in 
                    <a href="<?php echo admin_url('admin.php?page=aintelligize&tab=api-integration'); ?>">
                        API Integration
                    </a>.
                </p>
            </div>
        <?php else: ?>
            <!-- Current Status -->
            <div style="margin: 10px 0; padding: 10px; background: #f5f5f5; border-radius: 3px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                    <strong>Current FAQs:</strong>
                    <span id="myls_faq_count" style="color: <?php echo $faq_count > 0 ? '#00a32a' : '#999'; ?>; font-weight: 600;">
                        <?php echo $faq_count; ?>
                    </span>
                </div>
                
                <?php if (!$has_template): ?>
                    <div style="margin-top: 8px; padding: 8px; background: #fff3cd; border-left: 3px solid #ffc107; font-size: 12px;">
                        <strong>⚠️ No Prompt Template</strong><br>
                        <a href="<?php echo admin_url('admin.php?page=aintelligize&tab=ai&subtab=faqs'); ?>" target="_blank">
                            Configure prompt in AI → FAQs
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Generation Options -->
            <div style="margin: 15px 0;">
                <label style="display: block; margin-bottom: 8px; font-weight: 600;">
                    Generation Mode:
                </label>
                
                <div style="margin-bottom: 8px;">
                    <label style="display: block; margin-bottom: 5px;">
                        <input type="radio" name="myls_faq_variant" value="LONG" checked>
                        <strong>Detailed</strong>
                        <small style="display: block; margin-left: 20px; color: #666;">
                            Full answers with bullets and checklists
                        </small>
                    </label>
                </div>
                
                <div>
                    <label style="display: block;">
                        <input type="radio" name="myls_faq_variant" value="SHORT">
                        <strong>Concise</strong>
                        <small style="display: block; margin-left: 20px; color: #666;">
                            Brief, single-paragraph answers
                        </small>
                    </label>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div style="margin-top: 15px;">
                <button 
                    type="button" 
                    id="myls_generate_faqs_btn" 
                    class="button button-primary button-large"
                    style="width: 100%; height: auto; padding: 10px; margin-bottom: 8px;"
                    <?php echo !$has_template ? 'disabled' : ''; ?>
                >
                    <span class="dashicons dashicons-superhero" style="margin-top: 3px;"></span>
                    Generate FAQs
                </button>
                
                <?php if ($faq_count > 0): ?>
                    <button 
                        type="button" 
                        id="myls_clear_faqs_btn" 
                        class="button button-link-delete"
                        style="width: 100%; padding: 5px; font-size: 12px;"
                    >
                        <span class="dashicons dashicons-trash" style="font-size: 14px;"></span>
                        Clear All FAQs (<?php echo $faq_count; ?>)
                    </button>
                <?php endif; ?>
                
                <p id="myls_faq_status" style="margin: 8px 0 0 0; font-size: 12px; color: #666; text-align: center;"></p>
            </div>
            
            <!-- Info Box -->
            <div style="margin-top: 15px; padding: 10px; background: #f0f6fc; border-left: 3px solid #2271b1; font-size: 12px;">
                <strong>💡 How it works:</strong>
                <ul style="margin: 5px 0 0 0; padding-left: 20px;">
                    <li>Reads page content</li>
                    <li>Generates FAQs with AI</li>
                    <li>Saves to MYLS FAQ fields</li>
                    <li>Uses prompt from AI tab</li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
    
    <?php if ($has_api_key): ?>
    <script>
    jQuery(function($) {
        var postId = <?php echo (int)$post->ID; ?>;
        var generating = false;
        var aiNonce = '<?php echo esc_js( $ai_nonce ); ?>';
        
        // Generate FAQs
        $('#myls_generate_faqs_btn').on('click', function(e) {
            e.preventDefault();
            
            if (generating) return;
            
            var $btn = $(this);
            var $status = $('#myls_faq_status');
            var $count = $('#myls_faq_count');
            var variant = $('input[name="myls_faq_variant"]:checked').val();
            
            // Confirm if FAQs already exist
            var currentCount = parseInt($count.text()) || 0;
            if (currentCount > 0) {
                if (!confirm('This post already has ' + currentCount + ' FAQs. Generate new ones? (Existing FAQs will remain, duplicates will be skipped)')) {
                    return;
                }
            }
            
            generating = true;
            var originalText = $btn.html();
            
            // Disable button and show loading
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Generating...');
            $status.html('<span class="dashicons dashicons-update spin"></span> Analyzing content...').css('color', '#2271b1');
            
            // Step 1: Generate FAQs
            $.post(ajaxurl, {
                action: 'myls_ai_faqs_generate_v1',
                post_id: postId,
                variant: variant,
                source: 'metabox',
                nonce: aiNonce
            }, function(response) {
                if (!response.success) {
                    $status.html('<span class="dashicons dashicons-dismiss"></span> ' + (response.data?.message || 'Generation failed')).css('color', '#d63638');
                    $btn.prop('disabled', false).html(originalText);
                    generating = false;
                    return;
                }
                
                var html = response.data.html || '';
                if (!html) {
                    $status.html('<span class="dashicons dashicons-dismiss"></span> No FAQs generated').css('color', '#d63638');
                    $btn.prop('disabled', false).html(originalText);
                    generating = false;
                    return;
                }
                
                $status.html('<span class="dashicons dashicons-update spin"></span> Saving FAQs...').css('color', '#2271b1');
                
                // Step 2: Save to MYLS FAQs
                $.post(ajaxurl, {
                    action: 'myls_ai_faq_save_to_myls',
                    post_id: postId,
                    html: html,
                    nonce: $('#myls_ai_faq_gen_nonce').val()
                }, function(saveResponse) {
                    if (saveResponse.success) {
                        var newCount = saveResponse.data.count || 0;
                        var added = saveResponse.data.added || 0;
                        
                        $count.text(newCount).css('color', newCount > 0 ? '#00a32a' : '#999');
                        $status.html('<span class="dashicons dashicons-yes-alt"></span> Added ' + added + ' FAQs! Total: ' + newCount).css('color', '#00a32a');
                        
                        // Show clear button if not visible
                        if (newCount > 0 && $('#myls_clear_faqs_btn').length === 0) {
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        }
                    } else {
                        $status.html('<span class="dashicons dashicons-dismiss"></span> ' + (saveResponse.data?.message || 'Save failed')).css('color', '#d63638');
                    }
                    
                    $btn.prop('disabled', false).html(originalText);
                    generating = false;
                }).fail(function() {
                    $status.html('<span class="dashicons dashicons-dismiss"></span> Save failed').css('color', '#d63638');
                    $btn.prop('disabled', false).html(originalText);
                    generating = false;
                });
                
            }).fail(function(xhr) {
                var errorMsg = 'Generation failed';
                if (xhr.status === 403) {
                    errorMsg = 'Permission denied';
                } else if (xhr.responseJSON?.data?.message) {
                    errorMsg = xhr.responseJSON.data.message;
                }
                $status.html('<span class="dashicons dashicons-dismiss"></span> ' + errorMsg).css('color', '#d63638');
                $btn.prop('disabled', false).html(originalText);
                generating = false;
            });
        });
        
        // Clear FAQs
        $('#myls_clear_faqs_btn').on('click', function(e) {
            e.preventDefault();
            
            var currentCount = parseInt($('#myls_faq_count').text()) || 0;
            if (!confirm('Delete all ' + currentCount + ' FAQs from this post? This cannot be undone.')) {
                return;
            }
            
            var $btn = $(this);
            var $status = $('#myls_faq_status');
            var $count = $('#myls_faq_count');
            
            $btn.prop('disabled', true);
            $status.html('<span class="dashicons dashicons-update spin"></span> Clearing FAQs...').css('color', '#2271b1');
            
            $.post(ajaxurl, {
                action: 'myls_ai_faq_clear_myls',
                post_id: postId,
                nonce: $('#myls_ai_faq_gen_nonce').val()
            }, function(response) {
                if (response.success) {
                    $count.text('0').css('color', '#999');
                    $status.html('<span class="dashicons dashicons-yes-alt"></span> FAQs cleared').css('color', '#00a32a');
                    $btn.fadeOut();
                } else {
                    $status.html('<span class="dashicons dashicons-dismiss"></span> Clear failed').css('color', '#d63638');
                    $btn.prop('disabled', false);
                }
            }).fail(function() {
                $status.html('<span class="dashicons dashicons-dismiss"></span> Clear failed').css('color', '#d63638');
                $btn.prop('disabled', false);
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
 * AJAX: Save generated FAQs to MYLS _myls_faq_items
 */
add_action('wp_ajax_myls_ai_faq_save_to_myls', function() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'myls_ai_faq_gen')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Permission denied'], 403);
    }
    
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id) {
        wp_send_json_error(['message' => 'Invalid post ID']);
    }
    
    $html = isset($_POST['html']) ? wp_unslash($_POST['html']) : '';
    if (empty($html)) {
        wp_send_json_error(['message' => 'No FAQ content provided']);
    }
    
    // Extract FAQ pairs from HTML (reuse existing function)
    if (!function_exists('myls_ai_faqs_extract_pairs')) {
        wp_send_json_error(['message' => 'FAQ extraction function not available']);
    }
    
    $pairs = myls_ai_faqs_extract_pairs($html);
    if (empty($pairs)) {
        wp_send_json_error(['message' => 'No FAQs found in generated content']);
    }
    
    // Get existing FAQs
    $existing = get_post_meta($post_id, '_myls_faq_items', true);
    $existing = is_array($existing) ? $existing : [];
    
    // Build hash set of existing Q&A to avoid duplicates
    $existing_hashes = [];
    foreach ($existing as $item) {
        if (!is_array($item)) continue;
        $q = isset($item['q']) ? trim($item['q']) : '';
        $a = isset($item['a']) ? trim(wp_strip_all_tags($item['a'])) : '';
        if ($q && $a) {
            $existing_hashes[md5(strtolower($q . $a))] = true;
        }
    }
    
    // Add new FAQs (skip duplicates)
    $added = 0;
    foreach ($pairs as $pair) {
        $question = isset($pair['question']) ? trim($pair['question']) : '';
        $answer_html = isset($pair['answer_html']) ? trim($pair['answer_html']) : '';
        
        // Fallback to plain answer if no HTML
        if (empty($answer_html)) {
            $answer_html = isset($pair['answer']) ? '<p>' . esc_html(trim($pair['answer'])) . '</p>' : '';
        }
        
        if (empty($question) || empty($answer_html)) continue;
        
        // Check for duplicate
        $hash = md5(strtolower($question . wp_strip_all_tags($answer_html)));
        if (isset($existing_hashes[$hash])) continue;
        
        $existing[] = [
            'q' => $question,
            'a' => $answer_html
        ];
        $existing_hashes[$hash] = true;
        $added++;
    }
    
    // Save updated FAQs
    update_post_meta($post_id, '_myls_faq_items', $existing);
    
    wp_send_json_success([
        'message' => 'FAQs saved successfully',
        'count' => count($existing),
        'added' => $added
    ]);
});

/**
 * AJAX: Clear all MYLS FAQs
 */
add_action('wp_ajax_myls_ai_faq_clear_myls', function() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'myls_ai_faq_gen')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Permission denied'], 403);
    }
    
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id) {
        wp_send_json_error(['message' => 'Invalid post ID']);
    }
    
    // Clear FAQs
    delete_post_meta($post_id, '_myls_faq_items');
    
    wp_send_json_success([
        'message' => 'FAQs cleared successfully'
    ]);
});
