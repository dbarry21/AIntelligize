<?php
/**
 * Utilities Subtab: Elementor Page Builder
 * Path: admin/tabs/utilities/subtab-elementor.php
 * (Moved from AI tab in v7.8.19 — the render logic is identical; only location changed.)
 *
 * Generates native Elementor widget JSON written directly into _elementor_data.
 * Each AI section becomes a discrete Elementor flex container — fully editable
 * in the canvas without touching any code panel.
 *
 * v7.7.6 changes:
 * - "Generated Sections" + "Append Elementor Templates" replaced by a single
 *   drag-and-drop "Page Sections" panel.  Sections and templates share one
 *   ordered list so templates can sit anywhere in the page, not just the bottom.
 * - Templates: unlimited slots, add/remove, shows real template name.
 * - Feature Cards: Cols × Rows inputs replace the single card-width %; the
 *   backend generates Bootstrap col-class containers per card for stylesheet control.
 * - DALL-E: removed redundant separate "Featured Image" DALL-E call; hero image
 *   is optionally set as post thumbnail via an inline checkbox on the Hero row.
 *   "Feature Card Images" now clearly states it generates one image per card.
 *   "Integrate images" hidden checkbox removed — images always integrate when selected.
 * - Schema context: org/localbusiness data from Schema subtabs injected into AI prompt.
 */
if ( ! defined('ABSPATH') ) exit;

