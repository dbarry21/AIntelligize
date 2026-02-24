<?php
// File: inc/schema/helpers.php
if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('myls_opt') ) {
    function myls_opt($key, $default = '') {
        return get_option($key, $default);
    }
}
if ( ! function_exists('myls_update_opt') ) {
    function myls_update_opt($key, $val) {
        update_option($key, $val);
    }
}
if ( ! function_exists('myls_sanitize_csv') ) {
    function myls_sanitize_csv($str) {
        $str = wp_unslash((string)$str);
        $parts = array_filter(array_map('trim', explode("\n", $str)));
        return implode("\n", $parts);
    }
}
