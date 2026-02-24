<?php
if ( ! defined('ABSPATH') ) exit;

$id = 'service';

$spec = [
    'id'      => $id,
    'label'   => 'Services',
    'defaults'=> [
        'default_slug'    => 'service',
        'default_archive' => 'services',
        'menu_position'   => 21,
        'labels'          => ['name' => 'Services', 'singular' => 'Service'],
        'supports'        => ['title','editor','thumbnail','excerpt','custom-fields','page-attributes'],
        'hierarchical'    => true,
    ],
];

if ( defined('MYLS_CPT_DISCOVERY') && MYLS_CPT_DISCOVERY ) {
    return $spec;
}

if ( ! function_exists('myls_register_service_cpt') ) {
    function myls_register_service_cpt() {
        $id = 'service';
        $opt = "myls_enable_{$id}_cpt";
        if ( get_option($opt, '0') !== '1' ) return;
        if ( post_type_exists($id) ) return;

        $defaults = [
            'slug'        => 'service',
            'archive'     => 'services',
            'menu_pos'    => 21,
            'labels_name' => 'Services',
            'labels_sing' => 'Service',
            'hier'        => true,
            'supports'    => ['title','editor','thumbnail','excerpt','custom-fields','page-attributes'],
        ];

        $slug   = trim( (string) get_option("{$opt}_slug", '') );
        $arch   = trim( (string) get_option("{$opt}_hasarchive", '') );
        $slug   = $slug !== '' ? $slug : $defaults['slug'];
        $archive= $arch !== '' ? $arch : false;

        $labels = [
            'name' => $defaults['labels_name'],
            'singular_name' => $defaults['labels_sing'],
            'menu_name' => $defaults['labels_name'],
            'name_admin_bar' => $defaults['labels_sing'],
            'archives' => $defaults['labels_sing'] . ' Archives',
            'attributes' => $defaults['labels_sing'] . ' Attributes',
            'parent_item_colon' => 'Parent ' . $defaults['labels_sing'] . ':',
            'all_items' => 'All ' . $defaults['labels_name'],
            'add_new_item' => 'Add New ' . $defaults['labels_sing'],
            'add_new' => 'Add New',
            'new_item' => 'New ' . $defaults['labels_sing'],
            'edit_item' => 'Edit ' . $defaults['labels_sing'],
            'update_item' => 'Update ' . $defaults['labels_sing'],
            'view_item' => 'View ' . $defaults['labels_sing'],
            'view_items' => 'View ' . $defaults['labels_name'],
            'search_items' => 'Search ' . $defaults['labels_sing'],
            'not_found' => 'Not found',
            'not_found_in_trash' => 'Not found in Trash',
        ];

        $args = [
            'label'               => $defaults['labels_sing'],
            'labels'              => $labels,
            'supports'            => $defaults['supports'],
            'public'              => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_position'       => $defaults['menu_pos'],
            'show_in_admin_bar'   => true,
            'show_in_nav_menus'   => true,
            'can_export'          => true,
            'has_archive'         => $archive,
            'hierarchical'        => $defaults['hier'],
            'exclude_from_search' => false,
            'publicly_queryable'  => true,
            'show_in_rest'        => true,
            'rewrite'             => ['slug'=>$slug,'with_front'=>false],
            'capability_type'     => 'page',
        ];

        register_post_type($id, $args);
    }
    add_action('init', 'myls_register_service_cpt', 9);
}

return null;
