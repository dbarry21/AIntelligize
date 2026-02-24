<?php
/**
 * Utilities → ACF Migrations
 */
if ( ! defined('ABSPATH') ) exit;

$nonce = wp_create_nonce( defined('MYLS_UTIL_NONCE_ACTION') ? MYLS_UTIL_NONCE_ACTION : 'myls_utilities' );

?>
<div class="wrap myls-admin-wrap">
  <h2>Utilities</h2>

  <div class="card" style="max-width: 980px;">
    <div class="card-header">ACF → Native Migrations (Batch Safe)</div>
    <div class="card-body">
      <p class="mb-3">Use these tools to migrate legacy ACF-stored values into MYLS-native post meta. Runs in small batches to avoid timeouts.</p>

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label"><strong>Batch Size</strong></label>
          <select id="myls-util-batch-size" class="form-select">
            <option value="10">10</option>
            <option value="25" selected>25</option>
            <option value="50">50</option>
            <option value="100">100</option>
          </select>
        </div>
        <div class="col-md-6 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="myls-util-overwrite" value="1">
            <label class="form-check-label" for="myls-util-overwrite">
              Overwrite existing MYLS values
            </label>
          </div>
        </div>
      </div>

      <hr>

      <div class="d-flex flex-wrap gap-2">
        <button type="button" class="button button-primary" id="myls-btn-migrate-faqs">Run FAQ Migration</button>
        <button type="button" class="button button-primary" id="myls-btn-migrate-citystate">Run City/State Migration</button>
        <button type="button" class="button" id="myls-btn-clean-faqs">Clean MYLS FAQs (remove empty)</button>
        <button type="button" class="button" id="myls-btn-stop" disabled>Stop</button>
      </div>

      <input type="hidden" id="myls-util-nonce" value="<?php echo esc_attr($nonce); ?>">

      <div class="myls-results-header">
        <h3 style="margin:0;">Results</h3>
        <button type="button" class="myls-btn-export-pdf" data-log-target="myls-util-log"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
      </div>
      <pre id="myls-util-log" class="myls-results-terminal">Ready.</pre>

      <p class="description" style="margin-top:12px;">
        FAQ migration reads ACF repeater meta stored as either an array in <code>faq_items</code> or classic ACF row metas like <code>faq_items_0_question</code>.
        City/State migration copies <code>city_state</code> into <code>_myls_city_state</code>.
        Cleaning removes empty MYLS FAQ rows (blank question or blank/empty HTML answer) from <code>_myls_faq_items</code>.
      </p>
    </div>
  </div>
</div>
