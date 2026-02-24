<?php if ( ! defined('ABSPATH') ) exit;

/**
 * Subtab: Meta History
 * Path: admin/tabs/meta/subtab-history.php
 *
 * UI:
 * - Left column: Post Type → Search Filter → Post
 * - Right column: results table + export CSV + clear history
 */

return [
  'id'    => 'history',
  'label' => 'Meta History',
  'render'=> function () {
    // Gather public post types for selector
    $pts = get_post_types( ['public' => true], 'objects' );
    $default_pt = isset($pts['page']) ? 'page' : array_key_first($pts);
    ?>
    <div class="myls-two-col" style="display:grid;grid-template-columns:1fr 2fr;gap:20px;">
      <!-- Left column -->
      <div class="myls-left" style="border:1px solid #000;padding:16px;border-radius:1em;">
        <div class="myls-section-title h5 mb-3"><i class="bi bi-clock-history"></i> Meta Tag Change History</div>

        <!-- Post Type -->
        <div class="mb-3">
          <label class="form-label" for="myls_mh_pt">Post Type</label>
          <select id="myls_mh_pt" class="form-select">
            <?php foreach ($pts as $pt): ?>
              <option value="<?php echo esc_attr($pt->name); ?>" <?php selected($pt->name, $default_pt); ?>>
                <?php echo esc_html($pt->labels->singular_name ?? $pt->label ?? $pt->name); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Search Filter (NEW) -->
        <div class="mb-3">
          <label class="form-label" for="myls_mh_filter">Search</label>
          <input id="myls_mh_filter"
                 type="text"
                 class="form-control"
                 placeholder="Type to filter posts by title…"
                 autocomplete="off"
                 aria-describedby="myls_mh_filter_help">
          <small id="myls_mh_filter_help" class="text-muted">
            Starts filtering as you type. Matches anywhere in the title (case-insensitive).
          </small>
        </div>

        <!-- Post Select -->
        <div class="mb-3">
          <label class="form-label" for="myls_mh_post">Post</label>
          <select id="myls_mh_post" class="form-select">
            <option value="">— Select a post —</option>
          </select>
          <small class="text-muted">List populates after selecting a post type. Use the search box to filter.</small>
        </div>

        <div class="d-flex gap-2">
          <button id="myls_mh_load" class="button button-primary">
            <i class="bi bi-search"></i> Load History
          </button>
          <button id="myls_mh_clear" class="button" style="display:none;">
            <i class="bi bi-trash"></i> Clear History
          </button>
        </div>

        <div id="myls_mh_status" class="mt-3 small text-muted"></div>
      </div>

      <!-- Right column -->
      <div class="myls-right" style="border:1px solid #000;padding:16px;border-radius:1em;min-height:200px;">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="h6 mb-0"><i class="bi bi-table"></i> Change Log</div>
          <button id="myls_mh_export" class="button" style="display:none;">
            <i class="bi bi-download"></i> Export CSV
          </button>
        </div>

        <div class="table-responsive">
          <table id="myls_mh_table" class="widefat striped" style="width:100%;table-layout:fixed;">
            <thead>
              <tr>
                <th style="width:160px;">Date/Time</th>
                <th style="width:160px;">User</th>
                <th style="width:140px;">Field</th>
                <th>Old Value</th>
                <th>New Value</th>
              </tr>
            </thead>
            <tbody>
              <tr><td colspan="5"><em>No data loaded.</em></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php
  }
];
