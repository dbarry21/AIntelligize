<?php
/**
 * AI Exposure – Database Table + CRUD
 * Path: inc/db/ai-exposure-table.php
 *
 * Custom tables:
 *   {prefix}myls_ai_exposure          — individual check results per keyword per platform
 *   {prefix}myls_ai_exposure_history  — daily snapshots for trend tracking
 *
 * Stores AI chatbot exposure data: whether ChatGPT / Claude cite or mention
 * the user's domain when asked about tracked keywords.
 *
 * @since 7.9.0
 */

if ( ! defined('ABSPATH') ) exit;

define( 'MYLS_AE_DB_VERSION', '1.0' );

/* ═══════════════════════════════════════════════════════
 *  Table Names
 * ═══════════════════════════════════════════════════════ */

function myls_ae_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'myls_ai_exposure';
}

function myls_ae_history_table() {
    global $wpdb;
    return $wpdb->prefix . 'myls_ai_exposure_history';
}

/* ═══════════════════════════════════════════════════════
 *  Table Creation (dbDelta)
 * ═══════════════════════════════════════════════════════ */

function myls_ae_maybe_create_table() {
    $installed = get_option('myls_ae_db_version', '');
    if ( $installed === MYLS_AE_DB_VERSION ) return;

    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // ── Main table: individual check results ──
    $table = myls_ae_table_name();
    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        keyword varchar(255) NOT NULL DEFAULT '',
        sd_id bigint(20) unsigned DEFAULT NULL,
        post_id bigint(20) unsigned NOT NULL DEFAULT 0,
        domain varchar(255) NOT NULL DEFAULT '',
        platform varchar(30) NOT NULL DEFAULT '',
        model_used varchar(80) NOT NULL DEFAULT '',
        cited tinyint(1) NOT NULL DEFAULT 0,
        citation_count int(11) unsigned NOT NULL DEFAULT 0,
        source_position int(11) unsigned DEFAULT NULL,
        response_text longtext DEFAULT NULL,
        citations_found longtext DEFAULT NULL,
        competitor_domains longtext DEFAULT NULL,
        prompt_used text DEFAULT NULL,
        tokens_used int(11) unsigned NOT NULL DEFAULT 0,
        cost_usd decimal(10,6) NOT NULL DEFAULT 0.000000,
        error_message text DEFAULT NULL,
        checked_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_keyword (keyword(191)),
        KEY idx_platform (platform),
        KEY idx_domain (domain(191)),
        KEY idx_cited (cited),
        KEY idx_checked (checked_at),
        KEY idx_sd_id (sd_id)
    ) {$charset};";

    // ── History table: daily snapshots for trends ──
    $htable = myls_ae_history_table();
    $sql2 = "CREATE TABLE {$htable} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        keyword varchar(255) NOT NULL DEFAULT '',
        snapshot_date date NOT NULL,
        domain varchar(255) NOT NULL DEFAULT '',
        chatgpt_cited tinyint(1) DEFAULT NULL,
        chatgpt_count int(11) unsigned NOT NULL DEFAULT 0,
        chatgpt_position int(11) unsigned DEFAULT NULL,
        claude_cited tinyint(1) DEFAULT NULL,
        claude_count int(11) unsigned NOT NULL DEFAULT 0,
        claude_position int(11) unsigned DEFAULT NULL,
        competitor_count int(11) unsigned NOT NULL DEFAULT 0,
        exposure_score decimal(5,2) DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY kw_date_domain (keyword(150), snapshot_date, domain(100)),
        KEY idx_keyword (keyword(191)),
        KEY idx_snapshot (snapshot_date),
        KEY idx_domain (domain(191))
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    dbDelta( $sql2 );
    update_option('myls_ae_db_version', MYLS_AE_DB_VERSION);
}
add_action('admin_init', 'myls_ae_maybe_create_table');

/* ═══════════════════════════════════════════════════════
 *  CRUD Helpers
 * ═══════════════════════════════════════════════════════ */

/**
 * Save a single check result.
 *
 * @param array $data  Associative array of column values.
 * @return int         Inserted row ID.
 */
