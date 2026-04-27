<?php
/**
 * AJAX handler: AI Beaver Builder Page Builder
 * Path: inc/ajax/ai-beaver-builder.php
 *
 * Mirror of inc/ajax/ai-elementor-builder.php for Beaver Builder. Generates
 * page content via AI (JSON output) then materializes a native BB layout
 * (rows / column-groups / columns / modules) and persists it to the
 * `_fl_builder_data` / `_fl_builder_draft` postmeta keys.
 *
 * All parsing, layout building, and persistence is delegated to
 * AIntelligize_Beaver_Builder_Parser. This file never serializes BB nodes
 * directly.
 *
 * Actions:
 *   myls_bb_create_page          — Generate + create/update BB page
 *   myls_bb_save_prompt          — Persist custom prompt template
 *   myls_bb_generate_single_image— Generate ONE DALL-E image and patch into BB layout
 *   myls_bb_get_nav_posts        — Block-theme nav post helper (parity with Elementor)
 *   myls_bb_save_description     — Save description to history
 *   myls_bb_list_descriptions    — List saved descriptions
 *   myls_bb_delete_description   — Delete a saved description
 *   myls_bb_save_setup           — Save full Page Setup state as a named template
 *   myls_bb_list_setups          — List saved Page Setup templates
 *   myls_bb_delete_setup         — Delete a saved Page Setup template
 *   myls_bb_get_templates        — List BB saved templates (post type fl-builder-template)
 *   myls_bb_get_parent_pages     — List parents for the parent-page selector
 *   myls_bb_debug_post           — Inspect _fl_builder_data on a given post
 *   myls_bb_test_dalle           — DALL-E connection smoke test (parity with Elementor)
 *
 * @since 7.10.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'AIntelligize_Beaver_Builder_Parser' ) ) {
	require_once dirname( __DIR__ ) . '/class-aintelligize-beaver-builder-parser.php';
}

/* =========================================================================
 * Helpers
 * ========================================================================= */

if ( ! function_exists( 'myls_bb_replace_tokens' ) ) {
	/**
	 * Replace {{TOKEN}} and {{token}} placeholders in $text using $vars.
	 * Mirrors myls_elb_replace_tokens behavior so the same prompt files work.
	 */
	function myls_bb_replace_tokens( string $text, array $vars ): string {
		if ( $text === '' ) return $text;
		$replacements = array();
		foreach ( $vars as $k => $v ) {
			if ( ! is_scalar( $v ) ) continue;
			$replacements[ '{{' . strtoupper( $k ) . '}}' ] = (string) $v;
			$replacements[ '{{' . $k . '}}' ]               = (string) $v;
		}
		return strtr( $text, $replacements );
	}
}

if ( ! function_exists( 'myls_bb_get_contact_url' ) ) {
	function myls_bb_get_contact_url(): string {
		// Reuse the canonical contact-url resolver established by the page
		// builder layer. Fall back to home_url('/contact-us/') if missing.
		if ( function_exists( 'myls_get_contact_url' ) ) {
			return myls_get_contact_url();
		}
		$page_id = (int) get_option( 'myls_contact_page_id', 0 );
		$url     = $page_id > 0 ? (string) get_permalink( $page_id ) : '';
		if ( $url === '' ) $url = home_url( '/contact-us/' );
		return esc_url_raw( $url );
	}
}

/* =========================================================================
 * AJAX: Create / update BB page
 * ========================================================================= */
