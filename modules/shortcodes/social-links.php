<?php
/**
 * Shortcode: [social_links]
 *
 * Displays circular branded social-media icons linked to the
 * Organization schema sameAs / social profile URLs.
 *
 * Usage:
 *   [social_links]                        // defaults – all saved profiles
 *   [social_links size="44"]              // icon diameter in px
 *   [social_links gap="12"]               // space between icons in px
 *   [social_links align="center"]         // left | center | right
 *   [social_links target="_blank"]        // link target
 *   [social_links new_tab="1"]            // alias for target="_blank" (default on)
 *   [social_links style="color"]          // color (branded circles) | mono-dark | mono-light
 *   [social_links platforms="facebook,instagram,youtube"]  // show only these
 *   [social_links exclude="tiktok"]       // hide specific platforms
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('myls_social_links_shortcode') ) {

  /**
   * Platform detection map – order matters (most specific first).
   * Each entry: [ url_match, slug, label, icon_svg, bg_color ]
   *
   * SVG icons are simple 24×24 path-based icons (no external deps).
   */
  function myls_social_platform_defs() {
    return [
      // ── Major platforms ──
      [
        'match'  => ['facebook.com','fb.com','fb.me'],
        'slug'   => 'facebook',
        'label'  => 'Facebook',
        'bg'     => '#1877F2',
        'svg'    => '<path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073c0 6.026 4.388 11.022 10.125 11.927v-8.437H7.078v-3.49h3.047V9.413c0-3.025 1.792-4.697 4.533-4.697 1.313 0 2.686.236 2.686.236v2.971h-1.513c-1.49 0-1.953.93-1.953 1.884v2.266h3.328l-.532 3.49H13.88v8.437C19.612 23.095 24 18.1 24 12.073z" fill="white"/>',
      ],
      [
        'match'  => ['instagram.com'],
        'slug'   => 'instagram',
        'label'  => 'Instagram',
        'bg'     => 'linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888)',
        'svg'    => '<path d="M12 2.163c3.204 0 3.584.012 4.85.07 1.17.054 1.97.24 2.43.403a4.08 4.08 0 011.47.958c.453.453.768.898.958 1.47.163.46.35 1.26.403 2.43.058 1.266.07 1.646.07 4.85s-.012 3.584-.07 4.85c-.054 1.17-.24 1.97-.403 2.43a4.08 4.08 0 01-.958 1.47 4.08 4.08 0 01-1.47.958c-.46.163-1.26.35-2.43.403-1.266.058-1.646.07-4.85.07s-3.584-.012-4.85-.07c-1.17-.054-1.97-.24-2.43-.403a4.08 4.08 0 01-1.47-.958 4.08 4.08 0 01-.958-1.47c-.163-.46-.35-1.26-.403-2.43C2.175 15.584 2.163 15.204 2.163 12s.012-3.584.07-4.85c.054-1.17.24-1.97.403-2.43a4.08 4.08 0 01.958-1.47 4.08 4.08 0 011.47-.958c.46-.163 1.26-.35 2.43-.403C8.416 2.175 8.796 2.163 12 2.163zM12 0C8.741 0 8.333.014 7.053.072 5.775.13 4.903.333 4.14.63a5.88 5.88 0 00-2.126 1.384A5.88 5.88 0 00.63 4.14C.333 4.903.13 5.775.072 7.053.014 8.333 0 8.741 0 12s.014 3.667.072 4.947c.058 1.278.261 2.15.558 2.913a5.88 5.88 0 001.384 2.126 5.88 5.88 0 002.126 1.384c.763.297 1.635.5 2.913.558C8.333 23.986 8.741 24 12 24s3.667-.014 4.947-.072c1.278-.058 2.15-.261 2.913-.558a5.88 5.88 0 002.126-1.384 5.88 5.88 0 001.384-2.126c.297-.763.5-1.635.558-2.913.058-1.28.072-1.688.072-4.947s-.014-3.667-.072-4.947c-.058-1.278-.261-2.15-.558-2.913a5.88 5.88 0 00-1.384-2.126A5.88 5.88 0 0019.86.63C19.097.333 18.225.13 16.947.072 15.667.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 11-2.88 0 1.44 1.44 0 012.88 0z" fill="white"/>',
      ],
      [
        'match'  => ['twitter.com','x.com'],
        'slug'   => 'x',
        'label'  => 'X',
        'bg'     => '#000000',
        'svg'    => '<path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z" fill="white"/>',
      ],
      [
        'match'  => ['youtube.com','youtu.be'],
        'slug'   => 'youtube',
        'label'  => 'YouTube',
        'bg'     => '#FF0000',
        'svg'    => '<path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z" fill="white"/>',
      ],
      [
        'match'  => ['linkedin.com'],
        'slug'   => 'linkedin',
        'label'  => 'LinkedIn',
        'bg'     => '#0A66C2',
        'svg'    => '<path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z" fill="white"/>',
      ],
      [
        'match'  => ['tiktok.com'],
        'slug'   => 'tiktok',
        'label'  => 'TikTok',
        'bg'     => '#000000',
        'svg'    => '<path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z" fill="white"/>',
      ],
      [
        'match'  => ['pinterest.com','pin.it'],
        'slug'   => 'pinterest',
        'label'  => 'Pinterest',
        'bg'     => '#E60023',
        'svg'    => '<path d="M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.162-.105-.949-.199-2.403.041-3.439.219-.937 1.406-5.957 1.406-5.957s-.359-.72-.359-1.781c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738a.36.36 0 01.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.631-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12.017 24c6.624 0 11.99-5.367 11.99-11.988C24.007 5.367 18.641 0 12.017 0z" fill="white"/>',
      ],
      [
        'match'  => ['yelp.com'],
        'slug'   => 'yelp',
        'label'  => 'Yelp',
        'bg'     => '#D32323',
        'svg'    => '<path d="M20.16 12.594l-4.995 1.433a.96.96 0 00-.596.624.981.981 0 00.134.852l3.092 4.29a.97.97 0 001.387.178 9.452 9.452 0 002.122-2.794.969.969 0 00-.37-1.278l-4.174-2.63a.11.11 0 01-.048-.09.112.112 0 01.075-.083l5.1-1.31a.97.97 0 00.691-1.202 9.378 9.378 0 00-1.315-3.126.97.97 0 00-1.412-.243l-4.1 2.933a.112.112 0 01-.181-.087V5.378a.97.97 0 00-.661-.929 9.44 9.44 0 00-3.39-.489.97.97 0 00-.92 1.063l.44 5.05a.112.112 0 01-.165.107L6.49 7.62a.97.97 0 00-1.371.303 9.404 9.404 0 00-1.12 3.2.97.97 0 00.738 1.148l5.08 1.11a.112.112 0 01.054.188l-3.734 3.56a.97.97 0 00-.08 1.4 9.444 9.444 0 002.61 2.27.97.97 0 001.312-.434l2.19-4.69a.112.112 0 01.2-.006l2.4 4.54" fill="white"/>',
      ],
      [
        'match'  => ['nextdoor.com'],
        'slug'   => 'nextdoor',
        'label'  => 'Nextdoor',
        'bg'     => '#00B246',
        'svg'    => '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm3.2 14.4h-2.4v-4.8c0-.66-.54-1.2-1.2-1.2s-1.2.54-1.2 1.2v4.8H8V11.6c0-2.21 1.79-4 4-4s4 1.79 4 4v4.8h-.8z" fill="white"/>',
      ],
      // ── Google profiles ──
      [
        'match'  => ['google.com/maps','goo.gl/maps','maps.google'],
        'slug'   => 'googlemaps',
        'label'  => 'Google Maps',
        'bg'     => '#4285F4',
        'svg'    => '<path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 110-5 2.5 2.5 0 010 5z" fill="white"/>',
      ],
      [
        'match'  => ['business.google.com','google.com/business','g.page'],
        'slug'   => 'googlebusiness',
        'label'  => 'Google Business',
        'bg'     => '#4285F4',
        'svg'    => '<path d="M22 9.74l-2-2V4h-3v.76L12 0 2 9.74h3V21h5.5v-5.5h3V21H19V9.74h3zM12 15a3 3 0 110-6 3 3 0 010 6z" fill="white"/>',
      ],
      [
        'match'  => ['google.com'],
        'slug'   => 'google',
        'label'  => 'Google',
        'bg'     => '#4285F4',
        'svg'    => '<path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="white"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="white"/><path d="M5.84 14.09A6.97 6.97 0 015.5 12c0-.72.12-1.42.34-2.09V7.07H2.18A11 11 0 001 12c0 1.78.43 3.46 1.18 4.93l3.66-2.84z" fill="white"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="white"/>',
      ],
      // ── Catch-all ──
      [
        'match'  => ['bbb.org'],
        'slug'   => 'bbb',
        'label'  => 'BBB',
        'bg'     => '#005A78',
        'svg'    => '<text x="12" y="16" text-anchor="middle" font-size="12" font-weight="bold" fill="white" font-family="Arial,sans-serif">BBB</text>',
      ],
      [
        'match'  => ['thumbtack.com'],
        'slug'   => 'thumbtack',
        'label'  => 'Thumbtack',
        'bg'     => '#009FD9',
        'svg'    => '<path d="M12 2L4 7v10l8 5 8-5V7l-8-5zm0 3.5L16.5 9 12 12.5 7.5 9 12 5.5z" fill="white"/>',
      ],
      [
        'match'  => ['angi.com','angieslist.com','homeadvisor.com'],
        'slug'   => 'angi',
        'label'  => 'Angi',
        'bg'     => '#FF6153',
        'svg'    => '<text x="12" y="16" text-anchor="middle" font-size="11" font-weight="bold" fill="white" font-family="Arial,sans-serif">angi</text>',
      ],
    ];
  }

  /**
   * Match a URL to a platform.
   */
  function myls_detect_social_platform( $url ) {
    $url_lower = strtolower( $url );
    foreach ( myls_social_platform_defs() as $def ) {
      foreach ( $def['match'] as $pattern ) {
        if ( strpos( $url_lower, $pattern ) !== false ) {
          return $def;
        }
      }
    }
    // Fallback: generic link
    return [
      'slug'  => 'website',
      'label' => parse_url( $url, PHP_URL_HOST ) ?: 'Website',
      'bg'    => '#555555',
      'svg'   => '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z" fill="white"/>',
    ];
  }

  /**
   * Shortcode handler.
   */
  function myls_social_links_shortcode( $atts = [] ) {

    $a = shortcode_atts( [
      'size'      => '44',
      'gap'       => '12',
      'align'     => 'center',   // left | center | right
      'target'    => '',
      'new_tab'   => '1',        // convenience alias
      'style'     => 'color',    // color | mono-dark | mono-light
      'platforms' => '',          // comma-separated whitelist
      'exclude'   => '',          // comma-separated blacklist
    ], $atts, 'social_links' );

    // Get social URLs from Organization schema settings
    $urls = (array) get_option( 'myls_org_social_profiles', [] );
    $urls = array_values( array_filter( array_map( 'trim', $urls ) ) );

    if ( empty( $urls ) ) {
      return current_user_can('manage_options')
        ? '<p style="color:red;"><strong>Social Links:</strong> No social profiles found. Add them in AIntelligize → Schema → Organization → Social Profiles.</p>'
        : '';
    }

    // Build platform list
    $whitelist = $a['platforms'] ? array_map( 'trim', explode( ',', strtolower( $a['platforms'] ) ) ) : [];
    $blacklist = $a['exclude']  ? array_map( 'trim', explode( ',', strtolower( $a['exclude'] ) ) )   : [];

    $items = [];
    foreach ( $urls as $url ) {
      $platform = myls_detect_social_platform( $url );
      $slug = $platform['slug'];

      // Whitelist check
      if ( ! empty( $whitelist ) && ! in_array( $slug, $whitelist, true ) ) continue;
      // Blacklist check
      if ( ! empty( $blacklist ) && in_array( $slug, $blacklist, true ) ) continue;

      $platform['url'] = $url;
      $items[] = $platform;
    }

    if ( empty( $items ) ) return '';

    // Settings
    $size   = max( 20, (int) $a['size'] );
    $gap    = max( 0, (int) $a['gap'] );
    $target = $a['target'] ?: ( $a['new_tab'] === '1' ? '_blank' : '_self' );
    $rel    = $target === '_blank' ? 'noopener noreferrer' : '';
    $style  = in_array( $a['style'], ['color','mono-dark','mono-light'], true ) ? $a['style'] : 'color';

    $align_map = [ 'left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end' ];
    $justify   = $align_map[ $a['align'] ] ?? 'center';

    ob_start();

    // ── Inline CSS (once) ──
    static $css_done = false;
    if ( ! $css_done ) {
      $css_done = true;
      ?>
      <style>
      /* === AIntelligize – Social Links === */
      .myls-sl{display:flex;flex-wrap:wrap;list-style:none;margin:0;padding:0}
      .myls-sl-link{
        display:flex;align-items:center;justify-content:center;
        border-radius:50%;text-decoration:none;
        transition:transform .2s ease,box-shadow .2s ease;
        overflow:hidden;
      }
      .myls-sl-link:hover{transform:scale(1.12);box-shadow:0 4px 14px rgba(0,0,0,.25)}
      .myls-sl-link svg{display:block}

      /* Mono overrides */
      .myls-sl--mono-dark .myls-sl-link{background:#333!important}
      .myls-sl--mono-light .myls-sl-link{background:#e8e8e8!important}
      .myls-sl--mono-light .myls-sl-link svg path,
      .myls-sl--mono-light .myls-sl-link svg text{fill:#333!important}
      </style>
      <?php
    }

    $wrap_class = 'myls-sl';
    if ( $style !== 'color' ) $wrap_class .= ' myls-sl--' . $style;

    echo '<ul class="' . esc_attr( $wrap_class ) . '" style="gap:' . esc_attr($gap) . 'px;justify-content:' . esc_attr($justify) . ';">';

    $icon_size = round( $size * 0.55 ); // SVG size relative to circle

    foreach ( $items as $item ) {
      $bg = $style === 'color' ? $item['bg'] : '';
      // Handle gradient vs solid backgrounds
      $bg_css = '';
      if ( $style === 'color' ) {
        if ( strpos( $item['bg'], 'gradient' ) !== false ) {
          $bg_css = 'background:' . $item['bg'] . ';';
        } else {
          $bg_css = 'background-color:' . esc_attr( $item['bg'] ) . ';';
        }
      }

      echo '<li>';
      echo '<a class="myls-sl-link" href="' . esc_url( $item['url'] ) . '"'
        . ' title="' . esc_attr( $item['label'] ) . '"'
        . ' aria-label="' . esc_attr( $item['label'] ) . '"'
        . ( $target ? ' target="' . esc_attr( $target ) . '"' : '' )
        . ( $rel    ? ' rel="' . esc_attr( $rel ) . '"' : '' )
        . ' style="width:' . esc_attr($size) . 'px;height:' . esc_attr($size) . 'px;' . $bg_css . '">';
      echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="' . esc_attr($icon_size) . '" height="' . esc_attr($icon_size) . '">'
        . $item['svg']
        . '</svg>';
      echo '</a>';
      echo '</li>';
    }

    echo '</ul>';

    return ob_get_clean();
  }
}

add_shortcode( 'social_links', 'myls_social_links_shortcode' );
