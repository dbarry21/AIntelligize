<?php
/**
 * AIntelligize — Utilities: FAQ Quick Editor (MYLS native)
 *
 * AJAX endpoints (admin only):
 *  - wp_ajax_myls_faq_editor_get_posts_v1
 *  - wp_ajax_myls_faq_editor_get_faqs_v1
 *  - wp_ajax_myls_faq_editor_get_faqs_batch_v1
 *  - wp_ajax_myls_faq_editor_save_faqs_v1
 *  - wp_ajax_myls_faq_editor_save_batch_v1
 *  - wp_ajax_myls_faq_editor_export_docx_v1 (streams a .docx download)
 *  - wp_ajax_myls_faq_editor_export_docx_batch_v1 (streams a combined .docx download)
 */

if ( ! defined('ABSPATH') ) exit;

// Utilities cap + nonce action are defined in inc/utilities/acf-migrations.php
if ( ! function_exists('myls_util_cap') ) {
  function myls_util_cap() : string { return (string) apply_filters('myls_utilities_cap', 'manage_options'); }
}
if ( ! defined('MYLS_UTIL_NONCE_ACTION') ) {
  define('MYLS_UTIL_NONCE_ACTION', 'myls_utilities');
}

/* -------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------- */
if ( ! function_exists('myls_faq_editor_is_blank_html') ) {
  function myls_faq_editor_is_blank_html( string $html ) : bool {
    $plain = wp_strip_all_tags( $html, true );
    $plain = html_entity_decode( $plain, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $plain = str_replace(["\xC2\xA0", "&nbsp;"], ' ', $plain);
    $plain = preg_replace('/\s+/u', ' ', (string) $plain);
    return trim((string)$plain) === '';
  }
}

if ( ! function_exists('myls_faq_editor_sanitize_items') ) {
  /**
   * Sanitize incoming FAQ rows to MYLS canonical structure.
   *
   * Input row keys (any): q/question, a/answer, delete
   * Output: [ ['q'=>'...', 'a'=>'<p>..</p>'], ... ]
   */
  function myls_faq_editor_sanitize_items( array $rows ) : array {
    $out = [];
    foreach ( $rows as $row ) {
      if ( ! is_array($row) ) continue;

      // Support multiple possible keys from different editors / versions.
      $q = (string) ($row['q'] ?? ($row['question'] ?? ''));
      $a = (string) ($row['a'] ?? ($row['answer'] ?? ''));

      // Delete flags can come through as 1/"1"/true/"on" etc.
      $del_raw = $row['delete'] ?? ($row['del'] ?? ($row['delete_on_save'] ?? ($row['_delete'] ?? 0)));
      $del = false;
      if (is_bool($del_raw)) {
        $del = $del_raw;
      } else {
        $del = in_array((string)$del_raw, ['1','true','on','yes'], true);
      }

      $q = trim( wp_strip_all_tags($q) );
      $a = wp_kses_post($a);

      // Respect explicit delete.
      if ( $del ) continue;

      // Drop effectively empty rows.
      if ( $q === '' && myls_faq_editor_is_blank_html($a) ) continue;

      // If either side is empty, treat as invalid row (keeps schema clean).
      if ( $q === '' ) continue;
      if ( myls_faq_editor_is_blank_html($a) ) continue;

      $out[] = [ 'q' => $q, 'a' => $a ];
    }
    return $out;
  }

}

if ( ! function_exists('myls_faq_editor_analyze_rows') ) {
  /**
   * Count input rows and delete-flagged rows so the save handlers can tell
   * "user explicitly deleted everything" apart from "sanitizer silently
   * dropped invalid rows". Without this distinction an input like
   *   [ {q:"Q1",a:"A1",delete:0}, {q:"Q2",a:"A2",delete:1}, {q:"Q3",a:"A3",delete:1} ]
   * combined with a single row that fails is_blank_html() would produce an
   * empty sanitized set and wipe the entire meta key.
   */
  function myls_faq_editor_analyze_rows( array $rows ) : array {
    $input = 0;
    $deletes = 0;
    foreach ( $rows as $row ) {
      if ( ! is_array($row) ) continue;
      $input++;
      $del_raw = $row['delete'] ?? ($row['del'] ?? ($row['delete_on_save'] ?? ($row['_delete'] ?? 0)));
      if ( is_bool($del_raw) ) {
        if ( $del_raw ) $deletes++;
      } elseif ( in_array((string)$del_raw, ['1','true','on','yes'], true) ) {
        $deletes++;
      }
    }
    return [ 'input_count' => $input, 'delete_count' => $deletes ];
  }
}

if ( ! function_exists('myls_faq_editor_apply_save') ) {
  /**
   * Safely persist sanitized FAQ rows to post meta. Only deletes the meta key
   * when the caller explicitly wiped every row — never because the sanitizer
   * silently dropped invalid ones.
   */
  function myls_faq_editor_apply_save( int $post_id, array $clean, array $stats ) : void {
    if ( ! empty($clean) ) {
      update_post_meta($post_id, '_myls_faq_items', array_values($clean));
      return;
    }
    $input   = (int) ($stats['input_count']  ?? 0);
    $deletes = (int) ($stats['delete_count'] ?? 0);
    // Wipe only when input was empty (user removed every row) or every input
    // row was explicitly flagged for deletion.
    if ( $input === 0 || $deletes >= $input ) {
      delete_post_meta($post_id, '_myls_faq_items');
    }
    // Otherwise: preserve existing meta. A non-deleted row got dropped as
    // invalid (blank q/a) — treat as a no-op rather than a destructive wipe.
  }
}

if ( ! function_exists('myls_faq_editor_docx_escape') ) {
  function myls_faq_editor_docx_escape( string $text ) : string {
    $text = wp_strip_all_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    // XML escape
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
  }
}

if ( ! function_exists('myls_faq_editor_build_docx') ) {
  /**
   * Build a minimal .docx file containing the title and Q/A list.
   * Returns absolute path to the saved file.
   */
  function myls_faq_editor_build_docx( string $title, array $items ) : string {

    $upload = wp_upload_dir();
    $dir = trailingslashit( $upload['basedir'] ) . 'myls-exports';
    if ( ! file_exists($dir) ) {
      wp_mkdir_p($dir);
    }

    $safe = sanitize_title($title);
    if ( $safe === '' ) $safe = 'faqs';

    $filename = 'myls-faqs-' . $safe . '-' . date('Ymd-His') . '.docx';
    $path = trailingslashit($dir) . $filename;

    // Word XML (very small, compatible with modern Word)
    $title_xml = myls_faq_editor_docx_escape($title);

    $body_xml = '';
    // Title
    $body_xml .= '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>' . $title_xml . '</w:t></w:r></w:p>';
    $body_xml .= '<w:p><w:r><w:t> </w:t></w:r></w:p>';

    foreach ( $items as $row ) {
      $q = myls_faq_editor_docx_escape('Q: ' . (string)($row['q'] ?? ''));
      $a_plain = wp_strip_all_tags( (string)($row['a'] ?? ''), true );
      $a_plain = preg_replace('/\s+/u', ' ', trim((string)$a_plain));
      $a = myls_faq_editor_docx_escape('A: ' . $a_plain);

      $body_xml .= '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>' . $q . '</w:t></w:r></w:p>';
      $body_xml .= '<w:p><w:r><w:t>' . $a . '</w:t></w:r></w:p>';
      $body_xml .= '<w:p><w:r><w:t> </w:t></w:r></w:p>';
    }

    $document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
      . '<w:body>' . $body_xml . '<w:sectPr/></w:body></w:document>';

    $content_types = '<?xml version="1.0" encoding="UTF-8"?>'
      . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
      . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
      . '<Default Extension="xml" ContentType="application/xml"/>'
      . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
      . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
      . '</Relationships>';

    $doc_rels = '<?xml version="1.0" encoding="UTF-8"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';

    $zip = new ZipArchive();
    if ( true !== $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) ) {
      return '';
    }

    $zip->addFromString('[Content_Types].xml', $content_types);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('word/document.xml', $document_xml);
    $zip->addFromString('word/_rels/document.xml.rels', $doc_rels);

    $zip->close();

    return $path;
  }
}

