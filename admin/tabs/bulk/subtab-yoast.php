<?php
/**
 * File: admin/tabs/bulk/subtab-yoast.php
 *
 * Yoast Bulk Operations subtab
 */
if (!defined('ABSPATH')) exit;

return [
  'id'    => 'yoast',
  'label' => 'Yoast Operations',
  'order' => 10,
  'render'=> function( $ctx = [] ) {
    // Server-provided data (now populated by tab-bulk.php)
    $posts_by_type = isset($ctx['posts_by_type']) && is_array($ctx['posts_by_type']) ? $ctx['posts_by_type'] : [];
    $nonce         = $ctx['bulk_nonce'] ?? wp_create_nonce('myls_bulk_ops');

    // Safety: if nothing collected, show a friendly note instead of empty selects
    if ( empty($posts_by_type) ) {
      echo '<div class="notice notice-warning"><p>No published posts found for public post types, or the list failed to load. Try reloading the page.</p></div>';
    }
    ?>
    <div class="row g-4 align-items-start">
      <!-- SOURCE (left) -->
      <div class="col-12 col-lg-6">
        <div class="myls-section">
          <div class="myls-section-title"><i class="bi bi-diagram-3"></i> Source Post</div>

          <div class="mb-3">
            <label class="form-label" for="myls_yoast_source_pt">Post Type (Source)</label>
            <select id="myls_yoast_source_pt" class="form-select">
              <?php $i=0; foreach ( array_keys($posts_by_type) as $pt ) : $sel = ($i++ === 0) ? ' selected' : ''; ?>
                <option value="<?php echo esc_attr($pt); ?>"<?php echo $sel; ?>><?php echo esc_html($pt); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label" for="myls_yoast_source_search">Search (Source)</label>
            <input id="myls_yoast_source_search" type="text" class="form-control" placeholder="Type to filter…">
          </div>

          <div class="mb-1">
            <label class="form-label" for="myls_yoast_source_post">Choose Source Post</label>
            <select id="myls_yoast_source_post" class="form-select" size="14"></select>
          </div>
          <div class="form-text">Pick one source post. (Used only by actions that require a source.)</div>
        </div>
      </div>

      <!-- TARGETS (right) -->
      <div class="col-12 col-lg-6">
        <div class="myls-section">
          <div class="myls-section-title"><i class="bi bi-bullseye"></i> Target Posts</div>

          <div class="mb-3">
            <label class="form-label" for="myls_yoast_target_pt">Post Type (Targets)</label>
            <select id="myls_yoast_target_pt" class="form-select">
              <?php $i=0; foreach ( array_keys($posts_by_type) as $pt ) : $sel = ($i++ === 0) ? ' selected' : ''; ?>
                <option value="<?php echo esc_attr($pt); ?>"<?php echo $sel; ?>><?php echo esc_html($pt); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label" for="myls_yoast_target_search">Search (Targets)</label>
            <input id="myls_yoast_target_search" type="text" class="form-control" placeholder="Type to filter…">
          </div>

          <div class="mb-1">
            <label class="form-label" for="myls_yoast_target_posts">Choose Target Post(s)</label>
            <select id="myls_yoast_target_posts" class="form-select" size="14" multiple></select>
          </div>
          <div class="form-text">Hold Ctrl/⌘ to select multiple.</div>
        </div>
      </div>
    </div>

    <!-- ACTIONS ROW -->
    <div class="row g-4">
      <div class="col-12">
        <div class="myls-section">
          <div class="myls-section-title">Bulk Actions (apply to selected <em>Targets</em>)</div>
          <div class="d-flex gap-2" style="flex-wrap:wrap">
            <button type="button" class="btn btn-primary" data-action="indexfollow">
              <i class="bi bi-check2-square"></i> Set to Index, Follow
            </button>
            <button type="button" class="btn btn-secondary" data-action="reset_canonical">
              <i class="bi bi-arrow-counterclockwise"></i> Reset Canonical
            </button>
            <button type="button" class="btn btn-outline-secondary" data-action="clear_canonical">
              <i class="bi bi-x-circle"></i> Clear Canonical
            </button>
            <button type="button" class="btn btn-danger" data-action="noindex_nofollow">
              <i class="bi bi-shield-x"></i> Set to Noindex, Nofollow
            </button>
            <button type="button" class="btn btn-outline-primary" data-action="copy_canonical">
              <i class="bi bi-link-45deg"></i> Copy Canonical from Source → Targets
            </button>
          </div>

          <div id="myls_bulk_result" class="alert alert-info mt-3" style="display:none; max-height:300px; overflow:auto;"></div>
        </div>
      </div>
    </div>

    <!-- Subtab-scoped bootstrap payload for JS -->
    <script type="application/json" id="mylsYoastBootstrap">
    <?php
      echo wp_json_encode([
        'nonce'         => $nonce,
        'posts_by_type' => $posts_by_type, // <-- now populated server-side
        'selectors'     => [
          'source_pt'     => '#myls_yoast_source_pt',
          'source_search' => '#myls_yoast_source_search',
          'source_post'   => '#myls_yoast_source_post',
          'target_pt'     => '#myls_yoast_target_pt',
          'target_search' => '#myls_yoast_target_search',
          'target_posts'  => '#myls_yoast_target_posts',
          'result_box'    => '#myls_bulk_result',
        ],
        'actions'       => [
          'indexfollow'     => 'myls_set_index_follow',
          'reset_canonical' => 'myls_reset_canonical',
          'clear_canonical' => 'myls_clear_canonical',
          'noindex_nofollow'=> 'myls_set_noindex_nofollow',
          'copy_canonical'  => 'myls_copy_canonical_from_source',
        ],
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    ?>
    </script>
    <?php
  },
];
