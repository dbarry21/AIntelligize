<?php
/**
 * MYLS – SEO Stuff (Admin Bar)
 *
 * Adds an "SEO Stuff" menu to the WP Admin Bar with quick links:
 *  - Google Rich Results Test
 *  - Schema.org Validator
 *  - PageSpeed Insights (mobile + desktop)
 *  - GTmetrix
 *  - Google index check (live dot via URL Inspection API) + Google "site:" results link
 *  - Request Indexing (opens the GSC URL Inspection UI with URL prefilled)
 *
 * Notes:
 *  - Live index status uses your existing OAuth token getter: myls_gsc_get_access_token()
 *  - This only checks index status; it does NOT trigger indexing via API (that’s not available publicly).
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Helper: determine the current page URL to test (works in admin editor + front-end).
 */
if ( ! function_exists('myls_current_test_url') ) {
    function myls_current_test_url(): string {

        if ( is_admin() && ! empty($_GET['post']) && ($post_id = absint($_GET['post'])) ) {
            $u = get_permalink($post_id);
        } elseif ( is_singular() ) {
            $u = get_permalink();
        } else {
            $u = home_url('/');
        }

        $u = esc_url_raw( untrailingslashit( $u ) );

        return $u ?: esc_url_raw( home_url('/') );
    }
}

/**
 * Safe accessor: reuse existing token getter (OAuth module), fallback to stored option.
 */
if ( ! function_exists('myls_gsc_access_token_current') ) {
    function myls_gsc_access_token_current(): string {

        if ( function_exists('myls_gsc_get_access_token') ) {
            $t = call_user_func('myls_gsc_get_access_token');
            return is_string($t) ? $t : '';
        }

        $t = get_option('myls_gsc_access_token');
        return is_string($t) ? $t : '';
    }
}

/**
 * Admin Bar menu.
 */
add_action( 'admin_bar_menu', function( $wp_admin_bar ) {

    if ( ! is_admin_bar_showing() || ! current_user_can('edit_posts') ) return;

    $test_url = myls_current_test_url();

    // Tools
    $rich_results_url = add_query_arg( ['url' => $test_url], 'https://search.google.com/test/rich-results' );
    $psi_mobile       = add_query_arg( ['url' => $test_url, 'form_factor' => 'mobile'  ], 'https://pagespeed.web.dev/analysis' );
    $psi_desktop      = add_query_arg( ['url' => $test_url, 'form_factor' => 'desktop' ], 'https://pagespeed.web.dev/analysis' );
    $gtmetrix_url     = add_query_arg( ['url' => $test_url], 'https://gtmetrix.com/analyze.html' );

    // Google helpers
    $google_site_results = 'https://www.google.com/search?q=' . rawurlencode( 'site:' . $test_url );
    $schemaorg_validator = 'https://validator.schema.org/#url=' . rawurlencode( $test_url );

    // Parent
    $wp_admin_bar->add_node([
        'id'    => 'myls-seo-stuff',
        'title' => __('SEO Stuff', 'aintelligize'),
        'href'  => false,
    ]);

    // Rich results
    $wp_admin_bar->add_node([
        'id'     => 'myls-test-rich-results',
        'parent' => 'myls-seo-stuff',
        'title'  => __('Test Rich Results', 'aintelligize'),
        'href'   => esc_url($rich_results_url),
        'meta'   => ['target' => '_blank', 'title' => __('Run Google Rich Results Test', 'aintelligize')],
    ]);

    // Schema.org validator
    $wp_admin_bar->add_node([
        'id'     => 'myls-test-schemaorg',
        'parent' => 'myls-seo-stuff',
        'title'  => __('Test Schema.org', 'aintelligize'),
        'href'   => esc_url($schemaorg_validator),
        'meta'   => ['target' => '_blank', 'title' => __('Run Schema.org Validator', 'aintelligize')],
    ]);

    // PSI
    $wp_admin_bar->add_node([
        'id'     => 'myls-page-speed-mobile',
        'parent' => 'myls-seo-stuff',
        'title'  => __('Page Speed (Mobile)', 'aintelligize'),
        'href'   => esc_url($psi_mobile),
        'meta'   => ['target' => '_blank'],
    ]);

    $wp_admin_bar->add_node([
        'id'     => 'myls-page-speed-desktop',
        'parent' => 'myls-seo-stuff',
        'title'  => __('Page Speed (Desktop)', 'aintelligize'),
        'href'   => esc_url($psi_desktop),
        'meta'   => ['target' => '_blank'],
    ]);

    // GTmetrix
    $wp_admin_bar->add_node([
        'id'     => 'myls-page-speed-gtmetrix',
        'parent' => 'myls-seo-stuff',
        'title'  => __('GTmetrix Test', 'aintelligize'),
        'href'   => esc_url($gtmetrix_url),
        'meta'   => ['target' => '_blank'],
    ]);

    // Google Index (status dot + real Google "site:" link)
    $title_html = '<span class="myls-gsc-dot" aria-hidden="true"></span> ' . esc_html__('Check Google Index', 'aintelligize');
    $wp_admin_bar->add_node([
        'id'     => 'myls-gsc-index',
        'parent' => 'myls-seo-stuff',
        'title'  => $title_html,
        'href'   => esc_url($google_site_results),
        'meta'   => [
            'target' => '_blank',
            'title'  => __('Open Google site: results and refresh index status', 'aintelligize')
        ],
    ]);

    // Request indexing (opens GSC UI)
    $site_prop = get_option('myls_gsc_site_property') ?: home_url('/');
    $inspect_ui = add_query_arg(
        [
            // GSC expects these URL encoded (it will decode them on load).
            'resource_id' => rawurlencode( trailingslashit( $site_prop ) ),
            'url'         => rawurlencode( $test_url ),
        ],
        'https://search.google.com/search-console/inspect'
    );

    $wp_admin_bar->add_node([
        'id'     => 'myls-gsc-open-ui',
        'parent' => 'myls-seo-stuff',
        'title'  => __('Request Indexing in GSC', 'aintelligize'),
        'href'   => esc_url($inspect_ui),
        'meta'   => ['target' => '_blank'],
    ]);

}, 100 );

