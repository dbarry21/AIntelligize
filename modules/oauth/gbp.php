<?php
/**
 * Google Business Profile OAuth (Google OAuth 2.0)
 * Path: modules/oauth/gbp.php
 *
 * Provides:
 *  - admin_post actions:
 *      * myls_gbp_oauth_start
 *      * myls_gbp_oauth_cb
 *      * myls_gbp_disconnect
 *  - AJAX handlers:
 *      * myls_gbp_get_accounts      (cached 30 min via transient)
 *      * myls_gbp_get_locations     (cached 30 min per account)
 *      * myls_gbp_save_location
 *      * myls_gbp_get_photos
 *      * myls_gbp_import_photo
 *      * myls_gbp_clear_cache       (manually bust account/location cache)
 *  - Helpers:
 *      * myls_gbp_is_connected()
 *      * myls_gbp_get_access_token()   (auto-refresh)
 *      * myls_gbp_revoke_tokens()
 *      * myls_gbp_api_call()
 *
 * Options used:
 *  - myls_gbp_client_id
 *  - myls_gbp_client_secret
 *  - myls_gbp_redirect_uri
 *  - myls_gbp_access_token
 *  - myls_gbp_refresh_token
 *  - myls_gbp_token_expires       (unix timestamp)
 *  - myls_gbp_account_id          (e.g. accounts/123456789)
 *  - myls_gbp_location_id         (e.g. accounts/123456789/locations/AAABBB)
 *  - myls_gbp_location_label      (human-readable name)
 *
 * Transients (quota protection):
 *  - myls_gbp_accounts_cache          (30 min)
 *  - myls_gbp_locs_{md5_account_id}   (30 min per account)
 *
 * @since 7.5.0
 * @updated 7.5.1 — Added 30-min transient caching for accounts and locations
 *                  to prevent Google API quota exhaustion. Accounts now load
 *                  on-demand (button) rather than on every page load.
 */

if ( ! defined('ABSPATH') ) exit;

// ── API constants ──────────────────────────────────────────────────────────
define( 'MYLS_GBP_OAUTH_AUTH',  'https://accounts.google.com/o/oauth2/v2/auth' );
define( 'MYLS_GBP_OAUTH_TOKEN', 'https://oauth2.googleapis.com/token' );
define( 'MYLS_GBP_OAUTH_REVOKE','https://oauth2.googleapis.com/revoke' );
define( 'MYLS_GBP_SCOPE',       'https://www.googleapis.com/auth/business.manage' );

define( 'MYLS_GBP_ACCOUNTS_API',  'https://mybusinessaccountmanagement.googleapis.com/v1/accounts' );
define( 'MYLS_GBP_LOCATIONS_API', 'https://mybusinessbusinessinformation.googleapis.com/v1/' );
define( 'MYLS_GBP_MEDIA_API',     'https://mybusiness.googleapis.com/v4/' );

/**
 * Cache lifetime for accounts and locations.
 * The My Business Account Management API has very low default quotas (~1 QPM),
 * so we aggressively cache to avoid exhausting them.
 */
define( 'MYLS_GBP_CACHE_TTL', 30 * MINUTE_IN_SECONDS );


// ── Client helpers ─────────────────────────────────────────────────────────

function myls_gbp_client() : array {
	$cid   = (string) get_option( 'myls_gbp_client_id', '' );
	$sec   = (string) get_option( 'myls_gbp_client_secret', '' );
	$redir = (string) get_option( 'myls_gbp_redirect_uri', admin_url( 'admin-post.php?action=myls_gbp_oauth_cb' ) );
	return [ 'id' => $cid, 'secret' => $sec, 'redirect' => $redir ];
}

function myls_gbp_make_state() : string {
	$state = wp_generate_password( 24, false, false );
	set_transient( 'myls_gbp_state_' . $state, get_current_user_id(), 10 * MINUTE_IN_SECONDS );
	return $state;
}

function myls_gbp_check_state( string $state ) : bool {
	$owner = get_transient( 'myls_gbp_state_' . $state );
	if ( ! $owner ) return false;
	delete_transient( 'myls_gbp_state_' . $state );
	return true;
}


// ── Connection status + token store ───────────────────────────────────────

function myls_gbp_is_connected() : bool {
	return (string) get_option( 'myls_gbp_refresh_token', '' ) !== '';
}

