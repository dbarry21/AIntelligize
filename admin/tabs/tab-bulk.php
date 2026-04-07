<?php
/**
 * Admin Tab: Bulk
 * Path: admin/tabs/tab-bulk.php
 *
 * - Registers the "Bulk" admin tab via myls_register_admin_tab([... 'cb' => function() { ... }])
 * - Auto-discovers subtabs from admin/tabs/bulk/subtab-*.php (each returns an array $spec)
 * - Enqueues Bootstrap Icons + JS bundle (bulk, yoast, copy-service-areas, google-maps)
 * - Declares helpers for Service Area lists/trees, Yoast post lists, and nonce
 * - Provides AJAX endpoints used by subtabs
 */

if ( ! defined('ABSPATH') ) exit;

/* -------------------------------------------------------------------------
 * Small helpers (declare BEFORE use)
 * ------------------------------------------------------------------------- */

/** Resolve the Service Area post type key (fallback to 'service_area') */
if ( ! function_exists('myls_sa_post_type') ) {
	function myls_sa_post_type() {
		$pt = get_option('myls_cpt_service_area');              // preferred
		if ( ! $pt ) $pt = get_option('ssseo_cpt_service_area'); // legacy fallback
		$pt = $pt ?: 'service_area';
		return post_type_exists($pt) ? $pt : 'service_area';
	}
}

/** One nonce for all Bulk operations during the request */
if ( ! function_exists('myls_bulk_get_nonce') ) {
	function myls_bulk_get_nonce() {
		static $nonce = null;
		if ( $nonce === null ) $nonce = wp_create_nonce('myls_bulk_ops');
		return $nonce;
	}
}

/** Flat list of published Service Areas (ids) */
if ( ! function_exists('myls_sa_get_all_published_ids') ) {
	function myls_sa_get_all_published_ids() {
		$sa_pt = myls_sa_post_type();
		$ids = get_posts(array(
			'post_type'        => $sa_pt,
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'fields'           => 'ids',
			'suppress_filters' => true,
			'no_found_rows'    => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		));
		return array_map('intval', $ids);
	}
}

/** Tree list for hierarchical select: each row = [id, title, depth] */
if ( ! function_exists('myls_sa_get_tree_items') ) {
	function myls_sa_get_tree_items() {
		$sa_pt = myls_sa_post_type();
		$posts = get_posts(array(
			'post_type'        => $sa_pt,
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'orderby'          => array('menu_order' => 'ASC', 'title' => 'ASC'),
			'order'            => 'ASC',
			'suppress_filters' => true,
			'no_found_rows'    => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		));

		$by_id = array();
		foreach ( $posts as $p ) {
			$by_id[$p->ID] = array(
				'id'     => (int) $p->ID,
				'title'  => get_the_title($p) ?: '(no title)',
				'parent' => (int) $p->post_parent,
				'kids'   => array(),
			);
		}
		foreach ( $by_id as $id => &$node ) {
			if ( $node['parent'] && isset($by_id[$node['parent']]) ) {
				$by_id[$node['parent']]['kids'][] = &$node;
			}
		}
		unset($node);

		$roots = array();
		foreach ( $by_id as $id => $node ) {
			if ( empty($node['parent']) || ! isset($by_id[$node['parent']]) ) {
				$roots[] = $id;
			}
		}

		$out = array();
		$dfs = function( $id, $depth ) use ( &$by_id, &$out, &$dfs ) {
			$node  = $by_id[$id];
			$out[] = array(
				'id'    => $node['id'],
				'title' => $node['title'],
				'depth' => max(0, (int) $depth),
			);
			if ( ! empty($node['kids']) ) {
				usort($node['kids'], function($a,$b){
					return strcasecmp($a['title'], $b['title']);
				});
				foreach ( $node['kids'] as $child ) {
					$dfs($child['id'], $depth + 1);
				}
			}
		};
		foreach ( $roots as $rid ) $dfs($rid, 0);

		return $out;
	}
}

/** All published posts grouped by public post type (for Yoast subtab) */
if ( ! function_exists('myls_collect_posts_by_type') ) {
	function myls_collect_posts_by_type() {
		$out = array();
		$pts = get_post_types( array( 'public' => true ), 'objects' );
		unset($pts['attachment']); // exclude media

		foreach ( $pts as $pt ) {
			$items = array();
			$posts = get_posts(array(
				'post_type'        => $pt->name,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'no_found_rows'    => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'fields'           => 'ids',
			));
			foreach ( $posts as $pid ) {
				$items[] = array(
					'id'    => (int) $pid,
					'title' => get_the_title($pid) ?: '(no title)',
				);
			}
			$out[ $pt->name ] = $items;
		}
		ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
		return $out;
	}
}

