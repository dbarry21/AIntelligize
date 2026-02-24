<?php
/**
 * Search Demand – Database Table + CRUD
 * Path: inc/db/search-demand-table.php
 *
 * Custom table: {prefix}myls_search_demand
 * Stores focus keywords, AC suggestions, GSC metrics, AI Overview data.
 *
 * @since 6.3.2.7
 */

if ( ! defined('ABSPATH') ) exit;

define( 'MYLS_SD_DB_VERSION', '1.2' );

/**
 * Get the table name with prefix.
 */
function myls_sd_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'myls_search_demand';
}

/**
 * Get the history table name with prefix.
 */
function myls_sd_history_table() {
    global $wpdb;
    return $wpdb->prefix . 'myls_search_demand_history';
}

/**
 * Create or update both tables. Called on admin_init if version mismatch.
 */
function myls_sd_maybe_create_table() {
    $installed = get_option('myls_sd_db_version', '');
    if ( $installed === MYLS_SD_DB_VERSION ) return;

    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // ── Main table ──
    $table = myls_sd_table_name();
    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_id bigint(20) unsigned NOT NULL DEFAULT 0,
        post_title varchar(255) NOT NULL DEFAULT '',
        post_type varchar(50) NOT NULL DEFAULT '',
        keyword varchar(255) NOT NULL DEFAULT '',
        source varchar(50) NOT NULL DEFAULT '',
        ac_suggestions longtext DEFAULT NULL,
        ac_count int(11) NOT NULL DEFAULT 0,
        gsc_data longtext DEFAULT NULL,
        gsc_total int(11) NOT NULL DEFAULT 0,
        ai_overview longtext DEFAULT NULL,
        ai_count int(11) NOT NULL DEFAULT 0,
        post_rank decimal(5,1) DEFAULT NULL,
        post_rank_data longtext DEFAULT NULL,
        gsc_days int(11) NOT NULL DEFAULT 90,
        ac_refreshed_at datetime DEFAULT NULL,
        gsc_refreshed_at datetime DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY keyword_post (keyword(191), post_id),
        KEY post_id (post_id),
        KEY post_type (post_type)
    ) {$charset};";

    // ── History table (snapshots per GSC refresh) ──
    $htable = myls_sd_history_table();
    $sql2 = "CREATE TABLE {$htable} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        sd_id bigint(20) unsigned NOT NULL,
        snapshot_date date NOT NULL,
        post_rank decimal(5,1) DEFAULT NULL,
        impressions int(11) NOT NULL DEFAULT 0,
        clicks int(11) NOT NULL DEFAULT 0,
        ctr decimal(5,2) NOT NULL DEFAULT 0,
        gsc_total int(11) NOT NULL DEFAULT 0,
        ai_count int(11) NOT NULL DEFAULT 0,
        gsc_days int(11) NOT NULL DEFAULT 90,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY sd_date (sd_id, snapshot_date),
        KEY sd_id (sd_id),
        KEY snapshot_date (snapshot_date)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    dbDelta( $sql2 );
    update_option('myls_sd_db_version', MYLS_SD_DB_VERSION);
}
add_action('admin_init', 'myls_sd_maybe_create_table');

/* ═══════════════════════════════════════════════════════
 *  CRUD Helpers
 * ═══════════════════════════════════════════════════════ */

/**
 * Get all rows, optionally filtered by post_type.
 */
