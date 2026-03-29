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
 *   myls_elb_save_setup         – Save full Page Setup state as a named template
 *   myls_elb_list_setups        – List saved Page Setup templates
 *   myls_elb_delete_setup       – Delete a saved Page Setup template
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
 * Resolve the site's contact / CTA page URL.
 *
 * Single canonical source: myls_contact_page_id option (set in AI Content → FAQ Builder).
 * If not configured, falls back to auto-detecting /contact-us/ or /contact/ pages,
 * then to home_url('/contact-us/').
 *
 * Use this everywhere a button or CTA link is needed rather than hardcoding '/contact/'.
 *
 * @return string Absolute URL, never empty.
 */
function myls_get_contact_url(): string {
    $page_id = (int) get_option( 'myls_contact_page_id', 0 );

    // Smart default: auto-detect common contact page slugs on first call
    if ( $page_id <= 0 ) {
        $p = get_page_by_path( 'contact-us' ) ?: get_page_by_path( 'contact' );
        if ( $p && ! empty( $p->ID ) ) {
            $page_id = (int) $p->ID;
            update_option( 'myls_contact_page_id', $page_id );
        }
    }

    $url = $page_id > 0 ? (string) get_permalink( $page_id ) : '';
    if ( $url === '' ) {
        $url = home_url( '/contact-us/' );
    }
    return esc_url_raw( $url );
}

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
/**
 * Elementor Button widget helper.
 *
 * @param string $url  Full URL or root-relative path. Use the resolved $contact_url
 *                     from parse_and_build rather than a hardcoded string.
 */
