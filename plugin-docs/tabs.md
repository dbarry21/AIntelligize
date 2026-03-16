# Tabs and Subtabs

This section documents each tab and subtab in the AIntelligize admin area.

## Main Plugin Page (AIntelligize)

The main admin page is organized into tabs along the top. Each tab contains one or more subtabs.

---

## Dashboard
**File:** `tab-dashboard.php`

Landing page with quick links to key features, system status checks (API keys, schema status), and getting started guidance.

---

## Schema
**File:** `tab-schema.php`

Manages all structured data output for the site.

**Subtabs:**
- **Organization** — Business name, logo, address, phone, social profiles, awards, certifications.
  - **Org Image URL field** *(v7.8.70)*: Direct image URL input with Media Library picker (Select Image / ✕ / thumbnail preview). Used as tertiary fallback in LocalBusiness image chain.
  - Awards and certifications: double-escaping fixed in v7.8.72 — `wp_unslash()` applied at save, no re-sanitize on read.
  - Outputs Organization and LocalBusiness schema.

- **Person** — Multi-person E-E-A-T schema with Wikidata/Wikipedia expertise linking, LinkedIn import, PDF export. Supports multiple person profiles.

- **Service** — Service schema markup for service pages and the Service CPT.
  - **Price Ranges** repeater: assign low/high price ranges to specific posts; outputs as `offers → PriceSpecification` (minPrice/maxPrice). Option key: `myls_service_price_ranges`.
  - Provider node enrichment *(v7.8.68)*: fallback `$org_provider` (for pages not assigned to a location) now includes `award`, `hasCertification`, and `aggregateRating`.
  - `aggregateRating` removed from Service node *(v7.8.66)* — not valid per Google's spec; remains on LocalBusiness.
  - `areaServed` typed as `City` objects with `addressRegion` *(v7.9.2; was AdministrativeArea in v7.8.69)*.

- **WebPage** — WebPage schema for all singular pages *(v7.8.77)*.
  - `about` → Service `@id` on service pages *(v7.9.2)*; fallback to LocalBusiness on non-service pages.

- **LocalBusiness** — Location-specific schema.
  - **Site-wide Defaults block** *(v7.8.67)*: priceRange default (`myls_lb_default_price_range`) and live image fallback chain status showing which level resolves.
  - **Image fallback chain** *(v7.8.67)*: per-location Business Image URL → org logo attachment (`myls_org_logo_id`) → org image direct URL (`myls_org_image_url`).
  - **priceRange fallback chain** *(v7.8.67)*: per-location → site-wide default.
  - **Media library picker** for Business Image URL on each location row *(v7.8.71)*. Delegated event listener covers dynamically added rows.
  - **knowsAbout opt-in block** *(v7.8.64)*: select Service CPT posts + optional service subtype sentinel (`__subtype__`). Ctrl/Cmd multi-select. Empty = omitted. Option: `myls_lb_knows_about_include`.
  - **AggregateRating** *(v7.8.65)*: `ratingCount` = Google Places `user_ratings_total`; `reviewCount` = manual written review count (`myls_google_places_review_count_manual`). Falls back to `ratingCount` if manual value absent.
  - **Fallback node enrichment** *(v7.8.69)*: service pages not assigned to a location get a fully enriched fallback LocalBusiness node (image, priceRange, openingHoursSpecification, aggregateRating, award, hasCertification).
  - **`areaServed`** *(v7.9.0)*: pulls root-level `service_area` CPT posts as typed `City` objects with name and URL.
  - **`hasOfferCatalog`** *(v7.9.1)*: structured `OfferCatalog` listing all `service` CPT posts as `Offer` → `Service` items on LocalBusiness.

- **Service Area Service Schema** *(v7.9.1)* — Emits `Service` nodes on `service_area` pages via `service-area-service.php` provider.
  - Parent pages: one Service node per `service` CPT post, each with `areaServed` = specific city.
  - Child pages: matches child title to a service CPT post, emits single Service node with parent's city as `areaServed`.
  - Creates explicit Service → Provider (LocalBusiness) → City entity graph.
  - Toggle: `add_filter('myls_service_area_service_schema_enabled', '__return_false');`

