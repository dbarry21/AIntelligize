<?php
/**
 * AIntelligize — AI HowTo Steps Generator
 * File: inc/ajax/ai-howto.php
 *
 * AJAX endpoint: myls_generate_howto_steps
 * Extracts HowTo process steps from post content via Claude API (Haiku).
 * Called from the HowTo repeater in the MYLS FAQs metabox (myls-faq-citystate.php).
 */

if ( ! defined('ABSPATH') ) exit;

add_action( 'wp_ajax_myls_generate_howto_steps', 'myls_ajax_generate_howto_steps' );

function myls_ajax_generate_howto_steps() {

	// Security
	check_ajax_referer( 'myls_howto_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( 'Insufficient permissions.' );
	}

	$post_id = absint( $_POST['post_id'] ?? 0 );
	if ( ! $post_id ) {
		wp_send_json_error( 'Invalid post ID.' );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		wp_send_json_error( 'Post not found.' );
	}

	// Build clean plain-text from post content
	$content = wp_strip_all_tags(
		do_shortcode( apply_filters( 'the_content', $post->post_content ) )
	);
	$content = trim( preg_replace( '/\s+/', ' ', $content ) );

	if ( mb_strlen( $content ) < 50 ) {
		wp_send_json_error( 'Not enough page content to analyze. Add content to this page first.' );
	}

	$title           = get_the_title( $post_id );
	$content_trimmed = mb_substr( $content, 0, 4000 );

	// Check API is available
	if ( ! function_exists( 'myls_anthropic_chat' ) || ! function_exists( 'myls_ai_has_key' ) || ! myls_ai_has_key() ) {
		wp_send_json_error( 'Claude API key not configured. Set it in AIntelligize → API Integration.' );
	}

	$system_prompt = 'You are a structured data specialist. Extract process steps from service page content and return ONLY valid JSON — no markdown, no backticks, no preamble.';

	$user_prompt = 'Analyze this service page content for "' . esc_html( $title ) . '" and extract the numbered process steps that describe how the service is performed.

Return a JSON object with this exact structure:
{"title":"How [Service Name] Works","steps":[{"name":"Step Name","text":"1-3 sentence description."},{"name":"Step Name","text":"1-3 sentence description."}]}

Rules:
- Extract 3-6 steps maximum
- "name" must be short (3-6 words) — the step heading only
- "text" must be 1-3 sentences — specific and factual, using details from the content
- If no explicit numbered process exists, derive logical steps from the service description
- Return ONLY the JSON object — nothing else, no backticks, no markdown

Page content:
' . $content_trimmed;

	if ( function_exists( 'myls_ai_set_usage_context' ) ) {
		myls_ai_set_usage_context( 'howto-generator', $post_id );
	}

	$raw = myls_anthropic_chat( $user_prompt, [
		'model'       => 'claude-haiku-4-5-20251001',
		'max_tokens'  => 800,
		'temperature' => 0.3,
		'system'      => $system_prompt,
	] );

	if ( empty( $raw ) ) {
		$err = $GLOBALS['myls_ai_last_error'] ?? 'Empty response from AI.';
		wp_send_json_error( $err );
	}

	// Strip any accidental markdown fences
	$clean  = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw ) );
	$clean  = preg_replace( '/\s*```$/', '', $clean );
	$parsed = json_decode( $clean, true );

	if ( json_last_error() !== JSON_ERROR_NONE || empty( $parsed['steps'] ) ) {
		wp_send_json_error( 'Could not parse AI response. Raw: ' . mb_substr( $raw, 0, 300 ) );
	}

	// Sanitize
	$safe_steps = [];
	foreach ( $parsed['steps'] as $step ) {
		$n = sanitize_text_field( $step['name'] ?? '' );
		$t = sanitize_textarea_field( $step['text'] ?? '' );
		if ( $n !== '' && $t !== '' ) {
			$safe_steps[] = [ 'name' => $n, 'text' => $t ];
		}
	}

	if ( empty( $safe_steps ) ) {
		wp_send_json_error( 'No valid steps found in AI response.' );
	}

	wp_send_json_success( [
		'title' => sanitize_text_field( $parsed['title'] ?? 'How ' . $title . ' Works' ),
		'steps' => $safe_steps,
	] );
}
