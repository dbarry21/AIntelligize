<?php
if (!defined('ABSPATH')) exit;

/**
 * Utilities: one-off IPv4 HTTP wrappers so flaky IPv6 doesn't break tests.
 */
function myls_http_get_ipv4(string $url, array $args = []) {
  $args = wp_parse_args($args, ['timeout'=>12, 'redirection'=>2, 'headers'=>[]]);
  $force = function($h){ if (function_exists('curl_setopt')) @curl_setopt($h, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); };
  add_action('http_api_curl', $force, 10, 1);
  $res = wp_remote_get($url, $args);
  remove_action('http_api_curl', $force, 10);
  return $res;
}
function myls_http_head_ipv4(string $url, array $args = []) {
  $args = wp_parse_args($args, ['timeout'=>12, 'redirection'=>2, 'headers'=>[]]);
  $force = function($h){ if (function_exists('curl_setopt')) @curl_setopt($h, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); };
  add_action('http_api_curl', $force, 10, 1);
  $res = wp_remote_head($url, $args);
  remove_action('http_api_curl', $force, 10);
  return $res;
}
function myls_ajax_guard(): void {
  check_ajax_referer('myls_api_tab_ajax','nonce');
  if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);
}
/** Small helper to trim huge bodies in notices */
function myls_snip($s, $len=180){ $s = (string)$s; return (strlen($s) > $len) ? substr($s,0,$len).'…' : $s; }

/** ---------------------- OpenAI key test ---------------------- */
add_action('wp_ajax_myls_test_openai_key', function(){
  myls_ajax_guard();
  $key = sanitize_text_field($_POST['key'] ?? get_option('myls_openai_api_key',''));
  if ($key === '') { wp_send_json_error('No OpenAI API key provided'); }
  $r = myls_http_get_ipv4('https://api.openai.com/v1/models', [
    'headers' => ['Authorization' => 'Bearer '.$key],
  ]);
  if (is_wp_error($r)) {
    $msg = 'HTTP error: '.$r->get_error_code().' — '.$r->get_error_message();
    update_option('myls_openai_test_result', $msg.' @ '.current_time('mysql'));
    wp_send_json_error($msg);
  }
  $code = wp_remote_retrieve_response_code($r);
  if ($code === 200) {
    update_option('myls_openai_test_result', 'OK (200) @ '.current_time('mysql'));
    wp_send_json_success('OpenAI reachable (200)');
  } elseif ($code === 401) {
    update_option('myls_openai_test_result', 'Unauthorized (401) @ '.current_time('mysql'));
    wp_send_json_error('Unauthorized (401): check API key');
  } else {
    $body = myls_snip(wp_remote_retrieve_body($r));
    update_option('myls_openai_test_result', "HTTP {$code} @ ".current_time('mysql'));
    wp_send_json_error("Unexpected HTTP {$code}".($body ? " — ".myls_snip($body,120):''));
  }
});

