<?php
/**
 * AI Subtab: Tagline Generator
 * File: admin/tabs/ai/subtab-taglines.php
 *
 * - Generates benefit-focused service taglines for ANY post type
 * - 2-column layout matching other AI subtabs
 * - Post type selector with search filters
 * - Batch processing with results log
 * - Editable prompt template
 * - Stores in _myls_service_tagline meta (creates field if doesn't exist)
 */

if (!defined('ABSPATH')) exit;

return [
  'id'    => 'taglines',
  'label' => 'Taglines (Generator)',
  'icon'  => 'bi-megaphone',
  'order' => 60,
  'render'=> function () {

    // ---------------------------------------------------------------------
    // Handle form save
    // ---------------------------------------------------------------------
    if (isset($_POST['myls_ai_taglines_save']) && 
        isset($_POST['myls_ai_taglines_save_nonce']) && 
        wp_verify_nonce($_POST['myls_ai_taglines_save_nonce'], 'myls_ai_taglines_save_nonce')) {
      
      update_option('myls_ai_taglines_prompt_template', wp_kses_post($_POST['myls_ai_taglines_prompt_template'] ?? ''));
      update_option('myls_ai_taglines_tokens', absint($_POST['myls_ai_taglines_tokens'] ?? 100));
      update_option('myls_ai_taglines_temperature', floatval($_POST['myls_ai_taglines_temperature'] ?? 0.7));
      
      echo '<div class="notice notice-success is-dismissible"><p>Tagline settings saved!</p></div>';
    }

    // ---------------------------------------------------------------------
    // Default prompt template
    // ---------------------------------------------------------------------
    $default_prompt = get_option('myls_ai_taglines_prompt_template', '');
    if (!is_string($default_prompt) || trim($default_prompt) === '') {
      $default_prompt = myls_get_default_prompt('taglines');
    }

    $prompt = get_option('myls_ai_taglines_prompt_template', $default_prompt);
    if (!is_string($prompt) || trim($prompt) === '') $prompt = $default_prompt;

    $tokens = (int) get_option('myls_ai_taglines_tokens', 300);
    $temp   = (float) get_option('myls_ai_taglines_temperature', 0.7);

    // ---------------------------------------------------------------------
    // Post type selector
    // ---------------------------------------------------------------------
    $pts = get_post_types(['public' => true], 'objects');
    unset($pts['attachment']);
    $default_pt = isset($pts['service']) ? 'service' : (isset($pts['page']) ? 'page' : ($pts ? array_key_first($pts) : 'page'));

    $nonce = wp_create_nonce('myls_ai_ops');
    ?>

<div class="myls-two-col">
  <!-- LEFT: Prompt Configuration -->
  <div class="myls-card">
    <div class="myls-card-header">
      <h2 class="myls-card-title">
        <i class="bi bi-chat-left-text"></i>
        Tagline Prompt Template
      </h2>
    </div>

    <form method="post">
      <?php wp_nonce_field('myls_ai_taglines_save_nonce','myls_ai_taglines_save_nonce'); ?>

      <div class="mb-3">
        <label class="form-label"><strong>Prompt Template</strong></label>
        <?php myls_prompt_toolbar('taglines', 'myls_ai_taglines_prompt_template'); ?>
        <textarea 
          id="myls_ai_taglines_prompt_template" 
          name="myls_ai_taglines_prompt_template" 
          class="widefat" 
          rows="18"
        ><?php echo esc_textarea($prompt); ?></textarea>
        <p class="description">
          Variables:
          <code>{{TITLE}}</code>,
          <code>{{CONTENT}}</code>,
          <code>{{CITY_STATE}}</code>,
          <code>{{BUSINESS_TYPE}}</code>
        </p>
      </div>

      <div class="row">
        <div class="col-6 mb-3">
          <label class="form-label">Max Tokens</label>
          <input 
            id="myls_ai_taglines_tokens" 
            type="number" 
            min="100" 
            max="500"
            name="myls_ai_taglines_tokens" 
            class="regular-text form-control" 
            value="<?php echo esc_attr($tokens); ?>" 
          />
          <div class="small text-muted mt-1">Recommended: 250-400 for 3-4 tagline options</div>
        </div>
        <div class="col-6 mb-3">
          <label class="form-label">Temperature</label>
          <input 
            id="myls_ai_taglines_temperature" 
            type="number" 
            step="0.1" 
            min="0" 
            max="2" 
            name="myls_ai_taglines_temperature" 
            class="regular-text form-control" 
            value="<?php echo esc_attr($temp); ?>" 
          />
          <div class="small text-muted mt-1">For benefit-focused copy, 0.6–0.8 works well</div>
        </div>
      </div>

      <div class="alert alert-info">
        <strong>💡 Tagline Tips:</strong>
        <ul class="mb-0" style="padding-left: 20px;">
          <li>Generates 3-4 tagline options as HTML list</li>
          <li>Start with customer benefit, not company name</li>
          <li>Include trust signals (licensed, certified)</li>
          <li>Add differentiators (24/7, same-day, emergency)</li>
          <li>Use pipe ( | ) to separate key points</li>
          <li>Keep each tagline under 120 characters</li>
        </ul>
      </div>

      <p><button type="submit" name="myls_ai_taglines_save" class="button button-primary">Save Template & Params</button></p>
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
          Processed: <span id="myls_ai_taglines_count">0</span>
        </div>
      </div>
    </div>

    <div class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="form-label">Post Type</label>
        <select id="myls_ai_taglines_pt" class="form-select">
          <?php foreach ($pts as $pt_key => $obj): ?>
            <option value="<?php echo esc_attr($pt_key); ?>" <?php selected($pt_key, $default_pt); ?>>
              <?php echo esc_html($obj->labels->singular_name . " ({$pt_key})"); ?>
            </option>
          <?php endforeach; ?>
        </select>

        <div class="mt-2">
          <label class="form-label" for="myls_ai_taglines_search">Search</label>
          <input
            type="text"
            id="myls_ai_taglines_search"
            class="form-control"
            placeholder="Type to filter posts…"
            autocomplete="off"
          />
          <div class="small text-muted mt-1">Filters the loaded post list (title or ID).</div>
        </div>
      </div>

      <div class="col-md-8">
        <label class="form-label">Posts</label>
        <select id="myls_ai_taglines_posts" class="form-select" multiple size="8" style="min-height:200px;"></select>
      </div>
    </div>

    <div class="mt-3 myls-actions">
      <button type="button" class="button" id="myls_ai_taglines_select_all">Select All</button>
      <button type="button" class="button" id="myls_ai_taglines_clear">Clear</button>
      <span class="small text-muted" id="myls_ai_taglines_loaded_hint"></span>
    </div>

    <div class="mt-3 myls-actions">
      <label style="display:flex;align-items:center;gap:8px;margin:0;">
        <input type="checkbox" id="myls_ai_taglines_skip_existing" checked>
        <strong>Skip posts with existing taglines</strong>
      </label>

      <label style="display:flex;align-items:center;gap:8px;margin:0;">
        <input type="checkbox" id="myls_ai_taglines_overwrite">
        <strong>Overwrite existing taglines</strong>
      </label>

      <button type="button" class="button button-primary" id="myls_ai_taglines_generate">
        <i class="bi bi-stars"></i> Generate Taglines
      </button>

      <button type="button" class="button" id="myls_ai_taglines_stop" disabled>
        <i class="bi bi-stop-circle"></i> Stop
      </button>

      <span class="myls-spinner" id="myls_ai_taglines_spinner">
        <span class="dashicons dashicons-update"></span>
        <span class="myls-text-small">Processing…</span>
      </span>

      <span id="myls_ai_taglines_status" class="myls-text-muted"></span>
    </div>

    <hr class="myls-divider"/>

    <div class="mb-3">
      <div class="myls-results-header">
        <label class="form-label mb-0"><strong><i class="bi bi-terminal"></i> Results Log</strong></label>
        <button type="button" class="myls-btn-export-pdf" data-log-target="myls_ai_taglines_log"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
      </div>
      <pre
        id="myls_ai_taglines_log" 
        class="myls-results-terminal"
      >Ready.</pre>
    </div>

    <div class="alert alert-warning">
      <strong>⚠️ Note:</strong> Taglines are saved to <code>_myls_service_tagline</code> meta field. This field works with ANY post type and will be created automatically if it doesn't exist.
    </div>
  </div>
</div>

<script>
jQuery(function($) {
  const nonce = <?php echo wp_json_encode($nonce); ?>;
  const promptTemplate = <?php echo wp_json_encode($prompt); ?>;
  const tokens = <?php echo intval($tokens); ?>;
  const temperature = <?php echo floatval($temp); ?>;

  let allPosts = [];
  let stopRequested = false;
  let processedCount = 0;

  const $pt = $('#myls_ai_taglines_pt');
  const $search = $('#myls_ai_taglines_search');
  const $posts = $('#myls_ai_taglines_posts');
  const $selectAll = $('#myls_ai_taglines_select_all');
  const $clear = $('#myls_ai_taglines_clear');
  const $loadedHint = $('#myls_ai_taglines_loaded_hint');
  const $generate = $('#myls_ai_taglines_generate');
  const $stop = $('#myls_ai_taglines_stop');
  const $spinner = $('#myls_ai_taglines_spinner');
  const $status = $('#myls_ai_taglines_status');
  const $log = $('#myls_ai_taglines_log');
  const $count = $('#myls_ai_taglines_count');
  const $skipExisting = $('#myls_ai_taglines_skip_existing');
  const $overwrite = $('#myls_ai_taglines_overwrite');

  // Load posts when post type changes
  $pt.on('change', loadPosts);

  // Filter posts as user types
  $search.on('input', filterPosts);

  // Select all visible posts
  $selectAll.on('click', function() {
    $posts.find('option:visible').prop('selected', true);
  });

  // Clear selection
  $clear.on('click', function() {
    $posts.val([]);
  });

  // Generate taglines
  $generate.on('click', generateTaglines);

  // Stop processing
  $stop.on('click', function() {
    stopRequested = true;
    $stop.prop('disabled', true);
    $status.text('Stopping...');
  });

  // Sync checkboxes
  $skipExisting.on('change', function() {
    if ($(this).is(':checked')) {
      $overwrite.prop('checked', false);
    }
  });

  $overwrite.on('change', function() {
    if ($(this).is(':checked')) {
      $skipExisting.prop('checked', false);
    }
  });

  // Load posts for current post type
  function loadPosts() {
    const postType = $pt.val();
    
    $posts.empty().append('<option disabled>Loading...</option>');
    $loadedHint.text('');

    $.post(ajaxurl, {
      action: 'myls_ai_taglines_get_posts',
      post_type: postType,
      nonce: nonce
    }, function(response) {
      if (response.success && Array.isArray(response.data)) {
        allPosts = response.data;
        renderPosts(allPosts);
        $loadedHint.text(`Loaded ${allPosts.length} posts`);
      } else {
        $posts.empty().append('<option disabled>Error loading posts</option>');
      }
    }).fail(function() {
      $posts.empty().append('<option disabled>Error loading posts</option>');
    });
  }

  // Render posts in select
  function renderPosts(posts) {
    $posts.empty();
    posts.forEach(function(post) {
      const hasTagline = post.has_tagline ? '✓' : '';
      const label = `${hasTagline} #${post.ID} - ${post.post_title}`;
      $posts.append($('<option>', {
        value: post.ID,
        text: label,
        'data-has-tagline': post.has_tagline ? '1' : '0'
      }));
    });
  }

  // Filter posts by search
  function filterPosts() {
    const query = $search.val().toLowerCase();
    
    if (!query) {
      renderPosts(allPosts);
      return;
    }

    const filtered = allPosts.filter(function(post) {
      return post.post_title.toLowerCase().includes(query) || 
             String(post.ID).includes(query);
    });

    renderPosts(filtered);
  }

  // Generate taglines for selected posts
  async function generateTaglines() {
    const selectedIds = $posts.val();
    
    if (!selectedIds || selectedIds.length === 0) {
      alert('Please select at least one post');
      return;
    }

    const skipExisting = $skipExisting.is(':checked');
    const overwrite = $overwrite.is(':checked');

    // Filter out posts with existing taglines if skip is enabled
    let postsToProcess = selectedIds.map(id => {
      const post = allPosts.find(p => p.ID == id);
      return post;
    }).filter(Boolean);

    if (skipExisting && !overwrite) {
      postsToProcess = postsToProcess.filter(p => !p.has_tagline);
      
      if (postsToProcess.length === 0) {
        alert('All selected posts already have taglines. Uncheck "Skip posts with existing taglines" to regenerate.');
        return;
      }
    }

    // Reset state
    stopRequested = false;
    processedCount = 0;
    $count.text('0');
    $log.text('Starting tagline generation...');

    // UI state
    $generate.prop('disabled', true);
    $stop.prop('disabled', false);
    $spinner.show();
    $status.text(`Processing ${postsToProcess.length} posts...`);

    // Process posts sequentially
    for (let i = 0; i < postsToProcess.length; i++) {
      if (stopRequested) {
        logMessage('❌ Stopped by user', 'error');
        break;
      }

      const post = postsToProcess[i];
      await processPost(post, i + 1, postsToProcess.length);
    }

    // Done
    $generate.prop('disabled', false);
    $stop.prop('disabled', true);
    $spinner.hide();
    $status.text('');
    
    if (!stopRequested) {
      logMessage(`✅ Complete! Processed ${processedCount} posts`, 'success');
    }

    // Reload posts to update checkmarks
    loadPosts();
  }

  // Process single post
  async function processPost(post, index, total) {
    logMessage(`[${index}/${total}] Processing: ${post.post_title} (ID: ${post.ID})...`, 'info');

    try {
      const result = await $.post(ajaxurl, {
        action: 'myls_ai_taglines_generate_single',
        post_id: post.ID,
        prompt_template: promptTemplate,
        tokens: tokens,
        temperature: temperature,
        nonce: nonce
      });

      if (result.success) {
        const primaryTagline = result.data.tagline || '';
        const allTaglines = result.data.all_taglines || [primaryTagline];
        const charCount = result.data.char_count || primaryTagline.length;
        const status = charCount > 120 ? '⚠️' : '✓';
        
        // Log primary tagline (the one saved)
        logMessage(
          `${status} #${post.ID} SAVED: "${primaryTagline}" (${charCount} chars)`,
          charCount > 120 ? 'warning' : 'success'
        );
        
        // Log additional options if multiple taglines were generated
        if (allTaglines.length > 1) {
          logMessage(`   📋 Generated ${allTaglines.length} options:`, 'info');
          allTaglines.forEach((tagline, i) => {
            const chars = tagline.length;
            const indicator = chars > 120 ? '⚠️' : '•';
            const style = i === 0 ? 'success' : 'info';
            logMessage(
              `   ${indicator} Option ${i + 1}: "${tagline}" (${chars} chars)`,
              style
            );
          });
        }
        
        processedCount++;
        $count.text(processedCount);
      } else {
        logMessage(`❌ #${post.ID}: ${result.data?.message || 'Generation failed'}`, 'error');
      }
    } catch (error) {
      logMessage(`❌ #${post.ID}: Network error`, 'error');
    }

    // Small delay to avoid rate limits
    await new Promise(resolve => setTimeout(resolve, 500));
  }

  // Log message (terminal-style plain text)
  function logMessage(message, type = 'info') {
    var el = $log[0];
    if (!el) return;
    var current = el.textContent || '';
    if (current === 'Ready.' || current === 'Starting tagline generation...') {
      el.textContent = message + '\n';
    } else {
      el.textContent += message + '\n';
    }
    el.scrollTop = el.scrollHeight;
  }

  // Initial load
  loadPosts();
});
</script>

<?php
  },
];
