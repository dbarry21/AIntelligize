<?php
/**
 * Admin Tab: Custom Post Types (Modular CPT Manager)
 */

if ( ! defined('ABSPATH') ) exit;

myls_register_admin_tab([
  'id'    => 'cpt',
  'title' => 'Custom Post Types',
  'order' => 15,
  'cap'   => 'manage_options',
  'icon'  => 'dashicons-screenoptions',
  'cb'    => function () {

    // -------------------------------------------------
    // Discover CPT modules
    // -------------------------------------------------
    $cpt_specs = [];
    $debug     = [];

    $cpt_dir = trailingslashit( MYLS_PATH ) . 'modules/cpt';
    if ( ! is_dir($cpt_dir) ) {
        echo '<div class="alert alert-warning mt-3"><strong>Missing folder:</strong> <code>modules/cpt</code> under plugin root.</div>';
        return;
    }

    if ( ! defined('MYLS_CPT_DISCOVERY') ) {
        define('MYLS_CPT_DISCOVERY', true);
    }

    $files = glob( $cpt_dir . '/*.php' );
    if ( ! empty($files) ) {
        natsort($files);

        $skip_exact  = ['register.php','_bootstrap.php','_loader.php'];
        $skip_suffix = ['-columns.php','-metaboxes.php','-taxonomies.php','-templates.php'];

        foreach ( $files as $f ) {
            $base = basename($f);

            if ( in_array($base, $skip_exact, true) ) { $debug[] = "Skipped (helper): modules/cpt/{$base}"; continue; }
            $is_extra = false;
            foreach ( $skip_suffix as $suf ) { if ( str_ends_with($base, $suf) ) { $is_extra = true; break; } }
            if ( $is_extra ) { $debug[] = "Skipped (extra): modules/cpt/{$base}"; continue; }

            $spec = include $f;
            if ( is_array($spec) && ! empty($spec['id']) ) {
                $cpt_specs[$spec['id']] = $spec;
                $debug[] = "OK: modules/cpt/{$base}";
            } else {
                $debug[] = "No spec returned: modules/cpt/{$base}";
            }
        }
    }

    if ( empty($cpt_specs) ) {
        echo '<div class="alert alert-warning mt-3"><strong>No CPT modules discovered.</strong> Add files like <code>modules/cpt/video.php</code> that return a spec when <code>MYLS_CPT_DISCOVERY</code> is defined.</div>';
        if ( $debug ) {
            echo '<pre class="mt-2 p-2 bg-light border rounded small">'. esc_html(implode("\n", $debug)) .'</pre>';
        }
        return;
    }

    // -------------------------------------------------
    // Handle POST save (CPTs + Blog Prefix card)
    // -------------------------------------------------
    if (
        isset($_POST['myls_cpt_nonce']) &&
        wp_verify_nonce( $_POST['myls_cpt_nonce'], 'myls_cpt_save' ) &&
        current_user_can('manage_options')
    ) {
        // Save CPT options
        foreach ( $cpt_specs as $id => $spec ) {
            $opt_key = "myls_enable_{$id}_cpt";

            $enabled = isset($_POST[$opt_key]) ? '1' : '0';
            $slug    = isset($_POST["{$opt_key}_slug"]) ? sanitize_title( wp_unslash($_POST["{$opt_key}_slug"]) ) : '';
            $arch    = isset($_POST["{$opt_key}_hasarchive"]) ? sanitize_text_field( wp_unslash($_POST["{$opt_key}_hasarchive"]) ) : '';
            $label_sing = isset($_POST["{$opt_key}_label_singular"]) ? sanitize_text_field( wp_unslash($_POST["{$opt_key}_label_singular"]) ) : '';

            update_option($opt_key, $enabled);
            update_option("{$opt_key}_slug", $slug);
            update_option("{$opt_key}_hasarchive", $arch);
            update_option("{$opt_key}_label_singular", $label_sing);
        }

        // Save Blog Prefix options
        $bp_enabled = isset($_POST['myls_blogprefix_enabled']) ? '1' : '0';
        $bp_value   = isset($_POST['myls_blogprefix_value'])
            ? sanitize_title( wp_unslash($_POST['myls_blogprefix_value']) )
            : '';

        update_option('myls_blogprefix_enabled', $bp_enabled);
        update_option('myls_blogprefix_value',   $bp_value);

        flush_rewrite_rules();
        do_action('myls_cpt_settings_updated');

        echo '<div class="alert alert-success mt-3">Settings saved.</div>';
    }

    // -------------------------------------------------
    // Load settings
    // -------------------------------------------------
    $settings = [];
    foreach ( $cpt_specs as $id => $spec ) {
        $opt_key = "myls_enable_{$id}_cpt";
        $settings[$id] = [
            'enabled'        => get_option($opt_key, '0'),
            'slug'           => get_option("{$opt_key}_slug", ''),
            'has_archive'    => get_option("{$opt_key}_hasarchive", ''),
            'label_singular' => get_option("{$opt_key}_label_singular", ''),
        ];
    }
    $blogprefix = [
        'enabled' => get_option('myls_blogprefix_enabled', '0'),
        'value'   => get_option('myls_blogprefix_value',   ''),
    ];

    $human = function($s){ $s = str_replace(['-','_'],' ',$s); return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8'); };

    ?>
    <div class="container-fluid mt-3">
        <style>
          /* Light-blue placeholder text to signal "not entered" */
          .card .form-control::placeholder {
            color: rgba(13,110,253,0.65); /* Bootstrap primary ~ #0d6efd */
            opacity: 1;
          }
        </style>

        <?php if ( ! empty($debug) ) : ?>
            <details class="mb-3">
                <summary class="small text-muted">Discovery debug</summary>
                <pre class="mt-2 p-2 bg-light border rounded small"><?php echo esc_html(implode("\n", $debug)); ?></pre>
            </details>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('myls_cpt_save', 'myls_cpt_nonce'); ?>

            <div class="d-flex gap-2 mb-3">
                <button type="submit" class="btn btn-primary">Save Settings</button>
                <button type="button" class="btn btn-outline-secondary" id="myls-flush-rewrites">Flush Rewrites (AJAX)</button>
            </div>

            <div class="row">
                <?php foreach ( $cpt_specs as $id => $spec ) :
                    $s = $settings[$id];
                    $label    = !empty($spec['label']) ? $spec['label'] : $human($id);
                    $defaults = $spec['defaults'] ?? [];
                    $ph_slug  = $defaults['default_slug']    ?? $id;
                    $ph_arch  = $defaults['default_archive'] ?? "{$id}s";

                    // Defaults for labels from module spec (used as placeholders if no custom value)
                    $default_singular = $spec['labels']['singular'] ?? ($defaults['labels']['singular'] ?? $human(rtrim($id,'s')));
                    $default_plural   = $spec['labels']['name']     ?? ($defaults['labels']['name'] ?? $human(rtrim($id,'s')).'s');

                    $toggle   = "myls_enable_{$id}_cpt";
                ?>
                <div class="col-lg-4">
                    <div class="card mb-4 shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <strong><?php echo esc_html($label); ?></strong>
                        </div>
                        <div class="card-body">
                            <div class="form-check form-switch mb-3">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                    id="<?php echo esc_attr($toggle); ?>"
                                    name="<?php echo esc_attr($toggle); ?>"
                                    value="1"
                                    <?php checked('1', $s['enabled']); ?>
                                >
                                <label class="form-check-label" for="<?php echo esc_attr($toggle); ?>">
                                    Enable <strong><?php echo esc_html($label); ?></strong>
                                </label>
                            </div>

                            <div class="mb-3">
                                <label for="<?php echo esc_attr("{$toggle}_label_singular"); ?>" class="form-label">Singular Label</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="<?php echo esc_attr("{$toggle}_label_singular"); ?>"
                                    name="<?php echo esc_attr("{$toggle}_label_singular"); ?>"
                                    value="<?php echo esc_attr($s['label_singular']); ?>"
                                    placeholder="<?php echo esc_attr($default_singular); ?>"
                                >
                                <div class="form-text">Plural label is automatic based on singular.</div>
                            </div>

                            <div class="mb-3">
                                <label for="<?php echo esc_attr("{$toggle}_hasarchive"); ?>" class="form-label">Has Archive</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="<?php echo esc_attr("{$toggle}_hasarchive"); ?>"
                                    name="<?php echo esc_attr("{$toggle}_hasarchive"); ?>"
                                    value="<?php echo esc_attr($s['has_archive']); ?>"
                                    placeholder="<?php echo esc_attr($ph_arch); ?>"
                                >
                                <div class="form-text">Leave blank to disable archive.</div>
                            </div>

                            <div class="mb-3">
                                <label for="<?php echo esc_attr("{$toggle}_slug"); ?>" class="form-label">Slug</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="<?php echo esc_attr("{$toggle}_slug"); ?>"
                                    name="<?php echo esc_attr("{$toggle}_slug"); ?>"
                                    value="<?php echo esc_attr($s['slug']); ?>"
                                    placeholder="<?php echo esc_attr($ph_slug); ?>"
                                >
                                <div class="form-text">URL base (no slashes). Blank uses default.</div>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-info btn-sm" data-cpt="<?php echo esc_attr($id); ?>">
                                    Check Registered (AJAX)
                                </button>
                                <span class="align-self-center small text-muted" id="myls-cpt-status-<?php echo esc_attr($id); ?>"></span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Custom Blog Prefix card (unchanged from your last version) -->
                <div class="col-lg-4">
                    <div class="card mb-4 shadow-sm">
                        <div class="card-header bg-dark text-white">
                            <strong>Custom Blog Prefix</strong>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-3">
                                Applies a custom prefix to standard post permalinks (e.g., <code>/prefix/post-name/</code>).
                                When enabled, the plugin also redirects old <code>/post-name/</code> to the prefixed URL and sets the canonical accordingly.
                            </p>

                            <div class="form-check form-switch mb-3">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                    id="myls_blogprefix_enabled"
                                    name="myls_blogprefix_enabled"
                                    value="1"
                                    <?php checked('1', $blogprefix['enabled']); ?>
                                >
                                <label class="form-check-label" for="myls_blogprefix_enabled">
                                    Enable Custom Blog Prefix
                                </label>
                            </div>

                            <div class="mb-3">
                                <label for="myls_blogprefix_value" class="form-label">Prefix (no slashes)</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="myls_blogprefix_value"
                                    name="myls_blogprefix_value"
                                    value="<?php echo esc_attr($blogprefix['value']); ?>"
                                    placeholder="hvac-blog"
                                >
                                <div class="form-text">
                                    Example result: <code>https://yoursite.com/<span id="myls-bp-prev">hvac-blog</span>/your-post/</code>
                                </div>
                            </div>

                            <div class="alert alert-info py-2">
                                <div class="small mb-1"><strong>Note:</strong> After changing this value, flush rewrites.</div>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="myls-flush-rewrites-2">Flush Rewrites</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Save Settings</button>
        </form>
    </div>

    <script>
    (function(){
      const ajaxurl = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
      const nonce   = "<?php echo esc_js( wp_create_nonce('myls_cpt_ajax') ); ?>";

      // Flush rewrites (top button)
      const flushBtn = document.getElementById('myls-flush-rewrites');
      if (flushBtn) {
        flushBtn.addEventListener('click', function(){
          flushBtn.disabled = true;
          fetch(ajaxurl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: new URLSearchParams({ action: 'myls_flush_rewrites', _wpnonce: nonce })
          })
          .then(r=>r.json())
          .then(resp=>{
            alert((resp && resp.success && resp.data && resp.data.message) ? resp.data.message : 'Flushed.');
          })
          .catch(()=>alert('Request failed'))
          .finally(()=>flushBtn.disabled=false);
        });
      }

      // Flush rewrites (card button)
      const flushBtn2 = document.getElementById('myls-flush-rewrites-2');
      if (flushBtn2) {
        flushBtn2.addEventListener('click', function(){
          flushBtn2.disabled = true;
          fetch(ajaxurl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: new URLSearchParams({ action: 'myls_flush_rewrites', _wpnonce: nonce })
          })
          .then(r=>r.json())
          .then(resp=>{
            alert((resp && resp.success && resp.data && resp.data.message) ? resp.data.message : 'Flushed.');
          })
          .catch(()=>alert('Request failed'))
          .finally(()=>flushBtn2.disabled=false);
        });
      }

      // Live preview of example URL
      const input = document.getElementById('myls_blogprefix_value');
      const prev  = document.getElementById('myls-bp-prev');
      if (input && prev) {
        const upd = () => {
          const v = (input.value || '').trim().replace(/^\/+|\/+$/g,'');
          prev.textContent = v || 'prefix';
        };
        input.addEventListener('input', upd);
        upd();
      }

      // Check CPT registered (existing)
      function showDebug(el, payload){
        const wrapId = el.id + '--debugwrap';
        let wrap = document.getElementById(wrapId);
        if (!wrap) {
          wrap = document.createElement('details');
          wrap.id = wrapId;
          wrap.className = 'mt-2';
          const summary = document.createElement('summary');
          summary.className = 'small text-muted';
          summary.textContent = 'View debug payload';
          const pre = document.createElement('pre');
          pre.className = 'mt-2 p-2 bg-light border rounded small';
          pre.style.maxHeight = '250px';
          pre.style.overflow = 'auto';
          wrap.appendChild(summary);
          wrap.appendChild(pre);
          el.parentNode.appendChild(wrap);
        }
        const pre = wrap.querySelector('pre');
        pre.textContent = JSON.stringify(payload, null, 2);
      }

      document.querySelectorAll('button[data-cpt]').forEach(btn=>{
        btn.addEventListener('click', function(){
          const id  = btn.getAttribute('data-cpt');
          const out = document.getElementById('myls-cpt-status-'+id);
          if (out) out.textContent = 'Checking...';

          fetch(ajaxurl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: new URLSearchParams({ action: 'myls_check_cpt', cpt: id, _wpnonce: nonce })
          })
          .then(r=>r.json())
          .then(data=>{
            const ok = data && data.success;
            const payload = ok ? data.data : null;
            if (!out) return;

            out.textContent = payload.registered
              ? `Registered ✅ (${payload.resolved_id || id})`
              : 'Not registered ❌';

            showDebug(out, payload);
          })
          .catch(()=>{ if (out) out.textContent = 'Error'; });
        });
      });
    })();
    </script>

    <?php
  },
]);