add_action( 'wp_ajax_myls_bb_create_page', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
	}
	if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_bb_create' ) ) {
		wp_send_json_error( array( 'message' => 'Bad nonce' ), 400 );
	}

	$start_time = microtime( true );
	$log_lines  = array();
	$parser     = new AIntelligize_Beaver_Builder_Parser();

	// Hard-stop on unsupported BB versions — UI also short-circuits, but
	// defend the AJAX surface as well.
	if ( $parser->is_bb_plugin_active() && ! $parser->is_supported_version() ) {
		wp_send_json_error( array( 'message' => 'Beaver Builder version is below the minimum supported (' . AIntelligize_Beaver_Builder_Parser::MIN_BB_VERSION . ').' ), 400 );
	}

	/* ── Inputs ──────────────────────────────────────────────────────────── */
	$page_title      = sanitize_text_field( $_POST['page_title'] ?? '' );
	$post_type       = sanitize_key( $_POST['post_type'] ?? 'page' );
	$page_status     = in_array( $_POST['page_status'] ?? '', array( 'draft', 'publish' ), true ) ? $_POST['page_status'] : 'draft';
	$description     = sanitize_textarea_field( wp_unslash( $_POST['page_description'] ?? '' ) );
	$prompt_template = wp_kses_post( $_POST['prompt_template'] ?? '' );
	$add_to_menu     = ! empty( $_POST['add_to_menu'] );
	$seo_keyword     = sanitize_text_field( trim( $_POST['seo_keyword'] ?? '' ) );
	$page_slug       = sanitize_title( $_POST['page_slug'] ?? '' );
	$parent_page_id  = max( 0, (int) ( $_POST['parent_page_id'] ?? 0 ) );

	$integrate_images  = ! empty( $_POST['integrate_images'] );
	$image_style       = sanitize_text_field( $_POST['image_style'] ?? 'photo' );
	$gen_hero_img      = ! empty( $_POST['gen_hero'] );
	$gen_feature_cards = ! empty( $_POST['gen_feature_cards'] );
	$set_featured      = ! empty( $_POST['set_featured'] );

	if ( empty( $page_title ) ) {
		wp_send_json_error( array( 'message' => 'Page title is required.' ), 400 );
	}
	if ( ! post_type_exists( $post_type ) ) {
		wp_send_json_error( array( 'message' => 'Invalid post type: ' . $post_type ), 400 );
	}

	/* ── sections_order ──────────────────────────────────────────────────── */
	$sections_order_raw = wp_unslash( $_POST['sections_order'] ?? '' );
	$sections_order     = array();
	if ( ! empty( $sections_order_raw ) ) {
		$decoded = json_decode( $sections_order_raw, true );
		if ( is_array( $decoded ) ) $sections_order = $decoded;
	}
	if ( empty( $sections_order ) ) {
		// Sensible defaults when JS didn't send anything.
		$sections_order = array(
			array( 'id' => 'hero',      'type' => 'section', 'enabled' => true ),
			array( 'id' => 'tldr',      'type' => 'section', 'enabled' => true ),
			array( 'id' => 'intro',     'type' => 'section', 'enabled' => true ),
			array( 'id' => 'trust_bar', 'type' => 'section', 'enabled' => true ),
			array( 'id' => 'features',  'type' => 'section', 'enabled' => true, 'cols' => 3, 'rows' => 1 ),
			array( 'id' => 'rich_content', 'type' => 'section', 'enabled' => true ),
			array( 'id' => 'process',   'type' => 'section', 'enabled' => true, 'cols' => 2, 'rows' => 2 ),
			array( 'id' => 'pricing',   'type' => 'section', 'enabled' => true ),
			array( 'id' => 'cta',       'type' => 'section', 'enabled' => true ),
		);
	}

	// Derive feature/process grids.
	$feature_cols = 3;
	$feature_rows = 1;
	$process_cols = 2;
	$process_rows = 2;
	foreach ( $sections_order as $so ) {
		if ( ( $so['type'] ?? '' ) !== 'section' ) continue;
		if ( ( $so['id'] ?? '' ) === 'features' ) {
			$feature_cols = max( 1, min( 6, (int) ( $so['cols'] ?? 3 ) ) );
			$feature_rows = max( 1, min( 6, (int) ( $so['rows'] ?? 1 ) ) );
		} elseif ( ( $so['id'] ?? '' ) === 'process' ) {
			$process_cols = max( 1, min( 6, (int) ( $so['cols'] ?? 2 ) ) );
			$process_rows = max( 1, min( 6, (int) ( $so['rows'] ?? 2 ) ) );
		}
	}
	$card_count_total   = $feature_cols * $feature_rows;
	$process_step_total = $process_cols * $process_rows;

	/* ── Resolve contact URL + token vars ───────────────────────────────── */
	$contact_url_raw = trim( esc_url_raw( sanitize_text_field( $_POST['contact_url'] ?? '' ) ) );
	$contact_url     = $contact_url_raw !== '' ? $contact_url_raw : myls_bb_get_contact_url();

	$sb = get_option( 'myls_sb_settings', array() );
	$vars = array(
		'business_name' => $sb['business_name'] ?? get_bloginfo( 'name' ),
		'city'          => $sb['city']          ?? '',
		'phone'         => $sb['phone']         ?? '',
		'email'         => $sb['email']         ?? get_bloginfo( 'admin_email' ),
		'site_name'     => get_bloginfo( 'name' ),
		'site_url'      => home_url(),
		'contact_url'   => $contact_url,
		'page_title'    => $page_title,
		'yoast_title'   => $seo_keyword ?: $page_title,
		'description'   => $description,
		'post_type'     => $post_type,
	);

	// Resolve description tokens early so AI sees fully expanded content.
	$description = myls_bb_replace_tokens( $description, $vars );
	$vars['description'] = $description ?: 'A page about ' . $page_title;

	/* ── Site analysis ───────────────────────────────────────────────────── */
	$site_context = function_exists( 'myls_bb_analyze_site' )
		? myls_bb_analyze_site( $post_type )
		: array( 'kit' => array(), 'sample_pages' => array(), 'patterns' => array(), 'prompt_block' => '', 'log' => array() );

	/* ── Prompt assembly ─────────────────────────────────────────────────── */
	if ( empty( trim( $prompt_template ) ) ) {
		$prompt_template = function_exists( 'myls_get_default_prompt' )
			? myls_get_default_prompt( 'beaver-builder' )
			: '';
	}
	if ( empty( trim( $prompt_template ) ) ) {
		// Last-ditch fallback if the prompt file was missing.
		$prompt_template = "Generate a JSON page spec for {{PAGE_TITLE}}. Output only JSON.";
	}
	$prompt = myls_bb_replace_tokens( $prompt_template, $vars );
	if ( ! empty( $site_context['prompt_block'] ) ) {
		$prompt .= $site_context['prompt_block'];
	}
	$prompt .= "\n\n[GRID INSTRUCTION] features.items must contain exactly {$card_count_total} items (cols={$feature_cols} × rows={$feature_rows}).";
	$prompt .= "\n[GRID INSTRUCTION] process.steps must contain exactly {$process_step_total} steps (cols={$process_cols} × rows={$process_rows}).";

	// ── Schema-data grounding (mirrors Elementor) ──────────────────────────
	$schema_ctx_parts = array();
	$org_name   = trim( (string) get_option( 'myls_org_name', '' ) );
	$org_desc   = trim( (string) get_option( 'myls_org_description', '' ) );
	$org_areas  = trim( (string) get_option( 'myls_org_areas', '' ) );
	if ( $org_name )  $schema_ctx_parts[] = "Business name: {$org_name}";
	if ( $org_desc )  $schema_ctx_parts[] = "About the business: {$org_desc}";
	if ( $org_areas ) $schema_ctx_parts[] = "Service areas: {$org_areas}";

	$lb_locs = (array) get_option( 'myls_lb_locations', array() );
	$lb0     = is_array( $lb_locs[0] ?? null ) ? $lb_locs[0] : array();
	if ( ! empty( $lb0['city'] ) ) $schema_ctx_parts[] = 'Primary city: ' . $lb0['city'];

	$gbp_rating = trim( (string) get_option( 'myls_google_places_rating', '' ) );
	$gbp_count  = trim( (string) get_option( 'myls_google_places_review_count', '' ) );
	if ( $gbp_rating && $gbp_count ) $schema_ctx_parts[] = "Google rating: {$gbp_rating}/5 from {$gbp_count} reviews";
	elseif ( $gbp_rating )           $schema_ctx_parts[] = "Google rating: {$gbp_rating}/5";

	if ( ! empty( $schema_ctx_parts ) ) {
		$prompt     .= "\n\n--- Business Profile (use these facts — write naturally, do NOT copy verbatim) ---\n" . implode( "\n", $schema_ctx_parts );
		$log_lines[] = '🏢 Schema context injected (' . count( $schema_ctx_parts ) . ' data points).';
	}

	/* ── Call AI ─────────────────────────────────────────────────────────── */
	$raw     = '';
	$ai_used = false;
	if ( function_exists( 'myls_ai_chat' ) ) {
		$model = (string) get_option( 'myls_openai_model', '' );
		if ( function_exists( 'myls_ai_set_usage_context' ) ) {
			myls_ai_set_usage_context( 'beaver_builder', 0 );
		}
		$raw = myls_ai_chat( $prompt, array(
			'model'       => $model,
			'max_tokens'  => 4000,
			'temperature' => 0.7,
			'system'      => 'You are a content writer for Beaver Builder WordPress pages. You output ONLY valid JSON — never HTML at the top level, never markdown, never code fences. Your response is a single JSON object keyed by section: hero, tldr, trust_bar, intro, features, rich_content, process, pricing, cta. Start with { and end with }. The rich_content.html field is the ONLY field that contains HTML.',
		) );
		if ( ! empty( trim( $raw ) ) ) $ai_used = true;
	}

	// Fallback JSON when AI is unavailable so the round-trip still works.
	if ( empty( trim( $raw ) ) ) {
		$raw = wp_json_encode( array(
			'hero' => array(
				'title'       => $page_title,
				'subtitle'    => $description ?: 'Professional service in ' . ( $vars['city'] ?: 'your area' ),
				'button_text' => 'Get In Touch',
				'button_url'  => $contact_url,
			),
			'intro' => array(
				'heading'    => 'About ' . $page_title,
				'paragraphs' => array( $description ?: 'Learn more about ' . $page_title ),
			),
			'cta'   => array(
				'heading'     => 'Ready to Get Started?',
				'subtitle'    => 'Contact us today.',
				'button_text' => 'Contact Us',
				'button_url'  => $contact_url,
			),
		) );
		$log_lines[] = '⚠️ AI unavailable — used fallback JSON.';
	} else {
		$log_lines[] = '🤖 AI returned ' . strlen( $raw ) . ' chars of JSON.';
	}

	// Tolerate the occasional ```json``` fence the AI emits despite instructions.
	$json_decoded = json_decode( $raw, true );
	if ( ! is_array( $json_decoded ) ) {
		$cleaned = preg_replace( '/^```(json)?|```$/m', '', trim( $raw ) );
		$json_decoded = json_decode( trim( $cleaned ), true );
	}
	if ( ! is_array( $json_decoded ) ) {
		wp_send_json_error( array(
			'message'    => 'AI returned invalid JSON. Inspect prompt or model output.',
			'raw_sample' => mb_substr( (string) $raw, 0, 400 ),
		), 500 );
	}

	/* ── Build BB layout ────────────────────────────────────────────────── */
	$build = $parser->build_layout_from_sections( $json_decoded, $sections_order, array(
		'page_title'  => $page_title,
		'description' => $description,
		'contact_url' => $contact_url,
	) );

	$nodes         = $build['nodes'];
	$section_count = (int) $build['section_count'];
	$tldr_text     = (string) $build['tldr_text'];

	if ( empty( $nodes ) ) {
		wp_send_json_error( array( 'message' => 'No sections enabled — nothing to build.' ), 400 );
	}
	$log_lines[] = "🏗️  BB layout assembled: {$section_count} sections, " . count( $nodes ) . ' nodes.';

	/* ── Upsert post ─────────────────────────────────────────────────────── */
	$meta_key = '_myls_bb_generated_key';
	$gen_key  = 'bb:' . sanitize_title( $page_title );

	$existing = get_posts( array(
		'post_type'      => $post_type,
		'post_status'    => array( 'draft', 'publish', 'pending', 'future', 'private' ),
		'meta_key'       => $meta_key,
		'meta_value'     => $gen_key,
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );

	// BB renders its own content via _fl_builder_data; post_content gets the
	// rendered HTML cache on first frontend hit. We seed a placeholder.
	$post_content_fallback = '<!-- Beaver Builder page — edit in BB Builder -->';

	if ( $existing ) {
		$post_id      = (int) $existing[0];
		$update_args  = array(
			'ID'           => $post_id,
			'post_title'   => $page_title,
			'post_content' => $post_content_fallback,
			'post_status'  => $page_status,
		);
		if ( $page_slug )      $update_args['post_name']   = $page_slug;
		if ( $parent_page_id ) $update_args['post_parent'] = $parent_page_id;
		wp_update_post( $update_args );
		$action_label = 'updated';
	} else {
		$insert_args = array(
			'post_type'    => $post_type,
			'post_status'  => $page_status,
			'post_title'   => $page_title,
			'post_content' => $post_content_fallback,
			'meta_input'   => array(
				'_myls_bb_generated' => 1,
				$meta_key            => $gen_key,
			),
		);
		if ( $page_slug )      $insert_args['post_name']   = $page_slug;
		if ( $parent_page_id ) $insert_args['post_parent'] = $parent_page_id;
		$post_id = (int) wp_insert_post( $insert_args );
		$action_label = 'created';
	}

	if ( ! $post_id || is_wp_error( $post_id ) ) {
		wp_send_json_error( array( 'message' => 'Failed to create post.' ), 500 );
	}

	/* ── Persist BB layout via parser ────────────────────────────────────── */
	$saved = $parser->save_layout( $post_id, $nodes, array() );
	if ( ! $saved ) {
		wp_send_json_error( array( 'message' => 'Failed to save BB layout to post meta.' ), 500 );
	}
	$log_lines[] = "💾 BB layout persisted to _fl_builder_data + _fl_builder_draft on post #{$post_id}.";

	/* ── Yoast / SEO meta passthrough ────────────────────────────────────── */
	if ( $seo_keyword !== '' ) {
		update_post_meta( $post_id, '_yoast_wpseo_focuskw', $seo_keyword );
		update_post_meta( $post_id, '_yoast_wpseo_title', $seo_keyword );
	}
	if ( $tldr_text !== '' ) {
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', mb_substr( wp_strip_all_tags( $tldr_text ), 0, 155 ) );
		update_post_meta( $post_id, '_aintelligize_tldr', $tldr_text );
	}

	/* ── Inherit city_state from parent (parity with Elementor) ──────────── */
	if ( $parent_page_id ) {
		foreach ( array( 'city_state', 'county' ) as $field_key ) {
			$parent_val = function_exists( 'get_field' )
				? get_field( $field_key, $parent_page_id )
				: get_post_meta( $parent_page_id, $field_key, true );
			if ( $parent_val !== '' && $parent_val !== null && $parent_val !== false ) {
				update_post_meta( $post_id, $field_key, sanitize_text_field( $parent_val ) );
			}
		}
		$parent_myls_cs = get_post_meta( $parent_page_id, '_myls_city_state', true );
		if ( $parent_myls_cs !== '' && $parent_myls_cs !== false ) {
			update_post_meta( $post_id, '_myls_city_state', sanitize_text_field( $parent_myls_cs ) );
		}
	}

	/* ── Add to menu ─────────────────────────────────────────────────────── */
	if ( $add_to_menu ) {
		$locations = get_nav_menu_locations();
		$menu_id   = 0;
		foreach ( array( 'primary', 'main', 'top' ) as $loc ) {
			if ( ! empty( $locations[ $loc ] ) ) { $menu_id = (int) $locations[ $loc ]; break; }
		}
		if ( $menu_id > 0 && function_exists( 'wp_update_nav_menu_item' ) ) {
			wp_update_nav_menu_item( $menu_id, 0, array(
				'menu-item-title'     => $page_title,
				'menu-item-object'    => $post_type,
				'menu-item-object-id' => $post_id,
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
			) );
			$log_lines[] = '➕ Added to nav menu (#' . $menu_id . ').';
		}
	}

	/* ── Build pending_images manifest ───────────────────────────────────── */
	$pending_images = array();
	if ( $integrate_images && function_exists( 'myls_pb_dall_e_generate' ) ) {
		$api_key = function_exists( 'myls_openai_get_api_key' ) ? myls_openai_get_api_key() : '';
		if ( ! empty( $api_key ) ) {
			if ( $gen_hero_img ) {
				$pending_images[] = array( 'type' => 'hero', 'subject' => $page_title, 'size' => '1792x1024', 'index' => 0 );
			}
			if ( ! $gen_hero_img && $set_featured ) {
				$pending_images[] = array( 'type' => 'featured', 'subject' => $page_title, 'size' => '1792x1024', 'index' => 0 );
			}
			if ( $gen_feature_cards ) {
				$card_subjects = function_exists( 'myls_pb_suggest_image_subjects' )
					? myls_pb_suggest_image_subjects( $page_title, $description, $card_count_total )
					: array_fill( 0, $card_count_total, $page_title );
				for ( $c = 0; $c < $card_count_total; $c++ ) {
					$pending_images[] = array( 'type' => 'feature_card', 'subject' => $card_subjects[ $c ] ?? $page_title, 'size' => '1024x1024', 'index' => $c );
				}
			}
		}
	}

	$elapsed = number_format( microtime( true ) - $start_time, 2 );
	$log_lines[] = "✅ Page {$action_label} in {$elapsed}s. Section count: {$section_count}.";

	$edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
	$view_url = get_permalink( $post_id );
	// Add ?fl_builder=1 so opening goes straight into BB editor.
	$bb_edit_url = add_query_arg( array( 'fl_builder' => 1 ), $view_url );

	wp_send_json_success( array(
		'message'        => "Page {$action_label} (#{$post_id}).",
		'log_text'       => implode( "\n", $log_lines ),
		'post_id'        => $post_id,
		'action'         => $action_label,
		'section_count'  => $section_count,
		'pending_images' => $pending_images,
		'image_style'    => $image_style,
		'set_featured'   => (bool) $set_featured,
		'edit_url'       => $bb_edit_url,
		'view_url'       => $view_url,
		'admin_edit_url' => $edit_url,
		'ai_used'        => $ai_used,
	) );
} );

