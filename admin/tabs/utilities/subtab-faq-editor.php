<?php
/**
 * Utilities Subtab: FAQ Quick Editor
 * File: admin/tabs/utilities/subtab-faq-editor.php
 *
 * IMPORTANT:
 * - This file is auto-included by the MYLS admin tab loader.
 * - Do not output HTML at top-level.
 */

if ( ! defined('ABSPATH') ) exit;

return [
  'id'    => 'faq-editor',
  'label' => 'FAQ Quick Editor',
  'order' => 20,
  'render'=> function(){
    require_once MYLS_PATH . 'admin/views/utilities-faq-editor.php';
  },
];
