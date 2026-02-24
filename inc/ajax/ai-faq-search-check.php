<?php
/**
 * AIntelligize – AJAX: Search Demand Checker
 * File: inc/ajax/ai-faq-search-check.php
 *
 * Endpoints:
 *  - wp_ajax_myls_faq_search_check_v1         (batch ≤20 terms)
 *  - wp_ajax_myls_faq_search_check_single_v1  (single term — progressive UI)
 *  - wp_ajax_myls_sd_get_focus_keyword_v1      (focus kw + meta for a post)
 *  - wp_ajax_myls_sd_get_all_site_faqs_v1      (every post with FAQs — data only)
 *
 * @since 6.3.2.7
 */

if ( ! defined('ABSPATH') ) exit;

/* ── Nonce ─────────────────────────────────────────────────────────── */
if ( ! function_exists('myls_ai_check_nonce') ) {
  function myls_ai_check_nonce( string $action = 'myls_ai_ops' ) : void {
    $nonce = isset($_POST['nonce']) ? (string) $_POST['nonce'] : (string) ( $_REQUEST['_ajax_nonce'] ?? '' );
    if ( ! wp_verify_nonce( $nonce, $action ) ) {
      wp_send_json_error(['status'=>'error','message'=>'bad_nonce'], 403);
    }
  }
}

/* ── Google Autocomplete ───────────────────────────────────────────── */
if ( ! function_exists('myls_sd_google_autocomplete') ) {
  function myls_sd_google_autocomplete( string $query ) : array {
    $query = trim( $query );
    if ( $query === '' ) return [ 'suggestions' => [], 'error' => 'Empty query' ];

    $url = add_query_arg([
      'q' => $query, 'client' => 'firefox', 'hl' => 'en',
    ], 'https://suggestqueries.google.com/complete/search');

    $resp = wp_remote_get( $url, [
      'timeout' => 10,
      'user-agent' => 'Mozilla/5.0 (compatible; MYLS-SearchDemand/1.0)',
      'headers' => [ 'Accept' => 'application/json' ],
    ]);

    if ( is_wp_error( $resp ) ) return [ 'suggestions' => [], 'error' => $resp->get_error_message() ];

    $code = wp_remote_retrieve_response_code( $resp );
    if ( $code < 200 || $code >= 300 ) return [ 'suggestions' => [], 'error' => "HTTP {$code}" ];

    $data = json_decode( wp_remote_retrieve_body( $resp ), true );
    if ( is_array($data) && isset($data[1]) && is_array($data[1]) ) {
      return [ 'suggestions' => array_values( array_map('strval', $data[1]) ), 'error' => null ];
    }
    return [ 'suggestions' => [], 'error' => null ];
  }
}

/* ── Scoring ───────────────────────────────────────────────────────── */
if ( ! function_exists('myls_sd_score_term') ) {
  function myls_sd_score_term( string $question, array $suggestions ) : array {
    $q_lower  = strtolower( trim( $question ) );
    $q_clean  = preg_replace('/[^a-z0-9\s]/', '', $q_lower);
    $q_words  = array_filter( explode(' ', $q_clean) );

    $stop = ['the','a','an','is','are','was','were','do','does','did','can','could',
             'will','would','should','how','what','when','where','why','which','who',
             'to','for','in','on','at','of','and','or','but','not','with','from','by',
             'it','i','my','your'];
    $q_kw = array_diff( $q_words, $stop );
    if ( empty($q_kw) ) $q_kw = $q_words;

    $matched = []; $best = 0;

    foreach ( $suggestions as $sug ) {
      $s = preg_replace('/[^a-z0-9\s]/', '', strtolower(trim($sug)));

      if ( $q_clean === $s )                                    { $matched[] = $sug; $best = 100; continue; }
      if ( strpos($s,$q_clean)!==false||strpos($q_clean,$s)!==false ) { $matched[] = $sug; $best = max($best,85); continue; }

      $sk = array_diff( array_filter(explode(' ',$s)), $stop );
      if ( empty($sk) ) $sk = array_filter(explode(' ',$s));
      $ol = array_intersect($q_kw, $sk);
      if ( count($ol)>0 && count($q_kw)>0 ) {
        $p = (count($ol)/count($q_kw))*70;
        if ($p>=40) { $matched[] = $sug; $best = max($best,(int)$p); }
      }
    }

    if ($best>=80) return ['score'=>'high',  'label'=>'Strong match', 'matched'=>array_unique($matched),'confidence'=>$best];
    if ($best>=50) return ['score'=>'medium','label'=>'Partial match','matched'=>array_unique($matched),'confidence'=>$best];
    if ($best>=30) return ['score'=>'low',   'label'=>'Weak match',   'matched'=>array_unique($matched),'confidence'=>$best];
    return ['score'=>'none','label'=>'No match found','matched'=>[],'confidence'=>0];
  }
}


