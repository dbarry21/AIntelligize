<?php
/**
 * AIntelligize – Shortcode Documentation Data
 * File: admin/docs/shortcode-data.php
 *
 * Comprehensive documentation for all plugin shortcodes.
 * @since 5.0.0 — full rewrite covering all 30+ shortcodes
 * @updated 7.0.2 — added google_reviews_slider, social_links; updated service_grid with aspect_ratio
 */

if (!defined('ABSPATH')) exit;

function mlseo_compile_shortcode_documentation() {
    return [

        // ============================================================
        // LOCATION & GEOGRAPHY
        // ============================================================

        [
            'name' => 'city_state',
            'category' => 'location',
            'description' => 'Displays the city and state from the current post or ancestor. Perfect for dynamic location-based content in titles, headings, and body copy.',
            'basic_usage' => '[city_state]',
            'attributes' => [
                'post_id'     => ['default' => '0',          'description' => 'Specific post ID (0 = current post)'],
                'from'        => ['default' => 'self',       'description' => 'Where to get value: self, parent, ancestor'],
                'field'       => ['default' => 'city_state', 'description' => 'ACF field name to read'],
                'delimiter'   => ['default' => ',',          'description' => 'Separator between city and state'],
                'normalize'   => ['default' => '0',          'description' => '1 = clean formatting, 0 = raw'],
                'state_upper' => ['default' => '0',          'description' => '1 = uppercase state abbreviation'],
                'prefix'      => ['default' => '',           'description' => 'Text before output'],
                'suffix'      => ['default' => '',           'description' => 'Text after output'],
                'fallback'    => ['default' => '',           'description' => 'Text if no value found'],
            ],
            'examples' => [
                ['label' => 'Basic usage', 'code' => '[city_state]'],
                ['label' => 'With prefix', 'code' => '[city_state prefix="Serving "]'],
                ['label' => 'From parent page', 'code' => '[city_state from="parent"]'],
                ['label' => 'Uppercase state', 'code' => '[city_state state_upper="1"]'],
            ],
            'tips' => [
                'Use from="ancestor" to find the nearest parent with location data',
                'Works inside Divi and Elementor heading modules',
                'Set a fallback to avoid empty output on non-location pages',
            ],
        ],

        [
            'name' => 'city_only',
            'category' => 'location',
            'description' => 'Displays only the city name from location data, without the state.',
            'basic_usage' => '[city_only]',
            'attributes' => [
                'post_id'  => ['default' => '0',          'description' => 'Specific post ID (0 = current post)'],
                'from'     => ['default' => 'self',       'description' => 'Where to get value: self, parent, ancestor'],
                'field'    => ['default' => 'city_state', 'description' => 'ACF field name to read'],
                'fallback' => ['default' => '',           'description' => 'Text if no value found'],
            ],
            'examples' => [
                ['label' => 'Basic usage', 'code' => '[city_only]'],
                ['label' => 'From parent', 'code' => '[city_only from="parent"]'],
                ['label' => 'With fallback', 'code' => '[city_only fallback="your area"]'],
            ],
            'tips' => [
                'Useful for headlines where full city, state is too long',
                'Pairs well with city_state for varied content',
            ],
        ],

        [
            'name' => 'county_name',
            'category' => 'location',
            'description' => 'Displays the county name from the county ACF field.',
            'basic_usage' => '[county_name]',
            'attributes' => [
                'post_id'  => ['default' => '0',      'description' => 'Specific post ID (0 = current post)'],
                'from'     => ['default' => 'self',   'description' => 'Where to get value: self, parent, ancestor'],
                'field'    => ['default' => 'county', 'description' => 'ACF field name to read'],
                'fallback' => ['default' => '',       'description' => 'Text if no value found'],
            ],
            'examples' => [
                ['label' => 'Basic usage', 'code' => '[county_name]'],
                ['label' => 'In a sentence', 'code' => 'Serving [county_name] County'],
            ],
            'tips' => [
                'Requires the county ACF field to be populated',
                'Great for service area page descriptions',
            ],
        ],

        [
            'name' => 'acf_field',
            'category' => 'location',
            'description' => 'Generic shortcode to output any ACF field value. Flexible utility for custom fields not covered by dedicated shortcodes.',
            'basic_usage' => '[acf_field name="my_field"]',
            'attributes' => [
                'name'     => ['default' => '',     'description' => 'ACF field name (required)'],
                'post_id'  => ['default' => '0',    'description' => 'Specific post ID (0 = current post)'],
                'from'     => ['default' => 'self', 'description' => 'Where to get value: self, parent, ancestor'],
                'fallback' => ['default' => '',     'description' => 'Text if no value found'],
            ],
            'examples' => [
                ['label' => 'Custom field', 'code' => '[acf_field name="phone_number"]'],
                ['label' => 'From parent', 'code' => '[acf_field name="region" from="parent"]'],
            ],
            'tips' => [
                'Works with any ACF text, textarea, or select field',
                'Use from="ancestor" to inherit values from parent posts',
            ],
        ],

        // ============================================================
        // SERVICES & SERVICE AREAS
        // ============================================================

        [
            'name' => 'service_grid',
            'category' => 'services',
            'description' => 'Responsive card grid of service posts with images, titles, taglines/excerpts, and buttons. Supports 2–6 column layouts, featured first card, uniform image cropping, and center-aligned incomplete rows.',
            'basic_usage' => '[service_grid]',
            'attributes' => [
                'columns'        => ['default' => '4',                    'description' => 'Columns on desktop: 2, 3, 4, or 6'],
                'subtext'        => ['default' => 'tagline',             'description' => 'Below title: tagline or excerpt'],
                'show_excerpt'   => ['default' => '1',                    'description' => '1 = show subtext, 0 = hide'],
                'excerpt_words'  => ['default' => '20',                   'description' => 'Word count for excerpt mode'],
                'button'         => ['default' => '1',                    'description' => '1 = show button, 0 = hide'],
                'button_text'    => ['default' => 'Learn More',           'description' => 'Button label text'],
                'button_class'   => ['default' => 'btn btn-primary mt-2', 'description' => 'CSS classes for button'],
                'button_target'  => ['default' => '',                     'description' => 'Link target (_blank for new tab)'],
                'btn_bg'         => ['default' => '',                     'description' => 'Button background colour (hex/rgb). Sets --myls-sg-btn-bg CSS variable.'],
                'btn_color'      => ['default' => '',                     'description' => 'Button text colour (hex/rgb). Sets --myls-sg-btn-color CSS variable.'],
                'image_crop'     => ['default' => '0',                    'description' => '1 = uniform image height via CSS'],
                'image_height'   => ['default' => '220',                  'description' => 'Image height in px (when image_crop=1)'],
                'aspect_ratio'   => ['default' => '',                     'description' => 'CSS aspect ratio for images: 1/1, 4/3, 16/9, 3/4 (blank = natural)'],
                'featured_first' => ['default' => '0',                    'description' => '1 = first card spans wider'],
                'center'         => ['default' => '1',                    'description' => '1 = center incomplete rows'],
            ],
            'examples' => [
                ['label' => 'Default 4 columns', 'code' => '[service_grid]'],
                ['label' => '3 columns with excerpts', 'code' => '[service_grid columns="3" subtext="excerpt"]'],
                ['label' => 'Cropped images, 2 cols', 'code' => '[service_grid columns="2" image_crop="1" image_height="250"]'],
                ['label' => 'Square images, 6 cols', 'code' => '[service_grid columns="6" aspect_ratio="1/1" show_excerpt="0" button="0"]'],
                ['label' => 'Landscape ratio', 'code' => '[service_grid aspect_ratio="4/3"]'],
                ['label' => 'Featured first card', 'code' => '[service_grid featured_first="1"]'],
                ['label' => 'Custom button colours', 'code' => '[service_grid btn_bg="#172751" btn_color="#ffffff"]'],
            ],
            'tips' => [
                'Use aspect_ratio="1/1" for square images — works great with 6-column layouts',
                'aspect_ratio uses CSS object-fit: cover so images fill the ratio without distortion',
                'Tagline comes from the Service Tagline metabox; excerpt from WP excerpt',
                'Tagline only shows below the title — no more duplication (v5.0 fix)',
                'Incomplete last rows are auto-centered for a clean look',
            ],
        ],

        [
            'name' => 'service_area_grid',
            'category' => 'services',
            'description' => 'Alternating map + excerpt grid for service area posts. Each row shows a Google Map embed and rich HTML excerpt side by side, with left/right alternation.',
            'basic_usage' => '[service_area_grid]',
            'attributes' => [
                'show_page_title' => ['default' => '1',               'description' => 'Show current page title as H2 above grid: 1 or 0'],
                'show_title'      => ['default' => '1',               'description' => 'Show each service area H3 title: 1/true/yes or 0/false/no'],
                'button_text'     => ['default' => '',                'description' => 'CTA button text (empty = no button)'],
                'include_drafts'  => ['default' => '0',               'description' => '1 = include draft posts in grid'],
                'posts_per_page'  => ['default' => '-1',              'description' => 'Number of posts (-1 = all)'],
                'parent_id'       => ['default' => '',                'description' => 'Filter by parent post ID'],
                'orderby'         => ['default' => 'menu_order title','description' => 'WP_Query orderby tokens'],
                'order'           => ['default' => 'ASC',             'description' => 'Sort direction: ASC or DESC'],
                'map_ratio'       => ['default' => '16x9',            'description' => 'Map embed aspect ratio'],
                'class'           => ['default' => '',                'description' => 'Extra CSS class on container'],
            ],
            'examples' => [
                ['label' => 'Default with page title', 'code' => '[service_area_grid]'],
                ['label' => 'Hide page title', 'code' => '[service_area_grid show_page_title="0"]'],
                ['label' => 'With CTA button', 'code' => '[service_area_grid button_text="Schedule Estimate"]'],
                ['label' => 'Hide row titles', 'code' => '[service_area_grid show_title="0"]'],
                ['label' => 'Include drafts', 'code' => '[service_area_grid include_drafts="1"]'],
            ],
            'tips' => [
                'Excerpt priority: html_excerpt meta → WP excerpt → trimmed content',
                'Edit html_excerpt via the WYSIWYG metabox in the post editor (v5.0)',
                'Bulk generate html_excerpt via AI tab → Excerpts → column 3',
                'show_page_title and show_title are new in v5.0',
            ],
        ],

        [
            'name' => 'service_area_children',
            'category' => 'services',
            'description' => 'Lists child service area posts of the current (or specified) parent as linked items.',
            'basic_usage' => '[service_area_children]',
            'attributes' => [
                'parent_id' => ['default' => '0',   'description' => 'Parent post ID (0 = current post)'],
                'orderby'   => ['default' => 'title','description' => 'Sort field'],
                'order'     => ['default' => 'ASC',  'description' => 'Sort direction'],
            ],
            'examples' => [
                ['label' => 'Children of current page', 'code' => '[service_area_children]'],
                ['label' => 'Specific parent', 'code' => '[service_area_children parent_id="42"]'],
            ],
            'tips' => [
                'Use on parent service area pages to show sub-areas',
                'Returns nothing if the current post has no children',
            ],
        ],

        [
            'name' => 'service_area_roots_children',
            'category' => 'services',
            'description' => 'Displays all top-level (root) service areas with their children nested underneath. Great for a full service area directory.',
            'basic_usage' => '[service_area_roots_children]',
            'attributes' => [
                'hide_empty'    => ['default' => 'no', 'description' => 'yes = hide roots with no children'],
                'wrapper_class' => ['default' => '',   'description' => 'CSS class on the wrapper element'],
            ],
            'examples' => [
                ['label' => 'Full directory', 'code' => '[service_area_roots_children]'],
                ['label' => 'Hide empty roots', 'code' => '[service_area_roots_children hide_empty="yes"]'],
            ],
            'tips' => [
                'Automatically groups children under their parent heading',
                'All items are linked to their respective posts',
            ],
        ],

        [
            'name'        => 'service_area_list',
            'category'    => 'services',
            'description' => 'Simple linked list of service area posts. Supports a related-children mode that auto-filters posts whose titles begin with the current page title (e.g. "Pressure Washing in Clearwater" listed from "Pressure Washing"). Heading and icon are both customisable.',
            'basic_usage' => '[service_area_list]',
            'attributes'  => [
                'show_drafts'          => ['default' => 'false', 'description' => 'Show draft posts only instead of published (true/false or 1/0). Drafts render as plain text — no link.'],
                'get_related_children' => ['default' => 'false', 'description' => 'When true, lists service_area posts whose title STARTS WITH the current page\'s title. E.g. on "Pressure Washing" it surfaces "Pressure Washing in Clearwater", "Pressure Washing in Tampa", etc. Accepts true/false or 1/0.'],
                'heading'              => ['default' => '(auto)', 'description' => 'Override the section heading. Auto-defaults to "Related Service Areas" (get_related_children mode) or "Other Service Areas" (default mode). Set heading="" to suppress the heading entirely.'],
                'icon'                 => ['default' => 'true',  'description' => 'Show or hide the Font Awesome map-marker icon before each list item. Accepts true/false or 1/0.'],
            ],
            'examples' => [
                ['label' => 'All areas alphabetical',                   'code' => '[service_area_list]'],
                ['label' => 'Show drafts only',                         'code' => '[service_area_list show_drafts="true"]'],
                ['label' => 'Related children (auto-filter)',           'code' => '[service_area_list get_related_children="true"]'],
                ['label' => 'Related children — custom heading',        'code' => '[service_area_list get_related_children="true" heading="Also Available In"]'],
                ['label' => 'Related children — no heading, no icon',   'code' => '[service_area_list get_related_children="true" heading="" icon="0"]'],
                ['label' => 'Related children — drafts preview',        'code' => '[service_area_list get_related_children="true" show_drafts="true"]'],
            ],
            'tips' => [
                'heading="" (empty string) suppresses the <h3> entirely — useful when the surrounding layout already has a heading.',
                'icon="0" removes the Font Awesome map-marker — handy for minimal list styles or when Font Awesome is not loaded.',
                'get_related_children uses a case-insensitive PHP prefix match — no taxonomy or WP parent/child setup required.',
                'Drop [service_area_list get_related_children="true"] on any service page; new location variants are discovered automatically as you publish them.',
                'The current page is always excluded from results to prevent self-referencing links.',
            ],
        ],

        [
            'name' => 'service_posts',
            'category' => 'services',
            'description' => 'Displays service posts in a configurable card/list layout with featured images and excerpts.',
            'basic_usage' => '[service_posts]',
            'attributes' => [
                'posts_per_page' => ['default' => '-1',        'description' => 'Number of posts'],
                'columns'        => ['default' => '3',         'description' => 'Grid columns'],
                'orderby'        => ['default' => 'menu_order','description' => 'Sort field'],
                'order'          => ['default' => 'ASC',       'description' => 'Sort direction'],
                'show_image'     => ['default' => '1',         'description' => 'Show featured image'],
                'show_excerpt'   => ['default' => '1',         'description' => 'Show excerpt text'],
                'button_text'    => ['default' => 'Learn More','description' => 'Button label'],
            ],
            'examples' => [
                ['label' => 'Default grid', 'code' => '[service_posts]'],
                ['label' => '2 columns, no images', 'code' => '[service_posts columns="2" show_image="0"]'],
            ],
            'tips' => [
                'Use menu_order to control display sequence in WP admin',
            ],
        ],

        [
            'name' => 'custom_service_cards',
            'category' => 'services',
            'description' => 'Displays top-level (parent) service posts as styled cards. Minimal attributes — relies on post content and featured images.',
            'basic_usage' => '[custom_service_cards]',
            'attributes' => [
                'posts_per_page' => ['default' => '-1', 'description' => 'Number of services to show'],
            ],
            'examples' => [
                ['label' => 'All services', 'code' => '[custom_service_cards]'],
                ['label' => 'Top 6', 'code' => '[custom_service_cards posts_per_page="6"]'],
            ],
            'tips' => [
                'Only shows parent services (post_parent = 0)',
                'Uses featured images — ensure services have thumbnails set',
            ],
        ],

        [
            'name' => 'myls_card_grid',
            'category' => 'services',
            'description' => 'Service × Service Area flip card grid. Shows services as cards; on service area pages, links go to the child page for that service + area combination. Aliases: myls_flip_grid, ssseo_card_grid, ssseo_flip_grid.',
            'basic_usage' => '[myls_card_grid]',
            'attributes' => [
                'button_text' => ['default' => 'Learn More',             'description' => 'Button label on each card'],
                'image_size'  => ['default' => 'medium_large',           'description' => 'WordPress image size'],
                'use_icons'   => ['default' => '0',                      'description' => '1 = show icons instead of images'],
                'icon_class'  => ['default' => 'bi bi-grid-3x3-gap',    'description' => 'Bootstrap icon class (when use_icons=1)'],
            ],
            'examples' => [
                ['label' => 'Default grid', 'code' => '[myls_card_grid]'],
                ['label' => 'Icon mode', 'code' => '[myls_card_grid use_icons="1" icon_class="bi bi-tools"]'],
                ['label' => 'Alias', 'code' => '[myls_flip_grid button_text="View Details"]'],
            ],
            'tips' => [
                'Automatically detects if current page is a service area and adjusts links',
                'Four aliases available for backward compatibility',
            ],
        ],

        [
            'name' => 'service_faq_page',
            'category' => 'services',
            'description' => 'Generates a combined FAQ page pulling FAQs from all service posts. Displays as Bootstrap accordion grouped by service name with H3 headings.',
            'basic_usage' => '[service_faq_page]',
            'attributes' => [
                'title'         => ['default' => '(page title)', 'description' => 'Page heading (H1). Empty string to hide.'],
                'btn_bg'        => ['default' => '(admin setting)', 'description' => 'Accordion button background color. Falls back to Schema → FAQ admin setting.'],
                'btn_color'     => ['default' => '(admin setting)', 'description' => 'Accordion button text color. Falls back to Schema → FAQ admin setting.'],
                'heading_color' => ['default' => '',              'description' => 'Service heading (H3) color override'],
                'orderby'       => ['default' => 'menu_order',   'description' => 'Service sort field'],
                'order'         => ['default' => 'ASC',          'description' => 'Sort direction'],
                'show_empty'    => ['default' => '1',            'description' => '1 = show services with no FAQs'],
                'empty_message' => ['default' => 'No FAQs available for this service.', 'description' => 'Message for services with no FAQs'],
            ],
            'examples' => [
                ['label' => 'Default FAQ page', 'code' => '[service_faq_page]'],
                ['label' => 'Custom title', 'code' => '[service_faq_page title="Frequently Asked Questions"]'],
                ['label' => 'Hide empty services', 'code' => '[service_faq_page show_empty="0"]'],
                ['label' => 'Custom colors', 'code' => '[service_faq_page btn_bg="#003366" btn_color="#ffffff"]'],
            ],
            'tips' => [
                'Generate this page from Schema → FAQ → Generate Service FAQ Page',
                'FAQs are pulled from the myls_faqs custom field on each service post',
                'Includes FAQ schema markup automatically',
                'Colors default to the site-wide settings in Schema → FAQ — no attributes needed',
            ],
        ],

        [
            'name' => 'association_memberships',
            'category' => 'services',
            'description' => 'Renders association memberships as a responsive logo grid with cards. Data pulled from Schema → Organization settings.',
            'basic_usage' => '[association_memberships]',
            'attributes' => [
                'title'       => ['default' => '(page title)', 'description' => 'Page heading (H1). Empty string to hide.'],
                'columns'     => ['default' => '3',            'description' => 'Grid columns: 2, 3, or 4'],
                'show_desc'   => ['default' => '1',            'description' => '1 = show description text'],
                'show_since'  => ['default' => '1',            'description' => '1 = show "Member since" badge'],
                'link_text'   => ['default' => 'View Our Profile', 'description' => 'Profile link button text'],
                'card_bg'     => ['default' => '',             'description' => 'Card background color override'],
                'card_border' => ['default' => '',             'description' => 'Card border color override'],
            ],
            'examples' => [
                ['label' => 'Default layout', 'code' => '[association_memberships]'],
                ['label' => '4 columns, no description', 'code' => '[association_memberships columns="4" show_desc="0"]'],
                ['label' => 'Custom title', 'code' => '[association_memberships title="Our Professional Affiliations"]'],
            ],
            'tips' => [
                'Manage memberships in Schema → Organization → Memberships section',
                'Generate the page from Schema → Organization → Generate Memberships Page',
                'Each card shows: logo, name, member since badge, description, profile link',
            ],
        ],

        // ============================================================
        // CONTENT DISPLAY
        // ============================================================

        [
            'name' => 'about_the_area',
            'category' => 'content',
            'description' => 'Displays the "About the Area" content from post meta. Typically AI-generated rich HTML about the local area.',
            'basic_usage' => '[about_the_area]',
            'attributes' => [
                'post_id' => ['default' => '0', 'description' => 'Specific post ID (0 = current post)'],
            ],
            'examples' => [
                ['label' => 'Current post', 'code' => '[about_the_area]'],
                ['label' => 'Specific post', 'code' => '[about_the_area post_id="123"]'],
            ],
            'tips' => [
                'Content is stored in about_the_area post meta',
                'Bulk generate via AI tab → About the Area subtab',
            ],
        ],

        [
            'name' => 'custom_blog_cards',
            'category' => 'content',
            'description' => 'Displays blog posts as styled cards with featured images, excerpts, and read more buttons. Supports filtering by category and live search.',
            'basic_usage' => '[custom_blog_cards]',
            'attributes' => [
                'posts_per_page' => ['default' => '9',         'description' => 'Number of posts to display'],
                'category'       => ['default' => '',          'description' => 'Filter by category slug'],
                'columns'        => ['default' => '3',         'description' => 'Grid columns'],
                'show_excerpt'   => ['default' => '1',         'description' => 'Show excerpt text'],
                'show_date'      => ['default' => '1',         'description' => 'Show post date'],
                'show_author'    => ['default' => '1',         'description' => 'Show author name'],
                'show_search'    => ['default' => '0',         'description' => '1 = show live search input'],
                'button_text'    => ['default' => 'Read More', 'description' => 'Button label'],
            ],
            'examples' => [
                ['label' => 'Default blog cards', 'code' => '[custom_blog_cards]'],
                ['label' => 'Specific category', 'code' => '[custom_blog_cards category="news" columns="2"]'],
                ['label' => 'With live search', 'code' => '[custom_blog_cards show_search="1"]'],
            ],
            'tips' => [
                'Live search filters cards instantly as user types',
                'Responsive: 3 cols → 2 cols → 1 col on smaller screens',
            ],
        ],

        [
            'name' => 'divi_child_posts',
            'category' => 'content',
            'description' => 'Displays child posts of the current page as Divi-styled cards. Falls back to sibling posts if no children exist. Great for service area sub-pages.',
            'basic_usage' => '[divi_child_posts]',
            'attributes' => [
                'post_type'    => ['default' => 'service_area', 'description' => 'Post type to query'],
                'parent_id'    => ['default' => '(current)',    'description' => 'Parent post ID'],
                'columns'      => ['default' => '3',            'description' => 'Grid columns (1–6)'],
                'limit'        => ['default' => '6',            'description' => 'Max posts to show'],
                'heading'      => ['default' => '',             'description' => 'Section heading text'],
                'button_text'  => ['default' => 'View Service', 'description' => 'Card button text'],
                'show_tagline' => ['default' => '1',            'description' => 'Show service tagline'],
                'show_icon'    => ['default' => '1',            'description' => 'Show icon if available'],
                'show_image'   => ['default' => '1',            'description' => 'Show featured image'],
                'orderby'      => ['default' => 'menu_order',   'description' => 'Sort field'],
                'order'        => ['default' => 'ASC',          'description' => 'Sort direction'],
                'fallback'     => ['default' => 'siblings',     'description' => 'Fallback: siblings or none'],
            ],
            'examples' => [
                ['label' => 'Child areas', 'code' => '[divi_child_posts]'],
                ['label' => 'Services, 4 cols', 'code' => '[divi_child_posts post_type="service" columns="4"]'],
                ['label' => 'No fallback', 'code' => '[divi_child_posts fallback="none"]'],
            ],
            'tips' => [
                'fallback="siblings" shows sibling posts when current post has no children',
                'Works outside of Divi — name is legacy from the original implementation',
            ],
        ],

        [
            'name' => 'divi_service_posts',
            'category' => 'content',
            'description' => 'Displays service posts in a Divi-compatible card layout with images, taglines, and CTAs.',
            'basic_usage' => '[divi_service_posts]',
            'attributes' => [
                'posts_per_page' => ['default' => '-1',         'description' => 'Number of posts'],
                'columns'        => ['default' => '3',          'description' => 'Grid columns'],
                'button_text'    => ['default' => 'Learn More', 'description' => 'Button label'],
                'show_image'     => ['default' => '1',          'description' => 'Show featured image'],
                'show_excerpt'   => ['default' => '1',          'description' => 'Show excerpt'],
                'orderby'        => ['default' => 'menu_order', 'description' => 'Sort field'],
                'order'          => ['default' => 'ASC',        'description' => 'Sort direction'],
            ],
            'examples' => [
                ['label' => 'Default', 'code' => '[divi_service_posts]'],
                ['label' => 'Top 6, 2 cols', 'code' => '[divi_service_posts posts_per_page="6" columns="2"]'],
            ],
            'tips' => [
                'Similar to service_grid but optimized for Divi themes',
            ],
        ],

        // ============================================================
        // SCHEMA & SEO
        // ============================================================

        [
            'name' => 'faq_schema_accordion',
            'category' => 'schema',
            'description' => 'Bootstrap 5 accordion FAQ section with optional heading and per-instance color theming. Pulls FAQ items from native MYLS meta (_myls_faq_items) with automatic fallback to legacy ACF faq_items repeater. Outputs nothing if no FAQ items are found for the current post.',
            'basic_usage' => '[faq_schema_accordion]',
            'attributes' => [
                'heading'       => ['default' => 'Frequently Asked Questions', 'description' => 'Plain-text heading as centered H2. Set to "" to hide heading entirely.'],
                'heading_sc'    => ['default' => '',                           'description' => 'Recommended. Run another shortcode to build the heading (e.g. heading_sc=\'page_title suffix=" FAQs"\').  Takes priority over heading.'],
                'btn_bg'        => ['default' => '(inherit)',                  'description' => 'Accordion button background color (hex/rgb). Sets --myls-faq-btn-bg CSS variable.'],
                'btn_color'     => ['default' => '(inherit)',                  'description' => 'Accordion button text color (hex/rgb). Sets --myls-faq-btn-color CSS variable.'],
                'heading_color' => ['default' => '(inherit)',                  'description' => 'Heading H2 color (hex/rgb). Sets --myls-faq-heading-color CSS variable.'],
            ],
            'examples' => [
                ['label' => 'Default heading',           'code' => '[faq_schema_accordion]'],
                ['label' => 'Page title + FAQs suffix',  'code' => '[faq_schema_accordion heading_sc=\'page_title suffix=" FAQs"\']'],
                ['label' => 'City/State heading',        'code' => '[faq_schema_accordion heading_sc="city_state"]'],
                ['label' => 'No heading',                'code' => '[faq_schema_accordion heading=""]'],
                ['label' => 'Custom brand colors',       'code' => '[faq_schema_accordion heading_sc=\'page_title suffix=" FAQs"\' btn_bg="#172751" btn_color="#ffffff" heading_color="#172751"]'],
            ],
            'tips' => [
                'Use heading_sc instead of heading to avoid WordPress nested bracket parsing issues',
                'FAQ items are managed via the Utilities → FAQ Editor tab',
                'Accordion is Bootstrap 5 powered — JS is auto-enqueued by the shortcode if not already present',
                'Outputs nothing if no FAQ items are saved for the current post',
            ],
        ],

        [
            'name' => 'page_title',
            'category' => 'schema',
            'description' => 'Returns the current WordPress page title as plain text. Always uses the real WP title — does not check the Alternate Page Title field.',
            'basic_usage' => '[page_title]',
            'attributes' => [
                'id'     => ['default' => '',  'description' => 'Optional explicit post ID'],
                'prefix' => ['default' => '',  'description' => 'Text prepended to the title'],
                'suffix' => ['default' => '',  'description' => 'Text appended to the title'],
            ],
            'examples' => [
                ['label' => 'Default',         'code' => '[page_title]'],
                ['label' => 'With FAQs suffix','code' => '[page_title suffix=" FAQs"]'],
                ['label' => 'Specific post',   'code' => '[page_title id="123"]'],
            ],
            'tips' => [
                'Use inside other shortcode attributes (e.g. heading_sc=\'page_title suffix=" FAQs"\')',
                'For alternate page titles in Theme Builder headings, use [heading_title] instead',
                'Output is plain text (esc_html) — safe for use inside attributes',
            ],
        ],

        [
            'name' => 'heading_title',
            'category' => 'schema',
            'description' => 'Returns the Alternate Page Title if set, otherwise falls back to the WordPress page title. Designed for Elementor Theme Builder heading widgets where a custom heading is needed.',
            'basic_usage' => '[heading_title]',
            'attributes' => [
                'id'     => ['default' => '',  'description' => 'Optional explicit post ID'],
                'prefix' => ['default' => '',  'description' => 'Text prepended to the title'],
                'suffix' => ['default' => '',  'description' => 'Text appended to the title'],
            ],
            'examples' => [
                ['label' => 'Default',                'code' => '[heading_title]'],
                ['label' => 'With prefix and suffix', 'code' => '[heading_title prefix="About " suffix=" Services"]'],
                ['label' => 'Specific post',          'code' => '[heading_title id="123"]'],
            ],
            'tips' => [
                'Set the "Alternate Page Title" field in the MYLS City, State metabox in the post editor',
                'If the alternate title is blank, falls back to the standard WordPress page title',
                'Output is plain text (esc_html) — safe for use inside heading widgets',
            ],
        ],

        [
            'name' => 'myls_youtube_embed',
            'category' => 'schema',
            'description' => 'Lightweight YouTube video embed with thumbnail placeholder overlay. No iframe loaded until user clicks — great for page speed. Outputs inline VideoObject JSON-LD schema.',
            'basic_usage' => '[myls_youtube_embed video_id="dQw4w9WgXcQ"]',
            'attributes' => [
                'video_id'       => ['default' => '',    'description' => 'YouTube video ID (11 chars). Required if url/use_page_video not provided.'],
                'url'            => ['default' => '',    'description' => 'Full YouTube URL (watch, embed, shorts, youtu.be). Extracts ID automatically.'],
                'use_page_video' => ['default' => '0',   'description' => '1 = read video URL from current page meta (_myls_page_video_url or ACF video_url). Falls back to fallback_id then site default.'],
                'fallback_id'    => ['default' => '',    'description' => 'Fallback video ID when use_page_video finds no URL on the page.'],
                'title'          => ['default' => '',    'description' => 'Video title for schema and alt text. Defaults to current page title.'],
                'autoplay'       => ['default' => '1',   'description' => 'Autoplay + mute on click. Set 0 to disable.'],
                'play_color'     => ['default' => '',    'description' => 'Hex color for play button. Overrides admin setting.'],
            ],
            'examples' => [
                ['label' => 'By video ID',            'code' => '[myls_youtube_embed video_id="dQw4w9WgXcQ"]'],
                ['label' => 'By URL',                 'code' => '[myls_youtube_embed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"]'],
                ['label' => 'From embed code',        'code' => '[myls_youtube_embed url="https://www.youtube.com/embed/dQw4w9WgXcQ"]'],
                ['label' => 'From page meta',         'code' => '[myls_youtube_embed use_page_video="1"]'],
                ['label' => 'Page meta + fallback',   'code' => '[myls_youtube_embed use_page_video="1" fallback_id="dQw4w9WgXcQ"]'],
                ['label' => 'With custom title',      'code' => '[myls_youtube_embed video_id="dQw4w9WgXcQ" title="Paver Sealing Demo"]'],
            ],
            'tips' => [
                'Thumbnail placeholder — iframe only loads on click (saves ~500KB per embed)',
                'Stays within parent container width — ideal for Theme Builder columns and footers',
                'Outputs VideoObject schema with thumbnailUrl, embedUrl, uploadDate',
                'Uses myls_yt_thumbnail_url() for stored/canonical thumbnail resolution',
            ],
        ],

        [
            'name' => 'yoast_title',
            'category' => 'schema',
            'description' => 'Outputs the Yoast SEO title or falls back to the page title. Lets you display the meta title on the page.',
            'basic_usage' => '[yoast_title]',
            'attributes' => [
                'tag' => ['default' => 'h1', 'description' => 'HTML wrapper tag'],
            ],
            'examples' => [
                ['label' => 'Default H1', 'code' => '[yoast_title]'],
                ['label' => 'As H2', 'code' => '[yoast_title tag="h2"]'],
            ],
            'tips' => [
                'Falls back to regular page title if Yoast is not active',
                'Ensures on-page title matches meta title for SEO consistency',
                'Also registered as [seo_title] — both work identically',
            ],
        ],

        [
            'name' => 'post_author',
            'category' => 'schema',
            'description' => 'Displays post author information with optional avatar, bio, and social links.',
            'basic_usage' => '[post_author]',
            'attributes' => [
                'show_avatar' => ['default' => '1',  'description' => 'Show author avatar'],
                'show_bio'    => ['default' => '1',  'description' => 'Show author bio'],
                'avatar_size' => ['default' => '96', 'description' => 'Avatar size in pixels'],
            ],
            'examples' => [
                ['label' => 'Full author box', 'code' => '[post_author]'],
                ['label' => 'Name only', 'code' => '[post_author show_avatar="0" show_bio="0"]'],
            ],
            'tips' => [
                'Great for blog posts — adds author credibility signals',
                'Author info comes from WordPress user profile',
            ],
        ],

        // ============================================================
        // SOCIAL & SHARING
        // ============================================================

        [
            'name' => 'social_share',
            'category' => 'social',
            'description' => 'Adds social sharing buttons for Facebook, Twitter, LinkedIn, and email.',
            'basic_usage' => '[social_share]',
            'attributes' => [
                'platforms' => ['default' => 'facebook,twitter,linkedin,email', 'description' => 'Comma-separated platform list'],
                'style'     => ['default' => 'icons',  'description' => 'Display: icons, buttons, or text'],
                'size'      => ['default' => 'medium', 'description' => 'Icon size: small, medium, large'],
            ],
            'examples' => [
                ['label' => 'All platforms', 'code' => '[social_share]'],
                ['label' => 'Facebook + Twitter', 'code' => '[social_share platforms="facebook,twitter"]'],
                ['label' => 'Button style', 'code' => '[social_share style="buttons"]'],
            ],
            'tips' => [
                'Add to blog post templates for easy content sharing',
                'Shares the current page URL and title automatically',
            ],
        ],

        // ============================================================
        // UTILITY & TOOLS
        // ============================================================

        [
            'name' => 'ssseo_map_embed',
            'category' => 'utility',
            'description' => 'Embeds a Google Map based on address, city_state field, or coordinates. Supports responsive ratio sizing.',
            'basic_usage' => '[ssseo_map_embed]',
            'attributes' => [
                'field'   => ['default' => 'city_state', 'description' => 'ACF field to read address from'],
                'ratio'   => ['default' => '16x9',      'description' => 'Aspect ratio: 16x9, 4x3, 1x1'],
                'width'   => ['default' => '100%',       'description' => 'Map width (px or %)'],
                'address' => ['default' => '',           'description' => 'Direct address override'],
                'zoom'    => ['default' => '14',         'description' => 'Map zoom level: 1–20'],
            ],
            'examples' => [
                ['label' => 'From city_state field', 'code' => '[ssseo_map_embed]'],
                ['label' => 'Direct address', 'code' => '[ssseo_map_embed address="123 Main St, Tampa, FL"]'],
                ['label' => '4:3 ratio', 'code' => '[ssseo_map_embed ratio="4x3"]'],
            ],
            'tips' => [
                'Requires Google Maps API key in plugin settings',
                'Also registered as myls_map_embed for newer installations',
            ],
        ],

        [
            'name' => 'myls_ajax_search',
            'category' => 'utility',
            'description' => 'Live AJAX-powered search box with instant dropdown results as user types. Supports post-type filtering, priority ordering, and title-first ranking.',
            'basic_usage' => '[myls_ajax_search]',
            'attributes' => [
                'post_types'  => ['default' => 'current', 'description' => 'Which post types to search. "current" = same type as the page the shortcode is on. "all" = all public post types. Or a comma-separated list: "post,page,service"'],
                'placeholder' => ['default' => 'Search...', 'description' => 'Input placeholder text'],
                'limit'       => ['default' => '10',        'description' => 'Maximum number of results to display (alias: max)'],
                'priority'    => ['default' => '(none)',     'description' => 'Comma-separated post types to guarantee at the top of results in order, e.g. "service,page". Each listed type gets its own query pass so it is represented before the budget is exhausted'],
                'description' => ['default' => '1',         'description' => 'Show the "Searching: ..." hint below the input. Set to "0" to hide (alias: hint)'],
                'show_type'   => ['default' => '0',         'description' => 'Show post type label next to each result. Set to "1" to enable'],
                'min_chars'   => ['default' => '2',         'description' => 'Minimum characters before search fires'],
                'debounce_ms' => ['default' => '200',       'description' => 'Debounce delay in milliseconds between keystrokes'],
            ],
            'examples' => [
                ['label' => 'Default (current post type)', 'code' => '[myls_ajax_search]'],
                ['label' => 'Search all post types',       'code' => '[myls_ajax_search post_types="all" limit="10" placeholder="Search everything..."]'],
                ['label' => 'Services only with priority',  'code' => '[myls_ajax_search post_types="service" limit="10" priority="service,video" placeholder="Search..." description="0"]'],
                ['label' => 'Blog posts and pages',        'code' => '[myls_ajax_search post_types="post,page" show_type="1"]'],
            ],
            'tips' => [
                'Results appear instantly as user types — no page reload',
                'Title matches are ranked first, then content/excerpt matches fill remaining slots',
                'Use priority="service,page" to guarantee those types appear first — each gets its own query pass in the listed order',
                'post_types="all" searches every public post type (posts, pages, services, videos, etc.)',
                'Mobile-friendly dropdown design with thumbnail support',
            ],
        ],

        [
            'name' => 'gmb_address',
            'category' => 'utility',
            'description' => 'Displays the business address from Organization schema settings.',
            'basic_usage' => '[gmb_address]',
            'attributes' => [
                'format' => ['default' => 'full',  'description' => 'Address format: full, street, city, state, zip'],
                'link'   => ['default' => '0',     'description' => '1 = link to Google Maps directions'],
            ],
            'examples' => [
                ['label' => 'Full address', 'code' => '[gmb_address]'],
                ['label' => 'With Maps link', 'code' => '[gmb_address link="1"]'],
                ['label' => 'City only', 'code' => '[gmb_address format="city"]'],
            ],
            'tips' => [
                'Pulls from Schema → Organization settings',
                'link="1" creates a clickable Google Maps directions link',
            ],
        ],

        [
            'name' => 'gmb_hours',
            'category' => 'utility',
            'description' => 'Displays business hours from Organization schema settings as a formatted table.',
            'basic_usage' => '[gmb_hours]',
            'attributes' => [],
            'examples' => [
                ['label' => 'Business hours table', 'code' => '[gmb_hours]'],
            ],
            'tips' => [
                'Hours are managed in Schema → Organization → Business Hours',
                'Automatically highlights today\'s hours',
            ],
        ],

        [
            'name' => 'ssseo_category_list',
            'category' => 'utility',
            'description' => 'Displays a formatted list of post categories with optional post counts.',
            'basic_usage' => '[ssseo_category_list]',
            'attributes' => [
                'separator'  => ['default' => ', ', 'description' => 'Text between categories'],
                'show_count' => ['default' => '0',  'description' => '1 = show post count per category'],
            ],
            'examples' => [
                ['label' => 'Comma-separated', 'code' => '[ssseo_category_list]'],
                ['label' => 'With counts', 'code' => '[ssseo_category_list show_count="1"]'],
            ],
            'tips' => [
                'Categories are automatically linked to their archive pages',
            ],
        ],

        [
            'name' => 'post_title',
            'category' => 'utility',
            'description' => 'Outputs the post or page title. Useful in Elementor, Divi, or template contexts where the title needs to be injected via shortcode.',
            'basic_usage' => '[post_title]',
            'attributes' => [
                'id' => ['default' => 'current', 'description' => 'Post ID (defaults to current post)'],
            ],
            'examples' => [
                ['label' => 'Current post', 'code' => '[post_title]'],
                ['label' => 'Specific post', 'code' => '[post_title id="42"]'],
            ],
            'tips' => [
                'Useful in page builder heading modules that support shortcodes',
                'Returns the raw title text without wrapping HTML',
            ],
        ],

        [
            'name' => 'yearly_archives',
            'category' => 'utility',
            'description' => 'Generates a list of year links to post archives. Each year with published posts gets a clickable link.',
            'basic_usage' => '[yearly_archives]',
            'attributes' => [],
            'examples' => [
                ['label' => 'Archive links', 'code' => '[yearly_archives]'],
            ],
            'tips' => [
                'Outputs a <ul> list of years with links to year archive pages',
                'Only shows years that have published posts',
                'Great for sidebars or footer archive navigation',
            ],
        ],

        [
            'name' => 'ssseo_places_status',
            'category' => 'utility',
            'description' => 'Displays real-time Open/Closed status from Google Places API with optional next open/close time. Supports badge, text, and boolean output modes.',
            'basic_usage' => '[ssseo_places_status]',
            'attributes' => [
                'place_id'  => ['default' => '',        'description' => 'Google Place ID (defaults to site setting)'],
                'output'    => ['default' => 'text',    'description' => 'Display mode: text, badge, or boolean'],
                'refresh'   => ['default' => '900',     'description' => 'Cache duration in seconds'],
                'fallback'  => ['default' => 'unknown', 'description' => 'Text when status unavailable'],
                'show_next' => ['default' => '1',       'description' => '1 = show next open/close time'],
                'show_day'  => ['default' => 'abbr',    'description' => 'Day format: abbr or full'],
                'debug'     => ['default' => '0',       'description' => '1 = show debug info'],
            ],
            'examples' => [
                ['label' => 'Default text', 'code' => '[ssseo_places_status]'],
                ['label' => 'Badge style', 'code' => '[ssseo_places_status output="badge"]'],
                ['label' => 'Specific place', 'code' => '[ssseo_places_status place_id="ChIJ..." output="badge"]'],
                ['label' => 'No next time', 'code' => '[ssseo_places_status show_next="0"]'],
            ],
            'tips' => [
                'Uses Google Places API — requires API key in plugin settings',
                'Caches results for 15 minutes by default to stay within API limits',
                'output="boolean" returns "true"/"false" for conditional logic',
                'Automatically calculates open/closed from business hours if API doesn\'t return it directly',
            ],
        ],

        [
            'name' => 'social_share_icon',
            'category' => 'social',
            'description' => 'Icon-based social sharing with a modal popup. Compact sharing buttons that expand into a share dialog.',
            'basic_usage' => '[social_share_icon]',
            'attributes' => [],
            'examples' => [
                ['label' => 'Share icons', 'code' => '[social_share_icon]'],
            ],
            'tips' => [
                'More compact than [social_share] — opens a sharing modal on click',
                'Automatically shares current page URL and title',
                'Includes Facebook, Twitter, LinkedIn, and email',
            ],
        ],

        [
            'name' => 'social_links',
            'category' => 'social',
            'description' => 'Displays branded circular social media icons linked to the Organization schema sameAs / social profile URLs. Auto-detects platform from URL with inline SVG icons — no external icon library needed.',
            'basic_usage' => '[social_links]',
            'attributes' => [
                'size'      => ['default' => '44',     'description' => 'Icon circle diameter in px'],
                'gap'       => ['default' => '12',     'description' => 'Space between icons in px'],
                'align'     => ['default' => 'center', 'description' => 'Alignment: left, center, right'],
                'style'     => ['default' => 'color',  'description' => 'Style: color (branded), mono-dark, mono-light'],
                'platforms' => ['default' => '',        'description' => 'Comma-separated whitelist (e.g. facebook,instagram,youtube)'],
                'exclude'   => ['default' => '',        'description' => 'Comma-separated blacklist (e.g. tiktok,pinterest)'],
                'new_tab'   => ['default' => '1',       'description' => '1 = open links in new tab'],
            ],
            'examples' => [
                ['label' => 'All saved profiles', 'code' => '[social_links]'],
                ['label' => 'Large icons, left-aligned', 'code' => '[social_links size="56" align="left"]'],
                ['label' => 'Monochrome dark', 'code' => '[social_links style="mono-dark"]'],
                ['label' => 'Only Facebook & Instagram', 'code' => '[social_links platforms="facebook,instagram"]'],
                ['label' => 'Exclude TikTok', 'code' => '[social_links exclude="tiktok"]'],
            ],
            'tips' => [
                'Reads from Organization → Social Profiles (Schema tab) — no duplicate data entry',
                'Supports 15+ platforms: Facebook, Instagram, X, YouTube, LinkedIn, TikTok, Pinterest, Yelp, Google Business, Google Maps, BBB, Thumbtack, Angi, Nextdoor',
                'Unknown URLs get a globe icon with the domain name as tooltip',
                'Uses inline SVG — no Bootstrap Icons or Font Awesome dependency',
            ],
        ],

        [
            'name' => 'google_reviews_slider',
            'category' => 'social',
            'description' => 'Pulls Google reviews via the Places API and displays them in a glassmorphism-styled Swiper slider with star ratings, reviewer names, and autoplay.',
            'basic_usage' => '[google_reviews_slider]',
            'attributes' => [
                'place_id'        => ['default' => '',         'description' => 'Google Place ID (defaults to saved setting)'],
                'min_rating'      => ['default' => '0',        'description' => 'Minimum stars to show (0 = all)'],
                'max_reviews'     => ['default' => '0',        'description' => 'Limit number of reviews (0 = all)'],
                'sort'            => ['default' => 'default',  'description' => 'Sort: default (Google relevance), newest, highest'],
                'speed'           => ['default' => '5000',     'description' => 'Autoplay speed in ms'],
                'cache_hours'     => ['default' => '24',       'description' => 'Cache duration in hours'],
                'blur'            => ['default' => '14',       'description' => 'Backdrop blur in px'],
                'overlay_opacity' => ['default' => '0.12',     'description' => 'Glass overlay opacity (0–1)'],
                'star_color'      => ['default' => '#FFD700',  'description' => 'Star icon color'],
                'text_color'      => ['default' => '#ffffff',  'description' => 'Review text color'],
                'excerpt_words'   => ['default' => '0',        'description' => 'Word limit for review text (0 = full)'],
            ],
            'examples' => [
                ['label' => 'Default (all reviews)', 'code' => '[google_reviews_slider]'],
                ['label' => '4+ star reviews only', 'code' => '[google_reviews_slider min_rating="4"]'],
                ['label' => 'Top 5, newest first', 'code' => '[google_reviews_slider max_reviews="5" sort="newest"]'],
                ['label' => 'Custom styling', 'code' => '[google_reviews_slider blur="20" overlay_opacity="0.18" star_color="#FFA500"]'],
            ],
            'tips' => [
                'Requires Google Places API key and Place ID in AIntelligize → API Integration',
                'Reviews are cached via WP transients — default 24 hours to minimize API calls',
                'Place this inside a section with a background image for the glassmorphism effect to show through',
                'Google Places API returns a maximum of 5 reviews — use min_rating and sort to curate the best ones',
                'Swiper.js is loaded from CDN only when the shortcode is used on the page',
            ],
        ],

        [
            'name' => 'google_review_count',
            'category' => 'social',
            'description' => 'Outputs the total Google review/rating count as an inline value. Data comes from the 4-hour cron sync — no additional API calls.',
            'basic_usage' => '[google_review_count]',
            'attributes' => [
                'class' => ['default' => '', 'description' => 'Extra CSS class on the <span> wrapper'],
            ],
            'examples' => [
                ['label' => 'Inline count', 'code' => '[google_review_count]'],
                ['label' => 'In a sentence', 'code' => 'We have [google_review_count] five-star reviews!'],
            ],
            'tips' => [
                'Reads from myls_google_places_rating_count (synced every 4 hours)',
                'Returns empty string if no data — safe to use anywhere',
                'Wraps value in <span class="google-review-count"> for styling',
            ],
        ],

        [
            'name' => 'google_aggregate_rating',
            'category' => 'social',
            'description' => 'Outputs the aggregate Google star rating (e.g. 4.8) as an inline value. Data comes from the 4-hour cron sync — no additional API calls.',
            'basic_usage' => '[google_aggregate_rating]',
            'attributes' => [
                'class' => ['default' => '', 'description' => 'Extra CSS class on the <span> wrapper'],
            ],
            'examples' => [
                ['label' => 'Inline rating', 'code' => '[google_aggregate_rating]'],
                ['label' => 'In a sentence', 'code' => 'Rated [google_aggregate_rating] out of 5 on Google'],
            ],
            'tips' => [
                'Reads from myls_google_places_rating (synced every 4 hours)',
                'Returns empty string if no data — safe to use anywhere',
                'Wraps value in <span class="google-aggregate-rating"> for styling',
            ],
        ],

        [
            'name' => 'google_rating_badge',
            'category' => 'social',
            'description' => 'Visual Google Rating badge widget with the Google "G" logo, aggregate rating number, gold stars, and "Based on X reviews" text. Matches the standard Google Rating popup badge style.',
            'basic_usage' => '[google_rating_badge]',
            'attributes' => [
                'class'      => ['default' => '',        'description' => 'Extra CSS class on the badge wrapper'],
                'star_color' => ['default' => '#FFD700', 'description' => 'Star icon color (hex)'],
                'link'       => ['default' => 'auto',    'description' => 'auto = Google reviews page via Place ID, custom URL, or 0 to disable'],
                'dark'       => ['default' => '0',       'description' => '1 = dark background variant'],
            ],
            'examples' => [
                ['label' => 'Default badge', 'code' => '[google_rating_badge]'],
                ['label' => 'Dark variant', 'code' => '[google_rating_badge dark="1"]'],
                ['label' => 'No link', 'code' => '[google_rating_badge link="0"]'],
                ['label' => 'Custom link', 'code' => '[google_rating_badge link="https://g.page/your-business/review"]'],
            ],
            'tips' => [
                'Auto-links to Google reviews page using the stored Place ID',
                'Reads from existing cron-synced options — no additional API calls',
                'Self-contained CSS with no external dependencies',
                'Use dark="1" on dark page backgrounds for proper contrast',
                'Inline SVG Google "G" logo — no external image requests',
            ],
        ],

        // ============================================================
        // YOUTUBE
        // ============================================================

        [
            'name' => 'myls_youtube_panel',
            'category' => 'youtube',
            'description' => 'Embeds a YouTube video with an accordion panel below containing the video description and optional AI-generated transcript.',
            'basic_usage' => '[myls_youtube_panel url="https://youtube.com/watch?v=..."]',
            'attributes' => [
                'url'        => ['default' => '',  'description' => 'YouTube video URL (required)'],
                'transcript' => ['default' => '1', 'description' => '1 = include transcript accordion panel'],
            ],
            'examples' => [
                ['label' => 'With transcript', 'code' => '[myls_youtube_panel url="https://youtube.com/watch?v=dQw4w9WgXcQ"]'],
                ['label' => 'No transcript', 'code' => '[myls_youtube_panel url="https://youtube.com/watch?v=dQw4w9WgXcQ" transcript="0"]'],
            ],
            'tips' => [
                'Accordion panels use Bootstrap styling — Description and Transcript sections',
                'Transcript is fetched via YouTube Data API if available',
                'Supports standard youtube.com, youtu.be, and shorts URLs',
            ],
        ],

        [
            'name' => 'myls_youtube_with_transcript',
            'category' => 'youtube',
            'description' => 'Embeds a YouTube video with auto-generated transcript content. Used in video blog posts created by the YouTube Video Blog module.',
            'basic_usage' => '[myls_youtube_with_transcript url="https://youtube.com/watch?v=..."]',
            'attributes' => [
                'url' => ['default' => '', 'description' => 'YouTube video URL (required)'],
            ],
            'examples' => [
                ['label' => 'Basic embed', 'code' => '[myls_youtube_with_transcript url="https://youtube.com/watch?v=dQw4w9WgXcQ"]'],
            ],
            'tips' => [
                'Typically auto-inserted by the YouTube Video Blog module when generating draft posts',
                'Requires YouTube Data API key configured in plugin settings',
                'For manual embeds with more control, use [myls_youtube_panel] instead',
            ],
        ],

        [
            'name' => 'youtube_channel_list_detailed',
            'category' => 'youtube',
            'description' => 'Displays a card list of recent videos from a YouTube channel with large thumbnails and optional description excerpts.',
            'basic_usage' => '[youtube_channel_list_detailed]',
            'attributes' => [
                'max'      => ['default' => '6',   'description' => 'Maximum videos to display'],
                'channel'  => ['default' => '',    'description' => 'YouTube channel ID (defaults to plugin setting)'],
                'desc_max' => ['default' => '280', 'description' => 'Maximum characters for description excerpt'],
            ],
            'examples' => [
                ['label' => 'Default 6 videos', 'code' => '[youtube_channel_list_detailed]'],
                ['label' => '10 videos, short desc', 'code' => '[youtube_channel_list_detailed max="10" desc_max="150"]'],
                ['label' => 'Specific channel', 'code' => '[youtube_channel_list_detailed channel="UCxxxxxxx"]'],
            ],
            'tips' => [
                'Requires YouTube Data API key in plugin settings',
                'Channel ID defaults to the one configured in the YouTube tab',
                'Videos are pulled from the channel\'s uploads playlist',
                'Thumbnails link directly to YouTube',
            ],
        ],

    ];
}
