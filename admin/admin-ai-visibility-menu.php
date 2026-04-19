<?php
/**
 * AIntelligize — AI Visibility Submenu
 * Path: admin/admin-ai-visibility-menu.php
 *
 * Adds a dedicated "AI Visibility" submenu under AIntelligize with three
 * subtabs (Crawlers / Referrers / Google Search), query-arg driven.
 * AJAX handlers live in admin/tabs/ai-visibility/ajax.php.
 *
 * @since 7.9.18.107
 */

if ( ! defined('ABSPATH') ) exit;

define( 'MYLS_AIV_PAGE_SLUG',    'myls-ai-visibility' );
define( 'MYLS_AIV_NONCE_ACTION', 'myls_aiv' );

/* -------------------------------------------------------------------------
 * 1. Submenu registration
 * ------------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
	add_submenu_page(
		'aintelligize',
		'AI Visibility — Crawlers, Referrers, Google Search',
		'AI Visibility',
		'manage_options',
		MYLS_AIV_PAGE_SLUG,
		'myls_aiv_render_page'
	);
}, 27 );

/* -------------------------------------------------------------------------
 * 2. Enqueue assets on this page only
 * ------------------------------------------------------------------------- */

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( $hook !== 'aintelligize_page_' . MYLS_AIV_PAGE_SLUG ) return;

	$ver = defined('MYLS_VERSION') ? MYLS_VERSION : time();

	// Bootstrap + Bootstrap Icons (already loaded by inc/assets.php on the
	// main aintelligize page, but this is a distinct admin screen — ensure
	// they're present).
	wp_enqueue_style( 'myls-bootstrap',       'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css', [], '5.3.3' );
	wp_enqueue_style( 'myls-bootstrap-icons', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css', [], '1.11.3' );
	wp_enqueue_script('myls-bootstrap-bundle','https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js', [], '5.3.3', true );

	// Plugin admin CSS (contains .myls-aiv-* styles).
	wp_enqueue_style(
		'myls-admin-css',
		trailingslashit(MYLS_URL) . 'assets/css/admin.css',
		[ 'myls-bootstrap' ],
		$ver
	);

	// Chart.js from CDN — used only on this page.
	wp_enqueue_script( 'myls-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', [], '4.4.1', true );

	// Page JS.
	wp_enqueue_script(
		'myls-ai-visibility',
		trailingslashit(MYLS_URL) . 'assets/js/myls-ai-visibility.js',
		[ 'myls-chartjs' ],
		$ver,
		true
	);

	wp_localize_script( 'myls-ai-visibility', 'MYLS_AIV', [
		'ajaxurl'       => admin_url('admin-ajax.php'),
		'nonce'         => wp_create_nonce( MYLS_AIV_NONCE_ACTION ),
		'gsc_connected' => function_exists('myls_gsc_is_connected') ? myls_gsc_is_connected() : false,
		'api_tab_url'   => admin_url('admin.php?page=aintelligize&tab=api-integration'),
	] );
}, 20 );

/* -------------------------------------------------------------------------
 * 3. Page renderer — subtab router
 * ------------------------------------------------------------------------- */

function myls_aiv_render_page() : void {
	if ( ! current_user_can('manage_options') ) wp_die('Forbidden.');

	$dir = trailingslashit( MYLS_PATH ) . 'admin/tabs/ai-visibility';
	$subtabs = [];

	if ( is_dir($dir) ) {
		$files = glob( $dir . '/subtab-*.php' );
		if ( $files ) {
			natsort($files);
			foreach ( $files as $file ) {
				$spec = include $file;
				if ( is_array($spec) && ! empty($spec['id']) && ! empty($spec['label']) && ! empty($spec['render']) ) {
					$subtabs[ $spec['id'] ] = $spec;
				}
			}
		}
	}

	if ( empty($subtabs) ) {
		echo '<div class="wrap"><div class="notice notice-warning"><p>No AI Visibility subtabs found.</p></div></div>';
		return;
	}

	uasort( $subtabs, function ( $a, $b ) {
		$ao = $a['order'] ?? 50;
		$bo = $b['order'] ?? 50;
		if ( $ao === $bo ) return strcasecmp( $a['label'], $b['label'] );
		return $ao <=> $bo;
	} );

	$active = isset($_GET['sub']) ? sanitize_key($_GET['sub']) : '';
	if ( ! isset($subtabs[$active]) ) {
		$keys   = array_keys($subtabs);
		$active = reset($keys);
	}

	// Save-settings handler (per-subtab on_save callback).
	if (
		isset($_POST['myls_aiv_nonce']) &&
		wp_verify_nonce( $_POST['myls_aiv_nonce'], MYLS_AIV_NONCE_ACTION ) &&
		current_user_can('manage_options')
	) {
		if ( ! empty($_POST['myls_aiv_active_sub']) ) {
			$post_sub = sanitize_key($_POST['myls_aiv_active_sub']);
			if ( isset($subtabs[$post_sub]) ) $active = $post_sub;
		}
		if ( isset($subtabs[$active]['on_save']) && is_callable($subtabs[$active]['on_save']) ) {
			call_user_func( $subtabs[$active]['on_save'] );
			echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
		}
	}

	$base_url = admin_url('admin.php?page=' . MYLS_AIV_PAGE_SLUG);
	?>
	<div class="wrap myls-aiv-wrap">
		<h1 class="myls-aiv-title"><i class="bi bi-bar-chart-line"></i> AI Visibility</h1>
		<p class="myls-aiv-sub">Crawlers reading the site, AI-chatbot referrals, and Google Search visibility.</p>

		<ul class="myls-aiv-nav">
			<?php foreach ( $subtabs as $id => $spec ) :
				$label = $spec['label'];
				$icon  = $spec['icon']  ?? '';
				$url   = esc_url( add_query_arg( [ 'sub' => $id ], $base_url ) );
				$cls   = ( $id === $active ) ? 'active' : '';
			?>
			<li class="nav-item">
				<a class="nav-link <?php echo esc_attr($cls); ?>" href="<?php echo $url; ?>">
					<?php if ( $icon ) : ?><i class="<?php echo esc_attr($icon); ?>"></i> <?php endif; ?>
					<?php echo esc_html($label); ?>
				</a>
			</li>
			<?php endforeach; ?>
		</ul>

		<div class="myls-aiv-body">
			<?php call_user_func( $subtabs[$active]['render'] ); ?>
		</div>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * 4. Wire in AJAX + subtab registration
 * ------------------------------------------------------------------------- */

require_once trailingslashit( MYLS_PATH ) . 'admin/tabs/ai-visibility/ajax.php';