/* ═══════════════════════════════════════════════════════════════════════
 * Single check (progressive UI uses this one-at-a-time)
 * ═══════════════════════════════════════════════════════════════════════ */
add_action('wp_ajax_myls_faq_search_check_single_v1', function(){
  myls_ai_check_nonce('myls_ai_ops');
  if ( ! current_user_can('manage_options') ) wp_send_json_error(['message'=>'Permission denied.'],403);

  $q = sanitize_text_field( wp_unslash( $_POST['question'] ?? '' ) );
  if ( trim($q) === '' ) wp_send_json_error(['message'=>'No question provided.']);

  $ac = myls_sd_google_autocomplete($q);
  if ( $ac['error'] ) wp_send_json_error(['message'=>'Autocomplete error: '.$ac['error'],'question'=>$q]);

  $sc = myls_sd_score_term($q, $ac['suggestions']);
  wp_send_json_success([
    'question'=>$q, 'score'=>$sc['score'], 'label'=>$sc['label'],
    'confidence'=>$sc['confidence'], 'matched'=>$sc['matched'],
    'suggestions'=>$ac['suggestions'],
  ]);
});


/* ═══════════════════════════════════════════════════════════════════════
 * Batch check (≤20, used by manual terms box)
 * ═══════════════════════════════════════════════════════════════════════ */
add_action('wp_ajax_myls_faq_search_check_v1', function(){
  myls_ai_check_nonce('myls_ai_ops');
  if ( ! current_user_can('manage_options') ) wp_send_json_error(['message'=>'Permission denied.'],403);

  $questions = [];
  if ( ! empty($_POST['questions']) && is_array($_POST['questions']) ) {
    foreach ( $_POST['questions'] as $q ) {
      $c = sanitize_text_field( wp_unslash((string)$q) );
      if ( trim($c) !== '' ) $questions[] = $c;
    }
  }
  if ( empty($questions) && ! empty($_POST['post_id']) ) {
    $items = get_post_meta( (int)$_POST['post_id'], '_myls_faq_items', true );
    if ( is_array($items) ) foreach ($items as $r) {
      if ( is_array($r) && !empty($r['q']) ) { $c = sanitize_text_field((string)$r['q']); if(trim($c)!=='') $questions[]=$c; }
    }
  }
  if ( empty($questions) ) wp_send_json_error(['message'=>'No search terms provided or found.']);

  // Load-only mode
  if ( ! empty($_POST['load_only']) ) {
    $out = []; foreach ($questions as $q) $out[] = ['question'=>$q];
    wp_send_json_success(['results'=>$out,'total'=>count($questions)]);
  }

  $questions = array_slice($questions, 0, 20);
  $results = []; $summary = ['high'=>0,'medium'=>0,'low'=>0,'none'=>0,'error'=>0];

  foreach ($questions as $i => $q) {
    $ac = myls_sd_google_autocomplete($q);
    if ($ac['error']) {
      $results[] = ['question'=>$q,'score'=>'error','label'=>'Failed: '.$ac['error'],'confidence'=>0,'matched'=>[],'suggestions'=>[]];
      $summary['error']++; continue;
    }
    $sc = myls_sd_score_term($q, $ac['suggestions']);
    $results[] = ['question'=>$q,'score'=>$sc['score'],'label'=>$sc['label'],'confidence'=>$sc['confidence'],'matched'=>$sc['matched'],'suggestions'=>$ac['suggestions']];
    $summary[$sc['score']]++;
    if ($i < count($questions)-1) usleep(200000);
  }
  wp_send_json_success(['results'=>$results,'summary'=>$summary,'total'=>count($questions)]);
});


