<?php
/**
 * AI Visibility → Crawlers subtab
 * Path: admin/tabs/ai-visibility/subtab-crawlers.php
 */

if ( ! defined('ABSPATH') ) exit;

return [
	'id'     => 'crawlers',
	'label'  => 'AI Crawlers',
	'icon'   => 'bi bi-robot',
	'order'  => 10,
	'render' => function () {
		$retention = (int) get_option('myls_aiv_retention_days', 180);
		$enabled   = get_option('myls_aiv_tracking_enabled', '1') === '1';
		?>
		<div class="myls-aiv-panel">
			<div class="myls-aiv-toolbar">
				<label class="myls-aiv-range-label">
					Range:
					<select class="myls-aiv-range" data-target="crawlers">
						<option value="7">Last 7 days</option>
						<option value="28" selected>Last 28 days</option>
						<option value="90">Last 90 days</option>
					</select>
				</label>
				<span class="myls-aiv-status" data-status="crawlers"></span>
			</div>

			<div class="myls-aiv-kpis">
				<div class="myls-aiv-kpi"><span class="v" data-kpi="crawlers-total">—</span><span class="l">Total bot hits</span></div>
				<div class="myls-aiv-kpi"><span class="v" data-kpi="crawlers-bots">—</span><span class="l">Unique bots</span></div>
				<div class="myls-aiv-kpi"><span class="v" data-kpi="crawlers-paths">—</span><span class="l">Unique paths</span></div>
			</div>

			<div class="myls-aiv-card">
				<h3>Daily bot hits</h3>
				<canvas id="myls-aiv-crawlers-chart" height="120"></canvas>
			</div>

			<div class="myls-aiv-grid">
				<div class="myls-aiv-card">
					<h3>Hits by bot</h3>
					<table class="widefat striped myls-aiv-table" data-table="crawlers-by-bot">
						<thead><tr><th>Bot</th><th class="num">Hits</th></tr></thead>
						<tbody><tr><td colspan="2">Loading…</td></tr></tbody>
					</table>
				</div>
				<div class="myls-aiv-card">
					<h3>Top 25 paths crawled</h3>
					<table class="widefat striped myls-aiv-table" data-table="crawlers-top-paths">
						<thead><tr><th>Path</th><th>Bot</th><th class="num">Hits</th></tr></thead>
						<tbody><tr><td colspan="3">Loading…</td></tr></tbody>
					</table>
				</div>
			</div>

			<div class="myls-aiv-card myls-aiv-settings">
				<h3>Settings</h3>
				<form method="post">
					<?php wp_nonce_field( MYLS_AIV_NONCE_ACTION, 'myls_aiv_nonce' ); ?>
					<input type="hidden" name="myls_aiv_active_sub" value="crawlers">
					<p>
						<label>
							<input type="checkbox" name="myls_aiv_tracking_enabled" value="1" <?php checked( $enabled ); ?>>
							Track AI crawler hits
						</label>
					</p>
					<p>
						<label>
							Retention (days, 7–3650):
							<input type="number" min="7" max="3650" name="myls_aiv_retention_days" value="<?php echo esc_attr( $retention ); ?>" class="small-text">
						</label>
						&mdash; applies to both crawler hits and AI referrer logs.
					</p>
					<p><button type="submit" class="button button-primary">Save settings</button></p>
				</form>
			</div>
		</div>
		<?php
	},
	'on_save' => function () {
		$enabled = ! empty($_POST['myls_aiv_tracking_enabled']) ? '1' : '0';
		update_option( 'myls_aiv_tracking_enabled', $enabled );

		$days = isset($_POST['myls_aiv_retention_days']) ? (int) $_POST['myls_aiv_retention_days'] : 180;
		$days = max( 7, min( 3650, $days ) );
		update_option( 'myls_aiv_retention_days', $days );
	},
];
