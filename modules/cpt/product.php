<?php
if ( ! defined('ABSPATH') ) exit;

$id = 'product';

$spec = [
    'id'      => $id,
    'label'   => 'Products',
    'defaults'=> [
        'default_slug'    => 'product',
        'default_archive' => 'products',
        'menu_position'   => 23,
        'labels'          => ['name'=>'Products','singular'=>'Product'],
        'supports'        => ['title','editor','thumbnail','excerpt','custom-fields','page-attributes'],
        'hierarchical'    => true,
    ],
];

if ( defined('MYLS_CPT_DISCOVERY') && MYLS_CPT_DISCOVERY ) {
    return $spec;
}

if ( ! function_exists('myls_register_product_cpt') ) {
    function myls_register_product_cpt() {
        $id = 'product';
        $opt = "myls_enable_{$id}_cpt";
        if ( get_option($opt, '0') !== '1' ) return;
        if ( post_type_exists($id) ) return;

        $slug = trim( (string) get_option("{$opt}_slug", '') );
        $arch = trim( (string) get_option("{$opt}_hasarchive", '') );

        $slug = $slug !== '' ? $slug : 'product';
        $archive = $arch !== '' ? $arch : false;

        $labels_name = 'Products';
        $labels_sing = 'Product';

        $labels = [
            'name' => $labels_name,
            'singular_name' => $labels_sing,
            'menu_name' => $labels_name,
            'name_admin_bar' => $labels_sing,
            'archives' => $labels_sing . ' Archives',
            'attributes' => $labels_sing . ' Attributes',
            'parent_item_colon' => 'Parent ' . $labels_sing . ':',
            'all_items' => 'All ' . $labels_name,
            'add_new_item' => 'Add New ' . $labels_sing,
            'add_new' => 'Add New',
            'new_item' => 'New ' . $labels_sing,
            'edit_item' => 'Edit ' . $labels_sing,
            'update_item' => 'Update ' . $labels_sing,
            'view_item' => 'View ' . $labels_sing,
            'view_items' => 'View ' . $labels_name,
            'search_items' => 'Search ' . $labels_sing,
            'not_found' => 'Not found',
            'not_found_in_trash' => 'Not found in Trash',
        ];

        $args = [
            'label'               => $labels_sing,
            'labels'              => $labels,
            'supports'            => ['title','editor','thumbnail','excerpt','custom-fields','page-attributes'],
            'public'              => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_position'       => 23,
            'show_in_admin_bar'   => true,
            'show_in_nav_menus'   => true,
            'can_export'          => true,
            'has_archive'         => $archive,
            'hierarchical'        => true,
            'exclude_from_search' => false,
            'publicly_queryable'  => true,
            'show_in_rest'        => true,
            'rewrite'             => ['slug'=>$slug,'with_front'=>false],
            'capability_type'     => 'page',
        ];

        register_post_type($id, $args);
    }
    add_action('init', 'myls_register_product_cpt', 9);
}

return null;