function myls_gbp_save_tokens( array $t ) : void {
	if ( ! empty( $t['access_token'] ) ) {
		update_option( 'myls_gbp_access_token', (string) $t['access_token'], false );
	}
	if ( ! empty( $t['refresh_token'] ) ) {
		update_option( 'myls_gbp_refresh_token', (string) $t['refresh_token'], false );
	}
	if ( isset( $t['expires_in'] ) ) {
		$exp = time() + (int) $t['expires_in'] - 30;
		update_option( 'myls_gbp_token_expires', $exp, false );
	}
}

function myls_gbp_get_access_token() : string {
	$access = (string) get_option( 'myls_gbp_access_token', '' );
	$exp    = (int)    get_option( 'myls_gbp_token_expires', 0 );

	if ( $access && $exp > time() ) {
		return $access;
	}

	$refresh = (string) get_option( 'myls_gbp_refresh_token', '' );
	if ( ! $refresh ) return '';

	$client = myls_gbp_client();
	if ( empty( $client['id'] ) || empty( $client['secret'] ) ) return '';

	$resp = wp_remote_post( MYLS_GBP_OAUTH_TOKEN, [
		'timeout' => 15,
		'body'    => [
			'client_id'     => $client['id'],
			'client_secret' => $client['secret'],
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh,
		],
	] );

	if ( is_wp_error( $resp ) ) return '';
	$code = (int) wp_remote_retrieve_response_code( $resp );
	$body = json_decode( wp_remote_retrieve_body( $resp ), true );
	if ( $code !== 200 || empty( $body['access_token'] ) ) return '';

	myls_gbp_save_tokens( $body );
	return (string) $body['access_token'];
}

/** Bust all cached account/location transients. */
function myls_gbp_bust_cache() : void {
	delete_transient( 'myls_gbp_accounts_cache' );

	$saved_account = (string) get_option( 'myls_gbp_account_id', '' );
	if ( $saved_account ) {
		delete_transient( 'myls_gbp_locs_' . md5( $saved_account ) );
	}
}

/** Revoke tokens, clear options, and bust caches. */
function myls_gbp_revoke_tokens() : void {
	$access  = (string) get_option( 'myls_gbp_access_token', '' );
	$refresh = (string) get_option( 'myls_gbp_refresh_token', '' );
	$tok = $refresh ?: $access;

	if ( $tok ) {
		wp_remote_post( MYLS_GBP_OAUTH_REVOKE, [
			'timeout' => 10,
			'body'    => [ 'token' => $tok ],
		] );
	}

	delete_option( 'myls_gbp_access_token' );
	delete_option( 'myls_gbp_refresh_token' );
	delete_option( 'myls_gbp_token_expires' );
	myls_gbp_bust_cache();
}


// ── Authenticated API wrapper ──────────────────────────────────────────────

function myls_gbp_api_call( string $url, string $method = 'GET', array $body = [] ) {
	$token = myls_gbp_get_access_token();
	if ( ! $token ) {
		return new WP_Error( 'no_token', 'GBP not connected or token refresh failed.' );
	}

	$args = [
		'timeout' => 30,
		'headers' => [
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		],
	];

	if ( strtoupper( $method ) === 'POST' ) {
		$args['body'] = wp_json_encode( $body );
		$resp = wp_remote_post( $url, $args );
	} else {
		$resp = wp_remote_get( $url, $args );
	}

	if ( is_wp_error( $resp ) ) return $resp;

	$code    = (int) wp_remote_retrieve_response_code( $resp );
	$decoded = json_decode( wp_remote_retrieve_body( $resp ), true );

	if ( $code < 200 || $code >= 300 ) {
		$msg    = $decoded['error']['message'] ?? "HTTP {$code}";
		$status = $decoded['error']['status']  ?? '';

		// Provide an actionable fix for quota errors
		if ( $code === 429 || $status === 'RESOURCE_EXHAUSTED' || stripos( $msg, 'quota' ) !== false ) {
			$msg = 'Google API quota exceeded. '
				 . 'The My Business Account Management API has very low default quotas for new projects. '
				 . 'Fix: go to Google Cloud Console → APIs & Services → My Business Account Management API → Quotas, '
				 . 'and request a quota increase. '
				 . 'Accounts and locations are cached for 30 minutes once loaded successfully. '
				 . '(Original: ' . $msg . ')';
		}

		return new WP_Error( 'gbp_api_error', $msg, [ 'status' => $code ] );
	}

	return $decoded ?? [];
}


