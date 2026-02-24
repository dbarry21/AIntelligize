<?php
/**
 * AJAX handler: AI Image Generation for Page Builder
 * Path: inc/ajax/ai-image-gen.php
 *
 * Uses OpenAI DALL-E 3 to generate images, upload to WP Media Library,
 * and optionally set as featured image or insert into page content.
 *
 * Actions:
 *   myls_pb_generate_images  – Generate images and attach to a post
 */
if ( ! defined('ABSPATH') ) exit;

add_action('wp_ajax_myls_pb_generate_images', function () {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error(['message' => 'Forbidden'], 403);
    }
    if ( ! wp_verify_nonce($_POST['_wpnonce'] ?? '', 'myls_pb_create') ) {
        wp_send_json_error(['message' => 'Bad nonce'], 400);
    }

    $post_id     = (int) ($_POST['post_id'] ?? 0);
    $page_title  = sanitize_text_field($_POST['page_title'] ?? '');
    $description = wp_kses_post($_POST['description'] ?? '');
    $image_style = sanitize_text_field($_POST['image_style'] ?? 'modern-flat');
    $set_featured = ! empty($_POST['set_featured']);
    $insert_hero  = ! empty($_POST['insert_hero']);

    // What images to generate
    $gen_hero     = ! empty($_POST['gen_hero']);
    $gen_feature  = ! empty($_POST['gen_feature']);
    $feature_count = max(0, min(6, (int) ($_POST['feature_count'] ?? 0)));

    if ( ! $page_title && ! $description ) {
        wp_send_json_error(['message' => 'Need a page title or description to generate images.'], 400);
    }

    $api_key = function_exists('myls_openai_get_api_key') ? myls_openai_get_api_key() : '';
    if ( empty($api_key) ) {
        wp_send_json_error(['message' => 'OpenAI API key not configured.'], 400);
    }

    $log = [];
    $images = [];

    // ── Style presets ──────────────────────────────────────────────────
    $style_map = [
        'modern-flat'   => 'Modern flat design illustration, clean lines, soft gradients, professional color palette, minimalist',
        'photorealistic'=> 'Professional stock photography style, high quality, well-lit, clean background',
        'isometric'     => 'Isometric 3D illustration, colorful, tech-forward, clean white background',
        'watercolor'    => 'Soft watercolor style illustration, artistic, professional, warm tones',
        'gradient-abstract' => 'Abstract gradient art, flowing shapes, modern tech aesthetic, vivid colors',
    ];
    $style_suffix = $style_map[$image_style] ?? $style_map['modern-flat'];

    // ── Generate hero image ────────────────────────────────────────────
    if ( $gen_hero ) {
        $log[] = "🎨 Generating hero image…";
        $hero_prompt = "Create a wide banner/hero image for a webpage about: {$page_title}. ";
        if ( $description ) {
            $short_desc = mb_substr(wp_strip_all_tags($description), 0, 300);
            $hero_prompt .= "Context: {$short_desc}. ";
        }
        $hero_prompt .= "Style: {$style_suffix}. Landscape orientation, 1792x1024, no text or words in the image.";

        $result = myls_pb_dall_e_generate($api_key, $hero_prompt, '1792x1024');
        if ( $result['ok'] ) {
            $attach_id = myls_pb_upload_image_from_url(
                $result['url'],
                sanitize_title($page_title) . '-hero',
                $page_title . ' - Hero Image',
                $post_id
            );
            if ( $attach_id ) {
                $images[] = ['type' => 'hero', 'id' => $attach_id, 'url' => wp_get_attachment_url($attach_id)];
                $log[] = "   ✅ Hero image uploaded (ID: {$attach_id})";

                if ( $set_featured && $post_id ) {
                    set_post_thumbnail($post_id, $attach_id);
                    $log[] = "   📌 Set as Featured Image";
                }
                if ( $insert_hero && $post_id ) {
                    myls_pb_insert_hero_image($post_id, $attach_id);
                    $log[] = "   📄 Inserted into page content";
                }
            } else {
                $log[] = "   ❌ Failed to upload hero image to media library";
            }
        } else {
            $log[] = "   ❌ Hero: " . $result['error'];
        }
    }

    // ── Generate feature/section images ────────────────────────────────
    if ( $gen_feature && $feature_count > 0 ) {
        $log[] = "🎨 Generating {$feature_count} feature image(s)…";

        // Ask AI to suggest image subjects based on the description
        $subjects = myls_pb_suggest_image_subjects($page_title, $description, $feature_count);

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
                    $post_id
                );
                if ( $attach_id ) {
                    $images[] = ['type' => 'feature', 'id' => $attach_id, 'url' => wp_get_attachment_url($attach_id), 'subject' => $subject];
                    $log[] = "   ✅ Feature " . ($i + 1) . ": \"{$subject}\" (ID: {$attach_id})";
                } else {
                    $log[] = "   ❌ Feature " . ($i + 1) . ": upload failed";
                }
            } else {
                $log[] = "   ❌ Feature " . ($i + 1) . ": " . $result['error'];
            }
        }
    }

    if ( empty($images) && ($gen_hero || $gen_feature) ) {
        $log[] = "⚠️ No images were generated. Check your OpenAI API key and billing.";
    }

    $log[] = "";
    $log[] = "Done. " . count($images) . " image(s) added to Media Library.";
    if ( $post_id ) {
        $log[] = "Edit page: " . admin_url('post.php?post=' . $post_id . '&action=edit');
    }

    wp_send_json_success([
        'message' => count($images) . ' image(s) generated.',
        'log_text' => implode("\n", $log),
        'images'  => $images,
    ]);
});

