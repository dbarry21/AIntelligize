<?php
namespace MYLS\SiteBuilder;
if (!defined('ABSPATH')) exit;

class LookAndFeel {

  public static function import_from_url(string $url): array {
    $result = ['ok'=>false, 'message'=>'', 'skin'=>[], 'page_id'=>0];
    $url = esc_url_raw($url);
    if (!$url) { $result['message'] = 'Invalid URL.'; return $result; }

    $html = self::fetch($url);
    if (!$html) { $result['message'] = 'Failed to fetch URL.'; return $result; }

    // Colors from HTML + first CSS files
    $colors = self::extract_colors($html);
    $css_urls = self::extract_stylesheets($html, $url);
    foreach (array_slice($css_urls, 0, 3) as $css_url) {
      $css = self::fetch($css_url);
      if ($css) { $colors = array_merge($colors, self::extract_colors($css)); }
    }

    // Palette
    $palette   = self::top_colors($colors);
    $primary   = $palette[0] ?? '#136B92';
    $secondary = $palette[1] ?? '#333333';
    $accent    = $palette[2] ?? '#FFC107';

    // Fonts
    $fonts = self::extract_google_fonts($html);
    foreach (array_slice($css_urls, 0, 2) as $css_url) {
      $css = self::fetch($css_url);
      if ($css) { $fonts = array_merge($fonts, self::extract_font_family($css)); }
    }
    $fonts     = array_values(array_unique(array_filter($fonts)));
    $font_head = $fonts[0] ?? 'Inter';
    $font_body = $fonts[1] ?? $font_head;

    // Radius
    $radius = self::extract_radius($html);
    if (!$radius) {
      foreach (array_slice($css_urls, 0, 2) as $css_url) {
        $css = self::fetch($css_url);
        if ($css) { $radius = self::extract_radius($css); if ($radius) break; }
      }
    }
    if (!$radius) $radius = '12px';

    // Save skin snapshot
    $skin = [
      'source'       => $url,
      'primary'      => $primary,
      'secondary'    => $secondary,
      'accent'       => $accent,
      'radius'       => $radius,
      'font_head'    => $font_head,
      'font_body'    => $font_body,
      'updated_at'   => current_time('mysql'),
    ];
    update_option('myls_sb_skin', $skin);

    // Create / update example page
    $page_id = self::upsert_example_page($url, $html);
    if ($page_id) { update_option('myls_sb_skin_example_page_id', (int)$page_id); }

    $result['ok']      = true;
    $result['skin']    = $skin;
    $result['page_id'] = (int)$page_id;
    $result['message'] = 'Imported look & feel and created an example page.';
    return $result;
  }

  public static function fetch(string $url) {
    $res = wp_remote_get($url, ['timeout'=>10, 'redirection'=>3, 'user-agent'=>'MYLS-SiteBuilder/1.0']);
    if (is_wp_error($res)) return '';
    $code = wp_remote_retrieve_response_code($res);
    if ($code < 200 || $code >= 400) return '';
    return wp_remote_retrieve_body($res);
  }

  public static function extract_stylesheets(string $html, string $base_url): array {
    $out = [];
    if (preg_match_all('/<link[^>]+rel=["\']stylesheet["\'][^>]*>/i', $html, $m)) {
      foreach ($m[0] as $tag) {
        if (preg_match('/href=["\']([^"\']+)["\']/', $tag, $hm)) {
          $href = $hm[1];
          $out[] = self::abs_url($href, $base_url);
        }
      }
    }
    return array_values(array_unique($out));
  }

  public static function abs_url(string $href, string $base): string {
    if (preg_match('#^https?://#i', $href)) return $href;
    if (strpos($href, '//') === 0) {
      $p = wp_parse_url($base);
      return (isset($p['scheme']) ? $p['scheme'] : 'https') . ':' . $href;
    }
    if (substr($href, 0, 1) === '/') {
      $p = wp_parse_url($base);
      $origin = (isset($p['scheme']) ? $p['scheme'] : 'https') . '://' . ($p['host'] ?? '');
      if (!empty($p['port'])) $origin .= ':' . $p['port'];
      return $origin . $href;
    }
    $base = rtrim($base, '/');
    return $base . '/' . ltrim($href, '/');
  }

  public static function extract_colors(string $text): array {
    $colors = [];
    if (preg_match_all('/#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})\b/', $text, $m)) {
      foreach ($m[0] as $hex) {
        $H = strtoupper($hex);
        if (!in_array($H, ['#FFF','#FFFFFF','#000','#000000'], true)) {
          $colors[] = $H;
        }
      }
    }
    return $colors;
  }

  public static function top_colors(array $colors): array {
    $counts = [];
    foreach ($colors as $c) { $counts[$c] = ($counts[$c] ?? 0) + 1; }
    arsort($counts);
    $ordered = array_keys($counts);
    $ordered = array_values(array_filter($ordered, function($hex){
      $v = self::hex_to_rgb($hex);
      $avg = ($v[0] + $v[1] + $v[2]) / 3;
      return ($avg > 30 && $avg < 230);
    }));
    return array_slice($ordered, 0, 6);
  }

  public static function hex_to_rgb(string $hex): array {
    $hex = ltrim($hex,'#');
    if (strlen($hex)===3) {
      $r = hexdec(str_repeat($hex[0],2));
      $g = hexdec(str_repeat($hex[1],2));
      $b = hexdec(str_repeat($hex[2],2));
    } else {
      $r = hexdec(substr($hex,0,2));
      $g = hexdec(substr($hex,2,2));
      $b = hexdec(substr($hex,4,2));
    }
    return [$r,$g,$b];
  }

