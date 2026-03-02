<?php
/**
 * Plugin Name:       AIntelligize
 * Plugin URI:        https://aintelligize.com/
 * Description:       Modular local SEO toolkit with schema, AI tools, bulk operations, and shortcode utilities.
 * Version: 7.7.2
 * Author:            Dave Barry
 * Author URI:        https://davebarry.io/
 * Text Domain:       aintelligize
 * Domain Path:       /languages
 */

if ( ! defined('ABSPATH') ) exit;

/** ─────────────────────────────────────────────────────────────────────────
 * Canonical constants & helpers (single source of truth)
 * ───────────────────────────────────────────────────────────────────────── */
// Keep in sync with plugin header above.
if ( ! defined('MYLS_VERSION') )     define('MYLS_VERSION','7.5.28');
if ( ! defined('MYLS_MAIN_FILE') )   define('MYLS_MAIN_FILE', __FILE__);
if ( ! defined('MYLS_PATH') )        define('MYLS_PATH', plugin_dir_path(MYLS_MAIN_FILE));
if ( ! defined('MYLS_URL') )         define('MYLS_URL',  plugins_url('', MYLS_MAIN_FILE));
if ( ! defined('MYLS_BASENAME') )    define('MYLS_BASENAME', plugin_basename(MYLS_MAIN_FILE));

/** (Optional) legacy aliases used elsewhere in the codebase */
if ( ! defined('MYLS_PLUGIN_FILE') )     define('MYLS_PLUGIN_FILE', MYLS_MAIN_FILE);
if ( ! defined('MYLS_PLUGIN_DIR') )      define('MYLS_PLUGIN_DIR', MYLS_PATH);
if ( ! defined('MYLS_PLUGIN_URL') )      define('MYLS_PLUGIN_URL', trailingslashit(MYLS_URL) . '');
if ( ! defined('MYLS_PLUGIN_BASENAME') ) define('MYLS_PLUGIN_BASENAME', MYLS_BASENAME);
if ( ! defined('MYLS_PLUGIN_VERSION') )  define('MYLS_PLUGIN_VERSION', MYLS_VERSION);

/** Debug toggles (as in your original) */
if ( ! defined('MYLS_SCHEMA_DEBUG') ) define('MYLS_SCHEMA_DEBUG', true);
if ( ! defined('MYLS_DEBUG_ORG') )    define('MYLS_DEBUG_ORG', true);
if ( ! defined('MYLS_DEBUG_LB') )     define('MYLS_DEBUG_LB', true);

/** Helpers */
if ( ! function_exists('myls_asset_url') ) {
	function myls_asset_url(string $rel): string { return trailingslashit(MYLS_URL) . ltrim($rel, '/'); }
}
if ( ! function_exists('myls_asset_path') ) {
	function myls_asset_path(string $rel): string { return trailingslashit(MYLS_PATH) . ltrim($rel, '/'); }
}
if ( ! function_exists('myls_is_our_admin_page') ) {
	function myls_is_our_admin_page(): bool { return is_admin() && isset($_GET['page']) && $_GET['page'] === 'aintelligize'; }
}

/** ─────────────────────────────────────────────────────────────────────────
 * Core + loaders
 * ───────────────────────────────────────────────────────────────────────── */
require_once MYLS_PATH . 'inc/core.php';
require_once MYLS_PATH . 'inc/prompt-loader.php';
require_once MYLS_PATH . 'inc/prompt-toolbar.php';
require_once MYLS_PATH . 'inc/admin-tabs-loader.php';
require_once trailingslashit(MYLS_PATH).'inc/sitebuilder/bootstrap.php';
require_once trailingslashit(MYLS_PATH).'inc/sitebuilder/bootstrap-appearance.php';


/** Optional shim if you still have older renderers */
if ( ! function_exists('myls_get_tabs_ordered') && function_exists('myls_get_admin_tabs') ) {
	function myls_get_tabs_ordered() { return myls_get_admin_tabs(); }
}

/** Ensure discovery runs early in admin (guarded within the loader) */
if ( function_exists('myls_load_all_admin_tabs') ) {
	add_action('admin_init', 'myls_load_all_admin_tabs', 1);
}

