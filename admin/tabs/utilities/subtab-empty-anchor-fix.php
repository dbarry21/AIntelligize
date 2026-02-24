<?php
/**
 * Utilities > Empty Anchor Fix
 *
 * Location: admin/tabs/utilities/subtab-empty-anchor-fix.php
 *
 * Toggle and preview the empty anchor text fixer.
 * Resolves SEMRush "Links with no anchor text" audit warnings.
 *
 * @since 4.12.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

return [
    'id'     => 'empty_anchor_fix',
    'label'  => 'Empty Anchor Fix',
    'order'  => 40,
    'render' => function() {

        if ( ! current_user_can( 'manage_options' ) ) {
            echo '<p class="muted">You do not have permission to edit this section.</p>';
            return;
        }

        // ── Save ──
        $saved = false;
        if ( isset( $_POST['myls_eaf_save'] ) ) {
            check_admin_referer( 'myls_eaf_save' );

            update_option( 'myls_empty_anchor_fix_enabled', ! empty( $_POST['myls_eaf_enabled'] ) ? '1' : '0' );
            $saved = true;
        }

        // ── Load current settings ──
        $enabled = get_option( 'myls_empty_anchor_fix_enabled', '0' ) === '1';
        $site_url = home_url( '/' );

        ?>
        <?php if ( $saved ) : ?>
            <div class="notice notice-success" style="margin:0 0 16px 0;"><p>Settings saved.</p></div>
        <?php endif; ?>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px; align-items:start;">

            <!-- Left Column: Settings -->
            <div class="cardish">
                <h3 style="margin:0 0 6px 0;">
                    <span class="dashicons dashicons-admin-links" style="color:#0d6efd;"></span>
                    Empty Anchor Fix
                </h3>
                <p class="muted" style="margin:0 0 16px 0;">
                    Automatically adds <code>aria-label</code> attributes to links that have no visible anchor text.
                    Resolves SEMRush, Ahrefs, and Screaming Frog "Links with no anchor text" warnings.
                </p>

                <form method="post">
                    <?php wp_nonce_field( 'myls_eaf_save' ); ?>

                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; margin-bottom:16px;">
                        <input type="checkbox" name="myls_eaf_enabled" value="1" <?php checked( $enabled ); ?>>
                        <strong>Enable Empty Anchor Fix</strong>
                    </label>

                    <div style="background:#f0f7ff; border:1px solid #b6d4fe; border-radius:8px; padding:12px; margin-bottom:16px;">
                        <strong>How it works:</strong>
                        <ul style="margin:8px 0 0 18px; padding:0;">
                            <li>Runs on the frontend only (no admin impact)</li>
                            <li>Processes the final HTML via output buffer</li>
                            <li>Detects <code>&lt;a&gt;</code> tags with no visible text</li>
                            <li>Generates <code>aria-label</code> from context:
                                <ol style="margin:4px 0 0 18px;">
                                    <li><code>title</code> attribute</li>
                                    <li>Humanized URL slug</li>
                                    <li>Image <code>alt</code> text</li>
                                    <li><code>data-label</code> attribute</li>
                                </ol>
                            </li>
                            <li>Also fixes images inside empty links (adds <code>alt</code>)</li>
                        </ul>
                    </div>

                    <button type="submit" name="myls_eaf_save" class="btn btn-primary">Save Settings</button>
                </form>
            </div>

            <!-- Right Column: Examples & Verification -->
            <div class="cardish">
                <h3 style="margin:0 0 6px 0;">
                    <span class="dashicons dashicons-visibility" style="color:#198754;"></span>
                    What Gets Fixed
                </h3>
                <p class="muted" style="margin:0 0 12px 0;">
                    Common patterns this module catches and repairs automatically:
                </p>

                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="border-bottom:2px solid #dee2e6;">
                            <th style="text-align:left; padding:8px 6px;">Pattern</th>
                            <th style="text-align:left; padding:8px 6px;">Generated Label</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:8px 6px;"><code>&lt;a href="tel:813-935-6644"&gt;&lt;/a&gt;</code></td>
                            <td style="padding:8px 6px;"><span style="color:#198754;">Call 813-935-6644</span></td>
                        </tr>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:8px 6px;"><code>&lt;a href="/get-a-free-estimate/"&gt;&lt;/a&gt;</code></td>
                            <td style="padding:8px 6px;"><span style="color:#198754;">Get a Free Estimate</span></td>
                        </tr>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:8px 6px;"><code>&lt;a href="/contact-us/"&gt;&lt;/a&gt;</code></td>
                            <td style="padding:8px 6px;"><span style="color:#198754;">Contact Us</span></td>
                        </tr>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:8px 6px;"><code>&lt;a href="https://facebook.com/..." title="Follow on Facebook"&gt;[icon]&lt;/a&gt;</code></td>
                            <td style="padding:8px 6px;"><span style="color:#198754;">Follow on Facebook</span></td>
                        </tr>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:8px 6px;"><code>&lt;a href="mailto:info@site.com"&gt;&lt;/a&gt;</code></td>
                            <td style="padding:8px 6px;"><span style="color:#198754;">Email info@site.com</span></td>
                        </tr>
                        <tr>
                            <td style="padding:8px 6px;"><code>&lt;a href="/service/ac-repair/"&gt;&lt;img alt=""&gt;&lt;/a&gt;</code></td>
                            <td style="padding:8px 6px;"><span style="color:#198754;">AC Repair</span></td>
                        </tr>
                    </tbody>
                </table>

                <?php if ( $enabled ) : ?>
                    <div style="background:#d1e7dd; border:1px solid #a3cfbb; border-radius:8px; padding:12px; margin-top:16px;">
                        <strong style="color:#0f5132;">✓ Active</strong> — 
                        Verify by viewing page source and searching for:<br>
                        <code>&lt;!-- MYLS Empty Anchor Fix</code>
                    </div>
                <?php else : ?>
                    <div style="background:#fff3cd; border:1px solid #ffc107; border-radius:8px; padding:12px; margin-top:16px;">
                        <strong style="color:#664d03;">○ Inactive</strong> — 
                        Enable the fix and re-run your SEMRush site audit to verify.
                    </div>
                <?php endif; ?>
            </div>

        </div>
        <?php
    },
];
