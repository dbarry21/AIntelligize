<?php
/**
 * Utilities Subtab: FAQ Migration
 * File: admin/tabs/utilities/subtab-faq-migration.php
 *
 * IMPORTANT:
 * - This file is auto-included by the MYLS admin tab loader.
 * - Do not output HTML at top-level.
 */

if ( ! defined('ABSPATH') ) exit;

return [
  'id'    => 'faq-migration',
  'label' => 'FAQ Migration',
  'order' => 10,
  'render'=> function(){
    require_once MYLS_PATH . 'admin/views/utilities-faq-migration.php';
  },
];