/* ═══════════════════════════════════════════════════════════════════════
 * Focus keyword for a post (Yoast → Rank Math → AIOSEO)
 * ═══════════════════════════════════════════════════════════════════════ */
add_action('wp_ajax_myls_sd_get_focus_keyword_v1', function(){
  myls_ai_check_nonce('myls_ai_ops');
  if ( ! current_user_can('manage_options') ) wp_send_json_error(['message'=>'Permission denied.'],403);

  $pid = (int)($_POST['post_id'] ?? 0);
  if ( $pid<=0 || !get_post($pid) ) wp_send_json_error(['message'=>'Invalid post ID.']);

  $yoast_kw = trim((string)get_post_meta($pid,'_yoast_wpseo_focuskw',true));
  $rm_kw    = trim((string)get_post_meta($pid,'rank_math_focus_keyword',true));
  $aio_kw   = trim((string)get_post_meta($pid,'_aioseo_keywords',true));

  $focus=''; $source='';
  if ($yoast_kw!=='')    { $focus=$yoast_kw; $source='Yoast SEO'; }
  elseif ($rm_kw!=='')   { $parts=array_map('trim',explode(',',$rm_kw)); $focus=$parts[0]; $source='Rank Math'; }
  elseif ($aio_kw!=='')  { $focus=$aio_kw; $source='AIOSEO'; }

  $all = [];
  if ($yoast_kw!=='') $all[] = $yoast_kw;
  if ($rm_kw!=='') foreach (array_map('trim',explode(',',$rm_kw)) as $k) { if ($k!=='' && !in_array($k,$all,true)) $all[]=$k; }
  if ($aio_kw!=='' && !in_array($aio_kw,$all,true)) $all[] = $aio_kw;

  $faq_items = get_post_meta($pid,'_myls_faq_items',true);

  wp_send_json_success([
    'post_id'=>$pid, 'post_title'=>get_the_title($pid),
    'focus_keyword'=>$focus, 'all_keywords'=>$all, 'source'=>$source,
    'faq_count'=>is_array($faq_items)?count($faq_items):0,
  ]);
});


/* ═══════════════════════════════════════════════════════════════════════
 * All site FAQs (data only — client checks progressively)
 * ═══════════════════════════════════════════════════════════════════════ */
add_action('wp_ajax_myls_sd_get_all_site_faqs_v1', function(){
  myls_ai_check_nonce('myls_ai_ops');
  if ( ! current_user_can('manage_options') ) wp_send_json_error(['message'=>'Permission denied.'],403);

  global $wpdb;

  $rows = $wpdb->get_results(
    "SELECT pm.post_id, p.post_title, p.post_type, p.post_status
     FROM {$wpdb->postmeta} pm
     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
     WHERE pm.meta_key = '_myls_faq_items'
       AND p.post_status IN ('publish','draft','pending','future','private')
     ORDER BY p.post_title ASC", ARRAY_A
  );

  if ( empty($rows) ) wp_send_json_error(['message'=>'No posts with MYLS FAQs found on this site.']);

  $posts=[]; $total=0;
  foreach ($rows as $row) {
    $pid = (int)$row['post_id'];
    $items = get_post_meta($pid,'_myls_faq_items',true);
    if (!is_array($items)||empty($items)) continue;

    $qs = [];
    foreach ($items as $item) {
      if (is_array($item)&&!empty($item['q'])) {
        $q = sanitize_text_field((string)$item['q']);
        if (trim($q)!=='') $qs[] = $q;
      }
    }
    if (empty($qs)) continue;
    $total += count($qs);
    $posts[] = ['post_id'=>$pid,'post_title'=>$row['post_title']?:'(no title)','post_type'=>$row['post_type'],'status'=>$row['post_status'],'questions'=>$qs];
  }

  wp_send_json_success(['posts'=>$posts,'post_count'=>count($posts),'faq_count'=>$total]);
});


/* ═══════════════════════════════════════════════════════════════════════
 * All focus keywords by post type (data only — client checks progressively)
 * Action: myls_sd_get_all_focus_keywords_v1
 *
 * POST: post_type (optional, default all public types)
 * Returns every post that has a focus keyword set in Yoast/RM/AIOSEO.
 * ═══════════════════════════════════════════════════════════════════════ */
