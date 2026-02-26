<?php
/**
 * Utilities Subtab: GBP Photos
 *
 * Location: admin/tabs/utilities/subtab-gbp-photos.php
 *
 * Connects to Google Business Profile via OAuth 2.0, lets the user
 * pick an account + location from cascading dropdowns (loaded on demand,
 * not on page load — avoids quota exhaustion), then browse and import
 * GBP photos directly into the WordPress Media Library.
 *
 * Requires: modules/oauth/gbp.php (auto-loaded via myls_include_dir_excluding)
 *
 * Quota protection strategy:
 *  - Accounts and locations are fetched via a button click, NOT on page load.
 *  - Results are cached as transients (30 min) by gbp.php.
 *  - A "Refresh" button allows busting the cache when needed.
 *  - If a quota error occurs, a specific actionable message is shown.
 *
 * @since 7.5.0
 * @updated 7.5.1 — Button-triggered account loading (prevents on-load quota hits),
 *                  cache status indicator, quota error guidance, Refresh Cache button.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

return [
	'id'     => 'gbp_photos',
	'label'  => 'GBP Photos',
	'order'  => 60,
	'render' => function() {

		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<p class="muted">You do not have permission to use this section.</p>';
			return;
		}

		// ── Save credentials ──────────────────────────────────────────────
		if ( isset( $_POST['myls_gbp_creds_save'] ) ) {
			check_admin_referer( 'myls_gbp_creds_save' );

			$cid  = sanitize_text_field( $_POST['myls_gbp_client_id']     ?? '' );
			$csec = sanitize_text_field( $_POST['myls_gbp_client_secret'] ?? '' );
			$ruri = esc_url_raw(         $_POST['myls_gbp_redirect_uri']  ?? '' );

			if ( $cid )  update_option( 'myls_gbp_client_id',     $cid,  false );
			if ( $csec ) update_option( 'myls_gbp_client_secret', $csec, false );
			if ( $ruri ) update_option( 'myls_gbp_redirect_uri',  $ruri, false );

			echo '<div class="notice notice-success" style="margin:0 0 16px;"><p>Credentials saved.</p></div>';
		}

		// ── Load current state ────────────────────────────────────────────
		$connected      = function_exists( 'myls_gbp_is_connected' ) && myls_gbp_is_connected();
		$client_id      = (string) get_option( 'myls_gbp_client_id', '' );
		$client_secret  = (string) get_option( 'myls_gbp_client_secret', '' );
		$redirect_uri   = (string) get_option( 'myls_gbp_redirect_uri', admin_url( 'admin-post.php?action=myls_gbp_oauth_cb' ) );
		$saved_account  = (string) get_option( 'myls_gbp_account_id', '' );
		$saved_location = (string) get_option( 'myls_gbp_location_id', '' );
		$saved_label    = (string) get_option( 'myls_gbp_location_label', '' );
		$accounts_cached = ( false !== get_transient( 'myls_gbp_accounts_cache' ) );

		$nonce          = wp_create_nonce( 'myls_gbp_nonce' );
		$ajax_url       = admin_url( 'admin-ajax.php' );
		$connect_url    = wp_nonce_url( admin_url( 'admin-post.php?action=myls_gbp_oauth_start' ), 'myls_gbp_oauth_start' );
		$disconnect_url = wp_nonce_url( admin_url( 'admin-post.php?action=myls_gbp_disconnect' ), 'myls_gbp_disconnect' );

		$gbp_status = sanitize_text_field( $_GET['gbp']       ?? '' );
		$gbp_error  = sanitize_text_field( $_GET['gbp_error'] ?? '' );

		$mask = function( string $k ) : string {
			$k = trim( $k );
			if ( $k === '' ) return '';
			$len = strlen( $k );
			if ( $len <= 8 ) return str_repeat( '•', max( 0, $len - 2 ) ) . substr( $k, -2 );
			return substr( $k, 0, 4 ) . str_repeat( '•', $len - 8 ) . substr( $k, -4 );
		};
		?>

		<style>
			#gbp-photos-wrap { font-size: 14px; }
			#gbp-photos-wrap .gbp-section { margin-bottom: 24px; }
			#gbp-photos-wrap .gbp-section h3 {
				margin: 0 0 8px;
				font-size: 15px;
				display: flex;
				align-items: center;
				gap: 6px;
				flex-wrap: wrap;
			}
			#gbp-photos-wrap .status-badge {
				display: inline-flex;
				align-items: center;
				gap: 5px;
				font-size: 12px;
				font-weight: 700;
				padding: 3px 10px;
				border-radius: 20px;
				text-transform: uppercase;
				letter-spacing: .04em;
			}
			#gbp-photos-wrap .status-badge.connected    { background: #d1e7dd; color: #0f5132; }
			#gbp-photos-wrap .status-badge.disconnected { background: #f8d7da; color: #842029; }
			#gbp-photos-wrap .status-badge.cached       { background: #fff3cd; color: #664d03; font-size: 11px; }
			#gbp-photos-wrap .creds-grid {
				display: grid;
				grid-template-columns: 1fr 1fr;
				gap: 12px;
			}
			@media (max-width: 900px) {
				#gbp-photos-wrap .creds-grid { grid-template-columns: 1fr; }
			}
			#gbp-photos-wrap .form-row { display: flex; flex-direction: column; gap: 4px; }
			#gbp-photos-wrap .form-row label { font-weight: 600; font-size: 13px; }
			#gbp-photos-wrap .form-row input {
				padding: 7px 10px;
				border: 1px solid #ced4da;
				border-radius: 6px;
				font-size: 13px;
				width: 100%;
				box-sizing: border-box;
			}
			#gbp-photos-wrap .quota-notice {
				background: #fff8e1;
				border: 1px solid #ffe082;
				border-radius: 8px;
				padding: 12px 14px;
				font-size: 13px;
				margin-bottom: 14px;
				line-height: 1.5;
			}
			#gbp-photos-wrap .quota-notice strong { color: #6d4c00; }
			#gbp-photos-wrap .loc-selector {
				display: flex;
				gap: 10px;
				align-items: flex-end;
				flex-wrap: wrap;
			}
			#gbp-photos-wrap .loc-selector select {
				flex: 1 1 200px;
				padding: 7px 10px;
				border: 1px solid #ced4da;
				border-radius: 6px;
				font-size: 13px;
				min-width: 180px;
			}
			#gbp-photos-wrap .saved-location-bar {
				display: flex;
				align-items: center;
				gap: 10px;
				background: #e8f4fd;
				border: 1px solid #b6d4fe;
				border-radius: 8px;
				padding: 10px 14px;
				font-size: 13px;
				flex-wrap: wrap;
				margin-bottom: 14px;
			}
			#gbp-photos-wrap .saved-location-bar .loc-label { font-weight: 600; }
			/* Photo grid */
			#gbp-photo-grid {
				display: grid;
				grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
				gap: 12px;
				margin-top: 14px;
			}
			.gbp-photo-card {
				border: 2px solid #dee2e6;
				border-radius: 8px;
				overflow: hidden;
				background: #fff;
				cursor: pointer;
				transition: border-color .15s, box-shadow .15s;
				position: relative;
			}
			.gbp-photo-card.selected { border-color: #0d6efd; box-shadow: 0 0 0 3px rgba(13,110,253,.2); }
			.gbp-photo-card.imported { border-color: #198754; opacity: .7; cursor: default; }
			.gbp-photo-card img {
				display: block;
				width: 100%;
				height: 130px;
				object-fit: cover;
			}
			.gbp-photo-card .card-foot {
				padding: 6px 8px;
				font-size: 11px;
				color: #6c757d;
				display: flex;
				justify-content: space-between;
				align-items: center;
			}
			.gbp-photo-card .check-indicator {
				position: absolute;
				top: 6px;
				left: 6px;
				width: 20px;
				height: 20px;
				border-radius: 50%;
				background: #0d6efd;
				color: #fff;
				font-size: 12px;
				display: none;
				align-items: center;
				justify-content: center;
				font-weight: 700;
			}
			.gbp-photo-card.selected .check-indicator { display: flex; }
			.gbp-photo-card .imported-badge {
				position: absolute;
				top: 6px;
				right: 6px;
				background: #198754;
				color: #fff;
				font-size: 10px;
				font-weight: 700;
				padding: 2px 6px;
				border-radius: 10px;
				text-transform: uppercase;
			}
			#gbp-import-log {
				background: #212529;
				color: #adb5bd;
				font-family: monospace;
				font-size: 12px;
				padding: 12px;
				border-radius: 8px;
				max-height: 180px;
				overflow-y: auto;
				white-space: pre-wrap;
				margin-top: 12px;
				display: none;
			}
			#gbp-import-log.visible { display: block; }
			#gbp-import-progress {
				height: 6px;
				background: #e9ecef;
				border-radius: 4px;
				overflow: hidden;
				margin-top: 8px;
				display: none;
			}
			#gbp-import-progress.visible { display: block; }
			#gbp-import-progress-bar {
				height: 100%;
				background: #0d6efd;
				width: 0%;
				transition: width .2s ease;
			}
			.gbp-toolbar {
				display: flex;
				gap: 8px;
				align-items: center;
				flex-wrap: wrap;
				margin-bottom: 10px;
			}
			.gbp-count-badge {
				background: #0d6efd;
				color: #fff;
				font-size: 11px;
				font-weight: 700;
				padding: 2px 8px;
				border-radius: 10px;
			}
			.gbp-spinner {
				display: inline-block;
				width: 16px;
				height: 16px;
				border: 2px solid #dee2e6;
				border-top-color: #0d6efd;
				border-radius: 50%;
				animation: gbp-spin .7s linear infinite;
				vertical-align: middle;
				margin-left: 6px;
			}
			@keyframes gbp-spin { to { transform: rotate(360deg); } }
			#gbp-error-box {
				background: #f8d7da;
				border: 1px solid #f5c2c7;
				border-radius: 8px;
				padding: 12px 14px;
				font-size: 13px;
				line-height: 1.6;
				color: #842029;
				display: none;
				margin-top: 10px;
			}
			#gbp-error-box.visible { display: block; }
			#gbp-error-box a { color: #842029; font-weight: 600; }
		</style>

		<div id="gbp-photos-wrap">

			<?php if ( $gbp_status === 'connected' ) : ?>
				<div class="notice notice-success" style="margin:0 0 16px;"><p>✓ Google Business Profile connected successfully.</p></div>
			<?php elseif ( $gbp_status === 'disconnected' ) : ?>
				<div class="notice notice-info" style="margin:0 0 16px;"><p>GBP disconnected.</p></div>
			<?php elseif ( $gbp_error ) : ?>
				<div class="notice notice-error" style="margin:0 0 16px;"><p><strong>Error:</strong> <?php echo esc_html( urldecode( $gbp_error ) ); ?></p></div>
			<?php endif; ?>

			<!-- ── SECTION 1: Credentials ──────────────────────────────── -->
			<div class="gbp-section cardish">
				<h3>
					<span class="dashicons dashicons-lock" style="color:#6c757d;"></span>
					OAuth Credentials
					<?php if ( $connected ) : ?>
						<span class="status-badge connected">● Connected</span>
					<?php else : ?>
						<span class="status-badge disconnected">○ Not Connected</span>
					<?php endif; ?>
				</h3>
				<p class="muted" style="margin:0 0 14px;">
					Enter the Client ID and Client Secret from your Google Cloud Console OAuth 2.0 credential.
					The Redirect URI must match exactly what you entered in Cloud Console.
				</p>

				<form method="post">
					<?php wp_nonce_field( 'myls_gbp_creds_save' ); ?>
					<div class="creds-grid">
						<div class="form-row">
							<label for="gbp_client_id">Client ID</label>
							<input type="text" id="gbp_client_id" name="myls_gbp_client_id"
								placeholder="123456789-abc.apps.googleusercontent.com"
								value="">
							<?php if ( $client_id ) : ?>
								<span class="muted" style="font-size:11px;">Saved: <?php echo esc_html( $mask( $client_id ) ); ?></span>
							<?php endif; ?>
						</div>
						<div class="form-row">
							<label for="gbp_client_secret">Client Secret</label>
							<input type="text" id="gbp_client_secret" name="myls_gbp_client_secret"
								placeholder="GOCSPX-xxxxxxxxxxxxxxxxxxxx"
								value="">
							<?php if ( $client_secret ) : ?>
								<span class="muted" style="font-size:11px;">Saved: <?php echo esc_html( $mask( $client_secret ) ); ?></span>
							<?php endif; ?>
						</div>
						<div class="form-row" style="grid-column:1/-1;">
							<label for="gbp_redirect_uri">Redirect URI <span class="muted">(must match Cloud Console exactly)</span></label>
							<input type="text" id="gbp_redirect_uri" name="myls_gbp_redirect_uri"
								value="<?php echo esc_attr( $redirect_uri ); ?>">
						</div>
					</div>
					<div style="margin-top:14px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
						<button type="submit" name="myls_gbp_creds_save" class="btn btn-primary">Save Credentials</button>
						<?php if ( $client_id && $client_secret ) : ?>
							<?php if ( ! $connected ) : ?>
								<a href="<?php echo esc_url( $connect_url ); ?>" class="btn btn-primary" style="background:#198754;border-color:#198754;">
									<span class="dashicons dashicons-google" style="font-size:15px;width:15px;height:15px;line-height:15px;margin-right:4px;"></span>
									Connect Google Business Profile
								</a>
							<?php else : ?>
								<a href="<?php echo esc_url( $disconnect_url ); ?>"
									class="btn btn-outline-secondary"
									onclick="return confirm('Disconnect GBP? You can reconnect at any time.');">
									Disconnect
								</a>
							<?php endif; ?>
						<?php else : ?>
							<span class="muted" style="font-size:12px;">↑ Save credentials first to enable Connect button</span>
						<?php endif; ?>
					</div>
				</form>
			</div>

			<?php if ( $connected ) : ?>

			<!-- ── SECTION 2: Account + Location ───────────────────────── -->
			<div class="gbp-section cardish">
				<h3>
					<span class="dashicons dashicons-location" style="color:#0d6efd;"></span>
					Select Account &amp; Location
					<?php if ( $accounts_cached ) : ?>
						<span class="status-badge cached">⚡ Cached</span>
					<?php endif; ?>
				</h3>

				<!-- Quota notice — always visible in this section -->
				<div class="quota-notice">
					<strong>⚠ API Quota Note:</strong>
					The My Business Account Management API has low default quotas for new Google Cloud projects
					(sometimes as low as 1 request/minute). Accounts and locations are <strong>cached for 30 minutes</strong>
					once loaded to protect your quota. Use the <em>Load Accounts</em> button below — do not refresh the page repeatedly.
					If you see a quota error, visit
					<a href="https://console.cloud.google.com/apis/api/mybusinessaccountmanagement.googleapis.com/quotas" target="_blank" rel="noopener">
						Cloud Console → Quotas
					</a> and request an increase.
				</div>

				<?php if ( $saved_location && $saved_label ) : ?>
					<div class="saved-location-bar">
						<span class="dashicons dashicons-yes-alt" style="color:#198754;font-size:18px;width:18px;height:18px;line-height:18px;"></span>
						<span>Active location:</span>
						<span class="loc-label"><?php echo esc_html( $saved_label ); ?></span>
						<div style="margin-left:auto;display:flex;gap:6px;">
							<button type="button" class="btn btn-outline-secondary" id="gbp-change-location" style="font-size:12px;padding:.3rem .6rem;">
								Change Location
							</button>
							<button type="button" class="btn btn-outline-secondary" id="gbp-refresh-cache" style="font-size:12px;padding:.3rem .6rem;" title="Clear cached accounts/locations and reload fresh from Google">
								↺ Refresh Cache
							</button>
						</div>
					</div>
				<?php endif; ?>

				<div id="gbp-location-picker" <?php echo ( $saved_location && $saved_label ) ? 'style="display:none;"' : ''; ?>>
					<p class="muted" style="margin:0 0 14px;font-size:13px;">
						Four ways to connect your location — try in order, earlier options use less quota.
					</p>

					<!-- Option 1: Load via API -->
					<div style="border:1px solid #dee2e6;border-radius:8px;padding:12px 14px;margin-bottom:10px;">
						<div style="font-weight:700;font-size:12px;color:#0d6efd;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;">
							Option 1 — Load via API
						</div>
						<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
							<button type="button" id="gbp-load-accounts-btn" class="btn btn-primary">
								Load Accounts
							</button>
							<?php if ( $accounts_cached ) : ?>
								<span class="muted" style="font-size:12px;">⚡ Cached — click ↺ Refresh Cache above to reload</span>
							<?php else : ?>
								<span class="muted" style="font-size:12px;">Fetches accounts then locations. Results cached 30 min.</span>
							<?php endif; ?>
						</div>
					</div>

					<!-- Option 2: Business Name Search -->
					<div style="border:1px solid #dee2e6;border-radius:8px;padding:12px 14px;margin-bottom:10px;">
						<div style="font-weight:700;font-size:12px;color:#198754;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;">
							Option 2 — Business Name Search <span style="font-weight:400;color:#6c757d;font-size:11px;text-transform:none;">(different API endpoint — avoids Account Management quota)</span>
						</div>
						<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
							<div style="display:flex;flex-direction:column;gap:2px;">
								<label style="font-size:11px;font-weight:600;color:#6c757d;margin:0;">Business Name &amp; City</label>
								<input type="text" id="gbp-name-search"
									placeholder="e.g. Acme Plumbing Tampa FL"
									style="padding:5px 8px;border:1px solid #ced4da;border-radius:6px;font-size:13px;width:300px;">
							</div>
							<div style="display:flex;flex-direction:column;gap:2px;">
								<label style="font-size:11px;font-weight:600;color:transparent;margin:0;">.</label>
								<button type="button" id="gbp-lookup-by-name" class="btn btn-primary" style="background:#198754;border-color:#198754;">
									Search
								</button>
							</div>
						</div>
						<div id="gbp-name-results" style="margin-top:10px;display:none;"></div>
						<p class="muted" style="margin:6px 0 0;font-size:11px;">
							Searches the Business Information API using the <code>googleLocations:search</code> endpoint.
							The listing must be claimed and managed by your connected Google account to resolve the Location ID.
						</p>
					</div>

					<!-- Option 3: Store code lookup -->
					<div style="border:1px solid #dee2e6;border-radius:8px;padding:12px 14px;margin-bottom:10px;">
						<div style="font-weight:700;font-size:12px;color:#6f42c1;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;">
							Option 3 — Store Code Lookup <span style="font-weight:400;color:#6c757d;font-size:11px;text-transform:none;">(accounts/- wildcard — may also hit quota)</span>
						</div>
						<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
							<div style="display:flex;flex-direction:column;gap:2px;">
								<label style="font-size:11px;font-weight:600;color:#6c757d;margin:0;">Store Code</label>
								<input type="text" id="gbp-store-code"
									placeholder="e.g. TAMPA-MAIN or LOC001"
									style="padding:5px 8px;border:1px solid #ced4da;border-radius:6px;font-size:13px;width:220px;font-family:monospace;">
							</div>
							<div style="display:flex;flex-direction:column;gap:2px;">
								<label style="font-size:11px;font-weight:600;color:transparent;margin:0;">.</label>
								<button type="button" id="gbp-lookup-store-code" class="btn btn-primary" style="background:#6f42c1;border-color:#6f42c1;">
									Find Location
								</button>
							</div>
							<span id="gbp-store-code-result" class="muted" style="font-size:12px;display:none;"></span>
						</div>
						<p class="muted" style="margin:6px 0 0;font-size:11px;">
							Find your store code: GBP → Business Profile Manager → location → Info → Store code.
						</p>
					</div>

					<!-- Option 4: List All / Paste Location ID -->
					<div style="border:1px solid #dee2e6;border-radius:8px;padding:12px 14px;margin-bottom:10px;">
						<div style="font-weight:700;font-size:12px;color:#dc3545;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;">
							Option 4 — List All My Locations <span style="font-weight:400;color:#6c757d;font-size:11px;text-transform:none;">(or paste Location ID directly)</span>
						</div>
						<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
							<button type="button" id="gbp-list-all-locations" class="btn btn-primary" style="background:#dc3545;border-color:#dc3545;">
								List All My Locations
							</button>
							<span class="muted" style="font-size:12px;">One API call — lists every location managed by your connected account.</span>
						</div>
						<div id="gbp-all-locations-results" style="display:none;margin-bottom:10px;"></div>
						<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding-top:8px;border-top:1px solid #f0f0f0;">
							<span class="muted" style="font-size:12px;white-space:nowrap;">Or paste directly:</span>
							<div style="display:flex;flex-direction:column;gap:2px;">
								<label style="font-size:11px;font-weight:600;color:#6c757d;margin:0;">Location ID (full resource name — account ID will be derived automatically)</label>
								<input type="text" id="gbp-manual-location-id"
									placeholder="accounts/123456789/locations/AbCdEfGhIj"
									style="padding:5px 8px;border:1px solid #ced4da;border-radius:6px;font-size:12px;width:340px;font-family:monospace;">
							</div>
							<div style="display:flex;flex-direction:column;gap:2px;">
								<label style="font-size:11px;font-weight:600;color:transparent;margin:0;">.</label>
								<button type="button" id="gbp-use-manual-ids" class="btn btn-primary" style="background:#dc3545;border-color:#dc3545;">
									Use This ID
								</button>
							</div>
						</div>
					</div>

					<div class="loc-selector" id="gbp-dropdowns" style="display:none;">
						<div style="flex:1 1 200px;">
							<label style="font-weight:600;font-size:13px;display:block;margin-bottom:4px;">Account</label>
							<select id="gbp-account-select">
								<option value="">— Select Account —</option>
							</select>
						</div>
						<div style="flex:1 1 200px;">
							<label style="font-weight:600;font-size:13px;display:block;margin-bottom:4px;">Location</label>
							<select id="gbp-location-select" disabled>
								<option value="">— Select account first —</option>
							</select>
						</div>
						<div>
							<button type="button" id="gbp-save-location" class="btn btn-primary" disabled>
								Use This Location
							</button>
						</div>
					</div>

					<div id="gbp-location-status" class="muted" style="margin-top:8px;font-size:12px;"></div>

					<!-- Error box with quota guidance -->
					<div id="gbp-error-box">
						<strong>Error loading accounts.</strong><br>
						<span id="gbp-error-msg"></span>
						<div style="margin-top:8px;">
							<strong>If this is a quota error:</strong>
							Go to <a href="https://console.cloud.google.com/apis/api/mybusinessaccountmanagement.googleapis.com/quotas" target="_blank" rel="noopener">
								Google Cloud Console → My Business Account Management API → Quotas
							</a> and request a quota increase.
							The API has very low limits for new projects (~1 QPM). Once you have quota, accounts and locations
							will be cached for 30 minutes so you won't hit limits during normal use.
						</div>
					</div>
				</div>
			</div>

			<!-- ── SECTION 3: Photos ──────────────────────────────────── -->
			<div class="gbp-section cardish" id="gbp-photos-section">
				<h3>
					<span class="dashicons dashicons-format-gallery" style="color:#0d6efd;"></span>
					GBP Photos
					<span id="gbp-photo-count-badge" class="gbp-count-badge" style="display:none;"></span>
				</h3>

				<?php if ( $saved_location ) : ?>
					<div class="gbp-toolbar">
						<button type="button" id="gbp-fetch-photos" class="btn btn-primary">
							<span class="dashicons dashicons-image-rotate" style="font-size:15px;width:15px;height:15px;line-height:15px;margin-right:4px;"></span>
							Fetch Photos from GBP
						</button>
						<button type="button" id="gbp-select-all"    class="btn" style="display:none;">Select All</button>
						<button type="button" id="gbp-deselect-all"  class="btn" style="display:none;">Deselect All</button>
						<button type="button" id="gbp-import-selected" class="btn btn-primary" style="display:none;background:#198754;border-color:#198754;">
							<span class="dashicons dashicons-download" style="font-size:15px;width:15px;height:15px;line-height:15px;margin-right:4px;"></span>
							Import Selected to Media Library
						</button>
						<span id="gbp-selected-count" class="muted" style="font-size:12px;display:none;"></span>
					</div>
					<p class="muted" style="margin:0 0 4px;font-size:12px;">
						Fetching photos from: <strong><?php echo esc_html( $saved_label ); ?></strong>
					</p>
					<div id="gbp-load-more-wrap" style="display:none;margin-top:10px;">
						<button type="button" id="gbp-load-more" class="btn">Load More Photos</button>
						<span id="gbp-load-more-hint" class="muted" style="font-size:12px;margin-left:8px;"></span>
					</div>
				<?php else : ?>
					<p class="muted">Select an account and location above to fetch photos.</p>
				<?php endif; ?>

				<div id="gbp-photo-grid"></div>
				<div id="gbp-import-progress"><div id="gbp-import-progress-bar"></div></div>
				<div id="gbp-import-log"></div>
			</div>

			<?php else : ?>
				<div class="cardish" style="text-align:center;padding:32px;color:#6c757d;">
					<span class="dashicons dashicons-google" style="font-size:40px;width:40px;height:40px;line-height:40px;color:#dee2e6;"></span>
					<p style="margin:12px 0 4px;font-weight:600;">Not Connected</p>
					<p style="margin:0;font-size:13px;">Save your credentials above and click Connect Google Business Profile.</p>
				</div>
			<?php endif; ?>

		</div><!-- #gbp-photos-wrap -->

		<script>
		(function () {
			'use strict';

			const AJAX           = <?php echo wp_json_encode( $ajax_url ); ?>;
			const NONCE          = <?php echo wp_json_encode( $nonce ); ?>;
			const SAVED_ACCOUNT  = <?php echo wp_json_encode( $saved_account ); ?>;
			const SAVED_LOCATION = <?php echo wp_json_encode( $saved_location ); ?>;

			// ── Helpers ────────────────────────────────────────────────
			function $id(id) { return document.getElementById(id); }

			function spinner( el, show ) {
				if ( ! el ) return;
				let sp = el.querySelector('.gbp-spinner');
				if ( show ) {
					if ( ! sp ) { sp = document.createElement('span'); sp.className = 'gbp-spinner'; el.appendChild(sp); }
					el.disabled = true;
				} else {
					if ( sp ) sp.remove();
					el.disabled = false;
				}
			}

			async function apiFetch( action, data = {} ) {
				const body = new URLSearchParams({ action, nonce: NONCE, ...data });
				const res  = await fetch( AJAX, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body,
				});
				const json = await res.json();
				if ( ! json || json.success !== true ) {
					throw new Error( json?.data || 'Request failed' );
				}
				return json.data;
			}

			function showError( msg ) {
				const box   = $id('gbp-error-box');
				const msgEl = $id('gbp-error-msg');
				if ( ! box ) return;
				if ( msgEl ) msgEl.textContent = msg;
				box.classList.add('visible');
			}

			function hideError() {
				const box = $id('gbp-error-box');
				if ( box ) box.classList.remove('visible');
			}

			function logLine( msg ) {
				const el = $id('gbp-import-log');
				if ( ! el ) return;
				el.classList.add('visible');
				el.textContent += msg + '\n';
				el.scrollTop = el.scrollHeight;
			}

			function clearLog() {
				const el = $id('gbp-import-log');
				if ( el ) { el.textContent = ''; el.classList.remove('visible'); }
			}

			function setProgress( pct ) {
				const wrap = $id('gbp-import-progress');
				const bar  = $id('gbp-import-progress-bar');
				if ( ! wrap || ! bar ) return;
				wrap.classList.add('visible');
				bar.style.width = pct + '%';
				if ( pct >= 100 ) setTimeout( () => wrap.classList.remove('visible'), 1000 );
			}

			// ── Account / Location dropdowns ───────────────────────────

			const loadAccountsBtn = $id('gbp-load-accounts-btn');
			const accountSel      = $id('gbp-account-select');
			const locationSel     = $id('gbp-location-select');
			const saveLocBtn      = $id('gbp-save-location');
			const locStatus       = $id('gbp-location-status');
			const dropdownWrap    = $id('gbp-dropdowns');
			const changeBtn       = $id('gbp-change-location');
			const refreshCacheBtn = $id('gbp-refresh-cache');
			const pickerWrap      = $id('gbp-location-picker');
			const manualLocationEl  = $id('gbp-manual-location-id');
			const useManualBtn      = $id('gbp-use-manual-ids');
			const listAllBtn        = $id('gbp-list-all-locations');
			const allLocResults     = $id('gbp-all-locations-results');
			const nameSearchEl    = $id('gbp-name-search');
			const lookupNameBtn   = $id('gbp-lookup-by-name');
			const nameResults     = $id('gbp-name-results');
			const storeCodeEl     = $id('gbp-store-code');
			const lookupStoreBtn  = $id('gbp-lookup-store-code');
			const storeCodeResult = $id('gbp-store-code-result');

			// Pre-populate manual input from saved location
			if ( manualLocationEl && SAVED_LOCATION ) manualLocationEl.value = SAVED_LOCATION;

			/** Returns the active location ID — manual input takes priority if filled. */
			function getActiveLocationId() {
				const manual = manualLocationEl?.value.trim() || '';
				return manual || SAVED_LOCATION;
			}

			/** Shared handler: lookup succeeded — save and reload. */
			async function saveLookupResult( data, labelSuffix = '' ) {
				await apiFetch( 'myls_gbp_save_location', {
					account_id:     data.account_id,
					location_id:    data.location_id,
					location_label: data.location_label + labelSuffix,
				});
				window.location.reload();
			}

			// ── Option 2: Business Name Search ────────────────────────
			if ( lookupNameBtn ) {
				lookupNameBtn.addEventListener('click', async () => {
					const q = nameSearchEl?.value.trim() || '';
					if ( q.length < 3 ) { alert('Please enter at least 3 characters.'); return; }

					if ( nameResults ) { nameResults.style.display = 'none'; nameResults.innerHTML = ''; }
					hideError();
					spinner( lookupNameBtn, true );

					try {
						const data = await apiFetch( 'myls_gbp_lookup_by_name', { query: q } );
						spinner( lookupNameBtn, false );

						if ( ! nameResults ) return;
						nameResults.style.display = '';

						if ( ! data.matches || data.matches.length === 0 ) {
							nameResults.innerHTML = '<p style="color:#dc3545;margin:0;font-size:13px;">No matches found. Try adding city or address.</p>';
							return;
						}

						// Build a pick-list of results
						let html = '<p style="font-size:12px;margin:0 0 6px;font-weight:600;">Select your location:</p>';
						data.matches.forEach( (m, i) => {
							const managed = m.managed && m.location_id;
							const badge   = managed
								? '<span style="background:#d1e7dd;color:#0a3622;font-size:10px;padding:1px 6px;border-radius:4px;margin-left:6px;">✓ resolved</span>'
								: '<span style="background:#fff3cd;color:#664d03;font-size:10px;padding:1px 6px;border-radius:4px;margin-left:6px;">⚠ quota limited — use Option 4 to paste ID</span>';
							const addr    = m.address ? `<span style="color:#6c757d;font-size:11px;"> — ${m.address}</span>` : '';
							const btn     = managed
								? `<button type="button" class="btn btn-primary gbp-pick-result" data-idx="${i}" style="padding:3px 10px;font-size:12px;margin-left:8px;">Use This</button>`
								: '';
							html += `<div style="display:flex;align-items:center;padding:6px 0;border-bottom:1px solid #f0f0f0;">`
							      + `<span style="font-size:13px;">${m.title}${badge}${addr}</span>${btn}</div>`;
						});
						nameResults.innerHTML = html;

						// Wire up "Use This" buttons
						nameResults.querySelectorAll('.gbp-pick-result').forEach( btn => {
							btn.addEventListener('click', async () => {
								const m = data.matches[ parseInt( btn.dataset.idx ) ];
								spinner( btn, true );
								try {
									await saveLookupResult( { account_id: m.account_id, location_id: m.location_id, location_label: m.title }, ' [name search]' );
								} catch(e) {
									alert('Error saving: ' + e.message);
									spinner( btn, false );
								}
							});
						});



					} catch ( e ) {
						if ( nameResults ) {
							nameResults.style.display = '';
							nameResults.innerHTML = '<p style="color:#dc3545;margin:0;font-size:13px;">✗ ' + e.message + '</p>';
						}
						spinner( lookupNameBtn, false );
					}
				});
				if ( nameSearchEl ) {
					nameSearchEl.addEventListener('keydown', e => {
						if ( e.key === 'Enter' ) { e.preventDefault(); lookupNameBtn.click(); }
					});
				}
			}

			// ── Option 3: Store Code Lookup ────────────────────────────
			if ( lookupStoreBtn ) {
				lookupStoreBtn.addEventListener('click', async () => {
					const code = storeCodeEl?.value.trim() || '';
					if ( ! code ) { alert('Please enter a store code first.'); return; }

					if ( storeCodeResult ) { storeCodeResult.style.display = ''; storeCodeResult.textContent = 'Looking up…'; storeCodeResult.style.color = '#6c757d'; }
					hideError();
					spinner( lookupStoreBtn, true );

					try {
						const data = await apiFetch( 'myls_gbp_lookup_store_code', { store_code: code } );
						if ( storeCodeResult ) {
							storeCodeResult.textContent = '✓ Found: ' + data.location_label + ' — saving…';
							storeCodeResult.style.color = '#198754';
						}
						await saveLookupResult( data, ' [store: ' + data.store_code + ']' );
					} catch ( e ) {
						if ( storeCodeResult ) {
							storeCodeResult.textContent = '✗ ' + e.message;
							storeCodeResult.style.color = '#dc3545';
						}
						spinner( lookupStoreBtn, false );
					}
				});
				if ( storeCodeEl ) {
					storeCodeEl.addEventListener('keydown', e => {
						if ( e.key === 'Enter' ) { e.preventDefault(); lookupStoreBtn.click(); }
					});
				}
			}

			if ( changeBtn ) {
				changeBtn.addEventListener('click', () => {
					changeBtn.closest('.saved-location-bar').style.display = 'none';
					if ( pickerWrap ) pickerWrap.style.display = '';
				});
			}

			// "Use This ID" — accepts location ID only, account_id derived server-side
			if ( useManualBtn ) {
				useManualBtn.addEventListener('click', async () => {
					const locationId = manualLocationEl?.value.trim() || '';

					if ( ! locationId ) {
						alert('Please paste a Location ID.\n\nFormat: accounts/123456789/locations/AbCdEfGhIj');
						return;
					}

					if ( ! locationId.includes('/locations/') ) {
						alert('That doesn\'t look like a full Location ID.\n\nIt should look like: accounts/123456789/locations/AbCdEfGhIj\n\nThe account ID is the part before /locations/.');
						return;
					}

					const labelFallback = locationId.split('/').pop() || locationId;

					spinner( useManualBtn, true );
					try {
						// account_id is intentionally omitted — server derives it from location_id
						await apiFetch('myls_gbp_save_location', {
							location_id:    locationId,
							location_label: labelFallback + ' (manual)',
						});
						window.location.reload();
					} catch ( e ) {
						alert('Error saving: ' + e.message);
						spinner( useManualBtn, false );
					}
				});
			}

			// "List All My Locations" — single wildcard call, pick from results
			if ( listAllBtn ) {
				listAllBtn.addEventListener('click', async () => {
					if ( allLocResults ) { allLocResults.style.display = 'none'; allLocResults.innerHTML = ''; }
					hideError();
					spinner( listAllBtn, true );

					try {
						const data = await apiFetch( 'myls_gbp_list_all_locations', {} );
						spinner( listAllBtn, false );

						if ( ! allLocResults ) return;
						allLocResults.style.display = '';

						if ( ! data.locations?.length ) {
							allLocResults.innerHTML = '<p style="color:#dc3545;margin:0;font-size:13px;">No locations returned. Make sure the connected Google account manages at least one GBP listing.</p>';
							return;
						}

						let html = `<p style="font-size:12px;margin:0 0 6px;font-weight:600;">${data.locations.length} location(s) found — click to select:</p>`;
						data.locations.forEach( (loc, i) => {
							const addr = loc.address ? `<span style="color:#6c757d;font-size:11px;"> — ${loc.address}</span>` : '';
							html += `<div style="display:flex;align-items:center;padding:6px 0;border-bottom:1px solid #f0f0f0;">`
							      + `<span style="font-size:13px;flex:1;">${loc.title}${addr}</span>`
							      + `<button type="button" class="btn btn-primary gbp-pick-all-loc" data-idx="${i}" style="padding:3px 10px;font-size:12px;margin-left:8px;">Use This</button>`
							      + `</div>`;
						});
						allLocResults.innerHTML = html;

						allLocResults.querySelectorAll('.gbp-pick-all-loc').forEach( btn => {
							btn.addEventListener('click', async () => {
								const loc = data.locations[ parseInt( btn.dataset.idx ) ];
								spinner( btn, true );
								try {
									await saveLookupResult( { account_id: loc.account_id, location_id: loc.location_id, location_label: loc.title }, '' );
								} catch(e) {
									alert('Error saving: ' + e.message);
									spinner( btn, false );
								}
							});
						});

					} catch ( e ) {
						if ( allLocResults ) {
							allLocResults.style.display = '';
							allLocResults.innerHTML = '<p style="color:#dc3545;margin:0;font-size:13px;">✗ ' + e.message + '</p>';
						}
						spinner( listAllBtn, false );
					}
				});
			}

			async function loadAccounts( forceRefresh = false ) {
				if ( ! accountSel ) return;
				hideError();
				if ( locStatus ) locStatus.textContent = 'Loading accounts…';
				if ( dropdownWrap ) dropdownWrap.style.display = 'none';

				try {
					const data = await apiFetch( 'myls_gbp_get_accounts', {
						force_refresh: forceRefresh ? '1' : '0',
					});

					const accounts = data.accounts || [];
					accountSel.innerHTML = '<option value="">— Select Account —</option>';
					accounts.forEach( acct => {
						const opt        = document.createElement('option');
						opt.value        = acct.id;
						opt.textContent  = acct.label + ( acct.type ? ' (' + acct.type + ')' : '' );
						if ( acct.id === SAVED_ACCOUNT ) opt.selected = true;
						accountSel.appendChild(opt);
					});

					if ( dropdownWrap ) dropdownWrap.style.display = '';
					const cacheNote = data._from_cache ? ' (from cache)' : ' (live)';
					if ( locStatus ) locStatus.textContent = accounts.length + ' account(s) loaded' + cacheNote;

					// Auto-load locations for saved account
					if ( SAVED_ACCOUNT && accountSel.value === SAVED_ACCOUNT ) {
						await loadLocations( SAVED_ACCOUNT, forceRefresh );
					}
				} catch ( e ) {
					showError( e.message );
					if ( locStatus ) locStatus.textContent = '';
					if ( dropdownWrap ) dropdownWrap.style.display = '';
				}
			}

			async function loadLocations( accountId, forceRefresh = false ) {
				if ( ! locationSel ) return;
				locationSel.innerHTML = '<option value="">Loading locations…</option>';
				locationSel.disabled  = true;
				if ( saveLocBtn ) saveLocBtn.disabled = true;

				try {
					const data      = await apiFetch( 'myls_gbp_get_locations', {
						account_id:    accountId,
						force_refresh: forceRefresh ? '1' : '0',
					});
					const locations = data.locations || [];

					locationSel.innerHTML = '<option value="">— Select Location —</option>';
					locations.forEach( loc => {
						const opt          = document.createElement('option');
						opt.value          = loc.id;
						opt.dataset.label  = loc.label;
						opt.textContent    = loc.label;
						if ( loc.id === SAVED_LOCATION ) opt.selected = true;
						locationSel.appendChild(opt);
					});
					locationSel.disabled = false;
					if ( saveLocBtn ) saveLocBtn.disabled = ( locationSel.value === '' );
				} catch ( e ) {
					locationSel.innerHTML = '<option value="">Error loading locations</option>';
					showError( e.message );
				}
			}

			// Load accounts on button click (NOT on page load — prevents quota hits)
			if ( loadAccountsBtn ) {
				loadAccountsBtn.addEventListener('click', () => {
					spinner( loadAccountsBtn, true );
					loadAccounts( false ).finally( () => spinner( loadAccountsBtn, false ) );
				});
			}

			// Refresh cache button
			if ( refreshCacheBtn ) {
				refreshCacheBtn.addEventListener('click', async () => {
					spinner( refreshCacheBtn, true );
					try {
						await apiFetch( 'myls_gbp_clear_cache' );
						// Now load accounts with force refresh
						await loadAccounts( true );
					} catch ( e ) {
						showError( e.message );
					} finally {
						spinner( refreshCacheBtn, false );
					}
				});
			}

			if ( accountSel ) {
				accountSel.addEventListener('change', () => {
					if ( accountSel.value ) loadLocations( accountSel.value );
				});
			}

			if ( locationSel ) {
				locationSel.addEventListener('change', () => {
					if ( saveLocBtn ) saveLocBtn.disabled = ( locationSel.value === '' );
				});
			}

			if ( saveLocBtn ) {
				saveLocBtn.addEventListener('click', async () => {
					const accountId     = accountSel?.value || '';
					const locationId    = locationSel?.value || '';
					const selectedOpt   = locationSel?.options[locationSel.selectedIndex];
					const locationLabel = selectedOpt?.dataset.label || selectedOpt?.textContent || locationId;

					if ( ! accountId || ! locationId ) {
						alert('Please select both an account and a location.');
						return;
					}

					spinner( saveLocBtn, true );
					try {
						await apiFetch('myls_gbp_save_location', {
							account_id:     accountId,
							location_id:    locationId,
							location_label: locationLabel,
						});
						window.location.reload();
					} catch ( e ) {
						if ( locStatus ) locStatus.textContent = 'Error: ' + e.message;
						spinner( saveLocBtn, false );
					}
				});
			}

			// ── Photo Grid ─────────────────────────────────────────────

			let allPhotos     = [];
			let nextPageToken = '';
			let selectedNames = new Set();

			function renderPhotoCard( photo ) {
				const card         = document.createElement('div');
				card.className     = 'gbp-photo-card' + ( photo.already_imported ? ' imported' : '' );
				card.dataset.name  = photo.name;
				card.dataset.googleUrl = photo.google_url;
				card.dataset.desc  = photo.description || '';
				card.dataset.imported = photo.already_imported ? '1' : '0';

				const check       = document.createElement('span');
				check.className   = 'check-indicator';
				check.textContent = '✓';
				card.appendChild(check);

				if ( photo.already_imported ) {
					const badge       = document.createElement('span');
					badge.className   = 'imported-badge';
					badge.textContent = '✓ In Library';
					card.appendChild(badge);
				}

				const img = document.createElement('img');
				img.src   = photo.thumbnail_url || photo.google_url;
				img.alt   = photo.description || 'GBP Photo';
				img.loading = 'lazy';
				card.appendChild(img);

				const foot    = document.createElement('div');
				foot.className = 'card-foot';
				const dateStr = photo.create_time ? new Date( photo.create_time ).toLocaleDateString() : '';
				foot.innerHTML = '<span>' + ( photo.description || 'Photo' ) + '</span><span>' + dateStr + '</span>';
				card.appendChild(foot);

				if ( ! photo.already_imported ) {
					card.addEventListener('click', () => toggleCard( card, photo.name ) );
				}

				return card;
			}

			function toggleCard( card, name ) {
				if ( selectedNames.has( name ) ) {
					selectedNames.delete( name );
					card.classList.remove('selected');
				} else {
					selectedNames.add( name );
					card.classList.add('selected');
				}
				updateSelectionUI();
			}

			function updateSelectionUI() {
				const count     = selectedNames.size;
				const countEl   = $id('gbp-selected-count');
				const importBtn = $id('gbp-import-selected');
				if ( countEl )   { countEl.style.display = count > 0 ? '' : 'none'; countEl.textContent = count + ' selected'; }
				if ( importBtn ) importBtn.style.display = count > 0 ? '' : 'none';
			}

			function appendPhotos( photos ) {
				const grid = $id('gbp-photo-grid');
				if ( ! grid ) return;
				photos.forEach( photo => {
					allPhotos.push( photo );
					grid.appendChild( renderPhotoCard( photo ) );
				});
				const badge     = $id('gbp-photo-count-badge');
				if ( badge ) {
					const importable = allPhotos.filter( p => ! p.already_imported ).length;
					badge.textContent = allPhotos.length + ' photos · ' + importable + ' not yet imported';
					badge.style.display = '';
				}
			}

			const fetchBtn = $id('gbp-fetch-photos');
			if ( fetchBtn ) {
				fetchBtn.addEventListener('click', async () => {
					clearLog();
					const grid = $id('gbp-photo-grid');
					if ( grid ) grid.innerHTML = '';
					allPhotos = []; nextPageToken = ''; selectedNames = new Set();
					updateSelectionUI();
					const badge = $id('gbp-photo-count-badge');
					if ( badge ) badge.style.display = 'none';

					spinner( fetchBtn, true );
					try {
						const data = await apiFetch('myls_gbp_get_photos', {
							location_id: getActiveLocationId(),
							page_token:  '',
						});
						appendPhotos( data.photos || [] );
						nextPageToken = data.next_token || '';

						const loadMoreWrap = $id('gbp-load-more-wrap');
						const loadMoreHint = $id('gbp-load-more-hint');
						if ( loadMoreWrap ) loadMoreWrap.style.display = nextPageToken ? '' : 'none';
						if ( loadMoreHint && nextPageToken ) loadMoreHint.textContent = 'More photos available';

						const selAll  = $id('gbp-select-all');
						const desAll  = $id('gbp-deselect-all');
						if ( selAll )  selAll.style.display  = '';
						if ( desAll )  desAll.style.display  = '';

						if ( ! data.photos.length ) logLine('No photos found for this location.');
					} catch ( e ) {
						logLine('Error fetching photos: ' + e.message);
					} finally {
						spinner( fetchBtn, false );
					}
				});
			}

			const loadMoreBtn = $id('gbp-load-more');
			if ( loadMoreBtn ) {
				loadMoreBtn.addEventListener('click', async () => {
					if ( ! nextPageToken ) return;
					spinner( loadMoreBtn, true );
					try {
						const data = await apiFetch('myls_gbp_get_photos', {
							location_id: getActiveLocationId(),
							page_token:  nextPageToken,
						});
						appendPhotos( data.photos || [] );
						nextPageToken = data.next_token || '';
						if ( ! nextPageToken ) {
							const wrap = $id('gbp-load-more-wrap');
							if ( wrap ) wrap.style.display = 'none';
						}
					} catch ( e ) {
						logLine('Error loading more: ' + e.message);
					} finally {
						spinner( loadMoreBtn, false );
					}
				});
			}

			const selAllBtn  = $id('gbp-select-all');
			const desAllBtn  = $id('gbp-deselect-all');

			if ( selAllBtn ) {
				selAllBtn.addEventListener('click', () => {
					document.querySelectorAll('.gbp-photo-card:not(.imported)').forEach( card => {
						card.classList.add('selected');
						selectedNames.add( card.dataset.name );
					});
					updateSelectionUI();
				});
			}

			if ( desAllBtn ) {
				desAllBtn.addEventListener('click', () => {
					document.querySelectorAll('.gbp-photo-card.selected').forEach( card => card.classList.remove('selected') );
					selectedNames.clear();
					updateSelectionUI();
				});
			}

			// ── Import Selected ────────────────────────────────────────

			const importBtn = $id('gbp-import-selected');
			if ( importBtn ) {
				importBtn.addEventListener('click', async () => {
					if ( selectedNames.size === 0 ) return;
					clearLog();
					spinner( importBtn, true );

					const toImport = allPhotos.filter( p => selectedNames.has( p.name ) && ! p.already_imported );
					const total    = toImport.length;
					let done = 0, imported = 0, skipped = 0, errors = 0;

					logLine('Starting import of ' + total + ' photo(s)…');
					setProgress(0);

					for ( const photo of toImport ) {
						try {
							const result = await apiFetch('myls_gbp_import_photo', {
								google_url:  photo.google_url,
								gbp_name:    photo.name,
								description: photo.description || '',
							});

							const card = document.querySelector('.gbp-photo-card[data-name="' + CSS.escape( photo.name ) + '"]');

							if ( result.status === 'already_exists' ) {
								logLine('↩ Skipped (already in library): ' + photo.name);
								skipped++;
								if ( card ) { card.classList.add('imported'); card.classList.remove('selected'); }
							} else {
								logLine('✓ Imported (ID ' + result.attachment_id + '): ' + photo.name);
								imported++;
								if ( card ) {
									card.classList.add('imported'); card.classList.remove('selected');
									const badge       = document.createElement('span');
									badge.className   = 'imported-badge';
									badge.textContent = '✓ In Library';
									card.appendChild(badge);
								}
							}
						} catch ( e ) {
							logLine('✗ Error: ' + photo.name + ' — ' + e.message);
							errors++;
						}
						done++;
						setProgress( Math.round( done / total * 100 ) );
					}

					selectedNames.clear();
					updateSelectionUI();
					logLine('─────────────────────────');
					logLine('Done. Imported: ' + imported + '  Skipped: ' + skipped + '  Errors: ' + errors);
					spinner( importBtn, false );
				});
			}

		})();
		</script>

		<?php
	},
];
