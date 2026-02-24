<?php
/**
 * Google Search Console OAuth (Google OAuth 2.0)
 * Path: modules/oauth/gsc.php
 *
 * Provides:
 *  - admin_post actions:
 *      * myls_gsc_oauth_start
 *      * myls_gsc_oauth_cb
 *      * myls_gsc_disconnect
 *  - AJAX test:
 *      * myls_test_gsc_setup
 *  - Helpers:
 *      * myls_gsc_is_connected()
 *      * myls_gsc_get_access_token()   (auto-refresh — overrides inc/admin-helper-gsc.php stub)
 *      * myls_gsc_revoke_tokens()
 *      * myls_gsc_oauth_call()
 *
 * Options used:
 *  - myls_gsc_client_id
 *  - myls_gsc_client_secret
 *  - myls_gsc_redirect_uri      (should be admin-post.php?action=myls_gsc_oauth_cb)
 *  - myls_gsc_access_token
 *  - myls_gsc_refresh_token
 *  - myls_gsc_token_expires     (unix timestamp)
 *  - myls_gsc_site_property     (defaults to home_url('/'))
 *
 * @since 6.3.2.7
 */

if ( ! defined('ABSPATH') ) exit;

define( 'MYLS_GSC_OAUTH_AUTH',  'https://accounts.google.com/o/oauth2/v2/auth' );
define( 'MYLS_GSC_OAUTH_TOKEN', 'https://oauth2.googleapis.com/token' );
define( 'MYLS_GSC_OAUTH_REVOKE','https://oauth2.googleapis.com/revoke' );
define( 'MYLS_GSC_SCOPE',       'https://www.googleapis.com/auth/webmasters.readonly' );


/** ----------------------------------------------------------------
 * Small helpers to fetch client + state storage
 * ---------------------------------------------------------------- */
function myls_gsc_client() : array {
	$cid   = (string) get_option('myls_gsc_client_id', '');
	$sec   = (string) get_option('myls_gsc_client_secret', '');
	$redir = (string) get_option('myls_gsc_redirect_uri', admin_url('admin-post.php?action=myls_gsc_oauth_cb'));
	return ['id'=>$cid, 'secret'=>$sec, 'redirect'=>$redir];
}

function myls_gsc_make_state() : string {
	$state = wp_generate_password(24, false, false);
	set_transient( 'myls_gsc_state_' . $state, get_current_user_id(), 10 * MINUTE_IN_SECONDS );
	return $state;
}

function myls_gsc_check_state( string $state ) : bool {
	$owner = get_transient( 'myls_gsc_state_' . $state );
	if ( ! $owner ) return false;
	delete_transient( 'myls_gsc_state_' . $state );
	return true;
}


/** ----------------------------------------------------------------
 * Connection status + token store
 * ---------------------------------------------------------------- */
if ( ! function_exists('myls_gsc_is_connected') ) {
	function myls_gsc_is_connected() : bool {
		return (string) get_option('myls_gsc_refresh_token', '') !== '';
	}
}

function myls_gsc_save_tokens( array $t ) : void {
	if ( ! empty( $t['access_token'] ) ) {
		update_option( 'myls_gsc_access_token', (string) $t['access_token'], false );
	}
	if ( ! empty( $t['refresh_token'] ) ) {
		update_option( 'myls_gsc_refresh_token', (string) $t['refresh_token'], false );
	}
	if ( isset( $t['expires_in'] ) ) {
		$exp = time() + (int) $t['expires_in'] - 30; // 30s skew
		update_option( 'myls_gsc_token_expires', $exp, false );
	}
}

/**
 * Get a valid access token. Refreshes automatically if expired.
 * This is the primary function used by AJAX endpoints.
 */
