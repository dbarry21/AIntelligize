<?php
/**
 * AJAX handler: AI Elementor Page Builder
 * Path: inc/ajax/ai-elementor-builder.php
 *
 * Generates page content via AI (JSON output) then builds native Elementor
 * widget trees: Heading, Text Editor, Button, Icon Box, Image Box, Shortcode.
 * Uses the modern Flexbox Container structure (elType:container) — no columns.
 *
 * Actions:
 *   myls_elb_create_page        – Generate + create/update Elementor page
 *   myls_elb_save_prompt        – Persist custom prompt template
 *   myls_elb_get_nav_posts      – Return wp_navigation posts (reused from page builder)
 *   myls_elb_save_description   – Save description to history
 *   myls_elb_list_descriptions  – List saved descriptions
 *   myls_elb_delete_description – Delete a saved description
 */
if ( ! defined('ABSPATH') ) exit;

/* =========================================================================
 * ELEMENT BUILDERS
 * Converts parsed JSON section data into native Elementor widget arrays.
 *
 * Widget types used:
 *   heading       — H1/H2/H3 headings
 *   text-editor   — Rich text (TinyMCE-editable) for body paragraphs
 *   button        — Native CTA button using Elementor global styles
 *   icon-box      — Icon + title + description (features, process steps)
 *   shortcode     — [faq_schema_accordion] for FAQ sections
 *   container     — Outer section wrapper (full-width or boxed)
 *   container(i)  — Inner flex row for icon-box grids
 * ========================================================================= */

/**
 * Generate a random 8-char hex ID for an Elementor element.
 */
function myls_elb_uid(): string {
    return substr( md5( uniqid( '', true ) ), 0, 8 );
}

/**
 * Build an Elementor Flexbox Container.
 *
 * @param array  $widgets            Child widget/container elements.
 * @param array  $container_settings Elementor settings to merge in.
 * @param bool   $is_inner           True for inner containers (e.g. icon-box rows).
 * @return array
 */
function myls_elb_section( array $widgets, array $container_settings = [], bool $is_inner = false ): array {
    return [
        'id'       => myls_elb_uid(),
        'elType'   => 'container',
        'isInner'  => $is_inner,
        'settings' => array_merge( [
            'container_type'   => 'flex',   // explicit — prevents Elementor auto-switching to grid
            'content_width'    => 'full',
            'flex_direction'   => 'column',
            'flex_align_items' => 'stretch',
            'padding'          => [
                'unit' => 'px', 'top' => '0', 'right' => '0',
                'bottom' => '0', 'left' => '0', 'isLinked' => false,
            ],
        ], $container_settings ),
        'elements' => $widgets,
    ];
}

/**
 * Heading widget  (widgetType: heading)
 *
 * @param string $text   Heading text (plain text, no HTML).
 * @param string $tag    h1 | h2 | h3 | h4
 * @param string $align  left | center | right
 * @param array  $extra  Extra settings to merge.
 */
function myls_elb_heading_widget( string $text, string $tag = 'h2', string $align = 'left', array $extra = [] ): array {
    return [
        'id'         => myls_elb_uid(),
        'elType'     => 'widget',
        'widgetType' => 'heading',
        'settings'   => array_merge( [
            'title'       => $text,
            'header_size' => $tag,
            'align'       => $align,
        ], $extra ),
        'elements' => [],
    ];
}

/**
 * Text Editor widget  (widgetType: text-editor)
 * Editable via Elementor's TinyMCE inline editor.
 *
 * @param string $html  HTML content for the editor field.
 * @param array  $extra Extra settings to merge.
 */
function myls_elb_text_editor_widget( string $html, array $extra = [] ): array {
    return [
        'id'         => myls_elb_uid(),
        'elType'     => 'widget',
        'widgetType' => 'text-editor',
        'settings'   => array_merge( [
            'editor' => $html,
        ], $extra ),
        'elements' => [],
    ];
}

/**
 * Button widget  (widgetType: button)
 * References the Elementor global PRIMARY color via __globals__ so it
 * respects the site's kit color scheme instead of defaulting to accent.
 *
 * @param string $text  Button label.
 * @param string $url   Target URL.
 * @param string $align left | center | right
 */
function myls_elb_button_widget( string $text, string $url = '/contact/', string $align = 'center' ): array {
    return [
        'id'         => myls_elb_uid(),
        'elType'     => 'widget',
        'widgetType' => 'button',
        'settings'   => [
            'text'  => $text,
            'link'  => [
                'url'         => $url,
                'is_external' => false,
                'nofollow'    => false,
            ],
            'align'      => $align,
            // Reference the global PRIMARY color so the button uses the site's
            // brand kit color (e.g. --e-global-color-primary) rather than
            // falling back to accent which is often black.
            '__globals__' => [
                'background_color' => 'globals/colors?id=primary',
            ],
        ],
        'elements' => [],
    ];
}

/**
 * Icon Box widget  (widgetType: icon-box)
 * Used for both feature cards and process steps.
 *
 * @param string $fa_icon   FA icon string e.g. "fas fa-shield-alt"
 * @param string $title     Box heading (plain text).
 * @param string $desc      Description (plain text).
 * @param string $icon_color Background fill color for the stacked icon.
 */
function myls_elb_icon_box_widget( string $fa_icon, string $title, string $desc, string $icon_color = '#2c7be5' ): array {
    // Parse "fas fa-shield-alt" → library + value
    $parts   = explode( ' ', trim( $fa_icon ), 2 );
    $prefix  = $parts[0] ?? 'fas';
    $lib_map = [ 'fas' => 'fa-solid', 'far' => 'fa-regular', 'fab' => 'fa-brands' ];
    $library = $lib_map[ $prefix ] ?? 'fa-solid';

    return [
        'id'         => myls_elb_uid(),
        'elType'     => 'widget',
        'widgetType' => 'icon-box',
        'settings'   => [
            'selected_icon' => [
                'value'   => $fa_icon,
                'library' => $library,
            ],
            'title_text'       => $title,
            'description_text' => $desc,
            'title_size'       => 'h3',
            'position'         => 'top',
            'view'             => 'stacked',
            'icon_color'       => '#ffffff',
            'background_color_icon' => $icon_color,
            'border_radius_icon' => [
                'unit' => 'px', 'top' => '50', 'right' => '50',
                'bottom' => '50', 'left' => '50', 'isLinked' => true,
            ],
            // Flex-row sizing: ~30% so 3 fit per row; justify-content:center on the
            // parent row container will automatically center any orphan on the last row.
            '_element_width'        => 'initial',
            '_element_custom_width' => [ 'unit' => '%', 'size' => 30 ],
        ],
        'elements' => [],
    ];
}

/**
 * Image widget  (widgetType: image)
 *
 * @param int    $attach_id  WordPress attachment ID.
 * @param string $url        Full URL to the image.
 * @param string $alt        Alt text.
 * @param string $size       Elementor size key: 'full', 'large', 'medium'.
 */
function myls_elb_image_widget( int $attach_id, string $url, string $alt = '', string $size = 'full' ): array {
    return [
        'id'         => myls_elb_uid(),
        'elType'     => 'widget',
        'widgetType' => 'image',
        'settings'   => [
            'image'          => [ 'url' => $url, 'id' => $attach_id, 'alt' => $alt ],
            'image_size'     => $size,
            'align'          => 'center',
            'caption_source' => 'none',
        ],
        'elements' => [],
    ];
}

/**
 * Image Box widget  (widgetType: image-box)
 * Used instead of icon-box when a real generated image is available.
 *
 * @param int    $attach_id  WordPress attachment ID.
 * @param string $url        Full image URL.
 * @param string $alt        Alt text.
 * @param string $title      Box heading.
 * @param string $desc       Description.
 */
function myls_elb_image_box_widget( int $attach_id, string $url, string $alt, string $title, string $desc ): array {
    return [
        'id'         => myls_elb_uid(),
        'elType'     => 'widget',
        'widgetType' => 'image-box',
        'settings'   => [
            'image'            => [ 'url' => $url, 'id' => $attach_id, 'alt' => $alt ],
            'image_size'       => 'medium',
            'title_text'       => $title,
            'description_text' => $desc,
            'title_size'       => 'h3',
            'position'         => 'top',
            '_element_width'        => 'initial',
            '_element_custom_width' => [ 'unit' => '%', 'size' => 30 ],
        ],
        'elements' => [],
    ];
}

/**
 * Image Placeholder Box widget.
 *
 * Used when the site pattern shows image-box widgets but no generated image
 * is available.  Renders an image-box with a grey placeholder so the user
 * can see exactly where to drop their own image in Elementor.
 *
 * The placeholder SVG is data-URI so it works without any upload.
 *
 * @param string $title  Box heading.
 * @param string $desc   Description.
 */
function myls_elb_image_placeholder_box_widget( string $title, string $desc ): array {
    // Neutral grey placeholder — visible in Elementor without needing a media file
    $placeholder_url = 'https://placehold.co/400x300/e8e8e8/aaaaaa?text=Add+Image';
    return [
        'id'         => myls_elb_uid(),
        'elType'     => 'widget',
        'widgetType' => 'image-box',
        'settings'   => [
            'image'            => [
                'url' => $placeholder_url,
                'id'  => 0,
                'alt' => 'Placeholder — replace with your image',
            ],
            'image_size'       => 'medium',
            'title_text'       => $title,
            'description_text' => $desc,
            'title_size'       => 'h3',
            'position'         => 'top',
            '_element_width'        => 'initial',
            '_element_custom_width' => [ 'unit' => '%', 'size' => 30 ],
        ],
        'elements' => [],
    ];
}

/**
 * Standalone Image widget with placeholder.
 * Used when site has full-width image sections and no generated image is available.
 *
 * @param array  $img_data   Optional generated image array { id, url, alt }.
 * @param string $alt        Alt text for placeholder.
 */
