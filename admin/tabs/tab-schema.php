<?php
/**
 * Admin Tab: Schema (Full-width UI + scoped styles)
 * - Auto-discovers subtabs from admin/tabs/schema/subtab-*.php
 * - Refactored to use array-based tab registration with explicit ordering.
 */
if ( ! defined('ABSPATH') ) exit;

/**
 * Load tiny Bootstrap Icons only on our settings screen (and when Schema tab is active or present).
 */
if ( ! function_exists('myls_enqueue_schema_icons') ) {
	add_action('admin_enqueue_scripts', function( $hook ){
		if ( ! isset($_GET['page']) || $_GET['page'] !== 'aintelligize' ) return;
		// Lightweight, cached CDN font; safe across WP admin.
		wp_enqueue_style(
			'myls-bootstrap-icons',
			'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css',
			array(),
			'1.11.3'
		);
	});
}

/**
 * Register the "Schema" tab using the new registry signature.
 * Set 'order' to control position in the bar (lower = earlier).
 */
myls_register_admin_tab([
	'id'    => 'schema',
	'title' => 'Schema',
	'order' => 20,                    // adjust to your desired position
	'cap'   => 'manage_options',
	'icon'  => 'dashicons-editor-code',
	'cb'    => function () {

		$dir = trailingslashit( MYLS_PATH ) . 'admin/tabs/schema';
		if ( ! is_dir($dir) ) {
			echo '<div class="alert alert-warning mt-3">Create folder <code>admin/tabs/schema</code> and add <code>subtab-*.php</code> files.</div>';
			return;
		}

		if ( ! defined('MYLS_SCHEMA_DISCOVERY') ) {
			define('MYLS_SCHEMA_DISCOVERY', true);
		}

		// -------------------------------------------------
		// Discover subtabs
		// -------------------------------------------------
		$files   = glob( $dir . '/subtab-*.php' );
		$subtabs = [];
		if ( $files ) {
			natsort($files);
			foreach ( $files as $file ) {
				$spec = include $file;
				// Each subtab file should return: ['id'=>'...', 'label'=>'...', 'render'=>callable, 'on_save'=>callable?]
				if ( is_array($spec) && ! empty($spec['id']) && ! empty($spec['label']) && ! empty($spec['render']) ) {
					$subtabs[ $spec['id'] ] = $spec;
				}
			}
		}

		if ( empty($subtabs) ) {
			echo '<div class="alert alert-warning mt-3">No schema subtabs found in <code>admin/tabs/schema</code>.</div>';
			return;
		}

		// Deterministic order: by 'order', then label
		uasort($subtabs, function($a, $b){
			$ao = $a['order'] ?? 50;
			$bo = $b['order'] ?? 50;
			if ($ao === $bo) return strcasecmp($a['label'], $b['label']);
			return $ao <=> $bo;
		});

		// Active subtab (GET → fallback to first)
		$active = isset($_GET['sub']) ? sanitize_key($_GET['sub']) : '';
		if ( ! isset($subtabs[$active]) ) {
			$keys   = array_keys($subtabs);
			$active = reset($keys);
		}

		// -------------------------------------------------
		// Save handler for the active subtab
		// -------------------------------------------------
		if (
			isset($_POST['myls_schema_nonce']) &&
			wp_verify_nonce($_POST['myls_schema_nonce'], 'myls_schema_save') &&
			current_user_can('manage_options')
		) {
			// Prefer the POSTed subtab if present (in case URL loses ?sub=...)
			if ( ! empty($_POST['myls_active_sub']) ) {
				$post_sub = sanitize_key($_POST['myls_active_sub']);
				if ( isset($subtabs[$post_sub]) ) {
					$active = $post_sub;
				}
			}

			if ( isset($subtabs[$active]['on_save']) && is_callable($subtabs[$active]['on_save']) ) {
				call_user_func($subtabs[$active]['on_save']);
				echo '<div class="alert alert-success mt-3">Schema settings saved.</div>';
			} else {
				echo '<div class="alert alert-warning mt-3">Nothing saved: this subtab has no on_save() handler.</div>';
			}
		}

		$base_url = admin_url('admin.php?page=aintelligize&tab=schema');
		?>
		<style>
			/* ====== Full-width wrapper ====== */
			.myls-schema-wrap { max-width: none; width: 100%; margin-left: -20px; margin-right: -20px; }
			@media (max-width: 782px){ .myls-schema-wrap { margin-left: 0; margin-right: 0; } }

			/* ====== Scoped styles (no dependency on Bootstrap) ====== */
			.myls-schema-wrap .myls-schema-nav { display:flex; gap:.25rem; border-bottom:1px solid #dee2e6; background:#fff; padding:0 20px; }
			.myls-schema-wrap .nav-item { list-style:none; }
			.myls-schema-wrap .nav-link { display:inline-block; padding:.6rem .9rem; font-weight:600; color:#495057; text-decoration:none; border:1px solid transparent; border-bottom:2px solid transparent; border-radius:.5rem .5rem 0 0; }
			.myls-schema-wrap .nav-link:hover { color:#0d6efd; }
			.myls-schema-wrap .nav-link.active { color:#0d6efd; background:#fff; border-color:#dee2e6; border-bottom-color:#0d6efd; }

			.myls-schema-wrap .myls-subtab-body { padding:18px 24px 24px 24px; background:#f6f7fb; }
			.myls-schema-wrap .myls-subtab-inner { background:#fff; border:1px solid #e6e6e6; border-radius:10px; padding:20px; }

			.myls-schema-wrap .myls-section { border:1px solid #ececec; border-radius:10px; padding:16px; margin-bottom:18px; background:#fff; }
			.myls-schema-wrap .myls-section-title { display:flex; align-items:center; gap:.5rem; font-size:1.05rem; font-weight:700; margin:6px 0 14px; }
			.myls-schema-wrap .myls-hr { height:1px; background:#ececec; border:0; margin:12px 0 18px; }

			/* Buttons */
			.myls-schema-wrap .btn { display:inline-block; font-weight:600; border:1px solid #dee2e6; padding:.45rem .75rem; border-radius:.5rem; background:#f8f9fa; color:#212529; cursor:pointer; line-height:1.25; }
			.myls-schema-wrap .btn:hover { filter:brightness(0.97); }
			.myls-schema-wrap .btn-primary { background:#0d6efd; border-color:#0d6efd; color:#fff; }
			.myls-schema-wrap .btn-outline-secondary { background:transparent; color:#6c757d; border-color:#6c757d; }
			.myls-schema-wrap .btn-outline-secondary:hover { background:#6c757d; color:#fff; }

			/* Forms */
			.myls-schema-wrap .form-label { font-weight:600; margin-bottom:.35rem; display:block; }
			.myls-schema-wrap .form-control, .myls-schema-wrap .form-select,
			.myls-schema-wrap textarea, .myls-schema-wrap input[type="text"],
			.myls-schema-wrap input[type="email"], .myls-schema-wrap input[type="url"],
			.myls-schema-wrap input[type="time"] { width:100%; padding:.5rem .65rem; border:1px solid #ced4da; border-radius:.375rem; background:#fff; color:#212529; }
			.myls-schema-wrap .form-check { display:flex; align-items:center; gap:.5rem; }
			.myls-schema-wrap .form-check-input { margin:0; }

			/* Grid helpers */
			.myls-schema-wrap .row { display:flex; flex-wrap:wrap; margin-left:-.5rem; margin-right:-.5rem; }
			.myls-schema-wrap .row > [class^="col-"] { padding-left:.5rem; padding-right:.5rem; }
			.myls-schema-wrap .g-3 > [class^="col-"] { margin-bottom:1rem; }
			.myls-schema-wrap .g-4 > [class^="col-"] { margin-bottom:1.25rem; }
			.myls-schema-wrap .col-12 { flex:0 0 100%; max-width:100%; }
			.myls-schema-wrap .col-md-6 { flex:0 0 50%; max-width:50%; }
			.myls-schema-wrap .col-lg-4 { flex:0 0 33.333%; max-width:33.333%; }
			.myls-schema-wrap .col-lg-6 { flex:0 0 50%; max-width:50%; }
			.myls-schema-wrap .col-lg-8 { flex:0 0 66.666%; max-width:66.666%; }
			@media (max-width: 992px){ .myls-schema-wrap .col-lg-8, .myls-schema-wrap .col-lg-6, .myls-schema-wrap .col-lg-4 { flex:0 0 100%; max-width:100%; } }
			@media (max-width: 782px){ .myls-schema-wrap .col-md-6 { flex:0 0 100%; max-width:100%; } }

			/* Accordion */
			.myls-schema-wrap .accordion { margin:0; }
			.myls-schema-wrap .accordion-item { border:1px solid #e9ecef; border-radius:.5rem; overflow:hidden; background:#fff; margin-bottom:.75rem; }
			.myls-schema-wrap .accordion-button { width:100%; text-align:left; background:#f8f9fa; padding:.75rem 1rem; border:0; font-weight:600; cursor:pointer; }
			.myls-schema-wrap .accordion-button.collapsed { background:#fff; }
			.myls-schema-wrap .accordion-body { padding:1rem; }

			/* Utility */
			.myls-schema-wrap .text-muted { color:#6c757d; }
			.myls-schema-wrap .badge { display:inline-block; font-size:.75rem; padding:.25rem .5rem; border-radius:.35rem; background:#e9f2ff; color:#0d6efd; }
			.myls-schema-wrap .mt-2{ margin-top:.5rem; } .myls-schema-wrap .mt-3{ margin-top:1rem; } .myls-schema-wrap .mb-2{ margin-bottom:.5rem; } .myls-schema-wrap .mb-3{ margin-bottom:1rem; }
			.myls-schema-wrap .d-flex{ display:flex; } .myls-schema-wrap .gap-2{ gap:.5rem; }
		</style>

		<div class="myls-schema-wrap">
			<ul class="myls-schema-nav">
				<?php $base_url = admin_url('admin.php?page=aintelligize&tab=schema'); ?>
				<?php foreach ( $subtabs as $id => $spec ) :
					$label = $spec['label'];
					$url   = esc_url( add_query_arg(['sub'=>$id], $base_url) );
					$cls   = ($id === $active) ? 'active' : '';
				?>
				<li class="nav-item">
					<a class="nav-link <?php echo esc_attr($cls); ?>" href="<?php echo $url; ?>">
						<i class="bi bi-diagram-3"></i> <?php echo esc_html($label); ?>
					</a>
				</li>
				<?php endforeach; ?>
			</ul>

			<div class="myls-subtab-body">
				<div class="myls-subtab-inner">
					<form method="post">
						<?php wp_nonce_field('myls_schema_save', 'myls_schema_nonce'); ?>
						<input type="hidden" name="myls_active_sub" value="<?php echo esc_attr($active); ?>">
						<?php call_user_func( $subtabs[$active]['render'] ); ?>
						<div class="mt-3">
							<button type="submit" class="btn btn-primary">Save Settings</button>
							<a href="<?php echo esc_url( $base_url ); ?>" class="btn btn-outline-secondary">Back to Schema</a>
						</div>
					</form>
				</div>
			</div>
		</div>
		<?php
	},
]);