add_action('wp_ajax_myls_sd_get_all_focus_keywords_v1', function(){
  myls_ai_check_nonce('myls_ai_ops');
  if ( ! current_user_can('manage_options') ) wp_send_json_error(['message'=>'Permission denied.'],403);

  global $wpdb;

  // Which post type(s)?
  $filter_pt = ! empty($_POST['post_type']) ? sanitize_key($_POST['post_type']) : '';

  $pt_clause = "AND p.post_status IN ('publish','draft','pending','future','private')";
  if ( $filter_pt !== '' && $filter_pt !== 'all' ) {
    $pt_clause .= $wpdb->prepare(" AND p.post_type = %s", $filter_pt);
  }

  // Query posts that have ANY focus keyword meta key
  $meta_keys = [
    '_yoast_wpseo_focuskw',
    'rank_math_focus_keyword',
    '_aioseo_keywords',
  ];

  $placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
  $sql = $wpdb->prepare(
    "SELECT DISTINCT pm.post_id, p.post_title, p.post_type, p.post_status
     FROM {$wpdb->postmeta} pm
     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
     WHERE pm.meta_key IN ($placeholders)
       AND pm.meta_value != ''
       $pt_clause
     ORDER BY p.post_type ASC, p.post_title ASC",
    ...$meta_keys
  );

  $rows = $wpdb->get_results($sql, ARRAY_A);

  if ( empty($rows) ) {
    wp_send_json_error(['message' => 'No posts with focus keywords found' . ($filter_pt ? " for post type \"{$filter_pt}\"." : '.')]);
  }

  $posts = [];
  foreach ( $rows as $row ) {
    $pid = (int) $row['post_id'];

    $yoast_kw = trim((string) get_post_meta($pid, '_yoast_wpseo_focuskw', true));
    $rm_kw    = trim((string) get_post_meta($pid, 'rank_math_focus_keyword', true));
    $aio_kw   = trim((string) get_post_meta($pid, '_aioseo_keywords', true));

    $focus = ''; $source = '';
    if ($yoast_kw !== '')  { $focus = $yoast_kw; $source = 'Yoast SEO'; }
    elseif ($rm_kw !== '') {
      $parts = array_map('trim', explode(',', $rm_kw));
      $focus = $parts[0]; $source = 'Rank Math';
    }
    elseif ($aio_kw !== '') { $focus = $aio_kw; $source = 'AIOSEO'; }

    if ($focus === '') continue; // meta existed but was empty

    // Collect all keywords for this post
    $all_kw = [];
    if ($yoast_kw !== '') $all_kw[] = $yoast_kw;
    if ($rm_kw !== '') {
      foreach (array_map('trim', explode(',', $rm_kw)) as $k) {
        if ($k !== '' && !in_array($k, $all_kw, true)) $all_kw[] = $k;
      }
    }
    if ($aio_kw !== '' && !in_array($aio_kw, $all_kw, true)) $all_kw[] = $aio_kw;

    $posts[] = [
      'post_id'       => $pid,
      'post_title'    => $row['post_title'] ?: '(no title)',
      'post_type'     => $row['post_type'],
      'status'        => $row['post_status'],
      'focus_keyword' => $focus,
      'all_keywords'  => $all_kw,
      'source'        => $source,
    ];
  }

  if (empty($posts)) {
    wp_send_json_error(['message' => 'No valid focus keywords found.']);
  }

  wp_send_json_success([
    'posts'      => $posts,
    'post_count' => count($posts),
    'kw_count'   => array_sum(array_map(function($p){ return count($p['all_keywords']); }, $posts)),
  ]);
});


/* ═══════════════════════════════════════════════════════════════════════
 * GSC Search Analytics query — one keyword at a time
 * Action: myls_sd_gsc_query_v1
 *
 * POST: keyword (required), days (30|60|90, default 90)
 * Returns all GSC queries containing the keyword with
 * impressions, clicks, ctr, position.
 * ═══════════════════════════════════════════════════════════════════════ */
