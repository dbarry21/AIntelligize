<?php
/**
 * Shortcode: [myls_ajax_search]
 *
 * Live AJAX search (top results while typing) with post-type restriction + overrides.
 *
 * DEFAULT:
 * - post_types="current" (or omitted) searches ONLY the post type of the page where this shortcode is placed.
 *
 * OVERRIDES:
 * - post_types="all"                  => search all PUBLIC post types (excluding attachments)
 * - post_types="post"                 => search posts only
 * - post_types="page"                 => search pages only
 * - post_types="post,page,service"    => search multiple types
 *
 * NEW:
 * - priority="service,video"          => floats those post types to top (in that order)
 *
 * Ranking:
 *  1) Title-first pass (title-only matches up to max)
 *  2) Fill remainder with default WP search (title/excerpt/content), excluding duplicates
 *  3) Optional: post_type priority ordering (client-provided), then title
 *
 * Assets expected under plugin root:
 * - /assets/js/myls-ajax-search.js
 * - /assets/css/myls-ajax-search.css (optional)
 */

if ( ! defined('ABSPATH') ) exit;

/* -------------------------------------------------------------------------
 * Helpers: plugin root URL (assets under plugin root)
 * ------------------------------------------------------------------------- */
if ( ! function_exists('myls_ajax_search_plugin_root_url') ) {
	function myls_ajax_search_plugin_root_url() : string {

		// Preferred: plugin defines MYLS_PLUGIN_URL in main bootstrap.
		if ( defined('MYLS_PLUGIN_URL') && MYLS_PLUGIN_URL ) {
			return trailingslashit(MYLS_PLUGIN_URL);
		}

		// Expected: this file under /modules/shortcodes/ => plugin root is two levels up.
		$root_dir = dirname(__DIR__, 2);

		$candidates = [
			$root_dir . '/aintelligize.php',
			$root_dir . '/index.php',
		];

		foreach ( $candidates as $file ) {
			if ( file_exists($file) ) {
				return trailingslashit( plugin_dir_url($file) );
			}
		}

		// Last resort (may be wrong in some structures, but avoids fatal).
		return trailingslashit( plugin_dir_url(__FILE__) );
	}
}

/**
 * Allow only existing PUBLIC post types (excluding attachment).
 */
if ( ! function_exists('myls_ajax_search_filter_allowed_post_types') ) {
	function myls_ajax_search_filter_allowed_post_types( array $types ) : array {

		$public_pts = get_post_types(['public' => true], 'names');
		unset($public_pts['attachment']);

		$allowed = [];
		foreach ( $types as $pt ) {
			$pt = sanitize_key((string) $pt);
			if ( $pt && isset($public_pts[$pt]) ) {
				$allowed[] = $pt;
			}
		}

		return array_values(array_unique($allowed));
	}
}

/**
 * Parse shortcode attr post_types into array:
 * - "" or "current" => [current post type]
 * - "all"           => all public post types (excluding attachment)
 * - "post,page,..." => explicit list
 */
if ( ! function_exists('myls_ajax_search_parse_post_types') ) {
	function myls_ajax_search_parse_post_types( string $raw, string $fallback_current ) : array {

		$raw = trim((string) $raw);
		$raw_lc = strtolower($raw);

		if ( $raw === '' || $raw_lc === 'current' ) {
			return [$fallback_current];
		}

		if ( $raw_lc === 'all' ) {
			$pts = get_post_types(['public' => true], 'names');
			unset($pts['attachment']);
			return array_values($pts);
		}

		$parts = array_filter(array_map('trim', explode(',', $raw)));
		$types = [];

		foreach ( $parts as $pt ) {
			$pt = sanitize_key($pt);
			if ( $pt ) $types[] = $pt;
		}

		$types = array_values(array_unique($types));
		return $types ? $types : [$fallback_current];
	}
}

/**
 * Parse priority CSV into ordered map.
 * Example: "service,video" => ['service'=>0,'video'=>1]
 * Only keeps valid PUBLIC post types (excluding attachment).
 */
if ( ! function_exists('myls_ajax_search_parse_priority_map') ) {
	function myls_ajax_search_parse_priority_map( string $raw ) : array {
		$raw = trim((string) $raw);
		if ( $raw === '' ) return [];

		$parts = array_filter(array_map('trim', explode(',', $raw)));
		$parts = array_map('sanitize_key', $parts);
		$parts = myls_ajax_search_filter_allowed_post_types($parts);

		$map = [];
		$i = 0;
		foreach ( $parts as $pt ) {
			$map[$pt] = $i;
			$i++;
		}
		return $map;
	}
}

/* -------------------------------------------------------------------------
 * Assets (registered; enqueued by shortcode)
 * ------------------------------------------------------------------------- */