/** Admin renderer (uses myls_get_admin_tabs() internally) */
require_once MYLS_PATH . 'inc/admin.php';
require_once MYLS_PATH . 'admin/admin-docs-menu.php';
require_once MYLS_PATH . 'admin/admin-stats-menu.php';
require_once MYLS_PATH . 'admin/admin-search-stats-menu.php';

/** Release notes helpers (Docs → Release Notes + optional changelog queue) */
require_once MYLS_PATH . 'inc/release-notes.php';

/** Assets */
require_once MYLS_PATH . 'inc/assets.php';
require_once MYLS_PATH . 'inc/custom-css.php';
require_once MYLS_PATH . 'inc/blog-prefix.php';

/** CPT registration BEFORE module extras */
require_once MYLS_PATH . 'inc/cpt-registration.php';
require_once MYLS_PATH . 'inc/faq-schemas.php';
require_once MYLS_PATH . 'inc/service-area-city-state.php';

/** Page Builder compatibility — centralized content extraction for Elementor, DIVI, BB, WPBakery */
require_once MYLS_PATH . 'inc/page-builder-compat.php';

/** Serve /llms.txt and /llms-full.txt (AI discovery files) */
require_once MYLS_PATH . 'inc/llms-txt.php';

/** Serve /llm-info (HTML page for AI assistants) */
require_once MYLS_PATH . 'inc/llm-info.php';

/** Empty Anchor Fix — output buffer to add aria-labels to empty links (SEMRush audit) */
require_once MYLS_PATH . 'inc/empty-anchor-fix.php';

/** Native MYLS meta boxes (FAQ + City/State + Google Maps + AI FAQ Generator + Service Tagline + HTML Excerpt) */
require_once MYLS_PATH . 'inc/metaboxes/myls-faq-citystate.php';
require_once MYLS_PATH . 'inc/metaboxes/google-maps-metabox.php';
require_once MYLS_PATH . 'inc/metaboxes/ai-faq-generator.php';
require_once MYLS_PATH . 'inc/metaboxes/service-tagline.php';
require_once MYLS_PATH . 'inc/metaboxes/icon-image.php';
require_once MYLS_PATH . 'inc/metaboxes/html-excerpt.php';

/** Admin AJAX + admin bar */
require_once MYLS_PATH . 'inc/admin-ajax.php';
require_once MYLS_PATH . 'inc/admin-bar-menu.php';

/** Utilities: migration helpers + AJAX (admin-only, scoped) */
require_once MYLS_PATH . 'inc/utilities/acf-migrations.php';
require_once MYLS_PATH . 'inc/utilities/faq-editor.php';

/** CPT extras AFTER registration */
require_once MYLS_PATH . 'inc/load-cpt-modules.php';
require_once MYLS_PATH . 'inc/tools/inherit-city-state.php';

/** Search Demand DB table + CRUD */
require_once MYLS_PATH . 'inc/db/search-demand-table.php';


/** Schema */
require_once MYLS_PATH . 'inc/schema/helpers.php';
require_once MYLS_PATH . 'inc/schema/registry.php';
require_once MYLS_PATH . 'inc/schema/providers/organization.php';
require_once MYLS_PATH . 'inc/schema/providers/localbusiness.php';
require_once MYLS_PATH . 'inc/schema/providers/person.php';
require_once MYLS_PATH . 'inc/schema/providers/about-page.php';
require_once MYLS_PATH . 'inc/schema/providers/build-service-schema.php';
require_once MYLS_PATH . 'inc/schema/providers/video-archive.php';
require_once MYLS_PATH . 'inc/schema/providers/video-schema.php';
require_once MYLS_PATH . 'admin/api-integration-tests.php';
require_once MYLS_PATH . 'inc/schema/providers/video-collection-head.php';
require_once MYLS_PATH . 'inc/schema/providers/faq.php';
require_once MYLS_PATH . 'inc/schema/providers/service-faq-page.php';
require_once MYLS_PATH . 'inc/schema/providers/memberships-page.php';
require_once MYLS_PATH . 'inc/schema/providers/blog-posting.php';
require_once MYLS_PATH . 'inc/schema/localbusiness-sync.php';