add_action('wp_ajax_myls_sd_gsc_query_v1', function(){
  myls_ai_check_nonce('myls_ai_ops');
  if ( ! current_user_can('manage_options') ) wp_send_json_error(['message'=>'Permission denied.'],403);

  $keyword = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
  if ( trim($keyword) === '' ) wp_send_json_error(['message'=>'No keyword provided.']);

  $days = (int)( $_POST['days'] ?? 90 );
  if ( ! in_array($days, [30, 60, 90], true) ) $days = 90;

  $post_url = sanitize_text_field( wp_unslash( $_POST['post_url'] ?? '' ) );
  $post_id  = (int)( $_POST['post_id'] ?? 0 );
  if ( ! $post_url && $post_id > 0 ) {
    $post_url = get_permalink($post_id);
  }

  // Get GSC access token
  if ( ! function_exists('myls_gsc_get_access_token') ) {
    wp_send_json_error(['message'=>'GSC helper not loaded. Is the API Integration tab configured?']);
  }

  $token = myls_gsc_get_access_token();
  if ( ! $token ) {
    wp_send_json_error(['message'=>'Google Search Console not connected. Connect it in the API Integration tab.']);
  }

  $site_prop = get_option('myls_gsc_site_property');
  if ( ! $site_prop ) $site_prop = home_url('/');
  $site_prop = trailingslashit($site_prop);

  $end_date   = gmdate('Y-m-d');
  $start_date = gmdate('Y-m-d', strtotime("-{$days} days"));

  $api_url = 'https://www.googleapis.com/webmasters/v3/sites/' .
             rawurlencode($site_prop) . '/searchAnalytics/query';

  $body = [
    'startDate'  => $start_date,
    'endDate'    => $end_date,
    'dimensions' => ['query'],
    'dimensionFilterGroups' => [[
      'filters' => [[
        'dimension'  => 'query',
        'operator'   => 'contains',
        'expression' => strtolower(trim($keyword)),
      ]],
    ]],
    'rowLimit' => 500,
  ];

  $resp = wp_remote_post($api_url, [
    'timeout' => 30,
    'headers' => [
      'Authorization' => 'Bearer ' . $token,
      'Content-Type'  => 'application/json',
    ],
    'body' => wp_json_encode($body),
  ]);

  if ( is_wp_error($resp) ) {
    wp_send_json_error(['message' => 'GSC API error: ' . $resp->get_error_message()]);
  }

  $code = wp_remote_retrieve_response_code($resp);
  $data = json_decode(wp_remote_retrieve_body($resp), true);

  if ($code === 401 || $code === 403) {
    wp_send_json_error(['message' => 'GSC authorization failed (HTTP ' . $code . '). Try reconnecting in API Integration.']);
  }

  if ($code < 200 || $code >= 300) {
    $err_msg = $data['error']['message'] ?? "HTTP {$code}";
    wp_send_json_error(['message' => 'GSC API error: ' . $err_msg]);
  }

  $rows = [];
  if ( ! empty($data['rows']) ) {
    foreach ($data['rows'] as $r) {
      $rows[] = [
        'query'       => $r['keys'][0] ?? '',
        'clicks'      => (int)($r['clicks'] ?? 0),
        'impressions' => (int)($r['impressions'] ?? 0),
        'ctr'         => round(($r['ctr'] ?? 0) * 100, 1),
        'position'    => round($r['position'] ?? 0, 1),
      ];
    }
    // Sort by impressions descending
    usort($rows, function($a, $b) { return $b['impressions'] <=> $a['impressions']; });
  }

  // ── Second query: AI Overview appearances ──
  $ai_queries = [];
  $ai_body = [
    'startDate'  => $start_date,
    'endDate'    => $end_date,
    'dimensions' => ['query', 'searchAppearance'],
    'dimensionFilterGroups' => [[
      'groupType' => 'and',
      'filters'   => [
        [
          'dimension'  => 'query',
          'operator'   => 'contains',
          'expression' => strtolower(trim($keyword)),
        ],
        [
          'dimension'  => 'searchAppearance',
          'operator'   => 'equals',
          'expression' => 'AI_OVERVIEW',
        ],
      ],
    ]],
    'rowLimit' => 500,
  ];

  $ai_resp = wp_remote_post($api_url, [
    'timeout' => 30,
    'headers' => [
      'Authorization' => 'Bearer ' . $token,
      'Content-Type'  => 'application/json',
    ],
    'body' => wp_json_encode($ai_body),
  ]);

  if ( ! is_wp_error($ai_resp) ) {
    $ai_code = wp_remote_retrieve_response_code($ai_resp);
    $ai_data = json_decode(wp_remote_retrieve_body($ai_resp), true);
    if ($ai_code >= 200 && $ai_code < 300 && ! empty($ai_data['rows'])) {
      foreach ($ai_data['rows'] as $r) {
        $q = $r['keys'][0] ?? '';
        $ai_queries[$q] = [
          'impressions' => (int)($r['impressions'] ?? 0),
          'clicks'      => (int)($r['clicks'] ?? 0),
        ];
      }
    }
  }

  // ── Third query: per-post position (keyword + page filter) ──
  $post_rank = null;
  $post_rank_data = null;
  if ( $post_url !== '' ) {
    $rank_body = [
      'startDate'  => $start_date,
      'endDate'    => $end_date,
      'dimensions' => ['query'],
      'dimensionFilterGroups' => [[
        'groupType' => 'and',
        'filters'   => [
          [
            'dimension'  => 'query',
            'operator'   => 'contains',
            'expression' => strtolower(trim($keyword)),
          ],
          [
            'dimension'  => 'page',
            'operator'   => 'equals',
            'expression' => $post_url,
          ],
        ],
      ]],
      'rowLimit' => 500,
    ];

    $rank_resp = wp_remote_post($api_url, [
      'timeout' => 30,
      'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'Content-Type'  => 'application/json',
      ],
      'body' => wp_json_encode($rank_body),
    ]);

    if ( ! is_wp_error($rank_resp) ) {
      $rank_code = wp_remote_retrieve_response_code($rank_resp);
      $rank_data = json_decode(wp_remote_retrieve_body($rank_resp), true);
      if ($rank_code >= 200 && $rank_code < 300 && ! empty($rank_data['rows'])) {
        // Aggregate: weighted avg position by impressions across all matching queries
        $total_imp  = 0;
        $weighted   = 0;
        $rank_rows  = [];
        foreach ($rank_data['rows'] as $rr) {
          $imp = (int)($rr['impressions'] ?? 0);
          $pos = (float)($rr['position'] ?? 0);
          $total_imp += $imp;
          $weighted  += $pos * $imp;
          $rank_rows[] = [
            'query'       => $rr['keys'][0] ?? '',
            'clicks'      => (int)($rr['clicks'] ?? 0),
            'impressions' => $imp,
            'ctr'         => round(($rr['ctr'] ?? 0) * 100, 1),
            'position'    => round($pos, 1),
          ];
        }
        $post_rank = $total_imp > 0 ? round($weighted / $total_imp, 1) : null;
        $post_rank_data = [
          'url'   => $post_url,
          'avg'   => $post_rank,
          'total' => count($rank_rows),
          'impr'  => $total_imp,
          'rows'  => $rank_rows,
        ];
      }
    }
  }

  wp_send_json_success([
    'keyword'        => $keyword,
    'days'           => $days,
    'start_date'     => $start_date,
    'end_date'       => $end_date,
    'rows'           => $rows,
    'total'          => count($rows),
    'ai_overview'    => $ai_queries,
    'ai_count'       => count($ai_queries),
    'post_rank'      => $post_rank,
    'post_rank_data' => $post_rank_data,
  ]);
});