return [
    'id'    => 'elementor-builder',
    'label' => 'Elementor Builder',
    'icon'  => 'bi-layers',
    'order' => 71,
    'render'=> function () {

        /* ── Post types ──────────────────────────────────────────────────── */
        $pts = get_post_types( ['public' => true], 'objects' );
        unset( $pts['attachment'] );

        /* ── Business vars (schema → sb_settings → WP defaults) ─────────── */
        $sb      = get_option( 'myls_sb_settings', [] );
        $lb_locs = (array) get_option( 'myls_lb_locations', [] );
        $lb0     = is_array( $lb_locs[0] ?? null ) ? $lb_locs[0] : [];

        $biz_name = trim( (string) get_option( 'myls_org_name', '' ) );
        if ( $biz_name === '' ) $biz_name = $sb['business_name'] ?? get_bloginfo('name');

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
        if ( $biz_email === '' ) $biz_email = $lb0['email'] ?? $sb['email'] ?? get_bloginfo('admin_email');


        /* ── Saved prompt — auto-clear stale HTML-pipeline prompts ──────── */
        $saved_prompt = get_option( 'myls_elb_prompt_template', '' );
        $html_prompt_fingerprints = [ 'HTML RULES', 'Output raw HTML', 'elb-hero', 'Start directly with the first <section' ];
        $prompt_was_stale = false;
        foreach ( $html_prompt_fingerprints as $fp ) {
            if ( str_contains( $saved_prompt, $fp ) ) { $prompt_was_stale = true; break; }
        }
        if ( $prompt_was_stale ) { update_option( 'myls_elb_prompt_template', '' ); $saved_prompt = ''; }
        if ( empty( trim( $saved_prompt ) ) ) { $saved_prompt = myls_get_default_prompt('elementor-builder'); }

        /* ── Elementor detection ──────────────────────────────────────────── */
        $elementor_active  = defined('ELEMENTOR_VERSION') || class_exists('\\Elementor\\Plugin');
        $elementor_version = defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION
                           : ( class_exists('\\Elementor\\Plugin') ? 'detected' : '' );

        $nonce = wp_create_nonce( 'myls_elb_create' );

        // Price ranges — used to populate the service price range selector in the UI.
        // Serialised to JSON so JS can build the dropdown without a separate AJAX call.
        $elb_price_ranges = array_values( array_filter(
            (array) get_option( 'myls_service_price_ranges', [] ),
            fn( $r ) => is_array( $r ) && trim( (string) ( $r['label'] ?? '' ) ) !== ''
        ) );
        ?>

        <style>
        /* ── Drag-and-drop section list ─────────────────────────────────── */
        #myls_section_list { margin:0; padding:0; }
        .myls-section-row {
            display:flex; align-items:center; gap:8px;
            padding:7px 10px; margin-bottom:4px;
            background:#fff; border:1px solid #dce1e7; border-radius:6px;
            cursor:default; user-select:none;
            transition: opacity .2s, background .15s, border-color .15s;
        }
        .myls-section-row.myls-drag-over { border-color:#4f46e5; background:#f0efff; }
        .myls-section-row.myls-dragging  { opacity:.35; }
        .myls-section-row.myls-disabled  { opacity:.5; }
        .myls-section-row.myls-disabled .myls-section-label { text-decoration:line-through; color:#999; }
        .myls-drag-handle {
            cursor:grab; color:#bbb; font-size:16px; line-height:1;
            padding:0 2px; flex-shrink:0; letter-spacing:-.5px;
        }
        .myls-drag-handle:active { cursor:grabbing; }
        .myls-section-label { font-size:13px; font-weight:500; color:#1e293b; flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .myls-section-opts  { display:flex; align-items:center; gap:6px; flex-shrink:0; font-size:12px; }
        .myls-section-opts label { margin:0; color:#555; display:flex; align-items:center; gap:3px; white-space:nowrap; }
        .myls-section-opts input[type="number"] {
            width:46px; padding:2px 5px; font-size:12px;
            border:1px solid #ced4da; border-radius:4px; text-align:center;
        }
        .myls-tpl-select { font-size:12px !important; max-width:190px; flex-shrink:0; }
        .myls-tpl-remove {
            background:none; border:none; color:#dc3545; cursor:pointer;
            font-size:15px; padding:0 3px; line-height:1; flex-shrink:0;
        }
        .myls-tpl-remove:hover { color:#a00; }
        #myls_add_template_btn {
            font-size:12px; margin-top:6px; color:#4f46e5;
            background:none; border:1px dashed #9ca3af; border-radius:6px;
            padding:5px 12px; cursor:pointer; width:100%; text-align:center;
            transition: background .15s, border-color .15s;
        }
        #myls_add_template_btn:hover { background:#f0efff; border-color:#4f46e5; }

        /* ── DALL-E rows ─────────────────────────────────────────────────── */
        .myls-dalle-row {
            display:flex; align-items:center; gap:10px;
            padding:8px 12px; margin-bottom:6px;
            border:1px solid #e2e8f0; border-radius:6px; background:#f9fbff;
        }
        .myls-dalle-row .form-check { margin:0; flex-shrink:0; }
        .myls-dalle-label { font-size:13px; font-weight:500; flex:1; }
        .myls-dalle-label small { display:block; font-weight:400; color:#666; font-size:11px; margin-top:2px; }
        .myls-dalle-sub  { display:flex; align-items:center; gap:5px; font-size:12px; color:#444; flex-shrink:0; white-space:nowrap; }
        </style>

        <div style="display:grid; grid-template-columns:1fr 2fr; gap:20px;">

        <!-- ══════════════ LEFT: Page Setup ══════════════════════════════ -->
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

            <!-- Page Setup save/load -->
            <div class="d-flex gap-1 mb-3 align-items-center">
                <select id="myls_elb_setup_history" class="form-select form-select-sm flex-grow-1">
                    <option value="">— Load Saved Setup —</option>
                </select>
                <button type="button" class="button button-small" id="myls_elb_setup_load" title="Load selected setup">
                    <i class="bi bi-folder2-open"></i>
                </button>
                <button type="button" class="button button-small" id="myls_elb_setup_save" title="Save current setup as template">
                    <i class="bi bi-floppy"></i>
                </button>
                <button type="button" class="button button-small" id="myls_elb_setup_delete" title="Delete selected setup" style="color:#dc3545;">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
            <div id="myls_elb_setup_save_row" style="display:none;" class="d-flex gap-1 mb-2">
                <input type="text" id="myls_elb_setup_save_name" class="form-control form-control-sm" placeholder="Name this setup…" style="flex:1;">
                <button type="button" class="button button-primary button-small" id="myls_elb_setup_save_confirm"><i class="bi bi-check-lg"></i> Save</button>
                <button type="button" class="button button-small" id="myls_elb_setup_save_cancel"><i class="bi bi-x-lg"></i></button>
            </div>
            <div id="myls_elb_setup_msg" style="display:none;font-size:12px;padding:4px 8px;border-radius:4px;margin-bottom:8px;"></div>

            <label class="form-label fw-bold">Post Type</label>
            <select id="myls_elb_post_type" class="form-select mb-3">
                <?php foreach ( $pts as $pt => $obj ): ?>
                    <option value="<?php echo esc_attr($pt); ?>" <?php selected( $pt, 'page' ); ?>>
                        <?php echo esc_html( $obj->labels->singular_name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ( ! empty( $elb_price_ranges ) ): ?>
            <!-- Price Range selector — only shown when post type = service -->
            <div id="myls_elb_price_range_row" style="display:none;" class="mb-3">
                <label class="form-label fw-bold" for="myls_elb_price_range">
                    <i class="bi bi-tag"></i> Price Range
                    <span class="text-muted fw-normal" style="font-size:11px;">(optional)</span>
                </label>
                <select id="myls_elb_price_range" class="form-select form-select-sm">
                    <option value="">— No price range —</option>
                    <?php foreach ( $elb_price_ranges as $idx => $r ):
                        $cur    = strtoupper( $r['currency'] ?? 'USD' );
                        $symbol = isset( [ 'EUR' => '€', 'GBP' => '£', 'CAD' => 'CA$' ][ $cur ] ) ? [ 'EUR' => '€', 'GBP' => '£', 'CAD' => 'CA$' ][ $cur ] : '$';
                        $low_fmt  = ! empty( $r['low'] )  ? $symbol . number_format( (float) $r['low'],  0 ) : '';
                        $high_fmt = ! empty( $r['high'] ) ? $symbol . number_format( (float) $r['high'], 0 ) : '';
                        $range_display = esc_html( $r['label'] );
                        if ( $low_fmt || $high_fmt ) {
                            $range_display .= ' (' . implode( ' – ', array_filter( [ $low_fmt, $high_fmt ] ) ) . ')';
                        }
                    ?>
                        <option value="<?php echo esc_attr( $idx ); ?>">
                            <?php echo $range_display; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">
                    Assigns this price range from <a href="<?php echo admin_url('admin.php?page=aintelligize&tab=schema&subtab=service'); ?>" target="_blank">Service Schema → Price Ranges</a> to the generated post. The Pricing section will render this range automatically.
                </div>
            </div>
            <?php else: ?>
            <!-- No price ranges configured — show hint only for service post type -->
            <div id="myls_elb_price_range_row" style="display:none;" class="mb-3">
                <div class="form-text" style="color:#856404;background:#fff3cd;border:1px solid #ffc107;padding:6px 10px;border-radius:4px;">
                    <i class="bi bi-info-circle"></i>
                    No price ranges configured yet.
                    <a href="<?php echo admin_url('admin.php?page=aintelligize&tab=schema&subtab=service'); ?>" target="_blank">Add them in Service Schema → Price Ranges</a> to enable the Pricing section on generated pages.
                </div>
            </div>
            <?php endif; ?>

            <!-- Price ranges data for JS (index → label/low/high) -->
            <script>
            window.myls_elb_price_ranges = <?php echo wp_json_encode( array_values( $elb_price_ranges ) ); ?>;
            </script>
            </select>

            <label class="form-label fw-bold">Page Title <span class="text-danger">*</span></label>
            <input type="text" id="myls_elb_title" class="form-control mb-3" placeholder="e.g., Roof Replacement – Expert Service in Austin">

            <label class="form-label fw-bold">Description / Instructions</label>
            <div class="form-text mb-1" style="font-size:11px;">
                Supports tokens: <code>{{PAGE_TITLE}}</code> <code>{{YOAST_TITLE}}</code> <code>{{CITY}}</code>
                <code>{{BUSINESS_NAME}}</code> <code>{{PHONE}}</code> <code>{{EMAIL}}</code>
            </div>

            <!-- Description History -->
            <div class="d-flex gap-1 mb-2 align-items-end">
                <div class="flex-grow-1">
                    <select id="myls_elb_desc_history" class="form-select form-select-sm">
                        <option value="">— Saved Descriptions —</option>
                    </select>
                </div>
                <button type="button" class="button button-small" id="myls_elb_desc_load" title="Load selected description"><i class="bi bi-folder2-open"></i></button>
                <button type="button" class="button button-small" id="myls_elb_desc_save" title="Save current description"><i class="bi bi-floppy"></i></button>
                <button type="button" class="button button-small" id="myls_elb_desc_delete" title="Delete selected description" style="color:#dc3545;"><i class="bi bi-trash"></i></button>
            </div>
            <div id="myls_elb_save_row" style="display:none;" class="d-flex gap-1 mb-2">
                <input type="text" id="myls_elb_save_name" class="form-control form-control-sm" placeholder="Name this description…" style="flex:1;">
                <button type="button" class="button button-primary button-small" id="myls_elb_save_confirm"><i class="bi bi-check-lg"></i> Save</button>
                <button type="button" class="button button-small" id="myls_elb_save_cancel"><i class="bi bi-x-lg"></i></button>
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
                        <input class="form-check-input" type="checkbox" id="myls_elb_menu">
                        <label class="form-check-label" for="myls_elb_menu">Add to Menu</label>
                    </div>
                </div>
            </div>
            <div id="myls_elb_nav_info" class="form-text mb-3" style="display:none;"></div>

            <!-- Slug + Parent Page -->
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label fw-bold">Slug <span class="text-muted fw-normal" style="font-size:11px;">(optional)</span></label>
                    <input type="text" id="myls_elb_slug" class="form-control form-control-sm" placeholder="auto-generated from title">
                </div>
                <div class="col-6">
                    <label class="form-label fw-bold">Parent Page <span class="text-muted fw-normal" style="font-size:11px;">(optional)</span></label>
                    <select id="myls_elb_parent_page" class="form-select form-select-sm">
                        <option value="0">— No Parent —</option>
                    </select>
                </div>
            </div>

            <hr class="my-3">

            <div class="mb-3">
                <label class="form-label fw-bold mb-1" for="myls_elb_seo_keyword">
                    <i class="bi bi-search"></i> Yoast SEO Title / Focus Keyword
                </label>
                <input type="text" id="myls_elb_seo_keyword" class="form-control form-control-sm"
                       placeholder="e.g. Dog Training Tampa FL"
                       title="Used as Yoast focus keyword and to guide AI content generation.">
                <div class="form-text">Saved to Yoast focus keyword &amp; title. Also grounds Wikipedia/KG lookups.</div>
            </div>

            <!-- ══ PAGE SECTIONS — drag-and-drop unified list ═══════════ -->
            <div class="card mb-3" style="border:1px solid #ddd;background:#f9fbff;">
                <div class="card-body py-2 px-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <strong style="font-size:13px;"><i class="bi bi-list-task"></i> Page Sections</strong>
                        <span class="badge bg-secondary" style="font-size:10px;" id="myls_section_count_badge">6 sections</span>
                    </div>
                    <div class="small text-muted mb-2" style="font-size:11px;">
                        <i class="bi bi-grip-vertical"></i> Drag to reorder &nbsp;·&nbsp;
                        ☑ Include / exclude &nbsp;·&nbsp;
                        Templates can sit anywhere in the page
                    </div>

                    <!-- Hidden: serialised sections_order sent to backend -->
                    <input type="hidden" id="myls_sections_order" value="">

                    <!-- Rendered by JS renderSectionList() -->
                    <div id="myls_section_list"></div>

                    <button type="button" id="myls_add_template_btn">
                        <i class="bi bi-plus-circle"></i> Add Template
                    </button>
                    <div id="myls_tpl_loading_note" style="display:none;font-size:11px;color:#888;margin-top:4px;">
                        <i class="bi bi-hourglass-split"></i> Loading templates…
                    </div>
                </div>
            </div>

            <!-- Business Variables -->
            <h5 class="mb-2">Business Variables</h5>
            <p class="form-text mt-0 mb-2">Auto-filled from <a href="<?php echo admin_url('admin.php?page=myls-settings&tab=schema'); ?>" target="_blank">Schema settings</a>. Edit here for this session only.</p>
            <div class="row g-2 mb-2">
                <div class="col-6">
                    <label class="form-label small">Business Name</label>
                    <input type="text" id="myls_elb_biz_name" class="form-control form-control-sm" value="<?php echo esc_attr($biz_name); ?>">
                </div>
                <div class="col-6">
                    <label class="form-label small">City, State</label>
                    <input type="text" id="myls_elb_biz_city" class="form-control form-control-sm" value="<?php echo esc_attr($biz_city); ?>">
                </div>
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label small">Phone</label>
                    <input type="text" id="myls_elb_biz_phone" class="form-control form-control-sm" value="<?php echo esc_attr($biz_phone); ?>">
                </div>
                <div class="col-6">
                    <label class="form-label small">Email</label>
                    <input type="text" id="myls_elb_biz_email" class="form-control form-control-sm" value="<?php echo esc_attr($biz_email); ?>">
                </div>
            </div>
            <div class="row g-2 mt-1">
                <div class="col-12">
                    <label class="form-label small">Contact / CTA Button URL</label>
                    <div class="form-text p-2" style="background:#f0f4ff;border:1px solid #c7d2fe;border-radius:4px;font-size:11px;">
                        <?php
                        // Read from the canonical myls_contact_page_id option (set in AI Content → FAQ Builder).
                        $elb_contact_pid = (int) get_option('myls_contact_page_id', 0);
                        if ( $elb_contact_pid <= 0 ) {
                            $p = get_page_by_path('contact-us') ?: get_page_by_path('contact');
                            if ( $p ) $elb_contact_pid = (int) $p->ID;
                        }
                        $elb_resolved_url = $elb_contact_pid > 0 ? get_permalink($elb_contact_pid) : home_url('/contact-us/');
                        ?>
                        Resolved: <code><?php echo esc_html( esc_url_raw($elb_resolved_url) ); ?></code><br>
                        <a href="<?php echo esc_url( admin_url('admin.php?page=myls-ai&tab=faqs') ); ?>" target="_blank">
                            Change in AI Content → FAQ Builder → Contact Page
                        </a>
                    </div>
                </div>
            </div>

            <input type="hidden" id="myls_elb_nonce" value="<?php echo esc_attr($nonce); ?>">
        </div><!-- /LEFT -->

        <!-- ══════════════ RIGHT: Prompt + Results ════════════════════════ -->
        <div style="border:1px solid #000; padding:16px; border-radius:1em;">

            <!-- Info notice -->
            <div class="alert alert-info d-flex gap-2 align-items-start mb-3 py-2 px-3" style="font-size:13px;border-radius:8px;">
                <i class="bi bi-flask mt-1" style="flex-shrink:0;"></i>
                <div>
                    <strong>Elementor Builder</strong> — Generates native Elementor widgets:
                    <strong>Heading · Text Editor · Button · Icon Box · Shortcode</strong>.
                    FAQs are saved to custom fields and rendered via <code>[faq_schema_accordion]</code>.
                    All content is directly editable in the Elementor canvas.
                </div>
            </div>

            <h4 class="mb-2"><i class="bi bi-robot"></i> AI Content Generation</h4>
            <p class="mb-3" style="color:#555;">
                Tokens: <code>{{PAGE_TITLE}}</code> <code>{{YOAST_TITLE}}</code> <code>{{DESCRIPTION}}</code>
                <code>{{BUSINESS_NAME}}</code> <code>{{CITY}}</code>
                <code>{{PHONE}}</code> <code>{{EMAIL}}</code>
                <code>{{SITE_NAME}}</code> <code>{{SITE_URL}}</code> <code>{{POST_TYPE}}</code>
            </p>

            <!-- Prompt card -->
            <div class="card mb-3" style="border:1px solid #ddd;">
                <div class="card-body">
                    <?php if ( $prompt_was_stale ) : ?>
                    <div class="alert alert-warning d-flex gap-2 align-items-start mb-2 py-2 px-3" style="font-size:13px;border-radius:6px;">
                        <i class="bi bi-arrow-repeat mt-1" style="flex-shrink:0;"></i>
                        <div><strong>Prompt auto-updated.</strong> Your saved prompt contained old HTML instructions. It has been reset to the current JSON output default.</div>
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

            <!-- ══ DALL-E 3 Image Generation (redesigned v7.7.6) ═════════ -->
            <div class="card mb-3" style="border:1px solid #ddd;">
                <div class="card-header d-flex justify-content-between align-items-center" style="padding:8px 12px;">
                    <strong><i class="bi bi-image"></i> AI Images &mdash; DALL-E 3</strong>
                    <span class="badge bg-secondary">Optional</span>
                </div>
                <div class="card-body pb-2">

                    <!-- Hero / Banner Image row -->
                    <div class="myls-dalle-row">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="myls_elb_gen_hero" checked>
                        </div>
                        <div class="myls-dalle-label">
                            <i class="bi bi-card-image"></i> Hero / Banner Image
                            <small>Wide banner placed inside the hero section &mdash; 1792&times;1024</small>
                        </div>
                        <div class="myls-dalle-sub">
                            <input class="form-check-input" type="checkbox" id="myls_elb_set_featured" checked>
                            <label for="myls_elb_set_featured" style="cursor:pointer;">Set as post thumbnail</label>
                        </div>
                    </div>

                    <!-- Feature Card Images row -->
                    <div class="myls-dalle-row">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="myls_elb_gen_feature_cards">
                        </div>
                        <div class="myls-dalle-label">
                            <i class="bi bi-grid-3x2-gap"></i> Feature Card Images
                            <small>
                                One square image per card (1024&times;1024) &mdash;
                                count matches Cols &times; Rows set in Page Sections above
                            </small>
                        </div>
                    </div>

                    <!-- Image Style -->
                    <div class="mb-2 mt-1">
                        <label class="form-label small mb-1">Image Style</label>
                        <select id="myls_elb_img_style" class="form-select form-select-sm">
                            <option value="photo"            selected>Photo &mdash; real camera shot, natural light</option>
                            <option value="photorealistic">Photorealistic &mdash; stock photography style</option>
                            <option value="modern-flat">Modern Flat &mdash; clean illustration</option>
                            <option value="isometric">Isometric 3D &mdash; tech / icon style</option>
                            <option value="watercolor">Watercolor &mdash; artistic, warm tones</option>
                            <option value="gradient-abstract">Abstract Gradient &mdash; modern, vivid</option>
                        </select>
                    </div>

                    <!-- DALL-E Connection Test -->
                    <div class="d-flex align-items-center gap-2 mt-2 mb-1">
                        <button type="button" class="button" id="myls_elb_test_dalle_btn">
                            <i class="bi bi-plug"></i> Test DALL-E Connection
                        </button>
                        <span id="myls_elb_test_dalle_status" style="font-size:12px;"></span>
                    </div>
                    <pre id="myls_elb_test_dalle_log" style="display:none;margin-top:6px;background:#1e1e1e;color:#d4d4d4;padding:10px;border-radius:6px;font-size:11px;max-height:200px;overflow:auto;white-space:pre-wrap;"></pre>
                    <div class="form-text">Uses DALL-E 3 &middot; ~$0.04 / standard image &middot; Uploads to Media Library.</div>
                </div>
            </div>

            <!-- Action buttons -->
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
                    <div id="myls_elb_progress_bar" style="height:100%; width:0%; border-radius:50px; background:linear-gradient(90deg,#4f46e5,#7c3aed,#a855f7); background-size:200% 100%; transition:width 0.6s ease; animation:myls-shimmer 1.8s linear infinite;"></div>
                </div>
                <div id="myls_elb_progress_steps" style="display:flex; gap:0; margin-top:6px;">
                    <div class="myls-pstep" data-step="1" style="flex:1;text-align:center;font-size:10px;color:#aaa;padding:2px 0;border-top:2px solid #e0e0e0;transition:color .3s,border-color .3s;">🔑 API</div>
                    <div class="myls-pstep" data-step="2" style="flex:1;text-align:center;font-size:10px;color:#aaa;padding:2px 0;border-top:2px solid #e0e0e0;transition:color .3s,border-color .3s;">🎨 Images</div>
                    <div class="myls-pstep" data-step="3" style="flex:1;text-align:center;font-size:10px;color:#aaa;padding:2px 0;border-top:2px solid #e0e0e0;transition:color .3s,border-color .3s;">✍️ Content</div>
                    <div class="myls-pstep" data-step="4" style="flex:1;text-align:center;font-size:10px;color:#aaa;padding:2px 0;border-top:2px solid #e0e0e0;transition:color .3s,border-color .3s;">🏗️ Elementor</div>
                    <div class="myls-pstep" data-step="5" style="flex:1;text-align:center;font-size:10px;color:#aaa;padding:2px 0;border-top:2px solid #e0e0e0;transition:color .3s,border-color .3s;">✅ Done</div>
                </div>
            </div>
            <style>
            @keyframes myls-shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
            .myls-pstep.active { color:#4f46e5 !important; border-top-color:#7c3aed !important; font-weight:600; }
            .myls-pstep.done   { color:#16a34a !important; border-top-color:#16a34a !important; }
            </style>

            <hr>

            <!-- Results -->
            <div class="myls-results-header">
                <label class="form-label mb-0 fw-bold"><i class="bi bi-terminal"></i> Results</label>
                <div class="d-flex gap-2 align-items-center">
                    <span id="myls_elb_section_badge" style="display:none;" class="badge bg-primary"></span>
                    <button type="button" class="myls-btn-export-pdf" data-log-target="myls_elb_log"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
                    <span id="myls_elb_edit_link" style="display:none;">
                        <a id="myls_elb_edit_url" href="#" target="_blank" class="button button-secondary">
                            <i class="bi bi-pencil-square"></i> Edit in Elementor
                        </a>
                        <a id="myls_elb_preview_url" href="#" target="_blank" class="button button-secondary" style="margin-left:4px;">
                            <i class="bi bi-eye"></i> Preview Page
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

            <!-- Debug Inspector -->
            <div class="mt-3">
                <div class="d-flex align-items-center gap-2">
                    <strong style="font-size:13px;"><i class="bi bi-bug"></i> Debug Inspector</strong>
                    <input type="number" id="myls_elb_debug_post_id" class="form-control form-control-sm" style="width:110px;" placeholder="Post ID">
                    <button type="button" class="button" id="myls_elb_debug_btn">Inspect Elementor Data</button>
                </div>
                <pre id="myls_elb_debug_output" style="display:none;margin-top:8px;background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:6px;font-size:11px;max-height:300px;overflow:auto;white-space:pre-wrap;"></pre>
            </div>
        </div><!-- /RIGHT -->

        </div><!-- /grid -->

        <script>
        (function(){
            const $ = id => document.getElementById(id);
            let lastPostId   = 0;
            let descHistory  = [];
            let setupHistory = [];
            let loadedTemplates = []; // populated by AJAX once templates are fetched

            /* ── Section definitions (fixed sections, in default order) ─────── */
            const SECTION_DEFS = {
                hero:      { label: 'Hero Banner',    icon: '🖼️'  },
                tldr:      { label: 'TL;DR Block',    icon: '🟢'  },
                trust_bar: { label: 'Trust Bar',      icon: '🟡'  },
                intro:     { label: 'Service Intro',  icon: '📝'  },
                features:  { label: 'Feature Cards',  icon: '🃏',  hasCols: true },
                process:   { label: 'How It Works',   icon: '⚙️',  hasCols: true },
                pricing:   { label: 'Pricing',        icon: '💜'  },
                // faq removed — FAQs are generated post-creation via the FAQ Builder tab
                cta:       { label: 'CTA Block',      icon: '📣'  },
            };

            /* Default ordered list — cloned as live state */
            const DEFAULT_SECTIONS = [
                { id:'hero',      type:'section',  enabled:true },
                { id:'tldr',         type:'section',  enabled:true },
                { id:'intro',        type:'section',  enabled:true },
                { id:'trust_bar',    type:'section',  enabled:true },
                { id:'features',     type:'section',  enabled:true, cols:3, rows:1 },
                { id:'rich_content', type:'section',  enabled:true },
                { id:'process',      type:'section',  enabled:true, cols:2, rows:2 },
                { id:'pricing',   type:'section',  enabled:true },
                // faq omitted — FAQs are generated post-creation via the FAQ Builder tab
                { id:'cta',       type:'section',  enabled:true },
            ];

            /** Mutable live array — modified by drag, checkbox, add/remove */
            let sectionItems = JSON.parse(JSON.stringify(DEFAULT_SECTIONS));

            /** Generate a short unique client-side ID for template rows */
            function uid() { return 'tpl_' + Math.random().toString(36).slice(2,8); }

            /* ── Serialise list → hidden input ───────────────────────────────── */
            function serializeSections() {
                const list = $('myls_section_list');
                if (!list) return;
                const rows = list.querySelectorAll('.myls-section-row');
                const result = [];
                rows.forEach(row => {
                    const idx  = parseInt(row.dataset.sectionIdx);
                    const item = sectionItems[idx];
                    if (!item) return;

                    // Read enabled state from checkbox
                    const chk = row.querySelector('.myls-section-check');
                    item.enabled = chk ? chk.checked : true;

                    // Feature Card col/row inputs
                    if (item.type === 'section' && SECTION_DEFS[item.id]?.hasCols) {
                        const colEl = row.querySelector('.myls-cols-input');
                        const rowEl = row.querySelector('.myls-rows-input');
                        if (colEl) item.cols = Math.max(1, Math.min(6, parseInt(colEl.value)||3));
                        if (rowEl) item.rows = Math.max(1, Math.min(6, parseInt(rowEl.value)||1));
                    }

                    // Template selector
                    if (item.type === 'template') {
                        const sel = row.querySelector('.myls-tpl-select');
                        item.template_id = sel ? parseInt(sel.value)||0 : 0;
                    }

                    result.push(item);
                });
                sectionItems = result;

                const inp = $('myls_sections_order');
                if (inp) inp.value = JSON.stringify(sectionItems);

                // Update badge
                const enabled = sectionItems.filter(i => i.enabled).length;
                const badge   = $('myls_section_count_badge');
                if (badge) badge.textContent = enabled + ' of ' + sectionItems.length + ' enabled';
            }

            /* ── Render the drag-and-drop list ───────────────────────────────── */
            function renderSectionList() {
                const list = $('myls_section_list');
                if (!list) return;
                list.innerHTML = '';

                sectionItems.forEach((item, idx) => {
                    const row = document.createElement('div');
                    row.className  = 'myls-section-row' + (item.enabled ? '' : ' myls-disabled');
                    row.draggable  = true;
                    row.dataset.sectionIdx = idx;

                    /* Drag handle */
                    const handle = document.createElement('span');
                    handle.className   = 'myls-drag-handle';
                    handle.textContent = '⠿';
                    handle.title       = 'Drag to reorder';
                    row.appendChild(handle);

                    /* Enable/disable checkbox */
                    const chk = document.createElement('input');
                    chk.type      = 'checkbox';
                    chk.className = 'form-check-input myls-section-check';
                    chk.checked   = !!item.enabled;
                    chk.addEventListener('change', () => {
                        row.classList.toggle('myls-disabled', !chk.checked);
                        serializeSections();
                    });
                    row.appendChild(chk);

                    if (item.type === 'section') {
                        /* ── Generated section row ── */
                        const def = SECTION_DEFS[item.id] || {};

                        const lbl = document.createElement('span');
                        lbl.className   = 'myls-section-label';
                        lbl.textContent = (def.icon || '') + ' ' + (def.label || item.id);
                        row.appendChild(lbl);

                        if (def.hasCols) {
                            /* Feature Cards: inline cols × rows inputs */
                            const opts = document.createElement('span');
                            opts.className = 'myls-section-opts';
                            opts.innerHTML =
                                '<label>Cols<input type="number" class="myls-cols-input" value="'+(item.cols||3)+'" min="1" max="6" title="Grid columns"></label>' +
                                '<label>Rows<input type="number" class="myls-rows-input" value="'+(item.rows||1)+'" min="1" max="6" title="Grid rows"></label>';
                            opts.querySelectorAll('input').forEach(el => {
                                el.addEventListener('change', serializeSections);
                                el.addEventListener('input',  serializeSections);
                            });
                            row.appendChild(opts);
                        }

                    } else {
                        /* ── Template row ── */
                        const lbl = document.createElement('span');
                        lbl.className = 'myls-section-label';

                        // Resolve a display label for this template slot
                        const savedTpl = loadedTemplates.find(t => t.id == item.template_id);
                        lbl.textContent = '📄 ' + (savedTpl ? savedTpl.title : 'Template');
                        row.appendChild(lbl);

                        /* Template selector */
                        const sel = document.createElement('select');
                        sel.className = 'form-select form-select-sm myls-tpl-select';
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

                        /* Remove button */
                        const rem = document.createElement('button');
                        rem.type      = 'button';
                        rem.className = 'myls-tpl-remove';
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

                /* ── HTML5 drag-and-drop wiring ─────────────────────────────── */
                let dragSrc = null;

                list.querySelectorAll('.myls-section-row').forEach(row => {
                    row.addEventListener('dragstart', e => {
                        dragSrc = row;
                        row.classList.add('myls-dragging');
                        e.dataTransfer.effectAllowed = 'move';
                        e.dataTransfer.setData('text/plain', row.dataset.sectionIdx);
                    });
                    row.addEventListener('dragend', () => {
                        row.classList.remove('myls-dragging');
                        list.querySelectorAll('.myls-drag-over').forEach(r => r.classList.remove('myls-drag-over'));
                    });
                    row.addEventListener('dragover', e => {
                        e.preventDefault();
                        e.dataTransfer.dropEffect = 'move';
                        list.querySelectorAll('.myls-drag-over').forEach(r => r.classList.remove('myls-drag-over'));
                        if (row !== dragSrc) row.classList.add('myls-drag-over');
                    });
                    row.addEventListener('dragleave', () => {
                        row.classList.remove('myls-drag-over');
                    });
                    row.addEventListener('drop', e => {
                        e.preventDefault();
                        if (!dragSrc || dragSrc === row) return;
                        row.classList.remove('myls-drag-over');

                        // Serialise first so sectionItems is up-to-date before splice
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

            /* ── Add Template button ──────────────────────────────────────────── */
            $('myls_add_template_btn')?.addEventListener('click', () => {
                sectionItems.push({ id: uid(), type:'template', enabled:true, template_id:0 });
                renderSectionList();
            });

            /* ── Load Elementor templates via AJAX ────────────────────────────── */
            (async () => {
                $('myls_tpl_loading_note').style.display = '';
                const fd = new FormData();
                fd.append('action',   'myls_elb_get_templates');
                fd.append('_wpnonce', $('myls_elb_nonce').value);
                try {
                    const res  = await fetch(ajaxurl, { method:'POST', body:fd });
                    const data = await res.json();
                    if (data?.success && data.data.templates?.length) {
                        loadedTemplates = data.data.templates;
                    }
                } catch(e) { console.warn('Template load failed:', e); }
                finally {
                    $('myls_tpl_loading_note').style.display = 'none';
                    renderSectionList(); // Re-render with real template names
                }
            })();

            // Initial render so list appears immediately (templates fill in after AJAX)
            renderSectionList();

            /* ═══════════════════════════════════════════════════════════════════
             * Progress bar helpers
             * ═══════════════════════════════════════════════════════════════════ */
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
                bar.style.transition = 'none'; bar.style.width = '0%';
                pct.textContent = '0%'; lbl.textContent = 'Starting…';
                document.querySelectorAll('.myls-pstep').forEach(el => el.classList.remove('active','done'));
                wrap.style.display = '';
                requestAnimationFrame(() => { bar.style.transition = 'width 0.6s ease'; });

                let si = 0;
                const stages = withImages ? _progressStages : _progressStages.filter(s => s.step !== 2);
                const ivs    = withImages ? [800,8000,18000,8000,10000,4000,5000] : [800,8000,8000,4000,5000];

                function tick() {
                    if (si >= stages.length) return;
                    const stage = stages[si];
                    bar.style.width = stage.pct + '%'; pct.textContent = stage.pct + '%'; lbl.textContent = stage.label;
                    document.querySelectorAll('.myls-pstep').forEach(el => {
                        const s = parseInt(el.dataset.step);
                        if (s < stage.step)       el.classList.add('done'),   el.classList.remove('active');
                        else if (s === stage.step) el.classList.add('active'), el.classList.remove('done');
                        else                       el.classList.remove('active','done');
                    });
                    si++;
                    if (si < stages.length) _progressTimer = setTimeout(tick, ivs[si] || 6000);
                }
                _progressTimer = setTimeout(tick, ivs[0]);
            }

            function progressDone(success) {
                clearTimeout(_progressTimer);
                const bar = $('myls_elb_progress_bar');
                const lbl = $('myls_elb_progress_label');
                const pct = $('myls_elb_progress_pct');
                if (!bar) return;
                bar.style.width = '100%'; pct.textContent = '100%';
                lbl.textContent = success ? '✅ Page created!' : '❌ Something went wrong';
                if (success) { bar.style.background = 'linear-gradient(90deg,#16a34a,#22c55e)'; bar.style.animation = 'none'; }
                document.querySelectorAll('.myls-pstep').forEach(el => {
                    el.classList.remove('active');
                    if (success) el.classList.add('done');
                });
                if (success) {
                    setTimeout(() => {
                        const wrap = $('myls_elb_progress_wrap');
                        if (wrap) { wrap.style.transition = 'opacity 0.8s'; wrap.style.opacity = '0'; }
                        setTimeout(() => {
                            if (wrap) { wrap.style.display='none'; wrap.style.opacity='1'; wrap.style.transition=''; }
                            if (bar)  { bar.style.background='linear-gradient(90deg,#4f46e5,#7c3aed,#a855f7)'; bar.style.animation='myls-shimmer 1.8s linear infinite'; }
                        }, 900);
                    }, 3000);
                }
            }

            /* ═══════════════════════════════════════════════════════════════════
             * Description History
             * ═══════════════════════════════════════════════════════════════════ */
            function descMsg(text, ok=true) {
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
                    fd.append('action','myls_elb_list_descriptions');
                    fd.append('_wpnonce',$('myls_elb_nonce').value);
                    const res  = await fetch(ajaxurl, { method:'POST', body:fd });
                    const data = await res.json();
                    if (data?.success) { descHistory = data.data.history||[]; renderDescDropdown(); }
                } catch(e) { console.warn('Desc history load failed:', e); }
            }

            function renderDescDropdown() {
                const sel = $('myls_elb_desc_history');
                sel.innerHTML = '<option value="">— Saved Descriptions (' + descHistory.length + ') —</option>';
                descHistory.forEach(item => {
                    const opt = document.createElement('option');
                    opt.value = item.slug;
                    opt.textContent = item.name + (item.updated ? ' · ' + item.updated.substring(0,10) : '');
                    sel.appendChild(opt);
                });
            }

            $('myls_elb_desc_load')?.addEventListener('click', () => {
                const slug = $('myls_elb_desc_history').value;
                if (!slug) { descMsg('Select a saved description first.', false); return; }
                const item = descHistory.find(h => h.slug === slug);
                if (item) { $('myls_elb_description').value = item.description; descMsg('Loaded: '+item.name); }
            });

            $('myls_elb_desc_save')?.addEventListener('click', () => {
                const desc = $('myls_elb_description').value.trim();
                if (!desc) { descMsg('Write a description first.', false); return; }
                const nameEl = $('myls_elb_save_name');
                if (!nameEl.value) nameEl.value = $('myls_elb_title').value.trim() || '';
                $('myls_elb_save_row').style.display = '';
                nameEl.focus(); nameEl.select();
            });

            $('myls_elb_save_cancel')?.addEventListener('click', () => { $('myls_elb_save_row').style.display='none'; });

            $('myls_elb_save_confirm')?.addEventListener('click', async () => {
                const name = $('myls_elb_save_name').value.trim();
                const desc = $('myls_elb_description').value.trim();
                if (!name) { descMsg('Enter a name.', false); return; }
                if (!desc) { descMsg('Description is empty.', false); return; }
                const fd = new FormData();
                fd.append('action','myls_elb_save_description');
                fd.append('_wpnonce',$('myls_elb_nonce').value);
                fd.append('desc_name',name);
                fd.append('description',desc);
                try {
                    const res  = await fetch(ajaxurl,{method:'POST',body:fd});
                    const data = await res.json();
                    if (data?.success) {
                        descHistory = data.data.history||[];
                        renderDescDropdown();
                        const slug = descHistory.find(h=>h.name===name)?.slug;
                        if (slug) $('myls_elb_desc_history').value = slug;
                        $('myls_elb_save_row').style.display = 'none';
                        descMsg('Saved: '+name);
                    } else descMsg(data?.data?.message||'Save failed.', false);
                } catch(e) { descMsg('Network error: '+e.message, false); }
            });

            $('myls_elb_save_name')?.addEventListener('keydown', e => {
                if (e.key==='Enter')  { e.preventDefault(); $('myls_elb_save_confirm').click(); }
                if (e.key==='Escape') { $('myls_elb_save_row').style.display='none'; }
            });

            $('myls_elb_desc_delete')?.addEventListener('click', async () => {
                const slug = $('myls_elb_desc_history').value;
                if (!slug) { descMsg('Select a description to delete first.', false); return; }
                const item = descHistory.find(h=>h.slug===slug);
                const label = item?.name||slug;
                const msgEl = $('myls_elb_desc_msg');
                if (msgEl._pendingDelete===slug) {
                    msgEl._pendingDelete = null;
                    const fd = new FormData();
                    fd.append('action','myls_elb_delete_description');
                    fd.append('_wpnonce',$('myls_elb_nonce').value);
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

            /* ═══════════════════════════════════════════════════════════════════
             * Page Setup Snapshot — save/load full left-panel state
             * sections_order is included so drag-and-drop config is persisted.
             * ═══════════════════════════════════════════════════════════════════ */
            function setupMsg(text, ok=true) {
                const el = $('myls_elb_setup_msg');
                el.textContent = text;
                el.style.background = ok ? '#d4edda' : '#f8d7da';
                el.style.color      = ok ? '#155724' : '#721c24';
                el.style.display    = '';
                clearTimeout(el._t);
                el._t = setTimeout(() => { el.style.display='none'; }, 3000);
            }

            function getSetupSnapshot() {
                serializeSections(); // ensure sectionItems is up-to-date
                return {
                    post_type:         $('myls_elb_post_type')?.value      || 'page',
                    title:             $('myls_elb_title')?.value           || '',
                    description:       $('myls_elb_description')?.value     || '',
                    seo_keyword:       $('myls_elb_seo_keyword')?.value     || '',
                    status:            $('myls_elb_status')?.value          || 'draft',
                    add_to_menu:       $('myls_elb_menu')?.checked           ?? false,
                    page_slug:         ($('myls_elb_slug')?.value || '').trim(),
                    parent_page_id:    parseInt($('myls_elb_parent_page')?.value || '0'),
                    price_range_idx:   $('myls_elb_price_range')?.value     || '',
                    gen_hero:          $('myls_elb_gen_hero')?.checked       ?? true,
                    gen_feature_cards: $('myls_elb_gen_feature_cards')?.checked ?? false,
                    image_style:       $('myls_elb_img_style')?.value       || 'photo',
                    set_featured:      $('myls_elb_set_featured')?.checked   ?? true,
                    sections_order:    JSON.parse($('myls_sections_order')?.value || '[]'),
                };
            }

            function applySetupSnapshot(snap) {
                if (!snap) return;
                if ($('myls_elb_post_type')    && snap.post_type)           $('myls_elb_post_type').value    = snap.post_type;
                togglePriceRangeRow(); // show/hide price range row after restoring post type
                if ($('myls_elb_price_range')  && snap.price_range_idx != null) $('myls_elb_price_range').value = String(snap.price_range_idx);
                if ($('myls_elb_title')        && snap.title != null)        $('myls_elb_title').value        = snap.title;
                if ($('myls_elb_description')  && snap.description != null)  $('myls_elb_description').value  = snap.description;
                if ($('myls_elb_seo_keyword')  && snap.seo_keyword != null)  $('myls_elb_seo_keyword').value  = snap.seo_keyword;
                if ($('myls_elb_status')       && snap.status)               $('myls_elb_status').value       = snap.status;
                if ($('myls_elb_menu')         && snap.add_to_menu != null)   $('myls_elb_menu').checked       = !!snap.add_to_menu;
                if ($('myls_elb_slug')         && snap.page_slug != null)      $('myls_elb_slug').value         = snap.page_slug || '';
                // parent_page_id: set value; loadParentPages() must have already populated the select
                if ($('myls_elb_parent_page')  && snap.parent_page_id != null) $('myls_elb_parent_page').value  = String(snap.parent_page_id || 0);
                if ($('myls_elb_gen_hero')     && snap.gen_hero != null)      $('myls_elb_gen_hero').checked   = !!snap.gen_hero;
                if ($('myls_elb_gen_feature_cards') && snap.gen_feature_cards != null) $('myls_elb_gen_feature_cards').checked = !!snap.gen_feature_cards;
                if ($('myls_elb_img_style')    && snap.image_style)          $('myls_elb_img_style').value    = snap.image_style;
                if ($('myls_elb_set_featured') && snap.set_featured != null)  $('myls_elb_set_featured').checked = !!snap.set_featured;

                // Restore sections_order; fall back gracefully from old snapshots with include_* flags
                if (Array.isArray(snap.sections_order) && snap.sections_order.length) {
                    sectionItems = snap.sections_order;
                } else if (snap.include_hero !== undefined) {
                    // Backward compatibility: old snapshot without sections_order
                    sectionItems = JSON.parse(JSON.stringify(DEFAULT_SECTIONS));
                    const flagMap = { hero:'include_hero', intro:'include_intro', features:'include_features', process:'include_process', cta:'include_cta' };
                    sectionItems.forEach(item => {
                        if (item.type==='section' && flagMap[item.id] !== undefined)
                            item.enabled = !!snap[flagMap[item.id]];
                    });
                }
                renderSectionList();
                serializeSections();
                // Re-fetch parent pages for the restored post type — the initial
                // loadParentPages() call fires before snapshots are applied so it
                // uses the default post type; this corrects it after restore.
                loadParentPages();
            }

            function renderSetupDropdown() {
                const sel = $('myls_elb_setup_history');
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
                    fd.append('action','myls_elb_list_setups');
                    fd.append('_wpnonce',$('myls_elb_nonce').value);
                    const res  = await fetch(ajaxurl,{method:'POST',body:fd});
                    const data = await res.json();
                    if (data?.success) { setupHistory=data.data.history||[]; renderSetupDropdown(); }
                } catch(e) { console.warn('Setup history load failed:', e); }
            }

            $('myls_elb_setup_load')?.addEventListener('click', () => {
                const slug = $('myls_elb_setup_history').value;
                if (!slug) { setupMsg('Select a saved setup to load.', false); return; }
                const item = setupHistory.find(h=>h.slug===slug);
                if (item?.setup) { applySetupSnapshot(item.setup); setupMsg('Loaded: '+item.name); }
            });

            $('myls_elb_setup_save')?.addEventListener('click', () => {
                const nameEl = $('myls_elb_setup_save_name');
                if (!nameEl.value) nameEl.value = $('myls_elb_title').value.trim() || '';
                $('myls_elb_setup_save_row').style.display = '';
                nameEl.focus(); nameEl.select();
            });

            $('myls_elb_setup_save_cancel')?.addEventListener('click', () => {
                $('myls_elb_setup_save_row').style.display = 'none';
            });

            $('myls_elb_setup_save_confirm')?.addEventListener('click', async () => {
                const name = $('myls_elb_setup_save_name').value.trim();
                if (!name) { setupMsg('Enter a name for this setup.', false); return; }
                const snap = getSetupSnapshot();
                const fd = new FormData();
                fd.append('action','myls_elb_save_setup');
                fd.append('_wpnonce',$('myls_elb_nonce').value);
                fd.append('setup_name',name);
                fd.append('setup_data',JSON.stringify(snap));
                try {
                    const res  = await fetch(ajaxurl,{method:'POST',body:fd});
                    const data = await res.json();
                    if (data?.success) {
                        setupHistory=data.data.history||[]; renderSetupDropdown();
                        const slug = setupHistory.find(h=>h.name===name)?.slug;
                        if (slug) $('myls_elb_setup_history').value = slug;
                        $('myls_elb_setup_save_row').style.display='none';
                        setupMsg('Saved: '+name);
                    } else setupMsg(data?.data?.message||'Save failed.', false);
                } catch(e) { setupMsg('Network error: '+e.message, false); }
            });

            $('myls_elb_setup_save_name')?.addEventListener('keydown', e => {
                if (e.key==='Enter')  { e.preventDefault(); $('myls_elb_setup_save_confirm').click(); }
                if (e.key==='Escape') { $('myls_elb_setup_save_row').style.display='none'; }
            });

            $('myls_elb_setup_delete')?.addEventListener('click', async () => {
                const slug = $('myls_elb_setup_history').value;
                if (!slug) { setupMsg('Select a setup to delete first.', false); return; }
                const item = setupHistory.find(h=>h.slug===slug);
                const label = item?.name||slug;
                const msgEl = $('myls_elb_setup_msg');
                if (msgEl._pendingDelete===slug) {
                    msgEl._pendingDelete = null;
                    const fd = new FormData();
                    fd.append('action','myls_elb_delete_setup');
                    fd.append('_wpnonce',$('myls_elb_nonce').value);
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

            /* ── Parent Page loader — refreshes when post type changes ──────── */
            async function loadParentPages() {
                const pt  = $('myls_elb_post_type')?.value || 'page';
                const sel = $('myls_elb_parent_page');
                if (!sel) return;
                const current = sel.value;
                sel.innerHTML = '<option value="0">\u2014 No Parent \u2014</option>';
                try {
                    const fd = new FormData();
                    fd.append('action',    'myls_elb_get_parent_pages');
                    fd.append('_wpnonce',  $('myls_elb_nonce').value);
                    fd.append('post_type', pt);
                    const res  = await fetch(ajaxurl, {method:'POST', body:fd});
                    const data = await res.json();
                    console.log('[AIntelligize] parent pages →', pt, data?.data);
                    if (data?.success && Array.isArray(data.data.pages)) {
                        data.data.pages.forEach(p => {
                            const opt = document.createElement('option');
                            opt.value       = p.id;
                            opt.textContent = p.title;
                            if (String(p.id) === String(current)) opt.selected = true;
                            sel.appendChild(opt);
                        });
                    }
                } catch(e) { console.warn('[AIntelligize] loadParentPages error', e); }
            }
            loadParentPages();
            $('myls_elb_post_type')?.addEventListener('change', loadParentPages);

            /* ── Show/hide price range selector for service post type ─────── */
            function togglePriceRangeRow() {
                const pt  = $('myls_elb_post_type')?.value || '';
                const row = $('myls_elb_price_range_row');
                if ( row ) row.style.display = pt === 'service' ? '' : 'none';
            }
            togglePriceRangeRow(); // run on page load
            $('myls_elb_post_type')?.addEventListener('change', togglePriceRangeRow);

            /* ── Nav post detection (block theme info) ───────────────────────── */
            (async function() {
                try {
                    const fd = new FormData();
                    fd.append('action','myls_elb_get_nav_posts');
                    fd.append('_wpnonce',$('myls_elb_nonce').value);
                    const res  = await fetch(ajaxurl,{method:'POST',body:fd});
                    const data = await res.json();
                    if (data?.success && data.data.is_block_theme) {
                        const info   = $('myls_elb_nav_info');
                        const active = data.data.nav_posts?.find(n=>n.active);
                        info.innerHTML = '<i class="bi bi-info-circle"></i> Block theme · Active nav: <strong>'
                            + (active ? active.title+' (#'+active.id+')' : '(none)') + '</strong>';
                        info.style.display = '';
                    }
                } catch(e) { /* silent */ }
            })();

            /* ── Prompt handlers ─────────────────────────────────────────────── */
            const defaultPrompt = <?php echo wp_json_encode( myls_get_default_prompt('elementor-builder') ); ?>;
            const promptEl = $('myls_elb_prompt');
            if (promptEl && !promptEl.value.trim()) promptEl.value = defaultPrompt;

            $('myls_elb_save_prompt')?.addEventListener('click', async () => {
                const fd = new FormData();
                fd.append('action','myls_elb_save_prompt');
                fd.append('prompt_template',promptEl.value);
                fd.append('_wpnonce',$('myls_elb_nonce').value);
                try {
                    const res  = await fetch(ajaxurl,{method:'POST',body:fd});
                    const data = await res.json();
                    descMsg(data?.success ? '✓ Prompt template saved.' : (data?.data?.message||'Error.'), !!data?.success);
                } catch(e) { descMsg('Error: '+e.message, false); }
            });

            $('myls_elb_reset_prompt')?.addEventListener('click', async () => {
                const fd = new FormData();
                fd.append('action','myls_elb_save_prompt');
                fd.append('prompt_template',''); // empty = use file default
                fd.append('_wpnonce',$('myls_elb_nonce').value);
                try {
                    const res  = await fetch(ajaxurl,{method:'POST',body:fd});
                    const data = await res.json();
                    if (data?.success) { descMsg('✓ Reset — reloading…', true); setTimeout(()=>location.reload(),800); }
                    else descMsg(data?.data?.message||'Reset failed.', false);
                } catch(e) { descMsg('Error: '+e.message, false); }
            });

            /* ── Image helper ────────────────────────────────────────────────── */
            function wantsImages() {
                return $('myls_elb_gen_hero').checked || $('myls_elb_gen_feature_cards').checked;
            }

            /* ── Create Page ─────────────────────────────────────────────────── */
            $('myls_elb_create_btn')?.addEventListener('click', async () => {
                const title = $('myls_elb_title').value.trim();
                if (!title) { alert('Please enter a Page Title.'); $('myls_elb_title').focus(); return; }

                serializeSections(); // ensure hidden input is current

                const logEl    = $('myls_elb_log');
                const btn      = $('myls_elb_create_btn');
                const editLink = $('myls_elb_edit_link');
                const imgBtn   = $('myls_elb_gen_images_btn');
                const badge    = $('myls_elb_section_badge');
                const hasImages = wantsImages();

                editLink.style.display  = 'none';
                imgBtn.style.display    = 'none';
                badge.style.display     = 'none';
                $('myls_elb_img_preview').style.display = 'none';
                btn.disabled = true;
                progressStart(hasImages);

                if (hasImages) {
                    const parts = [];
                    if ($('myls_elb_gen_hero').checked) parts.push('hero image (1792×1024)');
                    if ($('myls_elb_gen_feature_cards').checked) parts.push('feature card images (1 per card, 1024×1024)');
                    logEl.textContent = '🎨 Generating: ' + parts.join(' + ') + '…\n✏️ AI will build Elementor sections with images integrated.';
                    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Generating Images + Content…';
                } else {
                    logEl.textContent = '⏳ Generating content with AI… this may take 15–30 seconds.';
                    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Generating…';
                }

                // Flush any typed-but-not-yet-blurred cols/rows values into the
                // hidden input before we read it. Without this, typing "4" and
                // immediately clicking Generate skips the 'change' event and sends
                // the stale default instead.
                serializeSections();

                const fd = new FormData();
                fd.append('action',            'myls_elb_create_page');
                fd.append('_wpnonce',          $('myls_elb_nonce').value);
                fd.append('page_title',        title);
                fd.append('post_type',         $('myls_elb_post_type').value);
                fd.append('page_status',       $('myls_elb_status').value);
                fd.append('page_description',  $('myls_elb_description').value);
                fd.append('prompt_template',   promptEl.value);
                fd.append('add_to_menu',       $('myls_elb_menu').checked ? '1' : '0');
                fd.append('page_slug',         ($('myls_elb_slug')?.value||'').trim());
                fd.append('parent_page_id',    $('myls_elb_parent_page')?.value || '0');
                fd.append('seo_keyword',       ($('myls_elb_seo_keyword').value||'').trim());
                fd.append('sections_order',    $('myls_sections_order').value);
                fd.append('image_style',       $('myls_elb_img_style').value);
                // Price range: only sent when post type is 'service' and a range is selected
                const priceRangeEl = $('myls_elb_price_range');
                if ( priceRangeEl && priceRangeEl.value !== '' ) {
                    fd.append('price_range_idx', priceRangeEl.value);
                }
                fd.append('gen_hero',          $('myls_elb_gen_hero').checked ? '1' : '0');
                fd.append('gen_feature_cards', $('myls_elb_gen_feature_cards').checked ? '1' : '0');
                fd.append('set_featured',      $('myls_elb_set_featured').checked ? '1' : '0');
                fd.append('integrate_images',  hasImages ? '1' : '0');
                fd.append('contact_url',       '<?php echo esc_js( esc_url_raw($elb_resolved_url) ); ?>');

                try {
                    // Phase 1: Build page content (no images yet)
                    const controller = new AbortController();
                    const fetchTimeout = setTimeout(() => controller.abort(), 300000); // 5 min safety
                    const res  = await fetch(ajaxurl, { method:'POST', body:fd, signal: controller.signal });
                    clearTimeout(fetchTimeout);
                    const data = await res.json();
                    if (data?.success) {
                        lastPostId = data.data.post_id || 0;
                        logEl.textContent = data.data.log_text || data.data.message || 'Done.';
                        if (data.data.section_count) {
                            badge.textContent = data.data.section_count + ' section' + (data.data.section_count!==1?'s':'') + ' (native widgets)';
                            badge.style.display = '';
                        }
                        const dbgEl = $('myls_elb_debug_post_id');
                        if (dbgEl && lastPostId) dbgEl.value = lastPostId;
                        if (data.data.edit_url) {
                            $('myls_elb_edit_url').href = data.data.edit_url;
                            if (data.data.view_url) {
                                $('myls_elb_preview_url').href = data.data.view_url;
                            }
                            editLink.style.display = '';
                        }

                        // Phase 2: Generate images one at a time (deferred)
                        const pending = data.data.pending_images || [];
                        if (pending.length > 0) {
                            const nonce      = $('myls_elb_nonce').value;
                            const imgStyle   = data.data.image_style || 'photo';
                            const setFeat    = data.data.set_featured ? '1' : '0';
                            const imgGrid    = $('myls_elb_img_grid');
                            const imgPreview = $('myls_elb_img_preview');
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
                                    imgFd.append('action',       'myls_elb_generate_single_image');
                                    imgFd.append('_wpnonce',     nonce);
                                    imgFd.append('post_id',      lastPostId);
                                    imgFd.append('image_type',   img.type);
                                    imgFd.append('image_index',  img.index);
                                    imgFd.append('subject',      img.subject || '');
                                    imgFd.append('size',         img.size || '1024x1024');
                                    imgFd.append('image_style',  imgStyle);
                                    imgFd.append('set_featured', setFeat);
                                    imgFd.append('page_title',   title);
                                    imgFd.append('description',  $('myls_elb_description').value);

                                    const imgCtrl    = new AbortController();
                                    const imgTimeout = setTimeout(() => imgCtrl.abort(), 180000); // 3 min per image
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
                    btn.innerHTML = '<i class="bi bi-lightning-charge"></i> Create Elementor Page with AI';
                }
            });

            /* ── Debug Inspector ─────────────────────────────────────────────── */
            $('myls_elb_debug_btn')?.addEventListener('click', async () => {
                const postIdEl = $('myls_elb_debug_post_id');
                const outputEl = $('myls_elb_debug_output');
                const post_id  = parseInt(postIdEl.value)||lastPostId;
                if (!post_id) { alert('Enter a Post ID or create a page first.'); return; }
                outputEl.style.display = '';
                outputEl.textContent   = 'Fetching Elementor data for Post #'+post_id+'…';
                const fd = new FormData();
                fd.append('action','myls_elb_debug_post');
                fd.append('_wpnonce',$('myls_elb_nonce').value);
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
                            '','=== ELEMENTOR META ===',
                            '_elementor_edit_mode:      '+d.edit_mode,
                            '_elementor_version:        '+d.elementor_version,
                            '_elementor_template_type:  '+d.template_type,
                            '_elementor_css cached:     '+d.css_cache_exists,
                            '','=== JSON DATA ===',
                            'JSON stored:    '+d.json_stored,
                            'JSON valid:     '+d.json_valid,
                            'JSON length:    '+d.json_length+' chars',
                            'Containers:     '+d.container_count,
                            'Widgets:        '+d.widget_count,
                            '','=== RAW JSON PREVIEW (first 500 chars) ===',
                            d.json_preview || '(empty)',
                        ].join('\n');
                    } else { outputEl.textContent = '❌ '+(data?.data?.message||'Error'); }
                } catch(e) { outputEl.textContent = '❌ Network error: '+e.message; }
            });

            /* ── DALL-E Connection Test ───────────────────────────────────────── */
            $('myls_elb_test_dalle_btn')?.addEventListener('click', async () => {
                const btn    = $('myls_elb_test_dalle_btn');
                const status = $('myls_elb_test_dalle_status');
                const log    = $('myls_elb_test_dalle_log');
                btn.disabled = true;
                btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Testing…';
                status.textContent = ''; log.style.display = 'none';
                const fd = new FormData();
                fd.append('action','myls_elb_test_dalle');
                fd.append('_wpnonce',$('myls_elb_nonce').value);
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