function myls_ae_save_result( $data ) {
    global $wpdb;
    $table = myls_ae_table_name();

    $row = [
        'keyword'            => sanitize_text_field( $data['keyword'] ?? '' ),
        'sd_id'              => isset($data['sd_id']) && $data['sd_id'] ? absint($data['sd_id']) : null,
        'post_id'            => absint( $data['post_id'] ?? 0 ),
        'domain'             => sanitize_text_field( $data['domain'] ?? '' ),
        'platform'           => sanitize_text_field( $data['platform'] ?? '' ),
        'model_used'         => sanitize_text_field( $data['model_used'] ?? '' ),
        'cited'              => (int) ( $data['cited'] ?? 0 ),
        'citation_count'     => absint( $data['citation_count'] ?? 0 ),
        'source_position'    => isset($data['source_position']) && $data['source_position'] !== null ? absint($data['source_position']) : null,
        'response_text'      => isset($data['response_text']) ? mb_substr( $data['response_text'], 0, 5000 ) : null,
        'citations_found'    => isset($data['citations_found']) ? wp_json_encode($data['citations_found']) : null,
        'competitor_domains' => isset($data['competitor_domains']) ? wp_json_encode($data['competitor_domains']) : null,
        'prompt_used'        => isset($data['prompt_used']) ? mb_substr( $data['prompt_used'], 0, 2000 ) : null,
        'tokens_used'        => absint( $data['tokens_used'] ?? 0 ),
        'cost_usd'           => (float) ( $data['cost_usd'] ?? 0 ),
        'error_message'      => isset($data['error_message']) ? sanitize_text_field($data['error_message']) : null,
        'checked_at'         => current_time('mysql'),
    ];

    $wpdb->insert( $table, $row );
    return (int) $wpdb->insert_id;
}

/**
 * Get all exposure results, optionally filtered by days and/or platform.
 * Returns the LATEST result per keyword per platform.
 */
function myls_ae_get_all( $days = 30, $platform = '' ) {
    global $wpdb;
    $table = myls_ae_table_name();

    // Sub-query: latest check per keyword+platform
    $where = '';
    $params = [];
    if ( $days > 0 ) {
        $where .= ' AND checked_at >= %s';
        $params[] = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
    }
    if ( $platform ) {
        $where .= ' AND platform = %s';
        $params[] = $platform;
    }

    $sql = "SELECT e.*
            FROM {$table} e
            INNER JOIN (
                SELECT keyword, platform, MAX(id) as max_id
                FROM {$table}
                WHERE 1=1 {$where}
                GROUP BY keyword, platform
            ) latest ON e.id = latest.max_id
            ORDER BY e.keyword, e.platform";

    if ( ! empty($params) ) {
        $sql = $wpdb->prepare($sql, ...$params);
    }

    $rows = $wpdb->get_results($sql, ARRAY_A);

    // Decode JSON fields
    foreach ( $rows as &$r ) {
        $r['citations_found']    = $r['citations_found']    ? json_decode($r['citations_found'], true) : [];
        $r['competitor_domains'] = $r['competitor_domains']  ? json_decode($r['competitor_domains'], true) : [];
        $r['cited']              = (int) $r['cited'];
        $r['citation_count']     = (int) $r['citation_count'];
        $r['source_position']    = $r['source_position'] !== null ? (int) $r['source_position'] : null;
        $r['tokens_used']        = (int) $r['tokens_used'];
        $r['cost_usd']           = (float) $r['cost_usd'];
    }
    return $rows;
}

/**
 * Aggregate stats summary for KPIs.
 */
function myls_ae_get_stats( $days = 30 ) {
    global $wpdb;
    $table = myls_ae_table_name();
    $since = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

    // Get latest result per keyword+platform in the period
    $sql = $wpdb->prepare(
        "SELECT
            COUNT(DISTINCT e.keyword) as total_keywords,
            COUNT(*) as total_checks,
            SUM(e.cited) as total_citations,
            COUNT(DISTINCT e.platform) as platforms_active,
            AVG(CASE WHEN e.source_position IS NOT NULL THEN e.source_position ELSE NULL END) as avg_position,
            SUM(e.tokens_used) as total_tokens,
            SUM(e.cost_usd) as total_cost,
            SUM(CASE WHEN e.platform = 'chatgpt' AND e.cited = 1 THEN 1 ELSE 0 END) as chatgpt_citations,
            SUM(CASE WHEN e.platform = 'claude' AND e.cited = 1 THEN 1 ELSE 0 END) as claude_citations,
            SUM(CASE WHEN e.platform = 'chatgpt' THEN 1 ELSE 0 END) as chatgpt_checks,
            SUM(CASE WHEN e.platform = 'claude' THEN 1 ELSE 0 END) as claude_checks,
            MAX(e.checked_at) as last_checked,
            MIN(e.checked_at) as first_checked
        FROM {$table} e
        INNER JOIN (
            SELECT keyword, platform, MAX(id) as max_id
            FROM {$table}
            WHERE checked_at >= %s
            GROUP BY keyword, platform
        ) latest ON e.id = latest.max_id",
        $since
    );

    $stats = $wpdb->get_row($sql, ARRAY_A);

    // Compute exposure score (0-100)
    $total_checks = (int) ($stats['total_checks'] ?? 0);
    $total_cited  = (int) ($stats['total_citations'] ?? 0);
    $stats['exposure_score'] = $total_checks > 0
        ? round(($total_cited / $total_checks) * 100, 1)
        : 0;

    return $stats;
}

