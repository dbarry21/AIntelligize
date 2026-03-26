=== AIntelligize ===
Contributors: davebarry
Tags: local seo, schema, ai, faq, utilities, person schema, linkedin
Requires at least: 6.0
Tested up to: 6.7.2
Stable tag: 7.9.18.31
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AIntelligize is a modular local SEO toolkit with schema, AI tools, bulk operations, and shortcode utilities.

== Description ==

This plugin provides a modular admin toolkit for local SEO workflows including schema generation, AI content tools, bulk operations, and shortcode utilities.

**Key Features:**
* Person Schema with E-E-A-T optimization (multi-person, Wikidata/Wikipedia expertise linking)
* LinkedIn Import — AI-powered profile extraction from pasted content
* Fillable PDF export for person profiles
* Organization & LocalBusiness schema with awards and certifications
* AI-powered content generation (meta descriptions, excerpts, FAQs, about areas, geo content)
* /llms.txt for AI discovery
* FAQ accordion with schema markup
* Google Maps integration for service areas
* Divi Builder module support
* AIntelligize Stats — AI usage analytics, cost tracking, handler breakdown with Chart.js
* Search Stats — Focus keyword & FAQ tracking, Google Autocomplete suggestions, GSC metrics, AI Overview detection, per-post SERP rank with history tracking
* Google Search Console OAuth integration
* 38+ shortcodes for location data, service grids, schema, social links, Google reviews, YouTube, and utilities
* YouTube Video Blog — auto-generate blog posts from channel videos with 12-hour auto-refresh, overwrite support, transcript accordion, and email notifications
* Enterprise logging with quality control and batch processing

== Installation ==

1. Upload the `my-local-seo` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.

== Upgrade Notice ==

= 7.9.18.29 =
Schema v2 fixes: ItemList city entity encoding, GeoCoordinates float types with new helper + geocode button, Person node global emit when no pages assigned, image/logo separation with new Business Photo URL admin field.

= 7.9.18.28 =
Fix 8 schema bugs: HTML entity double-encoding on city names, &amp; in TikTok sameAs URL, VideoObject @id pointing to foreign domain, FAQ CTA noise in acceptedAnswer.text, missing url on LocalBusiness, telephone format inconsistency (now E.164), FAQPage publisher referencing wrong entity, missing logo on LocalBusiness node.

= 7.9.18.27 =
AggregateRating schema support: new admin panel in Schema → Local Business with enable toggle, Google Places (auto) vs Manual Override source, live preview. Schema output controlled by myls_aggregate_rating option — off by default.

= 7.9.18.24 =
New shortcodes: [google_review_count] (inline review count), [google_aggregate_rating] (inline star rating), [google_rating_badge] (visual badge widget with Google G logo, stars, and review count — auto-links to Google reviews page). All read from existing cron-synced data.

= 7.9.8 =
Added 14-page YT Video Blog documentation PDF with help link from the tab heading.

= 7.9.7 =
Dedup now by video ID only (fixes dynamic title issues). Default status changed to Publish. New {transcript} template token with Bootstrap 5 accordion. Transcript editing via post editor and Video Transcripts interface. Optional email notification after generation.

= 7.9.6 =
YT Video Blog tab modernized with auto-refresh (12-hour cron) and overwrite toggle. New card-based UI with scheduling controls and last-run status display.

= 7.8.76 =
Critical fix for VideoObject schema on sites using Elementor Theme Builder. Elementor conditions were not matching due to format mismatch — VideoObject schema now generates correctly on all pages with Template Builder-applied video content.

= 7.8.74 =
New VideoObject auto-detector: automatically emits VideoObject schema on any page containing video, across Elementor, Beaver Builder, Divi, WPBakery, Gutenberg, and Classic editor. Requires YouTube Data API key (already in API Integration) for duration metadata.

= 7.0.2 =
New shortcodes: [google_reviews_slider] (Google Places API reviews in glassmorphism Swiper slider with caching), [social_links] (branded circular icons auto-detected from Organization schema sameAs URLs). Enhanced [service_grid] with aspect_ratio attribute and complete CSS foundation. All three shortcodes include inline CSS — no external stylesheet dependencies.

= 7.0 =
Major release: Search Stats dashboard with GSC integration, per-post SERP rank tracking with history, AI Overview detection, Focus Keyword + FAQ autocomplete expansion, Google Search Console OAuth, AIntelligize Stats dashboard, enterprise AI logging, comprehensive shortcode documentation update.

= 6.3.2.2 =
Fix: Excerpt and HTML Excerpt batch generation no longer fails with AJAX errors. Changed from 5-at-a-time to 1-at-a-time processing to prevent server timeout. Better error messages.

= 6.3.2.1 =
Rewritten: PDF export now uses print-friendly HTML window instead of jsPDF. Clean white layout, perfect Unicode/emoji, color-coded sections, zero dependencies.

= 6.3.2.0 =
Fix: Ctrl+A in results terminals now selects only terminal contents, not entire page. Covers all 8 AI result panels.

= 6.3.1.9 =
Improved: About the Area now shows live progress text during batch generation ("Processing 3 of 228 — Post Title…").

= 6.3.1.8 =
New: AIntelligize Stats dashboard — AI usage analytics, cost tracking, handler breakdown, activity log with Chart.js visualizations. Auto-logs every AI call.

= 6.3.1.7 =
Fix: Browser hangs on large batches (200+ posts). Meta, Excerpt, and HTML Excerpt generation now process 5 posts at a time with live progress and Stop button.

= 6.3.1.6 =
Fix: About the Area log header showed hardcoded "gpt-4o" instead of actual configured model.

= 6.3.1.5 =
Fix: Empty model string caused Anthropic API 400 error on all AI generation. Now falls back to provider default model when no model is configured.

= 6.3.1.4 =
New Prompt Reset utility (Utilities tab). Rewrote all 11 prompt templates with structural patterns, anti-duplication, banned phrases, and output enforcement. Use Prompt Reset to flush DB and pick up new defaults.

= 6.3.1.3 =
Rewritten excerpt prompts with 6 structural patterns, content_snippet context, banned phrases, CTA rotation. Expanded Variation Engine for both excerpt contexts. Added VE injection to HTML excerpt bulk handler.