function myls_elb_image_or_placeholder_widget( array $img_data = [], string $alt = '' ): array {
    if ( ! empty( $img_data['id'] ) && ! empty( $img_data['url'] ) ) {
        return myls_elb_image_widget( (int) $img_data['id'], $img_data['url'], $img_data['alt'] ?? $alt );
    }
    return myls_elb_image_widget(
        0,
        'https://placehold.co/1200x500/e8e8e8/aaaaaa?text=Add+Your+Image',
        $alt ?: 'Placeholder — replace with your image',
        'full'
    );
}

/**
 * Build a standalone image section (full-width image + optional caption).
 * Used when the AI returns an image_section key, or when site patterns
 * show image widgets in similar positions.
 *
 * @param array $d               { alt, caption } from AI JSON.
 * @param int   $container_width Kit container width.
 * @param array $image           Optional generated image array.
 */
function myls_elb_build_image_section( array $d, int $container_width = 1140, array $image = [] ): array {
    $widgets = [ myls_elb_image_or_placeholder_widget( $image, $d['alt'] ?? '' ) ];

    if ( ! empty( $d['caption'] ) ) {
        $widgets[] = myls_elb_text_editor_widget(
            '<p style="text-align:center;color:#666;font-size:0.9em;">' . esc_html( $d['caption'] ) . '</p>'
        );
    }

    return myls_elb_section( $widgets, [
        'content_width'  => 'boxed',
        'boxed_width'    => [ 'unit' => 'px', 'size' => $container_width ],
        'flex_direction' => 'column',
        'flex_align_items' => 'center',
        'padding'        => [ 'unit' => 'px', 'top' => '40', 'right' => '20', 'bottom' => '40', 'left' => '20', 'isLinked' => false ],
    ] );
}

/**
 * Shortcode widget  (widgetType: shortcode)
 *
 * @param string $shortcode  Full shortcode string e.g. "[faq_schema_accordion heading=""]"
 */
function myls_elb_shortcode_widget( string $shortcode ): array {
    return [
        'id'         => myls_elb_uid(),
        'elType'     => 'widget',
        'widgetType' => 'shortcode',
        'settings'   => [
            'shortcode' => $shortcode,
        ],
        'elements' => [],
    ];
}

/**
 * HTML widget — kept as a fallback for raw HTML content.
 *
 * @param string $html Raw HTML.
 */
function myls_elb_html_widget( string $html ): array {
    return [
        'id'         => myls_elb_uid(),
        'elType'     => 'widget',
        'widgetType' => 'html',
        'settings'   => [ 'html' => $html ],
        'elements'   => [],
    ];
}

/* =========================================================================
 * SECTION BUILDERS
 * Each function returns one top-level Elementor container array.
 * ========================================================================= */

/**
 * Icon color palette — cycled through feature / process icon boxes.
 */
function myls_elb_icon_color( int $index ): string {
    $palette = [ '#2c7be5', '#00b894', '#e17055', '#6c5ce7', '#fdcb6e', '#0984e3', '#d63031', '#00cec9' ];
    return $palette[ $index % count( $palette ) ];
}

/**
 * Hero section container.
 *
 *   [Container: dark bg, full-width, centered, tall padding]
 *     Heading (h1, center, white)
 *     Text Editor (subtitle, center, white)
 *     Button (center)
 */
function myls_elb_build_hero( array $d, array $hero_image = [] ): array {
    $widgets = [
        myls_elb_heading_widget(
            $d['title'] ?? '',
            'h1', 'center',
            [ 'title_color' => '#ffffff' ]
        ),
        myls_elb_text_editor_widget(
            '<p style="color:#e0e7ef;font-size:1.15em;text-align:center;">'
            . esc_html( $d['subtitle'] ?? '' ) . '</p>'
        ),
        myls_elb_button_widget(
            $d['button_text'] ?? 'Contact Us',
            $d['button_url']  ?? '/contact/',
            'center'
        ),
    ];

    // Base container settings — dark background
    $settings = [
        'background_background' => 'classic',
        'background_color'      => '#1a2332',
        'content_width'         => 'full',
        'flex_direction'        => 'column',
        'flex_align_items'      => 'center',
        'padding'               => [ 'unit' => 'px', 'top' => '100', 'right' => '20', 'bottom' => '100', 'left' => '20', 'isLinked' => false ],
    ];

    // If a hero image was generated, use it as the container background image
    // with a dark overlay so text remains readable.
    if ( ! empty( $hero_image['id'] ) && ! empty( $hero_image['url'] ) ) {
        $settings['background_image']    = [
            'url' => $hero_image['url'],
            'id'  => (int) $hero_image['id'],
        ];
        $settings['background_position'] = 'center center';
        $settings['background_size']     = 'cover';
        $settings['background_repeat']   = 'no-repeat';
        // Darken with overlay so white heading text stays legible
        $settings['background_overlay_background'] = 'classic';
        $settings['background_overlay_color']      = 'rgba(26, 35, 50, 0.72)';
    }

    return myls_elb_section( $widgets, $settings );
}

/**
 * Intro section container.
 *
 *   [Container: white, boxed, standard padding]
 *     Heading (h2)
 *     Text Editor (paragraphs)
 */
function myls_elb_build_intro( array $d, int $container_width = 1140 ): array {
    $paras = array_filter( (array) ( $d['paragraphs'] ?? [] ) );
    $html  = implode( '', array_map( fn( $p ) => '<p>' . esc_html( trim( $p ) ) . '</p>', $paras ) );

    $widgets = [
        myls_elb_heading_widget( $d['heading'] ?? '', 'h2', 'left' ),
        myls_elb_text_editor_widget( $html ),
    ];

    return myls_elb_section( $widgets, [
        'content_width'  => 'boxed',
        'boxed_width'    => [ 'unit' => 'px', 'size' => $container_width ],
        'flex_direction' => 'column',
        'padding'        => [ 'unit' => 'px', 'top' => '60', 'right' => '20', 'bottom' => '60', 'left' => '20', 'isLinked' => false ],
    ] );
}

/**
 * Features section container.
 *
 *   [Container: light grey, full-width, centered column]
 *     Heading (h2, center)
 *     [Inner Container: flex-row, wrap, center]
 *       Icon Box × N
 */
function myls_elb_build_features( array $d, array $feature_images = [], bool $prefer_image_box = false, int $container_width = 1140 ): array {
    $items    = (array) ( $d['items'] ?? [] );
    $boxes    = [];

    foreach ( $items as $idx => $item ) {
        $img = $feature_images[ $idx ] ?? [];

        if ( ! empty( $img['id'] ) && ! empty( $img['url'] ) ) {
            // Use Image Box widget with a real generated image
            $boxes[] = myls_elb_image_box_widget(
                (int) $img['id'],
                $img['url'],
                $item['title'] ?? $img['alt'] ?? '',
                $item['title'] ?? '',
                $item['description'] ?? ''
            );
        } elseif ( $prefer_image_box ) {
            // Site uses image-box widgets — add placeholder so user can drop image in
            $boxes[] = myls_elb_image_placeholder_box_widget(
                $item['title']       ?? '',
                $item['description'] ?? ''
            );
        } else {
            // Default: Icon Box
            $boxes[] = myls_elb_icon_box_widget(
                $item['icon']        ?? 'fas fa-star',
                $item['title']       ?? '',
                $item['description'] ?? '',
                myls_elb_icon_color( $idx )
            );
        }
    }

    $inner = myls_elb_section( $boxes, [
        'container_type'       => 'flex',
        'flex_direction'       => 'row',
        'flex_wrap'            => 'wrap',
        'flex_justify_content' => 'center',
        'gap'                  => [ 'unit' => 'px', 'size' => 20, 'isLinked' => true ],
        'content_width'        => 'full',
        'padding'              => [ 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => false ],
    ], true );

    $widgets = [
        myls_elb_heading_widget( $d['heading'] ?? '', 'h2', 'center' ),
        $inner,
    ];

    return myls_elb_section( $widgets, [
        'background_background' => 'classic',
        'background_color'      => '#f8f9fa',
        'content_width'         => 'boxed',
        'boxed_width'           => [ 'unit' => 'px', 'size' => $container_width ],
        'flex_direction'        => 'column',
        'flex_align_items'      => 'center',
        'padding'               => [ 'unit' => 'px', 'top' => '60', 'right' => '20', 'bottom' => '60', 'left' => '20', 'isLinked' => false ],
    ] );
}


function myls_elb_build_process( array $d, int $container_width = 1140 ): array {
    $steps      = (array) ( $d['steps'] ?? [] );
    $icon_boxes = [];
    foreach ( $steps as $idx => $step ) {
        $title        = ( $idx + 1 ) . '. ' . ( $step['title'] ?? '' );
        $icon_boxes[] = myls_elb_icon_box_widget(
            $step['icon']        ?? 'fas fa-check-circle',
            $title,
            $step['description'] ?? '',
            myls_elb_icon_color( $idx )
        );
    }

    $inner = myls_elb_section( $icon_boxes, [
        'container_type'       => 'flex',
        'flex_direction'       => 'row',
        'flex_wrap'            => 'wrap',
        'flex_justify_content' => 'center',
        'gap'                  => [ 'unit' => 'px', 'size' => 20, 'isLinked' => true ],
        'content_width'        => 'full',
        'padding'              => [ 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => false ],
    ], true );

    $widgets = [
        myls_elb_heading_widget( $d['heading'] ?? '', 'h2', 'left' ),
        $inner,
    ];

    return myls_elb_section( $widgets, [
        'content_width'  => 'boxed',
        'boxed_width'    => [ 'unit' => 'px', 'size' => $container_width ],
        'flex_direction' => 'column',
        'padding'        => [ 'unit' => 'px', 'top' => '60', 'right' => '20', 'bottom' => '60', 'left' => '20', 'isLinked' => false ],
    ] );
}


