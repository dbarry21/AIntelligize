<?php
/**
 * Custom CSS: AJAX save handler + Frontend enqueue
 * Path: inc/custom-css.php
 *
 * Saves custom CSS to wp_options and enqueues it on the frontend
 * with high priority to override theme styles.
 */
if ( ! defined('ABSPATH') ) exit;

/**
 * AJAX: Save custom CSS
 */
add_action('wp_ajax_myls_save_custom_css', function () {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error(['message' => 'Forbidden'], 403);
    }
    if ( ! wp_verify_nonce($_POST['_wpnonce'] ?? '', 'myls_custom_css') ) {
        wp_send_json_error(['message' => 'Bad nonce'], 400);
    }

    // Sanitize: strip tags but preserve CSS
    $css = wp_strip_all_tags($_POST['css'] ?? '');

    update_option('myls_custom_css', $css);
    wp_send_json_success([
        'message' => 'CSS saved.',
        'length'  => strlen($css),
    ]);
});

/**
 * Frontend: Enqueue custom CSS with high priority to override theme styles.
 *
 * Uses priority 9999 so it loads AFTER all theme and plugin stylesheets.
 * Also outputs with wp_head at priority 999 as inline <style> for maximum override.
 */
add_action('wp_head', function () {
    if ( is_admin() ) return;

    $css = get_option('myls_custom_css', '');
    if ( empty(trim($css)) ) return;

    echo "\n<!-- MYLS Custom CSS -->\n";
    echo '<style id="myls-custom-css">' . "\n";
    echo $css . "\n";
    echo '</style>' . "\n";
}, 999);
