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
			echo wp_json_encode([
				'nonce'   => $nonce,
				'ajaxurl' => admin_url('admin-ajax.php'),
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
		</div>
	<?php }
];

return $spec;