if ( ! function_exists('myls_gsc_get_access_token') ) {
	function myls_gsc_get_access_token() {
		$access = (string) get_option('myls_gsc_access_token', '');
		$exp    = (int) get_option('myls_gsc_token_expires', 0);
		if ( $access && $exp > time() ) {
			return $access;
		}

		$refresh = (string) get_option('myls_gsc_refresh_token', '');
		if ( ! $refresh ) return '';

		$client = myls_gsc_client();
		if ( empty($client['id']) || empty($client['secret']) ) return '';

		$resp = wp_remote_post( MYLS_GSC_OAUTH_TOKEN, [
			'timeout' => 15,
			'body'    => [
				'client_id'     => $client['id'],
				'client_secret' => $client['secret'],
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
			],
		] );

		if ( is_wp_error($resp) ) return '';
		$code = (int) wp_remote_retrieve_response_code($resp);
		$body = json_decode( wp_remote_retrieve_body($resp), true );
		if ( $code !== 200 || empty($body['access_token']) ) return '';

		myls_gsc_save_tokens( $body );
		return (string) $body['access_token'];
	}
}

/** Attempt to revoke tokens + clear. */
function myls_gsc_revoke_tokens() : void {
	$access  = (string) get_option('myls_gsc_access_token', '');
	$refresh = (string) get_option('myls_gsc_refresh_token', '');
	$tok = $refresh ?: $access;

	if ( $tok ) {
		wp_remote_post( MYLS_GSC_OAUTH_REVOKE, [
			'timeout' => 10,
			'body'    => [ 'token' => $tok ],
		] );
	}
	delete_option('myls_gsc_access_token');
	delete_option('myls_gsc_refresh_token');
	delete_option('myls_gsc_token_expires');
}


/** ----------------------------------------------------------------
 * Convenience wrapper: make an authenticated GET/POST to a GSC API URL
 * ---------------------------------------------------------------- */
function myls_gsc_oauth_call( string $url, string $method = 'GET', array $body = [] ) {
	$token = myls_gsc_get_access_token();
	if ( ! $token || is_wp_error($token) ) {
		return new WP_Error('no_token', 'GSC not connected or token refresh failed.');
	}

	$args = [
		'timeout' => 30,
		'headers' => [
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		],
	];

	if ( strtoupper($method) === 'POST' ) {
		$args['body'] = wp_json_encode($body);
		$resp = wp_remote_post($url, $args);
	} else {
		$resp = wp_remote_get($url, $args);
	}

	if ( is_wp_error($resp) ) return $resp;

	$code    = (int) wp_remote_retrieve_response_code($resp);
	$decoded = json_decode(wp_remote_retrieve_body($resp), true);

	if ( $code < 200 || $code >= 300 ) {
		$msg = $decoded['error']['message'] ?? "HTTP {$code}";
		return new WP_Error('gsc_api_error', $msg);
	}

	return $decoded;
}


/** ----------------------------------------------------------------
 * START: /wp-admin/admin-post.php?action=myls_gsc_oauth_start
 * ---------------------------------------------------------------- */
function myls_gsc_oauth_start() {
	if ( ! current_user_can('manage_options') ) wp_die('Forbidden.');
	check_admin_referer('myls_gsc_oauth_start');

	$client = myls_gsc_client();
	if ( empty($client['id']) || empty($client['secret']) || empty($client['redirect']) ) {
		wp_safe_redirect( admin_url('admin.php?page=aintelligize&tab=api-integration&gsc_error=' . rawurlencode('Missing GSC client settings. Save Client ID, Client Secret, and Redirect URI first.')) );
		exit;
	}

	$state = myls_gsc_make_state();

	$auth_url = add_query_arg( [
		'client_id'              => $client['id'],
		'redirect_uri'           => $client['redirect'],
		'response_type'          => 'code',
		'scope'                  => MYLS_GSC_SCOPE,
		'access_type'            => 'offline',
		'include_granted_scopes' => 'true',
		'prompt'                 => 'consent', // ensures refresh_token on re-connect
		'state'                  => $state,
	], MYLS_GSC_OAUTH_AUTH );

	wp_redirect( $auth_url );
	exit;
}
add_action('admin_post_myls_gsc_oauth_start', 'myls_gsc_oauth_start');


/** ----------------------------------------------------------------
 * CALLBACK: /wp-admin/admin-post.php?action=myls_gsc_oauth_cb
 * ---------------------------------------------------------------- */
