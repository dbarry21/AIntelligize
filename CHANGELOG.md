# AIntelligize — Changelog

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
