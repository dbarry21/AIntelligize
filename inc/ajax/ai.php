<?php
/**
 * AIntelligize – AI AJAX
 * Path: inc/ajax/ai.php
 *
 * Endpoints:
 *  - wp_ajax_myls_ai_posts_by_type
 *  - wp_ajax_myls_ai_generate_meta
 *  - wp_ajax_myls_ai_about_get_posts
 *  - wp_ajax_myls_ai_about_generate
 */
if ( ! defined('ABSPATH') ) exit;

/* ------------------------- JSON helper ------------------------- */
if ( ! function_exists('myls_ai_json') ) {
	function myls_ai_json( array $data, int $code = 200 ) : void {
		status_header( $code );
		wp_send_json( $data );
	}
}

/* ------------------------- Nonce helper ------------------------ */
if ( ! function_exists('myls_ai_check_nonce') ) {
	function myls_ai_check_nonce( string $action = 'myls_ai_ops' ) : void {
		$nonce = isset($_POST['nonce']) ? $_POST['nonce'] : ( $_REQUEST['_ajax_nonce'] ?? '' );
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			myls_ai_json( ['ok'=>false, 'error'=>'Invalid or missing nonce'], 403 );
		}
	}
}

/* ---------------------- Context helper (meta) ------------------ */
if ( ! function_exists('myls_ai_context_for_post') ) {
	function myls_ai_context_for_post( int $post_id ) : array {
		$post = get_post( $post_id );
		if ( ! $post ) return [];
		$site_name = get_bloginfo('name');
		$excerpt = has_excerpt($post_id) ? get_the_excerpt($post_id) : ( function_exists('myls_get_post_plain_text') ? myls_get_post_plain_text( $post_id, 40 ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 40, '…' ) );
		$primary_category = '';
		$cats = get_the_category( $post_id );
		if ( is_array($cats) && !empty($cats) ) $primary_category = $cats[0]->name;

		return [
			'post_title'       => get_the_title($post_id),
			'site_name'        => $site_name,
			'excerpt'          => $excerpt,
			'primary_category' => $primary_category,
			'permalink'        => get_permalink($post_id),
		];
	}
}

/* ---------------------- Token replace (meta) ------------------- */
if ( ! function_exists('myls_ai_apply_tokens') ) {
	function myls_ai_apply_tokens( string $tpl, array $ctx ) : string {
		return preg_replace_callback('/\{([a-z0-9_]+)\}/i', function($m) use ($ctx){
			$key = $m[1];
			return isset($ctx[$key]) ? (string)$ctx[$key] : $m[0];
		}, $tpl);
	}
}

/* ---------------------- AI provider wrapper -------------------- */
if ( ! function_exists('myls_ai_generate_text') ) {
	function myls_ai_generate_text( string $prompt, array $opts = [] ) : string {
		// Preferred provider
		$out = apply_filters('myls_ai_complete', '', array_merge([
			'prompt'      => $prompt,
			'max_tokens'  => $opts['max_tokens']  ?? null,
			'temperature' => $opts['temperature'] ?? null,
			'context'     => $opts['context']     ?? null,
			'post_id'     => $opts['post_id']     ?? null,
		], $opts));
		if ( is_string($out) && $out !== '' ) return $out;

		// Legacy fallback
		$resp = apply_filters('myls_ai_generate_text', '', $prompt);
		if ( is_string($resp) && $resp !== '' ) return $resp;

		return '';
	}
}

/* ---------------------- Meta output cleanup ------------------- */
/**
 * Extract a single, clean meta value from AI output.
 *
 * AI models often return multiple options, explanations, markdown formatting,
 * or commentary alongside the requested title/description. This function
 * aggressively strips everything except the first usable line.
 *
 * @since 6.3.0.9
 */