  public static function extract_google_fonts(string $html): array {
    $out = [];
    if (preg_match_all('#fonts\.googleapis\.com/css2\?family=([^"\']+)#i', $html, $m)) {
      foreach ($m[1] as $chunk) {
        $families = preg_split('/&family=/', $chunk);
        foreach ($families as $f) {
          $name = urldecode(preg_replace('/:.*$/','',$f));
          $name = trim(str_replace('+',' ', $name));
          if ($name) $out[] = $name;
        }
      }
    }
    return array_values(array_unique($out));
  }

  public static function extract_font_family(string $css): array {
    $out = [];
    if (preg_match_all('/font-family\s*:\s*([^;]+);/i', $css, $m)) {
      foreach ($m[1] as $fam) {
        $fam = trim($fam);
        $fam = preg_replace('/,!important$/','', $fam);
        $fam = trim($fam, "\"' ");
        if ($fam) {
          $first = trim(explode(',', $fam)[0]);
          $first = trim($first, "\"' ");
          if ($first) $out[] = $first;
        }
      }
    }
    return array_values(array_unique($out));
  }

  public static function extract_radius(string $text): string {
    if (preg_match('/border-radius\s*:\s*([0-9\.]+(px|rem|em|%))/i', $text, $m)) return $m[1];
    return '';
  }

  protected static function upsert_example_page(string $source_url, string $html): int {
    $title = 'Imported Home (Example)';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $tm)) {
      $t = trim(wp_strip_all_tags($tm[1]));
      if ($t) $title = $t . ' – Example';
    }

    $body = $html;
    if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $bm)) {
      $body = $bm[1];
    }

    $body = preg_replace('#<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>#is', '', $body);
    $body = preg_replace('#<noscript\b[^<]*(?:(?!<\/noscript>)<[^<]*)*<\/noscript>#is', '', $body);
    $body = self::absolutize_assets($body, $source_url);

    $note = sprintf(
      '<div class="alert alert-info" style="border:1px solid #ddd;padding:12px;margin-bottom:12px;"><strong>Imported Example:</strong> This draft was generated from <code>%s</code>. Images/scripts may not fully load. Use this as a starting layout.</div>',
      esc_html($source_url)
    );
    $content = $note . "\n<div class=\"myls-imported-example\">\n" . $body . "\n</div>";

    $host = parse_url($source_url, PHP_URL_HOST);
    $meta_key = '_myls_import_example_key';
    $meta_val = 'import_example:' . ($host ?: md5($source_url));

    $existing = new \WP_Query([
      'post_type'      => 'page',
      'post_status'    => ['draft','publish','pending','future'],
      'meta_key'       => $meta_key,
      'meta_value'     => $meta_val,
      'posts_per_page' => 1,
      'fields'         => 'all',
      'no_found_rows'  => true,
    ]);

    if ($existing->have_posts()) {
      $id = (int)$existing->posts[0]->ID;
      wp_update_post([
        'ID'           => $id,
        'post_title'   => $title,
        'post_content' => $content,
        'post_status'  => 'draft',
      ]);
      update_post_meta($id, '_myls_import_example_src', esc_url_raw($source_url));
      return $id;
    }

    $id = (int) wp_insert_post([
      'post_type'    => 'page',
      'post_status'  => 'draft',
      'post_title'   => $title,
      'post_content' => $content,
      'meta_input'   => [
        $meta_key                 => $meta_val,
        '_myls_import_example_src'=> esc_url_raw($source_url),
      ],
    ]);
    return $id;
  }

  protected static function absolutize_assets(string $html, string $base): string {
    $html = preg_replace_callback('/\shref=["\']([^"\']+)["\']/', function($m) use ($base){
      $abs = LookAndFeel::abs_url($m[1], $base);
      return ' href="' . esc_url($abs) . '"';
    }, $html);
    $html = preg_replace_callback('/\ssrc=["\']([^"\']+)["\']/', function($m) use ($base){
      $abs = LookAndFeel::abs_url($m[1], $base);
      return ' src="' . esc_url($abs) . '"';
    }, $html);
    return $html;
  }

  public static function output_skin_css() {
    $skin = get_option('myls_sb_skin', []);
    if (empty($skin)) return;
    $p = esc_html($skin['primary'] ?? '#136B92');
    $s = esc_html($skin['secondary'] ?? '#333333');
    $a = esc_html($skin['accent'] ?? '#FFC107');
    $r = esc_html($skin['radius'] ?? '12px');
    $fh= esc_html($skin['font_head'] ?? 'Inter');
    $fb= esc_html($skin['font_body'] ?? $fh);

    $css  = ":root{";
    $css .= "--myls-primary:{$p};";
    $css .= "--myls-secondary:{$s};";
    $css .= "--myls-accent:{$a};";
    $css .= "--myls-radius:{$r};";
    $css .= "--myls-font-headings:\"{$fh}\",system-ui,sans-serif;";
    $css .= "--myls-font-body:\"{$fb}\",system-ui,sans-serif;";
    $css .= "}";
    $css .= "a,.link-primary{color:var(--myls-primary);}";
    $css .= ".btn-primary{background:var(--myls-primary);border-color:var(--myls-primary);}";
    $css .= ".btn-outline-primary{color:var(--myls-primary);border-color:var(--myls-primary);}";
    $css .= ".card,.btn,.form-control{border-radius:var(--myls-radius);}";
    $css .= "h1,h2,h3,h4,h5{font-family:var(--myls-font-headings);}";
    $css .= "body{font-family:var(--myls-font-body);}";
    echo '<style id="myls-skin">'.$css.'</style>';
  }
}
