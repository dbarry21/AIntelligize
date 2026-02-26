<?php
/**
 * Elementor Site Analyzer
 * Path: inc/elementor-site-analyzer.php
 *
 * Reads two things before building a new page:
 *
 *  1. ELEMENTOR KIT SETTINGS  — active kit global colors, typography, container
 *     widths, button defaults. Source: elementor_active_kit → post meta.
 *
 *  2. SAMPLE PAGE STRUCTURE   — walks _elementor_data from up to 3 existing
 *     posts of the same type, extracts the widget types, section patterns,
 *     container settings, and image usage. Used to mimic consistency.
 *
 * Entry point:
 *   myls_elb_analyze_site( string $post_type ) : array
 *
 * Returns a $site_context array with:
 *   kit          array   — Global colors, typography, container width
 *   sample_pages array   — Per-page widget summaries
 *   patterns     array   — Aggregated: section order, widget types, image usage
 *   prompt_block string  — Ready-to-append prompt text for the AI
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* =========================================================================
 * KIT READER
 * Reads the active Elementor Kit post and extracts global design tokens.
 * ========================================================================= */

/**
 * Get the active Elementor kit post ID.
 *
 * @return int  0 if not found.
 */
function myls_elb_get_kit_id(): int {
    $kit_id = (int) get_option( 'elementor_active_kit', 0 );
    if ( $kit_id && get_post( $kit_id ) ) {
        return $kit_id;
    }
    // Fallback: find by post type
    $kits = get_posts( [
        'post_type'      => 'elementor_library',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'meta_key'       => '_elementor_template_type',
        'meta_value'     => 'kit',
        'fields'         => 'ids',
    ] );
    return ! empty( $kits ) ? (int) $kits[0] : 0;
}

/**
 * Read and parse Elementor kit global settings.
 *
 * @param int $kit_id  Kit post ID (0 = auto-detect).
 * @return array {
 *   container_width   int        Site default boxed container width in px
 *   colors            array[]    [ id, title, value ] global color entries
 *   typography        array[]    [ id, title, family, size, weight ] entries
 *   button_bg_color   string     Button background color value or global ref
 *   button_text_color string     Button text color value or global ref
 *   button_border_radius string  e.g. "4px"
 *   raw               array      Full parsed kit page settings
 * }
 */
function myls_elb_read_kit_settings( int $kit_id = 0 ): array {
    if ( ! $kit_id ) {
        $kit_id = myls_elb_get_kit_id();
    }
    if ( ! $kit_id ) {
        return myls_elb_kit_defaults();
    }

    $raw = get_post_meta( $kit_id, '_elementor_page_settings', true );
    if ( ! is_array( $raw ) ) {
        return myls_elb_kit_defaults();
    }

    // ── Container width ───────────────────────────────────────────────────
    // Kit stores this in 'container_width' as a size unit array
    $cw_raw = $raw['container_width'] ?? [];
    $container_width = isset( $cw_raw['size'] ) ? (int) $cw_raw['size'] : 1140;

    // ── Global colors ─────────────────────────────────────────────────────
    $colors = [];
    $color_entries = $raw['system_colors'] ?? $raw['colors'] ?? [];
    foreach ( (array) $color_entries as $entry ) {
        if ( ! is_array( $entry ) ) continue;
        $id    = sanitize_key( $entry['_id'] ?? '' );
        $title = sanitize_text_field( $entry['title'] ?? '' );
        $value = sanitize_hex_color( $entry['color'] ?? '' ) ?: ( $entry['color'] ?? '' );
        if ( $id && $value ) {
            $colors[] = [ 'id' => $id, 'title' => $title, 'value' => $value ];
        }
    }

    // ── Typography ────────────────────────────────────────────────────────
    $typography = [];
    $typo_entries = $raw['system_typography'] ?? $raw['typography'] ?? [];
    foreach ( (array) $typo_entries as $entry ) {
        if ( ! is_array( $entry ) ) continue;
        $id     = sanitize_key( $entry['_id'] ?? '' );
        $title  = sanitize_text_field( $entry['title'] ?? '' );
        $family = sanitize_text_field( $entry['typography_font_family'] ?? '' );
        $size   = $entry['typography_font_size']['size'] ?? '';
        $weight = $entry['typography_font_weight'] ?? '';
        if ( $id ) {
            $typography[] = [
                'id'     => $id,
                'title'  => $title,
                'family' => $family,
                'size'   => $size,
                'weight' => $weight,
            ];
        }
    }

    // ── Button defaults ───────────────────────────────────────────────────
    $btn_bg     = $raw['button_background_color'] ?? '';
    $btn_color  = $raw['button_text_color']       ?? '';
    $btn_radius_raw = $raw['button_border_radius'] ?? [];
    $btn_radius = '';
    if ( is_array( $btn_radius_raw ) ) {
        $sides = [ $btn_radius_raw['top'] ?? '', $btn_radius_raw['right'] ?? '',
                   $btn_radius_raw['bottom'] ?? '', $btn_radius_raw['left'] ?? '' ];
        $unit  = $btn_radius_raw['unit'] ?? 'px';
        $btn_radius = implode( ' ', array_map( fn($s) => $s !== '' ? $s . $unit : '0' . $unit, $sides ) );
    }
    if ( ! $btn_bg ) {
        // Check __globals__ fallback
        $btn_globals = $raw['__globals__'] ?? [];
        $btn_bg = $btn_globals['button_background_color'] ?? '';
    }

    return [
        'container_width'      => $container_width,
        'colors'               => $colors,
        'typography'           => $typography,
        'button_bg_color'      => $btn_bg,
        'button_text_color'    => $btn_color,
        'button_border_radius' => $btn_radius,
        'raw'                  => $raw,
    ];
}