- **FAQ** — FAQ schema settings and accordion configuration.

- **About Page** — AboutPage schema for company/about pages.

- **VideoObject Auto-Detector** *(v7.8.74)*: no UI — auto-runs on every singular page.
  - **File:** `inc/schema/providers/video-object-detector.php`
  - Detects videos across: Elementor widget (`video`, `video-playlist`, background), Elementor HTML widget / Text Editor iframes *(v7.8.75)*, Elementor Theme Builder templates (conditions matched via `_elementor_conditions`), Beaver Builder video module, Divi `[et_pb_video]`, WPBakery `[vc_video]`, Gutenberg `wp:embed` / `wp:video`, Classic editor `<iframe>` + bare URLs.
  - YouTube duration fetched from YouTube Data API v3, cached 30 days per video ID (`myls_yt_dur_{id}` transient).
  - Theme Builder condition matching *(fixed v7.8.76)*: parses Elementor's actual slash-delimited string format (`"include/general"`, `"include/singular/front_page"`) — not the associative array format that was previously expected.
  - Cross-validation fix *(v7.9.0)*: validation now includes Elementor Theme Builder template content (header/footer), so videos in site-wide templates get proper VideoObject schema.

- **ItemList** *(v7.9.0)*: no UI — auto-runs on the front page.
  - **File:** `inc/schema/providers/itemlist.php`
  - Emits `ItemList` for services (from `service` CPT, `Service` typed items) and service areas (from root `service_area` CPT, `City` typed items).
  - Helps AI systems extract structured service offerings and geographic coverage for AI Overviews.
  - Toggle via filters: `myls_itemlist_services_enabled`, `myls_itemlist_service_areas_enabled`.
  - Deduplicates by video_id/URL across all sources.
  - Skips `video` CPT (handled by existing `video-schema.php`).
  - Emits single `VideoObject` object or `@graph` array depending on count.
  - **Filters:** `myls_video_object_detector_enabled`, `myls_detected_video_items`, `myls_video_object_node`
  - **Requires:** YouTube Data API key in API Integration tab for duration metadata (optional — schema emits without it).

---

## AI
**File:** `tab-ai.php`

AI-powered content generation tools using OpenAI or Anthropic APIs.

**Subtabs:**
- **Meta Descriptions** — Generate SEO meta descriptions for posts, pages, and CPTs.
- **Excerpts** — Generate plain-text and HTML excerpts with batch processing.
- **FAQs** — Batch FAQ generation with quality validation, shared sanitization, and fill passes.
- **Taglines** — AI-generated service taglines with HTML formatting.
- **About the Area** — Location-specific "About the Area" content for service area pages.
- **Geo Content** — Bulk location-based content generation.
- **Page Builder** — AI-generated page content written directly into `post_content`. Supports all public post types, description/instructions field, DALL-E 3 image generation, and nav menu integration.



  **How it works:**
  1. Before generating, analyzes the active Elementor Kit for container width, global colors, and typography.
  2. Samples up to 3 existing posts of the same post type to detect widget patterns (icon boxes, image boxes, image slots, section backgrounds, FAQ shortcodes, hero/CTA structure).
  3. Appends a SITE CONTEXT block to the AI prompt so the output mirrors your existing pages.
  4. Appends a Business Profile block (Google rating, review count, awards, certifications) and instructs the AI to use these facts naturally in hero, feature cards, and CTA copy.
  5. AI returns structured JSON (hero, intro, features, process, cta). **FAQ section is not generated here** — use the FAQ Builder tab after page creation.
  6. PHP converts JSON into native Elementor containers with `container_type: flexbox` and child widgets.
  7. Saves via direct `_elementor_data` meta write (bypasses Elementor document API which strips unregistered settings like `container_type`).

  **GEO Writing Rules enforced in prompt:** wiki-voice declarative prose, fact density, Island Test (self-contained paragraphs), brand name repetition, question-format headings (at least 2 headings as direct search-intent questions).

  **`_myls_faq_items` is never deleted by the page builder.** Re-running the builder will not wipe FAQs created by the FAQ Builder tab.

  **Site analyzer detects:** hero sections (dark first container), CTA sections (dark container after pos 3), FAQ shortcodes, image/image-box widget usage, icon box counts, section background color sequences, button alignment.

  **Key file:** `inc/elementor-site-analyzer.php` — `myls_elb_analyze_site( $post_type )`

