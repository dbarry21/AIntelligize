<?php
// File: modules/cpt/video.php
if ( ! defined('ABSPATH') ) exit;

$id = 'video';

$spec = [
    'id'      => $id,
    'label'   => 'Videos',
    'defaults'=> [
        'default_slug'    => 'video',
        'default_archive' => 'videos',
        'menu_position'   => 21,
        'labels'          => ['name'=>'Videos','singular'=>'Video'],
        'supports'        => ['title','editor','thumbnail','excerpt','custom-fields','page-attributes'],
        'hierarchical'    => false,
    ],
];

// Discovery mode: return spec so the CPT tab can render the card.
if ( defined('MYLS_CPT_DISCOVERY') && MYLS_CPT_DISCOVERY ) {
    return $spec;
}

// Runtime: registrar (called via our custom hook in inc/load-cpt-modules.php)
if ( ! function_exists('myls_register_video_cpt_from_options') ) {
    function myls_register_video_cpt_from_options() {
        if ( get_option('myls_enable_video_cpt', '0') !== '1' ) return;
        if ( post_type_exists('video') ) return;

        $slug_opt    = trim( (string) get_option('myls_enable_video_cpt_slug', '') );
        $archive_opt = trim( (string) get_option('myls_enable_video_cpt_hasarchive', '') );

        $rewrite_slug = $slug_opt    !== '' ? $slug_opt    : 'video';
        $has_archive  = $archive_opt !== '' ? $archive_opt : false;

        $labels = [
            'name'               => 'Videos',
            'singular_name'      => 'Video',
            'menu_name'          => 'Videos',
            'name_admin_bar'     => 'Video',
            'archives'           => 'Video Archives',
            'attributes'         => 'Video Attributes',
            'parent_item_colon'  => 'Parent Video:',
            'all_items'          => 'All Videos',
            'add_new_item'       => 'Add New Video',
            'add_new'            => 'Add New',
            'new_item'           => 'New Video',
            'edit_item'          => 'Edit Video',
            'update_item'        => 'Update Video',
            'view_item'          => 'View Video',
            'view_items'         => 'View Videos',
            'search_items'       => 'Search Videos',
            'not_found'          => 'Not found',
            'not_found_in_trash' => 'Not found in Trash',
        ];

        $args = [
            'label'               => 'Video',
            'description'         => 'A custom post type to store individual videos',
            'labels'              => $labels,
            'supports'            => ['title','editor','thumbnail','excerpt','custom-fields','page-attributes'],
            'taxonomies'          => ['post_tag'],
            'hierarchical'        => false,
            'public'              => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_position'       => 21,
            'menu_icon'           => 'dashicons-video-alt3',
            'show_in_admin_bar'   => true,
            'show_in_nav_menus'   => true,
            'can_export'          => true,
            'has_archive'         => $has_archive, // string or false
            'rewrite'             => ['slug'=>$rewrite_slug, 'with_front'=>false],
            'exclude_from_search' => false,
            'publicly_queryable'  => true,
            'show_in_rest'        => true,
            'map_meta_cap'        => true,
        ];

        register_post_type('video', $args);
    }
}
// IMPORTANT: hook to our custom action, not plain init.
add_action('myls_register_enabled_cpts', 'myls_register_video_cpt_from_options', 10);

return null;
