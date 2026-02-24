<?php
/**
 * AJAX Handler: AI Tagline Generation
 * File: inc/ajax/ai-taglines.php
 *
 * Handles:
 *  - myls_ai_taglines_get_posts: Load posts by type with tagline status
 *  - myls_ai_taglines_generate_single: Generate tagline for a single post
 */

if (!defined('ABSPATH')) exit;

/**
 * AJAX: Get posts for tagline generation
 */
add_action('wp_ajax_myls_ai_taglines_get_posts', function() {
    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'myls_ai_ops')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }
    
    $post_type = isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : 'page';
    
    if (!post_type_exists($post_type)) {
        wp_send_json_error(['message' => 'Invalid post type'], 400);
    }
    
    $post_type_obj = get_post_type_object($post_type);
    $cap = $post_type_obj && isset($post_type_obj->cap->edit_posts) ? $post_type_obj->cap->edit_posts : 'edit_posts';
    
    if (!current_user_can($cap)) {
        wp_send_json_error(['message' => 'Permission denied'], 403);
    }
    
    $posts = get_posts([
        'post_type'       => $post_type,
        'post_status'     => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page'  => -1,
        'orderby'         => 'title',
        'order'           => 'ASC',
        'suppress_filters' => true,
    ]);
    
    $out = [];
    foreach ($posts as $post) {
        $tagline = get_post_meta($post->ID, '_myls_service_tagline', true);
        
        $out[] = [
            'ID'          => (int) $post->ID,
            'post_title'  => get_the_title($post->ID),
            'has_tagline' => !empty($tagline),
        ];
    }
    
    wp_send_json_success($out);
});

/**
 * AJAX: Generate tagline for single post
 */
