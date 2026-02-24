<?php
/**
 * Shortcode: [custom_blog_cards posts_per_page="12" search="1" placeholder="Search blog posts..."]
 *
 * Renders a Bootstrap card grid of your latest posts (default 12 per page) + core pagination,
 * AND (optionally) a live AJAX search box above the grid that shows top 5 results in real time.
 *
 * SEARCH BEHAVIOR (UPDATED):
 * - Title-first ranking:
 *   1) First pass: title-only matches (up to 5)
 *   2) Second pass: fill remaining slots with default WP search (title/excerpt/content),
 *      excluding duplicates.
 *
 * FILE LOCATION ASSUMPTION:
 * - This file is under: /modules/shortcodes/ (or similar)
 * - Assets are under plugin root: /assets/js/... and /assets/css/...
 *
 * REQUIRED NEW FILES AT PLUGIN ROOT:
 * - /assets/js/custom-blog-cards-live-search.js
 * - /assets/css/custom-blog-cards-live-search.css  (optional but recommended)
 */

if ( ! defined('ABSPATH') ) exit;

/* -------------------------------------------------------------------------
 * Helpers: plugin root URL (assets are under plugin root)
 * ------------------------------------------------------------------------- */
if ( ! function_exists('myls_custom_blog_cards_plugin_root_url') ) {
	/**
	 * Returns the plugin root URL (where /assets/ lives).
	 *
	 * Strategy:
	 * 1) If your plugin defines MYLS_PLUGIN_URL, use it.
	 * 2) Otherwise, compute root as two levels up from /modules/shortcodes/ => plugin root,
	 *    then feed plugin_dir_url() a "real" file inside that folder.
	 */
	function myls_custom_blog_cards_plugin_root_url() : string {

		// Preferred: use your plugin constant if available.
		if ( defined('MYLS_PLUGIN_URL') && MYLS_PLUGIN_URL ) {
			return trailingslashit( MYLS_PLUGIN_URL );
		}

		// Expected root for this file structure: /modules/shortcodes/this-file.php => go up 2
		$root_dir = dirname(__DIR__, 2);

		// Try to find a stable file in the plugin root to derive the URL.
		// If your main plugin file is named differently, add it here.
		$candidates = [
			$root_dir . '/aintelligize.php',
			$root_dir . '/index.php',
		];

		foreach ( $candidates as $file ) {
			if ( file_exists($file) ) {
				return trailingslashit( plugin_dir_url($file) );
			}
		}

		// Last resort fallback (may be wrong if assets are not near this file).
		return trailingslashit( plugin_dir_url(__FILE__) );
	}
}

/* -------------------------------------------------------------------------
 * 1) Register assets (enqueue only when shortcode is used)
 * ------------------------------------------------------------------------- */
add_action('wp_enqueue_scripts', function () {

	$root_url = myls_custom_blog_cards_plugin_root_url();

	wp_register_script(
		'custom-blog-cards-live-search',
		$root_url . 'assets/js/custom-blog-cards-live-search.js',
		['jquery'],
		'1.0.0',
		true
	);

	wp_register_style(
		'custom-blog-cards-live-search',
		$root_url . 'assets/css/custom-blog-cards-live-search.css',
		[],
		'1.0.0'
	);
});

/* -------------------------------------------------------------------------
 * 2) Title-only search filter (used ONLY during title-first pass)
 * ------------------------------------------------------------------------- */
if ( ! function_exists('custom_blog_cards_title_only_search') ) {
	/**
	 * Restrict WP search SQL to post_title only.
	 * This is applied temporarily during the title-first query.
	 */
	function custom_blog_cards_title_only_search( $search, $wp_query ) {
		global $wpdb;

		if ( empty($search) ) return $search;

		$q = $wp_query->query_vars;
		if ( empty($q['s']) ) return $search;

		// Parse into search terms similar to WP core behavior.
		if ( method_exists($wp_query, 'parse_search_terms') ) {
			$terms = $wp_query->parse_search_terms($q['s']);
		} else {
			// Fallback for older WP: basic split
			$terms = preg_split('/\s+/', trim($q['s']));
		}

		$terms = array_filter(array_map('trim', (array) $terms));
		if ( empty($terms) ) return $search;

		$clauses = [];
		foreach ( $terms as $t ) {
			$clauses[] = $wpdb->prepare("{$wpdb->posts}.post_title LIKE %s", '%' . $wpdb->esc_like($t) . '%');
		}

		// AND all terms together for tighter matching.
		return ' AND (' . implode(' AND ', $clauses) . ') ';
	}
}

