<?php
/**
 * AIntelligize – Cookie Consent Module
 * File: modules/cookie-consent/cookie-consent.php
 *
 * Handles:
 *  - Option registration & defaults
 *  - Frontend asset enqueueing
 *  - Banner HTML output (wp_footer)
 *  - Admin asset enqueueing (for preview)
 */

if ( ! defined('ABSPATH') ) exit;

/* ── Constants ─────────────────────────────────────────────────── */
define( 'CCB_OPTION',  'aintelligize_cookie_consent' );
define( 'CCB_VERSION', MYLS_VERSION );

/* ── Default settings ──────────────────────────────────────────── */
function ccb_defaults(): array {
    return [
        'enabled'            => '1',
        'delay'              => '1500',       // ms before banner appears
        'position'           => 'bottom',     // top | bottom
        'theme'              => 'dark',       // dark | light | glass | minimal | branded
        'banner_bg'          => '#1a1a2e',
        'banner_text_color'  => '#e8e8f0',
        'button_bg'          => '#4f8ef7',
        'button_text_color'  => '#ffffff',
        'message'            => 'We use cookies to improve your browsing experience, serve personalized content, and analyze our traffic.',
        'accept_label'       => 'Accept All',
        'decline_button'     => '1',          // show decline button
        'decline_label'      => 'Decline',
        'privacy_url'        => '',
        'privacy_label'      => 'Privacy Policy',
        'expire_days'        => '180',
        'script_blocking'    => '1',          // GDPR-level script blocking
    ];
}

/**
 * Get all settings merged with defaults.
 */
function ccb_get_settings(): array {
    $saved = get_option( CCB_OPTION, [] );
    return wp_parse_args( is_array($saved) ? $saved : [], ccb_defaults() );
}

/**
 * Get a single setting value.
 */
function ccb_get( string $key, $fallback = null ) {
    $settings = ccb_get_settings();
    return $settings[ $key ] ?? ( $fallback ?? ( ccb_defaults()[ $key ] ?? null ) );
}

/* ── Register settings ─────────────────────────────────────────── */
add_action( 'admin_init', function () {
    register_setting( 'aintelligize_ccb_group', CCB_OPTION, [
        'sanitize_callback' => 'ccb_sanitize_settings',
        'default'           => ccb_defaults(),
    ]);
});

function ccb_sanitize_settings( $input ): array {
    if ( ! is_array($input) ) return ccb_defaults();

    $defaults  = ccb_defaults();
    $sanitized = [];

    $sanitized['enabled']           = ! empty($input['enabled']) ? '1' : '0';
    $sanitized['delay']             = in_array( (int)($input['delay'] ?? 1500), [0,500,1000,1500,2000,3000,5000] )
                                        ? (string)(int)$input['delay'] : '1500';
    $sanitized['position']          = in_array( $input['position'] ?? 'bottom', ['top','bottom'] )
                                        ? $input['position'] : 'bottom';
    $sanitized['theme']             = in_array( $input['theme'] ?? 'dark', ['dark','light','glass','minimal','branded'] )
                                        ? $input['theme'] : 'dark';
    $sanitized['banner_bg']         = sanitize_hex_color( $input['banner_bg']         ?? $defaults['banner_bg'] )         ?? $defaults['banner_bg'];
    $sanitized['banner_text_color'] = sanitize_hex_color( $input['banner_text_color'] ?? $defaults['banner_text_color'] ) ?? $defaults['banner_text_color'];
    $sanitized['button_bg']         = sanitize_hex_color( $input['button_bg']         ?? $defaults['button_bg'] )         ?? $defaults['button_bg'];
    $sanitized['button_text_color'] = sanitize_hex_color( $input['button_text_color'] ?? $defaults['button_text_color'] ) ?? $defaults['button_text_color'];
    $sanitized['message']           = sanitize_textarea_field( $input['message']       ?? $defaults['message'] );
    $sanitized['accept_label']      = sanitize_text_field( $input['accept_label']      ?? $defaults['accept_label'] );
    $sanitized['decline_button']    = ! empty($input['decline_button']) ? '1' : '0';
    $sanitized['decline_label']     = sanitize_text_field( $input['decline_label']     ?? $defaults['decline_label'] );
    // Store page ID (int); 0 = none. Backward-compat: if legacy URL string passed, store as-is.
    $raw_purl = $input['privacy_url'] ?? '';
    $sanitized['privacy_url'] = is_numeric( $raw_purl ) ? absint( $raw_purl ) : esc_url_raw( $raw_purl );
    $sanitized['privacy_label']     = sanitize_text_field( $input['privacy_label']     ?? $defaults['privacy_label'] );
    $expire = (int)($input['expire_days'] ?? 180);
    $sanitized['expire_days']       = ( $expire > 0 && $expire <= 730 ) ? (string)$expire : '180';
    $sanitized['script_blocking']   = ! empty($input['script_blocking']) ? '1' : '0';

    return $sanitized;
}