function myls_elb_build_faq( array $d, int $container_width = 1140 ): array {
    $faqs = [];
    foreach ( (array) ( $d['items'] ?? [] ) as $item ) {
        $q = trim( wp_strip_all_tags( (string) ( $item['question'] ?? '' ) ) );
        $a = trim( wp_strip_all_tags( (string) ( $item['answer']   ?? '' ) ) );
        if ( $q && $a ) {
            $faqs[] = [ 'q' => $q, 'a' => $a ];
        }
    }

    $widgets = [
        myls_elb_heading_widget( $d['heading'] ?? 'Frequently Asked Questions', 'h2', 'left' ),
        myls_elb_shortcode_widget( '[faq_schema_accordion heading=""]' ),
    ];

    $container = myls_elb_section( $widgets, [
        'background_background' => 'classic',
        'background_color'      => '#f8f9fa',
        'content_width'         => 'boxed',
        'boxed_width'           => [ 'unit' => 'px', 'size' => $container_width ],
        'flex_direction'        => 'column',
        'padding'               => [ 'unit' => 'px', 'top' => '60', 'right' => '20', 'bottom' => '60', 'left' => '20', 'isLinked' => false ],
    ] );

    return [ 'container' => $container, 'faqs' => $faqs ];
}


function myls_elb_build_cta( array $d ): array {
    $widgets = [
        myls_elb_heading_widget(
            $d['heading'] ?? 'Ready to Get Started?',
            'h2', 'center',
            [ 'title_color' => '#ffffff' ]
        ),
        myls_elb_text_editor_widget(
            '<p style="color:#e0e7ef;font-size:1.1em;text-align:center;">' .
            esc_html( $d['subtitle'] ?? '' ) . '</p>'
        ),
        myls_elb_button_widget(
            $d['button_text'] ?? 'Contact Us',
            $d['button_url']  ?? '/contact/',
            'center'
        ),
    ];

    return myls_elb_section( $widgets, [
        'background_background' => 'classic',
        'background_color'      => '#1a2332',
        'content_width'         => 'full',
        'flex_direction'        => 'column',
        'flex_align_items'      => 'center',
        'padding'               => [ 'unit' => 'px', 'top' => '80', 'right' => '20', 'bottom' => '80', 'left' => '20', 'isLinked' => false ],
    ] );
}

/**
 * Parse AI JSON output and build the full Elementor data array.
 *
 * Replaces myls_elb_split_sections() + myls_elb_build_elementor_data().
 *
 * @param string $ai_output  Raw AI response (should be a JSON object).
 * @return array {
 *   json          string   wp_json_encode'd Elementor data
 *   faqs          array    FAQ items for _myls_faq_items post meta
 *   section_count int      Number of top-level containers created
 *   error         string   Non-empty if JSON could not be parsed
 * }
 */
function myls_elb_parse_and_build( string $ai_output, array $generated_images = [], array $kit = [], array $site_patterns = [], array $section_flags = [] ): array {
    // Strip any accidental markdown code fences
    $raw = trim( $ai_output );
    $raw = preg_replace( '/^```(?:json)?\s*/i', '', $raw );
    $raw = preg_replace( '/\s*```$/',           '', $raw );
    $raw = trim( $raw );

    $data = json_decode( $raw, true );
    if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
        // Fallback: wrap entire output in a single text-editor container
        $fallback = myls_elb_section(
            [ myls_elb_html_widget( '<div>' . wp_kses_post( $ai_output ) . '</div>' ) ],
            []
        );
        return [
            'json'          => (string) wp_json_encode( [ $fallback ] ),
            'faqs'          => [],
            'section_count' => 1,
            'error'         => 'AI output was not valid JSON — used HTML widget fallback. Error: ' . json_last_error_msg(),
        ];
    }

    $elements      = [];
    $all_faqs      = [];
    $section_count = 0;

    // Separate generated images by type for passing to section builders
    $hero_image     = [];
    $feature_images = [];
    foreach ( $generated_images as $img ) {
        if ( $img['type'] === 'hero' ) {
            $hero_image = $img;
        } elseif ( $img['type'] === 'feature' ) {
            $feature_images[] = $img;
        }
    }

    // Derive container width from kit (fallback 1140)
    $container_width = (int) ( $kit['container_width'] ?? 1140 );

    // Section visibility flags (default all true for backward compat)
    $show_hero     = $section_flags['hero']     ?? true;
    $show_intro    = $section_flags['intro']    ?? true;
    $show_features = $section_flags['features'] ?? true;
    $show_process  = $section_flags['process']  ?? true;
    $show_faq      = $section_flags['faq']      ?? true;
    $show_cta      = $section_flags['cta']      ?? true;

    // HERO
    if ( $show_hero && ! empty( $data['hero'] ) ) {
        $elements[] = myls_elb_build_hero( (array) $data['hero'], $hero_image );
        $section_count++;
    }

    // INTRO
    if ( $show_intro && ! empty( $data['intro'] ) ) {
        $elements[] = myls_elb_build_intro( (array) $data['intro'], $container_width );
        $section_count++;
    }

    // FEATURES
    if ( $show_features && ! empty( $data['features'] ) ) {
        $use_image_boxes = $site_patterns['has_image_boxes'] ?? false;
        $elements[] = myls_elb_build_features( (array) $data['features'], $feature_images, $use_image_boxes, $container_width );
        $section_count++;
    }

    // PROCESS
    if ( $show_process && ! empty( $data['process'] ) ) {
        $elements[] = myls_elb_build_process( (array) $data['process'], $container_width );
        $section_count++;
    }

    // FAQ — also extracts items for post meta
    if ( $show_faq && ! empty( $data['faq'] ) ) {
        $faq_result  = myls_elb_build_faq( (array) $data['faq'], $container_width );
        $elements[]  = $faq_result['container'];
        $all_faqs    = $faq_result['faqs'];
        $section_count++;
    }

    // CTA
    if ( $show_cta && ! empty( $data['cta'] ) ) {
        $elements[] = myls_elb_build_cta( (array) $data['cta'] );
        $section_count++;
    }

    // IMAGE SECTIONS — if site uses image widgets and AI returned image sections,
    // or if we need to inject placeholder image sections from site_patterns
    if ( ! empty( $data['image_section'] ) ) {
        foreach ( (array) $data['image_section'] as $img_section ) {
            $elements[] = myls_elb_build_image_section( (array) $img_section, $container_width );
            $section_count++;
        }
    }

    return [
        'json'          => (string) wp_json_encode( $elements ),
        'faqs'          => $all_faqs,
        'section_count' => $section_count,
        'error'         => '',
    ];
}

/**
 * Legacy wrapper — kept so any external code calling myls_elb_build_elementor_data
 * still compiles. New code should call myls_elb_parse_and_build() directly.
 *
 * @deprecated Use myls_elb_parse_and_build() instead.
 */
function myls_elb_split_sections( string $html ): array {
    return [ $html ];
}

function myls_elb_build_elementor_data( array $html_sections ): string {
    // This path is only hit if the old calling convention is used.
    // Treat first element as raw AI output and re-parse.
    $result = myls_elb_parse_and_build( $html_sections[0] ?? '' );
    return $result['json'];
}

/* -------------------------------------------------------------------------
 * AJAX: Create / update Elementor page
 * ------------------------------------------------------------------------- */
