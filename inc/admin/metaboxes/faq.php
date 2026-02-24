<?php
if ( ! defined('ABSPATH') ) exit;

add_action('add_meta_boxes', function () {

	$pts = get_post_types(['public' => true], 'names');
	unset($pts['attachment']);

	foreach ( $pts as $pt ) {
		add_meta_box(
			'myls_faq_box',
			__('FAQs (AIntelligize)', 'myls'),
			'myls_render_faq_metabox',
			$pt,
			'normal',
			'high'
		);
	}
});

function myls_render_faq_metabox( $post ) {

	wp_nonce_field('myls_faq_save', 'myls_faq_nonce');
	$faqs = myls_get_faq_items( $post->ID );

	echo '<div id="myls-faq-wrap">';

	foreach ( $faqs as $i => $row ) {

		echo '<div class="myls-faq-row">';
		echo '<p><strong>Question</strong></p>';
		echo '<input type="text" class="widefat" name="myls_faq['.$i.'][q]" value="'.esc_attr($row['q']).'" />';

		echo '<p><strong>Answer</strong></p>';

		wp_editor(
			$row['a'],
			'myls_faq_answer_'.$i,
			[
				'textarea_name' => 'myls_faq['.$i.'][a]',
				'media_buttons' => true,
				'textarea_rows' => 6,
			]
		);

		echo '<hr></div>';
	}

	echo '</div>';
}

add_action('save_post', function ( $post_id ) {

	if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
	if ( ! isset($_POST['myls_faq_nonce']) ) return;
	if ( ! wp_verify_nonce($_POST['myls_faq_nonce'], 'myls_faq_save') ) return;

	if ( isset($_POST['myls_faq']) && is_array($_POST['myls_faq']) ) {

		$clean = [];

		foreach ( $_POST['myls_faq'] as $row ) {
			if ( empty($row['q']) || empty($row['a']) ) continue;
			$clean[] = [
				'q' => sanitize_text_field($row['q']),
				'a' => wp_kses_post($row['a']),
			];
		}

		myls_set_faq_items( $post_id, $clean );
	}
});
