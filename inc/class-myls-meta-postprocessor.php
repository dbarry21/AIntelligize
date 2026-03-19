<?php
/**
 * MYLS Meta Description Post-Processor
 *
 * Validates and enforces quality rules on AI-generated meta descriptions
 * before they are saved to Yoast (_yoast_wpseo_metadesc).
 *
 * @package AIntelligize
 * @since   7.9.18.7
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MYLS_Meta_Postprocessor {

	/**
	 * Approved CTA phrases (shortest first for salvage priority).
	 */
	private const APPROVED_CTAS = [
		'see options and pricing.',
		'get a quote in minutes.',
		'schedule a consultation.',
		'get a free estimate today.',
		'call for same-day scheduling.',
		'request a free estimate today.',
	];

	/**
	 * City names that must not appear as a single city on service pages.
	 */
	private const BLOCKED_CITIES = [
		'apollo beach',
		'riverview',
		'brandon',
		'ruskin',
		'seffner',
		'lithia',
		'wimauma',
		'gibsonton',
		'valrico',
		'dover',
		'plant city',
		'sun city center',
		'parrish',
		'ellenton',
		'bradenton',
		'south tampa',
		'wesley chapel',
	];

	/**
	 * Enforce quality rules on an AI-generated meta description.
	 *
	 * Runs sanitisation, hard-fail checks, CTA enforcement, and length
	 * trimming in order. Returns the clean string on success or a WP_Error
	 * describing why the description was rejected.
	 *
	 * @param string $raw Raw AI output.
	 * @return string|\WP_Error Clean meta description or error.
	 */
	public static function enforce( string $raw ): string|\WP_Error {

		// ── A) SANITISE ──
		$text = trim( strip_tags( $raw ) );

		// Remove wrapping quotes (straight and curly).
		$text = preg_replace( '/^[\'""\x{201C}\x{201D}\x{2018}\x{2019}]+|[\'""\x{201C}\x{201D}\x{2018}\x{2019}]+$/u', '', $text );
		$text = trim( $text );

		// Collapse internal whitespace.
		$text = preg_replace( '/\s+/', ' ', $text );

		if ( $text === '' ) {
			return new \WP_Error(
				'meta_empty',
				'Meta description is empty after sanitisation. Raw: ' . mb_substr( $raw, 0, 200 )
			);
		}

		// ── B) HARD-FAIL CHECKS ──

		// First-person / second-person pronouns.
		if ( preg_match( '/\b(we|our|us|your)\b/i', $text ) ) {
			return new \WP_Error(
				'meta_first_person',
				'Contains first/second-person pronoun (we/our/us/your). Raw: ' . mb_substr( $text, 0, 200 )
			);
		}

		// Brand name.
		if ( preg_match( '/premier pro/i', $text ) ) {
			return new \WP_Error(
				'meta_brand',
				'Contains brand name "Premier Pro". Raw: ' . mb_substr( $text, 0, 200 )
			);
		}

		// Single city name (whole word, case-insensitive).
		foreach ( self::BLOCKED_CITIES as $city ) {
			$pattern = '/\b' . preg_quote( $city, '/' ) . '\b/i';
			if ( preg_match( $pattern, $text ) ) {
				return new \WP_Error(
					'meta_single_city',
					'Contains blocked city name "' . $city . '". Raw: ' . mb_substr( $text, 0, 200 )
				);
			}
		}

		// Question mark.
		if ( strpos( $text, '?' ) !== false ) {
			return new \WP_Error(
				'meta_question',
				'Contains question mark. Raw: ' . mb_substr( $text, 0, 200 )
			);
		}

		// ── C) CTA ENFORCEMENT ──
		$has_cta    = false;
		$matched_cta = '';

		foreach ( self::APPROVED_CTAS as $cta ) {
			if ( strcasecmp( substr( $text, -strlen( $cta ) ), $cta ) === 0 ) {
				$has_cta    = true;
				$matched_cta = $cta;
				break;
			}
		}

		if ( ! $has_cta ) {
			// Attempt salvage: find last sentence boundary before char 128.
			$prefix = substr( $text, 0, 128 );
			$last_dot = strrpos( $prefix, '. ' );

			if ( $last_dot !== false ) {
				$base = substr( $text, 0, $last_dot + 1 ); // includes the period

				// Try each CTA (shortest first) until total <= 160.
				foreach ( self::APPROVED_CTAS as $cta ) {
					$candidate = $base . ' ' . ucfirst( $cta );
					if ( strlen( $candidate ) <= 160 ) {
						$text       = $candidate;
						$has_cta    = true;
						$matched_cta = $cta;
						break;
					}
				}
			}

			if ( ! $has_cta ) {
				return new \WP_Error(
					'meta_bad_cta',
					'No approved CTA found and salvage failed. Raw: ' . mb_substr( $text, 0, 200 )
				);
			}
		}

		// ── D) LENGTH TRIM (over 160) ──
		if ( strlen( $text ) > 160 ) {
			// Preserve the matched CTA intact.
			$cta_len   = strlen( $matched_cta );
			$separator = '. ';
			$budget    = 160 - strlen( $separator ) - $cta_len;

			// Extract main clause (everything before the CTA).
			$main = rtrim( substr( $text, 0, strlen( $text ) - $cta_len ) );
			$main = rtrim( $main, '. ' );

			// Trim at last word boundary within budget.
			if ( strlen( $main ) > $budget ) {
				$main = substr( $main, 0, $budget );
				$last_space = strrpos( $main, ' ' );
				if ( $last_space !== false ) {
					$main = substr( $main, 0, $last_space );
				}
			}

			$text = rtrim( $main ) . $separator . ucfirst( $matched_cta );
		}

		// ── E) LENGTH CHECK (under 145) ──
		// Short is better than bad — return as-is, caller can log it.

		return $text;
	}
}