add_action('wp_ajax_myls_ai_taglines_generate_single', function() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'myls_ai_ops')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }
    $start_time = microtime(true);
    if ( class_exists('MYLS_Variation_Engine') ) { MYLS_Variation_Engine::reset_log(); }
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Permission denied'], 403);
    }
    
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id) {
        wp_send_json_error(['message' => 'Invalid post ID']);
    }
    
    $post = get_post($post_id);
    if (!$post) {
        wp_send_json_error(['message' => 'Post not found']);
    }
    
    // Get parameters
    $prompt_template = isset($_POST['prompt_template']) ? wp_kses_post($_POST['prompt_template']) : '';
    if ( empty($prompt_template) && function_exists('myls_get_default_prompt') ) {
        $prompt_template = myls_get_default_prompt('taglines');
    }
    $tokens = isset($_POST['tokens']) ? intval($_POST['tokens']) : 100;
    $temperature = isset($_POST['temperature']) ? floatval($_POST['temperature']) : 0.7;
    
    if (empty($prompt_template)) {
        wp_send_json_error(['message' => 'Prompt template is empty']);
    }
    
    // Get post details
    $title = get_the_title($post_id);
    
    // Get location context (city/state)
    $location_data = function_exists('myls_get_city_state_values') 
        ? myls_get_city_state_values($post_id) 
        : ['city' => '', 'state' => ''];
    
    $city_state = '';
    if (!empty($location_data['city']) && !empty($location_data['state'])) {
        $city_state = $location_data['city'] . ', ' . $location_data['state'];
    }
    
    // Fallback to organization location
    if (empty($city_state)) {
        $org_city = get_option('myls_org_locality', get_option('ssseo_organization_locality', ''));
        $org_state = get_option('myls_org_region', get_option('ssseo_organization_state', ''));
        if ($org_city && $org_state) {
            $city_state = $org_city . ', ' . $org_state;
        }
    }
    
    // Get content for context
    $content = get_the_excerpt($post_id);
    if (empty($content)) {
        $content = function_exists('myls_get_post_plain_text') ? myls_get_post_plain_text( $post_id, 50 ) : wp_trim_words(strip_shortcodes($post->post_content), 50);
    }
    $content = wp_strip_all_tags($content);
    
    // Determine business type from post type or organization
    $post_type_obj = get_post_type_object($post->post_type);
    $business_type = $post_type_obj ? $post_type_obj->labels->singular_name : 'Business';
    
    // If it's a service CPT, try to get more specific business type
    if ($post->post_type === 'service') {
        $org_name = get_option('myls_org_name', get_option('ssseo_organization_name', get_bloginfo('name')));
        if ($org_name) {
            $business_type = $org_name;
        }
    }
    
    // Build prompt by replacing variables
    $prompt = $prompt_template;
    $prompt = str_replace('{{TITLE}}', $title, $prompt);
    $prompt = str_replace('{{CONTENT}}', $content, $prompt);
    $prompt = str_replace('{{CITY_STATE}}', $city_state, $prompt);
    $prompt = str_replace('{{BUSINESS_TYPE}}', $business_type, $prompt);
    
    // ── Variation Engine: inject angle + banned phrases for tagline generation ──
    if ( class_exists('MYLS_Variation_Engine') ) {
        $angle  = MYLS_Variation_Engine::next_angle('taglines');
        $prompt = MYLS_Variation_Engine::inject_variation( $prompt, $angle, 'taglines' );
    }
    
    // Generate with AI
    if (!function_exists('myls_ai_generate_text')) {
        wp_send_json_error(['message' => 'AI function not available']);
    }
    
    if (function_exists("myls_ai_set_usage_context")) myls_ai_set_usage_context("taglines", $post_id);
    $response = myls_ai_generate_text($prompt, [
        'max_tokens' => $tokens,
        'temperature' => $temperature,
        'post_id' => $post_id
    ]);
    
    if (empty($response)) {
        wp_send_json_error(['message' => 'AI returned empty response']);
    }
    
    // Clean up response
    $response = trim($response);

    // ── Variation Engine: duplicate guard for taglines ──
    if ( class_exists('MYLS_Variation_Engine') ) {
        $response = MYLS_Variation_Engine::guard_duplicates(
            'taglines',
            $response,
            function( $original ) use ( $tokens, $temperature, $post_id ) {
                $rewrite = "Generate completely different taglines. Avoid these patterns:\n" . $original . "\n\nCreate fresh, unique taglines with different sentence structures.";
                return trim( myls_ai_generate_text( $rewrite, [
                    'max_tokens' => $tokens,
                    'temperature' => min(1.0, $temperature + 0.1),
                    'post_id' => $post_id
                ]) );
            }
        );
    }
    
    // Remove markdown code fences if present
    $response = preg_replace('/```html?\s*/', '', $response);
    $response = preg_replace('/```\s*$/', '', $response);
    
    // Extract taglines from HTML list
    $taglines = [];
    
    // Try to parse as HTML list
    if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $response, $matches)) {
        foreach ($matches[1] as $tagline_html) {
            $tagline = wp_strip_all_tags($tagline_html);
            $tagline = trim($tagline);
            $tagline = trim($tagline, '"\'');
            
            // Remove common AI preambles
            $tagline = preg_replace('/^(tagline:|here\'s|here is|sure,?|okay,?)/i', '', $tagline);
            $tagline = trim($tagline);
            
            // Remove bullet characters (•) and replace with pipe
            $tagline = str_replace('•', '|', $tagline);
            
            // Clean up multiple spaces around pipes
            $tagline = preg_replace('/\s*\|\s*/', ' | ', $tagline);
            
            if (!empty($tagline) && strlen($tagline) > 10) {
                $taglines[] = $tagline;
            }
        }
    }
    
    // Fallback: if no HTML list found, treat as plain text
    if (empty($taglines)) {
        $tagline = wp_strip_all_tags($response);
        $tagline = trim($tagline);
        $tagline = trim($tagline, '"\'');
        $tagline = preg_replace('/^(tagline:|here\'s|here is|sure,?|okay,?)/i', '', $tagline);
        $tagline = trim($tagline);
        
        // Remove bullet characters and replace with pipe
        $tagline = str_replace('•', '|', $tagline);
        $tagline = preg_replace('/\s*\|\s*/', ' | ', $tagline);
        
        if (!empty($tagline)) {
            $taglines[] = $tagline;
        }
    }
    
    if (empty($taglines)) {
        wp_send_json_error(['message' => 'No valid taglines extracted from AI response']);
    }
    
    // Use first tagline as the default
    $primary_tagline = $taglines[0];
    
    // Validate length (soft warning, still save)
    $char_count = strlen($primary_tagline);
    $is_over_limit = $char_count > 120;
    
    // Truncate if extremely long (over 150)
    if ($char_count > 150) {
        $primary_tagline = substr($primary_tagline, 0, 147) . '...';
        $char_count = strlen($primary_tagline);
    }
    
    // Save primary tagline to post meta
    update_post_meta($post_id, '_myls_service_tagline', $primary_tagline);
    
    $ve_log = class_exists('MYLS_Variation_Engine') ? MYLS_Variation_Engine::build_item_log($start_time, [
        'tokens'         => $tokens,
        'temperature'    => $temperature,
        'prompt_chars'   => mb_strlen($prompt),
        'output_chars'   => $char_count,
        'output_words'   => str_word_count($primary_tagline),
        'tagline_count'  => count($taglines),
        'page_title'     => $title,
        'city_state'     => $city_state,
        '_html'          => $primary_tagline,
    ]) : ['elapsed_ms' => round((microtime(true) - $start_time) * 1000)];

    wp_send_json_success([
        'tagline' => $primary_tagline,
        'all_taglines' => $taglines,
        'char_count' => $char_count,
        'is_over_limit' => $is_over_limit,
        'post_id' => $post_id,
        'post_title' => $title,
        'status' => 'saved',
        'preview' => $primary_tagline,
        'city_state' => $city_state,
        'log' => $ve_log,
    ]);
});
