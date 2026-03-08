<?php
/**
 * Utilities Subtab: Style Editor
 * File: admin/tabs/utilities/subtab-custom-css.php
 *
 * Frontend CSS editor with snippet insertion, live preview,
 * and CSS class reference for plugin shortcodes.
 *
 * Saves custom CSS to wp_options and enqueues on frontend
 * with high priority to override theme styles.
 */
if ( ! defined('ABSPATH') ) exit;

return [
    'id'    => 'style-editor',
    'label' => 'Style Editor',
    'order' => 10,
    'render'=> function() {

        $option_key = 'myls_custom_css';
        $css   = get_option($option_key, '');
        $nonce = wp_create_nonce('myls_custom_css');

        // CSS file info
        $frontend_css_path = MYLS_PATH . 'assets/frontend.css';
        $frontend_css_size = file_exists($frontend_css_path) ? size_format(filesize($frontend_css_path)) : '—';

        $admin_css_path = MYLS_PATH . 'assets/css/admin.css';
        $admin_css_size = file_exists($admin_css_path) ? size_format(filesize($admin_css_path)) : '—';

        $custom_css_len = strlen($css);

        // Preview pages
        $preview_pages = [];
        $preview_pages[] = ['url' => home_url('/'), 'label' => 'Homepage'];
        $recent = get_posts([
            'post_type'      => ['page','post','service','service_area'],
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ]);
        foreach ($recent as $p) {
            $obj = get_post_type_object($p->post_type);
            $type_label = $obj->labels->singular_name ?? $p->post_type;
            $preview_pages[] = [
                'url'   => get_permalink($p->ID),
                'label' => "[$type_label] " . (get_the_title($p->ID) ?: '(no title)'),
            ];
        }

        // CSS selector snippets organized by shortcode
        $snippets = [
            'Service Grid' => [
                '.myls-service-grid'   => 'Grid wrapper',
                '.service-box'         => 'Individual service card',
                '.myls-sg-img'         => 'Service image',
                '.myls-sg-title'       => 'Service title',
                '.myls-sg-title a'     => 'Title link',
                '.myls-sg-tagline'     => 'Tagline text',
                '.myls-sg-excerpt'     => 'Excerpt text',
                '.myls-sg-btn'         => 'Learn More button',
                '.myls-sg-img-link'    => 'Image link wrapper',
            ],
            'Service Posts' => [
                '.mlseo-service-posts-grid'   => 'Grid wrapper',
                '.service-post-card'          => 'Post card',
                '.service-post-image-wrapper' => 'Image container',
                '.service-post-image'         => 'Card image',
                '.service-post-icon-wrapper'  => 'Icon container',
                '.service-post-title'         => 'Post title',
                '.service-post-tagline'       => 'Post tagline',
                '.service-post-button'        => 'CTA button',
                '.service-type-heat'          => 'Heat service modifier',
                '.service-type-ac'            => 'AC service modifier',
            ],
            'Child Posts' => [
                '.mlseo-service-posts-grid' => 'Grid wrapper (shared)',
                '.service-post-card'        => 'Card (shared with service posts)',
                '.service-posts-heading'    => 'Section heading',
            ],
        ];
        ?>

        <style>
            /* ─── Style Editor Layout ─── */
            .myls-se-header { margin-bottom: 20px; }
            .myls-se-header h3 { margin: 0 0 4px; font-size: 18px; font-weight: 700; color: #1e293b; }
            .myls-se-header p  { margin: 0; color: #64748b; font-size: 13px; }

            /* Info cards */
            .myls-se-cards { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
            .myls-se-card  { flex: 1; min-width: 160px; padding: 12px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
            .myls-se-card-label { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #94a3b8; font-weight: 600; margin-bottom: 4px; }
            .myls-se-card-value { font-size: 16px; font-weight: 700; color: #334155; }
            .myls-se-card-sub   { font-size: 11px; color: #94a3b8; margin-top: 2px; }

            /* Main split layout */
            .myls-se-main { display: flex; gap: 0; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; height: calc(100vh - 380px); min-height: 450px; }

            /* Editor panel (left) */
            .myls-se-editor  { width: 45%; min-width: 360px; display: flex; flex-direction: column; background: #fff; }
            .myls-se-toolbar { display: flex; gap: 8px; align-items: center; padding: 10px 14px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
            .myls-se-toolbar .myls-se-title { font-weight: 600; font-size: 13px; color: #334155; }
            .myls-se-toolbar .spacer { flex: 1; }

            /* Snippet dropdown */
            .myls-se-snippet-wrap { position: relative; }
            .myls-se-snippet-btn  {
                background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px;
                padding: 5px 10px; font-size: 12px; cursor: pointer; color: #475569; font-weight: 500;
            }
            .myls-se-snippet-btn:hover { background: #e2e8f0; }
            .myls-se-snippet-menu {
                display: none; position: absolute; top: 100%; right: 0; margin-top: 4px;
                background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,.1); min-width: 340px; max-height: 400px;
                overflow-y: auto; z-index: 100;
            }
            .myls-se-snippet-menu.open { display: block; }
            .myls-se-snippet-group { padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
            .myls-se-snippet-group:last-child { border-bottom: none; }
            .myls-se-snippet-group-title {
                padding: 4px 14px; font-size: 11px; font-weight: 700;
                text-transform: uppercase; letter-spacing: .5px; color: #94a3b8;
            }
            .myls-se-snippet-item {
                display: flex; justify-content: space-between; padding: 6px 14px;
                cursor: pointer; font-size: 12px;
            }
            .myls-se-snippet-item:hover { background: #f1f5f9; }
            .myls-se-snippet-item .sel  { font-family: monospace; color: #7c3aed; font-weight: 500; }
            .myls-se-snippet-item .desc { color: #94a3b8; font-size: 11px; }

            /* Code textarea */
            #myls_se_textarea {
                flex: 1; width: 100%; border: none; resize: none; padding: 14px;
                font-family: 'SF Mono','Monaco','Menlo','Consolas','Liberation Mono', monospace;
                font-size: 13px; line-height: 1.7; tab-size: 2;
                background: #1e1e2e; color: #cdd6f4; outline: none;
            }
            #myls_se_textarea::placeholder { color: #585b70; }
            #myls_se_textarea::selection   { background: #45475a; }

            /* Status bar */
            .myls-se-status { display: flex; justify-content: space-between; align-items: center; padding: 8px 14px; background: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 11px; }
            .myls-se-status .saved   { color: #16a34a; font-weight: 600; }
            .myls-se-status .unsaved { color: #dc2626; font-weight: 600; }
            .myls-se-status .saving  { color: #2563eb; font-weight: 600; }
            .myls-se-status .info    { color: #94a3b8; }

            /* Keyboard hints */
            .myls-se-hints { padding: 6px 14px; background: #f8fafc; border-top: 1px solid #f1f5f9; font-size: 11px; color: #94a3b8; }
            .myls-se-hints kbd {
                display: inline-block; padding: 1px 5px; background: #e2e8f0;
                border: 1px solid #cbd5e1; border-radius: 3px; font-size: 10px;
                font-family: inherit; color: #475569;
            }

            /* Preview panel (right) */
            .myls-se-preview { flex: 1; display: flex; flex-direction: column; border-left: 1px solid #e2e8f0; }
            .myls-se-preview-toolbar {
                display: flex; gap: 8px; align-items: center; padding: 10px 14px;
                background: #f8fafc; border-bottom: 1px solid #e2e8f0;
            }
            .myls-se-preview-toolbar select {
                max-width: 240px; padding: 4px 8px; border: 1px solid #cbd5e1;
                border-radius: 6px; font-size: 12px; background: #fff;
            }
            .myls-se-preview-toolbar .spacer { flex: 1; }

            /* Device switcher */
            .myls-se-devices { display: flex; gap: 2px; background: #e2e8f0; border-radius: 6px; padding: 2px; }
            .myls-se-devices button {
                background: none; border: none; border-radius: 4px; padding: 4px 10px;
                cursor: pointer; font-size: 12px; color: #64748b; font-weight: 500;
            }
            .myls-se-devices button.active {
                background: #fff; color: #1e293b; box-shadow: 0 1px 2px rgba(0,0,0,.06);
            }
            .myls-se-devices button:hover:not(.active) { color: #334155; }

            /* Preview iframe */
            .myls-se-iframe-wrap { flex: 1; display: flex; justify-content: center; background: #f1f5f9; overflow: hidden; }
            #myls_se_iframe { flex: 1; width: 100%; border: none; background: #fff; transition: max-width .3s ease; }

            /* Small icon button */
            .myls-se-icon-btn {
                background: none; border: 1px solid #cbd5e1; border-radius: 6px;
                padding: 4px 8px; cursor: pointer; font-size: 14px; color: #64748b;
            }
            .myls-se-icon-btn:hover { background: #f1f5f9; color: #334155; }

            /* ─── CSS Reference Section ─── */
            .myls-se-ref { margin-top: 20px; }
            .myls-se-ref-toggle {
                display: flex; align-items: center; gap: 8px; padding: 12px 16px;
                background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
                cursor: pointer; font-size: 13px; font-weight: 600; color: #334155;
                width: 100%; text-align: left;
            }
            .myls-se-ref-toggle:hover { background: #f1f5f9; }
            .myls-se-ref-toggle .arrow { transition: transform .2s; font-size: 10px; }
            .myls-se-ref-toggle.open .arrow { transform: rotate(90deg); }

            .myls-se-ref-body { display: none; margin-top: 8px; }
            .myls-se-ref-body.open { display: flex; gap: 12px; flex-wrap: wrap; }

            .myls-se-ref-card { flex: 1; min-width: 280px; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
            .myls-se-ref-card-header {
                padding: 10px 14px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;
                font-weight: 600; font-size: 13px; color: #334155;
            }
            .myls-se-ref-card-body { padding: 0; }
            .myls-se-ref-row {
                display: flex; justify-content: space-between; padding: 6px 14px;
                border-bottom: 1px solid #f8fafc; font-size: 12px; cursor: pointer;
            }
            .myls-se-ref-row:last-child { border-bottom: none; }
            .myls-se-ref-row:hover { background: #f8fafc; }
            .myls-se-ref-row .sel  { font-family: monospace; color: #7c3aed; }
            .myls-se-ref-row .desc { color: #94a3b8; }

            /* Responsive */
            @media (max-width: 900px) {
                .myls-se-main    { flex-direction: column; height: auto; }
                .myls-se-editor  { width: 100%; min-width: 0; min-height: 350px; }
                .myls-se-preview { border-left: none; border-top: 1px solid #e2e8f0; min-height: 400px; }
            }
        </style>

        <!-- Header -->
        <div class="myls-se-header">
            <h3>Style Editor</h3>
            <p>Customize plugin frontend styles. Your CSS loads after theme styles with high priority for easy overrides.</p>
        </div>

        <!-- Info Cards -->
        <div class="myls-se-cards">
            <div class="myls-se-card">
                <div class="myls-se-card-label">Frontend Styles</div>
                <div class="myls-se-card-value"><?php echo esc_html($frontend_css_size); ?></div>
                <div class="myls-se-card-sub">assets/frontend.css</div>
            </div>
            <div class="myls-se-card">
                <div class="myls-se-card-label">Admin Styles</div>
                <div class="myls-se-card-value"><?php echo esc_html($admin_css_size); ?></div>
                <div class="myls-se-card-sub">assets/css/admin.css</div>
            </div>
            <div class="myls-se-card">
                <div class="myls-se-card-label">Custom Overrides</div>
                <div class="myls-se-card-value" id="myls_se_charcount"><?php echo number_format($custom_css_len); ?> chars</div>
                <div class="myls-se-card-sub">Saved in database</div>
            </div>
        </div>

        <!-- ═══ Main Editor ═══ -->
        <div class="myls-se-main">

            <!-- LEFT: CSS Editor -->
            <div class="myls-se-editor">
                <div class="myls-se-toolbar">
                    <span class="myls-se-title">Custom CSS Overrides</span>
                    <span class="spacer"></span>

                    <!-- Snippet Dropdown -->
                    <div class="myls-se-snippet-wrap">
                        <button type="button" class="myls-se-snippet-btn" id="myls_se_snippet_toggle">Insert Selector &#9662;</button>
                        <div class="myls-se-snippet-menu" id="myls_se_snippet_menu">
                            <?php foreach ($snippets as $group => $selectors): ?>
                                <div class="myls-se-snippet-group">
                                    <div class="myls-se-snippet-group-title"><?php echo esc_html($group); ?></div>
                                    <?php foreach ($selectors as $sel => $desc): ?>
                                        <div class="myls-se-snippet-item" data-selector="<?php echo esc_attr($sel); ?>">
                                            <span class="sel"><?php echo esc_html($sel); ?></span>
                                            <span class="desc"><?php echo esc_html($desc); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="button" class="myls-se-icon-btn" id="myls_se_revert" title="Revert to last saved">&#8634;</button>
                    <button type="button" class="btn btn-sm btn-primary" id="myls_se_save" style="padding:5px 14px;font-size:12px;">Save</button>
                </div>

                <textarea id="myls_se_textarea"
                    placeholder="/* Custom CSS Overrides */&#10;/* Tip: Use 'Insert Selector' above for quick access to plugin CSS classes */&#10;&#10;.service-box {&#10;  border-radius: 12px;&#10;  box-shadow: 0 2px 8px rgba(0,0,0,0.08);&#10;}"
                    spellcheck="false"><?php echo esc_textarea($css); ?></textarea>

                <div class="myls-se-hints">
                    <kbd>Tab</kbd> indent &middot; <kbd>Ctrl+S</kbd> save &middot; CSS loads after theme with high priority
                </div>

                <div class="myls-se-status">
                    <span id="myls_se_status" class="saved">Saved</span>
                    <span class="info"><span id="myls_se_chars"><?php echo $custom_css_len; ?></span> chars</span>
                </div>

                <input type="hidden" id="myls_se_nonce"     value="<?php echo esc_attr($nonce); ?>">
                <input type="hidden" id="myls_se_saved_css" value="<?php echo esc_attr($css); ?>">
            </div>

            <!-- RIGHT: Live Preview -->
            <div class="myls-se-preview">
                <div class="myls-se-preview-toolbar">
                    <select id="myls_se_page">
                        <?php foreach ($preview_pages as $pg): ?>
                            <option value="<?php echo esc_url($pg['url']); ?>">
                                <?php echo esc_html($pg['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="myls-se-icon-btn" id="myls_se_reload" title="Reload preview">&#8635;</button>
                    <span class="spacer"></span>
                    <div class="myls-se-devices">
                        <button type="button" data-width="100%" class="active">Desktop</button>
                        <button type="button" data-width="768px">Tablet</button>
                        <button type="button" data-width="375px">Mobile</button>
                    </div>
                </div>
                <div class="myls-se-iframe-wrap">
                    <iframe id="myls_se_iframe" src="<?php echo esc_url(home_url('/')); ?>"></iframe>
                </div>
            </div>

        </div>

        <!-- ═══ CSS Class Reference ═══ -->
        <div class="myls-se-ref">
            <button type="button" class="myls-se-ref-toggle" id="myls_se_ref_toggle">
                <span class="arrow">&#9654;</span>
                CSS Class Reference &mdash; Available selectors for plugin shortcodes
            </button>
            <div class="myls-se-ref-body" id="myls_se_ref_body">
                <?php foreach ($snippets as $group => $selectors):
                    $sc = strtolower(str_replace(' ', '_', $group));
                ?>
                    <div class="myls-se-ref-card">
                        <div class="myls-se-ref-card-header">[<?php echo esc_html($sc); ?>] &mdash; <?php echo esc_html($group); ?></div>
                        <div class="myls-se-ref-card-body">
                            <?php foreach ($selectors as $sel => $desc): ?>
                                <div class="myls-se-ref-row" data-selector="<?php echo esc_attr($sel); ?>">
                                    <span class="sel"><?php echo esc_html($sel); ?></span>
                                    <span class="desc"><?php echo esc_html($desc); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
        (function(){
            const textarea    = document.getElementById('myls_se_textarea');
            const iframe      = document.getElementById('myls_se_iframe');
            const pageSelect  = document.getElementById('myls_se_page');
            const statusEl    = document.getElementById('myls_se_status');
            const charsEl     = document.getElementById('myls_se_chars');
            const charcountEl = document.getElementById('myls_se_charcount');
            const savedEl     = document.getElementById('myls_se_saved_css');
            const nonceEl     = document.getElementById('myls_se_nonce');
            let debounceTimer = null;
            let lastSaved     = savedEl.value;

            /* ── Helpers ── */
            function updateCharCount() {
                const len = textarea.value.length;
                charsEl.textContent     = len;
                charcountEl.textContent = len.toLocaleString() + ' chars';
            }

            function injectCSS() {
                try {
                    const doc = iframe.contentDocument || iframe.contentWindow.document;
                    if (!doc || !doc.head) return;
                    let el = doc.getElementById('myls-live-css');
                    if (el) el.remove();
                    const style = doc.createElement('style');
                    style.id = 'myls-live-css';
                    style.textContent = textarea.value;
                    doc.head.appendChild(style);
                } catch(e) { /* cross-origin */ }
            }

            /* ── Live preview on typing ── */
            textarea.addEventListener('input', () => {
                statusEl.className   = textarea.value !== lastSaved ? 'unsaved' : 'saved';
                statusEl.textContent = textarea.value !== lastSaved ? 'Unsaved changes' : 'Saved';
                updateCharCount();
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(injectCSS, 150);
            });

            iframe.addEventListener('load', () => setTimeout(injectCSS, 200));

            /* ── Tab key + Ctrl+S ── */
            textarea.addEventListener('keydown', (e) => {
                if (e.key === 'Tab') {
                    e.preventDefault();
                    const s = textarea.selectionStart;
                    textarea.value = textarea.value.substring(0, s) + '  ' + textarea.value.substring(textarea.selectionEnd);
                    textarea.selectionStart = textarea.selectionEnd = s + 2;
                    textarea.dispatchEvent(new Event('input'));
                }
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    saveCSS();
                }
            });

            /* ── Page selector ── */
            pageSelect.addEventListener('change', () => { iframe.src = pageSelect.value; });
            document.getElementById('myls_se_reload')?.addEventListener('click', () => { iframe.src = iframe.src; });

            /* ── Device switcher ── */
            document.querySelectorAll('.myls-se-devices button').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.myls-se-devices button').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    iframe.style.maxWidth = btn.dataset.width;
                });
            });

            /* ── Save CSS via AJAX ── */
            async function saveCSS() {
                statusEl.textContent = 'Saving\u2026';
                statusEl.className   = 'saving';
                const fd = new FormData();
                fd.append('action',   'myls_save_custom_css');
                fd.append('_wpnonce', nonceEl.value);
                fd.append('css',      textarea.value);
                try {
                    const res  = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data?.success) {
                        lastSaved        = textarea.value;
                        savedEl.value    = textarea.value;
                        statusEl.className   = 'saved';
                        statusEl.textContent = 'Saved';
                        iframe.src = iframe.src; // reload preview with server-side CSS
                    } else {
                        statusEl.className   = 'unsaved';
                        statusEl.textContent = 'Save failed: ' + (data?.data?.message || 'Unknown error');
                    }
                } catch(e) {
                    statusEl.className   = 'unsaved';
                    statusEl.textContent = 'Network error';
                }
            }

            document.getElementById('myls_se_save')?.addEventListener('click', saveCSS);

            /* ── Revert ── */
            document.getElementById('myls_se_revert')?.addEventListener('click', () => {
                if (confirm('Revert to last saved version?')) {
                    textarea.value = lastSaved;
                    textarea.dispatchEvent(new Event('input'));
                    injectCSS();
                }
            });

            /* ── Snippet dropdown ── */
            const snippetMenu = document.getElementById('myls_se_snippet_menu');
            document.getElementById('myls_se_snippet_toggle')?.addEventListener('click', (e) => {
                e.stopPropagation();
                snippetMenu.classList.toggle('open');
            });
            document.addEventListener('click', () => snippetMenu?.classList.remove('open'));

            function insertSelector(selector) {
                const snippet = '\n' + selector + ' {\n  \n}\n';
                const pos = textarea.selectionStart;
                textarea.value = textarea.value.substring(0, pos) + snippet + textarea.value.substring(textarea.selectionEnd);
                textarea.selectionStart = textarea.selectionEnd = pos + selector.length + 5;
                textarea.focus();
                textarea.dispatchEvent(new Event('input'));
            }

            document.querySelectorAll('.myls-se-snippet-item').forEach(item => {
                item.addEventListener('click', () => {
                    insertSelector(item.dataset.selector);
                    snippetMenu.classList.remove('open');
                });
            });

            /* ── CSS Reference accordion ── */
            const refToggle = document.getElementById('myls_se_ref_toggle');
            const refBody   = document.getElementById('myls_se_ref_body');
            refToggle?.addEventListener('click', () => {
                refToggle.classList.toggle('open');
                refBody.classList.toggle('open');
            });

            // Click reference row to insert selector
            document.querySelectorAll('.myls-se-ref-row').forEach(row => {
                row.addEventListener('click', () => insertSelector(row.dataset.selector));
            });

        })();
        </script>
        <?php
    }
];
