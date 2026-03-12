<?php
/**
 * AIntelligize – Conditional CPT Registration
 * Path: inc/cpt-registration.php
 */

if ( ! defined('ABSPATH') ) exit;

/** Normalize has_archive from plugin option */
function myls_normalize_archive_option( $raw ) {
	if ( is_string($raw) && trim($raw) === '' ) return false;
	if ( in_array($raw, [0,'0',false,'false','off','no'], true) ) return false;
	if ( in_array($raw, [1,'1',true,'true','on','yes'], true) ) return true;
	if ( is_string($raw) ) {
		$slug = sanitize_title( $raw );
		return $slug !== '' ? $slug : false;
	}
	return false;
}

/** Very basic pluralization for UI labels (good enough for common English nouns) */
function myls_pluralize_basic( string $singular ) : string {
	$s = trim($singular);
	if ($s === '') return '';
	$lower = strtolower($s);

	// Ends with y (consonant + y) -> ies
	if ( preg_match('/[^aeiou]y$/i', $s) ) {
		return preg_replace('/y$/i', 'ies', $s);
	}
	// s, x, z, ch, sh -> add es
	if ( preg_match('/(s|x|z|ch|sh)$/i', $lower) ) {
		return $s . 'es';
	}
	// default: +s
	return $s . 's';
}

