<?php
if ( ! defined('ABSPATH') ) exit;

class MYLS_Meta_History_Logger {
  const TABLE = 'myls_meta_history';
  // Track Yoast Title, Description, Focus Keyword
  const KEYS  = ['_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw'];

  // holds "old" values captured in update_post_metadata filter
  protected static $old_cache = []; // [ "{$post_id}|{$meta_key}" => $old_value ]

  public static function init() {
    add_action('plugins_loaded', [__CLASS__, 'maybe_install_table'], 5);

    // BEFORE update: capture old value (correct filter name)
    add_filter('update_post_metadata', [__CLASS__, 'capture_old_before_update'], 10, 5);

    // AFTER update: write row
    add_action('updated_postmeta', [__CLASS__, 'on_updated'], 10, 4);

    // New meta rows
    add_action('added_post_meta',  [__CLASS__, 'on_added'], 10, 4);

    // Deletions
    add_action('deleted_post_meta',[__CLASS__, 'on_deleted'], 10, 4);
  }

  public static function table_name() {
    global $wpdb; return $wpdb->prefix . self::TABLE;
  }

  public static function maybe_install_table() {
    global $wpdb;
    $table   = self::table_name();
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      post_id BIGINT UNSIGNED NOT NULL,
      field VARCHAR(32) NOT NULL,
      old_value LONGTEXT NULL,
      new_value LONGTEXT NULL,
      user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY post_field_time (post_id, field, created_at)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
  }

  protected static function current_user_id() {
    $uid = get_current_user_id(); return $uid ? (int)$uid : 0;
  }

  protected static function key_to_field($key) {
    if ($key === '_yoast_wpseo_title')    return 'title';
    if ($key === '_yoast_wpseo_metadesc') return 'description';
    if ($key === '_yoast_wpseo_focuskw')  return 'focus_keyword';
    return null;
  }

  protected static function ins($post_id, $key, $old, $new) {
    $field = self::key_to_field($key);
    if (!$field) return;
    // skip exact no-op
    if (is_string($old) && is_string($new) && trim($old) === trim($new)) return;

    global $wpdb;
    $wpdb->insert(
      self::table_name(),
      [
        'post_id'    => (int)$post_id,
        'field'      => $field,
        'old_value'  => is_scalar($old) ? (string)$old : wp_json_encode($old),
        'new_value'  => is_scalar($new) ? (string)$new : wp_json_encode($new),
        'user_id'    => self::current_user_id(),
        'created_at' => current_time('mysql'),
      ],
      ['%d','%s','%s','%s','%d','%s']
    );
  }

  /** Filter: runs BEFORE update; capture the true old value */
  public static function capture_old_before_update($check, $object_id, $meta_key, $meta_value, $prev_value) {
    if ( ! in_array($meta_key, self::KEYS, true) ) return $check;
    $cache_key = "{$object_id}|{$meta_key}";
    // get current stored value as "old"
    self::$old_cache[$cache_key] = get_metadata('post', $object_id, $meta_key, true);
    // return $check as-is (null) to allow normal update to proceed
    return $check;
  }

  /** Action: AFTER update; write history row using cached old + new */
  public static function on_updated($meta_id, $object_id, $meta_key, $meta_value) {
    if ( ! in_array($meta_key, self::KEYS, true) ) return;
    $cache_key = "{$object_id}|{$meta_key}";
    $old = self::$old_cache[$cache_key] ?? '';
    unset(self::$old_cache[$cache_key]);
    self::ins($object_id, $meta_key, $old, $meta_value);
  }

  /** New meta rows */
  public static function on_added($meta_id, $object_id, $meta_key, $meta_value) {
    if ( ! in_array($meta_key, self::KEYS, true) ) return;
    self::ins($object_id, $meta_key, '', $meta_value);
  }

  /** Deleted meta rows */
  public static function on_deleted($meta_ids, $object_id, $meta_key, $_meta_value) {
    if ( ! in_array($meta_key, self::KEYS, true) ) return;
    $old = get_metadata('post', $object_id, $meta_key, true);
    self::ins($object_id, $meta_key, $old, '');
  }
}

MYLS_Meta_History_Logger::init();
