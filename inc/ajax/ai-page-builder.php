<?php
/**
 * AJAX handler: AI Page Builder
 * Path: inc/ajax/ai-page-builder.php
 *
 * Standalone page/post creation via AI — no dependency on Site Builder toggle.
 * Actions:
 *   myls_pb_create_page  – Generate and create a page/post with AI content
 */
if ( ! defined('ABSPATH') ) exit;

add_action('wp_ajax_myls_pb_create_page', function () {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error(['message' => 'Forbidden'], 403);
    }
    if ( ! wp_verify_nonce($_POST['_wpnonce'] ?? '', 'myls_pb_create') ) {
        wp_send_json_error(['message' => 'Bad nonce'], 400);
    }
    $start_time = microtime(true);
    if ( class_exists('MYLS_Variation_Engine') ) { MYLS_Variation_Engine::reset_log(); }

    // ── Collect & sanitize inputs ───────────────────────────────────────
    $page_title      = sanitize_text_field($_POST['page_title'] ?? '');
    $post_type       = sanitize_key($_POST['post_type'] ?? 'page');
    $page_status     = in_array(($_POST['page_status'] ?? ''), ['draft','publish'], true)
                        ? $_POST['page_status'] : 'draft';
    $description     = wp_kses_post($_POST['page_description'] ?? '');
    $prompt_template = wp_kses_post($_POST['prompt_template'] ?? '');
    $add_to_menu     = ! empty($_POST['add_to_menu']);

    // Image integration options
    $integrate_images = ! empty($_POST['integrate_images']);
    $image_style      = sanitize_text_field($_POST['image_style'] ?? 'modern-flat');
    $gen_hero_img     = ! empty($_POST['gen_hero']);
    $gen_feature_imgs = ! empty($_POST['gen_feature']);
    $feature_count    = max(0, min(6, (int) ($_POST['feature_count'] ?? 3)));
    $set_featured     = ! empty($_POST['set_featured']);

    if ( empty($page_title) ) {
        wp_send_json_error(['message' => 'Page title is required.'], 400);
    }

    // Validate post type exists
    if ( ! post_type_exists($post_type) ) {
        wp_send_json_error(['message' => 'Invalid post type: ' . $post_type], 400);
    }

    // ── Load business variables from Site Builder settings ──────────────
    $sb_settings = get_option('myls_sb_settings', []);
    $vars = [
        'business_name' => $sb_settings['business_name'] ?? get_bloginfo('name'),
        'city'          => $sb_settings['city']          ?? '',
        'phone'         => $sb_settings['phone']         ?? '',
        'email'         => $sb_settings['email']         ?? get_bloginfo('admin_email'),
        'site_name'     => get_bloginfo('name'),
        'site_url'      => home_url(),
    ];

    // ── Build the AI prompt ────────────────────────────────────────────
    if ( empty(trim($prompt_template)) ) {
        $prompt_template = myls_pb_default_prompt();
    }

    // Token replacement
    $token_map = array_merge($vars, [
        'page_title'  => $page_title,
        'description' => $description ?: 'A page about ' . $page_title,
        'post_type'   => $post_type,
    ]);
    $prompt = myls_pb_replace_tokens($prompt_template, $token_map);

    // ── Generate content via AI ────────────────────────────────────────
    $html = '';
    $ai_used = false;
    $generated_images = [];
    $image_log = [];

    // ── Pre-generate images if requested ─────────────────────────────
    if ( $integrate_images && function_exists('myls_pb_dall_e_generate') ) {
        $api_key = function_exists('myls_openai_get_api_key') ? myls_openai_get_api_key() : '';
        if ( ! empty($api_key) ) {
            // Style presets
            $style_map = [
                'modern-flat'       => 'Modern flat design illustration, clean lines, soft gradients, professional color palette, minimalist',
                'photorealistic'    => 'Professional stock photography style, high quality, well-lit, clean background',
                'isometric'         => 'Isometric 3D illustration, colorful, tech-forward, clean white background',
                'watercolor'        => 'Soft watercolor style illustration, artistic, professional, warm tones',
                'gradient-abstract' => 'Abstract gradient art, flowing shapes, modern tech aesthetic, vivid colors',
            ];
            $style_suffix = $style_map[$image_style] ?? $style_map['modern-flat'];

            // Generate hero image
            if ( $gen_hero_img ) {
                $image_log[] = "🎨 Generating hero image…";
                $hero_prompt = "Create a wide banner/hero image for a webpage about: {$page_title}. ";
                if ( $description ) {
                    $hero_prompt .= "Context: " . mb_substr(wp_strip_all_tags($description), 0, 300) . ". ";
                }
                $hero_prompt .= "Style: {$style_suffix}. Landscape orientation, 1792x1024, no text or words in the image.";

                $result = myls_pb_dall_e_generate($api_key, $hero_prompt, '1792x1024');
                if ( $result['ok'] ) {
                    $attach_id = myls_pb_upload_image_from_url(
                        $result['url'],
                        sanitize_title($page_title) . '-hero',
                        $page_title . ' - Hero Image',
                        0
                    );
                    if ( $attach_id ) {
                        $img_url = wp_get_attachment_url($attach_id);
                        $generated_images[] = [
                            'type'      => 'hero',
                            'id'        => $attach_id,
                            'url'       => $img_url,
                            'alt'       => $page_title . ' - Hero Image',
                            'subject'   => $page_title,
                        ];
                        $image_log[] = "   ✅ Hero image ready (ID: {$attach_id})";
                    }
                } else {
                    $image_log[] = "   ❌ Hero: " . $result['error'];
                }
            }

            // Generate feature images
            if ( $gen_feature_imgs && $feature_count > 0 ) {
                $image_log[] = "🎨 Generating {$feature_count} feature image(s)…";
                $subjects = function_exists('myls_pb_suggest_image_subjects')
                    ? myls_pb_suggest_image_subjects($page_title, $description, $feature_count)
                    : array_map(fn($i) => "Feature {$i} of {$page_title}", range(1, $feature_count));

                for ($i = 0; $i < $feature_count; $i++) {
                    $subject = $subjects[$i] ?? "Feature " . ($i + 1) . " of {$page_title}";
                    $feat_prompt = "Create a square icon/illustration representing: {$subject}. ";
                    $feat_prompt .= "For a page about: {$page_title}. ";
                    $feat_prompt .= "Style: {$style_suffix}. Square format, 1024x1024, no text or words in the image.";

                    $result = myls_pb_dall_e_generate($api_key, $feat_prompt, '1024x1024');
                    if ( $result['ok'] ) {
                        $attach_id = myls_pb_upload_image_from_url(
                            $result['url'],
                            sanitize_title($page_title) . '-feature-' . ($i + 1),
                            $page_title . ' - ' . $subject,
                            0
                        );
                        if ( $attach_id ) {
                            $img_url = wp_get_attachment_url($attach_id);
                            $generated_images[] = [
                                'type'    => 'feature',
                                'id'      => $attach_id,
                                'url'     => $img_url,
                                'alt'     => $page_title . ' - ' . $subject,
                                'subject' => $subject,
                            ];
                            $image_log[] = "   ✅ Feature " . ($i + 1) . ": \"{$subject}\"";
                        }
                    } else {
                        $image_log[] = "   ❌ Feature " . ($i + 1) . ": " . $result['error'];
                    }
                }
            }
        }
    }

    // ── Build image instruction block for AI prompt ──────────────────
    $image_instructions = '';
    if ( ! empty($generated_images) ) {
        $image_instructions = "\n\nAVAILABLE IMAGES — You MUST use ALL of these images in the page at appropriate locations:\n";
        foreach ( $generated_images as $idx => $img ) {
            $image_instructions .= sprintf(
                "- %s image: <img src=\"%s\" alt=\"%s\" class=\"img-fluid rounded\" />\n",
                ucfirst($img['type']),
                esc_url($img['url']),
                esc_attr($img['alt'])
            );
            if ( $img['type'] === 'hero' ) {
                $image_instructions .= "  → Place this as a full-width hero banner at the top of the page inside a figure tag\n";
            } else {
                $image_instructions .= "  → Place this in or near the section about: {$img['subject']}\n";
            }
        }
        $image_instructions .= "\nIMPORTANT: Use the exact <img> tags provided above. Do NOT invent placeholder image URLs. Place hero image prominently at top, and feature images alongside their related content sections (in cards, beside text, or as section illustrations).\n";
    }

    // ── Append image instructions to prompt (AFTER images are generated) ──
    if ( ! empty($image_instructions) ) {
        $prompt .= $image_instructions;
    }

    // ── Variation Engine: inject angle for page builder generation ──
    if ( class_exists('MYLS_Variation_Engine') ) {
        $angle  = MYLS_Variation_Engine::next_angle('about_the_area');
        $prompt = MYLS_Variation_Engine::inject_variation( $prompt, $angle, 'about_the_area' );
    }

    if ( function_exists('myls_ai_chat') ) {
        $model = (string) get_option('myls_openai_model', '');
        if ( function_exists('myls_ai_set_usage_context') ) {
            myls_ai_set_usage_context( 'page_builder', $post_id ?? 0 );
        }
        $html = myls_ai_chat($prompt, [
            'model'       => $model,
            'max_tokens'  => 4000,
            'temperature' => 0.7,
            'system'      => 'You are an expert web designer and content writer. Write clean, structured HTML for WordPress pages using Bootstrap 5 classes and Bootstrap Icons (bi bi-* classes from https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css).

CARD DESIGN RULES:
- Every feature/benefit card MUST include a Bootstrap Icon in a colored circle at the top
- Icon circle: 60x60px rounded-circle with a brand-colored background (use varied colors like bg-primary, bg-success, bg-info, bg-warning, bg-danger, or inline styles with hex colors like #2c7be5, #00b894, #e17055, #6c5ce7, #fdcb6e)
- Icon inside circle: text-white, font-size 1.5rem
- Card structure: <div class="card h-100 border-0 shadow-sm"><div class="card-body text-center p-4"><div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:60px;height:60px;background:#COLOR;"><i class="bi bi-ICON text-white" style="font-size:1.5rem;"></i></div><h3>Title</h3><p>Text</p></div></div>
- Use relevant icons: bi-chat-dots, bi-palette, bi-book, bi-globe, bi-shield-check, bi-people, bi-lightning, bi-gear, bi-phone, bi-envelope, bi-star, bi-rocket, bi-graph-up, bi-cpu, bi-lock, bi-heart, etc.

ACCORDION / FAQ RULES:
- Use Bootstrap 5 accordion with compact spacing
- Add class "accordion-flush" for tight design
- Each accordion-body: use py-2 px-3 (minimal padding)
- Each accordion-button: use py-2 (compact header)
- NO extra margin between items

GENERAL:
- Use <section>, <h2>, <h3>, <p>, <ul>, <li> tags — NEVER markdown
- Hero sections: use a dark or gradient background with white text
- Spacing: use py-5 for sections, mb-4 for section headings
- Output raw HTML only, no code fences, no explanation text',
        ]);
        if ( ! empty(trim($html)) ) {
            // Strip ```html code fences that AI sometimes wraps around output
            $html = trim($html);
            $html = preg_replace('/^```(?:html|HTML)?\s*\n?/i', '', $html);
            $html = preg_replace('/\n?\s*```\s*$/i', '', $html);
            $html = trim($html);
            $ai_used = true;
        }
    }

    // Fallback if AI unavailable
    if ( empty(trim($html)) ) {
        $t_esc = esc_html($page_title);
        $d_esc = esc_html($description ?: 'Learn more about ' . $page_title);
        $html  = "<section class='container py-5'>\n";
        $html .= "  <div class='text-center mb-4'>\n";
        $html .= "    <h1 class='display-5 mb-3'>{$t_esc}</h1>\n";
        $html .= "    <p class='lead'>{$d_esc}</p>\n";
        $html .= "  </div>\n";
        $html .= "</section>\n";
        $html .= "<section class='container py-4'>\n";
        $html .= "  <div class='row'><div class='col-lg-8 mx-auto'>\n";
        $html .= "    <h2 class='h3 mb-3'>About {$t_esc}</h2>\n";
        $html .= "    <p>{$d_esc}</p>\n";
        $html .= "    <a class='btn btn-primary btn-lg mt-3' href='/contact/'>Get Started Today</a>\n";
        $html .= "  </div></div>\n";
        $html .= "</section>";
    }

    // ── Upsert the page/post ──────────────────────────────────────────
    $meta_key = '_myls_pb_generated_key';
    $gen_key  = 'pb:' . sanitize_title($page_title);

    // Check for existing
    $existing = get_posts([
        'post_type'      => $post_type,
        'post_status'    => ['draft','publish','pending','future','private'],
        'meta_key'       => $meta_key,
        'meta_value'     => $gen_key,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ]);

    if ( $existing ) {
        $post_id = (int) $existing[0];
        wp_update_post([
            'ID'           => $post_id,
            'post_title'   => $page_title,
            'post_content' => $html,
            'post_status'  => $page_status,
        ]);
        $action_label = 'updated';
    } else {
        $post_id = (int) wp_insert_post([
            'post_type'    => $post_type,
            'post_status'  => $page_status,
            'post_title'   => $page_title,
            'post_content' => $html,
            'meta_input'   => [
                '_myls_pb_generated' => 1,
                $meta_key            => $gen_key,
            ],
        ]);
        $action_label = 'created';
    }

    if ( ! $post_id || is_wp_error($post_id) ) {
        wp_send_json_error(['message' => 'Failed to create post.'], 500);
    }

    // ── Attach generated images to the post ──────────────────────────
    if ( ! empty($generated_images) ) {
        foreach ( $generated_images as $img ) {
            // Re-parent image to this post
            wp_update_post([
                'ID'          => $img['id'],
                'post_parent' => $post_id,
            ]);
            // Set first hero image as featured
            if ( $img['type'] === 'hero' && $set_featured ) {
                set_post_thumbnail($post_id, $img['id']);
            }
        }
    }

    // Set Yoast meta if available
    $desc_for_yoast = wp_strip_all_tags($html);
    $desc_for_yoast = trim(preg_replace('/\s+/', ' ', $desc_for_yoast));
    $desc_for_yoast = mb_substr($desc_for_yoast, 0, 155);
    update_post_meta($post_id, '_yoast_wpseo_title', $page_title . ' %%sitename%%');
    update_post_meta($post_id, '_yoast_wpseo_metadesc', $desc_for_yoast);

    // ── Add to menu if requested ──────────────────────────────────────
    $menu_msg = '';
    if ( $add_to_menu ) {
        $menu_msg = myls_pb_add_to_menu($post_id, $post_type);
    }

    // ── Build response log ────────────────────────────────────────────
    $type_obj = get_post_type_object($post_type);
    $type_label = $type_obj ? $type_obj->labels->singular_name : $post_type;
    $edit_url = admin_url('post.php?post=' . $post_id . '&action=edit');
    $view_url = get_permalink($post_id);

    $log_lines = [];
    $log_lines[] = "✅ {$type_label} {$action_label}: \"{$page_title}\"";
    $log_lines[] = "   Post ID: {$post_id} | Status: {$page_status}";
    $log_lines[] = "   AI: " . ($ai_used ? 'Content generated by AI' : 'Using fallback template (check OpenAI API key)');
    if ( ! empty($image_log) ) {
        $log_lines = array_merge($log_lines, $image_log);
        $log_lines[] = "   📸 " . count($generated_images) . " image(s) integrated into page content";
    }
    if ( $menu_msg ) {
        $log_lines[] = "   Menu: {$menu_msg}";
        // Detect block theme and add helpful tip
        if ( wp_is_block_theme() ) {
            $log_lines[] = "";
            $log_lines[] = "📝 Block Theme Tip: To reorder menu items, go to:";
            $log_lines[] = "   Appearance → Editor → Navigation";
            $log_lines[] = "   Or: Appearance → Menus (classic editor also available)";
        }
    }
    $log_lines[] = "   Edit: {$edit_url}";

    $ve_log = class_exists('MYLS_Variation_Engine') ? MYLS_Variation_Engine::build_item_log($start_time, [
        'page_title'   => $page_title,
        'post_type'    => $post_type,
        'page_status'  => $page_status,
        'image_count'  => count($generated_images ?? []),
        'ai_used'      => $ai_used ?? false,
        'output_words' => str_word_count(wp_strip_all_tags($html ?? '')),
        'output_chars' => strlen($html ?? ''),
        'prompt_chars' => isset($prompt) ? mb_strlen($prompt) : 0,
        '_html'        => $html ?? '',
    ]) : ['elapsed_ms' => round((microtime(true) - $start_time) * 1000)];

    wp_send_json_success([
        'message'  => "{$type_label} {$action_label} successfully.",
        'log_text' => implode("\n", $log_lines),
        'log'      => $ve_log,
        'post_id'  => $post_id,
        'edit_url' => $edit_url,
        'view_url' => $view_url,
        'ai_used'  => $ai_used,
        'images'   => $generated_images,
        'status'   => 'saved',
        'title'    => $page_title,
        'preview'  => "Created {$type_label}: \"{$page_title}\" (ID {$post_id})",
    ]);
});