/* ═══════════════════════════════════════════════════════════════════════
 * DASHBOARD DB ENDPOINTS
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Load all dashboard data from DB.
 * Action: myls_sd_db_load
 * POST: post_type (optional, default 'all')
 */
add_action('wp_ajax_myls_sd_db_load', function(){
  myls_ai_check_nonce('myls_ai_ops');
  if ( ! current_user_can('manage_options') ) wp_send_json_error(['message'=>'Permission denied.'],403);

  $pt = sanitize_text_field( $_POST['post_type'] ?? 'all' );
  $rows  = myls_sd_get_all( $pt );
  $stats = myls_sd_get_stats();

  wp_send_json_success([
    'rows'  => $rows,
    'stats' => $stats,
  ]);
});

/**
 * Scan site for focus keywords and upsert into DB.
 * Action: myls_sd_db_scan
 * POST: post_type (optional)
 */
add_action('wp_ajax_myls_sd_db_scan', function(){
  myls_ai_check_nonce('myls_ai_ops');
  if ( ! current_user_can('manage_options') ) wp_send_json_error(['message'=>'Permission denied.'],403);

  $filter_pt = sanitize_text_field( $_POST['post_type'] ?? '' );

  global $wpdb;
  $meta_keys = [
    '_yoast_wpseo_focuskw',
    'rank_math_focus_keyword',
    '_aioseo_keywords',
  ];
  $placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
  $params = $meta_keys;

  $sql = "SELECT DISTINCT p.ID as post_id, p.post_title, p.post_type, p.post_status
          FROM {$wpdb->posts} p
          INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
          WHERE pm.meta_key IN ({$placeholders})
          AND pm.meta_value != ''
          AND p.post_status IN ('publish','draft','private')";

  if ( $filter_pt && $filter_pt !== 'all' ) {
    $sql .= $wpdb->prepare(' AND p.post_type = %s', $filter_pt);
  }

  $sql .= ' ORDER BY p.post_type, p.post_title';
  $rows = $wpdb->get_results( $wpdb->prepare($sql, ...$params), ARRAY_A );

  $upserted = 0;
  foreach ( ($rows ?: []) as $row ) {
    $pid = (int) $row['post_id'];
    $yoast = trim((string) get_post_meta($pid, '_yoast_wpseo_focuskw', true));
    $rm    = trim((string) get_post_meta($pid, 'rank_math_focus_keyword', true));
    $aio   = trim((string) get_post_meta($pid, '_aioseo_keywords', true));

    // Collect all keywords
    $keywords = [];
    if ($yoast !== '') $keywords[] = ['kw' => $yoast, 'src' => 'Yoast SEO'];
    if ($rm !== '') {
      foreach (array_map('trim', explode(',', $rm)) as $k) {
        if ($k !== '') $keywords[] = ['kw' => $k, 'src' => 'Rank Math'];
      }
    }
    if ($aio !== '' && !in_array($aio, array_column($keywords, 'kw'), true)) {
      $keywords[] = ['kw' => $aio, 'src' => 'AIOSEO'];
    }

    foreach ($keywords as $kw) {
      myls_sd_upsert([
        'post_id'    => $pid,
        'post_title' => $row['post_title'] ?: '(no title)',
        'post_type'  => $row['post_type'],
        'keyword'    => $kw['kw'],
        'source'     => $kw['src'],
      ]);
      $upserted++;
    }
  }

  // ── Also scan FAQ questions (_myls_faq_items) ──
  $faq_sql = "SELECT DISTINCT p.ID as post_id, p.post_title, p.post_type
              FROM {$wpdb->posts} p
              INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
              WHERE pm.meta_key = '_myls_faq_items'
              AND pm.meta_value != ''
              AND pm.meta_value != 'a:0:{}'
              AND p.post_status IN ('publish','draft','private')";

  if ( $filter_pt && $filter_pt !== 'all' ) {
    $faq_sql .= $wpdb->prepare(' AND p.post_type = %s', $filter_pt);
  }

  $faq_sql .= ' ORDER BY p.post_type, p.post_title';
  $faq_posts = $wpdb->get_results( $faq_sql, ARRAY_A );

  foreach ( ($faq_posts ?: []) as $fp ) {
    $pid   = (int) $fp['post_id'];
    $items = get_post_meta($pid, '_myls_faq_items', true);
    if ( ! is_array($items) || empty($items) ) continue;

    foreach ($items as $item) {
      $q = isset($item['q']) ? trim($item['q']) : '';
      if ( $q === '' ) continue;

      myls_sd_upsert([
        'post_id'    => $pid,
        'post_title' => $fp['post_title'] ?: '(no title)',
        'post_type'  => $fp['post_type'],
        'keyword'    => $q,
        'source'     => 'FAQ',
      ]);
      $upserted++;
    }
  }

  if ( $upserted === 0 ) {
    wp_send_json_error(['message' => 'No focus keywords or FAQ questions found.']);
  }

  // Prune orphans
  $pruned = myls_sd_prune_orphans();

  // Return fresh data
  $all   = myls_sd_get_all( $filter_pt );
  $stats = myls_sd_get_stats();

  wp_send_json_success([
    'rows'     => $all,
    'stats'    => $stats,
    'upserted' => $upserted,
    'pruned'   => $pruned,
  ]);
});

