<?php if (!defined('ABSPATH')) exit;

$spec = [
  'id'    => 'blog',
  'label' => 'Blog',
  'order' => 70,

  'render'=> function () {
    $enabled = get_option('myls_schema_blog_enabled','0'); // '0' or '1'
    ?>
    <!-- IMPORTANT: no <form>; this is inside the main Schema tab's form -->
    <div class="container-fluid px-0 myls-rounded">
      <div class="row g-3">
        <!-- LEFT: Form card -->
        <div class="col-12 col-lg-8">
          <div class="card mb-0 shadow-sm myls-card h-100">
            <div class="card-header bg-primary text-white">
              <strong>Blog Schema</strong>
            </div>
            <div class="card-body">

              <!-- Enable toggle (switch) -->
              <div class="form-check form-switch mb-3">
                <!-- Hidden ensures a '0' is posted when unchecked -->
                <input type="hidden" name="myls_schema_blog_enabled" value="0">
                <input
                  class="form-check-input"
                  type="checkbox"
                  role="switch"
                  id="myls_schema_blog_enabled"
                  name="myls_schema_blog_enabled"
                  value="1"
                  <?php checked('1', $enabled); ?>
                >
                <label class="form-check-label" for="myls_schema_blog_enabled">
                  Enable Blog / BlogPosting Schema
                </label>
              </div>

              <div class="d-flex gap-2">
                <!-- Submits the parent form -->
                <button class="btn btn-primary" type="submit">Save</button>
              </div>

            </div>
          </div>
        </div>

        <!-- RIGHT: Info card -->
        <div class="col-12 col-lg-4">
          <div class="card mb-0 shadow-sm myls-card h-100">
            <div class="card-header bg-primary text-white">
              <strong>Info</strong>
            </div>
            <div class="card-body">
              <p class="mb-2">
                When enabled, modules can output <em>Blog</em> / <em>BlogPosting</em> schema automatically
                on your blog home, archives, and individual posts based on context and content.
              </p>
              <ul class="mb-0">
                <li>Respects your theme loop and post meta.</li>
                <li>Pairs with Organization / LocalBusiness data for consistency.</li>
                <li>Extend generation via filters if needed.</li>
              </ul>
            </div>
          </div>
        </div>

      </div>
    </div>
    <?php
  },

  'on_save'=> function () {
    if (
      ! isset($_POST['myls_schema_nonce']) ||
      ! wp_verify_nonce($_POST['myls_schema_nonce'],'myls_schema_save') ||
      ! current_user_can('manage_options')
    ) {
      return;
    }
    $val = (isset($_POST['myls_schema_blog_enabled']) && $_POST['myls_schema_blog_enabled'] === '1') ? '1' : '0';
    update_option('myls_schema_blog_enabled', $val);
  }
];

if (defined('MYLS_SCHEMA_DISCOVERY') && MYLS_SCHEMA_DISCOVERY) return $spec;
return null;
