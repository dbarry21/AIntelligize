<?php
/**
 * Utilities Subtab: Paste a Post
 * Path: admin/tabs/utilities/subtab-paste-post.php
 *
 * Lets you paste content from Google Docs or Word into a WYSIWYG editor,
 * cleans the HTML (strips span/font/inline-styles), generates a title and
 * excerpt via AI, creates a standard WordPress post (works with Elementor,
 * Divi, Classic), generates a DALL-E Featured Image, and inserts a second
 * DALL-E image after the 2nd paragraph.  External links get target="_blank".
 *
 * AJAX: wp_ajax_myls_paste_post_create
 */

if ( ! defined('ABSPATH') ) exit;

// ─────────────────────────────────────────────────────────────────────────────
// Helper functions (defined once, guarded)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Strip disallowed tags and attributes from pasted HTML.
 * Keeps: h1-h3, ul, ol, li, p, a[href], br, strong, em, b, i, blockquote.
 * Removes: span, font, div, table, class=, style=, id= on all tags.
 */
if ( ! function_exists('myls_paste_clean_html') ) {
    function myls_paste_clean_html( string $raw ) : string {
        // 1. Decode any HTML entities so we work with real characters
        $html = html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        // 2. strip_tags keeps the *content* of removed tags — so span/font text survives
        $allowed = '<h1><h2><h3><ul><ol><li><p><a><br><strong><em><b><i><blockquote>';
        $html = strip_tags( $html, $allowed );

        // 3. Strip ALL attributes from block/inline tags — except <a> which gets href only
        // Remove attrs from structural tags
        $html = preg_replace(
            '/<(h[1-3]|ul|ol|li|p|strong|em|b|i|blockquote|br)\s+[^>]*>/i',
            '<$1>',
            $html
        );

        // Reduce <a> to href-only (drop style, class, target, rel, data-*, etc.)
        $html = preg_replace_callback(
            '/<a(\s[^>]*)>/i',
            function ( $m ) {
                $attrs = $m[1];
                // Extract href value (double or single quoted, or unquoted)
                if ( preg_match( '/\bhref\s*=\s*"([^"]*)"/i', $attrs, $hm ) ) {
                    return '<a href="' . $hm[1] . '">';
                }
                if ( preg_match( "/\\bhref\\s*=\\s*'([^']*)'/i", $attrs, $hm ) ) {
                    return '<a href="' . $hm[1] . '">';
                }
                if ( preg_match( '/\bhref\s*=\s*(\S+)/i', $attrs, $hm ) ) {
                    return '<a href="' . rtrim( $hm[1], '>' ) . '">';
                }
                return '<a>'; // no href — keep tag but empty
            },
            $html
        );

        // 4. Collapse runs of whitespace (but preserve newlines between block elements)
        $html = preg_replace( '/[ \t]+/', ' ', $html );

        // 5. Remove empty paragraph tags
        $html = preg_replace( '/<p>\s*<\/p>/', '', $html );

        // 6. Tidy up excessive blank lines
        $html = preg_replace( '/(\r?\n){3,}/', "\n\n", $html );

        return trim( $html );
    }
}

/**
 * Add target="_blank" rel="noopener noreferrer" to all external links.
 * Internal links (same domain) are left untouched.
 */
if ( ! function_exists('myls_paste_fix_external_links') ) {
    function myls_paste_fix_external_links( string $html, string $site_domain ) : string {
        return preg_replace_callback(
            '/<a\s+href="([^"]*)"([^>]*)>/i',
            function ( $m ) use ( $site_domain ) {
                $href  = $m[1];
                $extra = $m[2]; // any remaining attributes (should be empty after clean)

                // Determine if external: must start with http/https and not contain site domain
                $is_external = (
                    preg_match( '/^https?:\/\//i', $href ) &&
                    strpos( $href, $site_domain ) === false
                );

                if ( $is_external ) {
                    return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer"' . $extra . '>';
                }
                return '<a href="' . $href . '"' . $extra . '>';
            },
            $html
        );
    }
}

/**
 * Insert an image figure after the Nth closing </p> tag.
 * Falls back to appending at the end if there are fewer than N paragraphs.
 */