function myls_sd_get_all( $post_type = '' ) {
    global $wpdb;
    $table = myls_sd_table_name();
    $where = '';
    if ( $post_type && $post_type !== 'all' ) {
        $where = $wpdb->prepare(' WHERE post_type = %s', $post_type);
    }
    $rows = $wpdb->get_results(
        "SELECT * FROM {$table}{$where} ORDER BY post_type, post_title, keyword",
        ARRAY_A
    );

    // Batch-load movements and snapshot counts
    $movements = myls_sd_get_all_movements();
    $snap_counts = myls_sd_get_snapshot_counts();

    // Decode JSON fields + attach movement
    foreach ( $rows as &$r ) {
        $r['ac_suggestions'] = $r['ac_suggestions'] ? json_decode($r['ac_suggestions'], true) : [];
        $r['gsc_data']       = $r['gsc_data']       ? json_decode($r['gsc_data'], true) : [];
        $r['ai_overview']    = $r['ai_overview']     ? json_decode($r['ai_overview'], true) : [];
        $r['post_rank_data'] = $r['post_rank_data']  ? json_decode($r['post_rank_data'], true) : null;
        $r['post_rank']      = $r['post_rank'] !== null ? (float) $r['post_rank'] : null;

        $sid = (int) $r['id'];

        // Movement: prev_rank + delta (negative = improved, positive = dropped)
        if ( isset($movements[$sid]) && $movements[$sid]['prev_rank'] !== null && $r['post_rank'] !== null ) {
            $prev = (float) $movements[$sid]['prev_rank'];
            $r['prev_rank']   = $prev;
            $r['rank_delta']  = round($r['post_rank'] - $prev, 1); // negative = improved
            $r['prev_date']   = $movements[$sid]['prev_date'] ?? null;
            $r['prev_impr']   = (int)($movements[$sid]['prev_impr'] ?? 0);
            $r['prev_clicks'] = (int)($movements[$sid]['prev_clicks'] ?? 0);
        } else {
            $r['prev_rank']   = null;
            $r['rank_delta']  = null;
            $r['prev_date']   = null;
            $r['prev_impr']   = null;
            $r['prev_clicks'] = null;
        }

        $r['snapshot_count'] = $snap_counts[$sid] ?? 0;
    }
    return $rows;
}

/**
 * Get summary stats.
 */
function myls_sd_get_stats() {
    global $wpdb;
    $table = myls_sd_table_name();
    return $wpdb->get_row(
        "SELECT
            COUNT(*) as total_keywords,
            SUM(ac_count) as total_ac,
            SUM(gsc_total) as total_gsc,
            SUM(ai_count) as total_ai,
            SUM(CASE WHEN ac_refreshed_at IS NOT NULL THEN 1 ELSE 0 END) as ac_checked,
            SUM(CASE WHEN gsc_refreshed_at IS NOT NULL THEN 1 ELSE 0 END) as gsc_checked,
            SUM(CASE WHEN post_rank IS NOT NULL THEN 1 ELSE 0 END) as rank_checked,
            SUM(CASE WHEN post_rank IS NOT NULL AND post_rank <= 3 THEN 1 ELSE 0 END) as rank_top3,
            SUM(CASE WHEN post_rank IS NOT NULL AND post_rank <= 10 THEN 1 ELSE 0 END) as rank_top10,
            AVG(CASE WHEN post_rank IS NOT NULL THEN post_rank ELSE NULL END) as avg_rank,
            MIN(ac_refreshed_at) as oldest_ac,
            MAX(ac_refreshed_at) as newest_ac,
            MIN(gsc_refreshed_at) as oldest_gsc,
            MAX(gsc_refreshed_at) as newest_gsc
        FROM {$table}",
        ARRAY_A
    );
}

/**
 * Upsert a keyword row (insert or update on duplicate keyword+post_id).
 */
function myls_sd_upsert( $data ) {
    global $wpdb;
    $table = myls_sd_table_name();

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE keyword = %s AND post_id = %d",
        $data['keyword'], $data['post_id']
    ));

    if ( $existing ) {
        // Update only scan fields (don't overwrite AC/GSC data)
        $wpdb->update($table, [
            'post_title' => $data['post_title'] ?? '',
            'post_type'  => $data['post_type'] ?? '',
            'source'     => $data['source'] ?? '',
        ], ['id' => $existing]);
        return (int) $existing;
    }

    $wpdb->insert($table, [
        'post_id'    => $data['post_id'],
        'post_title' => $data['post_title'] ?? '',
        'post_type'  => $data['post_type'] ?? '',
        'keyword'    => $data['keyword'],
        'source'     => $data['source'] ?? '',
        'created_at' => current_time('mysql'),
    ]);
    return (int) $wpdb->insert_id;
}