add_action( 'wp_ajax_myls_elb_create_page', function () {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error( ['message' => 'Forbidden'], 403 );
    }
    if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_elb_create' ) ) {
        wp_send_json_error( ['message' => 'Bad nonce'], 400 );
    }

    $start_time = microtime( true );
    if ( class_exists('MYLS_Variation_Engine') ) { MYLS_Variation_Engine::reset_log(); }

    // ── Inputs ──────────────────────────────────────────────────────────
    $page_title      = sanitize_text_field( $_POST['page_title'] ?? '' );
    $post_type       = sanitize_key( $_POST['post_type'] ?? 'page' );
    $page_status     = in_array( $_POST['page_status'] ?? '', ['draft','publish'], true )
                        ? $_POST['page_status'] : 'draft';
    $description     = wp_kses_post( $_POST['page_description'] ?? '' );
    $prompt_template = wp_kses_post( $_POST['prompt_template'] ?? '' );

    // Resolve tokens in description early so {{PAGE_TITLE}}, {{YOAST_TITLE}},
    // {{CITY}} etc. are expanded before the description is used in AI prompts,
    // image generation context, fallback content, and Wikipedia lookups.
    // We build a minimal vars map here (full token_map is built later after
    // site analysis, but these core vars are always available immediately).
    $_desc_early_vars = array_merge(
        get_option( 'myls_sb_settings', [] ),
        [
            'business_name' => ( get_option( 'myls_sb_settings', [] )['business_name'] ?? get_bloginfo('name') ),
            'city'          => ( get_option( 'myls_sb_settings', [] )['city']          ?? '' ),
            'phone'         => ( get_option( 'myls_sb_settings', [] )['phone']         ?? '' ),
            'email'         => ( get_option( 'myls_sb_settings', [] )['email']         ?? get_bloginfo('admin_email') ),
            'site_name'     => get_bloginfo('name'),
            'site_url'      => home_url(),
            'page_title'    => sanitize_text_field( $_POST['page_title'] ?? '' ),
            'yoast_title'   => sanitize_text_field( trim( $_POST['seo_keyword'] ?? '' ) )
                               ?: sanitize_text_field( $_POST['page_title'] ?? '' ),
            'post_type'     => sanitize_key( $_POST['post_type'] ?? 'page' ),
        ]
    );
    if ( function_exists('myls_elb_replace_tokens') ) {
        $description = myls_elb_replace_tokens( $description, $_desc_early_vars );
    }
    $add_to_menu     = ! empty( $_POST['add_to_menu'] );

    // SEO keyword — used for Yoast meta and to guide AI content + Wikipedia lookup
    $seo_keyword = sanitize_text_field( trim( $_POST['seo_keyword'] ?? '' ) );

    // Append Elementor templates (up to 3) at bottom
    $append_template_ids = array_filter( array_map( 'intval', [
        $_POST['append_template_1'] ?? 0,
        $_POST['append_template_2'] ?? 0,
        $_POST['append_template_3'] ?? 0,
    ] ) );

    // Section toggles — default true so API calls without the param still get all sections
    $include_hero     = ! isset( $_POST['include_hero'] )     || ! empty( $_POST['include_hero'] );
    $include_intro    = ! isset( $_POST['include_intro'] )    || ! empty( $_POST['include_intro'] );
    $include_features = ! isset( $_POST['include_features'] ) || ! empty( $_POST['include_features'] );
    $include_process  = ! isset( $_POST['include_process'] )  || ! empty( $_POST['include_process'] );
    $include_faq      = ! isset( $_POST['include_faq'] )      || ! empty( $_POST['include_faq'] );
    $include_cta      = ! isset( $_POST['include_cta'] )      || ! empty( $_POST['include_cta'] );
    $integrate_images = ! empty( $_POST['integrate_images'] );
    $image_style      = sanitize_text_field( $_POST['image_style'] ?? 'photo' );
    $gen_hero_img     = ! empty( $_POST['gen_hero'] );
    $gen_feature_imgs = ! empty( $_POST['gen_feature'] );
    $feature_count    = 1;  // Featured Image is always 1 wide photorealistic image
    $set_featured     = ! empty( $_POST['set_featured'] );

    if ( empty( $page_title ) ) {
        wp_send_json_error( ['message' => 'Page title is required.'], 400 );
    }
    if ( ! post_type_exists( $post_type ) ) {
        wp_send_json_error( ['message' => 'Invalid post type: ' . $post_type ], 400 );
    }

    // ── Business vars ────────────────────────────────────────────────────
    $sb   = get_option( 'myls_sb_settings', [] );
    $vars = [
        'business_name' => $sb['business_name'] ?? get_bloginfo('name'),
        'city'          => $sb['city']          ?? '',
        'phone'         => $sb['phone']         ?? '',
        'email'         => $sb['email']         ?? get_bloginfo('admin_email'),
        'site_name'     => get_bloginfo('name'),
        'site_url'      => home_url(),
    ];

    // ── Site analysis ────────────────────────────────────────────────────
    // Reads Elementor kit settings (container width, global colors, typography)
    // and samples up to 3 existing posts of this type to extract widget patterns.
    $site_context  = function_exists('myls_elb_analyze_site')
        ? myls_elb_analyze_site( $post_type )
        : [ 'kit' => [ 'container_width' => 1140 ], 'sample_pages' => [], 'patterns' => [], 'prompt_block' => '', 'log' => [] ];
    $kit           = $site_context['kit'];
    $site_patterns = $site_context['patterns'];

    // ── Prompt ───────────────────────────────────────────────────────────
    // Detect stale HTML-output prompts (saved before v7.1.2 when the pipeline
    // used HTML <section> output instead of JSON).  Any prompt containing these
    // fingerprints will produce a single HTML widget instead of native widgets.
    $html_prompt_fingerprints = [
        'HTML RULES',
        'Output raw HTML',
        'elb-hero',
        'elb-features',
        'Start directly with the first <section',
        'Bootstrap Icons (bi bi-*)',
    ];
    $prompt_is_stale_html = false;
    foreach ( $html_prompt_fingerprints as $fp ) {
        if ( str_contains( $prompt_template, $fp ) ) {
            $prompt_is_stale_html = true;
            break;
        }
    }

    if ( $prompt_is_stale_html ) {
        // Auto-migrate: replace with the current JSON-output default and clear
        // the saved DB option so the textarea shows the new version on next load.
        $prompt_template = myls_get_default_prompt('elementor-builder');
        update_option( 'myls_elb_prompt_template', '' );  // empty = "use file default"
    }

    if ( empty( trim( $prompt_template ) ) ) {
        $prompt_template = myls_get_default_prompt('elementor-builder');
    }

    // YOAST_TITLE: use seo_keyword if set, otherwise fall back to page title
    $yoast_title_token = $seo_keyword ?: $page_title;

    $token_map = array_merge( $vars, [
        'page_title'  => $page_title,
        'yoast_title' => $yoast_title_token,
        'description' => $description ?: 'A page about ' . $page_title,
        'post_type'   => $post_type,
    ] );
    $prompt = myls_elb_replace_tokens( $prompt_template, $token_map );

    // Append site context block (kit colors, widget patterns from sample pages)
    if ( ! empty( $site_context['prompt_block'] ) ) {
        $prompt .= $site_context['prompt_block'];
    }

    // If existing pages use a specific icon box count, override the feature count
    // so the AI generates the right number of items to fill the grid evenly.
    if ( ! empty( $site_patterns['avg_icon_box_count'] ) && $feature_count === 3 ) {
        $feature_count = min( 6, $site_patterns['avg_icon_box_count'] );
    }

    // ── Pre-generate images (reuses page builder helpers) ───────────────
    $generated_images = [];
    $image_log        = [];

    if ( $integrate_images && function_exists('myls_pb_dall_e_generate') ) {
        $api_key = function_exists('myls_openai_get_api_key') ? myls_openai_get_api_key() : '';
        if ( ! empty( $api_key ) ) {
            $style_map = [
                'photo'             => 'Professional photograph, real camera shot, natural lighting, high resolution, sharp focus, authentic scene, no illustrations, no digital art',
                'modern-flat'       => 'Modern flat design illustration, clean lines, soft gradients, professional color palette, minimalist',
                'photorealistic'    => 'Professional stock photography style, high quality, well-lit, clean background',
                'isometric'         => 'Isometric 3D illustration, colorful, tech-forward, clean white background',
                'watercolor'        => 'Soft watercolor style illustration, artistic, professional, warm tones',
                'gradient-abstract' => 'Abstract gradient art, flowing shapes, modern tech aesthetic, vivid colors',
            ];
            $style_suffix = $style_map[ $image_style ] ?? $style_map['photo'];
            $dalle_style  = ( $image_style === 'photo' ) ? 'natural' : 'vivid';

            if ( $gen_hero_img ) {
                $image_log[] = '🎨 Generating hero image…';
                $hero_prompt = "Create a wide banner/hero image for a webpage about: {$page_title}. ";
                if ( $description ) {
                    $hero_prompt .= 'Context: ' . mb_substr( wp_strip_all_tags( $description ), 0, 300 ) . '. ';
                }
                $hero_prompt .= "Style: {$style_suffix}. Landscape orientation, 1792x1024, no text or words in the image.";

                $result = myls_pb_dall_e_generate( $api_key, $hero_prompt, '1792x1024', $dalle_style );
                if ( $result['ok'] ) {
                    $attach_id = myls_pb_upload_image_from_url(
                        $result['url'],
                        sanitize_title( $page_title ) . '-hero',
                        $page_title . ' - Hero Image',
                        0
                    );
                    if ( $attach_id ) {
                        $generated_images[] = [
                            'type'    => 'hero',
                            'id'      => $attach_id,
                            'url'     => wp_get_attachment_url( $attach_id ),
                            'alt'     => $page_title . ' - Hero Image',
                            'subject' => $page_title,
                        ];
                        $image_log[] = "   ✅ Hero image saved to Media Library (ID: {$attach_id})";
                    } else {
                        $image_log[] = '   ❌ Hero: DALL-E returned an image but Media Library sideload failed. Check PHP error_log.';
                    }
                } else {
                    $image_log[] = '   ❌ Hero DALL-E error: ' . $result['error'];
                }
            }

            if ( $gen_feature_imgs ) {
                $image_log[] = '🎨 Generating Featured Image (' . $image_style . ', 1792x1024)…';
                // Use the selected style_suffix — no override
                $i = 0; $feature_count = 1;
                $subjects = function_exists('myls_pb_suggest_image_subjects')
                    ? myls_pb_suggest_image_subjects( $page_title, $description, 1 )
                    : [ $page_title ];

                for ( $i = 0; $i < $feature_count; $i++ ) {
                    $subject     = $subjects[0] ?? $page_title;
                    $feat_prompt = "Create a wide image for a webpage about: {$page_title}. Subject: {$subject}. Style: {$style_suffix}. Landscape orientation, 1792x1024, no text or words in the image.";
                    $result      = myls_pb_dall_e_generate( $api_key, $feat_prompt, '1792x1024', $dalle_style );
                    if ( $result['ok'] ) {
                        $attach_id = myls_pb_upload_image_from_url(
                            $result['url'],
                            sanitize_title( $page_title ) . '-featured',
                            $page_title . ' - Featured Image',
                            0
                        );
                        if ( $attach_id ) {
                            $generated_images[] = [
                                'type'    => 'feature',
                                'id'      => $attach_id,
                                'url'     => wp_get_attachment_url( $attach_id ),
                                'alt'     => $page_title . ' - Featured Image',
                                'subject' => $subject,
                            ];
                            $image_log[] = "   ✅ Featured Image saved to Media Library (ID: {$attach_id})";
                            // Auto set as featured image if set_featured is checked
                            // (hero also sets it; feature overrides if hero not generated)
                        } else {
                            $image_log[] = '   ❌ Featured Image: DALL-E succeeded but Media Library upload failed.';
                        }
                    } else {
                        $image_log[] = '   ❌ Feature ' . ( $i + 1 ) . ': ' . $result['error'];
                    }
                }
            }

            if ( ! $gen_hero_img && ! $gen_feature_imgs ) {
                $image_log[] = 'ℹ️ No hero or feature images requested — only template image widgets will be scanned.';
            }
        }
    }

    // NOTE: Images are no longer injected into the AI prompt as <img> HTML tags.
    // The AI outputs structured JSON, so images cannot be placed via prompt instructions.
    // Instead, generated images are passed directly to myls_elb_parse_and_build()
    // below, where they are applied as native Elementor widget/container settings:
    //   hero image  → container background_image (overlaid on dark background)
    //   feature imgs → image-box widgets (replace icon-box when images are available)

    // ── Knowledge Graph grounding for main prompt ───────────────────────
    // Append KG facts to the prompt so the AI generates more accurate content.
    // If KG key isn't configured this returns '' silently and we fall back to Wikipedia.
    $main_kg_topic   = $seo_keyword ?: $page_title;
    $main_kg_context = function_exists('myls_elb_fetch_kg_context') ? myls_elb_fetch_kg_context( $main_kg_topic ) : '';
    $main_wiki_context = myls_elb_fetch_wikipedia_context( $main_kg_topic );

    if ( $main_kg_context ) {
        $prompt     .= "\n\n--- Knowledge Graph Reference (use for factual accuracy, rewrite in your own words) ---\n" . $main_kg_context;
        $log_lines[] = '🔍 Knowledge Graph context injected into main prompt for: "' . esc_html( $main_kg_topic ) . '"';
    }
    if ( $main_wiki_context ) {
        $prompt     .= "\n\n--- Wikipedia Reference (synthesize, do NOT copy) ---\n" . $main_wiki_context;
        $log_lines[] = '🌐 Wikipedia context injected into main prompt for: "' . esc_html( $main_kg_topic ) . '"';
    }

    // ── Variation Engine angle ───────────────────────────────────────────
    if ( class_exists('MYLS_Variation_Engine') ) {
        $angle  = MYLS_Variation_Engine::next_angle('about_the_area');
        $prompt = MYLS_Variation_Engine::inject_variation( $prompt, $angle, 'about_the_area' );
    }

    // ── Call AI ─────────────────────────────────────────────────────────
    $html    = '';
    $ai_used = false;

    if ( function_exists('myls_ai_chat') ) {
        $model = (string) get_option('myls_openai_model', '');
        if ( function_exists('myls_ai_set_usage_context') ) {
            myls_ai_set_usage_context( 'elementor_builder', 0 );
        }
        $raw = myls_ai_chat( $prompt, [
            'model'       => $model,
            'max_tokens'  => 4000,
            'temperature' => 0.7,
            'system'      => 'You are a content writer for Elementor WordPress pages. You output ONLY valid JSON — never HTML, never markdown, never code fences.

Your response must be a single JSON object with these exact keys: hero, intro, features, process, faq, cta.

Rules:
- Start your response with { and end with }
- No text before or after the JSON object
- No ```json``` fences
- All string values must be plain text (no HTML tags inside JSON values)
- icon values must be Font Awesome 5 solid class strings like "fas fa-shield-alt"
- paragraphs are arrays of plain text strings',
        ] );
        if ( ! empty( trim( $raw ) ) ) {
            $html    = $raw;
            $ai_used = true;
        }
    }

    // ── Fallback JSON ────────────────────────────────────────────────────
    // If AI returned nothing, build a minimal valid JSON structure.
    if ( empty( trim( $html ) ) ) {
        $html = wp_json_encode( [
            'hero'  => [
                'title'       => $page_title,
                'subtitle'    => $description ?: 'Professional service in ' . $page_title,
                'button_text' => 'Get In Touch',
                'button_url'  => '/contact/',
            ],
            'intro' => [
                'heading'    => 'About ' . $page_title,
                'paragraphs' => [ $description ?: 'Learn more about ' . $page_title ],
            ],
            'cta'   => [
                'heading'     => 'Ready to Get Started?',
                'subtitle'    => 'Contact us today.',
                'button_text' => 'Contact Us',
                'button_url'  => '/contact/',
            ],
        ] );
    }

    // ── Parse JSON + build native Elementor widgets ──────────────────────
    $section_flags  = [
        'hero'     => $include_hero,
        'intro'    => $include_intro,
        'features' => $include_features,
        'process'  => $include_process,
        'faq'      => $include_faq,
        'cta'      => $include_cta,
    ];
    $build_result   = myls_elb_parse_and_build( $html, $generated_images, $kit, $site_patterns, $section_flags );
    $elementor_json = $build_result['json'];
    $faq_items      = $build_result['faqs'];
    $section_count  = $build_result['section_count'];
    $parse_warning  = $build_result['error'];

    // ── Append Elementor templates (up to 3) ────────────────────────────
    // NOTE: $tpl_log_lines is separate from $log_lines (declared later) so
    // entries added here survive the $log_lines = [] reset that follows.
    $tpl_log_lines = [];

    if ( ! empty( $append_template_ids ) ) {
        // Fetch grounding context: Knowledge Graph first, Wikipedia as fallback/supplement
        $wiki_topic   = $seo_keyword ?: $page_title;

        $kg_context   = function_exists('myls_elb_fetch_kg_context') ? myls_elb_fetch_kg_context( $wiki_topic ) : '';
        if ( $kg_context ) {
            $tpl_log_lines[] = '🔍 Knowledge Graph context fetched for: "' . esc_html( $wiki_topic ) . '"';
        }

        $wiki_context = myls_elb_fetch_wikipedia_context( $wiki_topic );
        if ( $wiki_context ) {
            $tpl_log_lines[] = '🌐 Wikipedia context fetched for: "' . esc_html( $wiki_topic ) . '"';
        }

        $page_elements    = json_decode( $elementor_json, true ) ?: [];
        $tpl_slot_index   = 0;

        foreach ( $append_template_ids as $tpl_id ) {
            $tpl_slot_index++;
            $tpl_data = get_post_meta( $tpl_id, '_elementor_data', true );
            if ( ! $tpl_data ) continue;

            $tpl_elements = json_decode( $tpl_data, true );
            if ( ! is_array( $tpl_elements ) || empty( $tpl_elements ) ) continue;

            // Regen IDs so no collision between templates or with generated sections
            $tpl_elements = myls_elb_regen_ids( $tpl_elements );

            // ── Replace "AI Content Here" placeholders ──────────────────
            $ai_placeholder_count = myls_elb_count_ai_placeholders( $tpl_elements );
            if ( $ai_placeholder_count > 0 ) {
                $focus = $seo_keyword ?: $page_title;

                // Build a unique angle per slot so content differs across templates
                $slot_angles = [
                    1 => 'benefits and value proposition',
                    2 => 'process, methodology and what to expect',
                    3 => 'local relevance, trust factors and why choose us',
                ];
                $angle = $slot_angles[ $tpl_slot_index ] ?? 'key information and details';

                $ai_fill_prompt  = "Write approximately 400 words of professional, engaging HTML content about \"{$focus}\" focusing specifically on: {$angle}.\n";
                $ai_fill_prompt .= "Page title: {$page_title}.\n";
                if ( $description ) {
                    $ai_fill_prompt .= 'Page description: ' . mb_substr( wp_strip_all_tags( $description ), 0, 400 ) . "\n";
                }
                if ( $kg_context ) {
                    $ai_fill_prompt .= "\nKnowledge Graph facts (use as factual grounding, rewrite in your own words):\n" . $kg_context . "\n";
                }
                if ( $wiki_context ) {
                    $ai_fill_prompt .= "\nWikipedia reference (synthesize from this, do NOT copy — rewrite in your own words):\n" . $wiki_context . "\n";
                }
                $ai_fill_prompt .= "\nOutput ONLY HTML body content — use <p>, <ul>, <li>, <strong> tags. No headings. No markdown. No code fences. Approx 400 words. Make it original, specific, and valuable to readers.";

                $ai_fill_html = function_exists('myls_ai_chat') ? myls_ai_chat( $ai_fill_prompt, [
                    'max_tokens'  => 900,
                    'temperature' => 0.75,
                    'system'      => 'You write original, professional HTML content for service pages. Synthesize from reference material but write everything in your own words. Output only HTML body content, no headings, no code fences, no markdown.',
                ] ) : '';

                if ( $ai_fill_html ) {
                    $tpl_elements    = myls_elb_fill_ai_placeholders( $tpl_elements, wp_kses_post( $ai_fill_html ) );
                    $tpl_log_lines[] = "✍️ Template {$tpl_slot_index}: AI content (angle: {$angle}) inserted into {$ai_placeholder_count} placeholder(s).";
                } else {
                    $tpl_log_lines[] = "⚠️ Template {$tpl_slot_index}: Could not generate AI content — placeholder(s) left as-is.";
                }
            }

            $page_elements  = array_merge( $page_elements, $tpl_elements );
            $section_count += count( $tpl_elements );
            $tpl_log_lines[] = '📎 Template ' . $tpl_slot_index . ' appended: "' . get_the_title( $tpl_id ) . '" (' . count( $tpl_elements ) . ' container(s))';
        }

        $elementor_json = (string) wp_json_encode( $page_elements );
    }

    // ── Fill empty image widgets in templates with DALL-E ─────────────
    // When "Integrate Images into page content" is checked and at least one
    // template was appended, scan the final element tree for image widgets
    // that have no real image (id=0 or placeholder URL) and generate a
    // DALL-E image for each, based on the SEO keyword / page title.
    //
    // Images are uploaded to the Media Library with parent_post_id=0 here
    // (post not yet created); the parent is updated after the post is saved.
    // We cap at 5 generated template images to control API costs.
    if ( $integrate_images
         && ! empty( $append_template_ids )
         && function_exists('myls_elb_find_empty_image_widgets')
         && function_exists('myls_pb_dall_e_generate') ) {

        $tpl_api_key = function_exists('myls_openai_get_api_key') ? myls_openai_get_api_key() : '';

        if ( ! empty( $tpl_api_key ) ) {
            $all_elements  = json_decode( $elementor_json, true ) ?: [];
            $empty_widgets = myls_elb_find_empty_image_widgets( $all_elements );
            $max_tpl_imgs  = 5; // cost guard

            if ( ! empty( $empty_widgets ) ) {
                $tpl_log_lines[] = '🖼️ Found ' . count( $empty_widgets ) . ' empty image widget(s) in template(s) — generating with DALL-E…';

                $tpl_style_map = [
                    'photo'             => 'Professional photograph, real camera shot, natural lighting, high resolution, sharp focus, authentic scene, no illustrations, no digital art',
                    'photorealistic'    => 'Professional stock photography style, high quality, well-lit, clean background',
                    'modern-flat'       => 'Modern flat design illustration, clean lines, soft gradients, professional color palette, minimalist',
                    'isometric'         => 'Isometric 3D illustration, colorful, tech-forward, clean white background',
                    'watercolor'        => 'Soft watercolor style illustration, artistic, professional, warm tones',
                    'gradient-abstract' => 'Abstract gradient art, flowing shapes, modern tech aesthetic, vivid colors',
                ];
                $tpl_style_suffix = $tpl_style_map[ $image_style ] ?? $tpl_style_map['photo'];
                $tpl_dalle_style  = ( $image_style === 'photo' ) ? 'natural' : 'vivid';
                $focus_kw         = $seo_keyword ?: $page_title;

                foreach ( array_slice( $empty_widgets, 0, $max_tpl_imgs ) as $w_idx => $widget_info ) {
                    $img_number   = $w_idx + 1;
                    $tpl_img_size = in_array( $image_style, ['photo', 'photorealistic'], true ) ? '1792x1024' : '1024x1024';
                    $tpl_orient   = $tpl_img_size === '1792x1024' ? 'Landscape orientation, 1792x1024' : 'Square format, 1024x1024';
                    $img_prompt   = "Create a professional image for a webpage about: {$focus_kw}. "
                                  . ( $widget_info['alt_hint'] ? "Image context: {$widget_info['alt_hint']}. " : '' )
                                  . "Style: {$tpl_style_suffix}. {$tpl_orient}, no text or words in the image.";

                    $dall_e_result = myls_pb_dall_e_generate( $tpl_api_key, $img_prompt, $tpl_img_size, $tpl_dalle_style );

                    if ( $dall_e_result['ok'] ) {
                        $tpl_attach_id = myls_pb_upload_image_from_url(
                            $dall_e_result['url'],
                            sanitize_title( $focus_kw ) . '-tpl-img-' . $img_number,
                            $focus_kw . ' - Template Image ' . $img_number,
                            0  // parent updated after post is created
                        );

                        if ( $tpl_attach_id ) {
                            $tpl_img_url   = wp_get_attachment_url( $tpl_attach_id );
                            $tpl_img_alt   = $focus_kw . ' - Image ' . $img_number;

                            // Inject into the element tree
                            $all_elements = myls_elb_inject_image_into_widget(
                                $all_elements,
                                $widget_info['id'],
                                $tpl_attach_id,
                                $tpl_img_url,
                                $tpl_img_alt
                            );

                            // Track for parent update + preview after post is saved
                            $generated_images[] = [
                                'type'    => 'template',
                                'id'      => $tpl_attach_id,
                                'url'     => $tpl_img_url,
                                'alt'     => $tpl_img_alt,
                                'subject' => $focus_kw,
                            ];

                            $tpl_log_lines[] = "   ✅ Template image {$img_number} generated (ID: {$tpl_attach_id})";
                        } else {
                            $tpl_log_lines[] = "   ❌ Template image {$img_number}: upload to Media Library failed";
                        }
                    } else {
                        $tpl_log_lines[] = "   ❌ Template image {$img_number}: " . ( $dall_e_result['error'] ?? 'DALL-E error' );
                    }
                }

                // Re-encode with injected images
                $elementor_json = (string) wp_json_encode( $all_elements );
            }
        } else {
            $tpl_log_lines[] = '⚠️ Integrate Images: OpenAI API key not set — template image widgets left as placeholders.';
        }
    }

    // ── Upsert post ──────────────────────────────────────────────────────
    $meta_key = '_myls_elb_generated_key';
    $gen_key  = 'elb:' . sanitize_title( $page_title );

    $existing = get_posts( [
        'post_type'      => $post_type,
        'post_status'    => ['draft','publish','pending','future','private'],
        'meta_key'       => $meta_key,
        'meta_value'     => $gen_key,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ] );

    // Elementor stores its own rendered HTML in post_content as a cache.
    // For a fresh page we set a placeholder; Elementor will regenerate on first load.
    $post_content_fallback = '<!-- Elementor page — edit in Elementor editor -->';

    if ( $existing ) {
        $post_id = (int) $existing[0];
        wp_update_post( [
            'ID'           => $post_id,
            'post_title'   => $page_title,
            'post_content' => $post_content_fallback,
            'post_status'  => $page_status,
        ] );
        $action_label = 'updated';
    } else {
        $post_id = (int) wp_insert_post( [
            'post_type'    => $post_type,
            'post_status'  => $page_status,
            'post_title'   => $page_title,
            'post_content' => $post_content_fallback,
            'meta_input'   => [
                '_myls_elb_generated' => 1,
                $meta_key             => $gen_key,
            ],
        ] );
        $action_label = 'created';
    }

    if ( ! $post_id || is_wp_error( $post_id ) ) {
        wp_send_json_error( ['message' => 'Failed to create post.'], 500 );
    }

    // ── Save FAQ items to post meta ──────────────────────────────────────
    // The [faq_schema_accordion] shortcode reads _myls_faq_items from the
    // current post automatically, so we just write the array here.
    if ( ! empty( $faq_items ) ) {
        update_post_meta( $post_id, '_myls_faq_items', $faq_items );
    } else {
        // Clear stale FAQs if we regenerated without any
        delete_post_meta( $post_id, '_myls_faq_items' );
    }

    // ── Save Elementor meta ──────────────────────────────────────────────
    //
    // WHY WE SKIP $document->save() (Elementor document API):
    // ─────────────────────────────────────────────────────────
    // Elementor's document->save() runs every element setting through its
    // registered-controls sanitization pipeline. Any key that is NOT a formally
    // registered control for that widget/container is silently stripped.
    //
    // This is the root cause of the blank "Container Layout" field:
    //   `container_type => 'flexbox'` IS set in our JSON but Elementor's API
    //   removes it because it considers it an unregistered control at save time.
    //   When you open the container in Elementor and see "Layout: (blank)", that's
    //   the stripped container_type. Setting it manually to Flexbox re-adds the
    //   key directly in the editor, which is why the manual fix works.
    //
    // Fix: always write directly to post meta with wp_slash().
    //   • wp_slash() compensates for WordPress's internal stripslashes() on meta
    //   • We set the real installed Elementor version to prevent migration triggers
    //   • We clear all CSS/element caches so Elementor regenerates on first load
    //   • Our JSON is 100% valid — no sanitization pass needed

    $real_elementor_version = defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.24.0';

    // CRITICAL: wp_slash() before update_post_meta — WordPress calls stripslashes()
    // on meta values internally. Without this, JSON quote-escapes are stripped and
    // the stored JSON is invalid, causing Elementor to render a blank page.
    update_post_meta( $post_id, '_elementor_data',          wp_slash( $elementor_json ) );
    update_post_meta( $post_id, '_elementor_edit_mode',     'builder' );
    update_post_meta( $post_id, '_elementor_version',       $real_elementor_version );
    update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );

    // Clear all per-post Elementor caches so the next load regenerates cleanly.
    delete_post_meta( $post_id, '_elementor_css' );
    delete_post_meta( $post_id, '_elementor_element_cache' );
    delete_post_meta( $post_id, '_elementor_page_assets' );

    // Flush global Elementor files cache if Elementor is active.
    if ( class_exists('\Elementor\Plugin') &&
         isset( \Elementor\Plugin::$instance->files_manager ) ) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    }

    $saved_via_api = false; // document API intentionally bypassed — see comment above

    // ── Attach generated images ──────────────────────────────────────────
    // set_post_thumbnail priority: hero first, then feature (so checking only
    // "Featured Image" without hero still correctly sets the post thumbnail).
    if ( ! empty( $generated_images ) ) {
        foreach ( $generated_images as $img ) {
            wp_update_post( [ 'ID' => $img['id'], 'post_parent' => $post_id ] );
        }
        if ( $set_featured ) {
            // Prefer hero; fall back to feature image if no hero was generated
            $thumb = null;
            foreach ( $generated_images as $img ) {
                if ( $img['type'] === 'hero' )    { $thumb = $img['id']; break; }
            }
            if ( ! $thumb ) {
                foreach ( $generated_images as $img ) {
                    if ( $img['type'] === 'feature' ) { $thumb = $img['id']; break; }
                }
            }
            if ( $thumb ) {
                set_post_thumbnail( $post_id, $thumb );
                $image_log[] = "   📌 Set as Featured Image (attachment ID: {$thumb})";
            }
        }
    }

    // ── Yoast meta ───────────────────────────────────────────────────────
    $desc_text      = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
    $yoast_title    = $seo_keyword
        ? $seo_keyword . ' %%page%% %%sep%% %%sitename%%'
        : $page_title  . ' %%sep%% %%sitename%%';
    update_post_meta( $post_id, '_yoast_wpseo_title',   $yoast_title );
    update_post_meta( $post_id, '_yoast_wpseo_metadesc', mb_substr( $desc_text, 0, 155 ) );
    if ( $seo_keyword ) {
        update_post_meta( $post_id, '_yoast_wpseo_focuskw', $seo_keyword );
    }

    // ── Add to menu ───────────────────────────────────────────────────────
    $menu_msg = '';
    if ( $add_to_menu && function_exists('myls_pb_add_to_menu') ) {
        $menu_msg = myls_pb_add_to_menu( $post_id, $post_type );
    }

    // ── Build Elementor editor URL ────────────────────────────────────────
    $elementor_active = defined('ELEMENTOR_VERSION') || class_exists('\Elementor\Plugin');
    $edit_url = $elementor_active
        ? admin_url( 'post.php?post=' . $post_id . '&action=elementor' )
        : admin_url( 'post.php?post=' . $post_id . '&action=edit' );

    $view_url = get_permalink( $post_id );

    // ── Response log ─────────────────────────────────────────────────────
    $type_obj   = get_post_type_object( $post_type );
    $type_label = $type_obj ? $type_obj->labels->singular_name : $post_type;

    $log_lines   = [];
    // Site analysis lines first so user sees what was detected
    if ( ! empty( $site_context['log'] ) ) {
        $log_lines = array_merge( $log_lines, $site_context['log'] );
    }
    // Template processing lines (Wikipedia fetch, AI fill, appended containers, image gen)
    if ( ! empty( $tpl_log_lines ) ) {
        $log_lines = array_merge( $log_lines, $tpl_log_lines );
    }
    $log_lines[] = "✅ {$type_label} {$action_label}: \"{$page_title}\"";
    $log_lines[] = "   Post ID: {$post_id} | Status: {$page_status}";
    $log_lines[] = "   AI: " . ( $ai_used ? 'Content generated by AI' : 'Using fallback template (check OpenAI API key)' );
    $log_lines[] = "   Widgets: {$section_count} section container(s) — Heading · Text Editor · Icon Box · Button · Shortcode";
    if ( ! empty( $faq_items ) ) {
        $log_lines[] = "   FAQs: " . count( $faq_items ) . " question(s) saved to custom fields (used by [faq_schema_accordion])";
    }
    if ( $parse_warning ) {
        $log_lines[] = "   ⚠️  " . $parse_warning;
    }
    if ( $prompt_is_stale_html ) {
        $log_lines[] = "   🔄 Prompt auto-updated: your saved template contained old HTML instructions and has been reset to the current JSON default. Reload the page to see the updated prompt in the editor.";
    }
    $log_lines[] = "   Elementor: " . ( $elementor_active ? 'Active ✓ — saved directly to meta (container_type=flex preserved)' : 'Plugin not active — data saved, will be ready when Elementor is installed' );
    if ( ! empty( $image_log ) ) {
        $log_lines = array_merge( $log_lines, $image_log );
        $hero_count         = count( array_filter( $generated_images, fn($i) => $i['type'] === 'hero' ) );
        $feature_count_done = count( array_filter( $generated_images, fn($i) => $i['type'] === 'feature' ) );
        $tpl_img_count      = count( array_filter( $generated_images, fn($i) => $i['type'] === 'template' ) );
        $img_notes = [];
        if ( $hero_count )         $img_notes[] = "hero → container background";
        if ( $feature_count_done ) $img_notes[] = "{$feature_count_done} feature(s) → image-box widgets";
        if ( $tpl_img_count )      $img_notes[] = "{$tpl_img_count} template image(s) → image widgets";
        $log_lines[] = "   📸 " . count( $generated_images ) . " image(s) integrated: " . implode( ', ', $img_notes );
    }
    if ( $menu_msg ) {
        $log_lines[] = "   Menu: {$menu_msg}";
    }
    $log_lines[] = "   Edit in Elementor: {$edit_url}";

    $ve_log = class_exists('MYLS_Variation_Engine') ? MYLS_Variation_Engine::build_item_log( $start_time, [
        'page_title'     => $page_title,
        'post_type'      => $post_type,
        'page_status'    => $page_status,
        'section_count'  => $section_count,
        'image_count'    => count( $generated_images ),
        'ai_used'        => $ai_used,
        'output_words'   => str_word_count( wp_strip_all_tags( $html ) ),
        'output_chars'   => strlen( $html ),
        'prompt_chars'   => mb_strlen( $prompt ),
        '_html'          => $html,
    ] ) : [ 'elapsed_ms' => round( ( microtime( true ) - $start_time ) * 1000 ) ];

    wp_send_json_success( [
        'message'         => "{$type_label} {$action_label} successfully.",
        'log_text'        => implode( "\n", $log_lines ),
        'log'             => $ve_log,
        'post_id'         => $post_id,
        'edit_url'        => $edit_url,
        'view_url'        => $view_url,
        'ai_used'         => $ai_used,
        'images'          => $generated_images,
        'section_count'   => $section_count,
        'elementor_active'=> $elementor_active,
        'status'          => 'saved',
        'title'           => $page_title,
    ] );
} );