// ── OAuth: START ───────────────────────────────────────────────────────────

add_action( 'admin_post_myls_gbp_oauth_start', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden.' );
	check_admin_referer( 'myls_gbp_oauth_start' );

	$client = myls_gbp_client();
	if ( empty( $client['id'] ) || empty( $client['secret'] ) ) {
		$err = rawurlencode( 'Missing GBP Client ID or Client Secret. Save them first.' );
		wp_safe_redirect( admin_url( 'admin.php?page=aintelligize&tab=utilities&sub=gbp_photos&gbp_error=' . $err ) );
		exit;
	}

	$state    = myls_gbp_make_state();
	$auth_url = add_query_arg( [
		'client_id'              => $client['id'],
		'redirect_uri'           => $client['redirect'],
		'response_type'          => 'code',
		'scope'                  => MYLS_GBP_SCOPE,
		'access_type'            => 'offline',
		'include_granted_scopes' => 'true',
		'prompt'                 => 'consent',
		'state'                  => $state,
	], MYLS_GBP_OAUTH_AUTH );

	wp_redirect( $auth_url );
	exit;
} );


// ── OAuth: CALLBACK ────────────────────────────────────────────────────────

add_action( 'admin_post_myls_gbp_oauth_cb', function() {
	if ( ! is_user_logged_in() ) { auth_redirect(); }

	$back = admin_url( 'admin.php?page=aintelligize&tab=utilities&sub=gbp_photos' );

	if ( isset( $_GET['error'] ) ) {
		wp_safe_redirect( $back . '&gbp_error=' . rawurlencode( sanitize_text_field( $_GET['error'] ) ) );
		exit;
	}

	$state = sanitize_text_field( $_GET['state'] ?? '' );
	if ( ! $state || ! myls_gbp_check_state( $state ) ) {
		wp_safe_redirect( $back . '&gbp_error=' . rawurlencode( 'Invalid state token. Please try again.' ) );
		exit;
	}

	$code = sanitize_text_field( $_GET['code'] ?? '' );
	if ( ! $code ) {
		wp_safe_redirect( $back . '&gbp_error=' . rawurlencode( 'Missing authorization code.' ) );
		exit;
	}

	$client = myls_gbp_client();
	$resp   = wp_remote_post( MYLS_GBP_OAUTH_TOKEN, [
		'timeout' => 15,
		'body'    => [
			'code'          => $code,
			'client_id'     => $client['id'],
			'client_secret' => $client['secret'],
			'redirect_uri'  => $client['redirect'],
			'grant_type'    => 'authorization_code',
		],
	] );

	if ( is_wp_error( $resp ) ) {
		wp_safe_redirect( $back . '&gbp_error=' . rawurlencode( $resp->get_error_message() ) );
		exit;
	}

	$http_code = (int) wp_remote_retrieve_response_code( $resp );
	$body      = json_decode( wp_remote_retrieve_body( $resp ), true );

	if ( $http_code !== 200 || empty( $body['access_token'] ) ) {
		$msg = $body['error_description'] ?? 'Token exchange failed (HTTP ' . $http_code . ')';
		wp_safe_redirect( $back . '&gbp_error=' . rawurlencode( $msg ) );
		exit;
	}

	myls_gbp_save_tokens( $body );
	wp_safe_redirect( $back . '&gbp=connected' );
	exit;
} );


// ── OAuth: DISCONNECT ──────────────────────────────────────────────────────

add_action( 'admin_post_myls_gbp_disconnect', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden.' );
	check_admin_referer( 'myls_gbp_disconnect' );

	myls_gbp_revoke_tokens();

	wp_safe_redirect( admin_url( 'admin.php?page=aintelligize&tab=utilities&sub=gbp_photos&gbp=disconnected' ) );
	exit;
} );


// ── AJAX: Clear Cache ──────────────────────────────────────────────────────

add_action( 'wp_ajax_myls_gbp_clear_cache', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'myls_gbp_nonce' ) ) wp_send_json_error( 'bad_nonce' );

	myls_gbp_bust_cache();
	wp_send_json_success( 'Cache cleared. Next load will fetch fresh data from Google.' );
} );


// ── AJAX: Get Accounts (cached 30 min) ────────────────────────────────────

