<?php
/**
 * YouTube OAuth (Google OAuth 2.0)
 * Path: modules/oauth/youtube.php
 *
 * Provides:
 *  - admin_post actions:
 *      * myls_yt_oauth_start
 *      * myls_yt_oauth_cb
 *      * myls_yt_oauth_disconnect
 *  - AJAX test:
 *      * myls_test_youtube_oauth
 *  - Helpers:
 *      * myls_yt_is_connected()
 *      * myls_yt_get_access_token()   (auto-refresh)
 *      * myls_yt_revoke_tokens()
 *
 * Options used:
 *  - myls_yt_client_id
 *  - myls_yt_client_secret
 *  - myls_yt_redirect_uri      (should be admin-post.php?action=myls_yt_oauth_cb)
 *  - myls_yt_access_token
 *  - myls_yt_refresh_token
 *  - myls_yt_token_expires     (unix timestamp)
 */

if ( ! defined('ABSPATH') ) exit;

define( 'MYLS_YT_OAUTH_AUTH',  'https://accounts.google.com/o/oauth2/v2/auth' );
define( 'MYLS_YT_OAUTH_TOKEN', 'https://oauth2.googleapis.com/token' );
define( 'MYLS_YT_OAUTH_REVOKE','https://oauth2.googleapis.com/revoke' );
// Scope for read-only channel/video info:
define( 'MYLS_YT_SCOPE',       'https://www.googleapis.com/auth/youtube.readonly' );

/** ----------------------------------------------------------------
 * Small helpers to fetch client + state storage
 * ---------------------------------------------------------------- */
function myls_yt_client() : array {
	$cid  = (string) get_option('myls_yt_client_id', '');
	$sec  = (string) get_option('myls_yt_client_secret', '');
	$redir= (string) get_option('myls_yt_redirect_uri', admin_url('admin-post.php?action=myls_yt_oauth_cb'));
	return ['id'=>$cid,'secret'=>$sec,'redirect'=>$redir];
}

function myls_yt_make_state() : string {
	$state = wp_generate_password(24, false, false);
	set_transient( 'myls_yt_state_' . $state, get_current_user_id(), 10 * MINUTE_IN_SECONDS );
	return $state;
}

function myls_yt_check_state( string $state ) : bool {
	$owner = get_transient( 'myls_yt_state_' . $state );
	if ( ! $owner ) return false;
	delete_transient( 'myls_yt_state_' . $state );
	return true;
}

/** ----------------------------------------------------------------
 * Connection status + token store
 * ---------------------------------------------------------------- */
function myls_yt_is_connected() : bool {
	return (string) get_option('myls_yt_refresh_token', '') !== '';
}

function myls_yt_save_tokens( array $t ) : void {
	if ( ! empty( $t['access_token'] ) ) {
		update_option( 'myls_yt_access_token', (string) $t['access_token'], false );
	}
	if ( ! empty( $t['refresh_token'] ) ) {
		update_option( 'myls_yt_refresh_token', (string) $t['refresh_token'], false );
	}
	if ( isset( $t['expires_in'] ) ) {
		$exp = time() + (int) $t['expires_in'] - 30; // 30s skew
		update_option( 'myls_yt_token_expires', $exp, false );
	}
}

/**
 * Get a valid access token. Refreshes if needed.
 */
function myls_yt_get_access_token() {
	$access = (string) get_option('myls_yt_access_token', '');
	$exp    = (int) get_option('myls_yt_token_expires', 0);
	if ( $access && $exp > time() ) {
		return $access;
	}

	$refresh = (string) get_option('myls_yt_refresh_token', '');
	if ( ! $refresh ) return new WP_Error('no_refresh', 'Not connected.');

	$client = myls_yt_client();
	if ( empty($client['id']) || empty($client['secret']) ) {
		return new WP_Error('no_client', 'YouTube OAuth client not configured.');
	}

	$resp = wp_remote_post( MYLS_YT_OAUTH_TOKEN, [
		'timeout' => 15,
		'body'    => [
			'client_id'     => $client['id'],
			'client_secret' => $client['secret'],
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh,
		],
	] );

	if ( is_wp_error($resp) ) return $resp;
	$code = (int) wp_remote_retrieve_response_code($resp);
	$body = json_decode( wp_remote_retrieve_body($resp), true );
	if ( $code !== 200 || empty($body['access_token']) ) {
		return new WP_Error('refresh_failed', 'Token refresh failed.');
	}

	myls_yt_save_tokens( $body );
	return (string) $body['access_token'];
}

/** Attempt to revoke tokens + clear. */
function myls_yt_revoke_tokens() : void {
	$access  = (string) get_option('myls_yt_access_token', '');
	$refresh = (string) get_option('myls_yt_refresh_token', '');
	$tok = $refresh ?: $access;

	if ( $tok ) {
		wp_remote_post( MYLS_YT_OAUTH_REVOKE, [
			'timeout' => 10,
			'body'    => [ 'token' => $tok ],
		] );
	}
	delete_option('myls_yt_access_token');
	delete_option('myls_yt_refresh_token');
	delete_option('myls_yt_token_expires');
}

/** ----------------------------------------------------------------
 * START: /wp-admin/admin-post.php?action=myls_yt_oauth_start
 * ---------------------------------------------------------------- */