/* =========================================================================
 * AJAX: Save / reset prompt template
 * ========================================================================= */
add_action( 'wp_ajax_myls_bb_save_prompt', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
	if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_bb_create' ) ) wp_send_json_error( array( 'message' => 'Bad nonce' ), 400 );

	update_option( 'myls_bb_prompt_template', wp_kses_post( $_POST['prompt_template'] ?? '' ) );
	wp_send_json_success( array( 'message' => 'Beaver Builder prompt template saved.' ) );
} );

/* =========================================================================
 * AJAX: Generate single DALL-E image and patch into BB layout
 * ========================================================================= */
add_action( 'wp_ajax_myls_bb_generate_single_image', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
	if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_bb_create' ) ) wp_send_json_error( array( 'message' => 'Bad nonce' ), 400 );

	$post_id      = (int) ( $_POST['post_id'] ?? 0 );
	$image_type   = sanitize_key( $_POST['image_type'] ?? '' );
	$image_index  = (int) ( $_POST['image_index'] ?? 0 );
	$subject      = sanitize_text_field( $_POST['subject'] ?? '' );
	$size         = sanitize_text_field( $_POST['size'] ?? '1024x1024' );
	$image_style  = sanitize_text_field( $_POST['image_style'] ?? 'photo' );
	$set_featured = ! empty( $_POST['set_featured'] );
	$page_title   = sanitize_text_field( $_POST['page_title'] ?? '' );
	$description  = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );

	if ( ! $post_id || ! get_post( $post_id ) ) wp_send_json_error( array( 'message' => 'Invalid post ID.' ), 400 );
	if ( ! in_array( $image_type, array( 'hero', 'featured', 'feature_card' ), true ) ) wp_send_json_error( array( 'message' => 'Invalid image type.' ), 400 );
	if ( ! function_exists( 'myls_pb_dall_e_generate' ) || ! function_exists( 'myls_pb_upload_image_from_url' ) ) {
		wp_send_json_error( array( 'message' => 'Image generation helpers not available.' ), 500 );
	}

	$api_key = function_exists( 'myls_openai_get_api_key' ) ? myls_openai_get_api_key() : '';
	if ( empty( $api_key ) ) wp_send_json_error( array( 'message' => 'OpenAI API key not configured.' ), 500 );

	$style_map = array(
		'photo'             => 'Professional photograph, real camera shot, natural lighting, high resolution, sharp focus, authentic scene, no illustrations, no digital art',
		'modern-flat'       => 'Modern flat design illustration, clean lines, soft gradients, professional color palette, minimalist',
		'photorealistic'    => 'Professional stock photography style, high quality, well-lit, clean background',
		'isometric'         => 'Isometric 3D illustration, colorful, tech-forward, clean white background',
		'watercolor'        => 'Soft watercolor style illustration, artistic, professional, warm tones',
		'gradient-abstract' => 'Abstract gradient art, flowing shapes, modern tech aesthetic, vivid colors',
	);
	$style_suffix = $style_map[ $image_style ] ?? $style_map['photo'];
	$dalle_style  = ( $image_style === 'photo' ) ? 'natural' : 'vivid';

	$topic = $subject ?: $page_title ?: get_the_title( $post_id );
	switch ( $image_type ) {
		case 'hero':
			$prompt      = "Create a wide banner/hero image for a webpage about: {$topic}. ";
			if ( $description ) $prompt .= 'Context: ' . mb_substr( wp_strip_all_tags( $description ), 0, 300 ) . '. ';
			$prompt     .= "Style: {$style_suffix}. Landscape orientation, 1792x1024, no text or words in the image.";
			$slug_suffix = '-hero';
			$alt_suffix  = ' - Hero Image';
			break;
		case 'featured':
			$prompt      = "Create a wide featured image for a webpage about: {$topic}. ";
			if ( $description ) $prompt .= 'Context: ' . mb_substr( wp_strip_all_tags( $description ), 0, 300 ) . '. ';
			$prompt     .= "Style: {$style_suffix}. Landscape orientation, 1792x1024, no text or words in the image.";
			$slug_suffix = '-featured';
			$alt_suffix  = ' - Featured Image';
			break;
		case 'feature_card':
		default:
			$card_num    = $image_index + 1;
			$prompt      = "Create a professional square image for a service feature card about: {$topic}. Subject: {$subject}. Style: {$style_suffix}. Square format 1024x1024. No text or words in the image.";
			$slug_suffix = '-card-' . $card_num;
			$alt_suffix  = ' - Feature Card ' . $card_num;
			break;
	}

	$result = myls_pb_dall_e_generate( $api_key, $prompt, $size, $dalle_style );
	if ( ! $result['ok'] ) wp_send_json_error( array( 'message' => 'DALL-E error: ' . ( $result['error'] ?? 'Unknown error' ) ), 500 );

	$attach_id = myls_pb_upload_image_from_url(
		$result['url'],
		sanitize_title( $topic ) . $slug_suffix,
		$topic . $alt_suffix,
		$post_id
	);
	if ( ! $attach_id ) wp_send_json_error( array( 'message' => 'DALL-E succeeded but Media Library upload failed.' ), 500 );

	$attach_url = wp_get_attachment_url( $attach_id );
	if ( $set_featured && in_array( $image_type, array( 'hero', 'featured' ), true ) ) {
		set_post_thumbnail( $post_id, $attach_id );
	}

	/* ── Patch the BB layout to inject the image ────────────────────────── */
	$parser = new AIntelligize_Beaver_Builder_Parser();
	$raw    = get_post_meta( $post_id, '_fl_builder_data', true );
	$nodes  = is_string( $raw ) && ! empty( $raw )
		? @unserialize( $raw, array( 'allowed_classes' => array( 'stdClass', 'FLBuilderModule' ) ) )
		: array();

	if ( ! is_array( $nodes ) ) $nodes = array();

	$patched = false;
	if ( $image_type === 'hero' || $image_type === 'featured' ) {
		// Find the row marked as the hero section and set its bg_image.
		foreach ( $nodes as $id => $n ) {
			if ( ! is_object( $n ) ) continue;
			$section = isset( $n->settings->aintelligize_section ) ? $n->settings->aintelligize_section : '';
			if ( $n->type === 'row' && $section === 'hero' ) {
				$n->settings->bg_type           = 'photo';
				$n->settings->bg_image          = (int) $attach_id;
				$n->settings->bg_image_src      = $attach_url;
				$n->settings->bg_position       = 'center center';
				$n->settings->bg_size           = 'cover';
				$n->settings->bg_overlay_color  = 'rgba(0,0,0,0.45)';
				$nodes[ $id ] = $n;
				$patched = true;
				break;
			}
		}
	} elseif ( $image_type === 'feature_card' ) {
		// Walk modules in document order, find the nth photo module within
		// rows tagged as features_*, and stamp its photo settings.
		$photo_index = 0;
		foreach ( $nodes as $id => $n ) {
			if ( ! is_object( $n ) ) continue;
			if ( $n->type !== 'module' ) continue;
			$slug = $n->settings->type ?? '';
			if ( $slug !== 'photo' ) continue;

			// Check that this module's grandparent row is a features_* row.
			$col_id  = $n->parent ?? null;
			$cg_id   = ( $col_id && isset( $nodes[ $col_id ] ) && is_object( $nodes[ $col_id ] ) ) ? $nodes[ $col_id ]->parent : null;
			$row_id  = ( $cg_id  && isset( $nodes[ $cg_id ]  ) && is_object( $nodes[ $cg_id ]  ) ) ? $nodes[ $cg_id ]->parent  : null;
			$row_sec = ( $row_id && isset( $nodes[ $row_id ] ) && is_object( $nodes[ $row_id ] ) ) ? ( $nodes[ $row_id ]->settings->aintelligize_section ?? '' ) : '';
			if ( strpos( (string) $row_sec, 'features_row_' ) !== 0 ) continue;

			if ( $photo_index === $image_index ) {
				$n->settings->photo_source = 'library';
				$n->settings->photo        = (int) $attach_id;
				$n->settings->photo_src    = $attach_url;
				$nodes[ $id ] = $n;
				$patched = true;
				break;
			}
			$photo_index++;
		}
	}

	if ( $patched ) {
		$parser->save_layout( $post_id, $nodes );
	}

	wp_send_json_success( array(
		'type'    => $image_type,
		'index'   => $image_index,
		'id'      => $attach_id,
		'url'     => $attach_url,
		'alt'     => $topic . $alt_suffix,
		'subject' => $subject,
		'patched' => $patched,
	) );
} );

