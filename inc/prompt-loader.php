<?php
/**
 * Centralized Prompt Loader
 *
 * Single source of truth for all AI prompt templates.
 * Default prompts live as plain text files in /assets/prompts/
 * so they can be directly edited and stay consistent across
 * all admin tabs and AJAX handlers.
 *
 * Usage:
 *   $prompt = myls_get_default_prompt('meta-title');
 *   $prompt = myls_get_default_prompt('faqs-builder');
 *
 * Available prompt keys (match filenames without .txt):
 *   meta-title        – Yoast SEO title generation          {post_title}, {site_name}, {excerpt}, {primary_category}, {permalink}, {credentials}
 *   meta-description  – Yoast meta description generation   {post_title}, {site_name}, {excerpt}, {primary_category}, {permalink}, {credentials}
 *   excerpt           – WP post_excerpt generation          {post_title}, {site_name}, {excerpt}, {primary_category}, {city_state}, {permalink}, {content_snippet}
 *   html-excerpt      – HTML excerpt for service area grids
 *   about-area        – "About the Area" section            {{CITY_STATE}}, {{PAGE_TITLE}}, {{FOCUS_KEYWORD}}, {{SERVICE_SUBTYPE}}
 *   about-area-retry  – "About the Area" retry              {{CITY_STATE}}, {{PAGE_TITLE}}, {{FOCUS_KEYWORD}}, {{SERVICE_SUBTYPE}}
 *   geo-rewrite       – SEO → GEO rewrite draft builder
 *   faqs-builder      – FAQ generation                      {{TITLE}}, {{URL}}, {{PAGE_TEXT}}, {{CITY_STATE}}, {{CONTACT_URL}}, {{ALLOW_LINKS}}, {{VARIANT}}, {{CREDENTIALS}}
 *   taglines          – Service tagline generation          {{TITLE}}, {{CONTENT}}, {{CITY_STATE}}, {{BUSINESS_TYPE}}, {{CREDENTIALS}}
 *   page-builder      – AI page builder content generation  {{PAGE_TITLE}}, {{BUSINESS_NAME}}, {{CITY}}, {{PHONE}}, {{EMAIL}}, {{DESCRIPTION}}
 *   elementor-builder – Elementor page builder (section-per-block JSON output)
 *
 * NOTE — Two token styles exist:
 *   {{DOUBLE_BRACE}} — used by about-area, FAQs, taglines, page-builder (str_replace in each AJAX handler)
 *   {single_brace}   — used by meta title, meta description, excerpt (myls_ai_apply_tokens via myls_ai_context_for_post)
 *
 * {{CREDENTIALS}} / {credentials} auto-assembles from Organization + LocalBusiness schema options:
 *   myls_org_awards, myls_org_certifications, myls_org_memberships, myls_org_description (veteran detection),
 *   myls_org_name (Licensed & Insured trigger), myls_lb_locations[0] (aggregate rating).
 *   See myls_build_tagline_credentials() in inc/schema/helpers.php.
 *
 * @since 6.2.0
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('myls_get_default_prompt') ) {

    /**
     * Load a default prompt template from the /assets/prompts/ directory.
     *
     * @param  string $key  Prompt identifier (filename without .txt extension).
     * @return string       Prompt text, or empty string if file not found.
     */
    function myls_get_default_prompt( string $key ) : string {

        // Sanitize key to prevent directory traversal
        $key = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $key ) );

        $file = MYLS_PATH . 'assets/prompts/' . $key . '.txt';

        if ( ! file_exists( $file ) ) {
            return '';
        }

        // Cache in a static variable so each file is only read once per request
        static $cache = [];

        if ( ! isset( $cache[ $key ] ) ) {
            $cache[ $key ] = (string) file_get_contents( $file );
        }

        return $cache[ $key ];
    }
}

if ( ! function_exists('myls_list_prompt_keys') ) {

    /**
     * List all available prompt template keys.
     *
     * @return array  Associative array of key => description.
     */
    function myls_list_prompt_keys() : array {
        return [
            'meta-title'       => 'Yoast SEO Title — {post_title}, {site_name}, {excerpt}, {primary_category}, {permalink}, {credentials}',
            'meta-description' => 'Yoast Meta Description — {post_title}, {site_name}, {excerpt}, {primary_category}, {permalink}, {credentials}',
            'excerpt'          => 'WP Post Excerpt — {post_title}, {site_name}, {excerpt}, {primary_category}, {city_state}, {permalink}, {content_snippet}',
            'html-excerpt'     => 'HTML Excerpt (Service Area Grid)',
            'about-area'       => 'About the Area (First Pass) — {{CITY_STATE}}, {{PAGE_TITLE}}, {{FOCUS_KEYWORD}}, {{SERVICE_SUBTYPE}}',
            'about-area-retry' => 'About the Area (Retry) — {{CITY_STATE}}, {{PAGE_TITLE}}, {{FOCUS_KEYWORD}}, {{SERVICE_SUBTYPE}}',
            'geo-rewrite'      => 'SEO → GEO Rewrite Draft',
            'faqs-builder'     => 'FAQs Builder — {{TITLE}}, {{URL}}, {{PAGE_TEXT}}, {{CITY_STATE}}, {{CONTACT_URL}}, {{ALLOW_LINKS}}, {{VARIANT}}, {{CREDENTIALS}}',
            'taglines'         => 'Service Taglines — {{TITLE}}, {{CONTENT}}, {{CITY_STATE}}, {{BUSINESS_TYPE}}, {{CREDENTIALS}}',
            'page-builder'     => 'AI Page Builder — {{PAGE_TITLE}}, {{BUSINESS_NAME}}, {{CITY}}, {{PHONE}}, {{EMAIL}}, {{DESCRIPTION}}',
            'elementor-builder'=> 'Elementor Page Builder',
            'llms-txt'         => 'llms.txt Generator — {{CITY_NAME}}, {{STATE}}, {{COUNTY}}, {{BUSINESS_NAME}}',
        ];
    }
}
