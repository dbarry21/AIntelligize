<?php
namespace MYLS\SiteBuilder;
if (!defined('ABSPATH')) exit;

class Generator {
  private $settings;

  public function __construct() {
    $this->settings = get_option('myls_sb_settings', []);
  }

  public function run(array $opts): array {
    $log = [];
    $posts = [];

    $vars     = $this->vars();
    $services = $this->services();
    $areas    = $this->areas();

    $log[] = 'Creating/updating core pages…';
    $posts['home']     = $this->upsert_page('home',     'Home',               $this->content_home($vars, $services));
    $posts['about']    = $this->upsert_page('about',    'About Us',           $this->content_about($vars));
    $posts['contact']  = $this->upsert_page('contact',  'Contact',            $this->content_contact($vars));
    $posts['services'] = $this->upsert_page('services', 'Services',           $this->content_services_overview($vars, $services));
    $posts['faq']      = $this->upsert_page('faq',      'FAQ',                $this->content_faq($vars));

    if (!empty($opts['per_service'])) {
      $log[] = 'Creating/updating per-service pages…';
      foreach ($services as $svc) {
        $key = 'service:' . $svc;
        $title = $svc . ' in ' . $vars['city'];
        $posts['service'][$svc] = $this->upsert_page($key, $title, $this->content_service($vars, $svc));
      }
    }
    if (!empty($opts['service_areas'])) {
      $log[] = 'Creating/updating service-area pages…';
      foreach ($areas as $city) {
        $key = 'area:' . $city;
        $title = $city . ' Service Area';
        $posts['area'][$city] = $this->upsert_page($key, $title, $this->content_area($vars, $city));
      }
    }
    if (!empty($opts['blog_starters'])) {
      $log[] = 'Creating blog drafts…';
      $posts['blog'][] = $this->upsert_post('blog:inspection', '5 Signs You Need a Roof Inspection', $this->content_blog($vars, 'inspection'));
      $posts['blog'][] = $this->upsert_post('blog:storm', 'Storm Damage? What to Do First', $this->content_blog($vars, 'storm'));
      $posts['blog'][] = $this->upsert_post('blog:choose', 'How to Choose a Local Roofer', $this->content_blog($vars, 'choose'));
    }

    $log[] = $this->ensure_menu($posts);
    $log[] = 'Done.';
    return ['log' => implode("\n", $log), 'posts' => $posts];
  }

  public function generate_single(string $type, array $args = []): array {
    $vars = $this->vars();
    $log = [];
    $posts = [];

    switch ($type) {
      case 'service':
        $svc = trim($args['service'] ?? '');
        if (!$svc) return ['log'=>'No service provided.'];
        $key = 'service:' . $svc;
        $title = $svc . ' in ' . $vars['city'];
        $posts['service'][$svc] = $this->upsert_page($key, $title, $this->content_service($vars, $svc));
        $log[] = "Service page created/updated: {$svc}";
        break;

      case 'area':
        $city = trim($args['area'] ?? '');
        if (!$city) return ['log'=>'No area/city provided.'];
        $key = 'area:' . $city;
        $title = $city . ' Service Area';
        $posts['area'][$city] = $this->upsert_page($key, $title, $this->content_area($vars, $city));
        $log[] = "Area page created/updated: {$city}";
        break;

      case 'faq':
        $posts['faq'] = $this->upsert_page('faq', 'FAQ', $this->content_faq($vars));
        $log[] = "FAQ page created/updated.";
        break;

      case 'blog':
        $topic = trim($args['topic'] ?? '');
        if (!$topic) $topic = 'Local tips';
        $key = 'blog:' . sanitize_title($topic);
        $title = ucwords($topic);
        $posts['blog'][] = $this->upsert_post($key, $title, $this->content_blog($vars, $topic));
        $log[] = "Blog draft created: {$title}";
        break;

      case 'page':
        $page_title = trim($args['page_title'] ?? '');
        if (!$page_title) return ['log'=>'No page title provided.'];
        $description    = trim($args['page_description'] ?? '');
        $prompt_template = trim($args['page_prompt'] ?? '');
        $key = 'page:' . sanitize_title($page_title);
        $content = $this->content_custom_page($vars, $page_title, $description, $prompt_template);
        $post_id = $this->upsert_page($key, $page_title, $content);
        $posts['page'][$page_title] = $post_id;
        $edit_url = admin_url('post.php?post=' . $post_id . '&action=edit');
        $log[] = "Custom page created/updated: {$page_title} (ID: {$post_id})";
        $log[] = "Edit: {$edit_url}";
        break;

      default:
        $log[] = "Unknown type.";
    }

    return ['log' => implode("\n", $log), 'posts' => $posts];
  }

