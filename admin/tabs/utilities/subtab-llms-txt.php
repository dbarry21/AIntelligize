<?php
/**
 * Utilities > llms.txt
 *
 * Location: admin/tabs/utilities/subtab-llms-txt.php
 *
 * Provides basic controls for the /llms.txt endpoint so you can tune
 * what gets exposed to AI tools without editing code.
 */

if ( ! defined('ABSPATH') ) exit;

return [
  'id'    => 'llms_txt',
  'label' => 'llms.txt',
  'order' => 30,
  'render'=> function(){

    if ( ! current_user_can('manage_options') ) {
      echo '<p class="muted">You do not have permission to edit this section.</p>';
      return;
    }

    // -------------------------------------------------
    // Save
    // -------------------------------------------------
    $saved = false;
    if ( isset($_POST['myls_llms_save']) ) {
      check_admin_referer('myls_llms_save');

      // Endpoint master switch
      update_option('myls_llms_enabled', ! empty($_POST['myls_llms_enabled']) ? '1' : '0');

      // Section toggles
      update_option('myls_llms_include_business_details', ! empty($_POST['myls_llms_include_business_details']) ? '1' : '0');
      update_option('myls_llms_include_services', ! empty($_POST['myls_llms_include_services']) ? '1' : '0');
      update_option('myls_llms_include_service_areas', ! empty($_POST['myls_llms_include_service_areas']) ? '1' : '0');
      update_option('myls_llms_include_faqs', ! empty($_POST['myls_llms_include_faqs']) ? '1' : '0');

      // Limits (clamped in generator as well; this is just UI hygiene)
      $svc_limit   = max(1, min(200, (int)($_POST['myls_llms_services_limit'] ?? 15)));
      $area_limit  = max(1, min(300, (int)($_POST['myls_llms_service_areas_limit'] ?? 20)));
      $faq_limit   = max(1, min(200, (int)($_POST['myls_llms_faqs_limit'] ?? 25)));

      update_option('myls_llms_services_limit', (string) $svc_limit);
      update_option('myls_llms_service_areas_limit', (string) $area_limit);
      update_option('myls_llms_faqs_limit', (string) $faq_limit);

      $saved = true;
    }

    // Save AI prompt template (separate form)
    if ( isset($_POST['myls_llms_prompt_save']) ) {
      check_admin_referer('myls_llms_prompt_save');
      $posted_prompt = wp_kses_post( wp_unslash($_POST['myls_ai_llms_txt_prompt'] ?? '') );
      update_option('myls_ai_llms_txt_prompt_template', $posted_prompt);
      echo '<div class="updated notice"><p>Saved llms.txt prompt template.</p></div>';
    }

    // -------------------------------------------------
    // Values
    // -------------------------------------------------
    $enabled       = get_option('myls_llms_enabled', '1') === '1';
    $inc_business  = get_option('myls_llms_include_business_details', '1') === '1';
    $inc_services  = get_option('myls_llms_include_services', '1') === '1';
    $inc_areas     = get_option('myls_llms_include_service_areas', '1') === '1';
    $inc_faqs      = get_option('myls_llms_include_faqs', '1') === '1';

    $svc_limit     = (int) get_option('myls_llms_services_limit', '15');
    $area_limit    = (int) get_option('myls_llms_service_areas_limit', '20');
    $faq_limit     = (int) get_option('myls_llms_faqs_limit', '25');

    $endpoint_url  = home_url('/llms.txt');

    if ( $saved ) {
      echo '<div class="notice notice-success"><p><strong>Saved.</strong> If /llms.txt returns 404, visit <em>Settings → Permalinks</em> and click <em>Save Changes</em> to flush rewrite rules.</p></div>';
    }
    ?>

    <div class="row">
      <div class="col-12 col-md-8">
        <div class="cardish">
          <h2 style="margin-top:0;">/llms.txt Controls</h2>
          <p class="muted">Controls what is emitted by <code><?php echo esc_html($endpoint_url); ?></code>.</p>

          <form method="post" action="">
            <?php wp_nonce_field('myls_llms_save'); ?>

            <div class="mb-3">
              <label class="form-label">
                <input type="checkbox" name="myls_llms_enabled" value="1" <?php checked($enabled); ?> />
                Enable /llms.txt endpoint
              </label>
              <div class="muted">When disabled, the URL will return 404 (rewrite rule remains in place).</div>
            </div>

            <hr />

            <h3 style="margin:0 0 .5rem 0;">Include sections</h3>

            <div class="mb-2">
              <label class="form-label" style="display:flex; gap:.5rem; align-items:center;">
                <input type="checkbox" name="myls_llms_include_business_details" value="1" <?php checked($inc_business); ?> />
                Business details (Organization / LocalBusiness)
              </label>
              <div class="muted">Outputs legal name, website, phone, and address using MYLS schema settings.</div>
            </div>

            <div class="mb-2">
              <label class="form-label" style="display:flex; gap:.5rem; align-items:center;">
                <input type="checkbox" name="myls_llms_include_services" value="1" <?php checked($inc_services); ?> />
                Primary services (Service CPT)
              </label>
              <div class="row">
                <div class="col-12 col-md-4">
                  <label class="form-label">Max services</label>
                  <input type="text" class="form-control" name="myls_llms_services_limit" value="<?php echo esc_attr($svc_limit); ?>" />
                </div>
              </div>
            </div>

            <div class="mb-2 mt-3">
              <label class="form-label" style="display:flex; gap:.5rem; align-items:center;">
                <input type="checkbox" name="myls_llms_include_service_areas" value="1" <?php checked($inc_areas); ?> />
                Service areas (Service Area CPT)
              </label>
              <div class="row">
                <div class="col-12 col-md-4">
                  <label class="form-label">Max service areas</label>
                  <input type="text" class="form-control" name="myls_llms_service_areas_limit" value="<?php echo esc_attr($area_limit); ?>" />
                </div>
              </div>
            </div>

            <div class="mb-2 mt-3">
              <label class="form-label" style="display:flex; gap:.5rem; align-items:center;">
                <input type="checkbox" name="myls_llms_include_faqs" value="1" <?php checked($inc_faqs); ?> />
                FAQs (master list from post meta <code>_myls_faq_items</code>)
              </label>
              <div class="row">
                <div class="col-12 col-md-4">
                  <label class="form-label">Max FAQ links</label>
                  <input type="text" class="form-control" name="myls_llms_faqs_limit" value="<?php echo esc_attr($faq_limit); ?>" />
                </div>
              </div>
            </div>

            <div class="mt-3">
              <button type="submit" name="myls_llms_save" class="btn btn-primary">Save llms.txt settings</button>
            </div>

          </form>
        </div>
      </div>

      <div class="col-12 col-md-4">
        <div class="cardish">
          <h3 style="margin-top:0;">Quick checks</h3>
          <p><strong>Endpoint:</strong><br />
            <a href="<?php echo esc_url($endpoint_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($endpoint_url); ?></a>
          </p>

          <ul style="margin:0; padding-left:1.1rem;">
            <li>If you see 404, flush rewrites: <em>Settings → Permalinks → Save Changes</em>.</li>
            <li>Keep limits reasonable (10–25 is usually enough).</li>
            <li>FAQs link to stable anchors like <code>#faq-1</code> on the page.</li>
          </ul>

          <hr />

          <p class="muted" style="margin:0;">Tip: If a page has more than one FAQ accordion, anchors may repeat. Best practice is one FAQ accordion per page.</p>
        </div>
      </div>
    </div>

    <?php
    /* -----------------------------------------------------------------
     * AI llms.txt Generator
     * ----------------------------------------------------------------- */

    // Load saved prompt template
    $llms_prompt = get_option('myls_ai_llms_txt_prompt_template', '');
    if ( ! is_string($llms_prompt) || trim($llms_prompt) === '' ) {
      $llms_prompt = myls_get_default_prompt('llms-txt');
    }
    ?>

    <div class="row mt-4">
      <div class="col-12">
        <div class="cardish">
          <h2 style="margin-top:0;">
            <i class="bi bi-robot"></i> AI llms.txt Generator
          </h2>
          <p class="muted">Generate a city-specific, AI-optimized llms.txt file using your service area data and FAQs.</p>

          <div class="row">

            <!-- Left column: controls -->
            <div class="col-12 col-md-4">
              <div class="mb-3">
                <label class="form-label fw-bold">Select City</label>
                <select id="myls-llms-city-select" class="form-select">
                  <option value="">— Loading cities… —</option>
                </select>
                <div class="muted mt-1">Cities are pulled from your Service Area posts' City/State values.</div>
              </div>

              <div class="mb-3">
                <label class="form-label fw-bold">County <span class="muted">(optional)</span></label>
                <input type="text" id="myls-llms-county" class="form-control" placeholder="e.g. Hillsborough County" />
                <div class="muted mt-1">Helps AI add geographic context. Enter the county for the selected city.</div>
              </div>

              <div class="mb-3">
                <button type="button" id="myls-llms-generate-btn" class="btn btn-primary" disabled>
                  <i class="bi bi-stars"></i> Generate llms.txt
                </button>
                <button type="button" id="myls-llms-stop-btn" class="btn btn-outline-danger ms-2" style="display:none;">
                  Stop
                </button>
              </div>

              <div id="myls-llms-status" class="muted mb-3" style="display:none;"></div>

              <div id="myls-llms-result-actions" style="display:none;">
                <button type="button" id="myls-llms-copy-btn" class="btn btn-outline-secondary btn-sm me-1">
                  <i class="bi bi-clipboard"></i> Copy
                </button>
                <button type="button" id="myls-llms-download-btn" class="btn btn-outline-secondary btn-sm">
                  <i class="bi bi-download"></i> Download .txt
                </button>
              </div>
            </div>

            <!-- Right column: preview -->
            <div class="col-12 col-md-8">
              <label class="form-label fw-bold">Preview</label>
              <textarea id="myls-llms-preview" class="form-control" rows="20" readonly placeholder="Generated llms.txt content will appear here…" style="font-family:monospace; font-size:0.85rem;"></textarea>
            </div>

          </div>

          <!-- Prompt template (collapsible) -->
          <div class="mt-4">
            <p>
              <a class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" href="#myls-llms-prompt-section" role="button" aria-expanded="false" aria-controls="myls-llms-prompt-section" aria-label="Toggle prompt template section">
                <i class="bi bi-code-slash"></i> Prompt Template
              </a>
            </p>
            <div class="collapse" id="myls-llms-prompt-section">
              <form method="post" action="">
                <?php wp_nonce_field('myls_llms_prompt_save'); ?>
                <?php if ( function_exists('myls_prompt_toolbar') ) myls_prompt_toolbar('llms-txt', 'myls_ai_llms_txt_prompt'); ?>
                <textarea id="myls_ai_llms_txt_prompt" name="myls_ai_llms_txt_prompt" class="widefat" rows="22"><?php echo esc_textarea($llms_prompt); ?></textarea>
                <div class="mt-2">
                  <button type="submit" name="myls_llms_prompt_save" class="btn btn-sm btn-primary">Save Prompt Template</button>
                </div>
                <div class="muted mt-2">
                  <strong>Available variables:</strong>
                  <code>{{BUSINESS_NAME}}</code>
                  <code>{{WEBSITE_URL}}</code>
                  <code>{{PHONE}}</code>
                  <code>{{CITY_NAME}}</code>
                  <code>{{STATE}}</code>
                  <code>{{COUNTY}}</code>
                  <code>{{CITY_SERVICE_LIST}}</code>
                  <code>{{CITY_FAQ_LIST}}</code>
                  <code>{{DATE}}</code>
                </div>
              </form>
            </div>
          </div>

        </div>
      </div>
    </div>

    <script>
    (function(){
      const citySelect   = document.getElementById('myls-llms-city-select');
      const countyInput   = document.getElementById('myls-llms-county');
      const generateBtn   = document.getElementById('myls-llms-generate-btn');
      const stopBtn       = document.getElementById('myls-llms-stop-btn');
      const statusEl      = document.getElementById('myls-llms-status');
      const previewEl     = document.getElementById('myls-llms-preview');
      const resultActions = document.getElementById('myls-llms-result-actions');
      const copyBtn       = document.getElementById('myls-llms-copy-btn');
      const downloadBtn   = document.getElementById('myls-llms-download-btn');

      let abortController = null;

      /* ── Load cities on page load ── */
      function loadCities() {
        const fd = new FormData();
        fd.append('action', 'myls_ai_llms_txt_get_cities');
        fd.append('nonce', (typeof mylsAiOps !== 'undefined') ? mylsAiOps.nonce : '');

        fetch(ajaxurl, { method:'POST', body:fd })
          .then(r => r.json())
          .then(resp => {
            citySelect.innerHTML = '<option value="">— Select a city —</option>';
            if (resp.success && resp.data.length) {
              resp.data.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.city_state;
                opt.textContent = c.city_state + ' (' + c.count + ' service area' + (c.count !== 1 ? 's' : '') + ')';
                citySelect.appendChild(opt);
              });
              generateBtn.disabled = false;
            } else {
              citySelect.innerHTML = '<option value="">— No cities found —</option>';
            }
          })
          .catch(() => {
            citySelect.innerHTML = '<option value="">— Error loading cities —</option>';
          });
      }

      /* ── Enable/disable generate based on selection ── */
      citySelect.addEventListener('change', () => {
        generateBtn.disabled = !citySelect.value;
      });

      /* ── Generate ── */
      generateBtn.addEventListener('click', function() {
        const city = citySelect.value;
        if (!city) return;

        generateBtn.disabled = true;
        stopBtn.style.display = 'inline-block';
        statusEl.style.display = 'block';
        statusEl.textContent = 'Generating llms.txt for ' + city + '…';
        previewEl.value = '';
        resultActions.style.display = 'none';

        abortController = new AbortController();

        const fd = new FormData();
        fd.append('action', 'myls_ai_llms_txt_generate');
        fd.append('nonce', (typeof mylsAiOps !== 'undefined') ? mylsAiOps.nonce : '');
        fd.append('city_state', city);
        fd.append('county', countyInput.value.trim());

        fetch(ajaxurl, { method:'POST', body:fd, signal:abortController.signal })
          .then(r => r.json())
          .then(resp => {
            if (resp.success) {
              previewEl.value = resp.data.content;
              statusEl.innerHTML = '✔ Generated — <strong>' + resp.data.words + ' words</strong> in ' +
                resp.data.time + 's · Model: ' + resp.data.model + ' · Provider: ' + resp.data.provider;
              resultActions.style.display = 'block';
            } else {
              statusEl.textContent = '❌ ' + (resp.data?.message || 'Generation failed.');
            }
          })
          .catch(err => {
            if (err.name === 'AbortError') {
              statusEl.textContent = 'Stopped.';
            } else {
              statusEl.textContent = '❌ Network error: ' + err.message;
            }
          })
          .finally(() => {
            generateBtn.disabled = !citySelect.value;
            stopBtn.style.display = 'none';
            abortController = null;
          });
      });

      /* ── Stop ── */
      stopBtn.addEventListener('click', () => {
        if (abortController) abortController.abort();
      });

      /* ── Copy ── */
      copyBtn.addEventListener('click', () => {
        navigator.clipboard.writeText(previewEl.value).then(() => {
          const orig = copyBtn.innerHTML;
          copyBtn.innerHTML = '<i class="bi bi-check"></i> Copied!';
          setTimeout(() => { copyBtn.innerHTML = orig; }, 2000);
        });
      });

      /* ── Download ── */
      downloadBtn.addEventListener('click', () => {
        const city = citySelect.value.replace(/[^a-zA-Z0-9]/g, '-').toLowerCase();
        const filename = 'llms-' + city + '.txt';
        const blob = new Blob([previewEl.value], { type:'text/plain' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        a.click();
        URL.revokeObjectURL(a.href);
      });

      /* ── Init ── */
      loadCities();
    })();
    </script>

    <?php
  }
];
