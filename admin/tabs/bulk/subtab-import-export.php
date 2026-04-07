<?php
/**
 * Bulk > Import / Export (Subtab)
 * Path: admin/tabs/bulk/subtab-import-export.php
 *
 * Export all FAQs to CSV, or import an updated CSV to overwrite FAQ data.
 * Behavior lives in: assets/js/myls-import-export.js
 */

if ( ! defined('ABSPATH') ) exit;

$spec = [
	'id'    => 'import-export',
	'label' => 'Import / Export',
	'icon'  => 'bi bi-arrow-left-right',
	'order' => 40,
	'render' => function ( $ctx = [] ) {

		// Count posts with FAQs and total FAQ items for the summary.
		global $wpdb;
		$stats = $wpdb->get_row(
			"SELECT COUNT(DISTINCT pm.post_id) AS post_count,
			        COUNT(pm.post_id)           AS meta_rows
			   FROM {$wpdb->postmeta} pm
			   JOIN {$wpdb->posts}    p ON p.ID = pm.post_id
			  WHERE pm.meta_key   = '_myls_faq_items'
			    AND p.post_status IN ('publish','draft','pending','future','private')"
		);

		$post_count = (int) ( $stats->post_count ?? 0 );

		// To get actual FAQ item count we need to unserialize. Estimate from meta rows
		// or do a quick count. For accuracy, iterate:
		$faq_total = 0;
		if ( $post_count > 0 ) {
			$pids = $wpdb->get_col(
				"SELECT DISTINCT pm.post_id
				   FROM {$wpdb->postmeta} pm
				   JOIN {$wpdb->posts}    p ON p.ID = pm.post_id
				  WHERE pm.meta_key   = '_myls_faq_items'
				    AND p.post_status IN ('publish','draft','pending','future','private')"
			);
			foreach ( $pids as $pid ) {
				$items = function_exists('myls_get_faq_items')
					? myls_get_faq_items( (int) $pid )
					: get_post_meta( (int) $pid, '_myls_faq_items', true );
				if ( is_array( $items ) ) {
					$faq_total += count( $items );
				}
			}
		}

		$nonce = $ctx['bulk_nonce'] ?? wp_create_nonce('myls_bulk_ops');
		?>

		<!-- Bootstrap data for JS -->
		<script type="application/json" id="mylsIEBootstrap"><?php
			echo wp_json_encode([
				'nonce'      => $nonce,
				'ajaxurl'    => admin_url('admin-ajax.php'),
				'postCount'  => $post_count,
				'faqTotal'   => $faq_total,
			]);
		?></script>

		<div class="container-fluid px-0 mt-3">
			<div class="row g-4 align-items-start">

				<!-- ── LEFT: Export ────────────────────────────────── -->
				<div class="col-12 col-lg-5">
					<div class="myls-card">
						<div class="myls-card-header">
							<h2 class="myls-card-title">
								<i class="bi bi-download"></i> Export FAQs
							</h2>
						</div>

						<p class="text-muted mb-3">
							Download every FAQ across all posts as a single CSV file.
							Open in Excel or Google Sheets, make edits, then re-import.
						</p>

						<!-- Stats badges -->
						<div class="d-flex flex-wrap gap-2 mb-4">
							<span class="badge rounded-pill" style="background:var(--myls-color-primary,#0ea5e9);font-size:.85rem;padding:.45em .9em;">
								<i class="bi bi-file-earmark-text"></i>&ensp;<?php echo number_format( $post_count ); ?> post<?php echo $post_count !== 1 ? 's' : ''; ?> with FAQs
							</span>
							<span class="badge rounded-pill" style="background:#6366f1;font-size:.85rem;padding:.45em .9em;">
								<i class="bi bi-question-circle"></i>&ensp;<?php echo number_format( $faq_total ); ?> total FAQ item<?php echo $faq_total !== 1 ? 's' : ''; ?>
							</span>
						</div>

						<div class="mb-3">
							<p class="mb-2" style="font-size:.875rem;color:var(--myls-gray-600,#6b7280);">
								<strong>CSV columns:</strong> <code>post_id</code>, <code>post_title</code>, <code>faq_index</code>, <code>question</code>, <code>answer</code>
							</p>
						</div>

						<button id="myls_ie_export_btn" class="btn btn-primary" <?php echo $post_count === 0 ? 'disabled' : ''; ?>>
							<i class="bi bi-download"></i> Export All FAQs to CSV
						</button>
					</div>
				</div>

				<!-- ── RIGHT: Import ───────────────────────────────── -->
				<div class="col-12 col-lg-7">
					<div class="myls-card">
						<div class="myls-card-header">
							<h2 class="myls-card-title">
								<i class="bi bi-upload"></i> Import FAQs from CSV
							</h2>
						</div>

						<p class="text-muted mb-3">
							Upload an edited CSV to update FAQ content. The <code>post_id</code> column
							matches rows to existing posts. All FAQs for a post are replaced by the CSV data.
						</p>

						<!-- File picker -->
						<div class="mb-3">
							<label for="myls_ie_import_file" class="form-label">Choose CSV file</label>
							<input type="file" id="myls_ie_import_file" class="form-control" accept=".csv">
						</div>

						<div class="d-flex align-items-center gap-2 mb-3">
							<button id="myls_ie_preview_btn" class="btn btn-outline-primary" disabled>
								<i class="bi bi-eye"></i> Parse &amp; Preview
							</button>
							<span id="myls_ie_preview_status" class="text-muted" style="font-size:.875rem;"></span>
						</div>

						<!-- Preview area (hidden until parsed) -->
						<div id="myls_ie_preview_area" style="display:none;">
							<hr>
							<h3 style="font-size:1rem;font-weight:600;margin-bottom:.75rem;">
								<i class="bi bi-clipboard-data"></i> Import Preview
							</h3>

							<!-- Summary badges -->
							<div class="d-flex flex-wrap gap-2 mb-3" id="myls_ie_preview_badges"></div>

							<!-- Per-post breakdown table -->
							<div class="table-responsive mb-3" style="max-height:280px;overflow-y:auto;">
								<table class="table table-sm table-hover mb-0" style="font-size:.85rem;">
									<thead class="table-light" style="position:sticky;top:0;z-index:1;">
										<tr>
											<th>Post ID</th>
											<th>Title</th>
											<th>Status</th>
											<th style="text-align:center;">Modified</th>
											<th style="text-align:center;">Added</th>
											<th style="text-align:center;">Removed</th>
											<th style="text-align:center;">Same</th>
										</tr>
									</thead>
									<tbody id="myls_ie_preview_tbody"></tbody>
								</table>
							</div>

							<button id="myls_ie_confirm_btn" class="btn btn-success">
								<i class="bi bi-check-circle"></i> Confirm Import
							</button>
						</div>
					</div>
				</div>
			</div>

			<!-- ── Bottom: Results Log ─────────────────────────────── -->
			<div class="row mt-4">
				<div class="col-12">
					<div id="myls_ie_log_wrap" style="display:none;">
						<div class="myls-results-header mb-2">
							<h3 style="font-size:1rem;font-weight:600;margin:0;">
								<i class="bi bi-terminal"></i> Import Log
							</h3>
						</div>
						<pre id="myls_ie_log" class="myls-results-terminal" style="max-height:300px;overflow:auto;">Ready.</pre>
					</div>
				</div>
			</div>
		</div>
	<?php }
];

return $spec;
