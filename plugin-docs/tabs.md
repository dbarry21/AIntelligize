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
- **Organization** — Business name, logo, address, phone, social profiles, awards, certifications. Outputs Organization and LocalBusiness schema.
- **Person** — Multi-person E-E-A-T schema with Wikidata/Wikipedia expertise linking, LinkedIn import, PDF export. Supports multiple person profiles.
- **Service** — Service schema markup for service pages and the Service CPT.
- **FAQ** — FAQ schema settings and accordion configuration.
- **About Page** — AboutPage schema for company/about pages.

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
- **Elementor Builder** — AI-generated pages built as native Elementor widget trees (Heading, Text Editor, Button, Icon Box, Shortcode). Writes directly to `_elementor_data` post meta.

  **How it works:**
  1. Before generating, analyzes the active Elementor Kit for container width, global colors, and typography.
  2. Samples up to 3 existing posts of the same post type to detect widget patterns (icon boxes, image boxes, image slots, section backgrounds, FAQ shortcodes, hero/CTA structure).
  3. Appends a SITE CONTEXT block to the AI prompt so the output mirrors your existing pages.
  4. AI returns structured JSON (hero, intro, features, process, faq, cta).
  5. PHP converts JSON into native Elementor containers with `container_type: flexbox` and child widgets.
  6. Saves via direct `_elementor_data` meta write (bypasses Elementor document API which strips unregistered settings like `container_type`).
  7. FAQ items extracted from JSON and saved to `_myls_faq_items` for use by `[faq_schema_accordion]`.

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
- **Google Search Console** — OAuth 2.0 connect/disconnect/test. Site property selection with auto-detection.
- **YouTube Data API** — API key for video blog and transcript features.

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