if ( ! function_exists('myls_faq_editor_build_docx_batch') ) {
  /**
   * Build a combined .docx for multiple posts.
   * $sections = [ ['title' => '...', 'items' => [...]], ... ]
   */
  function myls_faq_editor_build_docx_batch( string $doc_title, array $sections ) : string {
    $upload = wp_upload_dir();
    $dir = trailingslashit( $upload['basedir'] ) . 'myls-exports';
    if ( ! file_exists($dir) ) wp_mkdir_p($dir);

    $safe = sanitize_title($doc_title);
    if ( $safe === '' ) $safe = 'faqs';

    $filename = 'myls-faqs-batch-' . $safe . '-' . date('Ymd-His') . '.docx';
    $path = trailingslashit($dir) . $filename;

    $doc_title_xml = myls_faq_editor_docx_escape($doc_title);

    $body_xml = '';
    // Document title
    $body_xml .= '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>' . $doc_title_xml . '</w:t></w:r></w:p>';
    $body_xml .= '<w:p><w:r><w:t> </w:t></w:r></w:p>';

    foreach ( $sections as $sec ) {
      $title = (string) ($sec['title'] ?? '');
      $items = is_array($sec['items'] ?? null) ? $sec['items'] : [];

      if ( $title !== '' ) {
        $t = myls_faq_editor_docx_escape($title);
        $body_xml .= '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>' . $t . '</w:t></w:r></w:p>';
      }

      foreach ( $items as $row ) {
        $q = myls_faq_editor_docx_escape('Q: ' . (string)($row['q'] ?? ''));
        $a_plain = wp_strip_all_tags( (string)($row['a'] ?? ''), true );
        $a_plain = preg_replace('/\s+/u', ' ', trim((string)$a_plain));
        $a = myls_faq_editor_docx_escape('A: ' . $a_plain);

        $body_xml .= '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>' . $q . '</w:t></w:r></w:p>';
        $body_xml .= '<w:p><w:r><w:t>' . $a . '</w:t></w:r></w:p>';
      }

      $body_xml .= '<w:p><w:r><w:t> </w:t></w:r></w:p>';
    }

    $document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
      . '<w:body>' . $body_xml . '<w:sectPr/></w:body></w:document>';

    $content_types = '<?xml version="1.0" encoding="UTF-8"?>'
      . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
      . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
      . '<Default Extension="xml" ContentType="application/xml"/>'
      . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
      . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
      . '</Relationships>';

    $doc_rels = '<?xml version="1.0" encoding="UTF-8"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';

    $zip = new ZipArchive();
    if ( true !== $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) ) {
      return '';
    }

    $zip->addFromString('[Content_Types].xml', $content_types);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('word/document.xml', $document_xml);
    $zip->addFromString('word/_rels/document.xml.rels', $doc_rels);
    $zip->close();

    return $path;
  }
}