= 6.3.1.2 =

= 6.3.1.1 =
Added "Generate Both for Selected" button to AI → Meta subtab. Runs titles then descriptions in one click.

= 6.3.1.0 =
Fixes AI meta title/description generation: cleanup regex bug that stripped valid output, full error diagnostics in results log, improved prompt templates with anti-duplication, universal page builder content extraction.

= 6.3.0.8 =
Universal page builder compatibility (Elementor, DIVI, Beaver Builder, WPBakery). Rewritten SEO meta prompt templates with 6-angle rotation and anti-duplication mechanics.

= 6.3.0.5 =
AI-powered llms.txt generation with city-specific content and hierarchical service area organization. Full-text llms-full.txt endpoint.

= 4.15.0 =
Major Person Schema update: LinkedIn AI Import (paste profile content for auto-extraction), fillable PDF export, person labels with live accordion headers. Requires OpenAI API key for LinkedIn import feature.

= 4.12.0 =
New Person Schema subtab with full multi-person support, E-E-A-T optimization, Wikidata/Wikipedia expertise linking, credentials, education, memberships, and per-person page assignment.

= 4.6.32 =
CRITICAL FIX: Increases default max_tokens from 1200 to 4000 so AI FAQ Generator produces full 10-15 FAQs instead of stopping after 1-2. Also adds context-specific token handling.

= 4.6.31 =
Fixes AI FAQ Generator to properly follow prompt templates with {{CITY_STATE}} and {{CONTACT_URL}} variables, ensures variant defaults are respected, and improves city/state detection from post meta.

= 4.6.19 =
Fixes a Utilities → FAQ Quick Editor scoping bug that could crash some installs, and ensures the AI → FAQs Builder LONG variant uses the new multi-block prompt (auto-migrates legacy one-line templates).

= 4.6.18 =
Fixes Utilities → FAQ Quick Editor batch save deletion flag handling.

= 4.6.17 =
Fixes MYLS delete-auto for AI-inserted FAQs by matching hash normalization to the insert routine (whitespace/nbsp handling).

= 4.6.16 =
Fixes AI → FAQs Builder insertion/deletion for the new multi-block FAQ HTML format and fixes the editor “Delete on save” checkbox behavior.

= 4.6.15 =
AI → FAQs Builder now defaults to a longer, AI Overview-friendly FAQ format (multi-block HTML per FAQ: h3 + paragraphs + bullets + “Helpful next step”). Adds LONG/SHORT variant support.

= 4.6.13 =
Adds Awards support on Organization/LocalBusiness schema output.


= 4.6.12 =
Adds Schema → About Us subtab with About page selector and optional overrides to output valid AboutPage schema.

= 4.6.11 =
Adds a search box on AI → FAQs Builder to quickly filter the loaded posts list by title or ID.

= 4.6.10 =
Fixes AI → FAQs Builder batch processing so each selected post is generated and auto-inserted into MYLS FAQs, with per-post appended preview + improved logging.

= 4.6.8 =
Updates the Admin Bar “SEO Stuff” menu: adds Schema.org validator and improves Google index check (live status + site: results link).