/**
 * Token replacement (mirrors myls_pb_replace_tokens).
 */
function myls_elb_replace_tokens( string $text, array $vars ): string {
    foreach ( $vars as $k => $v ) {
        if ( is_string( $v ) || is_numeric( $v ) ) {
            $text = str_replace( '{{' . strtoupper( $k ) . '}}', (string) $v, $text );
        }
    }
    return $text;
}

/* -------------------------------------------------------------------------
 * AJAX: Save prompt template
 * ------------------------------------------------------------------------- */
add_action( 'wp_ajax_myls_elb_save_prompt', function () {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error( ['message' => 'Forbidden'], 403 );
    }
    if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_elb_create' ) ) {
        wp_send_json_error( ['message' => 'Bad nonce'], 400 );
    }
    update_option( 'myls_elb_prompt_template', wp_kses_post( $_POST['prompt_template'] ?? '' ) );
    wp_send_json_success( ['message' => 'Elementor prompt template saved.'] );
} );

/* -------------------------------------------------------------------------
 * AJAX: Get nav posts (mirrors page builder — reuses same logic)
 * ------------------------------------------------------------------------- */
add_action( 'wp_ajax_myls_elb_get_nav_posts', function () {
    if ( ! current_user_can('manage_options') ) wp_send_json_error( [], 403 );
    if ( ! function_exists('myls_pb_find_active_nav_id') ) wp_send_json_success( ['nav_posts' => [], 'is_block_theme' => false] );

    $nav_posts = get_posts( [
        'post_type'      => 'wp_navigation',
        'post_status'    => 'publish',
        'posts_per_page' => 20,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );
    $active_id = myls_pb_find_active_nav_id();
    $items = [];
    foreach ( $nav_posts as $np ) {
        $items[] = [ 'id' => (int) $np->ID, 'title' => $np->post_title ?: '(untitled)', 'active' => (int) $np->ID === $active_id ];
    }
    wp_send_json_success( [ 'nav_posts' => $items, 'active_id' => $active_id, 'is_block_theme' => wp_is_block_theme() ] );
} );

/* -------------------------------------------------------------------------
 * AJAX: Description history (mirrors page builder)
 * ------------------------------------------------------------------------- */
add_action( 'wp_ajax_myls_elb_save_description', function () {
    if ( ! current_user_can('manage_options') ) wp_send_json_error( ['message' => 'Forbidden'], 403 );
    if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_elb_create' ) ) wp_send_json_error( ['message' => 'Bad nonce'], 400 );

    $name        = sanitize_text_field( $_POST['desc_name'] ?? '' );
    $description = wp_kses_post( $_POST['description'] ?? '' );
    if ( empty( $name ) )        wp_send_json_error( ['message' => 'Name is required.'], 400 );
    if ( empty( $description ) ) wp_send_json_error( ['message' => 'Description is empty.'], 400 );

    $history = get_option( 'myls_elb_desc_history', [] );
    if ( ! is_array( $history ) ) $history = [];
    $slug            = sanitize_title( $name );
    $history[ $slug ] = [ 'name' => $name, 'description' => $description, 'updated' => current_time('mysql') ];
    if ( count( $history ) > 50 ) $history = array_slice( $history, -50, 50, true );
    update_option( 'myls_elb_desc_history', $history );
    wp_send_json_success( [ 'message' => "Description \"{$name}\" saved.", 'history' => myls_elb_format_history( $history ) ] );
} );

add_action( 'wp_ajax_myls_elb_list_descriptions', function () {
    if ( ! current_user_can('manage_options') ) wp_send_json_error( [], 403 );
    $history = get_option( 'myls_elb_desc_history', [] );
    if ( ! is_array( $history ) ) $history = [];
    wp_send_json_success( [ 'history' => myls_elb_format_history( $history ) ] );
} );

add_action( 'wp_ajax_myls_elb_delete_description', function () {
    if ( ! current_user_can('manage_options') ) wp_send_json_error( ['message' => 'Forbidden'], 403 );
    if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_elb_create' ) ) wp_send_json_error( ['message' => 'Bad nonce'], 400 );
    $slug = sanitize_title( $_POST['desc_slug'] ?? '' );
    if ( empty( $slug ) ) wp_send_json_error( ['message' => 'Invalid entry.'], 400 );
    $history = get_option( 'myls_elb_desc_history', [] );
    if ( ! is_array( $history ) ) $history = [];
    $name = $history[ $slug ]['name'] ?? $slug;
    unset( $history[ $slug ] );
    update_option( 'myls_elb_desc_history', $history );
    wp_send_json_success( [ 'message' => "Deleted \"{$name}\".", 'history' => myls_elb_format_history( $history ) ] );
} );

function myls_elb_format_history( array $history ): array {
    $out = [];
    foreach ( $history as $slug => $entry ) {
        $out[] = [ 'slug' => $slug, 'name' => $entry['name'] ?? $slug, 'description' => $entry['description'] ?? '', 'updated' => $entry['updated'] ?? '' ];
    }
    usort( $out, fn( $a, $b ) => strcmp( $b['updated'], $a['updated'] ) );
    return $out;
}

/* -------------------------------------------------------------------------
 * AJAX: Debug — inspect _elementor_data stored on any post
 * Usage: wp_ajax call with action=myls_elb_debug_post&post_id=123&_wpnonce=...
 * Returns the raw stored JSON + a decoded preview so you can see exactly
 * what Elementor will try to render.
 * ------------------------------------------------------------------------- */
add_action( 'wp_ajax_myls_elb_debug_post', function () {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error( ['message' => 'Forbidden'], 403 );
    }
    if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_elb_create' ) ) {
        wp_send_json_error( ['message' => 'Bad nonce'], 400 );
    }

    $post_id = (int) ( $_POST['post_id'] ?? 0 );
    if ( $post_id <= 0 ) {
        wp_send_json_error( ['message' => 'post_id required'], 400 );
    }

    $post          = get_post( $post_id );
    $raw_json      = get_post_meta( $post_id, '_elementor_data',          true );
    $edit_mode     = get_post_meta( $post_id, '_elementor_edit_mode',     true );
    $el_version    = get_post_meta( $post_id, '_elementor_version',       true );
    $tmpl_type     = get_post_meta( $post_id, '_elementor_template_type', true );
    $css_exists    = (bool) get_post_meta( $post_id, '_elementor_css',    true );

    $decoded       = json_decode( $raw_json, true );
    $json_valid    = json_last_error() === JSON_ERROR_NONE;
    $container_count = 0;
    $widget_count    = 0;

    if ( $json_valid && is_array( $decoded ) ) {
        foreach ( $decoded as $el ) {
            if ( ( $el['elType'] ?? '' ) === 'container' ) {
                $container_count++;
                foreach ( ( $el['elements'] ?? [] ) as $w ) {
                    if ( ( $w['elType'] ?? '' ) === 'widget' ) $widget_count++;
                }
            }
        }
    }

    wp_send_json_success( [
        'post_id'          => $post_id,
        'post_title'       => $post ? $post->post_title : '(not found)',
        'post_status'      => $post ? $post->post_status : '(not found)',
        'edit_mode'        => $edit_mode,
        'elementor_version'=> $el_version,
        'template_type'    => $tmpl_type,
        'css_cache_exists' => $css_exists,
        'json_stored'      => ! empty( $raw_json ),
        'json_valid'       => $json_valid,
        'json_length'      => strlen( (string) $raw_json ),
        'container_count'  => $container_count,
        'widget_count'     => $widget_count,
        'json_preview'     => mb_substr( (string) $raw_json, 0, 500 ),
        'decoded_preview'  => $json_valid ? array_slice( (array) $decoded, 0, 2 ) : null,
    ] );
} );

