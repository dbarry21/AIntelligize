<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** Safe template filter example */
add_filter( 'single_template', function( $template ){
	return $template;
});