add_action( 'wp_ajax_myls_gbp_get_accounts', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'myls_gbp_nonce' ) ) wp_send_json_error( 'bad_nonce' );

	$force = ! empty( $_POST['force_refresh'] );

	if ( ! $force ) {
		$cached = get_transient( 'myls_gbp_accounts_cache' );
		if ( $cached !== false ) {
			wp_send_json_success( [ 'accounts' => $cached, '_from_cache' => true ] );
		}
	}

	$result = myls_gbp_api_call( MYLS_GBP_ACCOUNTS_API );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	$accounts = [];
	foreach ( ( $result['accounts'] ?? [] ) as $acct ) {
		$accounts[] = [
			'id'    => $acct['name']        ?? '',
			'label' => $acct['accountName'] ?? $acct['name'] ?? 'Unknown Account',
			'type'  => $acct['type']        ?? '',
		];
	}

	set_transient( 'myls_gbp_accounts_cache', $accounts, MYLS_GBP_CACHE_TTL );
	wp_send_json_success( [ 'accounts' => $accounts, '_from_cache' => false ] );
} );


// ── AJAX: Get Locations (cached 30 min per account) ───────────────────────

add_action( 'wp_ajax_myls_gbp_get_locations', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'myls_gbp_nonce' ) ) wp_send_json_error( 'bad_nonce' );

	$account_id = sanitize_text_field( $_POST['account_id'] ?? '' );
	$force      = ! empty( $_POST['force_refresh'] );

	if ( ! $account_id ) wp_send_json_error( 'missing account_id' );

	$cache_key = 'myls_gbp_locs_' . md5( $account_id );

	if ( ! $force ) {
		$cached = get_transient( $cache_key );
		if ( $cached !== false ) {
			wp_send_json_success( [ 'locations' => $cached, '_from_cache' => true ] );
		}
	}

	$url    = MYLS_GBP_LOCATIONS_API . $account_id . '/locations?readMask=name,title';
	$result = myls_gbp_api_call( $url );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	$locations = [];
	foreach ( ( $result['locations'] ?? [] ) as $loc ) {
		$locations[] = [
			'id'    => $loc['name']  ?? '',
			'label' => $loc['title'] ?? $loc['name'] ?? 'Unknown Location',
		];
	}

	set_transient( $cache_key, $locations, MYLS_GBP_CACHE_TTL );
	wp_send_json_success( [ 'locations' => $locations, '_from_cache' => false ] );
} );



// ── AJAX: Lookup Location by Business Name ────────────────────────────────

/**
 * Searches for a GBP location by business name/address using the
 * googleLocations:search endpoint on the Business Information API.
 *
 * This endpoint accepts a free-text "query" field — NOT a Place ID.
 * (The placeId field does not exist on this endpoint.)
 *
 * Returns up to 5 matches so the caller can let the user pick the right one.
 * Each match includes the resource name (accounts/X/locations/Y) when the
 * location is managed by the authenticated user, or requestAdminRightsUri
 * when it is not.
 */
