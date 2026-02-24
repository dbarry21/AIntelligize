<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** Add a tiny admin column to confirm modules loaded */
add_filter( 'manage_product_posts_columns', function( $cols ){
	$cols['myls_flag'] = 'MyLS';
	return $cols;
});
add_action( 'manage_product_posts_custom_column', function( $col, $post_id ){
	if ( $col === 'myls_flag' ) echo '✓';
}, 10, 2 );
