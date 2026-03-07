<?php
/**
 * AIntelligize Admin Tab: Cookie Consent
 * File: admin/tabs/tab-cookie-consent.php
 */

if ( ! defined('ABSPATH') ) exit;

myls_register_admin_tab([
    'id'    => 'cookie-consent',
    'title' => 'Cookie Consent',
    'icon'  => 'bi-shield-check',
    'order' => 92,
    'cap'   => 'manage_options',
    'cb'    => function () {

        // Save handler
        if (
            isset( $_POST['ccb_save'] ) &&
            isset( $_POST['_ccb_nonce'] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ccb_nonce'] ) ), 'aintelligize_ccb_save' )
        ) {
            $posted = isset( $_POST[ CCB_OPTION ] ) ? wp_unslash( $_POST[ CCB_OPTION ] ) : [];
            // Checkbox fields won't be present if unchecked
            foreach ( ['enabled', 'decline_button', 'script_blocking'] as $cb_field ) {
                if ( ! isset($posted[$cb_field]) ) {
                    $posted[$cb_field] = '0';
                }
            }
            update_option( CCB_OPTION, ccb_sanitize_settings( $posted ) );
            echo '<div class="notice notice-success is-dismissible"><p><strong>Cookie Consent settings saved.</strong></p></div>';
        }

        $s = ccb_get_settings();
        $opt = CCB_OPTION;

        // Subtab router
        $subtab = isset($_GET['subtab']) ? sanitize_key($_GET['subtab']) : 'settings';
        $base   = admin_url('admin.php?page=aintelligize&tab=cookie-consent');

        $subtabs = [
            'settings' => 'Settings & Preview',
            'blocking' => 'Script Blocking',
            'docs'     => 'Usage & Docs',
        ];
        ?>
        <div class="wrap aintelligize-ccb-admin">

        <style>
            .aintelligize-ccb-admin .ccb-card {
                background:#fff; border:1px solid #e0e0e0; border-radius:10px;
                padding:22px 24px; margin-bottom:20px;
            }
            .aintelligize-ccb-admin .ccb-card h3 {
                margin-top:0; font-size:15px; color:#1a1a2e; border-bottom:1px solid #f0f0f0;
                padding-bottom:10px; margin-bottom:16px;
            }
            .aintelligize-ccb-admin .ccb-grid {
                display:grid; grid-template-columns:1fr 1fr; gap:16px;
            }
            @media(max-width:900px){ .aintelligize-ccb-admin .ccb-grid { grid-template-columns:1fr; } }
            .aintelligize-ccb-admin .ccb-field { margin-bottom:14px; }
            .aintelligize-ccb-admin .ccb-field label {
                display:block; font-weight:600; font-size:13px; margin-bottom:5px; color:#333;
            }
            .aintelligize-ccb-admin .ccb-field .description {
                font-size:12px; color:#777; margin-top:4px;
            }
            .aintelligize-ccb-admin input[type="text"],
            .aintelligize-ccb-admin input[type="url"],
            .aintelligize-ccb-admin input[type="number"],
            .aintelligize-ccb-admin select,
            .aintelligize-ccb-admin textarea {
                width:100%; max-width:420px; padding:8px 10px;
                border:1px solid #ccc; border-radius:6px;
                font-size:13px; color:#333;
            }
            .aintelligize-ccb-admin textarea { height:70px; resize:vertical; }
            .aintelligize-ccb-admin .ccb-toggle-row {
                display:flex; align-items:center; gap:10px; margin-bottom:14px;
            }
            .aintelligize-ccb-admin .ccb-toggle-row label { font-weight:600; font-size:13px; }
            .aintelligize-ccb-admin .ccb-color-row {
                display:flex; align-items:center; gap:8px;
            }
            .aintelligize-ccb-admin .ccb-color-row input[type="color"] {
                width:44px; height:36px; padding:2px; border-radius:6px;
                border:1px solid #ccc; cursor:pointer;
            }
            .aintelligize-ccb-admin .ccb-color-row input[type="text"] {
                width:100px; max-width:100px; font-family:monospace;
            }
            /* Preview area */
            .aintelligize-ccb-admin .ccb-preview-wrap {
                background:#f0f2f7; border-radius:10px; padding:20px;
                min-height:100px; display:flex; align-items:flex-end;
            }
            .aintelligize-ccb-admin #ccb-preview-banner {
                width:100%; border-radius:8px; overflow:hidden;
            }
            /* Subtab nav */
            .aintelligize-ccb-admin .ccb-subtab-nav {
                display:flex; gap:4px; margin-bottom:20px;
                border-bottom:2px solid #e0e0e0; padding-bottom:0;
            }
            .aintelligize-ccb-admin .ccb-subtab-nav a {
                padding:8px 16px; font-weight:600; font-size:13px;
                text-decoration:none; color:#555; border-radius:6px 6px 0 0;
                border:1px solid transparent; border-bottom:2px solid transparent;
                margin-bottom:-2px;
            }
            .aintelligize-ccb-admin .ccb-subtab-nav a:hover { color:#0073aa; }
            .aintelligize-ccb-admin .ccb-subtab-nav a.active {
                color:#0073aa; background:#fff;
                border-color:#e0e0e0 #e0e0e0 #fff #e0e0e0;
                border-bottom-color:#fff;
            }
            /* Code block */
            .aintelligize-ccb-admin .ccb-code {
                background:#1e1e2e; color:#cdd6f4; border-radius:8px;
                padding:16px; font-family:monospace; font-size:13px;
                line-height:1.6; overflow-x:auto; white-space:pre;
            }
            .aintelligize-ccb-admin .ccb-code .kw { color:#89b4fa; }
            .aintelligize-ccb-admin .ccb-code .str { color:#a6e3a1; }
            .aintelligize-ccb-admin .ccb-code .attr { color:#f38ba8; }
            .aintelligize-ccb-admin .ccb-code .cm { color:#6c7086; }
        </style>

        <!-- Subtab nav -->
        <nav class="ccb-subtab-nav">
            <?php foreach ( $subtabs as $id => $label ) : ?>
                <a href="<?php echo esc_url( add_query_arg('subtab', $id, $base) ); ?>"
                   class="<?php echo $id === $subtab ? 'active' : ''; ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php
        /* ════════════════════════════════════════════
           SUBTAB: Settings & Preview
        ═══════════════════════════════════════════ */
        if ( $subtab === 'settings' ) : ?>

        <form method="post">
            <?php wp_nonce_field('aintelligize_ccb_save', '_ccb_nonce'); ?>
            <input type="hidden" name="ccb_save" value="1">

            <div class="ccb-grid">

                <!-- LEFT: Settings -->
                <div>
                    <div class="ccb-card">
                        <h3>⚙️ General</h3>
                        <div class="ccb-toggle-row">
                            <input type="checkbox" id="ccb_enabled" name="<?php echo $opt; ?>[enabled]" value="1"
                                <?php checked($s['enabled'], '1'); ?>>
                            <label for="ccb_enabled">Enable cookie consent banner</label>
                        </div>

                        <div class="ccb-field">
                            <label for="ccb_delay">Delay before showing</label>
                            <select name="<?php echo $opt; ?>[delay]" id="ccb_delay">
                                <?php foreach ([
                                    '0'    => 'Immediately',
                                    '500'  => '0.5 seconds',
                                    '1000' => '1 second',
                                    '1500' => '1.5 seconds (default)',
                                    '2000' => '2 seconds',
                                    '3000' => '3 seconds',
                                    '5000' => '5 seconds',
                                ] as $val => $label) : ?>
                                    <option value="<?php echo $val; ?>" <?php selected($s['delay'], $val); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="ccb-field">
                            <label for="ccb_position">Banner position</label>
                            <select name="<?php echo $opt; ?>[position]" id="ccb_position">
                                <option value="bottom" <?php selected($s['position'], 'bottom'); ?>>Bottom (recommended)</option>
                                <option value="top"    <?php selected($s['position'], 'top'); ?>>Top</option>
                            </select>
                        </div>

                        <div class="ccb-field">
                            <label for="ccb_expire_days">Consent cookie expires (days)</label>
                            <input type="number" name="<?php echo $opt; ?>[expire_days]" id="ccb_expire_days"
                                   value="<?php echo esc_attr($s['expire_days']); ?>" min="1" max="730">
                            <p class="description">180 days is standard. Max 730 (2 years).</p>
                        </div>

                        <div class="ccb-toggle-row">
                            <input type="checkbox" id="ccb_decline_button" name="<?php echo $opt; ?>[decline_button]" value="1"
                                <?php checked($s['decline_button'], '1'); ?>>
                            <label for="ccb_decline_button">Show Decline button</label>
                        </div>

                        <div class="ccb-toggle-row">
                            <input type="checkbox" id="ccb_script_blocking" name="<?php echo $opt; ?>[script_blocking]" value="1"
                                <?php checked($s['script_blocking'], '1'); ?>>
                            <label for="ccb_script_blocking">Enable GDPR script blocking</label>
                        </div>
                    </div>

                    <div class="ccb-card">
                        <h3>💬 Content</h3>
                        <div class="ccb-field">
                            <label for="ccb_message">Banner message</label>
                            <textarea name="<?php echo $opt; ?>[message]" id="ccb_message"><?php echo esc_textarea($s['message']); ?></textarea>
                        </div>
                        <div class="ccb-field">
                            <label for="ccb_accept_label">Accept button label</label>
                            <input type="text" name="<?php echo $opt; ?>[accept_label]" id="ccb_accept_label"
                                   value="<?php echo esc_attr($s['accept_label']); ?>">
                        </div>
                        <div class="ccb-field">
                            <label for="ccb_decline_label">Decline button label</label>
                            <input type="text" name="<?php echo $opt; ?>[decline_label]" id="ccb_decline_label"
                                   value="<?php echo esc_attr($s['decline_label']); ?>">
                        </div>
                        <div class="ccb-field">
                            <label for="ccb_privacy_url">Privacy Policy Page <em style="font-weight:400;">(optional)</em></label>
                            <?php
                            // Build page picker — native WP pages dropdown
                            $ccb_pages     = get_pages( [ 'sort_column' => 'post_title', 'sort_order' => 'ASC' ] );
                            $ccb_saved_url = $s['privacy_url'] ?? '';
                            // If legacy value is a URL string, try to match it to a page
                            $ccb_saved_id  = 0;
                            if ( is_numeric( $ccb_saved_url ) && (int) $ccb_saved_url > 0 ) {
                                $ccb_saved_id = (int) $ccb_saved_url;
                            } elseif ( ! empty( $ccb_saved_url ) ) {
                                // Legacy URL — try reverse-lookup by permalink
                                $ccb_legacy_id = url_to_postid( $ccb_saved_url );
                                $ccb_saved_id  = $ccb_legacy_id ?: 0;
                            }
                            ?>
                            <select name="<?php echo $opt; ?>[privacy_url]" id="ccb_privacy_url"
                                    style="max-width:100%;">
                                <option value="0"><?php esc_html_e( '— None —', 'aintelligize' ); ?></option>
                                <?php foreach ( $ccb_pages as $ccb_page ) : ?>
                                    <option value="<?php echo esc_attr( $ccb_page->ID ); ?>"
                                        <?php selected( $ccb_saved_id, $ccb_page->ID ); ?>>
                                        <?php echo esc_html( $ccb_page->post_title ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ( $ccb_saved_id > 0 ) : ?>
                                <p class="description" style="margin-top:4px;">
                                    ↗ <a href="<?php echo esc_url( get_permalink( $ccb_saved_id ) ); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_url( get_permalink( $ccb_saved_id ) ); ?>
                                    </a>
                                </p>
                            <?php endif; ?>
                        </div>
                        <div class="ccb-field">
                            <label for="ccb_privacy_label">Privacy link label</label>
                            <input type="text" name="<?php echo $opt; ?>[privacy_label]" id="ccb_privacy_label"
                                   value="<?php echo esc_attr($s['privacy_label']); ?>">
                        </div>
                    </div>

                    <div class="ccb-card">
                        <h3>🎨 Appearance</h3>
                        <div class="ccb-field">
                            <label for="ccb_theme">Theme</label>
                            <select name="<?php echo $opt; ?>[theme]" id="ccb_theme">
                                <option value="dark"     <?php selected($s['theme'], 'dark'); ?>>Dark</option>
                                <option value="light"    <?php selected($s['theme'], 'light'); ?>>Light</option>
                                <option value="glass"    <?php selected($s['theme'], 'glass'); ?>>Glassmorphism</option>
                                <option value="minimal"  <?php selected($s['theme'], 'minimal'); ?>>Minimal</option>
                                <option value="branded"  <?php selected($s['theme'], 'branded'); ?>>Branded (custom colors)</option>
                            </select>
                        </div>

                        <div id="ccb-branded-fields" style="<?php echo $s['theme'] !== 'branded' ? 'display:none;' : ''; ?>">
                            <?php
                            $color_fields = [
                                'banner_bg'          => ['Banner background',   $s['banner_bg']],
                                'banner_text_color'  => ['Banner text color',   $s['banner_text_color']],
                                'button_bg'          => ['Button background',   $s['button_bg']],
                                'button_text_color'  => ['Button text color',   $s['button_text_color']],
                            ];
                            foreach ( $color_fields as $field => [$label, $value] ) : ?>
                                <div class="ccb-field">
                                    <label><?php echo esc_html($label); ?></label>
                                    <div class="ccb-color-row">
                                        <input type="color" id="ccb_<?php echo $field; ?>"
                                               name="<?php echo $opt; ?>[<?php echo $field; ?>]"
                                               value="<?php echo esc_attr($value); ?>">
                                        <input type="text"
                                               value="<?php echo esc_attr($value); ?>"
                                               oninput="document.getElementById('ccb_<?php echo $field; ?>').value=this.value"
                                               onchange="document.getElementById('ccb_<?php echo $field; ?>').dispatchEvent(new Event('input'))">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php submit_button('Save Cookie Consent Settings', 'primary', 'ccb_save_submit', false); ?>
                </div>

                <!-- RIGHT: Live Preview -->
                <div>
                    <div class="ccb-card" style="position:sticky; top:32px;">
                        <h3>👁️ Live Preview</h3>
                        <p style="font-size:12px; color:#777; margin-top:-10px; margin-bottom:14px;">
                            Updates as you change settings — this is how it looks on the frontend.
                        </p>

                        <!-- Mobile preview frame -->
                        <div style="margin-bottom:16px;">
                            <div style="width:320px; margin:0 auto; border:2px solid #ccc; border-radius:16px; overflow:hidden; background:#f5f5f5;">
                                <div style="background:#e0e0e0; height:28px; display:flex; align-items:center; padding:0 12px; gap:6px;">
                                    <div style="width:8px;height:8px;border-radius:50%;background:#ff5f57;"></div>
                                    <div style="width:8px;height:8px;border-radius:50%;background:#ffbd2e;"></div>
                                    <div style="width:8px;height:8px;border-radius:50%;background:#28ca41;"></div>
                                </div>
                                <div style="background:#fff; min-height:100px; padding:12px; font-size:12px; color:#aaa; text-align:center; padding-top:30px;">
                                    Page content preview
                                </div>
                                <div class="ccb-preview-wrap" style="padding:0; border-radius:0; background:transparent;">
                                    <!-- Preview banner (position/transform overridden by admin JS) -->
                                    <div id="ccb-preview-banner"
                                         class="ccb-theme-<?php echo esc_attr($s['theme']); ?> ccb-pos-<?php echo esc_attr($s['position']); ?>"
                                         <?php if ($s['theme'] === 'branded') : ?>
                                         style="--ccb-bg:<?php echo esc_attr($s['banner_bg']); ?>; --ccb-text:<?php echo esc_attr($s['banner_text_color']); ?>; --ccb-btn-bg:<?php echo esc_attr($s['button_bg']); ?>; --ccb-btn-text:<?php echo esc_attr($s['button_text_color']); ?>;"
                                         <?php endif; ?>>
                                        <div class="ccb-content">
                                            <p class="ccb-message-text"><?php echo esc_html($s['message']); ?></p>
                                            <?php if ( ! empty($s['privacy_url']) ) : ?>
                                                <a href="#" class="ccb-privacy-link"><?php echo esc_html($s['privacy_label']); ?></a>
                                            <?php else : ?>
                                                <a href="#" class="ccb-privacy-link" style="display:none;"><?php echo esc_html($s['privacy_label']); ?></a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ccb-buttons">
                                            <button type="button" class="ccb-btn ccb-btn-accept"><?php echo esc_html($s['accept_label']); ?></button>
                                            <button type="button" class="ccb-btn ccb-btn-decline" <?php echo $s['decline_button'] !== '1' ? 'style="display:none;"' : ''; ?>><?php echo esc_html($s['decline_label']); ?></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <p style="text-align:center; font-size:11px; color:#aaa; margin-top:8px;">Mobile preview (320px)</p>
                        </div>

                        <!-- Desktop preview -->
                        <div class="ccb-preview-wrap">
                            <div id="ccb-preview-banner-desktop"
                                 class="ccb-theme-<?php echo esc_attr($s['theme']); ?>"
                                 style="width:100%; border-radius:6px; display:flex; flex-wrap:wrap; gap:14px; padding:14px 18px; align-items:center; justify-content:space-between; box-sizing:border-box;"
                                 <?php if ($s['theme'] === 'branded') : ?>
                                 style="--ccb-bg:<?php echo esc_attr($s['banner_bg']); ?>; --ccb-text:<?php echo esc_attr($s['banner_text_color']); ?>; --ccb-btn-bg:<?php echo esc_attr($s['button_bg']); ?>; --ccb-btn-text:<?php echo esc_attr($s['button_text_color']); ?>;"
                                 <?php endif; ?>>
                                <div class="ccb-content">
                                    <p class="ccb-message-text" style="margin:0;"><?php echo esc_html($s['message']); ?></p>
                                </div>
                                <div class="ccb-buttons">
                                    <button type="button" class="ccb-btn ccb-btn-accept"><?php echo esc_html($s['accept_label']); ?></button>
                                    <?php if ($s['decline_button'] === '1') : ?>
                                        <button type="button" class="ccb-btn ccb-btn-decline"><?php echo esc_html($s['decline_label']); ?></button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <p style="text-align:center; font-size:11px; color:#aaa; margin-top:8px;">Desktop preview</p>

                    </div>
                </div>

            </div><!-- /.ccb-grid -->
        </form>

        <?php
        /* ════════════════════════════════════════════
           SUBTAB: Script Blocking
        ═══════════════════════════════════════════ */
        elseif ( $subtab === 'blocking' ) : ?>

        <div class="ccb-card">
            <h3>🔒 GDPR Script Blocking — How It Works</h3>
            <p>When <strong>script blocking is enabled</strong>, tracking and analytics scripts won't load until the user accepts cookies.
            You must change <code>type="text/javascript"</code> to <code>type="text/plain"</code> and add a <code>data-ccb-category</code> attribute.</p>

            <h4 style="margin-top:18px;">Script Categories</h4>
            <table class="widefat striped" style="max-width:500px;">
                <thead><tr><th>Category</th><th>Fires on</th><th>Use for</th></tr></thead>
                <tbody>
                    <tr><td><code>analytics</code></td><td>Accept only</td><td>Google Analytics, Clarity, Hotjar</td></tr>
                    <tr><td><code>marketing</code></td><td>Accept only</td><td>Meta Pixel, Google Ads</td></tr>
                    <tr><td><code>functional</code></td><td>Accept <em>and</em> Decline</td><td>Livechat, essential widgets</td></tr>
                </tbody>
            </table>

            <h4 style="margin-top:22px;">Usage Examples</h4>

            <p><strong>Inline script:</strong></p>
            <div class="ccb-code"><?php echo esc_html(
'<!-- BEFORE -->
<script>
  // Google Analytics
  window.dataLayer = window.dataLayer || [];
</script>

<!-- AFTER (blocked until consent) -->
<script type="text/plain" data-ccb-category="analytics">
  window.dataLayer = window.dataLayer || [];
</script>'
            ); ?></div>

            <p style="margin-top:16px;"><strong>External src script:</strong></p>
            <div class="ccb-code"><?php echo esc_html(
'<!-- BEFORE -->
<script src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXX"></script>

<!-- AFTER -->
<script type="text/plain"
        data-ccb-category="analytics"
        src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXX">
</script>'
            ); ?></div>

            <p style="margin-top:16px;"><strong>WordPress (functions.php / plugin):</strong></p>
            <div class="ccb-code"><?php echo esc_html(
'// Dequeue default enqueueing, output as blocked script:
add_action(\'wp_head\', function() {
    echo \'<script type="text/plain" data-ccb-category="analytics"
             src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXX"></script>\';
}, 1);'
            ); ?></div>

            <div class="notice notice-info inline" style="margin-top:18px;">
                <p>💡 <strong>Pro tip:</strong> If you use a caching plugin (WP Rocket, LiteSpeed, etc.), make sure your
                cache does not pre-load blocked scripts. The <code>type="text/plain"</code> attribute prevents browsers from executing them on initial load.</p>
            </div>
        </div>

        <?php
        /* ════════════════════════════════════════════
           SUBTAB: Usage & Docs
        ═══════════════════════════════════════════ */
        elseif ( $subtab === 'docs' ) : ?>

        <div class="ccb-card">
            <h3>📖 Quick Reference</h3>

            <h4>Cookie stored</h4>
            <p>The banner sets a cookie named <code>aintelligize_consent</code> with a value of <code>accepted</code> or <code>declined</code>.</p>

            <h4 style="margin-top:18px;">Delay option</h4>
            <p>The delay is how long (in milliseconds) after the page loads before the banner slides in. A 1–2 second delay feels natural and non-intrusive.</p>

            <h4 style="margin-top:18px;">Themes</h4>
            <table class="widefat striped" style="max-width:480px;">
                <thead><tr><th>Theme</th><th>Best for</th></tr></thead>
                <tbody>
                    <tr><td>Dark</td><td>Modern dark-mode sites</td></tr>
                    <tr><td>Light</td><td>Clean white/light sites</td></tr>
                    <tr><td>Glassmorphism</td><td>Bold hero images, translucent feel</td></tr>
                    <tr><td>Minimal</td><td>Typography-first, bare-bones sites</td></tr>
                    <tr><td>Branded</td><td>Full custom colors to match brand</td></tr>
                </tbody>
            </table>

            <h4 style="margin-top:18px;">JavaScript events</h4>
            <p>You can listen for these custom events on <code>document</code> in your own scripts:</p>
            <div class="ccb-code"><?php echo esc_html(
'document.addEventListener(\'ccb:accepted\', function() {
    // User accepted — fire your own tracking setup here
    console.log(\'Consent accepted\');
});

document.addEventListener(\'ccb:declined\', function() {
    // User declined — suppress optional scripts
    console.log(\'Consent declined\');
});'
            ); ?></div>

            <h4 style="margin-top:18px;">Re-show the banner (testing)</h4>
            <p>To reset consent and see the banner again, run this in your browser console:</p>
            <div class="ccb-code"><?php echo esc_html("document.cookie = 'aintelligize_consent=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';\nlocation.reload();"); ?></div>
        </div>

        <?php endif; ?>

        </div><!-- /.wrap -->
        <?php
    },
]);
