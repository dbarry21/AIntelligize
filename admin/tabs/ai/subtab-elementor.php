<?php
/**
 * AI Subtab: Elementor Page Builder
 * Path: admin/tabs/ai/subtab-elementor.php
 *
 * Mirrors the native Page Builder subtab but writes content directly into
 * Elementor's _elementor_data JSON instead of post_content.
 *
 * Each AI-generated <section> becomes a discrete Elementor section containing
 * an HTML widget — fully editable in the Elementor canvas from day one.
 *
 * ⚠️  TEST TAB — runs alongside Page Builder while we validate Elementor output.
 */
if ( ! defined('ABSPATH') ) exit;

return [
    'id'    => 'elementor-builder',
    'label' => 'Elementor Builder',
    'icon'  => 'bi-layers',
    'order' => 71,          // Sits right after Page Builder (order 70)
    'render'=> function () {

        // All public + custom post types
        $pts = get_post_types( ['public' => true], 'objects' );
        unset( $pts['attachment'] );

        // Business vars
        $sb        = get_option( 'myls_sb_settings', [] );
        $biz_name  = $sb['business_name'] ?? get_bloginfo('name');
        $biz_city  = $sb['city']          ?? '';
        $biz_phone = $sb['phone']         ?? '';
        $biz_email = $sb['email']         ?? get_bloginfo('admin_email');

        // Saved prompt — detect and auto-clear stale HTML-output prompts
        $saved_prompt = get_option( 'myls_elb_prompt_template', '' );

        // If the saved prompt still contains old HTML-pipeline fingerprints,
        // clear it now so the textarea shows the current JSON default.
        $html_prompt_fingerprints = [ 'HTML RULES', 'Output raw HTML', 'elb-hero', 'Start directly with the first <section' ];
        $prompt_was_stale = false;
        foreach ( $html_prompt_fingerprints as $fp ) {
            if ( str_contains( $saved_prompt, $fp ) ) {
                $prompt_was_stale = true;
                break;
            }
        }
        if ( $prompt_was_stale ) {
            update_option( 'myls_elb_prompt_template', '' );
            $saved_prompt = '';
        }

        // Show current default if nothing saved
        if ( empty( trim( $saved_prompt ) ) ) {
            $saved_prompt = myls_get_default_prompt('elementor-builder');
        }

        // Elementor active check
        $elementor_active   = defined('ELEMENTOR_VERSION') || class_exists('\Elementor\Plugin');
        $elementor_version  = defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : ( class_exists('\Elementor\Plugin') ? 'detected' : '' );

        $nonce = wp_create_nonce( 'myls_elb_create' );
        $ajax  = admin_url( 'admin-ajax.php' );
        ?>

        <div style="display:grid; grid-template-columns:1fr 2fr; gap:20px;">

            <!-- ═══════════ LEFT: Page Setup ═══════════ -->
            <div style="border:1px solid #000; padding:16px; border-radius:1em;">

                <!-- Elementor status badge -->
                <div class="alert <?php echo $elementor_active ? 'alert-success' : 'alert-warning'; ?> d-flex align-items-center gap-2 mb-3 py-2 px-3" style="border-radius:8px;font-size:13px;">
                    <i class="bi <?php echo $elementor_active ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?>"></i>
                    <?php if ( $elementor_active ): ?>
                        <strong>Elementor Active</strong><?php echo $elementor_version ? " · v{$elementor_version}" : ''; ?>
                    <?php else: ?>
                        <span><strong>Elementor not detected.</strong> Pages will be created and data saved — they will render once Elementor is installed.</span>
                    <?php endif; ?>
                </div>

                <h4 class="mb-3"><i class="bi bi-layers"></i> Page Setup</h4>

                <label class="form-label fw-bold">Post Type</label>
                <select id="myls_elb_post_type" class="form-select mb-3">
                    <?php foreach ( $pts as $pt => $obj ): ?>
                        <option value="<?php echo esc_attr($pt); ?>" <?php selected( $pt, 'page' ); ?>>
                            <?php echo esc_html( $obj->labels->singular_name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label class="form-label fw-bold">Page Title <span class="text-danger">*</span></label>
                <input type="text" id="myls_elb_title" class="form-control mb-3"
                       placeholder="e.g., Roof Replacement – Expert Service in Austin">

                <label class="form-label fw-bold">Description / Instructions</label>
                <div class="form-text mb-1" style="font-size:11px;">
                    Supports tokens: <code>{{PAGE_TITLE}}</code> <code>{{YOAST_TITLE}}</code> <code>{{CITY}}</code> <code>{{BUSINESS_NAME}}</code> <code>{{PHONE}}</code> <code>{{EMAIL}}</code>
                </div>

                <!-- Description History -->
                <div class="d-flex gap-1 mb-2 align-items-end">
                    <div class="flex-grow-1">
                        <select id="myls_elb_desc_history" class="form-select form-select-sm">
                            <option value="">— Saved Descriptions —</option>
                        </select>
                    </div>
                    <button type="button" class="button button-small" id="myls_elb_desc_load" title="Load selected description">
                        <i class="bi bi-folder2-open"></i>
                    </button>
                    <button type="button" class="button button-small" id="myls_elb_desc_save" title="Save current description">
                        <i class="bi bi-floppy"></i>
                    </button>
                    <button type="button" class="button button-small" id="myls_elb_desc_delete" title="Delete selected description" style="color:#dc3545;">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
                <!-- Inline save row — avoids blocked prompt() dialog -->
                <div id="myls_elb_save_row" style="display:none;" class="d-flex gap-1 mb-2">
                    <input type="text" id="myls_elb_save_name" class="form-control form-control-sm"
                           placeholder="Name this description…" style="flex:1;">
                    <button type="button" class="button button-primary button-small" id="myls_elb_save_confirm">
                        <i class="bi bi-check-lg"></i> Save
                    </button>
                    <button type="button" class="button button-small" id="myls_elb_save_cancel">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <div id="myls_elb_desc_msg" style="display:none;font-size:12px;padding:4px 8px;border-radius:4px;margin-bottom:6px;"></div>

                <textarea id="myls_elb_description" class="form-control mb-1" rows="8"
                          placeholder="Describe what this page is about. Include service details, target audience, location, and any key selling points."></textarea>
                <div class="form-text mb-3">Features, audience, tone, structure — the more detail, the better.</div>

                <hr class="my-3">

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold">Status</label>
                        <select id="myls_elb_status" class="form-select">
                            <option value="draft" selected>Draft</option>
                            <option value="publish">Publish</option>
                        </select>
                    </div>
                    <div class="col-6 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="myls_elb_menu" checked>
                            <label class="form-check-label" for="myls_elb_menu">Add to Menu</label>
                        </div>
                    </div>
                </div>
                <div id="myls_elb_nav_info" class="form-text mb-3" style="display:none;"></div>

                <hr class="my-3">

                <!-- What sections will be generated -->
                <div class="mb-3">
                    <label class="form-label fw-bold mb-1" for="myls_elb_seo_keyword">
                        <i class="bi bi-search"></i> Yoast SEO Title / Focus Keyword
                    </label>
                    <input type="text" id="myls_elb_seo_keyword" class="form-control form-control-sm"
                           placeholder="e.g. Dog Training Tampa FL"
                           title="Used as Yoast focus keyword and to guide AI content generation. Leave blank to use the page title.">
                    <div class="form-text">Saved to Yoast focus keyword &amp; title. Also used to search Wikipedia for factual grounding.</div>
                </div>

                <div class="card mb-3" style="border:1px solid #ddd;background:#f9fbff;">
                    <div class="card-body py-2 px-3">
                        <strong style="font-size:13px;"><i class="bi bi-layout-text-window-reverse"></i> Generated Sections</strong>
                        <div class="small text-muted mt-1 mb-2">Each section becomes a separate Elementor container (Flexbox) you can rearrange or style.</div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;font-size:12px;color:#333;">
                            <div class="form-check m-0">
                                <input class="form-check-input" type="checkbox" id="myls_elb_include_hero" checked>
                                <label class="form-check-label" for="myls_elb_include_hero" title="Uncheck if your theme already provides a page header">Hero Banner</label>
                            </div>
                            <div class="form-check m-0">
                                <input class="form-check-input" type="checkbox" id="myls_elb_include_intro" checked>
                                <label class="form-check-label" for="myls_elb_include_intro">Service Intro</label>
                            </div>
                            <div class="form-check m-0">
                                <input class="form-check-input" type="checkbox" id="myls_elb_include_features" checked>
                                <label class="form-check-label" for="myls_elb_include_features">Feature Cards</label>
                            </div>
                            <div class="form-check m-0">
                                <input class="form-check-input" type="checkbox" id="myls_elb_include_process" checked>
                                <label class="form-check-label" for="myls_elb_include_process">How It Works</label>
                            </div>
                            <div class="form-check m-0">
                                <input class="form-check-input" type="checkbox" id="myls_elb_include_faq" checked>
                                <label class="form-check-label" for="myls_elb_include_faq">FAQ Accordion</label>
                            </div>
                            <div class="form-check m-0">
                                <input class="form-check-input" type="checkbox" id="myls_elb_include_cta" checked>
                                <label class="form-check-label" for="myls_elb_include_cta">CTA Block</label>
                            </div>
                        </div>
                        <div class="form-text mt-2" id="myls_elb_hero_note" style="display:none;color:#856404;">
                            &#9888; Hero skipped &mdash; your theme header will be used instead.
                        </div>
                    </div>
                </div>

                <!-- Elementor Templates card -->
                <div class="card mb-3" style="border:1px solid #ddd;background:#f9fbff;">
                    <div class="card-body py-2 px-3">
                        <strong style="font-size:13px;"><i class="bi bi-file-earmark-richtext"></i> Append Elementor Templates</strong>
                        <div class="small text-muted mt-1 mb-2">Select up to 3 saved templates to append in order after all generated sections. Text Editor widgets containing <code>AI Content Here</code> will be auto-filled with unique content.</div>
                        <div class="form-text mb-2" id="myls_elb_template_loading" style="display:none;">
                            <i class="bi bi-hourglass-split"></i> Loading templates…
                        </div>
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label small mb-1">Template 1</label>
                                <select id="myls_elb_template_1" class="form-select form-select-sm myls-tpl-select">
                                    <option value="">— None —</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label small mb-1">Template 2</label>
                                <select id="myls_elb_template_2" class="form-select form-select-sm myls-tpl-select">
                                    <option value="">— None —</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label small mb-1">Template 3</label>
                                <select id="myls_elb_template_3" class="form-select form-select-sm myls-tpl-select">
                                    <option value="">— None —</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <h5 class="mb-2">Business Variables</h5>
                <p class="form-text mt-0 mb-2">Auto-filled from Site Builder settings. Edit here for this session only.</p>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label small">Business Name</label>
                        <input type="text" id="myls_elb_biz_name" class="form-control form-control-sm"
                               value="<?php echo esc_attr($biz_name); ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small">City, State</label>
                        <input type="text" id="myls_elb_biz_city" class="form-control form-control-sm"
                               value="<?php echo esc_attr($biz_city); ?>">
                    </div>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small">Phone</label>
                        <input type="text" id="myls_elb_biz_phone" class="form-control form-control-sm"
                               value="<?php echo esc_attr($biz_phone); ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Email</label>
                        <input type="text" id="myls_elb_biz_email" class="form-control form-control-sm"
                               value="<?php echo esc_attr($biz_email); ?>">
                    </div>
                </div>

                <input type="hidden" id="myls_elb_nonce" value="<?php echo esc_attr($nonce); ?>">
            </div>

            <!-- ═══════════ RIGHT: Prompt + Results ═══════════ -->
            <div style="border:1px solid #000; padding:16px; border-radius:1em;">

                <!-- Test Tab notice -->
                <div class="alert alert-info d-flex gap-2 align-items-start mb-3 py-2 px-3" style="font-size:13px;border-radius:8px;">
                    <i class="bi bi-flask mt-1" style="flex-shrink:0;"></i>
                    <div>
                        <strong>Elementor Builder</strong> — Generates native Elementor widgets: <strong>Heading · Text Editor · Button · Icon Box · Shortcode</strong>.
                        FAQs are saved to custom fields and rendered via <code>[faq_schema_accordion]</code>.
                        All content is directly editable in the Elementor canvas without opening any code panel.
                    </div>
                </div>

                <h4 class="mb-2"><i class="bi bi-robot"></i> AI Content Generation</h4>
                <p class="mb-3" style="color:#555;">
                    Tokens: <code>{{PAGE_TITLE}}</code> <code>{{YOAST_TITLE}}</code> <code>{{DESCRIPTION}}</code>
                    <code>{{BUSINESS_NAME}}</code> <code>{{CITY}}</code>
                    <code>{{PHONE}}</code> <code>{{EMAIL}}</code>
                    <code>{{SITE_NAME}}</code> <code>{{SITE_URL}}</code> <code>{{POST_TYPE}}</code>
                </p>

                <div class="card mb-3" style="border:1px solid #ddd;">
                    <div class="card-body">
                        <?php if ( $prompt_was_stale ) : ?>
                        <div class="alert alert-warning d-flex gap-2 align-items-start mb-2 py-2 px-3" style="font-size:13px;border-radius:6px;">
                            <i class="bi bi-arrow-repeat mt-1" style="flex-shrink:0;"></i>
                            <div><strong>Prompt auto-updated.</strong> Your saved prompt contained old HTML instructions from before v7.1.2. It has been reset to the current JSON output default.</div>
                        </div>
                        <?php endif; ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong>AI Prompt Template</strong>
                            <div class="d-flex gap-1">
                                <button type="button" class="button" id="myls_elb_reset_prompt" title="Reset to built-in default">↺ Reset to Default</button>
                                <button type="button" class="button button-primary" id="myls_elb_save_prompt">Save to DB</button>
                            </div>
                        </div>
                        <?php myls_prompt_toolbar( 'elementor-builder', 'myls_elb_prompt' ); ?>
                        <textarea id="myls_elb_prompt" class="form-control font-monospace" rows="12"
                                  style="font-size:12px;"><?php echo esc_textarea( $saved_prompt ); ?></textarea>
                        <small style="color:#666;">The prompt must instruct the AI to output <strong>JSON</strong> (not HTML). Saving an empty value restores the built-in default.</small>
                    </div>
                </div>

                <!-- AI Images -->
                <div class="card mb-3" style="border:1px solid #ddd;">
                    <div class="card-header d-flex justify-content-between align-items-center" style="padding:8px 12px;">
                        <strong><i class="bi bi-image"></i> AI Images (DALL-E 3)</strong>
                        <span class="badge bg-secondary">Optional</span>
                    </div>
                    <div class="card-body">
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="myls_elb_gen_hero" checked>
                                    <label class="form-check-label" for="myls_elb_gen_hero">
                                        <i class="bi bi-card-image"></i> Hero / Banner Image
                                    </label>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="myls_elb_gen_feature">
                                    <label class="form-check-label" for="myls_elb_gen_feature">
                                        <i class="bi bi-image"></i> Featured Image
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-12">
                                <label class="form-label small">Image Style</label>
                                <select id="myls_elb_img_style" class="form-select form-select-sm">
                                    <option value="photo" selected>Photo</option>
                                    <option value="photorealistic">Photorealistic</option>
                                    <option value="modern-flat">Modern Flat</option>
                                    <option value="isometric">Isometric 3D</option>
                                    <option value="watercolor">Watercolor</option>
                                    <option value="gradient-abstract">Abstract Gradient</option>
                                </select>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="myls_elb_set_featured" checked>
                                    <label class="form-check-label small" for="myls_elb_set_featured">Set as Featured Image</label>
                                </div>
                            </div>
                        </div>
                        <div class="form-text mt-1">Uses DALL-E 3 · ~$0.04/standard image · Images upload to your Media Library.</div>
                        <div class="mt-2 d-flex align-items-center gap-2">
                            <button type="button" class="button" id="myls_elb_test_dalle_btn">
                                <i class="bi bi-plug"></i> Test DALL-E Connection
                            </button>
                            <span id="myls_elb_test_dalle_status" style="font-size:12px;"></span>
                        </div>
                        <pre id="myls_elb_test_dalle_log" style="display:none;margin-top:6px;background:#1e1e1e;color:#d4d4d4;padding:10px;border-radius:6px;font-size:11px;max-height:200px;overflow:auto;white-space:pre-wrap;"></pre>
                        <hr class="my-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="myls_elb_integrate_images" checked>
                            <label class="form-check-label fw-bold" for="myls_elb_integrate_images">
                                <i class="bi bi-layout-text-window-reverse"></i> Integrate images into page content
                            </label>
                        </div>
                        <div class="form-text mt-1">When checked, images are generated first and the AI weaves them into the Elementor HTML sections.</div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mb-2">
                    <button type="button" class="button button-primary button-hero" id="myls_elb_create_btn">
                        <i class="bi bi-lightning-charge"></i> Create Elementor Page with AI
                    </button>
                    <button type="button" class="button button-secondary" id="myls_elb_gen_images_btn" style="display:none;">
                        <i class="bi bi-images"></i> Generate Images
                    </button>
                </div>

                <!-- Progress bar -->
                <div id="myls_elb_progress_wrap" style="display:none; margin-bottom:12px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                        <span id="myls_elb_progress_label" style="font-size:12px; color:#555; font-weight:500;">Starting…</span>
                        <span id="myls_elb_progress_pct" style="font-size:11px; color:#888;">0%</span>
                    </div>
                    <div style="background:#e9ecef; border-radius:50px; height:10px; overflow:hidden; width:100%;">
                        <div id="myls_elb_progress_bar" style="
                            height:100%; width:0%; border-radius:50px;
                            background: linear-gradient(90deg, #4f46e5, #7c3aed, #a855f7);
                            background-size: 200% 100%;
                            transition: width 0.6s ease;
                            animation: myls-shimmer 1.8s linear infinite;
                        "></div>
                    </div>
                    <div id="myls_elb_progress_steps" style="display:flex; gap:0; margin-top:6px;">
                        <div class="myls-pstep" data-step="1" style="flex:1; text-align:center; font-size:10px; color:#aaa; padding:2px 0; border-top:2px solid #e0e0e0; transition:color .3s,border-color .3s;">🔑 API</div>
                        <div class="myls-pstep" data-step="2" style="flex:1; text-align:center; font-size:10px; color:#aaa; padding:2px 0; border-top:2px solid #e0e0e0; transition:color .3s,border-color .3s;">🎨 Images</div>
                        <div class="myls-pstep" data-step="3" style="flex:1; text-align:center; font-size:10px; color:#aaa; padding:2px 0; border-top:2px solid #e0e0e0; transition:color .3s,border-color .3s;">✍️ Content</div>
                        <div class="myls-pstep" data-step="4" style="flex:1; text-align:center; font-size:10px; color:#aaa; padding:2px 0; border-top:2px solid #e0e0e0; transition:color .3s,border-color .3s;">🏗️ Elementor</div>
                        <div class="myls-pstep" data-step="5" style="flex:1; text-align:center; font-size:10px; color:#aaa; padding:2px 0; border-top:2px solid #e0e0e0; transition:color .3s,border-color .3s;">✅ Done</div>
                    </div>
                </div>
                <style>
                @keyframes myls-shimmer {
                    0%   { background-position: 200% 0; }
                    100% { background-position: -200% 0; }
                }
                .myls-pstep.active { color: #4f46e5 !important; border-top-color: #7c3aed !important; font-weight:600; }
                .myls-pstep.done   { color: #16a34a !important; border-top-color: #16a34a !important; }
                </style>

                <hr>

                <div class="myls-results-header">
                    <label class="form-label mb-0 fw-bold"><i class="bi bi-terminal"></i> Results</label>
                    <div class="d-flex gap-2 align-items-center">
                        <span id="myls_elb_section_badge" style="display:none;" class="badge bg-primary"></span>
                        <button type="button" class="myls-btn-export-pdf" data-log-target="myls_elb_log"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
                        <span id="myls_elb_edit_link" style="display:none;">
                            <a id="myls_elb_edit_url" href="#" target="_blank" class="button button-secondary">
                                <i class="bi bi-pencil-square"></i> Edit in Elementor
                            </a>
                        </span>
                    </div>
                </div>
                <pre id="myls_elb_log" class="myls-results-terminal">Ready.</pre>

                <!-- Image preview -->
                <div id="myls_elb_img_preview" style="display:none;" class="mt-3">
                    <label class="form-label fw-bold"><i class="bi bi-images"></i> Generated Images</label>
                    <div id="myls_elb_img_grid" class="d-flex flex-wrap gap-2"></div>
                </div>

                <!-- Debug panel -->
                <div class="mt-3">
                    <div class="d-flex align-items-center gap-2">
                        <strong style="font-size:13px;"><i class="bi bi-bug"></i> Debug Inspector</strong>
                        <input type="number" id="myls_elb_debug_post_id" class="form-control form-control-sm" style="width:110px;" placeholder="Post ID">
                        <button type="button" class="button" id="myls_elb_debug_btn">Inspect Elementor Data</button>
                    </div>
                    <pre id="myls_elb_debug_output" style="display:none;margin-top:8px;background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:6px;font-size:11px;max-height:300px;overflow:auto;white-space:pre-wrap;"></pre>
                </div>
            </div>

        </div>

        <script>
        (function(){
            const $ = (id) => document.getElementById(id);
            let lastPostId = 0;
            let descHistory = [];

            // ── Progress bar helpers ──────────────────────────────────────
            let _progressTimer = null;
            const _progressStages = [
                { step:1, pct:8,  label:'🔑 Validating…' },
                { step:2, pct:28, label:'🎨 Generating images with DALL-E 3…' },
                { step:2, pct:48, label:'🎨 Uploading images to Media Library…' },
                { step:3, pct:62, label:'✍️ AI writing content…' },
                { step:3, pct:74, label:'✍️ AI writing content…' },
                { step:4, pct:84, label:'🏗️ Building Elementor sections…' },
                { step:4, pct:91, label:'🏗️ Assembling page…' },
            ];

            function progressStart(withImages) {
                const wrap = $('myls_elb_progress_wrap');
                const bar  = $('myls_elb_progress_bar');
                const lbl  = $('myls_elb_progress_label');
                const pct  = $('myls_elb_progress_pct');
                if (!wrap) return;

                // Reset
                bar.style.transition = 'none';
                bar.style.width = '0%';
                pct.textContent = '0%';
                lbl.textContent = 'Starting…';
                document.querySelectorAll('.myls-pstep').forEach(el => {
                    el.classList.remove('active','done');
                });
                wrap.style.display = '';

                // Kick off after paint
                requestAnimationFrame(() => {
                    bar.style.transition = 'width 0.6s ease';
                });

                let stageIdx = 0;
                // If no images skip the image stages
                const stages = withImages
                    ? _progressStages
                    : _progressStages.filter(s => s.step !== 2);

                // Timing: images take ~40s, content ~20s, build ~5s
                const intervals = withImages
                    ? [800, 8000, 18000, 8000, 10000, 4000, 5000]
                    : [800, 8000, 8000,  4000, 5000];

                function tick() {
                    if (stageIdx >= stages.length) return;
                    const stage = stages[stageIdx];
                    bar.style.width = stage.pct + '%';
                    pct.textContent = stage.pct + '%';
                    lbl.textContent = stage.label;

                    // Update step indicators
                    document.querySelectorAll('.myls-pstep').forEach(el => {
                        const s = parseInt(el.dataset.step);
                        if (s < stage.step)       el.classList.add('done'),   el.classList.remove('active');
                        else if (s === stage.step) el.classList.add('active'), el.classList.remove('done');
                        else                       el.classList.remove('active','done');
                    });

                    stageIdx++;
                    if (stageIdx < stages.length) {
                        _progressTimer = setTimeout(tick, intervals[stageIdx] || 6000);
                    }
                }
                _progressTimer = setTimeout(tick, intervals[0]);
            }

            function progressDone(success) {
                clearTimeout(_progressTimer);
                const bar  = $('myls_elb_progress_bar');
                const lbl  = $('myls_elb_progress_label');
                const pct  = $('myls_elb_progress_pct');
                if (!bar) return;

                bar.style.width = '100%';
                pct.textContent = '100%';
                lbl.textContent = success ? '✅ Page created!' : '❌ Something went wrong';

                if (success) {
                    bar.style.background = 'linear-gradient(90deg,#16a34a,#22c55e)';
                    bar.style.animation  = 'none';
                }
                document.querySelectorAll('.myls-pstep').forEach(el => {
                    el.classList.remove('active');
                    if (success) el.classList.add('done');
                });

                // Fade out after 3s on success
                if (success) {
                    setTimeout(() => {
                        const wrap = $('myls_elb_progress_wrap');
                        if (wrap) { wrap.style.transition = 'opacity 0.8s'; wrap.style.opacity = '0'; }
                        setTimeout(() => {
                            if (wrap) { wrap.style.display = 'none'; wrap.style.opacity = '1'; wrap.style.transition = ''; }
                            if (bar)  { bar.style.background = 'linear-gradient(90deg,#4f46e5,#7c3aed,#a855f7)'; bar.style.animation = 'myls-shimmer 1.8s linear infinite'; }
                        }, 900);
                    }, 3000);
                }
            }

            // ── Description History ──────────────────────────────────────

            function descMsg(text, ok = true) {
                const el = $('myls_elb_desc_msg');
                el.textContent = text;
                el.style.background = ok ? '#d4edda' : '#f8d7da';
                el.style.color      = ok ? '#155724' : '#721c24';
                el.style.display    = '';
                clearTimeout(el._t);
                el._t = setTimeout(() => { el.style.display = 'none'; }, 3000);
            }

            async function loadDescHistory() {
                try {
                    const fd = new FormData();
                    fd.append('action', 'myls_elb_list_descriptions');
                    fd.append('_wpnonce', $('myls_elb_nonce').value);
                    const res  = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data?.success) {
                        descHistory = data.data.history || [];
                        renderDescDropdown();
                    } else {
                        descMsg('Could not load saved descriptions: ' + (data?.data?.message || 'unknown error'), false);
                    }
                } catch(e) {
                    descMsg('Network error loading descriptions: ' + e.message, false);
                }
            }

            function renderDescDropdown() {
                const sel = $('myls_elb_desc_history');
                sel.innerHTML = '<option value="">— Saved Descriptions (' + descHistory.length + ') —</option>';
                descHistory.forEach(item => {
                    const opt = document.createElement('option');
                    opt.value       = item.slug;
                    opt.textContent = item.name + (item.updated ? ' · ' + item.updated.substring(0,10) : '');
                    sel.appendChild(opt);
                });
            }

            // LOAD — fill textarea from selection
            $('myls_elb_desc_load')?.addEventListener('click', () => {
                const slug = $('myls_elb_desc_history').value;
                if (!slug) { descMsg('Select a saved description from the dropdown first.', false); return; }
                const item = descHistory.find(h => h.slug === slug);
                if (item) {
                    $('myls_elb_description').value = item.description;
                    descMsg('Loaded: ' + item.name);
                }
            });

            // SAVE — show inline row instead of blocked prompt()
            $('myls_elb_desc_save')?.addEventListener('click', () => {
                const desc = $('myls_elb_description').value.trim();
                if (!desc) { descMsg('Write a description first.', false); return; }
                const nameEl = $('myls_elb_save_name');
                if (!nameEl.value) nameEl.value = $('myls_elb_title').value.trim() || '';
                $('myls_elb_save_row').style.display = '';
                nameEl.focus();
                nameEl.select();
            });

            $('myls_elb_save_cancel')?.addEventListener('click', () => {
                $('myls_elb_save_row').style.display = 'none';
            });

            $('myls_elb_save_confirm')?.addEventListener('click', async () => {
                const name = $('myls_elb_save_name').value.trim();
                const desc = $('myls_elb_description').value.trim();
                if (!name) { descMsg('Enter a name for this description.', false); return; }
                if (!desc) { descMsg('Description textarea is empty.', false); return; }

                const fd = new FormData();
                fd.append('action',      'myls_elb_save_description');
                fd.append('_wpnonce',    $('myls_elb_nonce').value);
                fd.append('desc_name',   name);
                fd.append('description', desc);

                try {
                    const res  = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data?.success) {
                        descHistory = data.data.history || [];
                        renderDescDropdown();
                        const slug = descHistory.find(h => h.name === name)?.slug;
                        if (slug) $('myls_elb_desc_history').value = slug;
                        $('myls_elb_save_row').style.display = 'none';
                        descMsg('Saved: ' + name);
                    } else {
                        descMsg(data?.data?.message || 'Save failed.', false);
                    }
                } catch(e) { descMsg('Network error: ' + e.message, false); }
            });

            $('myls_elb_save_name')?.addEventListener('keydown', (e) => {
                if (e.key === 'Enter')  { e.preventDefault(); $('myls_elb_save_confirm').click(); }
                if (e.key === 'Escape') { $('myls_elb_save_row').style.display = 'none'; }
            });

            // DELETE — two-click confirm (no confirm() dialog)
            $('myls_elb_desc_delete')?.addEventListener('click', async () => {
                const slug = $('myls_elb_desc_history').value;
                if (!slug) { descMsg('Select a description to delete first.', false); return; }
                const item  = descHistory.find(h => h.slug === slug);
                const label = item?.name || slug;
                const msgEl = $('myls_elb_desc_msg');

                if (msgEl._pendingDelete === slug) {
                    msgEl._pendingDelete = null;
                    const fd = new FormData();
                    fd.append('action',    'myls_elb_delete_description');
                    fd.append('_wpnonce',  $('myls_elb_nonce').value);
                    fd.append('desc_slug', slug);
                    try {
                        const res  = await fetch(ajaxurl, { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data?.success) {
                            descHistory = data.data.history || [];
                            renderDescDropdown();
                            descMsg('Deleted: ' + label);
                        } else {
                            descMsg(data?.data?.message || 'Delete failed.', false);
                        }
                    } catch(e) { descMsg('Network error: ' + e.message, false); }
                } else {
                    msgEl._pendingDelete = slug;
                    descMsg('Click delete again to confirm removing "' + label + '"', false);
                    setTimeout(() => { if (msgEl._pendingDelete === slug) msgEl._pendingDelete = null; }, 4000);
                }
            });

            loadDescHistory();

            // ── Nav detection ────────────────────────────────────────────
            (async function() {
                try {
                    const fd = new FormData();
                    fd.append('action', 'myls_elb_get_nav_posts');
                    fd.append('_wpnonce', $('myls_elb_nonce').value);
                    const res = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data?.success && data.data.is_block_theme) {
                        const info = $('myls_elb_nav_info');
                        const active = data.data.nav_posts?.find(n => n.active);
                        if (active) {
                            info.innerHTML = '<i class="bi bi-info-circle"></i> Block theme · Active nav: <strong>' + active.title + '</strong> (#' + active.id + ')';
                        } else {
                            info.innerHTML = '<i class="bi bi-info-circle"></i> Block theme detected.';
                        }
                        info.style.display = '';
                    }
                } catch(e) { /* silent */ }
            })();

            // ── Prompt ───────────────────────────────────────────────────
            const defaultPrompt = <?php echo wp_json_encode( myls_get_default_prompt('elementor-builder') ); ?>;
            const promptEl = $('myls_elb_prompt');
            if (promptEl && !promptEl.value.trim()) {
                promptEl.value = defaultPrompt;
            }

            $('myls_elb_save_prompt')?.addEventListener('click', async () => {
                const fd = new FormData();
                fd.append('action', 'myls_elb_save_prompt');
                fd.append('prompt_template', promptEl.value);
                fd.append('_wpnonce', $('myls_elb_nonce').value);
                try {
                    const res = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();
                    descMsg(data?.success ? '✓ Prompt template saved.' : (data?.data?.message || 'Error.'), !!data?.success);
                } catch(e) { descMsg('Error: ' + e.message, false); }
            });

            $('myls_elb_reset_prompt')?.addEventListener('click', async () => {
                // Clear the saved DB value — server will then serve the file default
                const fd = new FormData();
                fd.append('action', 'myls_elb_save_prompt');
                fd.append('prompt_template', '');  // empty = use file default
                fd.append('_wpnonce', $('myls_elb_nonce').value);
                try {
                    const res  = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data?.success) {
                        // Reload the page so the textarea repopulates from the file default
                        descMsg('✓ Reset — reloading…', true);
                        setTimeout(() => location.reload(), 800);
                    } else {
                        descMsg(data?.data?.message || 'Reset failed.', false);
                    }
                } catch(e) { descMsg('Error: ' + e.message, false); }
            });

            // ── Helpers ──────────────────────────────────────────────────
            function wantsImages() {
                return $('myls_elb_gen_hero').checked || $('myls_elb_gen_feature').checked;
            }

            // ── Hero toggle ──────────────────────────────────────────────
            $('myls_elb_include_hero')?.addEventListener('change', function() {
                $('myls_elb_hero_note').style.display = this.checked ? 'none' : '';
            });

            // ── Auto-switch Image Style when only Featured Image is checked ──
            // If Featured is checked and Hero is not, default style to Photo.
            // If Hero is checked (alone or with Featured), restore previous style.
            (function() {
                const heroChk    = $('myls_elb_gen_hero');
                const featChk    = $('myls_elb_gen_feature');
                const styleEl    = $('myls_elb_img_style');
                let   prevStyle  = styleEl ? styleEl.value : 'photo';

                function syncStyle() {
                    if (!heroChk || !featChk || !styleEl) return;
                    if (featChk.checked && !heroChk.checked) {
                        // Only Featured checked — switch to Photo
                        if (styleEl.value !== 'photo') prevStyle = styleEl.value;
                        styleEl.value = 'photo';
                    } else {
                        // Hero is checked or nothing checked — restore previous style
                        if (styleEl.value === 'photo') styleEl.value = prevStyle;
                    }
                }
                heroChk?.addEventListener('change', syncStyle);
                featChk?.addEventListener('change', syncStyle);
                // Run on load in case Featured is default-checked
                syncStyle();
            })();

            // ── Load Elementor templates into all 3 dropdowns ─────────────
            (async () => {
                const selIds  = ['myls_elb_template_1', 'myls_elb_template_2', 'myls_elb_template_3'];
                const loading = $('myls_elb_template_loading');
                loading.style.display = '';

                const fd = new FormData();
                fd.append('action',   'myls_elb_get_templates');
                fd.append('_wpnonce', $('myls_elb_nonce').value);
                try {
                    const res  = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();
                    const templates = (data?.success && data.data.templates?.length)
                        ? data.data.templates : [];

                    selIds.forEach(id => {
                        const sel = $(id);
                        if (!sel) return;
                        if (templates.length) {
                            templates.forEach(t => {
                                const opt = document.createElement('option');
                                opt.value       = t.id;
                                opt.textContent = t.title + (t.type ? '  [' + t.type + ']' : '');
                                sel.appendChild(opt);
                            });
                        } else {
                            const opt = document.createElement('option');
                            opt.value = ''; opt.textContent = '(no templates found)';
                            sel.appendChild(opt);
                        }
                    });
                } catch(e) {
                    console.warn('Template load failed:', e);
                } finally {
                    loading.style.display = 'none';
                }
            })();

            // ── Create Page ──────────────────────────────────────────────
            $('myls_elb_create_btn')?.addEventListener('click', async () => {
                const title = $('myls_elb_title').value.trim();
                if (!title) { alert('Please enter a Page Title.'); $('myls_elb_title').focus(); return; }

                const logEl     = $('myls_elb_log');
                const btn       = $('myls_elb_create_btn');
                const editLink  = $('myls_elb_edit_link');
                const imgBtn    = $('myls_elb_gen_images_btn');
                const badge     = $('myls_elb_section_badge');
                const hasTemplates = ['myls_elb_template_1','myls_elb_template_2','myls_elb_template_3']
                    .some(id => { const s = $(id); return s && s.value; });
                const integrateImages = $('myls_elb_integrate_images').checked && (wantsImages() || hasTemplates);

                editLink.style.display = 'none';
                imgBtn.style.display   = 'none';
                badge.style.display    = 'none';
                $('myls_elb_img_preview').style.display = 'none';
                btn.disabled = true;
                progressStart(integrateImages && (wantsImages() || hasTemplates));

                if (integrateImages) {
                    const totalImgs = ($('myls_elb_gen_hero').checked ? 1 : 0) + ($('myls_elb_gen_feature').checked ? 1 : 0);
                    const tplMsg = hasTemplates ? '\n🔍 Will also scan templates for empty image widgets and fill with DALL-E.' : '';
                    if (totalImgs > 0) {
                        logEl.textContent = '🎨 Step 1/2: Generating ' + totalImgs + ' image(s) with DALL-E 3…\n(This may take 30–90 seconds)' + tplMsg + '\n\n✏️ Step 2/2: AI will build Elementor sections with images integrated.';
                    } else {
                        logEl.textContent = '🔍 Integrate Images: scanning templates for empty image widgets…\n(Will generate DALL-E images for any empty image slots)\n\n✏️ Generating content with AI…';
                    }
                    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Generating Images + Content…';
                } else {
                    logEl.textContent = '⏳ Generating content with AI… this may take 15–30 seconds.';
                    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Generating…';
                }

                const fd = new FormData();
                fd.append('action',           'myls_elb_create_page');
                fd.append('_wpnonce',         $('myls_elb_nonce').value);
                fd.append('page_title',       title);
                fd.append('post_type',        $('myls_elb_post_type').value);
                fd.append('page_status',      $('myls_elb_status').value);
                fd.append('page_description', $('myls_elb_description').value);
                fd.append('prompt_template',  promptEl.value);
                fd.append('add_to_menu',      $('myls_elb_menu').checked ? '1' : '0');
                fd.append('include_hero',     $('myls_elb_include_hero').checked    ? '1' : '0');
                fd.append('include_intro',    $('myls_elb_include_intro').checked   ? '1' : '0');
                fd.append('include_features', $('myls_elb_include_features').checked ? '1' : '0');
                fd.append('include_process',  $('myls_elb_include_process').checked  ? '1' : '0');
                fd.append('include_faq',      $('myls_elb_include_faq').checked      ? '1' : '0');
                fd.append('include_cta',      $('myls_elb_include_cta').checked      ? '1' : '0');
                fd.append('seo_keyword',      ($('myls_elb_seo_keyword').value || '').trim());
                fd.append('append_template_1', $('myls_elb_template_1').value || '0');
                fd.append('append_template_2', $('myls_elb_template_2').value || '0');
                fd.append('append_template_3', $('myls_elb_template_3').value || '0');

                if (integrateImages) {
                    fd.append('integrate_images', '1');
                    fd.append('image_style',      $('myls_elb_img_style').value);
                    fd.append('gen_hero',          $('myls_elb_gen_hero').checked ? '1' : '0');
                    fd.append('gen_feature',       $('myls_elb_gen_feature').checked ? '1' : '0');
                    fd.append('set_featured',      $('myls_elb_set_featured').checked ? '1' : '0');
                }

                try {
                    const res  = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();

                    if (data?.success) {
                        progressDone(true);
                        lastPostId = data.data.post_id || 0;
                        logEl.textContent = data.data.log_text || data.data.message || 'Done.';

                        // Section count badge
                        if (data.data.section_count) {
                            badge.textContent = data.data.section_count + ' section' + (data.data.section_count !== 1 ? 's' : '') + ' (native widgets)';
                            badge.style.display = '';
                        }

                        // Auto-fill debug inspector with the new post ID
                        const debugPostIdEl = $('myls_elb_debug_post_id');
                        if (debugPostIdEl && lastPostId) debugPostIdEl.value = lastPostId;

                        if (data.data.edit_url) {
                            $('myls_elb_edit_url').href = data.data.edit_url;
                            editLink.style.display = '';
                        }

                        if (!integrateImages && wantsImages() && lastPostId) {
                            imgBtn.style.display = '';
                            logEl.textContent += '\n\n🖼️ Ready to generate images — click "Generate Images" below.';
                        }

                        if (data.data.images && data.data.images.length) {
                            const imgGrid    = $('myls_elb_img_grid');
                            const imgPreview = $('myls_elb_img_preview');
                            imgGrid.innerHTML = '';
                            data.data.images.forEach(img => {
                                const div = document.createElement('div');
                                div.style.cssText = 'width:140px; text-align:center;';
                                div.innerHTML = '<img src="' + img.url + '" style="width:140px;height:100px;object-fit:cover;border-radius:8px;border:1px solid #ddd;" alt="' + (img.subject || img.type) + '">'
                                    + '<div class="small text-muted mt-1">' + img.type + (img.subject ? ': ' + img.subject : '') + '</div>';
                                imgGrid.appendChild(div);
                            });
                            imgPreview.style.display = '';
                        }
                    } else {
                        progressDone(false);
                        logEl.textContent = '❌ ' + (data?.data?.message || 'Unknown error.');
                    }
                } catch(e) {
                    progressDone(false);
                    logEl.textContent = '❌ Network error: ' + e.message;
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-lightning-charge"></i> Create Elementor Page with AI';
                }
            });

            // ── Debug Inspector ───────────────────────────────────────────
            $('myls_elb_debug_btn')?.addEventListener('click', async () => {
                const postIdEl  = $('myls_elb_debug_post_id');
                const outputEl  = $('myls_elb_debug_output');
                const post_id   = parseInt(postIdEl.value) || lastPostId;

                if (!post_id) {
                    alert('Enter a Post ID or create a page first.');
                    return;
                }

                outputEl.style.display = '';
                outputEl.textContent   = 'Fetching Elementor data for Post #' + post_id + '…';

                const fd = new FormData();
                fd.append('action',   'myls_elb_debug_post');
                fd.append('_wpnonce', $('myls_elb_nonce').value);
                fd.append('post_id',  post_id);

                try {
                    const res  = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();

                    if (data?.success) {
                        const d = data.data;
                        const lines = [
                            '=== POST INFO ===',
                            'Post ID:           ' + d.post_id,
                            'Title:             ' + d.post_title,
                            'Status:            ' + d.post_status,
                            '',
                            '=== ELEMENTOR META ===',
                            '_elementor_edit_mode:      ' + d.edit_mode,
                            '_elementor_version:        ' + d.elementor_version,
                            '_elementor_template_type:  ' + d.template_type,
                            '_elementor_css cached:     ' + d.css_cache_exists,
                            '',
                            '=== JSON DATA ===',
                            'JSON stored:    ' + d.json_stored,
                            'JSON valid:     ' + d.json_valid,
                            'JSON length:    ' + d.json_length + ' chars',
                            'Containers:     ' + d.container_count,
                            'Widgets:        ' + d.widget_count,
                            '',
                            '=== RAW JSON PREVIEW (first 500 chars) ===',
                            d.json_preview || '(empty)',
                        ];
                        outputEl.textContent = lines.join('\n');
                    } else {
                        outputEl.textContent = '❌ ' + (data?.data?.message || 'Error');
                    }
                } catch(e) {
                    outputEl.textContent = '❌ Network error: ' + e.message;
                }
            });

            // Auto-fill post ID field when a page is created
            const origDebugPostId = $('myls_elb_debug_post_id');
            const _origCreate     = $('myls_elb_create_btn');

            // ── Generate Images (post-creation) ──────────────────────────
            $('myls_elb_gen_images_btn')?.addEventListener('click', async () => {
                if (!lastPostId) { alert('Create a page first.'); return; }

                const logEl       = $('myls_elb_log');
                const btn         = $('myls_elb_gen_images_btn');
                const imgGrid     = $('myls_elb_img_grid');
                const imgPreview  = $('myls_elb_img_preview');
                const genHero     = $('myls_elb_gen_hero').checked;
                const genFeature  = $('myls_elb_gen_feature').checked;
                const totalImages = (genHero ? 1 : 0) + (genFeature ? 1 : 0);

                if (!totalImages) { alert('Select at least one image type to generate.'); return; }

                logEl.textContent += '\n\n⏳ Generating ' + totalImages + ' image(s) with DALL-E 3…';
                btn.disabled = true;
                btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Generating Images…';

                const fd = new FormData();
                fd.append('action',        'myls_pb_generate_images');   // reuse page builder image AJAX
                fd.append('_wpnonce',      $('myls_elb_nonce').value);
                fd.append('post_id',       lastPostId);
                fd.append('page_title',    $('myls_elb_title').value);
                fd.append('description',   $('myls_elb_description').value);
                fd.append('image_style',   $('myls_elb_img_style').value);
                fd.append('gen_hero',      genHero ? '1' : '0');
                fd.append('gen_feature',   genFeature ? '1' : '0');
                fd.append('set_featured',  $('myls_elb_set_featured').checked ? '1' : '0');

                try {
                    const res  = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data?.success) {
                        logEl.textContent += '\n\n' + (data.data.log_text || 'Images done.');
                        if (data.data.images && data.data.images.length) {
                            imgGrid.innerHTML = '';
                            data.data.images.forEach(img => {
                                const div = document.createElement('div');
                                div.style.cssText = 'width:140px; text-align:center;';
                                div.innerHTML = `<img src="${img.url}" style="width:140px;height:100px;object-fit:cover;border-radius:8px;border:1px solid #ddd;" alt="${img.subject || img.type}">
                                    <div class="small text-muted mt-1">${img.type}${img.subject ? ': ' + img.subject : ''}</div>`;
                                imgGrid.appendChild(div);
                            });
                            imgPreview.style.display = '';
                        }
                    } else {
                        logEl.textContent += '\n\n❌ ' + (data?.data?.message || 'Image generation failed.');
                    }
                } catch(e) {
                    logEl.textContent += '\n\n❌ Network error: ' + e.message;
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-images"></i> Generate Images';
                }
            });
            // ── DALL-E Connection Test ─────────────────────────────────────
            $('myls_elb_test_dalle_btn')?.addEventListener('click', async () => {
                const btn    = $('myls_elb_test_dalle_btn');
                const status = $('myls_elb_test_dalle_status');
                const log    = $('myls_elb_test_dalle_log');

                btn.disabled = true;
                btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Testing…';
                status.textContent = '';
                status.style.color = '';
                log.style.display = 'none';

                const fd = new FormData();
                fd.append('action',   'myls_elb_test_dalle');
                fd.append('_wpnonce', $('myls_elb_nonce').value);

                try {
                    const res  = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();
                    const lines = (data?.data?.log || []).join('\n');
                    log.textContent   = lines;
                    log.style.display = lines ? '' : 'none';

                    if (data?.success) {
                        status.textContent = '✅ DALL-E working!';
                        status.style.color = '#155724';
                    } else {
                        status.textContent = '❌ ' + (data?.data?.message || 'Test failed');
                        status.style.color = '#721c24';
                    }
                } catch(e) {
                    status.textContent = '❌ Network error: ' + e.message;
                    status.style.color = '#721c24';
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-plug"></i> Test DALL-E Connection';
                }
            });

        })();
        </script>
        <?php
    }
];
