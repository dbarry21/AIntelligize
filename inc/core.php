<?php
/**
 * Core: Admin Tab Registry (single source of truth)
 * ------------------------------------------------------------
 * - Defines a SINGLE global registry for admin tabs.
 * - Exposes myls_register_admin_tab() with legacy signature support.
 * - DOES NOT load any files or render UI. The loader handles discovery.
 *
 * Usage from a tab file (new signature):
 *   myls_register_admin_tab([
 *     'id'    => 'dashboard',
 *     'title' => 'Dashboard',
 *     'cb'    => function(){ echo '<h2>Dashboard</h2>'; },
 *     'order' => 1,                  // lower = earlier; default 10
 *     'cap'   => 'manage_options',   // default
 *     'icon'  => 'dashicons-dashboard' // optional
 *   ]);
 *
 * Legacy signature still works:
 *   myls_register_admin_tab('dashboard', 'Dashboard', $callback, 1);
 */

if ( ! defined('ABSPATH') ) exit;

/** ----------------------------------------------------------------
 * Global registry container (associative array keyed by tab id)
 * ---------------------------------------------------------------- */
if ( ! isset( $GLOBALS['myls_admin_tabs'] ) || ! is_array( $GLOBALS['myls_admin_tabs'] ) ) {
	$GLOBALS['myls_admin_tabs'] = [];
}

/** ----------------------------------------------------------------
 * Register a tab (BC with legacy signature)
 * ----------------------------------------------------------------
 * @param array|string $args  New signature: assoc array of tab props.
 *                            Legacy signature: id string.
 * @param string       $legacy_title  (legacy) tab title
 * @param callable     $legacy_cb     (legacy) render callback
 * @param int          $legacy_order  (legacy) order
 * @return void
 */
if ( ! function_exists('myls_register_admin_tab') ) {
	function myls_register_admin_tab( $args, string $legacy_title = '', $legacy_cb = null, int $legacy_order = 10 ) : void {
		// Support legacy signature: (id, title, cb, order?)
		if ( is_string( $args ) ) {
			$args = [
				'id'    => $args,
				'title' => $legacy_title,
				'cb'    => $legacy_cb,
				'order' => $legacy_order,
			];
		}

		// Defaults
		$defaults = [
			'id'    => '',
			'title' => '',
			'cb'    => null,            // callable required
			'order' => 10,              // lower shows earlier
			'cap'   => 'manage_options',
			'icon'  => '',              // optional dashicon class
		];
		$args = wp_parse_args( $args, $defaults );

		// Basic validation
		if ( $args['id'] === '' || $args['title'] === '' || ! is_callable( $args['cb'] ) ) {
			/**
			 * Fires when a tab registration fails validation.
			 *
			 * @param array $args Raw args passed to registration.
			 */
			do_action( 'myls_admin_tab_register_error', $args );
			return;
		}

		// Normalize id key
		$args['id'] = sanitize_key( $args['id'] );

		// Store/replace in the registry
		$GLOBALS['myls_admin_tabs'][ $args['id'] ] = $args;

		/**
		 * Fires after a tab is registered.
		 *
		 * @param array $args The final, stored tab config.
		 */
		do_action( 'myls_admin_tab_registered', $args );
	}
}

/** ----------------------------------------------------------------
 * Raw getter (ASSOCIATIVE; UNSORTED)
 * - Provided for debugging or advanced filters. Do not sort here.
 * ---------------------------------------------------------------- */
if ( ! function_exists('myls_get_admin_tabs_raw') ) {
	function myls_get_admin_tabs_raw() : array {
		return is_array( $GLOBALS['myls_admin_tabs'] ?? null ) ? $GLOBALS['myls_admin_tabs'] : [];
	}
}

/** ----------------------------------------------------------------
 * Debug helper (optional): toggleable via filter
 * ---------------------------------------------------------------- */
if ( ! function_exists('myls_admin_tabs_dump_notice') ) {
	function myls_admin_tabs_dump_notice() : void {
		if ( ! is_admin() ) return;
		if ( ! current_user_can('manage_options') ) return;
		if ( ! apply_filters( 'myls_show_tabs_debug_notice', false ) ) return;

		$tabs = myls_get_admin_tabs_raw();
		if ( empty( $tabs ) ) return;

		echo '<div class="notice notice-info"><p><strong>MYLS tabs (raw):</strong> '
		   . esc_html( implode( ', ', array_keys( $tabs ) ) )
		   . '</p></div>';
	}
	add_action( 'admin_notices', 'myls_admin_tabs_dump_notice' );
}
