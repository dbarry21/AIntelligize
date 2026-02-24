=== AIntelligize ===
Contributors: davebarry
Tags: local seo, schema, ai, faq, utilities, person schema, linkedin
Requires at least: 6.0
Tested up to: 6.7.2
Stable tag: 7.0.2
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
* 35+ shortcodes for location data, service grids, schema, social links, Google reviews, YouTube, and utilities
* Enterprise logging with quality control and batch processing

== Installation ==

1. Upload the `my-local-seo` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.

== Upgrade Notice ==

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

