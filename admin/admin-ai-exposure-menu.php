<?php
/**
 * AIntelligize — AI Exposure Menu & Scripts
 * Path: admin/admin-ai-exposure-menu.php
 *
 * Registers the "AI Exposure" submenu page under AIntelligize and
 * enqueues the dashboard CSS/JS. All AJAX endpoints are in
 * inc/ajax/ai-exposure-check.php (myls_ae_* actions).
 *
 * @since 7.9.0
 */
if ( ! defined('ABSPATH') ) exit;

/* ═══════════════════════════════════════════════════════
 *  1. SUBMENU REGISTRATION
 * ═══════════════════════════════════════════════════════ */

add_action('admin_menu', 'myls_ai_exposure_add_submenu', 28);

function myls_ai_exposure_add_submenu() {
    add_submenu_page(
        'aintelligize',
        'AI Exposure — Chatbot Visibility',
        '&mdash; AI Exposure',
        'manage_options',
        'myls-ai-exposure',
        'myls_ai_exposure_render_page'
    );
}

function myls_ai_exposure_render_page() {
    ?>
    <div class="wrap">
        <div id="myls-ai-exposure-root"></div>
    </div>
    <?php
}

/* ═══════════════════════════════════════════════════════
 *  2. ENQUEUE SCRIPTS & STYLES
 * ═══════════════════════════════════════════════════════ */

add_action('admin_enqueue_scripts', 'myls_ai_exposure_admin_scripts');

function myls_ai_exposure_admin_scripts( $hook ) {
    if ( $hook !== 'aintelligize_page_myls-ai-exposure' ) return;

    $base_url = plugin_dir_url( dirname(__FILE__) );
    $version  = defined('MYLS_VERSION') ? MYLS_VERSION : time();

    // Chart.js from CDN
    wp_enqueue_script('chartjs', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js', [], '4.4.1', true);

    // Bootstrap Icons
    wp_enqueue_style('bootstrap-icons', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css', [], '1.11.3');

    // Shared design system
    wp_enqueue_style('myls-stats-css', $base_url . 'admin/stats/stats-dashboard.css', [], $version);

    // AI Exposure specific CSS
    wp_enqueue_style('myls-ai-exposure-css', $base_url . 'admin/ai-exposure/ai-exposure.css', ['myls-stats-css'], $version);

    // Dashboard JS
    wp_enqueue_script('myls-ai-exposure-js', $base_url . 'admin/ai-exposure/ai-exposure.js', ['chartjs', 'jquery'], $version, true);

    // Check API key availability
    $has_openai    = trim( (string) myls_openai_get_api_key() ) !== '';
    $has_anthropic = trim( (string) get_option('myls_anthropic_api_key', '') ) !== '';

    wp_localize_script('myls-ai-exposure-js', 'MYLS_AE', [
        'ajaxurl'        => admin_url('admin-ajax.php'),
        'nonce'          => wp_create_nonce('myls_ai_exposure'),
        'has_openai'     => $has_openai,
        'has_anthropic'  => $has_anthropic,
        'site_domain'    => strtolower( preg_replace('/^www\./', '', wp_parse_url( home_url(), PHP_URL_HOST ) ) ),
        'site_name'      => get_bloginfo('name'),
        'api_tab_url'    => admin_url('admin.php?page=aintelligize&tab=api-integration'),
    ]);
}