function myls_elb_button_widget( string $text, string $url = '', string $align = 'center' ): array {
    // If no URL provided, resolve from the canonical contact page setting.
    if ( $url === '' ) {
        $url = myls_get_contact_url();
    }
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
function myls_elb_icon_box_widget( string $fa_icon, string $title, string $desc, string $icon_color = '#2c7be5', int $card_width = 50 ): array {
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
            '_element_width'        => 'initial',
            '_element_custom_width' => [ 'unit' => '%', 'size' => $card_width ],
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
function myls_elb_image_box_widget( int $attach_id, string $url, string $alt, string $title, string $desc, int $card_width = 50 ): array {
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
            '_element_custom_width' => [ 'unit' => '%', 'size' => $card_width ],
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
function myls_elb_image_placeholder_box_widget( string $title, string $desc, int $card_width = 50 ): array {
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
            '_element_custom_width' => [ 'unit' => '%', 'size' => $card_width ],
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
            $d['button_url']  ?? $contact_url,
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
/**
 * Build the Feature Cards section.
 *
 * Uses a Bootstrap-grid structure: each card slot gets a container with
 * a CSS class of "col col-md-{width}" so you can control layout, borders,
 * padding, and backgrounds purely through your stylesheet without touching
 * the Elementor canvas.
 *
 * Card count is derived from cols × rows.  Feature images (one per card)
 * are mapped by index — if more items exist than images, icon-box widgets
 * are used as fallback.
 *
 * @param array  $d               Parsed AI JSON for the features section.
 * @param array  $feature_images  Pre-generated image attachments indexed 0…n.
 * @param bool   $prefer_image_box Fall back to image-placeholder widgets when true.
 * @param int    $container_width Boxed width in px (from Elementor kit).
 * @param int    $cols            Bootstrap grid columns (1–6).
 * @param int    $rows            Grid rows; used only to size the AI prompt — not
 *                                enforced structurally here since the row count is
 *                                implicit from ceil(items / cols).
 * @return array Elementor container element.
 */
function myls_elb_build_features( array $d, array $feature_images = [], bool $prefer_image_box = false, int $container_width = 1140, int $cols = 3, int $rows = 1 ): array {
    $items    = (array) ( $d['items'] ?? [] );
    $expected = max( 1, $cols * $rows );

    // Pad item list to exactly cols×rows so every grid cell and every generated
    // image gets placed — even if the AI returned fewer items than requested.
    while ( count( $items ) < $expected ) {
        $n       = count( $items ) + 1;
        $items[] = [
            'icon'        => 'fas fa-star',
            'title'       => 'Feature ' . $n,
            'description' => '',
        ];
    }
    // Trim to expected in case AI returned more
    $items = array_slice( $items, 0, $expected );

    $boxes = [];

    foreach ( $items as $idx => $item ) {
        $img = $feature_images[ $idx ] ?? [];

        // Build the inner widget (image-box or icon-box)
        if ( ! empty( $img['id'] ) && ! empty( $img['url'] ) ) {
            $inner_widget = myls_elb_image_box_widget(
                (int) $img['id'],
                $img['url'],
                $item['title'] ?? $img['alt'] ?? '',
                $item['title'] ?? '',
                $item['description'] ?? '',
                100  // widget fills the grid cell
            );
        } elseif ( $prefer_image_box ) {
            $inner_widget = myls_elb_image_placeholder_box_widget(
                $item['title']       ?? '',
                $item['description'] ?? '',
                100
            );
        } else {
            $inner_widget = myls_elb_icon_box_widget(
                $item['icon']        ?? 'fas fa-star',
                $item['title']       ?? '',
                $item['description'] ?? '',
                myls_elb_icon_color( $idx ),
                100  // widget fills the grid cell
            );
        }

        // Each card is an independent Elementor container — grid item.
        // full content_width so it expands to fill the grid cell naturally.
        // CSS class 'elb-feature-card' for per-card stylesheet targeting.
        $card_container = myls_elb_section( [ $inner_widget ], [
            'container_type' => 'flex',
            'flex_direction' => 'column',
            'flex_align_items' => 'center',
            'content_width'  => 'full',
            '_css_classes'   => 'elb-feature-card',
            'padding'        => [ 'unit' => 'px', 'top' => '24', 'right' => '16', 'bottom' => '24', 'left' => '16', 'isLinked' => false ],
        ], true );

        $boxes[] = $card_container;
    }

    // ── Inner grid container — Elementor CSS Grid ───────────────────────
    // container_type: 'grid' activates Elementor's Grid layout for this container.
    // grid_columns_number controls columns; rows are auto-created as items fill.
    // Boxed so the grid respects the kit max-width; background on outer container
    // spans full-width edge-to-edge.
    $grid_row = myls_elb_section( $boxes, [
        'container_type'     => 'grid',
        'grid_columns_number' => [ 'unit' => 'fr', 'size' => max( 1, min( 6, $cols ) ) ],
        'grid_columns_grid'  => [ 'unit' => 'fr', 'size' => max( 1, min( 6, $cols ) ), 'sizes' => [] ],
        'grid_auto_flow'     => 'row',
        'grid_columns_gap'   => [ 'unit' => 'em', 'size' => 1.5 ],
        'grid_rows_gap'      => [ 'unit' => 'em', 'size' => 1.5 ],
        'content_width'      => 'boxed',
        'boxed_width'        => [ 'unit' => 'px', 'size' => $container_width ],
        '_css_classes'       => 'elb-features-grid',
        'padding'            => [ 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => false ],
    ], true );

    // ── Outer 100%-wide section: heading + boxed grid ───────────────────
    return myls_elb_section( [
        myls_elb_heading_widget( $d['heading'] ?? '', 'h2', 'center' ),
        $grid_row,
    ], [
        'background_background' => 'classic',
        'background_color'      => '#f8f9fa',
        'content_width'         => 'full',
        'flex_direction'        => 'column',
        'flex_align_items'      => 'center',
        'padding'               => [ 'unit' => 'px', 'top' => '60', 'right' => '20', 'bottom' => '60', 'left' => '20', 'isLinked' => false ],
    ] );
}


/**
 * Rich Content Section — between Feature Cards and How It Works.
 *
 * A full-width outer container → boxed inner container → text_editor widget.
 * AI generates 200–300 words of structured HTML: H3 question sub-heading,
 * 2 tight wiki-voice paragraphs, and one 4–5 item <ul> of specific facts.
 * This is the citation-density layer — each paragraph and list item is an
 * independent chunk the AI can extract without surrounding context.
 *
 *   L1 — full-width outer flex container (white background)
 *   L2 — boxed inner flex container (isInner: true)
 *   L3 — text_editor widget with pre-formatted HTML
 */
function myls_elb_build_rich_content( array $d, int $container_width = 1140 ): array {
    $html = trim( (string) ( $d['html'] ?? '' ) );
    if ( $html === '' ) {
        $html = '<h3>Why Professional Service Delivers Better Results</h3><p>Professional-grade equipment and surface-specific techniques produce results that consumer-grade tools cannot replicate. Technicians assess each surface individually before selecting pressure levels, nozzle types, and cleaning agents.</p>';
    }

    // L2 — boxed inner container (isInner: true)
    $inner = myls_elb_section(
        [ myls_elb_text_editor_widget( $html ) ],
        [
            'container_type' => 'flex',
            'flex_direction'  => 'column',
            'content_width'   => 'boxed',
            'boxed_width'     => [ 'unit' => 'px', 'size' => $container_width ],
            '_css_classes'    => 'elb-rich-content',
            'padding'         => [ 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => false ],
        ],
        true
    );

    // L1 — full-width outer container
    return myls_elb_section( [ $inner ], [
        'background_background' => 'classic',
        'background_color'      => '#f8f9fa',
        'content_width'         => 'full',
        'flex_direction'        => 'column',
        'flex_align_items'      => 'center',
        'padding'               => [ 'unit' => 'px', 'top' => '60', 'right' => '20', 'bottom' => '60', 'left' => '20', 'isLinked' => false ],
    ] );
}

function myls_elb_build_process( array $d, int $container_width = 1140, int $cols = 2, bool $prefer_image_box = false ): array {
    $steps = (array) ( $d['steps'] ?? [] );
    $cells = [];

    foreach ( $steps as $idx => $step ) {
        $title  = ( $idx + 1 ) . '. ' . ( $step['title'] ?? '' );
        if ( $prefer_image_box ) {
            $widget = myls_elb_image_placeholder_box_widget(
                $title,
                $step['description'] ?? '',
                100
            );
        } else {
            $widget = myls_elb_icon_box_widget(
                $step['icon']        ?? 'fas fa-check-circle',
                $title,
                $step['description'] ?? '',
                myls_elb_icon_color( $idx ),
                100
            );
        }

        // Level 3 — flex container per step (isInner: true), holds the icon box.
        // content_width: full so it fills its grid cell edge-to-edge.
        $cells[] = myls_elb_section( [ $widget ], [
            'container_type' => 'flex',
            'flex_direction' => 'column',
            'content_width'  => 'full',
            '_css_classes'   => 'elb-process-step',
            'padding'        => [ 'unit' => 'px', 'top' => '24', 'right' => '16', 'bottom' => '24', 'left' => '16', 'isLinked' => false ],
        ], true );
    }

    // Level 2 — Elementor CSS Grid container.
    // container_type: 'grid' activates grid layout.
    // content_width: 'boxed' constrains to kit max-width.
    // grid_rows_number: 2 sets an explicit 2-row template so Elementor
    // shows the row control; items fill row-by-row automatically.
    $rows = (int) ceil( count( $cells ) / max( 1, $cols ) );  // auto-fit rows to item count
    $inner_grid = myls_elb_section( $cells, [
        'container_type'      => 'grid',
        'grid_columns_number' => [ 'unit' => 'fr', 'size' => max( 1, min( 6, $cols ) ) ],
        'grid_columns_grid'   => [ 'unit' => 'fr', 'size' => max( 1, min( 6, $cols ) ), 'sizes' => [] ],
        'grid_rows_number'    => [ 'unit' => 'fr', 'size' => max( 1, $rows ) ],
        'grid_auto_flow'      => 'row',
        'grid_columns_gap'    => [ 'unit' => 'em', 'size' => 1.5 ],
        'grid_rows_gap'       => [ 'unit' => 'em', 'size' => 1.5 ],
        'content_width'       => 'boxed',
        'boxed_width'         => [ 'unit' => 'px', 'size' => $container_width ],
        '_css_classes'        => 'elb-process-grid',
        'padding'             => [ 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => false ],
    ], true );

    // Level 1 — outer 100%-wide flex column container.
    // Full-width so a background colour can span edge-to-edge.
    // Heading sits above the boxed grid.
    return myls_elb_section( [
        myls_elb_heading_widget( $d['heading'] ?? '', 'h2', 'left' ),
        $inner_grid,
    ], [
        'container_type'  => 'flex',
        'flex_direction'  => 'column',
        'flex_align_items'=> 'center',
        'content_width'   => 'full',
        'padding'         => [ 'unit' => 'px', 'top' => '60', 'right' => '20', 'bottom' => '60', 'left' => '20', 'isLinked' => false ],
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


/**
 * TL;DR Block — Phase 2.
 *
 * A concise 1–3 sentence direct answer placed immediately after the Hero.
 * This is the first content AI crawlers extract; structured as a "grab-and-go"
 * citation snippet for Google AI Overviews and ChatGPT.
 *
 *   [Container: white, boxed, green left-border accent, compact padding]
 *     Text Editor (1–3 sentences, italic, entity-anchored)
 */
function myls_elb_build_tldr( array $d, int $container_width = 1140 ): array {
    $text = trim( $d['text'] ?? '' );
    if ( $text === '' ) {
        $text = 'Professional service delivered by licensed, insured specialists.';
    }

    $widget = myls_elb_text_editor_widget(
        '<p style="font-size:1.05em;line-height:1.7;color:#2d3a4a;font-style:italic;border-left:4px solid #10b981;padding-left:1rem;margin:0;">'
        . esc_html( $text ) . '</p>'
    );

    return myls_elb_section( [ $widget ], [
        'background_background' => 'classic',
        'background_color'      => '#ffffff',
        'content_width'         => 'boxed',
        'boxed_width'           => [ 'unit' => 'px', 'size' => $container_width ],
        'flex_direction'        => 'column',
        'padding'               => [ 'unit' => 'px', 'top' => '28', 'right' => '20', 'bottom' => '28', 'left' => '20', 'isLinked' => false ],
    ] );
}

/**
 * Trust Bar — Phase 2.
 *
 * A full-width amber/gold stats strip of 4 hard numbers (rating, reviews,
 * award, credential). AI processes these as discrete structured facts rather
 * than marketing copy, boosting citation confidence score.
 *
 *   [Container: amber-tinted, full-width, flex-row]
 *     Icon Box × 4  (stat as title, label as description)
 */
/**
 * Trust Bar — Phase 2.
 *
 * Matches the exact 3-level structure of Feature Cards and How It Works:
 *   L1 — full-width outer flex container (amber background, edge-to-edge)
 *   L2 — CSS Grid: 4 cols × 1 row, boxed to kit width (isInner: true)
 *   L3 — flex container per stat cell (isInner: true)
 *   L4 — icon_box widget (100% width, fills its grid cell)
 *
 * 4 hard numbers from Business Profile: rating, reviews, award, credential.
 * Each stat is a discrete entity the AI reads independently.
 */
/**
 * Trust Bar — Phase 2.
 *
 * Guaranteed single-row strip of 4 credential stats.
 *
 * WHY FLEX NOT GRID:
 * CSS Grid column count via JSON injection is unreliable across Elementor
 * versions — the responsive column control can silently default to 2-3 cols,
 * causing the 4 stat cells to wrap into 2 rows. A flex-row inner container
 * with explicit 25%-width cells is deterministic: always 1 row, never wraps.
 *
 * Structure (matches the 3-level pattern of Feature Cards / How It Works):
 *   L1 — full-width outer flex container (amber background, edge-to-edge)
 *   L2 — boxed flex-row inner container (isInner: true) — always 1 row
 *   L3 — flex column container per stat cell (isInner: true) — 25% width
 *   L4 — icon_box widget at 100% width inside each cell
 */
function myls_elb_build_trust_bar( array $d, int $container_width = 1140 ): array {
    $stats = array_values( (array) ( $d['stats'] ?? [] ) );

    // Fallback stats if AI returned nothing
    if ( empty( $stats ) ) {
        $stats = [
            [ 'icon' => 'fas fa-star',       'stat' => '5.0★',     'label' => 'Google Rating'    ],
            [ 'icon' => 'fas fa-users',       'stat' => '893+',     'label' => 'Verified Reviews' ],
            [ 'icon' => 'fas fa-shield-alt',  'stat' => 'Licensed', 'label' => '& Insured'        ],
            [ 'icon' => 'fas fa-medal',        'stat' => '#1',       'label' => 'Local Award'      ],
        ];
    }

    $icon_colors = [ '#d97706', '#b45309', '#92400e', '#78350f' ]; // amber shades
    $cells       = [];

    foreach ( array_slice( $stats, 0, 4 ) as $idx => $s ) {
        // L4 — icon_box widget, 100% fills its cell container
        $widget = myls_elb_icon_box_widget(
            $s['icon']  ?? 'fas fa-star',
            $s['stat']  ?? '',
            $s['label'] ?? '',
            $icon_colors[ $idx ] ?? '#d97706',
            100
        );
        $widget['settings']['title_color']       = '#1a1a1a';
        $widget['settings']['description_color'] = '#4b3a00';

        // L3 — flex column container per stat, 25% width (isInner: true).
        // _element_width + _element_custom_width set the 25% within the flex-row L2.
        // Mobile: 50% width so 4 stats stack as a clean 2×2 grid.
        // Tablet: keep 25% (4-across still fits on tablet/iPad viewports).
        $cells[] = myls_elb_section( [ $widget ], [
            'container_type'              => 'flex',
            'flex_direction'              => 'column',
            'flex_align_items'            => 'center',
            'content_width'               => 'full',
            '_css_classes'                => 'elb-trust-stat',
            // Desktop: 25% — 4 stats in one row
            '_element_width'              => 'initial',
            '_element_custom_width'       => [ 'unit' => '%', 'size' => 25 ],
            // Mobile: 50% — 4 stats become 2×2
            '_element_width_mobile'       => 'initial',
            '_element_custom_width_mobile'=> [ 'unit' => '%', 'size' => 50 ],
            'padding'                     => [ 'unit' => 'px', 'top' => '20', 'right' => '16', 'bottom' => '20', 'left' => '16', 'isLinked' => false ],
        ], true );
    }

    // L2 — flex-row inner container, boxed to kit max-width (isInner: true).
    // Desktop: nowrap — guaranteed single row.
    // Mobile: wrap — allows the 50%-wide cells to flow into a 2×2 grid.
    $row = myls_elb_section( $cells, [
        'container_type'           => 'flex',
        'flex_direction'           => 'row',
        'flex_wrap'                => 'nowrap',      // desktop: 1 row
        'flex_wrap_mobile'         => 'wrap',         // mobile: allow 2×2 wrap
        'flex_align_items'         => 'center',
        'flex_justify_content'     => 'space-around',
        'content_width'            => 'boxed',
        'boxed_width'              => [ 'unit' => 'px', 'size' => $container_width ],
        '_css_classes'             => 'elb-trust-row',
        'padding'                  => [ 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => false ],
    ], true );

    // L1 — full-width outer container (amber band spans edge-to-edge)
    return myls_elb_section( [ $row ], [
        'background_background' => 'classic',
        'background_color'      => '#fffbeb',
        'content_width'         => 'full',
        'flex_direction'        => 'column',
        'flex_align_items'      => 'center',
        'padding'               => [ 'unit' => 'px', 'top' => '32', 'right' => '20', 'bottom' => '32', 'left' => '20', 'isLinked' => false ],
        'border_border'         => 'solid',
        'border_color'          => '#fde68a',
        'border_width'          => [ 'unit' => 'px', 'top' => '1', 'right' => '0', 'bottom' => '1', 'left' => '0', 'isLinked' => false ],
    ] );
}


/**
 * Pricing Section — Phase 2.
 *
 * Machine-readable price ranges pulled from myls_service_price_ranges.
 * Displayed as an HTML table so AI can extract structured price data,
 * preventing hallucinated prices in AI Overview responses.
 *
 * Does NOT use AI-generated content — data comes directly from the
 * Service Schema → Price Ranges settings saved in v7.8.43.
 *
 *   [Container: light purple-tinted, boxed, standard padding]
 *     Heading (H2 — question format)
 *     HTML Widget (price table)
 *     Text Editor (caveat line)
 *
 * @param int   $post_id         Current post ID (used to match per-post ranges).
 * @param array $d               AI JSON pricing key (heading/city tokens from prompt).
 * @param int   $container_width Elementor kit container width in px.
 */
function myls_elb_build_pricing( int $post_id, array $d, int $container_width = 1140 ): array {
    // ── Heading and caveat from AI JSON (optional) ───────────────────────
    // Note: price data is no longer read here — [myls_pricing_table] does it
    // at render time so edits are live without page regeneration.
    $heading = trim( $d['heading'] ?? '' );
    if ( $heading === '' ) {
        $heading = 'How Much Does This Service Cost?';
    }

    $caveat = trim( $d['caveat'] ?? 'Final pricing depends on surface area, condition, and access. Contact us for a free, no-obligation written estimate.' );

    // ── Shortcode widget instead of static HTML ───────────────────────────
    // [myls_pricing_table] reads myls_service_price_ranges at render time so
    // any price range additions or edits are reflected on the page immediately
    // without regenerating the Elementor layout. post_id is baked in at
    // generation time so the correct per-post ranges are always shown.
    $sc_post_id = $post_id > 0 ? $post_id : '__current__';
    $widgets    = [
        myls_elb_heading_widget( $heading, 'h2', 'left' ),
        myls_elb_shortcode_widget( '[myls_pricing_table post_id="' . $sc_post_id . '"]' ),
        myls_elb_text_editor_widget(
            '<p style="color:#6b7280;font-size:13px;margin-top:.75rem;">' . esc_html( $caveat ) . '</p>'
        ),
    ];

    return myls_elb_section( $widgets, [
        'background_background' => 'classic',
        'background_color'      => '#f5f3ff',   // violet-50
        'content_width'         => 'boxed',
        'boxed_width'           => [ 'unit' => 'px', 'size' => $container_width ],
        'flex_direction'        => 'column',
        'padding'               => [ 'unit' => 'px', 'top' => '60', 'right' => '20', 'bottom' => '60', 'left' => '20', 'isLinked' => false ],
    ] );
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
            $d['button_url']  ?? $contact_url,
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
            'tldr_text'     => '',
            'error'         => 'AI output was not valid JSON — used HTML widget fallback. Error: ' . json_last_error_msg(),
        ];
    }

    $elements      = [];
    $all_faqs      = [];
    $section_count = 0;
    $tldr_text     = '';

    // Separate generated images by type for passing to section builders
    $hero_image     = [];
    $feature_images = [];
    foreach ( $generated_images as $img ) {
        if ( $img['type'] === 'hero' ) {
            $hero_image = $img;
        } elseif ( $img['type'] === 'feature_card' ) {
            // Per-card images — indexed to map 1:1 with feature card slots
            $feature_images[] = $img;
        }
    }

    $container_width = (int) ( $kit['container_width'] ?? 1140 );

    // Resolved CTA / button URL — from section_flags (set by AJAX handler) or
    // canonical myls_contact_page_id option. Falls back gracefully.
    $contact_url = trim( (string) ( $section_flags['contact_url'] ?? '' ) );
    if ( $contact_url === '' ) {
        $contact_url = myls_get_contact_url();
    }

    // ── Build page elements in declared order (sections_order) ───────────
    // sections_order is an array of items like:
    //   { id: 'hero', type: 'section', enabled: true }
    //   { id: 'tpl_abc', type: 'template', enabled: true, template_id: 123, cols: 3, rows: 1 }
    //
    // Backward compat: if no sections_order was passed, derive from section_flags.
    $sections_order = $section_flags['sections_order'] ?? [];
    if ( empty( $sections_order ) ) {
        $old_cols = (int) ( $section_flags['cols'] ?? 3 );
        $old_rows = (int) ( $section_flags['rows'] ?? 1 );
        $sections_order = [
            [ 'id'=>'hero',       'type'=>'section', 'enabled'=> (bool) ( $section_flags['hero']       ?? true ) ],
            [ 'id'=>'tldr',       'type'=>'section', 'enabled'=> (bool) ( $section_flags['tldr']       ?? true ) ],
            [ 'id'=>'intro',      'type'=>'section', 'enabled'=> (bool) ( $section_flags['intro']      ?? true ) ],
            [ 'id'=>'trust_bar',  'type'=>'section', 'enabled'=> (bool) ( $section_flags['trust_bar']  ?? true ) ],
            [ 'id'=>'features',     'type'=>'section', 'enabled'=> (bool) ( $section_flags['features']     ?? true ), 'cols'=>$old_cols, 'rows'=>$old_rows ],
            [ 'id'=>'rich_content', 'type'=>'section', 'enabled'=> (bool) ( $section_flags['rich_content'] ?? true ) ],
            [ 'id'=>'process',      'type'=>'section', 'enabled'=> (bool) ( $section_flags['process']      ?? true ) ],
            [ 'id'=>'pricing',    'type'=>'section', 'enabled'=> (bool) ( $section_flags['pricing']    ?? true ) ],
            // faq removed — FAQs generated post-creation via FAQ Builder tab
            [ 'id'=>'cta',        'type'=>'section', 'enabled'=> (bool) ( $section_flags['cta']        ?? true ) ],
        ];
        // Append legacy template IDs at bottom
        foreach ( (array) ( $section_flags['template_ids'] ?? [] ) as $tid ) {
            if ( $tid ) $sections_order[] = [ 'id'=>'tpl_'.$tid, 'type'=>'template', 'enabled'=>true, 'template_id'=>(int)$tid ];
        }
    }

    // Pre-compute template fill context (Wikipedia + KG) used for template placeholder AI fill
    $tpl_context_loaded = false;
    $tpl_kg_ctx   = '';
    $tpl_wiki_ctx = '';
    $tpl_log_pre  = [];

    foreach ( $sections_order as $item ) {
        if ( ( $item['type'] ?? '' ) === 'template' && ( $item['enabled'] ?? true ) && ! empty( $item['template_id'] ) ) {
            if ( ! $tpl_context_loaded ) {
                $tpl_topic = $section_flags['seo_keyword'] ?? $section_flags['page_title'] ?? '';
                $tpl_kg_ctx = function_exists('myls_elb_fetch_kg_context') ? myls_elb_fetch_kg_context( $tpl_topic ) : '';
                if ( $tpl_kg_ctx ) $tpl_log_pre[] = '🔍 Knowledge Graph context fetched for: "' . esc_html( $tpl_topic ) . '"';
                $tpl_wiki_ctx = myls_elb_fetch_wikipedia_context( $tpl_topic );
                if ( $tpl_wiki_ctx ) $tpl_log_pre[] = '🌐 Wikipedia context fetched for: "' . esc_html( $tpl_topic ) . '"';
                $tpl_context_loaded = true;
            }
            break;
        }
    }

    $tpl_slot_index = 0;

    foreach ( $sections_order as $item ) {
        $type    = $item['type']    ?? 'section';
        $enabled = $item['enabled'] ?? true;
        if ( ! $enabled ) continue;

        if ( $type === 'section' ) {
            $sid = $item['id'] ?? '';
            switch ( $sid ) {
                case 'hero':
                    if ( ! empty( $data['hero'] ) ) {
                        $elements[] = myls_elb_build_hero( (array) $data['hero'], $hero_image );
                        $section_count++;
                    }
                    break;

                case 'tldr':
                    if ( ! empty( $data['tldr'] ) ) {
                        $tldr_text  = trim( $data['tldr']['text'] ?? '' );
                        $elements[] = myls_elb_build_tldr( (array) $data['tldr'], $container_width );
                        $section_count++;
                    }
                    break;

                case 'trust_bar':
                    if ( ! empty( $data['trust_bar'] ) ) {
                        $elements[] = myls_elb_build_trust_bar( (array) $data['trust_bar'], $container_width );
                        $section_count++;
                    }
                    break;

                case 'intro':
                    if ( ! empty( $data['intro'] ) ) {
                        $elements[] = myls_elb_build_intro( (array) $data['intro'], $container_width );
                        $section_count++;
                    }
                    break;

                case 'features':
                    if ( ! empty( $data['features'] ) ) {
                        $fcols = max( 1, min( 6, (int) ( $item['cols'] ?? 3 ) ) );
                        $frows = max( 1, min( 6, (int) ( $item['rows'] ?? 1 ) ) );
                        $feat_widget_type = $item['widget_type'] ?? 'icon';
                        // Use image-box widgets when:
                        // 1. User explicitly chose "image" widget type, OR
                        // 2. Images exist OR deferred image gen is pending (legacy auto-detect)
                        $use_image_boxes = ( $feat_widget_type === 'image' )
                            || ! empty( $feature_images )
                            || ( ! empty( $section_flags['integrate_images'] ) && ! empty( $section_flags['gen_feature_cards'] ) );
                        $elements[] = myls_elb_build_features( (array) $data['features'], $feature_images, $use_image_boxes, $container_width, $fcols, $frows );
                        $section_count++;
                    }
                    break;

                case 'rich_content':
                    if ( ! empty( $data['rich_content'] ) ) {
                        $elements[] = myls_elb_build_rich_content( (array) $data['rich_content'], $container_width );
                        $section_count++;
                    }
                    break;

                case 'process':
                    if ( ! empty( $data['process'] ) ) {
                        $pcols = max( 1, min( 6, (int) ( $item['cols'] ?? 2 ) ) );
                        $proc_widget_type = $item['widget_type'] ?? 'icon';
                        $proc_prefer_image = ( $proc_widget_type === 'image' );
                        $elements[] = myls_elb_build_process( (array) $data['process'], $container_width, $pcols, $proc_prefer_image );
                        $section_count++;
                    }
                    break;

                case 'pricing':
                    // Pricing section always renders — [myls_pricing_table] inside it
                    // reads myls_service_price_ranges live at page-render time. If no
                    // ranges are configured the shortcode returns '' and only the heading
                    // and caveat show. This allows ranges added after page generation to
                    // appear automatically without regenerating the page.
                    $pricing_d  = is_array( $data['pricing'] ?? null ) ? (array) $data['pricing'] : [];
                    $build_post = (int) ( $section_flags['post_id'] ?? 0 );
                    $elements[] = myls_elb_build_pricing( $build_post, $pricing_d, $container_width );
                    $section_count++;
                    break;

                case 'faq':
                    if ( ! empty( $data['faq'] ) ) {
                        $faq_result  = myls_elb_build_faq( (array) $data['faq'], $container_width );
                        $elements[]  = $faq_result['container'];
                        $all_faqs    = $faq_result['faqs'];
                        $section_count++;
                    }
                    break;

                case 'cta':
                    if ( ! empty( $data['cta'] ) ) {
                        $elements[] = myls_elb_build_cta( (array) $data['cta'] );
                        $section_count++;
                    }
                    break;
            }

        } elseif ( $type === 'template' ) {
            $tpl_id = (int) ( $item['template_id'] ?? 0 );
            if ( ! $tpl_id ) continue;

            // ── Strip stale page settings from the template post ──────────
            // Elementor Library templates often carry _elementor_page_settings
            // populated by importers (e.g. Astra Starter Templates astra_sites_*
            // font/color overrides). When we write _elementor_data to the host page
            // Elementor's updated_post_meta hook looks up the source template's
            // _elementor_page_settings and copies them onto the host page — causing
            // layout and header/menu corruption. Deleting them from the template
            // permanently prevents the bleed for all future generations too.
            $tpl_page_settings = get_post_meta( $tpl_id, '_elementor_page_settings', true );
            if ( ! empty( $tpl_page_settings ) ) {
                delete_post_meta( $tpl_id, '_elementor_page_settings' );
                $tpl_log_pre[] = '🧹 Stripped stale _elementor_page_settings from template ' . $tpl_id . ' (' . count( (array) $tpl_page_settings ) . ' keys removed).';
            }

            $tpl_data = get_post_meta( $tpl_id, '_elementor_data', true );
            if ( ! $tpl_data ) continue;

            $tpl_elements = json_decode( $tpl_data, true );
            if ( ! is_array( $tpl_elements ) || empty( $tpl_elements ) ) continue;

            $tpl_elements = myls_elb_regen_ids( $tpl_elements );
            $tpl_slot_index++;

            // Fill AI-Content / AI-H2 / AI-H3 placeholders
            $ph_counts = myls_elb_get_placeholder_counts( $tpl_elements );
            if ( $ph_counts['total'] > 0 ) {
                $focus = $section_flags['seo_keyword'] ?? $section_flags['page_title'] ?? '';
                $slot_angles = [
                    1 => 'benefits and value proposition',
                    2 => 'process, methodology and what to expect',
                    3 => 'local relevance, trust factors and why choose us',
                ];
                $angle = $slot_angles[ $tpl_slot_index ] ?? 'key information and details';

                $page_title_ctx = $section_flags['page_title'] ?? '';
                $desc_ctx       = $section_flags['description'] ?? '';

                $ai_fill_prompt  = "You are filling placeholder widgets in an Elementor template.\n\n";
                $ai_fill_prompt .= "Context:\n";
                $ai_fill_prompt .= "- Page topic: {$focus}\n";
                $ai_fill_prompt .= "- Page title: {$page_title_ctx}\n";
                $ai_fill_prompt .= "- Section angle: {$angle}\n";
                if ( $desc_ctx ) {
                    $ai_fill_prompt .= '- Page description: ' . mb_substr( wp_strip_all_tags( $desc_ctx ), 0, 400 ) . "\n";
                }
                if ( $tpl_kg_ctx )   $ai_fill_prompt .= "\nKnowledge Graph facts (rewrite in your own words, do not copy):\n" . $tpl_kg_ctx . "\n";
                if ( $tpl_wiki_ctx ) $ai_fill_prompt .= "\nWikipedia reference (synthesize only, do not copy):\n" . $tpl_wiki_ctx . "\n";

                $ai_fill_prompt .= "\n== CRITICAL: GEO WRITING RULES ==\n";
                $ai_fill_prompt .= "These rules apply to every word. No exceptions.\n\n";
                $ai_fill_prompt .= "WIKI-VOICE: Write declaratively — as if for an encyclopedia entry.\n";
                $ai_fill_prompt .= "Remove ALL first-person and subjective phrases entirely.\n";
                $ai_fill_prompt .= "  BAD:  \"Our specialists utilize cutting-edge equipment tailored to your needs.\"\n";
                $ai_fill_prompt .= "  BAD:  \"We employ variable pressure settings for your surfaces.\"\n";
                $ai_fill_prompt .= "  BAD:  \"Experience the difference our team makes for your property.\"\n";
                $ai_fill_prompt .= "  GOOD: \"Professional pressure washing equipment delivers 1,500–4,000 PSI, removing embedded organic growth that consumer washers operating under 1,500 PSI cannot dislodge.\"\n";
                $ai_fill_prompt .= "  GOOD: \"Variable pressure settings — from 500 PSI soft washing for stucco to 4,000 PSI for concrete — prevent surface damage while achieving commercial-grade cleaning results.\"\n\n";
                $ai_fill_prompt .= "FACT DENSITY: Replace vague claims with specific measurements.\n";
                $ai_fill_prompt .= "  BAD:  \"Regular cleaning keeps surfaces looking great.\"\n";
                $ai_fill_prompt .= "  GOOD: \"Professional exterior cleaning scheduled every 12–18 months prevents the mold penetration that occurs in Florida's 74% average humidity environment within 6–12 months.\"\n\n";
                $ai_fill_prompt .= "ISLAND TEST: Every paragraph and list item must make sense in isolation.\n";
                $ai_fill_prompt .= "Replace all pronouns (it, they, this, our, we, us) with the specific noun.\n";
                $ai_fill_prompt .= "  BAD:  \"It prevents this from happening on your surfaces.\"\n";
                $ai_fill_prompt .= "  GOOD: \"Professional pressure washing prevents mold colonization from occurring on concrete driveways and pool decks.\"\n\n";
                $ai_fill_prompt .= "BANNED PHRASES — never use these:\n";
                $ai_fill_prompt .= "\"remarkable transformations\", \"cutting-edge\", \"tailored solutions\", \"dedicated to\",\n";
                $ai_fill_prompt .= "\"committed to excellence\", \"world-class\", \"state-of-the-art\", \"experience the difference\",\n";
                $ai_fill_prompt .= "\"we are proud\", \"our passion\", \"look no further\", \"our team\", \"we believe\"\n\n";
                $ai_fill_prompt .= "== SECTION ANGLES ==\n";
                $ai_fill_prompt .= "Use the angle to focus each block:\n";
                $ai_fill_prompt .= "  \"benefits and value proposition\" → measurable outcomes, prevention costs, property value. All declarative.\n";
                $ai_fill_prompt .= "  \"process, methodology and what to expect\" → technique names, equipment specs, step sequences, certifications.\n";
                $ai_fill_prompt .= "  \"local relevance, trust factors and why choose us\" → local climate facts, verifiable credentials, review counts. Third-person declarative only.\n\n";
                $ai_fill_prompt .= "== CONTENT STRUCTURE ==\n";
                $ai_fill_prompt .= "For each AI-Content placeholder, write exactly:\n";
                $ai_fill_prompt .= "  1. <h3> — question sub-heading (5–9 words, matches the section angle)\n";
                $ai_fill_prompt .= "  2. <p>  — 60–80 words, wiki-voice, specific technique or measurement, Island Test pass\n";
                $ai_fill_prompt .= "  3. <ul> — 3–4 <li> items, each starting with <strong>key point label:</strong> followed by a specific fact\n";
                $ai_fill_prompt .= "  4. <p>  — 40–60 words, different angle from opening paragraph, Island Test pass\n";
                $ai_fill_prompt .= "  Allowed tags only: <h3> <p> <ul> <li> <strong>\n";
                $ai_fill_prompt .= "  Total per block: 200–280 words\n\n";
                $ai_fill_prompt .= "For each AI-H2 placeholder: single plain-text H2 heading, question format preferred, 6–10 words, no marketing language, no first-person.\n";
                $ai_fill_prompt .= "For each AI-H3 placeholder: single plain-text H3 heading, 4–8 words, factual, descriptive.\n\n";
                $ai_fill_prompt .= "== OUTPUT FORMAT ==\n";
                $ai_fill_prompt .= "Return a JSON object with exactly these keys:\n";
                $ai_fill_prompt .= "  content_blocks: array of {$ph_counts['content']} HTML string(s) per CONTENT STRUCTURE above\n";
                $ai_fill_prompt .= "  h2_headings: array of {$ph_counts['h2']} plain-text H2 heading string(s)\n";
                $ai_fill_prompt .= "  h3_headings: array of {$ph_counts['h3']} plain-text H3 heading string(s)\n";
                $ai_fill_prompt .= "Output ONLY the JSON object. No markdown. No code fences. Start with { end with }.";

                $ai_fill_raw = function_exists('myls_ai_chat') ? myls_ai_chat( $ai_fill_prompt, [
                    'max_tokens'  => 1600,
                    'temperature' => 0.75,
                    'system'      => 'You are a content writer for Elementor WordPress pages. Output ONLY valid JSON — no markdown, no code fences. Start with { end with }.',
                ] ) : '';

                if ( $ai_fill_raw ) {
                    $ai_fill_clean = preg_replace( '/^```(?:json)?\s*/i', '', trim( $ai_fill_raw ) );
                    $ai_fill_clean = preg_replace( '/\s*```$/', '', $ai_fill_clean );
                    $ai_fill_data  = json_decode( trim( $ai_fill_clean ), true );
                    if ( json_last_error() === JSON_ERROR_NONE && is_array( $ai_fill_data ) ) {
                        $content_blocks = array_map( 'wp_kses_post',        (array) ( $ai_fill_data['content_blocks'] ?? [] ) );
                        $h2_headings    = array_map( 'sanitize_text_field', (array) ( $ai_fill_data['h2_headings']    ?? [] ) );
                        $h3_headings    = array_map( 'sanitize_text_field', (array) ( $ai_fill_data['h3_headings']    ?? [] ) );
                        $cursors = [];
                        $tpl_elements = myls_elb_fill_all_placeholders( $tpl_elements, $content_blocks, $h2_headings, $h3_headings, $cursors );
                        $tpl_log_pre[] = "✍️ Template {$tpl_slot_index}: AI filled placeholder(s) (angle: {$angle}).";
                    }
                }
            }

            // Append template elements at the current position in the page
            foreach ( $tpl_elements as $tel ) {
                $elements[] = $tel;
                $section_count++;
            }
            $tpl_log_pre[] = '📎 Template ' . $tpl_slot_index . ' inserted: "' . get_the_title( $tpl_id ) . '" (' . count( $tpl_elements ) . ' container(s))';

            // ── Generate DALL-E images for blank image widgets in this template ──
            // Runs immediately after insertion so images appear in the final output.
            // Caps at 5 images total across all templates (cost guard).
            static $tpl_imgs_generated = 0;
            $max_tpl_imgs = 5;

            $integrate = (bool) ( $section_flags['integrate_images'] ?? false );
            $tpl_api_key = (string) ( $section_flags['dalle_api_key'] ?? '' );

            if ( $integrate
                 && $tpl_api_key !== ''
                 && $tpl_imgs_generated < $max_tpl_imgs
                 && function_exists('myls_elb_find_empty_image_widgets')
                 && function_exists('myls_pb_dall_e_generate') ) {

                $empty_widgets = myls_elb_find_empty_image_widgets( $tpl_elements );

                if ( ! empty( $empty_widgets ) ) {
                    $tpl_log_pre[] = '🖼️ Found ' . count( $empty_widgets ) . ' empty image widget(s) in template ' . $tpl_slot_index . ' — generating…';

                    $img_style      = (string) ( $section_flags['image_style'] ?? 'photo' );
                    $focus_kw       = $section_flags['seo_keyword'] ?? $section_flags['page_title'] ?? '';
                    $dalle_style    = ( $img_style === 'photo' ) ? 'natural' : 'vivid';
                    $tpl_img_size   = in_array( $img_style, ['photo', 'photorealistic'], true ) ? '1792x1024' : '1024x1024';
                    $tpl_orient     = $tpl_img_size === '1792x1024' ? 'Landscape orientation, 1792x1024' : 'Square format, 1024x1024';

                    $style_map = [
                        'photo'             => 'Professional photograph, real camera shot, natural lighting, high resolution, sharp focus, authentic scene, no illustrations, no digital art',
                        'photorealistic'    => 'Professional stock photography style, high quality, well-lit, clean background',
                        'modern-flat'       => 'Modern flat design illustration, clean lines, soft gradients, professional color palette, minimalist',
                        'isometric'         => 'Isometric 3D illustration, colorful, tech-forward, clean white background',
                        'watercolor'        => 'Soft watercolor style illustration, artistic, professional, warm tones',
                        'gradient-abstract' => 'Abstract gradient art, flowing shapes, modern tech aesthetic, vivid colors',
                    ];
                    $style_suffix = $style_map[ $img_style ] ?? $style_map['photo'];

                    $remaining = $max_tpl_imgs - $tpl_imgs_generated;
                    foreach ( array_slice( $empty_widgets, 0, $remaining ) as $w_idx => $widget_info ) {
                        $img_num    = $w_idx + 1;
                        $alt_hint   = (string) ( $widget_info['alt_hint'] ?? '' );
                        $img_prompt = "Create a professional image for a webpage about: {$focus_kw}. "
                                    . ( $alt_hint ? "Image context: {$alt_hint}. " : '' )
                                    . "Style: {$style_suffix}. {$tpl_orient}, no text or words in the image.";

                        $dall_e_result = myls_pb_dall_e_generate( $tpl_api_key, $img_prompt, $tpl_img_size, $dalle_style );

                        if ( $dall_e_result['ok'] ) {
                            $tpl_attach_id = myls_pb_upload_image_from_url(
                                $dall_e_result['url'],
                                sanitize_title( $focus_kw ) . '-tpl-img-' . ( $tpl_slot_index ) . '-' . $img_num,
                                $focus_kw . ' - Template ' . $tpl_slot_index . ' Image ' . $img_num,
                                0
                            );
                            if ( $tpl_attach_id ) {
                                $tpl_img_url = wp_get_attachment_url( $tpl_attach_id );
                                $tpl_img_alt = $focus_kw . ' - Image ' . $img_num;

                                // Inject into the element tree at current position
                                $last_idx = count( $elements ) - 1;
                                foreach ( array_slice( $elements, count( $elements ) - count( $tpl_elements ) ) as $tel_i => &$tel_ref ) {
                                    // Walk entire elements array; inject helper handles recursion
                                }
                                unset( $tel_ref );

                                // Use the injector on the full running elements array
                                $elements = myls_elb_inject_image_into_widget(
                                    $elements,
                                    $widget_info['id'],
                                    $tpl_attach_id,
                                    $tpl_img_url,
                                    $tpl_img_alt
                                );

                                // Track for parent attachment update after post save
                                $section_flags['_tpl_images'][] = [
                                    'type'    => 'template',
                                    'id'      => $tpl_attach_id,
                                    'url'     => $tpl_img_url,
                                    'alt'     => $tpl_img_alt,
                                    'subject' => $focus_kw,
                                ];
                                $tpl_imgs_generated++;
                                $tpl_log_pre[] = "   ✅ Template image saved (ID: {$tpl_attach_id})";
                            } else {
                                $tpl_log_pre[] = "   ❌ Template image {$img_num}: upload to Media Library failed";
                            }
                        } else {
                            $tpl_log_pre[] = "   ❌ Template image {$img_num}: " . ( $dall_e_result['error'] ?? 'DALL-E error' );
                        }
                    }
                }
            }
        }
    }

    // Expose template log so caller can merge it
    $section_flags['_tpl_log'] = $tpl_log_pre;

    return [
        'json'          => (string) wp_json_encode( $elements ),
        'faqs'          => $all_faqs,
        'section_count' => $section_count,
        'tldr_text'     => $tldr_text,
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
    $description     = sanitize_textarea_field( wp_unslash( $_POST['page_description'] ?? '' ) );
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

    // ── sections_order — unified ordered list replacing flat flags ──────
    // Sent as JSON by the front-end.  Each element is one of:
    //   { id: 'hero', type: 'section', enabled: true }
    //   { id: 'features', type: 'section', enabled: true, cols: 3, rows: 2 }
    //   { id: 'tpl_xyz', type: 'template', enabled: true, template_id: 142 }
    $sections_order_raw = wp_unslash( $_POST['sections_order'] ?? '' );
    $sections_order     = [];
    if ( ! empty( $sections_order_raw ) ) {
        $so_decoded = json_decode( $sections_order_raw, true );
        if ( is_array( $so_decoded ) ) $sections_order = $so_decoded;
    }

    // Backward compat: if no sections_order, build from legacy flat flags
    if ( empty( $sections_order ) ) {
        $sections_order = [
            [ 'id'=>'hero',      'type'=>'section', 'enabled'=> ( ! isset($_POST['include_hero'])      || ! empty($_POST['include_hero']) ) ],
            [ 'id'=>'tldr',      'type'=>'section', 'enabled'=> ( ! isset($_POST['include_tldr'])      || ! empty($_POST['include_tldr']) ) ],
            [ 'id'=>'intro',     'type'=>'section', 'enabled'=> ( ! isset($_POST['include_intro'])     || ! empty($_POST['include_intro']) ) ],
            [ 'id'=>'trust_bar', 'type'=>'section', 'enabled'=> ( ! isset($_POST['include_trust_bar']) || ! empty($_POST['include_trust_bar']) ) ],
            [ 'id'=>'features',      'type'=>'section', 'enabled'=> ( ! isset($_POST['include_features'])      || ! empty($_POST['include_features']) ), 'cols'=>3, 'rows'=>1 ],
            [ 'id'=>'rich_content',  'type'=>'section', 'enabled'=> ( ! isset($_POST['include_rich_content'])  || ! empty($_POST['include_rich_content']) ) ],
            [ 'id'=>'process',       'type'=>'section', 'enabled'=> ( ! isset($_POST['include_process'])       || ! empty($_POST['include_process']) ) ],
            [ 'id'=>'pricing',   'type'=>'section', 'enabled'=> ( ! isset($_POST['include_pricing'])   || ! empty($_POST['include_pricing']) ) ],
            // faq removed — FAQs generated post-creation via FAQ Builder tab
            [ 'id'=>'cta',       'type'=>'section', 'enabled'=> ( ! isset($_POST['include_cta'])       || ! empty($_POST['include_cta']) ) ],
        ];
        foreach ( array_filter( array_map( 'intval', [
            $_POST['append_template_1'] ?? 0,
            $_POST['append_template_2'] ?? 0,
            $_POST['append_template_3'] ?? 0,
        ] ) ) as $tid ) {
            $sections_order[] = [ 'id'=>'tpl_'.$tid, 'type'=>'template', 'enabled'=>true, 'template_id'=>$tid ];
        }
    }

    // Derive feature card count from sections_order (cols × rows)
    $features_item  = null;
    foreach ( $sections_order as $so_item ) {
        if ( ( $so_item['type'] ?? '' ) === 'section' && ( $so_item['id'] ?? '' ) === 'features' ) {
            $features_item = $so_item;
            break;
        }
    }
    $feature_cols     = max( 1, min( 6, (int) ( $features_item['cols'] ?? 3 ) ) );
    $feature_rows     = max( 1, min( 6, (int) ( $features_item['rows'] ?? 1 ) ) );
    $card_count_total = $feature_cols * $feature_rows; // total feature card image slots

    // Derive process cols from sections_order (defaults 2 cols × 2 rows = 4 steps)
    $process_item = null;
    foreach ( $sections_order as $so_item ) {
        if ( ( $so_item['type'] ?? '' ) === 'section' && ( $so_item['id'] ?? '' ) === 'process' ) {
            $process_item = $so_item;
            break;
        }
    }
    $process_cols       = max( 1, min( 6, (int) ( $process_item['cols'] ?? 2 ) ) );
    $process_rows       = max( 1, min( 6, (int) ( $process_item['rows'] ?? 2 ) ) );
    $process_step_total = $process_cols * $process_rows;

    // Write cols/rows back into sections_order so parse_and_build reads the
    // correct values regardless of whether the frontend serialized them.
    foreach ( $sections_order as &$_so_ref ) {
        if ( ( $_so_ref['type'] ?? '' ) === 'section' ) {
            if ( $_so_ref['id'] === 'features' ) {
                $_so_ref['cols'] = $feature_cols;
                $_so_ref['rows'] = $feature_rows;
            } elseif ( $_so_ref['id'] === 'process' ) {
                $_so_ref['cols'] = $process_cols;
                $_so_ref['rows'] = $process_rows;
            }
        }
    }
    unset( $_so_ref );

    $integrate_images  = ! empty( $_POST['integrate_images'] );
    $image_style       = sanitize_text_field( $_POST['image_style'] ?? 'photo' );
    $gen_hero_img      = ! empty( $_POST['gen_hero'] );
    $gen_feature_cards = ! empty( $_POST['gen_feature_cards'] );
    $set_featured      = ! empty( $_POST['set_featured'] );   // hero image → post thumbnail
    $page_slug         = sanitize_title( $_POST['page_slug'] ?? '' );
    $parent_page_id    = max( 0, (int) ( $_POST['parent_page_id'] ?? 0 ) );

    if ( empty( $page_title ) ) {
        wp_send_json_error( ['message' => 'Page title is required.'], 400 );
    }
    if ( ! post_type_exists( $post_type ) ) {
        wp_send_json_error( ['message' => 'Invalid post type: ' . $post_type ], 400 );
    }

    // ── Business vars ────────────────────────────────────────────────────
    $sb   = get_option( 'myls_sb_settings', [] );

    // Contact / CTA button URL.
    // Canonical source: myls_contact_page_id (set in AI Content → FAQ Builder).
    // POST value from builder UI (PHP-injected resolved URL) is used directly;
    // if empty, we fall back to resolving from the option ourselves.
    $contact_url_raw = trim( esc_url_raw( sanitize_text_field( $_POST['contact_url'] ?? '' ) ) );
    if ( $contact_url_raw !== '' ) {
        $contact_url = $contact_url_raw;
    } else {
        // Resolve from canonical page-ID option (same logic as FAQ builder tab)
        $contact_url = function_exists('myls_get_contact_url')
            ? myls_get_contact_url()
            : home_url('/contact-us/');
    }

    $vars = [
        'business_name' => $sb['business_name'] ?? get_bloginfo('name'),
        'city'          => $sb['city']          ?? '',
        'phone'         => $sb['phone']         ?? '',
        'email'         => $sb['email']         ?? get_bloginfo('admin_email'),
        'site_name'     => get_bloginfo('name'),
        'site_url'      => home_url(),
        'contact_url'   => $contact_url,   // available as {{contact_url}} in prompts
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

    // Tell AI how many feature cards to generate so content matches the grid
    $prompt .= "\n\n[GRID INSTRUCTION] The Feature Cards section must contain exactly {$card_count_total} items (cols={$feature_cols} × rows={$feature_rows}). Generate exactly that many items in the features.items array.";
    $prompt .= "\n[GRID INSTRUCTION] The How It Works / Process section must contain exactly {$process_step_total} steps (cols={$process_cols} × rows={$process_rows}). Generate exactly that many items in the process.steps array.";

    // ── Deferred image generation — build pending_images manifest ───────
    // Images are no longer generated in this request. Instead, we build a
    // manifest of pending images and return it to the client. The JS then
    // fires one AJAX call per image via myls_elb_generate_single_image,
    // preventing server timeouts on multi-image builds.
    $generated_images = [];
    $image_log        = [];
    $pending_images   = [];

    if ( $integrate_images && function_exists('myls_pb_dall_e_generate') ) {
        $api_key = function_exists('myls_openai_get_api_key') ? myls_openai_get_api_key() : '';
        if ( ! empty( $api_key ) ) {

            if ( $gen_hero_img ) {
                $pending_images[] = [
                    'type'    => 'hero',
                    'subject' => $page_title,
                    'size'    => '1792x1024',
                    'index'   => 0,
                ];
                $image_log[] = '🖼️ Hero image queued for generation (1792×1024)';
            }

            if ( ! $gen_hero_img && $set_featured ) {
                $pending_images[] = [
                    'type'    => 'featured',
                    'subject' => $page_title,
                    'size'    => '1792x1024',
                    'index'   => 0,
                ];
                $image_log[] = '🖼️ Featured image queued for generation (1792×1024)';
            }

            if ( $gen_feature_cards ) {
                $card_count    = $card_count_total;
                $card_subjects = function_exists('myls_pb_suggest_image_subjects')
                    ? myls_pb_suggest_image_subjects( $page_title, $description, $card_count )
                    : array_fill( 0, $card_count, $page_title );

                for ( $c = 0; $c < $card_count; $c++ ) {
                    $pending_images[] = [
                        'type'    => 'feature_card',
                        'subject' => $card_subjects[ $c ] ?? $page_title,
                        'size'    => '1024x1024',
                        'index'   => $c,
                    ];
                }
                $image_log[] = "🖼️ {$card_count} feature card image(s) queued for generation (1024×1024)";
            }

            if ( empty( $pending_images ) ) {
                $image_log[] = 'ℹ️ No images requested — only template image widgets will be scanned.';
            }
        }
    }

    // NOTE: Images are no longer injected into the AI prompt as <img> HTML tags.
    // The AI outputs structured JSON, so images cannot be placed via prompt instructions.
    // Instead, generated images are passed directly to myls_elb_parse_and_build()
    // below, where they are applied as native Elementor widget/container settings:
    //   hero image       → container background_image (overlaid on dark background)
    //   feature_card[n]  → image-box widget in the nth card container of the grid

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

    // ── Schema data context — inject rich business facts into the prompt ─
    // Pulls from the Schema subtabs (Org, LocalBusiness, Service) already
    // configured by the site owner — awards, certs, rating, areas, hours, etc.
    // This is high-value grounding that meaningfully improves AI citation quality.
    $schema_ctx_parts = [];

    // Organisation core
    $org_name   = trim( (string) get_option( 'myls_org_name', '' ) );
    $org_desc   = trim( (string) get_option( 'myls_org_description', '' ) );
    $org_areas  = trim( (string) get_option( 'myls_org_areas', '' ) );
    $org_url    = trim( (string) get_option( 'myls_org_url', '' ) );
    if ( $org_name )  $schema_ctx_parts[] = "Business name: {$org_name}";
    if ( $org_desc )  $schema_ctx_parts[] = "About the business: {$org_desc}";
    if ( $org_areas ) $schema_ctx_parts[] = "Service areas: {$org_areas}";
    if ( $org_url )   $schema_ctx_parts[] = "Website: {$org_url}";

    // Awards & certifications
    $awards = array_values( array_filter( array_map( 'sanitize_text_field',
        (array) get_option( 'myls_org_awards', [] ) ) ) );
    $certs  = array_values( array_filter( array_map( 'sanitize_text_field',
        (array) get_option( 'myls_org_certifications', [] ) ) ) );
    if ( $awards ) $schema_ctx_parts[] = 'Awards / recognition: ' . implode( '; ', $awards );
    if ( $certs )  $schema_ctx_parts[] = 'Certifications: ' . implode( '; ', $certs );

    // Social profiles (signals E-E-A-T)
    $socials = array_filter( (array) get_option( 'myls_org_social_profiles', [] ) );
    if ( $socials ) $schema_ctx_parts[] = 'Social profiles: ' . implode( ', ', $socials );

    // LocalBusiness — first location (hours, price range, address)
    $lb_locs = (array) get_option( 'myls_lb_locations', [] );
    $lb0     = is_array( $lb_locs[0] ?? null ) ? $lb_locs[0] : [];
    if ( ! empty( $lb0['price'] ) ) $schema_ctx_parts[] = 'Price range: ' . $lb0['price'];
    if ( ! empty( $lb0['city'] )  ) $schema_ctx_parts[] = 'Primary city: ' . $lb0['city'];

    $hours_arr = (array) ( $lb0['hours'] ?? [] );
    $hour_lines = [];
    foreach ( $hours_arr as $h ) {
        $d = trim( (string) ( $h['day'] ?? '' ) );
        $o = trim( (string) ( $h['open'] ?? '' ) );
        $c = trim( (string) ( $h['close'] ?? '' ) );
        if ( $d && $o && $c ) $hour_lines[] = "{$d}: {$o}–{$c}";
    }
    if ( $hour_lines ) $schema_ctx_parts[] = 'Business hours: ' . implode( ', ', $hour_lines );

    // Google Business Profile — rating + review count
    $gbp_rating = trim( (string) get_option( 'myls_google_places_rating', '' ) );
    $gbp_count  = trim( (string) get_option( 'myls_google_places_review_count', '' ) );
    if ( $gbp_rating && $gbp_count ) $schema_ctx_parts[] = "Google rating: {$gbp_rating}/5 from {$gbp_count} reviews";
    elseif ( $gbp_rating )           $schema_ctx_parts[] = "Google rating: {$gbp_rating}/5";

    if ( ! empty( $schema_ctx_parts ) ) {
        $schema_block  = "\n\n--- Business Profile (use these facts — write naturally, do NOT copy verbatim) ---\n";
        $schema_block .= implode( "\n", $schema_ctx_parts );
        $prompt       .= $schema_block;
        $log_lines[]   = '🏢 Schema business context injected (' . count( $schema_ctx_parts ) . ' data points: org, localbusiness, awards/certs, GBP rating)';
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

Your response must be a single JSON object with these exact keys: hero, tldr, trust_bar, intro, features, rich_content, process, pricing, cta.

Rules:
- Start your response with { and end with }
- No text before or after the JSON object
- No ```json``` fences
- All string values must be plain text (no HTML tags inside JSON values)
- icon values must be Font Awesome 5 solid class strings like "fas fa-shield-alt"
- paragraphs are arrays of plain text strings
- trust_bar.stats is an array of exactly 4 objects: { icon, stat, label }
- tldr.text is a single 40-60 word plain-text sentence
- rich_content.html is 200-300 words of pre-formatted HTML: one <h3>, two <p>, one <ul> with 4-5 <li> items. No other HTML tags. All text wiki-voice. answering "what is this service and where?"
- pricing.heading is an H2 question about cost (optional — omit if not provided)
- pricing.caveat is a one-sentence caveat about final pricing (optional)',
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
                'button_url'  => $contact_url,
            ],
            'intro' => [
                'heading'    => 'About ' . $page_title,
                'paragraphs' => [ $description ?: 'Learn more about ' . $page_title ],
            ],
            'cta'   => [
                'heading'     => 'Ready to Get Started?',
                'subtitle'    => 'Contact us today.',
                'button_text' => 'Contact Us',
                'button_url'  => $contact_url,
            ],
        ] );
    }

    // ── Parse JSON + build native Elementor widgets ──────────────────────
    // sections_order drives build order, section visibility, cols/rows, and
    // template interleaving — all in one pass inside myls_elb_parse_and_build().
    $section_flags  = [
        'sections_order'   => $sections_order,
        'post_id'          => 0, // real post_id assigned after insert/update below
        'seo_keyword'      => $seo_keyword,
        'page_title'       => $page_title,
        'description'      => $description,
        'contact_url'      => $contact_url,    // resolved CTA/button URL for this generation
        // Template image generation flags — forwarded to parse_and_build
        'integrate_images'  => (bool) $integrate_images,
        'gen_feature_cards' => (bool) $gen_feature_cards,
        'dalle_api_key'    => $integrate_images && function_exists('myls_openai_get_api_key')
                                ? ( myls_openai_get_api_key() ?: '' )
                                : '',
        'image_style'      => $image_style,
    ];
    $build_result   = myls_elb_parse_and_build( $html, $generated_images, $kit, $site_patterns, $section_flags );
    $elementor_json = $build_result['json'];
    $faq_items      = $build_result['faqs'];
    $section_count  = $build_result['section_count'];
    $tldr_text      = $build_result['tldr_text'] ?? '';
    $parse_warning  = $build_result['error'];

    // Collect template-processing log lines emitted by parse_and_build
    $tpl_log_lines  = $section_flags['_tpl_log'] ?? [];

    // Merge template-generated images so parent post attachment update works
    $tpl_new_images = $section_flags['_tpl_images'] ?? [];
    if ( ! empty( $tpl_new_images ) ) {
        $generated_images = array_merge( $generated_images, $tpl_new_images );
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

    // ── Block Elementor from writing _elementor_page_settings ────────────
    // TWO separate paths can write _elementor_page_settings and break the header:
    //
    // PATH A — save_post (priority ~10): fires inside wp_insert_post /
    //   wp_update_post. Elementor's Theme Builder condition evaluator runs here
    //   and writes _elementor_page_settings with the matched template.
    //   Caught by: $elb_meta_cleanup on save_post @ priority 999.
    //
    // PATH B — updated_post_meta / added_post_meta: fires when update_post_meta()
    //   is called with _elementor_data containing Library template content.
    //   Elementor's own updated_post_meta hook runs document-save logic that writes
    //   _elementor_page_settings — bypassing save_post entirely. This is why the
    //   menu breaks specifically when a Page Setup template is included.
    //   Caught by: $elb_meta_settings_guard on updated/added_post_meta @ priority 999.
    //
    // Both hooks share $elb_block_post_id by reference — only active for our post.
    $elb_block_post_id = 0; // set after insert/update

    // PATH A — save_post cleanup
    $elb_meta_cleanup = function( $fired_post_id ) use ( &$elb_block_post_id ) {
        if ( $elb_block_post_id && (int) $fired_post_id === $elb_block_post_id ) {
            delete_post_meta( (int) $fired_post_id, '_wp_page_template' );
            delete_post_meta( (int) $fired_post_id, '_elementor_page_settings' );
        }
    };
    add_action( 'save_post', $elb_meta_cleanup, 999 );

    // PATH B — meta write guard (catches Library template path)
    // Fires the instant _elementor_page_settings or _wp_page_template is written
    // to our post by any caller, and immediately deletes it.
    $elb_meta_settings_guard = function( $meta_id, $object_id, $meta_key ) use ( &$elb_block_post_id ) {
        if ( ! $elb_block_post_id || (int) $object_id !== $elb_block_post_id ) return;
        if ( $meta_key === '_elementor_page_settings' || $meta_key === '_wp_page_template' ) {
            delete_post_meta( $elb_block_post_id, $meta_key );
        }
    };
    add_action( 'updated_post_meta', $elb_meta_settings_guard, 999, 3 );
    add_action( 'added_post_meta',   $elb_meta_settings_guard, 999, 3 );

    // Elementor stores its own rendered HTML in post_content as a cache.
    // For a fresh page we set a placeholder; Elementor will regenerate on first load.
    $post_content_fallback = '<!-- Elementor page — edit in Elementor editor -->';

    if ( $existing ) {
        $post_id       = (int) $existing[0];
        $update_args   = [
            'ID'           => $post_id,
            'post_title'   => $page_title,
            'post_content' => $post_content_fallback,
            'post_status'  => $page_status,
        ];
        if ( $page_slug )      $update_args['post_name']   = $page_slug;
        if ( $parent_page_id ) $update_args['post_parent'] = $parent_page_id;
        wp_update_post( $update_args );
        $action_label = 'updated';
    } else {
        $insert_args = [
            'post_type'    => $post_type,
            'post_status'  => $page_status,
            'post_title'   => $page_title,
            'post_content' => $post_content_fallback,
            'meta_input'   => [
                '_myls_elb_generated' => 1,
                $meta_key             => $gen_key,
            ],
        ];
        if ( $page_slug )      $insert_args['post_name']   = $page_slug;
        if ( $parent_page_id ) $insert_args['post_parent'] = $parent_page_id;
        $post_id = (int) wp_insert_post( $insert_args );
        $action_label = 'created';
    }

    if ( ! $post_id || is_wp_error( $post_id ) ) {
        wp_send_json_error( ['message' => 'Failed to create post.'], 500 );
    }

    // Activate the cleanup hook now that we have the real post ID.
    $elb_block_post_id = $post_id;

    // ── Assign selected price range to the new post ──────────────────────
    // When post_type = 'service' and the user chose a price range in the builder
    // UI, add this post_id to that range's post_ids array so the Pricing section
    // renders it automatically.  Uses direct $wpdb write — no hooks fired.
    $price_range_idx = isset( $_POST['price_range_idx'] ) && $_POST['price_range_idx'] !== ''
        ? (int) $_POST['price_range_idx']
        : -1;

    if ( $price_range_idx >= 0 ) {
        $all_ranges = (array) get_option( 'myls_service_price_ranges', [] );
        if ( isset( $all_ranges[ $price_range_idx ] ) && is_array( $all_ranges[ $price_range_idx ] ) ) {
            $range_post_ids = array_map( 'intval', (array) ( $all_ranges[ $price_range_idx ]['post_ids'] ?? [] ) );
            if ( ! in_array( $post_id, $range_post_ids, true ) ) {
                $range_post_ids[] = $post_id;
                $all_ranges[ $price_range_idx ]['post_ids'] = array_values( $range_post_ids );
                update_option( 'myls_service_price_ranges', $all_ranges );
                $log_lines[] = '💲 Price range "' . esc_html( $all_ranges[ $price_range_idx ]['label'] ?? '' ) . '" assigned to post ID ' . $post_id . '.';
            }
        } else {
            $log_lines[] = '⚠️ Price range index ' . $price_range_idx . ' not found — skipped.';
        }
    }

    // ── Inherit city_state + county + _myls_city_state from parent page ─────
    // If a parent page is set and has these fields populated, copy them
    // to the new post so shortcodes like [city_state] and [county_name] work
    // immediately without manual entry.
    if ( $parent_page_id ) {
        // ACF fields: city_state, county
        foreach ( [ 'city_state', 'county' ] as $field_key ) {
            $parent_val = function_exists( 'get_field' )
                ? get_field( $field_key, $parent_page_id )
                : get_post_meta( $parent_page_id, $field_key, true );
            if ( $parent_val !== '' && $parent_val !== null && $parent_val !== false ) {
                update_post_meta( $post_id, $field_key, sanitize_text_field( $parent_val ) );
            }
        }
        // MYLS native field: _myls_city_state (format: "City, State")
        $parent_myls_cs = get_post_meta( $parent_page_id, '_myls_city_state', true );
        if ( $parent_myls_cs !== '' && $parent_myls_cs !== false ) {
            update_post_meta( $post_id, '_myls_city_state', sanitize_text_field( $parent_myls_cs ) );
        }
    }

    // ── Save FAQ items to post meta ──────────────────────────────────────
    // NOTE: The Elementor builder no longer generates FAQs directly.
    // FAQs are created post-page-generation via the FAQ Builder tab and stored
    // in _myls_faq_items, which [faq_schema_accordion] reads automatically.
    // We do NOT delete existing _myls_faq_items here — re-running the page
    // builder must never wipe FAQs set by the FAQ Builder tab.
    if ( ! empty( $faq_items ) ) {
        // Legacy path: if a saved snapshot contained faq data, persist it.
        update_post_meta( $post_id, '_myls_faq_items', $faq_items );
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

    // Do NOT set _wp_page_template or _elementor_page_settings.
    // These are deleted at the very END of this handler (just before wp_send_json_success)
    // because wp_update_post() calls made later (excerpt, tagline, image attachment)
    // fire save_post which causes Elementor to re-write _elementor_page_settings.
    // Deleting here would be immediately undone.

    // Clear only this post's cached CSS — do NOT call files_manager->clear_cache().
    // That global clear destroys the header template and kit CSS files.
    // When those are missing on first page load, Elementor's sticky JS captures the
    // header width before responsive CSS applies, permanently locking it at ~100px
    // via inline style: position:fixed; width:100px.
    delete_post_meta( $post_id, '_elementor_css' );
    delete_post_meta( $post_id, '_elementor_element_cache' );
    delete_post_meta( $post_id, '_elementor_page_assets' );

    // Immediately regenerate this post's CSS so sticky JS has correct dimensions
    // on the very first page load. Use new() directly — ::create() is not available
    // in all Elementor versions and can output HTML errors that break JSON responses.
    if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
        try {
            $post_css = new \Elementor\Core\Files\CSS\Post( "post-{$post_id}" );
            $post_css->update();
        } catch ( \Throwable $e ) { /* silently ignore — CSS regenerates on first visit */ }
    }

    $saved_via_api = false; // document API intentionally bypassed — see comment above

    // ── Attach generated images ──────────────────────────────────────────
    // set_post_thumbnail priority: hero → standalone featured → (none).
    if ( ! empty( $generated_images ) ) {
        foreach ( $generated_images as $img ) {
            wp_update_post( [ 'ID' => $img['id'], 'post_parent' => $post_id ] );
        }
        if ( $set_featured ) {
            $thumb     = null;
            $thumb_src = '';
            // 1st priority: hero (also used as layout background)
            foreach ( $generated_images as $img ) {
                if ( $img['type'] === 'hero' ) { $thumb = $img['id']; $thumb_src = 'Hero'; break; }
            }
            // 2nd priority: standalone featured image (generated when no hero)
            if ( ! $thumb ) {
                foreach ( $generated_images as $img ) {
                    if ( $img['type'] === 'featured' ) { $thumb = $img['id']; $thumb_src = 'Featured'; break; }
                }
            }
            if ( $thumb ) {
                set_post_thumbnail( $post_id, $thumb );
                $image_log[] = "   📌 {$thumb_src} image set as post thumbnail (attachment ID: {$thumb})";
            }
        }
    }

    // ── Yoast meta + Excerpt + Tagline — AI-generated via plugin prompts ──
    // All four use the same prompt templates, token system, Variation Engine,
    // and myls_ai_generate_text() as the standalone AI subtabs.

    // Build shared token context (same as meta + excerpt subtabs)
    $post_obj = get_post( $post_id );
    $ai_ctx   = function_exists('myls_ai_context_for_post') ? myls_ai_context_for_post( $post_id ) : [];

    // Add city_state + content_snippet — required by excerpt + meta prompts
    $elb_city_state = '';
    if ( function_exists('get_field') ) $elb_city_state = (string) get_field('city_state', $post_id);
    if ( $elb_city_state === '' ) $elb_city_state = (string) get_post_meta( $post_id, 'city_state', true );
    if ( $elb_city_state === '' ) {
        $org_city  = trim( (string) get_option( 'myls_org_locality', '' ) );
        $org_state = trim( (string) get_option( 'myls_org_region', '' ) );
        if ( $org_city && $org_state ) $elb_city_state = "{$org_city}, {$org_state}";
    }
    $elb_content_snippet = function_exists('myls_get_post_plain_text')
        ? myls_get_post_plain_text( $post_id, 200 )
        : wp_trim_words( wp_strip_all_tags( (string) ( $post_obj->post_content ?? '' ) ), 200, '...' );
    $ai_ctx['city_state']      = $elb_city_state;
    $ai_ctx['content_snippet'] = $elb_content_snippet;

    // ── Yoast SEO Title — AI-generated ──────────────────────────────────
    $meta_title_tpl = function_exists('myls_get_default_prompt') ? myls_get_default_prompt('meta-title') : '';
    $yoast_title    = $seo_keyword
        ? $seo_keyword . ' %%page%% %%sep%% %%sitename%%'   // fallback if AI unavailable
        : $page_title  . ' %%sep%% %%sitename%%';

    if ( $meta_title_tpl && function_exists('myls_ai_apply_tokens') && function_exists('myls_ai_generate_text') ) {
        $mt_prompt = myls_ai_apply_tokens( $meta_title_tpl, $ai_ctx );
        if ( class_exists('MYLS_Variation_Engine') ) {
            $mt_angle  = MYLS_Variation_Engine::next_angle('meta_title');
            $mt_prompt = MYLS_Variation_Engine::inject_variation( $mt_prompt, $mt_angle, 'meta_title' );
        }
        if ( function_exists('myls_ai_set_usage_context') ) myls_ai_set_usage_context( 'meta_title', $post_id );
        $mt_raw = myls_ai_generate_text( $mt_prompt, [ 'max_tokens' => 80, 'temperature' => 0.7, 'post_id' => $post_id ] );
        if ( $mt_raw ) {
            $mt_clean = function_exists('myls_clean_meta_output') ? myls_clean_meta_output( $mt_raw ) : trim( wp_strip_all_tags( $mt_raw ) );
            if ( mb_strlen( $mt_clean ) >= 30 ) {
                $yoast_title = $mt_clean;
                $log_lines[] = '🏷️ SEO title AI-generated (' . mb_strlen( $yoast_title ) . ' chars)';
            }
        }
    }
    update_post_meta( $post_id, '_yoast_wpseo_title', $yoast_title );

    // ── Yoast Meta Description — AI-generated ────────────────────────────
    $meta_desc_tpl  = function_exists('myls_get_default_prompt') ? myls_get_default_prompt('meta-description') : '';
    $yoast_metadesc = mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) ), 0, 155 );  // fallback

    if ( $meta_desc_tpl && function_exists('myls_ai_apply_tokens') && function_exists('myls_ai_generate_text') ) {
        $md_prompt = myls_ai_apply_tokens( $meta_desc_tpl, $ai_ctx );
        if ( class_exists('MYLS_Variation_Engine') ) {
            $md_angle  = MYLS_Variation_Engine::next_angle('meta_description');
            $md_prompt = MYLS_Variation_Engine::inject_variation( $md_prompt, $md_angle, 'meta_description' );
        }
        if ( function_exists('myls_ai_set_usage_context') ) myls_ai_set_usage_context( 'meta_description', $post_id );
        $md_raw = myls_ai_generate_text( $md_prompt, [ 'max_tokens' => 100, 'temperature' => 0.7, 'post_id' => $post_id ] );
        if ( $md_raw ) {
            $md_clean = function_exists('myls_clean_meta_output') ? myls_clean_meta_output( $md_raw ) : trim( wp_strip_all_tags( $md_raw ) );
            if ( mb_strlen( $md_clean ) >= 60 ) {
                $yoast_metadesc = mb_substr( $md_clean, 0, 160 );
                $log_lines[] = '📋 Meta description AI-generated (' . mb_strlen( $yoast_metadesc ) . ' chars)';
            }
        }
    }
    update_post_meta( $post_id, '_yoast_wpseo_metadesc', $yoast_metadesc );
    if ( $seo_keyword ) update_post_meta( $post_id, '_yoast_wpseo_focuskw', $seo_keyword );

    // ── Post excerpt — prefer TL;DR text, fall back to AI generation ────
    $existing_excerpt = trim( (string) get_post_field( 'post_excerpt', $post_id ) );
    if ( $existing_excerpt === '' && $tldr_text !== '' ) {
        $tldr_clean = trim( wp_strip_all_tags( $tldr_text ) );
        if ( mb_strlen( $tldr_clean ) > 20 ) {
            global $wpdb;
            $wpdb->update( $wpdb->posts, [ 'post_excerpt' => $tldr_clean ], [ 'ID' => $post_id ] );
            clean_post_cache( $post_id );
            $existing_excerpt = $tldr_clean; // prevent AI fallback below
            $log_lines[] = '📝 Excerpt set from TL;DR (' . mb_strlen( $tldr_clean ) . ' chars)';
        }
    }
    if ( $existing_excerpt === '' && function_exists('myls_get_default_prompt') && function_exists('myls_ai_generate_text') ) {
        $exc_tpl = myls_get_default_prompt('excerpt');
        if ( $exc_tpl ) {
            $exc_prompt = function_exists('myls_ai_apply_tokens') ? myls_ai_apply_tokens( $exc_tpl, $ai_ctx ) : $exc_tpl;
            if ( class_exists('MYLS_Variation_Engine') ) {
                $ex_angle   = MYLS_Variation_Engine::next_angle('excerpt');
                $exc_prompt = MYLS_Variation_Engine::inject_variation( $exc_prompt, $ex_angle, 'excerpt' );
            }
            if ( function_exists('myls_ai_set_usage_context') ) myls_ai_set_usage_context( 'excerpts', $post_id );
            $exc_raw = myls_ai_generate_text( $exc_prompt, [
                'max_tokens'  => (int)   get_option( 'myls_ai_excerpt_max_tokens',   180 ),
                'temperature' => (float) get_option( 'myls_ai_excerpt_temperature', 0.7 ),
                'post_id'     => $post_id,
            ] );
            if ( $exc_raw ) {
                $exc_clean = function_exists('myls_clean_meta_output') ? myls_clean_meta_output( $exc_raw ) : trim( $exc_raw );
                $exc_clean = trim( wp_strip_all_tags( $exc_clean ) );
                if ( mb_strlen( $exc_clean ) > 20 ) {
                    // Use $wpdb->update directly — wp_update_post() fires save_post,
                    // which Elementor hooks to re-write _elementor_page_settings with
                    // the matched Theme Builder template, breaking the page header.
                    global $wpdb;
                    $wpdb->update( $wpdb->posts, [ 'post_excerpt' => $exc_clean ], [ 'ID' => $post_id ] );
                    clean_post_cache( $post_id );
                    $log_lines[] = '📝 Excerpt generated (' . mb_strlen( $exc_clean ) . ' chars)';
                }
            }
        }
    }

    // ── Tagline — uses plugin 'taglines' prompt template ─────────────────
    $existing_tagline = trim( (string) get_post_meta( $post_id, '_myls_service_tagline', true ) );
    if ( $existing_tagline === '' && function_exists('myls_get_default_prompt') && function_exists('myls_ai_generate_text') ) {
        $tl_tpl = myls_get_default_prompt('taglines');
        if ( $tl_tpl ) {
            $org_name      = trim( (string) get_option( 'myls_org_name', get_bloginfo( 'name' ) ) );
            $post_type_obj = get_post_type_object( $post_type );
            $business_type = $org_name ?: ( $post_type_obj ? $post_type_obj->labels->singular_name : 'Service' );
            $credentials   = function_exists('myls_build_tagline_credentials') ? myls_build_tagline_credentials() : '';
            $tl_content    = trim( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $post_id ) ) );
            if ( $tl_content === '' ) $tl_content = function_exists('myls_get_post_plain_text') ? myls_get_post_plain_text( $post_id, 50 ) : '';

            $tl_prompt = str_replace( '{{TITLE}}',         $page_title,      $tl_tpl );
            $tl_prompt = str_replace( '{{CONTENT}}',       $tl_content,      $tl_prompt );
            $tl_prompt = str_replace( '{{CITY_STATE}}',    $elb_city_state,  $tl_prompt );
            $tl_prompt = str_replace( '{{BUSINESS_TYPE}}', $business_type,   $tl_prompt );
            $tl_prompt = str_replace( '{{CREDENTIALS}}',   $credentials,     $tl_prompt );

            if ( class_exists('MYLS_Variation_Engine') ) {
                $tl_angle  = MYLS_Variation_Engine::next_angle('taglines');
                $tl_prompt = MYLS_Variation_Engine::inject_variation( $tl_prompt, $tl_angle, 'taglines' );
            }
            if ( function_exists('myls_ai_set_usage_context') ) myls_ai_set_usage_context( 'taglines', $post_id );
            $tl_raw = myls_ai_generate_text( $tl_prompt, [ 'max_tokens' => 200, 'temperature' => 0.75, 'post_id' => $post_id ] );
            if ( $tl_raw ) {
                $primary_tl = '';
                if ( preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $tl_raw, $tl_m ) ) {
                    $primary_tl = trim( wp_strip_all_tags( $tl_m[1][0] ) );
                }
                if ( $primary_tl === '' ) $primary_tl = trim( wp_strip_all_tags( $tl_raw ) );
                $primary_tl = preg_replace( '/\s*\|\s*/', ' | ', $primary_tl );
                $primary_tl = trim( $primary_tl, '"\'.' );
                if ( mb_strlen( $primary_tl ) > 8 ) {
                    update_post_meta( $post_id, '_myls_service_tagline', $primary_tl );
                    $log_lines[] = '✨ Tagline generated: ' . esc_html( $primary_tl );
                }
            }
        }
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
        $feature_card_count = count( array_filter( $generated_images, fn($i) => $i['type'] === 'feature_card' ) );
        $tpl_img_count      = count( array_filter( $generated_images, fn($i) => $i['type'] === 'template' ) );
        $img_notes = [];
        if ( $hero_count )         $img_notes[] = 'hero → container background' . ( $set_featured ? ' + post thumbnail' : '' );
        if ( $feature_card_count ) $img_notes[] = "{$feature_card_count} feature card(s) → image-box widgets ({$feature_cols}×{$feature_rows} grid)";
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

    // ── Final meta cleanup — MUST be last ────────────────────────────────
    // Working service pages have NEITHER _wp_page_template NOR
    // _elementor_page_settings set. Any value in either meta causes either a
    // double header or the mobile nav drawer firing open on page load.
    //
    // These deletes run here — after excerpt ($wpdb->update), tagline
    // (update_post_meta), and image-attachment (wp_update_post on child post IDs
    // only) — so nothing can re-write them afterward.
    delete_post_meta( $post_id, '_wp_page_template' );
    delete_post_meta( $post_id, '_elementor_page_settings' );

    wp_send_json_success( [
        'message'         => "{$type_label} {$action_label} successfully.",
        'log_text'        => implode( "\n", $log_lines ),
        'log'             => $ve_log,
        'post_id'         => $post_id,
        'edit_url'        => $edit_url,
        'view_url'        => $view_url,
        'ai_used'         => $ai_used,
        'images'          => $generated_images,
        'pending_images'  => $pending_images,
        'image_style'     => $image_style,
        'set_featured'    => $set_featured,
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
 * AJAX: Generate a single DALL-E image and attach to an existing post
 *
 * Called sequentially from the JS after the page is created. Each call
 * generates ONE image, uploads it to the Media Library, and patches the
 * Elementor JSON so the image appears in the correct widget/container.
 * This prevents server timeouts that occurred when all images were
 * generated in a single blocking request.
 * ------------------------------------------------------------------------- */
add_action( 'wp_ajax_myls_elb_generate_single_image', function () {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error( ['message' => 'Forbidden'], 403 );
    }
    if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_elb_create' ) ) {
        wp_send_json_error( ['message' => 'Bad nonce'], 400 );
    }

    $post_id     = (int) ( $_POST['post_id'] ?? 0 );
    $image_type  = sanitize_key( $_POST['image_type'] ?? '' );   // hero | featured | feature_card
    $image_index = (int) ( $_POST['image_index'] ?? 0 );
    $subject     = sanitize_text_field( $_POST['subject'] ?? '' );
    $size        = sanitize_text_field( $_POST['size'] ?? '1024x1024' );
    $image_style = sanitize_text_field( $_POST['image_style'] ?? 'photo' );
    $set_featured = ! empty( $_POST['set_featured'] );
    $page_title  = sanitize_text_field( $_POST['page_title'] ?? '' );
    $description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );

    if ( ! $post_id || ! get_post( $post_id ) ) {
        wp_send_json_error( ['message' => 'Invalid post ID.'], 400 );
    }
    if ( ! in_array( $image_type, [ 'hero', 'featured', 'feature_card' ], true ) ) {
        wp_send_json_error( ['message' => 'Invalid image type.'], 400 );
    }
    if ( ! function_exists('myls_pb_dall_e_generate') || ! function_exists('myls_pb_upload_image_from_url') ) {
        wp_send_json_error( ['message' => 'Image generation helpers not available.'], 500 );
    }

    $api_key = function_exists('myls_openai_get_api_key') ? myls_openai_get_api_key() : '';
    if ( empty( $api_key ) ) {
        wp_send_json_error( ['message' => 'OpenAI API key not configured.'], 500 );
    }

    // Style mapping (same as main handler)
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

    // Build prompt based on image type
    $topic = $subject ?: $page_title ?: get_the_title( $post_id );
    switch ( $image_type ) {
        case 'hero':
            $prompt = "Create a wide banner/hero image for a webpage about: {$topic}. ";
            if ( $description ) {
                $prompt .= 'Context: ' . mb_substr( wp_strip_all_tags( $description ), 0, 300 ) . '. ';
            }
            $prompt .= "Style: {$style_suffix}. Landscape orientation, 1792x1024, no text or words in the image.";
            $slug_suffix = '-hero';
            $alt_suffix  = ' - Hero Image';
            break;
        case 'featured':
            $prompt = "Create a wide featured image for a webpage about: {$topic}. ";
            if ( $description ) {
                $prompt .= 'Context: ' . mb_substr( wp_strip_all_tags( $description ), 0, 300 ) . '. ';
            }
            $prompt .= "Style: {$style_suffix}. Landscape orientation, 1792x1024, no text or words in the image.";
            $slug_suffix = '-featured';
            $alt_suffix  = ' - Featured Image';
            break;
        case 'feature_card':
        default:
            $card_num = $image_index + 1;
            $prompt   = "Create a professional square image for a service feature card about: {$topic}. "
                      . "Subject: {$subject}. "
                      . "Style: {$style_suffix}. Square format 1024x1024. No text or words in the image.";
            $slug_suffix = '-card-' . $card_num;
            $alt_suffix  = ' - Feature Card ' . $card_num;
            break;
    }

    // Generate the image
    $result = myls_pb_dall_e_generate( $api_key, $prompt, $size, $dalle_style );
    if ( ! $result['ok'] ) {
        wp_send_json_error( ['message' => 'DALL-E error: ' . ( $result['error'] ?? 'Unknown error' )], 500 );
    }

    // Upload to Media Library
    $attach_id = myls_pb_upload_image_from_url(
        $result['url'],
        sanitize_title( $topic ) . $slug_suffix,
        $topic . $alt_suffix,
        $post_id
    );
    if ( ! $attach_id ) {
        wp_send_json_error( ['message' => 'DALL-E succeeded but Media Library upload failed.'], 500 );
    }

    $attach_url = wp_get_attachment_url( $attach_id );

    // Set post thumbnail if applicable
    if ( $set_featured && in_array( $image_type, [ 'hero', 'featured' ], true ) ) {
        set_post_thumbnail( $post_id, $attach_id );
    }

    // ── Patch Elementor JSON to inject the image into the correct widget ──
    $elementor_data = get_post_meta( $post_id, '_elementor_data', true );
    if ( $elementor_data && is_string( $elementor_data ) ) {
        $elements = json_decode( $elementor_data, true );
        if ( is_array( $elements ) ) {
            $patched = false;

            if ( $image_type === 'hero' ) {
                // Find hero container and set background image
                myls_elb_patch_walk( $elements, function ( &$el ) use ( $attach_id, $attach_url, &$patched ) {
                    if ( ( $el['elType'] ?? '' ) === 'container'
                         && ( $el['settings']['myls_section_type'] ?? '' ) === 'hero' ) {
                        $el['settings']['background_image'] = [
                            'url' => $attach_url,
                            'id'  => $attach_id,
                        ];
                        $el['settings']['background_position'] = 'center center';
                        $el['settings']['background_size']     = 'cover';
                        $el['settings']['background_repeat']   = 'no-repeat';
                        $el['settings']['background_overlay_background'] = 'classic';
                        $el['settings']['background_overlay_color']      = 'rgba(0,0,0,0.45)';
                        $patched = true;
                    }
                });
            } elseif ( $image_type === 'feature_card' ) {
                // Find the nth image-box or image widget in the features section
                $img_widget_count = 0;
                myls_elb_patch_walk( $elements, function ( &$el ) use ( $attach_id, $attach_url, $image_index, &$img_widget_count, &$patched, $topic, $alt_suffix ) {
                    if ( ( $el['elType'] ?? '' ) === 'widget'
                         && in_array( $el['widgetType'] ?? '', [ 'image-box', 'image' ], true ) ) {
                        if ( $img_widget_count === $image_index ) {
                            $el['settings']['image'] = [
                                'url' => $attach_url,
                                'id'  => $attach_id,
                                'alt' => $topic . $alt_suffix,
                            ];
                            $patched = true;
                        }
                        $img_widget_count++;
                    }
                });
            }

            if ( $patched ) {
                $new_json = wp_json_encode( $elements );
                update_post_meta( $post_id, '_elementor_data', wp_slash( $new_json ) );
                // Clear CSS cache so Elementor regenerates
                delete_post_meta( $post_id, '_elementor_css' );
                delete_post_meta( $post_id, '_elementor_element_cache' );
            }
        }
    }

    wp_send_json_success( [
        'type'    => $image_type,
        'index'   => $image_index,
        'id'      => $attach_id,
        'url'     => $attach_url,
        'alt'     => $topic . $alt_suffix,
        'subject' => $subject,
    ] );
} );

