<?php
/**
 * Admin Tab: Utilities
 *
 * Location: admin/tabs/tab-utilities.php
 *
 * Contains safe tools (batch migrations, cleanups, diagnostics).
 * Refactored to support subtabs.
 *
 * Subtabs are discovered from:
 *  - admin/tabs/utilities/subtab-*.php
 */

if ( ! defined('ABSPATH') ) exit;

myls_register_admin_tab([
  'id'    => 'utilities',
  'title' => 'Utilities',
  'icon'  => 'dashicons-admin-tools',
  'order' => 95,
  'cap'   => function_exists('myls_util_cap') ? myls_util_cap() : 'manage_options',
  'cb'    => function(){

    $dir = trailingslashit( MYLS_PATH ) . 'admin/tabs/utilities';
    if ( ! is_dir($dir) ) {
      echo '<div class="notice notice-warning"><p>Missing folder: <code>admin/tabs/utilities</code></p></div>';
      return;
    }

    // -------------------------------------------------
    // Discover subtabs
    // -------------------------------------------------
    $files   = glob( $dir . '/subtab-*.php' );
    $subtabs = [];
    if ( $files ) {
      natsort($files);
      foreach ( $files as $file ) {
        $spec = include $file; // include (not include_once) so return[] always works
        if ( is_array($spec) && ! empty($spec['id']) && ! empty($spec['label']) && ! empty($spec['render']) ) {
          $subtabs[ $spec['id'] ] = $spec;
        }
      }
    }

    // Back-compat: if no subtabs exist, show legacy view.
    if ( empty($subtabs) ) {
      require_once MYLS_PATH . 'admin/views/utilities-acf-migrations.php';
      return;
    }

    // Order by 'order', then label
    uasort($subtabs, function($a, $b){
      $ao = $a['order'] ?? 50;
      $bo = $b['order'] ?? 50;
      if ($ao === $bo) return strcasecmp((string)$a['label'], (string)$b['label']);
      return $ao <=> $bo;
    });

    $active = isset($_GET['sub']) ? sanitize_key($_GET['sub']) : '';
    if ( ! isset($subtabs[$active]) ) {
      $keys   = array_keys($subtabs);
      $active = reset($keys);
    }

    $base_url = admin_url('admin.php?page=aintelligize&tab=utilities');
    ?>
    <style>
      .myls-util-wrap { max-width: none; width: 100%; margin-left: -20px; margin-right: -20px; }
      @media (max-width: 782px){ .myls-util-wrap { margin-left: 0; margin-right: 0; } }

      .myls-util-wrap .myls-util-nav { display:flex; gap:.25rem; border-bottom:1px solid #dee2e6; background:#fff; padding:0 20px; }
      .myls-util-wrap .nav-item { list-style:none; }
      .myls-util-wrap .nav-link { display:inline-block; padding:.6rem .9rem; font-weight:600; color:#495057; text-decoration:none; border:1px solid transparent; border-bottom:2px solid transparent; border-radius:.5rem .5rem 0 0; }
      .myls-util-wrap .nav-link:hover { color:#0d6efd; }
      .myls-util-wrap .nav-link.active { color:#0d6efd; background:#fff; border-color:#dee2e6; border-bottom-color:#0d6efd; }

      .myls-util-wrap .myls-subtab-body { padding:18px 24px 24px 24px; background:#f6f7fb; }
      .myls-util-wrap .myls-subtab-inner { background:#fff; border:1px solid #e6e6e6; border-radius:10px; padding:20px; }

      .myls-util-wrap .btn { display:inline-block; font-weight:600; border:1px solid #dee2e6; padding:.45rem .75rem; border-radius:.5rem; background:#f8f9fa; color:#212529; cursor:pointer; line-height:1.25; text-decoration:none; }
      .myls-util-wrap .btn:hover { filter:brightness(0.97); }
      .myls-util-wrap .btn-primary { background:#0d6efd; border-color:#0d6efd; color:#fff; }
      .myls-util-wrap .btn-outline-secondary { background:transparent; color:#6c757d; border-color:#6c757d; }
      .myls-util-wrap .btn-outline-secondary:hover { background:#6c757d; color:#fff; }

      /* Grid helpers (scoped, no Bootstrap dependency) */
      .myls-util-wrap .row { display:flex; flex-wrap:wrap; margin-left:-.5rem; margin-right:-.5rem; }
      .myls-util-wrap .row > [class^="col-"] { padding-left:.5rem; padding-right:.5rem; }
      .myls-util-wrap .col-12 { flex:0 0 100%; max-width:100%; }
      .myls-util-wrap .col-md-4 { flex:0 0 33.333%; max-width:33.333%; }
      .myls-util-wrap .col-md-8 { flex:0 0 66.666%; max-width:66.666%; }
      @media (max-width: 782px){
        .myls-util-wrap .col-md-4, .myls-util-wrap .col-md-8 { flex:0 0 100%; max-width:100%; }
      }

      .myls-util-wrap .form-label { font-weight:600; margin-bottom:.35rem; display:block; }
      .myls-util-wrap .form-control, .myls-util-wrap .form-select, .myls-util-wrap textarea, .myls-util-wrap input[type="text"]{
        width:100%; padding:.5rem .65rem; border:1px solid #ced4da; border-radius:.375rem; background:#fff; color:#212529;
      }
      .myls-util-wrap .cardish { border:1px solid #ececec; border-radius:10px; padding:16px; background:#fff; }
      .myls-util-wrap .muted { color:#6c757d; }
      .myls-util-wrap .mt-2{ margin-top:.5rem; } .myls-util-wrap .mt-3{ margin-top:1rem; } .myls-util-wrap .mb-2{ margin-bottom:.5rem; } .myls-util-wrap .mb-3{ margin-bottom:1rem; }
    </style>

    <div class="myls-util-wrap">
      <ul class="myls-util-nav">
        <?php foreach ( $subtabs as $id => $spec ) :
          $url = esc_url( add_query_arg(['sub'=>$id], $base_url) );
          $cls = ($id === $active) ? 'active' : '';
        ?>
          <li class="nav-item">
            <a class="nav-link <?php echo esc_attr($cls); ?>" href="<?php echo $url; ?>">
              <?php echo esc_html($spec['label']); ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>

      <div class="myls-subtab-body">
        <div class="myls-subtab-inner">
          <?php call_user_func( $subtabs[$active]['render'] ); ?>
        </div>
      </div>
    </div>
    <?php
  },
]);
