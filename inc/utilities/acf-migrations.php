<?php
/**
 * AIntelligize — Utilities: ACF → Native meta migrations
 *
 * Adds AJAX batch migration endpoints used by the Utilities tab.
 *
 * Migrations:
 *  - FAQ repeater (ACF) -> _myls_faq_items
 *  - city_state (ACF/text meta) -> _myls_city_state
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Only allow admins by default (filterable).
 */
if ( ! function_exists('myls_util_cap') ) {
	function myls_util_cap() : string {
		return (string) apply_filters('myls_utilities_cap', 'manage_options');
	}
}

/**
 * Nonce action shared by Utilities AJAX.
 */
if ( ! defined('MYLS_UTIL_NONCE_ACTION') ) {
	define('MYLS_UTIL_NONCE_ACTION', 'myls_utilities');
}

/**
 * Get all public post types we want to scan (excluding attachments).
 */
if ( ! function_exists('myls_util_get_public_post_types') ) {
	function myls_util_get_public_post_types() : array {
		$pts = get_post_types(['public' => true], 'names');
		unset($pts['attachment']);
		return array_values($pts);
	}
}

/**
 * Attempt to read ACF repeater rows for faq_items WITHOUT needing ACF active.
 * Supports two common storage formats:
 *  A) postmeta 'faq_items' is an array of rows
 *  B) postmeta 'faq_items' is a count, with submeta keys like:
 *     - faq_items_0_question
 *     - faq_items_0_answer
 */
if ( ! function_exists('myls_util_read_legacy_faq_rows') ) {
	function myls_util_read_legacy_faq_rows( int $post_id ) : array {
		$raw = get_post_meta($post_id, 'faq_items', true);

		// A) already an array of rows
		if ( is_array($raw) ) {
			return $raw;
		}

		// B) ACF stored repeater count
		$count = is_numeric($raw) ? (int) $raw : 0;
		if ( $count <= 0 ) {
			return [];
		}

		$rows = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$q = get_post_meta($post_id, "faq_items_{$i}_question", true);
			$a = get_post_meta($post_id, "faq_items_{$i}_answer", true);

			// Fallback common alternative names (just in case)
			if ( $q === '' ) $q = get_post_meta($post_id, "faq_items_{$i}_faq_question", true);
			if ( $a === '' ) $a = get_post_meta($post_id, "faq_items_{$i}_faq_answer", true);

			if ( trim((string)$q) === '' && trim((string)$a) === '' ) continue;
			$rows[] = [
				'question' => $q,
				'answer'   => $a,
			];
		}

		return $rows;
	}
}

/**
 * Normalize legacy FAQ rows into our internal shape.
 * Internal shape: [ ['q' => '...', 'a' => '<p>...</p>'], ... ]
 */
if ( ! function_exists('myls_util_normalize_faq_rows') ) {
	function myls_util_normalize_faq_rows( array $rows ) : array {
		$out = [];

		foreach ( $rows as $row ) {
			if ( ! is_array($row) ) continue;

			$q = $row['question'] ?? ($row['q'] ?? ($row['faq_question'] ?? ''));
			$a = $row['answer']   ?? ($row['a'] ?? ($row['faq_answer']   ?? ''));

			$q = wp_strip_all_tags( (string) $q );
			$a = (string) $a;

			if ( trim($q) === '' || trim(wp_strip_all_tags($a)) === '' ) continue;

			$out[] = [
				'q' => $q,
				'a' => wp_kses_post($a),
			];
		}

		return $out;
	}
}

/**

/**
 * Determine if an HTML string is effectively blank.
 * Treats whitespace, &nbsp;, and empty tags as blank.
 */
