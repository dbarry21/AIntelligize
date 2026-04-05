<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Schema > Service (Two-column; Local Business styling)
 * - FIX: Keep selected posts persistent unless explicitly deselected.
 *
 * Problem (what was happening):
 * - A native <select multiple> only submits currently-selected options.
 * - If you "filter" by hiding options and then click "Clear" (or change visible selection),
 *   any hidden selections may be lost on submit because they were never re-selected/submitted.
 *
 * Solution:
 * 1) JS keeps an internal Set() of "persisted selections" and mirrors it into hidden inputs.
 *    -> Hidden inputs are ALWAYS submitted, even when options are hidden.
 * 2) "Clear" only clears visible options AND updates the persisted set accordingly.
 * 3) PHP on_save accepts hidden inputs. If they exist, they become the source of truth.
 */

$spec = [
  'id'    => 'serviceschema',
  'label' => 'Service',
  'render'=> function () {

    // Match ssseo-tools list
    $service_types = [
      '',
      'LocalBusiness','Plumber','Electrician','HVACBusiness','RoofingContractor','PestControl',
      'LegalService','CleaningService','AutoRepair','MedicalBusiness','Locksmith','MovingCompany',
      'RealEstateAgent','ITService',
    ];

    $enabled        = get_option('myls_service_enabled','0');
    $default_type   = get_option('myls_service_default_type','');
    $subtype        = get_option('myls_service_subtype','');
    $service_output = get_option('myls_service_output','');

    // Detect optional CPTs
    $has_service_cpt       = post_type_exists('service');
    $has_service_area_cpt  = post_type_exists('service_area');

    // Selected IDs (saved)
    $selected_ids = array_values(array_unique(array_map('absint', (array) get_option('myls_service_pages', []))));

    // Price ranges — stored as array of {label, low, high, currency, post_ids[]}
    $price_ranges = (array) get_option( 'myls_service_price_ranges', [] );
    // Ensure each entry is a well-formed array with defaults
    $price_ranges = array_values( array_map( function( $r ) {
      return [
        'label'    => sanitize_text_field( $r['label']    ?? '' ),
        'low'      => sanitize_text_field( $r['low']      ?? '' ),
        'high'     => sanitize_text_field( $r['high']     ?? '' ),
        'currency' => sanitize_text_field( $r['currency'] ?? 'USD' ),
        'post_ids' => array_values( array_map( 'absint', (array) ( $r['post_ids'] ?? [] ) ) ),
      ];
    }, $price_ranges ) );

    // --- Build hierarchical "Pages"
    $pages = get_pages([
      'sort_order'  => 'asc',
      'sort_column' => 'menu_order,post_title',
      'post_status' => ['publish'],
    ]);

    // Children map for pages
    $page_children = [];
    foreach ( $pages as $pg ) {
      $page_children[ (int)$pg->post_parent ][] = $pg;
    }

    $render_page_options = function($parent_id, $depth) use (&$render_page_options, $page_children, $selected_ids) {
      if ( empty($page_children[$parent_id]) ) return;
      foreach ( $page_children[$parent_id] as $pg ) {
        $indent = str_repeat('— ', max(0, (int)$depth));
        $sel    = in_array((int)$pg->ID, $selected_ids, true) ? 'selected' : '';
        printf(
          '<option data-ptype="page" value="%d" %s>%s%s</option>',
          (int)$pg->ID,
          $sel,
          esc_html($indent),
          esc_html($pg->post_title)
        );
        $render_page_options($pg->ID, $depth+1);
      }
    };

    // Flat Posts (non-hierarchical)
    $posts = get_posts([
      'post_type'      => 'post',
      'post_status'    => 'publish',
      'posts_per_page' => -1,
      'orderby'        => 'title',
      'order'          => 'asc',
    ]);

    // Services (if CPT exists)
    $services = [];
    if ( $has_service_cpt ) {
      $services = get_posts([
        'post_type'      => 'service',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'asc',
      ]);
    }

    // Service Areas (if CPT exists)
    $service_areas = [];
    if ( $has_service_area_cpt ) {
      $service_areas = get_posts([
        'post_type'      => 'service_area',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'asc',
      ]);
    }
    ?>

    <style>
      /* Same look as Local Business */
      .myls-svc-wrap { width: 100%; }
      .myls-svc-grid { display:flex; flex-wrap:wrap; gap:8px; align-items:stretch; }
      .myls-svc-left  { flex:3 1 520px; min-width:320px; }
      .myls-svc-right { flex:1 1 280px; min-width:260px; }

      .myls-block { background:#fff; border:1px solid #000; border-radius:1em; padding:12px; }
      .myls-block-title { font-weight:800; margin:0 0 8px; }

      .myls-svc-wrap input[type="text"], .myls-svc-wrap input[type="email"], .myls-svc-wrap input[type="url"],
      .myls-svc-wrap input[type="time"], .myls-svc-wrap input[type="tel"], .myls-svc-wrap textarea, .myls-svc-wrap select {
        border:1px solid #000 !important; border-radius:1em !important; padding:.6rem .9rem; width:100%;
      }
      .form-label { font-weight:600; margin-bottom:.35rem; display:block; }
      .myls-hr { height:1px; background:#000; opacity:.15; border:0; margin:8px 0 10px; }
      .myls-actions { margin-top:10px; display:flex; gap:.5rem; flex-wrap: wrap; }
      .myls-btn { display:inline-block; font-weight:600; border:1px solid #000; padding:.45rem .9rem; border-radius:1em; background:#f8f9fa; color:#111; cursor:pointer; }
      .myls-btn-primary { background:#0d6efd; color:#fff; border-color:#0d6efd; }
      .myls-btn-outline { background:transparent; }
      .myls-btn:hover { filter:brightness(.97); }

      .myls-row { display:flex; flex-wrap:wrap; margin-left:-.5rem; margin-right:-.5rem; }
      .myls-col { padding-left:.5rem; padding-right:.5rem; margin-bottom:.75rem; }
      .col-12 { flex:0 0 100%; max-width:100%; }
      .col-6  { flex:0 0 50%;  max-width:50%; }

      /* Filter toolbar in right column */
      .myls-filter { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
      .myls-filter .chip { display:inline-flex; gap:.35rem; align-items:center; border:1px solid #000; border-radius:999px; padding:.3rem .6rem; background:#fff; }
      .myls-filter input { margin:0; }
      .myls-select { width:100%; min-height:420px; }
      optgroup { font-weight:700; }

      /* Search input */
      .myls-search { width:100%; margin:.25rem 0 .5rem; }
      .myls-search small { display:block; opacity:.8; margin-top:.25rem; }

      /* Hidden persisted selection container (not visible) */
      #myls-service-pages-hidden { display:none; }
    </style>

    <div class="myls-svc-wrap">
      <!-- NOTE: No inner <form> here. The outer form in tab-schema.php wraps this
           entire subtab and handles POST submission + nonce verification.
           Adding a second <form> here causes the browser to close the outer form early,
           orphaning the bottom "Save Settings" button outside any form. -->

      <div class="myls-svc-grid">
        <!-- LEFT (75%) -->
        <div class="myls-svc-left">
          <div class="myls-block">
            <div class="myls-block-title">Service Schema</div>

            <div class="myls-row">
              <div class="myls-col col-6">
                <label class="form-label">Enable Service Schema</label>
                <select name="myls_service_enabled">
                  <option value="0" <?php selected('0', $enabled); ?>>Disabled</option>
                  <option value="1" <?php selected('1', $enabled); ?>>Enabled</option>
                </select>
              </div>

              <div class="myls-col col-6">
                <label class="form-label">Default Service Type</label>
                <select name="myls_service_default_type">
                  <?php foreach ( $service_types as $opt ): ?>
                    <option value="<?php echo esc_attr($opt); ?>" <?php selected($default_type, $opt); ?>>
                      <?php echo $opt === '' ? '— Select —' : esc_html($opt); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">Used when a Service doesn’t specify a specific type.</div>
              </div>

              <div class="myls-col col-12">
                <label class="form-label">Service Subtype (optional)</label>
                <input type="text"
                       name="myls_service_subtype"
                       value="<?php echo esc_attr($subtype); ?>"
                       placeholder="Example: Paver Sealing, Dryer Vent Cleaning, Emergency Leak Repair">
                <div class="form-text">
                  Outputs in schema as <code>serviceType</code> (as a secondary value) after the primary title-based <code>serviceType</code>.
                </div>
              </div>

              <div class="myls-col col-12">
                <label class="form-label">Service Output (optional)</label>
                <input type="text"
                       name="myls_service_output"
                       value="<?php echo esc_attr($service_output); ?>"
                       placeholder="e.g. Clean, mold-free driveway surface">
                <div class="form-text">
                  The <strong>tangible deliverable</strong> the customer receives — a noun phrase, not a process description.
                  Outputs as <code>serviceOutput.name</code> in Service schema.
                  If left blank, a smart default is derived from the service type.
                  <strong>Do not use a sentence here</strong> — e.g. "Professionally cleaned exterior surfaces", not "We clean surfaces."
                </div>
              </div>
            </div>

            <div class="myls-actions">
              <button class="myls-btn myls-btn-primary" type="submit">Save Settings</button>
              <details>
                <summary style="cursor:pointer">Debug</summary>
                <pre style="white-space:pre-wrap"><?php
                  echo esc_html( sprintf(
                    "enabled=%s\ndefault_type=%s\nsubtype=%s\nselected_count=%d",
                    $enabled,
                    $default_type,
                    $subtype,
                    count($selected_ids)
                  ) );
                ?></pre>
              </details>
            </div>
          </div>
        </div>

        <!-- RIGHT (25%) -->
        <div class="myls-svc-right">
          <div class="myls-block">
            <div class="myls-block-title">
              Apply on Services / Pages / Posts<?php
                if ( ! $has_service_cpt ) echo ' <span class="form-text" style="display:block;margin-top:.25rem">Note: <code>service</code> CPT not detected.</span>';
                if ( ! $has_service_area_cpt ) echo ' <span class="form-text" style="display:block;margin-top:.25rem">Note: <code>service_area</code> CPT not detected.</span>';
              ?>
            </div>

            <!-- Filters -->
            <div class="myls-actions myls-filter" style="margin-bottom:.5rem">
              <label class="chip">
                <input type="checkbox" class="myls-ptype" value="page" checked> Pages
              </label>
              <label class="chip">
                <input type="checkbox" class="myls-ptype" value="post" checked> Posts
              </label>
              <?php if ( $has_service_cpt ): ?>
                <label class="chip">
                  <input type="checkbox" class="myls-ptype" value="service" checked> Service
                </label>
              <?php endif; ?>
              <?php if ( $has_service_area_cpt ): ?>
                <label class="chip">
                  <input type="checkbox" class="myls-ptype" value="service_area" checked> Service Area
                </label>
              <?php endif; ?>
              <button type="button" class="myls-btn myls-btn-outline" id="myls-service-select-all">Select All</button>
              <button type="button" class="myls-btn myls-btn-outline" id="myls-service-clear">Clear</button>
            </div>

            <!-- Search filter -->
            <div class="myls-search">
              <label class="form-label" style="margin-bottom:.25rem;">Search</label>
              <input type="text" id="myls-service-search" placeholder="Type to filter titles...">
              <small class="form-text">Filters visible options only (does not change saved selections).</small>
            </div>

            <!-- Persisted selections are submitted here (source of truth on save) -->
            <div id="myls-service-pages-hidden" aria-hidden="true"></div>

            <!-- Hierarchical, grouped select -->
            <select id="myls-service-pages" class="myls-select" multiple size="18">
              <optgroup label="Pages">
                <?php $render_page_options(0, 0); ?>
              </optgroup>

              <optgroup label="Posts">
                <?php foreach ( $posts as $p ):
                  $sel = in_array((int)$p->ID, $selected_ids, true) ? 'selected' : '';
                  printf('<option data-ptype="post" value="%d" %s>%s</option>',
                    (int)$p->ID, $sel, esc_html($p->post_title));
                endforeach; ?>
              </optgroup>

              <?php if ( $has_service_cpt ): ?>
                <optgroup label="Service">
                  <?php foreach ( $services as $p ):
                    $sel = in_array((int)$p->ID, $selected_ids, true) ? 'selected' : '';
                    printf('<option data-ptype="service" value="%d" %s>%s</option>',
                      (int)$p->ID, $sel, esc_html($p->post_title));
                  endforeach; ?>
                </optgroup>
              <?php endif; ?>

              <?php if ( $has_service_area_cpt ): ?>
                <optgroup label="Service Area">
                  <?php foreach ( $service_areas as $p ):
                    $sel = in_array((int)$p->ID, $selected_ids, true) ? 'selected' : '';
                    printf('<option data-ptype="service_area" value="%d" %s>%s</option>',
                      (int)$p->ID, $sel, esc_html($p->post_title));
                  endforeach; ?>
                </optgroup>
              <?php endif; ?>
            </select>

            <div class="form-text" style="margin-top:.5rem">
              Hold <strong>Ctrl/Cmd</strong> to select multiple. Use the chips to filter by post type.
            </div>

            <!-- IMPORTANT:
              We removed name="myls_service_pages[]" from the visible select on purpose.
              The hidden container will submit myls_service_pages_persist[] instead.
            -->
          </div>
        </div>

        <!-- PRICE RANGES BLOCK (full-width, below the two-column grid) -->
        <div style="margin-top:8px;width:100%;">
          <div class="myls-block">
            <div class="myls-block-title">💲 Service Price Ranges
              <span class="form-text" style="font-weight:400;display:inline;margin-left:.5rem;">
                Assign a low/high price range to specific posts. Outputs in Service schema as
                <code>offers → PriceSpecification</code> (minPrice / maxPrice).
              </span>
            </div>

            <!-- Full serialised state written here by JS before submit -->
            <input type="hidden" id="myls-price-ranges-json" name="myls_price_ranges_json"
                   value="<?php echo esc_attr( wp_json_encode( $price_ranges ) ); ?>">

            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;margin-top:10px;">

              <!-- LEFT: Repeater -->
              <div style="flex:3 1 480px;min-width:300px;">
                <div style="margin-bottom:.5rem;display:flex;align-items:center;gap:.5rem;">
                  <strong>Price Ranges</strong>
                  <button type="button" class="myls-btn myls-btn-primary" id="myls-add-range">+ Add Range</button>
                </div>
                <div id="myls-ranges-list"></div>
                <p class="form-text" style="margin-top:.5rem;">
                  Click <strong>Assign Posts →</strong> on any row to pick which posts use that price range.
                </p>
              </div>

              <!-- RIGHT: Assignment panel for the active range -->
              <div style="flex:1 1 260px;min-width:240px;">
                <div class="myls-block" style="border-color:#0d6efd;">
                  <div class="myls-block-title" id="myls-price-assign-title" style="color:#0d6efd;margin-bottom:.5rem;">
                    ← Select a range to assign posts
                  </div>

                  <!-- Post type chips -->
                  <div class="myls-actions myls-filter" style="margin-bottom:.5rem;">
                    <label class="chip"><input type="checkbox" class="myls-price-ptype" value="page" checked> Pages</label>
                    <label class="chip"><input type="checkbox" class="myls-price-ptype" value="post" checked> Posts</label>
                    <?php if ( $has_service_cpt ): ?>
                      <label class="chip"><input type="checkbox" class="myls-price-ptype" value="service" checked> Service</label>
                    <?php endif; ?>
                    <?php if ( $has_service_area_cpt ): ?>
                      <label class="chip"><input type="checkbox" class="myls-price-ptype" value="service_area" checked> Svc Area</label>
                    <?php endif; ?>
                    <button type="button" class="myls-btn myls-btn-outline" id="myls-price-select-all">All</button>
                    <button type="button" class="myls-btn myls-btn-outline" id="myls-price-clear-sel">Clear</button>
                  </div>

                  <!-- Title search -->
                  <input type="text" id="myls-price-search" placeholder="Filter titles..." style="margin-bottom:.4rem;">

                  <!-- Multi-select post list — disabled until a range is active -->
                  <select id="myls-price-posts" class="myls-select" multiple size="16" disabled>
                    <optgroup label="Pages">
                      <?php $render_page_options(0, 0); ?>
                    </optgroup>
                    <optgroup label="Posts">
                      <?php foreach ( $posts as $p ):
                        printf('<option data-ptype="post" value="%d">%s</option>',
                          (int)$p->ID, esc_html($p->post_title));
                      endforeach; ?>
                    </optgroup>
                    <?php if ( $has_service_cpt ): ?>
                      <optgroup label="Service">
                        <?php foreach ( $services as $p ):
                          printf('<option data-ptype="service" value="%d">%s</option>',
                            (int)$p->ID, esc_html($p->post_title));
                        endforeach; ?>
                      </optgroup>
                    <?php endif; ?>
                    <?php if ( $has_service_area_cpt ): ?>
                      <optgroup label="Service Area">
                        <?php foreach ( $service_areas as $p ):
                          printf('<option data-ptype="service_area" value="%d">%s</option>',
                            (int)$p->ID, esc_html($p->post_title));
                        endforeach; ?>
                      </optgroup>
                    <?php endif; ?>
                  </select>
                  <div class="form-text" style="margin-top:.4rem;">
                    Hold <strong>Ctrl/Cmd</strong> to multi-select. Assignments save automatically when you switch ranges or submit.
                  </div>
                </div>
              </div>

            </div><!-- /flex -->
          </div><!-- /myls-block price ranges -->
        </div><!-- /price ranges block -->

      </div><!-- /myls-svc-grid -->
    </div><!-- /myls-svc-wrap -->

    <script>
/* =========================================================================
 * PRICE RANGES REPEATER — AIntelligize Service Schema Tab
 * =========================================================================
 * State:
 *   priceRanges[]  — master array of {label, low, high, currency, postIds: Set}
 *   activeIdx      — index of the range whose posts are shown on the right
 *
 * Flow:
 *   1. Seed from PHP JSON (myls_price_ranges_json hidden input).
 *   2. Render repeater rows from priceRanges.
 *   3. Clicking "Assign Posts →" sets activeIdx, syncs right panel.
 *   4. Right panel post select reads/writes postIds of the active range.
 *   5. On form submit, serialize priceRanges to the hidden JSON input.
 * ========================================================================= */
(function(){
  'use strict';

  /* ---------- elements ---------- */
  const jsonInput   = document.getElementById('myls-price-ranges-json');
  const rangesList  = document.getElementById('myls-ranges-list');
  const addBtn      = document.getElementById('myls-add-range');
  const assignTitle = document.getElementById('myls-price-assign-title');
  const postSel     = document.getElementById('myls-price-posts');
  const priceSearch = document.getElementById('myls-price-search');
  const priceChips  = document.querySelectorAll('.myls-price-ptype');
  const selectAllBtn= document.getElementById('myls-price-select-all');
  const clearSelBtn = document.getElementById('myls-price-clear-sel');

  if ( !jsonInput || !rangesList || !postSel ) return;

  /* ---------- state ---------- */
  let priceRanges = [];  // [{label,low,high,currency,postIds:Set}, ...]
  let activeIdx   = -1;  // which range's posts are shown in right panel

  /* ---------- seed from PHP ---------- */
  try {
    const raw = JSON.parse( jsonInput.value || '[]' );
    priceRanges = (Array.isArray(raw) ? raw : []).map(r => ({
      label    : String(r.label    || ''),
      low      : String(r.low      || ''),
      high     : String(r.high     || ''),
      currency : String(r.currency || 'USD'),
      postIds  : new Set( (r.post_ids || []).map(Number) ),
    }));
  } catch(e) { priceRanges = []; }

  /* ---------- helpers ---------- */
  function norm(s){ return String(s||'').toLowerCase().trim(); }

  function serializeToJson(){
    // Write full state to hidden input so PHP receives it on form submit
    const arr = priceRanges.map(r => ({
      label    : r.label,
      low      : r.low,
      high     : r.high,
      currency : r.currency,
      post_ids : Array.from(r.postIds),
    }));
    jsonInput.value = JSON.stringify(arr);
  }

  /* ---------- right panel — post assignment ---------- */

  function applyFilters(){
    const allowed = new Set(
      Array.from(priceChips).filter(c => c.checked).map(c => c.value)
    );
    const q = norm(priceSearch ? priceSearch.value : '');
    for (const opt of postSel.querySelectorAll('option')) {
      const ptype = opt.getAttribute('data-ptype') || 'page';
      const text  = norm(opt.textContent);
      opt.hidden  = !allowed.has(ptype) || (q !== '' && text.indexOf(q) === -1);
    }
  }

  function syncRightPanelToRange(idx){
    if (idx < 0 || idx >= priceRanges.length) {
      // No active range — disable and reset panel
      postSel.disabled = true;
      assignTitle.textContent = '← Select a range to assign posts';
      for (const opt of postSel.querySelectorAll('option')) opt.selected = false;
      return;
    }
    const r = priceRanges[idx];
    postSel.disabled = false;
    const label = r.label || ('Range ' + (idx + 1));
    assignTitle.textContent = '📌 Assigning: ' + label;

    // Apply selections from this range's postIds Set
    for (const opt of postSel.querySelectorAll('option')) {
      opt.selected = r.postIds.has( Number(opt.value) );
    }
    applyFilters();
  }

  function captureRightPanelToRange(idx){
    // Write current UI selections back into priceRanges[idx].postIds
    if (idx < 0 || idx >= priceRanges.length) return;
    const ids = new Set();
    for (const opt of postSel.querySelectorAll('option')) {
      if (opt.selected) ids.add( Number(opt.value) );
    }
    priceRanges[idx].postIds = ids;
    serializeToJson();
    updateAssignBadge(idx);
  }

  function updateAssignBadge(idx){
    const row = rangesList.querySelector('[data-range-idx="' + idx + '"]');
    if (!row) return;
    const badge = row.querySelector('.myls-range-badge');
    if (badge) badge.textContent = priceRanges[idx].postIds.size + ' posts';
  }

  /* ---------- repeater render ---------- */

  function renderRow(r, idx){
    const isActive = (idx === activeIdx);
    const row = document.createElement('div');
    row.setAttribute('data-range-idx', idx);
    row.style.cssText = [
      'display:flex;gap:6px;align-items:center;flex-wrap:wrap;',
      'padding:8px;margin-bottom:6px;border-radius:1em;border:1px solid',
      isActive ? ' #0d6efd;background:#e8f0fe;' : ' #000;background:#fff;',
    ].join('');

    row.innerHTML = [
      '<input type="text" placeholder="Label (e.g. House Washing)"',
      '  style="flex:2 1 140px;min-width:100px;"',
      '  class="myls-range-label" value="' + esc(r.label) + '">',

      '<input type="text" placeholder="Low $" ',
      '  style="width:68px;flex:0 0 68px;" class="myls-range-low" value="' + esc(r.low) + '">',

      '<input type="text" placeholder="High $"',
      '  style="width:68px;flex:0 0 68px;" class="myls-range-high" value="' + esc(r.high) + '">',

      '<select class="myls-range-currency" style="width:68px;flex:0 0 68px;">',
        '<option value="USD"' + (r.currency==='USD'?' selected':'') + '>USD</option>',
        '<option value="EUR"' + (r.currency==='EUR'?' selected':'') + '>EUR</option>',
        '<option value="GBP"' + (r.currency==='GBP'?' selected':'') + '>GBP</option>',
        '<option value="CAD"' + (r.currency==='CAD'?' selected':'') + '>CAD</option>',
      '</select>',

      '<button type="button" class="myls-btn ' + (isActive ? 'myls-btn-primary' : 'myls-btn-outline') + ' myls-range-assign">',
        isActive ? '✅ Assigning' : 'Assign Posts →',
      '</button>',
      '<span class="myls-range-badge form-text" style="white-space:nowrap;">',
        r.postIds.size + ' posts',
      '</span>',

      '<button type="button" class="myls-btn myls-btn-outline myls-range-remove"',
      '  style="color:#dc3545;border-color:#dc3545;" title="Remove this range">🗑</button>',
    ].join('');

    /* -- field → state sync -- */
    row.querySelector('.myls-range-label').addEventListener('input', function(){
      priceRanges[idx].label = this.value;
      if (activeIdx === idx) assignTitle.textContent = '📌 Assigning: ' + (this.value || 'Range '+(idx+1));
      serializeToJson();
    });
    row.querySelector('.myls-range-low').addEventListener('input', function(){
      priceRanges[idx].low = this.value;
      serializeToJson();
    });
    row.querySelector('.myls-range-high').addEventListener('input', function(){
      priceRanges[idx].high = this.value;
      serializeToJson();
    });
    row.querySelector('.myls-range-currency').addEventListener('change', function(){
      priceRanges[idx].currency = this.value;
      serializeToJson();
    });

    /* -- Assign Posts button -- */
    row.querySelector('.myls-range-assign').addEventListener('click', function(){
      if (activeIdx === idx) {
        // Toggle off — deselect
        captureRightPanelToRange(activeIdx);
        activeIdx = -1;
      } else {
        // Save previous panel before switching
        if (activeIdx >= 0) captureRightPanelToRange(activeIdx);
        activeIdx = idx;
      }
      renderRepeater();
      syncRightPanelToRange(activeIdx);
    });

    /* -- Remove button -- */
    row.querySelector('.myls-range-remove').addEventListener('click', function(){
      if (!confirm('Remove this price range?')) return;
      if (activeIdx === idx) { activeIdx = -1; syncRightPanelToRange(-1); }
      else if (activeIdx > idx) activeIdx--;
      priceRanges.splice(idx, 1);
      renderRepeater();
      serializeToJson();
    });

    return row;
  }

  function esc(s){ return String(s||'').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

  function renderRepeater(){
    rangesList.innerHTML = '';
    if (priceRanges.length === 0) {
      rangesList.innerHTML = '<p class="form-text">No price ranges yet. Click <strong>+ Add Range</strong> to create one.</p>';
      return;
    }
    priceRanges.forEach((r, idx) => rangesList.appendChild(renderRow(r, idx)));
  }

  /* ---------- post list interactions ---------- */

  // Capture on every change of the select
  postSel.addEventListener('change', function(){
    if (activeIdx >= 0) captureRightPanelToRange(activeIdx);
  });

  priceChips.forEach(ch => ch.addEventListener('change', applyFilters));
  if (priceSearch) priceSearch.addEventListener('input', applyFilters);

  selectAllBtn && selectAllBtn.addEventListener('click', function(){
    if (activeIdx < 0) return;
    for (const opt of postSel.querySelectorAll('option')) {
      if (!opt.hidden) opt.selected = true;
    }
    captureRightPanelToRange(activeIdx);
  });

  clearSelBtn && clearSelBtn.addEventListener('click', function(){
    if (activeIdx < 0) return;
    for (const opt of postSel.querySelectorAll('option')) {
      if (!opt.hidden) opt.selected = false;
    }
    captureRightPanelToRange(activeIdx);
  });

  /* ---------- Add Range button ---------- */
  addBtn.addEventListener('click', function(){
    // Save active panel before adding
    if (activeIdx >= 0) captureRightPanelToRange(activeIdx);
    priceRanges.push({ label:'', low:'', high:'', currency:'USD', postIds: new Set() });
    activeIdx = priceRanges.length - 1; // auto-activate the new row
    renderRepeater();
    syncRightPanelToRange(activeIdx);
    serializeToJson();
    // Scroll new row into view
    const rows = rangesList.querySelectorAll('[data-range-idx]');
    if (rows.length) rows[rows.length-1].scrollIntoView({ behavior:'smooth', block:'nearest' });
  });

  /* ---------- Serialize before submit ---------- */
  // Capture final panel state when form submits
  const form = jsonInput.closest('form');
  if (form) {
    form.addEventListener('submit', function(){
      if (activeIdx >= 0) captureRightPanelToRange(activeIdx);
      serializeToJson();
    });
  }

  /* ---------- Init ---------- */
  renderRepeater();
  syncRightPanelToRange(activeIdx); // -1 → disabled panel

})();
</script>

    <script>
(function(){
  const sel        = document.getElementById('myls-service-pages');
  const chips      = document.querySelectorAll('.myls-ptype');
  const search     = document.getElementById('myls-service-search');
  const hiddenWrap = document.getElementById('myls-service-pages-hidden');

  if (!sel || !hiddenWrap) return;

  function norm(s){ return (s || '').toString().toLowerCase().trim(); }

  // Persisted = the assignments (truth)
  const persisted = new Set();

  // Map value -> option element (fast lookups)
  const optByVal = new Map();
  for (const opt of sel.querySelectorAll('option')) {
    const v = String(opt.value);
    optByVal.set(v, opt);
    if (opt.selected) persisted.add(v); // seed from PHP-selected
  }

  function syncHiddenInputs(){
    hiddenWrap.innerHTML = '';
    const vals = Array.from(persisted);
    vals.sort((a,b) => (parseInt(a,10)||0) - (parseInt(b,10)||0));

    for (const v of vals) {
      const input = document.createElement('input');
      input.type  = 'hidden';
      input.name  = 'myls_service_pages_persist[]';
      input.value = v;
      hiddenWrap.appendChild(input);
    }
  }

  // Apply UI selection state to match persisted
  // IMPORTANT: We avoid looping ALL options when possible.
  function applyPersistedToUI(){
    // 1) Unselect anything selected that is not persisted (selectedOptions is small)
    const selectedNow = Array.from(sel.selectedOptions || []);
    for (const o of selectedNow) {
      const v = String(o.value);
      if (!persisted.has(v)) o.selected = false;
    }

    // 2) Ensure everything in persisted is selected (persisted count is usually manageable)
    for (const v of persisted) {
      const o = optByVal.get(v);
      if (o) o.selected = true;
    }
  }

  // --- Filters (hide only; NEVER touch persisted)
  function applyTypeFilter(){
    const allowed = new Set(Array.from(chips).filter(c => c.checked).map(c => c.value));
    for (const opt of sel.querySelectorAll('option')) {
      const t = opt.getAttribute('data-ptype') || 'page';
      opt.hidden = !allowed.has(t);
    }
  }

  function applySearchFilter(){
    const q = norm(search?.value || '');

    for (const opt of sel.querySelectorAll('option')) {
      if (opt.hidden) continue; // already hidden by type filter
      if (q === '') { opt.hidden = false; continue; }

      const text = norm(opt.textContent || opt.innerText || '');
      opt.hidden = text.indexOf(q) === -1;
    }
  }

  function applyAllFilters(){
    applyTypeFilter();
    applySearchFilter();
  }

  chips.forEach(ch => ch.addEventListener('change', applyAllFilters));
  search?.addEventListener('input', applyAllFilters);

  // Track what the user actually clicked (so we can toggle only that item)
  let lastClickedValue = '';
  let lastScrollTop    = 0;

  sel.addEventListener('mousedown', function(e){
    const opt = (e.target && e.target.tagName === 'OPTION') ? e.target : null;
    if (!opt) return;

    // capture BEFORE the browser changes selection/scroll
    lastClickedValue = String(opt.value);
    lastScrollTop    = sel.scrollTop;
  });

  // Toggle logic on change:
  // - only the clicked item changes persisted
  // - then we re-apply persisted back into the UI (restoring “lost” selections)
  sel.addEventListener('change', function(){
    const v = String(lastClickedValue || sel.options[sel.selectedIndex]?.value || '');
    if (!v) return;

    // Toggle only the clicked value
    if (persisted.has(v)) persisted.delete(v);
    else persisted.add(v);

    // Re-apply persisted selection to UI (undo browser clearing)
    applyPersistedToUI();

    // Sync hidden inputs for submit
    syncHiddenInputs();

    // Restore scroll (browser may jump when it cleared selection)
    sel.scrollTop = lastScrollTop;

    // Reset click marker
    lastClickedValue = '';
  });

  // Select All (visible only): add visible items to persisted
  document.getElementById('myls-service-select-all')?.addEventListener('click', function(){
    const st = sel.scrollTop;

    for (const opt of sel.querySelectorAll('option')) {
      if (opt.hidden) continue;
      persisted.add(String(opt.value));
      opt.selected = true;
    }

    syncHiddenInputs();
    sel.scrollTop = st;
  });

  // Clear (visible only): remove visible items from persisted
  document.getElementById('myls-service-clear')?.addEventListener('click', function(){
    const st = sel.scrollTop;

    for (const opt of sel.querySelectorAll('option')) {
      if (opt.hidden) continue;
      persisted.delete(String(opt.value));
      opt.selected = false;
    }

    // Ensure hidden selections remain selected in UI
    applyPersistedToUI();

    syncHiddenInputs();
    sel.scrollTop = st;
  });

  // Init
  applyPersistedToUI();
  syncHiddenInputs();
  applyAllFilters();
})();
</script>

<!-- Service Grid Button Colors -->
<?php
    $sg_btn_bg    = get_option( 'myls_service_grid_btn_bg', '' );
    $sg_btn_color = get_option( 'myls_service_grid_btn_color', '' );
?>
<div class="myls-block" style="margin-top:1.5rem;">
  <div class="myls-block-title">Service Grid Button Colors</div>
  <p class="text-muted mb-3" style="font-size:.9rem;">
    Site-wide defaults for the <code>[service_grid]</code> button.
    Override per-shortcode with <code>btn_bg=""</code> and <code>btn_color=""</code>.
    Leave blank to use Bootstrap <code>.btn-primary</code>.
  </p>
  <div class="myls-row" style="gap:.75rem;">
    <div class="myls-col col-4">
      <label class="form-label fw-bold" for="myls_service_grid_btn_bg">Button Background</label>
      <div class="d-flex align-items-center gap-2">
        <input type="color" class="form-control form-control-color" id="myls_sg_btn_bg_picker"
               value="<?php echo esc_attr( $sg_btn_bg ?: '#0d6efd' ); ?>"
               style="width:48px;height:38px;padding:2px;cursor:pointer;">
        <input type="text" class="form-control form-control-sm font-monospace" id="myls_service_grid_btn_bg"
               name="myls_service_grid_btn_bg" value="<?php echo esc_attr( $sg_btn_bg ); ?>"
               placeholder="" maxlength="30" style="max-width:110px;">
      </div>
    </div>
    <div class="myls-col col-4">
      <label class="form-label fw-bold" for="myls_service_grid_btn_color">Button Text</label>
      <div class="d-flex align-items-center gap-2">
        <input type="color" class="form-control form-control-color" id="myls_sg_btn_color_picker"
               value="<?php echo esc_attr( $sg_btn_color ?: '#ffffff' ); ?>"
               style="width:48px;height:38px;padding:2px;cursor:pointer;">
        <input type="text" class="form-control form-control-sm font-monospace" id="myls_service_grid_btn_color"
               name="myls_service_grid_btn_color" value="<?php echo esc_attr( $sg_btn_color ); ?>"
               placeholder="" maxlength="30" style="max-width:110px;">
      </div>
    </div>
    <div class="myls-col col-4">
      <label class="form-label fw-bold">Preview</label>
      <div id="myls_sg_color_preview"
           style="background:<?php echo esc_attr( $sg_btn_bg ?: '#0d6efd' ); ?>;color:<?php echo esc_attr( $sg_btn_color ?: '#ffffff' ); ?>;padding:10px 16px;border-radius:6px;font-weight:bold;font-size:.9rem;cursor:default;user-select:none;">
        Learn More ›
      </div>
    </div>
  </div>
  <div class="myls-actions" style="margin-top:.75rem;">
    <button class="btn btn-primary" type="submit">Save Colors</button>
  </div>
</div>
<script>
(function(){
    function syncColor(pickerId, textId) {
        var picker = document.getElementById(pickerId);
        var text   = document.getElementById(textId);
        if (!picker || !text) return;
        picker.addEventListener('input', function() {
            text.value = this.value;
            updateSgPreview();
        });
        text.addEventListener('input', function() {
            var v = this.value.trim();
            if (/^#[0-9a-fA-F]{3,6}$/.test(v)) picker.value = v;
            updateSgPreview();
        });
    }
    function updateSgPreview() {
        var preview = document.getElementById('myls_sg_color_preview');
        var bg  = document.getElementById('myls_service_grid_btn_bg')?.value  || '#0d6efd';
        var col = document.getElementById('myls_service_grid_btn_color')?.value || '#ffffff';
        if (preview) {
            preview.style.background = bg;
            preview.style.color      = col;
        }
    }
    syncColor('myls_sg_btn_bg_picker',    'myls_service_grid_btn_bg');
    syncColor('myls_sg_btn_color_picker', 'myls_service_grid_btn_color');
})();
</script>

    <?php
  },

  'on_save'=> function () {
    if ( ! isset($_POST['myls_schema_nonce']) || ! wp_verify_nonce($_POST['myls_schema_nonce'], 'myls_schema_save') ) {
      return;
    }

    update_option('myls_service_enabled',       sanitize_text_field($_POST['myls_service_enabled'] ?? '0'));
    update_option('myls_service_default_type',  sanitize_text_field($_POST['myls_service_default_type'] ?? ''));
    update_option('myls_service_subtype',       sanitize_text_field($_POST['myls_service_subtype'] ?? ''));
    update_option('myls_service_output',        sanitize_text_field($_POST['myls_service_output'] ?? ''));

    // Save service grid button colors.
    $sg_btn_bg    = sanitize_hex_color( $_POST['myls_service_grid_btn_bg']    ?? '' );
    $sg_btn_color = sanitize_hex_color( $_POST['myls_service_grid_btn_color'] ?? '' );
    if ( $sg_btn_bg    !== null ) update_option( 'myls_service_grid_btn_bg',    $sg_btn_bg    ?: '' );
    if ( $sg_btn_color !== null ) update_option( 'myls_service_grid_btn_color', $sg_btn_color ?: '' );

    /**
     * Persisted selections:
     * - If JS ran, we'll receive myls_service_pages_persist[] (hidden inputs).
     * - If JS did not run (very rare), fall back to myls_service_pages[] if present.
     */
    $ids = [];

    if ( isset($_POST['myls_service_pages_persist']) && is_array($_POST['myls_service_pages_persist']) ) {
      $ids = array_map('absint', $_POST['myls_service_pages_persist']);
    } elseif ( isset($_POST['myls_service_pages']) && is_array($_POST['myls_service_pages']) ) {
      $ids = array_map('absint', $_POST['myls_service_pages']);
    }

    // Clean + store service page assignments
    $ids = array_values(array_filter(array_unique($ids)));
    update_option('myls_service_pages', $ids);

    // ── Price Ranges ──────────────────────────────────────────────────────
    // JS serialises the full repeater state (label, low, high, currency, post_ids[])
    // to a single JSON string in the hidden input myls_price_ranges_json.
    $raw_json = stripslashes( $_POST['myls_price_ranges_json'] ?? '[]' );
    $decoded  = json_decode( $raw_json, true );

    if ( is_array( $decoded ) ) {
      $clean_ranges = [];
      foreach ( $decoded as $r ) {
        if ( ! is_array($r) ) continue;

        $label    = sanitize_text_field( $r['label']    ?? '' );
        $low      = preg_replace( '/[^0-9.]/', '', (string) ( $r['low']  ?? '' ) );
        $high     = preg_replace( '/[^0-9.]/', '', (string) ( $r['high'] ?? '' ) );
        $currency = strtoupper( sanitize_text_field( $r['currency'] ?? 'USD' ) );
        $post_ids = array_values( array_filter( array_map( 'absint', (array) ( $r['post_ids'] ?? [] ) ) ) );

        // Allow ranges that have at least a label OR a price value
        if ( $label !== '' || $low !== '' || $high !== '' ) {
          $clean_ranges[] = compact( 'label', 'low', 'high', 'currency', 'post_ids' );
        }
      }
      update_option( 'myls_service_price_ranges', $clean_ranges );
    }
  }
];

if ( defined('MYLS_SCHEMA_DISCOVERY') && MYLS_SCHEMA_DISCOVERY ) return $spec;
return null;
