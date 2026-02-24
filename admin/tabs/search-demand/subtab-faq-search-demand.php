<?php
/**
 * Search Demand Subtab: FAQ Search Demand
 * File: admin/tabs/search-demand/subtab-faq-search-demand.php
 *
 * Two sections:
 *  1. Manual search terms (textarea + batch check)
 *  2. Site-wide FAQ audit (load table → check progressively)
 *
 * @since 6.3.2.7
 */

if ( ! defined('ABSPATH') ) exit;

return [
  'id'    => 'faq-search-demand',
  'label' => 'FAQ Search Demand',
  'icon'  => 'bi-patch-question',
  'order' => 10,
  'render'=> function () {

    $nonce = wp_create_nonce('myls_ai_ops');
    ?>

<!-- ══════════ Manual Search Terms ══════════ -->
<div class="myls-card mb-3">
  <div class="myls-card-header">
    <h2 class="myls-card-title"><i class="bi bi-search"></i> Manual Search Terms</h2>
  </div>

  <p class="description mb-3">
    Enter any search terms (one per line) and check them against Google Autocomplete. Max 20 per batch.
  </p>

  <textarea id="myls_fsd_terms" class="widefat" rows="8"
    placeholder="Enter search terms here, one per line…&#10;&#10;Example:&#10;roof repair cost near me&#10;best roofing material for florida&#10;do i need a permit for a new roof"></textarea>

  <div class="mt-2 d-flex flex-wrap align-items-center gap-2">
    <button type="button" class="button button-primary" id="myls_fsd_check_terms">
      <i class="bi bi-graph-up-arrow"></i> Check Search Demand
    </button>
    <button type="button" class="button" id="myls_fsd_clear_terms">
      <i class="bi bi-x-circle"></i> Clear
    </button>
    <span class="myls-sd-inline-spinner" id="myls_fsd_manual_spinner" style="display:none;">
      <span class="dashicons dashicons-update myls-spin"></span>
      <span>Checking…</span>
    </span>
  </div>

  <div id="myls_fsd_manual_summary" class="mt-3"></div>
  <div id="myls_fsd_manual_results" class="mt-2"></div>

  <hr class="myls-divider"/>
  <details>
    <summary class="fw-semibold" style="cursor:pointer;"><i class="bi bi-info-circle"></i> Score Key</summary>
    <div class="mt-2 small text-muted" style="line-height:1.7;">
      <span class="myls-sd-badge myls-sd-high">✅ Strong</span> Exact or very similar term in suggestions (80%+)<br/>
      <span class="myls-sd-badge myls-sd-medium">🟡 Partial</span> Significant keyword overlap (50–79%)<br/>
      <span class="myls-sd-badge myls-sd-low">🟠 Weak</span> Some keyword overlap (30–49%)<br/>
      <span class="myls-sd-badge myls-sd-none">❌ None</span> Not found in Google suggestions<br/>
      <p class="mt-2 mb-0">"No Match" doesn't mean zero volume — it means Google doesn't currently autocomplete it.</p>
    </div>
  </details>
</div>


<!-- ══════════ SITE-WIDE FAQ AUDIT ══════════ -->
<div class="myls-card">
  <div class="myls-card-header">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h2 class="myls-card-title"><i class="bi bi-globe2"></i> Site-Wide FAQ Audit</h2>
      <span class="myls-sd-badge myls-sd-info" id="myls_fsd_site_badge" style="display:none;">
        <span id="myls_fsd_site_post_count">0</span> posts &bull;
        <span id="myls_fsd_site_faq_count">0</span> FAQs
      </span>
    </div>
  </div>

  <p class="description mb-3">
    Load every MYLS FAQ from your site into a table, then check demand progressively — one at a time so Google isn't hit too hard.
  </p>

  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <button type="button" class="button button-primary" id="myls_fsd_site_load">
      <i class="bi bi-cloud-download"></i> Load All Site FAQs
    </button>
    <button type="button" class="button button-primary" id="myls_fsd_site_check" disabled>
      <i class="bi bi-graph-up-arrow"></i> Check Demand
    </button>
    <button type="button" class="button" id="myls_fsd_site_stop" disabled>
      <i class="bi bi-stop-circle"></i> Stop
    </button>
    <span class="myls-sd-inline-spinner" id="myls_fsd_site_spinner" style="display:none;">
      <span class="dashicons dashicons-update myls-spin"></span>
      <span id="myls_fsd_site_spinner_text">Working…</span>
    </span>
  </div>

  <!-- Progress bar -->
  <div id="myls_fsd_site_progress_wrap" style="display:none;" class="mb-3">
    <div class="myls-sd-progress-bar-outer">
      <div id="myls_fsd_site_progress_bar" class="myls-sd-progress-bar-inner" style="width:0%"></div>
    </div>
    <div class="d-flex justify-content-between mt-1 small text-muted">
      <span id="myls_fsd_site_progress_text">0 / 0</span>
      <span id="myls_fsd_site_progress_pct">0%</span>
    </div>
  </div>

  <div id="myls_fsd_site_summary" class="mb-2"></div>
  <div id="myls_fsd_site_results">
    <p class="text-muted">Click "Load All Site FAQs" to see all FAQ questions across your site.</p>
  </div>
</div>

<?php
    add_action('admin_print_footer_scripts', function() use ($nonce) {
      static $did = false;
      if ($did) return;
      $did = true;
      if ( empty($_GET['page']) || sanitize_key($_GET['page']) !== 'aintelligize' ) return;

      $v  = defined('MYLS_VERSION') ? MYLS_VERSION : (string)time();
      $js = rtrim(MYLS_URL, '/') . '/assets/js/myls-faq-search-demand.js';
      ?>
      <script>
      window.MYLS_FAQ_SD = {
        ajaxurl:             "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>",
        nonce:               "<?php echo esc_js( $nonce ); ?>",
        action_check_batch:  "myls_faq_search_check_v1",
        action_check_single: "myls_faq_search_check_single_v1",
        action_get_all_faqs: "myls_sd_get_all_site_faqs_v1"
      };
      </script>
      <script src="<?php echo esc_url( $js . '?v=' . rawurlencode($v) ); ?>"></script>
      <?php myls_sd_shared_css(); ?>
      <?php
    }, 9999);
  }
];
