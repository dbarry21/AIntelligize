<?php
/**
 * AIntelligize – AJAX: Content Analyzer
 * File: inc/ajax/ai-content-analyzer.php
 *
 * Endpoints:
 *  - wp_ajax_myls_content_analyze_v1   (batch analyze selected posts)
 *  - wp_ajax_myls_content_analyze_get_posts_v1
 *
 * @since 6.3.0
 */
if ( ! defined('ABSPATH') ) exit;

/* =============================================================================
 * Get posts for multiselect
 * ============================================================================= */
add_action('wp_ajax_myls_content_analyze_get_posts_v1', function(){
    $nonce = $_POST['nonce'] ?? $_REQUEST['_ajax_nonce'] ?? '';
    if ( ! wp_verify_nonce( (string)$nonce, 'myls_ai_ops' ) ) {
        wp_send_json_error(['message'=>'bad_nonce'], 403);
    }

    $pt = sanitize_key( $_POST['post_type'] ?? 'page' );
    $posts = get_posts([
        'post_type'      => $pt,
        'post_status'    => ['publish','draft','pending','future','private'],
        'posts_per_page' => 500,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
    ]);

    $list = [];
    foreach ( $posts as $pid ) {
        $title = get_the_title($pid);
        if ( $title === '' ) $title = '(no title)';
        $status = get_post_status($pid);
        $list[] = [
            'id'     => (int) $pid,
            'title'  => $title,
            'status' => $status,
        ];
    }

    wp_send_json_success(['posts' => $list]);
});

/* =============================================================================
 * Analyze: runs content quality analysis on selected posts (no AI calls)
 * ============================================================================= */