add_action('wp_enqueue_scripts', function () {

	$root_url = myls_ajax_search_plugin_root_url();

	wp_register_script(
		'myls-ajax-search',
		$root_url . 'assets/js/myls-ajax-search.js',
		['jquery'],
		'1.1.0',
		true
	);

	wp_register_style(
		'myls-ajax-search',
		$root_url . 'assets/css/myls-ajax-search.css',
		[],
		'1.0.0'
	);
});

/* -------------------------------------------------------------------------
 * Title-only search filter (applied temporarily during title-first pass)
 * ------------------------------------------------------------------------- */
if ( ! function_exists('myls_ajax_search_title_only_search') ) {
	function myls_ajax_search_title_only_search( $search, $wp_query ) {
		global $wpdb;

		if ( empty($search) ) return $search;

		$q = $wp_query->query_vars;
		if ( empty($q['s']) ) return $search;

		$terms = method_exists($wp_query, 'parse_search_terms')
			? $wp_query->parse_search_terms($q['s'])
			: preg_split('/\s+/', trim((string)$q['s']));

		$terms = array_filter(array_map('trim', (array) $terms));
		if ( empty($terms) ) return $search;

		$clauses = [];
		foreach ( $terms as $t ) {
			$clauses[] = $wpdb->prepare("{$wpdb->posts}.post_title LIKE %s", '%' . $wpdb->esc_like($t) . '%');
		}

		// AND all terms together (tight matching)
		return ' AND (' . implode(' AND ', $clauses) . ') ';
	}
}

/* -------------------------------------------------------------------------
 * AJAX endpoint: wp_ajax_myls_ajax_search + nopriv
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_myls_ajax_search',        'myls_ajax_search_handler');
add_action('wp_ajax_nopriv_myls_ajax_search', 'myls_ajax_search_handler');

if ( ! function_exists('myls_ajax_search_handler') ) {
	function myls_ajax_search_handler() : void {

		// Nonce
		$nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
		if ( ! wp_verify_nonce($nonce, 'myls_ajax_search_nonce') ) {
			wp_send_json_error(['message' => 'Invalid nonce.']);
		}

		// Term
		$term = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
		$term = trim($term);

		// Max results
		$max = isset($_POST['max']) ? max(1, (int) $_POST['max']) : 5;

		// Post types (comma-separated from client)
		$raw_post_types = isset($_POST['post_types']) ? sanitize_text_field(wp_unslash($_POST['post_types'])) : '';
		$types = array_filter(array_map('trim', explode(',', $raw_post_types)));
		$types = myls_ajax_search_filter_allowed_post_types($types);

		// Priority (optional): CSV like "service,video"
		$raw_priority = isset($_POST['priority']) ? sanitize_text_field(wp_unslash($_POST['priority'])) : '';
		$priority_map = myls_ajax_search_parse_priority_map($raw_priority);

		// Safety fallback (avoid accidentally searching "any")
		if ( empty($types) ) {
			$types = ['post'];
		}

		if ( $term === '' ) {
			wp_send_json_success(['items' => []]);
		}

		/* -----------------------------------------
		 * 1) Title-first pass (title only)
		 * ----------------------------------------- */
		add_filter('posts_search', 'myls_ajax_search_title_only_search', 10, 2);

		$q_title = new WP_Query([
			'post_type'           => $types,
			'post_status'         => 'publish',
			's'                   => $term,
			'posts_per_page'      => $max,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		]);

		remove_filter('posts_search', 'myls_ajax_search_title_only_search', 10);

		$items    = [];
		$used_ids = [];

		if ( $q_title->have_posts() ) {
			while ( $q_title->have_posts() ) {
				$q_title->the_post();
				$used_ids[] = get_the_ID();

				$items[] = [
					'title' => get_the_title(),
					'url'   => get_permalink(),
					'type'  => get_post_type(),
					'date'  => get_the_date('m/d/Y'),
					'thumb' => get_the_post_thumbnail_url(get_the_ID(), 'thumbnail') ?: '',
				];
			}
			wp_reset_postdata();
		}

		/* -----------------------------------------
		 * 2) Fill remainder (default WP search)
		 * ----------------------------------------- */
		$remaining = $max - count($items);

		if ( $remaining > 0 ) {

			$q_fill = new WP_Query([
				'post_type'           => $types,
				'post_status'         => 'publish',
				's'                   => $term,
				'posts_per_page'      => $remaining,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'post__not_in'        => $used_ids,
				'orderby'             => 'relevance',
			]);

			if ( $q_fill->have_posts() ) {
				while ( $q_fill->have_posts() ) {
					$q_fill->the_post();

					$items[] = [
						'title' => get_the_title(),
						'url'   => get_permalink(),
						'type'  => get_post_type(),
						'date'  => get_the_date('m/d/Y'),
						'thumb' => get_the_post_thumbnail_url(get_the_ID(), 'thumbnail') ?: '',
					];
				}
				wp_reset_postdata();
			}
		}

		/* -----------------------------------------
		 * 3) Optional: Priority sort by post type, then title
		 * ----------------------------------------- */
		if ( ! empty($priority_map) && ! empty($items) ) {
			usort($items, function($a, $b) use ($priority_map) {

				$at = isset($a['type']) ? (string) $a['type'] : '';
				$bt = isset($b['type']) ? (string) $b['type'] : '';

				$ap = array_key_exists($at, $priority_map) ? (int) $priority_map[$at] : 999;
				$bp = array_key_exists($bt, $priority_map) ? (int) $priority_map[$bt] : 999;

				if ( $ap !== $bp ) return $ap <=> $bp;

				$an = isset($a['title']) ? (string) $a['title'] : '';
				$bn = isset($b['title']) ? (string) $b['title'] : '';
				return strcasecmp($an, $bn);
			});
		}

		wp_send_json_success(['items' => $items]);
	}
}