/**
 * Safe defaults when no kit is found.
 */
function myls_elb_kit_defaults(): array {
    return [
        'container_width'      => 1140,
        'colors'               => [],
        'typography'           => [],
        'button_bg_color'      => '',
        'button_text_color'    => '',
        'button_border_radius' => '',
        'raw'                  => [],
    ];
}

/* =========================================================================
 * ELEMENTOR DATA WALKER
 * Recursively walks a parsed _elementor_data tree and extracts a flat
 * summary of every widget and container in the page.
 * ========================================================================= */

/**
 * Walk an Elementor elements array and return a flat list of widget summaries.
 *
 * Each entry in the returned array:
 *   elType       string   container | widget
 *   widgetType   string   heading | text-editor | button | icon-box | image | image-box | shortcode | html | ...
 *   depth        int      nesting depth (0 = top level container)
 *   settings     array    selected normalised settings (no huge HTML blobs)
 *   children     int      number of direct child elements
 *
 * @param array  $elements  Parsed Elementor elements array.
 * @param int    $depth     Current recursion depth.
 * @return array            Flat list of element summaries.
 */
function myls_elb_walk_elements( array $elements, int $depth = 0 ): array {
    $results = [];
    foreach ( $elements as $el ) {
        if ( ! is_array( $el ) ) continue;
        $el_type    = $el['elType']     ?? 'widget';
        $widget_type = $el['widgetType'] ?? '';
        $settings   = $el['settings']   ?? [];
        $children   = $el['elements']   ?? [];
        $child_types = array_column( $children, 'widgetType' );

        // Build a lean summary of the most useful settings
        $summary_settings = [];
        switch ( $widget_type ) {
            case 'heading':
                $summary_settings['text']  = mb_substr( wp_strip_all_tags( $settings['title'] ?? '' ), 0, 80 );
                $summary_settings['tag']   = $settings['header_size'] ?? 'h2';
                $summary_settings['align'] = $settings['align'] ?? 'left';
                break;

            case 'text-editor':
                $plain = wp_strip_all_tags( $settings['editor'] ?? '' );
                $summary_settings['text_preview'] = mb_substr( $plain, 0, 80 );
                break;

            case 'button':
                $summary_settings['text']      = $settings['text']           ?? '';
                $summary_settings['url']       = $settings['link']['url']    ?? '';
                $summary_settings['align']     = $settings['align']          ?? 'center';
                $summary_settings['has_global_color'] = ! empty( $settings['__globals__']['background_color'] );
                break;

            case 'icon-box':
                $summary_settings['title'] = $settings['title_text']  ?? '';
                $summary_settings['icon']  = $settings['selected_icon']['value'] ?? '';
                $summary_settings['view']  = $settings['view'] ?? 'traditional';
                break;

            case 'image':
            case 'image-box':
                $img = $settings['image'] ?? [];
                $summary_settings['has_image']    = ! empty( $img['url'] ) && ! str_contains( ($img['url'] ?? ''), 'placeholder' );
                $summary_settings['is_placeholder'] = str_contains( ($img['url'] ?? ''), 'placeholder' );
                $summary_settings['image_size']   = $settings['image_size']  ?? 'full';
                if ( $widget_type === 'image-box' ) {
                    $summary_settings['title'] = $settings['title_text'] ?? '';
                }
                break;

            case 'shortcode':
                $summary_settings['shortcode'] = mb_substr( $settings['shortcode'] ?? '', 0, 60 );
                break;

            case 'html':
                $plain = wp_strip_all_tags( $settings['html'] ?? '' );
                $summary_settings['html_preview'] = mb_substr( $plain, 0, 80 );
                break;

            default:
                // For unknown widgets, just note any text-like fields
                foreach ( [ 'title', 'text', 'content' ] as $f ) {
                    if ( ! empty( $settings[ $f ] ) ) {
                        $summary_settings[$f] = mb_substr( wp_strip_all_tags( (string) $settings[$f] ), 0, 60 );
                    }
                }
        }

        // Container-level settings summary
        if ( $el_type === 'container' ) {
            $summary_settings['bg_color']      = $settings['background_color'] ?? '';
            $summary_settings['content_width'] = $settings['content_width']    ?? 'full';
            $summary_settings['flex_direction'] = $settings['flex_direction']  ?? 'column';
            $summary_settings['is_inner']      = (bool) ( $el['isInner'] ?? false );
            $child_widget_types = array_filter( array_column( $children, 'widgetType' ) );
            $summary_settings['child_widgets'] = array_values( $child_widget_types );
        }

        $results[] = [
            'elType'     => $el_type,
            'widgetType' => $widget_type,
            'depth'      => $depth,
            'settings'   => $summary_settings,
            'children'   => count( $children ),
        ];

        // Recurse into children
        if ( ! empty( $children ) ) {
            $results = array_merge( $results, myls_elb_walk_elements( $children, $depth + 1 ) );
        }
    }
    return $results;
}

