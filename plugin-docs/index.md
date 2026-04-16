# AIntelligize Plugin Documentation

## Welcome to AIntelligize

AIntelligize is a comprehensive WordPress plugin designed for local businesses and SEO professionals. It provides powerful tools for managing local business information, generating AI-powered content, optimizing schema markup, and creating dynamic service area pages.

## Key Features

### AI-Powered Content Generation
- **AI Taglines**: Generate 3-4 benefit-focused tagline options for services in HTML format
- **AI FAQs**: Create structured FAQ content with schema markup
- **AI About Area**: Generate location-specific "About the Area" content  
- **AI Excerpts**: Automatic meta descriptions and excerpts
- **AI Geo Content**: Bulk generate location-based content

### Service Management
- **Service Grid**: Responsive grid display with customizable columns (2-6)
- **Service Taglines**: Manual or AI-generated taglines with character counter
- **Service Areas**: Hierarchical service area management with parent-child relationships
- **Service Schema**: Automatic Service schema markup for Google

### Schema & SEO
- **Organization Schema**: Business information with multiple locations, awards, certifications, `knowsAbout` opt-in topics
- **Local Business Schema**: Location-specific schema with enriched fallback chain — image (3-level: per-location → org logo → org image URL), priceRange (site-wide default fallback), openingHoursSpecification, aggregateRating, awards, certifications, `areaServed` (City objects from service_area CPT). Site-wide Defaults block with live fallback chain status display.
- **Service Schema**: Service-specific schema with fully enriched provider node (awards, certifications, aggregateRating on both assigned and unassigned pages). `areaServed` typed as `AdministrativeArea` objects.
- **FAQ Schema**: Structured FAQ data for rich snippets
- **About Page Schema**: AboutPage schema for company pages
- **VideoObject Schema** *(v7.8.74+)*: Automatic video detection and `VideoObject` JSON-LD emission on any singular page with video content. Detects across Elementor (video widget, video-playlist, background video, HTML widget iframes, Theme Builder templates), Beaver Builder, Divi, WPBakery, Gutenberg, and Classic editor. YouTube duration auto-fetched from YouTube Data API v3 and cached 30 days per video.
- **ItemList Schema** *(v7.9.0)*: Structured `ItemList` nodes on the front page for services (`Service` typed items) and service areas (`City` typed items). Helps AI systems extract structured offerings and geographic coverage for AI Overviews and generative search.
- **Service Area Service Schema** *(v7.9.1)*: Explicit `Service` schema nodes on `service_area` CPT pages creating the Service → Provider (LocalBusiness) → City entity graph. Parent pages emit one Service per service CPT post; child pages match to a specific service. Completes the geo-specific service relationship chain for AI systems.

### Location Features
- **Dynamic Location Tags**: [city_state], [city_only], [county] shortcodes
- **Service Area Grids**: Display service areas in responsive grids
- **Google Maps Integration**: Static map generation with preview
- **Location Hierarchy**: Parent-child service area relationships

### Cookie Consent & GDPR
- **Cookie Consent Banner**: Lightweight self-contained consent banner — no plugin dependencies
- **5 Themes**: Dark, Light, Glassmorphism, Minimal, Branded (custom colors)
- **Script Blocking**: GDPR-level — holds analytics scripts until user accepts
- **Native Page Picker**: Privacy Policy field uses WordPress page dropdown
- **Mobile-Optimized**: Slim bar with side-by-side buttons on small screens
- **JS Events**: `ccb:accepted` / `ccb:declined` custom DOM events for custom integrations

### Bulk Operations
- **Bulk Meta Generation**: Generate meta titles and descriptions
- **Bulk FAQ Generation**: Create FAQs for multiple posts
- **Bulk Tagline Generation**: Generate taglines for services
- **Google Maps Bulk**: Generate maps for multiple locations

### Search Stats (v7.0)
- **Keyword Tracking**: Scan focus keywords from Yoast, Rank Math, AIOSEO plus FAQ questions
- **Google Autocomplete Expansion**: 5 query types per keyword (exact, expanded, how, what, best)
- **GSC Search Analytics**: Impressions, clicks, CTR, average position per keyword
- **AI Overview Detection**: Identify queries appearing in Google AI Overviews
- **Per-Post SERP Rank**: Weighted average position filtered by specific page URL
- **Rank History**: Daily snapshots with movement arrows and chronological history view
- **Dashboard**: 6 KPI cards, post type filters, freshness badges, print-friendly layout

### AIntelligize Stats
- **AI Usage Analytics**: Track every API call with cost, tokens, and success rate
- **Handler Breakdown**: See which AI features (FAQs, Meta, Excerpts, etc.) use the most resources
- **Activity Log**: Searchable log of all AI operations with Chart.js visualizations
- **Cost Tracking**: Monitor OpenAI/Anthropic API spend over time

