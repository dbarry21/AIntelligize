<?php
/**
 * Generic Prompt Template History — AJAX Handlers
 *
 * Stores saved prompt versions per prompt key in a single WP option:
 *   myls_prompt_history  →  [ 'meta-title' => [ 'slug' => [...], ... ], ... ]
 *
 * Endpoints:
 *   myls_prompt_history_list   — list saved versions for a prompt key
 *   myls_prompt_history_save   — save/overwrite a named version
 *   myls_prompt_history_delete — delete a named version
 *
 * @since 6.2.0
 */

if ( ! defined('ABSPATH') ) exit;

/* ─── Helpers ──────────────────────────────────────────────────────── */

/**
 * Get the full history array from the option.
 */
function myls_prompt_history_get_all() : array {
    $all = get_option( 'myls_prompt_history', [] );
    return is_array( $all ) ? $all : [];
}

/**
 * Format entries for a single prompt key for JSON output.
 */
function myls_prompt_history_format( array $entries ) : array {
    $out = [];
    foreach ( $entries as $slug => $entry ) {
        $out[] = [
            'slug'    => $slug,
            'name'    => $entry['name'] ?? $slug,
            'content' => $entry['content'] ?? '',
            'updated' => $entry['updated'] ?? '',
        ];
    }
    usort( $out, function( $a, $b ) {
        return strcmp( $b['updated'], $a['updated'] );
    });
    return $out;
}

/* ─── AJAX: List ───────────────────────────────────────────────────── */

add_action( 'wp_ajax_myls_prompt_history_list', function () {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error( ['message' => 'Forbidden'], 403 );
    }

    $key = sanitize_key( $_POST['prompt_key'] ?? '' );
    if ( $key === '' ) {
        wp_send_json_error( ['message' => 'Missing prompt_key'], 400 );
    }

    $all     = myls_prompt_history_get_all();
    $entries = $all[ $key ] ?? [];

    wp_send_json_success( ['history' => myls_prompt_history_format( $entries )] );
});

/* ─── AJAX: Save ───────────────────────────────────────────────────── */

add_action( 'wp_ajax_myls_prompt_history_save', function () {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error( ['message' => 'Forbidden'], 403 );
    }
    if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_ai_ops' ) ) {
        wp_send_json_error( ['message' => 'Bad nonce'], 400 );
    }

    $key     = sanitize_key( $_POST['prompt_key'] ?? '' );
    $name    = sanitize_text_field( $_POST['entry_name'] ?? '' );
    $content = wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) );

    if ( $key === '' )     wp_send_json_error( ['message' => 'Missing prompt_key'], 400 );
    if ( $name === '' )    wp_send_json_error( ['message' => 'Name is required.'], 400 );
    if ( $content === '' ) wp_send_json_error( ['message' => 'Content is empty.'], 400 );

    $all = myls_prompt_history_get_all();
    if ( ! isset( $all[ $key ] ) || ! is_array( $all[ $key ] ) ) {
        $all[ $key ] = [];
    }

    $slug = sanitize_title( $name );
    $all[ $key ][ $slug ] = [
        'name'    => $name,
        'content' => $content,
        'updated' => current_time('mysql'),
    ];

    // Keep max 50 entries per prompt key
    if ( count( $all[ $key ] ) > 50 ) {
        $all[ $key ] = array_slice( $all[ $key ], -50, 50, true );
    }

    update_option( 'myls_prompt_history', $all );

    wp_send_json_success([
        'message' => "Saved \"{$name}\".",
        'history' => myls_prompt_history_format( $all[ $key ] ),
    ]);
});

/* ─── AJAX: Get factory default from text file ─────────────────────── */

add_action( 'wp_ajax_myls_prompt_get_default', function () {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error( ['message' => 'Forbidden'], 403 );
    }

    $key = sanitize_key( $_POST['prompt_key'] ?? '' );
    if ( $key === '' ) {
        wp_send_json_error( ['message' => 'Missing prompt_key'], 400 );
    }

    $content = myls_get_default_prompt( $key );
    if ( $content === '' ) {
        wp_send_json_error( ['message' => 'Prompt file not found: ' . $key], 404 );
    }

    wp_send_json_success( ['content' => $content] );
});

add_action( 'wp_ajax_myls_prompt_history_delete', function () {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error( ['message' => 'Forbidden'], 403 );
    }
    if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_ai_ops' ) ) {
        wp_send_json_error( ['message' => 'Bad nonce'], 400 );
    }

    $key  = sanitize_key( $_POST['prompt_key'] ?? '' );
    $slug = sanitize_title( $_POST['entry_slug'] ?? '' );

    if ( $key === '' )  wp_send_json_error( ['message' => 'Missing prompt_key'], 400 );
    if ( $slug === '' ) wp_send_json_error( ['message' => 'Invalid entry.'], 400 );

    $all  = myls_prompt_history_get_all();
    $name = $all[ $key ][ $slug ]['name'] ?? $slug;
    unset( $all[ $key ][ $slug ] );

    update_option( 'myls_prompt_history', $all );

    wp_send_json_success([
        'message' => "Deleted \"{$name}\".",
        'history' => myls_prompt_history_format( $all[ $key ] ?? [] ),
    ]);
});