= 4.6.7 =
Enhances /llms.txt with a Business details section (Organization/LocalBusiness), and upgrades FAQ links to use stable anchors (#faq-1, #faq-2, ...).

= 4.6.6 =
Expands /llms.txt with Primary Services, Service Areas, and a master FAQ list, plus Utilities toggles to control output.

= 4.6.5 =
Adds a basic /llms.txt endpoint (served at the site root) so LLMs and AI tools can discover key site links.

= 4.6.4 =
Fixes Divi Builder preview showing raw script contents as visible text (front-end output unchanged).

= 4.6.3 =
Restores the Divi FAQ Accordion module so FAQs render correctly inside the Divi Builder (Visual Builder + backend).

= 4.6.2 =
Adds a Docs → Release Notes tab and introduces upgrade notice + changelog tracking. If your host blocks plugin-file writes, release note entries will be queued inside WP instead.

= 4.6.1 =
FAQ Quick Editor now supports multi-post batch save and WYSIWYG answers.

= 4.6.0 =
Utilities now includes the FAQ Quick Editor and reorganized FAQ migration tools.

== Changelog ==

= 7.8.79 =
* NEW: Style Editor now has a "Frontend Source" tab showing the full contents of assets/frontend.css (read-only) alongside the Custom Overrides editor. Switch tabs to see existing styles before writing overrides.

= 7.8.78 =
* NEW: Replaced Custom CSS subtab with Style Editor — modern UI with CSS file info cards, Insert Selector dropdown for shortcode CSS classes, live preview with device simulation, and collapsible CSS class reference panel.

= 7.8.77 =
* FIX: Debug constants (MYLS_SCHEMA_DEBUG, MYLS_DEBUG_ORG, MYLS_DEBUG_LB) now default to false instead of true in production.
* FIX: Removed `sslverify => false` from Google Places API cron call — now uses WordPress default SSL verification.
* FIX: Removed duplicate require_once for admin/api-integration-tests.php.
* FIX: Removed duplicate ABSPATH exit guard.
* REFACTOR: Moved all static inline CSS to enqueued stylesheet files for easier editing and better caching. Admin tab nav, Bootstrap shim, and GSC status dot styles moved to assets/css/admin.css. Service Posts, Service Grid, and Divi child posts shortcode styles moved to assets/frontend.css.
* CLEANUP: Deleted 5 duplicate/unused CSS files (assets/admin.css, assets/dark.css, assets/default-styles.css, assets/utilities.css, assets/variables.css) superseded by assets/css/ equivalents.

= 7.8.76 =
* FIX: VideoObject schema now correctly generates on pages with Elementor Theme Builder templates containing videos. Root cause: _elementor_conditions is stored as a flat array of slash-delimited strings like ["include/general", "include/singular/front_page"] — not associative arrays. myls_elementor_condition_matches_post() completely rewritten to parse the string format; both string and array formats supported for forward/backward compat. Also removed erroneous json_decode() call — get_post_meta() auto-unserializes. Added include/singular/front_page and include/singular/home as explicit sub_id matches within the singular branch.

= 7.8.75 =
* FIX: Elementor HTML widget and Text Editor widget iframes now detected by VideoObject auto-detector. Catches manually embedded YouTube/Vimeo iframes in Theme Builder templates (e.g. footer template with raw iframe embed) that were previously invisible to the video widget scanner.

= 7.8.74 =
* NEW: VideoObject Auto-Detector — new inc/schema/providers/video-object-detector.php emits VideoObject JSON-LD on any singular page where videos are detected, across all major page builders: Elementor (video widget, video-playlist, background video), Elementor Theme Builder (scans applied templates via _elementor_conditions matching), Beaver Builder (video module), Divi ([et_pb_video]), WPBakery ([vc_video]), Gutenberg (wp:embed, wp:video blocks), Classic/fallback (iframe scan + bare URLs)
* NEW: YouTube duration auto-fetch from YouTube Data API v3 using myls_youtube_api_key. Cached 30 days per video ID to preserve quota. 
* NEW: Deduplication — videos appearing in both page content and Theme Builder templates are deduplicated by video ID/URL.
* Filters: myls_video_object_detector_enabled, myls_detected_video_items, myls_video_object_node

= 7.8.73 =
* FIX: LB tab media picker Select button did nothing — wp_enqueue_media() was never called in the LocalBusiness subtab render function, leaving wp.media undefined. Added wp_enqueue_media() at top of render, matching Organization subtab pattern.

= 7.8.72 =
* FIX: Awards and certifications double-escaping — two root causes: (1) Save path: wp_unslash() now applied before sanitize_text_field() on $_POST values; WP slashes all $_POST input so without unslash, apostrophes were stored with literal backslashes. (2) Read path: removed redundant array_map('sanitize_text_field') on DB values — re-sanitizing already-clean data caused a second encoding pass.

= 7.8.71 =
* NEW: Media library picker for Org Image URL field in Schema → Organization — Select Image / ✕ buttons + thumbnail preview.
* NEW: Media library picker for per-location Business Image URL in Schema → LocalBusiness — delegated event listener handles dynamically added location rows.

= 7.8.70 =
* FIX: Org Image URL field missing from Schema → Organization subtab. myls_org_image_url was referenced in the LB image fallback chain but had no input or save handler — status indicator always showed ❌. Added text input under Logo section with esc_url_raw save.

= 7.8.69 =
* FIX: Fallback LocalBusiness node (service pages not assigned to a location) was thin — only name/url/phone/address populated. Now fully enriched: image (per-location → org logo attachment → org image URL), priceRange (per-location → site-wide default), openingHoursSpecification, aggregateRating, award, hasCertification.
* FIX: areaServed now emits typed AdministrativeArea objects {"@type":"AdministrativeArea","name":"..."} instead of bare strings at both fallback assignment paths. New helper myls_wrap_areas_as_admin_area().

= 7.8.68 =
* FIX: Service schema fallback provider node was missing awards, certifications, and aggregateRating on unassigned pages. The fallback $org_provider now includes award (myls_org_awards), hasCertification (myls_org_certifications), and aggregateRating from myls_schema_build_aggregate_rating().

= 7.8.67 =
* FIX: LocalBusiness "Missing field priceRange" warning — added myls_lb_default_price_range site-wide option with fallback chain: per-location → site-wide default. New Site-wide Defaults block in LB tab.
* FIX: LocalBusiness "Missing field image" warning — extended image fallback chain to three levels: per-location image URL → org logo attachment (myls_org_logo_id) → org image URL (myls_org_image_url).
* NEW: Site-wide Defaults block in LocalBusiness tab with live fallback chain status display showing which level currently resolves.

= 7.8.66 =
* FIX: "Invalid object type for field <parent_node>" in Google Rich Results Test — removed aggregateRating from Service schema node. aggregateRating is not valid on @type: Service per Google's spec. Remains correctly on LocalBusiness where it is fully valid.

= 7.8.65 =
* FIX: Corrected ratingCount vs reviewCount semantic distinction — ratingCount = total star ratings (Google Places user_ratings_total), reviewCount = written text reviews only (new manual field).
* NEW: myls_google_places_rating_count option auto-populated on every Places API fetch. Legacy myls_google_places_review_count preserved for backward compat.
* NEW: "reviewCount (Written Reviews)" manual field in API Integration → Google Places. Leave blank to fall back to ratingCount.

= 7.8.64 =
* CHANGED: knowsAbout switched from auto-pull to deliberate opt-in selection. New knowsAbout block in LocalBusiness tab — hold Ctrl/Cmd to select services. Empty selection omits knowsAbout entirely.
* Data model: myls_lb_knows_about_include stores array of post IDs + '__subtype__' sentinel for the service schema name field.

= 7.8.63 =
* NEW: knowsAbout auto-populate on LocalBusiness and Organization schema — queries published Service CPT posts + myls_service_subtype. Static cache prevents double WP_Query. Filter myls_knows_about available.
* NEW: myls_get_knows_about() helper in inc/schema/helpers.php.

= 7.8.62 =
* FIX: areaServed not populating on Service schema — option key mismatch in fallback chain. Fixed order: myls_org_areas → ssseo_organization_areas_served → ssseo_areas_served.
* FIX: myls_parse_areas_served() sub-split fix — array items now sub-split by comma/newline before dedup, preventing comma-separated entries from being treated as single strings.

= 7.8.61 =
* Cookie Consent: banner now suppressed for logged-in administrators (manage_options) on front-end
* Cookie Consent: banner suppressed inside Elementor editor, Divi Visual Builder, WP Customizer, Block Editor iframe preview, Beaver Builder
* Added ccb_should_suppress() helper — both wp_enqueue_scripts and wp_footer hooks check before rendering

= 7.8.60 =
* Cookie Consent: Privacy Policy URL field replaced with native WordPress page picker (get_pages() dropdown)
* Cookie Consent: page ID stored via absint(); resolved to permalink at render time via get_permalink()
* Cookie Consent: backward-compatible — legacy URL strings reverse-looked-up via url_to_postid() on first save
* Cookie Consent: admin JS privacyLink() updated to treat select value "0" as no page selected
* Docs: tabs.md, index.md updated with full Cookie Consent module documentation

= 7.8.59 =
* Cookie Consent: fixed admin preview not rendering — rewrote cookie-consent-admin.js to use inline styles instead of CSS class names (frontend CSS not loaded on admin pages)
* Cookie Consent: fixed mobile layout — slim bar with small 11px centered text row above side-by-side 50% buttons (flex: 1 1 0)

= 7.8.58 =
* Added: Cookie Consent Banner module — lightweight GDPR/CCPA-aware consent banner, no third-party dependencies
* 5 themes: Dark, Light, Glassmorphism, Minimal, Branded (custom color pickers)
* Delay options: Immediately / 0.5s / 1s / 1.5s / 2s / 3s / 5s
* Accept + optional Decline button with configurable labels
* GDPR Script Blocking (opt-in): window.mylsCCBUnblock() helper; scripts tagged type="text/plain" data-ccb-consent="analytics" activated post-Accept
* Live admin preview updates mobile (320px) and desktop frames as settings change
* Cookie: myls_cookie_consent; values: accepted | declined; configurable expiry (default 180 days)
* Custom DOM events: ccb:accepted / ccb:declined
* Admin tab auto-discovered; module auto-loaded via existing myls_include_dir_excluding()

= 7.8.41 =
* Person/LinkedIn: Added 3-method tabbed import UI — Bookmarklet, URL Fetch, Paste
* New AJAX endpoint `myls_linkedin_bookmarklet_receive` — receives authenticated DOM data from the bookmarklet
* New AJAX endpoint `myls_linkedin_proxy_fetch` — server-side LinkedIn fetch via `wp_remote_get`
* New AJAX endpoint `myls_linkedin_get_bookmarklet` — generates personalized bookmarklet JS with embedded nonce and AJAX URL
* Bookmarklet reads full authenticated LinkedIn profile (skills, certs, education, awards) and POSTs to WP silently
* Admin page polls `localStorage` for incoming bookmarklet data (1.5s interval) and auto-populates the target person card
* Bookmarklet is auto-generated on page load; "Generate Bookmarklet" button refreshes nonce if needed
* Extracted `mylsPopulatePersonCard()` as shared JS helper — all 3 methods now use same populate logic
* Explained clearly why iframe approach doesn't work for LinkedIn (X-Frame-Options: DENY)
* Refactored: extracted `myls_linkedin_extraction_system_prompt()` shared function used by both proxy and bookmarklet AI pipelines



= 7.8.11 =
* Fixed: Parent Page dropdown not refreshing on page load when a saved setup is loaded — applySetupSnapshot() now calls loadParentPages() after restoring the post type, so the dropdown always reflects the correct post type on restore rather than requiring a manual change.

= 7.8.10 =
* Fixed: Parent Page dropdown always showed 'page' post type regardless of selected Post Type — replaced get_posts() (which silently ignored post_type in some WP configs due to 'fields'=>'ids') with a direct WP_Query using the explicit post_type from the AJAX request. Response now echoes back post_type for debugging. Dropdown options also get — depth indentation for hierarchical parents.
* Added: console.log('[AIntelligize] parent pages') debug line in loadParentPages() showing the post_type sent and response received.

= 7.8.9 =
* Fixed: Description field in Page Setup was escaping apostrophes and HTML entities (was using wp_kses_post; switched to sanitize_textarea_field + wp_unslash across all three save handlers).
* Fixed: Feature Cards grid showed only 3 of 4 images when AI returned fewer items than cols x rows — myls_elb_build_features() now pads and trims to exactly cols x rows, matching generated image count.
* Changed: Image box / image-box widget default width changed from 30% to 50% (icon_box, image_box, image_placeholder_box).
* Changed: "Add to Menu" checkbox is now unchecked by default in Page Setup.
* Added: Slug field (optional, auto-generated from title if blank) in Page Setup row.
* Added: Parent Page dropdown in Page Setup — populated via AJAX (myls_elb_get_parent_pages), refreshes automatically when Post Type changes.
* Added: Slug and Parent Page are included in FormData on generate and in setup snapshots (save/restore).
* Added: Standalone featured image (1792x1024) is now generated when "Set as post thumbnail" is checked but "Hero/Banner Image" is unchecked — uses same style/prompt logic as hero. Priority order for thumbnail: hero first, standalone featured second.
= 7.7.2 =
* Fixed: Aggregate Rating inputs (Star Rating + Review Count) restored to LocalBusiness location form — fields were missing from both the UI and save handler. Read by schema helpers and AI prompt tokens.
* Added: Live preview badge in location form showing star rating and review count when both fields are populated.

= 7.7.1 =
* Added: MYLS_PDF — pure-PHP PDF writer (inc/lib/myls-pdf.php) with zero dependencies, Helvetica built-in fonts, RGB colors, rectangles, auto page breaks, page numbering.
* Added: MYLS_AI_Deep_Report — professional report class with cover page, dark hero band, per-post color-coded section cards, and running footer with page numbers.
* Added: AI Deep Analysis card-based UI — replaces plain terminal with rich cards per post (meta strip, section blocks with color labels for Writing/Citation/Gaps/Rewrites).
* Added: Download PDF Report button — appears after analysis completes, POSTs results to PHP, streams binary PDF download.
* Added: Collapsible raw log panel below cards for Print Log access.
* Added: AJAX action myls_ca_deep_pdf_v1.

= 7.7.0 =
* Added: AI Deep Analysis button in Content Analyzer tab — sends selected posts to AI for writing quality critique, AI citation readiness scoring, competitor gap identification, and priority rewrite recommendations. Outputs to dedicated AI Results terminal with PDF export.
* Added: New AJAX action myls_content_analyze_ai_deep_v1 with structured prompt covering E-E-A-T, schema detection, and local SEO gap analysis.
* Added: [service_area_list] heading attribute — override or suppress the section heading.
* Added: [service_area_list] icon attribute — show/hide Font Awesome map-marker per list item.
* Added: [service_area_list] get_related_children attribute — auto-filter children by title prefix.
* Changed: Content Analyzer header badges updated to show both Instant Analysis and AI Deep Analysis.

= 7.5.30 =
* Added: `heading` attribute for `[service_area_list]` — override the section heading or suppress it entirely with heading="". Auto-defaults to "Related Service Areas" or "Other Service Areas" based on mode.
* Added: `icon` attribute for `[service_area_list]` — show/hide the Font Awesome map-marker icon per list item. Accepts true/false or 1/0. Default: true.
* Docs: Updated shortcode-data.php with all four attributes, six examples, and five tips.

= 7.5.29 =
* Added: `get_related_children` attribute for `[service_area_list]` shortcode — when true, lists service_area posts whose title starts with the current page's title (e.g. "Pressure Washing in Clearwater" listed on "Pressure Washing"). Accepts true/false or 1/0. Combines with existing show_drafts attribute.
* Changed: Section heading is now context-aware — "Related Service Areas" in get_related_children mode, "Other Service Areas" in default mode.
* Fixed: Corrected <H3> capitalisation to <h3> in shortcode HTML output.
* Docs: Updated shortcode-data.php interactive docs with new attribute, four examples, and tips.

= 7.5.28 =
* Fixed: Fatal PHP error "Unexpected token '<'" on page create — $card_width was read from $_POST in the AJAX handler but never passed into myls_elb_parse_and_build(), causing an undefined variable fatal inside that function; fixed by adding card_width to the $section_flags array passed to parse_and_build, and reading it from $section_flags['card_width'] inside that function


* Fixed: Mobile nav drawer firing open on page load — root cause was _wp_page_template and _elementor_page_settings being set by the plugin; working service pages (paver-sealing-tampa etc) have neither meta set and render correctly with Theme Builder; now explicitly deletes both metas on create/regenerate so previously broken pages are also cleaned up on next regeneration


* Fixed: Header double-render — now writes BOTH _wp_page_template = 'elementor-full-width.php' AND _elementor_page_settings['template'] = 'elementor_full_width'; Elementor writes both when you set Full Width manually in the editor; _wp_page_template is what WordPress uses to load the actual PHP template file — without it WP falls back to the theme default and fires the theme header alongside the Theme Builder header


* Fixed: Header/menu double-render — set _elementor_page_settings template to 'elementor_full_width'; this suppresses the theme's native header/footer output and lets Elementor Theme Builder's assigned header/footer conditions take sole control, which is the correct template for sites using Theme Builder on service post types


* Fixed: Removed gap property from inner flex-row containers in build_features and build_process — gap: 20px was causing card widths to exceed their row budget and break wrapping unexpectedly; spacing can be controlled via card width % and padding instead
* Investigated: Double header/menu on service pages confirmed NOT caused by plugin — paver-sealing and other pre-existing service pages have the same double nav; root cause is Elementor Theme Builder conditions overlapping on the service post type (two header templates both matching service pages); fix requires adjusting conditions in Elementor → Theme Builder → Header template


* Added: Feature Cards Width (%) number input in Generated Sections — controls _element_custom_width on icon-box, image-box, and placeholder-box widgets; defaults to 30%; clamped 10–100; persists in Page Setup Templates
* Fixed: myls_elb_icon_box_widget, myls_elb_image_box_widget, myls_elb_image_placeholder_box_widget all now accept $card_width param instead of hardcoded 30
* Wired: card_width POST param read in AJAX handler, passed through myls_elb_build_features to all three widget functions


* Fixed: Header/menu layout conflict on generated pages — removed all _elementor_page_settings writes; Elementor Theme Builder applies headers/footers automatically via WordPress template_include filter based on post type conditions and does not need this meta set; every value we tried ('default', 'elementor_theme') interfered with that native routing; now explicitly deletes the meta so Elementor's own detection runs cleanly
* Note: Any existing pages affected by v7.5.17–7.5.21 can be fixed by going to Elementor → Edit → Settings → Page Layout and saving once, or by using the Debug Inspector to verify _elementor_page_settings is cleared


* Fixed: Business Variables in Elementor Builder now pull from Schema settings (myls_org_name, myls_org_tel, myls_org_email, myls_org_locality, myls_org_region, myls_lb_locations[0]) instead of old Site Builder (myls_sb_settings); falls back gracefully if schema fields are empty
* Updated: Business Variables label now links directly to Schema settings tab
* Added: Page Setup Templates — save/load/delete the full left-panel state (post type, title, description, SEO keyword, status, menu toggle, all section toggles, image checkboxes, image style, set featured); stored in myls_elb_setup_history (max 50 entries)
* Added: AJAX handlers myls_elb_save_setup, myls_elb_list_setups, myls_elb_delete_setup
* Added: Two-click delete confirm for Page Setup Templates (matching description history pattern)


* Fixed: Double header/nav conflict on generated pages when Elementor Theme Builder is active — `template: default` caused both the regular theme header AND the Theme Builder's assigned header to render simultaneously on service post type pages
* Changed: `_elementor_page_settings` template value from `default` to `elementor_theme` — this suppresses the theme's native header/footer and lets Elementor Theme Builder's assigned header/footer templates take full control, which is the correct behavior for sites using Theme Builder


* Fixed: 8 repeated "Module not found" PHP error_log entries on every page load — modules/cpt/ was missing service-taxonomies.php, service-columns.php, service-metaboxes.php, service-templates.php, service-area-taxonomies.php, service-area-columns.php, service-area-metaboxes.php, service-area-templates.php
* Added: All 8 missing CPT module stubs created as safe no-op files matching the product/video module pattern — module loader now resolves cleanly for service and service_area CPTs


* Fixed: Critical Elementor error "sanitize_settings(): Argument #1 must be of type array, string given" when opening Edit with Elementor or Preview — caused by storing _elementor_page_settings as a wp_json_encode() string; Elementor expects a PHP array which WordPress serializes automatically via update_post_meta


* Fixed: Elementor-created pages now correctly use the theme header and footer — missing `_elementor_page_settings` meta was causing Elementor to default to full-canvas layout, overlapping the nav
* Added: `_elementor_page_settings` written on page creation with `template: default` and `page_layout: default` — equivalent to manually choosing "Default Template" in Elementor's Page Settings panel
* Updated: Debug Inspector now shows `_elementor_page_settings` value so you can verify layout on any post


* Added: "Feature Card Images" checkbox in Elementor Builder AI Images panel — generates 4 square (1024x1024) DALL-E images, one per feature card slot
* Added: `gen_feature_cards` POST param read in AJAX handler; generates images stored as type `feature_card`
* Fixed: Feature card images now correctly wire into `myls_elb_build_features()` via `$feature_images[]` indexed array — previously `$feature_images` was always empty because only `type === 'feature'` (post thumbnail) was collected, meaning image-box widgets were never actually created
* Updated: `myls_elb_parse_and_build()` — `feature_card` type images populate `$feature_images`; `$use_image_boxes` auto-set to true when any feature card images are present
* Updated: Image log summary — correctly distinguishes "1 featured → post thumbnail" from "N card(s) → image-box widgets"
* Updated: `wantsImages()` JS helper includes `gen_feature_cards` checkbox
* Updated: Progress log image count includes feature cards (4) in total


* Refactor: Replaced single "AI Content Here" placeholder with three typed placeholders — AI-Content (text-editor), AI-H2 (heading), AI-H3 (heading)
* Added: myls_elb_get_placeholder_counts() — single tree walk returning [ content, h2, h3, total ] counts for targeted fill logic
* Updated: myls_elb_count_ai_placeholders() — now wraps get_placeholder_counts()['total'] for backward compatibility
* Replaced: myls_elb_fill_ai_placeholders() with myls_elb_fill_all_placeholders() — recursive indexed fill; each slot gets unique content via per-type cursors maintained across the full element tree
* Updated: Template placeholder AI prompt — single structured JSON call requesting content_blocks[], h2_headings[], h3_headings[] sized exactly to match placeholder counts in the template
* Updated: AI-Content block structure — angle-based H3 heading with focus keyword, intro paragraph, 3-4 bullet list items, closing paragraph; ~300 words per block
* Fixed: MYLS_VERSION constant was behind plugin header version (7.5.12 vs 7.5.14) — both now aligned at 7.5.15
* Updated: max_tokens for template fill AI call bumped from 1400 to 1600 to accommodate structured multi-block responses


* Refactor: Moved myls_build_tagline_credentials() from ai-taglines.php to inc/schema/helpers.php (shared, loads early for all prompt types)
* Added: {{CREDENTIALS}} token to faqs-builder.txt prompt — AI can now reference real awards, certs, memberships verbatim in FAQ answers; fabrication guard updated (credentials from schema are now trusted data)
* Added: {credentials} token to meta-description.txt — used as the WHY differentiator when available (pulls Veteran-Owned, certifications, awards over generic fallbacks)
* Added: {credentials} token to meta-title.txt — available as a qualifier in title patterns (e.g. "Veteran-Owned", "PWNA Certified")
* Added: credentials key to myls_ai_context_for_post() in ai.php — automatically feeds all single-brace meta/excerpt prompts without per-handler changes
* Added: {{CREDENTIALS}} to ai-faqs.php str_replace token array
* Updated: prompt-loader.php docblock and myls_list_prompt_keys() — full token map documented for all prompt types, token system difference (double vs single brace) explained
* Updated: subtab-faqs.php and subtab-meta.php — live credential preview with link to Schema tab when empty
* Intentionally excluded: about-area prompt — that prompt explicitly forbids business-specific claims; credentials would conflict with its "area context only" rule


= 7.5.13 =
* Added: {{CREDENTIALS}} token to taglines prompt — auto-assembled from Organization/LocalBusiness schema (awards, certifications, memberships)
* Added: myls_build_tagline_credentials() helper — pulls myls_org_awards, myls_org_certifications, myls_org_memberships; detects Veteran-Owned from description; appends aggregate rating if stored in LocalBusiness location
* Updated: taglines.txt prompt — industry-specific examples (pressure washing/paver sealing), CREDENTIALS-aware trust signal instructions, fallback to "Licensed & Insured" when empty
* Updated: Taglines subtab — {{CREDENTIALS}} shown in variable list; live preview panel shows resolved credential string (or warning if empty with link to Schema tab)



= 7.0.2 =
* NEW: [google_reviews_slider] — Google Places API reviews displayed in a Swiper slider with glassmorphism card styling, star ratings, author attribution, and autoplay
* NEW: [social_links] — branded circular social icons auto-detected from Organization schema sameAs URLs; supports 15+ platforms with inline SVG icons, three style modes (color, mono-dark, mono-light), and whitelist/blacklist filtering
* ENHANCED: [service_grid] — added aspect_ratio attribute (e.g. "1/1", "4/3", "16/9") with CSS aspect-ratio + object-fit cover for uniform image sizing
* ENHANCED: [service_grid] — added complete inline CSS foundation (gutters, card layout, image transitions, title/tagline styling, crop mode, featured first card)
* Google reviews are cached via WP transients (default 24 hours) to minimize API calls
* Social links require no duplicate data entry — reads directly from Organization schema settings
* All three shortcodes use inline CSS with static guards — zero external stylesheet dependencies

= 7.0 =
* NEW: Search Stats — standalone dashboard for keyword performance tracking
* NEW: Google Search Console OAuth integration (connect/disconnect/test in API Integration tab)
* NEW: Focus Keyword + FAQ autocomplete expansion — 5 query types (exact, expanded, how, what, best)
* NEW: GSC Search Analytics enrichment — impressions, clicks, CTR, average position per keyword
* NEW: AI Overview detection — identifies queries appearing in Google AI Overviews
* NEW: Per-post SERP rank — weighted average position for specific post/keyword combinations
* NEW: Rank history tracking — daily snapshots with movement arrows (▲ improved / ▼ dropped)
* NEW: History panel — click to view chronological rank, impressions, clicks over time
* NEW: Custom DB tables — wp_myls_search_demand + wp_myls_search_demand_history
* NEW: Refresh All workflow — sequential Scan → AC → GSC with 1s throttle (gentle on Google)
* NEW: Post type filter pills — client-side filtering by page, post, service, etc.
* NEW: Freshness badges — FRESH (green), STALE (yellow), OLD (red), NOT RUN (gray)
* NEW: FAQ questions included in Search Stats alongside focus keywords
* NEW: 6 KPI cards — Keywords Tracked, AC Suggestions, GSC Queries, Avg Rank, AI Overview, Last Refreshed
* NEW: Expandable sub-grid rows with AC/GSC match highlighting and bonus GSC queries
* NEW: Print-friendly layout with colored backgrounds preserved
* NEW: 7 shortcodes added to interactive documentation
* IMPROVED: Shortcode documentation now covers all 35+ registered shortcodes with aliases noted
* FIX: HTML sanitization shared function prevents inconsistent cleanup across handlers

= 6.3.2.6 =
* REFACTOR: Extracted shared `myls_ai_faqs_sanitize_raw_html()` function — main generation and fill pass now use identical cleanup logic
* FIX: Added 6 new tag cleanup regexes: closing tag spaces (< / p >), tag name spaces (h 3), attribute spaces (href = "url"), missing angle brackets
* FIX: Per-FAQ validator now catches raw HTML attributes as text (href="..."), multiple consecutive tag fragments, and escaped HTML entities in answers
* IMPROVED: Fill pass prompt hardened with explicit HTML formatting rules, proper FAQ structure template with `<ul>` list, and tag integrity examples

= 6.3.2.5 =
* IMPROVED: FAQ batch AJAX error handling now shows HTTP status and actionable messages instead of generic "Bad JSON response"
* FIX: Server timeouts (504) during FAQ generation now display clear message with troubleshooting guidance

= 6.3.2.4 =
* FIX: Metabox AI buttons (HTML Excerpt, Service Tagline, FAQ Generator) now use provider-agnostic API key check — works with both OpenAI and Anthropic
* NEW: `myls_ai_has_key()` helper function for provider-agnostic key detection
* FIX: Updated "Configure OpenAI API key" messages to "Configure AI API key" across all metaboxes

= 6.3.2.3 =
* FIX: FAQ validator — global garbled_text check downgraded from hard rejection to warning; per-FAQ filter now handles individually
* FIX: FAQ sanitizer — leaked HTML tag names as text (e.g. "Answer : strong >") cleaned before wp_kses
* FIX: FAQ sanitizer — malformed closing tags with wrong brackets (</a] </a) </a}) fixed to proper HTML
* FIX: FAQ validator — new keyword_soup check rejects incoherent keyword-stuffed content (function-word ratio < 15%)
* FIX: FAQ validator — new malformed_html_tag and leaked_html_tag checks catch escaped broken tags
* IMPROVED: FAQ prompt — added WRITING QUALITY section with good/bad examples, no-nesting rule for HTML tags

