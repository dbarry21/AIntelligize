<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** Service Area CPT — template filter module (stub, safe no-op) */
add_filter( 'single_template', function( $template ) {
	// Provide a custom single-service-area template path here if desired.
	return $template;
} );
