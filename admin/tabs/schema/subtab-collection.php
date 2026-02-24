<?php if (!defined('ABSPATH')) exit;

$spec = [
  'id'    => 'collection',
  'label' => 'Collection',
  'order' => 60,

  'render'=> function () {
    $enabled = get_option('myls_schema_collection_enabled','0'); // '0' or '1'
    ?>
    <!-- IMPORTANT: no <form>; this lives inside the main Schema tab's form -->
    <div class="container-fluid px-0 myls-rounded">
      <div class="row g-3">
        <!-- LEFT: Form card -->
        <div class="col-12 col-lg-8">
          <div class="card mb-0 shadow-sm myls-card h-100">
            <div class="card-header bg-primary text-white">
              <strong>Collection Schema</strong>
            </div>
            <div class="card-body">

              <!-- Enable toggle (switch) -->
              <div class="form-check form-switch mb-3">
                <input type="hidden" name="myls_schema_collection_enabled" value="0">
                <input
                  class="form-check-input"
                  type="checkbox"
                  role="switch"
                  id="myls_schema_collection_enabled"
                  name="myls_schema_collection_enabled"
                  value="1"
                  <?php checked('1', $enabled); ?>
                >
                <label class="form-check-label" for="myls_schema_collection_enabled">
                  Enable CollectionPage / ItemList Schema
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
                Use <em>CollectionPage</em> or <em>ItemList</em> schema for curated listings
                (e.g., “Top Services”, “Case Studies”).
              </p>
              <ul class="mb-0">
                <li>Generation attaches automatically when page content qualifies.</li>
                <li>Complements Service/FAQ schema for stronger topical coverage.</li>
                <li>Useful for hub pages that group related posts or services.</li>
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
    $val = (isset($_POST['myls_schema_collection_enabled']) && $_POST['myls_schema_collection_enabled'] === '1') ? '1' : '0';
    update_option('myls_schema_collection_enabled', $val);
  }
];

if (defined('MYLS_SCHEMA_DISCOVERY') && MYLS_SCHEMA_DISCOVERY) return $spec;
return null;