= 6.3.2.2 =
* FIX: Excerpt and HTML Excerpt batch generation changed to process 1 post per AJAX call (was 5, caused server timeout)
* FIX: AJAX error handlers now show HTTP status code and response text for debugging
* FIX: Usage context $post_id scope issue in excerpt handlers

= 6.3.2.1 =
* REWRITE: PDF export replaced jsPDF with print-friendly HTML popup window
* Clean white background, perfect Unicode/emoji support, color-coded log sections
* Zero CDN dependencies — no more loading jsPDF from Cloudflare
* Toolbar with Print/Save as PDF and Copy All buttons
* Section headers render as dark banners, errors in red, success in green

= 6.3.2.0 =
* FIX: Ctrl+A in results terminals now selects only terminal text, not entire page
* All 8 results panels covered (Meta, Excerpts, HTML Excerpts, About Area, FAQs, GEO, Page Builder, Content Analyzer)
* Focus outline added so users can see when terminal is active

= 6.3.1.9 =
* IMPROVED: About the Area batch generation now shows live progress with post title and percentage
* Stop button styled red with icon for consistency across all AI handlers

= 6.3.1.8 =
* NEW: AIntelligize Stats submenu — comprehensive AI usage analytics dashboard
* NEW: 4-tab dashboard: Overview, Cost Analysis, Handlers, Activity Log
* NEW: Auto-logging of every AI call with handler, model, tokens, cost, duration
* NEW: Content coverage progress bars (titles, descriptions, excerpts)
* NEW: Cost projection, per-call cost breakdown, cumulative cost trend
* NEW: Data management with purge functionality

