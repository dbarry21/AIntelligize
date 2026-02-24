<?php if (!defined('ABSPATH')) exit;

$spec = [
  'id'    => 'video',
  'label' => 'Video',
  'order' => 40,

  'render'=> function () {
    $enabled = get_option('myls_schema_video_enabled','0');
    ?>
    <style>
      .myls-video-wrap { width:100%; }
      .myls-video-grid { display:flex; flex-wrap:wrap; gap:8px; align-items:stretch; }
      .myls-video-left  { flex:3 1 520px; min-width:320px; }
      .myls-video-right { flex:1 1 280px; min-width:260px; }

      .myls-block { background:#fff; border:1px solid #000; border-radius:1em; padding:12px; }
      .myls-block-title { font-weight:800; margin:0 0 8px; }

      .myls-video-wrap input[type="text"],
      .myls-video-wrap select {
        border:1px solid #000 !important; border-radius:1em !important; padding:.6rem .9rem; width:100%;
      }
      .form-label { font-weight:600; margin-bottom:.35rem; display:block; }
      .myls-actions { margin-top:10px; display:flex; gap:.5rem; flex-wrap:wrap; }
      .myls-btn { display:inline-block; font-weight:600; border:1px solid #000; padding:.45rem .9rem; border-radius:1em; background:#f8f9fa; color:#111; cursor:pointer; }
      .myls-btn-primary { background:#0d6efd; color:#fff; border-color:#0d6efd; }
      .myls-btn-outline { background:transparent; }
      .myls-btn:hover { filter:brightness(.97); }

      /* Simple toggle look */
      .myls-switch { display:flex; align-items:center; gap:.5rem; }
      .myls-switch input[type="checkbox"] { width:2.6rem; height:1.4rem; appearance:none; background:#ddd; border:1px solid #000; border-radius:999px; position:relative; outline:none; cursor:pointer; }
      .myls-switch input[type="checkbox"]::after { content:''; position:absolute; top:2px; left:2px; width:1rem; height:1rem; border-radius:50%; background:#fff; border:1px solid #000; transition:transform .15s ease; }
      .myls-switch input[type="checkbox"]:checked { background:#0d6efd; }
      .myls-switch input[type="checkbox"]:checked::after { transform:translateX(1.2rem); }
      .myls-switch .label { font-weight:600; }
    </style>

    <!-- IMPORTANT: no <form> here; this sits inside the main tab's form -->
    <div class="myls-video-wrap">
      <div class="myls-video-grid">
        <!-- LEFT: main card -->
        <div class="myls-video-left">
          <div class="myls-block">
            <div class="myls-block-title">Video Schema</div>

            <div class="myls-switch">
              <input type="checkbox" id="myls_schema_video_enabled" name="myls_schema_video_enabled" value="1" <?php checked('1',$enabled); ?>>
              <label class="label" for="myls_schema_video_enabled">Enable Video Schema</label>
            </div>

            <div class="myls-actions">
              <!-- This submit triggers the parent (main tab) form -->
              <button class="myls-btn myls-btn-primary" type="submit">Save</button>
              <details>
                <summary style="cursor:pointer">Debug</summary>
                <pre style="white-space:pre-wrap"><?php echo esc_html('enabled=' . $enabled); ?></pre>
              </details>
            </div>
          </div>
        </div>

        <!-- RIGHT: info card -->
        <div class="myls-video-right">
          <div class="myls-block">
            <div class="myls-block-title">Info</div>
            <p>When enabled, the module will add <code>VideoObject</code> schema to videos detected in content or to your Video CPT items.</p>
            <p>Thumbnails, durations, upload dates, and publishers will be derived from the video metadata where available.</p>
          </div>
        </div>
      </div>
    </div>
    <?php
  },

  'on_save'=> function () {
    if (
      ! isset($_POST['myls_schema_nonce']) ||
      ! wp_verify_nonce($_POST['myls_schema_nonce'],'myls_schema_save') ||
      ! current_user_can('manage_options')
    ) return;

    // Checkbox → "1" if present else "0"
    $val = isset($_POST['myls_schema_video_enabled']) ? '1' : '0';
    update_option('myls_schema_video_enabled', $val);
  }
];

if (defined('MYLS_SCHEMA_DISCOVERY') && MYLS_SCHEMA_DISCOVERY) return $spec;
return null;
