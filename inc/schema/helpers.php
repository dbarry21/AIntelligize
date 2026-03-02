<?php
// File: inc/schema/helpers.php
if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('myls_opt') ) {
    function myls_opt($key, $default = '') {
        return get_option($key, $default);
    }
}
if ( ! function_exists('myls_update_opt') ) {
    function myls_update_opt($key, $val) {
        update_option($key, $val);
    }
}
if ( ! function_exists('myls_sanitize_csv') ) {
    function myls_sanitize_csv($str) {
        $str = wp_unslash((string)$str);
        $parts = array_filter(array_map('trim', explode("\n", $str)));
        return implode("\n", $parts);
    }
}

/**
 * Build a flat, pipe-separated credential string from Organization + LocalBusiness schema options.
 *
 * Sources (in priority order):
 *   1. Veteran-Owned flag   — detected from keyword in myls_org_description
 *   2. Licensed & Insured   — appended when any org name is configured
 *   3. Certifications       — myls_org_certifications (array of strings)
 *   4. Awards               — myls_org_awards (array of strings)
 *   5. Memberships          — myls_org_memberships (array of {name} objects) → "X Member"
 *   6. Aggregate rating     — myls_lb_locations[0]['rating'] + ['review_count'] if stored
 *
 * Used as {{CREDENTIALS}} (double-brace) in: taglines, FAQs, page-builder prompts.
 * Used as {credentials}   (single-brace) in: meta title, meta description, excerpt prompts.
 *
 * Falls back gracefully to an empty string when no schema data is configured.
 *
 * @return string  Pipe-separated credential string, e.g.
 *                 "Veteran-Owned & Operated | Licensed & Insured | PWNA Member | Angi Super Service Award 2024 | 890+ 5.0-Star Google Reviews"
 *
 * @since 7.5.13
 */
if ( ! function_exists('myls_build_tagline_credentials') ) {
    function myls_build_tagline_credentials(): string {
        $parts = [];

        // 1. Veteran-Owned — keyword scan on org description
        $desc = strtolower( (string) get_option('myls_org_description', '') );
        if ( str_contains($desc, 'veteran') || str_contains($desc, 'military') ) {
            $parts[] = 'Veteran-Owned & Operated';
        }

        // 2. Licensed & Insured — present whenever an org name is configured
        $org_name = trim( (string) get_option('myls_org_name', '') );
        if ( $org_name !== '' ) {
            $parts[] = 'Licensed & Insured';
        }

        // 3. Certifications (hasCertification)
        $certs = get_option('myls_org_certifications', []);
        if ( is_array($certs) ) {
            foreach ( $certs as $cert ) {
                $cert = sanitize_text_field( (string) $cert );
                if ( $cert !== '' ) $parts[] = $cert;
            }
        }

        // 4. Awards (award)
        $awards = get_option('myls_org_awards', []);
        if ( is_array($awards) ) {
            foreach ( $awards as $award ) {
                $award = sanitize_text_field( (string) $award );
                if ( $award !== '' ) $parts[] = $award;
            }
        }

        // 5. Memberships (memberOf) — name only, append " Member"
        $memberships = get_option('myls_org_memberships', []);
        if ( is_array($memberships) ) {
            foreach ( $memberships as $m ) {
                if ( is_array($m) && ! empty($m['name']) ) {
                    $parts[] = sanitize_text_field( $m['name'] ) . ' Member';
                }
            }
        }

        // 6. Aggregate rating from first LocalBusiness location
        $lb_locations = get_option('myls_lb_locations', []);
        if ( is_array($lb_locations) && ! empty($lb_locations[0]) ) {
            $rating       = trim( (string) ( $lb_locations[0]['rating']       ?? '' ) );
            $review_count = trim( (string) ( $lb_locations[0]['review_count'] ?? '' ) );
            if ( $rating !== '' && $review_count !== '' ) {
                $parts[] = $review_count . '+ ' . $rating . '-Star Google Reviews';
            }
        }

        return implode(' | ', array_values( array_unique( array_filter($parts) ) ) );
    }
}