/**
 * Call DALL-E 3 API to generate a single image.
 */
function myls_pb_dall_e_generate(string $api_key, string $prompt, string $size = '1024x1024'): array {
    $body = [
        'model'   => 'dall-e-3',
        'prompt'  => $prompt,
        'n'       => 1,
        'size'    => $size,
        'quality' => 'standard',
    ];

    $resp = wp_remote_post('https://api.openai.com/v1/images/generations', [
        'timeout' => 90,
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => wp_json_encode($body),
    ]);

    if ( is_wp_error($resp) ) {
        return ['ok' => false, 'error' => $resp->get_error_message()];
    }

    $code = wp_remote_retrieve_response_code($resp);
    $json = json_decode(wp_remote_retrieve_body($resp), true);

    if ( $code !== 200 || empty($json['data'][0]['url']) ) {
        $err = $json['error']['message'] ?? "HTTP {$code}";
        return ['ok' => false, 'error' => $err];
    }

    return ['ok' => true, 'url' => $json['data'][0]['url']];
}

/**
 * Download an image from a URL and upload to WP Media Library.
 */
function myls_pb_upload_image_from_url(string $image_url, string $filename, string $title, int $parent_post_id = 0): int {
    if ( ! function_exists('media_sideload_image') ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    // Download to temp file
    $tmp = download_url($image_url, 90);
    if ( is_wp_error($tmp) ) {
        error_log('[MYLS] Image download failed: ' . $tmp->get_error_message());
        return 0;
    }

    $file_array = [
        'name'     => sanitize_file_name($filename . '.png'),
        'tmp_name' => $tmp,
    ];

    $attach_id = media_handle_sideload($file_array, $parent_post_id, $title);

    if ( is_wp_error($attach_id) ) {
        @unlink($tmp);
        error_log('[MYLS] Image sideload failed: ' . $attach_id->get_error_message());
        return 0;
    }

    // Set alt text
    update_post_meta($attach_id, '_wp_attachment_image_alt', $title);

    return (int) $attach_id;
}

/**
 * Insert a hero image at the top of the post content.
 */
function myls_pb_insert_hero_image(int $post_id, int $attach_id): void {
    $post = get_post($post_id);
    if ( ! $post ) return;

    $img_url = wp_get_attachment_url($attach_id);
    $alt     = get_post_meta($attach_id, '_wp_attachment_image_alt', true);

    $hero_html = sprintf(
        '<figure class="wp-block-image size-full mb-4"><img src="%s" alt="%s" class="img-fluid rounded" style="width:100%%;max-height:420px;object-fit:cover;"/></figure>',
        esc_url($img_url),
        esc_attr($alt)
    );

    $updated_content = $hero_html . "\n" . $post->post_content;

    wp_update_post([
        'ID'           => $post_id,
        'post_content' => $updated_content,
    ]);
}

/**
 * Use AI to suggest image subjects based on the page description.
 */
function myls_pb_suggest_image_subjects(string $page_title, string $description, int $count): array {
    if ( ! function_exists('myls_ai_chat') && ! function_exists('myls_openai_chat') ) {
        // Fallback: generic subjects
        $fallback = [];
        for ($i = 1; $i <= $count; $i++) {
            $fallback[] = "Feature {$i} of {$page_title}";
        }
        return $fallback;
    }

    $prompt = "For a webpage titled \"{$page_title}\", suggest exactly {$count} short image subject descriptions (1 line each, max 10 words). ";
    if ( $description ) {
        $short = mb_substr(wp_strip_all_tags($description), 0, 500);
        $prompt .= "Page description: {$short}. ";
    }
    $prompt .= "Output ONLY the {$count} subjects, one per line, no numbering, no explanation.";

    $chat_fn = function_exists('myls_ai_chat') ? 'myls_ai_chat' : 'myls_openai_chat';
    $result = $chat_fn($prompt, [
        'model'       => '',
        'max_tokens'  => 200,
        'temperature' => 0.8,
        'system'      => 'You suggest concise image subjects for illustrations. Output only the subjects, one per line.',
    ]);

    $lines = array_filter(array_map('trim', explode("\n", $result)));
    $lines = array_values($lines);

    // Pad if AI returned fewer than requested
    while ( count($lines) < $count ) {
        $lines[] = "Feature " . (count($lines) + 1) . " of {$page_title}";
    }

    return array_slice($lines, 0, $count);
}