/**
 * Default prompt template for AI Page Builder
 */
function myls_pb_default_prompt(): string {
    return myls_get_default_prompt('page-builder');
}

/**
 * Replace {{TOKEN}} placeholders (case-insensitive keys → UPPER tokens)
 */
function myls_pb_replace_tokens(string $text, array $vars): string {
    foreach ($vars as $k => $v) {
        if ( is_string($v) || is_numeric($v) ) {
            $text = str_replace('{{' . strtoupper($k) . '}}', (string) $v, $text);
        }
    }
    return $text;
}

/**
 * Add a post to the site navigation.
 *
 * Handles BOTH classic themes (wp_nav_menu) and block themes (wp_navigation post type).
 * For block themes like Twenty Twenty-Four, finds the ACTIVE wp_navigation post
 * used in the header template part and injects a link block into it.
 */
function myls_pb_add_to_menu(int $post_id, string $post_type = 'page'): string {
    $messages = [];

    // ── 1. Block theme: inject into the active wp_navigation post ──────
    if ( wp_is_block_theme() ) {
        $nav_result = myls_pb_add_to_block_nav($post_id, $post_type);
        if ( $nav_result ) {
            $messages[] = $nav_result;
        }
    }

    // ── 2. Classic menu: also add to classic nav ────────────────────────
    $classic_result = myls_pb_add_to_classic_menu($post_id, $post_type);
    if ( $classic_result ) {
        $messages[] = $classic_result;
    }

    return $messages ? implode(' | ', $messages) : 'Menu update attempted.';
}

