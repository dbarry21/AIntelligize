<?php
/**
 * Subtab: Person / People
 * Path: admin/tabs/schema/subtab-person.php
 *
 * Multi-person support with per-person page assignment.
 * Stored as: myls_person_profiles => [ 0 => [...], 1 => [...], ... ]
 *
 * Each profile:
 *  - label (display-only, not in schema output)
 *  - name, job_title, description, url, image_url, email, phone
 *  - honorific_prefix, gender
 *  - same_as => [ url, url, ... ]
 *  - knows_about => [ ['name'=>'', 'wikidata'=>'', 'wikipedia'=>''], ... ]
 *  - credentials => [ ['name'=>'', 'abbr'=>'', 'issuer'=>'', 'issuer_url'=>''], ... ]
 *  - alumni => [ ['name'=>'', 'url'=>''], ... ]
 *  - member_of => [ ['name'=>'', 'url'=>''], ... ]
 *  - awards => [ 'text', ... ]
 *  - languages => [ 'text', ... ]
 *  - works_for_override => '' (blank = use org)
 *  - pages => [ post_id, ... ]
 *  - enabled => '1'|'0'
 *
 * @since 4.12.0
 * @updated 4.13.0 — Added label field, PDF export button, extracted PDF JS.
 */

if (!defined('ABSPATH')) exit;

/* ====================================================================
 *  Helper: default empty profile
 * ==================================================================== */
if (!function_exists('myls_person_default_profile')) {
  function myls_person_default_profile(): array {
    return [
      'enabled'          => '1',
      'label'            => '',
      'name'             => '',
      'job_title'        => '',
      'honorific_prefix' => '',
      'description'      => '',
      'url'              => '',
      'email'            => '',
      'phone'            => '',
      'image_id'         => 0,
      'image_url'        => '',
      'same_as'          => [],
      'knows_about'      => [],
      'credentials'      => [],
      'alumni'           => [],
      'member_of'        => [],
      'awards'           => [],
      'languages'        => [],
      'pages'            => [],
    ];
  }
}

/* ====================================================================
 *  Spec: render + on_save
 * ==================================================================== */
