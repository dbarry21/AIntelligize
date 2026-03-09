<?php if (!defined('ABSPATH')) exit;

$spec = [
  'id'    => 'video',
  'label' => 'Video',
  'order' => 40,

  'render'=> function () {
    $enabled  = get_option('myls_schema_video_enabled','0');
    $entries  = get_option('myls_video_entries', []);
    if ( ! is_array($entries) ) $entries = [];

    // Check for duplicate names
    $names = array_map( function($e) { return trim($e['name'] ?? ''); }, $entries );
    $names = array_filter($names, function($n) { return $n !== ''; });
    $dupes = array_keys( array_filter( array_count_values($names), function($c) { return $c > 1; } ) );
    ?>
    <style>
      .myls-video-wrap { width:100%; }
      .myls-video-grid { display:flex; flex-wrap:wrap; gap:8px; align-items:stretch; }
      .myls-video-left  { flex:3 1 520px; min-width:320px; }
      .myls-video-right { flex:1 1 280px; min-width:260px; }

      .myls-block { background:#fff; border:1px solid #000; border-radius:1em; padding:12px; }
      .myls-block-title { font-weight:800; margin:0 0 8px; }

      .myls-video-wrap input[type="text"],
      .myls-video-wrap textarea,
      .myls-video-wrap select {
        border:1px solid #000 !important; border-radius:1em !important; padding:.6rem .9rem; width:100%;
      }
      .myls-video-wrap textarea { min-height:80px; font-size:13px; }
      .form-label { font-weight:600; margin-bottom:.35rem; display:block; }
      .myls-actions { margin-top:10px; display:flex; gap:.5rem; flex-wrap:wrap; }
      .myls-btn { display:inline-block; font-weight:600; border:1px solid #000; padding:.45rem .9rem; border-radius:1em; background:#f8f9fa; color:#111; cursor:pointer; }
      .myls-btn-primary { background:#0d6efd; color:#fff; border-color:#0d6efd; }
      .myls-btn-outline { background:transparent; }
      .myls-btn-sm { padding:.3rem .7rem; font-size:13px; }
      .myls-btn-danger { background:#dc3545; color:#fff; border-color:#dc3545; }
      .myls-btn:hover { filter:brightness(.97); }

      .myls-switch { display:flex; align-items:center; gap:.5rem; }
      .myls-switch input[type="checkbox"] { width:2.6rem; height:1.4rem; appearance:none; background:#ddd; border:1px solid #000; border-radius:999px; position:relative; outline:none; cursor:pointer; }
      .myls-switch input[type="checkbox"]::after { content:''; position:absolute; top:2px; left:2px; width:1rem; height:1rem; border-radius:50%; background:#fff; border:1px solid #000; transition:transform .15s ease; }
      .myls-switch input[type="checkbox"]:checked { background:#0d6efd; }
      .myls-switch input[type="checkbox"]:checked::after { transform:translateX(1.2rem); }
      .myls-switch .label { font-weight:600; }

      .myls-video-entry { background:#f8f9fa; border:1px solid #ddd; border-radius:.75em; padding:12px; margin-bottom:12px; }
      .myls-video-entry h4 { margin:0 0 8px; font-size:15px; font-weight:700; }
      .myls-dupe-warning { color:#dc3545; font-weight:600; font-size:13px; margin-top:4px; }
    </style>

    <!-- IMPORTANT: no <form> here; this sits inside the main tab's form -->
    <div class="myls-video-wrap">
      <div class="myls-video-grid">
        <!-- LEFT: main card -->
        <div class="myls-video-left">
          <div class="myls-block">
            <div class="myls-block-title">Video Schema</div>

            <div class="myls-switch" style="margin-bottom:12px">
              <input type="checkbox" id="myls_schema_video_enabled" name="myls_schema_video_enabled" value="1" <?php checked('1',$enabled); ?>>
              <label class="label" for="myls_schema_video_enabled">Enable Video Schema</label>
            </div>

            <hr>

            <div class="myls-block-title" style="margin-top:8px">Video Entries</div>
            <p style="font-size:13px;color:#666;margin:0 0 8px">
              Add per-video names and transcripts. These are used in VideoObject schema output.
            </p>

            <div id="myls-video-entries">
              <?php foreach ( $entries as $i => $entry ) :
                $vid   = sanitize_text_field( $entry['video_id'] ?? '' );
                $name  = sanitize_text_field( $entry['name'] ?? '' );
                $trans = sanitize_textarea_field( $entry['transcript'] ?? '' );
                $is_dupe = in_array( $name, $dupes, true ) && $name !== '';
              ?>
              <div class="myls-video-entry" data-index="<?php echo $i; ?>">
                <h4>Video #<?php echo $i + 1; ?></h4>

                <label class="form-label">YouTube Video ID</label>
                <input type="text" name="myls_video_entries[<?php echo $i; ?>][video_id]"
                  value="<?php echo esc_attr($vid); ?>"
                  placeholder="e.g. 135FeqUY_jo" style="margin-bottom:8px">

                <label class="form-label">Video Title (for schema)</label>
                <input type="text" name="myls_video_entries[<?php echo $i; ?>][name]"
                  value="<?php echo esc_attr($name); ?>"
                  placeholder="e.g. How We Remove Mold from Apollo Beach Driveways" style="margin-bottom:4px">
                <?php if ( $is_dupe ) : ?>
                  <div class="myls-dupe-warning">Warning: duplicate video name. AI knowledge graphs treat these as one signal.</div>
                <?php endif; ?>

                <label class="form-label" style="margin-top:8px">Transcript</label>
                <textarea name="myls_video_entries[<?php echo $i; ?>][transcript]"
                  placeholder="Paste or fetch transcript..."
                  id="myls_video_transcript_<?php echo $i; ?>"><?php echo esc_textarea($trans); ?></textarea>
                <div style="margin-top:6px;display:flex;gap:6px;align-items:center">
                  <button type="button" class="myls-btn myls-btn-outline myls-btn-sm myls-fetch-transcript"
                    data-index="<?php echo $i; ?>">Fetch Transcript</button>
                  <span class="myls-fetch-status" data-index="<?php echo $i; ?>" style="font-size:12px;color:#666"></span>
                </div>

                <div style="margin-top:8px">
                  <button type="button" class="myls-btn myls-btn-danger myls-btn-sm myls-remove-video"
                    data-index="<?php echo $i; ?>">Remove</button>
                </div>
              </div>
              <?php endforeach; ?>
            </div>

            <button type="button" class="myls-btn myls-btn-outline" id="myls-add-video-entry"
              style="margin-bottom:12px">+ Add Video Entry</button>

            <div class="myls-actions">
              <button class="myls-btn myls-btn-primary" type="submit">Save</button>
              <details>
                <summary style="cursor:pointer">Debug</summary>
                <pre style="white-space:pre-wrap"><?php echo esc_html('enabled=' . $enabled . ', entries=' . count($entries)); ?></pre>
              </details>
            </div>
          </div>
        </div>

        <!-- RIGHT: info card -->
        <div class="myls-video-right">
          <div class="myls-block">
            <div class="myls-block-title">Info</div>
            <p>When enabled, the module will add <code>VideoObject</code> schema to videos detected in content or to your Video CPT items.</p>
            <p>Add video entries below to provide unique <strong>names</strong> and <strong>transcripts</strong> for each video.</p>
            <p style="font-size:13px;color:#666">Thumbnails, durations, upload dates, and publishers are derived from video metadata automatically.</p>
          </div>
        </div>
      </div>
    </div>

    <script>
    (function(){
      var wrap = document.getElementById('myls-video-entries');
      var nextIndex = <?php echo count($entries); ?>;

      // Add entry
      document.getElementById('myls-add-video-entry').addEventListener('click', function(){
        var div = document.createElement('div');
        div.className = 'myls-video-entry';
        div.dataset.index = nextIndex;
        div.innerHTML =
          '<h4>Video #' + (nextIndex + 1) + '</h4>' +
          '<label class="form-label">YouTube Video ID</label>' +
          '<input type="text" name="myls_video_entries[' + nextIndex + '][video_id]" placeholder="e.g. 135FeqUY_jo" style="margin-bottom:8px">' +
          '<label class="form-label">Video Title (for schema)</label>' +
          '<input type="text" name="myls_video_entries[' + nextIndex + '][name]" placeholder="e.g. How We Remove Mold from Apollo Beach Driveways" style="margin-bottom:8px">' +
          '<label class="form-label">Transcript</label>' +
          '<textarea name="myls_video_entries[' + nextIndex + '][transcript]" placeholder="Paste or fetch transcript..." id="myls_video_transcript_' + nextIndex + '"></textarea>' +
          '<div style="margin-top:6px;display:flex;gap:6px;align-items:center">' +
            '<button type="button" class="myls-btn myls-btn-outline myls-btn-sm myls-fetch-transcript" data-index="' + nextIndex + '">Fetch Transcript</button>' +
            '<span class="myls-fetch-status" data-index="' + nextIndex + '" style="font-size:12px;color:#666"></span>' +
          '</div>' +
          '<div style="margin-top:8px">' +
            '<button type="button" class="myls-btn myls-btn-danger myls-btn-sm myls-remove-video" data-index="' + nextIndex + '">Remove</button>' +
          '</div>';
        wrap.appendChild(div);
        nextIndex++;
      });

      // Remove entry (delegate)
      wrap.addEventListener('click', function(e){
        if (e.target.classList.contains('myls-remove-video')) {
          e.target.closest('.myls-video-entry').remove();
        }
      });

      // Fetch transcript (delegate)
      wrap.addEventListener('click', function(e){
        if (!e.target.classList.contains('myls-fetch-transcript')) return;
        var idx = e.target.dataset.index;
        var entry = e.target.closest('.myls-video-entry');
        var vidInput = entry.querySelector('input[name*="[video_id]"]');
        var textarea = document.getElementById('myls_video_transcript_' + idx);
        var status = entry.querySelector('.myls-fetch-status[data-index="' + idx + '"]');

        var videoId = vidInput ? vidInput.value.trim() : '';
        if (!videoId) { status.textContent = 'Enter a video ID first.'; return; }

        status.textContent = 'Fetching...';
        e.target.disabled = true;

        var fd = new FormData();
        fd.append('action', 'myls_fetch_youtube_transcript');
        fd.append('video_id', videoId);
        fd.append('_nonce', '<?php echo wp_create_nonce("myls_fetch_transcript"); ?>');

        fetch(ajaxurl, { method: 'POST', body: fd })
          .then(function(r){ return r.json(); })
          .then(function(resp){
            e.target.disabled = false;
            if (resp.success && resp.data && resp.data.transcript) {
              textarea.value = resp.data.transcript;
              status.textContent = 'Fetched!';
            } else {
              status.textContent = (resp.data && resp.data.message) || 'No captions found.';
            }
          })
          .catch(function(){
            e.target.disabled = false;
            status.textContent = 'Network error.';
          });
      });
    })();
    </script>
    <?php
  },

  'on_save'=> function () {
    if (
      ! isset($_POST['myls_schema_nonce']) ||
      ! wp_verify_nonce($_POST['myls_schema_nonce'],'myls_schema_save') ||
      ! current_user_can('manage_options')
    ) return;

    // Checkbox
    $val = isset($_POST['myls_schema_video_enabled']) ? '1' : '0';
    update_option('myls_schema_video_enabled', $val);

    // Video entries
    $raw = $_POST['myls_video_entries'] ?? [];
    $entries = [];
    if ( is_array($raw) ) {
      foreach ( $raw as $entry ) {
        if ( ! is_array($entry) ) continue;
        $vid  = sanitize_text_field( $entry['video_id'] ?? '' );
        $name = sanitize_text_field( $entry['name'] ?? '' );
        $trans = sanitize_textarea_field( $entry['transcript'] ?? '' );
        // Keep entry if it has at least a video_id or name
        if ( $vid !== '' || $name !== '' ) {
          $entries[] = [
            'video_id'   => $vid,
            'name'       => $name,
            'transcript' => $trans,
          ];
        }
      }
    }
    update_option('myls_video_entries', $entries);
  }
];

if (defined('MYLS_SCHEMA_DISCOVERY') && MYLS_SCHEMA_DISCOVERY) return $spec;
return null;