/**
 * Save AC data for a specific DB row.
 * Action: myls_sd_db_save_ac
 * POST: row_id, ac_suggestions (JSON string), ac_count
 */
add_action('wp_ajax_myls_sd_db_save_ac', function(){
  myls_ai_check_nonce('myls_ai_ops');
  if ( ! current_user_can('manage_options') ) wp_send_json_error(['message'=>'Permission denied.'],403);

  $row_id = (int)( $_POST['row_id'] ?? 0 );
  if ( ! $row_id ) wp_send_json_error(['message' => 'Missing row_id.']);

  $ac_json  = wp_unslash( $_POST['ac_suggestions'] ?? '[]' );
  $ac_data  = json_decode($ac_json, true);
  $ac_count = (int)( $_POST['ac_count'] ?? 0 );

  myls_sd_save_ac( $row_id, $ac_data, $ac_count );
  wp_send_json_success(['saved' => true]);
});

/**
 * Save GSC data for a specific DB row.
 * Action: myls_sd_db_save_gsc
 * POST: row_id, gsc_data (JSON), gsc_total, ai_overview (JSON), ai_count, days
 */
add_action('wp_ajax_myls_sd_db_save_gsc', function(){
  myls_ai_check_nonce('myls_ai_ops');
  if ( ! current_user_can('manage_options') ) wp_send_json_error(['message'=>'Permission denied.'],403);

  $row_id = (int)( $_POST['row_id'] ?? 0 );
  if ( ! $row_id ) wp_send_json_error(['message' => 'Missing row_id.']);

  $gsc_data    = json_decode(wp_unslash($_POST['gsc_data'] ?? '[]'), true);
  $gsc_total   = (int)( $_POST['gsc_total'] ?? 0 );
  $ai_overview = json_decode(wp_unslash($_POST['ai_overview'] ?? '{}'), true);
  $ai_count    = (int)( $_POST['ai_count'] ?? 0 );
  $days        = (int)( $_POST['days'] ?? 90 );
  $post_rank   = isset($_POST['post_rank']) && $_POST['post_rank'] !== '' ? (float)$_POST['post_rank'] : null;
  $post_rank_data = isset($_POST['post_rank_data']) ? json_decode(wp_unslash($_POST['post_rank_data']), true) : null;

  myls_sd_save_gsc( $row_id, $gsc_data, $gsc_total, $ai_overview, $ai_count, $days, $post_rank, $post_rank_data );
  wp_send_json_success(['saved' => true]);
});