/* =========================================================================
 * SAMPLE PAGE READER
 * Gets up to $limit existing posts of the given type with Elementor data
 * and returns per-page widget summaries.
 * ========================================================================= */

/**
 * Read and analyse up to $limit posts of $post_type that have Elementor data.
 *
 * @param string $post_type
 * @param int    $limit      Max pages to sample (default 3).
 * @param int    $skip_id    Post ID to exclude (the page being generated).
 * @return array[]  Each entry: { post_id, title, url, elements }
 */
function myls_elb_sample_pages( string $post_type, int $limit = 3, int $skip_id = 0 ): array {
    $posts = get_posts( [
        'post_type'      => $post_type,
        'post_status'    => 'publish',
        'posts_per_page' => $limit + 2,  // fetch extra in case some lack Elementor data
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'     => '_elementor_edit_mode',
                'value'   => 'builder',
                'compare' => '=',
            ],
        ],
        'exclude'        => $skip_id ? [ $skip_id ] : [],
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ] );

    $sampled = [];
    foreach ( $posts as $pid ) {
        if ( count( $sampled ) >= $limit ) break;
        $raw_json = get_post_meta( (int) $pid, '_elementor_data', true );
        if ( empty( $raw_json ) ) continue;

        $elements = json_decode( $raw_json, true );
        if ( ! is_array( $elements ) || empty( $elements ) ) continue;

        $sampled[] = [
            'post_id'  => (int) $pid,
            'title'    => get_the_title( $pid ),
            'url'      => get_permalink( $pid ),
            'elements' => myls_elb_walk_elements( $elements ),
        ];
    }
    return $sampled;
}

/* =========================================================================
 * PATTERN AGGREGATOR
 * Reduces multiple sample page widget lists into a unified pattern summary
 * — which widget types appear most, whether images are used, section order, etc.
 * ========================================================================= */

/**
 * Aggregate sampled page data into a pattern summary.
 *
 * @param array[] $sample_pages  Output of myls_elb_sample_pages().
 * @return array {
 *   widget_freq          array   widgetType => count across all pages
 *   has_images           bool    Any image / image-box widgets present?
 *   image_depths         array   depth positions where images appear
 *   has_icon_boxes       bool
 *   has_image_boxes      bool
 *   top_level_sections   int     Average number of top-level containers
 *   section_bg_colors    array   Unique background colors used on sections
 *   section_bg_pattern   array   Ordered list of bg colors (to mimic alternating patterns)
 *   uses_shortcodes      array   Shortcode strings found
 *   common_btn_align     string  Most common button align
 *   avg_icon_box_count   int     Average icon boxes per features section
 *   has_hero             bool    Hero-like dark first section detected
 *   has_cta              bool    CTA-like dark final section detected
 *   has_faq              bool    FAQ shortcodes detected
 * }
 */