---

## Search Demand
**File:** `tab-search-demand.php`

Keyword research and autocomplete expansion tools.

**Subtabs:**
- **FAQ Search Demand** — Manual single-term Google Autocomplete checking with site-wide FAQ audit.
- **Focus Keyword AC Options** — Three-step workflow: Load Focus Keywords → Get AC Suggestions (5 query types) → GSC Enrich with metrics.

*Note: Search Stats dashboard (see below) provides a persistent, enriched version of these tools with database storage and history tracking.*

---

## Meta
**File:** `tab-meta.php`

View and manage post meta fields, Yoast/Rank Math data, and custom field values across posts and pages.

---

## Bulk
**File:** `tab-bulk.php`

Bulk operations for managing content across many posts at once.

**Subtabs:**
- **Bulk Meta** — Batch meta title/description generation.
- **Bulk FAQ** — Batch FAQ generation across post types.
- **Bulk Maps** — Generate Google Maps embeds for multiple locations.
- **Bulk Taglines** — Batch tagline generation for services.

---

## CPT (Custom Post Types)
**File:** `tab-cpt.php`

Manage Services and Service Areas custom post types. Configure slugs, archive pages, and taxonomy settings.

---

## Shortcodes
**File:** `tab-shortcodes.php`

Enable/disable individual shortcodes and configure global shortcode settings. See the Documentation → Shortcodes (Interactive) tab for the full reference.

---

## Utilities
**File:** `tab-utilities.php`

Miscellaneous tools including cache clearing, debug info, import/export settings, and database maintenance.

Subtabs are auto-discovered from `admin/tabs/utilities/subtab-*.php`.

**Subtabs:**
- **Custom CSS** — Live CSS editor with real-time preview. Saves to `wp_options` and enqueues on the frontend.
- **Elementor Builder** — AI-generated pages built as native Elementor widget trees (Heading, Text Editor, Button, Icon Box, Shortcode). Writes directly to `_elementor_data` post meta. *(Moved from AI tab in v7.8.19.)*

  **How it works:**
  1. Analyzes the active Elementor Kit for container width, global colors, and typography.
  2. Samples existing posts of the same type to detect widget patterns.
  3. Appends a SITE CONTEXT block to the AI prompt so the output mirrors existing pages.

  **Elementor Builder prompt** (v5, 2026-03-15): Now reads TARGET_CITY from Description field,
  references auto-appended Business Profile block for all business facts, supports SHOW_PRICING
  flag, enforces 600–800 word distribution across sections. Template filler updated with full
  GEO wiki-voice rules — eliminates first-person marketing language from AI-Content slots.
- **Empty Anchor Fix** — Automatically adds `aria-label` attributes to links with no visible anchor text. Resolves SEMRush/Ahrefs audit warnings.
- **FAQ Editor** — Edit MYLS FAQ items per-post with WYSIWYG editor and batch `.docx` export.
- **FAQ Migration** — Migrate FAQ data from ACF repeater fields to the native MYLS `_myls_faq_items` format.
- **GBP Photos** — Browse and import Google Business Profile photos into the WordPress Media Library.
  - Connects via OAuth 2.0 (`business.manage` scope) — same pattern as the GSC OAuth module.
  - Cascading account + location dropdowns; supports multiple accounts and multiple locations (agency-friendly).
  - Photo grid with thumbnails, category, date, and Load More pagination (100 photos/page).
  - Click-to-select photos, Select All / Deselect All, then import to Media Library in bulk.
  - Duplicate prevention via `_gbp_media_name` post meta — already-imported photos show a green badge.
  - Live import progress bar and per-photo log.
  - **Quota note:** The My Business Account Management API has very low default quotas. Accounts load
    on-demand (button-triggered, not on page load) and results are cached for 30 minutes. A ↺ Refresh Cache
    button is available. If quota errors occur, a GCP Quotas link is shown with fix instructions.