= 6.3.1.7 =
* FIX: Browser hung unresponsive when generating meta/excerpts for 200+ posts — now chunks 5 posts per AJAX call with live progress
* NEW: Stop button on Meta subtab to cancel mid-batch
* Excerpt and HTML Excerpt handlers also chunked for same fix

= 6.3.1.6 =
* FIX: About the Area batch log header showed hardcoded "gpt-4o" — now reads actual configured model via myls_ai_get_default_model()

= 6.3.1.5 =
* FIX: Empty model string passed to Anthropic API caused HTTP 400 on all AI generation (excerpts, HTML excerpts, taglines)
* myls_ai_chat() now strips empty model so provider functions fall back to built-in defaults

= 6.3.1.4 =
* NEW: Prompt Reset utility (Utilities → Prompt Reset) — status table + one-click reset of all 11 prompt templates
* REWRITE: about-area.txt — 6 structural patterns, anti-duplication for batch, banned openers
* REWRITE: about-area-retry.txt — aligned with main prompt, mandatory structure template
* REWRITE: faqs-builder.txt — anti-duplication rules, varied interrogatives, output enforcement
* REWRITE: geo-rewrite.txt — AI citation framing, full jump links, ol for steps, expanded Q&A
* REWRITE: page-builder.txt — 6-section spec, Bootstrap icon/color requirements, banned phrases
* REWRITE: taglines.txt — 4 structural patterns, varied pattern per tagline, banned phrases
* REWRITE: llms-txt.txt — AI citation optimization, quotable fact-statements, search intent queries
* FIX: All AJAX handlers now fall back to factory default files when DB option is empty (fixes "missing template" after reset)

