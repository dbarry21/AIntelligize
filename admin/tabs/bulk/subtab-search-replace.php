<?php
/**
 * Bulk > Search & Replace (Subtab)
 * Path: admin/tabs/bulk/subtab-search-replace.php
 *
 * Database-wide search & replace across posts, postmeta (including
 * Elementor JSON), and options. Dry-run preview before execution.
 * Behavior lives in: assets/js/myls-search-replace.js
 */

if ( ! defined('ABSPATH') ) exit;

$spec = [
	'id'    => 'search-replace',
	'label' => 'Search & Replace',
	'icon'  => 'bi bi-search',
	'order' => 45,
	'render' => function ( $ctx = [] ) {

		$nonce = $ctx['bulk_nonce'] ?? wp_create_nonce('myls_bulk_ops');
		?>

		<!-- Bootstrap data for JS -->
		<script type="application/json" id="mylsSRBootstrap"><?php
			$sr_post_types = array();
			foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $slug => $obj ) {
				if ( $slug === 'attachment' ) continue;
				$label = isset( $obj->labels->singular_name ) && $obj->labels->singular_name
					? $obj->labels->singular_name
					: $obj->label;
				$sr_post_types[] = array( 'slug' => (string) $slug, 'label' => (string) $label );
			}
			// Include Elementor templates — they use a non-public CPT but
			// store real content in _elementor_data that users need to search.
			if ( post_type_exists( 'elementor_library' ) ) {
				$sr_post_types[] = array( 'slug' => 'elementor_library', 'label' => 'Elementor Template' );
			}
			usort( $sr_post_types, function ( $a, $b ) {
				return strcasecmp( $a['label'], $b['label'] );
			} );
			echo wp_json_encode([
				'nonce'     => $nonce,
				'ajaxurl'   => admin_url('admin-ajax.php'),
				'postTypes' => $sr_post_types,
			]);
		?></script>

		<div class="container-fluid px-0 mt-3">
			<div class="row g-4 align-items-start">

				<!-- ── LEFT: Search & Replace Form ──────────────── -->
				<div class="col-12 col-lg-6">
					<div class="myls-card">
						<div class="myls-card-header">
							<h2 class="myls-card-title">
								<i class="bi bi-search"></i> Search &amp; Replace
							</h2>
						</div>

						<p class="text-muted mb-3">
							Find and replace text across your entire WordPress database.
							Handles Elementor JSON data safely with cache clearing.
						</p>

						<!-- Search input -->
						<div class="mb-3">
							<label for="myls_sr_search" class="form-label">Search for</label>
							<input type="text" id="myls_sr_search" class="form-control" placeholder="Text to find..." autocomplete="off">
						</div>

						<!-- Replace input -->
						<div class="mb-3">
							<label for="myls_sr_replace" class="form-label">Replace with</label>
							<input type="text" id="myls_sr_replace" class="form-control" placeholder="Replacement text..." autocomplete="off">
						</div>

						<!-- Case toggle -->
						<div class="form-check mb-4">
							<input class="form-check-input" type="checkbox" id="myls_sr_case_insensitive" value="1">
							<label class="form-check-label" for="myls_sr_case_insensitive">
								Case-insensitive matching
							</label>
						</div>

						<!-- Action buttons -->
						<div class="d-flex flex-wrap align-items-center gap-2">
							<button id="myls_sr_preview_btn" class="btn btn-outline-primary">
								<i class="bi bi-eye"></i> Dry Run (Preview)
							</button>
							<button id="myls_sr_execute_btn" class="btn btn-danger" disabled>
								<i class="bi bi-arrow-repeat"></i> Execute Replace
							</button>
							<span id="myls_sr_status" class="text-muted" style="font-size:.875rem;"></span>
						</div>
					</div>
				</div>

				<!-- ── RIGHT: Scope & Info ──────────────────────── -->
				<div class="col-12 col-lg-6">
					<div class="myls-card">
						<div class="myls-card-header">
							<h2 class="myls-card-title">
								<i class="bi bi-sliders"></i> Scope
							</h2>
						</div>

						<p class="text-muted mb-3">
							Choose which database tables to search. All are enabled by default.
						</p>

						<div class="mb-2">
							<div class="form-check mb-2">
								<input class="form-check-input" type="checkbox" id="myls_sr_scope_content" value="1" checked>
								<label class="form-check-label" for="myls_sr_scope_content">
									<strong>Post Content</strong>
									<span class="text-muted" style="font-size:.85rem;">— <code>wp_posts.post_content</code></span>
								</label>
							</div>
							<div class="form-check mb-2">
								<input class="form-check-input" type="checkbox" id="myls_sr_scope_title" value="1" checked>
								<label class="form-check-label" for="myls_sr_scope_title">
									<strong>Post Titles</strong>
									<span class="text-muted" style="font-size:.85rem;">— <code>wp_posts.post_title</code></span>
								</label>
							</div>
							<div class="form-check mb-2">
								<input class="form-check-input" type="checkbox" id="myls_sr_scope_excerpt" value="1" checked>
								<label class="form-check-label" for="myls_sr_scope_excerpt">
									<strong>Post Excerpts</strong>
									<span class="text-muted" style="font-size:.85rem;">— <code>wp_posts.post_excerpt</code></span>
								</label>
							</div>
							<div class="form-check mb-2">
								<input class="form-check-input" type="checkbox" id="myls_sr_scope_meta" value="1" checked>
								<label class="form-check-label" for="myls_sr_scope_meta">
									<strong>Post Meta</strong>
									<span class="text-muted" style="font-size:.85rem;">— <code>wp_postmeta</code> + Elementor JSON</span>
								</label>
							</div>
							<div class="form-check mb-2">
								<input class="form-check-input" type="checkbox" id="myls_sr_scope_options" value="1" checked>
								<label class="form-check-label" for="myls_sr_scope_options">
									<strong>Options</strong>
									<span class="text-muted" style="font-size:.85rem;">— <code>wp_options</code> (excludes transients)</span>
								</label>
							</div>
						</div>

						<!-- Post Types -->
						<hr class="my-3">
						<h3 style="font-size:.9rem;font-weight:600;margin-bottom:.5rem;">
							<i class="bi bi-file-earmark-text"></i> Post Types
						</h3>
						<div class="form-check mb-2">
							<input class="form-check-input" type="checkbox" id="myls_sr_pt_all" checked>
							<label class="form-check-label" for="myls_sr_pt_all"><strong>All post types</strong></label>
						</div>
						<div id="myls_sr_pt_list" class="ms-4 mb-2">
							<!-- Populated dynamically by JS from bootstrap postTypes -->
						</div>

						<!-- Post Statuses -->
						<hr class="my-3">
						<h3 style="font-size:.9rem;font-weight:600;margin-bottom:.5rem;">
							<i class="bi bi-flag"></i> Post Statuses
						</h3>
						<div class="mb-2">
							<div class="form-check mb-1">
								<input class="form-check-input myls-sr-status-cb" type="checkbox" id="myls_sr_ps_publish" value="publish" checked>
								<label class="form-check-label" for="myls_sr_ps_publish">Published</label>
							</div>
							<div class="form-check mb-1">
								<input class="form-check-input myls-sr-status-cb" type="checkbox" id="myls_sr_ps_draft" value="draft" checked>
								<label class="form-check-label" for="myls_sr_ps_draft">Draft</label>
							</div>
							<div class="form-check mb-1">
								<input class="form-check-input myls-sr-status-cb" type="checkbox" id="myls_sr_ps_pending" value="pending" checked>
								<label class="form-check-label" for="myls_sr_ps_pending">Pending</label>
							</div>
							<div class="form-check mb-1">
								<input class="form-check-input myls-sr-status-cb" type="checkbox" id="myls_sr_ps_future" value="future" checked>
								<label class="form-check-label" for="myls_sr_ps_future">Scheduled</label>
							</div>
							<div class="form-check mb-1">
								<input class="form-check-input myls-sr-status-cb" type="checkbox" id="myls_sr_ps_private" value="private" checked>
								<label class="form-check-label" for="myls_sr_ps_private">Private</label>
							</div>
						</div>

						<div class="alert alert-info py-2 px-3 mb-0" style="font-size:.8rem;">
							<i class="bi bi-shield-check"></i>
							Revisions, autosaves, nav menu items, and attachments are always excluded.
						</div>

						<!-- Preview summary (populated by JS) -->
						<div id="myls_sr_preview_area" style="display:none;">
							<hr>
							<h3 style="font-size:1rem;font-weight:600;margin-bottom:.75rem;">
								<i class="bi bi-clipboard-data"></i> Dry Run Results
							</h3>
							<div class="d-flex flex-wrap gap-2 mb-2" id="myls_sr_preview_badges"></div>
						</div>
					</div>
				</div>
			</div>

			<!-- ── Bottom: Execution Log ──────────────────────── -->
			<div class="row mt-4">
				<div class="col-12">
					<div id="myls_sr_log_wrap" style="display:none;">
						<div class="myls-results-header mb-2">
							<h3 style="font-size:1rem;font-weight:600;margin:0;">
								<i class="bi bi-terminal"></i> Execution Log
							</h3>
						</div>
						<pre id="myls_sr_log" class="myls-results-terminal" style="max-height:300px;overflow:auto;">Ready.</pre>
					</div>
				</div>
			</div>

			<!-- ── Bottom: Recent Operations (Undo History) ──── -->
			<div class="row mt-4">
				<div class="col-12">
					<div class="myls-card">
						<div class="myls-card-header">
							<h2 class="myls-card-title">
								<i class="bi bi-arrow-counterclockwise"></i> Recent Operations (Undo)
							</h2>
						</div>
						<p class="text-muted mb-3">
							The last 5 search &amp; replace operations are kept as snapshots. Click <strong>Undo</strong> to restore the original values for that operation. Large Elementor snapshots can take several seconds to restore.
						</p>
						<div id="myls_sr_history_wrap">
							<p class="text-muted" style="margin:0;">Loading…</p>
						</div>
					</div>
				</div>
			</div>
		</div>
	<?php }
];

return $spec;