  public function generate_batch(array $opts): array {
    $vars = $this->vars();
    $log = [];
    $posts = [];

    $services = !empty($opts['services_txt']) ? Utils::sanitize_lines($opts['services_txt']) : $this->services();
    $areas    = !empty($opts['areas_txt'])    ? Utils::sanitize_lines($opts['areas_txt'])    : $this->areas();
    $topics   = !empty($opts['topics_txt'])   ? Utils::sanitize_lines($opts['topics_txt'])   : [];

    if (!empty($opts['make_services'])) {
      foreach ($services as $svc) {
        $key = 'service:' . $svc;
        $title = $svc . ' in ' . $vars['city'];
        $posts['service'][$svc] = $this->upsert_page($key, $title, $this->content_service($vars, $svc));
      }
      $log[] = 'Service pages batch created/updated: ' . count($services);
    }
    if (!empty($opts['make_areas'])) {
      foreach ($areas as $city) {
        $key = 'area:' . $city;
        $title = $city . ' Service Area';
        $posts['area'][$city] = $this->upsert_page($key, $title, $this->content_area($vars, $city));
      }
      $log[] = 'Area pages batch created/updated: ' . count($areas);
    }
    foreach ($topics as $topic) {
      $key = 'blog:' . sanitize_title($topic);
      $title = ucwords($topic);
      $posts['blog'][] = $this->upsert_post($key, $title, $this->content_blog($vars, $topic));
    }
    if ($topics) $log[] = 'Blog drafts created: ' . count($topics);

    if (!$log) $log[] = 'Nothing to do.';
    return ['log' => implode("\n", $log), 'posts' => $posts];
  }

  private function vars(): array {
    $defaults = [
      'business_name' => 'Your Business Name',
      'city'          => 'Your City, ST',
      'phone'         => '(000) 000-0000',
      'email'         => 'info@example.com',
    ];
    return wp_parse_args($this->settings, $defaults);
  }
  private function services(): array {
    $raw = $this->settings['services'] ?? '';
    return Utils::sanitize_lines((string)$raw);
  }
  private function areas(): array {
    $raw = $this->settings['areas'] ?? '';
    return Utils::sanitize_lines((string)$raw);
  }

  private function upsert_page(string $key, string $title, string $html): int {
    $existing = $this->find_generated('page', $key);
    $content  = $html;
    if ($existing) {
      wp_update_post([
        'ID'           => $existing->ID,
        'post_title'   => $title,
        'post_content' => $content,
        'post_status'  => 'draft',
      ]);
      $post_id = (int)$existing->ID;
    } else {
      $post_id = (int) wp_insert_post([
        'post_type'    => 'page',
        'post_status'  => 'draft',
        'post_title'   => $title,
        'post_content' => $content,
        'meta_input'   => [
          '_myls_generated'     => 1,
          '_myls_generated_key' => $key,
        ],
      ]);
    }
    Utils::set_yoast_meta($post_id, $title . ' %%sitename%%', $this->yoast_desc_from_content($content));
    return $post_id;
  }

