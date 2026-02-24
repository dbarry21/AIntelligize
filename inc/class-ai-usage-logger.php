<?php
/**
 * AIntelligize — AI Usage Logger
 * Path: inc/class-ai-usage-logger.php
 *
 * Logs every AI API call to a custom DB table for the AIntelligize Stats dashboard.
 * Captures: handler context, model, token estimates, cost, post_id, timestamps.
 *
 * @since 6.3.1.8
 */
if ( ! defined('ABSPATH') ) exit;

class MYLS_AI_Usage_Logger {

    const TABLE   = 'myls_ai_usage_log';
    const VERSION = '1.0';

    /* ─── Install / Upgrade Table ─────────────────────────── */

    public static function init() {
        add_action('plugins_loaded', [ __CLASS__, 'maybe_install' ], 6);
    }

    public static function table_name() : string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function maybe_install() {
        $installed = get_option('myls_ai_usage_log_version', '');
        if ( $installed === self::VERSION ) return;

        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            handler VARCHAR(50) NOT NULL DEFAULT 'unknown',
            model VARCHAR(80) NOT NULL DEFAULT '',
            provider VARCHAR(20) NOT NULL DEFAULT '',
            post_id BIGINT UNSIGNED DEFAULT 0,
            prompt_chars INT UNSIGNED DEFAULT 0,
            output_chars INT UNSIGNED DEFAULT 0,
            input_tokens INT UNSIGNED DEFAULT 0,
            output_tokens INT UNSIGNED DEFAULT 0,
            est_cost_usd DECIMAL(10,6) DEFAULT 0.000000,
            max_tokens_requested INT UNSIGNED DEFAULT 0,
            temperature DECIMAL(3,2) DEFAULT 0.70,
            duration_ms INT UNSIGNED DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'ok',
            error_message TEXT NULL,
            batch_id VARCHAR(32) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_handler (handler),
            KEY idx_model (model),
            KEY idx_created (created_at),
            KEY idx_post (post_id),
            KEY idx_batch (batch_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option('myls_ai_usage_log_version', self::VERSION);
    }

    /* ─── Log an AI Call ──────────────────────────────────── */

    public static function log( array $data ) : int {
        global $wpdb;
        $table = self::table_name();

        $row = [
            'handler'             => sanitize_key( $data['handler'] ?? 'unknown' ),
            'model'               => sanitize_text_field( $data['model'] ?? '' ),
            'provider'            => sanitize_key( $data['provider'] ?? '' ),
            'post_id'             => absint( $data['post_id'] ?? 0 ),
            'prompt_chars'        => absint( $data['prompt_chars'] ?? 0 ),
            'output_chars'        => absint( $data['output_chars'] ?? 0 ),
            'input_tokens'        => absint( $data['input_tokens'] ?? 0 ),
            'output_tokens'       => absint( $data['output_tokens'] ?? 0 ),
            'est_cost_usd'        => floatval( $data['est_cost_usd'] ?? 0 ),
            'max_tokens_requested'=> absint( $data['max_tokens_requested'] ?? 0 ),
            'temperature'         => floatval( $data['temperature'] ?? 0.7 ),
            'duration_ms'         => absint( $data['duration_ms'] ?? 0 ),
            'status'              => sanitize_key( $data['status'] ?? 'ok' ),
            'error_message'       => isset($data['error_message']) ? sanitize_text_field($data['error_message']) : null,
            'batch_id'            => isset($data['batch_id']) ? sanitize_text_field($data['batch_id']) : null,
            'created_at'          => current_time('mysql'),
        ];

        // Estimate tokens if not provided
        if ( $row['input_tokens'] === 0 && $row['prompt_chars'] > 0 ) {
            $row['input_tokens'] = (int) ceil( $row['prompt_chars'] / 4 );
        }
        if ( $row['output_tokens'] === 0 && $row['output_chars'] > 0 ) {
            $row['output_tokens'] = (int) ceil( $row['output_chars'] / 4 );
        }

        // Estimate cost if not provided
        if ( $row['est_cost_usd'] == 0 && ( $row['input_tokens'] > 0 || $row['output_tokens'] > 0 ) ) {
            $cost = self::estimate_cost( $row['model'], $row['input_tokens'], $row['output_tokens'] );
            $row['est_cost_usd'] = $cost;
        }

        $wpdb->insert( $table, $row );
        return (int) $wpdb->insert_id;
    }

    /* ─── Cost Estimation ─────────────────────────────────── */

    public static function estimate_cost( string $model, int $input_tokens, int $output_tokens ) : float {
        // Pricing per 1M tokens [ input, output ]
        $pricing = [
            'gpt-4o'                       => [  2.50,  10.00 ],
            'gpt-4o-mini'                  => [  0.15,   0.60 ],
            'gpt-4-turbo'                  => [ 10.00,  30.00 ],
            'gpt-4'                        => [ 30.00,  60.00 ],
            'gpt-3.5-turbo'               => [  0.50,   1.50 ],
            'o1-mini'                      => [  3.00,  12.00 ],
            'claude-sonnet-4-20250514'     => [  3.00,  15.00 ],
            'claude-haiku-4-5-20251001'    => [  0.80,   4.00 ],
            'claude-opus-4-20250918'       => [ 15.00,  75.00 ],
        ];

        $key = strtolower( trim($model) );
        $rates = $pricing[$key] ?? $pricing['gpt-4o'];

        return round(
            ( $input_tokens / 1_000_000 ) * $rates[0] +
            ( $output_tokens / 1_000_000 ) * $rates[1],
            6
        );
    }

    /* ─── Query Helpers for Dashboard ─────────────────────── */

    public static function get_overview( int $days = 30 ) : array {
        global $wpdb;
        $table = self::table_name();
        $since = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS total_calls,
                COUNT(DISTINCT post_id) AS unique_posts,
                SUM(input_tokens) AS total_input_tokens,
                SUM(output_tokens) AS total_output_tokens,
                SUM(est_cost_usd) AS total_cost,
                AVG(duration_ms) AS avg_duration_ms,
                SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) AS success_count,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS error_count,
                COUNT(DISTINCT model) AS models_used,
                COUNT(DISTINCT handler) AS handlers_used,
                COUNT(DISTINCT batch_id) AS batch_count
            FROM {$table}
            WHERE created_at >= %s",
            $since
        ), ARRAY_A );

        return $row ?: [];
    }

    public static function get_timeline( int $days = 30 ) : array {
        global $wpdb;
        $table = self::table_name();
        $since = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                DATE(created_at) AS date,
                COUNT(*) AS calls,
                SUM(est_cost_usd) AS cost,
                SUM(input_tokens + output_tokens) AS tokens,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS errors
            FROM {$table}
            WHERE created_at >= %s
            GROUP BY DATE(created_at)
            ORDER BY date ASC",
            $since
        ), ARRAY_A ) ?: [];
    }

    public static function get_by_handler( int $days = 30 ) : array {
        global $wpdb;
        $table = self::table_name();
        $since = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                handler,
                COUNT(*) AS calls,
                SUM(est_cost_usd) AS cost,
                SUM(input_tokens) AS input_tokens,
                SUM(output_tokens) AS output_tokens,
                AVG(duration_ms) AS avg_duration,
                SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) AS successes,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS errors
            FROM {$table}
            WHERE created_at >= %s
            GROUP BY handler
            ORDER BY cost DESC",
            $since
        ), ARRAY_A ) ?: [];
    }

    public static function get_by_model( int $days = 30 ) : array {
        global $wpdb;
        $table = self::table_name();
        $since = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                model,
                provider,
                COUNT(*) AS calls,
                SUM(est_cost_usd) AS cost,
                SUM(input_tokens + output_tokens) AS total_tokens
            FROM {$table}
            WHERE created_at >= %s
            GROUP BY model, provider
            ORDER BY calls DESC",
            $since
        ), ARRAY_A ) ?: [];
    }

    public static function get_hourly( int $days = 30 ) : array {
        global $wpdb;
        $table = self::table_name();
        $since = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                HOUR(created_at) AS hour,
                COUNT(*) AS calls,
                SUM(est_cost_usd) AS cost
            FROM {$table}
            WHERE created_at >= %s
            GROUP BY HOUR(created_at)
            ORDER BY hour ASC",
            $since
        ), ARRAY_A ) ?: [];
    }

    public static function get_recent_calls( int $limit = 50 ) : array {
        global $wpdb;
        $table = self::table_name();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, handler, model, provider, post_id, input_tokens, output_tokens,
                    est_cost_usd, duration_ms, status, error_message, created_at
            FROM {$table}
            ORDER BY created_at DESC
            LIMIT %d",
            $limit
        ), ARRAY_A ) ?: [];
    }

    public static function get_total_rows() : int {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /* ─── Content Coverage (scans WP data, not this table) ── */

    public static function get_content_coverage() : array {
        global $wpdb;

        // Count all published service_page / page posts
        $post_types = "'page','service_page'";
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type IN ({$post_types}) AND post_status = 'publish'"
        );

        // With Yoast title
        $with_title = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_yoast_wpseo_title'
             WHERE p.post_type IN ({$post_types}) AND p.post_status = 'publish'
             AND pm.meta_value != ''"
        );

        // With Yoast description
        $with_desc = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_yoast_wpseo_metadesc'
             WHERE p.post_type IN ({$post_types}) AND p.post_status = 'publish'
             AND pm.meta_value != ''"
        );

        // With excerpt
        $with_excerpt = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type IN ({$post_types}) AND post_status = 'publish'
             AND post_excerpt != ''"
        );

        // With HTML excerpt (stored in meta)
        $with_html_excerpt = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_myls_html_excerpt'
             WHERE p.post_type IN ({$post_types}) AND p.post_status = 'publish'
             AND pm.meta_value != ''"
        );

        // Posts by type
        $by_type = $wpdb->get_results(
            "SELECT post_type, COUNT(*) AS count
             FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type NOT IN ('revision','nav_menu_item','customize_changeset','oembed_cache','wp_global_styles','wp_navigation','wp_template','wp_template_part','wp_font_family','wp_font_face')
             GROUP BY post_type
             ORDER BY count DESC",
            ARRAY_A
        );

        return [
            'total'              => $total,
            'with_title'         => $with_title,
            'with_desc'          => $with_desc,
            'with_excerpt'       => $with_excerpt,
            'with_html_excerpt'  => $with_html_excerpt,
            'by_type'            => $by_type ?: [],
        ];
    }

    /* ─── AI Config Summary ───────────────────────────────── */

    public static function get_ai_config() : array {
        $provider     = function_exists('myls_ai_get_provider') ? myls_ai_get_provider() : 'unknown';
        $default_model= function_exists('myls_ai_get_default_model') ? myls_ai_get_default_model() : '';
        $has_openai   = (bool) get_option('myls_openai_api_key', '');
        $has_anthropic= (bool) get_option('myls_anthropic_api_key', '');

        // Count saved vs default prompts
        $prompt_keys = [
            'myls_ai_prompt_title', 'myls_ai_prompt_desc', 'myls_ai_prompt_excerpt',
            'myls_ai_prompt_html_excerpt', 'myls_ai_about_prompt_template',
            'myls_ai_faqs_prompt_template', 'myls_ai_geo_prompt_template',
            'myls_pb_prompt_template', 'myls_ai_taglines_prompt_template',
            'myls_ai_llms_txt_prompt_template', 'myls_ai_faqs_prompt_template_v2',
        ];
        $custom_prompts = 0;
        foreach ( $prompt_keys as $key ) {
            if ( trim( (string) get_option($key, '') ) !== '' ) $custom_prompts++;
        }

        return [
            'provider'       => $provider,
            'default_model'  => $default_model,
            'has_openai'     => $has_openai,
            'has_anthropic'  => $has_anthropic,
            'custom_prompts' => $custom_prompts,
            'total_prompts'  => count($prompt_keys),
            'plugin_version' => defined('MYLS_VERSION') ? MYLS_VERSION : '?',
        ];
    }

    /* ─── Purge old data ──────────────────────────────────── */

    public static function purge_before( string $date ) : int {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s", $date
        ));
    }
}
