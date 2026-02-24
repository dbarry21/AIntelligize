<?php
/**
 * AIntelligize – AI AJAX: GEO
 * File: inc/ajax/ai-geo.php
 *
 * Endpoints:
 *  - wp_ajax_myls_ai_geo_get_posts_v1
 *  - wp_ajax_myls_ai_geo_duplicate_v1
 *  - wp_ajax_myls_ai_geo_analyze_v2
 *  - wp_ajax_myls_ai_geo_convert_v1
 *
 * Restored:
 * - This file was accidentally removed when adding the FAQs subtab.
 * - It matches the GEO subtab UI in admin/tabs/ai/subtab-geo.php.
 */

if ( ! defined('ABSPATH') ) exit;

/* =============================================================================
 * Nonce helper
 * ============================================================================= */
if ( ! function_exists('myls_ai_check_nonce') ) {
  function myls_ai_check_nonce( string $action = 'myls_ai_ops' ) : void {
    $nonce = isset($_POST['nonce']) ? (string)$_POST['nonce'] : (string)($_REQUEST['_ajax_nonce'] ?? '');
    if ( ! wp_verify_nonce( $nonce, $action ) ) {
      wp_send_json_error(['marker'=>'geo','status'=>'error','message'=>'bad_nonce'], 403);
    }
  }
}

/* =============================================================================
 * AI provider wrapper (shared pattern)
 * ============================================================================= */
if ( ! function_exists('myls_ai_generate_text') ) {
  function myls_ai_generate_text( string $prompt, array $opts = [] ) : string {
    // IMPORTANT:
    // Do NOT call myls_openai_complete() directly here.
    // myls_openai_complete() is a FILTER callback with signature ( $out, $args ).
    // Calling it directly with ($prompt, $opts) returns the prompt unchanged and skips the API call.
    // Always route through the filter so the correct OpenAI plumbing executes.

    // Preferred filter (lets your OpenAI plumbing intercept)
    $out = apply_filters('myls_ai_complete', '', array_merge([
      'prompt'      => $prompt,
      'model'       => $opts['model']       ?? null,
      'max_tokens'  => $opts['max_tokens']  ?? null,
      'temperature' => $opts['temperature'] ?? null,
      'context'     => $opts['context']     ?? null,
      'post_id'     => $opts['post_id']     ?? null,
    ], $opts));

    if ( is_string($out) && trim($out) !== '' ) return $out;

    // Secondary fallback filter
    $out2 = apply_filters('myls_ai_generate_text', '', $prompt);
    if ( is_string($out2) && trim($out2) !== '' ) return $out2;

    return '';
  }
}

/* =============================================================================
 * Small helpers
 * ============================================================================= */
if ( ! function_exists('myls_geo_strip_code_fences') ) {
  function myls_geo_strip_code_fences( string $s ) : string {
    $s = preg_replace('/^\s*```[a-zA-Z0-9_-]*\s*\n/u', '', (string)$s);
    $s = preg_replace('/\n?\s*```\s*$/u', '', (string)$s);
    return str_replace('```', '', (string)$s);
  }
}

if ( ! function_exists('myls_geo_split_combined_output') ) {
  /**
   * Split combined output into GEO HTML + DOC text.
   * If delimiter is missing, the entire output is treated as GEO HTML.
   *
   * @return array{geo_html:string, doc_text:string, combined_raw:string}
   */
  function myls_geo_split_combined_output( string $raw ) : array {
    $raw  = trim((string)$raw);
    $norm = str_replace(["\r\n","\r"], "\n", $raw);

    $parts = explode('===DOC_GUIDE===', $norm, 2);
    if (count($parts) === 2) {
      return [
        'geo_html'     => trim((string)$parts[0]),
        'doc_text'     => trim((string)$parts[1]),
        'combined_raw' => $raw,
      ];
    }

    return [
      'geo_html'     => $raw,
      'doc_text'     => '',
      'combined_raw' => $raw,
    ];
  }
}