if ( ! function_exists('myls_clean_meta_output') ) {
	function myls_clean_meta_output( string $raw ) : string {
		$raw = trim( $raw );
		if ( $raw === '' ) return '';

		// ── 1a. Inline truncation (no newlines — all on one line) ──
		// The AI sometimes outputs everything on a single line.
		// Cut at first inline signal of multi-option/commentary content.
		$inline_cuts = [
			' Or alternative',
			' **Option 2',
			' Option 2:',
			' Option 2 ',
			' Here are ',
			' Here\'s another',
			' Alternative version',
			' Which direction',
			' Each version',
			' I\'ve created',
			' I\'ve written',
			' Let me know',
			' Would you',
			' Do you prefer',
			' Note:',
		];
		foreach ( $inline_cuts as $needle ) {
			$pos = stripos( $raw, $needle );
			if ( $pos !== false && $pos > 20 ) {
				$raw = substr( $raw, 0, $pos );
			}
		}

		// ── 1b. Truncate at newline-based multi-option signals ──
		$cut_patterns = [
			'/\n\s*(\*\*)?Option\s*[2-9].*$/is',
			'/\n\s*Or\s+alternative.*$/is',
			'/\n\s*Alternative\s*(version|option)?.*$/is',
			'/\n\s*Which\s+(direction|option|version|one).*$/is',
			'/\n\s*Each\s+(version|option|title).*$/is',
			'/\n\s*Here\s+(are|is|\'s).*$/is',
			'/\n\s*I\'ve\s+(created|written|provided|generated).*$/is',
			'/\n\s*This\s+(title|description|version|option).*$/is',
			'/\n\s*Let\s+me\s+know.*$/is',
			'/\n\s*Would\s+you.*$/is',
			'/\n\s*Do\s+you\s+prefer.*$/is',
			'/\n\s*Note:.*$/is',
			'/\n\s*Explanation:.*$/is',
		];
		foreach ( $cut_patterns as $pattern ) {
			$result = preg_replace( $pattern, '', $raw );
			if ( $result !== null ) {
				$raw = $result;
			}
		}

		// ── 2. Split into lines, grab the first non-empty meaningful line ──
		$lines = preg_split( '/\r?\n/', $raw );
		$best  = '';
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' ) continue;

			// Skip lines that are clearly labels/commentary, not the actual meta value
			if ( preg_match( '/^(Option\s*\d|Version\s*\d|Alternative\s*(version|option)|Note\s*:|Explanation\s*:|Here\s+(are|is|\'s)\s+(your|the|my|a|some)|This\s+(title|description)\s+(is|was|uses|follows)|Each\s+(version|option|title)|Which\s+(direction|option|version|one)|Would\s+you|Do\s+you\s+prefer|I\'ve\s+(created|written|provided|generated)|Let\s+me\s+know)/i', $line ) ) {
				continue;
			}

			$best = $line;
			break;
		}

		if ( $best === '' ) return '';

		// ── 3. Strip markdown/formatting artifacts from the chosen line ──
		// Markdown heading prefixes
		$best = preg_replace( '/^#{1,3}\s+/', '', $best );
		// Bold markdown wrappers
		$best = preg_replace( '/^\*\*(.+)\*\*$/', '$1', $best );
		// Wrapping quotes
		$best = trim( $best, "\"'" );
		// Prefix labels: "Title:", "SEO Title:", "Meta Description:", etc.
		$best = preg_replace( '/^(Title|Meta\s*Title|Meta\s*Description|Description|SEO\s*Title|Output)\s*:\s*/i', '', $best );
		// Trailing character count notes: "(95 chars)", "(120 characters)"
		$best = preg_replace( '/\s*\(\d+\s*char(acter)?s?\)\s*$/', '', $best );
		// Collapse multiple spaces
		$best = preg_replace( '/\s{2,}/', ' ', $best );

		return trim( $best );
	}
}

/* ========================= EXISTING: Meta ====================== */