/** Main registrar */
function myls_register_custom_post_types() {
	$cpts = [
		'service' => [
			'option_key'      => 'myls_enable_service_cpt',
			'default_slug'    => 'service',
			'default_archive' => 'services',
			'menu_position'   => 21,
			'labels'          => ['name'=>'Services','singular'=>'Service'],
			'hierarchical'    => true,
			'supports'        => [ 'title','editor','thumbnail','excerpt','custom-fields','revisions','page-attributes' ],
			'capability_type' => 'post',
			'menu_icon'       => 'dashicons-hammer',
			'taxonomies'      => [ 'category','post_tag' ],
		],
		'service_area' => [
			'option_key'      => 'myls_enable_service_area_cpt',
			'default_slug'    => 'service-area',
			'default_archive' => 'service-areas',
			'menu_position'   => 22,
			'labels'          => ['name'=>'Service Areas','singular'=>'Service Area'],
			'hierarchical'    => true,
			'supports'        => [ 'title','editor','thumbnail','excerpt','custom-fields','page-attributes','revisions' ],
			'capability_type' => 'page',
			'menu_icon'       => 'dashicons-location',
			'taxonomies'      => [],
		],
		'product' => [
			'option_key'      => 'myls_enable_product_cpt',
			'default_slug'    => 'product',
			'default_archive' => 'products',
			'menu_position'   => 23,
			'labels'          => ['name'=>'Products','singular'=>'Product'],
			'hierarchical'    => false,
			'supports'        => [ 'title','editor','thumbnail','excerpt','custom-fields','revisions','page-attributes' ],
			'capability_type' => 'post',
			'menu_icon'       => 'dashicons-cart',
			'taxonomies'      => [ 'category','post_tag' ],
		],
		'video' => [
			'option_key'      => 'myls_enable_video_cpt',
			'default_slug'    => 'video',
			'default_archive' => 'videos',
			'menu_position'   => 24,
			'labels'          => ['name'=>'Videos','singular'=>'Video'],
			'hierarchical'    => false,
			'supports'        => [ 'title','editor','thumbnail','excerpt','custom-fields','comments','revisions','page-attributes' ],
			'capability_type' => 'post',
			'menu_icon'       => 'dashicons-video-alt3',
			'taxonomies'      => [ 'category','post_tag' ],
		],
	];

	foreach ( $cpts as $post_type => $config ) {
		$enabled = get_option( $config['option_key'], '0' );
		if ( $enabled !== '1' ) continue;

		// Slug + archive from plugin options
		$slug_opt  = trim( (string) get_option( $config['option_key'] . '_slug', '' ) );
		$arch_opt  = get_option( $config['option_key'] . '_hasarchive', '' );

		$slug        = $slug_opt !== '' ? sanitize_title($slug_opt) : $config['default_slug'];
		$has_archive = myls_normalize_archive_option( $arch_opt );
		if ( $has_archive === true ) $has_archive = $config['default_archive'];

		// Custom singular label (optional)
		$singular_custom = trim( (string) get_option( $config['option_key'] . '_label_singular', '' ) );
		$singular = $singular_custom !== '' ? $singular_custom : $config['labels']['singular'];
		$plural   = $config['labels']['name'];

		// If singular is customized, plural is auto from singular
		if ( $singular_custom !== '' ) {
			$plural = myls_pluralize_basic( $singular );
		}

		$labels = [
			'name'                  => _x( $plural, 'Post Type General Name', 'myls' ),
			'singular_name'         => _x( $singular, 'Post Type Singular Name', 'myls' ),
			'menu_name'             => __( $plural, 'myls' ),
			'name_admin_bar'        => __( $singular, 'myls' ),
			'archives'              => __( $singular . ' Archives', 'myls' ),
			'attributes'            => __( $singular . ' Attributes', 'myls' ),
			'parent_item_colon'     => __( 'Parent ' . $singular . ':', 'myls' ),
			'all_items'             => __( 'All ' . $plural, 'myls' ),
			'add_new_item'          => __( 'Add New ' . $singular, 'myls' ),
			'add_new'               => __( 'Add New', 'myls' ),
			'new_item'              => __( 'New ' . $singular, 'myls' ),
			'edit_item'             => __( 'Edit ' . $singular, 'myls' ),
			'update_item'           => __( 'Update ' . $singular, 'myls' ),
			'view_item'             => __( 'View ' . $singular, 'myls' ),
			'view_items'            => __( 'View ' . $plural, 'myls' ),
			'search_items'          => __( 'Search ' . $singular, 'myls' ),
			'not_found'             => __( 'Not found', 'myls' ),
			'not_found_in_trash'    => __( 'Not found in Trash', 'myls' ),
			'featured_image'        => __( 'Featured Image', 'myls' ),
			'set_featured_image'    => __( 'Set featured image', 'myls' ),
			'remove_featured_image' => __( 'Remove featured image', 'myls' ),
			'use_featured_image'    => __( 'Use as featured image', 'myls' ),
			'insert_into_item'      => __( 'Insert into ' . $singular, 'myls' ),
			'uploaded_to_this_item' => __( 'Uploaded to this ' . $singular, 'myls' ),
			'items_list'            => __( $plural . ' list', 'myls' ),
			'items_list_navigation' => __( $plural . ' list navigation', 'myls' ),
			'filter_items_list'     => __( 'Filter ' . $plural . ' list', 'myls' ),
		];

		$args = [
			'label'               => __( $singular, 'myls' ),
			'description'         => __( $singular . ' Description', 'myls' ),
			'labels'              => $labels,
			'supports'            => $config['supports'],
			'public'              => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_position'       => $config['menu_position'],
			'show_in_admin_bar'   => true,
			'show_in_nav_menus'   => true,
			'can_export'          => true,
			'has_archive'         => $has_archive,
			'hierarchical'        => (bool) $config['hierarchical'],
			'exclude_from_search' => false,
			'publicly_queryable'  => true,
			'show_in_rest'        => true,
			'rewrite'             => [ 'slug' => $slug, 'with_front' => false ],
			'capability_type'     => $config['capability_type'],
			'map_meta_cap'        => true,
			'menu_icon'           => $config['menu_icon'] ?? 'dashicons-admin-post',
			'taxonomies'          => $config['taxonomies'] ?? [],
		];

		register_post_type( $post_type, $args );
	}
}
add_action( 'init', 'myls_register_custom_post_types', 0 );