/**
 * Inline assets (tiny CSS + JS to paint the index-status dot).
 */
if ( ! function_exists('myls_print_adminbar_gsc_assets') ) {
    function myls_print_adminbar_gsc_assets() {

        if ( ! is_user_logged_in() || ! is_admin_bar_showing() ) return;

        $nonce     = wp_create_nonce('myls_gsc_nonce');
        $ajax      = admin_url('admin-ajax.php');
        $url       = myls_current_test_url();
        $site_prop = get_option('myls_gsc_site_property') ?: home_url('/');
        $has_token = (bool) myls_gsc_access_token_current();
        ?>
        <style>
          .myls-gsc-dot{display:inline-block;width:10px;height:10px;border-radius:50%;background:#999;margin-right:6px}
          .myls-gsc-dot.is-checking{background:#aaa}
          .myls-gsc-dot.is-ok{background:#2bbf6a}
          .myls-gsc-dot.is-bad{background:#e55353}
        </style>
        <script>
          window.MYLS_GSC = {
            ajax: "<?php echo esc_js($ajax); ?>",
            nonce: "<?php echo esc_js($nonce); ?>",
            url: "<?php echo esc_js($url); ?>",
            siteUrl: "<?php echo esc_js( trailingslashit($site_prop) ); ?>",
            hasToken: <?php echo $has_token ? 'true' : 'false'; ?>
          };
          (function(){
            function post(data, cb){
              var x = new XMLHttpRequest();
              x.open('POST', MYLS_GSC.ajax, true);
              x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
              x.onload = function(){ cb && cb(x); };
              x.send(Object.keys(data).map(function(k){
                return k + '=' + encodeURIComponent(data[k]);
              }).join('&'));
            }
            function check(){
              var dot = document.querySelector('.myls-gsc-dot');
              if(!dot) return;
              dot.className = 'myls-gsc-dot is-checking';
              post({
                action: 'myls_gsc_check_index',
                nonce:  MYLS_GSC.nonce,
                url:    MYLS_GSC.url,
                siteUrl:MYLS_GSC.siteUrl
              }, function(x){
                try{
                  var r = JSON.parse(x.responseText || '{}');
                  dot.className = 'myls-gsc-dot ' + ((r.success && r.data && r.data.indexed) ? 'is-ok' : 'is-bad');
                } catch(e){
                  dot.className = 'myls-gsc-dot';
                }
              });
            }
            window.addEventListener('load', function(){
              if (MYLS_GSC.hasToken) check();
            });
          })();
        </script>
        <?php
    }
}

add_action('admin_head', 'myls_print_adminbar_gsc_assets');
add_action('wp_head',    'myls_print_adminbar_gsc_assets');

/**
 * AJAX: index status only (PASS => indexed).
 */
add_action('wp_ajax_myls_gsc_check_index', function() {

    check_ajax_referer('myls_gsc_nonce', 'nonce');

    $url  = esc_url_raw( $_POST['url']     ?? '' );
    $site = esc_url_raw( $_POST['siteUrl'] ?? '' );

    if ( ! $url || ! $site ) {
        wp_send_json_error(['message' => 'Missing URL or siteUrl.']);
    }

    $token = myls_gsc_access_token_current();
    if ( ! $token ) {
        wp_send_json_error(['message' => 'Missing GSC access token.']);
    }

    $resp = wp_remote_post(
        'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
        [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'inspectionUrl' => $url,
                'siteUrl'       => trailingslashit($site),
            ]),
        ]
    );

    if ( is_wp_error($resp) ) {
        wp_send_json_error(['message' => $resp->get_error_message()]);
    }

    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode( wp_remote_retrieve_body($resp), true );

    if ( $code < 200 || $code >= 300 || ! is_array($body) ) {
        wp_send_json_error(['message' => 'Bad response from GSC API.', 'code' => $code]);
    }

    $verdict = $body['inspectionResult']['indexStatusResult']['verdict'] ?? '';
    $indexed = ( strtoupper((string)$verdict) === 'PASS' );

    wp_send_json_success([
        'indexed'  => $indexed,
        'verdict'  => $verdict,
    ]);
});