if ( ! function_exists('myls_paste_insert_after_paragraph') ) {
    function myls_paste_insert_after_paragraph( string $content, string $img_html, int $after_p = 2 ) : string {
        $count = 0;
        $result = preg_replace_callback(
            '/<\/p>/i',
            function ( $m ) use ( &$count, $img_html, $after_p ) {
                $count++;
                if ( $count === $after_p ) {
                    return '</p>' . "\n" . $img_html;
                }
                return $m[0];
            },
            $content
        );

        // Fewer than $after_p paragraphs — just append
        if ( $count < $after_p ) {
            $result = $content . "\n" . $img_html;
        }

        return $result ?? $content;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX: myls_paste_post_create
// Registered here (file included at admin_init via myls_include_dir)
// ─────────────────────────────────────────────────────────────────────────────

if ( ! has_action('wp_ajax_myls_paste_post_create') ) {
    add_action('wp_ajax_myls_paste_post_create', function () {

        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'myls_paste_post' ) ) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }

        $log = [];

        // ── Inputs ──────────────────────────────────────────────────────────
        $raw_content    = wp_unslash( $_POST['content']       ?? '' );
        $title          = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        $post_type      = sanitize_key( $_POST['post_type']    ?? 'post' );
        $gen_images     = ! empty( $_POST['generate_images'] );
        $post_status    = sanitize_key( $_POST['post_status']  ?? 'draft' );
        $schedule_date  = sanitize_text_field( wp_unslash( $_POST['schedule_date'] ?? '' ) );

        // ── Resolve scheduled publish ────────────────────────────────────────
        // When the user picks "future", $schedule_date is a local datetime string
        // like "2026-03-10T14:30" from <input type="datetime-local">.
        // WordPress needs post_date (site local) and post_date_gmt (UTC).
        $post_date     = '';
        $post_date_gmt = '';
        if ( $post_status === 'future' && ! empty( $schedule_date ) ) {
            // Parse as a local time in the site's timezone
            $tz      = wp_timezone();
            try {
                $dt          = new DateTime( $schedule_date, $tz );
                $post_date   = $dt->format('Y-m-d H:i:s');           // local
                $dt->setTimezone( new DateTimeZone('UTC') );
                $post_date_gmt = $dt->format('Y-m-d H:i:s');         // UTC
            } catch ( Exception $e ) {
                $post_status = 'draft'; // fallback if date parse fails
                $log[] = "⚠️ Could not parse schedule date — saved as draft.";
            }
        } elseif ( $post_status === 'future' ) {
            // No date supplied with "future" — fall back to draft
            $post_status = 'draft';
        }

        if ( empty( trim( $raw_content ) ) ) {
            wp_send_json_error(['message' => 'Content is empty. Please paste some content first.']);
        }

        // Validate post type
        if ( ! post_type_exists( $post_type ) ) {
            $post_type = 'post';
        }

        // ── Step 1: Clean HTML ───────────────────────────────────────────────
        $log[] = '🧹 Cleaning HTML…';
        $clean_html = myls_paste_clean_html( $raw_content );
        $log[] = '   ✅ Removed span/font/div/style attributes — kept h1–h3, p, ul, ol, li, a, strong, em, blockquote';

        // ── Step 2: Fix external links ───────────────────────────────────────
        $site_domain = wp_parse_url( get_site_url(), PHP_URL_HOST );
        $clean_html  = myls_paste_fix_external_links( $clean_html, $site_domain );
        $log[] = '   ✅ External links updated (target="_blank" rel="noopener noreferrer")';

        // ── Step 3: AI — generate title if blank ────────────────────────────
        $ai_available = function_exists('myls_ai_generate_text');
        if ( empty( $title ) ) {
            if ( $ai_available ) {
                $log[] = '🤖 No title supplied — generating with AI…';
                $preview = mb_substr( wp_strip_all_tags( $clean_html ), 0, 600 );
                $t_prompt = "Based on this blog post content, write a clear and engaging SEO-friendly title (max 65 characters). Output ONLY the title, no quotes, no explanation.\n\nContent excerpt:\n{$preview}";
                myls_ai_set_usage_context('paste_post_title');
                $ai_title = myls_ai_generate_text( $t_prompt, ['max_tokens' => 80] );
                $title = trim( strip_tags( $ai_title ) );
                if ( empty( $title ) ) $title = 'New Blog Post';
                $log[] = "   ✅ Title: \"{$title}\"";
            } else {
                $title = 'New Blog Post';
                $log[] = '   ⚠️ AI unavailable — using placeholder title';
            }
        } else {
            $log[] = "📝 Using supplied title: \"{$title}\"";
        }

        // ── Step 4: AI — generate excerpt ────────────────────────────────────
        $excerpt = '';
        if ( $ai_available ) {
            $log[] = '🤖 Generating excerpt…';
            $content_preview = mb_substr( wp_strip_all_tags( $clean_html ), 0, 900 );
            $e_prompt = "Write a compelling 1–2 sentence meta description/excerpt for this blog post (max 160 characters). Output ONLY the excerpt, no quotes, no preamble.\n\nTitle: {$title}\nContent: {$content_preview}";
            myls_ai_set_usage_context('paste_post_excerpt');
            $ai_excerpt = myls_ai_generate_text( $e_prompt, ['max_tokens' => 120] );
            $excerpt = trim( strip_tags( $ai_excerpt ) );
            $log[] = '   ✅ Excerpt generated';
        } else {
            $log[] = '   ⚠️ AI unavailable — excerpt skipped';
        }

        // ── Step 5: Create the WordPress post ───────────────────────────────
        $log[] = '📝 Creating WordPress post…';
        $insert_args = [
            'post_title'   => $title,
            'post_content' => $clean_html,
            'post_excerpt' => $excerpt,
            'post_type'    => $post_type,
            'post_status'  => $post_status,
            'meta_input'   => [
                '_myls_paste_post' => '1',
            ],
        ];
        if ( $post_date )     $insert_args['post_date']     = $post_date;
        if ( $post_date_gmt ) $insert_args['post_date_gmt'] = $post_date_gmt;

        $post_id = wp_insert_post( $insert_args, true );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error([
                'message' => 'Failed to create post: ' . $post_id->get_error_message(),
                'log'     => implode("\n", $log),
            ]);
        }
        $status_label = $post_status;
        if ( $post_status === 'future' && $post_date ) {
            $status_label = "scheduled for {$post_date} (site time)";
        }
        $log[] = "   ✅ Post created (ID: {$post_id}, status: {$status_label})";

        // ── Step 6: DALL-E images ────────────────────────────────────────────
        if ( $gen_images ) {
            $api_key = function_exists('myls_openai_get_api_key') ? myls_openai_get_api_key() : trim( (string) get_option('myls_openai_api_key', '') );
            if ( empty( $api_key ) ) {
                $log[] = '⚠️ No OpenAI API key found — skipping image generation';
                $log[] = '   Set your key under AI → API Integration to enable DALL-E.';
            } elseif ( ! function_exists('myls_pb_dall_e_generate') || ! function_exists('myls_pb_upload_image_from_url') ) {
                $log[] = '⚠️ Image generation functions unavailable — skipping';
            } else {
                // ── 6a. Featured image ─────────────────────────────────────
                $log[] = '🎨 Generating Featured Image (DALL-E 3, 1792×1024)…';
                $feat_prompt = sprintf(
                    'Wide hero banner photograph for a blog post titled "%s". Context: %s. Landscape 1792x1024, professional photography, no text or words anywhere in the image.',
                    $title,
                    mb_substr( $excerpt ?: wp_strip_all_tags( $clean_html ), 0, 200 )
                );
                $feat_result = myls_pb_dall_e_generate( $api_key, $feat_prompt, '1792x1024', 'natural' );
                if ( $feat_result['ok'] ) {
                    $feat_attach = myls_pb_upload_image_from_url(
                        $feat_result['url'],
                        sanitize_title( $title ) . '-featured',
                        $title . ' – Featured Image',
                        $post_id
                    );
                    if ( $feat_attach ) {
                        set_post_thumbnail( $post_id, $feat_attach );
                        $log[] = "   ✅ Featured image set (attachment ID: {$feat_attach})";
                    } else {
                        $log[] = '   ❌ Featured image: upload to Media Library failed';
                    }
                } else {
                    $log[] = '   ❌ Featured image: ' . $feat_result['error'];
                }

                // ── 6b. Inline image (after 2nd paragraph) ────────────────
                $log[] = '🎨 Generating inline image (DALL-E 3, 1024×1024)…';
                $inline_prompt = sprintf(
                    'High-quality editorial photograph illustrating the topic: "%s". Square composition, no text or words in the image, clean professional style.',
                    $title
                );
                $inline_result = myls_pb_dall_e_generate( $api_key, $inline_prompt, '1024x1024', 'natural' );
                if ( $inline_result['ok'] ) {
                    $inline_attach = myls_pb_upload_image_from_url(
                        $inline_result['url'],
                        sanitize_title( $title ) . '-inline',
                        $title . ' – Inline Image',
                        $post_id
                    );
                    if ( $inline_attach ) {
                        $img_url  = wp_get_attachment_url( $inline_attach );
                        $img_html = sprintf(
                            "\n<figure class=\"wp-block-image aligncenter size-large\" style=\"margin:1.5em 0;text-align:center;\">\n" .
                            "  <img src=\"%s\" alt=\"%s\" class=\"wp-image-%d\" style=\"max-width:100%%;height:auto;border-radius:6px;\"/>\n" .
                            "</figure>\n",
                            esc_url( $img_url ),
                            esc_attr( $title ),
                            $inline_attach
                        );

                        // Insert after 2nd paragraph; use $wpdb to avoid save_post hook cascade
                        $updated_content = myls_paste_insert_after_paragraph( $clean_html, $img_html, 2 );
                        global $wpdb;
                        $wpdb->update(
                            $wpdb->posts,
                            ['post_content' => $updated_content],
                            ['ID' => $post_id],
                            ['%s'],
                            ['%d']
                        );
                        clean_post_cache( $post_id );
                        $log[] = "   ✅ Inline image inserted after paragraph 2 (attachment ID: {$inline_attach})";
                    } else {
                        $log[] = '   ❌ Inline image: upload to Media Library failed';
                    }
                } else {
                    $log[] = '   ❌ Inline image: ' . $inline_result['error'];
                }
            }
        }

        $log[] = '';
        $log[] = '✅ All done!';

        wp_send_json_success([
            'log'      => implode("\n", $log),
            'post_id'  => $post_id,
            'title'    => $title,
            'edit_url' => admin_url( "post.php?post={$post_id}&action=edit" ),
            'view_url' => (string) get_permalink( $post_id ),
        ]);
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// Subtab spec (returned to tab-utilities.php loader)
// ─────────────────────────────────────────────────────────────────────────────

return [
    'id'     => 'paste-post',
    'label'  => 'Paste a Post',
    'order'  => 88,
    'render' => function () {

        $nonce = wp_create_nonce('myls_paste_post');

        // Public post types for the selector
        $post_types = get_post_types(['public' => true], 'objects');
        unset($post_types['attachment']);

        ?>
        <style>
        /* ── Paste a Post scoped styles ────────────────────────────────────── */
        #myls-paste-post-wrap h2.myls-pap-heading {
            font-size:1.1rem; font-weight:700; color:#1d2327;
            margin:0 0 4px; padding:0;
        }
        #myls-paste-post-wrap .myls-pap-desc {
            color:#6c757d; font-size:13px; margin:0 0 18px;
        }
        #myls-paste-post-wrap .myls-pap-grid {
            display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;
        }
        @media(max-width:782px){ #myls-paste-post-wrap .myls-pap-grid { grid-template-columns:1fr; } }
        #myls-paste-post-wrap label.myls-pap-label {
            display:block; font-weight:600; font-size:13px; margin-bottom:4px; color:#1d2327;
        }
        #myls-paste-post-wrap input[type="text"],
        #myls-paste-post-wrap select {
            width:100%; padding:.45rem .6rem; border:1px solid #8c8f94;
            border-radius:4px; font-size:13px; background:#fff; color:#1d2327;
        }
        #myls-paste-post-wrap .myls-pap-editor-wrap {
            margin-bottom:14px;
        }
        #myls-paste-post-wrap .myls-pap-options {
            display:flex; align-items:center; gap:20px; flex-wrap:wrap;
            padding:10px 14px; background:#f6f7fb; border:1px solid #e6e6e6;
            border-radius:6px; margin-bottom:14px; font-size:13px;
        }
        #myls-paste-post-wrap .myls-pap-options label { font-weight:600; cursor:pointer; }
        #myls-paste-post-wrap #myls_paste_post_btn {
            font-size:14px; padding:.5rem 1.2rem; cursor:pointer;
        }
        #myls-paste-post-wrap #myls_paste_post_log {
            margin-top:14px; padding:12px 14px; background:#1e1e1e; color:#d4d4d4;
            font-family:monospace; font-size:12px; border-radius:6px;
            white-space:pre-wrap; max-height:280px; overflow-y:auto;
            display:none;
        }
        #myls-paste-post-wrap #myls_paste_post_links {
            margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;
        }
        #myls-paste-post-wrap .myls-pap-tip {
            font-size:12px; color:#6c757d; margin-top:5px;
        }
        #myls-paste-post-wrap .myls-pap-section-rule {
            border:none; border-top:1px solid #e6e6e6; margin:18px 0;
        }
        /* Permalink preview */
        #myls-paste-post-wrap .myls-pap-permalink {
            display:flex; align-items:center; gap:8px; flex-wrap:wrap;
            padding:8px 12px; background:#f0f6fc; border:1px solid #b8d4f0;
            border-radius:6px; margin-bottom:14px; font-size:12px; color:#1d2327;
        }
        #myls-paste-post-wrap .myls-pap-permalink .pap-pl-icon { color:#0073aa; flex-shrink:0; }
        #myls-paste-post-wrap .myls-pap-permalink .pap-pl-base { color:#6c757d; white-space:nowrap; }
        #myls-paste-post-wrap .myls-pap-permalink .pap-pl-slug { color:#0073aa; font-weight:700; word-break:break-all; }
        #myls-paste-post-wrap .myls-pap-permalink .pap-pl-trail { color:#6c757d; }
        /* Schedule panel */
        #myls-paste-post-wrap #myls_paste_schedule_panel {
            display:none; margin-top:10px;
            padding:12px 14px; background:#fffbec; border:1px solid #e6c94a;
            border-radius:6px; font-size:13px;
        }
        #myls-paste-post-wrap #myls_paste_schedule_panel label { font-weight:600; display:block; margin-bottom:6px; }
        #myls-paste-post-wrap #myls_paste_schedule_dt {
            padding:.4rem .6rem; border:1px solid #8c8f94; border-radius:4px;
            font-size:13px; background:#fff;
        }
        #myls-paste-post-wrap .pap-schedule-note {
            margin-top:6px; font-size:11px; color:#6c757d;
        }
        </style>

        <div id="myls-paste-post-wrap">
            <h2 class="myls-pap-heading">📋 Paste a Post</h2>
            <p class="myls-pap-desc">
                Paste content from Google Docs or a Word document. The editor accepts rich text — just
                use <kbd>Ctrl+V</kbd> / <kbd>⌘+V</kbd> to paste. Span, font, and inline style tags
                will be stripped server-side; semantic HTML (headings, lists, links) is preserved.
                AI will generate a title and excerpt. DALL-E can add a Featured Image and an inline image.
            </p>

            <input type="hidden" id="myls_paste_nonce" value="<?php echo esc_attr($nonce); ?>">

            <div class="myls-pap-grid">
                <div>
                    <label class="myls-pap-label" for="myls_paste_title">Post Title</label>
                    <input type="text" id="myls_paste_title" placeholder="Leave blank to auto-generate from content">
                </div>
                <div>
                    <label class="myls-pap-label" for="myls_paste_post_type">Post Type</label>
                    <select id="myls_paste_post_type">
                        <?php foreach ( $post_types as $pt ) : ?>
                            <option value="<?php echo esc_attr($pt->name); ?>"
                                <?php selected($pt->name, 'post'); ?>>
                                <?php echo esc_html($pt->label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php
            // Permalink base: site URL + any blog prefix
            $permalink_base = trailingslashit( get_site_url() );
            $permalink_struct = get_option('permalink_structure', '');
            // Check for date-based or other complex structures — just show base for simplicity
            ?>
            <div class="myls-pap-permalink" id="myls_paste_permalink_row">
                <button type="button" id="myls_paste_permalink_copy" title="Copy permalink to clipboard" style="background:none;border:none;cursor:pointer;padding:0;line-height:1;color:#0073aa;font-size:15px;" aria-label="Copy permalink">📋</button>
                <span class="pap-pl-base"><?php echo esc_html( $permalink_base ); ?></span><span class="pap-pl-slug" id="myls_paste_permalink_slug">your-post-title</span><span class="pap-pl-trail">/</span>
                <span id="myls_paste_permalink_copied" style="display:none;color:#46b450;font-size:11px;font-weight:600;">✔ Copied!</span>
            </div>

            <div class="myls-pap-editor-wrap">
                <label class="myls-pap-label">Post Content</label>
                <p class="myls-pap-tip">
                    Paste your Google Doc or Word document content here. Switch to the <strong>Text</strong>
                    tab to paste/edit raw HTML directly.
                </p>
                <?php
                // wp_editor must be called on screen render (not in AJAX)
                wp_editor( '', 'myls_paste_content', [
                    'textarea_name' => 'myls_paste_content',
                    'textarea_rows' => 20,
                    'media_buttons' => false,
                    'teeny'         => false,
                    'quicktags'     => true,
                    'tinymce'       => [
                        'paste_as_text'                   => false,
                        'paste_word_valid_elements'        => 'h1,h2,h3,p,br,ul,ol,li,a[href],strong,em,b,i,blockquote',
                        'paste_remove_styles'             => true,
                        'paste_retain_style_properties'   => '',
                        'valid_elements'                  => 'h1,h2,h3,p,br,ul,ol,li,a[href],strong,em,b,i,blockquote',
                        'statusbar'                       => false,
                    ],
                ]);
                ?>
            </div>

            <hr class="myls-pap-section-rule">

            <div class="myls-pap-options">
                <label>
                    <input type="checkbox" id="myls_paste_gen_images" checked>
                    🎨 Generate DALL-E images (Featured Image + inline after paragraph 2)
                </label>
                <div style="margin-left:auto;">
                    <label style="font-weight:normal; margin-right:6px;">Publish as:</label>
                    <select id="myls_paste_post_status" style="width:auto;display:inline-block;padding:.3rem .5rem;border:1px solid #8c8f94;border-radius:4px;font-size:13px;">
                        <option value="draft" selected>Draft</option>
                        <option value="publish">Published</option>
                        <option value="pending">Pending Review</option>
                        <option value="future">⏰ Scheduled</option>
                    </select>
                </div>
            </div>

            <div id="myls_paste_schedule_panel">
                <label for="myls_paste_schedule_dt">📅 Publish on (site time: <?php echo esc_html( wp_timezone_string() ); ?>)</label>
                <input type="datetime-local" id="myls_paste_schedule_dt" name="myls_paste_schedule_dt">
                <p class="pap-schedule-note">The post will be saved as <strong>Scheduled</strong> and will publish automatically at the chosen time.</p>
            </div>

            <button type="button" id="myls_paste_post_btn" class="button button-primary">
                🚀 Create Post
            </button>

            <pre id="myls_paste_post_log"></pre>
            <div id="myls_paste_post_links"></div>
        </div>

        <script>
        (function () {
            'use strict';

            const $ = function (id) { return document.getElementById(id); };

            const btn        = $('myls_paste_post_btn');
            const logEl      = $('myls_paste_post_log');
            const linksEl    = $('myls_paste_post_links');
            const titleInput = $('myls_paste_title');
            const slugEl     = $('myls_paste_permalink_slug');
            const statusSel  = $('myls_paste_post_status');
            const schedPanel = $('myls_paste_schedule_panel');
            const schedDt    = $('myls_paste_schedule_dt');

            if ( ! btn ) return;

            // ── Permalink slug preview ─────────────────────────────────────
            function toSlug( str ) {
                return str
                    .toLowerCase()
                    .replace( /<[^>]+>/g, '' )
                    .replace( /[''""]/g, '' )
                    .replace( /[^a-z0-9]+/g, '-' )
                    .replace( /^-+|-+$/g, '' );
            }

            function updatePermalink() {
                const val = ( titleInput ? titleInput.value : '' ).trim();
                if ( slugEl ) slugEl.textContent = val ? toSlug( val ) : 'your-post-title';
            }

            if ( titleInput ) {
                titleInput.addEventListener( 'input', updatePermalink );
                updatePermalink();
            }

            // ── Copy permalink to clipboard ────────────────────────────────
            const copyBtn    = $('myls_paste_permalink_copy');
            const copiedMsg  = $('myls_paste_permalink_copied');
            const plBase     = document.querySelector('.pap-pl-base');

            if ( copyBtn ) {
                copyBtn.addEventListener('click', function () {
                    const base = plBase ? plBase.textContent : '';
                    const slug = slugEl ? slugEl.textContent : '';
                    const full = base + slug + '/';
                    navigator.clipboard.writeText( full ).then(function () {
                        if ( copiedMsg ) {
                            copiedMsg.style.display = '';
                            setTimeout(function () { copiedMsg.style.display = 'none'; }, 2000);
                        }
                    }).catch(function () {
                        // Fallback for older browsers
                        const ta = document.createElement('textarea');
                        ta.value = full;
                        ta.style.position = 'fixed'; ta.style.opacity = '0';
                        document.body.appendChild(ta);
                        ta.select();
                        document.execCommand('copy');
                        document.body.removeChild(ta);
                        if ( copiedMsg ) {
                            copiedMsg.style.display = '';
                            setTimeout(function () { copiedMsg.style.display = 'none'; }, 2000);
                        }
                    });
                });
            }

            // ── Schedule panel toggle ──────────────────────────────────────
            function toggleSchedule() {
                if ( ! schedPanel || ! statusSel ) return;
                const isScheduled = statusSel.value === 'future';
                schedPanel.style.display = isScheduled ? 'block' : 'none';
                if ( isScheduled && schedDt && ! schedDt.value ) {
                    const d = new Date();
                    d.setDate( d.getDate() + 1 );
                    d.setHours( 9, 0, 0, 0 );
                    const pad = n => String(n).padStart(2,'0');
                    schedDt.value = d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' +
                                    pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
                }
            }

            if ( statusSel ) {
                statusSel.addEventListener( 'change', toggleSchedule );
                toggleSchedule();
            }

            // ── Create Post ────────────────────────────────────────────────
            btn.addEventListener('click', async function () {

                let content = '';
                if ( typeof tinymce !== 'undefined' && tinymce.get('myls_paste_content') ) {
                    content = tinymce.get('myls_paste_content').getContent();
                } else {
                    const ta = $('myls_paste_content');
                    content = ta ? ta.value : '';
                }

                if ( ! content.trim() ) {
                    logEl.textContent = '⚠️ Please paste some content into the editor first.';
                    logEl.style.display = '';
                    return;
                }

                const title      = ( titleInput ? titleInput.value : '' ).trim();
                const postType   = $('myls_paste_post_type').value;
                const postStatus = statusSel.value;
                const genImages  = $('myls_paste_gen_images').checked;

                if ( postStatus === 'future' ) {
                    if ( ! schedDt || ! schedDt.value ) {
                        logEl.textContent = '⚠️ Please choose a publish date/time for scheduled posts.';
                        logEl.style.display = '';
                        return;
                    }
                    if ( new Date( schedDt.value ) <= new Date() ) {
                        logEl.textContent = '⚠️ Scheduled time must be in the future.';
                        logEl.style.display = '';
                        return;
                    }
                }

                btn.disabled      = true;
                btn.textContent   = '⏳ Processing…';
                logEl.textContent = 'Sending to server…';
                logEl.style.display = '';
                linksEl.innerHTML   = '';

                const fd = new FormData();
                fd.append('action',      'myls_paste_post_create');
                fd.append('nonce',       $('myls_paste_nonce').value);
                fd.append('content',     content);
                fd.append('title',       title);
                fd.append('post_type',   postType);
                fd.append('post_status', postStatus);
                if ( postStatus === 'future' && schedDt && schedDt.value ) {
                    fd.append('schedule_date', schedDt.value);
                }
                if ( genImages ) fd.append('generate_images', '1');

                try {
                    const res  = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();

                    if ( data.success ) {
                        const d = data.data;
                        logEl.textContent = d.log || '✅ Done!';
                        linksEl.innerHTML =
                            '<a href="' + d.edit_url + '" class="button button-primary" target="_blank">' +
                            '✏️ Edit Post</a> ' +
                            '<a href="' + d.view_url + '" class="button" target="_blank">' +
                            '👁 Preview</a>';
                    } else {
                        logEl.textContent = '❌ ' + ( data.data?.message || 'Unknown error' );
                    }

                } catch ( err ) {
                    logEl.textContent = '❌ Network error: ' + err.message;
                } finally {
                    btn.disabled    = false;
                    btn.textContent = '🚀 Create Post';
                }
            });

        })();
        </script>
        <?php
    },
];
