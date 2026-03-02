<?php
/**
 * AI Subtab: FAQs Builder
 * File: admin/tabs/ai/subtab-faqs.php
 *
 * - Mirrors the GEO subtab layout/UX (2-column)
 * - Loads posts by type (includes drafts)
 * - Generates conversion-focused FAQs from:
 *    (A) on-site rendered page text (permalink fetch)
 *    (B) reputable public sources (generic service info, not business-specific)
 * - Optional external source links (target="_blank")
 * - Provides preview + download .html + download .docx
 *
 * NEW:
 * - Contact Page selector saved in option myls_contact_page_id (stores page ID)
 * - Smart default detection: /contact-us/ then /contact/
 * - Exposes resolved contactUrl + contactPageId to JS config
 * - Updated default prompt supports: {{CITY_STATE}}, {{CONTACT_URL}}, UL/OL mix, and internal CTA link
 * - Default variant LONG
 */

if ( ! defined('ABSPATH') ) exit;

return [
  'id'    => 'faqs',
  'label' => 'FAQs (Builder)',
  'icon'  => 'bi-question-circle',
  'order' => 50,
  'render'=> function () {

    // ---------------------------------------------------------------------
    // Contact Page option (store page ID, resolve permalink dynamically)
    // ---------------------------------------------------------------------
    $contact_page_id = (int) get_option('myls_contact_page_id', 0);

    // Smart default detection (only if not set yet)
    if ( $contact_page_id <= 0 ) {
      $p = get_page_by_path('contact-us');
      if ( ! $p ) $p = get_page_by_path('contact');

      if ( $p && ! empty($p->ID) ) {
        $contact_page_id = (int) $p->ID;
        update_option('myls_contact_page_id', $contact_page_id);
      }
    }

    // Resolve contact URL (fallback to /contact-us/)
    $contact_url = $contact_page_id > 0 ? get_permalink($contact_page_id) : home_url('/contact-us/');
    $contact_url = esc_url_raw( $contact_url );

    // ---------------------------------------------------------------------
    // Default prompt (v2)
    // ---------------------------------------------------------------------
    $default_prompt = get_option('myls_ai_faqs_prompt_template_v2', '');
    if ( ! is_string($default_prompt) || trim($default_prompt) === '' ) {
      $default_prompt = myls_get_default_prompt('faqs-builder');
    }

    // ---------------------------------------------------------------------
    // One-time migration from legacy option key (v1)
    // ---------------------------------------------------------------------
    if ( ! is_string($default_prompt) || trim($default_prompt) === '' ) {
      $legacy = get_option('myls_ai_faqs_prompt_template', '');
      if ( is_string($legacy) && trim($legacy) !== '' ) {
        $is_legacy_one_line = (stripos($legacy, 'ONE LINE') !== false) || (stripos($legacy, '<strong>Question') !== false && stripos($legacy, '<strong>Answer') !== false);
        $migrated = $is_legacy_one_line ? $default_prompt : $legacy;
        update_option('myls_ai_faqs_prompt_template_v2', $migrated);
        $default_prompt = $migrated;
      }
    }

    // ---------------------------------------------------------------------
    // Save prompt + params + contact page
    // ---------------------------------------------------------------------
    if (
      isset($_POST['myls_ai_faqs_save']) &&
      check_admin_referer('myls_ai_faqs_save_nonce', 'myls_ai_faqs_save_nonce')
    ) {
      $posted_prompt = wp_kses_post( wp_unslash($_POST['myls_ai_faqs_prompt_template'] ?? '') );

      update_option('myls_ai_faqs_prompt_template', $posted_prompt);
      update_option('myls_ai_faqs_prompt_template_v2', $posted_prompt);

      update_option('myls_ai_faqs_tokens', max(1, (int) ($_POST['myls_ai_faqs_tokens'] ?? 1200)));
      update_option('myls_ai_faqs_temperature', (float) ($_POST['myls_ai_faqs_temperature'] ?? 0.5));

      $v = strtoupper( sanitize_text_field( wp_unslash($_POST['myls_ai_faqs_variant_default'] ?? 'LONG') ) );
      if ( $v !== 'SHORT' ) $v = 'LONG';
      update_option('myls_ai_faqs_variant_default', $v);

      // ✅ Contact page ID save
      $posted_contact_id = isset($_POST['myls_contact_page_id']) ? (int) $_POST['myls_contact_page_id'] : 0;
      update_option('myls_contact_page_id', max(0, $posted_contact_id));

      echo '<div class="updated notice"><p>Saved FAQs prompt template, params, and Contact Page.</p></div>';

      // Refresh local vars after save
      $contact_page_id = (int) get_option('myls_contact_page_id', 0);
      $contact_url     = $contact_page_id > 0 ? get_permalink($contact_page_id) : home_url('/contact-us/');
      $contact_url     = esc_url_raw( $contact_url );
    }

    $prompt = get_option('myls_ai_faqs_prompt_template_v2', $default_prompt);
    if ( ! is_string($prompt) || trim($prompt) === '' ) $prompt = $default_prompt;

    $tokens = (int) get_option('myls_ai_faqs_tokens', 4000);
    $temp   = (float) get_option('myls_ai_faqs_temperature', 0.5);

    // ✅ Default to LONG (as requested)
    $variant_default = strtoupper( (string) get_option('myls_ai_faqs_variant_default', 'LONG') );
    if ( $variant_default !== 'SHORT' ) $variant_default = 'LONG';

    // ---------------------------------------------------------------------
    // Post type selector
    // ---------------------------------------------------------------------
    $pts = get_post_types(['public' => true], 'objects');
    unset($pts['attachment']);
    $default_pt = isset($pts['page']) ? 'page' : ( $pts ? array_key_first($pts) : 'page' );

    $nonce = wp_create_nonce('myls_ai_ops');
    ?>

<!-- Inline styles removed - now using admin.css component classes -->

<div class="myls-two-col">
  <!-- LEFT: Prompt Configuration -->
  <div class="myls-card">
    <div class="myls-card-header">
      <h2 class="myls-card-title">
        <i class="bi bi-chat-left-text"></i>
        FAQs Prompt Template
      </h2>
    </div>

    <form method="post">
      <?php wp_nonce_field('myls_ai_faqs_save_nonce','myls_ai_faqs_save_nonce'); ?>

      <div class="mb-3">
        <label class="form-label"><strong>Contact Page</strong></label>
        <?php
          wp_dropdown_pages([
            'name'              => 'myls_contact_page_id',
            'selected'          => $contact_page_id,
            'show_option_none'  => '-- Select Contact Page --',
            'option_none_value' => 0,
          ]);
        ?>
        <div class="small text-muted mt-1">
          Resolved URL: <code><?php echo esc_html( $contact_url ?: home_url('/contact-us/') ); ?></code>
          <br/>This value is injected into prompts as <code>{{CONTACT_URL}}</code>.
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label"><strong>Prompt Template</strong></label>
        <?php myls_prompt_toolbar('faqs-builder', 'myls_ai_faqs_prompt_template'); ?>
        <textarea id="myls_ai_faqs_prompt_template" name="myls_ai_faqs_prompt_template" class="widefat" rows="22"><?php echo esc_textarea($prompt); ?></textarea>
        <p class="description">
          Variables:
          <code>{{TITLE}}</code>,
          <code>{{URL}}</code>,
          <code>{{PAGE_TEXT}}</code>,
          <code>{{ALLOW_LINKS}}</code>,
          <code>{{VARIANT}}</code>,
          <code>{{CITY_STATE}}</code>,
          <code>{{CONTACT_URL}}</code>,
          <code>{{CREDENTIALS}}</code>
          <?php
          if ( function_exists('myls_build_tagline_credentials') ) {
              $cred = myls_build_tagline_credentials();
              if ( $cred !== '' ) {
                  echo '<br/><span class="description"><strong>{{CREDENTIALS}}:</strong> <code>' . esc_html($cred) . '</code></span>';
              } else {
                  echo '<br/><span class="description"><strong>{{CREDENTIALS}} is empty</strong> — add awards, certs, or memberships in <a href="admin.php?page=aintelligize&tab=schema">Schema → Organization</a>.</span>';
              }
          }
          ?>
        </p>
      </div>

      <div class="row">
        <div class="col-6 mb-3">
          <label class="form-label">Max Tokens</label>
          <input id="myls_ai_faqs_tokens" type="number" min="1" name="myls_ai_faqs_tokens" class="regular-text form-control" value="<?php echo esc_attr($tokens); ?>" />
          <div class="small text-muted mt-1">Recommended: 10000-12000 for LONG variant (10-15 detailed FAQs), 5000-6000 for SHORT variant.</div>
        </div>
        <div class="col-6 mb-3">
          <label class="form-label">Temperature</label>
          <input id="myls_ai_faqs_temperature" type="number" step="0.1" min="0" max="2" name="myls_ai_faqs_temperature" class="regular-text form-control" value="<?php echo esc_attr($temp); ?>" />
          <div class="small text-muted mt-1">For structured HTML, 0.5–0.6 is usually the sweet spot.</div>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Default Variant</label>
        <select name="myls_ai_faqs_variant_default" class="form-select" style="max-width:220px;">
          <option value="LONG" <?php selected('LONG', $variant_default); ?>>LONG (10–15 FAQs • Longer answers)</option>
          <option value="SHORT" <?php selected('SHORT', $variant_default); ?>>SHORT (8–12 FAQs • Shorter answers)</option>
        </select>
        <div class="small text-muted mt-1">Default is LONG (recommended) for richer answers and AI Overview formatting.</div>
      </div>

      <p><button type="submit" name="myls_ai_faqs_save" class="button button-primary">Save Template & Params</button></p>
    </form>
  </div>

  <!-- RIGHT: Post Selection & Generation -->
  <div class="myls-card">
    <div class="myls-card-header">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h2 class="myls-card-title">
          <i class="bi bi-list-check"></i>
          Select Posts
        </h2>
        <div class="myls-badge myls-badge-primary">
          Processed: <span id="myls_ai_faqs_count">0</span>
        </div>
      </div>
    </div>

    <div class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="form-label">Post Type</label>
        <select id="myls_ai_faqs_pt" class="form-select">
          <?php foreach ($pts as $pt_key => $obj): ?>
            <option value="<?php echo esc_attr($pt_key); ?>" <?php selected($pt_key, $default_pt); ?>>
              <?php echo esc_html($obj->labels->singular_name . " ({$pt_key})"); ?>
            </option>
          <?php endforeach; ?>
        </select>

        <div class="mt-2">
          <label class="form-label" for="myls_ai_faqs_search">Search</label>
          <input
            type="text"
            id="myls_ai_faqs_search"
            class="form-control"
            placeholder="Type to filter posts…"
            autocomplete="off"
          />
          <div class="small text-muted mt-1">Filters the loaded post list (title or ID).</div>
        </div>
      </div>

      <div class="col-md-8">
        <label class="form-label">Posts</label>
        <select id="myls_ai_faqs_posts" class="form-select" multiple size="8" style="min-height:200px;"></select>
      </div>
    </div>

    <div class="mt-3 myls-actions">
      <button type="button" class="button" id="myls_ai_faqs_select_all">Select All</button>
      <button type="button" class="button" id="myls_ai_faqs_clear">Clear</button>
      <span class="small text-muted" id="myls_ai_faqs_loaded_hint"></span>
    </div>

    <!-- Options -->
    <div class="mt-3 d-flex flex-wrap align-items-center gap-3">
      <label style="display:flex;align-items:center;gap:6px;margin:0;">
        <input type="checkbox" id="myls_ai_faqs_allow_links" checked>
        <strong>Allow source links (target="_blank")</strong>
      </label>

      <label style="display:flex;align-items:center;gap:6px;margin:0;">
        <input type="checkbox" id="myls_ai_faqs_skip_existing" checked>
        <strong>Skip posts with existing MYLS FAQs</strong>
      </label>

      <label style="display:flex;align-items:center;gap:6px;margin:0;">
        <input type="checkbox" id="myls_ai_faqs_acf_replace">
        <strong>Replace existing MYLS FAQs (overwrite)</strong>
      </label>
    </div>

    <!-- Row 1: Insert / Delete / Stop -->
    <div class="mt-2 d-flex flex-wrap align-items-center gap-2">
      <button type="button" class="button" id="myls_ai_faqs_insert_acf" disabled>
        <i class="bi bi-plus-circle"></i> Insert into MYLS FAQs
      </button>
      <button type="button" class="button" id="myls_ai_faqs_delete_auto" disabled>
        <i class="bi bi-trash"></i> Delete MYLS FAQs
      </button>
      <button type="button" class="button" id="myls_ai_faqs_stop" disabled>
        <i class="bi bi-stop-circle"></i> Stop
      </button>

      <span class="myls-spinner" id="myls_ai_faqs_spinner">
        <span class="dashicons dashicons-update"></span>
        <span class="myls-text-small">Processing…</span>
      </span>
      <span id="myls_ai_faqs_status" class="myls-text-muted"></span>
    </div>

    <!-- Row 2: Generate & Downloads (slim) -->
    <div class="mt-1 d-flex flex-wrap align-items-center gap-2">
      <button type="button" class="button button-primary" id="myls_ai_faqs_generate">
        <i class="bi bi-stars"></i> Generate FAQs (Preview)
      </button>
      <button type="button" class="button" id="myls_ai_faqs_docx" disabled>
        <i class="bi bi-file-earmark-word"></i> Download .docx
      </button>
      <button type="button" class="button" id="myls_ai_faqs_html" disabled>
        <i class="bi bi-filetype-html"></i> Download .html
      </button>
    </div>

    <hr class="myls-divider"/>

    <div class="mb-3">
      <h3 class="h5 mb-2">
        <i class="bi bi-eye"></i> Preview (FAQs HTML)
      </h3>
      <div id="myls_ai_faqs_preview" class="myls-preview-box"></div>
    </div>

    <div class="mb-3">
      <h3 class="h5 mb-2">
        <i class="bi bi-code"></i> Raw Output
      </h3>
      <pre id="myls_ai_faqs_output" class="myls-preview-box" style="white-space:pre-wrap;"></pre>
    </div>

    <div class="mb-3">
      <div class="myls-results-header">
        <h3 class="h5 mb-0"><i class="bi bi-terminal"></i> Results Log</h3>
        <button type="button" class="myls-btn-export-pdf" data-log-target="myls_ai_faqs_results"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
      </div>
      <pre id="myls_ai_faqs_results" class="myls-results-terminal">Ready.</pre>
    </div>

  </div>
</div>

<?php
    /**
     * Print config + load JS
     */
    add_action('admin_print_footer_scripts', function() use ($default_pt, $nonce, $variant_default, $contact_page_id, $contact_url) {

      static $did = false;
      if ($did) return;
      $did = true;

      if ( empty($_GET['page']) || sanitize_key($_GET['page']) !== 'aintelligize' ) return;

      $script_url = rtrim(MYLS_URL, '/') . '/assets/js/myls-ai-faqs.js';
      $v = (defined('MYLS_VERSION') && MYLS_VERSION) ? MYLS_VERSION : (string) time();
      ?>
      <script>
      window.MYLS_AI_FAQS = {
        ajaxurl: "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>",
        nonce: "<?php echo esc_js( $nonce ); ?>",
        defaultType: "<?php echo esc_js( $default_pt ); ?>",
        defaultVariant: "<?php echo esc_js( $variant_default ); ?>",

        // Contact settings (NEW)
        contactPageId: "<?php echo esc_js( (string) (int) $contact_page_id ); ?>",
        contactUrl: "<?php echo esc_js( (string) $contact_url ); ?>",

        action_get_posts: "myls_ai_faqs_get_posts_v1",
        action_generate: "myls_ai_faqs_generate_v1",
        action_check_existing_myls: "myls_ai_faqs_check_existing_myls_v1",

        // MYLS native
        action_insert_myls: "myls_ai_faqs_insert_myls_v1",
        action_delete_auto_myls: "myls_ai_faqs_delete_auto_myls_v1",

        // Back-compat map
        action_insert_acf: "myls_ai_faqs_insert_myls_v1",
        action_delete_auto_acf: "myls_ai_faqs_delete_auto_myls_v1"
      };
      </script>
      <script src="<?php echo esc_url( $script_url . '?v=' . rawurlencode($v) ); ?>"></script>
      <?php
    }, 9999);

  }
];
