<?php

add_action('admin_menu', 'mlseo_add_single_docs_submenu');
function mlseo_add_single_docs_submenu() {
    add_submenu_page(
        'aintelligize', // ✅ Correct parent slug
        'Documentation',
        'Documentation',
        'manage_options',
        'mlseo-docs',
        'mlseo_render_docs_combined'
    );
}

function mlseo_render_docs_combined() {
    include plugin_dir_path(__FILE__) . 'docs/documentation.php';
}

/* -------------------------------------------------------------------------
 * Phase 3: Export Shortcode Reference (Markdown / HTML)
 *
 * These exports are generated at request-time by scanning /modules/shortcodes/.
 * We send the file as a download (no writes inside the plugin directory).
 * ---------------------------------------------------------------------- */

add_action('admin_post_mlseo_docs_export_shortcodes_md', 'mlseo_docs_export_shortcodes_md');
add_action('admin_post_mlseo_docs_export_shortcodes_html', 'mlseo_docs_export_shortcodes_html');
add_action('admin_post_mlseo_docs_export_pdf', 'mlseo_docs_export_pdf');

// Quick Start Progress AJAX handlers
add_action('wp_ajax_mlseo_update_quick_start_progress', 'mlseo_update_quick_start_progress');
add_action('wp_ajax_mlseo_reset_quick_start_progress', 'mlseo_reset_quick_start_progress');

function mlseo_update_quick_start_progress() {
    check_ajax_referer('mlseo_quick_start_progress');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }
    
    $step = isset($_POST['step']) ? sanitize_text_field($_POST['step']) : '';
    $completed = isset($_POST['completed']) ? intval($_POST['completed']) : 0;
    
    if (empty($step)) {
        wp_send_json_error(['message' => 'Invalid step']);
    }
    
    $user_id = get_current_user_id();
    $progress = get_user_meta($user_id, 'mlseo_quick_start_progress', true);
    
    if (!is_array($progress)) {
        $progress = [];
    }
    
    if ($completed) {
        if (!in_array($step, $progress)) {
            $progress[] = $step;
        }
    } else {
        $progress = array_diff($progress, [$step]);
    }
    
    update_user_meta($user_id, 'mlseo_quick_start_progress', $progress);
    
    wp_send_json_success([
        'completed_count' => count($progress),
        'steps' => $progress
    ]);
}

function mlseo_reset_quick_start_progress() {
    check_ajax_referer('mlseo_quick_start_progress');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }
    
    $user_id = get_current_user_id();
    delete_user_meta($user_id, 'mlseo_quick_start_progress');
    
    wp_send_json_success(['message' => 'Progress reset']);
}

function mlseo_docs_export_shortcodes_md() {
    if ( ! current_user_can('manage_options') ) wp_die('Forbidden');
    check_admin_referer('mlseo_docs_export_shortcodes');

    require_once plugin_dir_path(__FILE__) . 'docs/lib/shortcodes.php';
    $items = mlseo_docs_build_shortcodes_index(true);
    $md = mlseo_docs_render_shortcodes_markdown($items);

    $filename = 'aintelligize-shortcodes-' . gmdate('Ymd-His') . '.md';
    nocache_headers();
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $md;
    exit;
}

function mlseo_docs_export_shortcodes_html() {
    if ( ! current_user_can('manage_options') ) wp_die('Forbidden');
    check_admin_referer('mlseo_docs_export_shortcodes');

    require_once plugin_dir_path(__FILE__) . 'docs/lib/shortcodes.php';
    $items = mlseo_docs_build_shortcodes_index(true);
    $html = mlseo_docs_render_shortcodes_html($items);

    $filename = 'aintelligize-shortcodes-' . gmdate('Ymd-His') . '.html';
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $html;
    exit;
}

function mlseo_docs_export_pdf() {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('mlseo_docs_export_pdf');

    // Load shortcode data
    require_once plugin_dir_path(__FILE__) . 'docs/shortcode-data.php';
    require_once plugin_dir_path(__FILE__) . 'docs/pdf-generator.php';
    
    $shortcodes = mlseo_compile_shortcode_documentation();
    
    MLSEO_PDF_Generator::generate_shortcodes_pdf($shortcodes);
}
