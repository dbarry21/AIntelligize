<?php
namespace MYLS\SiteBuilder;
if (!defined('ABSPATH')) exit;

class Utils {
  public static function get_option(string $key, $default = '') { return get_option($key, $default); }
  public static function update_option(string $key, $value) { update_option($key, $value); }
  public static function sanitize_lines(string $text): array {
    $lines = array_filter(array_map('trim', preg_split('/\r?\n/', (string)$text)));
    return array_values(array_unique($lines));
  }
  public static function first_available_menu_location(): string {
    $locs = get_registered_nav_menus();
    if (isset($locs['primary'])) return 'primary';
    $keys = array_keys($locs);
    return $keys ? $keys[0] : '';
  }
  public static function set_yoast_meta(int $post_id, string $title, string $desc) {
    if (!function_exists('update_post_meta')) return;
    update_post_meta($post_id, '_yoast_wpseo_title', $title);
    update_post_meta($post_id, '_yoast_wpseo_metadesc', $desc);
  }
  public static function replace_tokens(string $html, array $vars): string {
    foreach ($vars as $k=>$v) { $html = str_replace('{{' . strtoupper($k) . '}}', esc_html($v), $html); }
    return $html;
  }
}