if ( ! function_exists('myls_util_is_blank_html') ) {
	function myls_util_is_blank_html( string $html ) : bool {
		$plain = wp_strip_all_tags( $html, true );
		$plain = html_entity_decode( $plain, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$plain = str_replace(["\xC2\xA0", "&nbsp;"], ' ', $plain);
		$plain = preg_replace('/\s+/u', ' ', (string) $plain);
		$plain = trim((string) $plain);
		return $plain === '';
	}
}

/**
 * Clean MYLS FAQ items array by removing empty rows.
 *
 * @param array $items
 * @return array
 */
if ( ! function_exists('myls_util_clean_myls_faq_items') ) {
	function myls_util_clean_myls_faq_items( array $items ) : array {
		$clean = [];
		foreach ( $items as $row ) {
			if ( ! is_array($row) ) continue;
			$q = isset($row['q']) ? wp_strip_all_tags((string)$row['q']) : '';
			$a = isset($row['a']) ? (string) $row['a'] : '';
			$q = trim((string) $q);
			$a = wp_kses_post($a);
			if ( $q === '' ) continue;
			if ( myls_util_is_blank_html($a) ) continue;
			$clean[] = [ 'q' => $q, 'a' => $a ];
		}
		return $clean;
	}
}

/**
 * Bulk-clean MYLS FAQs for one post.
 * Uses status keys compatible with existing batch UI:
 *  - migrated         => cleaned/updated
 *  - skipped_existing => no changes needed
 *  - empty            => no MYLS FAQs stored
 */
if ( ! function_exists('myls_util_clean_myls_faq_for_post') ) {
	function myls_util_clean_myls_faq_for_post( int $post_id, bool $overwrite = false ) : string {
		$existing = get_post_meta($post_id, '_myls_faq_items', true);
		if ( ! is_array($existing) || empty($existing) ) {
			return 'empty';
		}

		$clean = myls_util_clean_myls_faq_items($existing);

		// If nothing changed, skip.
		if ( count($clean) === count($existing) ) {
			return 'skipped_existing';
		}

		// If all rows were removed, delete meta.
		if ( empty($clean) ) {
			delete_post_meta($post_id, '_myls_faq_items');
			return 'migrated';
		}

		update_post_meta($post_id, '_myls_faq_items', $clean);
		return 'migrated';
	}
}

/**
 * Determine if an HTML string is effectively blank (only whitespace, &nbsp;, <br>, empty <p>, etc.).
 */
if ( ! function_exists('myls_util_is_blank_html') ) {
	function myls_util_is_blank_html( string $html ) : bool {
		// Strip tags, decode entities, normalize non-breaking spaces.
		$plain = wp_strip_all_tags( $html, true );
		$plain = html_entity_decode( $plain, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$plain = str_replace(["\xC2\xA0", "\u00A0"], ' ', $plain);
		$plain = preg_replace('/\s+/u', ' ', (string) $plain);
		return trim((string)$plain) === '';
	}
}

/**
 * Normalize + remove empty MYLS FAQ rows.
 * Internal shape: [ ['q' => '...', 'a' => '<p>...</p>'], ... ]
 */
if ( ! function_exists('myls_util_clean_myls_faq_items') ) {
	function myls_util_clean_myls_faq_items( array $items ) : array {
		$clean = [];
		foreach ( $items as $row ) {
			if ( ! is_array($row) ) continue;
			$q = isset($row['q']) ? wp_strip_all_tags((string)$row['q']) : '';
			$a = isset($row['a']) ? (string)$row['a'] : '';

			$q = trim((string)$q);
			if ( $q === '' ) continue;
			if ( myls_util_is_blank_html($a) ) continue;

			$clean[] = [
				'q' => $q,
				'a' => wp_kses_post($a),
			];
		}
		return $clean;
	}
}

/**
 * Bulk cleanup for one post: remove empty rows from _myls_faq_items.
 * Returns:
 *  - migrated (cleaned/updated)
 *  - skipped_existing (no changes needed)
 *  - empty (no MYLS FAQ meta)
 */
if ( ! function_exists('myls_util_clean_myls_faqs_for_post') ) {
	function myls_util_clean_myls_faqs_for_post( int $post_id, bool $overwrite_unused = false ) : string {
		$existing = get_post_meta($post_id, '_myls_faq_items', true);
		if ( ! is_array($existing) || empty($existing) ) {
			return 'empty';
		}

		$clean = myls_util_clean_myls_faq_items($existing);

		// If nothing survives, delete the meta entirely.
		if ( empty($clean) ) {
			delete_post_meta($post_id, '_myls_faq_items');
			return 'migrated';
		}

		// No changes.
		if ( $clean === $existing ) {
			return 'skipped_existing';
		}

		update_post_meta($post_id, '_myls_faq_items', $clean);
		return 'migrated';
	}
}
/**
 * Migrate FAQ items for one post.
 */
if ( ! function_exists('myls_util_migrate_faq_for_post') ) {
	function myls_util_migrate_faq_for_post( int $post_id, bool $overwrite = false ) : string {
		$existing = get_post_meta($post_id, '_myls_faq_items', true);
		if ( ! $overwrite && is_array($existing) && ! empty($existing) ) {
			return 'skipped_existing';
		}

		$legacy_rows = myls_util_read_legacy_faq_rows($post_id);
		$items = myls_util_normalize_faq_rows($legacy_rows);

		if ( empty($items) ) {
			return 'empty';
		}

		update_post_meta($post_id, '_myls_faq_items', $items);
		return 'migrated';
	}
}

/**
 * Migrate city_state for one post.
 */
if ( ! function_exists('myls_util_migrate_city_state_for_post') ) {
	function myls_util_migrate_city_state_for_post( int $post_id, bool $overwrite = false ) : string {
		$existing = (string) get_post_meta($post_id, '_myls_city_state', true);
		if ( ! $overwrite && trim($existing) !== '' ) {
			return 'skipped_existing';
		}

		$legacy = (string) get_post_meta($post_id, 'city_state', true);
		$legacy = sanitize_text_field($legacy);

		if ( trim($legacy) === '' ) {
			return 'empty';
		}

		update_post_meta($post_id, '_myls_city_state', $legacy);
		return 'migrated';
	}
}

/**
 * Shared batch runner.
 */
if ( ! function_exists('myls_util_run_batch') ) {
	function myls_util_run_batch( callable $migrator, int $offset, int $limit, bool $overwrite ) : array {
		$pts = myls_util_get_public_post_types();

		$q = new WP_Query([
			'post_type'      => $pts,
			'post_status'    => 'any',
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => false,
		]);

		$ids = array_map('intval', $q->posts ?: []);

		$counts = [
			'migrated' => 0,
			'skipped_existing' => 0,
			'empty' => 0,
			'errors' => 0,
		];

		$logs = [];

		foreach ( $ids as $post_id ) {
			try {
				$status = (string) call_user_func($migrator, $post_id, $overwrite);
				if ( isset($counts[$status]) ) $counts[$status]++;
				else $counts['errors']++;
				$logs[] = sprintf('#%d: %s', $post_id, $status);
			} catch ( Throwable $e ) {
				$counts['errors']++;
				$logs[] = sprintf('#%d: error (%s)', $post_id, $e->getMessage());
			}
		}

		$total = (int) $q->found_posts;

		return [
			'offset' => $offset,
			'limit'  => $limit,
			'next_offset' => $offset + count($ids),
			'batch_count' => count($ids),
			'total'  => $total,
			'done'   => ($offset + count($ids)) >= $total,
			'counts' => $counts,
			'logs'   => $logs,
		];
	}
}

/**
 * AJAX: FAQ batch
 */
add_action('wp_ajax_myls_util_migrate_faqs_batch', function(){
	if ( ! current_user_can( myls_util_cap() ) ) {
		wp_send_json_error(['message' => 'Permission denied'], 403);
	}
	check_ajax_referer( MYLS_UTIL_NONCE_ACTION, 'nonce' );

	$offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
	$limit  = isset($_POST['limit'])  ? max(1, min(200, (int) $_POST['limit'])) : 25;
	$overwrite = ! empty($_POST['overwrite']);

	$res = myls_util_run_batch('myls_util_migrate_faq_for_post', $offset, $limit, $overwrite);
	wp_send_json_success($res);
});

/**
 * AJAX: City/State batch
 */
add_action('wp_ajax_myls_util_migrate_city_state_batch', function(){
	if ( ! current_user_can( myls_util_cap() ) ) {
		wp_send_json_error(['message' => 'Permission denied'], 403);
	}
	check_ajax_referer( MYLS_UTIL_NONCE_ACTION, 'nonce' );

	$offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
	$limit  = isset($_POST['limit'])  ? max(1, min(200, (int) $_POST['limit'])) : 25;
	$overwrite = ! empty($_POST['overwrite']);

	$res = myls_util_run_batch('myls_util_migrate_city_state_for_post', $offset, $limit, $overwrite);
	wp_send_json_success($res);
});

/**

/**
 * AJAX: Clean MYLS FAQs (remove empty rows) batch
 */
add_action('wp_ajax_myls_util_clean_myls_faqs_batch', function(){
	if ( ! current_user_can( myls_util_cap() ) ) {
		wp_send_json_error(['message' => 'Permission denied'], 403);
	}
	check_ajax_referer( MYLS_UTIL_NONCE_ACTION, 'nonce' );

	$offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
	$limit  = isset($_POST['limit'])  ? max(1, min(200, (int) $_POST['limit'])) : 25;
	$overwrite = ! empty($_POST['overwrite']);

	$res = myls_util_run_batch('myls_util_clean_myls_faq_for_post', $offset, $limit, $overwrite);
	wp_send_json_success($res);
});

/**
 * AJAX: Clean MYLS FAQs (remove empty rows) batch
 */
add_action('wp_ajax_myls_util_clean_myls_faqs_batch', function(){
	if ( ! current_user_can( myls_util_cap() ) ) {
		wp_send_json_error(['message' => 'Permission denied'], 403);
	}
	check_ajax_referer( MYLS_UTIL_NONCE_ACTION, 'nonce' );

	$offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
	$limit  = isset($_POST['limit'])  ? max(1, min(200, (int) $_POST['limit'])) : 25;
	$overwrite = ! empty($_POST['overwrite']);

	$res = myls_util_run_batch('myls_util_clean_myls_faq_for_post', $offset, $limit, $overwrite);
	wp_send_json_success($res);
});
/**
 * Enqueue Utilities JS only on MYLS admin page + Utilities tab.
 */
add_action('admin_enqueue_scripts', function(){
	if ( ! function_exists('myls_is_admin_page') || ! myls_is_admin_page() ) return;
	$tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
	if ( $tab !== 'utilities' ) return;

	$handle = 'myls-utilities';
	// MYLS_URL is defined without a trailing slash; use the trailing-slashed constant for assets.
	$src = ( defined('MYLS_PLUGIN_URL') ? MYLS_PLUGIN_URL : trailingslashit(MYLS_URL) ) . 'assets/js/myls-utilities.js';
	wp_enqueue_script($handle, $src, [], MYLS_VERSION, true);

	wp_localize_script($handle, 'MYLS_UTIL', [
		'ajax_url' => admin_url('admin-ajax.php'),
		'nonce'    => wp_create_nonce(MYLS_UTIL_NONCE_ACTION),
	]);
});