/* =========================================================================
 * AJAX: nav posts (block theme info — same query as Elementor handler)
 * ========================================================================= */
add_action( 'wp_ajax_myls_bb_get_nav_posts', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array(), 403 );
	if ( ! function_exists( 'myls_pb_find_active_nav_id' ) ) {
		wp_send_json_success( array( 'nav_posts' => array(), 'is_block_theme' => false ) );
	}
	$nav_posts = get_posts( array(
		'post_type'      => 'wp_navigation',
		'post_status'    => 'publish',
		'posts_per_page' => 20,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );
	$active_id = myls_pb_find_active_nav_id();
	$items     = array();
	foreach ( $nav_posts as $np ) {
		$items[] = array( 'id' => (int) $np->ID, 'title' => $np->post_title ?: '(untitled)', 'active' => (int) $np->ID === $active_id );
	}
	wp_send_json_success( array( 'nav_posts' => $items, 'active_id' => $active_id, 'is_block_theme' => wp_is_block_theme() ) );
} );

/* =========================================================================
 * AJAX: Description history
 * ========================================================================= */
add_action( 'wp_ajax_myls_bb_save_description', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
	if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_bb_create' ) ) wp_send_json_error( array( 'message' => 'Bad nonce' ), 400 );

	$name        = sanitize_text_field( $_POST['desc_name'] ?? '' );
	$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
	if ( $name === '' )         wp_send_json_error( array( 'message' => 'Name is required.' ), 400 );
	if ( $description === '' )  wp_send_json_error( array( 'message' => 'Description is empty.' ), 400 );

	$history = get_option( 'myls_bb_desc_history', array() );
	if ( ! is_array( $history ) ) $history = array();
	$slug              = sanitize_title( $name );
	$history[ $slug ]  = array( 'name' => $name, 'description' => $description, 'updated' => current_time( 'mysql' ) );
	if ( count( $history ) > 50 ) $history = array_slice( $history, -50, 50, true );
	update_option( 'myls_bb_desc_history', $history );
	wp_send_json_success( array( 'message' => "Description \"{$name}\" saved.", 'history' => myls_bb_format_history( $history ) ) );
} );