if ( ! function_exists('myls_geo_fetch_permalink_text') ) {
  /**
   * Fetch rendered front-end HTML for a permalink and extract readable text.
   */
  function myls_geo_fetch_permalink_text( string $url ) : string {
    $url = trim($url);
    if ($url === '') return '';

    $resp = wp_remote_get($url, [
      'timeout' => 20,
      'headers' => [
        'User-Agent' => 'MYLS-AI-GEO/1.0; ' . home_url('/'),
      ],
    ]);

    if ( is_wp_error($resp) ) return '';
    $code = (int) wp_remote_retrieve_response_code($resp);
    if ( $code < 200 || $code >= 300 ) return '';

    $html = (string) wp_remote_retrieve_body($resp);
    if ( $html === '' ) return '';

    // Remove scripts/styles/comments
    $html = preg_replace('#<script[^>]*>.*?</script>#si', ' ', $html);
    $html = preg_replace('#<style[^>]*>.*?</style>#si', ' ', $html);
    $html = preg_replace('#<!--.*?-->#s', ' ', $html);

    // Prefer main/article if present
    $main = '';
    if ( preg_match('#<main[^>]*>(.*?)</main>#si', $html, $m) ) {
      $main = (string) $m[1];
    } elseif ( preg_match('#<article[^>]*>(.*?)</article>#si', $html, $m) ) {
      $main = (string) $m[1];
    } else {
      $main = $html;
    }

    $text = wp_strip_all_tags($main);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', trim($text));

    // Cap prompt input
    if ( strlen($text) > 20000 ) {
      $text = substr($text, 0, 20000);
    }

    return (string) $text;
  }
}

/* =============================================================================
 * DOCX writer (minimal)
 * - Used only if the model returns a doc guide part.
 * ============================================================================= */
if ( ! function_exists('myls_geo_build_docx_from_text') ) {
  function myls_geo_build_docx_from_text( int $post_id, string $title, string $text ) : string {
    if ( ! class_exists('ZipArchive') ) return '';

    $uploads = wp_upload_dir();
    if ( empty($uploads['basedir']) || empty($uploads['baseurl']) ) return '';

    $dir = trailingslashit($uploads['basedir']) . 'myls-geo-docs/';
    if ( ! file_exists($dir) ) wp_mkdir_p($dir);

    $safe = sanitize_title($title ?: 'geo');
    $filename = sprintf('%s-%d-%s.docx', $safe, $post_id, date('Ymd-His'));
    $path = $dir . $filename;

    $ok = myls_geo_docx_write_minimal($path, $title, $text);
    if ( ! $ok ) return '';

    return trailingslashit($uploads['baseurl']) . 'myls-geo-docs/' . $filename;
  }
}

if ( ! function_exists('myls_geo_docx_escape') ) {
  function myls_geo_docx_escape( string $s ) : string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
  }
}