- **llms.txt** — Controls the `/llms.txt` and `/llms-full.txt` AI discovery endpoints.
- **Paste a Post** — Paste content from Google Docs or Word into a WYSIWYG editor to create a blog post.
  - Strips `<span>`, `<font>`, and inline style/class attributes; keeps semantic HTML (h1–h3, p, ul, ol, li, a, strong, em, blockquote).
  - AI generates title (if blank) and excerpt. Creates a standard WP post — compatible with Elementor, Divi, and Classic editor.
  - Sets Title, Excerpt, and Content. Post status selectable (Draft / Published / Pending Review).
  - External links automatically get `target="_blank" rel="noopener noreferrer"`. Internal links are preserved.
  - Optional DALL-E 3 image generation: Featured Image (1792×1024) + inline image inserted after the 2nd paragraph (1024×1024).
  - AJAX action: `myls_paste_post_create`. Nonce: `myls_paste_post`.
- **Prompt Reset** — Reset any or all AI prompt templates back to factory defaults.

**Credentials needed for GBP Photos:**
- Google Cloud Console OAuth 2.0 Client ID + Client Secret
- My Business Account Management API enabled
- My Business Business Information API enabled
- OAuth scope: `https://www.googleapis.com/auth/business.manage`
- Redirect URI: `https://YOURSITE.com/wp-admin/admin-post.php?action=myls_gbp_oauth_cb`

---

## API Integration
**File:** `api-integration.php`

Configure external API connections.

**Sections:**
- **OpenAI / Anthropic** — API key, model selection, temperature, max tokens.
- **Google Maps** — Maps API key for embed shortcodes and service area grids.
- **Google Places** — Places API key for open/closed status and review data.
  - **Star Rating** — auto-fetched from Google Places on every cron/manual fetch; stored as `myls_google_places_rating`.
  - **ratingCount** *(v7.8.65)* — total star ratings (Google Places `user_ratings_total`); stored as `myls_google_places_rating_count`.
  - **reviewCount (Written Reviews)** *(v7.8.65)* — manual field (`myls_google_places_review_count_manual`). Enter the written review count from your GBP dashboard. Leave blank to fall back to `ratingCount`.
- **Google Search Console** — OAuth 2.0 connect/disconnect/test. Site property selection with auto-detection.
- **YouTube Data API** — API key for video blog, transcript features, and VideoObject duration auto-fetch (v7.8.74).

---

## Site Builder
**File:** `site-builder.php`

Tools for building out service area page structures, generating child pages, and managing page hierarchies.

---

## YouTube Video Blog
**File:** `yt-video-blog.php`

Automated video blog post creation from YouTube channel content. Pulls videos via YouTube Data API, generates draft posts with embeds, descriptions, and optional transcripts.

**Settings:** Post type, category, slug prefix, auto-embed, title template, content template, post status.

---

## Migration
**File:** `tab-migration.php`

Tools for migrating data from other SEO plugins or importing/exporting plugin settings between sites.

---

## Cookie Consent
**File:** `admin/tabs/tab-cookie-consent.php`
**Module:** `modules/cookie-consent/cookie-consent.php`
**Order:** 90

Lightweight, self-contained GDPR/CCPA cookie consent banner. No third-party
plugin dependencies. Auto-loaded via `myls_include_dir_excluding()` and
auto-discovered by the tab loader.

**Subtabs:**

### Settings & Preview
All configuration options with a live dual-frame preview (320px mobile + desktop)
that updates in real time as you change settings.

| Setting | Description | Default |
|---|---|---|
| Enable Banner | Toggle the banner on/off sitewide | On |
| Banner Message | Consent message text (supports `<a>`, `<strong>`, `<em>`) | "We use cookies…" |
| Accept Button Label | Label for the accept button | Accept |
| Decline Button Label | Label for the decline button | Decline |
| Show Decline Button | Toggle the decline button | On |
| Privacy Policy Page | Native WordPress page picker — dropdown of all published pages | None |
| Privacy Link Label | Link text for the privacy policy link | Privacy Policy |
| Position | `bottom` or `top` | bottom |
| Delay | Time before banner slides in: Immediately / 0.5s / 1s / 1.5s / 2s / 3s / 5s | 1.5s |
| Consent Cookie Expiry | Days before consent cookie expires | 180 |
| Theme | `dark` / `light` / `glass` / `minimal` / `branded` | dark |
| Branded Colors | Background, button, and text color pickers (visible when Theme = Branded) | — |
| Script Blocking | GDPR-level script blocking — holds `type="text/plain"` scripts until Accept | Off |

