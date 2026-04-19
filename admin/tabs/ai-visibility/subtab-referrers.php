<?php
/**
 * AI Visibility → Referrers subtab
 * Path: admin/tabs/ai-visibility/subtab-referrers.php
 *
 * Read-only UI on top of the existing {prefix}myls_ai_referrals table,
 * populated by inc/ai-referral-tracker.php.
 */

if ( ! defined('ABSPATH') ) exit;

return [
	'id'     => 'referrers',
	'label'  => 'AI Referrers',
	'icon'   => 'bi bi-link-45deg',
	'order'  => 20,
	'render' => function () {
		$tracking_enabled = get_option('myls_ai_referral_enabled', '1') === '1';
		?>
		<div class="myls-aiv-panel">
			<div class="myls-aiv-toolbar">
				<label class="myls-aiv-range-label">
					Range:
					<select class="myls-aiv-range" data-target="referrers">
						<option value="7">Last 7 days</option>
						<option value="28" selected>Last 28 days</option>
						<option value="90">Last 90 days</option>
					</select>
				</label>
				<span class="myls-aiv-status" data-status="referrers"></span>
			</div>

			<div class="myls-aiv-kpis">
				<div class="myls-aiv-kpi"><span class="v" data-kpi="referrers-total">—</span><span class="l">Total AI clicks</span></div>
				<div class="myls-aiv-kpi"><span class="v" data-kpi="referrers-sources">—</span><span class="l">Unique sources</span></div>
				<div class="myls-aiv-kpi"><span class="v" data-kpi="referrers-pages">—</span><span class="l">Landing pages</span></div>
			</div>

			<div class="myls-aiv-card">
				<h3>Daily clicks by source</h3>
				<canvas id="myls-aiv-referrers-chart" height="120"></canvas>
			</div>

			<div class="myls-aiv-grid">
				<div class="myls-aiv-card">
					<h3>Clicks by source</h3>
					<table class="widefat striped myls-aiv-table" data-table="referrers-by-source">
						<thead><tr><th>Source</th><th class="num">Clicks</th></tr></thead>
						<tbody><tr><td colspan="2">Loading…</td></tr></tbody>
					</table>
				</div>
				<div class="myls-aiv-card">
					<h3>Top 25 landing pages</h3>
					<table class="widefat striped myls-aiv-table" data-table="referrers-top-pages">
						<thead><tr><th>Page</th><th>Source</th><th class="num">Clicks</th></tr></thead>
						<tbody><tr><td colspan="3">Loading…</td></tr></tbody>
					</table>
				</div>
			</div>

			<div class="myls-aiv-card myls-aiv-settings">
				<h3>Settings</h3>
				<form method="post">
					<?php wp_nonce_field( MYLS_AIV_NONCE_ACTION, 'myls_aiv_nonce' ); ?>
					<input type="hidden" name="myls_aiv_active_sub" value="referrers">
					<p>
						<label>
							<input type="checkbox" name="myls_ai_referral_enabled" value="1" <?php checked( $tracking_enabled ); ?>>
							Log AI referral traffic (chatgpt.com, perplexity.ai, claude.ai, gemini.google.com, copilot.microsoft.com, …)
						</label>
					</p>
					<p class="description">
						Referral data is captured by the existing AI Referral Tracker in <code>inc/ai-referral-tracker.php</code>. Retention matches the Crawlers tab.
					</p>
					<p><button type="submit" class="button button-primary">Save settings</button></p>
				</form>
			</div>
		</div>
		<?php
	},
	'on_save' => function () {
		$enabled = ! empty($_POST['myls_ai_referral_enabled']) ? '1' : '0';
		update_option( 'myls_ai_referral_enabled', $enabled );
	},
];
