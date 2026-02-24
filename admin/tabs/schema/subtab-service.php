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

    $enabled      = get_option('myls_service_enabled','0');
    $default_type = get_option('myls_service_default_type','');
    $subtype      = get_option('myls_service_subtype','');

    // Detect optional CPTs
    $has_service_cpt       = post_type_exists('service');
    $has_service_area_cpt  = post_type_exists('service_area');

    // Selected IDs (saved)
    $selected_ids = array_values(array_unique(array_map('absint', (array) get_option('myls_service_pages', []))));

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

    <form method="post" class="myls-svc-wrap">
      <?php wp_nonce_field('myls_schema_save','myls_schema_nonce'); ?>

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
      </div>
    </form>

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




    <?php
  },

  'on_save'=> function () {
    if ( ! isset($_POST['myls_schema_nonce']) || ! wp_verify_nonce($_POST['myls_schema_nonce'], 'myls_schema_save') ) {
      return;
    }

    update_option('myls_service_enabled',       sanitize_text_field($_POST['myls_service_enabled'] ?? '0'));
    update_option('myls_service_default_type',  sanitize_text_field($_POST['myls_service_default_type'] ?? ''));
    update_option('myls_service_subtype',       sanitize_text_field($_POST['myls_service_subtype'] ?? ''));

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

    // Clean + store
    $ids = array_values(array_filter(array_unique($ids)));
    update_option('myls_service_pages', $ids);
  }
];

if ( defined('MYLS_SCHEMA_DISCOVERY') && MYLS_SCHEMA_DISCOVERY ) return $spec;
return null;