add_action('wp_ajax_myls_content_analyze_v1', function(){
    $nonce = $_POST['nonce'] ?? $_REQUEST['_ajax_nonce'] ?? '';
    if ( ! wp_verify_nonce( (string)$nonce, 'myls_ai_ops' ) ) {
        wp_send_json_error(['message'=>'bad_nonce'], 403);
    }

    $post_id = (int) ($_POST['post_id'] ?? 0);
    if ( $post_id <= 0 || get_post_status($post_id) === false ) {
        wp_send_json_error(['message'=>'bad_post'], 400);
    }
    if ( ! current_user_can('edit_post', $post_id) ) {
        wp_send_json_error(['message'=>'cap_denied'], 403);
    }

    if ( ! class_exists('MYLS_Content_Analyzer') ) {
        wp_send_json_error(['message'=>'Content Analyzer class not loaded.'], 500);
    }

    $post  = get_post($post_id);
    $title = get_the_title($post_id);
    $url   = get_permalink($post_id);
    $html  = function_exists('myls_get_post_html') ? myls_get_post_html( $post_id ) : (string) ($post ? $post->post_content : '');

    // Render shortcodes for analysis (handles DIVI, WPBakery, etc.)
    $html_rendered = do_shortcode($html);

    // Get city/state from post meta or title
    $city_state = (string) get_post_meta($post_id, '_myls_city_state', true);
    if ( $city_state === '' ) {
        // Attempt to extract from title (common pattern: "Service in City, State")
        if ( preg_match('/(?:in|for|near)\s+(.+)$/i', $title, $m) ) {
            $city_state = trim($m[1]);
        }
    }

    // Focus keyword from Yoast
    $focus_keyword = (string) get_post_meta($post_id, '_yoast_wpseo_focuskw', true);

    // Yoast meta
    $yoast_title = (string) get_post_meta($post_id, '_yoast_wpseo_title', true);
    $yoast_desc  = (string) get_post_meta($post_id, '_yoast_wpseo_metadesc', true);

    // About the Area content
    $about_area = (string) get_post_meta($post_id, '_about_the_area', true);

    // FAQs
    $faq_html = (string) get_post_meta($post_id, '_myls_faq_html', true);

    // Excerpt
    $excerpt = (string) ($post ? $post->post_excerpt : '');
    $html_excerpt = (string) get_post_meta($post_id, '_myls_html_excerpt', true);

    // Tagline
    $tagline = (string) get_post_meta($post_id, '_myls_service_tagline', true);

    // ── Run quality analysis on main content ──
    $quality = MYLS_Content_Analyzer::analyze($html_rendered, [
        'city_state'    => $city_state,
        'focus_keyword' => $focus_keyword,
    ]);

    // ── Run analysis on about area if present ──
    $about_quality = null;
    if ( trim(wp_strip_all_tags($about_area)) !== '' ) {
        $about_quality = MYLS_Content_Analyzer::analyze($about_area, [
            'city_state'    => $city_state,
            'focus_keyword' => $focus_keyword,
        ]);
    }

    // ── Completeness audit ──
    $completeness = [];
    $completeness['has_content']       = $quality['words'] > 50;
    $completeness['has_meta_title']    = trim($yoast_title) !== '';
    $completeness['has_meta_desc']     = trim($yoast_desc) !== '';
    $completeness['has_focus_keyword'] = trim($focus_keyword) !== '';
    $completeness['has_excerpt']       = trim($excerpt) !== '' || trim($html_excerpt) !== '';
    $completeness['has_about_area']    = trim(wp_strip_all_tags($about_area)) !== '';
    $completeness['has_faqs']          = trim(wp_strip_all_tags($faq_html)) !== '';
    $completeness['has_tagline']       = trim($tagline) !== '';
    $completeness['has_h2']            = $quality['h2_count'] > 0;
    $completeness['has_h3']            = $quality['h3_count'] > 0;
    $completeness['has_lists']         = $quality['ul_count'] > 0;
    $completeness['has_links']         = $quality['link_count'] > 0;
    $completeness['has_location_ref']  = $quality['location_mentions'] > 0;

    // Score: percentage of checks that pass
    $passed = array_sum(array_map('intval', $completeness));
    $total_checks = count($completeness);
    $score = round(($passed / $total_checks) * 100);

    // ── Recommendations ──
    $recommendations = [];

    if ( ! $completeness['has_meta_title'] ) {
        $recommendations[] = ['priority'=>'high', 'area'=>'Meta Title', 'action'=>'Add a Yoast SEO title with focus keyword.'];
    }
    if ( ! $completeness['has_meta_desc'] ) {
        $recommendations[] = ['priority'=>'high', 'area'=>'Meta Description', 'action'=>'Write a compelling meta description (150-160 chars).'];
    }
    if ( ! $completeness['has_focus_keyword'] ) {
        $recommendations[] = ['priority'=>'high', 'area'=>'Focus Keyword', 'action'=>'Set a focus keyword in Yoast for this page.'];
    }
    if ( $quality['words'] < 300 ) {
        $recommendations[] = ['priority'=>'high', 'area'=>'Content Length', 'action'=>'Content is thin (' . $quality['words'] . ' words). Aim for 500+ words.'];
    } elseif ( $quality['words'] < 500 ) {
        $recommendations[] = ['priority'=>'medium', 'area'=>'Content Length', 'action'=>'Content is light (' . $quality['words'] . ' words). Consider expanding to 600+.'];
    }
    if ( ! $completeness['has_h2'] && ! $completeness['has_h3'] ) {
        $recommendations[] = ['priority'=>'high', 'area'=>'Headings', 'action'=>'No headings found. Add H2/H3 headings to structure content.'];
    }
    if ( ! $completeness['has_location_ref'] && $city_state !== '' ) {
        $recommendations[] = ['priority'=>'medium', 'area'=>'Local Signals', 'action'=>'No location references found. Mention "' . $city_state . '" in the content.'];
    }
    if ( $quality['opening_match'] !== '(none)' ) {
        $recommendations[] = ['priority'=>'medium', 'area'=>'Uniqueness', 'action'=>'Stock opening "' . $quality['opening_match'] . '…" detected. Use a more unique intro.'];
    }
    if ( ! $completeness['has_about_area'] ) {
        $recommendations[] = ['priority'=>'medium', 'area'=>'About Area', 'action'=>'No About the Area content. Generate one in the AI → About Area tab.'];
    }
    if ( ! $completeness['has_faqs'] ) {
        $recommendations[] = ['priority'=>'medium', 'area'=>'FAQs', 'action'=>'No FAQ content. Generate FAQs in the AI → FAQs tab.'];
    }
    if ( ! $completeness['has_excerpt'] ) {
        $recommendations[] = ['priority'=>'low', 'area'=>'Excerpt', 'action'=>'No excerpt set. Generate one in the AI → Excerpts tab.'];
    }
    if ( ! $completeness['has_tagline'] ) {
        $recommendations[] = ['priority'=>'low', 'area'=>'Tagline', 'action'=>'No service tagline. Generate one in the AI → Taglines tab.'];
    }
    if ( ! $completeness['has_lists'] ) {
        $recommendations[] = ['priority'=>'low', 'area'=>'Lists', 'action'=>'No lists found. Bullet points improve scannability.'];
    }
    if ( ! $completeness['has_links'] ) {
        $recommendations[] = ['priority'=>'low', 'area'=>'Internal Links', 'action'=>'No links found. Add internal links to related service pages.'];
    }
    if ( $quality['readability_grade'] > 14 ) {
        $recommendations[] = ['priority'=>'medium', 'area'=>'Readability', 'action'=>'Content reads at grade ' . $quality['readability_grade'] . '. Simplify sentences for broader audience.'];
    }
    if ( $quality['avg_sentence_len'] > 25 ) {
        $recommendations[] = ['priority'=>'low', 'area'=>'Sentence Length', 'action'=>'Avg sentence is ' . $quality['avg_sentence_len'] . ' words. Aim for 15-20 for readability.'];
    }
    if ( $focus_keyword !== '' && $quality['keyword_density'] < 0.5 ) {
        $recommendations[] = ['priority'=>'medium', 'area'=>'Keyword Usage', 'action'=>'Focus keyword "' . $focus_keyword . '" density is low (' . $quality['keyword_density'] . '%). Use it more naturally.'];
    } elseif ( $focus_keyword !== '' && $quality['keyword_density'] > 3.0 ) {
        $recommendations[] = ['priority'=>'medium', 'area'=>'Keyword Stuffing', 'action'=>'Focus keyword density is ' . $quality['keyword_density'] . '%. Consider reducing to avoid over-optimization.'];
    }

    // Meta desc length check
    if ( $yoast_desc !== '' ) {
        $desc_len = strlen($yoast_desc);
        if ( $desc_len < 120 ) {
            $recommendations[] = ['priority'=>'low', 'area'=>'Meta Description', 'action'=>'Meta description is short (' . $desc_len . ' chars). Aim for 150-160.'];
        } elseif ( $desc_len > 160 ) {
            $recommendations[] = ['priority'=>'low', 'area'=>'Meta Description', 'action'=>'Meta description may truncate (' . $desc_len . ' chars). Keep under 160.'];
        }
    }

    // Sort recommendations by priority
    $priority_order = ['high'=>1, 'medium'=>2, 'low'=>3];
    usort($recommendations, function($a, $b) use ($priority_order) {
        return ($priority_order[$a['priority']] ?? 9) <=> ($priority_order[$b['priority']] ?? 9);
    });

    wp_send_json_success([
        'post_id'         => $post_id,
        'title'           => (string) $title,
        'url'             => (string) $url,
        'status'          => get_post_status($post_id),
        'score'           => $score,
        'quality'         => $quality,
        'about_quality'   => $about_quality,
        'completeness'    => $completeness,
        'recommendations' => $recommendations,
        'meta'            => [
            'yoast_title'    => $yoast_title,
            'yoast_desc'     => $yoast_desc,
            'focus_keyword'  => $focus_keyword,
            'excerpt_len'    => strlen($excerpt),
            'html_excerpt'   => trim($html_excerpt) !== '',
            'tagline'        => $tagline,
            'city_state'     => $city_state,
            'about_words'    => $about_quality ? $about_quality['words'] : 0,
            'faq_present'    => trim(wp_strip_all_tags($faq_html)) !== '',
        ],
    ]);
});
