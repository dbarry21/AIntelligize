<?php
/**
 * AIntelligize — Search Demand Menu & Scripts
 * Path: admin/admin-search-stats-menu.php
 *
 * Registers the "Search Demand" submenu page under AIntelligize and
 * enqueues the dashboard CSS/JS. All AJAX endpoints are in
 * inc/ajax/ai-faq-search-check.php (myls_sd_db_* actions).
 *
 * @since 6.3.2.7
 */
if ( ! defined('ABSPATH') ) exit;

/* ── Redirect old slug → new slug ── */
add_action('admin_init', function() {
    if ( isset($_GET['page']) && $_GET['page'] === 'myls-search-stats' ) {
        wp_safe_redirect( admin_url('admin.php?page=myls-search-demand') );
        exit;
    }
});

/* ═══════════════════════════════════════════════════════
 *  1. SUBMENU REGISTRATION
 * ═══════════════════════════════════════════════════════ */

add_action('admin_menu', 'myls_search_stats_add_submenu', 27);

function myls_search_stats_add_submenu() {
    add_submenu_page(
        'aintelligize',
        'Search Demand — Keyword Analytics',
        '&mdash; Search Demand',
        'manage_options',
        'myls-search-demand',
        'myls_search_stats_render_page'
    );
}

function myls_search_stats_render_page() {
    ?>
    <div class="wrap">
        <div id="myls-search-stats-root"></div>
    </div>
    <?php
}

/* ═══════════════════════════════════════════════════════
 *  2. ENQUEUE SCRIPTS & STYLES
 * ═══════════════════════════════════════════════════════ */

add_action('admin_enqueue_scripts', 'myls_search_stats_admin_scripts');

function myls_search_stats_admin_scripts( $hook ) {
    if ( $hook !== 'aintelligize_page_myls-search-demand' ) return;

    $base_url = plugin_dir_url( dirname(__FILE__) );
    $version  = defined('MYLS_VERSION') ? MYLS_VERSION : time();

    // Reuse AIntelligize Stats CSS (same ms-* design system)
    wp_enqueue_style('myls-stats-css', $base_url . 'admin/stats/stats-dashboard.css', [], $version);

    // Search Stats specific CSS
    wp_enqueue_style('myls-search-stats-css', $base_url . 'admin/search-stats/search-stats.css', ['myls-stats-css'], $version);

    // Bootstrap Icons (already registered by main plugin, but ensure it)
    wp_enqueue_style('bootstrap-icons', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css', [], '1.11.3');

    // Dashboard JS
    wp_enqueue_script('myls-search-stats-js', $base_url . 'admin/search-stats/search-stats.js', ['jquery'], $version, true);

    // GSC connection status
    $gsc_connected = false;
    if ( function_exists('myls_gsc_is_connected') ) {
        $gsc_connected = myls_gsc_is_connected();
    }

    wp_localize_script('myls-search-stats-js', 'MYLS_SS', [
        'ajaxurl'        => admin_url('admin-ajax.php'),
        'nonce'          => wp_create_nonce('myls_ai_ops'),
        'gsc_connected'  => $gsc_connected,
        'api_tab_url'    => admin_url('admin.php?page=aintelligize&tab=api-integration'),
    ]);
}
