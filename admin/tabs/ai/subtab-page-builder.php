<?php
/**
 * AI Subtab: Page Builder
 * Path: admin/tabs/ai/subtab-page-builder.php
 *
 * Create any page or post type with AI-generated content.
 */
if ( ! defined('ABSPATH') ) exit;

return [
    'id'    => 'page-builder',
    'label' => 'Page Builder',
    'icon'  => 'bi-file-earmark-plus',
    'order' => 70,
    'render'=> function () {

        // All public + custom post types
        $pts = get_post_types( ['public' => true], 'objects' );
        unset($pts['attachment']);

        // Business vars from Site Builder settings (or fallback)
        $sb = get_option('myls_sb_settings', []);
        $biz_name = $sb['business_name'] ?? get_bloginfo('name');
        $biz_city = $sb['city'] ?? '';
        $biz_phone = $sb['phone'] ?? '';
        $biz_email = $sb['email'] ?? get_bloginfo('admin_email');

        // Default prompt
        $saved_prompt = get_option('myls_pb_prompt_template', '');

        $nonce = wp_create_nonce('myls_pb_create');
        ?>

        <div style="display:grid; grid-template-columns:1fr 2fr; gap:20px;">

            <!-- ═══════════ LEFT: Page Setup ═══════════ -->
            <div style="border:1px solid #000; padding:16px; border-radius:1em;">
                <h4 class="mb-3"><i class="bi bi-file-earmark-plus"></i> Page Setup</h4>

                <label class="form-label fw-bold">Post Type</label>
                <select id="myls_pb_post_type" class="form-select mb-3">
                    <?php foreach ($pts as $pt => $obj): ?>
                        <option value="<?php echo esc_attr($pt); ?>" <?php selected($pt, 'page'); ?>>
                            <?php echo esc_html($obj->labels->singular_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label class="form-label fw-bold">Page Title <span class="text-danger">*</span></label>
                <input type="text" id="myls_pb_title" class="form-control mb-3"
                       placeholder="e.g., Intelligize Chat – AI-Powered Chat Plugin">

                <label class="form-label fw-bold">Description / Instructions</label>

                <!-- Description History Controls -->
                <div class="d-flex gap-1 mb-2 align-items-end">
                    <div class="flex-grow-1">
                        <select id="myls_pb_desc_history" class="form-select form-select-sm">
                            <option value="">— Saved Descriptions —</option>
                        </select>
                    </div>
                    <button type="button" class="button button-small" id="myls_pb_desc_load" title="Load selected description">
                        <i class="bi bi-folder2-open"></i>
                    </button>
                    <button type="button" class="button button-small" id="myls_pb_desc_save" title="Save current description">
                        <i class="bi bi-floppy"></i>
                    </button>
                    <button type="button" class="button button-small" id="myls_pb_desc_delete" title="Delete selected description" style="color:#dc3545;">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>

                <textarea id="myls_pb_description" class="form-control mb-1" rows="8"
                          placeholder="Describe what this page is about. The more detail you give, the better the AI output.&#10;&#10;Example:&#10;Intelligize Chat is a WordPress plugin that adds an AI-powered chat widget. Highlight features like knowledge base training, customizable appearance, lead capture, and easy setup. Target audience: small business owners. Include a CTA to download."></textarea>
                <div class="form-text mb-3">Key features, target audience, tone, page structure — whatever helps the AI.</div>

                <hr class="my-3">

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold">Status</label>
                        <select id="myls_pb_status" class="form-select">
                            <option value="draft" selected>Draft</option>
                            <option value="publish">Publish</option>
                        </select>
                    </div>
                    <div class="col-6 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="myls_pb_menu" checked>
                            <label class="form-check-label" for="myls_pb_menu">Add to Menu</label>
                        </div>
                    </div>
                </div>
                <div id="myls_pb_nav_info" class="form-text mb-3" style="display:none;"></div>

                <hr class="my-3">

                <h5 class="mb-2">Business Variables</h5>
                <p class="form-text mt-0 mb-2">Auto-filled from Site Builder settings. Edit here for this session only.</p>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label small">Business Name</label>
                        <input type="text" id="myls_pb_biz_name" class="form-control form-control-sm"
                               value="<?php echo esc_attr($biz_name); ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small">City, State</label>
                        <input type="text" id="myls_pb_biz_city" class="form-control form-control-sm"
                               value="<?php echo esc_attr($biz_city); ?>">
                    </div>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small">Phone</label>
                        <input type="text" id="myls_pb_biz_phone" class="form-control form-control-sm"
                               value="<?php echo esc_attr($biz_phone); ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Email</label>
                        <input type="text" id="myls_pb_biz_email" class="form-control form-control-sm"
                               value="<?php echo esc_attr($biz_email); ?>">
                    </div>
                </div>

                <input type="hidden" id="myls_pb_nonce" value="<?php echo esc_attr($nonce); ?>">
            </div>

            <!-- ═══════════ RIGHT: Prompt + Results ═══════════ -->
            <div style="border:1px solid #000; padding:16px; border-radius:1em;">
                <h4 class="mb-2"><i class="bi bi-robot"></i> AI Content Generation</h4>
                <p class="mb-3" style="color:#555;">
                    Tokens: <code>{{PAGE_TITLE}}</code> <code>{{DESCRIPTION}}</code>
                    <code>{{BUSINESS_NAME}}</code> <code>{{CITY}}</code>
                    <code>{{PHONE}}</code> <code>{{EMAIL}}</code>
                    <code>{{SITE_NAME}}</code> <code>{{SITE_URL}}</code> <code>{{POST_TYPE}}</code>
                </p>

                <div class="card mb-3" style="border:1px solid #ddd;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong>AI Prompt Template</strong>
                            <div class="d-flex gap-1">
                                <button type="button" class="button button-primary" id="myls_pb_save_prompt">Save to DB</button>
                            </div>
                        </div>
                        <?php myls_prompt_toolbar('page-builder', 'myls_pb_prompt'); ?>
                        <textarea id="myls_pb_prompt" class="form-control font-monospace" rows="12"
                                  style="font-size:12px;"><?php echo esc_textarea($saved_prompt); ?></textarea>
                        <small style="color:#666;">Saved to: <code>myls_pb_prompt_template</code> · Leave blank to use the built-in default.</small>
                    </div>
                </div>

                <!-- ── AI Images ─────────────────────────── -->
                <div class="card mb-3" style="border:1px solid #ddd;">
                    <div class="card-header d-flex justify-content-between align-items-center" style="padding:8px 12px;">
                        <strong><i class="bi bi-image"></i> AI Images (DALL-E 3)</strong>
                        <span class="badge bg-secondary">Optional</span>
                    </div>
                    <div class="card-body">
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="myls_pb_gen_hero" checked>
                                    <label class="form-check-label" for="myls_pb_gen_hero">
                                        <i class="bi bi-card-image"></i> Hero / Banner Image
                                    </label>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="myls_pb_gen_feature">
                                    <label class="form-check-label" for="myls_pb_gen_feature">
                                        <i class="bi bi-grid-3x2-gap"></i> Feature Images
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small">Feature image count</label>
                                <select id="myls_pb_feature_count" class="form-select form-select-sm">
                                    <option value="0">0</option>
                                    <option value="3" selected>3</option>
                                    <option value="4">4</option>
                                    <option value="6">6</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small">Image Style</label>
                                <select id="myls_pb_img_style" class="form-select form-select-sm">
                                    <option value="modern-flat">Modern Flat</option>
                                    <option value="photorealistic">Photorealistic</option>
                                    <option value="isometric">Isometric 3D</option>
                                    <option value="watercolor">Watercolor</option>
                                    <option value="gradient-abstract">Abstract Gradient</option>
                                </select>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="myls_pb_set_featured" checked>
                                    <label class="form-check-label small" for="myls_pb_set_featured">Set hero as Featured Image</label>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="myls_pb_insert_hero" checked>
                                    <label class="form-check-label small" for="myls_pb_insert_hero">Insert hero into page</label>
                                </div>
                            </div>
                        </div>
                        <div class="form-text mt-1">Uses DALL-E 3 · ~$0.04/standard image · Images upload to your Media Library.</div>
                        <hr class="my-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="myls_pb_integrate_images" checked>
                            <label class="form-check-label fw-bold" for="myls_pb_integrate_images">
                                <i class="bi bi-layout-text-window-reverse"></i> Integrate images into page content
                            </label>
                        </div>
                        <div class="form-text mt-1">When checked, images are generated FIRST and the AI weaves them into the page HTML. When unchecked, use the separate "Generate Images" button after page creation.</div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="button button-primary button-hero" id="myls_pb_create_btn">
                        <i class="bi bi-lightning-charge"></i> Create Page with AI
                    </button>
                    <button type="button" class="button button-secondary" id="myls_pb_gen_images_btn" style="display:none;">
                        <i class="bi bi-images"></i> Generate Images
                    </button>
                </div>

                <hr>

                <div class="myls-results-header">
                    <label class="form-label mb-0 fw-bold"><i class="bi bi-terminal"></i> Results</label>
                    <div class="d-flex gap-2 align-items-center">
                        <button type="button" class="myls-btn-export-pdf" data-log-target="myls_pb_log"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
                        <span id="myls_pb_edit_link" style="display:none;">
                            <a id="myls_pb_edit_url" href="#" target="_blank" class="button button-secondary">
                                <i class="bi bi-pencil-square"></i> Edit Page
                            </a>
                        </span>
                    </div>
                </div>
                <pre id="myls_pb_log" class="myls-results-terminal">Ready.</pre>

                <!-- Image preview area -->
                <div id="myls_pb_img_preview" style="display:none;" class="mt-3">
                    <label class="form-label fw-bold"><i class="bi bi-images"></i> Generated Images</label>
                    <div id="myls_pb_img_grid" class="d-flex flex-wrap gap-2"></div>
                </div>
            </div>

        </div>

        <script>
        (function(){
            const $ = (id) => document.getElementById(id);
            let lastPostId = 0;
            let descHistory = []; // cached history

            // ── Description History ─────────────────────────────────────
            async function loadDescHistory() {
                try {
                    const fd = new FormData();
                    fd.append('action', 'myls_pb_list_descriptions');
                    fd.append('_wpnonce', $('myls_pb_nonce').value);
                    const res = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data?.success) {
                        descHistory = data.data.history || [];
                        renderDescDropdown();
                    }
                } catch(e) { /* silent */ }
            }

            function renderDescDropdown() {
                const sel = $('myls_pb_desc_history');
                sel.innerHTML = '<option value="">— Saved Descriptions (' + descHistory.length + ') —</option>';
                descHistory.forEach(item => {
                    const opt = document.createElement('option');
                    opt.value = item.slug;
                    opt.textContent = item.name + (item.updated ? ' · ' + item.updated.substring(0,10) : '');
                    sel.appendChild(opt);
                });
            }

            // Load selected description
            $('myls_pb_desc_load')?.addEventListener('click', () => {
                const slug = $('myls_pb_desc_history').value;
                if (!slug) { alert('Select a saved description first.'); return; }
                const item = descHistory.find(h => h.slug === slug);
                if (item) {
                    $('myls_pb_description').value = item.description;
                }
            });

            // Save current description
            $('myls_pb_desc_save')?.addEventListener('click', async () => {
                const desc = $('myls_pb_description').value.trim();
                if (!desc) { alert('Write a description first.'); return; }

                // Use page title as default name, or prompt
                let name = $('myls_pb_title').value.trim() || '';
                name = prompt('Save description as:', name || 'My Description');
                if (!name) return;

                const fd = new FormData();
                fd.append('action', 'myls_pb_save_description');
                fd.append('_wpnonce', $('myls_pb_nonce').value);
                fd.append('desc_name', name);
                fd.append('description', desc);

                try {
                    const res = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data?.success) {
                        descHistory = data.data.history || [];
                        renderDescDropdown();
                        // Auto-select the one we just saved
                        const slug = descHistory.find(h => h.name === name)?.slug;
                        if (slug) $('myls_pb_desc_history').value = slug;
                    }
                    alert(data?.success ? data.data.message : (data?.data?.message || 'Error'));
                } catch(e) { alert('Error: ' + e.message); }
            });

            // Delete selected description
            $('myls_pb_desc_delete')?.addEventListener('click', async () => {
                const slug = $('myls_pb_desc_history').value;
                if (!slug) { alert('Select a description to delete.'); return; }
                const item = descHistory.find(h => h.slug === slug);
                if (!confirm('Delete "' + (item?.name || slug) + '"?')) return;

                const fd = new FormData();
                fd.append('action', 'myls_pb_delete_description');
                fd.append('_wpnonce', $('myls_pb_nonce').value);
                fd.append('desc_slug', slug);

                try {
                    const res = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data?.success) {
                        descHistory = data.data.history || [];
                        renderDescDropdown();
                    }
                } catch(e) { alert('Error: ' + e.message); }
            });

            // Load history on init
            loadDescHistory();

            // Load nav info (detect block theme and active navigation)
            (async function() {
                try {
                    const fd = new FormData();
                    fd.append('action', 'myls_pb_get_nav_posts');
                    fd.append('_wpnonce', $('myls_pb_nonce').value);
                    const res = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data?.success) {
                        const info = $('myls_pb_nav_info');
                        if (data.data.is_block_theme) {
                            const active = data.data.nav_posts?.find(n => n.active);
                            if (active) {
                                info.innerHTML = '<i class="bi bi-info-circle"></i> Block theme detected. Active navigation: <strong>' + active.title + '</strong> (#' + active.id + '). Manage in <a href="' + ajaxurl.replace('admin-ajax.php', 'site-editor.php?path=%2Fnavigation') + '">Appearance → Editor → Navigation</a>';
                            } else if (data.data.nav_posts?.length) {
                                info.innerHTML = '<i class="bi bi-info-circle"></i> Block theme detected. ' + data.data.nav_posts.length + ' navigation menu(s) found.';
                            } else {
                                info.innerHTML = '<i class="bi bi-info-circle"></i> Block theme detected. Header likely uses Page List (auto-shows all published pages).';
                            }
                            info.style.display = '';
                        }
                    }
                } catch(e) { /* silent */ }
            })();

            const defaultPrompt = <?php echo wp_json_encode( myls_get_default_prompt('page-builder') ); ?>;

            // Init prompt textarea
            const promptEl = $('myls_pb_prompt');
            if (promptEl && !promptEl.value.trim()) {
                promptEl.value = defaultPrompt;
            }

            // Save prompt template
            $('myls_pb_save_prompt')?.addEventListener('click', async () => {
                const fd = new FormData();
                fd.append('action', 'myls_pb_save_prompt');
                fd.append('prompt_template', promptEl.value);
                fd.append('_wpnonce', $('myls_pb_nonce').value);
                try {
                    const res = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();
                    alert(data?.success ? 'Prompt template saved.' : (data?.data?.message || 'Error saving.'));
                } catch(e) { alert('Error: ' + e.message); }
            });

            // Helper: should we generate images?
            function wantsImages() {
                return $('myls_pb_gen_hero').checked || $('myls_pb_gen_feature').checked;
            }

            // ── Create Page ─────────────────────────────────────────────
            $('myls_pb_create_btn')?.addEventListener('click', async () => {
                const title = $('myls_pb_title').value.trim();
                if (!title) { alert('Please enter a Page Title.'); $('myls_pb_title').focus(); return; }

                const logEl = $('myls_pb_log');
                const btn   = $('myls_pb_create_btn');
                const editLink = $('myls_pb_edit_link');
                const imgBtn = $('myls_pb_gen_images_btn');
                const integrateImages = $('myls_pb_integrate_images').checked && wantsImages();

                editLink.style.display = 'none';
                imgBtn.style.display = 'none';
                $('myls_pb_img_preview').style.display = 'none';
                btn.disabled = true;

                if (integrateImages) {
                    const totalImgs = ($('myls_pb_gen_hero').checked ? 1 : 0) + (parseInt($('myls_pb_feature_count').value) || 0);
                    logEl.textContent = '🎨 Step 1/2: Generating ' + totalImgs + ' image(s) with DALL-E 3…\n(This may take 30-90 seconds)\n\n✏️ Step 2/2: AI will then build the page with images integrated.';
                    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Generating Images + Content…';
                } else {
                    logEl.textContent = '⏳ Generating content with AI… this may take 15-30 seconds.';
                    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Generating…';
                }

                const fd = new FormData();
                fd.append('action',           'myls_pb_create_page');
                fd.append('_wpnonce',         $('myls_pb_nonce').value);
                fd.append('page_title',       title);
                fd.append('post_type',        $('myls_pb_post_type').value);
                fd.append('page_status',      $('myls_pb_status').value);
                fd.append('page_description', $('myls_pb_description').value);
                fd.append('prompt_template',  promptEl.value);
                fd.append('add_to_menu',      $('myls_pb_menu').checked ? '1' : '0');

                // Send image params when integrating
                if (integrateImages) {
                    fd.append('integrate_images', '1');
                    fd.append('image_style',      $('myls_pb_img_style').value);
                    fd.append('gen_hero',          $('myls_pb_gen_hero').checked ? '1' : '0');
                    fd.append('gen_feature',       $('myls_pb_gen_feature').checked ? '1' : '0');
                    fd.append('feature_count',     $('myls_pb_feature_count').value);
                    fd.append('set_featured',      $('myls_pb_set_featured').checked ? '1' : '0');
                }

                try {
                    const res  = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();

                    if (data?.success) {
                        lastPostId = data.data.post_id || 0;
                        logEl.textContent = data.data.log_text || data.data.message || 'Done.';

                        if (data.data.edit_url) {
                            $('myls_pb_edit_url').href = data.data.edit_url;
                            editLink.style.display = '';
                        }

                        // Show separate image gen button only if NOT integrated and images are wanted
                        if (!integrateImages && wantsImages() && lastPostId) {
                            imgBtn.style.display = '';
                            logEl.textContent += '\n\n🖼️ Ready to generate images — click "Generate Images" below.';
                        }

                        // Show image previews if images were generated (integrated mode)
                        if (data.data.images && data.data.images.length) {
                            const imgGrid = $('myls_pb_img_grid');
                            const imgPreview = $('myls_pb_img_preview');
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
                        logEl.textContent = '❌ ' + (data?.data?.message || 'Unknown error.');
                    }
                } catch(e) {
                    logEl.textContent = '❌ Network error: ' + e.message;
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-lightning-charge"></i> Create Page with AI';
                }
            });

            // ── Generate Images ─────────────────────────────────────────
            $('myls_pb_gen_images_btn')?.addEventListener('click', async () => {
                if (!lastPostId) { alert('Create a page first.'); return; }

                const logEl = $('myls_pb_log');
                const btn   = $('myls_pb_gen_images_btn');
                const imgGrid = $('myls_pb_img_grid');
                const imgPreview = $('myls_pb_img_preview');

                const genHero = $('myls_pb_gen_hero').checked;
                const genFeature = $('myls_pb_gen_feature').checked;
                const featureCount = genFeature ? parseInt($('myls_pb_feature_count').value) : 0;
                const totalImages = (genHero ? 1 : 0) + featureCount;

                if (!totalImages) { alert('Select at least one image type to generate.'); return; }

                logEl.textContent += '\n\n⏳ Generating ' + totalImages + ' image(s) with DALL-E 3… this may take 30-90 seconds.';
                btn.disabled = true;
                btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Generating Images…';

                const fd = new FormData();
                fd.append('action',        'myls_pb_generate_images');
                fd.append('_wpnonce',      $('myls_pb_nonce').value);
                fd.append('post_id',       lastPostId);
                fd.append('page_title',    $('myls_pb_title').value);
                fd.append('description',   $('myls_pb_description').value);
                fd.append('image_style',   $('myls_pb_img_style').value);
                fd.append('gen_hero',      genHero ? '1' : '0');
                fd.append('gen_feature',   genFeature ? '1' : '0');
                fd.append('feature_count', featureCount);
                fd.append('set_featured',  $('myls_pb_set_featured').checked ? '1' : '0');
                fd.append('insert_hero',   $('myls_pb_insert_hero').checked ? '1' : '0');

                try {
                    const res = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();

                    if (data?.success) {
                        logEl.textContent += '\n\n' + (data.data.log_text || 'Images done.');

                        // Show image previews
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
        })();
        </script>
        <?php
    }
];
