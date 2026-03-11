<?php
/**
 * AIntelligize — AI Exposure AJAX Endpoints
 * Path: inc/ajax/ai-exposure-check.php
 *
 * Provides endpoints for the AI Exposure dashboard:
 *   - Load data, check ChatGPT/Claude, manage keywords/competitors, history, purge.
 *
 * All endpoints verify nonce 'myls_ai_exposure' and require 'manage_options'.
 *
 * @since 7.9.0
 */

if ( ! defined('ABSPATH') ) exit;

/* ═══════════════════════════════════════════════════════
 *  Helper: domain detection in AI responses
 * ═══════════════════════════════════════════════════════ */

/**
 * Parse an AI response text for domain/URL mentions.
 *
 * @param string $text          The AI response text.
 * @param string $own_domain    The user's domain (e.g. "example.com").
 * @param string $brand_name    The organization/brand name.
 * @param array  $competitors   Manual competitor domains to flag.
 * @return array {
 *   cited: bool, citation_count: int, source_position: int|null,
 *   citations_found: array, competitor_domains: array
 * }
 */
function myls_ae_parse_response( $text, $own_domain, $brand_name = '', $competitors = [] ) {
    $result = [
        'cited'              => false,
        'citation_count'     => 0,
        'source_position'    => null,
        'citations_found'    => [],
        'competitor_domains' => [],
    ];

    if ( ! $text ) return $result;

    // Normalize own domain (strip www.)
    $own_domain   = strtolower( preg_replace('/^www\./', '', trim($own_domain)) );
    $brand_lower  = strtolower( trim($brand_name) );

    // Extract all URLs from response
    preg_match_all('#https?://([^\s<>"\')\]]+)#i', $text, $url_matches);
    $found_urls = $url_matches[0] ?? [];

    // Extract all domain-like patterns (e.g. "example.com")
    preg_match_all('#\b([a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.(?:com|org|net|io|co|biz|info|me|us|ca|uk|au|de|fr|es|it|nl|pt|br|jp|kr|in|za))\b#i', $text, $domain_matches);
    $found_domains = $domain_matches[1] ?? [];

    // Collect all unique domains
    $all_domains = [];
    foreach ( $found_urls as $url ) {
        $parsed = wp_parse_url($url);
        if ( isset($parsed['host']) ) {
            $host = strtolower( preg_replace('/^www\./', '', $parsed['host']) );
            $all_domains[$host] = true;
            $result['citations_found'][] = $url;
        }
    }
    foreach ( $found_domains as $d ) {
        $d = strtolower( preg_replace('/^www\./', '', $d) );
        $all_domains[$d] = true;
    }

    // Check if own domain is cited
    $own_url_count = 0;
    foreach ( array_keys($all_domains) as $d ) {
        if ( $d === $own_domain || strpos($d, $own_domain) !== false ) {
            $result['cited'] = true;
            $own_url_count++;
        }
    }

    // Also check for brand name mention (case-insensitive)
    if ( ! $result['cited'] && $brand_lower && strlen($brand_lower) > 2 ) {
        if ( stripos($text, $brand_lower) !== false ) {
            $result['cited'] = true;
            $own_url_count = max(1, $own_url_count);
        }
    }

    $result['citation_count'] = $own_url_count;

    // Detect source position: look for numbered lists where our domain appears
    // Pattern: "1. domain.com" or "1) domain.com" or "#1: domain.com"
    $lines = preg_split('/\r?\n/', $text);
    $position = 1;
    foreach ( $lines as $line ) {
        $line = trim($line);
        if ( preg_match('/^(?:\d+[\.\):]|\*|-)\s/', $line) ) {
            $line_lower = strtolower($line);
            if ( strpos($line_lower, $own_domain) !== false || ($brand_lower && stripos($line_lower, $brand_lower) !== false) ) {
                $result['source_position'] = $position;
                break;
            }
            $position++;
        }
    }

    // Competitor domains: any domain that isn't our own
    $comp_domains = [];
    $manual_comps = array_map('strtolower', $competitors);
    foreach ( array_keys($all_domains) as $d ) {
        if ( $d === $own_domain || strpos($d, $own_domain) !== false ) continue;
        // Skip common non-competitor domains
        $skip = ['google.com', 'youtube.com', 'wikipedia.org', 'facebook.com', 'twitter.com',
                 'linkedin.com', 'instagram.com', 'reddit.com', 'pinterest.com', 'amazon.com',
                 'yelp.com', 'bbb.org', 'github.com', 'stackoverflow.com', 'medium.com',
                 'x.com', 'tiktok.com', 'apple.com', 'microsoft.com'];
        if ( in_array($d, $skip, true) ) continue;
        $comp_domains[] = $d;
    }
    $result['competitor_domains'] = array_values(array_unique($comp_domains));

    return $result;
}