/** GET POSTS BY TYPE (used by Meta tab) */
add_action('wp_ajax_myls_ai_posts_by_type', function(){
	myls_ai_check_nonce();

	$pt = isset($_POST['pt']) ? sanitize_key($_POST['pt']) : 'page';
	if ( ! post_type_exists($pt) ) {
		myls_ai_json( ['ok'=>false, 'error'=>'Invalid post type'] , 400 );
	}

	$ptype_obj = get_post_type_object($pt);
	$cap = $ptype_obj && isset($ptype_obj->cap->edit_posts) ? $ptype_obj->cap->edit_posts : 'edit_posts';
	if ( ! current_user_can($cap) ) {
		myls_ai_json( ['ok'=>false, 'error'=>'Insufficient permissions'], 403 );
	}

	$ids = get_posts([
		'post_type'       => $pt,
		'post_status'     => ['publish','draft','pending','future','private'],
		'posts_per_page'  => 500,
		'orderby'         => 'title',
		'order'           => 'ASC',
		'fields'          => 'ids',
		'suppress_filters'=> true,
	]);

	$posts = [];
	foreach ($ids as $pid) {
		$title = get_the_title($pid);
		if ($title === '') $title = '(no title)';
		$posts[] = ['id' => (int)$pid, 'title' => $title];
	}

	myls_ai_json( ['ok'=>true, 'posts'=>$posts] );
});

