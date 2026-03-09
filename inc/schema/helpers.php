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
 * Strip 'Answer:' prefix from FAQ answer text for schema output.
 *
 * AI citation engines read schema text literally — the prefix is noise.
 * Strips both HTML bold-wrapped and plain text variants.
 *
 * @param  string $text  Raw answer text (may contain HTML).
 * @return string        Cleaned text.
 * @since  7.8.77
 */
if ( ! function_exists('myls_strip_answer_prefix') ) {
	function myls_strip_answer_prefix( string $text ) : string {
		// Strip <strong>Answer:</strong> (with optional whitespace)
		$text = preg_replace( '/<strong>\s*Answer:\s*<\/strong>\s*/i', '', $text );
		// Strip plain text "Answer:" at the beginning
		$text = preg_replace( '/^\s*Answer:\s*/i', '', $text );
		return $text;
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

/**
 * Build an aggregateRating node from Google Places data.
 *
 * Returns null if rating or review count is missing/invalid.
 * Used by: LocalBusiness, Organization, Service schema providers.
 *
 * @param string $rating_override  Optional override (e.g. pass from a specific location).
 * @param string $count_override   Optional override.
 * @return array|null
 */
if ( ! function_exists('myls_schema_build_aggregate_rating') ) {
	/**
	 * Build an AggregateRating node for LocalBusiness and Service schema.
	 *
	 * ratingCount  — total number of star ratings (with or without written text).
	 *                Auto-populated from Google Places user_ratings_total via
	 *                myls_google_places_rating_count. Accepts override.
	 *
	 * reviewCount  — number of written text reviews only.
	 *                Stored separately as myls_google_places_review_count_manual
	 *                (admin-entered) because the Places API caps returned reviews
	 *                at 5 and doesn't expose the full text-review count.
	 *                Falls back to ratingCount when no manual value is set
	 *                (safe — Google treats them interchangeably for ranking).
	 *
	 * @param string $rating_override  Override ratingValue (optional).
	 * @param string $count_override   Override ratingCount (optional).
	 * @return array|null  AggregateRating node, or null if data is missing/invalid.
	 */
	function myls_schema_build_aggregate_rating( string $rating_override = '', string $count_override = '' ) : ?array {
		$rating = $rating_override !== '' ? $rating_override : trim( (string) get_option( 'myls_google_places_rating', '' ) );

		// ratingCount: auto-fetched user_ratings_total. Falls back to legacy option key.
		$rating_count = $count_override !== ''
			? $count_override
			: trim( (string) get_option( 'myls_google_places_rating_count',
				get_option( 'myls_google_places_review_count', '' )
			) );

		// reviewCount: manual field (text reviews only). Falls back to ratingCount.
		$review_count_manual = trim( (string) get_option( 'myls_google_places_review_count_manual', '' ) );
		$review_count = ( $review_count_manual !== '' && is_numeric( $review_count_manual ) )
			? (int) $review_count_manual
			: null; // null = fall back to ratingCount in output

		if ( $rating === '' || $rating_count === '' ) return null;
		if ( ! is_numeric( $rating ) || ! is_numeric( $rating_count ) ) return null;

		$r  = (float) $rating;
		$rc = (int)   $rating_count;

		if ( $r < 1.0 || $r > 5.0 || $rc < 1 ) return null;

		$node = [
			'@type'       => 'AggregateRating',
			'ratingValue' => number_format( $r, 1 ),
			'ratingCount' => $rc,
			'reviewCount' => $review_count !== null ? $review_count : $rc,
			'bestRating'  => '5',
			'worstRating' => '1',
		];

		return $node;
	}
}

if ( ! function_exists('myls_get_knows_about') ) {
	/**
	 * Build the knowsAbout array for LocalBusiness and Organization schema nodes.
	 *
	 * Opt-in: only items explicitly selected in the LocalBusiness tab are included.
	 * The include list (myls_lb_knows_about_include) stores a mix of:
	 *   - int post IDs  — resolved to the Service CPT post title at runtime
	 *   - '__subtype__' — resolved to the myls_service_subtype option value
	 *
	 * Results are request-cached via static variable so both LocalBusiness and
	 * Organization providers share a single option read per page load.
	 *
	 * @return array  Array of ['@type' => 'Thing', 'name' => string], or [].
	 */
	function myls_get_knows_about() : array {
		static $cache = null;
		if ( $cache !== null ) return $cache;

		// Load the saved opt-in list.
		$include = (array) get_option( 'myls_lb_knows_about_include', [] );
		if ( empty( $include ) ) {
			$cache = [];
			return $cache;
		}

		$out  = [];
		$seen = [];

		foreach ( $include as $item ) {
			$name = '';

			if ( $item === '__subtype__' ) {
				// Resolve the Service schema name field.
				$name = trim( wp_strip_all_tags( (string) get_option( 'myls_service_subtype', '' ) ) );

			} elseif ( is_numeric( $item ) && (int) $item > 0 ) {
				// Resolve Service CPT post ID to its title.
				$post = get_post( (int) $item );
				if ( $post && $post->post_status === 'publish' ) {
					$name = trim( wp_strip_all_tags( get_the_title( $post ) ) );
				}
			}

			if ( $name === '' ) continue;

			// Case-insensitive deduplicate, preserve selection order.
			$key = strtolower( $name );
			if ( isset( $seen[ $key ] ) ) continue;
			$seen[ $key ] = true;

			$out[] = [ '@type' => 'Thing', 'name' => $name ];
		}

		// Allow last-mile additions or removals via filter.
		$out = apply_filters( 'myls_knows_about', $out );

		$cache = is_array( $out ) ? $out : [];
		return $cache;
	}
}