/* -------------------------------------------------------------------------
 * AJAX: Get posts for editor
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_myls_faq_editor_get_posts_v1', function(){
  if ( ! current_user_can( myls_util_cap() ) ) {
    wp_send_json_error(['message' => 'Permission denied'], 403);
  }
  check_ajax_referer( MYLS_UTIL_NONCE_ACTION, 'nonce' );

  $pt = sanitize_key( $_POST['post_type'] ?? 'page' );
  $q  = sanitize_text_field( $_POST['search'] ?? '' );

  // Validate post type
  $pts = get_post_types(['public' => true], 'names');
  unset($pts['attachment']);
  if ( ! in_array($pt, $pts, true) ) {
    $pt = 'page';
  }

  $args = [
    'post_type'      => $pt,
    'post_status'    => ['publish','draft','pending','future','private'],
    'posts_per_page' => 800,
    'orderby'        => 'title',
    'order'          => 'ASC',
    'suppress_filters' => true,
    'fields'         => 'ids',
  ];
  if ( $q !== '' ) {
    $args['s'] = $q;
  }

  $ids = get_posts($args);
  $out = [];
  foreach ( $ids as $id ) {
    $out[] = [
      'id'    => (int) $id,
      'title' => (get_the_title($id) ?: '(no title)'),
      'type'  => $pt,
    ];
  }

  wp_send_json_success([
    'post_type' => $pt,
    'posts'     => $out,
  ]);
});

/* -------------------------------------------------------------------------
 * AJAX: Get FAQs for post
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_myls_faq_editor_get_faqs_v1', function(){
  if ( ! current_user_can( myls_util_cap() ) ) {
    wp_send_json_error(['message' => 'Permission denied'], 403);
  }
  check_ajax_referer( MYLS_UTIL_NONCE_ACTION, 'nonce' );

  $post_id = (int) ($_POST['post_id'] ?? 0);
  if ( $post_id <= 0 || ! get_post($post_id) ) {
    wp_send_json_error(['message' => 'Invalid post_id'], 400);
  }

  $items = get_post_meta($post_id, '_myls_faq_items', true);
  if ( ! is_array($items) ) $items = [];

  wp_send_json_success([
    'post_id' => $post_id,
    'title'   => get_the_title($post_id) ?: '(no title)',
    'items'   => array_values($items),
  ]);
});

/* -------------------------------------------------------------------------
 * AJAX: Get FAQs for multiple posts (batch)
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_myls_faq_editor_get_faqs_batch_v1', function(){
  if ( ! current_user_can( myls_util_cap() ) ) {
    wp_send_json_error(['message' => 'Permission denied'], 403);
  }
  check_ajax_referer( MYLS_UTIL_NONCE_ACTION, 'nonce' );

  $raw = (string) ($_POST['post_ids'] ?? '');
  $ids = array_filter(array_map('absint', preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY)));
  $ids = array_values(array_unique($ids));

  if ( empty($ids) ) {
    wp_send_json_success(['posts' => []]);
  }

  // Safety: prevent insanely large batches
  if ( count($ids) > 30 ) {
    $ids = array_slice($ids, 0, 30);
  }

  $out = [];
  foreach ( $ids as $post_id ) {
    if ( $post_id <= 0 || ! get_post($post_id) ) continue;

    $items = get_post_meta($post_id, '_myls_faq_items', true);
    if ( ! is_array($items) ) $items = [];

    $out[] = [
      'id'    => (int) $post_id,
      'title' => get_the_title($post_id) ?: '(no title)',
      'items' => array_values($items),
    ];
  }

  wp_send_json_success([
    'posts' => $out,
  ]);
});

/* -------------------------------------------------------------------------
 * AJAX: Save FAQs for post
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_myls_faq_editor_save_faqs_v1', function(){
  if ( ! current_user_can( myls_util_cap() ) ) {
    wp_send_json_error(['message' => 'Permission denied'], 403);
  }
  check_ajax_referer( MYLS_UTIL_NONCE_ACTION, 'nonce' );

  $post_id = (int) ($_POST['post_id'] ?? 0);
  if ( $post_id <= 0 || ! get_post($post_id) ) {
    wp_send_json_error(['message' => 'Invalid post_id'], 400);
  }

  $raw = $_POST['items'] ?? [];
  if ( is_string($raw) ) {
    $raw = wp_unslash($raw);
    $decoded = json_decode($raw, true);
    $raw = is_array($decoded) ? $decoded : [];
  }
  if ( ! is_array($raw) ) $raw = [];

  $stats = myls_faq_editor_analyze_rows($raw);
  $clean = myls_faq_editor_sanitize_items($raw);
  myls_faq_editor_apply_save($post_id, $clean, $stats);

  wp_send_json_success([
    'post_id' => $post_id,
    'saved'   => count($clean),
    'message' => 'Saved.',
  ]);
});

/* -------------------------------------------------------------------------
 * AJAX: Batch save FAQs for multiple posts
 * Payload: posts = JSON { "123": [ {q,a,delete}, ... ], "456": [...] }
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_myls_faq_editor_save_batch_v1', function(){
  if ( ! current_user_can( myls_util_cap() ) ) {
    wp_send_json_error(['message' => 'Permission denied'], 403);
  }
  check_ajax_referer( MYLS_UTIL_NONCE_ACTION, 'nonce' );

  $raw = $_POST['posts'] ?? [];
  if ( is_string($raw) ) {
    $raw = wp_unslash($raw);
    $decoded = json_decode($raw, true);
    $raw = is_array($decoded) ? $decoded : [];
  }
  if ( ! is_array($raw) ) $raw = [];

  $saved_map = [];
  $updated_posts = 0;

  // Safety: cap updates per request
  $post_ids = array_keys($raw);
  $post_ids = array_slice($post_ids, 0, 30);

  foreach ( $post_ids as $pid ) {
    $post_id = (int) $pid;
    if ( $post_id <= 0 || ! get_post($post_id) ) continue;

    $items = $raw[$pid];
    if ( is_string($items) ) {
      $items = wp_unslash($items);
      $decoded_items = json_decode($items, true);
      $items = is_array($decoded_items) ? $decoded_items : [];
    }
    if ( ! is_array($items) ) $items = [];

    $stats = myls_faq_editor_analyze_rows($items);
    $clean = myls_faq_editor_sanitize_items($items);
    myls_faq_editor_apply_save($post_id, $clean, $stats);

    $saved_map[(string)$post_id] = count($clean);
    $updated_posts++;
  }

  wp_send_json_success([
    'posts' => $updated_posts,
    'saved' => $saved_map,
  ]);
});

/* -------------------------------------------------------------------------
 * AJAX: Export FAQs to DOCX (streams download)
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_myls_faq_editor_export_docx_v1', function(){
  if ( ! current_user_can( myls_util_cap() ) ) {
    wp_die('Permission denied', 403);
  }

  $nonce = isset($_REQUEST['nonce']) ? (string) $_REQUEST['nonce'] : '';
  if ( ! wp_verify_nonce($nonce, MYLS_UTIL_NONCE_ACTION) ) {
    wp_die('Invalid nonce', 403);
  }

  $post_id = (int) ($_REQUEST['post_id'] ?? 0);
  if ( $post_id <= 0 || ! get_post($post_id) ) {
    wp_die('Invalid post_id', 400);
  }

  $title = get_the_title($post_id) ?: 'FAQs';
  $items = get_post_meta($post_id, '_myls_faq_items', true);
  if ( ! is_array($items) ) $items = [];

  $path = myls_faq_editor_build_docx($title, $items);
  if ( ! $path || ! file_exists($path) ) {
    wp_die('Unable to create DOCX', 500);
  }

  $filename = basename($path);

  header('Content-Description: File Transfer');
  header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Content-Transfer-Encoding: binary');
  header('Expires: 0');
  header('Cache-Control: must-revalidate');
  header('Pragma: public');
  header('Content-Length: ' . filesize($path));

  // Stream file
  readfile($path);
  exit;
});

/* -------------------------------------------------------------------------
 * AJAX: Export FAQs to DOCX (batch, streams download)
 * Query: post_ids=1,2,3
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_myls_faq_editor_export_docx_batch_v1', function(){
  if ( ! current_user_can( myls_util_cap() ) ) {
    wp_die('Permission denied', 403);
  }

  $nonce = isset($_REQUEST['nonce']) ? (string) $_REQUEST['nonce'] : '';
  if ( ! wp_verify_nonce($nonce, MYLS_UTIL_NONCE_ACTION) ) {
    wp_die('Invalid nonce', 403);
  }

  $raw = (string) ($_REQUEST['post_ids'] ?? '');
  $ids = array_filter(array_map('absint', preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY)));
  $ids = array_values(array_unique($ids));
  if ( empty($ids) ) {
    wp_die('No post_ids provided', 400);
  }
  if ( count($ids) > 30 ) {
    $ids = array_slice($ids, 0, 30);
  }

  $sections = [];
  foreach ( $ids as $post_id ) {
    if ( $post_id <= 0 || ! get_post($post_id) ) continue;
    $title = get_the_title($post_id) ?: ('Post #' . $post_id);
    $items = get_post_meta($post_id, '_myls_faq_items', true);
    if ( ! is_array($items) ) $items = [];
    $sections[] = [
      'title' => $title,
      'items' => array_values($items),
    ];
  }

  if ( empty($sections) ) {
    wp_die('No valid posts found', 400);
  }

  $path = myls_faq_editor_build_docx_batch('MYLS FAQs Export', $sections);
  if ( ! $path || ! file_exists($path) ) {
    wp_die('Unable to create DOCX', 500);
  }

  $filename = basename($path);

  header('Content-Description: File Transfer');
  header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Content-Transfer-Encoding: binary');
  header('Expires: 0');
  header('Cache-Control: must-revalidate');
  header('Pragma: public');
  header('Content-Length: ' . filesize($path));
  readfile($path);
  exit;
});

/* -------------------------------------------------------------------------
 * Enqueue JS only on MYLS admin page + Utilities → FAQ Editor
 * ------------------------------------------------------------------------- */
add_action('admin_enqueue_scripts', function(){
  if ( ! function_exists('myls_is_admin_page') || ! myls_is_admin_page() ) return;
  $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
  $sub = isset($_GET['sub']) ? sanitize_key($_GET['sub']) : '';
  if ( $tab !== 'utilities' || $sub !== 'faq-editor' ) return;

  // WYSIWYG support in the Quick Editor (wp.editor.initialize)
  if ( function_exists('wp_enqueue_editor') ) {
    wp_enqueue_editor();
  } else {
    wp_enqueue_script('wp-editor');
  }

  $handle = 'myls-faq-editor';
  $src = ( defined('MYLS_PLUGIN_URL') ? MYLS_PLUGIN_URL : trailingslashit(MYLS_URL) ) . 'assets/js/myls-faq-editor.js';
  wp_enqueue_script($handle, $src, ['jquery','wp-editor'], MYLS_VERSION, true);

  wp_localize_script($handle, 'MYLS_FAQ_EDITOR', [
    'ajax_url'   => admin_url('admin-ajax.php'),
    'nonce'      => wp_create_nonce(MYLS_UTIL_NONCE_ACTION),
    'export_base'=> admin_url('admin-ajax.php'),
  ]);
});
