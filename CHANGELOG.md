## [7.8.23] — 2026-03-04

### Fixed
- **Paste a Post — Featured Image not generating.** Root cause: API key was fetched via
  bare `get_option('myls_openai_api_key')` which misses the two fallback option keys
  (`ssseo_openai_api_key`, `openai_api_key`) and the `myls_openai_api_key` filter.
  Fixed to use `myls_openai_get_api_key()` (with a `get_option` fallback if the function
  is unavailable), matching the pattern used by `ai-image-gen.php`.

### Added
- **Paste a Post — Copy Permalink button.** The 🔗 icon in the permalink preview bar is
  now a 📋 clipboard button. Clicking it copies the full URL (base + slug + trailing slash)
  to the clipboard via `navigator.clipboard.writeText()` with a legacy `execCommand` fallback.
  A "✔ Copied!" confirmation fades after 2 seconds.

**Files changed:** `admin/tabs/utilities/subtab-paste-post.php`,
`aintelligize.php`, `readme.txt`, `CHANGELOG.md`

## [7.8.22] — 2026-03-04

### Fixed
- **Paste a Post** — Schedule panel (yellow box) was never appearing when "⏰ Scheduled" was selected.
  Root cause: `schedPanel.style.display = ''` (empty string) removes the inline style but leaves
  the CSS `display:none` rule still active. Fixed by setting to `'block'` explicitly.

**Files changed:** `admin/tabs/utilities/subtab-paste-post.php`, `aintelligize.php`, `readme.txt`, `CHANGELOG.md`

## [7.8.21] — 2026-03-04

### Changed
- **Paste a Post** — Schedule panel: removed yellow background, now renders as a plain section with a top border.

**Files changed:** `admin/tabs/utilities/subtab-paste-post.php`, `aintelligize.php`, `readme.txt`, `CHANGELOG.md`

## [7.8.20] — 2026-03-04

### Changed
- **Paste a Post — Scheduled Publish + Permalink Preview**
  - **Permalink preview bar** now appears below the Title / Post Type row. Updates live
    as you type the title, converting it to a slug using the same lowercase/hyphenate
    logic WordPress applies (mirrors `sanitize_title()`).
  - **Status dropdown** gains a ⏰ Scheduled option. Selecting it reveals a
    `datetime-local` picker pre-filled to tomorrow at 09:00 (site local time).
  - Client-side validation rejects a scheduled time that is in the past.
  - Server-side: `schedule_date` (ISO `YYYY-MM-DDTHH:MM`) is parsed in the site's
    WordPress timezone (`wp_timezone()`), `post_status` is set to `future`,
    `post_date` (local) and `post_date_gmt` (UTC) are computed and passed to
    `wp_insert_post()`. Falls back to `draft` if the date can't be parsed.
  - Log line now reads `scheduled for 2026-03-10 09:00:00 (site time)` when applicable.

**Files changed:** `admin/tabs/utilities/subtab-paste-post.php`,
`aintelligize.php`, `readme.txt`, `CHANGELOG.md`

## [7.8.19] — 2026-03-04

### Added
- **Utilities → Paste a Post** — New subtab that accepts pasted content from Google Docs or
  Word documents via a WYSIWYG editor (wp_editor/TinyMCE).
  - Strips `<span>`, `<font>`, inline `style=`/`class=` attributes server-side; preserves
    `h1`–`h3`, `p`, `ul`, `ol`, `li`, `a`, `strong`, `em`, `blockquote`, `br`.
  - Preserves all external and internal links; adds `target="_blank" rel="noopener noreferrer"`
    to external links automatically.
  - AI generates a title (if blank) and excerpt via the plugin's `myls_ai_generate_text()` pipeline.
  - Creates a standard WordPress post (compatible with Elementor, Divi, Classic editor) — sets
    Title, Excerpt, and Content. Post status is selectable (Draft / Published / Pending).
  - DALL-E 3 generates a **Featured Image** (1792×1024, natural style) and an
    **inline image** (1024×1024) inserted after the 2nd paragraph using `$wpdb->update()`
    + `clean_post_cache()` to avoid hook cascade.
  - Helper functions: `myls_paste_clean_html()`, `myls_paste_fix_external_links()`,
    `myls_paste_insert_after_paragraph()`.
  - AJAX action: `myls_paste_post_create` (nonce: `myls_paste_post`).

### Changed
- **Elementor Builder moved from AI tab → Utilities tab.**
  File relocated from `admin/tabs/ai/subtab-elementor.php` to
  `admin/tabs/utilities/subtab-elementor.php`. No functional changes — all AJAX handlers
  remain in `inc/ajax/ai-elementor-builder.php` and `inc/ajax/ai-image-gen.php`.

**Files changed:** `admin/tabs/utilities/subtab-elementor.php` (moved),
`admin/tabs/utilities/subtab-paste-post.php` (new),
`admin/tabs/ai/subtab-elementor.php` (deleted),
`aintelligize.php`, `readme.txt`, `CHANGELOG.md`, `plugin-docs/tabs.md`

## [7.8.18] — 2026-03-04

### Fixed
- **Empty excerpts on 2 of 4 posts** — `max_tokens` default raised from 90 to 120.
  90 tokens was cutting off generation before a clean response could land.

### Changed
- **`_myls_city_state` now inherited from parent page** alongside `city_state` and
  `county`. All three location fields are copied to the new post at generation time
  when a parent page is set and the parent has them populated.

**Files changed:** `inc/ajax/ai-excerpts.php`, `inc/ajax/ai-elementor-builder.php`,
`aintelligize.php`, `readme.txt`, `CHANGELOG.md`

## [7.8.17] — 2026-03-04

### Changed
- **Excerpt word count halved.** Plain excerpt target reduced from 20–40 words
  to 10–20 words (1 sentence). HTML excerpt target reduced from 40–80 words to
  20–40 words (1–2 sentences). Default `max_tokens` reduced from 180 to 90 to
  match. Existing saved prompt overrides in the DB are unaffected — only the
  default prompt template changes.

**Files changed:** `assets/prompts/excerpt.txt`, `assets/prompts/html-excerpt.txt`,
`inc/ajax/ai-excerpts.php`, `aintelligize.php`, `readme.txt`, `CHANGELOG.md`

## [7.8.16] — 2026-03-04

### Changed
- **Removed `aggregateRating` from Organization schema.** Rating belongs on
  `LocalBusiness` and `Service` only. Organization is the site-wide entity
  identifier (homepage only) — duplicating the rating there creates redundant
  entity signals. `LocalBusiness` and `Service` schemas are unchanged.

**Files changed:** `inc/schema/providers/organization.php`, `aintelligize.php`,
`readme.txt`, `CHANGELOG.md`

## [7.8.15] — 2026-03-04

### Added
- **Inherit `city_state` and `county` from parent page.** When a parent page is
  set in the Elementor Builder UI and that parent has `city_state` and/or `county`
  ACF fields populated, the new post inherits those values automatically at
  generation time. Uses `get_field()` when ACF is active, falls back to
  `get_post_meta()`. Only copies if the parent value is non-empty.

**Files changed:** `inc/ajax/ai-elementor-builder.php`, `aintelligize.php`,
`readme.txt`, `CHANGELOG.md`

## [7.8.14] — 2026-03-04

### Fixed
- **Template page settings bleeding onto host page and breaking menu/header.**
  Elementor Library templates imported via Astra Starter Templates carry
  `_elementor_page_settings` with `astra_sites_*` font/color overrides. When
  our plugin writes `_elementor_data` to the host page, Elementor's
  `updated_post_meta` hook reads the source template's `_elementor_page_settings`
  and copies them onto the host page — corrupting the header container sizing and
  breaking the menu.
- **Fix:** Before reading `_elementor_data` from any Library template, the plugin
  now checks for and permanently deletes `_elementor_page_settings` from the
  template post. This cleans up stale Astra imports on first use and prevents
  the bleed for all future generations. Logged to the generation output so you
  can see which templates were cleaned.

**Files changed:** `inc/ajax/ai-elementor-builder.php`, `aintelligize.php`,
`readme.txt`, `CHANGELOG.md`

## [7.8.13] — 2026-03-04

### Fixed
- **Menu/header breaks when a Page Setup template is included as a page section.**
  Root cause: two separate paths write `_elementor_page_settings`. PATH A (inside
  `wp_insert_post`/`wp_update_post`) was already handled by the `save_post` cleanup
  hook. PATH B — Elementor's `updated_post_meta` hook firing when `_elementor_data`
  is written with Library template content — wrote `_elementor_page_settings` outside
  of `save_post` entirely, bypassing the cleanup.
- **Fix:** Added `$elb_meta_settings_guard` on `updated_post_meta` + `added_post_meta`
  at priority 999. Any write to `_elementor_page_settings` or `_wp_page_template` on
  the generated post is deleted the instant it lands, regardless of trigger path.

**Files changed:** `inc/ajax/ai-elementor-builder.php`, `aintelligize.php`,
`readme.txt`, `CHANGELOG.md`

## 7.7.4 — 2026-03-02

### Fixed
- **`[service_area_list]` — `heading` attribute broken** — `shortcode_atts()` converts
  `null` defaults to `''` (empty string), so `is_null($heading_override)` always returned
  `false`. The heading was treated as "suppressed" whenever the user omitted the attribute,
  meaning the auto-detect fallback ("Related Service Areas" / "Other Service Areas") never
  fired. Fixed by replacing the `null` sentinel with `'__auto__'` and checking
  `=== '__auto__'` instead of `is_null()`.
- Added `function_exists()` guard around `service_area_list_shortcode` to prevent fatal
  errors if another plugin registers the same function name.

**Shortcode now works correctly:**
- `[service_area_list]` — auto heading, icon shown
- `[service_area_list get_related_children="true" heading="Check close to you!" icon="0"]`
  — filtered children, custom heading, no icon

**Files changed:** `modules/shortcodes/service-area-lists.php`,
`aintelligize.php`, `readme.txt`, `CHANGELOG.md`

---

## 7.7.3 — 2026-03-02

### Fixed
- **Google Places Aggregate Rating sync restored** — ported from v7.6.5. The "Google Rating
  & Review Count" section is back inside the Google Places (Business Profile) card on the
  API Integration tab. Reverted the incorrect manual input fields added in v7.7.2.

### How it works
- **Fetch Now button** — calls `wp_ajax_myls_fetch_places_rating` which hits the Places API
  (`fields=name,rating,user_ratings_total`) and saves results to `myls_google_places_rating`,
  `myls_google_places_review_count`, and `myls_places_rating_fetched_at` options.
- **Auto-refresh** — WP-Cron fires `myls_refresh_places_rating` every 4 hours
  (`myls_every_4_hours` interval). A self-healing `init` hook reschedules if missed.
- **Schema consumers** — `inc/schema/providers/localbusiness.php`, `organization.php`,
  and `build-service-schema.php` all read these options for `aggregateRating` output.
- **Display** — shows current rating + review count, fetch timestamp, and time until next
  auto-refresh directly in the card.

**Files changed:** `admin/tabs/api-integration.php`, `admin/api-integration-tests.php`,
`aintelligize.php`, `admin/tabs/schema/subtab-localbusiness.php` (bad fields removed),
`readme.txt`, `CHANGELOG.md`

---

# AIntelligize — Changelog

## 7.7.2 — 2026-03-01

### Fixed
- **LocalBusiness Schema — Aggregate Rating inputs restored** — `rating` (star rating,
  1.0–5.0) and `review_count` (total Google reviews) fields were missing from both the
  location form UI and the save handler in `admin/tabs/schema/subtab-localbusiness.php`.
  These fields are read by `inc/schema/helpers.php` (for schema markup output) and
  `inc/prompt-loader.php` (for AI prompt tokens like "890+ 4.9-Star Google Reviews").

### Added
- Live preview badge in the location form — when both `rating` and `review_count` are
  populated, a yellow ★ badge renders inline showing e.g. `★ 4.9 · 890+ reviews`.

### Changed
- Save handler now validates `rating` with `is_numeric()` and rounds to 1 decimal place;
  `review_count` is cast via `absint()`.

**Files changed:** `admin/tabs/schema/subtab-localbusiness.php`,
`aintelligize.php`, `readme.txt`, `CHANGELOG.md`

---

### Added
- **`MYLS_PDF` — Pure-PHP PDF Writer** (`inc/lib/myls-pdf.php`) — Self-contained PDF
  generation class with zero dependencies. Produces valid PDF 1.4 binary output using
  PHP built-ins and built-in PDF fonts (Helvetica, Helvetica-Bold, Courier — no embedding
  required). Supports: RGB fill/text/draw colors, filled rectangles, horizontal lines,
  auto-wrapping MultiCell, automatic page breaks, page numbering via `{NB}` alias.

- **`MYLS_AI_Deep_Report`** (`inc/pdf/ai-deep-report.php`) — Professional report layout
  class built on `MYLS_PDF`. Generates a multi-page PDF with:
  - **Cover page** — dark hero band, purple accent stripe, site name, timestamp, analyzed
    pages table with word count/keyword chips, "What's Inside" callout box
  - **Per-post analysis pages** — dark header bar with post title + page counter chip,
    metadata strip (words, keyword, location, schema status), color-coded section blocks
    (green = Writing, purple = AI Citation, amber = Competitor Gaps, red = Rewrites)
  - **Running footer** — page numbers (`Page N of M`), separator line, report label

- **AI Deep Analysis — card-based terminal UI** — Replaced the plain `<pre>` terminal
  dump with a rich card renderer. Each analyzed post gets a structured card showing:
  - Dark header with post number chip, title, and URL
  - Metadata strip: word count, focus keyword, location, schema detection status
  - Color-coded section blocks per AI analysis dimension, with label pill and body text

- **AI Deep Analysis — Download PDF Report button** — Purple button appears after analysis
  completes. POSTs the collected JS-side results to `wp_ajax_myls_ca_deep_pdf_v1` using
  a hidden form submit (ensures the browser intercepts the binary response as a file
  download). Filename: `ai-deep-analysis-YYYYMMDD-HHmmss.pdf`.

- **AI Deep Analysis — Raw Log panel** — Collapsible `Show Raw Log` button below the
  cards gives access to the terminal-formatted log for the existing Print Log button.

- **New AJAX action** `wp_ajax_myls_ca_deep_pdf_v1` — Accepts JSON results array,
  sanitizes fields, instantiates `MYLS_AI_Deep_Report`, streams binary PDF with correct
  `Content-Disposition: attachment` headers.

**Files added:** `inc/lib/myls-pdf.php`, `inc/pdf/ai-deep-report.php`
**Files changed:** `admin/tabs/ai/subtab-content-analyzer.php`,
`inc/ajax/ai-content-analyzer.php`, `assets/js/myls-ai-content-analyzer.js`,
`aintelligize.php`, `readme.txt`, `CHANGELOG.md`

---

### Added
- **Content Analyzer — AI Deep Analysis button** — Purple `⭐ AI Deep Analysis` button added
  to the Content Analyzer tab alongside the existing instant Analyze/Stop buttons. Sends
  selected posts through a full AI-powered analysis pipeline covering four dimensions:

  1. **Writing Quality & Tone** — clarity, engagement, brand voice, sentence variety, passive
     voice, jargon, and emotional resonance. Flags specific weak phrases from the content.
  2. **AI Citation Readiness** — likelihood of the page being cited by AI assistants
     (ChatGPT, Perplexity, Gemini). Evaluates schema presence, E-E-A-T signals, FAQ coverage,
     factual specificity, and structured data. Returns a High/Medium/Low score with rationale.
  3. **Competitor Gap Opportunities** — 3–5 content angles or trust signals competitors
     typically exploit that the page is missing (local proof points, process transparency,
     pain-point targeting, comparison content).
  4. **Priority Rewrite Recommendations** — 3–5 high-ROI specific changes with enough
     detail to act on immediately: what to change, why it matters, what the improved version
     should accomplish.

- **Dedicated AI Results terminal** — AI Deep Analysis outputs to its own
  `#myls_ca_deep_results` terminal below the instant analysis terminal. Includes its own
  PDF export button. The two terminals are visually distinct and operate independently.

- **New AJAX action** — `wp_ajax_myls_content_analyze_ai_deep_v1` in
  `inc/ajax/ai-content-analyzer.php`. Gathers page content, metadata (Yoast title/desc,
  focus keyword, city/state, tagline, FAQs, About the Area, schema detection) and sends
  a structured prompt to `myls_ai_chat()`. Caps content at 3,000 chars to manage tokens.

- **Header badges updated** — Card header now shows both `Instant Analysis` (blue) and
  `AI Deep Analysis` (purple) badges to reflect both capabilities.

### Fixed
- Restored AI Deep Analysis functionality that was missing from recent builds (7.5.26–7.5.30)
  due to the feature being dropped between zip snapshots.

### Changed (from previous two releases)
- **7.5.29** — `[service_area_list]` `get_related_children` attribute
- **7.5.30** — `[service_area_list]` `heading` and `icon` attributes
  *(both included in this build)*