/** GENERATE META (Yoast title/description) */
add_action('wp_ajax_myls_ai_generate_meta', function(){
	myls_ai_check_nonce();
	$batch_start = microtime(true);

	// Reset variation engine log
	if ( class_exists('MYLS_Variation_Engine') ) {
		MYLS_Variation_Engine::reset_log();
	}

	$kind      = isset($_POST['kind']) ? sanitize_key($_POST['kind']) : 'title'; // 'title'|'desc'
	$pt        = isset($_POST['pt']) ? sanitize_key($_POST['pt']) : 'page';
	$ids       = isset($_POST['ids']) ? (array) $_POST['ids'] : [];
	$prompt    = isset($_POST['prompt']) ? wp_unslash($_POST['prompt']) : '';
	if ( trim($prompt) === '' && function_exists('myls_get_default_prompt') ) {
		$prompt = myls_get_default_prompt( $kind === 'title' ? 'meta-title' : 'meta-description' );
	}
	$overwrite = ! empty($_POST['overwrite']);
	$dryrun    = ! empty($_POST['dryrun']);

	if ( $kind !== 'title' && $kind !== 'desc' ) {
		myls_ai_json( ['ok'=>false,'error'=>'Invalid kind'], 400 );
	}
	if ( ! post_type_exists($pt) ) {
		myls_ai_json( ['ok'=>false,'error'=>'Invalid post type'], 400 );
	}
	if ( empty($ids) ) {
		myls_ai_json( ['ok'=>false,'error'=>'No post IDs provided'], 400 );
	}

	$items = [];
	$saved_count = 0;

	foreach ($ids as $maybe_id) {
		$item_start = microtime(true);
		$id  = (int) $maybe_id;
		$row = ['id'=>$id, 'post_title'=> get_the_title($id)];
		if ( ! $id || ! current_user_can('edit_post', $id) ) {
			$row['error'] = 'Insufficient permissions';
			$items[] = $row;
			continue;
		}

		// Reset VE log per-item
		if ( class_exists('MYLS_Variation_Engine') ) {
			MYLS_Variation_Engine::reset_log();
		}

		$meta_key = ($kind === 'title') ? '_yoast_wpseo_title' : '_yoast_wpseo_metadesc';
		$old = get_post_meta($id, $meta_key, true);

		if ( !$overwrite && is_string($old) && $old !== '' ) {
			$row['old']   = $old;
			$row['new']   = '';
			$row['saved'] = false;
			$row['msg']   = 'Existing value present; skipping (overwrite off).';
			$items[] = $row;
			continue;
		}

		$ctx = myls_ai_context_for_post($id);
		$final_prompt = myls_ai_apply_tokens($prompt, $ctx);

		// ── Variation Engine: inject angle + banned phrases for meta generation ──
		if ( class_exists('MYLS_Variation_Engine') ) {
			$ve_context = ( $kind === 'title' ) ? 'meta_title' : 'meta_description';
			$angle = MYLS_Variation_Engine::next_angle( $ve_context );
			$final_prompt = MYLS_Variation_Engine::inject_variation( $final_prompt, $angle, $ve_context );
		}

		// Call AI — no max_tokens override; let openai.php context defaults handle it
		if ( function_exists('myls_ai_set_usage_context') ) {
			myls_ai_set_usage_context( 'meta_' . $kind, $id );
		}
		$new = trim( myls_ai_generate_text( $final_prompt ) );

		// ── Clean up AI output: extract single meta value ──
		$raw_output = $new; // keep original for logging
		if ( $new !== '' ) {
			$new = myls_clean_meta_output( $new );
		}

		// ── Variation Engine: duplicate guard for meta fields ──
		// Checks this output against previous batch outputs; rewrites if >60% similar.
		if ( $new !== '' && class_exists('MYLS_Variation_Engine') ) {
			$new = MYLS_Variation_Engine::guard_duplicates(
				$ve_context ?? 'meta_title',
				$new,
				function( $original ) use ( $final_prompt, $kind ) {
					$rewrite = "Rewrite this to be structurally distinct. Use a different opening word and sentence structure. Respond with ONLY the rewritten text — no options, no commentary.\n\nOriginal: " . $original;
					$out = trim( myls_ai_generate_text( $rewrite ) );
					if ( $out !== '' ) {
						$out = myls_clean_meta_output( $out );
					}
					return $out;
				}
			);
		}

		if ($new === '') {
			// Diagnose: was it the API or the cleanup?
			$diag = '';
			if ( $raw_output === '' ) {
				$diag = 'API returned empty.';
				// Check for stored API error
				if ( ! empty( $GLOBALS['myls_ai_last_error'] ) ) {
					$diag .= ' Error: ' . mb_substr( $GLOBALS['myls_ai_last_error'], 0, 300 );
				}
				// Check for last call info
				if ( function_exists('myls_ai_last_call') ) {
					$lc = myls_ai_last_call();
					$diag .= ' | Provider: ' . ($lc['provider'] ?? '?');
					$diag .= ', Model: ' . ($lc['resolved_model'] ?? $lc['requested_model'] ?? '?');
				}
			} else {
				$diag = 'API returned ' . strlen($raw_output) . ' chars but cleanup stripped it. Raw: ' . mb_substr($raw_output, 0, 200);
			}
			$row['old']   = $old;
			$row['new']   = '';
			$row['saved'] = false;
			$row['error'] = 'No output. ' . $diag;
			$items[] = $row;
			// Clear the error for next iteration
			$GLOBALS['myls_ai_last_error'] = '';
			continue;
		}
		// Clear error on success
		$GLOBALS['myls_ai_last_error'] = '';

		$row['old']    = $old;
		$row['new']    = $new;
		$row['dryrun'] = !!$dryrun;

		if ( $dryrun ) {
			$row['saved'] = false;
			$row['msg']   = 'Dry-run only.';
		} else {
			update_post_meta( $id, $meta_key, $new );
			$row['saved'] = true;
			$row['msg']   = 'Saved.';
			$saved_count++;
		}

		// Per-item log data
		$row['log'] = class_exists('MYLS_Variation_Engine') ? MYLS_Variation_Engine::build_item_log($item_start, [
			'prompt_chars'   => mb_strlen( $final_prompt ),
			'output_chars'   => strlen( $new ),
			'output_words'   => str_word_count( $new ),
			'page_title'     => get_the_title( $id ),
			'_html'          => $new,
			'char_count'     => strlen( $new ),
			'kind'           => $kind,
		]) : [
			'elapsed_ms'     => round( ( microtime(true) - $item_start ) * 1000 ),
			'kind'           => $kind,
		];

		$items[] = $row;
	}

	$summary = sprintf(
		'%d item(s) processed. %d saved. %d preview/skip.',
		count($items),
		$saved_count,
		count($items) - $saved_count
	);

	myls_ai_json( ['ok'=>true, 'items'=>$items, 'summary'=>$summary, 'batch_elapsed_ms' => round( ( microtime(true) - $batch_start ) * 1000 ) ] );
});

/* ===================== NEW: About the Area ===================== */

