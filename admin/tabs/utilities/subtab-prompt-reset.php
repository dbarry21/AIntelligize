<?php if ( ! defined('ABSPATH') ) exit;

/**
 * Subtab: Reset AI Prompt Templates
 *
 * Deletes all saved prompt options from DB so every AI handler
 * falls back to the factory defaults in assets/prompts/*.txt.
 *
 * Option keys reset:
 *  - myls_ai_prompt_title            (Meta Title)
 *  - myls_ai_prompt_desc             (Meta Description)
 *  - myls_ai_prompt_excerpt          (WP Excerpt)
 *  - myls_ai_prompt_html_excerpt     (HTML Excerpt)
 *  - myls_ai_about_prompt_template   (About the Area)
 *  - myls_ai_faqs_prompt_template    (FAQs Builder legacy)
 *  - myls_ai_faqs_prompt_template_v2 (FAQs Builder v2)
 *  - myls_ai_geo_prompt_template     (GEO Rewrite)
 *  - myls_pb_prompt_template         (Page Builder)
 *  - myls_ai_taglines_prompt_template(Taglines)
 *  - myls_ai_llms_txt_prompt_template(llms.txt)
 */

/* -------------------------------------------------------------------------
 * AJAX handler
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_myls_reset_all_prompts', function(){
  if ( ! current_user_can('manage_options') ) {
    wp_send_json_error('Unauthorized', 403);
  }
  if ( empty($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'myls_reset_prompts') ) {
    wp_send_json_error('Bad nonce', 400);
  }

  $option_keys = [
    'myls_ai_prompt_title',
    'myls_ai_prompt_desc',
    'myls_ai_prompt_excerpt',
    'myls_ai_prompt_html_excerpt',
    'myls_ai_about_prompt_template',
    'myls_ai_faqs_prompt_template',
    'myls_ai_faqs_prompt_template_v2',
    'myls_ai_geo_prompt_template',
    'myls_pb_prompt_template',
    'myls_ai_taglines_prompt_template',
    'myls_ai_llms_txt_prompt_template',
  ];

  $deleted = 0;
  $details = [];
  foreach ( $option_keys as $key ) {
    $existed = get_option($key, '__MISSING__') !== '__MISSING__';
    if ( $existed ) {
      delete_option($key);
      $deleted++;
      $details[] = $key;
    }
  }

  wp_send_json_success([
    'deleted' => $deleted,
    'total'   => count($option_keys),
    'keys'    => $details,
    'message' => $deleted > 0
      ? "Deleted {$deleted} saved prompt(s). All handlers will now use factory defaults from assets/prompts/."
      : 'No saved prompts found — already using factory defaults.',
  ]);
});

/* -------------------------------------------------------------------------
 * Subtab spec
 * ------------------------------------------------------------------------- */
return [
  'id'    => 'prompt-reset',
  'label' => 'Prompt Reset',
  'order' => 80,
  'render'=> function() {
    $nonce = wp_create_nonce('myls_reset_prompts');
    $ajax  = admin_url('admin-ajax.php');

    // Build current status list
    $option_map = [
      'myls_ai_prompt_title'             => ['Meta Title',       'meta-title.txt'],
      'myls_ai_prompt_desc'              => ['Meta Description', 'meta-description.txt'],
      'myls_ai_prompt_excerpt'           => ['WP Excerpt',       'excerpt.txt'],
      'myls_ai_prompt_html_excerpt'      => ['HTML Excerpt',     'html-excerpt.txt'],
      'myls_ai_about_prompt_template'    => ['About the Area',   'about-area.txt'],
      'myls_ai_faqs_prompt_template_v2'  => ['FAQs Builder',     'faqs-builder.txt'],
      'myls_ai_geo_prompt_template'      => ['GEO Rewrite',      'geo-rewrite.txt'],
      'myls_pb_prompt_template'          => ['Page Builder',      'page-builder.txt'],
      'myls_ai_taglines_prompt_template' => ['Taglines',          'taglines.txt'],
      'myls_ai_llms_txt_prompt_template' => ['llms.txt Generator','llms-txt.txt'],
    ];
    ?>
    <h3 style="margin-top:0;">Reset AI Prompt Templates</h3>
    <p style="color:#555;">
      This tool deletes <strong>all saved prompt templates</strong> from the database. After reset, every AI handler
      will load its factory default from <code>assets/prompts/*.txt</code>. Use this after a plugin update
      to pick up improved prompt templates.
    </p>

    <table class="widefat striped" style="max-width:700px;">
      <thead>
        <tr>
          <th>Handler</th>
          <th>Default File</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ( $option_map as $key => $info ):
          $has_saved = get_option($key, '__MISSING__') !== '__MISSING__';
        ?>
        <tr>
          <td><strong><?php echo esc_html($info[0]); ?></strong></td>
          <td><code><?php echo esc_html($info[1]); ?></code></td>
          <td>
            <?php if ($has_saved): ?>
              <span style="color:#b32d2e;">● Custom (saved in DB)</span>
            <?php else: ?>
              <span style="color:#2e7d32;">● Using factory default</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div style="margin-top:16px;">
      <button id="myls_reset_prompts_btn" class="button" style="background:#b32d2e;border-color:#b32d2e;color:#fff;">
        <i class="bi bi-arrow-counterclockwise"></i> Reset All Prompts to Factory Defaults
      </button>
      <span id="myls_reset_prompts_status" style="margin-left:12px;font-weight:600;"></span>
    </div>

    <script>
    (function(){
      var btn = document.getElementById('myls_reset_prompts_btn');
      var status = document.getElementById('myls_reset_prompts_status');
      if (!btn) return;
      btn.addEventListener('click', function(e){
        e.preventDefault();
        if (!confirm('This will delete ALL saved prompt templates from the database.\n\nEvery AI handler will revert to factory defaults.\n\nContinue?')) return;
        btn.disabled = true;
        btn.textContent = 'Resetting…';
        status.textContent = '';

        var fd = new FormData();
        fd.append('action', 'myls_reset_all_prompts');
        fd.append('nonce', <?php echo json_encode($nonce); ?>);

        fetch(<?php echo json_encode($ajax); ?>, { method: 'POST', body: fd })
          .then(function(r){ return r.json(); })
          .then(function(r){
            if (r.success) {
              status.style.color = '#2e7d32';
              status.textContent = '✅ ' + r.data.message;
              // Refresh status dots after 1s
              setTimeout(function(){ location.reload(); }, 1500);
            } else {
              status.style.color = '#b32d2e';
              status.textContent = '❌ ' + (r.data || 'Unknown error');
            }
          })
          .catch(function(err){
            status.style.color = '#b32d2e';
            status.textContent = '❌ ' + err.message;
          })
          .finally(function(){
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i> Reset All Prompts to Factory Defaults';
          });
      });
    })();
    </script>
    <?php
  }
];