/**
 * Build the AI prompt for exposure checking.
 */
function myls_ae_build_prompt( $keyword, $platform ) {
    $site_name = get_bloginfo('name');
    $location  = trim( (string) get_option('myls_org_city', '') );
    $state     = trim( (string) get_option('myls_org_state', '') );
    $geo       = '';
    if ( $location ) {
        $geo = " in {$location}";
        if ( $state ) $geo .= ", {$state}";
    }

    $prompt = "I'm looking for the best providers or resources for \"{$keyword}\"{$geo}. ";
    $prompt .= "Please recommend specific companies, websites, or services. ";
    $prompt .= "Include their website URLs when possible. ";
    $prompt .= "List them in order of relevance and explain briefly why you recommend each one.";

    return $prompt;
}

/* ═══════════════════════════════════════════════════════
 *  AJAX: Load All Exposure Data
 * ═══════════════════════════════════════════════════════ */

add_action('wp_ajax_myls_ae_load', function() {
    check_ajax_referer('myls_ai_exposure', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Forbidden', 403);

    $days = isset($_POST['days']) ? absint($_POST['days']) : 30;

    wp_send_json_success([
        'rows'  => myls_ae_get_all($days),
        'stats' => myls_ae_get_stats($days),
        'total' => myls_ae_get_total_rows(),
    ]);
});

/* ═══════════════════════════════════════════════════════
 *  AJAX: Check ChatGPT
 * ═══════════════════════════════════════════════════════ */

add_action('wp_ajax_myls_ae_check_chatgpt', function() {
    check_ajax_referer('myls_ai_exposure', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Forbidden', 403);

    $keyword = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
    $sd_id   = isset($_POST['sd_id']) && $_POST['sd_id'] ? absint($_POST['sd_id']) : null;
    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

    if ( ! $keyword ) {
        wp_send_json_error('Keyword is required.');
    }

    // Check API key
    $key = myls_openai_get_api_key();
    if ( ! $key ) {
        wp_send_json_error('OpenAI API key not configured. Set it in AIntelligize > API Integration.');
    }

    $domain     = strtolower( preg_replace('/^www\./', '', wp_parse_url( home_url(), PHP_URL_HOST ) ) );
    $brand_name = trim( (string) get_option('myls_org_name', get_bloginfo('name')) );
    $competitors = myls_ae_get_manual_competitors();

    // Set usage context for AI usage logging
    if ( function_exists('myls_ai_set_usage_context') ) {
        myls_ai_set_usage_context('ai_exposure', $post_id);
    }

    $prompt = myls_ae_build_prompt($keyword, 'chatgpt');
    $model  = 'gpt-4o-mini';

    $system = 'You are a knowledgeable assistant helping users find the best services and resources. '
            . 'Always include specific website URLs in your recommendations when you know them. '
            . 'Be thorough and list multiple options.';

    $response = myls_openai_chat($prompt, [
        'model'       => $model,
        'max_tokens'  => 1500,
        'temperature' => 0.7,
        'system'      => $system,
    ]);

    if ( $response === '' ) {
        $error = $GLOBALS['myls_ai_last_error'] ?? 'Unknown error';
        $rid = myls_ae_save_result([
            'keyword'       => $keyword,
            'sd_id'         => $sd_id,
            'post_id'       => $post_id,
            'domain'        => $domain,
            'platform'      => 'chatgpt',
            'model_used'    => $model,
            'cited'         => 0,
            'prompt_used'   => $prompt,
            'error_message' => $error,
        ]);
        wp_send_json_error(['message' => $error, 'row_id' => $rid]);
    }

    // Parse the response
    $parsed = myls_ae_parse_response($response, $domain, $brand_name, $competitors);

    // Estimate tokens/cost
    $prompt_tokens  = (int) ceil(mb_strlen($prompt) / 4);
    $output_tokens  = (int) ceil(mb_strlen($response) / 4);
    $total_tokens   = $prompt_tokens + $output_tokens;
    // gpt-4o-mini: $0.15/1M input, $0.60/1M output
    $cost = ($prompt_tokens * 0.15 / 1000000) + ($output_tokens * 0.60 / 1000000);

    $rid = myls_ae_save_result([
        'keyword'            => $keyword,
        'sd_id'              => $sd_id,
        'post_id'            => $post_id,
        'domain'             => $domain,
        'platform'           => 'chatgpt',
        'model_used'         => $model,
        'cited'              => $parsed['cited'] ? 1 : 0,
        'citation_count'     => $parsed['citation_count'],
        'source_position'    => $parsed['source_position'],
        'response_text'      => $response,
        'citations_found'    => $parsed['citations_found'],
        'competitor_domains' => $parsed['competitor_domains'],
        'prompt_used'        => $prompt,
        'tokens_used'        => $total_tokens,
        'cost_usd'           => $cost,
    ]);

    // Auto-snapshot
    myls_ae_snapshot($keyword, $domain);

    wp_send_json_success([
        'row_id'             => $rid,
        'keyword'            => $keyword,
        'platform'           => 'chatgpt',
        'cited'              => $parsed['cited'],
        'citation_count'     => $parsed['citation_count'],
        'source_position'    => $parsed['source_position'],
        'citations_found'    => $parsed['citations_found'],
        'competitor_domains' => $parsed['competitor_domains'],
        'tokens'             => $total_tokens,
        'cost'               => round($cost, 6),
    ]);
});

/* ═══════════════════════════════════════════════════════
 *  AJAX: Check Claude
 * ═══════════════════════════════════════════════════════ */

add_action('wp_ajax_myls_ae_check_claude', function() {
    check_ajax_referer('myls_ai_exposure', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Forbidden', 403);

    $keyword = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
    $sd_id   = isset($_POST['sd_id']) && $_POST['sd_id'] ? absint($_POST['sd_id']) : null;
    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

    if ( ! $keyword ) {
        wp_send_json_error('Keyword is required.');
    }

    // Check API key
    $key = trim( (string) get_option('myls_anthropic_api_key', '') );
    if ( ! $key ) {
        wp_send_json_error('Anthropic API key not configured. Set it in AIntelligize > API Integration.');
    }

    $domain     = strtolower( preg_replace('/^www\./', '', wp_parse_url( home_url(), PHP_URL_HOST ) ) );
    $brand_name = trim( (string) get_option('myls_org_name', get_bloginfo('name')) );
    $competitors = myls_ae_get_manual_competitors();

    // Set usage context
    if ( function_exists('myls_ai_set_usage_context') ) {
        myls_ai_set_usage_context('ai_exposure', $post_id);
    }

    $prompt = myls_ae_build_prompt($keyword, 'claude');
    $model  = 'claude-haiku-4-5-20251001';

    $system = 'You are a knowledgeable assistant helping users find the best services and resources. '
            . 'Always include specific website URLs in your recommendations when you know them. '
            . 'Be thorough and list multiple options.';

    $response = myls_anthropic_chat($prompt, [
        'model'       => $model,
        'max_tokens'  => 1500,
        'temperature' => 0.7,
        'system'      => $system,
    ]);

    if ( $response === '' ) {
        $error = $GLOBALS['myls_ai_last_error'] ?? 'Unknown error';
        $rid = myls_ae_save_result([
            'keyword'       => $keyword,
            'sd_id'         => $sd_id,
            'post_id'       => $post_id,
            'domain'        => $domain,
            'platform'      => 'claude',
            'model_used'    => $model,
            'cited'         => 0,
            'prompt_used'   => $prompt,
            'error_message' => $error,
        ]);
        wp_send_json_error(['message' => $error, 'row_id' => $rid]);
    }

    // Parse the response
    $parsed = myls_ae_parse_response($response, $domain, $brand_name, $competitors);

    // Estimate tokens/cost
    $prompt_tokens  = (int) ceil(mb_strlen($prompt) / 4);
    $output_tokens  = (int) ceil(mb_strlen($response) / 4);
    $total_tokens   = $prompt_tokens + $output_tokens;
    // claude-haiku: $1.00/1M input, $5.00/1M output
    $cost = ($prompt_tokens * 1.00 / 1000000) + ($output_tokens * 5.00 / 1000000);

    $rid = myls_ae_save_result([
        'keyword'            => $keyword,
        'sd_id'              => $sd_id,
        'post_id'            => $post_id,
        'domain'             => $domain,
        'platform'           => 'claude',
        'model_used'         => $model,
        'cited'              => $parsed['cited'] ? 1 : 0,
        'citation_count'     => $parsed['citation_count'],
        'source_position'    => $parsed['source_position'],
        'response_text'      => $response,
        'citations_found'    => $parsed['citations_found'],
        'competitor_domains' => $parsed['competitor_domains'],
        'prompt_used'        => $prompt,
        'tokens_used'        => $total_tokens,
        'cost_usd'           => $cost,
    ]);

    // Auto-snapshot
    myls_ae_snapshot($keyword, $domain);

    wp_send_json_success([
        'row_id'             => $rid,
        'keyword'            => $keyword,
        'platform'           => 'claude',
        'cited'              => $parsed['cited'],
        'citation_count'     => $parsed['citation_count'],
        'source_position'    => $parsed['source_position'],
        'citations_found'    => $parsed['citations_found'],
        'competitor_domains' => $parsed['competitor_domains'],
        'tokens'             => $total_tokens,
        'cost'               => round($cost, 6),
    ]);
});

/* ═══════════════════════════════════════════════════════
 *  AJAX: Get Keywords (merged: Search Demand + custom)
 * ═══════════════════════════════════════════════════════ */

add_action('wp_ajax_myls_ae_get_keywords', function() {
    check_ajax_referer('myls_ai_exposure', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Forbidden', 403);

    global $wpdb;
    $sd_table = function_exists('myls_sd_table_name') ? myls_sd_table_name() : '';
    $keywords = [];

    // Get from Search Demand table
    if ( $sd_table ) {
        $sd_rows = $wpdb->get_results(
            "SELECT id, keyword, post_id, post_title, post_type, source FROM {$sd_table} ORDER BY keyword",
            ARRAY_A
        );
        foreach ( $sd_rows as $r ) {
            $keywords[] = [
                'keyword'    => $r['keyword'],
                'sd_id'      => (int) $r['id'],
                'post_id'    => (int) $r['post_id'],
                'post_title' => $r['post_title'],
                'post_type'  => $r['post_type'],
                'source'     => $r['source'],
                'custom'     => false,
            ];
        }
    }

    // Get custom keywords
    $custom = myls_ae_get_custom_keywords();
    foreach ( $custom as $kw ) {
        $keywords[] = [
            'keyword'    => $kw,
            'sd_id'      => null,
            'post_id'    => 0,
            'post_title' => '',
            'post_type'  => '',
            'source'     => 'Custom',
            'custom'     => true,
        ];
    }

    wp_send_json_success([
        'keywords' => $keywords,
        'total'    => count($keywords),
    ]);
});

/* ═══════════════════════════════════════════════════════
 *  AJAX: Add / Delete Custom Keyword
 * ═══════════════════════════════════════════════════════ */

add_action('wp_ajax_myls_ae_add_keyword', function() {
    check_ajax_referer('myls_ai_exposure', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Forbidden', 403);

    $keyword = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
    if ( ! $keyword ) wp_send_json_error('Keyword is required.');

    $kws = myls_ae_add_custom_keyword($keyword);
    wp_send_json_success(['keywords' => $kws]);
});

add_action('wp_ajax_myls_ae_delete_keyword', function() {
    check_ajax_referer('myls_ai_exposure', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Forbidden', 403);

    $keyword = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
    if ( ! $keyword ) wp_send_json_error('Keyword is required.');

    $kws = myls_ae_remove_custom_keyword($keyword);
    wp_send_json_success(['keywords' => $kws]);
});

/* ═══════════════════════════════════════════════════════
 *  AJAX: Competitors
 * ═══════════════════════════════════════════════════════ */

add_action('wp_ajax_myls_ae_get_competitors', function() {
    check_ajax_referer('myls_ai_exposure', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Forbidden', 403);

    $days = isset($_POST['days']) ? absint($_POST['days']) : 30;

    wp_send_json_success([
        'manual'        => myls_ae_get_manual_competitors(),
        'auto_detected' => myls_ae_get_auto_competitors($days),
    ]);
});

add_action('wp_ajax_myls_ae_save_competitors', function() {
    check_ajax_referer('myls_ai_exposure', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Forbidden', 403);

    $domains_raw = wp_unslash( $_POST['domains'] ?? '[]' );
    $domains = json_decode( $domains_raw, true );
    if ( ! is_array($domains) ) $domains = [];

    $saved = myls_ae_save_manual_competitors($domains);
    wp_send_json_success(['domains' => $saved]);
});

/* ═══════════════════════════════════════════════════════
 *  AJAX: History
 * ═══════════════════════════════════════════════════════ */

add_action('wp_ajax_myls_ae_history', function() {
    check_ajax_referer('myls_ai_exposure', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Forbidden', 403);

    $keyword = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
    $limit   = isset($_POST['limit']) ? absint($_POST['limit']) : 90;

    if ( ! $keyword ) wp_send_json_error('Keyword is required.');

    $domain = strtolower( preg_replace('/^www\./', '', wp_parse_url( home_url(), PHP_URL_HOST ) ) );

    wp_send_json_success([
        'keyword' => $keyword,
        'history' => myls_ae_get_history($keyword, $domain, $limit),
    ]);
});

/* ═══════════════════════════════════════════════════════
 *  AJAX: Purge
 * ═══════════════════════════════════════════════════════ */

add_action('wp_ajax_myls_ae_purge', function() {
    check_ajax_referer('myls_ai_exposure', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Forbidden', 403);

    $days = isset($_POST['days']) ? absint($_POST['days']) : 90;
    $result = myls_ae_purge($days);

    wp_send_json_success(['deleted' => $result, 'days' => $days]);
});

/* ═══════════════════════════════════════════════════════
 *  AJAX: Overview (lightweight, for aggregated dashboard)
 * ═══════════════════════════════════════════════════════ */

add_action('wp_ajax_myls_ae_overview', function() {
    check_ajax_referer('myls_ai_exposure', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Forbidden', 403);

    $days = isset($_GET['days']) ? absint($_GET['days']) : 30;
    wp_send_json_success( myls_ae_get_summary($days) );
});