/* -------------------------------------------------------------------------
 * Shortcode: [myls_ajax_search]
 * ------------------------------------------------------------------------- */
add_shortcode('myls_ajax_search', function ($atts) {

	$atts = shortcode_atts([
		'placeholder' => 'Search...',
		'post_types'  => 'current', // current | all | post,page,service
		'priority'    => '',        // "service,video,page" — float types to top
		'max'         => 10,
		'min_chars'   => 2,
		'debounce_ms' => 200,
		'hint'        => 1,
		'show_type'   => 0,         // show post type label in results (JS)

		// Back-compat aliases (won't break older shortcodes)
		'post_type'   => '',        // alias for post_types (single)
		'limit'       => '',        // alias for max
		'description' => '',        // alias for hint
	], $atts, 'myls_ajax_search');

	// Alias support: post_type="page"
	if ( $atts['post_type'] !== '' && $atts['post_types'] === 'current' ) {
		$atts['post_types'] = (string) $atts['post_type'];
	}

	// Alias support: limit="10"
	if ( $atts['limit'] !== '' ) {
		$atts['max'] = (int) $atts['limit'];
	}

	// Alias support: description="0" => hint=0
	if ( $atts['description'] !== '' ) {
		$atts['hint'] = (int) $atts['description'];
	}

	$placeholder = sanitize_text_field($atts['placeholder']);
	$priority    = sanitize_text_field((string)$atts['priority']);
	$max         = max(1, (int) $atts['max']);
	$min_chars   = max(1, (int) $atts['min_chars']);
	$debounce_ms = max(0, (int) $atts['debounce_ms']);
	$hint        = ((int) $atts['hint'] === 1);
	$show_type   = ((int) $atts['show_type'] === 1);

	// Determine the post type where this search bar is displayed
	$current_pt = get_post_type(get_the_ID());
	if ( ! $current_pt ) $current_pt = 'post';

	// Resolve post types
	$post_types = myls_ajax_search_parse_post_types((string) $atts['post_types'], $current_pt);
	$post_types = myls_ajax_search_filter_allowed_post_types($post_types);

	// Safety fallback
	if ( empty($post_types) ) {
		$post_types = [$current_pt];
	}

	// Enqueue assets
	wp_enqueue_script('myls-ajax-search');
	wp_enqueue_style('myls-ajax-search');

	// Global transport config (per-instance config is in data-* attrs)
	wp_localize_script('myls-ajax-search', 'MYLS_AJAX_SEARCH', [
		'ajaxurl' => admin_url('admin-ajax.php'),
		'nonce'   => wp_create_nonce('myls_ajax_search_nonce'),
	]);

	static $instance = 0;
	$instance++;
	$id = 'myls-ajax-search-' . $instance;

	$data_post_types = esc_attr(implode(',', $post_types));
	$hint_mode = strtolower(trim((string) $atts['post_types']));
	$hint_label = ($hint_mode === 'all')
		? 'All public post types'
		: implode(', ', $post_types);

	ob_start(); ?>
	<div class="myls-ajax-search"
	     id="<?php echo esc_attr($id); ?>"
	     data-post-types="<?php echo $data_post_types; ?>"
	     data-priority="<?php echo esc_attr($priority); ?>"
	     data-max="<?php echo esc_attr($max); ?>"
	     data-min-chars="<?php echo esc_attr($min_chars); ?>"
	     data-debounce-ms="<?php echo esc_attr($debounce_ms); ?>"
	     data-show-type="<?php echo esc_attr($show_type ? '1' : '0'); ?>">

		<div class="position-relative">
			<input type="search"
			       class="form-control myls-ajax-search-input"
			       placeholder="<?php echo esc_attr($placeholder); ?>"
			       aria-label="<?php echo esc_attr($placeholder); ?>"
			       autocomplete="off">
			<div class="myls-ajax-search-results list-group" style="display:none;"></div>
		</div>

		<?php if ( $hint ) : ?>
			<div class="small text-muted mt-2">
				Searching: <strong><?php echo esc_html($hint_label); ?></strong> (title-first)
			</div>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
});
