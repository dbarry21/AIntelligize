<?php
if ( ! defined('ABSPATH') ) exit;

return [
  'id'    => 'about-area',
  'label' => 'About the Area',
  'icon'  => 'bi-map',
  'order' => 30,
  'render'=> function () {

    if ( isset($_POST['myls_ai_about_save']) && check_admin_referer('myls_ai_about_save_nonce', 'myls_ai_about_save_nonce') ) {
      update_option('myls_ai_about_prompt_template', wp_kses_post(stripslashes($_POST['myls_ai_about_prompt_template'] ?? '')));
      update_option('myls_ai_about_tokens', max(1, (int)($_POST['myls_ai_about_tokens'] ?? 600)));
      update_option('myls_ai_about_temperature', (float)($_POST['myls_ai_about_temperature'] ?? 0.7));
      echo '<div class="updated notice"><p>Saved “About the Area” template & params.</p></div>';
    }

    $default_prompt = myls_get_default_prompt('about-area');

    $about_prompt = get_option('myls_ai_about_prompt_template', $default_prompt);
    $about_tokens = (int) get_option('myls_ai_about_tokens', 1600);
    $about_temp   = (float) get_option('myls_ai_about_temperature', 0.7);

    $pts = get_post_types(['public'=>true], 'objects');
    $default_pt = isset($pts['service_area']) ? 'service_area' : ( isset($pts['page']) ? 'page' : array_key_first($pts) );
    $nonce = wp_create_nonce('myls_ai_ops');
    ?>

    <div class="myls-two-col" style="display:grid;grid-template-columns:1fr 1.5fr;gap:20px;">
      <div style="border:1px solid #000;padding:16px;border-radius:12px;">
        <h2 class="h4" style="margin-top:0;">About the Area — Prompt Template</h2>
        <form method="post">
          <?php wp_nonce_field('myls_ai_about_save_nonce','myls_ai_about_save_nonce'); ?>
          <div class="mb-3">
            <label class="form-label"><strong>Prompt Template</strong></label>
            <?php myls_prompt_toolbar('about-area', 'myls_ai_about_prompt_ta'); ?>
            <textarea id="myls_ai_about_prompt_ta" name="myls_ai_about_prompt_template" class="widefat" rows="12"><?php echo esc_textarea($about_prompt); ?></textarea>
            <p class="description">Variables: <code>{{CITY_STATE}}</code>, <code>{{PAGE_TITLE}}</code>, <code>{{FOCUS_KEYWORD}}</code> (Yoast/Rank Math), <code>{{SERVICE_SUBTYPE}}</code> (from Service Schema subtab).</p>
          </div>

          <div class="row">
            <div class="col-6 mb-3">
              <label class="form-label">Max Tokens</label>
              <input type="number" min="1" name="myls_ai_about_tokens" class="regular-text form-control" value="<?php echo esc_attr($about_tokens); ?>" />
            </div>
            <div class="col-6 mb-3">
              <label class="form-label">Temperature</label>
              <input type="number" step="0.1" min="0" max="2" name="myls_ai_about_temperature" class="regular-text form-control" value="<?php echo esc_attr($about_temp); ?>" />
            </div>
          </div>

          <p><button type="submit" name="myls_ai_about_save" class="button button-primary">Save Template & Params</button></p>
        </form>
      </div>

      <div style="border:1px solid #000;padding:16px;border-radius:12px;">
        <h2 class="h4" style="margin-top:0;">Generate “About the Area”</h2>

        <div class="row g-3 align-items-end">
          <div class="col-md-4">
            <label class="form-label">Post Type</label>
            <select id="myls_ai_about_pt" class="form-select">
              <?php foreach ($pts as $pt_key => $obj): if ($pt_key === 'attachment') continue; ?>
                <option value="<?php echo esc_attr($pt_key); ?>" <?php selected($pt_key, $default_pt); ?>>
                  <?php echo esc_html($obj->labels->singular_name . " ({$pt_key})"); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-8">
            <label class="form-label">Select Posts</label>
            <select id="myls_ai_about_posts" class="form-select" multiple size="8" style="width:100%;"></select>
          </div>
        </div>

        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" id="myls_ai_about_skip_filled" checked>
          <label class="form-check-label" for="myls_ai_about_skip_filled">
            Skip posts where <code>about_the_area</code> is already filled
          </label>
        </div>

        <div class="mt-3 d-flex gap-2 align-items-center flex-wrap">
          <button id="myls_ai_about_generate" class="button button-primary">Generate for Selected</button>
          <button id="myls_ai_about_stop" class="button" style="color:#d63638;border-color:#d63638;" disabled>⏹ Stop</button>
          <span id="myls_ai_about_status" style="margin-left:10px;font-weight:500;"></span>
          <span id="myls_ai_about_progress" style="margin-left:4px;color:#787c82;font-size:13px;"></span>
        </div>

        <hr/>
        <div>
          <div class="myls-results-header">
            <h3 class="h5 mb-0"><i class="bi bi-terminal"></i> Results</h3>
            <button type="button" class="myls-btn-export-pdf" data-log-target="myls_ai_about_results"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
          </div>
          <pre id="myls_ai_about_results" class="myls-results-terminal">Ready.</pre>
          <div class="mt-2"><strong>Processed:</strong> <span id="myls_ai_about_count">0</span></div>
        </div>
      </div>
    </div>

    <?php
    add_action('admin_print_footer_scripts', function() use ($default_pt, $nonce) {
      if ( empty($_GET['page']) || $_GET['page'] !== 'aintelligize' ) return;

      if ( defined('MYLS_URL') )       $base_url = rtrim(MYLS_URL, '/');
      elseif ( function_exists('myls_plugin_base_url') ) $base_url = rtrim( myls_plugin_base_url(), '/' );
      else { $maybe_root = dirname(__DIR__, 2) . '/aintelligize.php'; $base_url = rtrim( plugins_url('', $maybe_root), '/' ); }

      $script_url = $base_url . '/assets/js/myls-ai-about.js';
      ?>
      <script>
      window.MYLS_AI_ABOUT = {
        ajaxurl: "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>",
        nonce:   "<?php echo esc_js( $nonce ); ?>",
        defaultType: "<?php echo esc_js( $default_pt ); ?>",
        model: "<?php echo esc_js( function_exists('myls_ai_get_default_model') ? myls_ai_get_default_model() : '' ); ?>",
        // Use the v2 endpoints explicitly:
        action_get_posts: "myls_ai_about_get_posts_v2",
        action_generate:  "myls_ai_about_generate_v2"
      };
      </script>
      <script src="<?php echo esc_url( $script_url . '?v=' . (defined('MYLS_VERSION') ? MYLS_VERSION : time()) ); ?>"></script>
      <?php
    });
  }
];
