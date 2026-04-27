<?php
/**
 * Utilities Subtab: Beaver Builder Page Builder
 * Path: admin/tabs/utilities/subtab-beaver-builder.php
 *
 * Mirrors admin/tabs/utilities/subtab-elementor.php for Beaver Builder. Same
 * Page Setup → drag-and-drop sections → AI generate → BB layout flow, but the
 * generated payload is a native BB row/column/module graph persisted to
 * `_fl_builder_data` (not Elementor JSON).
 *
 * All `myls_elb_*` identifiers in the Elementor twin are substituted to
 * `myls_bb_*` here. The two sub-tabs share no JS or CSS files at runtime.
 *
 * @since 7.10.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'AIntelligize_Beaver_Builder_Parser' ) ) {
    require_once MYLS_PATH . 'inc/class-aintelligize-beaver-builder-parser.php';
}

return [
    'id'    => 'beaver-builder',
    'label' => 'Beaver Builder',
    'icon'  => 'bi-bricks',
    'order' => 72, // Elementor is 71; Beaver Builder sits directly after.
    'render'=> function () {

        /* ── Status panel — render first ─────────────────────────────────── */
        $parser = new AIntelligize_Beaver_Builder_Parser();
        $status = $parser->get_environment_status();
        include MYLS_PATH . 'admin/tabs/utilities/partials/bb-status-panel.php';

        /* ── Hard short-circuit on unsupported BB versions ──────────────── */
        if ( $status['bb_plugin_active'] && ! $status['supported_version'] ) {
            ?>
            <div class="notice notice-error" style="margin:0 0 16px;padding:10px 14px;">
                <strong>Beaver Builder is older than the supported minimum (<?php echo esc_html( AIntelligize_Beaver_Builder_Parser::MIN_BB_VERSION ); ?>).</strong>
                Update Beaver Builder to use this sub-tab.
            </div>
            <?php
            return;
        }

        /* ── Post types ──────────────────────────────────────────────────── */
        $pts = get_post_types( [ 'public' => true ], 'objects' );
        unset( $pts['attachment'] );

        /* ── Business vars (schema → sb_settings → WP defaults) ─────────── */
        $sb      = get_option( 'myls_sb_settings', [] );
        $lb_locs = (array) get_option( 'myls_lb_locations', [] );
        $lb0     = is_array( $lb_locs[0] ?? null ) ? $lb_locs[0] : [];

        $biz_name = trim( (string) get_option( 'myls_org_name', '' ) );
        if ( $biz_name === '' ) $biz_name = $sb['business_name'] ?? get_bloginfo( 'name' );

        $org_city  = trim( (string) get_option( 'myls_org_locality', '' ) );
        $org_state = trim( (string) get_option( 'myls_org_region',   '' ) );
        if ( $org_city !== '' ) {
            $biz_city = $org_state !== '' ? "{$org_city}, {$org_state}" : $org_city;
        } elseif ( ! empty( $lb0['city'] ) ) {
            $biz_city = $lb0['city'];
        } else {
            $biz_city = $sb['city'] ?? '';
        }

        $biz_phone = trim( (string) get_option( 'myls_org_tel', '' ) );
        if ( $biz_phone === '' ) $biz_phone = $lb0['phone'] ?? $sb['phone'] ?? '';

        $biz_email = trim( (string) get_option( 'myls_org_email', '' ) );
        if ( $biz_email === '' ) $biz_email = $lb0['email'] ?? $sb['email'] ?? get_bloginfo( 'admin_email' );

        /* ── Saved prompt — fall back to file default ───────────────────── */
        $saved_prompt = get_option( 'myls_bb_prompt_template', '' );
        if ( empty( trim( $saved_prompt ) ) ) {
            $saved_prompt = function_exists( 'myls_get_default_prompt' )
                ? myls_get_default_prompt( 'beaver-builder' )
                : '';
        }

        $bb_active  = ! empty( $status['bb_plugin_active'] );
        $bb_version = (string) ( $status['bb_plugin_version'] ?? '' );
        $bb_edition = (string) ( $status['bb_edition'] ?? '' );

        $nonce = wp_create_nonce( 'myls_bb_create' );

        // Resolved contact URL for {{contact_url}} token + button defaults
        $bb_contact_pid = (int) get_option( 'myls_contact_page_id', 0 );
        if ( $bb_contact_pid <= 0 ) {
            $p = get_page_by_path( 'contact-us' ) ?: get_page_by_path( 'contact' );
            if ( $p ) $bb_contact_pid = (int) $p->ID;
        }
        $bb_resolved_url = $bb_contact_pid > 0 ? get_permalink( $bb_contact_pid ) : home_url( '/contact-us/' );
        ?>

        <style>
        /* ── Drag-and-drop section list (scoped to BB sub-tab) ─────────── */
        #myls_bb_section_list { margin:0; padding:0; }
        .myls-bb-section-row {
            display:flex; align-items:center; gap:8px;
            padding:7px 10px; margin-bottom:4px;
            background:#fff; border:1px solid #dce1e7; border-radius:6px;
            cursor:default; user-select:none;
            transition: opacity .2s, background .15s, border-color .15s;
        }
        .myls-bb-section-row.myls-bb-drag-over { border-color:#4f46e5; background:#f0efff; }
        .myls-bb-section-row.myls-bb-dragging  { opacity:.35; }
        .myls-bb-section-row.myls-bb-disabled  { opacity:.5; }
        .myls-bb-section-row.myls-bb-disabled .myls-bb-section-label { text-decoration:line-through; color:#999; }
        .myls-bb-drag-handle {
            cursor:grab; color:#bbb; font-size:16px; line-height:1;
            padding:0 2px; flex-shrink:0; letter-spacing:-.5px;
        }
        .myls-bb-drag-handle:active { cursor:grabbing; }
        .myls-bb-section-label { font-size:13px; font-weight:500; color:#1e293b; flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .myls-bb-section-opts  { display:flex; align-items:center; gap:6px; flex-shrink:0; font-size:12px; }
        .myls-bb-section-opts label { margin:0; color:#555; display:flex; align-items:center; gap:3px; white-space:nowrap; }
        .myls-bb-section-opts input[type="number"] {
            width:46px; padding:2px 5px; font-size:12px;
            border:1px solid #ced4da; border-radius:4px; text-align:center;
        }
        .myls-bb-tpl-select { font-size:12px !important; max-width:190px; flex-shrink:0; }
        .myls-bb-tpl-remove {
            background:none; border:none; color:#dc3545; cursor:pointer;
            font-size:15px; padding:0 3px; line-height:1; flex-shrink:0;
        }
        .myls-bb-tpl-remove:hover { color:#a00; }
        #myls_bb_add_template_btn {
            font-size:12px; margin-top:6px; color:#4f46e5;
            background:none; border:1px dashed #9ca3af; border-radius:6px;
            padding:5px 12px; cursor:pointer; width:100%; text-align:center;
            transition: background .15s, border-color .15s;
        }
        #myls_bb_add_template_btn:hover { background:#f0efff; border-color:#4f46e5; }

        /* ── DALL-E rows ─────────────────────────────────────────────────── */
        .myls-bb-dalle-row {
            display:flex; align-items:center; gap:10px;
            padding:8px 12px; margin-bottom:6px;
            border:1px solid #e2e8f0; border-radius:6px; background:#f9fbff;
        }
        .myls-bb-dalle-row .form-check { margin:0; flex-shrink:0; }
        .myls-bb-dalle-label { font-size:13px; font-weight:500; flex:1; }
        .myls-bb-dalle-label small { display:block; font-weight:400; color:#666; font-size:11px; margin-top:2px; }
        .myls-bb-dalle-sub  { display:flex; align-items:center; gap:5px; font-size:12px; color:#444; flex-shrink:0; white-space:nowrap; }

        @keyframes myls-bb-shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
        .myls-bb-pstep.active { color:#4f46e5 !important; border-top-color:#7c3aed !important; font-weight:600; }
        .myls-bb-pstep.done   { color:#16a34a !important; border-top-color:#16a34a !important; }
        </style>

        <div style="display:grid; grid-template-columns:1fr 2fr; gap:20px;">

        <!-- ══════════════ LEFT: Page Setup ══════════════════════════════ -->
        <div style="border:1px solid #000; padding:16px; border-radius:1em;">

            <!-- BB plugin status badge -->
            <div class="alert <?php echo $bb_active ? 'alert-success' : 'alert-warning'; ?> d-flex align-items-center gap-2 mb-3 py-2 px-3" style="border-radius:8px;font-size:13px;">
                <i class="bi <?php echo $bb_active ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?>"></i>
                <?php if ( $bb_active ): ?>
                    <strong>Beaver Builder Active</strong><?php echo $bb_version ? " · v{$bb_version}" : ''; ?><?php echo $bb_edition ? " · {$bb_edition}" : ''; ?>
                <?php else: ?>
                    <span><strong>Beaver Builder not detected.</strong> Pages will be created and data saved — they will render once Beaver Builder is installed.</span>
                <?php endif; ?>
            </div>

            <h4 class="mb-3"><i class="bi bi-bricks"></i> Page Setup</h4>

            <!-- Page Setup save/load -->
            <div class="d-flex gap-1 mb-3 align-items-center">
                <select id="myls_bb_setup_history" class="form-select form-select-sm flex-grow-1">
                    <option value="">— Load Saved Setup —</option>
                </select>
                <button type="button" class="button button-small" id="myls_bb_setup_load" title="Load selected setup">
                    <i class="bi bi-folder2-open"></i>
                </button>
                <button type="button" class="button button-small" id="myls_bb_setup_save" title="Save current setup as template">
                    <i class="bi bi-floppy"></i>
                </button>
                <button type="button" class="button button-small" id="myls_bb_setup_delete" title="Delete selected setup" style="color:#dc3545;">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
            <div id="myls_bb_setup_save_row" style="display:none;" class="d-flex gap-1 mb-2">
                <input type="text" id="myls_bb_setup_save_name" class="form-control form-control-sm" placeholder="Name this setup…" style="flex:1;">
                <button type="button" class="button button-primary button-small" id="myls_bb_setup_save_confirm"><i class="bi bi-check-lg"></i> Save</button>
                <button type="button" class="button button-small" id="myls_bb_setup_save_cancel"><i class="bi bi-x-lg"></i></button>
            </div>
            <div id="myls_bb_setup_msg" style="display:none;font-size:12px;padding:4px 8px;border-radius:4px;margin-bottom:8px;"></div>

            <label class="form-label fw-bold">Post Type</label>
            <select id="myls_bb_post_type" class="form-select mb-3">
                <?php foreach ( $pts as $pt => $obj ): ?>
                    <option value="<?php echo esc_attr( $pt ); ?>" <?php selected( $pt, 'page' ); ?>>
                        <?php echo esc_html( $obj->labels->singular_name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="form-label fw-bold">Page Title <span class="text-danger">*</span></label>
            <input type="text" id="myls_bb_title" class="form-control mb-3" placeholder="e.g., Roof Replacement – Expert Service in Austin">

            <label class="form-label fw-bold">Description / Instructions</label>
            <div class="form-text mb-1" style="font-size:11px;">
                Supports tokens: <code>{{PAGE_TITLE}}</code> <code>{{YOAST_TITLE}}</code> <code>{{CITY}}</code>
                <code>{{BUSINESS_NAME}}</code> <code>{{PHONE}}</code> <code>{{EMAIL}}</code>
            </div>

            <!-- Description History -->
            <div class="d-flex gap-1 mb-2 align-items-end">
                <div class="flex-grow-1">
                    <select id="myls_bb_desc_history" class="form-select form-select-sm">
                        <option value="">— Saved Descriptions —</option>
                    </select>
                </div>
                <button type="button" class="button button-small" id="myls_bb_desc_load" title="Load selected description"><i class="bi bi-folder2-open"></i></button>
                <button type="button" class="button button-small" id="myls_bb_desc_save" title="Save current description"><i class="bi bi-floppy"></i></button>
                <button type="button" class="button button-small" id="myls_bb_desc_delete" title="Delete selected description" style="color:#dc3545;"><i class="bi bi-trash"></i></button>
            </div>
            <div id="myls_bb_save_row" style="display:none;" class="d-flex gap-1 mb-2">
                <input type="text" id="myls_bb_save_name" class="form-control form-control-sm" placeholder="Name this description…" style="flex:1;">
                <button type="button" class="button button-primary button-small" id="myls_bb_save_confirm"><i class="bi bi-check-lg"></i> Save</button>
                <button type="button" class="button button-small" id="myls_bb_save_cancel"><i class="bi bi-x-lg"></i></button>
            </div>
            <div id="myls_bb_desc_msg" style="display:none;font-size:12px;padding:4px 8px;border-radius:4px;margin-bottom:6px;"></div>

            <textarea id="myls_bb_description" class="form-control mb-1" rows="8"
                      placeholder="Describe what this page is about. Include service details, target audience, location, and any key selling points."></textarea>
            <div class="form-text mb-3">Features, audience, tone, structure — the more detail, the better.</div>

            <hr class="my-3">

            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label fw-bold">Status</label>
                    <select id="myls_bb_status" class="form-select">
                        <option value="draft" selected>Draft</option>
                        <option value="publish">Publish</option>
                    </select>
                </div>
                <div class="col-6 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="myls_bb_menu">
                        <label class="form-check-label" for="myls_bb_menu">Add to Menu</label>
                    </div>
                </div>
            </div>
            <div id="myls_bb_nav_info" class="form-text mb-3" style="display:none;"></div>

            <!-- Slug + Parent Page -->
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label fw-bold">Slug <span class="text-muted fw-normal" style="font-size:11px;">(optional)</span></label>
                    <input type="text" id="myls_bb_slug" class="form-control form-control-sm" placeholder="auto-generated from title">
                </div>
                <div class="col-6">
                    <label class="form-label fw-bold">Parent Page <span class="text-muted fw-normal" style="font-size:11px;">(optional)</span></label>
                    <select id="myls_bb_parent_page" class="form-select form-select-sm">
                        <option value="0">— No Parent —</option>
                    </select>
                </div>
            </div>

            <hr class="my-3">

            <div class="mb-3">
                <label class="form-label fw-bold mb-1" for="myls_bb_seo_keyword">
                    <i class="bi bi-search"></i> Yoast SEO Title / Focus Keyword
                </label>
                <input type="text" id="myls_bb_seo_keyword" class="form-control form-control-sm"
                       placeholder="e.g. Dog Training Tampa FL"
                       title="Used as Yoast focus keyword and to guide AI content generation.">
                <div class="form-text">Saved to Yoast focus keyword &amp; title.</div>
            </div>

            <!-- ══ PAGE SECTIONS — drag-and-drop unified list ═══════════ -->
            <div class="card mb-3" style="border:1px solid #ddd;background:#f9fbff;">
                <div class="card-body py-2 px-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <strong style="font-size:13px;"><i class="bi bi-list-task"></i> Page Sections</strong>
                        <span class="badge bg-secondary" style="font-size:10px;" id="myls_bb_section_count_badge">— sections</span>
                    </div>
                    <div class="small text-muted mb-2" style="font-size:11px;">
                        <i class="bi bi-grip-vertical"></i> Drag to reorder &nbsp;·&nbsp;
                        ☑ Include / exclude &nbsp;·&nbsp;
                        Templates can sit anywhere in the page
                    </div>

                    <input type="hidden" id="myls_bb_sections_order" value="">

                    <div id="myls_bb_section_list"></div>

                    <button type="button" id="myls_bb_add_template_btn">
                        <i class="bi bi-plus-circle"></i> Add Template
                    </button>
                    <div id="myls_bb_tpl_loading_note" style="display:none;font-size:11px;color:#888;margin-top:4px;">
                        <i class="bi bi-hourglass-split"></i> Loading templates…
                    </div>
                </div>
            </div>

            <!-- Business Variables -->
            <h5 class="mb-2">Business Variables</h5>
            <p class="form-text mt-0 mb-2">Auto-filled from <a href="<?php echo admin_url( 'admin.php?page=aintelligize&tab=schema' ); ?>" target="_blank">Schema settings</a>. Edit here for this session only.</p>
            <div class="row g-2 mb-2">
                <div class="col-6">
                    <label class="form-label small">Business Name</label>
                    <input type="text" id="myls_bb_biz_name" class="form-control form-control-sm" value="<?php echo esc_attr( $biz_name ); ?>">
                </div>
                <div class="col-6">
                    <label class="form-label small">City, State</label>
                    <input type="text" id="myls_bb_biz_city" class="form-control form-control-sm" value="<?php echo esc_attr( $biz_city ); ?>">
                </div>
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label small">Phone</label>
                    <input type="text" id="myls_bb_biz_phone" class="form-control form-control-sm" value="<?php echo esc_attr( $biz_phone ); ?>">
                </div>
                <div class="col-6">
                    <label class="form-label small">Email</label>
                    <input type="text" id="myls_bb_biz_email" class="form-control form-control-sm" value="<?php echo esc_attr( $biz_email ); ?>">
                </div>
            </div>
            <div class="row g-2 mt-1">
                <div class="col-12">
                    <label class="form-label small">Contact / CTA Button URL</label>
                    <div class="form-text p-2" style="background:#f0f4ff;border:1px solid #c7d2fe;border-radius:4px;font-size:11px;">
                        Resolved: <code><?php echo esc_html( esc_url_raw( $bb_resolved_url ) ); ?></code><br>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=myls-ai&tab=faqs' ) ); ?>" target="_blank">
                            Change in AI Content → FAQ Builder → Contact Page
                        </a>
                    </div>
                </div>
            </div>

            <input type="hidden" id="myls_bb_nonce" value="<?php echo esc_attr( $nonce ); ?>">
        </div><!-- /LEFT -->

        <!-- ══════════════ RIGHT: Prompt + Results ════════════════════════ -->
        <div style="border:1px solid #000; padding:16px; border-radius:1em;">

            <div class="alert alert-info d-flex gap-2 align-items-start mb-3 py-2 px-3" style="font-size:13px;border-radius:8px;">
                <i class="bi bi-flask mt-1" style="flex-shrink:0;"></i>
                <div>
                    <strong>Beaver Builder</strong> — Generates native BB modules:
                    <strong>Heading · Rich Text · Button · Photo · Icon</strong>.
                    All content is directly editable in the Beaver Builder canvas.
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
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong>AI Prompt Template</strong>
                        <div class="d-flex gap-1">
                            <button type="button" class="button" id="myls_bb_reset_prompt" title="Reset to built-in default">↺ Reset to Default</button>
                            <button type="button" class="button button-primary" id="myls_bb_save_prompt">Save to DB</button>
                        </div>
                    </div>
                    <?php if ( function_exists( 'myls_prompt_toolbar' ) ) myls_prompt_toolbar( 'beaver-builder', 'myls_bb_prompt' ); ?>
                    <textarea id="myls_bb_prompt" class="form-control font-monospace" rows="12"
                              style="font-size:12px;"><?php echo esc_textarea( $saved_prompt ); ?></textarea>
                    <small style="color:#666;">The prompt must instruct the AI to output <strong>JSON</strong>. Saving an empty value restores the built-in default.</small>
                </div>
            </div>

            <!-- ══ DALL-E 3 Image Generation ════════════════════════════════ -->
            <div class="card mb-3" style="border:1px solid #ddd;">
                <div class="card-header d-flex justify-content-between align-items-center" style="padding:8px 12px;">
                    <strong><i class="bi bi-image"></i> AI Images &mdash; DALL-E 3</strong>
                    <span class="badge bg-secondary">Optional</span>
                </div>
                <div class="card-body pb-2">

                    <div class="myls-bb-dalle-row">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="myls_bb_gen_hero" checked>
                        </div>
                        <div class="myls-bb-dalle-label">
                            <i class="bi bi-card-image"></i> Hero / Banner Image
                            <small>Wide banner placed inside the hero section &mdash; 1792&times;1024</small>
                        </div>
                        <div class="myls-bb-dalle-sub">
                            <input class="form-check-input" type="checkbox" id="myls_bb_set_featured" checked>
                            <label for="myls_bb_set_featured" style="cursor:pointer;">Set as post thumbnail</label>
                        </div>
                    </div>

                    <div class="myls-bb-dalle-row">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="myls_bb_gen_feature_cards">
                        </div>
                        <div class="myls-bb-dalle-label">
                            <i class="bi bi-grid-3x2-gap"></i> Feature Card Images
                            <small>One square image per card (1024&times;1024) &mdash; count matches Cols × Rows</small>
                        </div>
                    </div>

                    <div class="mb-2 mt-1">
                        <label class="form-label small mb-1">Image Style</label>
                        <select id="myls_bb_img_style" class="form-select form-select-sm">
                            <option value="photo"            selected>Photo &mdash; real camera shot, natural light</option>
                            <option value="photorealistic">Photorealistic &mdash; stock photography style</option>
                            <option value="modern-flat">Modern Flat &mdash; clean illustration</option>
                            <option value="isometric">Isometric 3D &mdash; tech / icon style</option>
                            <option value="watercolor">Watercolor &mdash; artistic, warm tones</option>
                            <option value="gradient-abstract">Abstract Gradient &mdash; modern, vivid</option>
                        </select>
                    </div>

                    <div class="d-flex align-items-center gap-2 mt-2 mb-1">
                        <button type="button" class="button" id="myls_bb_test_dalle_btn">
                            <i class="bi bi-plug"></i> Test DALL-E Connection
                        </button>
                        <span id="myls_bb_test_dalle_status" style="font-size:12px;"></span>
                    </div>
                    <pre id="myls_bb_test_dalle_log" style="display:none;margin-top:6px;background:#1e1e1e;color:#d4d4d4;padding:10px;border-radius:6px;font-size:11px;max-height:200px;overflow:auto;white-space:pre-wrap;"></pre>
                    <div class="form-text">Uses DALL-E 3 &middot; ~$0.04 / standard image &middot; Uploads to Media Library.</div>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mb-2">
                <button type="button" class="button button-primary button-hero" id="myls_bb_create_btn">
                    <i class="bi bi-lightning-charge"></i> Create Beaver Builder Page with AI
                </button>
                <button type="button" class="button button-secondary" id="myls_bb_gen_images_btn" style="display:none;">
                    <i class="bi bi-images"></i> Generate Images
                </button>
            </div>

            <div id="myls_bb_progress_wrap" style="display:none; margin-bottom:12px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                    <span id="myls_bb_progress_label" style="font-size:12px; color:#555; font-weight:500;">Starting…</span>
                    <span id="myls_bb_progress_pct" style="font-size:11px; color:#888;">0%</span>
                </div>
                <div style="background:#e9ecef; border-radius:50px; height:10px; overflow:hidden; width:100%;">
                    <div id="myls_bb_progress_bar" style="height:100%; width:0%; border-radius:50px; background:linear-gradient(90deg,#4f46e5,#7c3aed,#a855f7); background-size:200% 100%; transition:width 0.6s ease; animation:myls-bb-shimmer 1.8s linear infinite;"></div>
                </div>
                <div id="myls_bb_progress_steps" style="display:flex; gap:0; margin-top:6px;">
                    <div class="myls-bb-pstep" data-step="1" style="flex:1;text-align:center;font-size:10px;color:#aaa;padding:2px 0;border-top:2px solid #e0e0e0;transition:color .3s,border-color .3s;">🔑 API</div>
                    <div class="myls-bb-pstep" data-step="2" style="flex:1;text-align:center;font-size:10px;color:#aaa;padding:2px 0;border-top:2px solid #e0e0e0;transition:color .3s,border-color .3s;">🎨 Images</div>
                    <div class="myls-bb-pstep" data-step="3" style="flex:1;text-align:center;font-size:10px;color:#aaa;padding:2px 0;border-top:2px solid #e0e0e0;transition:color .3s,border-color .3s;">✍️ Content</div>
                    <div class="myls-bb-pstep" data-step="4" style="flex:1;text-align:center;font-size:10px;color:#aaa;padding:2px 0;border-top:2px solid #e0e0e0;transition:color .3s,border-color .3s;">🏗️ BB Layout</div>
                    <div class="myls-bb-pstep" data-step="5" style="flex:1;text-align:center;font-size:10px;color:#aaa;padding:2px 0;border-top:2px solid #e0e0e0;transition:color .3s,border-color .3s;">✅ Done</div>
                </div>
            </div>

            <hr>

            <div class="myls-results-header">
                <label class="form-label mb-0 fw-bold"><i class="bi bi-terminal"></i> Results</label>
                <div class="d-flex gap-2 align-items-center">
                    <span id="myls_bb_section_badge" style="display:none;" class="badge bg-primary"></span>
                    <button type="button" class="myls-btn-export-pdf" data-log-target="myls_bb_log"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
                    <span id="myls_bb_edit_link" style="display:none;">
                        <a id="myls_bb_edit_url" href="#" target="_blank" class="button button-secondary">
                            <i class="bi bi-pencil-square"></i> Edit in Beaver Builder
                        </a>
                        <a id="myls_bb_preview_url" href="#" target="_blank" class="button button-secondary" style="margin-left:4px;">
                            <i class="bi bi-eye"></i> Preview Page
                        </a>
                    </span>
                </div>
            </div>
            <pre id="myls_bb_log" class="myls-results-terminal">Ready.</pre>

            <div id="myls_bb_img_preview" style="display:none;" class="mt-3">
                <label class="form-label fw-bold"><i class="bi bi-images"></i> Generated Images</label>
                <div id="myls_bb_img_grid" class="d-flex flex-wrap gap-2"></div>
            </div>

            <!-- Debug Inspector -->
            <div class="mt-3">
                <div class="d-flex align-items-center gap-2">
                    <strong style="font-size:13px;"><i class="bi bi-bug"></i> Debug Inspector</strong>
                    <input type="number" id="myls_bb_debug_post_id" class="form-control form-control-sm" style="width:110px;" placeholder="Post ID">
                    <button type="button" class="button" id="myls_bb_debug_btn">Inspect BB Data</button>
                </div>
                <pre id="myls_bb_debug_output" style="display:none;margin-top:8px;background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:6px;font-size:11px;max-height:300px;overflow:auto;white-space:pre-wrap;"></pre>
            </div>
        </div><!-- /RIGHT -->

        </div><!-- /grid -->

        <script>
        (function(){
            const $ = id => document.getElementById(id);
            let lastPostId      = 0;
            let descHistory     = [];
            let setupHistory    = [];
            let loadedTemplates = [];

            const SECTION_DEFS = {
                hero:         { label: 'Hero Banner',    icon: '🖼️'  },
                tldr:         { label: 'TL;DR Block',    icon: '🟢'  },
                trust_bar:    { label: 'Trust Bar',      icon: '🟡'  },
                intro:        { label: 'Service Intro',  icon: '📝'  },
                features:     { label: 'Feature Cards',  icon: '🃏',  hasCols: true, hasWidgetType: true },
                rich_content: { label: 'Rich Content',   icon: '📄'  },
                process:      { label: 'How It Works',   icon: '⚙️',  hasCols: true, hasWidgetType: true },
                pricing:      { label: 'Pricing',        icon: '💜'  },
                cta:          { label: 'CTA Block',      icon: '📣'  },
            };

            const DEFAULT_SECTIONS = [
                { id:'hero',         type:'section', enabled:true },
                { id:'tldr',         type:'section', enabled:true },
                { id:'intro',        type:'section', enabled:true },
                { id:'trust_bar',    type:'section', enabled:true },
                { id:'features',     type:'section', enabled:true, cols:3, rows:1, widget_type:'icon' },
                { id:'rich_content', type:'section', enabled:true },
                { id:'process',      type:'section', enabled:true, cols:2, rows:2, widget_type:'icon' },
                { id:'pricing',      type:'section', enabled:true },
                { id:'cta',          type:'section', enabled:true },
            ];

            let sectionItems = JSON.parse(JSON.stringify(DEFAULT_SECTIONS));

            function uid() { return 'tpl_' + Math.random().toString(36).slice(2,8); }

            function serializeSections() {
                const list = $('myls_bb_section_list');
                if (!list) return;
                const rows = list.querySelectorAll('.myls-bb-section-row');
                const result = [];
                rows.forEach(row => {
                    const idx  = parseInt(row.dataset.sectionIdx);
                    const item = sectionItems[idx];
                    if (!item) return;
                    const chk = row.querySelector('.myls-bb-section-check');
                    item.enabled = chk ? chk.checked : true;
                    if (item.type === 'section' && SECTION_DEFS[item.id]?.hasCols) {
                        const colEl = row.querySelector('.myls-bb-cols-input');
                        const rowEl = row.querySelector('.myls-bb-rows-input');
                        if (colEl) item.cols = Math.max(1, Math.min(6, parseInt(colEl.value)||3));
                        if (rowEl) item.rows = Math.max(1, Math.min(6, parseInt(rowEl.value)||1));
                    }
                    if (item.type === 'section' && SECTION_DEFS[item.id]?.hasWidgetType) {
                        const wtEl = row.querySelector('.myls-bb-widget-type-select');
                        if (wtEl) item.widget_type = wtEl.value || 'icon';
                    }
                    if (item.type === 'template') {
                        const sel = row.querySelector('.myls-bb-tpl-select');
                        item.template_id = sel ? parseInt(sel.value)||0 : 0;
                    }
                    result.push(item);
                });
                sectionItems = result;
                const inp = $('myls_bb_sections_order');
                if (inp) inp.value = JSON.stringify(sectionItems);
                const enabled = sectionItems.filter(i => i.enabled).length;
                const badge   = $('myls_bb_section_count_badge');
                if (badge) badge.textContent = enabled + ' of ' + sectionItems.length + ' enabled';
            }

            function renderSectionList() {
                const list = $('myls_bb_section_list');
                if (!list) return;
                list.innerHTML = '';

                sectionItems.forEach((item, idx) => {
                    const row = document.createElement('div');
                    row.className  = 'myls-bb-section-row' + (item.enabled ? '' : ' myls-bb-disabled');
                    row.draggable  = true;
                    row.dataset.sectionIdx = idx;

                    const handle = document.createElement('span');
                    handle.className   = 'myls-bb-drag-handle';
                    handle.textContent = '⠿';
                    handle.title       = 'Drag to reorder';
                    row.appendChild(handle);

                    const chk = document.createElement('input');
                    chk.type      = 'checkbox';
                    chk.className = 'form-check-input myls-bb-section-check';
                    chk.checked   = !!item.enabled;
                    chk.addEventListener('change', () => {
                        row.classList.toggle('myls-bb-disabled', !chk.checked);
                        serializeSections();
                    });
                    row.appendChild(chk);

                    if (item.type === 'section') {
                        const def = SECTION_DEFS[item.id] || {};
                        const lbl = document.createElement('span');
                        lbl.className   = 'myls-bb-section-label';
                        lbl.textContent = (def.icon || '') + ' ' + (def.label || item.id);
                        row.appendChild(lbl);

                        if (def.hasCols) {
                            const opts = document.createElement('span');
                            opts.className = 'myls-bb-section-opts';
                            opts.innerHTML =
                                '<label>Cols<input type="number" class="myls-bb-cols-input" value="'+(item.cols||3)+'" min="1" max="6" title="Grid columns"></label>' +
                                '<label>Rows<input type="number" class="myls-bb-rows-input" value="'+(item.rows||1)+'" min="1" max="6" title="Grid rows"></label>';
                            opts.querySelectorAll('input').forEach(el => {
                                el.addEventListener('change', serializeSections);
                                el.addEventListener('input',  serializeSections);
                            });
                            row.appendChild(opts);
                        }

                        if (def.hasWidgetType) {
                            const wt = item.widget_type || 'icon';
                            const wtSel = document.createElement('select');
                            wtSel.className = 'form-select form-select-sm myls-bb-widget-type-select';
                            wtSel.style.cssText = 'width:auto;max-width:120px;font-size:11px;padding:2px 22px 2px 6px;';
                            wtSel.title = 'Widget type: Icon Box uses Font Awesome icons; Image Box uses images';
                            wtSel.innerHTML =
                                '<option value="icon"' +(wt==='icon'  ? ' selected':'')+ '>Icon Box</option>' +
                                '<option value="image"'+(wt==='image' ? ' selected':'')+ '>Image Box</option>';
                            wtSel.addEventListener('change', serializeSections);
                            row.appendChild(wtSel);
                        }

                    } else {
                        const lbl = document.createElement('span');
                        lbl.className = 'myls-bb-section-label';
                        const savedTpl = loadedTemplates.find(t => t.id == item.template_id);
                        lbl.textContent = '📄 ' + (savedTpl ? savedTpl.title : 'Template');
                        row.appendChild(lbl);

                        const sel = document.createElement('select');
                        sel.className = 'form-select form-select-sm myls-bb-tpl-select';
                        const blank = document.createElement('option');
                        blank.value = ''; blank.textContent = '— Select Template —';
                        sel.appendChild(blank);

                        loadedTemplates.forEach(t => {
                            const opt = document.createElement('option');
                            opt.value       = t.id;
                            opt.textContent = t.title + (t.type ? ' ['+t.type+']' : '');
                            if (t.id == item.template_id) opt.selected = true;
                            sel.appendChild(opt);
                        });

                        sel.addEventListener('change', () => {
                            const chosen = loadedTemplates.find(t => t.id == sel.value);
                            lbl.textContent = '📄 ' + (chosen ? chosen.title : 'Template');
                            serializeSections();
                        });
                        row.appendChild(sel);

                        const rem = document.createElement('button');
                        rem.type      = 'button';
                        rem.className = 'myls-bb-tpl-remove';
                        rem.title     = 'Remove template slot';
                        rem.textContent = '✕';
                        rem.addEventListener('click', () => {
                            sectionItems.splice(idx, 1);
                            renderSectionList();
                            serializeSections();
                        });
                        row.appendChild(rem);
                    }

                    list.appendChild(row);
                });

                let dragSrc = null;
                list.querySelectorAll('.myls-bb-section-row').forEach(row => {
                    row.addEventListener('dragstart', e => {
                        dragSrc = row;
                        row.classList.add('myls-bb-dragging');
                        e.dataTransfer.effectAllowed = 'move';
                        e.dataTransfer.setData('text/plain', row.dataset.sectionIdx);
                    });
                    row.addEventListener('dragend', () => {
                        row.classList.remove('myls-bb-dragging');
                        list.querySelectorAll('.myls-bb-drag-over').forEach(r => r.classList.remove('myls-bb-drag-over'));
                    });
                    row.addEventListener('dragover', e => {
                        e.preventDefault();
                        e.dataTransfer.dropEffect = 'move';
                        list.querySelectorAll('.myls-bb-drag-over').forEach(r => r.classList.remove('myls-bb-drag-over'));
                        if (row !== dragSrc) row.classList.add('myls-bb-drag-over');
                    });
                    row.addEventListener('dragleave', () => row.classList.remove('myls-bb-drag-over'));
                    row.addEventListener('drop', e => {
                        e.preventDefault();
                        if (!dragSrc || dragSrc === row) return;
                        row.classList.remove('myls-bb-drag-over');
                        serializeSections();
                        const srcIdx  = parseInt(dragSrc.dataset.sectionIdx);
                        const dstIdx  = parseInt(row.dataset.sectionIdx);
                        const [moved] = sectionItems.splice(srcIdx, 1);
                        sectionItems.splice(dstIdx, 0, moved);
                        renderSectionList();
                        serializeSections();
                    });
                });

                serializeSections();
            }

            $('myls_bb_add_template_btn')?.addEventListener('click', () => {
                sectionItems.push({ id: uid(), type:'template', enabled:true, template_id:0 });
                renderSectionList();
            });

            (async () => {
                $('myls_bb_tpl_loading_note').style.display = '';
                const fd = new FormData();
                fd.append('action',   'myls_bb_get_templates');
                fd.append('_wpnonce', $('myls_bb_nonce').value);
                try {
                    const res  = await fetch(ajaxurl, { method:'POST', body:fd });
                    const data = await res.json();
                    if (data?.success && data.data.templates?.length) {
                        loadedTemplates = data.data.templates;
                    }
                } catch(e) { console.warn('BB template load failed:', e); }
                finally {
                    $('myls_bb_tpl_loading_note').style.display = 'none';
                    renderSectionList();
                }
            })();

            renderSectionList();

            /* ── Progress bar helpers ─────────────────────────────────────── */
            let _progressTimer = null;
            const _progressStages = [
                { step:1, pct:8,  label:'🔑 Validating…' },
                { step:2, pct:28, label:'🎨 Generating images with DALL-E 3…' },
                { step:2, pct:48, label:'🎨 Uploading images to Media Library…' },
                { step:3, pct:62, label:'✍️ AI writing content…' },
                { step:3, pct:74, label:'✍️ AI writing content…' },
                { step:4, pct:84, label:'🏗️ Building BB rows + modules…' },
                { step:4, pct:91, label:'🏗️ Persisting layout…' },
            ];
            function progressStart(withImages) {
                const wrap = $('myls_bb_progress_wrap'), bar = $('myls_bb_progress_bar'),
                      lbl = $('myls_bb_progress_label'), pct = $('myls_bb_progress_pct');
                if (!wrap) return;
                bar.style.transition = 'none'; bar.style.width = '0%';
                pct.textContent = '0%'; lbl.textContent = 'Starting…';
                document.querySelectorAll('.myls-bb-pstep').forEach(el => el.classList.remove('active','done'));
                wrap.style.display = '';
                requestAnimationFrame(() => { bar.style.transition = 'width 0.6s ease'; });

                let si = 0;
                const stages = withImages ? _progressStages : _progressStages.filter(s => s.step !== 2);
                const ivs    = withImages ? [800,8000,18000,8000,10000,4000,5000] : [800,8000,8000,4000,5000];
                function tick() {
                    if (si >= stages.length) return;
                    const stage = stages[si];
                    bar.style.width = stage.pct + '%'; pct.textContent = stage.pct + '%'; lbl.textContent = stage.label;
                    document.querySelectorAll('.myls-bb-pstep').forEach(el => {
                        const s = parseInt(el.dataset.step);
                        if (s < stage.step)        { el.classList.add('done');   el.classList.remove('active'); }
                        else if (s === stage.step) { el.classList.add('active'); el.classList.remove('done');   }
                        else                       { el.classList.remove('active','done'); }
                    });
                    si++;
                    if (si < stages.length) _progressTimer = setTimeout(tick, ivs[si] || 6000);
                }
                _progressTimer = setTimeout(tick, ivs[0]);
            }
            function progressDone(success) {
                clearTimeout(_progressTimer);
                const bar = $('myls_bb_progress_bar'), lbl = $('myls_bb_progress_label'), pct = $('myls_bb_progress_pct');
                if (!bar) return;
                bar.style.width = '100%'; pct.textContent = '100%';
                lbl.textContent = success ? '✅ Page created!' : '❌ Something went wrong';
                if (success) { bar.style.background = 'linear-gradient(90deg,#16a34a,#22c55e)'; bar.style.animation = 'none'; }
                document.querySelectorAll('.myls-bb-pstep').forEach(el => {
                    el.classList.remove('active');
                    if (success) el.classList.add('done');
                });
                if (success) {
                    setTimeout(() => {
                        const wrap = $('myls_bb_progress_wrap');
                        if (wrap) { wrap.style.transition = 'opacity 0.8s'; wrap.style.opacity = '0'; }
                        setTimeout(() => {
                            if (wrap) { wrap.style.display='none'; wrap.style.opacity='1'; wrap.style.transition=''; }
                            if (bar)  { bar.style.background='linear-gradient(90deg,#4f46e5,#7c3aed,#a855f7)'; bar.style.animation='myls-bb-shimmer 1.8s linear infinite'; }
                        }, 900);
                    }, 3000);
                }
            }

            /* ── Description history ─────────────────────────────────────── */
            function descMsg(text, ok=true) {
                const el = $('myls_bb_desc_msg');
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
                    fd.append('action','myls_bb_list_descriptions');
                    fd.append('_wpnonce',$('myls_bb_nonce').value);
                    const res  = await fetch(ajaxurl, { method:'POST', body:fd });
                    const data = await res.json();
                    if (data?.success) { descHistory = data.data.history||[]; renderDescDropdown(); }
                } catch(e) { console.warn('BB desc history load failed:', e); }
            }
            function renderDescDropdown() {
                const sel = $('myls_bb_desc_history');
                sel.innerHTML = '<option value="">— Saved Descriptions (' + descHistory.length + ') —</option>';
                descHistory.forEach(item => {
                    const opt = document.createElement('option');
                    opt.value = item.slug;
                    opt.textContent = item.name + (item.updated ? ' · ' + item.updated.substring(0,10) : '');
                    sel.appendChild(opt);
                });
            }
            $('myls_bb_desc_load')?.addEventListener('click', () => {
                const slug = $('myls_bb_desc_history').value;
                if (!slug) { descMsg('Select a saved description first.', false); return; }
                const item = descHistory.find(h => h.slug === slug);
                if (item) { $('myls_bb_description').value = item.description; descMsg('Loaded: '+item.name); }
            });
            $('myls_bb_desc_save')?.addEventListener('click', () => {
                const desc = $('myls_bb_description').value.trim();
                if (!desc) { descMsg('Write a description first.', false); return; }
                const nameEl = $('myls_bb_save_name');
                if (!nameEl.value) nameEl.value = $('myls_bb_title').value.trim() || '';
                $('myls_bb_save_row').style.display = '';
                nameEl.focus(); nameEl.select();
            });
            $('myls_bb_save_cancel')?.addEventListener('click', () => { $('myls_bb_save_row').style.display='none'; });
            $('myls_bb_save_confirm')?.addEventListener('click', async () => {
                const name = $('myls_bb_save_name').value.trim();
                const desc = $('myls_bb_description').value.trim();
                if (!name) { descMsg('Enter a name.', false); return; }
                if (!desc) { descMsg('Description is empty.', false); return; }
                const fd = new FormData();
                fd.append('action','myls_bb_save_description');
                fd.append('_wpnonce',$('myls_bb_nonce').value);
                fd.append('desc_name',name);
                fd.append('description',desc);
                try {
                    const res  = await fetch(ajaxurl,{method:'POST',body:fd});
                    const data = await res.json();
                    if (data?.success) {
                        descHistory = data.data.history||[]; renderDescDropdown();
                        const slug = descHistory.find(h=>h.name===name)?.slug;
                        if (slug) $('myls_bb_desc_history').value = slug;
                        $('myls_bb_save_row').style.display = 'none';
                        descMsg('Saved: '+name);
                    } else descMsg(data?.data?.message||'Save failed.', false);
                } catch(e) { descMsg('Network error: '+e.message, false); }
            });
            $('myls_bb_save_name')?.addEventListener('keydown', e => {
                if (e.key==='Enter')  { e.preventDefault(); $('myls_bb_save_confirm').click(); }
                if (e.key==='Escape') { $('myls_bb_save_row').style.display='none'; }
            });
            $('myls_bb_desc_delete')?.addEventListener('click', async () => {
                const slug = $('myls_bb_desc_history').value;
                if (!slug) { descMsg('Select a description to delete first.', false); return; }
                const item = descHistory.find(h=>h.slug===slug);
                const label = item?.name||slug;
                const msgEl = $('myls_bb_desc_msg');
                if (msgEl._pendingDelete===slug) {
                    msgEl._pendingDelete = null;
                    const fd = new FormData();
                    fd.append('action','myls_bb_delete_description');
                    fd.append('_wpnonce',$('myls_bb_nonce').value);
                    fd.append('desc_slug',slug);
                    try {
                        const res  = await fetch(ajaxurl,{method:'POST',body:fd});
                        const data = await res.json();
                        if (data?.success) { descHistory=data.data.history||[]; renderDescDropdown(); descMsg('Deleted: '+label); }
                        else descMsg(data?.data?.message||'Delete failed.', false);
                    } catch(e) { descMsg('Network error: '+e.message, false); }
                } else {
                    msgEl._pendingDelete = slug;
                    descMsg('Click delete again to confirm removing "'+label+'"', false);
                    setTimeout(() => { if(msgEl._pendingDelete===slug) msgEl._pendingDelete=null; }, 4000);
                }
            });
            loadDescHistory();

            /* ── Page Setup snapshot save/load ───────────────────────────── */
            function setupMsg(text, ok=true) {
                const el = $('myls_bb_setup_msg');
                el.textContent = text;
                el.style.background = ok ? '#d4edda' : '#f8d7da';
                el.style.color      = ok ? '#155724' : '#721c24';
                el.style.display    = '';
                clearTimeout(el._t);
                el._t = setTimeout(() => { el.style.display='none'; }, 3000);
            }
            function getSetupSnapshot() {
                serializeSections();
                return {
                    post_type:         $('myls_bb_post_type')?.value      || 'page',
                    title:             $('myls_bb_title')?.value           || '',
                    description:       $('myls_bb_description')?.value     || '',
                    seo_keyword:       $('myls_bb_seo_keyword')?.value     || '',
                    status:            $('myls_bb_status')?.value          || 'draft',
                    add_to_menu:       $('myls_bb_menu')?.checked           ?? false,
                    page_slug:         ($('myls_bb_slug')?.value || '').trim(),
                    parent_page_id:    parseInt($('myls_bb_parent_page')?.value || '0'),
                    gen_hero:          $('myls_bb_gen_hero')?.checked       ?? true,
                    gen_feature_cards: $('myls_bb_gen_feature_cards')?.checked ?? false,
                    image_style:       $('myls_bb_img_style')?.value       || 'photo',
                    set_featured:      $('myls_bb_set_featured')?.checked   ?? true,
                    sections_order:    JSON.parse($('myls_bb_sections_order')?.value || '[]'),
                    biz_name:          $('myls_bb_biz_name')?.value        || '',
                    biz_city:          $('myls_bb_biz_city')?.value        || '',
                    biz_phone:         $('myls_bb_biz_phone')?.value       || '',
                    biz_email:         $('myls_bb_biz_email')?.value       || '',
                    prompt_template:   $('myls_bb_prompt')?.value          || '',
                };
            }
            function applySetupSnapshot(snap) {
                if (!snap) return;
                if ($('myls_bb_post_type')    && snap.post_type)            $('myls_bb_post_type').value    = snap.post_type;
                if ($('myls_bb_title')        && snap.title != null)        $('myls_bb_title').value        = snap.title;
                if ($('myls_bb_description')  && snap.description != null)  $('myls_bb_description').value  = snap.description;
                if ($('myls_bb_seo_keyword')  && snap.seo_keyword != null)  $('myls_bb_seo_keyword').value  = snap.seo_keyword;
                if ($('myls_bb_status')       && snap.status)               $('myls_bb_status').value       = snap.status;
                if ($('myls_bb_menu')         && snap.add_to_menu != null)  $('myls_bb_menu').checked       = !!snap.add_to_menu;
                if ($('myls_bb_slug')         && snap.page_slug != null)    $('myls_bb_slug').value         = snap.page_slug || '';
                if ($('myls_bb_parent_page')  && snap.parent_page_id != null) $('myls_bb_parent_page').value = String(snap.parent_page_id || 0);
                if ($('myls_bb_gen_hero')     && snap.gen_hero != null)     $('myls_bb_gen_hero').checked   = !!snap.gen_hero;
                if ($('myls_bb_gen_feature_cards') && snap.gen_feature_cards != null) $('myls_bb_gen_feature_cards').checked = !!snap.gen_feature_cards;
                if ($('myls_bb_img_style')    && snap.image_style)          $('myls_bb_img_style').value    = snap.image_style;
                if ($('myls_bb_set_featured') && snap.set_featured != null) $('myls_bb_set_featured').checked = !!snap.set_featured;
                if ($('myls_bb_biz_name')     && snap.biz_name != null)     $('myls_bb_biz_name').value     = snap.biz_name;
                if ($('myls_bb_biz_city')     && snap.biz_city != null)     $('myls_bb_biz_city').value     = snap.biz_city;
                if ($('myls_bb_biz_phone')    && snap.biz_phone != null)    $('myls_bb_biz_phone').value    = snap.biz_phone;
                if ($('myls_bb_biz_email')    && snap.biz_email != null)    $('myls_bb_biz_email').value    = snap.biz_email;
                if ($('myls_bb_prompt')       && snap.prompt_template != null) $('myls_bb_prompt').value    = snap.prompt_template;
                if (Array.isArray(snap.sections_order) && snap.sections_order.length) {
                    sectionItems = snap.sections_order;
                }
                renderSectionList();
                serializeSections();
                loadParentPages();
            }
            function renderSetupDropdown() {
                const sel = $('myls_bb_setup_history');
                sel.innerHTML = '<option value="">— Load Saved Setup ('+setupHistory.length+') —</option>';
                setupHistory.forEach(item => {
                    const opt = document.createElement('option');
                    opt.value = item.slug;
                    opt.textContent = item.name + (item.updated ? ' · '+item.updated.substring(0,10) : '');
                    sel.appendChild(opt);
                });
            }
            async function loadSetupHistory() {
                try {
                    const fd = new FormData();
                    fd.append('action','myls_bb_list_setups');
                    fd.append('_wpnonce',$('myls_bb_nonce').value);
                    const res  = await fetch(ajaxurl,{method:'POST',body:fd});
                    const data = await res.json();
                    if (data?.success) { setupHistory=data.data.history||[]; renderSetupDropdown(); }
                } catch(e) { console.warn('BB setup history load failed:', e); }
            }
            $('myls_bb_setup_load')?.addEventListener('click', () => {
                const slug = $('myls_bb_setup_history').value;
                if (!slug) { setupMsg('Select a saved setup to load.', false); return; }
                const item = setupHistory.find(h=>h.slug===slug);
                if (item?.setup) { applySetupSnapshot(item.setup); setupMsg('Loaded: '+item.name); }
            });
            $('myls_bb_setup_save')?.addEventListener('click', () => {
                const nameEl = $('myls_bb_setup_save_name');
                if (!nameEl.value) nameEl.value = $('myls_bb_title').value.trim() || '';
                $('myls_bb_setup_save_row').style.display = '';
                nameEl.focus(); nameEl.select();
            });
            $('myls_bb_setup_save_cancel')?.addEventListener('click', () => $('myls_bb_setup_save_row').style.display='none');
            $('myls_bb_setup_save_confirm')?.addEventListener('click', async () => {
                const name = $('myls_bb_setup_save_name').value.trim();
                if (!name) { setupMsg('Enter a name for this setup.', false); return; }
                const snap = getSetupSnapshot();
                const fd = new FormData();
                fd.append('action','myls_bb_save_setup');
                fd.append('_wpnonce',$('myls_bb_nonce').value);
                fd.append('setup_name',name);
                fd.append('setup_data',JSON.stringify(snap));
                try {
                    const res  = await fetch(ajaxurl,{method:'POST',body:fd});
                    const data = await res.json();
                    if (data?.success) {
                        setupHistory=data.data.history||[]; renderSetupDropdown();
                        const slug = setupHistory.find(h=>h.name===name)?.slug;
                        if (slug) $('myls_bb_setup_history').value = slug;
                        $('myls_bb_setup_save_row').style.display='none';
                        setupMsg('Saved: '+name);
                    } else setupMsg(data?.data?.message||'Save failed.', false);
                } catch(e) { setupMsg('Network error: '+e.message, false); }
            });
            $('myls_bb_setup_save_name')?.addEventListener('keydown', e => {
                if (e.key==='Enter')  { e.preventDefault(); $('myls_bb_setup_save_confirm').click(); }
                if (e.key==='Escape') { $('myls_bb_setup_save_row').style.display='none'; }
            });
            $('myls_bb_setup_delete')?.addEventListener('click', async () => {
                const slug = $('myls_bb_setup_history').value;
                if (!slug) { setupMsg('Select a setup to delete first.', false); return; }
                const item = setupHistory.find(h=>h.slug===slug);
                const label = item?.name||slug;
                const msgEl = $('myls_bb_setup_msg');
                if (msgEl._pendingDelete===slug) {
                    msgEl._pendingDelete = null;
                    const fd = new FormData();
                    fd.append('action','myls_bb_delete_setup');
                    fd.append('_wpnonce',$('myls_bb_nonce').value);
                    fd.append('setup_slug',slug);
                    try {
                        const res  = await fetch(ajaxurl,{method:'POST',body:fd});
                        const data = await res.json();
                        if (data?.success) { setupHistory=data.data.history||[]; renderSetupDropdown(); setupMsg('Deleted: '+label); }
                        else setupMsg(data?.data?.message||'Delete failed.', false);
                    } catch(e) { setupMsg('Network error: '+e.message, false); }
                } else {
                    msgEl._pendingDelete = slug;
                    setupMsg('Click delete again to confirm removing "'+label+'"', false);
                    setTimeout(() => { if(msgEl._pendingDelete===slug) msgEl._pendingDelete=null; }, 4000);
                }
            });
            loadSetupHistory();

            /* ── Parent pages loader ─────────────────────────────────────── */
            async function loadParentPages() {
                const pt  = $('myls_bb_post_type')?.value || 'page';
                const sel = $('myls_bb_parent_page');
                if (!sel) return;
                const current = sel.value;
                sel.innerHTML = '<option value="0">\u2014 No Parent \u2014</option>';
                try {
                    const fd = new FormData();
                    fd.append('action',    'myls_bb_get_parent_pages');
                    fd.append('_wpnonce',  $('myls_bb_nonce').value);
                    fd.append('post_type', pt);
                    const res  = await fetch(ajaxurl, {method:'POST', body:fd});
                    const data = await res.json();
                    if (data?.success && Array.isArray(data.data.pages)) {
                        data.data.pages.forEach(p => {
                            const opt = document.createElement('option');
                            opt.value       = p.id;
                            opt.textContent = p.title;
                            if (String(p.id) === String(current)) opt.selected = true;
                            sel.appendChild(opt);
                        });
                    }
                } catch(e) { console.warn('[AIntelligize BB] loadParentPages error', e); }
            }
            loadParentPages();
            $('myls_bb_post_type')?.addEventListener('change', loadParentPages);

            /* ── Nav post detection (block theme info) ───────────────────── */
            (async function() {
                try {
                    const fd = new FormData();
                    fd.append('action','myls_bb_get_nav_posts');
                    fd.append('_wpnonce',$('myls_bb_nonce').value);
                    const res  = await fetch(ajaxurl,{method:'POST',body:fd});
                    const data = await res.json();
                    if (data?.success && data.data.is_block_theme) {
                        const info   = $('myls_bb_nav_info');
                        const active = data.data.nav_posts?.find(n=>n.active);
                        info.innerHTML = '<i class="bi bi-info-circle"></i> Block theme · Active nav: <strong>'
                            + (active ? active.title+' (#'+active.id+')' : '(none)') + '</strong>';
                        info.style.display = '';
                    }
                } catch(e) { /* silent */ }
            })();

            /* ── Prompt save / reset ─────────────────────────────────────── */
            const defaultPrompt = <?php echo wp_json_encode( function_exists( 'myls_get_default_prompt' ) ? myls_get_default_prompt( 'beaver-builder' ) : '' ); ?>;
            const promptEl = $('myls_bb_prompt');
            if (promptEl && !promptEl.value.trim()) promptEl.value = defaultPrompt;

            $('myls_bb_save_prompt')?.addEventListener('click', async () => {
                const fd = new FormData();
                fd.append('action','myls_bb_save_prompt');
                fd.append('prompt_template',promptEl.value);
                fd.append('_wpnonce',$('myls_bb_nonce').value);
                try {
                    const res  = await fetch(ajaxurl,{method:'POST',body:fd});
                    const data = await res.json();
                    descMsg(data?.success ? '✓ Prompt template saved.' : (data?.data?.message||'Error.'), !!data?.success);
                } catch(e) { descMsg('Error: '+e.message, false); }
            });
            $('myls_bb_reset_prompt')?.addEventListener('click', async () => {
                const fd = new FormData();
                fd.append('action','myls_bb_save_prompt');
                fd.append('prompt_template','');
                fd.append('_wpnonce',$('myls_bb_nonce').value);
                try {
                    const res  = await fetch(ajaxurl,{method:'POST',body:fd});
                    const data = await res.json();
                    if (data?.success) { descMsg('✓ Reset — reloading…', true); setTimeout(()=>location.reload(),800); }
                    else descMsg(data?.data?.message||'Reset failed.', false);
                } catch(e) { descMsg('Error: '+e.message, false); }
            });

            function wantsImages() {
                return $('myls_bb_gen_hero').checked || $('myls_bb_gen_feature_cards').checked;
            }

            /* ── Create Page ─────────────────────────────────────────────── */
            $('myls_bb_create_btn')?.addEventListener('click', async () => {
                const title = $('myls_bb_title').value.trim();
                if (!title) { alert('Please enter a Page Title.'); $('myls_bb_title').focus(); return; }

                serializeSections();

                const logEl    = $('myls_bb_log');
                const btn      = $('myls_bb_create_btn');
                const editLink = $('myls_bb_edit_link');
                const imgBtn   = $('myls_bb_gen_images_btn');
                const badge    = $('myls_bb_section_badge');
                const hasImages = wantsImages();

                editLink.style.display = 'none';
                imgBtn.style.display   = 'none';
                badge.style.display    = 'none';
                $('myls_bb_img_preview').style.display = 'none';
                btn.disabled = true;
                progressStart(hasImages);

                if (hasImages) {
                    const parts = [];
                    if ($('myls_bb_gen_hero').checked) parts.push('hero image (1792×1024)');
                    if ($('myls_bb_gen_feature_cards').checked) parts.push('feature card images (1 per card, 1024×1024)');
                    logEl.textContent = '🎨 Generating: ' + parts.join(' + ') + '…\n✏️ AI will build BB rows + modules with images integrated.';
                    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Generating Images + Content…';
                } else {
                    logEl.textContent = '⏳ Generating content with AI… this may take 15–30 seconds.';
                    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Generating…';
                }

                serializeSections();

                const fd = new FormData();
                fd.append('action',            'myls_bb_create_page');
                fd.append('_wpnonce',          $('myls_bb_nonce').value);
                fd.append('page_title',        title);
                fd.append('post_type',         $('myls_bb_post_type').value);
                fd.append('page_status',       $('myls_bb_status').value);
                fd.append('page_description',  $('myls_bb_description').value);
                fd.append('prompt_template',   promptEl.value);
                fd.append('add_to_menu',       $('myls_bb_menu').checked ? '1' : '0');
                fd.append('page_slug',         ($('myls_bb_slug')?.value||'').trim());
                fd.append('parent_page_id',    $('myls_bb_parent_page')?.value || '0');
                fd.append('seo_keyword',       ($('myls_bb_seo_keyword').value||'').trim());
                fd.append('sections_order',    $('myls_bb_sections_order').value);
                fd.append('image_style',       $('myls_bb_img_style').value);
                fd.append('gen_hero',          $('myls_bb_gen_hero').checked ? '1' : '0');
                fd.append('gen_feature_cards', $('myls_bb_gen_feature_cards').checked ? '1' : '0');
                fd.append('set_featured',      $('myls_bb_set_featured').checked ? '1' : '0');
                fd.append('integrate_images',  hasImages ? '1' : '0');
                fd.append('contact_url',       '<?php echo esc_js( esc_url_raw( $bb_resolved_url ) ); ?>');

                try {
                    const controller = new AbortController();
                    const fetchTimeout = setTimeout(() => controller.abort(), 300000);
                    const res  = await fetch(ajaxurl, { method:'POST', body:fd, signal: controller.signal });
                    clearTimeout(fetchTimeout);
                    const data = await res.json();

                    if (data?.success) {
                        lastPostId = data.data.post_id || 0;
                        logEl.textContent = data.data.log_text || data.data.message || 'Done.';
                        if (data.data.section_count) {
                            badge.textContent = data.data.section_count + ' section' + (data.data.section_count!==1?'s':'') + ' (BB rows)';
                            badge.style.display = '';
                        }
                        const dbgEl = $('myls_bb_debug_post_id');
                        if (dbgEl && lastPostId) dbgEl.value = lastPostId;
                        if (data.data.edit_url) {
                            $('myls_bb_edit_url').href = data.data.edit_url;
                            if (data.data.view_url) $('myls_bb_preview_url').href = data.data.view_url;
                            editLink.style.display = '';
                        }

                        const pending = data.data.pending_images || [];
                        if (pending.length > 0) {
                            const nonce      = $('myls_bb_nonce').value;
                            const imgStyle   = data.data.image_style || 'photo';
                            const setFeat    = data.data.set_featured ? '1' : '0';
                            const imgGrid    = $('myls_bb_img_grid');
                            const imgPreview = $('myls_bb_img_preview');
                            imgGrid.innerHTML = '';
                            imgPreview.style.display = '';
                            let completed = 0;

                            for (const img of pending) {
                                completed++;
                                const label = img.type === 'feature_card'
                                    ? 'Feature Card ' + (img.index + 1)
                                    : img.type.charAt(0).toUpperCase() + img.type.slice(1);
                                logEl.textContent += '\n🖼️ Generating ' + label + ' (' + completed + '/' + pending.length + ')…';
                                btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Image ' + completed + '/' + pending.length + '…';

                                try {
                                    const imgFd = new FormData();
                                    imgFd.append('action',       'myls_bb_generate_single_image');
                                    imgFd.append('_wpnonce',     nonce);
                                    imgFd.append('post_id',      lastPostId);
                                    imgFd.append('image_type',   img.type);
                                    imgFd.append('image_index',  img.index);
                                    imgFd.append('subject',      img.subject || '');
                                    imgFd.append('size',         img.size || '1024x1024');
                                    imgFd.append('image_style',  imgStyle);
                                    imgFd.append('set_featured', setFeat);
                                    imgFd.append('page_title',   title);
                                    imgFd.append('description',  $('myls_bb_description').value);

                                    const imgCtrl    = new AbortController();
                                    const imgTimeout = setTimeout(() => imgCtrl.abort(), 180000);
                                    const imgRes     = await fetch(ajaxurl, { method:'POST', body:imgFd, signal: imgCtrl.signal });
                                    clearTimeout(imgTimeout);
                                    const imgData    = await imgRes.json();

                                    if (imgData?.success) {
                                        logEl.textContent += '\n   ✅ ' + label + ' saved (ID: ' + imgData.data.id + ')';
                                        const div = document.createElement('div');
                                        div.style.cssText = 'width:140px;text-align:center;';
                                        div.innerHTML = '<img src="'+imgData.data.url+'" style="width:140px;height:100px;object-fit:cover;border-radius:8px;border:1px solid #ddd;" alt="'+(imgData.data.subject||imgData.data.type)+'">'
                                            + '<div class="small text-muted mt-1">'+imgData.data.type+(imgData.data.subject?': '+imgData.data.subject:'')+'</div>';
                                        imgGrid.appendChild(div);
                                    } else {
                                        logEl.textContent += '\n   ❌ ' + label + ': ' + (imgData?.data?.message || 'Failed');
                                    }
                                } catch(imgErr) {
                                    logEl.textContent += '\n   ❌ ' + label + ': ' + imgErr.message;
                                }
                            }
                            logEl.textContent += '\n\n🎉 Image generation complete (' + completed + ' processed).';
                        }

                        progressDone(true);
                    } else {
                        progressDone(false);
                        logEl.textContent = '❌ ' + (data?.data?.message || 'Unknown error.');
                    }
                } catch(e) {
                    progressDone(false);
                    logEl.textContent = '❌ Network error: ' + e.message;
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-lightning-charge"></i> Create Beaver Builder Page with AI';
                }
            });

            /* ── Debug Inspector ─────────────────────────────────────────── */
            $('myls_bb_debug_btn')?.addEventListener('click', async () => {
                const postIdEl = $('myls_bb_debug_post_id');
                const outputEl = $('myls_bb_debug_output');
                const post_id  = parseInt(postIdEl.value)||lastPostId;
                if (!post_id) { alert('Enter a Post ID or create a page first.'); return; }
                outputEl.style.display = '';
                outputEl.textContent   = 'Fetching BB data for Post #'+post_id+'…';
                const fd = new FormData();
                fd.append('action','myls_bb_debug_post');
                fd.append('_wpnonce',$('myls_bb_nonce').value);
                fd.append('post_id',post_id);
                try {
                    const res  = await fetch(ajaxurl,{method:'POST',body:fd});
                    const data = await res.json();
                    if (data?.success) {
                        const d = data.data;
                        outputEl.textContent = [
                            '=== POST INFO ===',
                            'Post ID:           '+d.post_id,
                            'Title:             '+d.post_title,
                            'Status:            '+d.post_status,
                            '','=== BEAVER BUILDER META ===',
                            '_fl_builder_enabled:        '+d.fl_builder_enabled,
                            'Data stored:                '+d.data_stored,
                            'Draft stored:               '+d.draft_stored,
                            'Data length:                '+d.data_length+' bytes',
                            'Unserialize OK:             '+d.unserialize_ok,
                            '','=== LAYOUT ===',
                            'Row count:        '+d.row_count,
                            'Module count:     '+d.module_count,
                            'Module types:     '+JSON.stringify(d.module_types || {}),
                            '','=== RAW DATA PREVIEW (first 500 chars) ===',
                            d.data_preview || '(empty)',
                        ].join('\n');
                    } else { outputEl.textContent = '❌ '+(data?.data?.message||'Error'); }
                } catch(e) { outputEl.textContent = '❌ Network error: '+e.message; }
            });

            /* ── DALL-E Connection Test ──────────────────────────────────── */
            $('myls_bb_test_dalle_btn')?.addEventListener('click', async () => {
                const btn    = $('myls_bb_test_dalle_btn');
                const status = $('myls_bb_test_dalle_status');
                const log    = $('myls_bb_test_dalle_log');
                btn.disabled = true;
                btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Testing…';
                status.textContent = ''; log.style.display = 'none';
                const fd = new FormData();
                fd.append('action','myls_bb_test_dalle');
                fd.append('_wpnonce',$('myls_bb_nonce').value);
                try {
                    const res  = await fetch(ajaxurl,{method:'POST',body:fd});
                    const data = await res.json();
                    const lines = (data?.data?.log||[]).join('\n');
                    log.textContent = lines; log.style.display = lines ? '' : 'none';
                    if (data?.success) { status.textContent='✅ DALL-E working!'; status.style.color='#155724'; }
                    else { status.textContent='❌ '+(data?.data?.message||'Test failed'); status.style.color='#721c24'; }
                } catch(e) { status.textContent='❌ Network error: '+e.message; status.style.color='#721c24'; }
                finally { btn.disabled=false; btn.innerHTML='<i class="bi bi-plug"></i> Test DALL-E Connection'; }
            });

        })();
        </script>
        <?php
    }
];
