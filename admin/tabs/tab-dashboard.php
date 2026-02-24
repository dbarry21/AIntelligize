<?php
if ( ! defined('ABSPATH') ) exit;

myls_register_admin_tab([
  'id'    => 'dashboard',
  'title' => 'Dashboard',
  'icon'  => 'dashicons-dashboard', // ← add this
  'order' => 1,
  'cap'   => 'manage_options',
  'cb'    => function(){
    echo '<div class="wrap"><h2>Dashboard</h2></div>';
  },
]);