add_action( 'wp_ajax_myls_gbp_lookup_by_name', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'myls_gbp_nonce' ) ) wp_send_json_error( 'bad_nonce' );

	$query = sanitize_text_field( trim( $_POST['query'] ?? '' ) );
	if ( strlen( $query ) < 3 ) {
		wp_send_json_error( 'Please enter at least 3 characters.' );
	}

	// Step 1: Search by name to get Place IDs.
	// NOTE: googleLocations:search NEVER returns accounts/.../locations/... resource names.
	// It always returns googleLocations/{placeId} — a public Maps reference only.
	// The requestAdminRightsUri is present on ALL results regardless of management status.
	$url    = MYLS_GBP_LOCATIONS_API . 'googleLocations:search';
	$result = myls_gbp_api_call( $url, 'POST', [
		'query'    => $query,
		'pageSize' => 5,
	] );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	$hits = $result['googleLocations'] ?? [];

	if ( empty( $hits ) ) {
		wp_send_json_error(
			'No GBP locations found matching "' . esc_html( $query ) . '". '
			. 'Try adding your city or address to narrow the search.'
		);
	}

	// Step 2: For each hit, extract the Place ID from the googleLocations/{placeId} name field,
	// then resolve it to an accounts/.../locations/... resource name via accounts/-/locations
	// filtered by metadata.place_id. This is the only reliable way to get the resource name
	// without knowing the account ID upfront.
	$matches = [];
	foreach ( $hits as $hit ) {
		$loc = $hit['location'] ?? [];

		// Extract Place ID from "googleLocations/{placeId}"
		$gl_name  = $hit['name'] ?? '';
		$place_id = '';
		if ( strpos( $gl_name, 'googleLocations/' ) === 0 ) {
			$place_id = substr( $gl_name, strlen( 'googleLocations/' ) );
		}
		// Also check location.metadata.placeId as fallback
		if ( ! $place_id ) {
			$place_id = $loc['metadata']['placeId'] ?? '';
		}

		$address_parts = array_filter( [
			$loc['storefrontAddress']['addressLines'][0] ?? '',
			$loc['storefrontAddress']['locality']        ?? '',
			$loc['storefrontAddress']['administrativeArea'] ?? '',
		] );

		$location_id = '';
		$account_id  = '';

		// Step 2: resolve Place ID → GBP resource name via wildcard filter
		if ( $place_id ) {
			$filter_url = MYLS_GBP_LOCATIONS_API
				. 'accounts/-/locations'
				. '?readMask=name,title'
				. '&filter=' . rawurlencode( 'metadata.place_id="' . $place_id . '"' );

			$filter_result = myls_gbp_api_call( $filter_url );

			if ( ! is_wp_error( $filter_result ) ) {
				$resolved = $filter_result['locations'][0]['name'] ?? '';
				if ( strpos( $resolved, 'accounts/' ) === 0 && strpos( $resolved, '/locations/' ) !== false ) {
					$location_id = $resolved;
					if ( preg_match( '#^(accounts/[^/]+)/locations/#', $location_id, $m ) ) {
						$account_id = $m[1];
					}
				}
			}
			// If filter call quota-fails or returns nothing, we surface the result
			// without a resource name so user can still see the match and use Option 4.
		}

		$matches[] = [
			'title'       => $loc['title'] ?? 'Unknown',
			'address'     => implode( ', ', $address_parts ),
			'place_id'    => $place_id,
			'location_id' => $location_id,
			'account_id'  => $account_id,
			'managed'     => ! empty( $location_id ),
		];
	}

	wp_send_json_success( [ 'matches' => $matches ] );
} );



// ── AJAX: Lookup Location by Store Code ───────────────────────────────────

/**
 * Looks up a location by its store code using the `accounts/-` wildcard,
 * which searches across ALL accounts the authenticated user can access.
 * This avoids the quota-limited Account Management API entirely — only
 * the Business Information API is called here.
 *
 * Wildcard support: `accounts/-` is documented in the GBP API and allows
 * cross-account searches. If the wildcard is not permitted on this project,
 * the API returns a 400 or 403 and we surface a clear fallback message.
 *
 * On success: returns location_id (full resource name) and account_id
 * (extracted from the resource name) so the caller can save both without
 * ever having called the Account Management API.
 */
add_action( 'wp_ajax_myls_gbp_lookup_store_code', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'myls_gbp_nonce' ) ) wp_send_json_error( 'bad_nonce' );

	$store_code = sanitize_text_field( trim( $_POST['store_code'] ?? '' ) );
	if ( ! $store_code ) {
		wp_send_json_error( 'Please enter a store code.' );
	}

	// Use accounts/- wildcard to search across all accessible accounts.
	// readMask must include 'storeCode' so the filter field is available.
	$url = MYLS_GBP_LOCATIONS_API
		 . 'accounts/-/locations'
		 . '?readMask=name,title,storeCode'
		 . '&filter=' . rawurlencode( 'storeCode="' . $store_code . '"' );

	$result = myls_gbp_api_call( $url );

	if ( is_wp_error( $result ) ) {
		$msg  = $result->get_error_message();
		$data = $result->get_error_data();
		$code = $data['status'] ?? 0;

		// Wildcard not permitted on this project — tell user their options
		if ( in_array( (int) $code, [ 400, 403 ], true )
			|| stripos( $msg, 'wildcard' ) !== false
			|| stripos( $msg, 'not supported' ) !== false
		) {
			wp_send_json_error(
				'Store code lookup via accounts/- wildcard is not permitted on this project. '
				. 'Use Option 1 (Load Accounts) or Option 4 (paste IDs manually) instead. '
				. '(API error: ' . $msg . ')'
			);
		}

		wp_send_json_error( $msg );
	}

	$locations = $result['locations'] ?? [];

	if ( empty( $locations ) ) {
		wp_send_json_error( 'No location found with store code "' . esc_html( $store_code ) . '". '
			. 'Check the exact code in your GBP dashboard (Business Profile Manager → Location → Info → Store code).' );
	}

	// Take the first match (store codes should be unique)
	$loc          = $locations[0];
	$location_id  = $loc['name']  ?? '';
	$location_label = $loc['title'] ?? $location_id;

	// Extract account_id from full resource name: accounts/123/locations/ABC → accounts/123
	$account_id = '';
	if ( preg_match( '#^(accounts/[^/]+)/locations/#', $location_id, $m ) ) {
		$account_id = $m[1];
	}

	wp_send_json_success( [
		'location_id'    => $location_id,
		'location_label' => $location_label,
		'account_id'     => $account_id,
		'store_code'     => $loc['storeCode'] ?? $store_code,
		'match_count'    => count( $locations ),
	] );
} );