/* -------------------------------------------------------------------------
 * Assets (Bootstrap Icons + Bulk JS)
 * ------------------------------------------------------------------------- */
add_action('admin_enqueue_scripts', function( $hook ){
	if ( empty($_GET['page']) || $_GET['page'] !== 'aintelligize' ) return;

	// Icons
	wp_enqueue_style(
		'myls-bootstrap-icons',
		'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css',
		array(),
		'1.11.3'
	);

	$ver = defined('MYLS_PLUGIN_VERSION') ? MYLS_PLUGIN_VERSION : '1.0.0';

	// Register scripts first so we can extend MYLS before enqueue
	wp_register_script(
		'myls-bulk',
		MYLS_PLUGIN_URL . 'assets/js/myls-bulk.js',
		array('jquery'),
		$ver,
		true
	);

	wp_register_script(
		'myls-yoast',
		MYLS_PLUGIN_URL . 'assets/js/myls-yoast.js',
		array('jquery','myls-bulk'),
		$ver,
		true
	);

	wp_register_script(
		'myls-copy-service-areas',
		MYLS_PLUGIN_URL . 'assets/js/myls-copy-service-areas.js',
		array('jquery','myls-bulk'),
		$ver,
		true
	);

	// NEW: Google Maps subtab JS
	wp_register_script(
		'myls-google-maps',
		MYLS_PLUGIN_URL . 'assets/js/myls-google-maps.js',
		array('jquery','myls-bulk'),
		$ver,
		true
	);

	// Import / Export subtab JS
	wp_register_script(
		'myls-import-export',
		MYLS_PLUGIN_URL . 'assets/js/myls-import-export.js',
		array('jquery','myls-bulk'),
		$ver,
		true
	);

	// Base MYLS globals (ajaxurl + nonce + basic screen context), non-destructive
	$base = array(
		'ajaxurl'   => admin_url('admin-ajax.php'),
		'bulkNonce' => myls_bulk_get_nonce(),
		'page'      => isset($_GET['page']) ? sanitize_key($_GET['page']) : '',
		'tab'       => isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '',
		'subtab'    => isset($_GET['subtab']) ? sanitize_key($_GET['subtab']) : '',
	);
	wp_add_inline_script(
		'myls-bulk',
		'window.MYLS = Object.assign(window.MYLS||{}, '. wp_json_encode($base) .');',
		'before'
	);

	// Enqueue base
	wp_enqueue_script('myls-bulk');

	// Conditionally enqueue subtabs
	$subtab = isset($_GET['subtab']) ? sanitize_key($_GET['subtab']) : '';

	if ( $subtab === 'yoast' ) {
		wp_enqueue_script('myls-yoast');
	}

	if ( $subtab === 'clone-service-areas' ) {
		wp_enqueue_script('myls-copy-service-areas');
	}

	if ( $subtab === 'googlemaps' ) {
		// Preload service areas so the post list populates even if AJAX is blocked
		$items = array();
		$sa_pt = myls_sa_post_type();
		$posts = get_posts(array(
			'post_type'        => $sa_pt,
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'fields'           => 'ids',
			'suppress_filters' => false,
		));
		foreach ( $posts as $pid ) {
			$items[] = array(
				'id'    => (int) $pid,
				'title' => get_the_title($pid),
			);
		}

		// Inject into window.MYLS before the maps script runs
		wp_add_inline_script(
			'myls-google-maps',
			'window.MYLS = Object.assign(window.MYLS||{}, { gmapsItems: '. wp_json_encode($items) .' });',
			'before'
		);

		wp_enqueue_script('myls-google-maps');
	}

	if ( $subtab === 'import-export' ) {
		wp_enqueue_script('myls-import-export');
	}
});

/** Footer safety: ensure MYLS.bulkNonce is available (in case other plugins interfere) */
add_action('admin_print_footer_scripts', function(){
	if ( empty($_GET['page']) || $_GET['page'] !== 'aintelligize' ) return;
	printf(
		'<script>window.MYLS = window.MYLS || {}; if (!MYLS.bulkNonce) MYLS.bulkNonce = %s;</script>',
		wp_json_encode( myls_bulk_get_nonce() )
	);
}, 5);

/* -------------------------------------------------------------------------
 * AJAX endpoints
 * ------------------------------------------------------------------------- */