function myls_elb_aggregate_patterns( array $sample_pages ): array {
    $widget_freq      = [];
    $images_found     = false;
    $icon_box_count   = 0;
    $image_box_count  = 0;
    $image_depths     = [];
    $section_counts   = [];
    $bg_colors        = [];
    $bg_sequences     = [];
    $shortcodes       = [];
    $btn_aligns       = [];
    $icon_box_per_page = [];
    $has_hero         = false;
    $has_cta          = false;
    $has_faq          = false;

    foreach ( $sample_pages as $page ) {
        $elements        = $page['elements'] ?? [];
        $top_level       = 0;
        $page_icon_boxes = 0;
        $page_bg_seq     = [];

        foreach ( $elements as $el ) {
            $wt = $el['widgetType'] ?: $el['elType'];
            if ( $wt ) {
                $widget_freq[ $wt ] = ( $widget_freq[ $wt ] ?? 0 ) + 1;
            }

            if ( $el['elType'] === 'container' && $el['depth'] === 0 ) {
                $top_level++;
                $bg = $el['settings']['bg_color'] ?? '';
                if ( $bg ) {
                    $bg_colors[] = $bg;
                    $page_bg_seq[] = $bg;
                    // Detect hero: dark bg at position 1
                    if ( $top_level === 1 && myls_elb_is_dark_color( $bg ) ) {
                        $has_hero = true;
                    }
                    // Detect CTA: dark bg after section 3+
                    if ( $top_level > 3 && myls_elb_is_dark_color( $bg ) ) {
                        $has_cta = true;
                    }
                } else {
                    $page_bg_seq[] = '';
                }
            }

            if ( in_array( $el['widgetType'], [ 'image', 'image-box' ], true ) ) {
                $images_found = true;
                $image_depths[] = $el['depth'];
                if ( $el['widgetType'] === 'image-box' ) $image_box_count++;
            }

            if ( $el['widgetType'] === 'icon-box' ) {
                $icon_box_count++;
                $page_icon_boxes++;
            }

            if ( $el['widgetType'] === 'shortcode' ) {
                $sc = $el['settings']['shortcode'] ?? '';
                if ( $sc ) {
                    $shortcodes[] = $sc;
                    if ( str_contains( strtolower( $sc ), 'faq' ) ) {
                        $has_faq = true;
                    }
                }
            }

            if ( $el['widgetType'] === 'button' ) {
                $btn_aligns[] = $el['settings']['align'] ?? 'center';
            }
        }

        $section_counts[] = $top_level;
        if ( $page_icon_boxes ) $icon_box_per_page[] = $page_icon_boxes;
        if ( $page_bg_seq ) $bg_sequences[] = $page_bg_seq;
    }

    arsort( $widget_freq );

    // Most common button align
    $align_counts = array_count_values( $btn_aligns );
    arsort( $align_counts );
    $common_btn_align = array_key_first( $align_counts ) ?? 'center';

    // Unique bg colors (excluding empty)
    $unique_bg_colors = array_values( array_unique( array_filter( $bg_colors ) ) );

    // Background color sequence from the first sampled page — use to mimic alternating pattern
    $bg_pattern = ! empty( $bg_sequences ) ? $bg_sequences[0] : [];

    return [
        'widget_freq'        => $widget_freq,
        'has_images'         => $images_found,
        'has_icon_boxes'     => $icon_box_count > 0,
        'has_image_boxes'    => $image_box_count > 0,
        'image_depths'       => array_values( array_unique( $image_depths ) ),
        'top_level_sections' => $section_counts ? (int) round( array_sum( $section_counts ) / count( $section_counts ) ) : 6,
        'section_bg_colors'  => $unique_bg_colors,
        'section_bg_pattern' => $bg_pattern,
        'uses_shortcodes'    => array_values( array_unique( $shortcodes ) ),
        'common_btn_align'   => $common_btn_align,
        'avg_icon_box_count' => $icon_box_per_page ? (int) round( array_sum( $icon_box_per_page ) / count( $icon_box_per_page ) ) : 4,
        'has_hero'           => $has_hero,
        'has_cta'            => $has_cta,
        'has_faq'            => $has_faq,
    ];
}