/**
 * Lightweight summary for the aggregated dashboard.
 */
function myls_ae_get_summary( $days = 30 ) {
    $stats = myls_ae_get_stats($days);
    return [
        'total_citations'  => (int) ($stats['total_citations'] ?? 0),
        'exposure_score'   => (float) ($stats['exposure_score'] ?? 0),
        'platforms_active' => (int) ($stats['platforms_active'] ?? 0),
        'total_keywords'   => (int) ($stats['total_keywords'] ?? 0),
        'total_cost'       => (float) ($stats['total_cost'] ?? 0),
        'last_checked'     => $stats['last_checked'] ?? null,
    ];
}

/**
 * Create or update a daily history snapshot for a keyword.
 */
function myls_ae_snapshot( $keyword, $domain ) {
    global $wpdb;
    $table  = myls_ae_table_name();
    $htable = myls_ae_history_table();
    $today  = current_time('Y-m-d');

    // Get latest ChatGPT result for this keyword
    $chatgpt = $wpdb->get_row($wpdb->prepare(
        "SELECT cited, citation_count, source_position FROM {$table}
         WHERE keyword = %s AND domain = %s AND platform = 'chatgpt'
         ORDER BY checked_at DESC LIMIT 1",
        $keyword, $domain
    ), ARRAY_A);

    // Get latest Claude result for this keyword
    $claude = $wpdb->get_row($wpdb->prepare(
        "SELECT cited, citation_count, source_position FROM {$table}
         WHERE keyword = %s AND domain = %s AND platform = 'claude'
         ORDER BY checked_at DESC LIMIT 1",
        $keyword, $domain
    ), ARRAY_A);

    // Count unique competitor domains from latest checks
    $comp_count = 0;
    $all_comps = [];
    foreach ( ['chatgpt', 'claude'] as $plat ) {
        $comp_json = $wpdb->get_var($wpdb->prepare(
            "SELECT competitor_domains FROM {$table}
             WHERE keyword = %s AND domain = %s AND platform = %s
             ORDER BY checked_at DESC LIMIT 1",
            $keyword, $domain, $plat
        ));
        if ( $comp_json ) {
            $comps = json_decode($comp_json, true);
            if ( is_array($comps) ) {
                $all_comps = array_merge($all_comps, $comps);
            }
        }
    }
    $comp_count = count(array_unique($all_comps));

    // Compute exposure score for this keyword
    $checks = 0;
    $cited  = 0;
    if ( $chatgpt ) { $checks++; $cited += (int) $chatgpt['cited']; }
    if ( $claude )  { $checks++; $cited += (int) $claude['cited']; }
    $score = $checks > 0 ? round(($cited / $checks) * 100, 1) : null;

    $data = [
        'keyword'           => $keyword,
        'snapshot_date'     => $today,
        'domain'            => $domain,
        'chatgpt_cited'     => $chatgpt ? (int) $chatgpt['cited'] : null,
        'chatgpt_count'     => $chatgpt ? (int) $chatgpt['citation_count'] : 0,
        'chatgpt_position'  => $chatgpt && $chatgpt['source_position'] !== null ? (int) $chatgpt['source_position'] : null,
        'claude_cited'      => $claude ? (int) $claude['cited'] : null,
        'claude_count'      => $claude ? (int) $claude['citation_count'] : 0,
        'claude_position'   => $claude && $claude['source_position'] !== null ? (int) $claude['source_position'] : null,
        'competitor_count'  => $comp_count,
        'exposure_score'    => $score,
    ];

    // Upsert: update if same day, insert if new day
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$htable} WHERE keyword = %s AND snapshot_date = %s AND domain = %s",
        $keyword, $today, $domain
    ));

    if ( $existing ) {
        $wpdb->update($htable, $data, ['id' => (int) $existing]);
    } else {
        $data['created_at'] = current_time('mysql');
        $wpdb->insert($htable, $data);
    }
}

