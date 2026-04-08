<?php
/**
 * Plugin Name:       AIntelligize
 * Plugin URI:        https://aintelligize.com/
 * Description:       Modular local SEO toolkit with schema, AI tools, bulk operations, and shortcode utilities.
 * Version: 7.9.18.74
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
if ( ! defined('MYLS_VERSION') )     define('MYLS_VERSION','7.9.18.74');
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

/** Debug toggles — default off in production; override in wp-config.php to enable */
if ( ! defined('MYLS_SCHEMA_DEBUG') ) define('MYLS_SCHEMA_DEBUG', false);
if ( ! defined('MYLS_DEBUG_ORG') )    define('MYLS_DEBUG_ORG', false);
if ( ! defined('MYLS_DEBUG_LB') )     define('MYLS_DEBUG_LB', false);

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
require_once MYLS_PATH . 'admin/admin-video-transcripts-menu.php';

/** Release notes helpers (Docs → Release Notes + optional changelog queue) */
require_once MYLS_PATH . 'inc/release-notes.php';

/** Assets */
require_once MYLS_PATH . 'inc/assets.php';
require_once MYLS_PATH . 'inc/custom-css.php';

/** Cookie Consent Module */
require_once MYLS_PATH . 'modules/cookie-consent/cookie-consent.php';
require_once MYLS_PATH . 'inc/blog-prefix.php';

/** CPT registration BEFORE module extras */
require_once MYLS_PATH . 'inc/cpt-registration.php';
require_once MYLS_PATH . 'inc/faq-schemas.php';
require_once MYLS_PATH . 'inc/city-state.php';
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
require_once MYLS_PATH . 'inc/metaboxes/page-video-url.php';

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

/** Video Transcripts DB table + CRUD */
require_once MYLS_PATH . 'inc/db/video-transcripts-table.php';


/** Schema */
require_once MYLS_PATH . 'inc/schema/helpers.php';
require_once MYLS_PATH . 'inc/schema/registry.php';
require_once MYLS_PATH . 'inc/schema/providers/website.php';
require_once MYLS_PATH . 'inc/schema/providers/organization.php';
require_once MYLS_PATH . 'inc/schema/providers/localbusiness.php';
require_once MYLS_PATH . 'inc/schema/providers/person.php';
require_once MYLS_PATH . 'inc/schema/providers/webpage.php';
require_once MYLS_PATH . 'inc/schema/providers/about-page.php';
require_once MYLS_PATH . 'inc/schema/providers/build-service-schema.php';
require_once MYLS_PATH . 'inc/schema/providers/video-archive.php';
require_once MYLS_PATH . 'inc/schema/providers/video-schema.php';
require_once MYLS_PATH . 'inc/schema/providers/video-object-detector.php';
require_once MYLS_PATH . 'inc/schema/providers/video-collection-head.php';
require_once MYLS_PATH . 'inc/schema/providers/faq.php';
require_once MYLS_PATH . 'inc/schema/providers/service-faq-page.php';
require_once MYLS_PATH . 'inc/schema/providers/memberships-page.php';
require_once MYLS_PATH . 'inc/schema/providers/blog-posting.php';
require_once MYLS_PATH . 'inc/schema/providers/breadcrumb.php';
require_once MYLS_PATH . 'inc/schema/providers/itemlist.php';
require_once MYLS_PATH . 'inc/schema/providers/service-area-service.php';
require_once MYLS_PATH . 'inc/schema/providers/howto.php';
require_once MYLS_PATH . 'inc/schema/localbusiness-sync.php';

/** GEO/AI infrastructure */
require_once MYLS_PATH . 'inc/robots-txt-ai.php';
require_once MYLS_PATH . 'inc/ai-referral-tracker.php';

/** Video transcript frontend accordion (single video CPT pages) */
require_once MYLS_PATH . 'inc/video-transcript-frontend.php';

/** Meta description post-processor (validates AI output before Yoast save) */
require_once MYLS_PATH . 'inc/class-myls-meta-postprocessor.php';

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
require_once MYLS_PATH . 'inc/ajax/ai-linkedin-proxy.php';
require_once MYLS_PATH . 'inc/ajax/ai-taglines.php';
require_once MYLS_PATH . 'inc/ajax/ai-page-builder.php';
require_once MYLS_PATH . 'inc/elementor-site-analyzer.php';
require_once MYLS_PATH . 'inc/ajax/ai-elementor-builder.php';
require_once MYLS_PATH . 'inc/ajax/prompt-history.php';
require_once MYLS_PATH . 'inc/ajax/ai-image-gen.php';
require_once MYLS_PATH . 'inc/lib/myls-pdf.php';
require_once MYLS_PATH . 'inc/ajax/ai-content-analyzer.php';
require_once MYLS_PATH . 'inc/ajax/ai-howto.php';
require_once MYLS_PATH . 'inc/ajax/ai-llms-txt.php';
require_once MYLS_PATH . 'inc/pb-wpautop-fix.php';

/** Service FAQ Page generator AJAX */
require_once MYLS_PATH . 'inc/ajax/generate-service-faq-page.php';
require_once MYLS_PATH . 'inc/ajax/generate-memberships-page.php';

/** Google Maps bulk generation AJAX */
require_once MYLS_PATH . 'inc/ajax/google-maps.php';

/** YouTube transcript fetch AJAX */
require_once MYLS_PATH . 'inc/ajax/fetch-youtube-transcript.php';

