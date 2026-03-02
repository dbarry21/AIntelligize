<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** Service CPT — template filter module (stub, safe no-op) */
add_filter( 'single_template', function( $template ) {
	// Provide a custom single-service template path here if desired.
	return $template;
} );
