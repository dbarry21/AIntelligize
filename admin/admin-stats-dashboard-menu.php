<?php
/**
 * AIntelligize — Stats Dashboard (Aggregated Overview)
 * Path: admin/admin-stats-dashboard-menu.php
 *
 * Registers the "AIntelligize Stats" parent submenu page that shows an
 * aggregated KPI dashboard pulling from AI Usage, Search Demand, and AI Exposure.
 *
 * @since 7.9.0
 */
if ( ! defined('ABSPATH') ) exit;

/* ═══════════════════════════════════════════════════════
 *  1. SUBMENU REGISTRATION
 * ═══════════════════════════════════════════════════════ */

add_action('admin_menu', 'myls_stats_dashboard_add_submenu', 25);

function myls_stats_dashboard_add_submenu() {
    add_submenu_page(
        'aintelligize',
        'AIntelligize Stats — Overview',
        'AIntelligize Stats',
        'manage_options',
        'myls-stats-dashboard',
        'myls_stats_dashboard_render_page'
    );
}

function myls_stats_dashboard_render_page() {
    ?>
    <div class="wrap">
        <div id="myls-stats-dash-root"></div>
    </div>
    <?php
}

/* ═══════════════════════════════════════════════════════
 *  2. ENQUEUE SCRIPTS & STYLES
 * ═══════════════════════════════════════════════════════ */

add_action('admin_enqueue_scripts', 'myls_stats_dashboard_admin_scripts');

function myls_stats_dashboard_admin_scripts( $hook ) {
    if ( $hook !== 'aintelligize_page_myls-stats-dashboard' ) return;

    $base_url = plugin_dir_url( dirname(__FILE__) );
    $version  = defined('MYLS_VERSION') ? MYLS_VERSION : time();

    // Chart.js from CDN
    wp_enqueue_script('chartjs', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js', [], '4.4.1', true);

    // Bootstrap Icons
    wp_enqueue_style('bootstrap-icons', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css', [], '1.11.3');

    // Shared design system
    wp_enqueue_style('myls-stats-css', $base_url . 'admin/stats/stats-dashboard.css', [], $version);

    // Dashboard-specific CSS
    wp_enqueue_style('myls-stats-dash-css', $base_url . 'admin/stats-dashboard/stats-overview.css', ['myls-stats-css'], $version);

    // Dashboard JS
    wp_enqueue_script('myls-stats-dash-js', $base_url . 'admin/stats-dashboard/stats-overview.js', ['chartjs', 'jquery'], $version, true);

    wp_localize_script('myls-stats-dash-js', 'MYLSStatsDash', [
        'ajaxurl'           => admin_url('admin-ajax.php'),
        'nonce_stats'       => wp_create_nonce('myls_stats'),
        'nonce_sd'          => wp_create_nonce('myls_ai_ops'),
        'nonce_ae'          => wp_create_nonce('myls_ai_exposure'),
        'ai_usage_url'      => admin_url('admin.php?page=myls-ai-usage'),
        'search_demand_url' => admin_url('admin.php?page=myls-search-demand'),
        'ai_exposure_url'   => admin_url('admin.php?page=myls-ai-exposure'),
    ]);
}
