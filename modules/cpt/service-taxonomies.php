<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** Service CPT — taxonomy module (stub, safe no-op) */
add_action( 'init', function() {
	if ( ! post_type_exists('service') ) return;
	// Taxonomies for the Service CPT can be registered here.
	// Left as a no-op stub so the module loader does not log missing file errors.
}, 11 );
