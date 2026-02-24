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
 *   meta-title        – Yoast SEO title generation
 *   meta-description  – Yoast meta description generation
 *   excerpt           – WP post_excerpt generation
 *   html-excerpt      – HTML excerpt for service area grids
 *   about-area        – "About the Area" section (first pass)
 *   about-area-retry  – "About the Area" retry when first pass is short
 *   geo-rewrite       – SEO → GEO rewrite draft builder
 *   faqs-builder      – FAQ generation (long/short variants)
 *   taglines          – Service tagline generation
 *   page-builder      – AI page builder content generation
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
            'meta-title'       => 'Yoast SEO Title',
            'meta-description' => 'Yoast Meta Description',
            'excerpt'          => 'WP Post Excerpt',
            'html-excerpt'     => 'HTML Excerpt (Service Area Grid)',
            'about-area'       => 'About the Area (First Pass) — {{CITY_STATE}}, {{PAGE_TITLE}}, {{FOCUS_KEYWORD}}, {{SERVICE_SUBTYPE}}',
            'about-area-retry' => 'About the Area (Retry) — {{CITY_STATE}}, {{PAGE_TITLE}}, {{FOCUS_KEYWORD}}, {{SERVICE_SUBTYPE}}',
            'geo-rewrite'      => 'SEO → GEO Rewrite Draft',
            'faqs-builder'     => 'FAQs Builder',
            'taglines'         => 'Service Taglines',
            'page-builder'     => 'AI Page Builder',
            'llms-txt'         => 'llms.txt Generator — {{CITY_NAME}}, {{STATE}}, {{COUNTY}}, {{BUSINESS_NAME}}',
        ];
    }
}