add_action( 'wp_ajax_myls_bb_list_descriptions', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array(), 403 );
	$history = get_option( 'myls_bb_desc_history', array() );
	if ( ! is_array( $history ) ) $history = array();
	wp_send_json_success( array( 'history' => myls_bb_format_history( $history ) ) );
} );

add_action( 'wp_ajax_myls_bb_delete_description', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
	if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_bb_create' ) ) wp_send_json_error( array( 'message' => 'Bad nonce' ), 400 );

	$slug = sanitize_title( $_POST['desc_slug'] ?? '' );
	if ( $slug === '' ) wp_send_json_error( array( 'message' => 'Invalid entry.' ), 400 );
	$history = get_option( 'myls_bb_desc_history', array() );
	if ( ! is_array( $history ) ) $history = array();
	$name = $history[ $slug ]['name'] ?? $slug;
	unset( $history[ $slug ] );
	update_option( 'myls_bb_desc_history', $history );
	wp_send_json_success( array( 'message' => "Deleted \"{$name}\".", 'history' => myls_bb_format_history( $history ) ) );
} );

if ( ! function_exists( 'myls_bb_format_history' ) ) {
	function myls_bb_format_history( array $history ): array {
		$out = array();
		foreach ( $history as $slug => $entry ) {
			$out[] = array(
				'slug'        => $slug,
				'name'        => $entry['name']        ?? $slug,
				'description' => $entry['description'] ?? '',
				'updated'     => $entry['updated']     ?? '',
			);
		}
		usort( $out, fn( $a, $b ) => strcmp( $b['updated'], $a['updated'] ) );
		return $out;
	}
}

