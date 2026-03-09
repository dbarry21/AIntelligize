<?php
/**
 * AIntelligize — Video Transcripts Menu & Scripts
 * Path: admin/admin-video-transcripts-menu.php
 *
 * Registers the "Video Transcripts" submenu page under AIntelligize
 * and enqueues the dashboard JS/CSS.
 *
 * @since 7.8.86
 */
if ( ! defined('ABSPATH') ) exit;

/* ═══════════════════════════════════════════════════════
 *  1. SUBMENU REGISTRATION
 * ═══════════════════════════════════════════════════════ */

add_action('admin_menu', 'myls_vt_add_submenu', 27);

function myls_vt_add_submenu() {
	add_submenu_page(
		'aintelligize',
		'Video Transcripts — Transcript Cache',
		'Video Transcripts',
		'manage_options',
		'myls-video-transcripts',
		'myls_vt_render_page'
	);
}

function myls_vt_render_page() {
	?>
	<div class="wrap myls-vt-wrap">
		<h1>Video Transcripts</h1>

		<!-- Stats bar -->
		<div class="myls-vt-stats" id="myls-vt-stats">
			<span class="myls-vt-stat"><strong id="vt-stat-total">0</strong> Total</span>
			<span class="myls-vt-stat myls-vt-stat--ok"><strong id="vt-stat-ok">0</strong> OK</span>
			<span class="myls-vt-stat myls-vt-stat--pending"><strong id="vt-stat-pending">0</strong> Pending</span>
			<span class="myls-vt-stat myls-vt-stat--none"><strong id="vt-stat-none">0</strong> None</span>
			<span class="myls-vt-stat myls-vt-stat--error"><strong id="vt-stat-error">0</strong> Error</span>
		</div>

		<!-- Action buttons -->
		<div class="myls-vt-actions">
			<button type="button" class="button button-primary" id="myls-vt-sync">Sync Channel Videos</button>
			<button type="button" class="button button-primary" id="myls-vt-fetch-missing">Fetch Missing Transcripts</button>
			<button type="button" class="button" id="myls-vt-migrate">Migrate Legacy Entries</button>
		</div>

		<!-- Single video fetch -->
		<div class="myls-vt-single-fetch">
			<label for="myls-vt-single-id"><strong>Fetch Single Video:</strong></label>
			<input type="text" id="myls-vt-single-id" placeholder="YouTube Video ID (11 chars)" maxlength="11" style="width:200px;">
			<button type="button" class="button" id="myls-vt-fetch-single">Fetch</button>
			<span id="myls-vt-single-result"></span>
		</div>

		<!-- Progress bar -->
		<div class="myls-vt-progress" id="myls-vt-progress" style="display:none;">
			<div class="myls-vt-progress-bar">
				<div class="myls-vt-progress-fill" id="myls-vt-progress-fill" style="width:0%"></div>
			</div>
			<span id="myls-vt-progress-text">Processing...</span>
		</div>

		<!-- Status message -->
		<div id="myls-vt-message" class="notice" style="display:none;"></div>

		<!-- Table -->
		<table class="wp-list-table widefat fixed striped" id="myls-vt-table">
			<thead>
				<tr>
					<th style="width:120px;">Video ID</th>
					<th>Title</th>
					<th style="width:80px;">Status</th>
					<th style="width:80px;">Source</th>
					<th style="width:140px;">Fetched</th>
					<th style="width:180px;">Actions</th>
				</tr>
			</thead>
			<tbody id="myls-vt-tbody">
				<tr><td colspan="6" style="text-align:center;padding:20px;">Loading...</td></tr>
			</tbody>
		</table>
	</div>
	<?php
}

/* ═══════════════════════════════════════════════════════
 *  2. ENQUEUE SCRIPTS & STYLES
 * ═══════════════════════════════════════════════════════ */

add_action('admin_enqueue_scripts', 'myls_vt_admin_scripts');

function myls_vt_admin_scripts( $hook ) {
	if ( $hook !== 'aintelligize_page_myls-video-transcripts' ) return;

	$base_url = plugin_dir_url( dirname(__FILE__) );
	$version  = defined('MYLS_VERSION') ? MYLS_VERSION : time();

	wp_enqueue_style('myls-vt-css', $base_url . 'admin/video-transcripts/video-transcripts.css', [], $version);
	wp_enqueue_script('myls-vt-js', $base_url . 'admin/video-transcripts/video-transcripts.js', ['jquery'], $version, true);

	wp_localize_script('myls-vt-js', 'MYLS_VT', [
		'ajaxurl' => admin_url('admin-ajax.php'),
		'nonce'   => wp_create_nonce('myls_vt_ops'),
	]);
}