/**
 * Determine if a hex color is dark (luminance < 0.35).
 * Used to detect hero/CTA sections from background colors.
 *
 * @param string $hex  Hex color e.g. '#1a2332'.
 * @return bool
 */
function myls_elb_is_dark_color( string $hex ): bool {
    $hex = ltrim( trim( $hex ), '#' );
    if ( strlen( $hex ) === 3 ) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    if ( strlen( $hex ) !== 6 ) return false;
    $r         = hexdec( substr( $hex, 0, 2 ) ) / 255;
    $g         = hexdec( substr( $hex, 2, 2 ) ) / 255;
    $b         = hexdec( substr( $hex, 4, 2 ) ) / 255;
    $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    return $luminance < 0.35;
}

/* =========================================================================
 * PROMPT BLOCK BUILDER
 * Converts kit + pattern data into a human-readable block appended to the
 * AI prompt so it generates content that matches the site's style.
 * ========================================================================= */

/**
 * Build the site-context block to append to the AI prompt.
 *
 * @param array $kit      Output of myls_elb_read_kit_settings().
 * @param array $patterns Output of myls_elb_aggregate_patterns().
 * @param array $sample_pages For referencing specific pages.
 * @return string
 */
function myls_elb_build_prompt_context_block( array $kit, array $patterns, array $sample_pages ): string {
    $lines = [];
    $lines[] = "\n\n---\nSITE CONTEXT — Match these patterns from the existing site:\n";

    // Container width
    $lines[] = "CONTAINER WIDTH: {$kit['container_width']}px (use this for boxed sections)";

    // Colors
    if ( ! empty( $kit['colors'] ) ) {
        $color_list = implode( ', ', array_map(
            fn($c) => "{$c['title']} ({$c['id']}): {$c['value']}",
            array_slice( $kit['colors'], 0, 6 )
        ) );
        $lines[] = "GLOBAL COLORS: {$color_list}";
    }

    // Typography
    $primary_typo = null;
    foreach ( $kit['typography'] as $t ) {
        if ( $t['family'] ) { $primary_typo = $t; break; }
    }
    if ( $primary_typo ) {
        $lines[] = "PRIMARY FONT: {$primary_typo['family']}" . ( $primary_typo['size'] ? " at {$primary_typo['size']}px" : '' );
    }

    // Widget patterns
    if ( ! empty( $patterns['widget_freq'] ) ) {
        $top_widgets = array_slice( $patterns['widget_freq'], 0, 8, true );
        $widget_list = implode( ', ', array_map(
            fn( $wt, $n ) => "{$wt} ×{$n}",
            array_keys( $top_widgets ), $top_widgets
        ) );
        $lines[] = "WIDGETS USED ON SITE: {$widget_list}";
    }

    // Section structure
    $lines[] = "SECTIONS: Existing pages average {$patterns['top_level_sections']} top-level sections.";
    if ( $patterns['has_hero'] ) {
        $lines[] = "HERO: Site uses a dark-background hero section as the first section — include hero.";
    }
    if ( $patterns['has_cta'] ) {
        $lines[] = "CTA: Site has a dark-background CTA section near the end — include cta.";
    }

    // Background color pattern
    if ( ! empty( $patterns['section_bg_pattern'] ) ) {
        $bg_str = implode( ' → ', array_filter( $patterns['section_bg_pattern'] ) );
        if ( $bg_str ) {
            $lines[] = "SECTION BACKGROUNDS (in order): {$bg_str} — alternate light/dark sections to match this pattern.";
        }
    }

    // Images
    if ( $patterns['has_images'] ) {
        $type = $patterns['has_image_boxes'] ? 'image-box widgets' : 'image widgets';
        $lines[] = "IMAGES: Existing pages use {$type}. Include image slots in similar positions.";
    } else {
        $lines[] = "IMAGES: Existing pages do not use image widgets in content sections.";
    }

    // Icon boxes
    if ( $patterns['has_icon_boxes'] ) {
        $avg = $patterns['avg_icon_box_count'];
        $lines[] = "ICON BOXES: Site uses icon-box widgets in feature/benefit sections. Avg {$avg} per page — use {$avg} items in the features array.";
    }

    // Shortcodes / FAQ
    if ( $patterns['has_faq'] ) {
        $lines[] = "FAQ: Site uses FAQ shortcodes — always include a faq section with 5 items.";
    }
    foreach ( $patterns['uses_shortcodes'] as $sc ) {
        if ( str_contains( $sc, 'faq' ) ) {
            $lines[] = "FAQ SHORTCODE: Site uses [{$sc}] for FAQs — include a faq section.";
        }
    }

    // Button alignment
    if ( ! empty( $patterns['common_btn_align'] ) ) {
        $lines[] = "BUTTON ALIGNMENT: Use '{$patterns['common_btn_align']}' alignment for buttons (matches existing pages).";
    }

    // Sample page titles
    if ( ! empty( $sample_pages ) ) {
        $titles = implode( ', ', array_map( fn($p) => "\"{$p['title']}\"", $sample_pages ) );
        $lines[] = "SAMPLED PAGES: {$titles}";
    }

    $lines[] = "\nMATCH the widget types and section structure of existing pages as closely as possible.";
    $lines[] = "If existing pages have image widgets, include an \"image\" key in each relevant section.";
    $lines[] = "---";

    return implode( "\n", $lines );
}