/**
 * Save AC suggestions for a row.
 */
function myls_sd_save_ac( $id, $ac_suggestions, $ac_count ) {
    global $wpdb;
    return $wpdb->update(myls_sd_table_name(), [
        'ac_suggestions'  => wp_json_encode($ac_suggestions),
        'ac_count'        => (int) $ac_count,
        'ac_refreshed_at' => current_time('mysql'),
    ], ['id' => (int) $id]);
}

/**
 * Save GSC data for a row + auto-snapshot to history.
 */
function myls_sd_save_gsc( $id, $gsc_data, $gsc_total, $ai_overview, $ai_count, $days, $post_rank = null, $post_rank_data = null ) {
    global $wpdb;
    $result = $wpdb->update(myls_sd_table_name(), [
        'gsc_data'         => wp_json_encode($gsc_data),
        'gsc_total'        => (int) $gsc_total,
        'ai_overview'      => wp_json_encode($ai_overview),
        'ai_count'         => (int) $ai_count,
        'post_rank'        => $post_rank,
        'post_rank_data'   => $post_rank_data ? wp_json_encode($post_rank_data) : null,
        'gsc_days'         => (int) $days,
        'gsc_refreshed_at' => current_time('mysql'),
    ], ['id' => (int) $id]);

    // Auto-snapshot: aggregate impressions/clicks from gsc_data
    $total_impr   = 0;
    $total_clicks = 0;
    if ( is_array($gsc_data) ) {
        foreach ( $gsc_data as $row ) {
            $total_impr   += (int)($row['impressions'] ?? 0);
            $total_clicks += (int)($row['clicks'] ?? 0);
        }
    }
    $ctr = $total_impr > 0 ? round(($total_clicks / $total_impr) * 100, 2) : 0;

    myls_sd_snapshot( (int)$id, $post_rank, $total_impr, $total_clicks, $ctr, $gsc_total, $ai_count, $days );

    return $result;
}

/**
 * Insert or update a daily snapshot in history.
 * One row per sd_id per day (UNIQUE KEY sd_date).
 */
function myls_sd_snapshot( $sd_id, $post_rank, $impressions, $clicks, $ctr, $gsc_total, $ai_count, $gsc_days ) {
    global $wpdb;
    $htable = myls_sd_history_table();
    $today  = current_time('Y-m-d');

    // Upsert: update if same day, insert if new day
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$htable} WHERE sd_id = %d AND snapshot_date = %s",
        $sd_id, $today
    ));

    $data = [
        'sd_id'         => $sd_id,
        'snapshot_date'  => $today,
        'post_rank'     => $post_rank,
        'impressions'   => (int) $impressions,
        'clicks'        => (int) $clicks,
        'ctr'           => (float) $ctr,
        'gsc_total'     => (int) $gsc_total,
        'ai_count'      => (int) $ai_count,
        'gsc_days'      => (int) $gsc_days,
    ];

    if ( $existing ) {
        $wpdb->update($htable, $data, ['id' => (int) $existing]);
    } else {
        $data['created_at'] = current_time('mysql');
        $wpdb->insert($htable, $data);
    }
}

/**
 * Get history snapshots for a keyword (sd_id), ordered by date.
 * @param int    $sd_id  The search_demand row id.
 * @param int    $limit  Max rows (default 90).
 * @return array
 */
function myls_sd_get_history( $sd_id, $limit = 90 ) {
    global $wpdb;
    $htable = myls_sd_history_table();
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$htable} WHERE sd_id = %d ORDER BY snapshot_date DESC LIMIT %d",
        (int) $sd_id, (int) $limit
    ), ARRAY_A);
}

