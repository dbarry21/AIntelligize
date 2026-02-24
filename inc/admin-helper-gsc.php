<?php
/**
 * AIntelligize – Admin Helper: Google Search Console
 * Uses OAuth values saved in API Integration tab.
 *
 * Expects options (already saved by your API Integration tab):
 * - myls_gsc_client_id
 * - myls_gsc_client_secret
 * - myls_gsc_access_token
 * - myls_gsc_refresh_token
 * - myls_gsc_token_expires (unix timestamp)
 * - myls_gsc_site_property (defaults to home_url('/'))
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Get a valid access token, refreshing if needed.
 */
function myls_gsc_get_access_token(): string {
    $access  = get_option('myls_gsc_access_token');
    $refresh = get_option('myls_gsc_refresh_token');
    $expiry  = (int) get_option('myls_gsc_token_expires');
    $cid     = get_option('myls_gsc_client_id');
    $secret  = get_option('myls_gsc_client_secret');

    // If we have a token that is still fresh (>= 5 min left), return it.
    if ($access && $expiry && (time() + 300) < $expiry) {
        return $access;
    }

    // Need to refresh
    if ( ! $refresh || ! $cid || ! $secret ) {
        return ''; // Not connected or missing creds
    }

    $resp = wp_remote_post('https://oauth2.googleapis.com/token', [
        'timeout' => 20,
        'body'    => [
            'client_id'     => $cid,
            'client_secret' => $secret,
            'refresh_token' => $refresh,
            'grant_type'    => 'refresh_token',
        ],
    ]);

    if (is_wp_error($resp)) return '';

    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code >= 200 && $code < 300 && ! empty($body['access_token'])) {
        update_option('myls_gsc_access_token', $body['access_token']);
        if (! empty($body['expires_in'])) {
            update_option('myls_gsc_token_expires', time() + (int)$body['expires_in']);
        }
        return $body['access_token'];
    }

    return '';
}

/**
 * Register toolbar item via your helper toolbar filter.
 * Your toolbar renderer should call:
 *   $items = apply_filters('myls_helper_toolbar_items', []);
 * and then output each $item['html'].
 */
add_filter('myls_helper_toolbar_items', function(array $items){
    $items[] = [
        'id'    => 'gsc-index',
        'order' => 40,
        'html'  => '<div class="myls-helper-item myls-gsc-block">
            <span class="myls-status-dot" aria-hidden="true"></span>
            <a href="#" class="myls-gsc-submit" title="Check / Submit to Google">Check / Submit to Google</a>
          </div>',
    ];
    return $items;
});

/**
 * Enqueue JS + dot styles on post editor screens.
 */
add_action('admin_enqueue_scripts', function($hook){
    if ( ! in_array($hook, ['post.php','post-new.php'], true) ) return;
    $post = get_post();
    if ( ! $post ) return;

    // Local data
    $has_token = (bool) myls_gsc_get_access_token(); // will also refresh if needed
    $site_prop = get_option('myls_gsc_site_property');
    if ( ! $site_prop ) $site_prop = home_url('/');

    wp_enqueue_script(
        'myls-helper-gsc',
        plugins_url('assets/js/myls-helper-gsc.js', MYLS_MAIN_FILE),
        ['jquery'],
        defined('MYLS_VERSION') ? MYLS_VERSION : time(),
        true
    );

    wp_localize_script('myls-helper-gsc', 'MYLS_GSC', [
        'ajax'     => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('myls_gsc_nonce'),
        'url'      => get_permalink($post),
        'siteUrl'  => trailingslashit($site_prop),
        'hasToken' => $has_token,
    ]);

    // Minimal CSS for status dot
    $css = '
    .myls-status-dot{display:inline-block;width:10px;height:10px;border-radius:50%;background:#c4c4c4;margin-right:8px;vertical-align:middle}
    .myls-status-dot.is-checking{background:#aaa}
    .myls-status-dot.is-ok{background:#2bbf6a}
    .myls-status-dot.is-bad{background:#e55353}
    .myls-status-dot.is-unknown{background:#999}';
    // piggyback a core style handle so inline CSS prints
    wp_add_inline_style('wp-components', $css);
});

/**
 * AJAX: Check index status with URL Inspection API
 */
add_action('wp_ajax_myls_gsc_check_index', function(){
    check_ajax_referer('myls_gsc_nonce','nonce');

    $url     = esc_url_raw($_POST['url'] ?? '');
    $siteUrl = esc_url_raw($_POST['siteUrl'] ?? '');
    if ( ! $url || ! $siteUrl ) {
        wp_send_json_error(['message'=>'Missing URL parameters.']);
    }

    $token = myls_gsc_get_access_token();
    if ( ! $token ) {
        wp_send_json_error(['message'=>'Google not connected.']);
    }

    $resp = wp_remote_post('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
        'headers' => [
            'Authorization' => 'Bearer '.$token,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode([
            'inspectionUrl' => $url,
            'siteUrl'       => trailingslashit($siteUrl),
        ]),
        'timeout' => 20,
    ]);

    if ( is_wp_error($resp) ) {
        wp_send_json_error(['message'=>$resp->get_error_message()]);
    }

    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);

    if ($code === 401 || $code === 403) {
        wp_send_json_error(['message'=>'Unauthorized. Reconnect Google.']);
    }

    $verdict        = $body['inspectionResult']['indexStatusResult']['verdict']        ?? '';
    $coverageState  = $body['inspectionResult']['indexStatusResult']['coverageState']  ?? '';
    $lastCrawlTime  = $body['inspectionResult']['indexStatusResult']['lastCrawlTime']  ?? '';
    $userCanonical  = $body['inspectionResult']['indexStatusResult']['userCanonical']  ?? '';
    $googleCanonical= $body['inspectionResult']['indexStatusResult']['googleCanonical']?? '';

    $indexed = (strtoupper($verdict) === 'PASS');

    wp_send_json_success([
        'indexed'         => $indexed,
        'verdict'         => $verdict,
        'coverageState'   => $coverageState,
        'lastCrawlTime'   => $lastCrawlTime,
        'userCanonical'   => $userCanonical,
        'googleCanonical' => $googleCanonical,
    ]);
});

/**
 * (Optional) AJAX: Request a re-inspect (acts like "fetch now")
 */
add_action('wp_ajax_myls_gsc_request_inspect', function(){
    check_ajax_referer('myls_gsc_nonce','nonce');

    $url     = esc_url_raw($_POST['url'] ?? '');
    $siteUrl = esc_url_raw($_POST['siteUrl'] ?? '');
    if ( ! $url || ! $siteUrl ) {
        wp_send_json_error(['message'=>'Missing URL parameters.']);
    }

    $token = myls_gsc_get_access_token();
    if ( ! $token ) {
        wp_send_json_error(['message'=>'Google not connected.']);
    }

    $resp = wp_remote_post('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
        'headers' => [
            'Authorization' => 'Bearer '.$token,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode([
            'inspectionUrl' => $url,
            'siteUrl'       => trailingslashit($siteUrl),
        ]),
        'timeout' => 20,
    ]);

    if ( is_wp_error($resp) ) {
        wp_send_json_error(['message'=>$resp->get_error_message()]);
    }

    $code = wp_remote_retrieve_response_code($resp);
    wp_send_json( ($code >= 200 && $code < 300) ? ['success'=>true] : ['success'=>false, 'code'=>$code] );
});