/* ── Frontend: Enqueue assets ──────────────────────────────────── */
add_action( 'wp_enqueue_scripts', function () {
    if ( is_admin() ) return;
    if ( ccb_get('enabled') !== '1' ) return;

    $ver = CCB_VERSION;
    $url = trailingslashit(MYLS_URL) . 'modules/cookie-consent/';

    wp_enqueue_style(
        'aintelligize-ccb',
        $url . 'cookie-consent-frontend.css',
        [],
        $ver
    );

    wp_enqueue_script(
        'aintelligize-ccb',
        $url . 'cookie-consent-frontend.js',
        [],
        $ver,
        true   // footer
    );

    // Pass config to JS
    wp_localize_script( 'aintelligize-ccb', 'aintelligize_ccb', [
        'cookie_name'    => 'aintelligize_consent',
        'delay'          => ccb_get('delay'),
        'expire_days'    => ccb_get('expire_days'),
        'script_blocking'=> ccb_get('script_blocking'),
    ]);
});

/* ── Frontend: Banner HTML ─────────────────────────────────────── */
add_action( 'wp_footer', function () {
    if ( is_admin() ) return;
    if ( ccb_get('enabled') !== '1' ) return;

    $s       = ccb_get_settings();
    $theme   = esc_attr( $s['theme'] );
    $pos     = esc_attr( $s['position'] );
    $message = esc_html( $s['message'] );
    $accept  = esc_html( $s['accept_label'] );
    $decline = esc_html( $s['decline_label'] );

    // CSS vars for branded theme
    $inline_style = '';
    if ( $s['theme'] === 'branded' ) {
        $inline_style = sprintf(
            ' style="--ccb-bg:%s; --ccb-text:%s; --ccb-btn-bg:%s; --ccb-btn-text:%s;"',
            esc_attr( $s['banner_bg'] ),
            esc_attr( $s['banner_text_color'] ),
            esc_attr( $s['button_bg'] ),
            esc_attr( $s['button_text_color'] )
        );
    }

    ?>
    <div id="aintelligize-ccb"
         class="ccb-theme-<?php echo $theme; ?> ccb-pos-<?php echo $pos; ?>"
         role="dialog"
         aria-live="polite"
         aria-label="Cookie consent"
         <?php echo $inline_style; ?>>

        <div class="ccb-content">
            <p class="ccb-message-text"><?php echo $message; ?></p>
            <?php if ( ! empty($s['privacy_url']) ) : ?>
                <?php
                // Resolve: page ID -> permalink; URL string -> use directly (legacy)
                $ccb_purl = $s['privacy_url'] ?? '';
                $ccb_href = ( is_numeric( $ccb_purl ) && (int) $ccb_purl > 0 )
                    ? get_permalink( (int) $ccb_purl )
                    : ( $ccb_purl ?: '' );
                ?>
                <a href="<?php echo esc_url( $ccb_href ); ?>"
                   class="ccb-privacy-link"
                   target="_blank"
                   rel="noopener noreferrer">
                    <?php echo esc_html( $s['privacy_label'] ); ?>
                </a>
            <?php endif; ?>
        </div>

        <div class="ccb-buttons">
            <button id="ccb-accept" class="ccb-btn ccb-btn-accept" type="button">
                <?php echo $accept; ?>
            </button>
            <?php if ( $s['decline_button'] === '1' ) : ?>
                <button id="ccb-decline" class="ccb-btn ccb-btn-decline" type="button">
                    <?php echo $decline; ?>
                </button>
            <?php endif; ?>
        </div>

    </div>
    <?php
}, 99 );

/* ── Admin: enqueue preview assets on cookie consent tab ───────── */
add_action( 'admin_enqueue_scripts', function () {
    if ( ! myls_is_our_admin_page() ) return;
    if ( ( $_GET['tab'] ?? '' ) !== 'cookie-consent' ) return;

    $ver = CCB_VERSION;
    $url = trailingslashit(MYLS_URL) . 'modules/cookie-consent/';

    // Load frontend CSS in admin for the preview widget
    wp_enqueue_style(
        'aintelligize-ccb-preview',
        $url . 'cookie-consent-frontend.css',
        [],
        $ver
    );

    wp_enqueue_script(
        'aintelligize-ccb-admin',
        $url . 'cookie-consent-admin.js',
        [],
        $ver,
        true
    );
});