/** ---------------------- Anthropic (Claude) key test ---------------------- */
add_action('wp_ajax_myls_test_anthropic_key', function(){
  myls_ajax_guard();
  $key = sanitize_text_field($_POST['key'] ?? get_option('myls_anthropic_api_key',''));
  if ($key === '') { wp_send_json_error('No Anthropic API key provided'); }

  // Send a tiny completion to verify the key works end-to-end
  $body = wp_json_encode([
    'model'      => 'claude-haiku-4-5-20251001',
    'max_tokens' => 10,
    'messages'   => [['role' => 'user', 'content' => 'Say OK']],
  ]);

  $force = function($h){ if (function_exists('curl_setopt')) @curl_setopt($h, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); };
  add_action('http_api_curl', $force, 10, 1);
  $r = wp_remote_post('https://api.anthropic.com/v1/messages', [
    'timeout' => 15,
    'headers' => [
      'Content-Type'      => 'application/json',
      'x-api-key'         => $key,
      'anthropic-version'  => '2023-06-01',
    ],
    'body' => $body,
  ]);
  remove_action('http_api_curl', $force, 10);

  if (is_wp_error($r)) {
    $msg = 'HTTP error: '.$r->get_error_code().' — '.$r->get_error_message();
    update_option('myls_anthropic_test_result', $msg.' @ '.current_time('mysql'));
    wp_send_json_error($msg);
  }

  $code = (int) wp_remote_retrieve_response_code($r);
  $json = json_decode(wp_remote_retrieve_body($r), true);

  if ($code === 200 && is_array($json) && isset($json['content'])) {
    $model_used = $json['model'] ?? 'unknown';
    update_option('myls_anthropic_test_result', "OK (200) model: {$model_used} @ ".current_time('mysql'));
    wp_send_json_success(['message' => "Anthropic OK — model: {$model_used}"]);
  } elseif ($code === 401) {
    update_option('myls_anthropic_test_result', 'Unauthorized (401) @ '.current_time('mysql'));
    wp_send_json_error('Unauthorized (401): check API key');
  } elseif ($code === 400 || $code === 404) {
    $err = $json['error']['message'] ?? 'Bad request';
    update_option('myls_anthropic_test_result', "HTTP {$code}: {$err} @ ".current_time('mysql'));
    wp_send_json_error("HTTP {$code}: ".myls_snip($err, 120));
  } else {
    $err = $json['error']['message'] ?? myls_snip(wp_remote_retrieve_body($r));
    update_option('myls_anthropic_test_result', "HTTP {$code} @ ".current_time('mysql'));
    wp_send_json_error("Unexpected HTTP {$code}".($err ? " — ".myls_snip($err, 120) : ''));
  }
});

