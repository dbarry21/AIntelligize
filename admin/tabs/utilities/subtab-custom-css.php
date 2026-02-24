<?php
/**
 * Utilities Subtab: Custom CSS Editor
 * File: admin/tabs/utilities/subtab-custom-css.php
 *
 * Live CSS editor with real-time preview. Saves to wp_options
 * and enqueues on frontend with high priority to override theme styles.
 */
if ( ! defined('ABSPATH') ) exit;

return [
    'id'    => 'custom-css',
    'label' => 'Custom CSS',
    'order' => 10,
    'render'=> function() {

        $option_key = 'myls_custom_css';
        $css = get_option($option_key, '');
        $nonce = wp_create_nonce('myls_custom_css');

        // Get some pages to preview
        $preview_pages = [];
        $preview_pages[] = ['url' => home_url('/'), 'label' => 'Homepage'];
        $recent = get_posts([
            'post_type'      => ['page','post'],
            'post_status'    => 'publish',
            'posts_per_page' => 15,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ]);
        foreach ($recent as $p) {
            $preview_pages[] = [
                'url'   => get_permalink($p->ID),
                'label' => get_the_title($p->ID) ?: '(no title)',
            ];
        }
        ?>

        <style>
            .myls-css-wrap { display:flex; gap:0; height:calc(100vh - 220px); min-height:500px; }
            .myls-css-editor { width:40%; min-width:320px; display:flex; flex-direction:column; border-right:2px solid #dee2e6; }
            .myls-css-preview { flex:1; display:flex; flex-direction:column; }

            .myls-css-toolbar { display:flex; gap:6px; align-items:center; padding:8px 12px; background:#f0f0f1; border-bottom:1px solid #dee2e6; flex-wrap:wrap; }
            .myls-css-toolbar select { max-width:260px; }
            .myls-css-toolbar .btn-sm { padding:4px 12px; font-size:12px; }

            #myls_css_textarea {
                flex:1; width:100%; border:none; resize:none; padding:12px;
                font-family:'SF Mono','Monaco','Menlo','Consolas','Liberation Mono',monospace;
                font-size:13px; line-height:1.6; tab-size:2;
                background:#1e1e2e; color:#cdd6f4; outline:none;
            }
            #myls_css_textarea::placeholder { color:#6c7086; }

            #myls_css_iframe { flex:1; width:100%; border:none; background:#fff; }

            .myls-css-status { padding:6px 12px; font-size:11px; background:#f0f0f1; border-top:1px solid #dee2e6; display:flex; justify-content:space-between; align-items:center; }
            .myls-css-status .saved { color:#16a34a; font-weight:600; }
            .myls-css-status .unsaved { color:#dc2626; font-weight:600; }
            .myls-css-status .info { color:#6c757d; }

            .myls-css-hints { padding:8px 12px; background:#f8f9fa; border-top:1px solid #dee2e6; font-size:11px; color:#6c757d; }
            .myls-css-hints code { font-size:11px; background:#e9ecef; padding:1px 4px; border-radius:3px; }

            /* Responsive handle */
            .myls-device-btns { display:flex; gap:2px; }
            .myls-device-btns button { background:none; border:1px solid #ccc; border-radius:4px; padding:3px 8px; cursor:pointer; font-size:14px; }
            .myls-device-btns button.active { background:#0d6efd; color:#fff; border-color:#0d6efd; }
        </style>

        <div class="myls-css-wrap">

            <!-- ═══ LEFT: CSS Editor ═══ -->
            <div class="myls-css-editor">
                <div class="myls-css-toolbar">
                    <strong style="font-size:13px;">📝 Custom CSS</strong>
                    <div style="flex:1;"></div>
                    <button type="button" class="btn btn-sm btn-primary" id="myls_css_save">💾 Save</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="myls_css_undo" title="Revert to last saved">↩ Revert</button>
                </div>

                <textarea id="myls_css_textarea"
                          placeholder="/* Your custom CSS here */&#10;/* Overrides theme styles with !important priority */&#10;&#10;body {&#10;  font-family: 'Inter', sans-serif;&#10;}&#10;&#10;.site-header {&#10;  background: #1a2332;&#10;}"
                          spellcheck="false"><?php echo esc_textarea($css); ?></textarea>

                <div class="myls-css-hints">
                    <strong>Tips:</strong>
                    Tab to indent · Ctrl+S to save · Loaded after theme with <code>!important</code> priority
                </div>

                <div class="myls-css-status">
                    <span id="myls_css_status_text" class="saved">✓ Saved</span>
                    <span class="info"><?php echo strlen($css); ?> chars</span>
                </div>

                <input type="hidden" id="myls_css_nonce" value="<?php echo esc_attr($nonce); ?>">
                <input type="hidden" id="myls_css_saved" value="<?php echo esc_attr($css); ?>">
            </div>

            <!-- ═══ RIGHT: Live Preview ═══ -->
            <div class="myls-css-preview">
                <div class="myls-css-toolbar">
                    <select id="myls_css_page" class="form-select form-select-sm" style="max-width:280px;">
                        <?php foreach ($preview_pages as $pg): ?>
                            <option value="<?php echo esc_url($pg['url']); ?>">
                                <?php echo esc_html($pg['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="myls_css_reload" title="Reload preview">🔄</button>

                    <div style="flex:1;"></div>

                    <div class="myls-device-btns">
                        <button type="button" data-width="100%" class="active" title="Desktop">🖥</button>
                        <button type="button" data-width="768px" title="Tablet">📱</button>
                        <button type="button" data-width="375px" title="Mobile">📲</button>
                    </div>
                </div>

                <iframe id="myls_css_iframe" src="<?php echo esc_url(home_url('/')); ?>"></iframe>
            </div>

        </div>

        <script>
        (function(){
            const textarea  = document.getElementById('myls_css_textarea');
            const iframe    = document.getElementById('myls_css_iframe');
            const pageSelect = document.getElementById('myls_css_page');
            const statusEl  = document.getElementById('myls_css_status_text');
            const savedEl   = document.getElementById('myls_css_saved');
            const nonceEl   = document.getElementById('myls_css_nonce');
            let debounceTimer = null;
            let lastSaved = savedEl.value;

            // ── Inject CSS into iframe ──────────────────────────────────
            function injectCSS() {
                try {
                    const doc = iframe.contentDocument || iframe.contentWindow.document;
                    if (!doc || !doc.head) return;

                    // Remove previous injection
                    let existing = doc.getElementById('myls-live-css');
                    if (existing) existing.remove();

                    // Create new style
                    const style = doc.createElement('style');
                    style.id = 'myls-live-css';
                    style.textContent = textarea.value;
                    doc.head.appendChild(style);
                } catch(e) {
                    // Cross-origin — ignore silently
                }
            }

            // ── Live preview on typing ──────────────────────────────────
            textarea.addEventListener('input', () => {
                // Update status
                if (textarea.value !== lastSaved) {
                    statusEl.className = 'unsaved';
                    statusEl.textContent = '● Unsaved changes';
                } else {
                    statusEl.className = 'saved';
                    statusEl.textContent = '✓ Saved';
                }

                // Debounced inject
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(injectCSS, 150);
            });

            // Re-inject on iframe load
            iframe.addEventListener('load', () => {
                setTimeout(injectCSS, 200);
            });

            // ── Tab key support ─────────────────────────────────────────
            textarea.addEventListener('keydown', (e) => {
                if (e.key === 'Tab') {
                    e.preventDefault();
                    const start = textarea.selectionStart;
                    const end = textarea.selectionEnd;
                    textarea.value = textarea.value.substring(0, start) + '  ' + textarea.value.substring(end);
                    textarea.selectionStart = textarea.selectionEnd = start + 2;
                    textarea.dispatchEvent(new Event('input'));
                }

                // Ctrl+S / Cmd+S to save
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    saveCSS();
                }
            });

            // ── Page selector ───────────────────────────────────────────
            pageSelect.addEventListener('change', () => {
                iframe.src = pageSelect.value;
            });

            // Reload
            document.getElementById('myls_css_reload')?.addEventListener('click', () => {
                iframe.src = iframe.src;
            });

            // ── Device width buttons ────────────────────────────────────
            document.querySelectorAll('.myls-device-btns button').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.myls-device-btns button').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    iframe.style.maxWidth = btn.dataset.width;
                    iframe.style.margin = btn.dataset.width === '100%' ? '0' : '0 auto';
                    iframe.style.borderLeft = btn.dataset.width === '100%' ? 'none' : '1px solid #dee2e6';
                    iframe.style.borderRight = btn.dataset.width === '100%' ? 'none' : '1px solid #dee2e6';
                });
            });

            // ── Save CSS ────────────────────────────────────────────────
            async function saveCSS() {
                statusEl.textContent = '⏳ Saving…';
                statusEl.className = 'info';

                const fd = new FormData();
                fd.append('action', 'myls_save_custom_css');
                fd.append('_wpnonce', nonceEl.value);
                fd.append('css', textarea.value);

                try {
                    const res = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();

                    if (data?.success) {
                        lastSaved = textarea.value;
                        savedEl.value = textarea.value;
                        statusEl.className = 'saved';
                        statusEl.textContent = '✓ Saved';

                        // Reload iframe to show saved version from server
                        iframe.src = iframe.src;
                    } else {
                        statusEl.className = 'unsaved';
                        statusEl.textContent = '❌ ' + (data?.data?.message || 'Save failed');
                    }
                } catch(e) {
                    statusEl.className = 'unsaved';
                    statusEl.textContent = '❌ Network error';
                }
            }

            document.getElementById('myls_css_save')?.addEventListener('click', saveCSS);

            // ── Revert ──────────────────────────────────────────────────
            document.getElementById('myls_css_undo')?.addEventListener('click', () => {
                if (confirm('Revert to last saved version?')) {
                    textarea.value = lastSaved;
                    textarea.dispatchEvent(new Event('input'));
                    injectCSS();
                }
            });

        })();
        </script>
        <?php
    }
];