/**
 * Get history snapshots for a keyword, ordered by date desc.
 */
function myls_ae_get_history( $keyword, $domain = '', $limit = 90 ) {
    global $wpdb;
    $htable = myls_ae_history_table();

    if ( $domain ) {
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$htable} WHERE keyword = %s AND domain = %s ORDER BY snapshot_date DESC LIMIT %d",
            $keyword, $domain, (int) $limit
        ), ARRAY_A);
    }

    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$htable} WHERE keyword = %s ORDER BY snapshot_date DESC LIMIT %d",
        $keyword, (int) $limit
    ), ARRAY_A);
}

/**
 * Get all auto-detected competitor domains from recent checks.
 */
function myls_ae_get_auto_competitors( $days = 30 ) {
    global $wpdb;
    $table = myls_ae_table_name();
    $since = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT competitor_domains FROM {$table}
         WHERE checked_at >= %s AND competitor_domains IS NOT NULL AND competitor_domains != ''",
        $since
    ));

    $all = [];
    foreach ( $rows as $json ) {
        $domains = json_decode($json, true);
        if ( is_array($domains) ) {
            foreach ( $domains as $d ) {
                $d = strtolower(trim($d));
                if ( $d ) {
                    $all[$d] = ( $all[$d] ?? 0 ) + 1;
                }
            }
        }
    }

    // Sort by frequency descending
    arsort($all);
    return $all;
}

/**
 * Get manual competitor domains from options.
 */
function myls_ae_get_manual_competitors() {
    $json = get_option('myls_ai_exposure_competitors', '[]');
    $domains = json_decode($json, true);
    return is_array($domains) ? $domains : [];
}

/**
 * Save manual competitor domains.
 */
function myls_ae_save_manual_competitors( $domains ) {
    $clean = [];
    if ( is_array($domains) ) {
        foreach ( $domains as $d ) {
            $d = strtolower( trim( sanitize_text_field($d) ) );
            if ( $d ) $clean[] = $d;
        }
    }
    update_option('myls_ai_exposure_competitors', wp_json_encode(array_unique($clean)));
    return $clean;
}

/**
 * Get custom keywords (not from Search Demand).
 */
function myls_ae_get_custom_keywords() {
    $json = get_option('myls_ai_exposure_custom_keywords', '[]');
    $kws = json_decode($json, true);
    return is_array($kws) ? $kws : [];
}

/**
 * Add a custom keyword.
 */
function myls_ae_add_custom_keyword( $keyword ) {
    $kws = myls_ae_get_custom_keywords();
    $keyword = sanitize_text_field( trim($keyword) );
    if ( $keyword && ! in_array($keyword, $kws, true) ) {
        $kws[] = $keyword;
        update_option('myls_ai_exposure_custom_keywords', wp_json_encode($kws));
    }
    return $kws;
}

/**
 * Remove a custom keyword.
 */
function myls_ae_remove_custom_keyword( $keyword ) {
    $kws = myls_ae_get_custom_keywords();
    $kws = array_values( array_filter($kws, function($k) use ($keyword) {
        return $k !== $keyword;
    }) );
    update_option('myls_ai_exposure_custom_keywords', wp_json_encode($kws));
    return $kws;
}

/**
 * Purge records older than N days.
 */
function myls_ae_purge( $days = 90 ) {
    global $wpdb;
    $table  = myls_ae_table_name();
    $htable = myls_ae_history_table();
    $before = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
    $before_date = gmdate('Y-m-d', strtotime("-{$days} days"));

    $deleted_main    = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE checked_at < %s", $before));
    $deleted_history = $wpdb->query($wpdb->prepare("DELETE FROM {$htable} WHERE snapshot_date < %s", $before_date));

    return [
        'main'    => (int) $deleted_main,
        'history' => (int) $deleted_history,
    ];
}

/**
 * Get total row count.
 */
function myls_ae_get_total_rows() {
    global $wpdb;
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . myls_ae_table_name());
}
