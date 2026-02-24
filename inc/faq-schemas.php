<?php
add_action( 'acf/include_fields', function() {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_local_field_group( array(
	'key' => 'group_67ecf85477469',
	'title' => 'FAQ Schema',
	'fields' => array(
		array(
			'key' => 'field_67ecf855edf0b',
			'label' => 'FAQ Items',
			'name' => 'faq_items',
			'aria-label' => '',
			'type' => 'repeater',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'layout' => 'row',
			'pagination' => 0,
			'min' => 0,
			'max' => 0,
			'collapsed' => '',
			'button_label' => 'Add Row',
			'rows_per_page' => 20,
			'sub_fields' => array(
				array(
					'key' => 'field_67ecf892edf0c',
					'label' => 'Question',
					'name' => 'question',
					'aria-label' => '',
					'type' => 'text',
					'instructions' => '',
					'required' => 0,
					'conditional_logic' => 0,
					'wrapper' => array(
						'width' => '',
						'class' => '',
						'id' => '',
					),
					'default_value' => '',
					'maxlength' => '',
					'allow_in_bindings' => 1,
					'placeholder' => '',
					'prepend' => '',
					'append' => '',
					'parent_repeater' => 'field_67ecf855edf0b',
				),
				array(
				'key' => 'field_67ecf8abedf0d',
				'label' => 'Answer',
				'name' => 'answer',
				'aria-label' => '',
				'type' => 'wysiwyg',
				'instructions' => '',
				'required' => 0,
				'conditional_logic' => 0,
				'wrapper' => array(
					'width' => '',
					'class' => '',
					'id' => '',
				),

				// WYSIWYG-specific settings
				'default_value' => '',
				'tabs' => 'all',          // 'all' | 'visual' | 'text'
				'toolbar' => 'full',        // 'basic' | 'full' (or a custom toolbar name)
				'media_upload' => 1,         // 1 = allow media button, 0 = hide
				'delay' => 0,                // 1 = initialize editor on focus (faster admin), 0 = load immediately

				'allow_in_bindings' => 0,
				'parent_repeater' => 'field_67ecf855edf0b',
			),
			),
		),
	),
	'location' => array(
		array(
			array(
				'param' => 'post_type',
				'operator' => '==',
				'value' => 'page',
			),
		),
		array(
			array(
				'param' => 'post_type',
				'operator' => '==',
				'value' => 'service',
			),
		),
		array(
			array(
				'param' => 'post_type',
				'operator' => '==',
				'value' => 'service_area',
			),
		),
		array(
			array(
				'param' => 'post_type',
				'operator' => '==',
				'value' => 'video',
			),
		),
	),
	'menu_order' => 0,
	'position' => 'normal',
	'style' => 'default',
	'label_placement' => 'top',
	'instruction_placement' => 'label',
	'hide_on_screen' => '',
	'active' => true,
	'description' => '',
	'show_in_rest' => 0,
) );
} );

