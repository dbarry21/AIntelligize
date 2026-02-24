<?php
/**
 * Subtab: Clone Service Areas
 * Path: admin/tabs/bulk/subtab-clone-service-areas.php
 */
if (!defined('ABSPATH')) exit;

return [
  'id'    => 'clone-service-areas',
  'label' => 'Clone Service Areas',
  'order' => 20,
  'render'=> function( $ctx = [] ) {

    $all_service_areas = isset($ctx['all_service_areas']) ? (array)$ctx['all_service_areas'] : [];
    $target_tree_items = isset($ctx['target_tree_items']) ? (array)$ctx['target_tree_items'] : [];
    $bulk_nonce        = isset($ctx['bulk_nonce']) ? $ctx['bulk_nonce'] : wp_create_nonce('myls_bulk_ops');

    // Build JSON caches for robust filtering
    $src_items = [];
    foreach ($all_service_areas as $pid) {
      $src_items[] = ['id' => (int)$pid, 'title' => get_the_title($pid)];
    }
    $tgt_items = [];
    foreach ($target_tree_items as $node) {
      $tgt_items[] = [
        'id'    => (int)$node['id'],
        'title' => (string)($node['title'] ?? '(no title)'),
        'depth' => max(0, (int)($node['depth'] ?? 0)),
      ];
    }
    ?>
    <div class="container-fluid" id="myls-bulk-clone-sa">
      <p class="text-muted mb-3">
        Select one <strong>source</strong> Service Area and one or more <strong>parent</strong> Service Areas (any level).
        We’ll clone the source under each selected parent and set ACF <code>city_state</code> from the parent.
      </p>

      <input type="hidden" id="myls_bulk_ops_nonce" value="<?php echo esc_attr($bulk_nonce); ?>">

      <!-- JSON caches for JS (bullet-proof against timing/visibility issues) -->
      <script type="application/json" id="myls-source-cache"><?php echo wp_json_encode($src_items); ?></script>
      <script type="application/json" id="myls-target-cache"><?php echo wp_json_encode($tgt_items); ?></script>

      <div class="row g-4">
        <!-- Source (single) -->
        <div class="col-md-6">
          <div class="card shadow-sm">
            <div class="card-body">
              <div class="d-flex align-items-center justify-content-between">
                <h5 class="card-title mb-0"><i class="bi bi-box-arrow-in-right me-1"></i>1) Choose Source Service Area (single)</h5>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="myls-reload-source">Reload</button>
              </div>

              <label for="myls-clone-sa-source-filter" class="form-label mt-3">Filter source by title</label>
              <input type="text" id="myls-clone-sa-source-filter" class="form-control mb-2" placeholder="Type to filter…">

              <select id="myls-clone-sa-source" class="form-select" size="12" aria-label="Source service area">
                <?php foreach ($src_items as $it): ?>
                  <option value="<?php echo esc_attr($it['id']); ?>">
                    <?php echo esc_html($it['title'] . ' (ID ' . $it['id'] . ')'); ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <div class="form-text mt-2">Copies content, meta (incl. Elementor), taxonomies, and featured image.</div>
            </div>
          </div>
        </div>

        <!-- Targets (multiple, hierarchical) -->
        <div class="col-md-6">
          <div class="card shadow-sm">
            <div class="card-body">
              <div class="d-flex align-items-center justify-content-between">
                <h5 class="card-title mb-0"><i class="bi bi-diagram-3 me-1"></i>2) Choose Target Parents (multiple, hierarchical)</h5>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="myls-reload-targets">Reload</button>
              </div>

              <label for="myls-clone-sa-target-filter" class="form-label mt-3">Filter targets by title</label>
              <input type="text" id="myls-clone-sa-target-filter" class="form-control mb-2" placeholder="Type to filter…">

              <select id="myls-clone-sa-targets" class="form-select" multiple size="12" aria-label="Target parent service areas">
                <?php foreach ($tgt_items as $node): ?>
                  <?php $indent = str_repeat('— ', $node['depth']); ?>
                  <option value="<?php echo esc_attr($node['id']); ?>" data-depth="<?php echo esc_attr($node['depth']); ?>">
                    <?php echo esc_html($indent . $node['title'] . ' (ID ' . $node['id'] . ')'); ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <div class="form-text mt-2">All published Service Areas are listed in a hierarchical, indented tree.</div>

              <label for="myls-clone-sa-slug" class="form-label mt-3">Slug for new clones (optional)</label>
              <input type="text" id="myls-clone-sa-slug" class="form-control mb-2" placeholder="e.g. roofing-in-tampa (leave empty to auto-generate)">
              <div class="form-text mb-2">If set, each clone will use this slug (sanitized). WordPress will suffix if needed.</div>

              <label for="myls-clone-sa-focus-base" class="form-label">Yoast Focus Keyphrase (base)</label>
              <input type="text" id="myls-clone-sa-focus-base" class="form-control mb-2" placeholder="e.g. Pool screen cleaning">
              <div class="form-text">We’ll append the parent’s <code>city_state</code>.</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Options -->
      <div class="form-check mt-3">
        <input class="form-check-input" type="checkbox" id="myls-clone-sa-draft" checked>
        <label class="form-check-label" for="myls-clone-sa-draft">Create clones as <strong>drafts</strong></label>
      </div>
      <div class="form-check mt-2">
        <input class="form-check-input" type="checkbox" id="myls-clone-sa-skip-existing" checked>
        <label class="form-check-label" for="myls-clone-sa-skip-existing">Skip if a child with the <em>same title</em> already exists under that parent</label>
      </div>
      <div class="form-check mt-2">
        <input class="form-check-input" type="checkbox" id="myls-clone-sa-debug">
        <label class="form-check-label" for="myls-clone-sa-debug">Show debug details</label>
      </div>

      <!-- Action -->
      <div class="mt-3">
        <button type="button" class="button button-primary" id="myls-clone-sa-run">Clone Now</button>
        <span class="spinner" id="myls-clone-sa-spinner" style="float:none; margin-left:8px; display:none;"></span>
      </div>

      <!-- Results -->
      <div class="mt-4">
        <div class="myls-results-header">
          <h5 class="mb-0">Results</h5>
          <button type="button" class="myls-btn-export-pdf" data-log-target="myls-clone-sa-results"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
        </div>
        <pre id="myls-clone-sa-results" class="myls-results-terminal">Ready.</pre>
      </div>
    </div>
    <?php
  }
];