/**
 * Get previous rank for a keyword (most recent snapshot before today).
 * Returns null if no history.
 */
function myls_sd_get_prev_rank( $sd_id ) {
    global $wpdb;
    $htable = myls_sd_history_table();
    $today  = current_time('Y-m-d');
    return $wpdb->get_row($wpdb->prepare(
        "SELECT post_rank, impressions, clicks, snapshot_date
         FROM {$htable}
         WHERE sd_id = %d AND snapshot_date < %s
         ORDER BY snapshot_date DESC LIMIT 1",
        (int) $sd_id, $today
    ), ARRAY_A);
}

/**
 * Get movement data for all keywords (batch query for dashboard).
 * Returns array keyed by sd_id with prev_rank and rank_delta.
 */
function myls_sd_get_all_movements() {
    global $wpdb;
    $htable = myls_sd_history_table();
    $table  = myls_sd_table_name();
    $today  = current_time('Y-m-d');

    // For each sd_id, get the most recent snapshot BEFORE today
    $sql = "SELECT h1.sd_id, h1.post_rank as prev_rank, h1.impressions as prev_impr,
                   h1.clicks as prev_clicks, h1.snapshot_date as prev_date
            FROM {$htable} h1
            INNER JOIN (
                SELECT sd_id, MAX(snapshot_date) as max_date
                FROM {$htable}
                WHERE snapshot_date < %s
                GROUP BY sd_id
            ) h2 ON h1.sd_id = h2.sd_id AND h1.snapshot_date = h2.max_date";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $today), ARRAY_A);

    $map = [];
    foreach ( $rows as $r ) {
        $map[(int)$r['sd_id']] = $r;
    }
    return $map;
}

/**
 * Get snapshot count per sd_id (for showing "X snapshots" badge).
 */
function myls_sd_get_snapshot_counts() {
    global $wpdb;
    $htable = myls_sd_history_table();
    $rows = $wpdb->get_results(
        "SELECT sd_id, COUNT(*) as cnt FROM {$htable} GROUP BY sd_id",
        ARRAY_A
    );
    $map = [];
    foreach ($rows as $r) {
        $map[(int)$r['sd_id']] = (int)$r['cnt'];
    }
    return $map;
}

/**
 * Delete a single row.
 */
function myls_sd_delete_row( $id ) {
    global $wpdb;
    // Delete history first
    $wpdb->delete(myls_sd_history_table(), ['sd_id' => (int) $id]);
    return $wpdb->delete(myls_sd_table_name(), ['id' => (int) $id]);
}

/**
 * Clear all rows + history.
 */
function myls_sd_clear_all() {
    global $wpdb;
    $wpdb->query("TRUNCATE TABLE " . myls_sd_history_table());
    return $wpdb->query("TRUNCATE TABLE " . myls_sd_table_name());
}

/**
 * Remove rows whose post_id no longer exists.
 */
function myls_sd_prune_orphans() {
    global $wpdb;
    $table  = myls_sd_table_name();
    $htable = myls_sd_history_table();

    // Find orphaned sd_ids first (for history cleanup)
    $orphan_ids = $wpdb->get_col(
        "SELECT sd.id FROM {$table} sd
         LEFT JOIN {$wpdb->posts} p ON p.ID = sd.post_id
         WHERE p.ID IS NULL AND sd.post_id > 0"
    );

    // Delete their history
    if ( ! empty($orphan_ids) ) {
        $id_list = implode(',', array_map('intval', $orphan_ids));
        $wpdb->query("DELETE FROM {$htable} WHERE sd_id IN ({$id_list})");
    }

    // Delete the orphaned main rows
    return $wpdb->query(
        "DELETE sd FROM {$table} sd
         LEFT JOIN {$wpdb->posts} p ON p.ID = sd.post_id
         WHERE p.ID IS NULL AND sd.post_id > 0"
    );
}