  private function upsert_post(string $key, string $title, string $html): int {
    $existing = $this->find_generated('post', $key);
    $content  = $html;
    if ($existing) {
      wp_update_post([
        'ID'           => $existing->ID,
        'post_title'   => $title,
        'post_content' => $content,
        'post_status'  => 'draft',
      ]);
      $post_id = (int)$existing->ID;
    } else {
      $post_id = (int) wp_insert_post([
        'post_type'    => 'post',
        'post_status'  => 'draft',
        'post_title'   => $title,
        'post_content' => $content,
        'meta_input'   => [
          '_myls_generated'     => 1,
          '_myls_generated_key' => $key,
        ],
      ]);
    }
    Utils::set_yoast_meta($post_id, $title . ' %%sitename%%', $this->yoast_desc_from_content($content));
    return $post_id;
  }

  private function find_generated(string $type, string $key) {
    $q = new \WP_Query([
      'post_type'      => $type,
      'post_status'    => ['draft','publish','pending','future'],
      'meta_key'       => '_myls_generated_key',
      'meta_value'     => $key,
      'posts_per_page' => 1,
      'fields'         => 'all',
      'no_found_rows'  => true,
      'ignore_sticky_posts'=> true,
    ]);
    return $q->have_posts() ? $q->posts[0] : null;
  }

  private function yoast_desc_from_content(string $html): string {
    $t = wp_strip_all_tags($html);
    $t = trim(preg_replace('/\s+/', ' ', $t));
    return mb_substr($t, 0, 155);
  }

  private function ensure_menu(array $posts_map): string {
    $menu_name = 'Main Menu';
    $menu = wp_get_nav_menu_object($menu_name);
    if (!$menu) {
      $menu_id = wp_create_nav_menu($menu_name);
    } else {
      $menu_id = (int)$menu->term_id;
    }

    $core_keys = ['home','services','about','faq','contact'];
    foreach ($core_keys as $k) {
      if (!empty($posts_map[$k])) {
        $id = (int)$posts_map[$k];
        $this->ensure_menu_item($menu_id, $id);
      }
    }

    $location = Utils::first_available_menu_location();
    if ($location) {
      $locations = get_nav_menu_locations();
      $locations[$location] = $menu_id;
      set_theme_mod('nav_menu_locations', $locations);
      return "Menu '{$menu_name}' assigned to location '{$location}'.";
    }
    return "Menu '{$menu_name}' created.";
  }

  private function ensure_menu_item(int $menu_id, int $post_id) {
    $items = wp_get_nav_menu_items($menu_id);
    $exists = false;
    if ($items) {
      foreach ($items as $it) {
        if ((int)$it->object_id === $post_id) { $exists = true; break; }
      }
    }
    if (!$exists) {
      wp_update_nav_menu_item($menu_id, 0, [
        'menu-item-object-id' => $post_id,
        'menu-item-object'    => 'page',
        'menu-item-type'      => 'post_type',
        'menu-item-status'    => 'publish',
      ]);
    }
  }

  private function try_ai(string $purpose, array $vars): string {
    $payload = ['purpose'=>$purpose, 'vars'=>$vars];
    $html = apply_filters('myls_sb_ai_generate', '', $payload);
    if (is_string($html) && strlen(trim($html)) > 0) return $html;
    return '';
  }

  private function content_home(array $vars, array $services): string {
    $html = $this->try_ai('home', array_merge($vars, ['services'=>$services]));
    if ($html) return $html;
    $svc_list = '';
    foreach ($services as $s) { $svc_list .= '<li>' . esc_html($s) . '</li>'; }
    $tpl = "
<section class='container py-5'>
  <div class='text-center'>
    <h1 class='display-5 mb-2'>{{BUSINESS_NAME}}</h1>
    <p class='lead'>Trusted local pros in {{CITY}}</p>
    <a class='btn btn-primary btn-lg mt-2' href='/contact/'>Call {{PHONE}} • Free Estimate</a>
  </div>
</section>
<section class='container py-4'>
  <h2 class='h3 mb-3'>Our Services</h2>
  <ul class='row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3 list-unstyled'>
    {$svc_list}
  </ul>
</section>";
    return Utils::replace_tokens($tpl, $vars);
  }