/**
 * Walk Elementor elements tree recursively, calling $callback on each element.
 * Callback receives element by reference so it can modify in place.
 */
if ( ! function_exists( 'myls_elb_patch_walk' ) ) {
    function myls_elb_patch_walk( array &$elements, callable $callback ): void {
        foreach ( $elements as &$el ) {
            $callback( $el );
            if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
                myls_elb_patch_walk( $el['elements'], $callback );
            }
        }
    }
}

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
    $description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
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
 * AJAX: Page Setup Templates — save/list/delete full left-panel state
 * Stores: post_type, title, description, seo_keyword, status, menu,
 *         section toggles, image checkboxes, image_style, set_featured
 * Option key: myls_elb_setup_history  (array keyed by slug, max 50)
 * ------------------------------------------------------------------------- */
add_action( 'wp_ajax_myls_elb_save_setup', function () {
    if ( ! current_user_can('manage_options') ) wp_send_json_error( ['message' => 'Forbidden'], 403 );
    if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_elb_create' ) ) wp_send_json_error( ['message' => 'Bad nonce'], 400 );

    $name      = sanitize_text_field( $_POST['setup_name'] ?? '' );
    $setup_raw = wp_unslash( $_POST['setup_data'] ?? '' );
    if ( empty( $name ) )      wp_send_json_error( ['message' => 'Name is required.'], 400 );
    if ( empty( $setup_raw ) ) wp_send_json_error( ['message' => 'Setup data is empty.'], 400 );

    $setup = json_decode( $setup_raw, true );
    if ( ! is_array( $setup ) ) wp_send_json_error( ['message' => 'Invalid setup data.'], 400 );

    // Sanitize each field
    // sections_order: deep-sanitize each item
    $raw_so = is_array( $setup['sections_order'] ?? null ) ? $setup['sections_order'] : [];
    $clean_so = [];
    foreach ( $raw_so as $so_item ) {
        if ( ! is_array( $so_item ) ) continue;
        $type = in_array( $so_item['type'] ?? '', ['section','template'] ) ? $so_item['type'] : 'section';
        $entry = [
            'id'      => sanitize_key( $so_item['id']      ?? '' ),
            'type'    => $type,
            'enabled' => (bool) ( $so_item['enabled'] ?? true ),
        ];
        if ( $type === 'section' && ! empty( $so_item['cols'] ) ) {
            $entry['cols'] = max( 1, min( 6, (int) $so_item['cols'] ) );
            $entry['rows'] = max( 1, min( 6, (int) ( $so_item['rows'] ?? 1 ) ) );
        }
        if ( $type === 'section' && ! empty( $so_item['widget_type'] ) ) {
            $entry['widget_type'] = in_array( $so_item['widget_type'], [ 'icon', 'image' ], true )
                ? $so_item['widget_type'] : 'icon';
        }
        if ( $type === 'template' ) {
            $entry['template_id'] = (int) ( $so_item['template_id'] ?? 0 );
        }
        $clean_so[] = $entry;
    }

    $clean = [
        'post_type'         => sanitize_key(        $setup['post_type']    ?? 'page' ),
        'title'             => sanitize_text_field(  $setup['title']        ?? '' ),
        'description'       => sanitize_textarea_field( wp_unslash( $setup['description'] ?? '' ) ),
        'seo_keyword'       => sanitize_text_field(  $setup['seo_keyword']  ?? '' ),
        'status'            => in_array( $setup['status'] ?? '', ['draft','publish','pending'] ) ? $setup['status'] : 'draft',
        'add_to_menu'       => (bool) ( $setup['add_to_menu']       ?? true ),
        'sections_order'    => $clean_so,  // replaces include_* flat flags
        'gen_hero'          => (bool) ( $setup['gen_hero']          ?? true ),
        'gen_feature_cards' => (bool) ( $setup['gen_feature_cards'] ?? false ),
        'image_style'       => sanitize_key( $setup['image_style']  ?? 'photo' ),
        'set_featured'      => (bool) ( $setup['set_featured']      ?? true ),
        // Business variables
        'biz_name'          => sanitize_text_field(  $setup['biz_name']     ?? '' ),
        'biz_city'          => sanitize_text_field(  $setup['biz_city']     ?? '' ),
        'biz_phone'         => sanitize_text_field(  $setup['biz_phone']    ?? '' ),
        'biz_email'         => sanitize_email(       $setup['biz_email']    ?? '' ),
        // AI Prompt Template
        'prompt_template'   => wp_kses_post( wp_unslash( $setup['prompt_template'] ?? '' ) ),
    ];

    $history = get_option( 'myls_elb_setup_history', [] );
    if ( ! is_array( $history ) ) $history = [];
    $slug            = sanitize_title( $name );
    $history[ $slug ] = [ 'name' => $name, 'setup' => $clean, 'updated' => current_time('mysql') ];
    if ( count( $history ) > 50 ) $history = array_slice( $history, -50, 50, true );
    update_option( 'myls_elb_setup_history', $history );
    wp_send_json_success( [ 'message' => "Setup \"{$name}\" saved.", 'history' => myls_elb_format_setups( $history ) ] );
} );