$spec = [
  'id'    => 'person',
  'label' => 'Person',
  'order' => 15,

  /* ------------------------------------------------------------------
   *  RENDER
   * ---------------------------------------------------------------- */
  'render' => function () {

    // Enqueue media for image picker
    if (function_exists('wp_enqueue_media')) wp_enqueue_media();

    // Load saved profiles
    $profiles = get_option('myls_person_profiles', []);
    if (!is_array($profiles) || empty($profiles)) {
      $profiles = [ myls_person_default_profile() ];
    }

    // Ensure every profile has all keys
    foreach ($profiles as $i => $p) {
      $profiles[$i] = wp_parse_args($p, myls_person_default_profile());
    }

    // Assignable pages
    $assignable = get_posts([
      'post_type'   => ['page','post','service','service_area'],
      'post_status' => 'publish',
      'numberposts' => -1,
      'orderby'     => 'title',
      'order'       => 'asc',
    ]);

    // Org name for "worksFor" display
    $org_name = get_option('myls_org_name', get_bloginfo('name'));

    ?>
    <style>
      /* ── Person subtab scoped styles ── */
      .myls-person-wrap { width:100%; }

      /* Accordion */
      .myls-person-accordion { display:flex; flex-direction:column; gap:10px; }
      .myls-person-card { border:1px solid #000; border-radius:1em; overflow:hidden; background:#fff; }
      .myls-person-card.is-collapsed .myls-person-body { display:none; }

      .myls-person-header {
        display:flex; align-items:center; gap:10px; padding:12px 16px;
        background:#f8f9fa; cursor:pointer; user-select:none;
        border-bottom:1px solid #e5e5e5;
      }
      .myls-person-header:hover { background:#f0f0f0; }
      .myls-person-header .person-avatar {
        width:36px; height:36px; border-radius:50%; object-fit:cover;
        background:#e9ecef; flex-shrink:0;
      }
      .myls-person-header .person-avatar-placeholder {
        width:36px; height:36px; border-radius:50%; background:#e9ecef;
        display:flex; align-items:center; justify-content:center;
        font-size:16px; color:#adb5bd; flex-shrink:0;
      }
      .myls-person-header .person-info  { flex:1; min-width:0; }
      .myls-person-header .person-name  { font-weight:700; font-size:15px; }
      .myls-person-header .person-meta  { font-size:12px; color:#6c757d; }
      .myls-person-header .toggle-icon  { font-size:18px; color:#6c757d; transition:transform .2s; }
      .myls-person-card:not(.is-collapsed) .toggle-icon { transform:rotate(180deg); }
      .myls-person-header .person-badge {
        font-size:11px; padding:2px 8px; border-radius:10px; font-weight:600;
      }
      .myls-person-header .badge-enabled  { background:#d1fae5; color:#065f46; }
      .myls-person-header .badge-disabled { background:#fee2e2; color:#991b1b; }

      .myls-person-body { padding:16px; }

      /* Grid layout inside each person */
      .myls-person-grid     { display:flex; flex-wrap:wrap; gap:16px; }
      .myls-person-col-main { flex:2 1 400px; min-width:300px; }
      .myls-person-col-side { flex:1 1 280px; min-width:260px; }

      /* Field groups */
      .myls-fieldgroup {
        border:1px solid #e5e5e5; border-radius:.75em; padding:14px;
        margin-bottom:14px; background:#fafafa;
      }
      .myls-fieldgroup-title {
        font-weight:700; font-size:14px; margin:0 0 10px;
        display:flex; align-items:center; gap:6px;
      }

      /* Inputs */
      .myls-person-wrap input[type="text"],
      .myls-person-wrap input[type="email"],
      .myls-person-wrap input[type="url"],
      .myls-person-wrap input[type="tel"],
      .myls-person-wrap textarea,
      .myls-person-wrap select {
        border:1px solid #ced4da !important; border-radius:.5em !important;
        padding:.45rem .65rem; width:100%; font-size:14px;
      }
      .myls-person-wrap textarea  { min-height:70px; }
      .myls-person-wrap .form-label { font-weight:600; margin-bottom:4px; display:block; font-size:13px; }
      .myls-person-wrap .form-hint  { font-size:12px; color:#6c757d; margin-top:2px; }
      .myls-field-row   { margin-bottom:10px; }
      .myls-field-half  { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
      .myls-field-third { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; }

      /* Repeater rows */
      .myls-repeater-row {
        display:flex; gap:6px; align-items:center; margin-bottom:6px;
      }
      .myls-repeater-row input { flex:1; }
      .myls-repeater-row .myls-btn-xs {
        flex-shrink:0; width:28px; height:28px; border-radius:50%;
        border:1px solid #ced4da; background:#fff; cursor:pointer;
        font-size:14px; display:flex; align-items:center; justify-content:center;
        color:#dc3545;
      }
      .myls-repeater-row .myls-btn-xs:hover { background:#fee2e2; }

      /* Composite repeater (multi-field per row) */
      .myls-composite-row {
        display:grid; gap:6px; margin-bottom:8px; padding:8px; background:#fff;
        border:1px solid #e9ecef; border-radius:.5em; position:relative;
      }
      .myls-composite-row.cols-2 { grid-template-columns:1fr 1fr; }
      .myls-composite-row.cols-3 { grid-template-columns:1fr 1fr 1fr; }
      .myls-composite-row.cols-4 { grid-template-columns:1fr 1fr 1fr 1fr; }
      .myls-composite-row .row-remove {
        position:absolute; top:4px; right:4px; width:20px; height:20px;
        border-radius:50%; border:none; background:#fee2e2; color:#dc3545;
        font-size:12px; cursor:pointer; display:flex; align-items:center;
        justify-content:center; line-height:1;
      }

      /* Page assignment */
      .myls-page-list {
        max-height:200px; overflow-y:auto; border:1px solid #e5e5e5;
        border-radius:.5em; padding:6px; background:#fff;
      }
      .myls-page-list label {
        display:flex; align-items:center; gap:6px; padding:3px 4px;
        font-size:13px; cursor:pointer;
      }
      .myls-page-list label:hover { background:#f0f4ff; border-radius:4px; }

      /* Image preview */
      .myls-img-preview     { display:flex; align-items:center; gap:10px; margin-top:6px; }
      .myls-img-preview img { width:60px; height:60px; object-fit:cover; border-radius:8px; border:1px solid #dee2e6; }

      /* Action buttons */
      .myls-person-actions {
        display:flex; gap:8px; flex-wrap:wrap; margin-top:12px;
        padding-top:12px; border-top:1px solid #e5e5e5;
      }
      .myls-btn-sm {
        display:inline-flex; align-items:center; gap:4px;
        font-weight:600; font-size:13px; border:1px solid #ced4da;
        padding:6px 12px; border-radius:.5em; background:#fff;
        color:#212529; cursor:pointer;
      }
      .myls-btn-sm:hover       { background:#f0f0f0; }
      .myls-btn-add            { background:#d1fae5; border-color:#a7f3d0; color:#065f46; }
      .myls-btn-add:hover      { background:#a7f3d0; }
      .myls-btn-danger         { background:#fee2e2; border-color:#fecaca; color:#991b1b; }
      .myls-btn-danger:hover   { background:#fecaca; }
      .myls-btn-export-pdf     { background:#eef2ff; border-color:#c7d2fe; color:#4338ca; transition:all .2s ease; }
      .myls-btn-export-pdf:hover { background:#c7d2fe; }

      /* Info callout */
      .myls-info-box {
        background:#eff6ff; border:1px solid #bfdbfe; border-radius:.75em;
        padding:12px 14px; font-size:13px; color:#1e40af; line-height:1.5;
      }
      .myls-info-box strong { display:block; margin-bottom:2px; }

      @media (max-width: 700px) {
        .myls-field-half, .myls-field-third { grid-template-columns:1fr; }
        .myls-composite-row.cols-3,
        .myls-composite-row.cols-4 { grid-template-columns:1fr; }
        .myls-person-grid { flex-direction:column; }
      }
    </style>

    <div class="myls-person-wrap">

      <!-- Top info -->
      <div class="myls-info-box" style="margin-bottom:16px;">
        <strong><i class="bi bi-person-badge"></i> Person Schema — E-E-A-T &amp; AI Visibility</strong>
        Add owners, founders, or key team members. Each person gets their own schema markup on assigned pages.
        Connect expertise to Wikidata/Wikipedia for maximum AI citation potential.
        worksFor automatically links to your <strong><?php echo esc_html($org_name); ?></strong> Organization schema.
      </div>

      <!-- LinkedIn Import -->
      <div id="myls-linkedin-import" style="margin-bottom:16px;padding:14px;background:#fafafa;border:1px solid #e5e5e5;border-radius:.75em;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
          <label class="form-label" style="font-weight:700;font-size:13px;margin:0;"><i class="bi bi-linkedin" style="color:#0a66c2;"></i> Import Person from LinkedIn</label>
          <label style="font-size:12px;color:#6c757d;cursor:pointer;display:flex;align-items:center;gap:4px;">
            <input type="checkbox" id="myls-linkedin-html-mode" onchange="mylsLinkedInToggleMode(this)" />
            Advanced: Paste HTML source
          </label>
        </div>
        <div id="myls-linkedin-text-hint" class="form-hint" style="margin-bottom:8px;">
          <strong>How to use:</strong> Open the LinkedIn profile in your browser → Click anywhere on the page → <kbd>Ctrl+A</kbd> (select all) → <kbd>Ctrl+C</kbd> (copy) → Click the box below and <kbd>Ctrl+V</kbd> (paste).
        </div>
        <div id="myls-linkedin-html-hint" class="form-hint" style="margin-bottom:8px;display:none;">
          <strong>Advanced:</strong> Open the LinkedIn profile → Right-click → View Page Source → <kbd>Ctrl+A</kbd> → <kbd>Ctrl+C</kbd> → Paste below. HTML contains more structured data for better extraction.
        </div>
        <textarea id="myls-linkedin-content" rows="5" placeholder="Paste LinkedIn profile content here…" style="width:100%;font-size:13px;"></textarea>
        <div style="display:flex;align-items:center;gap:10px;margin-top:10px;">
          <input type="url" id="myls-linkedin-url" placeholder="LinkedIn URL (optional — for sameAs link)" style="flex:1;" />
          <select id="myls-linkedin-target" style="padding:7px 10px;font-size:13px;border:1px solid #ced4da;border-radius:.5em;background:#fff;">
            <?php foreach ($profiles as $tidx => $tp): ?>
            <option value="<?php echo $tidx; ?>"><?php echo esc_html($tp['label'] ?: ($tp['name'] ?: 'Person #' . ($tidx + 1))); ?></option>
            <?php endforeach; ?>
          </select>
          <button type="button" id="myls-linkedin-import-btn" class="myls-btn-sm" style="background:#0a66c2;border-color:#0a66c2;color:#fff;padding:8px 16px;flex-shrink:0;">
            <i class="bi bi-cloud-download"></i> Import with AI
          </button>
        </div>
        <span class="form-hint" style="margin-top:4px;display:block;">Target person card to populate. AI will extract name, title, bio, education, credentials, expertise and more.</span>
      </div>
      <?php // Nonce for AI AJAX ?>
      <input type="hidden" id="myls-ai-nonce" value="<?php echo wp_create_nonce('myls_ai_ops'); ?>" />

      <!-- ═══════════════════════════════════════════
           Accordion of people
           ═══════════════════════════════════════════ -->
      <div class="myls-person-accordion" id="myls-person-list">
        <?php foreach ($profiles as $idx => $p) :
          $label_display = $p['label'] ?: 'Person #' . ($idx + 1);
          $name_display  = $p['name']  ?: 'No name set';
          $job_display   = $p['job_title'] ?: 'No title set';
          $is_enabled    = ($p['enabled'] ?? '1') === '1';
          $img_url       = '';
          if (!empty($p['image_id'])) {
            $img_url = wp_get_attachment_image_url((int)$p['image_id'], 'thumbnail');
          }
          if (!$img_url && !empty($p['image_url'])) {
            $img_url = $p['image_url'];
          }
          $page_ids  = array_map('absint', (array)($p['pages'] ?? []));
          $collapsed = $idx > 0 ? ' is-collapsed' : '';
        ?>
        <div class="myls-person-card<?php echo $collapsed; ?>" data-person-idx="<?php echo $idx; ?>">

          <!-- ── Accordion header ── -->
          <div class="myls-person-header" onclick="this.parentElement.classList.toggle('is-collapsed')">
            <?php if ($img_url): ?>
              <img class="person-avatar" src="<?php echo esc_url($img_url); ?>" alt="" />
            <?php else: ?>
              <span class="person-avatar-placeholder"><i class="bi bi-person"></i></span>
            <?php endif; ?>
            <div class="person-info">
              <span class="person-name"><?php echo esc_html($label_display); ?></span>
              <span class="person-meta"><?php echo esc_html($name_display); ?> · <?php echo esc_html($job_display); ?> · <?php echo count($page_ids); ?> page(s)</span>
            </div>
            <span class="person-badge <?php echo $is_enabled ? 'badge-enabled' : 'badge-disabled'; ?>">
              <?php echo $is_enabled ? 'Active' : 'Disabled'; ?>
            </span>
            <span class="toggle-icon"><i class="bi bi-chevron-down"></i></span>
          </div>

          <!-- ── Accordion body ── -->
          <div class="myls-person-body">

            <!-- Person Label -->
            <div style="margin-bottom:14px;">
              <label class="form-label" style="font-weight:700;font-size:13px;">Person Label</label>
              <input type="text"
                     class="person-label-input"
                     name="myls_person[<?php echo $idx; ?>][label]"
                     value="<?php echo esc_attr($p['label'] ?? ''); ?>"
                     placeholder="Person #<?php echo $idx + 1; ?>"
                     oninput="mylsPersonUpdateHeaderLabel(this)"
                     style="font-weight:600;" />
              <span class="form-hint">A friendly label for this profile (e.g. "Owner", "Dr. Smith"). Displayed in header only — not in schema output.</span>
            </div>

            <!-- Export to PDF -->
            <div style="margin-bottom:14px;">
              <button type="button" class="myls-btn-sm myls-btn-export-pdf" onclick="mylsPersonExportPDF(this)">
                <i class="bi bi-file-earmark-pdf"></i> Export Person Profile to PDF
              </button>
            </div>

            <div class="myls-person-grid">

              <!-- ═══ LEFT COLUMN ═══ -->
              <div class="myls-person-col-main">

                <!-- Identity -->
                <div class="myls-fieldgroup">
                  <div class="myls-fieldgroup-title"><i class="bi bi-person-fill"></i> Identity</div>

                  <div style="margin-bottom:10px;">
                    <label style="display:flex;align-items:center;gap:6px;font-weight:600;font-size:14px;cursor:pointer;">
                      <input type="checkbox" name="myls_person[<?php echo $idx; ?>][enabled]" value="1" <?php checked($p['enabled'] ?? '1', '1'); ?> />
                      Enable Person Schema for this profile
                    </label>
                  </div>

                  <div class="myls-field-half">
                    <div class="myls-field-row">
                      <label class="form-label">Full Name <span style="color:#dc3545;">*</span></label>
                      <input type="text" name="myls_person[<?php echo $idx; ?>][name]" value="<?php echo esc_attr($p['name']); ?>" placeholder="Jane Smith" />
                    </div>
                    <div class="myls-field-row">
                      <label class="form-label">Job Title</label>
                      <input type="text" name="myls_person[<?php echo $idx; ?>][job_title]" value="<?php echo esc_attr($p['job_title']); ?>" placeholder="Owner &amp; Founder" />
                    </div>
                  </div>

                  <div class="myls-field-half">
                    <div class="myls-field-row">
                      <label class="form-label">Honorific Prefix</label>
                      <input type="text" name="myls_person[<?php echo $idx; ?>][honorific_prefix]" value="<?php echo esc_attr($p['honorific_prefix'] ?? ''); ?>" placeholder="Dr., Rev., etc." />
                    </div>
                    <div class="myls-field-row">
                      <label class="form-label">Profile / About URL</label>
                      <input type="url" name="myls_person[<?php echo $idx; ?>][url]" value="<?php echo esc_attr($p['url']); ?>" placeholder="https://yoursite.com/about" />
                    </div>
                  </div>

                  <div class="myls-field-row">
                    <label class="form-label">Bio / Description</label>
                    <textarea name="myls_person[<?php echo $idx; ?>][description]" placeholder="Brief professional bio (1-3 sentences recommended)"><?php echo esc_textarea($p['description']); ?></textarea>
                  </div>

                  <div class="myls-field-half">
                    <div class="myls-field-row">
                      <label class="form-label">Email</label>
                      <input type="email" name="myls_person[<?php echo $idx; ?>][email]" value="<?php echo esc_attr($p['email'] ?? ''); ?>" placeholder="jane@example.com" />
                    </div>
                    <div class="myls-field-row">
                      <label class="form-label">Phone</label>
                      <input type="tel" name="myls_person[<?php echo $idx; ?>][phone]" value="<?php echo esc_attr($p['phone'] ?? ''); ?>" placeholder="+1-555-123-4567" />
                    </div>
                  </div>

                  <!-- Image picker -->
                  <div class="myls-field-row">
                    <label class="form-label">Photo / Headshot</label>
                    <input type="hidden" class="person-image-id" name="myls_person[<?php echo $idx; ?>][image_id]" value="<?php echo esc_attr($p['image_id'] ?? ''); ?>" />
                    <input type="url" class="person-image-url" name="myls_person[<?php echo $idx; ?>][image_url]" value="<?php echo esc_attr($p['image_url'] ?? ''); ?>" placeholder="Or paste image URL directly" />
                    <div class="myls-img-preview">
                      <?php if ($img_url): ?>
                        <img src="<?php echo esc_url($img_url); ?>" alt="" />
                      <?php endif; ?>
                      <button type="button" class="myls-btn-sm" onclick="mylsPersonPickImage(this, <?php echo $idx; ?>)"><i class="bi bi-image"></i> Choose Image</button>
                    </div>
                  </div>
                </div>

                <!-- sameAs (Social / Profiles) -->
                <div class="myls-fieldgroup">
                  <div class="myls-fieldgroup-title"><i class="bi bi-link-45deg"></i> Social Profiles &amp; sameAs</div>
                  <div class="form-hint" style="margin-bottom:8px;">LinkedIn, Facebook, X/Twitter, YouTube, Wikipedia, Wikidata, Crunchbase, etc.</div>
                  <div class="myls-repeater" data-field="same_as" data-idx="<?php echo $idx; ?>">
                    <?php
                    $same_as = (array)($p['same_as'] ?? ['']);
                    if (empty($same_as)) $same_as = [''];
                    foreach ($same_as as $si => $sa_url): ?>
                    <div class="myls-repeater-row">
                      <input type="url" name="myls_person[<?php echo $idx; ?>][same_as][]" value="<?php echo esc_attr($sa_url); ?>" placeholder="https://linkedin.com/in/..." />
                      <button type="button" class="myls-btn-xs" onclick="this.parentElement.remove()" title="Remove">×</button>
                    </div>
                    <?php endforeach; ?>
                  </div>
                  <button type="button" class="myls-btn-sm myls-btn-add" onclick="mylsPersonAddRepeater(this, 'same_as')">
                    <i class="bi bi-plus-circle"></i> Add Profile
                  </button>
                </div>

                <!-- knowsAbout -->
                <div class="myls-fieldgroup">
                  <div class="myls-fieldgroup-title"><i class="bi bi-lightbulb"></i> Areas of Expertise (knowsAbout)</div>
                  <div class="form-hint" style="margin-bottom:8px;">Link topics to Wikidata &amp; Wikipedia for best AI recognition. <a href="https://www.wikidata.org/" target="_blank" rel="noopener">Search Wikidata →</a></div>
                  <div class="myls-composite-repeater" data-field="knows_about" data-idx="<?php echo $idx; ?>">
                    <?php
                    $knows = (array)($p['knows_about'] ?? []);
                    if (empty($knows)) $knows = [['name'=>'','wikidata'=>'','wikipedia'=>'']];
                    foreach ($knows as $ki => $k): ?>
                    <div class="myls-composite-row cols-3">
                      <div>
                        <label class="form-label">Topic Name</label>
                        <input type="text" name="myls_person[<?php echo $idx; ?>][knows_about][<?php echo $ki; ?>][name]" value="<?php echo esc_attr($k['name'] ?? ''); ?>" placeholder="e.g. Plumbing" />
                      </div>
                      <div>
                        <label class="form-label">Wikidata URL</label>
                        <input type="url" name="myls_person[<?php echo $idx; ?>][knows_about][<?php echo $ki; ?>][wikidata]" value="<?php echo esc_attr($k['wikidata'] ?? ''); ?>" placeholder="https://www.wikidata.org/wiki/Q..." />
                      </div>
                      <div>
                        <label class="form-label">Wikipedia URL</label>
                        <input type="url" name="myls_person[<?php echo $idx; ?>][knows_about][<?php echo $ki; ?>][wikipedia]" value="<?php echo esc_attr($k['wikipedia'] ?? ''); ?>" placeholder="https://en.wikipedia.org/wiki/..." />
                      </div>
                      <button type="button" class="row-remove" onclick="this.parentElement.remove()" title="Remove">×</button>
                    </div>
                    <?php endforeach; ?>
                  </div>
                  <button type="button" class="myls-btn-sm myls-btn-add" onclick="mylsPersonAddComposite(this, 'knows_about', ['name','wikidata','wikipedia'], ['Topic Name','Wikidata URL','Wikipedia URL'], ['e.g. HVAC','https://www.wikidata.org/wiki/Q...','https://en.wikipedia.org/wiki/...'])">
                    <i class="bi bi-plus-circle"></i> Add Topic
                  </button>
                </div>

                <!-- hasCredential -->
                <div class="myls-fieldgroup">
                  <div class="myls-fieldgroup-title"><i class="bi bi-award"></i> Credentials &amp; Licenses</div>
                  <div class="form-hint" style="margin-bottom:8px;">Professional licenses, certifications (CPA, CFP, state contractor license, etc.)</div>
                  <div class="myls-composite-repeater" data-field="credentials" data-idx="<?php echo $idx; ?>">
                    <?php
                    $creds = (array)($p['credentials'] ?? []);
                    if (empty($creds)) $creds = [['name'=>'','abbr'=>'','issuer'=>'','issuer_url'=>'']];
                    foreach ($creds as $ci => $c): ?>
                    <div class="myls-composite-row cols-4">
                      <div>
                        <label class="form-label">Credential Name</label>
                        <input type="text" name="myls_person[<?php echo $idx; ?>][credentials][<?php echo $ci; ?>][name]" value="<?php echo esc_attr($c['name'] ?? ''); ?>" placeholder="Certified Financial Planner" />
                      </div>
                      <div>
                        <label class="form-label">Abbreviation</label>
                        <input type="text" name="myls_person[<?php echo $idx; ?>][credentials][<?php echo $ci; ?>][abbr]" value="<?php echo esc_attr($c['abbr'] ?? ''); ?>" placeholder="CFP" />
                      </div>
                      <div>
                        <label class="form-label">Issuing Organization</label>
                        <input type="text" name="myls_person[<?php echo $idx; ?>][credentials][<?php echo $ci; ?>][issuer]" value="<?php echo esc_attr($c['issuer'] ?? ''); ?>" placeholder="CFP Board" />
                      </div>
                      <div>
                        <label class="form-label">Issuer URL</label>
                        <input type="url" name="myls_person[<?php echo $idx; ?>][credentials][<?php echo $ci; ?>][issuer_url]" value="<?php echo esc_attr($c['issuer_url'] ?? ''); ?>" placeholder="https://www.cfp.net/" />
                      </div>
                      <button type="button" class="row-remove" onclick="this.parentElement.remove()" title="Remove">×</button>
                    </div>
                    <?php endforeach; ?>
                  </div>
                  <button type="button" class="myls-btn-sm myls-btn-add" onclick="mylsPersonAddComposite(this, 'credentials', ['name','abbr','issuer','issuer_url'], ['Credential Name','Abbreviation','Issuing Org','Issuer URL'], ['Licensed Plumber','LP','State Board','https://...'])">
                    <i class="bi bi-plus-circle"></i> Add Credential
                  </button>
                </div>

                <!-- alumniOf -->
                <div class="myls-fieldgroup">
                  <div class="myls-fieldgroup-title"><i class="bi bi-mortarboard"></i> Education (alumniOf)</div>
                  <div class="myls-composite-repeater" data-field="alumni" data-idx="<?php echo $idx; ?>">
                    <?php
                    $alumni = (array)($p['alumni'] ?? []);
                    if (empty($alumni)) $alumni = [['name'=>'','url'=>'']];
                    foreach ($alumni as $ai => $a): ?>
                    <div class="myls-composite-row cols-2">
                      <div>
                        <label class="form-label">Institution Name</label>
                        <input type="text" name="myls_person[<?php echo $idx; ?>][alumni][<?php echo $ai; ?>][name]" value="<?php echo esc_attr($a['name'] ?? ''); ?>" placeholder="University of Florida" />
                      </div>
                      <div>
                        <label class="form-label">Institution URL</label>
                        <input type="url" name="myls_person[<?php echo $idx; ?>][alumni][<?php echo $ai; ?>][url]" value="<?php echo esc_attr($a['url'] ?? ''); ?>" placeholder="https://www.ufl.edu/" />
                      </div>
                      <button type="button" class="row-remove" onclick="this.parentElement.remove()" title="Remove">×</button>
                    </div>
                    <?php endforeach; ?>
                  </div>
                  <button type="button" class="myls-btn-sm myls-btn-add" onclick="mylsPersonAddComposite(this, 'alumni', ['name','url'], ['Institution Name','Institution URL'], ['MIT','https://www.mit.edu/'])">
                    <i class="bi bi-plus-circle"></i> Add School
                  </button>
                </div>

                <!-- memberOf -->
                <div class="myls-fieldgroup">
                  <div class="myls-fieldgroup-title"><i class="bi bi-people"></i> Memberships (memberOf)</div>
                  <div class="form-hint" style="margin-bottom:8px;">Trade associations, BBB, Chamber of Commerce, etc.</div>
                  <div class="myls-composite-repeater" data-field="member_of" data-idx="<?php echo $idx; ?>">
                    <?php
                    $members = (array)($p['member_of'] ?? []);
                    if (empty($members)) $members = [['name'=>'','url'=>'']];
                    foreach ($members as $mi => $m): ?>
                    <div class="myls-composite-row cols-2">
                      <div>
                        <label class="form-label">Organization Name</label>
                        <input type="text" name="myls_person[<?php echo $idx; ?>][member_of][<?php echo $mi; ?>][name]" value="<?php echo esc_attr($m['name'] ?? ''); ?>" placeholder="Better Business Bureau" />
                      </div>
                      <div>
                        <label class="form-label">Organization URL</label>
                        <input type="url" name="myls_person[<?php echo $idx; ?>][member_of][<?php echo $mi; ?>][url]" value="<?php echo esc_attr($m['url'] ?? ''); ?>" placeholder="https://www.bbb.org/" />
                      </div>
                      <button type="button" class="row-remove" onclick="this.parentElement.remove()" title="Remove">×</button>
                    </div>
                    <?php endforeach; ?>
                  </div>
                  <button type="button" class="myls-btn-sm myls-btn-add" onclick="mylsPersonAddComposite(this, 'member_of', ['name','url'], ['Org Name','Org URL'], ['Chamber of Commerce','https://...'])">
                    <i class="bi bi-plus-circle"></i> Add Membership
                  </button>
                </div>

                <!-- Awards + Languages (side by side) -->
                <div class="myls-field-half">
                  <div class="myls-fieldgroup">
                    <div class="myls-fieldgroup-title"><i class="bi bi-trophy"></i> Awards</div>
                    <div class="myls-repeater" data-field="awards" data-idx="<?php echo $idx; ?>">
                      <?php
                      $awards = (array)($p['awards'] ?? ['']);
                      if (empty($awards)) $awards = [''];
                      foreach ($awards as $aw): ?>
                      <div class="myls-repeater-row">
                        <input type="text" name="myls_person[<?php echo $idx; ?>][awards][]" value="<?php echo esc_attr($aw); ?>" placeholder="Best of 2024" />
                        <button type="button" class="myls-btn-xs" onclick="this.parentElement.remove()">×</button>
                      </div>
                      <?php endforeach; ?>
                    </div>
                    <button type="button" class="myls-btn-sm myls-btn-add" onclick="mylsPersonAddRepeater(this, 'awards')"><i class="bi bi-plus-circle"></i> Add</button>
                  </div>
                  <div class="myls-fieldgroup">
                    <div class="myls-fieldgroup-title"><i class="bi bi-translate"></i> Languages</div>
                    <div class="myls-repeater" data-field="languages" data-idx="<?php echo $idx; ?>">
                      <?php
                      $langs = (array)($p['languages'] ?? ['']);
                      if (empty($langs)) $langs = [''];
                      foreach ($langs as $lg): ?>
                      <div class="myls-repeater-row">
                        <input type="text" name="myls_person[<?php echo $idx; ?>][languages][]" value="<?php echo esc_attr($lg); ?>" placeholder="English" />
                        <button type="button" class="myls-btn-xs" onclick="this.parentElement.remove()">×</button>
                      </div>
                      <?php endforeach; ?>
                    </div>
                    <button type="button" class="myls-btn-sm myls-btn-add" onclick="mylsPersonAddRepeater(this, 'languages')"><i class="bi bi-plus-circle"></i> Add</button>
                  </div>
                </div>

              </div><!-- /col-main -->

              <!-- ═══ RIGHT COLUMN ═══ -->
              <div class="myls-person-col-side">

                <!-- Page Assignment -->
                <div class="myls-fieldgroup">
                  <div class="myls-fieldgroup-title"><i class="bi bi-file-earmark-check"></i> Page Assignment</div>
                  <div class="form-hint" style="margin-bottom:8px;">Schema outputs only on checked pages.</div>
                  <div class="myls-page-list">
                    <?php foreach ($assignable as $post):
                      $checked    = in_array($post->ID, $page_ids) ? 'checked' : '';
                      $type_label = get_post_type_object($post->post_type)->labels->singular_name ?? $post->post_type;
                    ?>
                    <label>
                      <input type="checkbox" name="myls_person[<?php echo $idx; ?>][pages][]" value="<?php echo $post->ID; ?>" <?php echo $checked; ?> />
                      <?php echo esc_html($post->post_title); ?>
                      <span style="color:#adb5bd;font-size:11px;">(<?php echo esc_html($type_label); ?>)</span>
                    </label>
                    <?php endforeach; ?>
                  </div>
                </div>

                <!-- Tips -->
                <div class="myls-fieldgroup" style="background:#eff6ff; border-color:#bfdbfe;">
                  <div class="myls-fieldgroup-title" style="color:#1e40af;"><i class="bi bi-info-circle"></i> Pro Tips</div>
                  <ul style="margin:0;padding-left:1.1rem;font-size:13px;color:#1e40af;line-height:1.6;">
                    <li><strong>sameAs</strong> — LinkedIn is the #1 most impactful profile link for E-E-A-T</li>
                    <li><strong>knowsAbout</strong> — Use Wikidata IDs (Q-numbers) to connect topics to Google's Knowledge Graph</li>
                    <li><strong>hasCredential</strong> — State licenses and industry certifications build trust signals</li>
                    <li><strong>worksFor</strong> — Automatically linked to your Organization schema</li>
                    <li><strong>Best pages</strong> — Assign to About, Homepage, and key service pages</li>
                    <li>AI assistants use this data to verify expertise when citing your content</li>
                  </ul>
                </div>

                <!-- Schema preview hint -->
                <div class="myls-fieldgroup">
                  <div class="myls-fieldgroup-title"><i class="bi bi-code-slash"></i> Output</div>
                  <p style="font-size:13px;margin:0;color:#6c757d;">
                    JSON-LD will output in <code>&lt;head&gt;</code> on assigned pages.
                    Validate with <a href="https://validator.schema.org/" target="_blank" rel="noopener">Schema.org Validator</a>
                    or the Admin Bar → SEO Stuff → Test Schema.ORG link.
                  </p>
                </div>

                <!-- Remove person button -->
                <?php if (count($profiles) > 1): ?>
                <div style="margin-top:8px;">
                  <button type="button" class="myls-btn-sm myls-btn-danger" onclick="if(confirm('Remove this person profile? This cannot be undone.')) this.closest('.myls-person-card').remove()">
                    <i class="bi bi-trash"></i> Remove This Person
                  </button>
                </div>
                <?php endif; ?>

              </div><!-- /col-side -->
            </div><!-- /grid -->
          </div><!-- /body -->
        </div><!-- /card -->
        <?php endforeach; ?>
      </div><!-- /accordion -->

      <!-- Add Person button -->
      <div style="margin-top:14px;">
        <button type="button" class="myls-btn-sm myls-btn-add" id="myls-add-person" style="font-size:14px;padding:8px 16px;">
          <i class="bi bi-person-plus"></i> Add Another Person
        </button>
      </div>

    </div><!-- /wrap -->

    <script>
    (function(){
      /* ── Simple repeater: sameAs, awards, languages ── */
      window.mylsPersonAddRepeater = function(btn, field) {
        var container = btn.previousElementSibling;
        var idx = container.dataset.idx;
        var row = document.createElement('div');
        row.className = 'myls-repeater-row';
        row.innerHTML = '<input type="url" name="myls_person['+idx+']['+field+'][]" value="" placeholder="" />'
          + '<button type="button" class="myls-btn-xs" onclick="this.parentElement.remove()" title="Remove">×</button>';
        if (field === 'awards' || field === 'languages') {
          row.querySelector('input').type = 'text';
        }
        container.appendChild(row);
        row.querySelector('input').focus();
      };

      /* ── Composite repeater: knowsAbout, credentials, alumni, memberOf ── */
      window.mylsPersonAddComposite = function(btn, field, keys, labels, placeholders) {
        var container = btn.previousElementSibling;
        var idx       = container.dataset.idx;
        var subIdx    = container.querySelectorAll('.myls-composite-row').length;
        var colClass  = 'cols-' + keys.length;
        var row       = document.createElement('div');
        row.className = 'myls-composite-row ' + colClass;
        var html = '';
        for (var i = 0; i < keys.length; i++) {
          html += '<div><label class="form-label">'+labels[i]+'</label>';
          var inputType = keys[i].includes('url') || keys[i].includes('wikidata') || keys[i].includes('wikipedia') ? 'url' : 'text';
          html += '<input type="'+inputType+'" name="myls_person['+idx+']['+field+']['+subIdx+']['+keys[i]+']" value="" placeholder="'+placeholders[i]+'" />';
          html += '</div>';
        }
        html += '<button type="button" class="row-remove" onclick="this.parentElement.remove()" title="Remove">×</button>';
        row.innerHTML = html;
        container.appendChild(row);
        row.querySelector('input').focus();
      };

      /* ── Image picker via WP Media ── */
      window.mylsPersonPickImage = function(btn, idx) {
        var frame = wp.media({ title: 'Select Person Photo', multiple: false, library: { type: 'image' } });
        frame.on('select', function() {
          var att     = frame.state().get('selection').first().toJSON();
          var card    = btn.closest('.myls-person-card');
          card.querySelector('.person-image-id').value  = att.id;
          card.querySelector('.person-image-url').value = att.url;
          var preview = btn.closest('.myls-img-preview');
          var img     = preview.querySelector('img');
          if (!img) { img = document.createElement('img'); preview.prepend(img); }
          img.src = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
        });
        frame.open();
      };

      /* ── Live-update accordion header when typing label ── */
      window.mylsPersonUpdateHeaderLabel = function(input) {
        var card   = input.closest('.myls-person-card');
        var nameEl = card.querySelector('.person-name');
        if (nameEl) nameEl.textContent = input.value.trim() || input.placeholder;
      };

      /* ══════════════════════════════════════════════════════════════════
       *  LINKEDIN IMPORT — AI-powered extraction from pasted content
       * ══════════════════════════════════════════════════════════════════ */
      window.mylsLinkedInToggleMode = function(cb) {
        document.getElementById('myls-linkedin-text-hint').style.display = cb.checked ? 'none' : 'block';
        document.getElementById('myls-linkedin-html-hint').style.display = cb.checked ? 'block' : 'none';
        var ta = document.getElementById('myls-linkedin-content');
        ta.value = '';
        ta.placeholder = cb.checked
          ? 'Paste LinkedIn page HTML source here…'
          : 'Paste LinkedIn profile content here…';
      };

      document.getElementById('myls-linkedin-import-btn')?.addEventListener('click', function() {
        var btn     = this;
        var content = (document.getElementById('myls-linkedin-content').value || '').trim();
        var liUrl   = (document.getElementById('myls-linkedin-url').value || '').trim();
        var target  = document.getElementById('myls-linkedin-target');
        var nonce   = document.getElementById('myls-ai-nonce');
        var isHtml  = document.getElementById('myls-linkedin-html-mode').checked;

        if (!content || content.length < 50) {
          alert('Please paste the LinkedIn profile content first. The box appears to be empty or too short.');
          document.getElementById('myls-linkedin-content').focus();
          return;
        }

        var orig = btn.innerHTML;
        btn.disabled  = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> AI Extracting…';

        var fd = new FormData();
        fd.append('action', 'myls_person_import_linkedin');
        fd.append('nonce', nonce.value);
        fd.append('content', content);
        fd.append('content_type', isHtml ? 'html' : 'text');
        fd.append('linkedin_url', liUrl);

        fetch(ajaxurl, { method: 'POST', body: fd })
          .then(function(r) { return r.json(); })
          .then(function(resp) {
            btn.disabled = false;
            btn.innerHTML = orig;

            if (!resp.success) {
              alert('Import failed: ' + (resp.data?.message || 'Unknown error'));
              return;
            }

            var p    = resp.data.profile;
            var tidx = target.value;
            var card = document.querySelector('.myls-person-card[data-person-idx="' + tidx + '"]');
            if (!card) { alert('Target person card not found.'); return; }

            var pre = 'myls_person[' + tidx + ']';

            // Helper: set input value by name
            function setVal(field, val) {
              var el = card.querySelector('[name="' + pre + '[' + field + ']"]');
              if (el) el.value = val || '';
            }

            // Populate scalar fields
            setVal('name', p.name);
            setVal('job_title', p.job_title);
            setVal('honorific_prefix', p.honorific_prefix);
            setVal('description', p.description);
            setVal('url', p.url);
            setVal('email', p.email);
            setVal('phone', p.phone);

            // Update header display
            var nameEl = card.querySelector('.person-name');
            var metaEl = card.querySelector('.person-meta');
            if (nameEl) nameEl.textContent = p.name || 'Person #' + (parseInt(tidx,10)+1);
            if (metaEl) metaEl.textContent = (p.name||'No name set') + ' \u00b7 ' + (p.job_title||'No title set');

            // ── Populate repeaters (sameAs, awards, languages) ──
            function fillRepeater(field, values, inputType) {
              var container = card.querySelector('.myls-repeater[data-field="' + field + '"]');
              if (!container || !values || !values.length) return;
              container.querySelectorAll('.myls-repeater-row').forEach(function(r) { r.remove(); });
              var idx = container.dataset.idx;
              values.forEach(function(val) {
                var row = document.createElement('div');
                row.className = 'myls-repeater-row';
                row.innerHTML = '<input type="' + (inputType||'url') + '" name="myls_person[' + idx + '][' + field + '][]" value="" />'
                  + '<button type="button" class="myls-btn-xs" onclick="this.parentElement.remove()" title="Remove">\u00d7</button>';
                row.querySelector('input').value = val;
                container.appendChild(row);
              });
            }

            fillRepeater('same_as', p.same_as, 'url');
            fillRepeater('awards', p.awards, 'text');
            fillRepeater('languages', p.languages, 'text');

            // ── Populate composite repeaters ──
            function fillComposite(field, items, keys, labels, colClass) {
              var container = card.querySelector('.myls-composite-repeater[data-field="' + field + '"]');
              if (!container || !items || !items.length) return;
              container.querySelectorAll('.myls-composite-row').forEach(function(r) { r.remove(); });
              var idx = container.dataset.idx;
              items.forEach(function(item, ri) {
                var row = document.createElement('div');
                row.className = 'myls-composite-row ' + colClass;
                var html = '';
                keys.forEach(function(k, ki) {
                  var itype = (k.includes('url') || k.includes('wikidata') || k.includes('wikipedia')) ? 'url' : 'text';
                  html += '<div><label class="form-label">' + labels[ki] + '</label>';
                  html += '<input type="' + itype + '" name="myls_person[' + idx + '][' + field + '][' + ri + '][' + k + ']" value="" /></div>';
                });
                html += '<button type="button" class="row-remove" onclick="this.parentElement.remove()" title="Remove">\u00d7</button>';
                row.innerHTML = html;
                var inputs = row.querySelectorAll('input');
                keys.forEach(function(k, ki) {
                  if (inputs[ki]) inputs[ki].value = item[k] || '';
                });
                container.appendChild(row);
              });
            }

            fillComposite('knows_about', p.knows_about,
              ['name','wikidata','wikipedia'], ['Topic Name','Wikidata URL','Wikipedia URL'], 'cols-3');
            fillComposite('credentials', p.credentials,
              ['name','abbr','issuer','issuer_url'], ['Credential Name','Abbreviation','Issuing Org','Issuer URL'], 'cols-4');
            fillComposite('alumni', p.alumni,
              ['name','url'], ['Institution Name','Institution URL'], 'cols-2');
            fillComposite('member_of', p.member_of,
              ['name','url'], ['Organization Name','Organization URL'], 'cols-2');

            // Expand the target card and scroll to it
            card.classList.remove('is-collapsed');
            card.scrollIntoView({ behavior: 'smooth', block: 'start' });

            // Clear paste area
            document.getElementById('myls-linkedin-content').value = '';
            document.getElementById('myls-linkedin-url').value = '';
          })
          .catch(function(err) {
            btn.disabled = false;
            btn.innerHTML = orig;
            alert('Network error: ' + err.message);
          });
      });

      /* ══════════════════════════════════════════════════════════════════
       *  PDF EXPORT — Fillable Form via pdf-lib (lazy-loaded from CDN)
       * ══════════════════════════════════════════════════════════════════ */
      var PDFLIB_CDN     = 'https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js';
      var REPEATER_ROWS  = 5;
      var COMPOSITE_ROWS = 3;

      function ensurePdfLib(ok, fail) {
        if (typeof window.PDFLib !== 'undefined') return ok();
        var s = document.createElement('script');
        s.src = PDFLIB_CDN;
        s.onload = ok;
        s.onerror = fail;
        document.head.appendChild(s);
      }

      window.mylsPersonExportPDF = function(btn) {
        var orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Loading PDF engine…';
        ensurePdfLib(function() {
          btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Generating…';
          mylsPersonBuildPDF(btn).then(function() {
            btn.disabled = false; btn.innerHTML = orig;
          }).catch(function(err) {
            console.error('PDF Export Error:', err);
            alert('PDF export failed: ' + err.message);
            btn.disabled = false; btn.innerHTML = orig;
          });
        }, function() {
          alert('Failed to load PDF library. Check your internet connection.');
          btn.disabled = false; btn.innerHTML = orig;
        });
      };

      async function mylsPersonBuildPDF(btn) {
        var card = btn.closest('.myls-person-card');
        if (!card) return;

        var PL  = window.PDFLib;
        var doc = await PL.PDFDocument.create();
        var frm = doc.getForm();
        var fnt = await doc.embedFont(PL.StandardFonts.Helvetica);
        var fntB = await doc.embedFont(PL.StandardFonts.HelveticaBold);
        var rgb = PL.rgb;

        /* ── Layout ── */
        var W = 612, H = 792, m = 50, cW = W - m * 2;
        var fH = 20, lH = 14, gap = 4, secGap = 10;
        var pg, yy, pgNum = 0, fldN = 0;

        function uid(b) { fldN++; return b + '_' + fldN; }
        function newPg() { pg = doc.addPage([W, H]); pgNum++; yy = H - m; return pg; }
        function chk(n) { if (yy - n < m + 30) { newPg(); return true; } return false; }

        /* ── Colours ── */
        var cBrand   = rgb(79/255,70/255,229/255);
        var cBrandLt = rgb(238/255,242/255,255/255);
        var cWhite   = rgb(1,1,1);
        var cDark    = rgb(17/255,24/255,39/255);
        var cMid     = rgb(55/255,65/255,81/255);
        var cLight   = rgb(107/255,114/255,128/255);
        var cFldBg   = rgb(250/255,250/255,250/255);
        var cFldBdr  = rgb(206/255,212/255,218/255);

        /* ── Draw helpers ── */
        function txt(s,x,y,o) {
          o=o||{};
          pg.drawText(String(s||''),{x:x,y:y,size:o.sz||9,font:o.f||fnt,color:o.c||cDark});
        }
        function rect(x,y,w,h,c) { pg.drawRectangle({x:x,y:y,width:w,height:h,color:c}); }

        function secHdr(title) {
          chk(30); yy -= secGap;
          rect(m, yy-10, cW, 20, cBrandLt);
          txt(title, m+8, yy-5, {sz:11, f:fntB, c:cBrand});
          yy -= 24;
        }

        /* ── Form field creators ── */
        function mkText(name, x, y, w, h, val, multi) {
          var f = frm.createTextField(uid(name));
          if (val) f.setText(val);
          f.addToPage(pg, {x:x, y:y, width:w, height:h||fH,
            borderWidth:0.75, borderColor:cFldBdr, backgroundColor:cFldBg});
          f.setFontSize(9);
          if (multi) f.enableMultiline();
          return f;
        }

        function mkCB(name, x, y, checked) {
          var f = frm.createCheckBox(uid(name));
          f.addToPage(pg, {x:x, y:y, width:14, height:14,
            borderWidth:0.75, borderColor:cFldBdr, backgroundColor:cFldBg});
          if (checked) f.check();
          return f;
        }

        /* ── Field layouts ── */
        function field1(label, name, val) {
          chk(fH+lH+gap+2);
          txt(label, m+2, yy-10, {sz:8, f:fntB, c:cMid});
          yy -= lH;
          mkText(name, m, yy-fH, cW, fH, val);
          yy -= (fH+gap);
        }

        function field2(l1,n1,v1, l2,n2,v2) {
          var hw = (cW-10)/2, x2 = m+hw+10;
          chk(fH+lH+gap+2);
          txt(l1, m+2, yy-10, {sz:8, f:fntB, c:cMid});
          txt(l2, x2+2, yy-10, {sz:8, f:fntB, c:cMid});
          yy -= lH;
          mkText(n1, m, yy-fH, hw, fH, v1);
          mkText(n2, x2, yy-fH, hw, fH, v2);
          yy -= (fH+gap);
        }

        function repeater(title, base, vals, rows) {
          secHdr(title);
          var it = vals.slice();
          while (it.length < rows) it.push('');
          for (var i=0; i<it.length; i++) {
            chk(fH+gap+2);
            txt((i+1)+'.', m+2, yy-fH+6, {sz:8, c:cLight});
            mkText(base+'_'+i, m+16, yy-fH, cW-16, fH, it[i]);
            yy -= (fH+gap);
          }
        }

        function composite(title, cols, data, rows) {
          secHdr(title);
          var cn = cols.length, cg = 6, cw = (cW-(cn-1)*cg)/cn;
          chk(lH+fH+gap+4);
          for (var c=0; c<cn; c++) txt(cols[c], m+c*(cw+cg)+2, yy-10, {sz:8, f:fntB, c:cMid});
          yy -= (lH+2);
          var it = data.slice();
          while (it.length < rows) { var e=[]; for(var z=0;z<cn;z++) e.push(''); it.push(e); }
          for (var r=0; r<it.length; r++) {
            chk(fH+gap+2);
            for (var c2=0; c2<cn; c2++) {
              mkText(title.replace(/[^a-zA-Z]/g,'')+'_r'+r+'c'+c2,
                     m+c2*(cw+cg), yy-fH, cw, fH, it[r][c2]||'');
            }
            yy -= (fH+gap);
          }
        }

        /* ── Gather form data ── */
        var idx = card.dataset.personIdx;
        var pre = 'myls_person['+idx+']';
        function nv(f) { var e=card.querySelector('[name="'+pre+'['+f+']"]'); return e?e.value.trim():''; }

        var label    = (card.querySelector('.person-label-input')||{}).value || 'Person #'+(parseInt(idx,10)+1);
        var fullName = nv('name'), jobTitle = nv('job_title'), honorific = nv('honorific_prefix');
        var profUrl  = nv('url'), bio = nv('description'), email = nv('email');
        var phone    = nv('phone'), imgUrl = nv('image_url');
        var enCB     = card.querySelector('[name="'+pre+'[enabled]"]');
        var isOn     = enCB ? enCB.checked : true;

        function getSimple(f) {
          var v=[]; card.querySelectorAll('.myls-repeater[data-field="'+f+'"] input').forEach(function(i){
            if(i.value.trim()) v.push(i.value.trim());
          }); return v;
        }
        function getComp(f,mc) {
          var rows=[]; card.querySelectorAll('.myls-composite-repeater[data-field="'+f+'"] .myls-composite-row').forEach(function(r){
            var ins=r.querySelectorAll('input'); if(ins.length<mc) return;
            var cells=[],has=false;
            for(var i=0;i<mc;i++){cells.push(ins[i].value.trim()); if(ins[i].value.trim()) has=true;}
            if(has) rows.push(cells);
          }); return rows;
        }

        var sameAs   = getSimple('same_as'),  awards = getSimple('awards'), langs = getSimple('languages');
        var kaRows   = getComp('knows_about',3), credRows = getComp('credentials',4);
        var alumRows = getComp('alumni',2),       memRows  = getComp('member_of',2);

        /* ══════════════════════════════════════
         *  BUILD PDF
         * ══════════════════════════════════════ */
        newPg();

        /* Brand header */
        rect(0, H-70, W, 70, cBrand);
        txt('AIntelligize', m, H-35, {sz:20, f:fntB, c:cWhite});
        txt('Person Schema Profile \u2014 Fillable Form', m, H-52, {sz:10, c:cWhite});
        var bW = fntB.widthOfTextAtSize(label,10)+16;
        rect(W-m-bW, H-50, bW, 22, cWhite);
        txt(label, W-m-bW+8, H-43, {sz:10, f:fntB, c:cBrand});
        yy = H-85;

        /* Enabled checkbox */
        chk(24);
        mkCB('enabled', m, yy-14, isOn);
        txt('Enable Person Schema for this profile', m+20, yy-10, {sz:10, f:fntB, c:cDark});
        yy -= 28;

        /* Identity */
        secHdr('Identity');
        field2('Full Name *','name',fullName, 'Job Title','job_title',jobTitle);
        field2('Honorific Prefix','honorific',honorific, 'Profile / About URL','url',profUrl);
        field2('Email','email',email, 'Phone','phone',phone);
        field1('Photo / Headshot URL','image_url',imgUrl);

        /* Bio */
        secHdr('Bio / Description');
        chk(70);
        mkText('bio', m, yy-60, cW, 60, bio, true);
        yy -= 66;

        /* Sections */
        repeater('Social Profiles & sameAs','sameas',sameAs,REPEATER_ROWS);
        composite('Areas of Expertise (knowsAbout)',['Topic Name','Wikidata URL','Wikipedia URL'],kaRows,COMPOSITE_ROWS);
        composite('Credentials & Licenses',['Credential Name','Abbreviation','Issuing Org','Issuer URL'],credRows,COMPOSITE_ROWS);
        composite('Education (alumniOf)',['Institution Name','Institution URL'],alumRows,COMPOSITE_ROWS);
        composite('Memberships (memberOf)',['Organization Name','Organization URL'],memRows,COMPOSITE_ROWS);
        repeater('Awards','awards',awards,REPEATER_ROWS);
        repeater('Languages','langs',langs,REPEATER_ROWS);

        /* ── CRITICAL: update field appearances so they render ── */
        frm.updateFieldAppearances(fnt);

        /* ── Footers on every page ── */
        var pgs = doc.getPages();
        var ds  = new Date().toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'});
        for (var p=0; p<pgs.length; p++) {
          var g = pgs[p];
          g.drawLine({start:{x:m,y:35},end:{x:W-m,y:35},thickness:0.5,color:cBrand});
          g.drawText('AIntelligize Plugin \u2014 Person Profile Form',{x:m,y:22,size:7,font:fnt,color:cLight});
          var rt = 'Generated: '+ds+'  |  Page '+(p+1)+' of '+pgs.length;
          g.drawText(rt,{x:W-m-fnt.widthOfTextAtSize(rt,7),y:22,size:7,font:fnt,color:cLight});
        }

        /* ── Download ── */
        var bytes = await doc.save();
        var blob  = new Blob([bytes],{type:'application/pdf'});
        var url   = URL.createObjectURL(blob);
        var a     = document.createElement('a');
        a.href     = url;
        a.download = 'person-profile-'+(fullName||label||'person').replace(/[^a-zA-Z0-9]/g,'_').toLowerCase()+'.pdf';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
      }

      /* ══════════════════════════════════════════════════════════════════
       *  ADD NEW PERSON (clone first as template)
       * ══════════════════════════════════════════════════════════════════ */
      document.getElementById('myls-add-person')?.addEventListener('click', function() {
        var list   = document.getElementById('myls-person-list');
        var cards  = list.querySelectorAll('.myls-person-card');
        var newIdx = cards.length;
        var clone  = cards[0].cloneNode(true);

        // Update data-idx
        clone.dataset.personIdx = newIdx;

        // Clear all inputs
        clone.querySelectorAll('input[type="text"], input[type="email"], input[type="url"], input[type="tel"], textarea').forEach(function(el) { el.value = ''; });
        clone.querySelectorAll('input[type="checkbox"]').forEach(function(el) { el.checked = el.value === '1'; });
        clone.querySelectorAll('input[type="checkbox"][name*="[pages]"]').forEach(function(el) { el.checked = false; });

        // Update all name attributes
        clone.querySelectorAll('[name]').forEach(function(el) {
          el.name = el.name.replace(/myls_person\[\d+\]/, 'myls_person[' + newIdx + ']');
        });

        // Update repeater data-idx
        clone.querySelectorAll('[data-idx]').forEach(function(el) { el.dataset.idx = newIdx; });

        // Update header display
        var nameEl = clone.querySelector('.person-name');
        if (nameEl) nameEl.textContent = 'Person #' + (newIdx + 1);
        var metaEl = clone.querySelector('.person-meta');
        if (metaEl) metaEl.textContent = 'No name set \u00b7 No title set \u00b7 0 page(s)';

        // Set label placeholder
        var labelInput = clone.querySelector('.person-label-input');
        if (labelInput) labelInput.placeholder = 'Person #' + (newIdx + 1);

        // Clear image preview
        var imgPreview = clone.querySelector('.myls-img-preview img');
        if (imgPreview) imgPreview.remove();
        var avatarImg = clone.querySelector('.person-avatar');
        if (avatarImg) {
          var placeholder = document.createElement('span');
          placeholder.className = 'person-avatar-placeholder';
          placeholder.innerHTML = '<i class="bi bi-person"></i>';
          avatarImg.replaceWith(placeholder);
        }

        // Start expanded
        clone.classList.remove('is-collapsed');

        // Ensure remove button exists
        var removeBtn = clone.querySelector('.myls-btn-danger');
        if (!removeBtn) {
          var sideCol = clone.querySelector('.myls-person-col-side');
          if (sideCol) {
            var div = document.createElement('div');
            div.style.marginTop = '8px';
            div.innerHTML = '<button type="button" class="myls-btn-sm myls-btn-danger" onclick="if(confirm(\'Remove this person profile?\')) this.closest(\'.myls-person-card\').remove()"><i class="bi bi-trash"></i> Remove This Person</button>';
            sideCol.appendChild(div);
          }
        }

        list.appendChild(clone);
        clone.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });

    })();
    </script>
    <?php
  },

  /* ------------------------------------------------------------------
   *  ON SAVE
   * ---------------------------------------------------------------- */
  'on_save' => function () {
    $raw = $_POST['myls_person'] ?? [];
    if (!is_array($raw)) $raw = [];

    $profiles = [];
    foreach ($raw as $idx => $data) {
      if (!is_array($data)) continue;

      $p = myls_person_default_profile();

      // Scalars
      $p['enabled']          = !empty($data['enabled']) ? '1' : '0';
      $p['label']            = sanitize_text_field(wp_unslash($data['label'] ?? ''));
      $p['name']             = sanitize_text_field(wp_unslash($data['name'] ?? ''));
      $p['job_title']        = sanitize_text_field(wp_unslash($data['job_title'] ?? ''));
      $p['honorific_prefix'] = sanitize_text_field(wp_unslash($data['honorific_prefix'] ?? ''));
      $p['description']      = sanitize_textarea_field(wp_unslash($data['description'] ?? ''));
      $p['url']              = esc_url_raw(wp_unslash($data['url'] ?? ''));
      $p['email']            = sanitize_email(wp_unslash($data['email'] ?? ''));
      $p['phone']            = sanitize_text_field(wp_unslash($data['phone'] ?? ''));
      $p['image_id']         = absint($data['image_id'] ?? 0);
      $p['image_url']        = esc_url_raw(wp_unslash($data['image_url'] ?? ''));

      // sameAs
      $p['same_as'] = [];
      if (!empty($data['same_as']) && is_array($data['same_as'])) {
        foreach ($data['same_as'] as $url) {
          $url = esc_url_raw(wp_unslash(trim($url)));
          if ($url) $p['same_as'][] = $url;
        }
      }

      // knowsAbout
      $p['knows_about'] = [];
      if (!empty($data['knows_about']) && is_array($data['knows_about'])) {
        foreach ($data['knows_about'] as $ka) {
          $name = sanitize_text_field(wp_unslash($ka['name'] ?? ''));
          if (!$name) continue;
          $p['knows_about'][] = [
            'name'      => $name,
            'wikidata'  => esc_url_raw(wp_unslash($ka['wikidata'] ?? '')),
            'wikipedia' => esc_url_raw(wp_unslash($ka['wikipedia'] ?? '')),
          ];
        }
      }

      // credentials
      $p['credentials'] = [];
      if (!empty($data['credentials']) && is_array($data['credentials'])) {
        foreach ($data['credentials'] as $cr) {
          $name = sanitize_text_field(wp_unslash($cr['name'] ?? ''));
          if (!$name) continue;
          $p['credentials'][] = [
            'name'       => $name,
            'abbr'       => sanitize_text_field(wp_unslash($cr['abbr'] ?? '')),
            'issuer'     => sanitize_text_field(wp_unslash($cr['issuer'] ?? '')),
            'issuer_url' => esc_url_raw(wp_unslash($cr['issuer_url'] ?? '')),
          ];
        }
      }

      // alumni
      $p['alumni'] = [];
      if (!empty($data['alumni']) && is_array($data['alumni'])) {
        foreach ($data['alumni'] as $al) {
          $name = sanitize_text_field(wp_unslash($al['name'] ?? ''));
          if (!$name) continue;
          $p['alumni'][] = [
            'name' => $name,
            'url'  => esc_url_raw(wp_unslash($al['url'] ?? '')),
          ];
        }
      }

      // memberOf
      $p['member_of'] = [];
      if (!empty($data['member_of']) && is_array($data['member_of'])) {
        foreach ($data['member_of'] as $mo) {
          $name = sanitize_text_field(wp_unslash($mo['name'] ?? ''));
          if (!$name) continue;
          $p['member_of'][] = [
            'name' => $name,
            'url'  => esc_url_raw(wp_unslash($mo['url'] ?? '')),
          ];
        }
      }

      // awards
      $p['awards'] = [];
      if (!empty($data['awards']) && is_array($data['awards'])) {
        foreach ($data['awards'] as $aw) {
          $aw = sanitize_text_field(wp_unslash(trim($aw)));
          if ($aw) $p['awards'][] = $aw;
        }
      }

      // languages
      $p['languages'] = [];
      if (!empty($data['languages']) && is_array($data['languages'])) {
        foreach ($data['languages'] as $lg) {
          $lg = sanitize_text_field(wp_unslash(trim($lg)));
          if ($lg) $p['languages'][] = $lg;
        }
      }

      // pages
      $p['pages'] = [];
      if (!empty($data['pages']) && is_array($data['pages'])) {
        $p['pages'] = array_map('absint', $data['pages']);
      }

      // Only save if name is set
      if ($p['name']) {
        $profiles[] = $p;
      }
    }

    update_option('myls_person_profiles', $profiles);
  },
];

return $spec;
