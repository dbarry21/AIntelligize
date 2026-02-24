<?php
/**
 * Admin Tab: AI
 * Path: admin/tabs/tab-ai.php
 *
 * - Registers the "AI" admin tab for AIntelligize
 * - Auto-discovers subtabs from admin/tabs/ai/subtab-*.php
 * - Enqueues AI-specific JS and localized data
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Helper: return an array of posts grouped by post type for preload
 */
if ( ! function_exists('myls_ai_get_posts_by_type') ) {
	function myls_ai_get_posts_by_type( array $types = [] , int $limit_per_type = 500 ) : array {
		$out = [];

		if (empty($types)) {
			$objs  = get_post_types( ['public' => true], 'objects' );
			foreach ($objs as $k => $o) {
				if ($k === 'attachment') continue;
				$types[] = $k;
			}
		}

		foreach ($types as $pt) {
			$posts = get_posts([
				'post_type'       => $pt,
				'post_status'     => ['publish','draft','pending','future','private'],
				'posts_per_page'  => $limit_per_type,
				'orderby'         => 'title',
				'order'           => 'ASC',
				'suppress_filters'=> true,
				'fields'          => 'ids',
			]);

			$list = [];
			foreach ($posts as $pid) {
				$title = get_the_title($pid);
				if ($title === '') { $title = '(no title)'; }
				$list[] = ['id' => (int)$pid, 'title' => $title];
			}
			$out[$pt] = $list;
		}
		return $out;
	}
}

/**
 * Utility: base plugin URL (no guessing)
 * Prefer constants if your main plugin defines them; else compute safely here.
 */
if ( ! function_exists('myls_plugin_base_url') ) {
	function myls_plugin_base_url() : string {
		// If your main plugin defines MYLS_MAIN_FILE or MYLS_URL, use them.
		if ( defined('MYLS_URL') ) return rtrim(MYLS_URL, '/');
		if ( defined('MYLS_MAIN_FILE') && MYLS_MAIN_FILE && file_exists(MYLS_MAIN_FILE) ) {
			return rtrim( plugins_url('', MYLS_MAIN_FILE), '/' );
		}
		// Best effort: assume root file is aintelligize.php in plugin dir
		$maybe_root = dirname(__DIR__, 2) . '/aintelligize.php';
		if ( file_exists($maybe_root) ) {
			return rtrim( plugins_url('', $maybe_root), '/' );
		}
		// Fallback to this file (WordPress still resolves plugin root correctly)
		return rtrim( plugins_url('', __FILE__), '/' );
	}
}

/**
 * Bootstrap Icons are now loaded globally via inc/assets.php
 * (Removed duplicate loading from here to avoid conflicts)
 */

/**
 * Register the "AI" tab
 */
myls_register_admin_tab([
	'id'    => 'ai',
	'title' => 'AI',
	'order' => 40,
	'cap'   => 'manage_options',
	'icon'  => 'bi-robot',
	'cb'    => function () {
		$subtabs = [];
		foreach (glob(__DIR__ . '/ai/subtab-*.php') as $file) {
			$spec = include $file;
			if (is_array($spec) && isset($spec['id'])) {
				$subtabs[$spec['id']] = $spec;
			}
		}
		usort($subtabs, function($a, $b){ return ($a['order'] ?? 0) <=> ($b['order'] ?? 0); });
		myls_render_subtabs('ai', $subtabs);
	}
]);

/**
 * Enqueue JS for the AI tab (robust URL + guaranteed localization)
 */
add_action('admin_enqueue_scripts', function($hook){
	if ( empty($_GET['page']) || $_GET['page'] !== 'aintelligize' ) return;

	$base_url = myls_plugin_base_url(); // e.g., https://site/wp-content/plugins/aintelligize
	// Cache-busting: use filemtime when possible so edits propagate immediately.
	$ai_js_path = ( defined('MYLS_PATH') ? MYLS_PATH : dirname(__DIR__, 2) . '/' ) . 'assets/js/myls-ai.js';
	$ver = file_exists($ai_js_path) ? (string) filemtime($ai_js_path) : ( defined('MYLS_VERSION') ? MYLS_VERSION : (string) time() );

	// Script URL will be like: {base}/assets/js/myls-ai.js
	wp_enqueue_script(
		'myls-ai',
		$base_url . '/assets/js/myls-ai.js',
		['jquery'],
		$ver,
		true
	);

	// Localize data for the JS
	$posts_boot = function_exists('myls_ai_get_posts_by_type') ? myls_ai_get_posts_by_type() : [];

	wp_localize_script('myls-ai', 'MYLS_AI', [
		'ajaxurl'        => admin_url('admin-ajax.php'),
		'nonce'          => wp_create_nonce('myls_ai_ops'),
		'posts_by_type'  => $posts_boot,
		'default_type'   => 'page',
	]);
});

// Load AI AJAX endpoints early & reliably (admin + AJAX)
add_action('plugins_loaded', function(){
	$path = dirname(__DIR__) . '/ajax/ai-endpoints.php';
	if ( file_exists($path) ) require_once $path;
});
add_action('admin_init', function(){
	$path = dirname(__DIR__) . '/ajax/ai-endpoints.php';
	if ( file_exists($path) ) require_once $path;
});