**Files changed:** `admin/tabs/ai/subtab-content-analyzer.php`,
`inc/ajax/ai-content-analyzer.php`, `assets/js/myls-ai-content-analyzer.js`,
`admin/docs/shortcode-data.php`, `modules/shortcodes/service-area-lists.php`,
`aintelligize.php`, `readme.txt`, `CHANGELOG.md`

---

### Added
- **`[service_area_list]` — `heading` attribute** — Override the section heading rendered
  above the list. When omitted, the shortcode auto-selects `"Related Service Areas"` (in
  `get_related_children` mode) or `"Other Service Areas"` (default mode). Set `heading=""`
  (empty string) to **suppress the `<h3>` entirely**.

  ```
  [service_area_list heading="Also Available In"]
  [service_area_list get_related_children="true" heading="We Also Serve"]
  [service_area_list heading=""]
  ```

- **`[service_area_list]` — `icon` attribute** — Show or hide the Font Awesome
  `fa-map-marker` icon before each list item. Accepts `true/false` or `1/0`.
  Default: `true` (preserves existing behaviour).

  ```
  [service_area_list icon="0"]
  [service_area_list get_related_children="true" icon="false"]
  ```

- **Shortcode docs updated** — `shortcode-data.php` now documents all four attributes,
  six examples, and five tips for `service_area_list`.

### Changed
- Icon HTML extracted into `$icon_html` variable — removes duplicate markup across draft
  and published render paths.

**Files changed:** `modules/shortcodes/service-area-lists.php`, `admin/docs/shortcode-data.php`,
`aintelligize.php`, `readme.txt`, `CHANGELOG.md`

---

## 7.5.29 — 2026-03-01

### Added
- **`[service_area_list]` — `get_related_children` attribute** — New boolean attribute
  (`true`/`false` or `1`/`0`, default `false`) that switches the shortcode from its default
  "all parent service areas" mode into a **related-children** mode.

  When enabled, the shortcode reads the current page's post title and queries for any
  `service_area` post whose title **starts with that title** (case-insensitive prefix match).
  This lets you place a single shortcode on a parent service page and have it automatically
  discover and list all matching location variants as you publish them — no taxonomy, no
  parent/child hierarchy required.

  **Example:** on a "Pressure Washing" page the shortcode will surface:
  - "Pressure Washing in Clearwater"
  - "Pressure Washing in Tampa"
  - "Pressure Washing in St. Pete"

  Combines with the existing `show_drafts` attribute so you can preview upcoming children
  before they go live.

  ```
  [service_area_list get_related_children="true"]
  [service_area_list get_related_children="1"]
  [service_area_list get_related_children="true" show_drafts="true"]
  ```

- **Shortcode interactive docs updated** — `shortcode-data.php` entry for `service_area_list`
  now documents both attributes (`show_drafts`, `get_related_children`), four usage examples,
  and four tips covering prefix-match behaviour, CSS hooks, and self-exclusion logic.

### Changed
- Section heading is context-aware: **"Related Service Areas"** in `get_related_children`
  mode vs **"Other Service Areas"** in default mode (existing behaviour preserved).
- Corrected `<H3>` → `<h3>` capitalisation in HTML output.
- All no-results paths unified — both modes return `<p>No service areas found.</p>`.

**Files changed:** `modules/shortcodes/service-area-lists.php`, `admin/docs/shortcode-data.php`,
`aintelligize.php`, `readme.txt`, `CHANGELOG.md`

---

## 7.5.5 — 2026-02-25

### Fixed
- **GBP Photos — Option 2 rewritten as Business Name Search** — The previous Place ID Lookup
  was sending `placeId` in the POST body to `googleLocations:search`, which does not accept
  that field (API error: "Unknown name placeId: Cannot find field"). The endpoint only accepts
  a free-text `query` field.
- **Removed duplicate AJAX handler** — Two `myls_gbp_lookup_place_id` handlers were registered
  (from overlapping edits), causing the first broken handler to always win.

### Changed
- Option 2 now accepts a **business name + city** (e.g. "Acme Plumbing Tampa FL") and calls
  `googleLocations:search` with `{ query: "...", pageSize: 5 }`.