**Themes:**
- `dark` — Deep navy background, blue CTA button
- `light` — White background, subtle shadow
- `glass` — Frosted glass via `backdrop-filter: blur()`
- `minimal` — Off-white, black border, underline-style decline
- `branded` — Fully custom colors via admin color pickers

**Mobile layout (≤600px):** Slim bar — small `11px` centered text row above two
side-by-side buttons each taking exactly 50% width (`flex: 1 1 0`). Compact
padding keeps the banner unobtrusive on small screens.

### Script Blocking
Documentation tab with copy-ready code snippets for GDPR-level script blocking:

- **Method 1 — Inline scripts:** Change `type="text/javascript"` → `type="text/plain"` + `data-ccb-consent="analytics"`. Plugin activates after Accept.
- **Method 2 — WordPress `wp_head` hook:** Dequeue auto-loaded script, re-output as `text/plain`.
- **Method 3 — Manual JS trigger:** Call `window.mylsCCBUnblock('analytics')` or `window.mylsCCBUnblock('*')` directly.

### Usage & Docs
- Theme reference table
- JavaScript events: `ccb:accepted` / `ccb:declined` (custom DOM events, bubbles)
- Reset consent snippet for testing (clears `myls_cookie_consent` cookie via console)
- GDPR compliance notes

**Storage:**
- Cookie name: `myls_cookie_consent`
- Values: `accepted` | `declined`
- Expiry: configurable (default 180 days)
- Option key: `aintelligize_ccb_settings` (single serialized array)

**JS API:**
```js
// Unblock all consent-gated scripts after custom accept logic
window.mylsCCBUnblock('*');
window.mylsCCBUnblock('analytics'); // category-specific

// Listen for consent events
document.addEventListener('ccb:accepted', () => { /* activate tracking */ });
document.addEventListener('ccb:declined', () => { /* suppress tracking */ });
```

**WP Consent API:** Not required. The module is fully self-contained.

**Admin & Editor Suppression:** The banner is automatically hidden when:
- The current user has `manage_options` (admins) — suppressed on all front-end pages
- Elementor editor or preview (`?elementor-preview`, `?elementor_library`)
- WordPress Block Editor iframe preview (`?iframe=1&preview=1`)
- WordPress Customizer (`is_customize_preview()`)
- Divi Visual Builder (`?et_fb`)
- Beaver Builder / generic builder preview (`?fl_builder`, `?preview_id`)

---

---

## Standalone Submenu Pages

These pages appear as separate items under the AIntelligize menu, not as tabs on the main page.

### AIntelligize Stats
**File:** `admin/admin-stats-menu.php`

AI usage analytics dashboard with Chart.js visualizations. Tracks every AI API call with cost, token usage, handler breakdown, and activity log. KPI cards show total calls, tokens, cost, and success rate.

### Search Stats
**File:** `admin/admin-search-stats-menu.php`

Keyword performance tracking dashboard. Combines focus keywords (from Yoast/Rank Math/AIOSEO) and FAQ questions into a single tracked keyword list.

**Features:**
- Google Autocomplete expansion (5 query types per keyword)
- GSC Search Analytics (impressions, clicks, CTR, avg position)
- AI Overview detection
- Per-post SERP rank (weighted average position filtered by page URL)
- Rank history with daily snapshots and movement arrows
- Post type filter pills, freshness badges, print layout
- 6 KPI cards: Keywords, AC Suggestions, GSC Queries, Avg Rank, AI Overview, Last Refreshed

### Documentation
**File:** `admin/docs/documentation.php`

Plugin documentation hub with tabs: Quick Start, Tabs & Subtabs, Shortcodes (Interactive), Shortcodes (Auto), Tutorials, Release Notes.
