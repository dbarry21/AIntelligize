<?php
if ( ! defined('ABSPATH') ) exit;

return [
  'id'    => 'content-analyzer',
  'label' => 'Content Analyzer',
  'icon'  => 'bi-clipboard-data',
  'order' => 5,
  'render'=> function () {

    $nonce    = wp_create_nonce('myls_ai_ops');
    $base_url = defined('MYLS_URL') ? rtrim(MYLS_URL, '/') : '';
    $types    = get_post_types(['public' => true], 'objects');
    unset($types['attachment']);

    ?>
    <div class="card shadow-sm mb-4">
      <div class="card-header bg-dark text-white d-flex align-items-center gap-2">
        <i class="bi bi-clipboard-data fs-5"></i>
        <strong>Content Analyzer</strong>
        <span class="badge bg-info ms-auto">No AI calls — instant analysis</span>
      </div>
      <div class="card-body">

        <p class="text-muted mb-3">
          Audit existing pages for SEO completeness, content quality, and uniqueness.
          Generates actionable improvement plans per page with a batch scorecard.
        </p>

        <!-- Controls Row -->
        <div class="row g-3 mb-3">
          <div class="col-md-3">
            <label class="form-label fw-semibold" for="myls_ca_pt">Post Type</label>
            <select id="myls_ca_pt" class="form-select form-select-sm">
              <?php foreach ( $types as $slug => $obj ) : ?>
                <option value="<?php echo esc_attr($slug); ?>" <?php selected($slug, 'page'); ?>>
                  <?php echo esc_html($obj->labels->name); ?> (<?php echo esc_html($slug); ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-5">
            <label class="form-label fw-semibold" for="myls_ca_posts">
              Select Pages <small class="text-muted">(Ctrl/⌘ + Click for multi-select)</small>
            </label>
            <select id="myls_ca_posts" class="form-select form-select-sm" multiple size="8"></select>
          </div>

          <div class="col-md-4 d-flex flex-column justify-content-end gap-2">
            <div class="d-flex gap-2">
              <button id="myls_ca_run" type="button" class="btn btn-primary btn-sm flex-fill">
                <i class="bi bi-search"></i> Analyze
              </button>
              <button id="myls_ca_stop" type="button" class="btn btn-outline-danger btn-sm" disabled>
                <i class="bi bi-stop-circle"></i> Stop
              </button>
            </div>
            <div class="d-flex align-items-center gap-2">
              <small class="text-muted">
                Processed: <strong id="myls_ca_count">0</strong>
              </small>
              <small id="myls_ca_status" class="text-muted ms-auto"></small>
            </div>
          </div>
        </div>

        <!-- Scorecard Panel (populated by JS after analysis) -->
        <div id="myls_ca_scorecard" class="mb-3"></div>

        <!-- Results Log -->
        <div class="myls-results-header">
          <h3 class="h5 mb-0"><i class="bi bi-terminal"></i> Analysis Results</h3>
          <button type="button" class="myls-btn-export-pdf" data-log-target="myls_ca_results">
            <i class="bi bi-file-earmark-pdf"></i> PDF
          </button>
        </div>
        <pre id="myls_ca_results" class="myls-results-terminal">Ready.</pre>

      </div>
    </div>
    <?php

    // Enqueue JS
    add_action('admin_footer', function() use ($nonce, $base_url) {
      $script_url = $base_url . '/assets/js/myls-ai-content-analyzer.js';
      ?>
      <script>
      window.MYLS_CONTENT_ANALYZER = {
        ajaxurl: "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>",
        nonce:   "<?php echo esc_js( $nonce ); ?>"
      };
      </script>
      <script src="<?php echo esc_url( $script_url . '?v=' . (defined('MYLS_VERSION') ? MYLS_VERSION : time()) ); ?>"></script>
      <?php
    });

  }
];
