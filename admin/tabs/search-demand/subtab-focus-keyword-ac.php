<?php
/**
 * Search Demand Subtab: Focus Keyword AC Options
 * File: admin/tabs/search-demand/subtab-focus-keyword-ac.php
 *
 * Three-step flow:
 *  1. Load focus keywords by post type
 *  2. Get AC suggestions (5 queries per keyword)
 *  3. Enrich with GSC data (impressions, clicks, CTR, position)
 *
 * @since 6.3.2.7
 */

if ( ! defined('ABSPATH') ) exit;

return [
  'id'    => 'focus-keyword-ac',
  'label' => 'Focus Keyword AC Options',
  'icon'  => 'bi-crosshair',
  'order' => 20,
  'render'=> function () {

    $nonce = wp_create_nonce('myls_ai_ops');
    $pts   = get_post_types(['public' => true], 'objects');
    unset($pts['attachment']);

    // Check GSC connection status
    $gsc_connected = false;
    if ( function_exists('myls_gsc_is_connected') ) {
      $gsc_connected = myls_gsc_is_connected();
    }

    ?>

<!-- ══════════ Load + Check + Enrich ══════════ -->
<div class="myls-card mb-3">
  <div class="myls-card-header">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h2 class="myls-card-title"><i class="bi bi-crosshair"></i> Focus Keywords</h2>
      <span class="myls-sd-badge myls-sd-info" id="myls_fkac_badge" style="display:none;">
        <span id="myls_fkac_post_count">0</span> posts &bull;
        <span id="myls_fkac_kw_count">0</span> keywords
      </span>
    </div>
  </div>

  <p class="description mb-3">
    Load all focus keywords from your site (Yoast SEO, Rank Math, or AIOSEO), get Google Autocomplete
    suggestions, then enrich with real search volume data from Google Search Console.
  </p>

  <!-- Row 1: Post Type + Load + AC Suggestions + Stop -->
  <div class="d-flex flex-wrap align-items-end gap-2 mb-2">
    <div>
      <label class="form-label mb-1">Post Type</label>
      <select id="myls_fkac_pt" class="form-select" style="min-width:200px;">
        <option value="all">All Post Types</option>
        <?php foreach ($pts as $pt_key => $obj): ?>
          <option value="<?php echo esc_attr($pt_key); ?>">
            <?php echo esc_html($obj->labels->singular_name . " ({$pt_key})"); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="d-flex align-items-end gap-2">
      <button type="button" class="button button-primary" id="myls_fkac_load">
        <i class="bi bi-cloud-download"></i> Load Focus Keywords
      </button>
      <button type="button" class="button button-primary" id="myls_fkac_check" disabled>
        <i class="bi bi-graph-up-arrow"></i> Get AC Suggestions
      </button>
      <button type="button" class="button" id="myls_fkac_stop" disabled>
        <i class="bi bi-stop-circle"></i> Stop
      </button>
    </div>
  </div>

  <!-- Row 2: GSC Enrichment -->
  <div class="d-flex flex-wrap align-items-end gap-2 mb-3" style="border-top:1px solid #e5e5e5;padding-top:10px;">
    <div>
      <label class="form-label mb-1">GSC Date Range</label>
      <select id="myls_fkac_gsc_days" class="form-select" style="min-width:160px;">
        <option value="30">Last 30 days</option>
        <option value="60">Last 60 days</option>
        <option value="90" selected>Last 90 days</option>
      </select>
    </div>

    <div class="d-flex align-items-end gap-2">
      <button type="button" class="button button-primary" id="myls_fkac_gsc" disabled>
        <i class="bi bi-google"></i> Enrich with GSC
      </button>
      <button type="button" class="button" id="myls_fkac_gsc_stop" disabled>
        <i class="bi bi-stop-circle"></i> Stop
      </button>
      <button type="button" class="button" id="myls_fkac_print" style="display:none;">
        <i class="bi bi-printer"></i> Print Report
      </button>

      <?php if ( ! $gsc_connected ): ?>
        <span class="small text-muted" style="line-height:1.3;max-width:300px;">
          <i class="bi bi-exclamation-triangle text-warning"></i>
          GSC not connected. Set up in <strong>API Integration</strong> tab.
        </span>
      <?php endif; ?>
    </div>

    <span class="myls-sd-inline-spinner" id="myls_fkac_spinner" style="display:none;">
      <span class="dashicons dashicons-update myls-spin"></span>
      <span id="myls_fkac_spinner_text">Working…</span>
    </span>
  </div>

  <!-- Progress bar -->
  <div id="myls_fkac_progress_wrap" style="display:none;" class="mb-3">
    <div class="myls-sd-progress-bar-outer">
      <div id="myls_fkac_progress_bar" class="myls-sd-progress-bar-inner" style="width:0%"></div>
    </div>
    <div class="d-flex justify-content-between mt-1 small text-muted">
      <span id="myls_fkac_progress_text">0 / 0</span>
      <span id="myls_fkac_progress_pct">0%</span>
    </div>
  </div>

  <div id="myls_fkac_summary" class="mb-2"></div>
  <div id="myls_fkac_results">
    <p class="text-muted">Select a post type and click "Load Focus Keywords" to see all keywords across your site.</p>
  </div>
</div>

<!-- ══════════ How it works ══════════ -->
<div class="myls-card myls-no-print">
  <div class="myls-card-header">
    <h2 class="myls-card-title"><i class="bi bi-lightbulb"></i> How It Works</h2>
  </div>
  <div class="small" style="line-height:1.8;">
    <p>
      Short focus keywords like <strong>"roof repair"</strong> or <strong>"plumber"</strong>
      are great for SEO targeting, but you need to know what real people type into Google around those keywords.
    </p>
    <p><strong>Step 1:</strong> Load keywords → <strong>Step 2:</strong> Get AC suggestions (5 queries each) →
       <strong>Step 3:</strong> Enrich with GSC data (impressions, clicks, CTR, position).</p>
    <table class="widefat" style="font-size:12px;">
      <thead><tr><th>AC Query</th><th>Example</th><th>Discovers</th></tr></thead>
      <tbody>
        <tr><td><strong>Exact</strong></td><td><code>roof repair</code></td><td>Direct completions</td></tr>
        <tr><td><strong>Expanded</strong></td><td><code>roof repair </code> (trailing space)</td><td>Next-word suggestions</td></tr>
        <tr><td><strong>How</strong></td><td><code>how roof repair</code></td><td>Process / DIY questions</td></tr>
        <tr><td><strong>What</strong></td><td><code>what roof repair</code></td><td>Definition / cost queries</td></tr>
        <tr><td><strong>Best</strong></td><td><code>best roof repair</code></td><td>Comparison / quality terms</td></tr>
      </tbody>
    </table>
    <p class="mt-2 mb-0">
      GSC enrichment queries your Search Console for each keyword, matching AC suggestions to real search data.
      Suggestions with GSC data show impressions and clicks. Those without are <em>untapped opportunities</em>.
    </p>
  </div>
</div>

<?php
    add_action('admin_print_footer_scripts', function() use ($nonce, $gsc_connected) {
      static $did = false;
      if ($did) return;
      $did = true;
      if ( empty($_GET['page']) || sanitize_key($_GET['page']) !== 'aintelligize' ) return;

      $v  = defined('MYLS_VERSION') ? MYLS_VERSION : (string)time();
      $js = rtrim(MYLS_URL, '/') . '/assets/js/myls-focus-kw-ac.js';
      ?>
      <script>
      window.MYLS_FKAC = {
        ajaxurl:              "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>",
        nonce:                "<?php echo esc_js( $nonce ); ?>",
        action_check_single:  "myls_faq_search_check_single_v1",
        action_get_all_fk:    "myls_sd_get_all_focus_keywords_v1",
        action_gsc_query:     "myls_sd_gsc_query_v1",
        gsc_connected:        <?php echo $gsc_connected ? 'true' : 'false'; ?>
      };
      </script>
      <script src="<?php echo esc_url( $js . '?v=' . rawurlencode($v) ); ?>"></script>
      <?php myls_sd_shared_css(); ?>
      <?php
    }, 9999);
  }
];
