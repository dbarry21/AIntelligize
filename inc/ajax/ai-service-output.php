<?php
/**
 * AJAX: Service Output Generation & Save
 * File: inc/ajax/ai-service-output.php
 *
 * Handles:
 *   wp_ajax_myls_service_output_generate_single
 *   wp_ajax_myls_service_output_save_single
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Generate serviceOutput for a single service post using AI.
 * Saves result to _myls_service_output post meta on success.
 */
add_action( 'wp_ajax_myls_service_output_generate_single', function() {

    if ( ! check_ajax_referer( 'myls_svcout_ops', 'nonce', false ) ) {
        wp_send_json_error( ['message' => 'bad_nonce'], 403 );
    }
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( ['message' => 'cap_denied'], 403 );
    }

    $post_id = absint( $_POST['post_id'] ?? 0 );
    if ( ! $post_id ) {
        wp_send_json_error( ['message' => 'invalid_post_id'], 400 );
    }

    $post = get_post( $post_id );
    if ( ! $post || $post->post_type !== 'service' ) {
        wp_send_json_error( ['message' => 'invalid_post'], 400 );
    }

    if ( ! function_exists('myls_ai_generate_text') ) {
        wp_send_json_error( ['message' => 'ai_unavailable'], 500 );
    }

    // Build context
    $title = get_the_title( $post_id );

    // Page text: first 300 words of plain content
    $raw_content = (string) $post->post_content;
    if ( function_exists('myls_get_post_plain_text') ) {
        $page_text = myls_get_post_plain_text( $post_id, 60 );
    } else {
        $page_text = wp_trim_words(
            wp_strip_all_tags( do_shortcode( $raw_content ) ),
            60, ''
        );
    }

    // City/state context
    $city_state = '';
    if ( function_exists('get_field') ) {
        $city_state = trim( (string) get_field( 'city_state', $post_id ) );
    }
    if ( $city_state === '' ) {
        $city_state = trim( (string) get_post_meta( $post_id, '_myls_city_state', true ) );
    }
    if ( $city_state === '' ) {
        $locality = trim( (string) get_option( 'myls_org_locality', '' ) );
        $region   = trim( (string) get_option( 'myls_org_region', '' ) );
        if ( $locality !== '' && $region !== '' ) {
            $city_state = $locality . ', ' . $region;
        } elseif ( $locality !== '' ) {
            $city_state = $locality;
        }
    }

    // Load prompt template
    $default_prompt = "You are writing a Schema.org serviceOutput noun-phrase for a local service business page.\n\nService: {{TITLE}}\nLocation: {{CITY_STATE}}\nPage content summary: {{PAGE_TEXT}}\n\nWrite ONE short noun-phrase (8-15 words) describing the specific tangible deliverable the customer receives after this service is complete.\n\nRules:\n- Must be a noun phrase, not a sentence or marketing slogan\n- Describe the physical result, not the process\n- Be specific to this service type\n- Do not start with verbs like \"Get\" or \"Enjoy\"\n- Do not include the business name\n\nGood examples:\n- Clean, sealed paver surface with restored polymeric sand joints\n- Mold-free, pressure-washed concrete driveway surface\n- Streak-free, cleaned pool screen enclosure panels\n\nOutput ONLY the noun-phrase. No quotes, no preamble, no punctuation at end.";

    $prompt_template = (string) get_option( 'myls_service_output_prompt', '' );
    if ( trim( $prompt_template ) === '' ) {
        $prompt_template = $default_prompt;
    }

    // Replace tokens
    $prompt = str_replace(
        [ '{{TITLE}}', '{{CITY_STATE}}', '{{PAGE_TEXT}}' ],
        [ $title, $city_state ?: 'Tampa Bay, FL', $page_text ],
        $prompt_template
    );

    // Generate
    $result = myls_ai_generate_text( $prompt, [
        'max_tokens'  => 80,
        'temperature' => 0.4,
        'post_id'     => $post_id,
    ] );

    if ( empty( $result ) || ! is_string( $result ) ) {
        wp_send_json_error( ['message' => 'empty_response'], 500 );
    }

    // Clean output
    $value = trim( $result );
    $value = trim( $value, '"\'-–—' );
    $value = wp_strip_all_tags( $value );
    $value = sanitize_textarea_field( $value );

    if ( $value === '' ) {
        wp_send_json_error( ['message' => 'empty_after_clean'], 500 );
    }

    // Save to post meta
    update_post_meta( $post_id, '_myls_service_output', $value );

    wp_send_json_success( [
        'post_id' => $post_id,
        'value'   => $value,
    ] );
} );

/**
 * Save a manually entered serviceOutput value.
 */
add_action( 'wp_ajax_myls_service_output_save_single', function() {

    if ( ! check_ajax_referer( 'myls_svcout_ops', 'nonce', false ) ) {
        wp_send_json_error( ['message' => 'bad_nonce'], 403 );
    }
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( ['message' => 'cap_denied'], 403 );
    }

    $post_id = absint( $_POST['post_id'] ?? 0 );
    if ( ! $post_id ) {
        wp_send_json_error( ['message' => 'invalid_post_id'], 400 );
    }

    $value = sanitize_textarea_field(
        wp_unslash( $_POST['value'] ?? '' )
    );

    update_post_meta( $post_id, '_myls_service_output', $value );

    wp_send_json_success( [
        'post_id' => $post_id,
        'value'   => $value,
    ] );
} );