/** ---------------------- Google Places key test ---------------------- */
add_action('wp_ajax_myls_test_places_key', function(){
  myls_ajax_guard();
  $key = sanitize_text_field($_POST['key'] ?? get_option('myls_google_places_api_key',''));
  if ($key === '') { wp_send_json_error('No Places API key provided'); }
  $url = add_query_arg([
    'input'      => 'Google',
    'inputtype'  => 'textquery',
    'fields'     => 'place_id',
    'key'        => $key,
  ], 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json');

  $r = myls_http_get_ipv4($url);
  if (is_wp_error($r)) { wp_send_json_error('HTTP error: '.$r->get_error_message()); }
  $body = json_decode(wp_remote_retrieve_body($r), true);
  $status = $body['status'] ?? '';
  if (in_array($status, ['OK','ZERO_RESULTS'], true)) {
    update_option('myls_places_test_result', 'OK ('.$status.') @ '.current_time('mysql'));
    wp_send_json_success('Places key OK ('.$status.')');
  }
  $err = ($body['error_message'] ?? 'Unknown error');
  update_option('myls_places_test_result', $status.': '.$err.' @ '.current_time('mysql'));
  wp_send_json_error($status.': '.$err);
});

/** ---------------------- Google Places Place ID test ---------------------- */
add_action('wp_ajax_myls_test_places_pid', function(){
  myls_ajax_guard();
  $key  = sanitize_text_field($_POST['key'] ?? get_option('myls_google_places_api_key',''));
  $pid  = sanitize_text_field($_POST['place_id'] ?? get_option('myls_google_places_place_id',''));
  if ($key === '' || $pid === '') { wp_send_json_error('Need API key and Place ID'); }
  $url = add_query_arg([
    'place_id' => $pid,
    'fields'   => 'place_id,name,url',
    'key'      => $key,
  ], 'https://maps.googleapis.com/maps/api/place/details/json');

  $r = myls_http_get_ipv4($url);
  if (is_wp_error($r)) { wp_send_json_error('HTTP error: '.$r->get_error_message()); }
  $body = json_decode(wp_remote_retrieve_body($r), true);
  $status = $body['status'] ?? '';
  if ($status === 'OK') {
    $name = $body['result']['name'] ?? 'OK';
    update_option('myls_places_pid_test_result', 'OK: '.$name.' @ '.current_time('mysql'));
    wp_send_json_success('Place ID OK: '.$name);
  }
  $err = ($body['error_message'] ?? 'Unknown error');
  update_option('myls_places_pid_test_result', $status.': '.$err.' @ '.current_time('mysql'));
  wp_send_json_error($status.': '.$err);
});

/** ---------------------- Fetch rating + review count from Places API ---------------------- */
add_action('wp_ajax_myls_fetch_places_rating', function(){
  myls_ajax_guard();
  $key = sanitize_text_field($_POST['key'] ?? get_option('myls_google_places_api_key',''));
  $pid = sanitize_text_field($_POST['place_id'] ?? get_option('myls_google_places_place_id',''));
  if ($key === '' || $pid === '') { wp_send_json_error('API key and Place ID are required.'); }

  $url = add_query_arg([
    'place_id' => $pid,
    'fields'   => 'name,rating,user_ratings_total',
    'key'      => $key,
  ], 'https://maps.googleapis.com/maps/api/place/details/json');

  $r = myls_http_get_ipv4($url);
  if (is_wp_error($r)) { wp_send_json_error('HTTP error: '.$r->get_error_message()); }

  $body   = json_decode(wp_remote_retrieve_body($r), true);
  $status = $body['status'] ?? '';
  if ($status !== 'OK') {
    wp_send_json_error($status.': '.($body['error_message'] ?? 'Unknown error'));
  }

  $rating       = (string) ($body['result']['rating']             ?? '');
  $review_count = (string) ($body['result']['user_ratings_total'] ?? '');
  $name         = (string) ($body['result']['name']               ?? '');

  if ($rating === '' || $review_count === '') {
    wp_send_json_error('Places API returned no rating data for this Place ID.');
  }

  update_option('myls_google_places_rating',       $rating);
  update_option('myls_google_places_review_count', $review_count);
  update_option('myls_places_rating_fetched_at',   current_time('mysql'));

  wp_send_json_success([
    'name'         => $name,
    'rating'       => $rating,
    'review_count' => $review_count,
    'message'      => $name.' — '. $rating.' stars, '.$review_count.' reviews',
  ]);
});

/** ---------------------- Static Maps key test ---------------------- */
add_action('wp_ajax_myls_test_maps_key', function(){
  myls_ajax_guard();
  $key = sanitize_text_field($_POST['key'] ?? get_option('myls_google_static_maps_api_key',''));
  if ($key === '') { wp_send_json_error('No Static Maps API key provided'); }
  $url = add_query_arg([
    'size'    => '1x1',
    'markers' => '0,0',
    'key'     => $key,
  ], 'https://maps.googleapis.com/maps/api/staticmap');
  $r = myls_http_head_ipv4($url);
  if (is_wp_error($r)) { wp_send_json_error('HTTP error: '.$r->get_error_message()); }
  $code = wp_remote_retrieve_response_code($r);
  if ($code === 200) {
    update_option('myls_maps_test_result', 'OK (200) @ '.current_time('mysql'));
    wp_send_json_success('Static Maps reachable (200)');
  }
  update_option('myls_maps_test_result', 'HTTP '.$code.' @ '.current_time('mysql'));
  wp_send_json_error('Unexpected HTTP '.$code);
});

/** ---------------------- YouTube (API key) test ---------------------- */
add_action('wp_ajax_myls_test_youtube_api', function(){
  myls_ajax_guard();
  $key     = get_option('myls_youtube_api_key','');
  $channel = get_option('myls_youtube_channel_id','');
  if ($key === '') { wp_send_json_error('No YouTube API key saved'); }

  if ($channel) {
    $url = add_query_arg(['part'=>'id','id'=>$channel,'key'=>$key], 'https://www.googleapis.com/youtube/v3/channels');
  } else {
    $url = add_query_arg(['part'=>'id','chart'=>'mostPopular','maxResults'=>1,'key'=>$key], 'https://www.googleapis.com/youtube/v3/videos');
  }
  $r = myls_http_get_ipv4($url);
  if (is_wp_error($r)) { wp_send_json_error('HTTP error: '.$r->get_error_message()); }
  $code = wp_remote_retrieve_response_code($r);
  $body = json_decode(wp_remote_retrieve_body($r), true);
  if ($code === 200 && isset($body['items'])) {
    update_option('myls_youtube_test_result', 'OK (200) @ '.current_time('mysql'));
    wp_send_json_success('YouTube Data API reachable (200)');
  }
  $err = $body['error']['message'] ?? ('HTTP '.$code);
  update_option('myls_youtube_test_result', myls_snip($err).' @ '.current_time('mysql'));
  wp_send_json_error(myls_snip($err));
});

/** ---------------------- YouTube (OAuth) test ---------------------- */
add_action('wp_ajax_myls_test_youtube_oauth', function(){
  myls_ajax_guard();
  // If your OAuth module exposes a call helper, use it; otherwise just report connection state.
  if (function_exists('myls_yt_oauth_call')) {
    $r = myls_yt_oauth_call('https://www.googleapis.com/youtube/v3/channels?part=id&mine=true', 'GET');
    if (is_wp_error($r)) {
      $msg = 'OAuth HTTP error: '.$r->get_error_message();
      update_option('myls_ytoauth_test_result', $msg.' @ '.current_time('mysql'));
      wp_send_json_error($msg);
    }
    $code = wp_remote_retrieve_response_code($r);
    if ($code === 200) {
      update_option('myls_ytoauth_test_result', 'OK (200) @ '.current_time('mysql'));
      wp_send_json_success('YouTube OAuth OK (200)');
    }
    $body = wp_remote_retrieve_body($r);
    update_option('myls_ytoauth_test_result', 'HTTP '.$code.' @ '.current_time('mysql'));
    wp_send_json_error('Unexpected HTTP '.$code.($body? ' — '.myls_snip($body,120):''));
  } else {
    // Fallback: no module loaded yet
    $ok = (string) get_option('myls_yt_refresh_token','') !== '';
    $msg = $ok ? 'Connected (token present)' : 'Not connected';
    update_option('myls_ytoauth_test_result', $msg.' @ '.current_time('mysql'));
    $ok ? wp_send_json_success($msg) : wp_send_json_error($msg);
  }
});

/** ---------------------- GSC client / OAuth test ---------------------- */
add_action('wp_ajax_myls_test_gsc_client', function(){
  myls_ajax_guard();
  $cid = get_option('myls_gsc_client_id','');
  $sec = get_option('myls_gsc_client_secret','');
  $red = get_option('myls_gsc_redirect_uri','');
  if ($cid && $sec && $red) {
    if (function_exists('myls_gsc_oauth_call') && function_exists('myls_gsc_is_connected') && myls_gsc_is_connected()) {
      $r = myls_gsc_oauth_call('https://www.googleapis.com/webmasters/v3/sites', 'GET');
      if (is_wp_error($r)) {
        $msg = 'OAuth error: '.$r->get_error_message();
        update_option('myls_gsc_test_result', $msg.' @ '.current_time('mysql'));
        wp_send_json_error($msg);
      }
      // myls_gsc_oauth_call returns decoded JSON on success
      $sites = [];
      if ( ! empty($r['siteEntry']) ) {
        foreach ($r['siteEntry'] as $s) $sites[] = $s['siteUrl'] ?? '';
      }
      $count = count($sites);
      $msg = 'OK: '.$count.' site'.($count!==1?'s':'').' found';
      if ($count > 0) $msg .= ' ('.implode(', ', array_slice($sites, 0, 3)).')';
      update_option('myls_gsc_test_result', $msg.' @ '.current_time('mysql'));
      wp_send_json_success($msg);
    } else {
      update_option('myls_gsc_test_result', 'Client configured; not connected @ '.current_time('mysql'));
      wp_send_json_success('Client configured. Connect Google to fully test.');
    }
  } else {
    update_option('myls_gsc_test_result', 'Missing client config @ '.current_time('mysql'));
    wp_send_json_error('Client ID/Secret/Redirect URI not set');
  }
});