/* -------------------------------------------------------------------------
 * 3) AJAX endpoint (top 5 live results, TITLE FIRST)
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_custom_blog_cards_live_search',        'custom_blog_cards_live_search_handler');
add_action('wp_ajax_nopriv_custom_blog_cards_live_search', 'custom_blog_cards_live_search_handler');

if ( ! function_exists('custom_blog_cards_live_search_handler') ) {
	function custom_blog_cards_live_search_handler() : void {

		// Nonce
		$nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
		if ( ! wp_verify_nonce($nonce, 'custom_blog_cards_live_search_nonce') ) {
			wp_send_json_error(['message' => 'Invalid nonce.']);
		}

		$term = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
		$term = trim($term);

		if ( $term === '' ) {
			wp_send_json_success(['items' => []]);
		}

		$max = 5;

		/* -----------------------------------------
		 * 1) TITLE-FIRST: title-only matches (up to 5)
		 * ----------------------------------------- */
		add_filter('posts_search', 'custom_blog_cards_title_only_search', 10, 2);

		$q_title = new WP_Query([
			'post_type'           => 'post',
			'post_status'         => 'publish',
			's'                   => $term,
			'posts_per_page'      => $max,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		]);

		remove_filter('posts_search', 'custom_blog_cards_title_only_search', 10);

		$items    = [];
		$used_ids = [];

		if ( $q_title->have_posts() ) {
			while ( $q_title->have_posts() ) {
				$q_title->the_post();

				$used_ids[] = get_the_ID();

				$items[] = [
					'title' => get_the_title(),
					'url'   => get_permalink(),
					'date'  => get_the_date('m/d/Y'),
					'thumb' => get_the_post_thumbnail_url(get_the_ID(), 'thumbnail') ?: '',
				];
			}
			wp_reset_postdata();
		}

		/* -----------------------------------------
		 * 2) FILL: broaden to default WP search
		 * ----------------------------------------- */
		$remaining = $max - count($items);

		if ( $remaining > 0 ) {

			$q_fill = new WP_Query([
				'post_type'           => 'post',
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
						'date'  => get_the_date('m/d/Y'),
						'thumb' => get_the_post_thumbnail_url(get_the_ID(), 'thumbnail') ?: '',
					];
				}
				wp_reset_postdata();
			}
		}

		wp_send_json_success(['items' => $items]);
	}
}

/* -------------------------------------------------------------------------
 * 4) Shortcode: card grid + live search UI above
 * ------------------------------------------------------------------------- */
