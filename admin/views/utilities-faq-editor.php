<?php
/**
 * Utilities → FAQ Quick Editor (MYLS native, no ACF)
 *
 * Left column:
 *  - post type dropdown (all public)
 *  - search filter
 *  - multi-select list of posts
 *
 * Right column:
 *  - MYLS FAQ repeater editor for the currently active post
 *  - Save FAQs (AJAX)
 *  - Export to DOCX
 */
if ( ! defined('ABSPATH') ) exit;

$nonce = wp_create_nonce( defined('MYLS_UTIL_NONCE_ACTION') ? MYLS_UTIL_NONCE_ACTION : 'myls_utilities' );

$pts = get_post_types(['public' => true], 'objects');
unset($pts['attachment']);

// Sensible default
$default_pt = isset($pts['page']) ? 'page' : ( $pts ? array_key_first($pts) : 'page' );
?>

<div class="myls-util-faq-editor">
  <h2 class="mb-3">FAQ Quick Editor</h2>
  <p class="muted mb-3">Quickly view, edit, and export MYLS FAQs stored in <code>_myls_faq_items</code>. Works across all public post types.</p>

  <div class="row">
    <!-- Left: selector -->
    <div class="col-md-4">
      <div class="cardish">
        <label class="form-label" for="myls-fe-post-type">Post Type</label>
        <select id="myls-fe-post-type" class="form-select">
          <?php foreach ($pts as $pt => $obj): ?>
            <option value="<?php echo esc_attr($pt); ?>" <?php selected($pt, $default_pt); ?>><?php echo esc_html($obj->labels->singular_name); ?></option>
          <?php endforeach; ?>
        </select>

        <div class="mt-3">
          <label class="form-label" for="myls-fe-search">Search</label>
          <input type="text" id="myls-fe-search" class="form-control" placeholder="Type to filter...">
        </div>

        <div class="mt-3">
          <label class="form-label" for="myls-fe-posts">Posts</label>
          <select id="myls-fe-posts" class="form-control" multiple size="18" style="width:100%;">
            <option value="">Loading…</option>
          </select>
          <p class="muted mt-2" style="font-size:.92em;">Tip: select one or more posts to load their FAQs for quick editing. Use <strong>Save FAQs</strong> to batch-save all loaded posts in one click.</p>
        </div>
      </div>
    </div>

    <!-- Right: editor -->
    <div class="col-md-8">
      <div class="cardish">
        <div class="d-flex" style="justify-content:space-between; align-items:center; gap:.5rem; flex-wrap:wrap;">
          <div>
            <div class="form-label" style="margin:0;">Editing</div>
            <div id="myls-fe-active-title" style="font-weight:700;">Select one or more posts…</div>
            <div class="muted" id="myls-fe-active-meta" style="font-size:.92em;"></div>
          </div>
          <div class="d-flex" style="gap:.5rem; flex-wrap:wrap;">
            <button type="button" class="btn btn-primary" id="myls-fe-save" disabled>Save FAQs (Batch)</button>
            <button type="button" class="btn btn-outline-secondary" id="myls-fe-export" disabled>Export to DOCX</button>
          </div>
        </div>

        <hr class="mt-3 mb-3" style="border:0;height:1px;background:#ececec;">

        <div id="myls-fe-editor" class="myls-fe-editor" style="max-height:70vh; overflow-y:auto; padding-right:6px;">
          <div class="muted">No posts loaded.</div>
        </div>

        <input type="hidden" id="myls-fe-nonce" value="<?php echo esc_attr($nonce); ?>">
      </div>

      <div class="mt-3">
        <div class="myls-results-header">
          <strong>Results</strong>
          <button type="button" class="myls-btn-export-pdf" data-log-target="myls-fe-log"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
        </div>
        <pre id="myls-fe-log" class="myls-results-terminal">Ready.</pre>
      </div>
    </div>
  </div>
</div>
