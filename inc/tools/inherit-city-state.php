<?php
/**
 * AIntelligize — Inherit city_state from top-level service areas down to descendants
 * Shows under BOTH:
 *  - Tools → City/State Inherit
 *  - AIntelligize → City/State Inherit
 */
if ( ! defined('ABSPATH') ) exit;

/** --------- Helpers --------- */
if ( ! function_exists('myls_service_area_get_descendant_ids') ) {
  function myls_service_area_get_descendant_ids( int $parent_id ) : array {
    $out = [];
    $queue = [$parent_id];
    while ($queue) {
      $pid = array_shift($queue);
      $kids = get_posts([
        'post_type'       => 'service_area',
        'post_parent'     => $pid,
        'post_status'     => ['publish','draft','pending','future','private'],
        'numberposts'     => -1,
        'fields'          => 'ids',
        'suppress_filters'=> true,
      ]);
      foreach ($kids as $cid) {
        $cid = (int) $cid;
        if (!in_array($cid, $out, true)) {
          $out[] = $cid;
          $queue[] = $cid;
        }
      }
    }
    return array_values( array_diff( $out, [$parent_id] ) );
  }
}

if ( ! function_exists('myls_read_city_state') ) {
  function myls_read_city_state( int $post_id ) : string {
    if ( function_exists('get_field') ) {
      $v = (string) get_field('city_state', $post_id);
      if ($v !== '') return $v;
    }
    return (string) get_post_meta($post_id, 'city_state', true);
  }
}

if ( ! function_exists('myls_write_city_state') ) {
  function myls_write_city_state( int $post_id, string $value ) : bool {
    $ok = false;
    if ( function_exists('update_field') ) {
      $ok = update_field('city_state', $value, $post_id);
      if ( ! $ok && function_exists('get_field_object') ) {
        $fo = get_field_object('city_state', $post_id);
        if ( $fo && !empty($fo['key']) ) {
          $ok = update_field($fo['key'], $value, $post_id);
        }
      }
    }
    if ( ! $ok ) {
      $ok = (bool) update_post_meta($post_id, 'city_state', $value);
    }
    return $ok;
  }
}

if ( ! function_exists('myls_inherit_city_state_to_children') ) {
  function myls_inherit_city_state_to_children( bool $overwrite = true ) : array {
    @set_time_limit(0);

    $parents = get_posts([
      'post_type'       => 'service_area',
      'post_parent'     => 0,
      'post_status'     => ['publish','draft','pending','future','private'],
      'numberposts'     => -1,
      'fields'          => 'ids',
      'suppress_filters'=> true,
    ]);

    $totals = [
      'parents'      => count($parents),
      'children'     => 0,
      'updated'      => 0,
      'skipped'      => 0,
      'empty_parent' => 0,
    ];

    foreach ($parents as $parent_id) {
      $parent_id = (int) $parent_id;
      $val = trim( myls_read_city_state($parent_id) );

      if ($val === '') { $totals['empty_parent']++; continue; }

      $desc = myls_service_area_get_descendant_ids($parent_id);
      $totals['children'] += count($desc);

      foreach ($desc as $cid) {
        $cid = (int) $cid; if (!$cid) continue;

        if ( !$overwrite ) {
          $existing = trim( myls_read_city_state($cid) );
          if ($existing !== '') { $totals['skipped']++; continue; }
        }

        if ( myls_write_city_state($cid, $val) ) {
          $totals['updated']++;
        } else {
          $totals['skipped']++;
        }
      }
    }

    return $totals;
  }
}

/** --------- UI callback --------- */
if ( ! function_exists('myls_citystate_render_page') ) {
  function myls_citystate_render_page() {
    if ( ! current_user_can( apply_filters('myls_citystate_cap', 'edit_posts') ) ) {
      wp_die( esc_html__('You do not have permission to access this page.', 'myls') );
    }

    $ran = false; $report = [];
    if ( isset($_POST['myls_citystate_run']) ) {
      check_admin_referer('myls_citystate_inherit');
      $overwrite = ! empty($_POST['myls_overwrite']);
      $report = myls_inherit_city_state_to_children( $overwrite );
      $ran = true;
    }
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('Inherit city_state to Children (service_area)', 'myls'); ?></h1>
      <p><?php esc_html_e('Copies the city_state value from each top-level service_area (parent ID = 0) to all of its descendants.', 'myls'); ?></p>

      <form method="post">
        <?php wp_nonce_field('myls_citystate_inherit'); ?>
        <p>
          <label>
            <input type="checkbox" name="myls_overwrite" value="1" checked>
            <?php esc_html_e('Overwrite existing child values', 'myls'); ?>
          </label>
        </p>
        <p>
          <button type="submit" name="myls_citystate_run" class="button button-primary">
            <?php esc_html_e('Run Inheritance Now', 'myls'); ?>
          </button>
        </p>
      </form>

      <?php if ( $ran ): ?>
        <hr>
        <h2><?php esc_html_e('Report', 'myls'); ?></h2>
        <table class="widefat striped" style="max-width:600px">
          <tbody>
          <tr><th><?php esc_html_e('Top-level parents', 'myls'); ?></th><td><?php echo (int) ($report['parents'] ?? 0); ?></td></tr>
          <tr><th><?php esc_html_e('Parents with empty city_state', 'myls'); ?></th><td><?php echo (int) ($report['empty_parent'] ?? 0); ?></td></tr>
          <tr><th><?php esc_html_e('Descendant children found', 'myls'); ?></th><td><?php echo (int) ($report['children'] ?? 0); ?></td></tr>
          <tr><th><?php esc_html_e('Children updated', 'myls'); ?></th><td><strong><?php echo (int) ($report['updated'] ?? 0); ?></strong></td></tr>
          <tr><th><?php esc_html_e('Children skipped', 'myls'); ?></th><td><?php echo (int) ($report['skipped'] ?? 0); ?></td></tr>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <?php
  }
}

/** --------- Menus (Tools + AIntelligize submenu) --------- */
add_action('admin_menu', function(){
  // Capability (filterable): default to 'edit_posts' so Editors can use it too.
  $cap = apply_filters('myls_citystate_cap', 'edit_posts');

  // 1) Tools menu
  add_management_page(
    __('City/State Inherit','myls'),
    __('City/State Inherit','myls'),
    $cap,
    'myls-citystate-inherit',
    'myls_citystate_render_page'
  );

  // 2) Under "AIntelligize" menu as well (if present)
  // Parent slug must match your main menu slug; using 'aintelligize' from your tabs.
  add_submenu_page(
    'aintelligize',
    __('City/State Inherit','myls'),
    __('City/State Inherit','myls'),
    $cap,
    'myls-citystate-inherit',
    'myls_citystate_render_page',
    90
  );
}, 99);

/** --------- Optional: WP-CLI command --------- */
if ( defined('WP_CLI') && WP_CLI ) {
  WP_CLI::add_command('myls citystate-inherit', function( $args, $assoc_args ){
    $overwrite = isset($assoc_args['overwrite']) ? filter_var($assoc_args['overwrite'], FILTER_VALIDATE_BOOLEAN) : true;
    $r = myls_inherit_city_state_to_children( $overwrite );
    WP_CLI::success( sprintf(
      'Parents: %d | Empty parents: %d | Children: %d | Updated: %d | Skipped: %d',
      $r['parents'], $r['empty_parent'], $r['children'], $r['updated'], $r['skipped']
    ));
  });
}