if ( ! function_exists('divi_custom_blog_cards_shortcode') ) {

	function divi_custom_blog_cards_shortcode( $atts ) {

		$atts = shortcode_atts(
			[
				'posts_per_page' => 12,
				'search'         => 1,  // 1 = show live search UI
				'placeholder'    => 'Search blog posts...',
				'min_chars'      => 2,  // start searching after N chars
				'debounce_ms'    => 200 // typing debounce for AJAX calls
			],
			$atts,
			'custom_blog_cards'
		);

		$posts_per_page = max(1, (int) $atts['posts_per_page']);
		$show_search    = ((int) $atts['search'] === 1);
		$placeholder    = sanitize_text_field($atts['placeholder']);
		$min_chars      = max(1, (int) $atts['min_chars']);
		$debounce_ms    = max(0, (int) $atts['debounce_ms']);

		// Figure out current pagination page
		$paged = get_query_var('paged') ? (int) get_query_var('paged') : ( get_query_var('page') ? (int) get_query_var('page') : 1 );
		if ( $paged < 1 ) $paged = 1;

		// Custom query for posts (original behavior)
		$custom_query = new WP_Query([
			'post_type'      => 'post',
			'paged'          => $paged,
			'posts_per_page' => $posts_per_page,
		]);

		// Enqueue assets only when shortcode is used
		if ( $show_search ) {
			wp_enqueue_script('custom-blog-cards-live-search');
			wp_enqueue_style('custom-blog-cards-live-search');

			wp_localize_script('custom-blog-cards-live-search', 'CUSTOM_BLOG_CARDS_LIVE_SEARCH', [
				'ajaxurl'     => admin_url('admin-ajax.php'),
				'nonce'       => wp_create_nonce('custom_blog_cards_live_search_nonce'),
				'minChars'    => $min_chars,
				'debounceMs'  => $debounce_ms,
				'maxResults'  => 5,
			]);
		}

		// Unique wrapper id (supports multiple shortcodes on one page)
		static $instance = 0;
		$instance++;
		$wrap_id = 'custom-blog-cards-' . $instance;

		ob_start();

		/* ------------------------------
		 * Live search UI (top)
		 * ------------------------------ */
		if ( $show_search ) : ?>
			<div class="custom-blog-cards-search mb-4" id="<?php echo esc_attr($wrap_id); ?>">
				<div class="position-relative">
					<input
						type="search"
						class="form-control custom-blog-cards-search-input"
						placeholder="<?php echo esc_attr($placeholder); ?>"
						aria-label="<?php echo esc_attr($placeholder); ?>"
						autocomplete="off"
					>
					<div class="custom-blog-cards-search-results list-group" style="display:none;"></div>
				</div>
				<div class="small text-muted mt-2 custom-blog-cards-search-hint">
					Start typing to see the top 5 matches (title-first).
				</div>
			</div>
		<?php endif;

		/* ------------------------------
		 * Card grid + pagination (original)
		 * ------------------------------ */
		if ( $custom_query->have_posts() ) : ?>
			<div class="row">
				<?php
				while ( $custom_query->have_posts() ) :
					$custom_query->the_post(); ?>
					<div class="col-md-4 mb-4">
						<a href="<?php the_permalink(); ?>"
						   class="card h-100 text-decoration-none text-reset">

							<?php if ( has_post_thumbnail() ) : ?>
								<img
									src="<?php echo esc_url( get_the_post_thumbnail_url( get_the_ID(), 'medium' ) ); ?>"
									class="card-img-top"
									alt="<?php the_title_attribute(); ?>"
									loading="lazy"
									decoding="async"
								>
							<?php endif; ?>

							<div class="card-body">
								<h5 class="card-title"><?php the_title(); ?></h5>

								<div class="card-meta mb-2">
									<span class="meta-date"><?php echo esc_html( get_the_date('m/d/Y') ); ?></span>
									<span class="meta-author"><?php echo esc_html( 'by ' . get_the_author() ); ?></span>
								</div>

								<p class="card-text">
									<?php echo esc_html( wp_trim_words( get_the_excerpt(), 20 ) ); ?>
								</p>
							</div>
						</a>
					</div>
				<?php endwhile; ?>
			</div>

			<?php
			// Temporarily swap in our custom query so pagination works
			global $wp_query;
			$orig_query = $wp_query;
			$wp_query   = $custom_query;

			the_posts_pagination([
				'mid_size'           => 2,
				'prev_text'          => '« Prev',
				'next_text'          => 'Next »',
				'screen_reader_text' => 'Posts navigation',
			]);

			// Restore original query object
			$wp_query = $orig_query;
			wp_reset_postdata();
			?>

		<?php else : ?>
			<p><?php esc_html_e('Sorry, no posts found.', 'your-text-domain'); ?></p>
		<?php endif;

		return ob_get_clean();
	}
}

add_shortcode('custom_blog_cards', 'divi_custom_blog_cards_shortcode');