function myls_yt_oauth_start() {
	if ( ! current_user_can('manage_options') ) wp_die('Forbidden.');
	check_admin_referer('myls_yt_oauth_start');

	$client = myls_yt_client();
	if ( empty($client['id']) || empty($client['secret']) || empty($client['redirect']) ) {
		wp_safe_redirect( admin_url('admin.php?page=aintelligize&tab=api-integration&yt_oauth_error=Missing+client+settings') );
		exit;
	}

	$state = myls_yt_make_state();

	$auth_url = add_query_arg( [
		'client_id'             => $client['id'],
		'redirect_uri'          => $client['redirect'],
		'response_type'         => 'code',
		'scope'                 => MYLS_YT_SCOPE,
		'access_type'           => 'offline',
		'include_granted_scopes'=> 'true',
		'prompt'                => 'consent', // ensures refresh_token on subsequent connects
		'state'                 => $state,
	], MYLS_YT_OAUTH_AUTH );

	wp_redirect( $auth_url );
	exit;
}
add_action('admin_post_myls_yt_oauth_start', 'myls_yt_oauth_start');

/** ----------------------------------------------------------------
 * CALLBACK: /wp-admin/admin-post.php?action=myls_yt_oauth_cb
 * ---------------------------------------------------------------- */
function myls_yt_oauth_cb() {
	// Require login to capture tokens in admin.
	if ( ! is_user_logged_in() ) {
		auth_redirect();
	}
	if ( isset($_GET['error']) ) {
		$e = sanitize_text_field($_GET['error']);
		wp_safe_redirect( admin_url('admin.php?page=aintelligize&tab=api-integration&yt_oauth_error=' . rawurlencode($e)) );
		exit;
	}

	$state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
	if ( ! $state || ! myls_yt_check_state($state) ) {
		wp_safe_redirect( admin_url('admin.php?page=aintelligize&tab=api-integration&yt_oauth_error=Invalid+state') );
		exit;
	}

	$code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
	if ( ! $code ) {
		wp_safe_redirect( admin_url('admin.php?page=aintelligize&tab=api-integration&yt_oauth_error=Missing+code') );
		exit;
	}

	$client = myls_yt_client();
	$resp = wp_remote_post( MYLS_YT_OAUTH_TOKEN, [
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
		wp_safe_redirect( admin_url('admin.php?page=aintelligize&tab=api-integration&yt_oauth_error=' . rawurlencode($resp->get_error_message())) );
		exit;
	}

	$code_http = (int) wp_remote_retrieve_response_code($resp);
	$body      = json_decode( wp_remote_retrieve_body($resp), true );
	if ( $code_http !== 200 || empty($body['access_token']) ) {
		$msg = ! empty($body['error_description']) ? $body['error_description'] : 'Token exchange failed';
		wp_safe_redirect( admin_url('admin.php?page=aintelligize&tab=api-integration&yt_oauth_error=' . rawurlencode($msg)) );
		exit;
	}

	myls_yt_save_tokens( $body );
	wp_safe_redirect( admin_url('admin.php?page=aintelligize&tab=api-integration&yt_oauth=connected') );
	exit;
}
add_action('admin_post_myls_yt_oauth_cb', 'myls_yt_oauth_cb');

/** ----------------------------------------------------------------
 * DISCONNECT: /wp-admin/admin-post.php?action=myls_yt_oauth_disconnect
 * ---------------------------------------------------------------- */
function myls_yt_oauth_disconnect() {
	if ( ! current_user_can('manage_options') ) wp_die('Forbidden.');
	check_admin_referer('myls_yt_oauth_disconnect');

	myls_yt_revoke_tokens();
	wp_safe_redirect( admin_url('admin.php?page=aintelligize&tab=api-integration&yt_oauth=disconnected') );
	exit;
}
add_action('admin_post_myls_yt_oauth_disconnect', 'myls_yt_oauth_disconnect');

/** ----------------------------------------------------------------
 * AJAX: Test YouTube OAuth by calling channels.list?mine=true
 * ---------------------------------------------------------------- */
function myls_ajax_test_youtube_oauth() {
	if ( ! current_user_can('manage_options') ) wp_send_json_error('forbidden');
	$nonce = $_POST['nonce'] ?? '';
	if ( ! wp_verify_nonce( $nonce, 'myls_api_tab_ajax' ) ) wp_send_json_error('bad_nonce');

	$token = myls_yt_get_access_token();
	if ( is_wp_error($token) ) {
		wp_send_json_error( $token->get_error_message() );
	}

	$resp = wp_remote_get( add_query_arg([
		'part' => 'snippet',
		'mine' => 'true',
	], 'https://www.googleapis.com/youtube/v3/channels' ), [
		'timeout' => 15,
		'headers' => [ 'Authorization' => 'Bearer ' . $token ],
	] );

	if ( is_wp_error($resp) ) {
		wp_send_json_error( $resp->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code($resp);
	if ( $code !== 200 ) {
		wp_send_json_error( 'HTTP ' . $code . ': ' . wp_remote_retrieve_body($resp) );
	}
	$data = json_decode( wp_remote_retrieve_body($resp), true );
	$ch   = $data['items'][0]['snippet']['title'] ?? '(unknown channel)';
	update_option('myls_ytoauth_test_result', 'OK: ' . $ch, false);
	wp_send_json_success( 'OAuth OK: ' . $ch );
}
add_action('wp_ajax_myls_test_youtube_oauth', 'myls_ajax_test_youtube_oauth');