/* =========================================================================
 * AJAX: Page Setup save / list / delete (snapshot of left-panel state)
 * ========================================================================= */
add_action( 'wp_ajax_myls_bb_save_setup', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
	if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_bb_create' ) ) wp_send_json_error( array( 'message' => 'Bad nonce' ), 400 );

	$name      = sanitize_text_field( $_POST['setup_name'] ?? '' );
	$setup_raw = wp_unslash( $_POST['setup_data'] ?? '' );
	if ( $name === '' )      wp_send_json_error( array( 'message' => 'Name is required.' ), 400 );
	if ( $setup_raw === '' ) wp_send_json_error( array( 'message' => 'Setup data is empty.' ), 400 );

	$setup = json_decode( $setup_raw, true );
	if ( ! is_array( $setup ) ) wp_send_json_error( array( 'message' => 'Invalid setup data.' ), 400 );

	$raw_so   = is_array( $setup['sections_order'] ?? null ) ? $setup['sections_order'] : array();
	$clean_so = array();
	foreach ( $raw_so as $so_item ) {
		if ( ! is_array( $so_item ) ) continue;
		$type  = in_array( $so_item['type'] ?? '', array( 'section', 'template' ), true ) ? $so_item['type'] : 'section';
		$entry = array(
			'id'      => sanitize_key( $so_item['id'] ?? '' ),
			'type'    => $type,
			'enabled' => (bool) ( $so_item['enabled'] ?? true ),
		);
		if ( $type === 'section' && ! empty( $so_item['cols'] ) ) {
			$entry['cols'] = max( 1, min( 6, (int) $so_item['cols'] ) );
			$entry['rows'] = max( 1, min( 6, (int) ( $so_item['rows'] ?? 1 ) ) );
		}
		if ( $type === 'section' && ! empty( $so_item['widget_type'] ) ) {
			$entry['widget_type'] = in_array( $so_item['widget_type'], array( 'icon', 'image' ), true ) ? $so_item['widget_type'] : 'icon';
		}
		if ( $type === 'template' ) {
			$entry['template_id'] = (int) ( $so_item['template_id'] ?? 0 );
		}
		$clean_so[] = $entry;
	}

	$clean = array(
		'post_type'         => sanitize_key( $setup['post_type'] ?? 'page' ),
		'title'             => sanitize_text_field( $setup['title'] ?? '' ),
		'description'       => sanitize_textarea_field( wp_unslash( $setup['description'] ?? '' ) ),
		'seo_keyword'       => sanitize_text_field( $setup['seo_keyword'] ?? '' ),
		'status'            => in_array( $setup['status'] ?? '', array( 'draft', 'publish', 'pending' ), true ) ? $setup['status'] : 'draft',
		'add_to_menu'       => (bool) ( $setup['add_to_menu'] ?? true ),
		'sections_order'    => $clean_so,
		'gen_hero'          => (bool) ( $setup['gen_hero'] ?? true ),
		'gen_feature_cards' => (bool) ( $setup['gen_feature_cards'] ?? false ),
		'image_style'       => sanitize_key( $setup['image_style'] ?? 'photo' ),
		'set_featured'      => (bool) ( $setup['set_featured'] ?? true ),
		'biz_name'          => sanitize_text_field( $setup['biz_name']  ?? '' ),
		'biz_city'          => sanitize_text_field( $setup['biz_city']  ?? '' ),
		'biz_phone'         => sanitize_text_field( $setup['biz_phone'] ?? '' ),
		'biz_email'         => sanitize_email(      $setup['biz_email'] ?? '' ),
		'prompt_template'   => wp_kses_post( wp_unslash( $setup['prompt_template'] ?? '' ) ),
	);

	$history = get_option( 'myls_bb_setup_history', array() );
	if ( ! is_array( $history ) ) $history = array();
	$slug             = sanitize_title( $name );
	$history[ $slug ] = array( 'name' => $name, 'setup' => $clean, 'updated' => current_time( 'mysql' ) );
	if ( count( $history ) > 50 ) $history = array_slice( $history, -50, 50, true );
	update_option( 'myls_bb_setup_history', $history );
	wp_send_json_success( array( 'message' => "Setup \"{$name}\" saved.", 'history' => myls_bb_format_setups( $history ) ) );
} );