/** AI plumbing (keep if files exist; otherwise comment these two lines) */
require_once MYLS_PATH . 'inc/ajax/ai.php';
require_once MYLS_PATH . 'inc/ajax/ai-about.php';

// GEO Rewrite tab endpoints
require_once MYLS_PATH . 'inc/ajax/ai-geo.php';

require_once MYLS_PATH . 'inc/ajax/ai-faqs.php';
require_once MYLS_PATH . 'inc/ajax/ai-faq-search-check.php';
// Content Quality Analyzer for enterprise logging (optional - degrades gracefully)
$_myls_ca_path = MYLS_PATH . 'inc/ai/content-analyzer.php';
if ( file_exists( $_myls_ca_path ) ) {
	require_once $_myls_ca_path;
}
// Variation Engine for AI anti-duplication (optional - degrades gracefully if missing)
$_myls_ve_path = MYLS_PATH . 'inc/ai/variation-engine.php';
if ( file_exists( $_myls_ve_path ) ) {
	require_once $_myls_ve_path;
}
require_once MYLS_PATH . 'inc/openai.php';
require_once MYLS_PATH . 'inc/class-ai-usage-logger.php';
MYLS_AI_Usage_Logger::init();

// Helper: set AI usage context before making AI calls in AJAX handlers
if ( ! function_exists('myls_ai_set_usage_context') ) {
	function myls_ai_set_usage_context( string $handler, int $post_id = 0, ?string $batch_id = null ) {
		global $myls_ai_usage_context;
		$myls_ai_usage_context = [
			'handler'  => $handler,
			'post_id'  => $post_id,
			'batch_id' => $batch_id,
		];
	}
}
require_once MYLS_PATH . 'inc/ajax/ai-excerpts.php';
require_once MYLS_PATH . 'inc/ajax/ai-html-excerpts.php';
require_once MYLS_PATH . 'inc/ajax/ai-person-linkedin.php';
require_once MYLS_PATH . 'inc/ajax/ai-taglines.php';
require_once MYLS_PATH . 'inc/ajax/ai-page-builder.php';
require_once MYLS_PATH . 'inc/elementor-site-analyzer.php';
require_once MYLS_PATH . 'inc/ajax/ai-elementor-builder.php';
require_once MYLS_PATH . 'inc/ajax/prompt-history.php';
require_once MYLS_PATH . 'inc/ajax/ai-image-gen.php';
require_once MYLS_PATH . 'inc/lib/myls-pdf.php';
require_once MYLS_PATH . 'inc/ajax/ai-content-analyzer.php';
require_once MYLS_PATH . 'inc/ajax/ai-llms-txt.php';
require_once MYLS_PATH . 'inc/pb-wpautop-fix.php';

/** Service FAQ Page generator AJAX */
require_once MYLS_PATH . 'inc/ajax/generate-service-faq-page.php';
require_once MYLS_PATH . 'inc/ajax/generate-memberships-page.php';

/** Google Maps bulk generation AJAX */
require_once MYLS_PATH . 'inc/ajax/google-maps.php';

/** Updater */
require_once MYLS_PATH . 'update-plugin.php';

/** Include non-CPT modules (skip modules/cpt) */
if ( ! function_exists('myls_include_dir_excluding') ) {
	function myls_include_dir_excluding( $dir, $exclude_dirs = array() ) {
		$dir = trailingslashit( $dir );
		if ( ! is_dir($dir) || ! is_readable($dir) ) return;
		foreach ( scandir($dir) ?: [] as $item ) {
			if ( $item === '.' || $item === '..' ) continue;
			$path = $dir . $item;
			if ( is_dir($path) ) {
				if ( in_array( $item, $exclude_dirs, true ) ) continue;
				myls_include_dir_excluding( $path, $exclude_dirs );
				continue;
			}
			if ( substr($item, -4) === '.php' ) include_once $path;
		}
	}
}
myls_include_dir_excluding( MYLS_PATH . 'modules', array('cpt') );

