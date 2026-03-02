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
        <span class="badge bg-info ms-auto">Instant Analysis</span> <span class="badge ms-1" style="background:#6f42c1;">AI Deep Analysis</span>
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
            <!-- Row 1: Instant analyze + stop -->
            <div class="d-flex gap-2">
              <button id="myls_ca_run" type="button" class="btn btn-primary btn-sm flex-fill">
                <i class="bi bi-search"></i> Analyze
              </button>
              <button id="myls_ca_stop" type="button" class="btn btn-outline-danger btn-sm" disabled>
                <i class="bi bi-stop-circle"></i> Stop
              </button>
            </div>
            <!-- Row 2: AI Deep Analysis -->
            <div class="d-flex gap-2">
              <button id="myls_ca_deep_run" type="button" class="btn btn-sm flex-fill" style="background:#6f42c1;color:#fff;border-color:#6f42c1;">
                <i class="bi bi-stars"></i> AI Deep Analysis
              </button>
              <button id="myls_ca_deep_stop" type="button" class="btn btn-outline-danger btn-sm" disabled>
                <i class="bi bi-stop-circle"></i> Stop
              </button>
            </div>
            <!-- Status row -->
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

        <!-- Instant Analysis Results -->
        <div class="myls-results-header">
          <h3 class="h5 mb-0"><i class="bi bi-terminal"></i> Analysis Results</h3>
          <button type="button" class="myls-btn-export-pdf" data-log-target="myls_ca_results">
            <i class="bi bi-file-earmark-pdf"></i> PDF
          </button>
        </div>
        <pre id="myls_ca_results" class="myls-results-terminal">Ready.</pre>

        <!-- AI Deep Analysis Results -->
        <div class="myls-results-header mt-3" style="border-top:3px solid #6f42c1;padding-top:12px;">
          <h3 class="h5 mb-0">
            <i class="bi bi-stars" style="color:#6f42c1;"></i>
            AI Deep Analysis Results
          </h3>
          <div class="d-flex gap-2 align-items-center">
            <button id="myls_ca_deep_pdf" type="button" class="btn btn-sm"
              style="background:#6f42c1;color:#fff;border-color:#6f42c1;display:none;">
              <i class="bi bi-file-earmark-arrow-down"></i> Download PDF Report
            </button>
            <button type="button" class="myls-btn-export-pdf" data-log-target="myls_ca_deep_log"
              title="Export terminal log to browser print/PDF">
              <i class="bi bi-printer"></i> Print Log
            </button>
          </div>
        </div>

        <!-- AI Results: card UI (rich view) -->
        <div id="myls_ca_deep_cards" style="display:none;">
          <!-- JS renders analysis cards here -->
        </div>

        <!-- AI Results: terminal log (raw, collapsible) -->
        <div id="myls_ca_deep_log_wrap" style="margin-top:10px;">
          <button type="button" id="myls_ca_deep_log_toggle"
            class="btn btn-outline-secondary btn-sm mb-2" style="font-size:12px;">
            <i class="bi bi-terminal"></i> Show Raw Log
          </button>
          <pre id="myls_ca_deep_log" class="myls-results-terminal"
            style="display:none;">Run AI Deep Analysis to see results here.</pre>
        </div>

        <style>
        /* ── AI Deep Analysis Card UI ─────────────────────── */
        .myls-deep-post-card {
          border:1px solid #e0e0e0; border-radius:8px; margin-bottom:16px;
          overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.06);
        }
        .myls-deep-post-header {
          background:#1d2327; color:#fff; padding:10px 14px;
          display:flex; align-items:center; gap:10px;
        }
        .myls-deep-post-num {
          background:#6f42c1; color:#fff; font-size:11px; font-weight:700;
          padding:2px 7px; border-radius:4px; white-space:nowrap;
        }
        .myls-deep-post-title { font-weight:600; font-size:13px; flex:1; }
        .myls-deep-post-url {
          font-size:11px; color:#9da3ae; display:block; margin-top:2px;
          white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:500px;
        }
        .myls-deep-meta-strip {
          background:#f8f9fa; border-bottom:1px solid #e9ecef;
          padding:6px 14px; display:flex; gap:20px; flex-wrap:wrap;
        }
        .myls-deep-chip { font-size:11px; color:#6c757d; }
        .myls-deep-chip strong { color:#1d2327; }
        .myls-deep-sections { padding:0; }
        .myls-deep-section {
          border-bottom:1px solid #f0f0f0; padding:12px 14px;
        }
        .myls-deep-section:last-child { border-bottom:none; }
        .myls-deep-section-label {
          display:inline-block; font-size:10px; font-weight:700;
          padding:2px 8px; border-radius:3px; margin-bottom:6px;
          letter-spacing:.5px;
        }
        .myls-deep-section-heading {
          font-size:12.5px; font-weight:700; color:#1d2327; margin-bottom:6px;
        }
        .myls-deep-section-body {
          font-size:12.5px; color:#2d3338; line-height:1.6;
          white-space:pre-wrap; word-break:break-word;
        }
        .myls-deep-empty {
          padding:40px; text-align:center; color:#9da3ae;
          font-size:13px; border:2px dashed #e0e0e0; border-radius:8px;
        }
        </style>

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