/* -------------------------------------------------------------------------
 * Helper: Fetch factual context from Wikipedia for a given topic.
 *
 * Uses the Wikipedia REST API summary endpoint. Returns the extract (plain text)
 * on success, empty string on failure. Used to ground AI-generated content in
 * real facts and force original synthesis rather than generic filler.
 *
 * Falls back to a Wikidata label lookup if Wikipedia returns no useful content.
 * -------------------------------------------------------------------------*/
function myls_elb_fetch_wikipedia_context( string $topic ): string {
    if ( empty( $topic ) ) return '';

    // Normalise: title-case, spaces to underscores for the URL
    $slug = urlencode( str_replace( ' ', '_', ucwords( strtolower( trim( $topic ) ) ) ) );

    $api_url  = "https://en.wikipedia.org/api/rest_v1/page/summary/{$slug}";
    $response = wp_remote_get( $api_url, [
        'timeout'    => 8,
        'user-agent' => 'AIntelligize/1.0 (WordPress plugin; content research)',
    ] );

    if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $extract = trim( $body['extract'] ?? '' );

        // Only use if it's a useful length (not a disambiguation stub)
        if ( strlen( $extract ) > 100 && ( $body['type'] ?? '' ) !== 'disambiguation' ) {
            // Trim to ~1200 chars to keep prompt size reasonable
            return mb_substr( $extract, 0, 1200 );
        }
    }

    // ── Wikidata fallback: fetch description for the topic ──────────────
    $wd_url  = 'https://www.wikidata.org/w/api.php?' . http_build_query( [
        'action'   => 'wbsearchentities',
        'search'   => $topic,
        'language' => 'en',
        'limit'    => 1,
        'format'   => 'json',
    ] );
    $wd_resp = wp_remote_get( $wd_url, [
        'timeout'    => 6,
        'user-agent' => 'AIntelligize/1.0 (WordPress plugin; content research)',
    ] );

    if ( ! is_wp_error( $wd_resp ) && wp_remote_retrieve_response_code( $wd_resp ) === 200 ) {
        $wd = json_decode( wp_remote_retrieve_body( $wd_resp ), true );
        $desc = $wd['search'][0]['description'] ?? '';
        $label = $wd['search'][0]['label'] ?? '';
        if ( $desc ) {
            return "{$label}: {$desc}";
        }
    }

    return '';
}

