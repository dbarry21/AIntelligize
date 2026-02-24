<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** Placeholder meta box (safe no-op, remove if unused) */
add_action( 'add_meta_boxes', function(){
	add_meta_box( 'myls_product_note', 'MyLS Product Note', function(){
		echo '<p style="margin:0;">Product modules loaded.</p>';
	}, 'product', 'side', 'low' );
});