function myls_gsc_oauth_cb() {
	if ( ! is_user_logged_in() ) {
		auth_redirect();
	}

	$redir_base = admin_url('admin.php?page=aintelligize&tab=api-integration');

	if ( isset($_GET['error']) ) {
		$e = sanitize_text_field($_GET['error']);
		wp_safe_redirect( $redir_base . '&gsc_error=' . rawurlencode($e) );
		exit;
	}

	$state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
	if ( ! $state || ! myls_gsc_check_state($state) ) {
		wp_safe_redirect( $redir_base . '&gsc_error=' . rawurlencode('Invalid state token. Please try again.') );
		exit;
	}

	$code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
	if ( ! $code ) {
		wp_safe_redirect( $redir_base . '&gsc_error=' . rawurlencode('Missing authorization code.') );
		exit;
	}

	$client = myls_gsc_client();
	$resp = wp_remote_post( MYLS_GSC_OAUTH_TOKEN, [
		'timeout' => 15,
		'body'    => [
			'code'          => $code,
			'client_id'     => $client['id'],
			'client_secret' => $client['secret'],
			'redirect_uri'  => $client['redirect'],
			'grant_type'    => 'authorization_code',
		],
	] );

	if ( is_wp_error($resp) ) {
		wp_safe_redirect( $redir_base . '&gsc_error=' . rawurlencode($resp->get_error_message()) );
		exit;
	}

	$http_code = (int) wp_remote_retrieve_response_code($resp);
	$body      = json_decode( wp_remote_retrieve_body($resp), true );

	if ( $http_code !== 200 || empty($body['access_token']) ) {
		$msg = ! empty($body['error_description']) ? $body['error_description'] : 'Token exchange failed (HTTP ' . $http_code . ')';
		wp_safe_redirect( $redir_base . '&gsc_error=' . rawurlencode($msg) );
		exit;
	}

	myls_gsc_save_tokens( $body );

	// Auto-detect site property if not set
	$current_prop = get_option('myls_gsc_site_property', '');
	if ( empty($current_prop) ) {
		update_option('myls_gsc_site_property', home_url('/'), false);
	}

	wp_safe_redirect( $redir_base . '&gsc=connected' );
	exit;
}
add_action('admin_post_myls_gsc_oauth_cb', 'myls_gsc_oauth_cb');


/** ----------------------------------------------------------------
 * DISCONNECT: /wp-admin/admin-post.php?action=myls_gsc_disconnect
 * ---------------------------------------------------------------- */
function myls_gsc_disconnect() {
	if ( ! current_user_can('manage_options') ) wp_die('Forbidden.');
	check_admin_referer('myls_gsc_disconnect');

	myls_gsc_revoke_tokens();
	wp_safe_redirect( admin_url('admin.php?page=aintelligize&tab=api-integration&gsc=disconnected') );
	exit;
}
add_action('admin_post_myls_gsc_disconnect', 'myls_gsc_disconnect');


/** ----------------------------------------------------------------
 * AJAX: Test GSC setup — list sites to confirm access
 * ---------------------------------------------------------------- */
function myls_ajax_test_gsc_setup() {
	if ( ! current_user_can('manage_options') ) wp_send_json_error('forbidden');
	$nonce = $_POST['nonce'] ?? '';
	if ( ! wp_verify_nonce( $nonce, 'myls_api_tab_ajax' ) ) wp_send_json_error('bad_nonce');

	if ( ! myls_gsc_is_connected() ) {
		$msg = 'Client configured; not connected';
		update_option('myls_gsc_test_result', $msg, false);
		wp_send_json_error( $msg );
	}

	$result = myls_gsc_oauth_call( 'https://www.googleapis.com/webmasters/v3/sites', 'GET' );

	if ( is_wp_error($result) ) {
		$msg = $result->get_error_message();
		update_option('myls_gsc_test_result', $msg, false);
		wp_send_json_error( $msg );
	}

	$sites = [];
	if ( ! empty($result['siteEntry']) ) {
		foreach ( $result['siteEntry'] as $s ) {
			$sites[] = $s['siteUrl'] ?? '';
		}
	}

	$count = count($sites);
	$msg   = 'OK: ' . $count . ' site' . ($count !== 1 ? 's' : '') . ' found';
	if ( $count > 0 ) {
		$msg .= ' (' . implode(', ', array_slice($sites, 0, 3)) . ')';
	}

	update_option('myls_gsc_test_result', $msg, false);
	wp_send_json_success( $msg );
}
add_action('wp_ajax_myls_test_gsc_setup', 'myls_ajax_test_gsc_setup');
