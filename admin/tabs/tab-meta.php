<?php
/**
 * Admin Tab: Meta Tags
 * Path: admin/tabs/tab-meta.php
 *
 * - Registers the "Meta" admin tab
 * - Auto-discovers subtabs from admin/tabs/meta/subtab-*.php
 * - Enqueues Bootstrap Icons + meta-specific JS (myls-meta.js)
 * - Declares shared nonce + helpers for subtabs
 */

if ( ! defined('ABSPATH') ) exit;

/* -------------------------------------------------------
 * Enqueue assets only on our settings page
 * ------------------------------------------------------- */
add_action('admin_enqueue_scripts', function($hook) {
	if ( empty($_GET['page']) || $_GET['page'] !== 'aintelligize' ) return;

	// Bootstrap Icons
	wp_enqueue_style(
		'myls-bootstrap-icons',
		'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css',
		[],
		'1.11.3'
	);

	// JS bundle for Meta tab
	wp_enqueue_script(
		'myls-meta-js',
		plugins_url('../../assets/js/myls-meta.js', __FILE__),
		['jquery'],
		'1.0.0',
		true
	);

	// Localize nonce for AJAX calls
	wp_localize_script('myls-meta-js', 'MYLS_META', [
		'ajaxurl' => admin_url('admin-ajax.php'),
		'nonce'   => wp_create_nonce('myls_meta_ops')
	]);
});

/* -------------------------------------------------------
 * Register "Meta" tab
 * ------------------------------------------------------- */
myls_register_admin_tab([
	'id'    => 'meta',
	'title' => 'Meta Tags',
	'order' => 40,
	'cap'   => 'manage_options',
	'icon'  => 'dashicons-tag',
	'cb'    => function() {
		echo '<div class="wrap myls-meta-tab">';
		echo '<h1><span class="bi bi-tags"></span> Meta Tag Tools</h1>';

		// Discover subtabs
		$subtabs = [];
		foreach ( glob( plugin_dir_path(__FILE__) . 'meta/subtab-*.php' ) as $file ) {
			$spec = include $file;
			if ( is_array($spec) && isset($spec['id']) ) {
				$subtabs[ $spec['id'] ] = $spec;
			}
		}

		// Sort by label
		uasort($subtabs, function($a, $b){
			return strcasecmp($a['label'], $b['label']);
		});

		// Render nav
		echo '<nav class="nav-tab-wrapper">';
		$active = $_GET['subtab'] ?? key($subtabs);
		foreach ($subtabs as $id => $spec) {
			$url = admin_url('admin.php?page=aintelligize&tab=meta&subtab='.$id);
			$cls = ($id === $active) ? 'nav-tab nav-tab-active' : 'nav-tab';
			echo '<a href="'.esc_url($url).'" class="'.$cls.'">'.esc_html($spec['label']).'</a>';
		}
		echo '</nav>';

		// Render content
		if ($active && isset($subtabs[$active])) {
			echo '<div class="myls-subtab-content">';
			call_user_func($subtabs[$active]['render']);
			echo '</div>';
		}

		echo '</div>'; // .wrap
	}
]);