if ( ! function_exists('myls_geo_docx_write_minimal') ) {
  function myls_geo_docx_write_minimal( string $filepath, string $doc_title, string $text ) : bool {
    $zip = new ZipArchive();
    if ( $zip->open($filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true ) {
      return false;
    }

    $zip->addFromString('[Content_Types].xml', myls_geo_docx_content_types_xml());
    $zip->addFromString('_rels/.rels', myls_geo_docx_rels_xml());
    $zip->addFromString('docProps/core.xml', myls_geo_docx_core_xml($doc_title));
    $zip->addFromString('docProps/app.xml', myls_geo_docx_app_xml());
    $zip->addFromString('word/_rels/document.xml.rels', myls_geo_docx_document_rels_xml());
    $zip->addFromString('word/document.xml', myls_geo_docx_document_xml($text));

    $zip->close();
    return true;
  }
}

if ( ! function_exists('myls_geo_docx_content_types_xml') ) {
  function myls_geo_docx_content_types_xml() : string {
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>
XML;
  }
}

if ( ! function_exists('myls_geo_docx_rels_xml') ) {
  function myls_geo_docx_rels_xml() : string {
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>
XML;
  }
}

if ( ! function_exists('myls_geo_docx_document_rels_xml') ) {
  function myls_geo_docx_document_rels_xml() : string {
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>
XML;
  }
}

if ( ! function_exists('myls_geo_docx_core_xml') ) {
  function myls_geo_docx_core_xml( string $title ) : string {
    $t = myls_geo_docx_escape($title ?: 'Document');
    $created = gmdate('Y-m-d\TH:i:s\Z');
    return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"
 xmlns:dc="http://purl.org/dc/elements/1.1/"
 xmlns:dcterms="http://purl.org/dc/terms/"
 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:title>{$t}</dc:title>
  <dc:creator>MYLS</dc:creator>
  <cp:lastModifiedBy>MYLS</cp:lastModifiedBy>
  <dcterms:created xsi:type="dcterms:W3CDTF">{$created}</dcterms:created>
  <dcterms:modified xsi:type="dcterms:W3CDTF">{$created}</dcterms:modified>
</cp:coreProperties>
XML;
  }
}

if ( ! function_exists('myls_geo_docx_app_xml') ) {
  function myls_geo_docx_app_xml() : string {
    return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"
 xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>MYLS</Application>
</Properties>
XML;
  }
}

if ( ! function_exists('myls_geo_docx_p') ) {
  function myls_geo_docx_p( string $text, string $style = '' ) : string {
    $t = myls_geo_docx_escape($text);
    $pPr = '';
    if ($style !== '') {
      $s = myls_geo_docx_escape($style);
      $pPr = "<w:pPr><w:pStyle w:val=\"{$s}\"/></w:pPr>";
    }
    return "<w:p>{$pPr}<w:r><w:t xml:space=\"preserve\">{$t}</w:t></w:r></w:p>";
  }
}

if ( ! function_exists('myls_geo_docx_document_xml') ) {
  function myls_geo_docx_document_xml( string $text ) : string {
    $lines = preg_split("/\r\n|\n|\r/", (string)$text);
    $paras = [];

    foreach ($lines as $line) {
      $line = trim((string)$line);

      if ($line === '') {
        $paras[] = myls_geo_docx_p('');
        continue;
      }

      if (strpos($line, '### ') === 0) {
        $paras[] = myls_geo_docx_p(substr($line, 4), 'Heading3');
        continue;
      }
      if (strpos($line, '## ') === 0) {
        $paras[] = myls_geo_docx_p(substr($line, 3), 'Heading2');
        continue;
      }
      if (strpos($line, '# ') === 0) {
        $paras[] = myls_geo_docx_p(substr($line, 2), 'Heading1');
        continue;
      }

      if (strpos($line, '- ') === 0) {
        $paras[] = myls_geo_docx_p('• ' . substr($line, 2));
        continue;
      }

      $paras[] = myls_geo_docx_p($line);
    }

    $body = implode("\n", $paras);

    return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    {$body}
    <w:sectPr>
      <w:pgSz w:w="12240" w:h="15840"/>
      <w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/>
    </w:sectPr>
  </w:body>
</w:document>
XML;
  }
}

/* =============================================================================
 * Endpoint: Get posts by type (includes drafts)
 * ============================================================================= */
add_action('wp_ajax_myls_ai_geo_get_posts_v1', function(){
  myls_ai_check_nonce();

  $pt = isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : 'page';
  if ( ! post_type_exists($pt) ) {
    wp_send_json_error(['marker'=>'geo','status'=>'error','message'=>'invalid_post_type'], 400);
  }

  $ptype_obj = get_post_type_object($pt);
  $cap = $ptype_obj && isset($ptype_obj->cap->edit_posts) ? $ptype_obj->cap->edit_posts : 'edit_posts';
  if ( ! current_user_can($cap) ) {
    wp_send_json_error(['marker'=>'geo','status'=>'error','message'=>'cap_denied'], 403);
  }

  $ids = get_posts([
    'post_type'       => $pt,
    'post_status'     => ['publish','draft','pending','future','private'],
    'posts_per_page'  => -1,
    'orderby'         => 'title',
    'order'           => 'ASC',
    'fields'          => 'ids',
    'suppress_filters'=> true,
  ]);

  $out = [];
  foreach ($ids as $pid) {
    $out[] = ['id'=>(int)$pid, 'title'=> get_the_title($pid)];
  }

  wp_send_json_success(['marker'=>'geo','status'=>'ok','posts'=>$out]);
});

/* =============================================================================
 * Analyze v2: returns sanitized GEO HTML and optional doc_url (if delimiter part B exists)
 * ============================================================================= */
add_action('wp_ajax_myls_ai_geo_analyze_v2', function(){
  myls_ai_check_nonce();
  $start_time = microtime(true);
  if ( class_exists('MYLS_Variation_Engine') ) { MYLS_Variation_Engine::reset_log(); }

  $post_id = (int) ($_POST['post_id'] ?? 0);
  if ( $post_id <= 0 || get_post_status($post_id) === false ) {
    wp_send_json_error(['marker'=>'geo','status'=>'error','message'=>'bad_post'], 400);
  }
  if ( ! current_user_can('edit_post', $post_id) ) {
    wp_send_json_error(['marker'=>'geo','status'=>'error','message'=>'cap_denied'], 403);
  }

  $title = get_the_title($post_id);
  $url   = get_permalink($post_id);

  $template = isset($_POST['template']) ? (string) wp_unslash($_POST['template']) : '';
  if ( trim($template) === '' ) {
    $template = (string) get_option('myls_ai_geo_prompt_template', '');
  }
  if ( trim($template) === '' && function_exists('myls_get_default_prompt') ) {
    $template = myls_get_default_prompt('geo-rewrite');
  }
  if ( trim($template) === '' ) {
    wp_send_json_error(['marker'=>'geo','status'=>'error','message'=>'missing_template'], 400);
  }

  $include = isset($_POST['include_faq_howto']) ? sanitize_text_field((string)$_POST['include_faq_howto']) : 'NO';
  $include = (strtoupper($include) === 'YES') ? 'YES' : 'NO';

  $tokens      = max(1, (int) ($_POST['tokens'] ?? (int) get_option('myls_ai_geo_tokens', 1200)));
  $temperature = (float) ($_POST['temperature'] ?? (float) get_option('myls_ai_geo_temperature', 0.4));

  $page_text = myls_geo_fetch_permalink_text((string)$url);
  if ($page_text === '') {
    $p = get_post($post_id);
    $page_text = function_exists('myls_get_post_plain_text') ? myls_get_post_plain_text( $post_id ) : preg_replace('/\s+/u',' ', trim( wp_strip_all_tags( (string) ($p ? $p->post_content : '') ) ));
  }

  $prompt = str_replace(
    ['{{TITLE}}','{{URL}}','{{PAGE_TEXT}}','{{INCLUDE_FAQ_HOWTO}}'],
    [$title, $url, $page_text, $include],
    $template
  );

  // ── Variation Engine: inject angle + banned phrases for GEO generation ──
  if ( class_exists('MYLS_Variation_Engine') ) {
    $angle  = MYLS_Variation_Engine::next_angle('about_the_area'); // reuse about angles for GEO
    $prompt = MYLS_Variation_Engine::inject_variation( $prompt, $angle, 'about_the_area' );
  }

  $ai = myls_ai_generate_text($prompt, [
    'max_tokens'  => $tokens,
    'temperature' => $temperature,
    'context'     => 'geo_analyze',
    'post_id'     => $post_id,
  ]);

  $raw_all = myls_geo_strip_code_fences((string)$ai);

  $split = myls_geo_split_combined_output($raw_all);
  $geo_html = (string) ($split['geo_html'] ?? '');
  $doc_text = (string) ($split['doc_text'] ?? '');

  // Allowlist: no style/class/data attributes; allow ids on headings; allow href/target on links.
  $allowed = [
    'h2' => ['id'=>true],
    'h3' => ['id'=>true],
    'p'  => [],
    'ul' => [],
    'li' => [],
    'strong' => [],
    'em' => [],
    'a'  => ['href'=>true, 'target'=>true, 'rel'=>true],
  ];

  $clean = wp_kses($geo_html, $allowed);

  // If the user asked for add-ons, verify the model actually added them.
  if ($include === 'YES') {
    if (stripos($clean, 'id="how-tos"') === false || stripos($clean, 'id="faqs"') === false) {
      wp_send_json_error([
        'marker'=>'geo','status'=>'error','message'=>'missing_howto_or_faqs',
        'debug'=>[
          'has_howtos' => (stripos($clean, 'id="how-tos"') !== false),
          'has_faqs'   => (stripos($clean, 'id="faqs"') !== false),
        ]
      ], 422);
    }
  }

  $doc_url = '';
  if ( trim($doc_text) !== '' ) {
    $doc_url = myls_geo_build_docx_from_text($post_id, (string)$title, (string)$doc_text);
  }

  $ve_log = class_exists('MYLS_Variation_Engine') ? MYLS_Variation_Engine::build_item_log($start_time, [
    'model'          => 'default',
    'tokens'         => $tokens,
    'temperature'    => $temperature,
    'prompt_chars'   => mb_strlen($prompt),
    'output_words'   => str_word_count(wp_strip_all_tags($clean)),
    'output_chars'   => strlen($clean),
    'page_title'     => (string)$title,
    '_html'          => $clean,
    'city_state'     => (string)$title,
    'include_faq'    => $include,
    'has_doc'        => trim($doc_text) !== '',
  ]) : ['elapsed_ms' => round((microtime(true) - $start_time) * 1000)];

  wp_send_json_success([
    'marker'       => 'geo',
    'status'       => 'ok',
    'post_id'      => $post_id,
    'title'        => (string)$title,
    'url'          => (string)$url,
    'geo_html'     => (string)$clean,
    'raw_combined' => (string)$raw_all,
    'page_text'    => (string)$page_text,
    'doc_url'      => (string)$doc_url,
    'preview'      => mb_substr(wp_strip_all_tags($clean), 0, 120) . (mb_strlen(wp_strip_all_tags($clean)) > 120 ? '...' : ''),
    'log'          => $ve_log,
  ]);
});

/* =============================================================================
 * Convert to GEO draft: creates a draft and returns edit link
 * ============================================================================= */
add_action('wp_ajax_myls_ai_geo_convert_v1', function(){
  myls_ai_check_nonce();

  $post_id = (int) ($_POST['post_id'] ?? 0);
  if ( $post_id <= 0 || get_post_status($post_id) === false ) {
    wp_send_json_error(['marker'=>'geo','status'=>'error','message'=>'bad_post'], 400);
  }
  if ( ! current_user_can('edit_post', $post_id) ) {
    wp_send_json_error(['marker'=>'geo','status'=>'error','message'=>'cap_denied'], 403);
  }

  $src = get_post($post_id);
  if ( ! $src ) {
    wp_send_json_error(['marker'=>'geo','status'=>'error','message'=>'post_not_found'], 404);
  }

  $title = get_the_title($post_id);
  $url   = get_permalink($post_id);

  $template = isset($_POST['template']) ? (string) wp_unslash($_POST['template']) : '';
  if ( trim($template) === '' ) {
    $template = (string) get_option('myls_ai_geo_prompt_template', '');
  }
  if ( trim($template) === '' && function_exists('myls_get_default_prompt') ) {
    $template = myls_get_default_prompt('geo-rewrite');
  }
  if ( trim($template) === '' ) {
    wp_send_json_error(['marker'=>'geo','status'=>'error','message'=>'missing_template'], 400);
  }

  $include = isset($_POST['include_faq_howto']) ? sanitize_text_field((string)$_POST['include_faq_howto']) : 'NO';
  $include = (strtoupper($include) === 'YES') ? 'YES' : 'NO';

  $tokens      = max(1, (int) ($_POST['tokens'] ?? (int) get_option('myls_ai_geo_tokens', 1200)));
  $temperature = (float) ($_POST['temperature'] ?? (float) get_option('myls_ai_geo_temperature', 0.4));

  $page_text = myls_geo_fetch_permalink_text((string)$url);
  if ($page_text === '') {
    $page_text = function_exists('myls_get_post_plain_text') ? myls_get_post_plain_text( $src->ID ) : preg_replace('/\s+/u',' ', trim( wp_strip_all_tags( (string) $src->post_content ) ));
  }

  $prompt = str_replace(
    ['{{TITLE}}','{{URL}}','{{PAGE_TEXT}}','{{INCLUDE_FAQ_HOWTO}}'],
    [$title, $url, $page_text, $include],
    $template
  );

  // ── Variation Engine: inject angle for GEO convert ──
  if ( class_exists('MYLS_Variation_Engine') ) {
    $angle  = MYLS_Variation_Engine::next_angle('about_the_area');
    $prompt = MYLS_Variation_Engine::inject_variation( $prompt, $angle, 'about_the_area' );
  }

  $ai = myls_ai_generate_text($prompt, [
    'max_tokens'  => $tokens,
    'temperature' => $temperature,
    'context'     => 'geo_convert',
    'post_id'     => $post_id,
  ]);

  $raw_all = myls_geo_strip_code_fences((string)$ai);
  $split = myls_geo_split_combined_output($raw_all);
  $geo_html = (string) ($split['geo_html'] ?? '');
  $doc_text = (string) ($split['doc_text'] ?? '');

  $allowed = [
    'h2' => ['id'=>true],
    'h3' => ['id'=>true],
    'p'  => [],
    'ul' => [],
    'li' => [],
    'strong' => [],
    'em' => [],
    'a'  => ['href'=>true, 'target'=>true, 'rel'=>true],
  ];
  $clean = wp_kses($geo_html, $allowed);

  if ($include === 'YES') {
    if (stripos($clean, 'id="how-tos"') === false || stripos($clean, 'id="faqs"') === false) {
      wp_send_json_error(['marker'=>'geo','status'=>'error','message'=>'missing_howto_or_faqs'], 422);
    }
  }

  $new_id = wp_insert_post([
    'post_type'    => $src->post_type,
    'post_status'  => 'draft',
    'post_title'   => trim((string)$src->post_title) . ' (GEO Draft)',
    'post_content' => (string)$clean,
    'post_excerpt' => (string)$src->post_excerpt,
    'post_parent'  => (int)$src->post_parent,
    'menu_order'   => (int)$src->menu_order,
  ], true);

  if ( is_wp_error($new_id) ) {
    wp_send_json_error(['marker'=>'geo','status'=>'error','message'=>'insert_failed','debug'=>['error'=>$new_id->get_error_message()]], 500);
  }

  // Featured image
  $thumb_id = get_post_thumbnail_id($src->ID);
  if ( $thumb_id ) set_post_thumbnail($new_id, $thumb_id);

  $doc_url = '';
  if ( trim($doc_text) !== '' ) {
    $doc_url = myls_geo_build_docx_from_text($post_id, (string)$title, (string)$doc_text);
  }

  $edit_link = function_exists('get_edit_post_link') ? get_edit_post_link($new_id, 'raw') : '';

  wp_send_json_success([
    'marker'   => 'geo',
    'status'   => 'converted',
    'draft_id' => (int)$new_id,
    'edit_link'=> (string)$edit_link,
    'doc_url'  => (string)$doc_url,
  ]);
});

/* =============================================================================
 * Duplicate to draft (no rewrite)
 * ============================================================================= */
add_action('wp_ajax_myls_ai_geo_duplicate_v1', function(){
  myls_ai_check_nonce();

  $post_id = (int) ($_POST['post_id'] ?? 0);
  if ( $post_id <= 0 ) {
    wp_send_json_error(['marker'=>'geo','status'=>'error','message'=>'missing_post_id'], 400);
  }

  $src = get_post($post_id);
  if ( ! $src || empty($src->ID) ) {
    wp_send_json_error(['marker'=>'geo','status'=>'error','message'=>'post_not_found'], 404);
  }

  if ( ! current_user_can('edit_post', $post_id) ) {
    wp_send_json_error(['marker'=>'geo','status'=>'error','message'=>'cap_denied'], 403);
  }

  $new_id = wp_insert_post([
    'post_type'    => $src->post_type,
    'post_status'  => 'draft',
    'post_title'   => trim((string)$src->post_title) . ' (GEO Draft)',
    'post_content' => (string)$src->post_content,
    'post_excerpt' => (string)$src->post_excerpt,
    'post_parent'  => (int)$src->post_parent,
    'menu_order'   => (int)$src->menu_order,
  ], true);

  if ( is_wp_error($new_id) ) {
    wp_send_json_error(['marker'=>'geo','status'=>'error','message'=>'insert_failed','debug'=>['error'=>$new_id->get_error_message()]], 500);
  }

  $thumb_id = get_post_thumbnail_id($src->ID);
  if ( $thumb_id ) set_post_thumbnail($new_id, $thumb_id);

  wp_send_json_success([
    'marker'   => 'geo',
    'status'   => 'duplicated',
    'draft_id' => (int)$new_id,
  ]);
});