// ── AJAX: Save selected Account + Location ─────────────────────────────────


// ── AJAX: List All Managed Locations (accounts/- wildcard, no filter) ─────

/**
 * Lists ALL locations accessible to the authenticated user by calling
 * accounts/-/locations with no filter. The accounts/- wildcard iterates
 * across every account the OAuth token has access to.
 *
 * This is a single API call to the Business Information API (not the
 * quota-limited Account Management API). Returns full resource names
 * so account_id can be derived without a separate lookup.
 *
 * If quota is exhausted here too, the user should use Option 4 (location ID only).
 */
add_action( 'wp_ajax_myls_gbp_list_all_locations', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'myls_gbp_nonce' ) ) wp_send_json_error( 'bad_nonce' );

	$url    = MYLS_GBP_LOCATIONS_API . 'accounts/-/locations?readMask=name,title,storefrontAddress';
	$result = myls_gbp_api_call( $url );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	$raw_locations = $result['locations'] ?? [];

	if ( empty( $raw_locations ) ) {
		wp_send_json_error( 'No locations found for this Google account. Make sure you connected with the account that manages your GBP listings.' );
	}

	$locations = [];
	foreach ( $raw_locations as $loc ) {
		$location_id = $loc['name'] ?? '';
		if ( ! $location_id ) continue;

		$account_id = '';
		if ( preg_match( '#^(accounts/[^/]+)/locations/#', $location_id, $m ) ) {
			$account_id = $m[1];
		}

		$address_parts = array_filter( [
			$loc['storefrontAddress']['addressLines'][0] ?? '',
			$loc['storefrontAddress']['locality']        ?? '',
			$loc['storefrontAddress']['administrativeArea'] ?? '',
		] );

		$locations[] = [
			'location_id'  => $location_id,
			'account_id'   => $account_id,
			'title'        => $loc['title'] ?? $location_id,
			'address'      => implode( ', ', $address_parts ),
		];
	}

	wp_send_json_success( [ 'locations' => $locations ] );
} );

add_action( 'wp_ajax_myls_gbp_save_location', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'myls_gbp_nonce' ) ) wp_send_json_error( 'bad_nonce' );

	$location_id    = sanitize_text_field( $_POST['location_id']    ?? '' );
	$location_label = sanitize_text_field( $_POST['location_label'] ?? '' );

	if ( ! $location_id ) wp_send_json_error( 'missing location_id' );

	// Derive account_id from the location resource name — it's always the first two segments.
	// e.g. accounts/123456789/locations/AbCdEf  →  accounts/123456789
	// If caller also passes account_id explicitly, prefer that; otherwise extract it.
	$account_id = sanitize_text_field( $_POST['account_id'] ?? '' );
	if ( ! $account_id && preg_match( '#^(accounts/[^/]+)/locations/#', $location_id, $m ) ) {
		$account_id = $m[1];
	}

	update_option( 'myls_gbp_account_id',     $account_id,     false );
	update_option( 'myls_gbp_location_id',    $location_id,    false );
	update_option( 'myls_gbp_location_label', $location_label, false );

	wp_send_json_success( 'Location saved.' );
} );


// ── AJAX: Fetch Photos ─────────────────────────────────────────────────────