/**
 * Find the active wp_navigation post ID used in the site header.
 *
 * Parses the header template part to extract the `ref` attribute from
 * the Navigation block. This is the ID TT4 actually renders.
 */
function myls_pb_find_active_nav_id(): int {
    // 1. Check for user-customized header template part (stored in DB)
    $custom_parts = get_posts([
        'post_type'   => 'wp_template_part',
        'post_status' => 'publish',
        'name'        => 'header',
        'posts_per_page' => 1,
    ]);

    $header_content = '';
    if ( ! empty($custom_parts) ) {
        $header_content = $custom_parts[0]->post_content;
    }

    // 2. Fall back to theme file if no DB customization
    if ( empty($header_content) ) {
        $theme_dir = get_stylesheet_directory();
        $candidates = [
            $theme_dir . '/parts/header.html',
            $theme_dir . '/templates/parts/header.html',
            $theme_dir . '/block-templates/parts/header.html',
        ];
        foreach ( $candidates as $path ) {
            if ( file_exists($path) ) {
                $header_content = file_get_contents($path);
                break;
            }
        }
    }

    // 3. Parse ref from <!-- wp:navigation {"ref":123} -->
    if ( $header_content && preg_match('/<!-- wp:navigation\s+\{[^}]*"ref"\s*:\s*(\d+)/s', $header_content, $m) ) {
        $ref_id = (int) $m[1];
        // Verify this post exists
        if ( get_post_type($ref_id) === 'wp_navigation' ) {
            return $ref_id;
        }
    }

    // 4. Fallback: get the most recent wp_navigation post
    $nav_posts = get_posts([
        'post_type'      => 'wp_navigation',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    return ! empty($nav_posts) ? (int) $nav_posts[0]->ID : 0;
}

/**
 * Block theme navigation: find the active wp_navigation post and append a link.
 */
function myls_pb_add_to_block_nav(int $post_id, string $post_type = 'page'): string {
    $nav_id = myls_pb_find_active_nav_id();

    if ( ! $nav_id ) {
        return 'No block navigation found. Your header may use a Page List block (auto-shows all published pages).';
    }

    $nav_post = get_post($nav_id);
    if ( ! $nav_post ) {
        return "Navigation post #{$nav_id} not found.";
    }

    $page_title = get_the_title($post_id);
    $page_url   = get_permalink($post_id);
    $content    = $nav_post->post_content;

    // Check if this page is already in the navigation
    if ( preg_match('/"id"\s*:\s*' . $post_id . '[,}\s]/', $content) ) {
        return "Already in block navigation (#{$nav_id}).";
    }

    // Check if header uses <!-- wp:page-list /--> (auto-lists all published pages)
    if ( strpos($content, 'wp:page-list') !== false ) {
        return "Navigation uses Page List (auto-shows all published pages — your page will appear once published).";
    }

    $link_block = sprintf(
        '<!-- wp:navigation-link {"label":"%s","type":"%s","id":%d,"url":"%s","kind":"post-type"} /-->',
        esc_attr($page_title),
        esc_attr($post_type),
        $post_id,
        esc_url($page_url)
    );

    $updated_content = trim($content) . "\n" . $link_block;

    wp_update_post([
        'ID'           => $nav_id,
        'post_content' => $updated_content,
    ]);

    return "Added to block navigation \"{$nav_post->post_title}\" (#{$nav_id}).";
}

/**
 * Classic menu: add to wp_nav_menu for classic/hybrid themes.
 */
function myls_pb_add_to_classic_menu(int $post_id, string $post_type = 'page'): string {
    $menu_name = 'Main Menu';
    $menu = wp_get_nav_menu_object($menu_name);
    if ( ! $menu ) {
        $menu_id = wp_create_nav_menu($menu_name);
        if ( is_wp_error($menu_id) ) {
            return '';
        }
    } else {
        $menu_id = (int) $menu->term_id;
    }

    // Check for duplicate
    $items = wp_get_nav_menu_items($menu_id);
    if ( $items ) {
        foreach ( $items as $item ) {
            if ( (int) $item->object_id === $post_id ) {
                return "Already in classic menu.";
            }
        }
    }

    wp_update_nav_menu_item($menu_id, 0, [
        'menu-item-object-id' => $post_id,
        'menu-item-object'    => $post_type,
        'menu-item-type'      => 'post_type',
        'menu-item-status'    => 'publish',
    ]);

    // Assign to first registered location
    $locs = get_registered_nav_menus();
    $location = isset($locs['primary']) ? 'primary'
              : ( isset($locs['menu-1']) ? 'menu-1'
              : ( isset($locs['myls-primary']) ? 'myls-primary'
              : ( $locs ? array_key_first($locs) : '' ) ) );

    if ( $location ) {
        $locations = get_nav_menu_locations();
        if ( empty($locations[$location]) || (int) $locations[$location] !== $menu_id ) {
            $locations[$location] = $menu_id;
            set_theme_mod('nav_menu_locations', $locations);
        }
    }

    return "Added to classic '{$menu_name}' menu.";
}

/**
 * Register classic menu locations from the plugin.
 * Enables Appearance → Menus in the WP admin even for block themes.
 */
add_action('after_setup_theme', function() {
    register_nav_menus([
        'myls-primary' => __('MYLS Primary Menu', 'aintelligize'),
        'myls-footer'  => __('MYLS Footer Menu', 'aintelligize'),
    ]);
}, 20);

/**
 * AJAX: Get all wp_navigation posts (for the dropdown in Page Builder)
 */
add_action('wp_ajax_myls_pb_get_nav_posts', function () {
    if ( ! current_user_can('manage_options') ) wp_send_json_error([], 403);

    $nav_posts = get_posts([
        'post_type'      => 'wp_navigation',
        'post_status'    => 'publish',
        'posts_per_page' => 20,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    $active_id = myls_pb_find_active_nav_id();

    $items = [];
    foreach ( $nav_posts as $np ) {
        $items[] = [
            'id'     => (int) $np->ID,
            'title'  => $np->post_title ?: '(untitled)',
            'active' => (int) $np->ID === $active_id,
        ];
    }

    wp_send_json_success([
        'nav_posts' => $items,
        'active_id' => $active_id,
        'is_block_theme' => wp_is_block_theme(),
    ]);
});

/**
 * AJAX: Save a page description to the history library
 */
add_action('wp_ajax_myls_pb_save_description', function () {
    if ( ! current_user_can('manage_options') ) wp_send_json_error(['message' => 'Forbidden'], 403);
    if ( ! wp_verify_nonce($_POST['_wpnonce'] ?? '', 'myls_pb_create') ) wp_send_json_error(['message' => 'Bad nonce'], 400);

    $name        = sanitize_text_field($_POST['desc_name'] ?? '');
    $description = wp_kses_post($_POST['description'] ?? '');

    if ( empty($name) ) wp_send_json_error(['message' => 'Name is required.'], 400);
    if ( empty($description) ) wp_send_json_error(['message' => 'Description is empty.'], 400);

    $history = get_option('myls_pb_desc_history', []);
    if ( ! is_array($history) ) $history = [];

    // Use slug as key for easy overwrite
    $slug = sanitize_title($name);
    $history[$slug] = [
        'name'        => $name,
        'description' => $description,
        'updated'     => current_time('mysql'),
    ];

    // Keep max 50 entries
    if ( count($history) > 50 ) {
        $history = array_slice($history, -50, 50, true);
    }

    update_option('myls_pb_desc_history', $history);
    wp_send_json_success([
        'message' => "Description \"{$name}\" saved.",
        'history' => myls_pb_format_history($history),
    ]);
});

/**
 * AJAX: List all saved descriptions
 */
add_action('wp_ajax_myls_pb_list_descriptions', function () {
    if ( ! current_user_can('manage_options') ) wp_send_json_error([], 403);

    $history = get_option('myls_pb_desc_history', []);
    if ( ! is_array($history) ) $history = [];

    wp_send_json_success(['history' => myls_pb_format_history($history)]);
});

/**
 * AJAX: Delete a saved description
 */
add_action('wp_ajax_myls_pb_delete_description', function () {
    if ( ! current_user_can('manage_options') ) wp_send_json_error(['message' => 'Forbidden'], 403);
    if ( ! wp_verify_nonce($_POST['_wpnonce'] ?? '', 'myls_pb_create') ) wp_send_json_error(['message' => 'Bad nonce'], 400);

    $slug = sanitize_title($_POST['desc_slug'] ?? '');
    if ( empty($slug) ) wp_send_json_error(['message' => 'Invalid entry.'], 400);

    $history = get_option('myls_pb_desc_history', []);
    if ( ! is_array($history) ) $history = [];

    $name = $history[$slug]['name'] ?? $slug;
    unset($history[$slug]);

    update_option('myls_pb_desc_history', $history);
    wp_send_json_success([
        'message' => "Deleted \"{$name}\".",
        'history' => myls_pb_format_history($history),
    ]);
});

/**
 * Format history entries for JSON response
 */
function myls_pb_format_history(array $history): array {
    $out = [];
    foreach ( $history as $slug => $entry ) {
        $out[] = [
            'slug'        => $slug,
            'name'        => $entry['name'] ?? $slug,
            'description' => $entry['description'] ?? '',
            'updated'     => $entry['updated'] ?? '',
        ];
    }
    // Sort by updated desc
    usort($out, function($a, $b) { return strcmp($b['updated'], $a['updated']); });
    return $out;
}

/**
 * AJAX: Save the Page Builder prompt template
 */
add_action('wp_ajax_myls_pb_save_prompt', function () {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error(['message' => 'Forbidden'], 403);
    }
    if ( ! wp_verify_nonce($_POST['_wpnonce'] ?? '', 'myls_pb_create') ) {
        wp_send_json_error(['message' => 'Bad nonce'], 400);
    }
    $template = wp_kses_post($_POST['prompt_template'] ?? '');
    update_option('myls_pb_prompt_template', $template);
    wp_send_json_success(['message' => 'Prompt template saved.']);
});