add_action( 'wp_ajax_myls_bb_list_setups', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array(), 403 );
	$history = get_option( 'myls_bb_setup_history', array() );
	if ( ! is_array( $history ) ) $history = array();
	wp_send_json_success( array( 'history' => myls_bb_format_setups( $history ) ) );
} );

add_action( 'wp_ajax_myls_bb_delete_setup', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
	if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_bb_create' ) ) wp_send_json_error( array( 'message' => 'Bad nonce' ), 400 );
	$slug = sanitize_title( $_POST['setup_slug'] ?? '' );
	if ( $slug === '' ) wp_send_json_error( array( 'message' => 'Invalid entry.' ), 400 );
	$history = get_option( 'myls_bb_setup_history', array() );
	if ( ! is_array( $history ) ) $history = array();
	$name = $history[ $slug ]['name'] ?? $slug;
	unset( $history[ $slug ] );
	update_option( 'myls_bb_setup_history', $history );
	wp_send_json_success( array( 'message' => "Deleted \"{$name}\".", 'history' => myls_bb_format_setups( $history ) ) );
} );

if ( ! function_exists( 'myls_bb_format_setups' ) ) {
	function myls_bb_format_setups( array $history ): array {
		$out = array();
		foreach ( $history as $slug => $entry ) {
			$out[] = array(
				'slug'    => $slug,
				'name'    => $entry['name']    ?? $slug,
				'setup'   => $entry['setup']   ?? array(),
				'updated' => $entry['updated'] ?? '',
			);
		}
		usort( $out, fn( $a, $b ) => strcmp( $b['updated'], $a['updated'] ) );
		return $out;
	}
}

/* =========================================================================
 * AJAX: BB templates (post type fl-builder-template)
 * ========================================================================= */
add_action( 'wp_ajax_myls_bb_get_templates', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array(), 403 );
	if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_bb_create' ) ) wp_send_json_error( array( 'message' => 'Bad nonce' ), 400 );

	$posts = get_posts( array(
		'post_type'      => 'fl-builder-template',
		'post_status'    => 'publish',
		'posts_per_page' => 100,
		'orderby'        => 'title',
		'order'          => 'ASC',
	) );

	$templates = array();
	foreach ( $posts as $p ) {
		$type = '';
		$terms = wp_get_post_terms( $p->ID, 'fl-builder-template-type', array( 'fields' => 'slugs' ) );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$type = (string) $terms[0];
		}
		$templates[] = array(
			'id'    => (int) $p->ID,
			'title' => $p->post_title ?: '(untitled)',
			'type'  => $type ?: 'layout',
		);
	}

	wp_send_json_success( array( 'templates' => $templates ) );
} );