add_action( 'wp_ajax_myls_gbp_get_photos', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'myls_gbp_nonce' ) ) wp_send_json_error( 'bad_nonce' );

	$location_id = sanitize_text_field( $_POST['location_id'] ?? get_option( 'myls_gbp_location_id', '' ) );
	if ( ! $location_id ) wp_send_json_error( 'No location selected.' );

	$page_token = sanitize_text_field( $_POST['page_token'] ?? '' );
	$url        = MYLS_GBP_MEDIA_API . $location_id . '/media?pageSize=100';
	if ( $page_token ) {
		$url .= '&pageToken=' . rawurlencode( $page_token );
	}

	$result = myls_gbp_api_call( $url );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	$photos = [];
	foreach ( ( $result['mediaItems'] ?? [] ) as $item ) {
		if ( ( $item['mediaFormat'] ?? '' ) !== 'PHOTO' ) continue;

		$gbp_name         = $item['name'] ?? '';
		$already_imported = false;

		if ( $gbp_name ) {
			$existing = get_posts( [
				'post_type'   => 'attachment',
				'meta_key'    => '_gbp_media_name',
				'meta_value'  => $gbp_name,
				'post_status' => 'inherit',
				'numberposts' => 1,
				'fields'      => 'ids',
			] );
			$already_imported = ! empty( $existing );
		}

		$photos[] = [
			'name'             => $gbp_name,
			'google_url'       => $item['googleUrl']    ?? '',
			'thumbnail_url'    => $item['thumbnailUrl'] ?? $item['googleUrl'] ?? '',
			'create_time'      => $item['createTime']   ?? '',
			'description'      => $item['locationAssociation']['category'] ?? '',
			'already_imported' => $already_imported,
		];
	}

	wp_send_json_success( [
		'photos'     => $photos,
		'next_token' => $result['nextPageToken'] ?? '',
		'total'      => count( $photos ),
	] );
} );


// ── AJAX: Import Single Photo to Media Library ─────────────────────────────

add_action( 'wp_ajax_myls_gbp_import_photo', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'myls_gbp_nonce' ) ) wp_send_json_error( 'bad_nonce' );

	$google_url  = esc_url_raw( $_POST['google_url']  ?? '' );
	$gbp_name    = sanitize_text_field( $_POST['gbp_name']    ?? '' );
	$description = sanitize_text_field( $_POST['description'] ?? '' );

	if ( ! $google_url ) wp_send_json_error( 'No URL provided.' );

	if ( $gbp_name ) {
		$existing = get_posts( [
			'post_type'   => 'attachment',
			'meta_key'    => '_gbp_media_name',
			'meta_value'  => $gbp_name,
			'post_status' => 'inherit',
			'numberposts' => 1,
			'fields'      => 'ids',
		] );
		if ( ! empty( $existing ) ) {
			wp_send_json_success( [
				'attachment_id' => $existing[0],
				'status'        => 'already_exists',
				'message'       => 'Already in Media Library.',
			] );
		}
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$tmp = download_url( $google_url, 30 );
	if ( is_wp_error( $tmp ) ) {
		wp_send_json_error( 'Download failed: ' . $tmp->get_error_message() );
	}

	$slug = $gbp_name ? sanitize_file_name( basename( $gbp_name ) ) : 'gbp-photo-' . time();
	if ( ! preg_match( '/\.(jpe?g|png|webp|gif)$/i', $slug ) ) {
		$slug .= '.jpg';
	}

	$attachment_id = media_handle_sideload( [ 'name' => $slug, 'tmp_name' => $tmp ], 0, $description ?: 'GBP Photo' );

	if ( is_wp_error( $attachment_id ) ) {
		@unlink( $tmp );
		wp_send_json_error( 'Sideload failed: ' . $attachment_id->get_error_message() );
	}

	if ( $gbp_name ) update_post_meta( $attachment_id, '_gbp_media_name',     $gbp_name );
	update_post_meta( $attachment_id, '_gbp_source_url',  $google_url );
	update_post_meta( $attachment_id, '_gbp_imported_at', current_time( 'mysql' ) );
	if ( $description ) update_post_meta( $attachment_id, '_wp_attachment_image_alt', $description );

	wp_send_json_success( [
		'attachment_id' => $attachment_id,
		'status'        => 'imported',
		'message'       => 'Photo imported successfully.',
		'edit_url'      => get_edit_post_link( $attachment_id, 'raw' ),
	] );
} );
