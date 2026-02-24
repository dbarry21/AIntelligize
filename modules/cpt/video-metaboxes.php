<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** Placeholder meta box */
add_action( 'add_meta_boxes', function(){
	add_meta_box( 'myls_video_note', 'MyLS Video Note', function(){
		echo '<p style="margin:0;">Video modules loaded.</p>';
	}, 'video', 'side', 'low' );
});
