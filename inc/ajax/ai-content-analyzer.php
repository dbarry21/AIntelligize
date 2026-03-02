<?php
/**
 * AIntelligize – AJAX: Content Analyzer
 * File: inc/ajax/ai-content-analyzer.php
 *
 * Endpoints:
 *  - wp_ajax_myls_content_analyze_v1   (batch analyze selected posts)
 *  - wp_ajax_myls_content_analyze_get_posts_v1
 *  - wp_ajax_myls_content_analyze_ai_deep_v1  (AI deep analysis: writing, citation, gaps, rewrites)
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

/* =============================================================================
 * AI Deep Analysis
 * Runs a full AI-powered deep-dive on a single post: writing quality critique,
 * AI citation readiness, competitor gap suggestions, and rewrite recommendations.
 *
 * Action: wp_ajax_myls_content_analyze_ai_deep_v1
 * @since 7.7.0
 * ============================================================================= */
add_action( 'wp_ajax_myls_content_analyze_ai_deep_v1', function () {

    $nonce = $_POST['nonce'] ?? $_REQUEST['_ajax_nonce'] ?? '';
    if ( ! wp_verify_nonce( (string) $nonce, 'myls_ai_ops' ) ) {
        wp_send_json_error( [ 'message' => 'bad_nonce' ], 403 );
    }

    $post_id = (int) ( $_POST['post_id'] ?? 0 );
    if ( $post_id <= 0 || get_post_status( $post_id ) === false ) {
        wp_send_json_error( [ 'message' => 'bad_post' ], 400 );
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( [ 'message' => 'cap_denied' ], 403 );
    }
    if ( ! function_exists( 'myls_ai_chat' ) ) {
        wp_send_json_error( [ 'message' => 'AI helper not available. Check OpenAI API key.' ], 500 );
    }

    /* ── Gather page data ──────────────────────────────────────────────── */
    $post           = get_post( $post_id );
    $title          = get_the_title( $post_id );
    $url            = get_permalink( $post_id );
    $raw_content    = $post ? $post->post_content : '';
    $html_rendered  = do_shortcode( $raw_content );
    $plain_text     = wp_strip_all_tags( $html_rendered );
    $plain_text     = preg_replace( '/\s+/', ' ', trim( $plain_text ) );
    $content_sample = mb_substr( $plain_text, 0, 3000 ); // cap tokens

    $focus_keyword  = (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw',  true );
    $yoast_title    = (string) get_post_meta( $post_id, '_yoast_wpseo_title',    true );
    $yoast_desc     = (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
    $city_state     = (string) get_post_meta( $post_id, '_myls_city_state',      true );
    $about_area     = (string) get_post_meta( $post_id, '_about_the_area',       true );
    $faq_html       = (string) get_post_meta( $post_id, '_myls_faq_html',        true );
    $tagline        = (string) get_post_meta( $post_id, '_myls_service_tagline', true );
    $word_count     = str_word_count( $plain_text );

    // Schema detection — look for JSON-LD in raw content or head
    $has_faqpage_schema  = ( strpos( $raw_content, 'FAQPage' )    !== false );
    $has_service_schema  = ( strpos( $raw_content, '"Service"' )  !== false );
    $has_localbiz_schema = ( strpos( $raw_content, 'LocalBusiness' ) !== false );

    /* ── Build AI prompt ───────────────────────────────────────────────── */
    $business_name = get_option( 'myls_sb_settings', [] )['business_name'] ?? get_bloginfo( 'name' );

    $prompt = <<<PROMPT
You are an expert SEO content analyst and local business marketing consultant. Perform a deep analysis of the following web page and return a structured report covering all four sections below.

## Page Information
- Business: {$business_name}
- Page Title: {$title}
- URL: {$url}
- Focus Keyword: {$focus_keyword}
- Location: {$city_state}
- Word Count: {$word_count}
- Yoast Title: {$yoast_title}
- Meta Description: {$yoast_desc}
- Tagline: {$tagline}
- Has FAQPage Schema: {$has_faqpage_schema}
- Has Service Schema: {$has_service_schema}
- Has LocalBusiness Schema: {$has_localbiz_schema}
- Has FAQ Content: {$has_faq_html}
- Has About the Area: {$about_area}

## Page Content (first 3000 chars)
{$content_sample}

---

Provide your analysis in exactly this format with these four sections. Be specific, practical, and actionable. Do not pad with generic advice.

### 1. WRITING QUALITY & TONE
Evaluate: clarity, engagement, brand voice, sentence variety, passive voice overuse, jargon, and emotional resonance. Call out specific weak phrases or sentences if you can find them in the sample. Rate overall tone: Professional / Conversational / Too Formal / Too Generic.

### 2. AI CITATION READINESS
Evaluate this page's likelihood of being cited by AI assistants (ChatGPT, Perplexity, Gemini, etc.). Consider: schema markup presence, E-E-A-T signals (expertise, experience, authority, trust), FAQ coverage, factual specificity, structured data, and whether the content answers the kinds of questions AI systems pull from. Give a readiness score: High / Medium / Low — and explain why.

### 3. COMPETITOR GAP OPPORTUNITIES
Based on the topic and location, identify 3–5 content gaps or angles that competitors in this space typically exploit that this page is missing. Think: unique trust signals, local proof points, process transparency, comparison content, or specific pain-point targeting.

### 4. PRIORITY REWRITE RECOMMENDATIONS
List 3–5 specific, high-impact improvements with enough detail to act on immediately. For each: state what to change, why it matters, and what the improved version should accomplish. Focus on the highest ROI changes first.
PROMPT;

    /* ── Call AI ────────────────────────────────────────────────────────── */
    $ai_response = myls_ai_chat( $prompt, [
        'max_tokens'  => 1400,
        'temperature' => 0.4,
        'system'      => 'You are a senior SEO content strategist specializing in local service businesses. You give brutally honest, specific, actionable analysis. No fluff. No generic advice. Output plain text with the section headers exactly as instructed.',
    ] );

    if ( empty( $ai_response ) ) {
        wp_send_json_error( [ 'message' => 'AI returned an empty response. Check your API key and quota.' ], 500 );
    }

    wp_send_json_success( [
        'post_id'  => $post_id,
        'title'    => $title,
        'url'      => $url,
        'analysis' => $ai_response,
        'meta'     => [
            'word_count'     => $word_count,
            'focus_keyword'  => $focus_keyword,
            'city_state'     => $city_state,
            'has_schema'     => $has_faqpage_schema || $has_service_schema || $has_localbiz_schema,
        ],
    ] );
} );

/* =============================================================================
 * PDF Report Download — AI Deep Analysis
 *
 * Accepts the collected results as JSON POST, generates a binary PDF using
 * MYLS_AI_Deep_Report, and streams it as a file download.
 *
 * Action: wp_ajax_myls_ca_deep_pdf_v1
 * @since 7.7.0
 * ============================================================================= */
add_action( 'wp_ajax_myls_ca_deep_pdf_v1', function () {

    $nonce = $_POST['nonce'] ?? '';
    if ( ! wp_verify_nonce( (string) $nonce, 'myls_ai_ops' ) ) {
        wp_send_json_error( [ 'message' => 'bad_nonce' ], 403 );
    }
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( [ 'message' => 'cap_denied' ], 403 );
    }

    $raw     = stripslashes( $_POST['results'] ?? '[]' );
    $results = json_decode( $raw, true );

    if ( ! is_array( $results ) || empty( $results ) ) {
        wp_send_json_error( [ 'message' => 'No analysis results provided.' ], 400 );
    }

    // Sanitize result fields (titles/URLs are from our own DB, analysis from AI)
    foreach ( $results as &$r ) {
        $r['title']    = sanitize_text_field( $r['title'] ?? '' );
        $r['url']      = esc_url_raw( $r['url'] ?? '' );
        $r['analysis'] = wp_kses_post( $r['analysis'] ?? '' );
        $r['meta']     = array_map( 'sanitize_text_field', (array) ( $r['meta'] ?? [] ) );
        // Preserve boolean 'has_schema'
        $r['meta']['has_schema'] = ! empty( $r['meta']['has_schema'] ) && $r['meta']['has_schema'] !== 'false';
        $r['meta']['word_count'] = (int) ( $r['meta']['word_count'] ?? 0 );
    }
    unset( $r );

    require_once MYLS_PATH . 'inc/pdf/ai-deep-report.php';

    $report   = new MYLS_AI_Deep_Report( $results );
    $pdf_data = $report->generate();

    $filename = 'ai-deep-analysis-' . gmdate( 'Ymd-His' ) . '.pdf';

    // Clear any previous output
    if ( ob_get_level() ) {
        ob_end_clean();
    }

    header( 'Content-Type: application/pdf' );
    header( 'Content-Disposition: attachment; filename="' . rawurlencode( $filename ) . '"' );
    header( 'Content-Length: ' . strlen( $pdf_data ) );
    header( 'Cache-Control: private, max-age=0, must-revalidate' );
    header( 'Pragma: public' );
    header( 'X-Robots-Tag: noindex' );

    echo $pdf_data;
    exit;
} );
