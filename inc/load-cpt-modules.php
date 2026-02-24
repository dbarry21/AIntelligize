<?php
// File: inc/load-cpt-modules.php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AIntelligize – CPT Module Loader
 * Loads per-CPT extras (taxonomies, columns, metaboxes, templates)
 * only when that CPT is enabled (reads the same per-CPT options as tab-cpt.php).
 * Looks in modules/cpt/* first, then falls back to inc/cpt/*.
 *
 * NOTE: CPTs are registered in inc/cpt-registration.php (included earlier).
 */

/**
 * Guarded helper: only declare if the registrar hasn't already.
 * (Main plugin includes cpt-registration.php first, so this won't run in normal flow,
 * but it keeps us safe if file order ever changes.)
 */
if ( ! function_exists( 'myls_cpt_enabled' ) ) {
	function myls_cpt_enabled( string $id, bool $default = false ): bool {
		$val = get_option( "myls_enable_{$id}_cpt", $default ? '1' : '0' );
		return in_array( $val, ['1', 1, true, 'true', 'on', 'yes'], true );
	}
}

/** Build search paths for module files (modules first, then inc fallback) */
function myls_cpt_module_paths(): array {
	$root = plugin_dir_path( dirname( __FILE__ ) ); // plugin root
	return [
		trailingslashit( $root . 'modules/cpt' ),
		trailingslashit( $root . 'inc/cpt' ),
	];
}

/** Load extras after CPTs are registered (registrar runs at priority 0) */
add_action( 'init', function () {
	$paths = myls_cpt_module_paths();

	$catalog = [
		'service' => [
			'default_enabled' => true,
			'files' => [
				'service-taxonomies.php',
				'service-columns.php',
				'service-metaboxes.php',
				'service-templates.php',
			],
		],
		'service_area' => [
			'default_enabled' => true,
			'files' => [
				'service-area-taxonomies.php',
				'service-area-columns.php',
				'service-area-metaboxes.php',
				'service-area-templates.php',
			],
		],
		// Focus CPTs
		'product' => [
			'default_enabled' => false,
			'files' => [
				'product-taxonomies.php',
				'product-columns.php',
				'product-metaboxes.php',
				'product-templates.php',
			],
		],
		'video' => [
			'default_enabled' => false,
			'files' => [
				'video-taxonomies.php',
				'video-columns.php',
				'video-metaboxes.php',
				'video-templates.php',
			],
		],
	];

	foreach ( $catalog as $type => $cfg ) {
		// Only load extras if CPT is enabled and actually registered
		if ( ! myls_cpt_enabled( $type, $cfg['default_enabled'] ) ) continue;
		if ( ! post_type_exists( $type ) ) continue;

		// Try modules/cpt first, then inc/cpt as fallback
		foreach ( $cfg['files'] as $basename ) {
			$loaded = false;
			foreach ( $paths as $dir ) {
				$file = $dir . $basename;
				if ( is_readable( $file ) ) { require_once $file; $loaded = true; break; }
			}
			if ( ! $loaded && defined('WP_DEBUG') && WP_DEBUG ) {
				error_log( '[AIntelligize] Module not found for '.$type.': '.$basename );
			}
		}
	}
}, 20);
