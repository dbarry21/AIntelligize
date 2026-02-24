<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** Example: register a simple 'product_category' taxonomy (optional) */
add_action( 'init', function() {
	if ( ! post_type_exists('product') ) return;
	register_taxonomy( 'product_category', ['product'], [
		'label'        => __( 'Product Categories', 'myls' ),
		'hierarchical' => true,
		'show_ui'      => true,
		'show_in_rest' => true,
		'rewrite'      => [ 'slug' => 'product-category' ],
	]);
}, 11);