= 6.3.1.3 =
* REWRITE: WP excerpt prompt — 6 structural patterns, {content_snippet} + {city_state} tokens, 16 banned phrases, anti-duplication
* REWRITE: HTML excerpt prompt — 6 structural patterns, card-context awareness, CTA rotation pool, 19 banned phrases
* Variation Engine: expanded excerpt/html_excerpt angles to 6 each, new medium-form rules, banned phrase lists
* WP excerpt handler: added content_snippet + city_state tokens, error diagnostics, output cleanup
* HTML excerpt handler: added content_snippet token, VE angle injection in bulk loop, error diagnostics
* Both handlers: added old/new values to results for log display

= 6.3.1.2 =
* NEW: "Export to CSV" button on Meta Bulk Editor — exports all rows across all pages
* CSV includes ID, Post Title, Yoast Title, Yoast Description, Focus Keyword
* Respects current post type and search filter

= 6.3.1.1 =
* NEW: "Generate Both for Selected" button — runs titles then descriptions sequentially
* Results log shows both batches with separator between them

= 6.3.1.0 =
* BUGFIX: AI meta cleanup regex was corrupting valid output (broken pattern flags caused preg_replace to return null)
* BUGFIX: Reload Default prompt button now properly updates textarea with visual confirmation flash
* NEW: Full error diagnostics in meta generation results log — shows API errors, provider info, or raw output if cleanup stripped it
* NEW: Meta Output section in results log shows old → new values with character counts
* NEW: `myls_clean_meta_output()` function — robust single-value extraction from AI responses (strips options, commentary, markdown, labels)
* Inline + newline-based truncation patterns for multi-option AI output
* Null-safety on all preg_replace calls
* API error pipeline: openai.php stores last error in global for diagnostic surfacing
* Updated meta title prompt: minimum 90 characters, forceful single-output instruction
* Updated meta description prompt: forceful single-output instruction