/** List posts for multiselect in About tab */
add_action('wp_ajax_myls_ai_about_get_posts', function(){
	myls_ai_check_nonce();

	$pt = isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : 'service_area';
	if ( ! post_type_exists($pt) ) {
		wp_send_json_error(['status'=>'error','message'=>'invalid_post_type'], 400);
	}

	$ptype_obj = get_post_type_object($pt);
	$cap = $ptype_obj && isset($ptype_obj->cap->edit_posts) ? $ptype_obj->cap->edit_posts : 'edit_posts';
	if ( ! current_user_can($cap) ) {
		wp_send_json_error(['status'=>'error','message'=>'cap_denied'], 403);
	}

	$ids = get_posts([
		'post_type'       => $pt,
		'post_status'     => ['publish','draft','pending','future','private'],
		'posts_per_page'  => -1,
		'orderby'         => 'title',
		'order'           => 'ASC',
		'fields'          => 'ids',
		'suppress_filters'=> true,
	]);

	$out = [];
	foreach ($ids as $pid) {
		$out[] = [
			'id'    => (int) $pid,
			'title' => get_the_title($pid),
		];
	}

	wp_send_json_success(['status'=>'ok','posts'=>$out,'marker'=>'about_get_posts_v1']);
});

/** Generate + save About-the-Area for one post */
add_action('wp_ajax_myls_ai_about_generate', function(){
	myls_ai_check_nonce();

	$post_id     = (int) ($_POST['post_id'] ?? 0);
	$skip_filled = !empty($_POST['skip_filled']);
	$template    = isset($_POST['template']) ? wp_unslash($_POST['template']) : '';
	$tokens      = max(1, (int) ($_POST['tokens'] ?? 600));
	$temperature = (float) ($_POST['temperature'] ?? 0.7);

	if ( $post_id <= 0 || get_post_status($post_id) === false ) {
		wp_send_json_error(['marker'=>'about_generate_v1','status'=>'error','message'=>'bad_post'], 400);
	}
	if ( ! current_user_can('edit_post', $post_id) ) {
		wp_send_json_error(['marker'=>'about_generate_v1','status'=>'error','message'=>'cap_denied'], 403);
	}

	// Skip if already filled
	$existing = get_post_meta($post_id, 'about_the_area', true);
	if ( $skip_filled && is_string($existing) && trim($existing) !== '' ) {
		wp_send_json_success([
			'marker'  => 'about_generate_v1',
			'status'  => 'skipped',
			'post_id' => $post_id,
			'reason'  => 'already_filled',
		]);
	}

	// CITY/STATE from ACF or meta or title
	$city_state = '';
	if ( function_exists('get_field') ) $city_state = (string) get_field('city_state', $post_id);
	if ( $city_state === '' ) $city_state = (string) get_post_meta($post_id, 'city_state', true);
	if ( $city_state === '' ) $city_state = get_the_title($post_id);

	// Template fallback
	if ( $template === '' ) $template = get_option('myls_ai_about_prompt_template', '');
	if ( $template === '' && function_exists('myls_get_default_prompt') ) $template = myls_get_default_prompt('about-area');
	if ( $template === '' ) $template = "Write a 400–500 word clean-HTML 'About the Area' for {{CITY_STATE}}.";
	$prompt = str_replace('{{CITY_STATE}}', $city_state, $template);

	// Generate
	$html = myls_ai_generate_text($prompt, [
		'max_tokens'  => $tokens,
		'temperature' => $temperature,
		'context'     => 'about_the_area',
		'post_id'     => $post_id,
	]);

	if ( $html === '' || is_wp_error($html) ) {
		$err = is_wp_error($html) ? $html->get_error_message() : 'empty_response';
		wp_send_json_error([
			'marker'  => 'about_generate_v1',
			'status'  => 'error',
			'post_id' => $post_id,
			'message' => 'ai_failed',
			'error'   => $err,
		], 500);
	}

	// Sanitize
	$allowed = wp_kses_allowed_html('post');
	// Clean up markdown artifacts from AI response
	if ( function_exists('myls_about_clean_ai_response') ) {
		$html = myls_about_clean_ai_response( $html );
	}
	$clean   = wp_kses( wp_unslash($html), $allowed );

	/* ---------- Robust ACF save (name → key → raw-meta+link) ---------- */

	// Field key discovery (handles Group/Repeater/Flex)
	if ( ! function_exists('myls_acf_find_field_key_by_name') ) {
		function myls_acf_find_field_key_by_name( $post_id, $target_name ) {
			if ( ! function_exists('get_field_objects') ) return '';
			$objects = get_field_objects( $post_id, false );
			if ( ! is_array($objects) ) return '';
			$stack = array_values($objects);
			while ( $stack ) {
				$fo = array_pop($stack);
				if ( ! is_array($fo) ) continue;
				if ( ! empty($fo['name']) && $fo['name'] === $target_name && ! empty($fo['key']) ) return $fo['key'];
				if ( ! empty($fo['sub_fields']) && is_array($fo['sub_fields']) ) foreach ($fo['sub_fields'] as $sub) $stack[] = $sub;
				if ( ! empty($fo['layouts']) && is_array($fo['layouts']) ) {
					foreach ($fo['layouts'] as $lay) {
						if ( ! empty($lay['sub_fields']) && is_array($lay['sub_fields']) ) foreach ($lay['sub_fields'] as $sub) $stack[] = $sub;
					}
				}
			}
			return '';
		}
	}

	$saved        = false;
	$saved_method = '';
	$acf_key_used = '';

	// (1) ACF by NAME
	if ( function_exists('update_field') && update_field('about_the_area', $clean, $post_id) ) {
		$saved = true; $saved_method = 'acf_update_field_by_name';
	}

	// (2) ACF by KEY
	if ( ! $saved && function_exists('update_field') ) {
		$key = '';
		if ( function_exists('get_field_object') ) {
			$fo = get_field_object('about_the_area', $post_id);
			if ( $fo && !empty($fo['key']) ) $key = $fo['key'];
		}
		if ( $key === '' ) $key = myls_acf_find_field_key_by_name( $post_id, 'about_the_area' );
		if ( $key !== '' && update_field( $key, $clean, $post_id ) ) {
			$saved = true; $saved_method = 'acf_update_field_by_key'; $acf_key_used = $key;
		}
	}

	// (3) Raw meta + link companion key
	if ( ! $saved ) {
		$saved_raw = (bool) update_post_meta( $post_id, 'about_the_area', $clean );
		if ( $saved_raw ) {
			$saved = true; $saved_method = 'raw_update_post_meta';
			if ( $acf_key_used === '' ) $acf_key_used = myls_acf_find_field_key_by_name( $post_id, 'about_the_area' );
			if ( $acf_key_used !== '' ) {
				update_post_meta($post_id, '_about_the_area', $acf_key_used);
				$saved_method .= '+acf_key_link';
			}
		}
	}

	// (4) Verify
	$verify = '';
	if ( function_exists('get_field') ) $verify = (string) get_field('about_the_area', $post_id);
	if ( $verify === '' ) $verify = (string) get_post_meta($post_id, 'about_the_area', true);

	if ( ! $saved || trim( wp_strip_all_tags($verify) ) === '' ) {
		wp_send_json_error([
			'marker'  => 'about_generate_v1',
			'status'  => 'error',
			'post_id' => $post_id,
			'message' => 'save_failed_or_empty_after_write',
			'debug'   => [
				'saved'        => $saved ? 'true' : 'false',
				'saved_method' => $saved_method,
				'acf_key_used' => $acf_key_used,
				'verify_len'   => strlen($verify),
			],
		], 500);
	}

	wp_send_json_success([
		'marker'     => 'about_generate_v1',
		'status'     => 'saved',
		'post_id'    => $post_id,
		'length'     => strlen($clean),
		'city_state' => $city_state,
		'preview'    => mb_substr( wp_strip_all_tags( $verify ), 0, 80 ) . ( strlen( wp_strip_all_tags($verify) ) > 80 ? '…' : '' ),
		'debug'      => [
			'saved_method' => $saved_method,
			'acf_key_used' => $acf_key_used,
		],
	]);
});