/** Activation/Deactivation */
register_activation_hook( __FILE__, 'myls_activate_register_cpts_and_flush' );
register_deactivation_hook( __FILE__, function(){ flush_rewrite_rules(); });

/** Plugin row “Settings” link */
add_filter('plugin_action_links_' . MYLS_BASENAME, function( $links ) {
	$url = admin_url('admin.php?page=aintelligize');
	$links[] = '<a href="' . esc_url($url) . '">'. esc_html__('Settings', 'aintelligize') .'</a>';
	return $links;
});

/** ─────────────────────────────────────────────────────────────────────────
 * Admin CSS — scoped to our page only (prevents global 404s)
 * ───────────────────────────────────────────────────────────────────────── */
add_action('admin_enqueue_scripts', function(){
	if ( ! myls_is_our_admin_page() ) return;

	$vars  = 'assets/css/variables.css';
	$utils = 'assets/css/utilities.css';
	$admin = 'assets/css/admin.css';

	// Enqueue each file that exists (don't fail all if one is missing)
	$deps = [];
	if ( file_exists( myls_asset_path($vars) ) ) {
		wp_enqueue_style('myls-vars', myls_asset_url($vars), [], MYLS_VERSION);
		$deps[] = 'myls-vars';
	}
	if ( file_exists( myls_asset_path($utils) ) ) {
		wp_enqueue_style('myls-utils', myls_asset_url($utils), $deps, MYLS_VERSION);
		$deps[] = 'myls-utils';
	}
	if ( file_exists( myls_asset_path($admin) ) ) {
		wp_enqueue_style('myls-admin-css', myls_asset_url($admin), $deps, MYLS_VERSION);
	}
});

/** Tabs CSS (also scoped to our page) */
add_action('admin_enqueue_scripts', function(){
	if ( ! myls_is_our_admin_page() ) return;
	wp_enqueue_style('myls-tabs-css', myls_asset_url('assets/css/tabs.css'), [], MYLS_VERSION);
});

/** Duplicate class hook (kept from your original) */
add_filter('myls_admin_tabs_nav_classes', function( $classes, $tabs = [], $current_id = '' ){
  return trim($classes . ' myls-tabs');
}, 10, 3);