= 6.3.0.8 =
* NEW: Universal page builder content extraction — `myls_get_post_plain_text()` and `myls_get_post_html()`
* Supports Elementor (_elementor_data JSON), DIVI/WPBakery (shortcode tag stripping), Beaver Builder (_fl_builder_data)
* NEW: `myls_strip_shortcode_tags()` — strips shortcode brackets while preserving inner content (critical for DIVI)
* Builder detection helpers: `myls_detect_page_builder()`, `myls_post_uses_elementor()`, etc.
* 11 files updated to use centralized content extraction
* NEW: Rewritten SEO meta title/description prompt templates with 6 structural patterns each
* Variation Engine: new angle arrays for meta_title and meta_description contexts
* Expanded banned phrase lists for meta generation
* Context-aware `inject_variation()` — short-form vs long-form rules

= 6.3.0.5 =
* NEW: AI-powered llms.txt generation with city-specific content
* NEW: llms-full.txt endpoint with comprehensive service area organization
* Hierarchical structure: parent cities → child service+city combinations
* Elementor page builder content extraction for llms.txt source material
* Enterprise logging with content quality metrics and cost tracking

= 4.15.0 =
* NEW: LinkedIn Import — paste profile content (text or HTML source), AI extracts structured person data
* NEW: Person Label — display-only label for each person accordion header (not in schema output)
* NEW: Fillable PDF Export — branded fillable form with text fields, checkboxes, multi-column grids
* PDF uses pdf-lib (client-side, CDN lazy-loaded) with proper form field appearances
* LinkedIn import supports both plain text paste and advanced HTML source paste
* AI extracts: name, title, bio, education, credentials, expertise (with Wikidata/Wikipedia), memberships, awards, languages
* Added inc/ajax/ai-person-linkedin.php AJAX endpoint
* Version bumped across plugin header and constants

