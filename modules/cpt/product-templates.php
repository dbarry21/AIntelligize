<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** Safe template filter example (no output override by default) */
add_filter( 'single_template', function( $template ){
	// Provide your own template path if desired
	return $template;
});