/** Ensure dashicons + attach inline CSS only on our page. */
add_action('admin_enqueue_scripts', function(){
	if ( ! myls_is_our_admin_page() ) return;

	wp_enqueue_style('dashicons');

	$css = <<<CSS
.myls-tabs { background:#fff; padding:8px 0; border-bottom:1px solid #e5e7eb; }
.myls-tabs .nav-tab { display:inline-flex; align-items:center; gap:6px; padding:10px 14px; font-weight:600; border-radius:10px 10px 0 0; border:1px solid transparent; color:#334155; }
.myls-tabs .nav-tab .dashicons { font-size:18px; width:18px; height:18px; line-height:18px; }
.myls-tabs .nav-tab:hover { color:#0ea5e9; text-decoration:none; }
.myls-tabs .nav-tab.nav-tab-active { color:#0f172a; background:#fff; border-color:#e5e7eb #e5e7eb #fff; box-shadow:0 2px 0 0 #fff, 0 -1px 0 0 #e5e7eb, 0 -6px 14px rgba(15,23,42,.04); }
@media (max-width:782px){ .myls-tabs .nav-tab{ padding:8px 10px; gap:4px; } }
CSS;

	wp_add_inline_style('myls-admin-css', $css);
});

add_action('wp_enqueue_scripts', function() {
    // Use the centralized asset URL helper (prevents missing/double slashes).
    wp_enqueue_style('myls-accordion', myls_asset_url('assets/css/myls-accordion.css'), [], MYLS_VERSION);
});


/** Divi modules (safe: module file registers itself on et_builder_ready) */
add_action('plugins_loaded', function () {
	$divi_faq = MYLS_PATH . 'modules/divi/faq-accordion.php';
	if ( file_exists($divi_faq) ) {
		require_once $divi_faq;
	}
}, 20);


/** Meta history */
require_once MYLS_PATH . 'inc/myls-meta-history-logger.php';
require_once MYLS_PATH . 'inc/myls-meta-history-endpoints.php';

if ( is_admin() ) {
	require_once MYLS_PATH . 'admin/api-integration-tests.php';
	require_once MYLS_PATH . 'modules/meta/meta-history.php';
}

/**
 * MYLS FAQ Accordion – standalone collapse + hard stop other handlers
 * Prevents "re-opening" caused by additional click handlers.
 */
add_action('wp_enqueue_scripts', function () {

	$handle = 'myls-faq-standalone-accordion';

	if ( ! wp_script_is( $handle, 'registered' ) ) {
		wp_register_script( $handle, '', [], '1.0.1', true );
	}

	wp_enqueue_script( $handle );

	$js = <<<JS
(function () {

  function closeSiblings(root, keepPanel) {
    root.querySelectorAll('.accordion-collapse.show').forEach(function(p){
      if (p === keepPanel) return;
      p.classList.remove('show');

      var id = p.getAttribute('id');
      if (!id) return;

      var b = root.querySelector('.accordion-button[data-bs-target="#' + CSS.escape(id) + '"]');
      if (b) {
        b.classList.add('collapsed');
        b.setAttribute('aria-expanded', 'false');
      }
    });
  }

  // Disable Bootstrap data-api toggling if it exists on the page.
  function neutralizeBootstrapDataApi(root) {
    root.querySelectorAll('.accordion-button[data-bs-toggle="collapse"]').forEach(function(btn){
      btn.removeAttribute('data-bs-toggle'); // stops bootstrap delegation from acting on it
    });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.ssseo-accordion .accordion-button');
    if (!btn) return;

    // HARD STOP: prevents Elementor/other handlers from also toggling.
    e.preventDefault();
    e.stopPropagation();
    if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();

    // Prevent rapid double-click / duplicate event dispatch
    if (btn.__mylsLock) return;
    btn.__mylsLock = true;
    setTimeout(function(){ btn.__mylsLock = false; }, 50);

    var root = btn.closest('.ssseo-accordion');
    if (!root) return;

    neutralizeBootstrapDataApi(root);

    var target = btn.getAttribute('data-bs-target');
    if (!target || target.charAt(0) !== '#') return;

    var panel = root.querySelector(target);
    if (!panel) return;

    var willOpen = !panel.classList.contains('show');

    // Mimic Bootstrap accordion behavior (only one open) if data-bs-parent is present
    var parent = panel.getAttribute('data-bs-parent');
    if (parent && willOpen) {
      closeSiblings(root, panel);
    }

    panel.classList.toggle('show', willOpen);

    // Update caret state + aria state
    btn.classList.toggle('collapsed', !willOpen);
    btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');

  }, true); // capture=true is important

})();
JS;

	wp_add_inline_script( $handle, $js );
}, 50);

/**
 * Force Elementor Text Editor widget to run shortcodes.
 */
if ( ! defined('ABSPATH') ) exit;

/**
 * 1) Elementor frontend content filter (broad, but usually safe).
 */
add_filter( 'elementor/frontend/the_content', function( $content ) {
	return do_shortcode( $content );
}, 11 );

/**
 * 2) Elementor Text Editor widget-specific parse filter (preferred).
 * This is the one that specifically targets the Text Editor widget output.
 */
add_filter( 'elementor/widget/text-editor/parse_text', function( $text ) {
	return do_shortcode( $text );
}, 11 );

/**
 * 3) Divi — process shortcodes in all module output (titles, text, etc.).
 *    Uses a recursion guard to prevent infinite loops since Divi modules
 *    are themselves shortcodes and do_shortcode() would re-trigger this filter.
 *    Divi sometimes passes an array (e.g. contact form items), so skip those.
 */
add_filter( 'et_module_shortcode_output', function( $output ) {
	if ( ! is_string( $output ) ) return $output;
	static $running = false;
	if ( $running ) return $output;
	$running = true;
	$output  = do_shortcode( $output );
	$running = false;
	return $output;
}, 10 );