= 4.12.0 =
* NEW: Person Schema subtab — full multi-person support with accordion UI
* Per-person: identity, bio, social profiles, expertise (knowsAbout with Wikidata), credentials, education, memberships, awards, languages
* Per-person page assignment and enable/disable toggle
* JSON-LD output on assigned pages, worksFor linked to Organization schema
* Pro Tips sidebar with E-E-A-T best practices

= 4.6.32 =
* CRITICAL FIX: Increased default max_tokens from 1200 to 4000 - was causing FAQ generator to only produce 1-2 FAQs instead of 10-15
* Fixed: Added context-specific token handling in OpenAI integration for 'faqs_generate' context
* Improved: Added helpful UI guidance about token requirements (4000+ for LONG, 2500+ for SHORT)
* Improved: Better system prompt for FAQ generation context

= 4.6.31 =
* Fixed: AI FAQ Generator now properly replaces {{CITY_STATE}} and {{CONTACT_URL}} template variables
* Fixed: City/state detection improved with multiple fallback meta keys (_myls_city, city, _city, etc.)
* Fixed: Temperature default now consistently uses 0.5 from saved options
* Fixed: Added <ol> tag support for ordered lists in generated HTML
* Improved: Added filter hook 'myls_ai_faqs_city_state' for custom city/state detection logic

= 4.6.15 =
* AI → FAQs Builder: Upgraded default FAQ prompt to produce longer, more complete homeowner answers.
* Adds LONG/SHORT variants, AI Overview-tuned structure, and subtle “helpful next step” phrasing.
* Adds {{VARIANT}} placeholder support and a Variant selector in the builder UI.

= 4.6.7 =
* /llms.txt: Added Business details section (Organization → LocalBusiness → site defaults)
* FAQs: Added stable anchors (#faq-1, #faq-2, ...) to the MYLS FAQ accordion output
* /llms.txt: Master FAQ list now links to page + stable anchor

= 4.6.6 =
* /llms.txt: Added Primary services (service CPT) and Service areas (service_area CPT)
* /llms.txt: Added master FAQ link list from MYLS FAQ post meta (_myls_faq_items)
* Utilities: New llms.txt subtab with enable switch, section toggles, and per-section limits

= 4.6.5 =
* Added first-pass support for serving /llms.txt at the site root (Markdown, plain text) via rewrite + template redirect

= 4.6.4 =
* Divi Builder: strip <script> tags in builder context to prevent raw script text from appearing in previews

= 4.6.3 =
* Restored Divi module: FAQ Schema Accordion (modules/divi/faq-accordion.php)
* Fixed module loader timing so it registers reliably in Divi Builder

= 4.6.2 =
* Added Docs → Release Notes tab
* Added optional release-notes append helper (queues when filesystem is not writable)
* Added Upgrade Notice section

= 4.6.1 =
* Added multi-post batch save for FAQ Quick Editor
* Answers use WYSIWYG editor
* Batch DOCX export for selected posts

= 4.6.0 =
* Added FAQ Quick Editor
* Added Utilities subtabs
* AI FAQ insert targets MYLS FAQ structure