  private function content_about(array $vars): string {
    $html = $this->try_ai('about', $vars);
    if ($html) return $html;
    $tpl = "
<section class='container py-5'>
  <h2 class='h2 mb-3'>About {{BUSINESS_NAME}}</h2>
  <p>We proudly serve {{CITY}} with licensed, insured professionals focused on quality and clear communication.</p>
  <p>From the first call to final walkthrough, our team delivers dependable service and fair pricing.</p>
</section>";
    return Utils::replace_tokens($tpl, $vars);
  }

  private function content_contact(array $vars): string {
    $html = $this->try_ai('contact', $vars);
    if ($html) return $html;
    $tpl = "
<section class='container py-5'>
  <h2 class='h2 mb-3'>Contact Us</h2>
  <p>Have questions? Need a quick quote in {{CITY}}? Call <strong>{{PHONE}}</strong> or use the form below and our team will reach out.</p>
  <div class='mt-3'>[contact-form-7 id='123' title='Contact form']</div>
</section>";
    return Utils::replace_tokens($tpl, $vars);
  }

  private function content_services_overview(array $vars, array $services): string {
    $html = $this->try_ai('services_overview', array_merge($vars, ['services'=>$services]));
    if ($html) return $html;
    $cards = '';
    foreach ($services as $s) {
      $s_esc = esc_html($s);
      $cards .= "
      <div class='col'>
        <div class='card h-100'>
          <div class='card-body'>
            <h3 class='h5 card-title'>{$s_esc}</h3>
            <p class='card-text'>Professional {$s_esc} in {{CITY}}. Fast, fair, and friendly.</p>
            <a class='btn btn-outline-primary' href='#'>Learn More</a>
          </div>
        </div>
      </div>";
    }
    $tpl = "
<section class='container py-5'>
  <h2 class='h2 mb-4'>Services</h2>
  <div class='row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3'>
    {$cards}
  </div>
</section>";
    return Utils::replace_tokens($tpl, $vars);
  }

  private function content_faq(array $vars): string {
    $html = $this->try_ai('faq', $vars);
    if ($html) return $html;
    $tpl = "
<section class='container py-5'>
  <h2 class='h2 mb-3'>Frequently Asked Questions</h2>
  [myls_faq count='6']
</section>";
    return Utils::replace_tokens($tpl, $vars);
  }

  private function content_service(array $vars, string $service): string {
    $vars2 = array_merge($vars, ['service'=>$service]);
    $html = $this->try_ai('service', $vars2);
    if ($html) return $html;
    $tpl = "
<section class='container py-5'>
  <h2 class='h2 mb-2'>{{SERVICE}} in {{CITY}}</h2>
  <p>Expert {{SERVICE}} with transparent pricing and responsive scheduling. Call {{PHONE}} today.</p>
  <a class='btn btn-primary mt-2' href='/contact/'>Request a Free Estimate</a>
</section>";
    return Utils::replace_tokens($tpl, array_merge($vars, ['service'=>$service]));
  }

  private function content_area(array $vars, string $city): string {
    $vars2 = array_merge($vars, ['area_city'=>$city]);
    $html = $this->try_ai('area', $vars2);
    if ($html) return $html;
    $city_esc = esc_html($city);
    $tpl = "
<section class='container py-5'>
  <h2 class='h2 mb-2'>Serving {$city_esc}</h2>
  <p>{{BUSINESS_NAME}} proudly serves {$city_esc} and surrounding areas with dependable service and clear communication.</p>
  [service_area_grid city='{$city_esc}']
</section>";
    return Utils::replace_tokens($tpl, $vars);
  }