/**
 * Delete a row or clear all.
 * Action: myls_sd_db_delete
 * POST: row_id (0 = clear all)
 */
add_action('wp_ajax_myls_sd_db_delete', function(){
  myls_ai_check_nonce('myls_ai_ops');
  if ( ! current_user_can('manage_options') ) wp_send_json_error(['message'=>'Permission denied.'],403);

  $row_id = (int)( $_POST['row_id'] ?? 0 );
  if ( $row_id > 0 ) {
    myls_sd_delete_row($row_id);
  } else {
    myls_sd_clear_all();
  }

  wp_send_json_success(['deleted' => true]);
});

/**
 * Get history snapshots for a keyword.
 * Action: myls_sd_db_history
 * POST: row_id, limit (optional, default 90)
 */
add_action('wp_ajax_myls_sd_db_history', function(){
  myls_ai_check_nonce('myls_ai_ops');
  if ( ! current_user_can('manage_options') ) wp_send_json_error(['message'=>'Permission denied.'],403);

  $row_id = (int)( $_POST['row_id'] ?? 0 );
  if ( ! $row_id ) wp_send_json_error(['message' => 'Missing row_id.']);

  $limit = (int)( $_POST['limit'] ?? 90 );
  if ( $limit < 1 || $limit > 365 ) $limit = 90;

  $history = myls_sd_get_history( $row_id, $limit );

  wp_send_json_success([
    'row_id'  => $row_id,
    'history' => $history,
    'count'   => count($history),
  ]);
});