add_action( 'wp_ajax_myls_elb_list_setups', function () {
    if ( ! current_user_can('manage_options') ) wp_send_json_error( [], 403 );
    $history = get_option( 'myls_elb_setup_history', [] );
    if ( ! is_array( $history ) ) $history = [];
    wp_send_json_success( [ 'history' => myls_elb_format_setups( $history ) ] );
} );

add_action( 'wp_ajax_myls_elb_delete_setup', function () {
    if ( ! current_user_can('manage_options') ) wp_send_json_error( ['message' => 'Forbidden'], 403 );
    if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_elb_create' ) ) wp_send_json_error( ['message' => 'Bad nonce'], 400 );
    $slug = sanitize_title( $_POST['setup_slug'] ?? '' );
    if ( empty( $slug ) ) wp_send_json_error( ['message' => 'Invalid entry.'], 400 );
    $history = get_option( 'myls_elb_setup_history', [] );
    if ( ! is_array( $history ) ) $history = [];
    $name = $history[ $slug ]['name'] ?? $slug;
    unset( $history[ $slug ] );
    update_option( 'myls_elb_setup_history', $history );
    wp_send_json_success( [ 'message' => "Deleted \"{$name}\".", 'history' => myls_elb_format_setups( $history ) ] );
} );

function myls_elb_format_setups( array $history ): array {
    $out = [];
    foreach ( $history as $slug => $entry ) {
        $out[] = [
            'slug'    => $slug,
            'name'    => $entry['name']    ?? $slug,
            'setup'   => $entry['setup']   ?? [],
            'updated' => $entry['updated'] ?? '',
        ];
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
    $page_settings = get_post_meta( $post_id, '_elementor_page_settings', true );
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
        'page_settings'    => $page_settings ?: '(not set)',
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
 * Helper: Walk element tree and return counts for each placeholder type.
 *
 * Placeholder markers (case-insensitive, checked against stripped text):
 *   AI-Content  →  text-editor widget  (settings.editor)
 *   AI-H2       →  heading widget      (settings.title, forces tag h2)
 *   AI-H3       →  heading widget      (settings.title, forces tag h3)
 *
 * Returns:
 *   [ 'content' => int, 'h2' => int, 'h3' => int, 'total' => int ]
 * -------------------------------------------------------------------------*/
function myls_elb_get_placeholder_counts( array $elements ): array {
    $counts = [ 'content' => 0, 'h2' => 0, 'h3' => 0 ];

    foreach ( $elements as $el ) {
        $el_type     = $el['elType']     ?? '';
        $widget_type = $el['widgetType'] ?? '';

        if ( $el_type === 'widget' ) {
            if ( $widget_type === 'text-editor' ) {
                $val = strip_tags( $el['settings']['editor'] ?? '' );
                if ( stripos( $val, 'AI-Content' ) !== false ) {
                    $counts['content']++;
                }
            } elseif ( $widget_type === 'heading' ) {
                $title = strip_tags( $el['settings']['title'] ?? '' );
                if ( stripos( $title, 'AI-H2' ) !== false ) {
                    $counts['h2']++;
                } elseif ( stripos( $title, 'AI-H3' ) !== false ) {
                    $counts['h3']++;
                }
            }
        }

        if ( ! empty( $el['elements'] ) ) {
            $child = myls_elb_get_placeholder_counts( $el['elements'] );
            $counts['content'] += $child['content'];
            $counts['h2']      += $child['h2'];
            $counts['h3']      += $child['h3'];
        }
    }

    $counts['total'] = $counts['content'] + $counts['h2'] + $counts['h3'];
    return $counts;
}

/* -------------------------------------------------------------------------
 * Helper: Count all placeholder widgets (any type) — used for the "any?" gate.
 * -------------------------------------------------------------------------*/
function myls_elb_count_ai_placeholders( array $elements ): int {
    return myls_elb_get_placeholder_counts( $elements )['total'];
}

/* -------------------------------------------------------------------------
 * Helper: Recursively fill all placeholder types with indexed content.
 *
 * Each placeholder slot gets its own unique string from the supplied arrays
 * (slot 1 ≠ slot 2).  If an array runs out of items that widget is left
 * unchanged so nothing is accidentally blanked.
 *
 * @param array $elements        Elementor element tree.
 * @param array $content_blocks  HTML strings for AI-Content slots  (0-indexed).
 * @param array $h2_headings     Plain-text strings for AI-H2 slots (0-indexed).
 * @param array $h3_headings     Plain-text strings for AI-H3 slots (0-indexed).
 * @param array &$cursors        Internal per-type counters — pass [] on first call.
 * @return array                 Updated element tree.
 * -------------------------------------------------------------------------*/
function myls_elb_fill_all_placeholders(
    array $elements,
    array $content_blocks,
    array $h2_headings,
    array $h3_headings,
    array &$cursors = []
): array {
    // Initialise per-type cursors on first call
    if ( empty( $cursors ) ) {
        $cursors = [ 'content' => 0, 'h2' => 0, 'h3' => 0 ];
    }

    foreach ( $elements as &$el ) {
        $el_type     = $el['elType']     ?? '';
        $widget_type = $el['widgetType'] ?? '';

        if ( $el_type === 'widget' ) {

            // ── AI-Content → text-editor ──────────────────────────────
            if ( $widget_type === 'text-editor' ) {
                $val = strip_tags( $el['settings']['editor'] ?? '' );
                if ( stripos( $val, 'AI-Content' ) !== false ) {
                    $idx = $cursors['content'];
                    if ( isset( $content_blocks[ $idx ] ) ) {
                        $el['settings']['editor'] = $content_blocks[ $idx ];
                    }
                    $cursors['content']++;
                }
            }

            // ── AI-H2 / AI-H3 → heading ───────────────────────────────
            elseif ( $widget_type === 'heading' ) {
                $title = strip_tags( $el['settings']['title'] ?? '' );

                if ( stripos( $title, 'AI-H2' ) !== false ) {
                    $idx = $cursors['h2'];
                    if ( isset( $h2_headings[ $idx ] ) ) {
                        $el['settings']['title']       = $h2_headings[ $idx ];
                        $el['settings']['header_size'] = 'h2';
                    }
                    $cursors['h2']++;

                } elseif ( stripos( $title, 'AI-H3' ) !== false ) {
                    $idx = $cursors['h3'];
                    if ( isset( $h3_headings[ $idx ] ) ) {
                        $el['settings']['title']       = $h3_headings[ $idx ];
                        $el['settings']['header_size'] = 'h3';
                    }
                    $cursors['h3']++;
                }
            }
        }

        // Recurse into child elements — pass cursors by reference so indices
        // stay sequential across the entire element tree.
        if ( ! empty( $el['elements'] ) ) {
            $el['elements'] = myls_elb_fill_all_placeholders(
                $el['elements'],
                $content_blocks,
                $h2_headings,
                $h3_headings,
                $cursors
            );
        }
    }
    unset( $el ); // break reference
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

/* -------------------------------------------------------------------------
 * AJAX: Get pages/posts for the Parent Page dropdown
 * Accepts: post_type (default: page)
 * Returns: array of { id, title } for all published posts of that type
 * that support page hierarchy (has_archive or is_hierarchical).
 * -------------------------------------------------------------------------*/
add_action( 'wp_ajax_myls_elb_get_parent_pages', function () {
    if ( ! current_user_can('manage_options') ) wp_send_json_error( [], 403 );
    if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'myls_elb_create' ) ) wp_send_json_error( ['message' => 'Bad nonce'], 400 );

    $post_type = sanitize_key( $_POST['post_type'] ?? 'page' );
    if ( ! post_type_exists( $post_type ) ) wp_send_json_error( ['message' => 'Invalid post type'], 400 );

    // Use WP_Query directly so post_type is never coerced.
    // 'post_type' is explicit — we never fall back to 'page'.
    $q = new WP_Query( [
        'post_type'              => $post_type,
        'post_status'            => [ 'publish', 'draft' ],
        'posts_per_page'         => 300,
        'orderby'                => 'menu_order title',
        'order'                  => 'ASC',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ] );

    // Build a flat list with parent depth indentation so nested pages
    // are visually distinguishable in the dropdown.
    $all   = $q->posts;   // WP_Post objects
    $index = [];
    foreach ( $all as $p ) $index[ $p->ID ] = $p;

    function myls_elb_get_depth( int $id, array &$index, int $limit = 10 ): int {
        $depth = 0;
        while ( $depth < $limit && isset( $index[ $id ] ) && $index[ $id ]->post_parent > 0 ) {
            $id = $index[ $id ]->post_parent;
            $depth++;
        }
        return $depth;
    }

    $pages = [];
    foreach ( $all as $p ) {
        $depth   = myls_elb_get_depth( $p->ID, $index );
        $prefix  = $depth ? str_repeat( '— ', $depth ) : '';
        $pages[] = [
            'id'    => $p->ID,
            'title' => $prefix . ( $p->post_title ?: '(untitled #' . $p->ID . ')' ),
        ];
    }

    wp_send_json_success( [ 'pages' => $pages, 'post_type' => $post_type ] );
} );