/**
 * Return all published Service Areas for the Maps subtab.
 * Response shape:
 *   { success:true, data:{ items:[ {id, title}, ... ] } }
 */
if ( ! function_exists('myls_sa_all_published') ) {
	add_action('wp_ajax_myls_sa_all_published', 'myls_sa_all_published');
	function myls_sa_all_published() {
		if (
			empty($_POST['nonce']) ||
			! wp_verify_nonce($_POST['nonce'], 'myls_bulk_ops') ||
			! current_user_can('manage_options')
		) {
			wp_send_json_error( array('message' => 'Unauthorized'), 403 );
		}

		$sa_pt = myls_sa_post_type();
		$posts = get_posts(array(
			'post_type'        => $sa_pt,
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'fields'           => 'ids',
			'suppress_filters' => true,
			'no_found_rows'    => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		));

		$items = array();
		foreach ( $posts as $pid ) {
			$items[] = array(
				'id'    => (int) $pid,
				'title' => get_the_title($pid),
			);
		}

		wp_send_json_success( array( 'items' => $items ) );
	}
}

// Clone Service Areas AJAX (existing)
require_once MYLS_PLUGIN_DIR . 'admin/tabs/bulk/_clone-service-areas-ajax.php';

// Yoast Bulk Operations AJAX (if present)
$yoast_ajax = MYLS_PLUGIN_DIR . 'admin/tabs/bulk/_yoast-ajax.php';
if ( file_exists( $yoast_ajax ) ) {
	require_once $yoast_ajax;
}

// Import / Export AJAX
$ie_ajax = MYLS_PLUGIN_DIR . 'admin/tabs/bulk/_import-export-ajax.php';
if ( file_exists( $ie_ajax ) ) {
	require_once $ie_ajax;
}

/* -------------------------------------------------------------------------
 * Register the Bulk tab (Schema-style)
 * ------------------------------------------------------------------------- */
myls_register_admin_tab([
	'id'    => 'bulk',
	'title' => 'Bulk',
	'order' => 30,
	'cap'   => 'manage_options',
	'icon'  => 'dashicons-screenoptions',
	'cb'    => function () {

		// Discover subtabs
		$subtabs = array();
		$pattern = trailingslashit(__DIR__) . 'bulk/subtab-*.php';
		foreach ( glob($pattern) as $file ) {
			$spec = include $file;
			if ( is_array($spec) && ! empty($spec['id']) && ! empty($spec['render']) ) {
				$spec['order'] = isset($spec['order']) ? (int)$spec['order'] : 10;
				$subtabs[$spec['id']] = $spec;
			}
		}

		// Sort by order asc, then label asc
		uasort($subtabs, function($a,$b){
			$ao = (int)($a['order'] ?? 10);
			$bo = (int)($b['order'] ?? 10);
			if ( $ao === $bo ) {
				return strcasecmp($a['label'] ?? '', $b['label'] ?? '');
			}
			return ($ao < $bo) ? -1 : 1;
		});

		$active = isset($_GET['subtab']) ? sanitize_key($_GET['subtab']) : (array_key_first($subtabs) ?: '');

		// Wrapper + Nav
		echo '<div class="wrap myls-wrap">';
		echo '<h1 class="wp-heading-inline">Bulk</h1>';
		echo '<hr class="wp-header-end">';

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $subtabs as $id => $spec ) {
			$url = add_query_arg([
				'page'   => 'aintelligize',
				'tab'    => 'bulk',
				'subtab' => $id,
			], admin_url('admin.php'));
			printf(
				'<a href="%s" class="nav-tab %s">%s</a>',
				esc_url($url),
				($id === $active ? 'nav-tab-active' : ''),
				esc_html($spec['label'] ?? ucfirst(str_replace('-', ' ', $id)))
			);
		}
		echo '</h2>';

		// Per-subtab context (optional data to pass into render callback)
		$ctx = array(
			'bulk_nonce' => myls_bulk_get_nonce(),
		);

		if ( $active === 'clone-service-areas' ) {
			$ctx['all_service_areas'] = myls_sa_get_all_published_ids();
			$ctx['target_tree_items'] = myls_sa_get_tree_items();
		}

		if ( $active === 'yoast' ) {
			$ctx['posts_by_type'] = myls_collect_posts_by_type();
		}

		// Render active subtab
		if ( isset($subtabs[$active]) ) {
			call_user_func( $subtabs[$active]['render'], $ctx );
		} else {
			echo '<div class="notice notice-warning"><p>No subtab found.</p></div>';
		}

		echo '</div>'; // .wrap
	}
]);