/* -------------------------------------------------------------------------
 * Helper: Count text-editor widgets containing the "AI Content Here" placeholder
 * -------------------------------------------------------------------------*/
function myls_elb_count_ai_placeholders( array $elements ): int {
    $count = 0;
    foreach ( $elements as $el ) {
        if ( ( $el['elType'] ?? '' ) === 'widget'
            && ( $el['widgetType'] ?? '' ) === 'text-editor' ) {
            $val = $el['settings']['editor'] ?? '';
            if ( stripos( strip_tags( $val ), 'AI Content Here' ) !== false ) {
                $count++;
            }
        }
        if ( ! empty( $el['elements'] ) ) {
            $count += myls_elb_count_ai_placeholders( $el['elements'] );
        }
    }
    return $count;
}

/* -------------------------------------------------------------------------
 * Helper: Recursively replace "AI Content Here" in text-editor widgets
 *
 * Each matching widget gets the same generated HTML (one AI call serves all
 * placeholders in the template — typically there is only one).
 * -------------------------------------------------------------------------*/
function myls_elb_fill_ai_placeholders( array $elements, string $html ): array {
    foreach ( $elements as &$el ) {
        if ( ( $el['elType'] ?? '' ) === 'widget'
            && ( $el['widgetType'] ?? '' ) === 'text-editor' ) {
            $val = $el['settings']['editor'] ?? '';
            if ( stripos( strip_tags( $val ), 'AI Content Here' ) !== false ) {
                $el['settings']['editor'] = $html;
            }
        }
        if ( ! empty( $el['elements'] ) ) {
            $el['elements'] = myls_elb_fill_ai_placeholders( $el['elements'], $html );
        }
    }
    return $elements;
}