- Returns up to 5 matches displayed as a pick-list. Managed locations show a green
  "✓ managed" badge and a "Use This" button. Unmanaged locations show a red badge and no
  button (can't resolve Location ID without manager access).
- AJAX action renamed from `myls_gbp_lookup_place_id` to `myls_gbp_lookup_by_name`.

**Files changed:** `modules/oauth/gbp.php`, `admin/tabs/utilities/subtab-gbp-photos.php`, `aintelligize.php`

---


## 7.5.4 — 2026-02-25

### Added
- **GBP Photos — Place ID Lookup (Option 2)** — New location resolution method using the
  `googleLocations:search` endpoint on the Business Information API. Accepts a standard
  Google Place ID (e.g. `ChIJN1t_tDeuEmsRUsoyG83frY4`) and resolves it to the GBP resource
  name (`accounts/X/locations/Y`) without touching the quota-limited Account Management API.
  - If the Place ID belongs to a location managed by the authenticated Google account, the
    resource name is returned and saved automatically — no Account ID needed.
  - Clear error messages if: location not found, account is not a manager of the listing,
    or the resource name was not returned (unclaimed/unverified location).
  - Enter key supported. Saves and reloads on success.

### Changed
- **Option numbering updated** — Store Code is now Option 3, Paste IDs Manually is Option 4.
- **Store Code and Place ID handlers share** a `saveLookupResult()` helper — less code duplication.
- Store code error message updated to reference Option 4 (was Option 2).

**Files changed:** `modules/oauth/gbp.php`, `admin/tabs/utilities/subtab-gbp-photos.php`, `aintelligize.php`

---


## 7.5.3 — 2026-02-25

### Added
- **GBP Photos — Store Code lookup (Option 2)** — New third path for selecting a location
  that requires no Account ID and makes zero calls to the quota-limited Account Management API.
  - Enter the store code you assigned to your GBP location (found in Business Profile Manager →
    location → Info → Store code) and click **Find Location**.
  - Uses the `accounts/-` wildcard on the Business Information API to search across all accounts
    the authenticated user can access — a single API call to a less-restricted endpoint.
  - On success: resolves both the Location ID and Account ID from the returned resource name,
    auto-saves, and reloads the page.
  - If the `accounts/-` wildcard is not permitted on the project, a specific error is shown
    explaining the limitation and pointing to Options 1 or 3 instead.
  - Enter key supported in the store code input field.
  - Location label saved with a `[store: CODE]` suffix for traceability.
- **Redesigned location picker** — The three connection methods are now in clearly labelled,
  bordered cards (Option 1 blue / Option 2 purple / Option 3 red) replacing the previous
  single cramped row.

**Files changed:** `modules/oauth/gbp.php`, `admin/tabs/utilities/subtab-gbp-photos.php`, `aintelligize.php`

---


## 7.5.2 — 2026-02-25

### Added
- **GBP Photos — Manual Account ID / Location ID override** — Two text inputs added next to
  the Load Accounts button allow bypassing the quota-limited Account Management API entirely.
  Paste your `accounts/123456789` Account ID and `accounts/123456789/locations/ABCDEF`
  Location ID directly, then click **Use These IDs** to save and proceed straight to
  fetching photos — zero API calls to the restricted endpoints. Pre-populated from any
  previously saved selection. The **Fetch Photos** button uses the manual Location ID input
  as a live override if filled, falling back to the saved location. Intended as a workaround
  while awaiting a GCP quota increase.

**Files changed:** `admin/tabs/utilities/subtab-gbp-photos.php`, `aintelligize.php`

---


## 7.5.1 — 2026-02-25

### Fixed
- **GBP Photos — Quota exceeded error on Account dropdown** — The My Business Account Management API
  has very low default quotas for new Google Cloud projects (sometimes as low as 1 QPM). Accounts were
  previously fetched automatically on every page load, exhausting the quota immediately.
  - Accounts are now loaded **on-demand via a "Load Accounts" button**, never on page load.
  - Both accounts and locations are **cached as WordPress transients for 30 minutes** to protect quota.
  - Added a **↺ Refresh Cache** button to manually bust transients when fresh data is needed.
  - Quota errors now surface a **specific actionable message** with a direct link to GCP → Quotas.
  - Added `myls_gbp_clear_cache` AJAX action and `myls_gbp_bust_cache()` helper.
  - Permanent **⚠ API Quota Note** banner added in the Account/Location section.
  - **⚡ Cached** badge appears when accounts/locations are served from transient cache.

**Files changed:** `modules/oauth/gbp.php`, `admin/tabs/utilities/subtab-gbp-photos.php`, `aintelligize.php`

---

## 7.5.0 — 2026-02-25

### Added
- **Utilities → GBP Photos** — New subtab to browse and import Google Business Profile photos into
  the WordPress Media Library via OAuth 2.0.
  - Full OAuth 2.0 flow (`business.manage` scope), same pattern as existing GSC OAuth module.
  - Cascading account/location dropdowns supporting multiple accounts and multiple locations per account.
  - Photo grid with thumbnails, category labels, creation dates, and Load More pagination.
  - Selective import: click-to-select cards, Select All / Deselect All, duplicate prevention via
    `_gbp_media_name` post meta, live progress bar and import log.
  - Auto-discovered by the Utilities tab system — no changes to `aintelligize.php` required.

**Files added:** `modules/oauth/gbp.php`, `admin/tabs/utilities/subtab-gbp-photos.php`

---


## 7.4.1 — 2026-02-24

### Fixed
- **Photo image style was identical to Photorealistic** — root cause: DALL-E 3 defaults to `style: "vivid"` (hyper-realistic/dramatic) regardless of the prompt. Added the DALL-E API `style` parameter to `myls_pb_dall_e_generate()`. Photo now passes `style: "natural"` (authentic, muted, real camera look); all other styles pass `style: "vivid"`. Applied to hero, featured image, template image widgets, and standalone image generation.

**Files changed:** `inc/ajax/ai-elementor-builder.php`, `inc/ajax/ai-image-gen.php`

---

## 7.4.0 — 2026-02-24

### Added
- **Progress bar** — animated gradient progress bar appears below the Create button while the page is generating. Shows 5 stages with step indicators: API → Images → Content → Elementor → Done. Adapts timing based on whether images are being generated. Fades out automatically on success, stays red on error.

**Files changed:** `admin/tabs/ai/subtab-elementor.php`

---

## 7.3.9 — 2026-02-24

### Changed
- **Knowledge Graph uses Google Places API key** — no separate key needed. The Places API key already stored in API Integration is reused for Knowledge Graph Search API calls (same Google Cloud key works for both).
- Removed the standalone Knowledge Graph API key field and card from API Integration.

**Files changed:** `inc/ajax/ai-elementor-builder.php`, `admin/tabs/api-integration.php`

---

## 7.3.8 — 2026-02-24

### Fixed
- **Photo image style not working**: Featured Image and Hero were ignoring the selected "Photo" style due to a hardcoded `$feat_photo_style` override. Now all image types correctly use `$style_suffix` from the selected Image Style dropdown.

### Added
- **Google Knowledge Graph grounding** — add your KG API key in API Integration. When set, Knowledge Graph entity facts are injected into both the main page content prompt AND template AI fill prompts alongside Wikipedia. KG first, Wikipedia as supplement. Falls back silently if key not configured.
- **Knowledge Graph API key field** in API Integration tab with a Test button.
- **`🔍 Knowledge Graph context fetched`** log line in Results terminal when active.

**Files changed:** `inc/ajax/ai-elementor-builder.php`, `admin/tabs/api-integration.php`

---

## 7.3.7 — 2026-02-24

### Added
- **Tokens in Description / Instructions field** — `{{PAGE_TITLE}}`, `{{YOAST_TITLE}}`, `{{CITY}}`, `{{BUSINESS_NAME}}`, `{{PHONE}}`, `{{EMAIL}}`, `{{SITE_NAME}}`, `{{SITE_URL}}`, `{{POST_TYPE}}` are now resolved in the description before it is passed to AI prompts, image generation, and Wikipedia lookups. Token hints displayed under the field label.

**Files changed:** `admin/tabs/ai/subtab-elementor.php`, `inc/ajax/ai-elementor-builder.php`

---

## 7.3.6 — 2026-02-24

### Fixed
- **Template image placeholders used wrong style**: The template image filling block had its own `$tpl_style_map` that was missing the `photo` key and defaulting to `modern-flat`. Now includes `photo` and defaults to `photo`.
- **Template images now use correct size**: Photo and Photorealistic styles generate at `1792x1024` (landscape); all other styles use `1024x1024` (square).

**Files changed:** `inc/ajax/ai-elementor-builder.php`

---

## 7.3.5 — 2026-02-24

### Added
- **`{{YOAST_TITLE}}` token** in AI Prompt Template — resolves to the Yoast SEO Title / Focus Keyword field value. Falls back to page title if the field is empty. Token now visible in the token reference row above the prompt textarea.

**Files changed:** `admin/tabs/ai/subtab-elementor.php`, `inc/ajax/ai-elementor-builder.php`

---

## 7.3.4 — 2026-02-24

### Added
- **"Photo" Image Style** — new first option in Image Style dropdown. Uses a real-photography prompt: natural lighting, sharp focus, authentic scene, no illustrations. Default style for all new sessions.
- **Auto-switch to Photo** — when only "Featured Image" is checked and Hero is unchecked, Image Style automatically switches to Photo. Restores previous style if Hero is re-checked.

**Files changed:** `admin/tabs/ai/subtab-elementor.php`, `inc/ajax/ai-elementor-builder.php`, `inc/ajax/ai-image-gen.php`

---

## 7.3.3 — 2026-02-24

### Fixed
- **Featured Image without Hero**: When only "Featured Image" is checked (no Hero), the generated image now correctly gets set as the post's featured thumbnail. Previously `set_post_thumbnail` only fired for `type === hero` — the featured image was generated and saved to Media Library but never attached to the post. Priority order: Hero sets thumbnail first; Feature image is used as fallback if no hero was generated.

**Files changed:** `inc/ajax/ai-elementor-builder.php`

---

## 7.3.2 — 2026-02-24

### Changed — AI Images Card Cleanup
- **Removed** Feature image count dropdown — Featured Image is now always a single image
- **Removed** "Insert hero into page" checkbox
- **Featured Image** now generates at `1792x1024` (wide/hero proportions) with a forced photorealistic prompt — professional photography style regardless of Image Style selection
- **Image Style** dropdown now defaults to Photorealistic and spans full width
- **Set as Featured Image** checkbox retained (works for both Hero and Featured Image)

**Files changed:** `admin/tabs/ai/subtab-elementor.php`, `inc/ajax/ai-elementor-builder.php`, `inc/ajax/ai-image-gen.php`

---

## 7.3.1 — 2026-02-24

### Fixed
- **Silent image failure**: All failure paths in DALL-E image generation now produce visible log lines in the Results terminal. Previously: empty API key, missing helper function, and failed Media Library sideload all failed silently with zero output.
- **Added specific error messages** for: missing OpenAI key (with note that DALL-E requires OpenAI even when using Anthropic for text), helper function not loaded, DALL-E API error, and Media Library upload failure (with troubleshooting steps).

### Added
- **Test DALL-E Connection button** in the AI Images card — runs a 3-step diagnostic: verifies API key exists, calls DALL-E API, and uploads result to Media Library. Shows exactly which step fails with actionable fix instructions.

**Files changed:** `inc/ajax/ai-elementor-builder.php`, `inc/ajax/ai-image-gen.php`, `admin/tabs/ai/subtab-elementor.php`

---

## 7.3.0 — 2026-02-24

### Fixed
- **`$log_lines` bug in Elementor Builder**: Template processing lines (Wikipedia fetch, AI content fill, appended container counts) were wiped by the `$log_lines = []` reset that runs after post creation. Separated template logs into `$tpl_log_lines` and merged them into the final output — all log lines now appear in the Results terminal.

### Added
- **Template Image Widget Auto-Fill**: When "Integrate Images into page content" is checked and a template is appended, the plugin now scans the final Elementor element tree for `image` widgets with no real image (empty URL, `id=0`, or placehold.co URL) and generates a DALL-E image for each (capped at 5 per run to control costs). Images are saved to the Media Library, attachment parent is updated after post creation, and they appear in the Generated Images preview.
- **New helpers in `inc/ajax/ai-image-gen.php`**: `myls_elb_find_empty_image_widgets( array )` and `myls_elb_inject_image_into_widget( array, id, attach_id, url, alt )` — recursively scan and inject into the Elementor element tree.
- **JS: Template-aware `integrateImages` flag**: The "Integrate Images" trigger now also fires when a template is selected but hero/feature checkboxes are not checked — enabling template image filling even without generating hero or feature images.

**Files changed:** `inc/ajax/ai-elementor-builder.php`, `inc/ajax/ai-image-gen.php`, `admin/tabs/ai/subtab-elementor.php`

---

## 7.2.9 — 2026-02-24

### Added — Yoast SEO Title / Focus Keyword input

New text input above Generated Sections. Saved to:
- `_yoast_wpseo_focuskw` (Yoast focus keyword)
- `_yoast_wpseo_title` → `{keyword} %%page%% %%sep%% %%sitename%%` (when set) or `{title} %%sep%% %%sitename%%` (when blank)

Also used as the Wikipedia lookup topic and injected into AI prompts for more targeted content.

### Improved — Append Elementor Templates: up to 3 slots

Single dropdown replaced with three labeled dropdowns (Template 1 / 2 / 3). All three
load the same template list from a single AJAX call. Set any slot to "— None —" to skip.

Each selected template:
1. Gets IDs regenerated via `myls_elb_regen_ids()` to prevent collisions
2. Has its `AI Content Here` placeholders filled with unique content using a different
   angle per slot:
   - Slot 1 → benefits and value proposition
   - Slot 2 → process, methodology and what to expect
   - Slot 3 → local relevance, trust factors and why choose us
3. Is appended in order after all generated sections
4. Logs: `📎 Template N appended: "Name" (X container(s))`

### Improved — AI content uniqueness via Wikipedia + Wikidata

Before filling any `AI Content Here` placeholder, the plugin fetches factual context:

1. **Wikipedia REST API** (`/api/rest_v1/page/summary/{topic}`) — uses SEO keyword or
   page title as the lookup topic. Returns up to 1200 chars of the article extract.
   Skips disambiguation pages and stubs under 100 chars.
2. **Wikidata fallback** — if Wikipedia returns nothing useful, queries Wikidata
   `wbsearchentities` for the topic label + description.

The fetched context is injected into the AI prompt with explicit instruction:
`"synthesize from this, do NOT copy — rewrite in your own words"`.

This grounds content in real facts, forces original phrasing, and produces genuinely
more useful content vs generic filler. Wikipedia fetch is done once and reused across
all 3 template slots to keep latency low.

Log entry: `🌐 Wikipedia context fetched for: "Dog Training Tampa FL"`

**New helper:** `myls_elb_fetch_wikipedia_context( string $topic ): string`

**Files changed:** `admin/tabs/ai/subtab-elementor.php`, `inc/ajax/ai-elementor-builder.php`

---

## 7.2.8 — 2026-02-24

### Added — Elementor Builder: AI content fills "AI Content Here" placeholder in appended templates

When a template is appended and any of its `text-editor` widgets contain the text
`AI Content Here` (case-insensitive, strip_tags checked so it works even if the
editor has wrapped it in `<p>` tags), the plugin automatically:

1. Counts matching placeholders via `myls_elb_count_ai_placeholders()`
2. Calls `myls_ai_chat()` with a prompt asking for ~400 words of professional HTML
   content relevant to the page title and description
3. Replaces every matching widget's `editor` value with the generated HTML via
   `myls_elb_fill_ai_placeholders()` — the same content fills all placeholders
   (typically there is only one per template)
4. Logs: `✍️ AI content generated (~400 words) and inserted into N placeholder(s)`

The generated content uses `<p>`, `<ul>`, `<li>`, `<strong>` tags, no headings
(since the template typically has its own heading above the text editor).
Sanitized with `wp_kses_post()` before storing. If AI call fails, placeholders
are left as-is with a warning in the log.

**New helpers:**
- `myls_elb_count_ai_placeholders( array $elements ): int`
- `myls_elb_fill_ai_placeholders( array $elements, string $html ): array`

Both recurse the full Elementor element tree so they work regardless of nesting depth.

**Files changed:** `inc/ajax/ai-elementor-builder.php`

---

## 7.2.7 — 2026-02-24

### Added — Elementor Builder: Append Elementor Template

New **"Append Elementor Template"** card below Generated Sections. Loads all published
templates from `Elementor → Templates` (the `elementor_library` post type) into a
dropdown. Select any template and its containers will be appended verbatim at the
bottom of the generated page after all AI-generated sections.

**How it works:**
1. On page load, JS calls `myls_elb_get_templates` AJAX action which queries all
   published `elementor_library` posts ordered A–Z and returns their ID, title, and
   `_elementor_template_type` meta (shown in brackets next to the title).
2. Dropdown shows `— None —` by default. Selecting a template shows a blue info note.
3. On page creation, if `append_template_id > 0`, the handler reads the template's
   `_elementor_data` JSON, passes all its containers through `myls_elb_regen_ids()`
   to give each element a fresh unique ID (prevents ID collisions), then merges them
   onto the end of the generated containers array before saving.
4. The results log reports: `📎 Template appended: "Name" (N container(s))`

**New helper:** `myls_elb_regen_ids( array $elements ): array` — recursively walks
an Elementor element tree and replaces every `id` field with a fresh `myls_elb_uid()`
value. Safe to call on any depth of nested containers and widgets.

**New AJAX action:** `myls_elb_get_templates` — returns all published Elementor library
templates with their type label for the dropdown.

**Files changed:** `admin/tabs/ai/subtab-elementor.php`, `inc/ajax/ai-elementor-builder.php`

---

## 7.2.6 — 2026-02-24

### Improved — Elementor Builder: per-section include/exclude checkboxes

All 6 generated sections now have individual checkboxes in the Generated Sections card,
all checked by default. Uncheck any section to skip it entirely from the generated page.

| Checkbox ID | Section |
|---|---|
| `myls_elb_include_hero` | Hero Banner (+ theme-header note when unchecked) |
| `myls_elb_include_intro` | Service Intro |
| `myls_elb_include_features` | Feature Cards |
| `myls_elb_include_process` | How It Works |
| `myls_elb_include_faq` | FAQ Accordion |
| `myls_elb_include_cta` | CTA Block |

Flags are passed to `myls_elb_parse_and_build()` as a `$section_flags` array
(replaces the previous single `bool $include_hero` parameter). All flags default
to `true` so API/code calls without the param still receive all sections.

**Files changed:** `admin/tabs/ai/subtab-elementor.php`, `inc/ajax/ai-elementor-builder.php`

---

## 7.2.5 — 2026-02-24

### Fixed — Elementor Builder: AI Images section not functioning at all

**Root cause:** The post-creation image generation button calls `myls_pb_generate_images`
(the shared image AJAX action). That handler verifies the nonce against `'myls_pb_create'`
(the Page Builder nonce action). The Elementor Builder subtab creates its nonce with action
`'myls_elb_create'`. Every image request from the Elementor tab failed silently with
"Bad nonce" (HTTP 400) — images never generated.

**Fix:** Updated `ai-image-gen.php` to accept either nonce action:
```php
if ( ! wp_verify_nonce($nonce, 'myls_pb_create') && ! wp_verify_nonce($nonce, 'myls_elb_create') )
```

**Files changed:** `inc/ajax/ai-image-gen.php`

---

### Added — Elementor Builder: Hero Banner toggle (skip for Hello Elementor / theme headers)

Some themes (Hello Elementor, Astra, GeneratePress, etc.) provide their own page header
or title area at the top of every page. In those cases the AI-generated hero section
would double up with the theme header.

**New "Hero Banner" checkbox** in the Generated Sections card (checked by default).
Uncheck to skip the hero container entirely — the page will start at the Service Intro
section. A yellow note appears when unchecked: "Hero skipped — your theme header will
be used instead."

The `include_hero` param defaults to `true` server-side so existing integrations are
unaffected. `myls_elb_parse_and_build()` gains a `bool $include_hero = true` parameter.

**Files changed:** `admin/tabs/ai/subtab-elementor.php`, `inc/ajax/ai-elementor-builder.php`

---

## 7.2.4 — 2026-02-24

### Fixed — Elementor Builder: Advanced → Width dropdown not showing "Custom"

**Root cause:** `_element_width` was set to `'custom'` but Elementor's actual select option
value for "Custom" is `'initial'`. The select options are:
- `''` → Default
- `'inherit'` → Full Width (100%)
- `'auto'` → Inline (auto)
- `'initial'` → Custom  ← correct value

The 30% custom width was being stored but the dropdown above it remained on "Default"
because `'custom'` matched no option. Fixed all three widget functions.

**Files changed:** `inc/ajax/ai-elementor-builder.php`

---

## 7.2.3 — 2026-02-24

### Fixed — Elementor Builder: icon boxes stacking vertically instead of flowing in a row

**Root cause:** The inner row container had `flex_direction: row`, `flex_wrap: wrap`, and
`flex_justify_content: center` — but the individual icon box widgets had no explicit width.
In Elementor's flex containers, widgets without a width setting default to stretching across
the full container width (like block elements), so they stacked vertically regardless of
the row direction.

**Fix:** Added `_element_width: 'custom'` and `_element_custom_width: 30%` to all three
box widget types used in the features/process rows:
- `myls_elb_icon_box_widget()` — 30% width
- `myls_elb_image_box_widget()` — 30% width
- `myls_elb_image_placeholder_box_widget()` — 30% width

With 30% width, 3 boxes fit comfortably per row with the 20px gap between them. When the
AI generates 4 items, 3 appear on row 1 and 1 orphan on row 2 — which is automatically
centered because the parent row container has `flex_justify_content: center`.

**Files changed:** `inc/ajax/ai-elementor-builder.php`

---

## 7.2.2 — 2026-02-24

### Fixed — Elementor Builder: Container Layout dropdown still blank (wrong value "flexbox" vs "flex")

**Root cause:** Elementor 3.x stores `container_type` as `"flex"` for flexbox containers,
not `"flexbox"`. The plugin was writing `"flexbox"` which doesn't match any option in
Elementor's Container Layout select control — so the dropdown rendered blank even though
the data was technically present.

Confirmed by inspecting live containers on a real page (Puppy Kindergarten): all containers
have `container_type: "flex"`. Generated containers had `container_type: "flexbox"` — a
value that exists in no Elementor option set.

**Fix:** Changed all three `container_type` assignments in `myls_elb_section()` and the
inner row builders from `'flex'` → already `'flex'`. Specifically:
- Line 55 (`myls_elb_section()` default) — `'flexbox'` → `'flex'`
- Line 479 (features inner row) — `'flexbox'` → `'flex'`
- Line 519 (process inner row) — `'flexbox'` → `'flex'`

Container Layout now shows "Flexbox" correctly in the Elementor panel on all generated containers.

**Files changed:** `inc/ajax/ai-elementor-builder.php`

---

## 7.2.1 — 2026-02-24

### Fixed — Elementor Builder: Container Layout always blank after generation

**Root cause:** The `container_type => 'flexbox'` setting was being silently stripped every
time a page was created. When Elementor's `$document->save()` API is called it runs all
element settings through its registered-controls sanitization pipeline. Because `container_type`
is not a formally registered control at save-time, Elementor discards it. Opening any generated
container in the Elementor panel showed "Layout: (blank)" — manually setting it to Flexbox
re-added the key, which is why that manual fix worked.

**Fix:** Removed the `$document->save()` call entirely. The builder now always writes directly
to `_elementor_data` post meta via `update_post_meta( $post_id, '_elementor_data', wp_slash( $elementor_json ) )`.
Direct meta write preserves the exact JSON as generated — including `container_type => 'flexbox'`
on every container. The real installed Elementor version is used for `_elementor_version` to
prevent migration triggers, and `_elementor_css`, `_elementor_element_cache`, and
`_elementor_page_assets` are cleared so Elementor regenerates its CSS cache on first page load.

**Files changed:** `inc/ajax/ai-elementor-builder.php`

---

### Improved — Elementor Builder: richer site pattern detection

`inc/elementor-site-analyzer.php` — `myls_elb_aggregate_patterns()` now captures:

- **Hero detection** — dark background on the first top-level container marks `has_hero: true`.
  The AI prompt is told to include a hero section.
- **CTA detection** — dark background on a container after position 3 marks `has_cta: true`.
  The AI prompt is told to include a CTA section near the end.
- **FAQ detection** — any shortcode containing "faq" sets `has_faq: true`. The AI prompt is
  told to always include a faq section with 5 items.
- **Background color sequence** — the ordered list of background colors from the first sampled
  page is captured as `section_bg_pattern`. The AI prompt is given the sequence so it can
  alternate light/dark sections to match the site's existing pattern.
- **Button alignment** — most common button alignment across sampled pages is passed to the
  AI prompt.

New helper: `myls_elb_is_dark_color( string $hex ): bool` — calculates relative luminance
(sRGB) and returns true when luminance < 0.35. Used to identify hero and CTA sections by their
background color.

`myls_elb_build_prompt_context_block()` now emits additional context lines:
`HERO`, `CTA`, `FAQ`, `SECTION BACKGROUNDS (in order)`, and `BUTTON ALIGNMENT`.

Results panel log now shows detected sections (hero / cta / faq), avg icon box count, and
section background colors.

**Files changed:** `inc/elementor-site-analyzer.php`

---

## 7.2.0 — 2026-02-24

### New Feature — Elementor Builder: Site Analysis & Structural Consistency

#### Overview
Before generating a new page, the Elementor Builder now reads two things from the live site:
1. **Elementor Kit settings** — container width, global colors, typography
2. **Up to 3 existing posts** of the same post type — walks their full widget tree to extract patterns

Generated pages now match the structural style of existing pages on the site.

---

#### New file: `inc/elementor-site-analyzer.php`

**`myls_elb_get_kit_id()`**
Reads `elementor_active_kit` option to find the active Elementor Kit post ID.
Falls back to querying `elementor_library` posts with `_elementor_template_type = kit`.

**`myls_elb_read_kit_settings( int $kit_id = 0 ): array`**
Reads `_elementor_page_settings` from the kit post and extracts:
- `container_width` (px) — the site's boxed container dimension
- `colors` — global color palette entries with id, title, and value
- `typography` — font families, sizes, weights
- `button_bg_color`, `button_text_color`, `button_border_radius`

**`myls_elb_walk_elements( array $elements, int $depth = 0 ): array`**
Recursively walks an `_elementor_data` widget tree and returns a flat list of
element summaries. Each entry includes: elType, widgetType, depth, lean settings
(text preview, icon, image presence, bg color, child widget list), and child count.
Does not store full HTML blobs — only what's needed for pattern analysis.

**`myls_elb_sample_pages( string $post_type, int $limit = 3, int $skip_id = 0 ): array`**
Fetches up to 3 published posts of the given post type that have `_elementor_edit_mode = builder`.
Returns ordered by `modified DESC` so the most recently edited pages are sampled.
Each entry includes post_id, title, url, and the walked elements array.

**`myls_elb_aggregate_patterns( array $sample_pages ): array`**
Reduces all sampled page widget lists into a unified pattern summary:
- `widget_freq` — widgetType → count, sorted by frequency
- `has_images` / `has_image_boxes` — whether image widgets are used
- `image_depths` — nesting depths where images appear
- `top_level_sections` — average section count per page
- `section_bg_colors` — unique background colors used on sections
- `uses_shortcodes` — shortcode strings found
- `common_btn_align` — most common button alignment
- `avg_icon_box_count` — average icon boxes per page

**`myls_elb_build_prompt_context_block( array $kit, array $patterns, array $sample_pages ): string`**
Converts the kit + pattern data into a human-readable block appended to the AI prompt:
- Container width instruction
- Global color names and values
- Primary font family
- Widget frequency list (top 8)
- Image usage note (include slots if images found, omit if not)
- Icon box count guidance
- Section count target
- Sampled page titles for reference

**`myls_elb_analyze_site( string $post_type, int $skip_id = 0 ): array`**
Main entry point. Calls all of the above and returns:
`{ kit, sample_pages, patterns, prompt_block, log }`
The `log` array contains human-readable lines shown in the results panel so you can
see exactly what was detected before generation.

---

#### Changes to `inc/ajax/ai-elementor-builder.php`

**Site analysis wired in:**
- `myls_elb_analyze_site()` runs immediately after post type validation
- `$site_context['prompt_block']` is appended to the AI prompt
- `$kit` and `$site_patterns` are passed into `myls_elb_parse_and_build()`
- Site analysis log lines appear at the top of the results panel

**Container width propagation:**
- All section builders now accept `int $container_width = 1140`
- `build_intro`, `build_features`, `build_process`, `build_faq` all use kit width
  in their `boxed_width` container setting instead of the hardcoded 1200px

**Image consistency:**
- `build_features()` gains `bool $prefer_image_box = false` param
- When the site pattern shows `has_image_boxes = true` and no generated image
  is available, falls back to `myls_elb_image_placeholder_box_widget()` instead
  of icon-box — matching the site's existing widget type
- Feature count overridden by `avg_icon_box_count` from patterns when user
  hasn't customised it (default 3 → matched to site average)

**New widget functions:**
- `myls_elb_image_placeholder_box_widget( $title, $desc )` — image-box widget
  with a visible grey placeholder (placehold.co) so the slot is clearly visible
  in Elementor and ready for the user to swap in a real image
- `myls_elb_image_or_placeholder_widget( $img_data, $alt )` — returns a real
  image widget if generated data is available, otherwise the same placeholder
- `myls_elb_build_image_section( $d, $container_width, $image )` — standalone
  image section builder used when AI returns an `image_section` key

**AI prompt template updated:**
- Added documentation for optional `image_section` array key
- AI is instructed to include image slots only when SITE CONTEXT block says images are used

---


## 7.1.4 — 2026-02-24

### Fixed — Icon box orphan centering + button color inheriting black instead of brand color

**Diagnosed via live browser inspection of the generated page.**

#### Icon box row centering

**Root cause:** Elementor was rendering inner containers as `display: grid` (CSS Grid)
even though our settings targeted flex. The `flex_justify_content: center` setting was
completely ignored on a grid container. A 4-item features section produced 3 items in row
1 and the orphaned 4th item stuck left in row 2.

**Why it happened:** The container JSON had no explicit `container_type` key. In
Elementor 3.8+, when `container_type` is absent, Elementor's frontend defaults to grid
layout for inner containers with multiple children.

**Fix:**
- Added `'container_type' => 'flexbox'` to the default `myls_elb_section()` settings,
  so every generated container explicitly uses flex.
- Added `'container_type' => 'flexbox'` explicitly to both the features and process inner
  row containers.
- Changed process inner container from `flex-start` to `center` justify — orphaned steps
  now center just like features.

With `display: flex`, `flex-wrap: wrap`, `justify-content: center`, each row (including
the last partial row) distributes its items centered, regardless of count.

#### Button color (black instead of brand orange)

**Root cause:** Elementor's global kit on this site defines:
- `--e-global-color-primary`: `#BF4820` (brand dark orange)
- `--e-global-color-secondary`: `#FF7648` (brand orange)
- `--e-global-color-accent`: `#000000` (black)

When a Button widget has no explicit `background_color` and no `__globals__` reference,
Elementor generates a CSS rule using `--e-global-color-accent` (black) as the default.
The site's existing orange buttons work because they explicitly reference
`--e-global-color-secondary` via Elementor's global color system.

**Fix:** Added `__globals__` to the Button widget settings:
```php
'__globals__' => [
    'background_color' => 'globals/colors?id=primary',
],
```
This tells Elementor to render the button using `--e-global-color-primary` — the site's
brand primary color from the kit. The button now matches the site's color scheme on
any site, using whatever that site has set as its Elementor global primary color.

---


## 7.1.3 — 2026-02-24

### Fixed — Elementor Builder prompt template still showing/using old HTML instructions

**Root cause:** The user-editable prompt textarea is populated from the `myls_elb_prompt_template`
DB option. That option was saved before v7.1.0 and still contained the old HTML-output
instructions (`HTML RULES`, `Output raw HTML`, `elb-hero` sections, etc.). Updating the
prompt file on disk had no effect — the saved DB value always wins.

**Three-layer fix:**

1. **Subtab render (PHP)** — on every page load, the saved option is checked against a list
   of HTML-pipeline fingerprints. If matched, the option is cleared and the textarea shows
   the current JSON-output default. A yellow notice banner is shown explaining the auto-reset.

2. **AJAX handler (PHP)** — if a POST arrives with a stale HTML prompt (same fingerprint check),
   the handler silently substitutes the current default before calling the AI and clears the
   saved option. The results log shows a 🔄 notice explaining the substitution.

3. **"↺ Reset to Default" button (JS)** — added next to "Save to DB" in the prompt card.
   Clears the saved option and reloads the page so the textarea repopulates from the file
   default. Useful for any future prompt divergence.

**Other UI changes:**
- Save prompt now shows inline feedback via `descMsg()` instead of `alert()`
- Test Tab notice updated to accurately describe the native widget approach

---


## 7.1.2 — 2026-02-24

### Fixed — Elementor Builder still generating single HTML block

**Root cause:** The `system` parameter passed to `myls_ai_chat()` was overriding the
user prompt entirely. It contained explicit instructions to output HTML with `<section>`
tags and Bootstrap classes — which is exactly what the AI followed, ignoring the
JSON instructions in the prompt file.

The AI always obeys the system message over the user prompt when they conflict.
Updating the prompt file in v7.1.0 had zero effect because the system message said:
`"Write clean, structured HTML... Always wrap each major content section in a <section> tag"`

**Fix:** The system message now reads:
`"You output ONLY valid JSON — never HTML, never markdown, never code fences."`

With matching instructions in both the system message and the user prompt, the AI
will now return the JSON structure that `myls_elb_parse_and_build()` expects, and
native Elementor widgets (Heading, Text Editor, Button, Icon Box, Shortcode) will
be built correctly.

---


## 7.1.1 — 2026-02-24

### Fixed — Elementor Builder: images not placed in page or appearing correctly

**Root cause:** The image injection code was written for the old HTML-output pipeline.
It appended `<img>` tag instructions to the AI prompt, but the AI now outputs structured
JSON — so those instructions had no effect. Images were generated and saved to the media
library (that part worked), then silently discarded when building Elementor widgets.

**Hero image** — now set as the container `background_image` with `background_size: cover`
and a semi-transparent dark overlay (`rgba(26,35,50,0.72)`). This is the correct
Elementor-native approach: the image fills the section and heading/text remain readable.

**Feature images** — when generated images are available, `icon-box` widgets are replaced
with `image-box` widgets (Elementor's Image Box: image + title + description). When no
images were generated, icon-box widgets continue to be used as before.

**New widget functions added:**
- `myls_elb_image_widget()` — standalone Image widget
- `myls_elb_image_box_widget()` — Image Box widget (image + title + description)

**`myls_elb_parse_and_build()`** now accepts `$generated_images` as a second parameter
and routes hero/feature images to the appropriate section builders.

**Old `$img_block` prompt injection removed** — it was dead code targeting an HTML pipeline
that no longer exists.

**Log line** now reports exactly where images were placed:
`📸 2 image(s) integrated: hero → container background, 1 feature(s) → image-box widgets`

---


## 7.1.0 — 2026-02-24

### Changed — Elementor Builder: native widget architecture

The Elementor Builder subtab now generates fully native Elementor widget trees
instead of raw HTML code widgets. Every section is directly editable in the
Elementor visual editor without opening any code panels.

**Widget mapping:**

| Section  | Elementor widgets used |
|----------|------------------------|
| Hero     | Heading (h1) + Text Editor + Button |
| Intro    | Heading (h2) + Text Editor |
| Features | Heading (h2) + inner flex row of Icon Box widgets |
| Process  | Heading (h2) + inner flex row of Icon Box widgets (numbered) |
| FAQ      | Heading (h2) + Shortcode `[faq_schema_accordion heading=""]` |
| CTA      | Heading (h2) + Text Editor + Button |

**Button & style defaults:** Button widget uses Elementor's global defaults — no hardcoded colors. Icon Box icons use the `view: stacked` + rounded circle style with a cycling color palette.

**FAQ flow:** AI-generated FAQ items are extracted from the JSON and saved to `_myls_faq_items` post meta. The `[faq_schema_accordion]` shortcode reads from that meta automatically — FAQs appear with full schema markup and Bootstrap accordion, and are editable via the plugin's custom fields panel.

**AI prompt rewritten:** The prompt now requests a structured JSON object instead of HTML. Each section is a named key (`hero`, `intro`, `features`, `process`, `faq`, `cta`) with typed fields. This makes parsing deterministic — no more DOMDocument HTML splitting. If the AI returns malformed JSON, the output falls back to a single HTML widget.

**New PHP functions:**
- `myls_elb_heading_widget()` — Heading widget
- `myls_elb_text_editor_widget()` — Text Editor widget (TinyMCE-editable inline)
- `myls_elb_button_widget()` — Button widget with global style defaults
- `myls_elb_icon_box_widget()` — Icon Box widget (stacked, rounded, color cycled)
- `myls_elb_shortcode_widget()` — Shortcode widget
- `myls_elb_icon_color()` — Color palette helper
- `myls_elb_build_hero/intro/features/process/faq/cta()` — per-section builders
- `myls_elb_parse_and_build()` — main entry: parses AI JSON → Elementor data array + FAQ items

**Backward compat:** `myls_elb_split_sections()` and `myls_elb_build_elementor_data()` are kept as deprecated stubs that delegate to `myls_elb_parse_and_build()`.

---


## 7.0.9 — 2026-02-24

### Fixed
- **Elementor Builder subtab — description history save (and all other buttons) not working.** A literal newline character was embedded inside a JavaScript string in the debug inspector block: `lines.join('` + actual newline + `')`. This is a JS syntax error. Because it was inside the same IIFE as every other listener (load, save, delete, create page), the **entire script block failed to parse** and zero event listeners were attached — explaining why save appeared to do nothing. Fixed by replacing the literal newline with the `\n` escape sequence. Confirmed clean with `node --check` after fix.

---


## 7.0.8 — 2026-02-24

### Fixed
- **`/llm-info` returning 404.** The rewrite rule was registered on `init` but `flush_rewrite_rules()` was never called, so WordPress's rewrite database never learned the route existed. A versioned auto-flush (same pattern as `llms-txt.php`) is now added to `llm-info.php`. On first load after the update, `flush_rewrite_rules(false)` runs once and `/llm-info` becomes accessible immediately — no manual Permalinks save required.

### Added
- **Utilities → llms.txt sidebar** now shows all three endpoint URLs as clickable links:
  - `/llms.txt` — lightweight index
  - `/llms-full.txt` — comprehensive content
  - `/llm-info` — HTML page for AI assistants (was previously invisible in the UI)
- **"Flush Rewrite Rules" button** in the sidebar. One click calls `flush_rewrite_rules(false)` from within the plugin — no need to navigate to Settings → Permalinks.

---


## 7.0.7 — 2026-02-24

### Fixed
- **Description history buttons (Load / Save / Delete) not functioning in AI Page Builder and Elementor Builder subtabs.**

  Root cause: Both subtabs were using `window.prompt()` for the save name dialog and `window.confirm()` for the delete dialog. Chrome (and other modern browsers) silently block these native dialogs on WordPress admin pages, so `prompt()` returned `null` immediately → the `if (!name) return` guard fired → nothing happened. Errors from `loadDescHistory()` were also swallowed with `catch(e) { /* silent */ }` making diagnosis impossible.

  **Fixes applied to both `subtab-page-builder.php` and `subtab-elementor.php`:**
  - **Save button** — now reveals an inline row (text input + Save/Cancel buttons) instead of calling `prompt()`. Pre-fills with the page title. Enter key confirms, Escape cancels.
  - **Delete button** — two-click confirm pattern using the inline message div instead of `confirm()`. First click shows a warning message; second click within 4 seconds executes the delete.
  - **Load button** — unchanged logic but now shows an inline error message instead of `alert()` if no description is selected.
  - **`loadDescHistory()`** — errors now surface in the message div instead of being silently swallowed.
  - All success/error feedback shown as a green/red inline message that auto-dismisses after 3 seconds.

---


## 7.0.6 — 2026-02-24

### Fixed
- **AI → Elementor Builder — blank page on creation.** Three root causes corrected:

  1. **`wp_slash()` missing (primary cause)** — `update_post_meta()` calls `stripslashes()` internally before storing values. Without `wp_slash()` pre-applied, every `\"` in the JSON string was stripped, producing invalid JSON that Elementor silently discarded → blank page. Fixed: `update_post_meta( $post_id, '_elementor_data', wp_slash( $elementor_json ) )`.

  2. **Version hardcoded to `3.0.0`** — Elementor checks `_elementor_version` against the running version and attempts data migration if it looks old. Writing `3.0.0` while Elementor 3.24+ is installed triggered internal migration logic that could corrupt the structure. Fixed: reads `ELEMENTOR_VERSION` constant at runtime.

  3. **No cache invalidation after direct meta write** — Elementor caches rendered CSS and element trees per-post. A direct `update_post_meta` call bypasses the cache invalidation that the editor normally triggers. Fixed: deletes `_elementor_css` and `_elementor_element_cache` post-meta, then calls `files_manager->clear_cache()` if available.

- **Elementor Document API path added** — When `\Elementor\Plugin` is active, `documents->get($post_id)->save(['elements' => $array])` is now attempted first. This is the official save path — it handles slashing, versioning, and cache invalidation automatically. Direct meta write is retained as a fallback for when Elementor is not yet active.

### Added
- **Debug Inspector panel** in the Elementor Builder subtab. After creating a page, click "Inspect Elementor Data" to see: `_elementor_edit_mode`, `_elementor_version`, `_elementor_template_type`, JSON stored/valid/length, container count, widget count, and a raw JSON preview. Post ID auto-fills after creation. Essential for diagnosing save issues.
- `wp_ajax_myls_elb_debug_post` AJAX action backing the inspector.

---


## 7.0.5 — 2026-02-24

### Fixed
- **AI → Elementor Builder** — Elementor containers were not being created when the Flexbox Container experiment/feature is active (Elementor 3.6+). The old `section → column → widget` structure was replaced with the modern `container → widget` structure (`elType: 'container'`). Key changes:
  - `myls_elb_section()` now emits `elType: 'container'` with `content_width: 'full'`, `flex_direction: 'column'`, `flex_align_items: 'stretch'` — no intermediate column element.
  - `myls_elb_build_elementor_data()` uses `content_width` and `width` for full-stretch containers instead of the deprecated `stretch_section` setting.
  - `_elementor_data` JSON is now fully compatible with the Flexbox Container model.

### Changed
- UI label updated from "Generated Sections" → "Generated Containers" throughout the subtab and results badge.

---


## 7.0.4 — 2026-02-24

### Added
- **AI → Elementor Builder (new subtab)** — Parallel test tab alongside the native Page Builder. Generates the same AI content but writes it directly into Elementor's `_elementor_data` JSON rather than `post_content`.
- `admin/tabs/ai/subtab-elementor.php` — Full UI: post type selector, description history (save/load/delete), business variable fields, image generation settings (DALL-E 3), prompt template editor, nav detection, results log, and "Edit in Elementor" deep link.
- `inc/ajax/ai-elementor-builder.php` — All AJAX actions: `myls_elb_create_page`, `myls_elb_save_prompt`, `myls_elb_get_nav_posts`, `myls_elb_save_description`, `myls_elb_list_descriptions`, `myls_elb_delete_description`.
- `assets/prompts/elementor-builder.txt` — Elementor-specific prompt template. Instructs the AI to wrap each content block in a `<section class="elb-*">` tag so the PHP layer can split them into discrete Elementor sections.
- Helper functions in the AJAX handler: `myls_elb_section()`, `myls_elb_html_widget()`, `myls_elb_split_sections()`, `myls_elb_build_elementor_data()`, `myls_elb_uid()` — build valid Elementor JSON from AI output with correct section/column/widget hierarchy.

### Changed
- `aintelligize.php` — `require_once` added for `inc/ajax/ai-elementor-builder.php`.

### Notes
- Each AI `<section>` maps to one Elementor section → column → HTML widget, all fully editable in the canvas.
- Hero/CTA sections automatically get a dark background (`#1a2332`) on the Elementor wrapper; Features/FAQ sections get light grey (`#f8f9fa`).
- Image generation re-uses existing `myls_pb_dall_e_generate()` and `myls_pb_upload_image_from_url()` helpers.
- Post-creation image generation re-uses the `myls_pb_generate_images` AJAX action from the native page builder.
- Elementor active/version status shown in a badge at top-left of the setup panel.

---


## 7.0.3 — 2026-02-24

### Added
- **AI → FAQs Builder** — post list now shows a `✓` prefix on any post that already has MYLS FAQ items saved (`_myls_faq_items`), matching the existing behaviour in the Taglines generator.
- **AI → About the Area** — post list now shows a `✓` prefix on any post that already has About the Area content saved (`_about_the_area`).

### Changed
- `inc/ajax/ai-faqs.php` — `myls_ai_faqs_get_posts_v1` now returns `has_faqs` (bool) alongside each post record.
- `inc/ajax/ai-about.php` — `myls_ai_about_get_posts_v2` now returns `has_content` (bool) alongside each post record.
- `assets/js/myls-ai-faqs.js` — `renderPosts()` prepends `✓ ` to option label when `has_faqs` is true.
- `assets/js/myls-ai-about.js` — option builder prepends `✓ ` to label when `has_content` is true.

---


## 7.0.2 — 2026-02-22

### New Shortcode: [google_reviews_slider]

Pulls Google reviews via the Places API and displays them in a glassmorphism-styled Swiper slider.

**Features:**
- Fetches reviews from Google Places API using saved API key + Place ID
- Swiper.js slider with prev/next arrows, pagination dots, autoplay with pause-on-hover
- Glassmorphism card styling (frosted glass blur via `backdrop-filter`)
- Star ratings rendered as Unicode stars with configurable color
- WP transient caching (default 24 hours) to minimize API calls
- Filter by minimum rating, sort by newest/highest, limit count
- Configurable blur, opacity, text color, autoplay speed, excerpt word limit
- Supports multiple instances on same page via unique IDs
- Admin-only error messages when API key or Place ID is missing

**Usage:** `[google_reviews_slider]` / `[google_reviews_slider min_rating="4" sort="newest" speed="5000"]`

**File added:** `modules/shortcodes/google-reviews-slider.php`

### New Shortcode: [social_links]

Displays branded circular social media icons linked to the Organization schema sameAs / social profile URLs.

**Features:**
- Auto-reads URLs from `myls_org_social_profiles` (Organization schema settings) — no duplicate data entry
- Auto-detects platform from URL: Facebook, Instagram, X, YouTube, LinkedIn, TikTok, Pinterest, Yelp, Google Business, Google Maps, BBB, Thumbtack, Angi, Nextdoor, and generic fallback
- Inline SVG icons (no external icon library dependency)
- Three style modes: `color` (branded circles), `mono-dark`, `mono-light`
- Configurable size, gap, alignment, and link target
- Whitelist (`platforms="facebook,instagram"`) and blacklist (`exclude="tiktok"`) filtering
- Hover effect with scale + shadow transition
- Accessible with `aria-label` and `title` on each icon

**Usage:** `[social_links]` / `[social_links size="44" style="color" align="center"]`

**File added:** `modules/shortcodes/social-links.php`

### Enhanced: [service_grid]

- **New attribute:** `aspect_ratio` — accepts any CSS aspect-ratio value (`1/1`, `4/3`, `16/9`, `3/4`)
- Uses CSS `aspect-ratio` property with `object-fit: cover` for uniform image sizing
- Added complete inline CSS foundation (previously missing):
  - 10px gutters via `--bs-gutter-x/y`
  - Flexbox card layout for `.service-box`
  - Image link overflow hidden + border radius + hover scale transition
  - Aspect-ratio mode via CSS variable `--myls-sg-ratio`
  - Cropped-image mode (existing `image_crop="1"` now functional)
  - Title link styling and tagline/excerpt typography
  - Featured first card minimum height
- CSS printed once via `static $css_printed` guard

**Usage:** `[service_grid columns="6" show_excerpt="0" button="0" aspect_ratio="1/1"]`

**File changed:** `modules/shortcodes/service-grid.php`

## 7.0 — 2026-02-22

### Search Stats Dashboard

New standalone submenu page (AIntelligize → Search Stats) providing comprehensive keyword performance tracking with persistent database storage.

**Core Features:**
- Focus keyword scanning from Yoast SEO, Rank Math, and AIOSEO
- FAQ question integration — each FAQ becomes a tracked keyword
- Google Autocomplete expansion — 5 query types per keyword (exact, expanded, how, what, best)
- GSC Search Analytics enrichment — impressions, clicks, CTR, average position
- AI Overview detection via `searchAppearance` dimension filter
- Per-post SERP rank — filters by keyword + page URL for exact position
- Rank history tracking — daily snapshots stored in `wp_myls_search_demand_history`
- Movement arrows (▲/▼) comparing current rank to previous snapshot

**Dashboard UI:**
- 6 KPI cards: Keywords Tracked, AC Suggestions, GSC Queries, Avg Rank, AI Overview, Last Refreshed
- Post type filter pills (All Types, Page, Post, Service, etc.)
- Refresh All button — sequential Scan → AC → GSC with progress bar
- Expandable sub-grid rows with green AC/GSC match highlighting
- Yellow bonus section for GSC queries not in AC suggestions
- Purple AI Overview badges with impression counts
- Color-coded rank badges: green (#1-3), blue (#4-10), yellow (#11-20), red (20+)
- Freshness badges: FRESH (<7d), STALE (7-30d), OLD (>30d), NOT RUN
- Print-friendly CSS with all colors preserved

**Database:**
- `wp_myls_search_demand` — main table with keyword, AC, GSC, rank data (UNIQUE on keyword+post_id)
- `wp_myls_search_demand_history` — daily snapshots (UNIQUE on sd_id+snapshot_date)
- Auto-snapshot on every GSC save — no extra button needed
- Orphan pruning cascades to history table

### Google Search Console OAuth

- Full OAuth 2.0 flow with start/callback/disconnect/test handlers
- Module at `modules/oauth/gsc.php` with automatic token refresh
- Site property auto-detection with manual override option
- Connection status indicator in API Integration tab

### Search Demand Tab

- Top-level tab (order 45) with two subtabs:
  - FAQ Search Demand — manual term checking and site-wide audit
  - Focus Keyword AC Options — 5-query autocomplete expansion workflow
- Three-step workflow: Load Focus Keywords → Get AC Suggestions → GSC Enrich
- Progressive processing with 300ms/500ms throttle and Stop/Resume
- Grouped, expandable results with GSC sub-grid tables

### Documentation

- Interactive shortcode docs updated with 7 previously undocumented shortcodes
- All 35+ registered shortcodes now covered with attributes, examples, and tips
- Aliases noted (seo_title → yoast_title, myls_flip_grid → myls_card_grid, etc.)
- New YouTube category for video-related shortcodes

**Files added:**
- `admin/admin-search-stats-menu.php` — submenu registration
- `admin/search-stats/search-stats.js` — dashboard rendering (35KB)
- `admin/search-stats/search-stats.css` — dashboard styles
- `inc/db/search-demand-table.php` — DB tables + CRUD + history
- `inc/ajax/ai-faq-search-check.php` — AJAX endpoints for scan/AC/GSC/history
- `modules/oauth/gsc.php` — GSC OAuth module

## 6.3.2.6 — 2026-02-21

### Fixed: Fill Pass Producing Broken HTML / Double FAQ in Single Answer

**Problem:** The fill pass (which generates replacement FAQs when bad ones are dropped) produced HTML with spaces inside tags (`< / p >`, `< h 3 >`, `a href = "url"`). This caused two FAQs to merge into one accordion body — the `<h3>` separator between them was mangled into visible text (`p> h 3 >`), and raw `<a href="...">` tags rendered as plain text instead of clickable links.

**Root cause:** The fill pass had its own copy of the sanitization regexes that was out of sync with the main handler. The main handler had been getting new fixes (6.3.2.3–6.3.2.5) but the fill pass copy was stale — missing the closing tag space fixes, tag name space fixes, and attribute space fixes.

**Fix (3 parts):**

1. **Shared sanitization function** — Extracted all 12+ regex cleanup operations into `myls_ai_faqs_sanitize_raw_html()`. Both the main generation handler and the fill pass now call this single function, eliminating the sync problem permanently. New cleanup patterns include:
   - `< / p >` → `</p>` (space between `<` and `/`)
   - `< h 3 >` → `<h3>` (space inside tag name)
   - `href = "url"` → `href="url"` (spaces around `=`)
   - `. / p >` → `.</p>` (missing `<` on closing tags after punctuation)

2. **Expanded per-FAQ validator** — Rewrote the leaked HTML detection with 5 distinct pattern checks:
   - `leaked_html_tag` — Tag names + `>` as text (now covers ALL tags, not just strong/em/div/span)
   - `leaked_html_attr` — Raw `href="https://..."` appearing as visible text
   - `leaked_html_tags_multiple` — Two+ consecutive tag fragments (definitive broken HTML)
   - `malformed_html_tag` — Wrong bracket closings (`</a]`)
   - `escaped_html_in_answer` — HTML entities (`&lt;h3&gt;`) in answer_html

3. **Hardened fill prompt** — Added explicit HTML formatting section with tag integrity rules, proper FAQ structure template including `<ul>` lists, no-nesting rule, and examples of correct vs. incorrect tag syntax.

**Files changed:** `inc/ajax/ai-faqs.php`

## 6.3.2.5 — 2026-02-21

### Improved: FAQ AJAX Error Diagnostics

**Problem:** When FAQ generation failed due to server timeout or other HTTP errors, the batch log showed only "Bad JSON response" — giving no indication of the root cause. The Brandon, FL failure (5-minute timeout / 300s) was indistinguishable from a PHP fatal error or memory issue.

**Fix:** Rewrote `postAJAX()` in `myls-ai-faqs.js` to capture the raw response text before attempting JSON parse. On failure, it now provides specific messages based on HTTP status:
- **504/524** → "Server timeout — try reducing batch size or increasing max_execution_time"
- **502/503** → "Server unavailable — try again in a moment"
- **500** → "Server error — check PHP error logs"
- **Other** → Shows HTTP status + first 200 chars of response body for debugging

**Files changed:** `assets/js/myls-ai-faqs.js`

## 6.3.2.4 — 2026-02-21

### Fixed: Metabox AI Buttons Locked to OpenAI Provider

**Problem:** The AI generation buttons in the post editor metaboxes (HTML Excerpt, Service Tagline, FAQ Generator) only checked for the OpenAI API key (`myls_openai_api_key`). Users with Anthropic configured as their provider saw the "Configure OpenAI API key" fallback message instead of the AI buttons, even though AI generation would work fine via the bulk admin tabs.

**Fix:**
- New `myls_ai_has_key()` helper function in `openai.php` — returns `true` if the active provider (OpenAI or Anthropic) has an API key configured
- All three metaboxes now use `myls_ai_has_key()` instead of directly checking `myls_openai_api_key`
- Updated UI text from "Configure OpenAI API key" to "Configure AI API key" for provider-agnostic messaging
- Includes graceful fallback to the old OpenAI-only check if `myls_ai_has_key()` isn't available yet

**Files changed:** `inc/openai.php`, `inc/metaboxes/html-excerpt.php`, `inc/metaboxes/service-tagline.php`, `inc/metaboxes/ai-faq-generator.php`

## 6.3.2.3 — 2026-02-21

### Fixed: FAQ Generation — Keyword Soup, Leaked HTML Tags & Garbled Text Handling

**Problem 1 — Keyword soup & leaked HTML:** Some AI-generated FAQ answers contained two quality issues that slipped past validation:
1. **Leaked HTML tag names as text** — The AI occasionally output malformed nested tags (e.g. `<strong>Answer: <strong>text</strong></strong>`) which, after `wp_kses` processing, left the inner tag name as visible text: `Answer : strong >`.
2. **Keyword soup content** — Answers strung keywords together without proper grammar, articles, or sentence structure (e.g. "precision dedication adherence highest standards customer care every project").

**Fix (3 layers):**
- **Raw output sanitizer** — New regex cleanup catches `Answer : strong >` patterns and either fixes them to proper `<strong>Answer:</strong>` or strips the leaked tag name. Also fixes malformed closing tags where the AI uses wrong bracket characters (`</a]`, `</a)`, `</a}` → `</a>`). Applied in both main generation and fill pass.
- **Per-FAQ validator** — Three new checks in `myls_ai_faq_validate_pair()`:
  - `leaked_html_tag` — Detects HTML tag names (strong, em, div, etc.) appearing as visible text followed by `>` or `&gt;`
  - `malformed_html_tag` — Catches broken tags with wrong brackets like `</a]` or `&lt;/a)` that survived sanitization
  - `keyword_soup` — Measures function-word ratio (articles, prepositions, verbs); normal English prose is ~35-55% function words, keyword soup drops below 15%. FAQs failing this check are dropped and replaced via the fill pass.
- **Prompt hardening** — Added explicit "WRITING QUALITY" section forbidding keyword-strung content with good/bad examples, plus a no-nesting rule for HTML tags.

**Problem 2 — Garbled text rejecting entire batch:** The global `garbled_text` check in `myls_ai_faqs_validate_output()` scanned the entire HTML output for 60+ character strings without spaces. When the AI produced garbled text in even one FAQ, the entire batch was rejected — wasting good FAQs and burning all 3 retry attempts.

**Fix:** Converted the global garbled_text check from a hard rejection to a **warning log**. The per-FAQ validator (`myls_ai_faq_validate_pair`) already catches garbled text at the individual FAQ level (40+ chars without spaces). Now: bad FAQs get dropped individually while good ones survive, and the fill pass generates replacements for the dropped ones.

**Problem 3 — Malformed closing tags in contact links:** The AI occasionally output `</a]` (square bracket instead of angle bracket) for closing anchor tags, resulting in visible `Contact us</a]` text on the frontend.

**Fix:** New regex sanitizer converts wrong-bracket closings (`</a]`, `</a)`, `</a}`) to proper `</a>`. Per-FAQ validator also catches any escaped variants (`&lt;/a]`) that survive the regex pass.

## 6.3.2.2 — 2026-02-21

### Fixed: Excerpt & HTML Excerpt Batch AJAX Errors ("AJAX error on chunk")

**Problem:** Batch excerpt generation failed with "AJAX error on chunk. Continuing..." for all posts. Root cause: chunk size of 5 posts per AJAX request caused PHP to make 5 sequential AI calls (10–15 sec each = 50–75 sec total), exceeding the server's `max_execution_time` (typically 30s). The server killed the PHP process mid-execution, returning HTTP 500.

**Fix:** Changed both Excerpts and HTML Excerpts from `CHUNK = 5` to `CHUNK = 1` — each AJAX request now processes a single post, matching the About Area and FAQs pattern. This eliminates server timeout risk while maintaining the same progress UI.

Meta titles/descriptions keep `CHUNK = 5` because those AI calls are fast (2–3s each, well within timeout).

**Additional fixes:**
- AJAX `.fail()` handlers now log actual HTTP status code and response text instead of generic "AJAX error on chunk" — makes debugging much easier
- Fixed `$post_id` scope issue in `myls_ai_generate_excerpt_text()` — usage context was referencing an undefined variable inside the function; moved context setting to the loop caller where `$pid` is in scope
- Same fix applied to HTML excerpts — context now set properly in both single-post and bulk handlers

**Files:**
- `assets/js/myls-ai.js` — CHUNK=1 for excerpts/HTML excerpts, improved error reporting with HTTP status
- `inc/ajax/ai-excerpts.php` — Usage context with proper $pid in loop
- `inc/ajax/ai-html-excerpts.php` — Usage context with proper $pid/$post_id in both handlers

## 6.3.2.1 — 2026-02-21

### Rewritten: PDF Export for Results Terminals

**Problem:** jsPDF export looked terrible — box-drawing characters (━, ║, ═) rendered as blank/garbled, dark-on-dark background was muddy on paper, emoji icons missing, and monospace alignment was broken.

**Fix:** Completely replaced jsPDF with a **print-friendly HTML window** approach:
- Opens a clean popup window with parsed, color-coded log output on a white background
- Auto-triggers the browser's Print dialog → "Save as PDF" for pixel-perfect output
- **Zero CDN dependencies** — no more lazy-loading jsPDF from Cloudflare
- Full Unicode support: all box-drawing chars, emoji, and special symbols render perfectly
- Log lines classified and color-coded: section headers (dark banner), sub-headers (blue), success (green), errors (red), warnings (amber), skipped (gray italic), detail rows (muted)
- Separator lines (━━━) converted to clean CSS borders instead of garbled characters
- Section headers (`━━━ [1/10] Post #123...`) render as dark banners with white text
- Banner lines (╔, ║, ╚) get blue left-border accent
- Print CSS with `page-break-inside: avoid` keeps entries together across pages
- Sticky toolbar with "🖨 Print / Save as PDF" and "📋 Copy All" buttons
- Header shows tab name, export timestamp, and site name
- Footer with plugin credit and timestamp
- Responsive: works in any browser's print engine

**Before (jsPDF):** Garbled characters, missing emoji, dark unreadable background, broken alignment
**After (HTML print):** Clean white layout, perfect Unicode, color-coded sections, professional output

**File:** `assets/js/myls-export-log.js` — Full rewrite (203 lines, down from 158)

## 6.3.2.0 — 2026-02-21

### Fixed: Ctrl+A in Results Terminals Selects Entire Page

**Problem:** Pressing Ctrl+A (or Cmd+A on Mac) while focused on any results terminal (`<pre class="myls-results-terminal">`) selected the entire browser page instead of just the terminal contents.

**Fix:** Added a global delegated `keydown` handler in `myls-ai.js` that intercepts Ctrl/Cmd+A on all `.myls-results-terminal` elements and uses `Range.selectNodeContents()` to select only the terminal text. All 8 results terminals across Meta, Excerpts, HTML Excerpts, About Area, FAQs, GEO, Page Builder, and Content Analyzer are covered.

- Terminals get `tabindex="0"` so they can receive keyboard focus
- Blue outline on focus (`outline: 2px solid #2271b1`) so users see when the terminal is active
- Click inside the terminal → Ctrl+A → only terminal text selected

**Files:**
- `assets/js/myls-ai.js` — Global Ctrl+A handler for `.myls-results-terminal`
- `assets/css/admin.css` — Focus outline style

## 6.3.1.9 — 2026-02-21

### Improved: About the Area — Live Progress Text During Batch Generation

**Problem:** About the Area generation showed only "Working…" during batch runs with no indication of which post was being processed or how far along the batch was.

**Fix:** Added rich progress display matching the Meta subtab pattern:
- Status text now shows: "Processing 3 of 228 (1%) — Roof Repair in Tampa…"
- Post title displayed alongside progress counter
- Percentage indicator
- Progress clears properly on stop/completion
- Stop button styled red with ⏹ icon for consistency

**Note:** About Area and FAQs already process 1-post-at-a-time (no browser freeze). FAQs already had `setProgress()` with "Processing X/Y (Z%) — Title" display. The gap was About Area only.

**Files:**
- `admin/tabs/ai/subtab-about-area.php` — Added progress span, styled stop button
- `assets/js/myls-ai-about.js` — Added `setProgress()`, title lookup from select options, rich status during loop

## 6.3.1.8 — 2026-02-21

### New: AIntelligize Stats — AI Usage Analytics Dashboard

Comprehensive AI analytics dashboard accessible from **AIntelligize → AIntelligize Stats** submenu.

**Dashboard Features (4 Tabs):**

**◎ Overview Tab:**
- 5 KPI cards: Total AI Calls, Total Cost, Total Tokens, Avg Response Time, Success Rate
- Dual-axis timeline chart (calls + cost over time)
- Model usage doughnut chart
- Content coverage progress bars (SEO Titles, Meta Descriptions, WP Excerpts, HTML Excerpts with % of total)
- AI configuration summary (provider, model, API keys, custom prompts, plugin version)
- Published content breakdown by post type

**💰 Cost Analysis Tab:**
- Cost KPIs: Total Spent, Cost per Call, Projected Monthly, Input/Output token ratio
- Daily cost trend with cumulative overlay (dual axis)
- Cost by handler doughnut chart
- Full cost breakdown table by handler (calls, tokens, successes, errors, avg duration, cost)
- Cost by model table (per-call cost calculation)

**⚙ Handlers Tab:**
- Handler KPIs: Active Handlers, Total Calls, Top Handler by cost
- Horizontal bar chart: calls by handler
- Hourly heatmap: call volume by hour (business hours highlighted)
- Ranked handler performance list with call %, avg duration, error count, cost

**📋 Activity Log Tab:**
- Full sortable table of recent AI calls with: timestamp, handler, model, provider, post ID (linked), tokens, cost, duration, status
- Error rows highlighted red with error messages
- Data management: record count + purge button (older than 90 days)

**Period Selector:** 7d / 14d / 30d / 90d across all tabs

**Backend Architecture:**
- `MYLS_AI_Usage_Logger` class (`inc/class-ai-usage-logger.php`):
  - Auto-creates `{prefix}_myls_ai_usage_log` DB table on activation
  - Logs every AI call with: handler, model, provider, post_id, prompt/output chars, token estimates, cost estimates, duration, status, error messages, batch_id
  - Built-in cost estimation for OpenAI (GPT-4o, GPT-4o Mini, GPT-4, GPT-3.5) and Anthropic (Claude Sonnet 4, Haiku 4.5, Opus 4)
  - Content coverage queries (scans Yoast meta, excerpts across published posts)
  - AI config summary (provider, model, API keys, custom prompts)
  - Data purge by age

- Automatic logging via `myls_ai_chat()` hook — every AI call is captured with timing, context, and cost
- `myls_ai_set_usage_context()` helper for handlers to identify themselves
- Context automatically set for: Meta Titles, Meta Descriptions, Excerpts, HTML Excerpts, Taglines, Page Builder
- Handlers using `myls_openai_complete()` with context (About Area, FAQs, GEO, LLMS.txt) auto-mapped

- 6 AJAX endpoints with nonce security: overview, timeline, by_handler, by_model, hourly, recent, purge
- Chart.js 4.4.1 from CDN for all visualizations
- Dashboard CSS with full variable system matching plugin design language

**Files Added:**
- `inc/class-ai-usage-logger.php` — Logger class + DB table + query helpers
- `admin/admin-stats-menu.php` — Submenu registration + AJAX endpoints
- `admin/stats/stats-dashboard.js` — Full client-side dashboard (Chart.js)
- `admin/stats/stats-dashboard.css` — Dashboard styles

**Files Modified:**
- `my-local-seo.php` — Logger include, context helper function, stats menu include
- `inc/openai.php` — Auto-logging in `myls_ai_chat()`, context mapping in `myls_openai_complete()`
- `inc/ajax/ai.php` — Meta handler usage context
- `inc/ajax/ai-excerpts.php` — Excerpt handler usage context
- `inc/ajax/ai-html-excerpts.php` — HTML excerpt handler usage context
- `inc/ajax/ai-taglines.php` — Taglines handler usage context
- `inc/ajax/ai-page-builder.php` — Page builder handler usage context

## 6.3.1.7 — 2026-02-21

### Fixed: Browser Hangs on Large Batch AI Generation (Meta/Excerpts/HTML Excerpts)

**Problem:** Generating meta titles/descriptions, excerpts, or HTML excerpts for 200+ posts sent ALL post IDs in a single AJAX request. PHP then made sequential API calls for every post before returning anything — the browser sat unresponsive for 5-15+ minutes with no progress feedback and no way to stop.

**Fix:** All three handlers now use client-side chunking (5 posts per AJAX call):
- **Meta Titles/Descriptions** (`runGenerate()`): Chunked with live progress counter ("Processing 6–10 of 228…"), Stop button, and 50ms yield between chunks for browser responsiveness
- **WP Excerpts** (`$exGen` handler): Same chunked pattern with progress in button text
- **HTML Excerpts** (`$hexGen` handler): Same chunked pattern

**New UI elements:**
- Red "⏹ Stop" button on Meta subtab (appears during generation, hidden otherwise)
- Progress indicator showing current chunk range
- "Generate Both" respects stop — if stopped during titles, descriptions phase is skipped

**Technical details:**
- Chunk size: 5 posts per AJAX call (tuned for AI API response times)
- `setTimeout(fn, 50)` between chunks yields to browser event loop
- Each chunk returns immediately with results — log renders incrementally
- PHP handlers unchanged — they already loop over the `ids` array they receive
- Stop flag checked between chunks, not mid-request (current chunk completes)

**Files:**
- `admin/tabs/ai/subtab-meta.php` — Stop button + progress span
- `assets/js/myls-ai.js` — Chunked `runGenerate()`, excerpt handler, HTML excerpt handler

## 6.3.1.6 — 2026-02-21

### Fixed: About the Area Log Header Showed Hardcoded "gpt-4o"

The batch log header for About the Area displayed `Model: gpt-4o` regardless of which AI provider was configured. This was a hardcoded string in `myls-ai-about.js` line 73.

**Fix:**
- `subtab-about-area.php`: Added `model` key to `MYLS_AI_ABOUT` JS config, reading from `myls_ai_get_default_model()`
- `myls-ai-about.js`: Replaced `model: 'gpt-4o'` with `model: CFG.model || 'default'`

The per-item log already showed the correct resolved model (e.g., `claude-haiku-4-5-20251001`) — this fix aligns the batch header to match.

## 6.3.1.5 — 2026-02-21

### Fixed: Empty Model String Causes API 400 Error

**Root Cause:** When `myls_openai_model` DB option is empty (common after fresh install or if user hasn't selected a model), the excerpt handler passed `'model' => ''` to `myls_ai_chat()`. This flowed through to `myls_anthropic_chat()` where `$args['model'] ?? 'claude-sonnet-4-20250514'` did NOT trigger the fallback — PHP's `??` only catches `null`, not empty string. The Anthropic API then rejected the request: `model: String should have at least 1 character`.

**Fix:** `myls_ai_chat()` now sanitizes the model argument — if empty/whitespace-only, it `unset()`s `$args['model']` before passing to the provider function, allowing the downstream `??` defaults to work correctly. This fixes all handlers that call `myls_ai_chat()` directly (excerpts, HTML excerpts, taglines).

**Files:** `inc/openai.php` — `myls_ai_chat()` model sanitization + resolved_model tracking

## 6.3.1.4 — 2026-02-21

### New: Prompt Reset Utility (Utilities → Prompt Reset)
- New subtab under Utilities tab with status table showing all 11 prompt templates
- Each row shows handler name, default file, and live status (Custom/Factory Default)
- "Reset All Prompts to Factory Defaults" button deletes all saved prompt options from DB
- AJAX handler with confirmation dialog, real-time feedback, and auto-refresh
- Option keys reset: `myls_ai_prompt_title`, `myls_ai_prompt_desc`, `myls_ai_prompt_excerpt`, `myls_ai_prompt_html_excerpt`, `myls_ai_about_prompt_template`, `myls_ai_faqs_prompt_template`, `myls_ai_faqs_prompt_template_v2`, `myls_ai_geo_prompt_template`, `myls_pb_prompt_template`, `myls_ai_taglines_prompt_template`, `myls_ai_llms_txt_prompt_template`

### Rewritten: All 11 Prompt Templates
Complete rewrite of every prompt file in `assets/prompts/`:

**about-area.txt** — Added 6 structural patterns (A–F: geography/climate/community/history/lifestyle/infrastructure), anti-duplication rules for batch runs, banned opener list, strong single-output enforcement

**about-area-retry.txt** — Aligned with main prompt structure, added explicit "all sections mandatory" with HTML structure template, matching banned phrases

**excerpt.txt** (done in 6.3.1.3) — 6 structural patterns, `{content_snippet}` + `{city_state}` tokens, 16 banned phrases

**html-excerpt.txt** (done in 6.3.1.3) — 6 structural patterns, card-context awareness, CTA rotation pool, 19 banned phrases

**faqs-builder.txt** — Added anti-duplication rules (vary interrogatives, answer lengths, "Helpful next step" phrasing, list type mix), stronger output enforcement, start-with-h2 instruction

**geo-rewrite.txt** — Added AI citation purpose statement, full jump links section, `<ol>` for How It Works steps, expanded Common Questions to 3–5 with quotability guidance, anti-duplication between Key Facts and What You Get

**meta-title.txt** (done in 6.3.0.8) — 6 structural patterns (A–F), 90–120 char guidance, anti-duplication, banned openers

**meta-description.txt** (done in 6.3.0.8) — 3-element formula (WHAT/WHY/NEXT STEP), 6 opening varieties, CTA rotation pool, banned phrases

**page-builder.txt** — Detailed 6-section structure spec (hero, overview, benefit cards, how-it-works, FAQ accordion, bottom CTA), Bootstrap icon/color requirements, content rules against fabrication, banned phrases

**taglines.txt** — 4 structural patterns (A–D: benefit+trust, service+differentiator, outcome+credential, problem+solution), requirement for different pattern per tagline, banned phrases

**llms-txt.txt** — AI citation optimization framing, independently-quotable fact-statement guidance, search intent coverage section expanded to 8–12 queries with question formats, anti-duplication rules for multi-city batches

### Fixed: All AJAX Handlers Fall Back to Factory Defaults
After a prompt reset (or fresh install), every handler now chains: POST → DB option → `myls_get_default_prompt('file-key')` → hardcoded fallback. Previously, WP Excerpt, GEO, FAQs, Taglines, and Meta handlers would error with "missing template" or "empty prompt" when the DB option was missing.

Files patched: `ai-excerpts.php`, `ai-geo.php` (both handlers), `ai-faqs.php`, `ai-taglines.php`, `ai.php` (meta + about area)

## 6.3.1.3 — 2026-02-21

### Rewritten: Excerpt Prompt Templates (WP + HTML)

**WP Excerpt (`excerpt.txt`)**
- 6 structural patterns (A–F): service-forward, audience-forward, location-forward, benefit-forward, problem-forward, credential-forward
- 16 banned cliché phrases
- Anti-duplication rules: different opening word per batch item, varied sentence rhythm
- New `{content_snippet}` token (first 200 words of page content via page builder compat)
- New `{city_state}` token (was missing from WP excerpt handler)
- Strong single-output enforcement matching meta prompt hardening

**HTML Excerpt (`html-excerpt.txt`)**
- 6 structural patterns (A–F): service-benefit, location-service, problem-solution, credential-trust, outcome-first, specificity-lead
- Card context awareness: front-load keywords, bold key terms, scannable sentences
- CTA rotation pool (8 phrases): "Learn about our process", "See what sets us apart", etc.
- 19 banned cliché phrases (includes CTA clichés: "Don't hesitate", "Contact us today", "Call now")
- Anti-duplication: different opening word, different CTA phrase, different bold target per card
- New `{content_snippet}` token

### Updated: Variation Engine (excerpt + html_excerpt contexts)
- Expanded excerpt angles from 4 to 6 (matching prompt patterns A–F)
- Expanded html_excerpt angles from 4 to 6 (matching prompt patterns A–F)
- Added 16 banned phrases for `excerpt` context
- Added 19 banned phrases for `html_excerpt` context
- New medium-form rules in `inject_variation()` — excerpts now get tailored variation instructions (not the paragraph-level rules meant for about areas, not the single-line rules meant for meta titles)

### Updated: Excerpt AJAX Handlers
- **WP excerpt handler** (`ai-excerpts.php`):
  - Added `{content_snippet}` token — first 200 words via `myls_get_post_plain_text()` with page builder fallback
  - Added `{city_state}` token with multi-key fallback (get_field → city_state meta → _myls_city meta)
  - Added error diagnostics: shows API error, provider, model on empty output
  - Added `myls_clean_meta_output()` cleanup to strip AI commentary/options
  - Added old/new values to results for log display
- **HTML excerpt handler** (`ai-html-excerpts.php`):
  - Added `{content_snippet}` token to `myls_ai_build_html_excerpt_prompt()`
  - Added `_myls_city` fallback for city_state resolution
  - Added VE angle injection to bulk loop (was missing — only single handler had it)
  - Added error diagnostics matching WP excerpt handler
  - Added old/new values to results for log display
- **Subtab UI**: Updated placeholder documentation for both columns

## 6.3.1.2 — 2026-02-21

### New: Meta Bulk Editor CSV Export
- Added "Export to CSV" button above the meta editor table
- Exports ALL rows across all pages (paginates through 100 at a time via AJAX)
- CSV includes: ID, Post Title, Yoast Title, Yoast Description, Focus Keyword
- Filename includes post type and date: `meta-editor-page-2026-02-21.csv`
- BOM prefix for proper Excel/Sheets Unicode handling
- Respects current search filter

## 6.3.1.1 — 2026-02-21

### New: Generate Both Button
- Added "Generate Both for Selected" button on AI → Meta subtab
- Runs titles first, then descriptions sequentially on the same selected posts
- Results log shows both batches: titles batch with summary, separator, then descriptions batch with summary
- All three buttons disabled during generation to prevent overlap

## 6.3.1.0 — 2026-02-21

### Bugfix: AI Meta Generation Cleanup
- **CRITICAL FIX:** Regex patterns in `myls_clean_meta_output()` had broken flag concatenation (`'/pattern/i' . '.*$/s'` → invalid regex). `preg_replace` returned `null`, silently destroying all valid API output.
- Fixed all 13 newline-based cut patterns — flags now self-contained: `'/pattern.*$/is'`
- Added null-safety on all `preg_replace` calls (keeps previous value if regex fails)

### Bugfix: Reload Default Prompt Button
- Button now shows "Loading..." state during AJAX call
- Green flash on textarea confirms successful load
- Fires `input` and `change` events so watchers know value changed
- Specific error messages with console logging on failure

### New: Error Diagnostics in Results Log
- Empty API output now shows WHY: API error details (HTTP code, error message, missing key) or cleanup diagnostic (raw output chars + first 200 chars of stripped content)
- `openai.php` stores last error in `$GLOBALS['myls_ai_last_error']` for all failure paths
- New "Meta Output" section in log formatter shows old → new values with character counts

### New: Robust Meta Output Cleanup
- `myls_clean_meta_output()` function extracts single meta value from verbose AI responses
- Inline truncation: 15 needle patterns catch single-line multi-option output (`" Or alternative"`, `" **Option 2"`, etc.)
- Newline truncation: 13 regex patterns catch multiline options/commentary
- Line-skip filter: skips label/commentary lines, grabs first meaningful content line
- Artifact stripping: `#` headings, `**bold**`, `"quotes"`, `Title:` labels, `(95 chars)` notes

### Improved: Meta Prompt Templates
- Title minimum changed from 70 to 90 characters
- Output instruction strengthened: "Respond with ONLY the title tag text — nothing else. No options, no alternatives, no explanations, no markdown formatting, no numbering, no commentary."
- Same reinforcement applied to description template

## 6.3.0.8 — 2026-02-21

### New: Universal Page Builder Content Extraction
- Centralized `inc/page-builder-compat.php` (340 lines) with two public API functions:
  - `myls_get_post_plain_text( $post_id, $max_words )` — clean text for prompts
  - `myls_get_post_html( $post_id )` — HTML for analysis
- **Elementor:** Parses `_elementor_data` JSON, recursively walks widget tree, extracts text from editor/title/description/text/html/content/tab_content keys, handles repeater fields
- **DIVI / WPBakery:** `myls_strip_shortcode_tags()` strips `[shortcode]` brackets while preserving inner content (unlike WordPress `strip_shortcodes()` which destroys content)
- **Beaver Builder:** Parses `_fl_builder_data` serialized array, extracts from module settings
- Builder detection: `myls_detect_page_builder()` returns `'elementor'|'divi'|'beaver_builder'|'wpbakery'|'gutenberg'|'classic'`
- 11 files updated to use centralized utility with `function_exists()` fallback

### New: Rewritten SEO Meta Prompt Templates
- 6 structural patterns per context (title: A–F service-location-brand through location-led; description: A–F service-forward through specificity-forward)
- Comprehensive banned phrase lists (11 title openers, 13 description clichés)
- Variation Engine updated: new angle arrays, expanded banned phrases, context-aware injection (short-form vs long-form rules)
- Batch anti-duplication: each item MUST open with different word, no repeated adjective-noun pairings, CTA rotation pool

## 6.3.0.5 — 2026-02-20

### New: AI-Powered llms.txt Generation
- AI-generated llms.txt with city-specific content and service area organization
- llms-full.txt endpoint with comprehensive hierarchical structure
- Parent cities → child service+city combinations
- Elementor page builder content extraction for source material
- Enterprise logging with quality metrics and cost tracking

## 6.0.0 — 2026-02-18

### New Feature: AI Page Builder
- Added **Page Builder** subtab under AI tab for creating pages/posts with AI-generated content
- Supports all public post types (pages, posts, services, service areas, and any registered CPT)
- Description/Instructions field for detailed AI context (features, audience, tone, structure)
- Customizable AI prompt template with token support ({{PAGE_TITLE}}, {{DESCRIPTION}}, {{BUSINESS_NAME}}, etc.)
- Save/Reset prompt templates across sessions
- Post status selection (Draft/Publish) and Add to Main Menu option
- Business variables auto-filled from Site Builder settings, editable per-session
- Standalone AJAX handler — no dependency on Site Builder enabled state
- Upsert logic prevents duplicate pages on re-run
- Auto-sets Yoast SEO title and meta description
- Edit link appears in results log after page creation

## 5.0.0 — 2026-02-16

### New Feature: HTML Excerpt Editor & AI Generation
- **WYSIWYG metabox** for `html_excerpt` post meta on all public post types — full
  TinyMCE editor with Visual/Text tabs, trimmed toolbar (bold, italic, link, lists,
  headings), and live preview.
- **AI Generate button** in the editor metabox — pulls prompt template from plugin
  admin settings (`myls_ai_prompt_html_excerpt`), generates via OpenAI, and inserts
  into the WYSIWYG editor.
- **Admin AI tab → Excerpts subtab** now has 3-column layout:
  - Column 1: Select Posts (shared post selector)
  - Column 2: AI Actions (standard WP `post_excerpt` generation)
  - Column 3: HTML Excerpt Actions (new `html_excerpt` meta generation)
- Each column has its own prompt template, save/reset, and bulk generate buttons.
- New AJAX endpoints: `myls_ai_html_excerpt_generate_single`,
  `myls_ai_html_excerpt_save_prompt`, `myls_ai_html_excerpt_generate_bulk`.

### Updated: service_area_grid Shortcode
- **`show_page_title` attribute** (default: `1`) — renders the current page title as
  an H2 above the grid. Set `show_page_title="0"` to hide.
- **`show_title` fix** — boolean parsing now accepts `0/false/no` reliably (was strict
  `=== '1'` comparison that could fail depending on attribute format).
- Updated shortcode doc header with full attribute reference.

### Updated: service_grid Shortcode
- **Fixed duplicate tagline** — tagline was rendering both above and below the title.
  Removed the above-title `show_tagline` block; tagline now only appears below the
  title via the `subtext` logic. `show_tagline` attribute kept for backward compat.

### Updated: Shortcode Documentation
- Comprehensive shortcode-data.php rewrite covering all 30+ shortcodes across 6
  categories with full attributes, examples, and tips.
- New shortcodes documented: `association_memberships`, `service_faq_page`,
  `service_area_roots_children`, `divi_child_posts`, `custom_service_cards`,
  `myls_card_grid`/`myls_flip_grid`, `channel_list`, `gmb_hours`, `county_name`,
  `acf_field`, `page_title`, `with_transcript`.
- **Interactive Shortcodes tab redesigned** — single-column accordion layout with
  persistent search, category pills, inline copy buttons, and collapsible detail
  sections. Replaces the previous 4-column card grid that was difficult to scan.

## 4.15.8 — 2026-02-16

### New Feature: Association Memberships
Manage professional association memberships (BBB, Chamber of Commerce, trade groups, etc.)
from the plugin admin and display them on the front end with valid structured data.

#### Admin UI (Schema → Organization)
- **Memberships repeater** — add/remove association entries with fields:
  Name (required), Association URL, Your Profile URL, Logo URL, Member Since year, Description.
- **Generate Memberships Page** card — creates/updates a WordPress page with the
  `[association_memberships]` shortcode. Configurable title, slug, and status.
- Data saved to `myls_org_memberships` option.

#### Schema
- **`memberOf` on Organization** — each membership is output as an `Organization` object
  in the `memberOf` array on the existing Organization schema node.
- **`memberOf` on LocalBusiness** — same `memberOf` array is injected into LocalBusiness
  schema via new `myls_lb_build_member_of()` helper.
- **Dedicated Memberships Page provider** (`inc/schema/providers/memberships-page.php`) —
  if the memberships page is not already assigned to Organization schema, outputs a
  lightweight Organization node with `memberOf` in the `@graph`.
- **LocalBusiness auto-assigned** to the generated page.

#### Shortcode: `[association_memberships]`
- Responsive logo grid card layout (2/3/4 columns, mobile-responsive).
- Each card shows: logo (linked), association name, "Member since" badge, description,
  and "View Our Profile" button linking to your profile on their site.
- Attributes: `title`, `columns`, `show_desc`, `show_since`, `link_text`, `card_bg`, `card_border`.
- H1 defaults to current post title (same pattern as `[service_faq_page]`).

#### Content Best Practices for Search & AI
- Descriptions explain what each membership *means* (not just the org name).
- Profile URLs create verifiable two-way link relationships.
- Logos with proper alt text for image search visibility.
- `memberOf` structured data feeds Google Knowledge Graph and AI systems
  (Gemini, ChatGPT, Perplexity) for entity credibility verification.

### Files
- **NEW:** `modules/shortcodes/association-memberships.php`
- **NEW:** `inc/ajax/generate-memberships-page.php`
- **NEW:** `inc/schema/providers/memberships-page.php`
- **Changed:** `admin/tabs/schema/subtab-organization.php` — memberships section + page generator
- **Changed:** `inc/schema/providers/organization.php` — `memberOf` injection
- **Changed:** `inc/schema/providers/localbusiness.php` — `memberOf` injection + helper
- **Changed:** `my-local-seo.php` — new includes

## 4.15.7 — 2026-02-16

### Shortcodes
- **`[service_faq_page]`** — H1 title now defaults to the current page/post title instead of
  a hardcoded "Service FAQs". Whatever you set as the Page Title in the admin card becomes both
  the WP post title and the H1 on the page. You can still override with `title="Custom Text"`
  or hide with `title=""`.

## 4.15.6 — 2026-02-16

### Bug Fix
- **FIX: AI FAQ Generator "Permission Denied" in post editor** — The AI FAQ Generator metabox
  was sending the `myls_ai_faq_gen` nonce to the generate endpoint (`myls_ai_faqs_generate_v1`),
  but that endpoint validates against `myls_ai_ops` via the shared `myls_ai_check_nonce()` helper.
  The metabox now creates and sends the correct `myls_ai_ops` nonce for the generate call, while
  continuing to use `myls_ai_faq_gen` for the save and clear endpoints (which verify it directly).
- File changed: `inc/metaboxes/ai-faq-generator.php`.

## 4.15.5 — 2026-02-16

### Schema → FAQ (Critical Fix)
- **FIX: FAQPage JSON-LD now outputs correctly** — Previous versions attempted to hook `wp_head`
  from inside the shortcode, but shortcodes execute during `the_content` (after `wp_head` has
  already fired), so schema was never output. Replaced with a dedicated schema provider at
  `inc/schema/providers/service-faq-page.php` that hooks `myls_schema_graph` and runs during
  `wp_head` via `registry.php` (priority 90).
- **FAQPage schema validates** — outputs `@type: FAQPage` with `@id`, `url`, `name`, and
  `mainEntity` array of `Question`/`Answer` pairs. All FAQ items are deduplicated (case-insensitive).
  Validates at schema.org and Google Rich Results Test.
- **LocalBusiness schema auto-assigned** — the generated Service FAQ Page is automatically assigned
  to LocalBusiness location #1 (via `_myls_lb_assigned` / `_myls_lb_loc_index` post meta), so
  both FAQPage and LocalBusiness JSON-LD appear in `<head>` on the same page.
- **No duplicate schema** — `providers/faq.php` guard skips the Service FAQ Page (provider handles
  its own FAQPage node); shortcode no longer attempts schema output.
- Shortcode `schema` attribute removed (no longer needed; provider handles it).

### Files
- **NEW:** `inc/schema/providers/service-faq-page.php` — dedicated FAQPage schema provider.
- **Changed:** `modules/shortcodes/service-faq-page.php` — HTML rendering only, schema removed.
- **Changed:** `inc/ajax/generate-service-faq-page.php` — auto-assigns LocalBusiness meta on page create/update.
- **Changed:** `my-local-seo.php` — includes new provider.

## 4.15.4 — 2026-02-16

### Schema → FAQ
- **FAQPage JSON-LD Schema** — the generated Service FAQ Page now outputs a valid `FAQPage` JSON-LD
  `<script type="application/ld+json">` block in `<head>` containing all aggregated, deduplicated FAQ items.
  Validates against Google's Rich Results Test / Schema.org spec. Includes `@context`, `@type`, `name`, `url`,
  and `mainEntity` array of `Question`/`Answer` pairs.
- **Deduplication** — duplicate questions across services are automatically removed (case-insensitive,
  first occurrence wins). Stats card now shows raw count, deduped count, and duplicates removed.
- **Page Slug field** — configurable slug/permalink for the generated page (default: `service-faqs`).
  Live preview of the full URL updates as you type.
- **Schema conflict guard** — `providers/faq.php` now explicitly skips the Service FAQ Page
  (shortcode handles its own FAQPage schema), preventing duplicate JSON-LD output.
- Admin card description updated to mention JSON-LD output and dedup behavior.
- AJAX response now returns `page_slug`, `dupes_removed` count, and updates the slug field with
  the actual saved slug (WordPress may sanitize/suffix it).

### Shortcodes
- **`[service_faq_page]`** — added `schema="1|0"` attribute to control JSON-LD output.
  Added `myls_collect_post_faqs()` and `myls_dedupe_faqs()` helper functions.

## 4.15.3 — 2026-02-16

### Schema → FAQ
- **NEW: Generate Service FAQ Page** — card added to the FAQ subtab under Schema.
  - Creates (or updates) a WordPress page that aggregates FAQs from all published Service posts.
  - Uses the dynamic `[service_faq_page]` shortcode — page always reflects current FAQ data without regeneration.
  - Configurable page title (default: "Service FAQs") and status (Published / Draft).
  - Shows live FAQ stats: total services, services with FAQs, total FAQ items.
  - View/Edit page links appear once the page exists.
  - AJAX-powered generation with spinner and success/error feedback.
  - New AJAX endpoint: `wp_ajax_myls_generate_service_faq_page` (file: `inc/ajax/generate-service-faq-page.php`).

### Shortcodes
- **NEW: `[service_faq_page]`** — renders all Service post FAQs on a single page.
  - H3 heading per service, Bootstrap 5 accordion for each service's FAQs.
  - Services ordered by menu_order (ASC) by default.
  - Shows "No FAQs available" message for services without FAQ items.
  - Supports per-instance color overrides: `btn_bg`, `btn_color`, `heading_color`.
  - Supports `orderby`, `order`, `show_empty`, `empty_message` attributes.
  - Reuses plugin's existing accordion CSS (`myls-accordion.min.css`).
  - Falls back to legacy ACF repeater fields when native MYLS FAQ meta is empty.
  - File: `modules/shortcodes/service-faq-page.php`.

## 4.15.0 — 2026-02-15

### Schema → Person
- **NEW: LinkedIn Import** — AI-powered profile extraction from pasted LinkedIn content.
  - Paste visible text (Ctrl+A → Ctrl+C from the profile page) for quick import.
  - Advanced toggle: paste raw HTML page source for richer structured data extraction (JSON-LD, OG tags, noscript content).
  - AI extracts: name, job title, bio, education, credentials, expertise topics (with Wikidata/Wikipedia linking), memberships, awards, languages, and social profile URLs.
  - Target selector to populate any existing person card.
  - Uses the plugin's existing OpenAI integration — no additional API keys needed.
  - New AJAX endpoint: `wp_ajax_myls_person_import_linkedin` (file: `inc/ajax/ai-person-linkedin.php`).
- **NEW: Person Label** — display-only label field at the top of each person accordion (e.g. "Owner", "Dr. Smith").
  - Live-updates the accordion header as you type.
  - Stored in database but NOT included in schema output.
  - Accordion header now shows: Label (primary) + "Full Name · Job Title · X page(s)" (meta line).
- **NEW: Export to Fillable PDF** — generates a branded, fillable PDF form from any person profile.
  - Client-side generation via pdf-lib (lazy-loaded from CDN on first click).
  - Fillable text fields for all identity, bio, social, and URL fields.
  - Fillable checkbox for schema enabled/disabled status.
  - Composite sections (expertise, credentials, education, memberships) render as multi-column fillable grids (3 rows each).
  - Repeater sections (sameAs, awards, languages) render as numbered fillable rows (5 rows each).
  - Multi-line fillable textarea for bio/description.
  - Branded purple header bar with profile label badge.
  - Footer on every page with plugin name, generation date, and page numbers.
  - Page assignments excluded from PDF output.
  - Pre-populates with current form data — empty fields remain fillable blanks.
  - Downloads as `person-profile-{name}.pdf`.

### Internal
- Added `inc/ajax/ai-person-linkedin.php` — AJAX handler with HTML content extraction and AI-powered structured parsing.
- `myls_linkedin_extract_from_html()` — extracts OG tags, meta description, JSON-LD, noscript content, and visible text from pasted LinkedIn HTML.
- `myls_linkedin_sanitize_profile()` — sanitizes all AI-returned fields with WordPress sanitization functions.
- PDF export logic inlined in the Person subtab `<script>` block for reliable loading (no external JS dependency).
- Version bumped to 4.15.0 across plugin header and MYLS_VERSION constant.

---

## 4.12.0 — 2026-02-14

### Schema → Person
- **NEW: Person Schema Subtab** — full multi-person support with per-person page assignment.
  - Multi-person accordion UI with add/clone/remove functionality.
  - Per-person fields: name, job title, honorific prefix, bio, email, phone, photo, profile URL.
  - Social profiles (sameAs) repeater — LinkedIn, Facebook, X/Twitter, YouTube, Wikipedia, Wikidata, Crunchbase.
  - Areas of Expertise (knowsAbout) — composite repeater with Wikidata Q-ID and Wikipedia URL linking for AI citation.
  - Credentials & Licenses (hasCredential) — composite repeater with credential name, abbreviation, issuing org, issuer URL.
  - Education (alumniOf) — composite repeater with institution name and URL.
  - Memberships (memberOf) — composite repeater with organization name and URL.
  - Awards and Languages simple repeaters.
  - Per-person page assignment with checkbox list (pages, posts, services, service areas).
  - Per-person enable/disable toggle with visual Active/Disabled badge in accordion header.
  - Stored as `myls_person_profiles` option array.
  - JSON-LD output on assigned pages via schema graph.
  - worksFor automatically linked to Organization schema.
  - Pro Tips sidebar with E-E-A-T best practices.

---

## 4.6.32
- **CRITICAL FIX**: Increased default max_tokens from 1200 to 4000 - was causing FAQ generator to only produce 1-2 FAQs instead of the intended 10-15
- Fixed: Added context-specific token handling in OpenAI integration (`myls_openai_complete`) for 'faqs_generate' context with 4000 token fallback
- Improved: Added helpful UI guidance about token requirements (4000+ for LONG variant, 2500+ for SHORT variant)
- Improved: Better system prompt for FAQ generation context: "You are an expert local SEO copywriter. Generate clean, structured HTML for FAQ content."

## 4.6.31
- Fix: AI FAQ Generator now properly replaces `{{CITY_STATE}}` and `{{CONTACT_URL}}` template variables in prompts.
- Fix: City/state detection improved with multiple fallback meta keys (`_myls_city`, `city`, `_city`, `_myls_state`, `state`, `_state`).
- Fix: Temperature default now consistently uses 0.5 from saved options instead of hardcoded 0.4.
- Fix: Added `<ol>` tag support for ordered lists in generated FAQ HTML output.
- Improvement: Added filter hook `myls_ai_faqs_city_state` for custom city/state detection logic.

## 4.6.25
- Fix: Video ItemList JSON-LD now validates cleanly in schema.org by wrapping `ItemList` inside a `CollectionPage` and moving `publisher` + `dateModified` onto the page entity (where those properties are valid).

## 4.6.24
- Fix: Removed a legacy Utilities migration stub file that echoed HTML at include-time, which could bleed into the admin header area.

## 4.6.23
- Fix: YouTube Channel List per-page ItemList schema now reliably outputs `uploadDate` for each VideoObject.
- Improvement: Normalizes helper date keys (`date`/`publishedAt`/`uploadDate`) and adds safe fallbacks (local post meta → YouTube API cached → WP post date).

## 4.6.20
- Fix: AI generation wrapper incorrectly called `myls_openai_complete()` directly (it's a filter callback), which could return the prompt unchanged and result in only 1 FAQ being inserted.
- Fix: Route AI requests through the `myls_ai_complete` filter so max_tokens/temperature/model settings are honored and LONG/SHORT variants generate properly.

## 4.6.19
- Fix: Utilities → FAQ Quick Editor file scoping bug that could fatally error on some installs (docx helper functions were accidentally wrapped inside the sanitize_items conditional).
- Fix: AI → FAQs Builder LONG variant now reliably uses the v2 multi-block prompt template (auto-migrates legacy one-line templates and auto-upgrades at generation time when LONG is selected).

## 4.6.18
- Fix Utilities → FAQ Quick Editor batch save deletion flag handling and JSON unslashing.

## 4.6.17 — 2026-02-04
- Fix: MYLS delete-auto now normalizes question/answer text exactly like the insert routine (whitespace + nbsp handling), so stored AI hashes match and rows actually delete.

## 4.6.16 — 2026-02-04
- Fix: FAQ editor metabox delete checkbox can now be intentionally checked (even on non-empty rows).
- Fix: AI → FAQs Builder now correctly parses the new multi-block FAQ output (h3 + paragraphs + bullets) and inserts into MYLS FAQs.
- Fix: Auto-delete of AI-inserted FAQs now uses the same hashing strategy as insert (requires the 4.6.17 normalization patch for whitespace edge cases).

## 4.6.15 — 2026-02-04
- AI → FAQs Builder: upgraded the default FAQ prompt to produce longer, more complete answers tuned for AI Overviews.
- Adds LONG/SHORT variants with a Variant selector, and supports the {{VARIANT}} placeholder in prompt templates.
- Answers now follow a multi-block structure per FAQ (h3 + paragraphs + bullets + “Helpful next step”) for better scannability and completeness.

## 4.6.14 — 2026-02-03
- Add Certifications list to Organization schema UI and output as hasCertification on Organization and LocalBusiness.
- Fix Organization provider to output award and hasCertification.

# AIntelligize — Changelog


## 4.6.13 — 2026-02-03

### Schema → Organization
- Added **Awards** list to the Organization tab.
- Outputs awards on **Organization** and **LocalBusiness** schema as Schema.org-valid `award` strings.


## 4.6.12 — 2026-02-03

### Schema → About Us
- Added a new **About Us** schema subtab under **Schema**.
- Includes an **About page selector** (outputs only on the selected page).
- Added optional overrides: **Headline/Name**, **Description**, **Primary Image URL**.
- Outputs **AboutPage + WebSite** JSON-LD via the schema graph (validates with Schema.org).


## 4.6.11 — 2026-02-03

### AI → FAQs Builder
- Added a **Search** input under the Post Type dropdown to filter the loaded posts list (client-side by title or ID).


## 4.6.10 — 2026-02-03

### AI → FAQs Builder
- Fixed version mismatch in plugin header/constants.
- Fixed batch workflow so **each selected post** can be processed in sequence with **auto-insert into MYLS FAQs**.
- Preview window now **appends** output per post and starts each section with the **post title + ID**.
- Added **Skip posts with existing MYLS FAQs** checkbox (pre-checks before generating to avoid wasting AI calls).


## 4.6.8 — 2026-02-03

### Admin Bar
- Updated **SEO Stuff** admin-bar menu: added **Schema.org Validator** link and improved **Google index check** (live status dot + Google site: results link).

## 4.6.7 — 2026-02-02

### AI Discovery
- Enhanced **/llms.txt** to be more comprehensive:
  - Added **Business details** section (prefers Organization settings, falls back to LocalBusiness location #1)
  - Master FAQ links now point to **page + stable anchor** (e.g. `#faq-3`)

### Shortcodes
- **FAQ Accordion**: Added stable per-question anchors (`#faq-1`, `#faq-2`, ...) so other systems can deep-link to specific questions.

### Utilities
- **Utilities → llms.txt**: Added toggle for including Business details.

## 4.6.6 — 2026-02-02

### AI Discovery
- Expanded **/llms.txt** with high-signal sections:
  - Primary services (from the `service` CPT)
  - Service areas (from the `service_area` CPT)
  - Master FAQ links (from MYLS FAQ post meta `_myls_faq_items`)

### Utilities
- Added **Utilities → llms.txt** settings subtab:
  - Enable/disable endpoint
  - Toggle sections (services, service areas, FAQs)
  - Per-section limits

## 4.6.5 — 2026-02-02

### AI Discovery
- Added first-pass support for serving **/llms.txt** at the site root (Markdown, plain text) via rewrite + template redirect.
- Includes basic, high-value links (Home, Contact if found, Sitemap, Robots) and exposes a filter (`myls_llms_txt_content`) for future expansion.

## 4.6.4 — 2026-01-29

### Divi
- Fixed Divi Builder preview showing raw `<script>` contents as visible text by stripping scripts **only in builder contexts** (front-end output unchanged).

## 4.6.3 — 2026-01-29

### Divi
- Restored Divi Builder module: **FAQ Schema Accordion** (`modules/divi/faq-accordion.php`).
- Updated loader so the module registers reliably in the Divi Builder (Visual Builder + backend).

## 4.6.2 — 2026-01-29

### Docs
- Added **Docs → Release Notes** tab (renders `CHANGELOG.md` inside the plugin).
- Added optional **Append Release Notes** form (writes to `CHANGELOG.md` when writable, otherwise queues entries in WP options).

### Updates
- Added `readme.txt` with **Upgrade Notice** section so update systems can display upgrade notes.

## 4.6.1 — 2026-01-29

### Utilities → FAQ Quick Editor
- Added true **batch save** for multi-selected posts (edit multiple posts’ FAQs on one screen, then save all at once).
- Switched Answer inputs to WordPress **WYSIWYG** (TinyMCE/Quicktags via `wp.editor.initialize`).
- Added batch `.docx` export for all selected posts (combined document).

## 4.6.0 — 2026-01-29

### Utilities
- Added Utilities subtabs (auto-discovered from `admin/tabs/utilities/subtab-*.php`).
- Moved the existing ACF → MYLS FAQ migration/cleanup screen into **Utilities → FAQ Migration**.
- Added **Utilities → FAQ Quick Editor**:
  - All public post types supported.
  - Post type selector + search filter + post multi-select.
  - MYLS FAQ repeater editor (native `_myls_faq_items`) with Add Row + Save.
  - Export current post’s FAQs to `.docx` (title + formatted Q/A list).

### AI → FAQs Builder
- Updated insert success UX to display a count (example: `14 items inserted`).

---

## 4.5.10
- Baseline version (uploaded working build).

## [7.7.6] — 2026-03-02

### Changed
- **Elementor Builder — Unified Page Sections panel** replacing separate "Generated Sections" + "Append Elementor Templates" cards with a single drag-and-drop ordered list; templates can be interleaved anywhere, not just appended at the bottom.
- **Feature Cards grid** — outer container is now 100% full-width (background spans edge-to-edge); inner container is Elementor boxed (kit max-width); each card is its own independent Elementor container (`elb-feature-card` CSS class) sized by Bootstrap col percentage (`100 / cols %`) for easy stylesheet targeting.
- **DALL-E UI simplified** — removed separate "Featured Image" generation; "Hero / Banner Image" now includes inline "Set as post thumbnail" toggle; Feature Card Images generates exactly `cols × rows` images (matches grid).
- **Schema context injection** — Organization name, description, service areas, awards, certifications, social profiles, LocalBusiness hours/price range, and Google rating/review count are now injected into the AI prompt grounding block before generation, significantly improving content quality and E-E-A-T signals.
- **Template placeholder fill** now runs inside `myls_elb_parse_and_build()` so KG/Wikipedia context is shared across all template slots in a single pass, reducing API calls.
- **Setup Snapshots** now save/restore `sections_order` array (full drag order + template IDs + cols/rows) with backward compat for old `include_*` boolean snapshots.

### Removed
- Separate "Featured Image" DALL-E generation (separate from hero) — hero image handles post thumbnail via `set_featured` toggle.
- `card_width %` input replaced by `Columns` × `Rows` inputs.
- Hardcoded `card_count = 4` — card count is now always `cols × rows` from the UI.

## [7.7.7] — 2026-03-03

### Changed
- **Feature Cards — Elementor CSS Grid layout**: Inner container changed from `container_type: flex` (wrap) to `container_type: grid` with `grid_columns_number` from the UI cols setting. Each card container is a full-width grid item with class `elb-feature-card`. Removed Bootstrap col classes — grid handles sizing natively. Gaps: 1.5em columns and rows.
- **Process section — Elementor CSS Grid layout**: Inner container changed from flex-wrap to `container_type: grid`, default 2 columns. Each step is wrapped in its own grid-item container (`elb-process-step` class) with icon box at full width. Outer section unchanged (boxed).
- **Template blank images auto-generated**: When "Integrate Images" is checked, blank `image` widgets in appended Elementor templates now get DALL-E images generated inline inside `parse_and_build()` immediately after each template is inserted. Capped at 5 total across all templates. Style/size inherits from Image Style selector. Images tracked in `_tpl_images` and parent-attached to post after save.
- **Auto-generate post excerpt**: After a post is created/updated, if no excerpt exists, an AI-written 2-3 sentence plain-text excerpt is generated from the page title, description, and SEO keyword, then saved to `post_excerpt`. Logged in response.
- **Auto-generate tagline**: After post save, if no `_myls_service_tagline` meta exists, a pipe-delimited tagline is generated using business name, city/state, and credentials, then saved. Logged in response.

## [7.7.8] - 2026-03-02
### Fixed
- **Feature Cards grid cols/rows**: `feature_cols`/`feature_rows` from page setup are now written back into `sections_order` before passing to `parse_and_build`, so the grid always reflects the UI settings instead of defaulting to 3×1.
- **How-To / Process section structure**: Rebuilt as correct 3-level Elementor nesting:
  - **Level 1** — full-width flex column container (background spans edge-to-edge, heading sits here)
  - **Level 2** — CSS Grid with `content_width: boxed` (2 cols × auto rows, respects kit max-width)
  - **Level 3** — flex container per step (isInner: true) → icon box widget
  - Added explicit `grid_rows_number` so Elementor exposes the row control in the editor.

## [7.7.9] - 2026-03-02
### Fixed
- **Broken menu / mobile nav drawer on generated pages**: Root cause was that `wp_update_post()` calls made after the initial Elementor meta save (excerpt auto-generation, tagline save, image parent attachment) fire WordPress's `save_post` action, which Elementor hooks to re-write `_elementor_page_settings`. The early `delete_post_meta` calls were being immediately undone. Fix: moved both `delete_post_meta( $post_id, '_wp_page_template' )` and `delete_post_meta( $post_id, '_elementor_page_settings' )` to the very last lines before `wp_send_json_success`, so they always run after every other save operation.

## [7.8.0] - 2026-03-02
### Fixed
- **Broken menu / page header (root cause)**: `wp_update_post()` for excerpt auto-generation was firing WordPress `save_post` action, which Elementor hooks to re-write `_elementor_page_settings` with the matched Theme Builder template (e.g. "services-header"). Replaced with `$wpdb->update()` + `clean_post_cache()` — writes the excerpt directly to the DB with zero hook firing. `_elementor_page_settings` can no longer be re-written by the excerpt save.

### Changed
- **How It Works cols/rows**: Process section now supports cols × rows grid control in the UI — same Cols/Rows inputs as Feature Cards. Defaults to 2 cols × 2 rows (4 steps). Frontend `SECTION_DEFS` updated with `hasCols: true` for process; `DEFAULT_SECTIONS` default updated to `cols:2, rows:2`.
- **Process grid instruction to AI**: AI prompt now includes a `[GRID INSTRUCTION]` for process steps matching the cols × rows setting, so AI generates the correct number of steps.
- **Process cols passed to builder**: `build_process()` call now reads `$item['cols']` from sections_order (same pattern as features), with both features and process cols/rows written back into sections_order before `parse_and_build` runs.

## [7.8.1] - 2026-03-03
### Fixed
- **Broken sticky header (100px width) — actual root cause**: `files_manager->clear_cache()` was destroying the header template (17749) and kit (1872) CSS files in addition to the page's own CSS. On the first page load after generation, Elementor renders without those cached files and generates CSS inline on-the-fly. Due to a race condition, Elementor's sticky JS (`outerWidth()`) captures the header container before the `--width` CSS variable resolves correctly at desktop breakpoint, locking it at ~100px via `position: fixed; width: 100px` inline style permanently.
- **Fix**: Removed `files_manager->clear_cache()`. Now only the generated post's own `_elementor_css`, `_elementor_element_cache`, and `_elementor_page_assets` metas are deleted. Immediately after saving, `\Elementor\Core\Files\CSS\Post::create()->update()` regenerates the page CSS so sticky JS has correct dimensions on first load.

## [7.8.3] - 2026-03-03
### Fixed
- **Sticky header 100px width — actual root cause identified**: Comparing `_elementor_page_settings` between a working post (32 keys: all `astra_sites_*` typography/color settings) vs a broken generated post (1 key: only `eael_ext_toc_title`) revealed the real issue. EAEL's `save_post` hook was overwriting the full page settings with just its own key. Without `astra_sites_*` context, Elementor generates CSS without proper sizing, and sticky JS captures ~100px.
- **Stop deleting `_elementor_page_settings`**: Previous versions deleted this meta entirely. Working pages NEED it — it contains the typography context for CSS generation. Only `_wp_page_template` should be deleted (this is what forces a Theme Builder template causing the mobile nav drawer).
- **Stamp correct page settings after save**: After all saves complete, query for an existing published post of the same type with full page settings (>5 keys), copy those `astra_sites_*` settings to the new post, preserving the EAEL toc key. Logs as "⚙️ Page settings stamped from reference post #X".

## [7.8.8] - 2026-03-03
### Fixed
- **Feature Cards grid still not respecting cols/rows (7.8.7 partial fix)**: Confirmed via live _elementor_data export that Elementor requires TWO keys to control grid columns: `grid_columns_number` (the slider UI control) AND `grid_columns_grid` (the key that actually generates the CSS `grid-template-columns` property). We were only writing `grid_columns_number`. Added `grid_columns_grid: { unit: 'fr', size: N, sizes: [] }` to both the features grid and process grid containers, matching Elementor's own serialization format exactly.

---

## [7.8.7] - 2026-03-03
### Fixed
- **Feature Cards cols/rows still defaulting to 3×2 despite correct UI values**: Root cause was wrong data format for `grid_columns_number` and `grid_rows_number`. These are Elementor SLIDER controls (like `grid_columns_gap`) that expect `{ 'unit': 'fr', 'size': N }` objects — not bare integers. Elementor silently discarded our integer values and fell back to its hardcoded default of 3 columns × 2 rows. Fixed by writing `[ 'unit' => 'fr', 'size' => $cols ]` format for both features and process grid containers. Same fix applied to `grid_rows_number` in the process section.

---

## [7.8.6] - 2026-03-03
### Fixed
- **Feature Cards cols/rows ignored**: `serializeSections()` was not called before building `FormData` in the generate handler. If a user typed a new column/row count and immediately clicked Generate (without tabbing away first), the `change` event never fired and the hidden input retained stale defaults. Fix: explicit `serializeSections()` call just before `new FormData()`. Also added `input` event listener alongside `change` so values are captured on every keystroke, not just on blur.
- **Feature Card icons not used when "Feature Card Images" is unchecked**: `$use_image_boxes` fell back to `$site_patterns['has_image_boxes']`, which could be `true` from a prior site analysis even when the checkbox was unchecked — resulting in image-placeholder widgets instead of icon boxes. Fix: `$use_image_boxes` now depends solely on whether actual generated images exist (`!empty($feature_images)`).

---

## [7.8.5] - 2026-03-03
### Fixed
- **Mobile nav drawer / broken menu on generated pages (regression in 7.8.3–7.8.4)**: The page-settings stamping approach introduced in v7.8.3 was itself injecting values into `_elementor_page_settings` that trigger Astra/Hello Elementor's mobile nav drawer on page load. Reverted to the v7.7.4 approach: delete both `_wp_page_template` and `_elementor_page_settings` entirely. Additionally activated the `save_post` priority-999 hook (previously a no-op) to delete both metas immediately after every Elementor save_post write, providing belt-and-suspenders protection regardless of hook firing order.

---

## [7.8.4] - 2026-03-03
### Fixed
- **Stop deleting `_wp_page_template`**: Confirmed both working and broken posts have it set to "default" — it is not involved in the nav drawer issue. Removed all `delete_post_meta` calls for this key.
- **No metas deleted at all**: Plugin no longer deletes any Elementor metas. The only action taken is stamping the correct `astra_sites_*` page settings from a reference post onto newly generated posts.