/* =========================================================================
 * MAIN ENTRY POINT
 * ========================================================================= */

/**
 * Analyze the site for a given post type.
 *
 * Returns everything needed to make generated pages consistent with existing ones.
 *
 * @param string $post_type  Post type being generated.
 * @param int    $skip_id    Post ID to exclude (the page being regenerated).
 * @return array {
 *   kit           array   Kit settings (container_width, colors, typography, etc.)
 *   sample_pages  array   Up to 3 sampled page widget summaries
 *   patterns      array   Aggregated pattern data
 *   prompt_block  string  Ready-to-append AI prompt context block
 *   log           array   Human-readable analysis log lines for results panel
 * }
 */
function myls_elb_analyze_site( string $post_type, int $skip_id = 0 ): array {
    $kit          = myls_elb_read_kit_settings();
    $sample_pages = myls_elb_sample_pages( $post_type, 3, $skip_id );
    $patterns     = myls_elb_aggregate_patterns( $sample_pages );
    $prompt_block = myls_elb_build_prompt_context_block( $kit, $patterns, $sample_pages );

    // Build log lines for the results panel
    $log = [];
    $log[] = '🔍 Site analysis:';
    $log[] = "   Kit: container width {$kit['container_width']}px, "
           . count( $kit['colors'] ) . ' global colors, '
           . count( $kit['typography'] ) . ' typography sets';

    if ( ! empty( $sample_pages ) ) {
        $log[] = '   Sampled ' . count( $sample_pages ) . ' existing ' . $post_type . ' page(s): '
               . implode( ', ', array_map( fn($p) => "\"{$p['title']}\"", $sample_pages ) );
    } else {
        $log[] = "   No existing {$post_type} pages with Elementor data found — using defaults";
    }

    if ( ! empty( $patterns['widget_freq'] ) ) {
        $top = array_slice( $patterns['widget_freq'], 0, 5, true );
        $log[] = '   Top widgets: ' . implode( ', ', array_map( fn($wt, $n) => "{$wt}×{$n}", array_keys($top), $top ) );
    }

    if ( $patterns['has_images'] ) {
        $log[] = '   Images: site uses ' . ( $patterns['has_image_boxes'] ? 'image-box' : 'image' ) . ' widgets — image slots will be added';
    }

    $structure_notes = [];
    if ( $patterns['has_hero'] ) $structure_notes[] = 'hero';
    if ( $patterns['has_cta']  ) $structure_notes[] = 'cta';
    if ( $patterns['has_faq']  ) $structure_notes[] = 'faq';
    if ( ! empty( $structure_notes ) ) {
        $log[] = '   Detected sections: ' . implode( ', ', $structure_notes ) . ' — will be included';
    }

    if ( $patterns['has_icon_boxes'] ) {
        $log[] = "   Icon boxes: avg {$patterns['avg_icon_box_count']} per page";
    }

    if ( ! empty( $patterns['section_bg_colors'] ) ) {
        $log[] = '   Section bg colors: ' . implode( ', ', array_slice( $patterns['section_bg_colors'], 0, 4 ) );
    }

    return [
        'kit'          => $kit,
        'sample_pages' => $sample_pages,
        'patterns'     => $patterns,
        'prompt_block' => $prompt_block,
        'log'          => $log,
    ];
}