/** Video transcript cache AJAX */
require_once MYLS_PATH . 'inc/ajax/video-transcript-cache.php';

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
register_activation_hook( __FILE__, function() {
	if ( ! wp_next_scheduled('myls_refresh_places_rating') ) {
		wp_schedule_event( time(), 'myls_every_4_hours', 'myls_refresh_places_rating' );
	}
} );
register_deactivation_hook( __FILE__, function() {
	wp_clear_scheduled_hook('myls_refresh_places_rating');
	wp_clear_scheduled_hook('myls_ytvb_auto_generate');
} );

/** Custom cron intervals */
add_filter( 'cron_schedules', function( $schedules ) {
	$schedules['myls_every_4_hours'] = [
		'interval' => 4 * HOUR_IN_SECONDS,
		'display'  => 'Every 4 Hours (AIntelligize)',
	];
	$schedules['myls_every_12_hours'] = [
		'interval' => 12 * HOUR_IN_SECONDS,
		'display'  => 'Every 12 Hours (AIntelligize YT Video Blog)',
	];
	return $schedules;
} );

/** Cron callback: silently refresh Places rating + review count */
add_action( 'myls_refresh_places_rating', function() {
	$key = (string) get_option('myls_google_places_api_key', '');
	$pid = (string) get_option('myls_google_places_place_id', '');
	if ( $key === '' || $pid === '' ) return;

	$url = add_query_arg( [
		'place_id' => $pid,
		'fields'   => 'name,rating,user_ratings_total',
		'key'      => $key,
	], 'https://maps.googleapis.com/maps/api/place/details/json' );

	$r = wp_remote_get( $url, [ 'timeout' => 15 ] );
	if ( is_wp_error($r) ) return;

	$body   = json_decode( wp_remote_retrieve_body($r), true );
	$status = $body['status'] ?? '';
	if ( $status !== 'OK' ) return;

	$rating = (string) ( $body['result']['rating']             ?? '' );
	$count  = (string) ( $body['result']['user_ratings_total'] ?? '' );
	if ( $rating === '' || $count === '' ) return;

	update_option( 'myls_google_places_rating',        $rating );
	update_option( 'myls_google_places_review_count',  $count  ); // backward compat
	update_option( 'myls_google_places_rating_count',  $count  ); // schema.org ratingCount (= user_ratings_total)
	update_option( 'myls_places_rating_fetched_at',    current_time('mysql') );
} );

/** Self-healing: ensure cron is scheduled on every request */
add_action( 'init', function() {
	if ( ! wp_next_scheduled('myls_refresh_places_rating') ) {
		wp_schedule_event( time(), 'myls_every_4_hours', 'myls_refresh_places_rating' );
	}
} );

/** Cron callback: auto-generate YouTube video blog posts */
add_action( 'myls_ytvb_auto_generate', function() {
	if ( get_option('myls_ytvb_enabled', '0') !== '1' ) return;
	if ( get_option('myls_ytvb_auto_refresh', '0') !== '1' ) return;

	$overwrite = get_option('myls_ytvb_overwrite', '0') === '1';

	if ( class_exists('MYLS_Youtube') && method_exists('MYLS_Youtube', 'generate_cron') ) {
		MYLS_Youtube::generate_cron( null, 0, $overwrite );
	}
} );

/** Self-healing: ensure YTVB cron matches auto-refresh setting */
add_action( 'init', function() {
	$auto      = get_option('myls_ytvb_auto_refresh', '0') === '1';
	$scheduled = wp_next_scheduled('myls_ytvb_auto_generate');
	if ( $auto && ! $scheduled ) {
		wp_schedule_event( time(), 'myls_every_12_hours', 'myls_ytvb_auto_generate' );
	} elseif ( ! $auto && $scheduled ) {
		wp_clear_scheduled_hook('myls_ytvb_auto_generate');
	}
} );
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

/** Ensure dashicons are available on our page. Tab nav CSS now in assets/css/admin.css */
add_action('admin_enqueue_scripts', function(){
	if ( ! myls_is_our_admin_page() ) return;
	wp_enqueue_style('dashicons');
});

// Register (don't enqueue) accordion CSS — shortcodes enqueue it when needed.
add_action('wp_enqueue_scripts', function() {
    wp_register_style('myls-accordion', myls_asset_url('assets/css/myls-accordion.css'), [], MYLS_VERSION);
});


/**
 * One-time prompt reset migration — v7.8.57
 *
 * Clears all saved prompt options so new GEO-aligned file defaults are used.
 * Runs once on update; flag myls_prompts_reset_v78570 prevents re-running.
 * Affects: meta title/desc, excerpts, taglines, about-area, geo-rewrite,
 *          llms-txt, page-builder, elementor-builder, and all FAQ variants.
 */
add_action( 'plugins_loaded', function () {
    if ( get_option( 'myls_prompts_reset_v78570' ) ) return;

    $prompt_options = [
        'myls_ai_prompt_title',
        'myls_ai_prompt_desc',
        'myls_ai_prompt_excerpt',
        'myls_ai_prompt_html_excerpt',
        'myls_ai_taglines_prompt_template',
        'myls_ai_about_prompt_template',
        'myls_ai_geo_prompt_template',
        'myls_ai_llms_txt_prompt_template',
        'myls_pb_prompt_template',
        'myls_elb_prompt_template',
        'myls_ai_faqs_prompt_template',
        'myls_ai_faqs_prompt_template_v2',
        'myls_ai_faqs_prompt_template_v3',
    ];

    foreach ( $prompt_options as $key ) {
        delete_option( $key );
    }

    update_option( 'myls_prompts_reset_v78570', '1' );
}, 5 );  // priority 5 — runs before any tab tries to read saved prompt


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
 *
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
