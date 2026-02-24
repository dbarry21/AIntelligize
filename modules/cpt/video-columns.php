<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** Example: register 'video_topic' taxonomy (optional) */
add_action( 'init', function() {
	if ( ! post_type_exists('video') ) return;
	register_taxonomy( 'video_topic', ['video'], [
		'label'        => __( 'Video Topics', 'myls' ),
		'hierarchical' => true,
		'show_ui'      => true,
		'show_in_rest' => true,
		'rewrite'      => [ 'slug' => 'video-topic' ],
	]);
}, 11);