  private function content_custom_page(array $vars, string $page_title, string $description = '', string $prompt_template = ''): string {
    // Build the prompt from template or default
    if (empty($prompt_template)) {
      $prompt_template = 'Create a professional, SEO-optimized WordPress page for "{{PAGE_TITLE}}".

Business: {{BUSINESS_NAME}} in {{CITY}}
Phone: {{PHONE}} | Email: {{EMAIL}}

Page Description & Instructions:
{{DESCRIPTION}}

Requirements:
- Write clean, semantic HTML using Bootstrap 5 classes
- Include an engaging hero section with a clear headline
- Add 3-5 content sections covering key features/benefits
- Include a strong call-to-action section
- Use <section>, <h2>, <h3>, <p>, <ul> tags — NO markdown
- Make it locally relevant and SEO-friendly
- Output raw HTML only, no code fences or explanation';
    }

    // Replace all tokens in the prompt
    $token_map = array_merge($vars, [
      'page_title'  => $page_title,
      'description' => $description ?: 'A page about ' . $page_title,
    ]);
    $prompt = Utils::replace_tokens($prompt_template, $token_map);

    // Try AI generation first
    $html = '';
    if (function_exists('myls_ai_chat')) {
      $html = myls_ai_chat($prompt, [
        'model'       => '',
        'max_tokens'  => 3000,
        'temperature' => 0.7,
        'system'      => 'You are an expert web content writer. Write clean, structured HTML for WordPress pages. Use HTML tags like <section>, <h2>, <h3>, <p>, <ul>, <li>, <strong>, <em>. Use Bootstrap 5 utility classes for layout. NEVER use markdown. Output raw HTML only, no code fences.',
      ]);
    } elseif (function_exists('myls_openai_chat')) {
      $html = myls_openai_chat($prompt, [
        'model'       => '',
        'max_tokens'  => 3000,
        'temperature' => 0.7,
        'system'      => 'You are an expert web content writer. Write clean, structured HTML for WordPress pages. Use HTML tags like <section>, <h2>, <h3>, <p>, <ul>, <li>, <strong>, <em>. Use Bootstrap 5 utility classes for layout. NEVER use markdown. Output raw HTML only, no code fences.',
      ]);
    }

    // Also try the filter-based approach
    if (empty($html)) {
      $html = $this->try_ai('custom_page', array_merge($vars, [
        'page_title'  => $page_title,
        'description' => $description,
      ]));
    }

    // Fallback if AI is unavailable
    if (empty($html)) {
      $title_esc = esc_html($page_title);
      $desc_esc  = esc_html($description ?: 'Learn more about ' . $page_title);
      $html = "
<section class='container py-5'>
  <div class='text-center mb-4'>
    <h1 class='display-5 mb-3'>{$title_esc}</h1>
    <p class='lead'>{$desc_esc}</p>
  </div>
</section>
<section class='container py-4'>
  <div class='row'>
    <div class='col-lg-8 mx-auto'>
      <h2 class='h3 mb-3'>About {$title_esc}</h2>
      <p>{$desc_esc}</p>
      <p>Contact us at <strong>{{PHONE}}</strong> or email <strong>{{EMAIL}}</strong> to learn more.</p>
      <a class='btn btn-primary btn-lg mt-3' href='/contact/'>Get Started Today</a>
    </div>
  </div>
</section>";
      $html = Utils::replace_tokens($html, $vars);
    }

    return $html;
  }

  private function content_blog(array $vars, string $topic): string {
    $vars2 = array_merge($vars, ['topic'=>$topic]);
    $html = $this->try_ai('blog', $vars2);
    if ($html) return $html;
    $tpl = "<p>Starter post about {{TOPIC}} in {{CITY}}.</p>";
    return Utils::replace_tokens($tpl, array_merge($vars, ['topic'=>$topic]));
  }
}
