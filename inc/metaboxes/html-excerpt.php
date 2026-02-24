<?php
/**
 * AIntelligize – HTML Excerpt Metabox (WYSIWYG)
 * Path: inc/metaboxes/html-excerpt.php
 *
 * Adds a metabox for editing the `html_excerpt` post meta field.
 * - Full WYSIWYG editor (wp_editor / TinyMCE)
 * - AI generation button (pulls prompt from plugin admin template)
 * - Used by [service_area_grid] shortcode for rich excerpt display
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Register the HTML Excerpt metabox on all public post types
 */
add_action('add_meta_boxes', function() {
    $post_types = get_post_types(['public' => true], 'names');
    unset($post_types['attachment']);

    foreach ($post_types as $post_type) {
        add_meta_box(
            'myls_html_excerpt_box',
            '<span class="dashicons dashicons-text-page" style="margin-right:5px;"></span> HTML Excerpt',
            'myls_html_excerpt_render',
            $post_type,
            'normal',
            'high'
        );
    }
});

/**
 * Render the HTML Excerpt metabox
 */
function myls_html_excerpt_render( $post ) {
    wp_nonce_field('myls_html_excerpt_save', 'myls_html_excerpt_nonce');

    // Get current html_excerpt from post meta
    $html_excerpt = '';
    if ( function_exists('get_field') ) {
        $html_excerpt = (string) get_field('html_excerpt', $post->ID);
    }
    if ( $html_excerpt === '' ) {
        $html_excerpt = (string) get_post_meta($post->ID, 'html_excerpt', true);
    }

    // Check if AI API key is configured (provider-agnostic)
    $has_api_key = function_exists('myls_ai_has_key') ? myls_ai_has_key() : ! empty( trim( get_option('myls_openai_api_key', '') ) );

    // Editor ID must be lowercase, no hyphens
    $editor_id = 'myls_html_excerpt';
    ?>
    <div class="myls-html-excerpt-box" style="padding: 5px 0;">

        <!-- Info Box -->
        <div style="margin-bottom: 12px; padding: 10px; background: #f0f6fc; border-left: 3px solid #2271b1; font-size: 12px;">
            <strong>💡 HTML Excerpt</strong> — Rich excerpt displayed by the <code>[service_area_grid]</code> shortcode.
            Falls back to the standard WP excerpt if empty.
            <span style="float:right;color:#888;">Stored as: <code>html_excerpt</code> post meta</span>
        </div>

        <!-- WYSIWYG Editor -->
        <?php
        wp_editor( $html_excerpt, $editor_id, [
            'textarea_name' => 'myls_html_excerpt',
            'textarea_rows' => 6,
            'media_buttons' => false,
            'teeny'         => false,
            'quicktags'     => true,
            'tinymce'       => [
                'toolbar1'       => 'bold,italic,link,unlink,bullist,numlist,blockquote,removeformat,undo,redo',
                'toolbar2'       => '',
                'block_formats'  => 'Paragraph=p;Heading 3=h3;Heading 4=h4',
                'valid_elements' => 'p,br,strong/b,em/i,a[href|target|rel|title],ul,ol,li,h3,h4,blockquote,span[style]',
                'statusbar'      => false,
            ],
        ]);
        ?>

        <!-- AI Generation -->
        <?php if ( $has_api_key ) : ?>
            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #ddd;">
                <button
                    type="button"
                    id="myls_generate_html_excerpt_btn"
                    class="button button-secondary"
                    style="width: 100%;"
                >
                    <span class="dashicons dashicons-superhero" style="margin-top: 3px;"></span>
                    Generate HTML Excerpt with AI
                </button>
                <p id="myls_html_excerpt_status" style="margin: 8px 0 0 0; font-size: 12px; color: #666; text-align: center;"></p>
            </div>
        <?php else : ?>
            <div style="margin-top: 12px; padding: 8px; background: #fff3cd; border-left: 3px solid #ffc107; font-size: 12px;">
                <strong>💡 AI Generation Available</strong><br>
                <a href="<?php echo admin_url('admin.php?page=aintelligize&tab=api-integration'); ?>">
                    Configure AI API key
                </a> to auto-generate HTML excerpts.
            </div>
        <?php endif; ?>
    </div>

    <?php if ( $has_api_key ) : ?>
    <script>
    jQuery(function($) {
        var editorId = '<?php echo esc_js($editor_id); ?>';
        var postId   = <?php echo (int) $post->ID; ?>;

        /**
         * Get content from whichever editor mode is active (Visual or Text)
         */
        function getEditorContent() {
            var ed = (typeof tinymce !== 'undefined') ? tinymce.get(editorId) : null;
            if (ed && !ed.isHidden()) {
                return ed.getContent();
            }
            return $('#' + editorId).val() || '';
        }

        /**
         * Set content into whichever editor mode is active
         */
        function setEditorContent(html) {
            var ed = (typeof tinymce !== 'undefined') ? tinymce.get(editorId) : null;
            if (ed && !ed.isHidden()) {
                ed.setContent(html);
                ed.fire('change');
            } else {
                $('#' + editorId).val(html).trigger('input');
            }
        }

        // AI Generation
        $('#myls_generate_html_excerpt_btn').on('click', function(e) {
            e.preventDefault();

            var $btn    = $(this);
            var $status = $('#myls_html_excerpt_status');
            var originalText = $btn.html();

            // Confirm if excerpt exists
            var current = getEditorContent().trim();
            if (current !== '') {
                if (!confirm('Replace existing HTML excerpt with AI-generated one?')) {
                    return;
                }
            }

            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update myls-spin"></span> Generating...');
            $status.html('<span class="dashicons dashicons-update myls-spin"></span> Generating excerpt...').css('color', '#2271b1');

            $.post(ajaxurl, {
                action:  'myls_ai_html_excerpt_generate_single',
                post_id: postId,
                nonce:   $('#myls_html_excerpt_nonce').val()
            }, function(response) {
                if (response.success && response.data.html_excerpt) {
                    setEditorContent(response.data.html_excerpt);
                    $status.html('<span class="dashicons dashicons-yes-alt"></span> Generated! Review and save/update the post.').css('color', '#00a32a');
                } else {
                    var msg = (response.data && response.data.message) ? response.data.message : 'Generation failed';
                    $status.html('<span class="dashicons dashicons-dismiss"></span> ' + msg).css('color', '#d63638');
                }
                $btn.prop('disabled', false).html(originalText);
            }).fail(function() {
                $status.html('<span class="dashicons dashicons-dismiss"></span> AJAX error').css('color', '#d63638');
                $btn.prop('disabled', false).html(originalText);
            });
        });
    });
    </script>

    <style>
    @keyframes myls-spin-anim {
        from { transform: rotate(0deg); }
        to { transform: rotate(359deg); }
    }
    .myls-spin {
        display: inline-block;
        animation: myls-spin-anim 2s infinite linear;
    }
    </style>
    <?php endif; ?>
    <?php
}

/**
 * Save html_excerpt on post save
 */
add_action('save_post', function( $post_id, $post ) {
    if ( ! isset($_POST['myls_html_excerpt_nonce']) ) return;
    if ( ! wp_verify_nonce($_POST['myls_html_excerpt_nonce'], 'myls_html_excerpt_save') ) return;
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;

    $post_type_obj = get_post_type_object($post->post_type);
    if ( ! $post_type_obj || ! $post_type_obj->public ) return;
    if ( $post->post_type === 'attachment' ) return;

    if ( isset($_POST['myls_html_excerpt']) ) {
        $html_excerpt = wp_kses_post($_POST['myls_html_excerpt']);
        update_post_meta($post_id, 'html_excerpt', $html_excerpt);
    }
}, 10, 2);
