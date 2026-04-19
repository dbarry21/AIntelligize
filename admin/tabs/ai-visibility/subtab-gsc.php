<?php
/**
 * AI Visibility → Google Search subtab
 * Path: admin/tabs/ai-visibility/subtab-gsc.php
 *
 * Pulls impressions/clicks/position, top queries, top pages, and AI Overview
 * appearances from Google Search Console. Reuses modules/oauth/gsc.php.
 */

if ( ! defined('ABSPATH') ) exit;

return [
	'id'     => 'gsc',
	'label'  => 'Google Search',
	'icon'   => 'bi bi-google',
	'order'  => 30,
	'render' => function () {
		$connected = function_exists('myls_gsc_is_connected') && myls_gsc_is_connected();
		$site_prop = trim( (string) get_option('myls_gsc_site_property', '') );
		if ( $site_prop === '' ) $site_prop = home_url('/');

		if ( ! $connected ) : ?>
			<div class="myls-aiv-panel">
				<div class="notice notice-warning">
					<p><strong>Google Search Console not connected.</strong></p>
					<p>Connect GSC in <a href="<?php echo esc_url( admin_url('admin.php?page=aintelligize&tab=api-integration') ); ?>">API Integration → Google Search Console</a> to populate this tab.</p>
				</div>
			</div>
			<?php return; endif; ?>

		<div class="myls-aiv-panel">
			<div class="myls-aiv-toolbar">
				<label class="myls-aiv-range-label">
					Range:
					<select class="myls-aiv-range" data-target="gsc">
						<option value="7">Last 7 days</option>
						<option value="28" selected>Last 28 days</option>
						<option value="90">Last 90 days</option>
					</select>
				</label>
				<span class="myls-aiv-site-prop">Site: <code><?php echo esc_html( $site_prop ); ?></code></span>
				<span class="myls-aiv-status" data-status="gsc"></span>
			</div>

			<div class="myls-aiv-kpis myls-aiv-kpis-4">
				<div class="myls-aiv-kpi"><span class="v" data-kpi="gsc-impressions">—</span><span class="l">Impressions</span></div>
				<div class="myls-aiv-kpi"><span class="v" data-kpi="gsc-clicks">—</span><span class="l">Clicks</span></div>
				<div class="myls-aiv-kpi"><span class="v" data-kpi="gsc-ctr">—</span><span class="l">CTR</span></div>
				<div class="myls-aiv-kpi"><span class="v" data-kpi="gsc-position">—</span><span class="l">Avg position</span></div>
			</div>

			<div class="myls-aiv-card">
				<h3>AI Overview appearances <span class="myls-aiv-hint">(searchAppearance = AI_OVERVIEW)</span></h3>
				<div class="myls-aiv-kpis">
					<div class="myls-aiv-kpi"><span class="v" data-kpi="gsc-aio-impressions">—</span><span class="l">AI Overview impressions</span></div>
					<div class="myls-aiv-kpi"><span class="v" data-kpi="gsc-aio-clicks">—</span><span class="l">AI Overview clicks</span></div>
				</div>
			</div>

			<div class="myls-aiv-grid">
				<div class="myls-aiv-card">
					<h3>Top 25 queries</h3>
					<table class="widefat striped myls-aiv-table" data-table="gsc-queries">
						<thead><tr><th>Query</th><th class="num">Impr.</th><th class="num">Clicks</th><th class="num">Pos.</th></tr></thead>
						<tbody><tr><td colspan="4">Loading…</td></tr></tbody>
					</table>
				</div>
				<div class="myls-aiv-card">
					<h3>Top 25 pages</h3>
					<table class="widefat striped myls-aiv-table" data-table="gsc-pages">
						<thead><tr><th>Page</th><th class="num">Impr.</th><th class="num">Clicks</th><th class="num">Pos.</th></tr></thead>
						<tbody><tr><td colspan="4">Loading…</td></tr></tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	},
];
