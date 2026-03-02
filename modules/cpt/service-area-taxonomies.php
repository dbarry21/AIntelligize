<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** Service Area CPT — taxonomy module (stub, safe no-op) */
add_action( 'init', function() {
	if ( ! post_type_exists('service_area') ) return;
	// Taxonomies for the Service Area CPT can be registered here.
	// Left as a no-op stub so the module loader does not log missing file errors.
}, 11 );
