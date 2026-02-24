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