/* =========================================================================
 * AJAX: Parent pages selector
 * ========================================================================= */
add_action( 'wp_ajax_myls_bb_get_parent_pages', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array(), 403 );
	if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_bb_create' ) ) wp_send_json_error( array( 'message' => 'Bad nonce' ), 400 );

	$post_type = sanitize_key( $_POST['post_type'] ?? 'page' );
	if ( ! post_type_exists( $post_type ) ) wp_send_json_error( array( 'message' => 'Invalid post type' ), 400 );

	$q = new WP_Query( array(
		'post_type'              => $post_type,
		'post_status'            => array( 'publish', 'draft' ),
		'posts_per_page'         => 300,
		'orderby'                => 'menu_order title',
		'order'                  => 'ASC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );

	$index = array();
	foreach ( $q->posts as $p ) $index[ $p->ID ] = $p;

	$depth_fn = function ( int $id ) use ( &$index ) {
		$d = 0;
		while ( $d < 10 && isset( $index[ $id ] ) && $index[ $id ]->post_parent > 0 ) {
			$id = $index[ $id ]->post_parent;
			$d++;
		}
		return $d;
	};

	$pages = array();
	foreach ( $q->posts as $p ) {
		$d      = $depth_fn( $p->ID );
		$prefix = $d ? str_repeat( '— ', $d ) : '';
		$pages[] = array(
			'id'    => $p->ID,
			'title' => $prefix . ( $p->post_title ?: '(untitled #' . $p->ID . ')' ),
		);
	}

	wp_send_json_success( array( 'pages' => $pages, 'post_type' => $post_type ) );
} );

/* =========================================================================
 * AJAX: Debug — inspect _fl_builder_data on any post
 * ========================================================================= */
add_action( 'wp_ajax_myls_bb_debug_post', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
	if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_bb_create' ) ) wp_send_json_error( array( 'message' => 'Bad nonce' ), 400 );

	$post_id = (int) ( $_POST['post_id'] ?? 0 );
	if ( $post_id <= 0 ) wp_send_json_error( array( 'message' => 'post_id required' ), 400 );

	$post     = get_post( $post_id );
	$enabled  = get_post_meta( $post_id, '_fl_builder_enabled', true );
	$raw      = get_post_meta( $post_id, '_fl_builder_data', true );
	$raw_drft = get_post_meta( $post_id, '_fl_builder_draft', true );

	$nodes        = array();
	$unser_ok     = false;
	if ( is_string( $raw ) && $raw !== '' ) {
		$nodes = @unserialize( $raw, array( 'allowed_classes' => array( 'stdClass', 'FLBuilderModule' ) ) );
		$unser_ok = is_array( $nodes ) || is_object( $nodes );
		if ( is_object( $nodes ) ) $nodes = (array) $nodes;
	}
	$row_count    = 0;
	$module_count = 0;
	$module_types = array();
	if ( is_array( $nodes ) ) {
		foreach ( $nodes as $n ) {
			if ( ! is_object( $n ) ) continue;
			if ( $n->type === 'row' ) $row_count++;
			if ( $n->type === 'module' ) {
				$module_count++;
				$slug = $n->settings->type ?? '';
				if ( $slug ) $module_types[ $slug ] = ( $module_types[ $slug ] ?? 0 ) + 1;
			}
		}
	}

	wp_send_json_success( array(
		'post_id'            => $post_id,
		'post_title'         => $post ? $post->post_title : '(not found)',
		'post_status'        => $post ? $post->post_status : '(not found)',
		'fl_builder_enabled' => $enabled,
		'data_stored'        => ! empty( $raw ),
		'draft_stored'       => ! empty( $raw_drft ),
		'data_length'        => strlen( (string) $raw ),
		'unserialize_ok'     => $unser_ok,
		'row_count'          => $row_count,
		'module_count'       => $module_count,
		'module_types'       => $module_types,
		'data_preview'       => mb_substr( (string) $raw, 0, 500 ),
	) );
} );

/* =========================================================================
 * AJAX: DALL-E connection test (mirror Elementor)
 * ========================================================================= */
add_action( 'wp_ajax_myls_bb_test_dalle', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
	if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_bb_create' ) ) wp_send_json_error( array( 'message' => 'Bad nonce' ), 400 );

	$log = array();
	$log[] = '🔑 Looking up OpenAI API key…';
	$api_key = function_exists( 'myls_openai_get_api_key' ) ? myls_openai_get_api_key() : '';
	if ( empty( $api_key ) ) {
		$log[] = '❌ No API key configured.';
		wp_send_json_error( array( 'message' => 'OpenAI API key not configured.', 'log' => $log ) );
	}
	$log[] = '✅ API key present.';

	if ( ! function_exists( 'myls_pb_dall_e_generate' ) ) {
		$log[] = '❌ DALL-E helper not available (myls_pb_dall_e_generate missing).';
		wp_send_json_error( array( 'message' => 'DALL-E helper not available.', 'log' => $log ) );
	}

	$log[] = '🎨 Requesting tiny test image (1024×1024)…';
	$result = myls_pb_dall_e_generate( $api_key, 'A simple blue circle on a white background', '1024x1024', 'natural' );
	if ( ! ( $result['ok'] ?? false ) ) {
		$log[] = '❌ ' . ( $result['error'] ?? 'Unknown error' );
		wp_send_json_error( array( 'message' => $result['error'] ?? 'Test failed', 'log' => $log ) );
	}
	$log[] = '✅ DALL-E reachable. Sample URL: ' . mb_substr( (string) ( $result['url'] ?? '' ), 0, 80 ) . '…';
	wp_send_json_success( array( 'log' => $log ) );
} );