### YouTube Video Blog
- **Auto-Import**: Pull videos from YouTube channel and create draft blog posts
- **Transcripts**: AI-generated transcripts with accordion display
- **Shortcodes**: [myls_youtube_panel], [myls_youtube_with_transcript], [youtube_channel_list_detailed]

### Shortcodes (35+)
- **Location**: city_state, city_only, county_name, acf_field
- **Services**: service_grid, service_posts, service_area_grid, service_area_list, service_area_children, service_area_siblings, service_area_flip_cards, and more
- **Schema**: faq_schema_accordion, yoast_title, post_author
- **Social**: social_share, social_share_icon
- **YouTube**: myls_youtube_panel, myls_youtube_with_transcript, youtube_channel_list, youtube_channel_list_detailed, youtube_with_transcript
- **Utility**: gmb_address, gmb_hours, ssseo_places_status, ssseo_map_embed, myls_ajax_search

## Quick Start Guide

### Step 1: Configure Organization Settings

1. Go to **AIntelligize → Schema → Organization**
2. Enter your business information:
   - Organization Name
   - URL
   - Phone Number
   - Address
   - Logo
3. Click **Save Changes**

### Step 2: Set Up API Integration

1. Go to **AIntelligize → API Integration**
2. Enter your **OpenAI** or **Anthropic API Key**
3. Configure default settings:
   - Model: gpt-4o or claude-sonnet (recommended)
   - Temperature: 0.7
   - Max Tokens: 4000
4. (Optional) Connect **Google Search Console** via OAuth for Search Stats
5. Click **Save Settings**

### Step 3: Create Services

1. Go to **Services → Add New**
2. Enter service title and description
3. Add featured image
4. In the **Service Tagline** metabox:
   - Click **Generate with AI** for automatic tagline
   - Or enter manually
5. Publish the service

### Step 4: Add Shortcodes to Pages

Common shortcodes:
- Services grid: `[service_grid]`
- Location: `[city_state]`
- FAQs: `[faq_schema_accordion]`

### Step 5: Enable Schema

1. Go to **AIntelligize → Schema**
2. Enable desired schema types:
   - **Organization**: Always enable
   - **Local Business**: If you have physical locations
   - **Service**: For service pages
   - **FAQ**: If using FAQ content
3. Save settings

## Common Workflows

### Creating a Service Area Landing Page

1. Create a new Page or Service Area post
2. Add location fields (city_state, county)
3. Add this content:
   - Headline: `Services in [city_state]`
   - Intro: `[about_the_area]`
   - Services: `[service_grid]`
   - FAQs: `[faq_schema_accordion]`
4. Generate AI content via AI tabs

### Bulk Generating Taglines

1. Go to **AIntelligize → AI → Taglines**
2. Select post type (Services, Pages, etc.)
3. Select posts (or click Select All)
4. Configure options:
   - Skip existing or Overwrite
5. Click **Generate Taglines**
6. Review results in log

### Setting Up Service Schema

1. Go to **AIntelligize → Schema → Service**
2. Enable Service Schema
3. Assign service pages or use Service CPT
4. Configure service subtype (optional)
5. Save settings
6. Schema automatically appears on service pages

## System Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- MySQL 5.6 or higher
- OpenAI or Anthropic API key (for AI features)
- Google Maps API key (for map features)
- Google Search Console OAuth credentials (for Search Stats GSC integration)
- YouTube Data API key (for video blog features)
- Google Places API key (for open/closed status shortcode)

## Support & Documentation

- **Shortcodes**: See the Shortcodes tab for complete reference
- **Admin Tabs**: See Tabs & Subtabs for all admin features
- **Tutorials**: See Tutorials tab for step-by-step guides
- **Release Notes**: See Release Notes for version history

## Tips for Success

1. **Start with Organization Schema**: This is the foundation for all other schema types
2. **Use AI Features Wisely**: Generate content for 2-3 posts first to test quality
3. **Test Shortcodes**: Preview pages before publishing
4. **Backup Settings**: Export your configuration regularly
5. **Monitor Token Usage**: Use AIntelligize Stats to track API costs and usage
6. **Track Rankings**: Run Search Stats weekly to build rank history over time
7. **Enable Caching**: Use caching plugins for better performance
8. **Mobile Testing**: Always test on mobile devices
9. **Connect GSC**: Link Google Search Console for real search performance data

## Getting Help

If you encounter issues:
1. Check the Tutorials tab for step-by-step guides
2. Review the Shortcodes tab for usage examples
3. Verify API keys in Settings
4. Clear WordPress and browser cache
5. Check browser console for JavaScript errors

## What's Next?

- Explore the **Shortcodes** tab to learn all available shortcodes
- Visit **Tabs & Subtabs** to understand admin features  
- Check **Tutorials** for detailed workflow guides
- Review **API Reference** for developer documentation
