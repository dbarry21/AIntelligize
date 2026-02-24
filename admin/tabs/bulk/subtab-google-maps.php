<?php
/**
 * Bulk → Google Maps (Subtab)
 * Path: admin/tabs/bulk/subtab-google-maps.php
 *
 * - Desktop: two columns side-by-side
 * - Mobile: stacks naturally (Bootstrap grid)
 * - Left: options + run
 * - Right: filter box + multi-select post list + controls
 * - Debug row under columns
 *
 * Behavior is in: assets/js/myls-google-maps.js
 */

if (!defined('ABSPATH')) exit;

$spec = [
  'id'    => 'googlemaps',
  'label' => 'Google Maps',
  'icon'  => 'bi bi-map',
  'order' => 50,
  'render'=> function () {
    $nonce = wp_create_nonce('myls_bulk_ops'); ?>

    <input type="hidden" id="myls_bulk_ops_nonce" value="<?php echo esc_attr($nonce); ?>">

    <div class="container-fluid px-0">
      <!-- Two-column responsive layout -->
      <div class="row g-4 align-items-start">
        <!-- LEFT COLUMN -->
        <div class="col-12 col-lg-6">
          <div class="myls-section-title">
            <i class="bi bi-map"></i> Google Maps — Bulk Featured Image Generator
          </div>
          <p class="text-muted">
            Generate Static Maps and set them as the featured image for selected <strong>Service Area</strong> posts.
          </p>

          <div class="mb-3">
            <label class="form-label d-block">Options</label>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="myls_gmaps_force" value="1">
              <label class="form-check-label" for="myls_gmaps_force">
                Regenerate even if a featured image already exists
              </label>
            </div>
          </div>

          <div class="d-flex align-items-center gap-2">
            <button id="myls_gmaps_run" class="btn btn-primary">
              <i class="bi bi-play-circle"></i> Generate Featured Maps
            </button>
            <span id="myls_gmaps_status" class="text-muted"></span>
          </div>
        </div>

        <!-- RIGHT COLUMN -->
        <div class="col-12 col-lg-6">
          <div class="mb-2">
            <label for="myls_gmaps_search" class="form-label">Filter Service Areas</label>
            <input type="search" id="myls_gmaps_search" class="form-control" placeholder="Type to filter by title…">
          </div>

          <div class="mb-2">
            <label for="myls_gmaps_post_list" class="form-label">Service Area Posts</label>
            <select id="myls_gmaps_post_list" class="form-select" multiple size="16" aria-describedby="myls_gmaps_help">
              <!-- JS populates options -->
            </select>
            <div id="myls_gmaps_help" class="form-text">
              Tip: Click then type to jump; hold Ctrl/Cmd to multi-select.
            </div>
          </div>

          <div class="d-flex align-items-center gap-3">
            <button id="myls_gmaps_select_all" type="button" class="btn btn-link p-0">Select all</button>
            <button id="myls_gmaps_clear" type="button" class="btn btn-link p-0">Clear</button>
            <span id="myls_gmaps_count" class="text-muted ms-auto"></span>
          </div>
        </div>
      </div>

      <!-- DEBUG ROW -->
      <div class="row mt-3">
        <div class="col-12">
          <div id="myls_gmaps_result" style="display:none;">
            <div class="alert alert-info" id="myls_gmaps_summary" role="status"></div>
            <pre class="border p-2 bg-light" id="myls_gmaps_log" style="max-height:300px; overflow:auto;"></pre>
          </div>
          <div id="myls_gmaps_empty" class="text-muted"><em>No operations run yet.</em></div>
        </div>
      </div>
    </div>
  <?php }
];

return $spec;