/* -------------------------------------------------------------------------
 * Helper: Regenerate all element IDs in an Elementor data tree
 * Prevents ID collisions when appending template containers to a generated page.
 * -------------------------------------------------------------------------*/
function myls_elb_regen_ids( array $elements ): array {
    foreach ( $elements as &$el ) {
        $el['id'] = myls_elb_uid();
        if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
            $el['elements'] = myls_elb_regen_ids( $el['elements'] );
        }
    }
    return $elements;
}

/* -------------------------------------------------------------------------
 * AJAX: Get Elementor templates for the append-template dropdown
 *
 * Returns all published posts from `elementor_library` post type with their
 * template type (_elementor_template_type meta) so the UI can group/label them.
 * -------------------------------------------------------------------------*/
add_action( 'wp_ajax_myls_elb_get_templates', function () {
    if ( ! current_user_can('manage_options') ) wp_send_json_error( [], 403 );
    if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_elb_create' ) ) wp_send_json_error( ['message' => 'Bad nonce'], 400 );

    $posts = get_posts( [
        'post_type'      => 'elementor_library',
        'post_status'    => 'publish',
        'posts_per_page' => 100,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );

    $templates = [];
    foreach ( $posts as $p ) {
        $type = get_post_meta( $p->ID, '_elementor_template_type', true ) ?: 'template';
        $templates[] = [
            'id'    => (int) $p->ID,
            'title' => $p->post_title ?: '(untitled)',
            'type'  => $type,
        ];
    }

    wp_send_json_success( [ 'templates' => $templates ] );
} );

/* =========================================================================
 * Google Knowledge Graph — context fetcher
 * Queries the Knowledge Graph Search API for an entity/topic and returns a
 * compact factual summary string suitable for injecting into AI prompts.
 *
 * Returns '' if the key is not set, the query returns no results, or the
 * request fails — caller should fall back to Wikipedia silently.
 * ========================================================================= */
if ( ! function_exists('myls_elb_fetch_kg_context') ) {
    function myls_elb_fetch_kg_context( string $topic ): string {
        // Reuse Google Places API key — same key works for Knowledge Graph Search API
        $api_key = trim( (string) get_option( 'myls_google_places_api_key', '' ) );
        if ( empty( $api_key ) ) return '';

        $url = add_query_arg( [
            'query'  => $topic,
            'limit'  => 3,
            'indent' => 'false',
            'key'    => $api_key,
        ], 'https://kgsearch.googleapis.com/v1/entities:search' );

        $response = wp_remote_get( $url, [ 'timeout' => 8, 'sslverify' => true ] );
        if ( is_wp_error( $response ) ) return '';

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $items = $body['itemListElement'] ?? [];
        if ( empty( $items ) ) return '';

        $lines = [];
        foreach ( $items as $item ) {
            $result = $item['result'] ?? [];
            $name   = $result['name'] ?? '';
            $desc   = $result['description'] ?? '';
            $detail = $result['detailedDescription']['articleBody'] ?? '';

            if ( ! $name ) continue;

            $line = $name;
            if ( $desc )    $line .= " — {$desc}";
            if ( $detail )  $line .= '. ' . mb_substr( $detail, 0, 300 );
            $lines[] = $line;
        }

        return implode( "\n", $lines );
    }
}

/* =========================================================================
 * AJAX: Test Google Knowledge Graph API key
 * Uses the Google Places API key (same key works for KG Search API).
 * ========================================================================= */
add_action( 'wp_ajax_myls_test_kg_api', function () {
    if ( ! current_user_can('manage_options') )
        wp_send_json_error( ['message' => 'Forbidden'], 403 );
    if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_api_integration_nonce' ) )
        wp_send_json_error( ['message' => 'Bad nonce'], 400 );

    $api_key = sanitize_text_field( $_POST['api_key'] ?? '' );
    if ( empty( $api_key ) )
        wp_send_json_error( ['message' => 'No API key provided.'] );

    $url = add_query_arg( [
        'query'  => 'WordPress',
        'limit'  => 1,
        'indent' => 'false',
        'key'    => $api_key,
    ], 'https://kgsearch.googleapis.com/v1/entities:search' );

    $response = wp_remote_get( $url, [ 'timeout' => 8 ] );
    if ( is_wp_error( $response ) )
        wp_send_json_error( ['message' => 'Request failed: ' . $response->get_error_message()] );

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code === 200 && ! empty( $body['itemListElement'] ) ) {
        $name = $body['itemListElement'][0]['result']['name'] ?? 'entity';
        wp_send_json_success( ['message' => "API key valid. Test query returned: \"{$name}\""] );
    } elseif ( $code === 403 ) {
        wp_send_json_error( ['message' => 'API key rejected (403). Check the key and that the Knowledge Graph Search API is enabled in Google Cloud Console.'] );
    } elseif ( $code === 400 ) {
        wp_send_json_error( ['message' => 'Bad request (400). Key may be invalid.'] );
    } else {
        wp_send_json_error( ['message' => "Unexpected response (HTTP {$code}). " . wp_remote_retrieve_body($response)] );
    }
} );
