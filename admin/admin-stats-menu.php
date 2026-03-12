<?php
/**
 * AIntelligize — AIntelligize Stats Menu & AJAX Endpoints
 * Path: admin/admin-stats-menu.php
 *
 * Registers the "AIntelligize Stats" submenu page under AIntelligize and
 * provides AJAX endpoints for the dashboard to fetch data.
 *
 * @since 6.3.1.8
 */
if ( ! defined('ABSPATH') ) exit;

/* ═══════════════════════════════════════════════════════
 *  1. SUBMENU REGISTRATION
 * ═══════════════════════════════════════════════════════ */

add_action('admin_menu', 'myls_stats_add_submenu', 25);

function myls_stats_add_submenu() {
    add_submenu_page(
        'aintelligize',
        'AIntelligize Stats — AI Analytics',
        'AIntelligize Stats',
        'manage_options',
        'myls-plugin-stats',
        'myls_stats_render_page'
    );
}

function myls_stats_render_page() {
    ?>
    <div class="wrap">
        <div id="myls-stats-root"></div>
    </div>
    <?php
}

/* ═══════════════════════════════════════════════════════
 *  2. ENQUEUE SCRIPTS & STYLES
 * ═══════════════════════════════════════════════════════ */

add_action('admin_enqueue_scripts', 'myls_stats_admin_scripts');

function myls_stats_admin_scripts( $hook ) {
    if ( $hook !== 'aintelligize_page_myls-plugin-stats' ) return;

    $base_url = plugin_dir_url( dirname(__FILE__) );
    $version  = defined('MYLS_VERSION') ? MYLS_VERSION : time();

    // Chart.js from CDN
    wp_enqueue_script('chartjs', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js', [], '4.4.1', true);

    // Our dashboard
    wp_enqueue_style('myls-stats-css',  $base_url . 'admin/stats/stats-dashboard.css', [], $version);
    wp_enqueue_script('myls-stats-js',  $base_url . 'admin/stats/stats-dashboard.js', ['chartjs', 'jquery'], $version, true);

    wp_localize_script('myls-stats-js', 'MYLSStats', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('myls_stats'),
    ]);
}

/* ═══════════════════════════════════════════════════════
 *  3. AJAX ENDPOINTS
 * ═══════════════════════════════════════════════════════ */

add_action('wp_ajax_myls_stats_overview', function(){
    check_ajax_referer('myls_stats', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Forbidden', 403);

    $days = isset($_GET['days']) ? absint($_GET['days']) : 30;

    wp_send_json_success([
        'overview'  => MYLS_AI_Usage_Logger::get_overview($days),
        'config'    => MYLS_AI_Usage_Logger::get_ai_config(),
        'coverage'  => MYLS_AI_Usage_Logger::get_content_coverage(),
        'total_log' => MYLS_AI_Usage_Logger::get_total_rows(),
    ]);
});

add_action('wp_ajax_myls_stats_timeline', function(){
    check_ajax_referer('myls_stats', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Forbidden', 403);

    $days = isset($_GET['days']) ? absint($_GET['days']) : 30;
    wp_send_json_success( MYLS_AI_Usage_Logger::get_timeline($days) );
});

add_action('wp_ajax_myls_stats_by_handler', function(){
    check_ajax_referer('myls_stats', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Forbidden', 403);

    $days = isset($_GET['days']) ? absint($_GET['days']) : 30;
    wp_send_json_success( MYLS_AI_Usage_Logger::get_by_handler($days) );
});

add_action('wp_ajax_myls_stats_by_model', function(){
    check_ajax_referer('myls_stats', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Forbidden', 403);

    $days = isset($_GET['days']) ? absint($_GET['days']) : 30;
    wp_send_json_success( MYLS_AI_Usage_Logger::get_by_model($days) );
});

add_action('wp_ajax_myls_stats_hourly', function(){
    check_ajax_referer('myls_stats', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Forbidden', 403);

    $days = isset($_GET['days']) ? absint($_GET['days']) : 30;
    wp_send_json_success( MYLS_AI_Usage_Logger::get_hourly($days) );
});

add_action('wp_ajax_myls_stats_recent', function(){
    check_ajax_referer('myls_stats', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Forbidden', 403);

    $limit = isset($_GET['limit']) ? min(200, absint($_GET['limit'])) : 50;
    wp_send_json_success( MYLS_AI_Usage_Logger::get_recent_calls($limit) );
});

add_action('wp_ajax_myls_stats_purge', function(){
    check_ajax_referer('myls_stats', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Forbidden', 403);

    $days = isset($_POST['days']) ? absint($_POST['days']) : 90;
    $before = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
    $deleted = MYLS_AI_Usage_Logger::purge_before($before);

    wp_send_json_success(['deleted' => $deleted, 'before' => $before]);
});
