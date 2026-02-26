# Shortcodes Reference

This section documents all available shortcodes and their usage.

## Shortcode List:

### [about_the_area]
**File:** `about-the-area.php`

**Usage:** `[mlseo_about_the_area]`

**Description:** _TBD - Add a short description of what this shortcode does._

---

### [channel_list_detailed]
**File:** `channel-list-detailed.php`

**Usage:** `[mlseo_channel_list_detailed]`

**Description:** _TBD - Add a short description of what this shortcode does._

---

### [channel_list]
**File:** `channel-list.php`

**Usage:** `[mlseo_channel_list]`

**Description:** _TBD - Add a short description of what this shortcode does._

---

### [city_state]
**File:** `city-state.php`

**Usage:** `[mlseo_city_state]`

**Description:** _TBD - Add a short description of what this shortcode does._

---

### [county]
**File:** `county.php`

**Usage:** `[mlseo_county]`

**Description:** _TBD - Add a short description of what this shortcode does._

---

### [elementor_filters]
**File:** `elementor-filters.php`

**Usage:** `[mlseo_elementor_filters]`

**Description:** _TBD - Add a short description of what this shortcode does._

---

### [faq_schema_accordion]
**File:** `faq-schema-accordion.php`

**Usage:** `[faq_schema_accordion]`

**Description:** Renders a Bootstrap 5 accordion FAQ section with optional heading, per-instance color theming, and JSON-LD FAQ schema markup. Pulls FAQ items from native MYLS meta (`_myls_faq_items`) with automatic fallback to a legacy ACF `faq_items` repeater. Outputs nothing if no FAQ items are found for the current post.

> **Requires:** Bootstrap 5 bundle JS on the page (automatically enqueued by the shortcode if not already present).

**Attributes:**

| Attribute | Default | Description |
|---|---|---|
| `heading` | `"Frequently Asked Questions"` | Plain-text heading rendered as a centered `<h2>`. Set to `""` (explicitly empty) to hide the heading entirely. |
| `heading_sc` | _(empty)_ | **Recommended.** Run another shortcode to build the heading — avoids WP's nested-bracket parsing issues. Pass the shortcode name and any of its attributes as a plain string (see examples below). Takes priority over `heading` when set. |
| `btn_bg` | _(inherit)_ | CSS hex/rgb color for the accordion button background (sets `--myls-faq-btn-bg`). |
| `btn_color` | _(inherit)_ | CSS hex/rgb color for accordion button text (sets `--myls-faq-btn-color`). |
| `heading_color` | _(inherit)_ | CSS hex/rgb color for the heading `<h2>` (sets `--myls-faq-heading-color`). |

**Heading logic:**
- `heading` omitted → prints "Frequently Asked Questions"
- `heading=""` (explicitly blank, no `heading_sc`) → heading is hidden
- `heading_sc` set → executes that shortcode and uses its output as the heading; falls back to `heading` value if the shortcode returns empty

**Examples:**

```
[faq_schema_accordion]
```
*Default heading "Frequently Asked Questions".*

```
[faq_schema_accordion heading_sc='page_title suffix=" FAQs"']
```
*Heading pulled from the page title shortcode with " FAQs" appended — the most common pattern.*

```
[faq_schema_accordion heading_sc="city_state"]
```
*Heading set to the current city/state.*

```
[faq_schema_accordion heading="" ]
```
*No heading rendered — accordion only.*

```
[faq_schema_accordion heading_sc='page_title suffix=" FAQs"' btn_bg="#172751" btn_color="#ffffff" heading_color="#172751"]
```
*Full example with custom brand colors.*

---

### [gmb_address]
**File:** `gmb-address.php`

**Usage:** `[mlseo_gmb_address]`

**Description:** _TBD - Add a short description of what this shortcode does._

---

### [location_meta]
**File:** `location-meta.php`

**Usage:** `[mlseo_location_meta]`

**Description:** _TBD - Add a short description of what this shortcode does._

---

### [map_embed]
**File:** `map-embed.php`

**Usage:** `[mlseo_map_embed]`

**Description:** _TBD - Add a short description of what this shortcode does._

---

### [post_author]
**File:** `post-author.php`

**Usage:** `[mlseo_post_author]`

**Description:** _TBD - Add a short description of what this shortcode does._

---

### [service_area_grid]
**File:** `service-area-grid.php`

**Usage:** `[mlseo_service_area_grid]`

**Description:** _TBD - Add a short description of what this shortcode does._

---

### [service_area_lists]
**File:** `service-area-lists.php`

**Usage:** `[mlseo_service_area_lists]`

**Description:** _TBD - Add a short description of what this shortcode does._

---

### [service_grid]
**File:** `service-grid.php`

**Usage:** `[service_grid]`

**Description:** Responsive card grid of service posts with images, titles, taglines/excerpts, and buttons. Supports 2–6 column layouts, aspect ratio control, featured first card, uniform image cropping, and center-aligned incomplete rows.

**Key Attributes:** `columns`, `aspect_ratio`, `subtext`, `show_excerpt`, `button`, `image_crop`, `image_height`, `featured_first`

**Examples:**
- `[service_grid columns="6" aspect_ratio="1/1" show_excerpt="0" button="0"]` — Square image grid
- `[service_grid columns="3" subtext="excerpt"]` — 3-column with excerpts
- `[service_grid aspect_ratio="4/3"]` — Landscape ratio images

---

### [service_service_area_flip_grid]
**File:** `service-service-area-flip-grid.php`

**Usage:** `[mlseo_service_service_area_flip_grid]`

**Description:** _TBD - Add a short description of what this shortcode does._

---

### [social_sharing]
**File:** `social-sharing.php`

**Usage:** `[social_share]` / `[social_share_icon]`

**Description:** Adds social sharing buttons/icons for Facebook, Twitter, LinkedIn, and email. `[social_share]` renders full buttons; `[social_share_icon]` renders a compact icon that opens a sharing modal.

---

### [social_links]
**File:** `social-links.php`

**Usage:** `[social_links]`

**Description:** Displays branded circular social media icons linked to Organization schema sameAs URLs. Auto-detects platform from URL with inline SVG icons. Supports 15+ platforms including Facebook, Instagram, X, YouTube, LinkedIn, TikTok, Pinterest, Yelp, Google Business, BBB, Thumbtack, Angi, and Nextdoor.

**Key Attributes:** `size`, `gap`, `align`, `style` (color/mono-dark/mono-light), `platforms`, `exclude`

**Examples:**
- `[social_links]` — All saved Organization profiles
- `[social_links style="mono-dark" size="56"]` — Large monochrome icons
- `[social_links platforms="facebook,instagram,youtube"]` — Whitelist specific platforms

---

### [google_reviews_slider]
**File:** `google-reviews-slider.php`

**Usage:** `[google_reviews_slider]`

**Description:** Pulls Google reviews via the Places API and displays them in a glassmorphism-styled Swiper slider with star ratings, reviewer names, and autoplay. Reviews are cached via WP transients.

**Key Attributes:** `place_id`, `min_rating`, `max_reviews`, `sort`, `speed`, `cache_hours`, `blur`, `overlay_opacity`, `star_color`, `text_color`, `excerpt_words`

**Examples:**
- `[google_reviews_slider]` — All reviews with defaults
- `[google_reviews_slider min_rating="4" sort="newest"]` — 4+ stars, newest first
- `[google_reviews_slider blur="20" overlay_opacity="0.18"]` — Custom glass effect

---

### [with_transcript]
**File:** `with-transcript.php`

**Usage:** `[mlseo_with_transcript]`

**Description:** _TBD - Add a short description of what this shortcode does._

---
