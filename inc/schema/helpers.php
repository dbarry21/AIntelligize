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
 * Extract the opening answer block from FAQ answer HTML for schema output.
 *
 * AI citation engines (ChatGPT, Gemini, Perplexity) read acceptedAnswer.text
 * directly. Answers over ~80 words are summarised poorly or skipped entirely.
 * The FAQ generator prompt produces a structured answer where the FIRST <p>
 * tag contains a concise 40–60 word standalone answer (the "opening answer
 * block"). Only that paragraph is used for schema — the full HTML continues
 * to render in the on-page accordion unchanged.
 *
 * Extraction logic (in priority order):
 *   1. If the answer HTML contains a <p> tag — extract the first <p> content,
 *      strip all HTML tags, strip the "Answer:" prefix, trim whitespace.
 *   2. If no <p> tag exists — strip all HTML from the full text, strip the
 *      "Answer:" prefix, trim whitespace (safe fallback for plain-text answers).
 *
 * The full HTML answer is NOT modified — it is stored and rendered separately.
 * This function only affects what goes into acceptedAnswer.text in JSON-LD.
 *
 * @param  string $text  Raw answer HTML from _myls_faq_items post meta.
 * @return string        Concise plain-text string for acceptedAnswer.text.
 * @since  7.9.10
 */
if ( ! function_exists('myls_strip_answer_prefix') ) {
	function myls_strip_answer_prefix( string $text ) : string {
		// ── Step 1: Try to extract the first <p> tag content ─────────────────
		// The FAQ generator always wraps the opening answer block in a <p> tag.
		// Match the first <p>...</p> pair, including any nested tags inside it.
		if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $text, $matches ) ) {
			$first_para = $matches[1];
		} else {
			// Fallback: no <p> tag found — use full text as-is
			$first_para = $text;
		}

		// ── Step 2: Strip all HTML tags from the extracted paragraph ─────────
		$clean = wp_strip_all_tags( $first_para );

		// ── Step 3: Strip "Answer:" prefix variants ───────────────────────────
		// Handles both "Answer: " and "Answer:" (no space) at string start.
		$clean = preg_replace( '/^\s*Answer:\s*/i', '', $clean );

		// ── Step 4: Strip trailing CTA noise ("Helpful next step: ...") ──
		// Google FAQ guidelines prohibit calls to action in acceptedAnswer.text.
		// This pattern matches:
		//   "Helpful next step: ..."
		//   "Helpful next step — ..."
		// and removes it along with any preceding whitespace/bullets/punctuation.
		$clean = preg_replace( '/[\s\.\,\;\:\-\•\*]*Helpful next step[:\s\-–—].*$/si', '', $clean );

		// Also strip any trailing bullet-list remnants left after the CTA removal
		// e.g. "• Contact us today." or "* Call us." appearing at the very end.
		$clean = rtrim( $clean, " \t\n\r\0\x0B.,;:•*-–—" );

		// ── Step 5: Collapse whitespace and trim ──────────────────────────────
		$clean = trim( preg_replace( '/\s+/', ' ', $clean ) );

		return trim( $clean ) !== '' ? trim( $clean ) : trim( $text );
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
 * Build an AggregateRating node for LocalBusiness schema.
 *
 * Controlled by the myls_aggregate_rating option (admin UI in LocalBusiness tab).
 * Returns null when feature is disabled, data is missing, or values fail validation.
 *
 * Sources:
 *   - 'google' (default) — reads from Google Places cron data in wp_options
 *   - 'manual' — uses admin-entered override values
 *
 * @return array|null  AggregateRating node, or null.
 * @since 7.9.18.27
 */
if ( ! function_exists('myls_schema_build_aggregate_rating') ) {
	function myls_schema_build_aggregate_rating() : ?array {
		$opt = get_option( 'myls_aggregate_rating', [] );

		// Feature must be explicitly enabled
		if ( empty($opt['enabled']) || (string) $opt['enabled'] !== '1' ) {
			return null;
		}

		$source = $opt['source'] ?? 'google';

		if ( $source === 'google' ) {
			// Read from Google Places cron data
			$rating = trim( (string) get_option( 'myls_google_places_rating', '' ) );
			$count  = trim( (string) get_option(
				'myls_google_places_rating_count',
				get_option( 'myls_google_places_review_count', '' ) // legacy key fallback
			) );
		} else {
			// Manual override values
			$rating = trim( (string) ( $opt['rating_value'] ?? '' ) );
			$count  = trim( (string) ( $opt['review_count']  ?? '' ) );
		}

		// Both rating and count are required
		if ( $rating === '' || $count === '' ) {
			return null;
		}

		// Validate rating is numeric and within 0–5 range
		if ( ! is_numeric( $rating ) || (float) $rating < 0 || (float) $rating > 5 ) {
			return null;
		}

		// Validate count is a positive integer
		if ( ! ctype_digit( $count ) || (int) $count < 1 ) {
			return null;
		}

		$best_rating  = trim( (string) ( $opt['best_rating']  ?? '5' ) );
		$worst_rating = trim( (string) ( $opt['worst_rating'] ?? '1' ) );

		// Defaults if blank
		if ( $best_rating  === '' ) $best_rating  = '5';
		if ( $worst_rating === '' ) $worst_rating = '1';

		return [
			'@type'       => 'AggregateRating',
			'ratingValue' => $rating,
			'reviewCount' => $count,
			'bestRating'  => $best_rating,
			'worstRating' => $worst_rating,
		];
	}
}

if ( ! function_exists('myls_normalize_phone_e164') ) {
	/**
	 * Normalize a US/CA phone number to E.164 format (+1XXXXXXXXXX).
	 *
	 * Only normalizes numbers that appear to be 10-digit North American numbers.
	 * Returns the original string unchanged if it can't be confidently normalized
	 * (e.g. international numbers, extensions, or non-numeric strings).
	 *
	 * @param  string $phone  Raw phone string from wp_options.
	 * @return string         E.164 string (e.g. "+18134232383") or original input.
	 */
	function myls_normalize_phone_e164( string $phone ) : string {
		if ( $phone === '' ) return '';

		// Extract digits only
		$digits = preg_replace( '/\D/', '', $phone );

		// Handle +1 country code already present (11 digits starting with 1)
		if ( strlen( $digits ) === 11 && $digits[0] === '1' ) {
			return '+' . $digits;
		}

		// 10-digit North American number — prepend +1
		if ( strlen( $digits ) === 10 ) {
			return '+1' . $digits;
		}

		// Can't confidently normalize — return original unchanged
		return $phone;
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