/** Metabox and other helpers… (unchanged from your file) */
add_action('add_meta_boxes', function() {
	add_meta_box(
		'myls_about_the_area',
		'About the Area',
		function( $post ) {
			$content = get_post_meta( $post->ID, '_about_the_area', true );
			wp_nonce_field( 'myls_save_about_the_area', 'myls_about_the_area_nonce' );
			wp_editor( $content, 'myls_about_the_area_editor', [
				'textarea_name' => 'about_the_area',
				'media_buttons' => true,
				'textarea_rows' => 8,
			] );
		},
		'service_area',
		'normal',
		'high'
	);

	// Video Transcript meta box — shows on posts with a YouTube video ID
	$screen_types = ['post', 'video'];
	foreach ( $screen_types as $pt ) {
		add_meta_box(
			'myls_video_transcript',
			'Video Transcript',
			'myls_render_transcript_meta_box',
			$pt,
			'normal',
			'default'
		);
	}
});

/** Render the Video Transcript meta box (only if post has a YouTube video ID). */
function myls_render_transcript_meta_box( $post ) {
	$video_id = get_post_meta( $post->ID, '_myls_youtube_video_id', true );
	if ( empty( $video_id ) ) {
		echo '<p class="description">This post is not linked to a YouTube video. The transcript meta box only applies to posts created by the YT Video Blog generator.</p>';
		return;
	}

	wp_nonce_field( 'myls_save_transcript', 'myls_transcript_nonce' );

	$transcript = '';
	$row = null;
	if ( function_exists('myls_vt_get_by_id') ) {
		$row = myls_vt_get_by_id( $video_id );
		if ( $row && ! empty( $row['transcript'] ) ) {
			$transcript = $row['transcript'];
		}
	}

	echo '<p class="description" style="margin-bottom:8px;">Video ID: <code>' . esc_html( $video_id ) . '</code>';
	if ( $row && ! empty( $row['source'] ) ) {
		echo ' &mdash; Source: <code>' . esc_html( $row['source'] ) . '</code>';
	}
	echo '</p>';
	echo '<textarea name="myls_video_transcript" id="myls_video_transcript" rows="15" style="width:100%;font-family:monospace;font-size:13px;">' . esc_textarea( $transcript ) . '</textarea>';
	if ( empty( $transcript ) ) {
		echo '<p class="description">No transcript available. Fetch via the <a href="' . esc_url( admin_url('admin.php?page=myls-video-transcripts') ) . '">Video Transcripts</a> interface or paste manually.</p>';
	}
}

add_action('save_post', function( $post_id ) {
	if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
	if ( ! current_user_can('edit_post', $post_id) ) return;

	// About the Area (service_area CPT)
	if ( isset($_POST['myls_about_the_area_nonce']) &&
	     wp_verify_nonce( $_POST['myls_about_the_area_nonce'], 'myls_save_about_the_area' ) ) {
		if ( isset($_POST['about_the_area']) ) {
			update_post_meta( $post_id, '_about_the_area', wp_kses_post( $_POST['about_the_area'] ) );
		}
	}

	// Video Transcript
	if ( isset($_POST['myls_transcript_nonce']) &&
	     wp_verify_nonce( $_POST['myls_transcript_nonce'], 'myls_save_transcript' ) ) {
		$video_id = get_post_meta( $post_id, '_myls_youtube_video_id', true );
		if ( ! empty( $video_id ) && function_exists('myls_vt_upsert') ) {
			$transcript = isset($_POST['myls_video_transcript']) ? sanitize_textarea_field( wp_unslash( $_POST['myls_video_transcript'] ) ) : '';
			if ( $transcript !== '' ) {
				myls_vt_upsert([
					'video_id'   => $video_id,
					'transcript' => $transcript,
					'source'     => 'manual',
					'status'     => 'ok',
					'fetched_at' => current_time('mysql'),
				]);
			}
		}
	}
});

/** Auto-flush rewrites when CPT options change */
add_action( 'updated_option', function( $option, $old, $new ) {
	if ( strpos( $option, 'myls_enable_' ) === 0 ) {
		myls_register_custom_post_types();
		flush_rewrite_rules();
	}
}, 10, 3 );

function myls_activate_register_cpts_and_flush() {
	myls_register_custom_post_types();
	// Ensure /llms.txt rewrite exists before flushing.
	if ( function_exists('myls_llms_register_rewrite') ) {
		myls_llms_register_rewrite();
	}
	flush_rewrite_rules();
}
function myls_deactivate_flush_rewrites() {
	flush_rewrite_rules();
}
