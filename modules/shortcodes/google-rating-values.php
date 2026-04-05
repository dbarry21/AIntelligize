<?php
/**
 * Google review data shortcodes.
 *
 * [google_review_count]      – outputs the total review/rating count (inline)
 * [google_aggregate_rating]  – outputs the aggregate star rating (inline)
 * [google_rating_badge]      – visual badge widget with G logo, stars, count
 *
 * Data is populated by the 4-hour cron in aintelligize.php and stored
 * in wp_options (myls_google_places_rating, myls_google_places_rating_count).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Return the raw location array assigned to the given post, or null.
 * Mirrors assignment logic in myls_schema_localbusiness_for_post() but
 * returns the raw $loc array rather than a built schema node.
 *
 * Priority: post meta fast path -> pages[] scan -> first-location fallback.
 *
 * @param int $post_id
 * @return array|null
 */
if ( ! function_exists( 'myls_get_assigned_location_raw' ) ) {
	function myls_get_assigned_location_raw( int $post_id ) : ?array {
		if ( $post_id <= 0 ) return null;

		$locs = function_exists( 'myls_lb_get_locations_cached' )
			? myls_lb_get_locations_cached()
			: (array) get_option( 'myls_lb_locations', [] );

		if ( empty( $locs ) ) return null;

		// Fast path: post meta
		$is_assigned = get_post_meta( $post_id, '_myls_lb_assigned', true );
		$loc_index   = get_post_meta( $post_id, '_myls_lb_loc_index', true );

		if ( $is_assigned === '1' && $loc_index !== '' ) {
			$i = (int) $loc_index;
			if ( isset( $locs[ $i ] ) && is_array( $locs[ $i ] ) ) {
				return $locs[ $i ];
			}
		}

		// Scan pages[] assignment
		foreach ( $locs as $loc ) {
			if ( ! is_array( $loc ) ) continue;
			$pages = array_map( 'absint', (array) ( $loc['pages'] ?? [] ) );
			if ( ! empty( $pages ) && in_array( $post_id, $pages, true ) ) {
				return $loc;
			}
		}

		// First-location fallback (single-location sites)
		return isset( $locs[0] ) && is_array( $locs[0] ) ? $locs[0] : null;
	}
}

/**
 * Get rating and review count for a Place ID.
 * Checks per-location cached options (myls_loc_rating_{key}) first,
 * then falls back to global options.
 *
 * @param string $place_id  Google Place ID (may be empty — returns global).
 * @return array{rating: string, count: string, place_id: string}
 */
if ( ! function_exists( 'myls_get_rating_data_for_place' ) ) {
	function myls_get_rating_data_for_place( string $place_id = '' ) : array {
		$global_rating = trim( (string) get_option( 'myls_google_places_rating', '' ) );
		$global_count  = trim( (string) get_option(
			'myls_google_places_rating_count',
			get_option( 'myls_google_places_review_count', '' )
		) );
		$global_pid    = trim( (string) get_option( 'myls_google_places_place_id', '' ) );

		if ( $place_id === '' ) {
			return [ 'rating' => $global_rating, 'count' => $global_count, 'place_id' => $global_pid ];
		}

		$loc_key    = sanitize_key( $place_id );
		$loc_rating = trim( (string) get_option( 'myls_loc_rating_' . $loc_key, '' ) );
		$loc_count  = trim( (string) get_option( 'myls_loc_rating_count_' . $loc_key, '' ) );

		return [
			'rating'   => $loc_rating !== '' ? $loc_rating : $global_rating,
			'count'    => $loc_count  !== '' ? $loc_count  : $global_count,
			'place_id' => $place_id,
		];
	}
}

/**
 * Resolve the Google Place ID for the current front-end page.
 * Assigned location's place_id takes priority; falls back to global.
 *
 * @return string  Place ID or empty string.
 */
if ( ! function_exists( 'myls_get_current_page_place_id' ) ) {
	/**
	 * Resolve the Google Place ID for the current front-end page.
	 *
	 * Priority:
	 *  1. Assigned location's place_id — only when rating_enabled !== '0'.
	 *  2. Global default Place ID (myls_google_places_place_id).
	 *
	 * @return string  Place ID or empty string.
	 */
	function myls_get_current_page_place_id() : string {
		if ( is_singular() ) {
			$post_id = (int) get_queried_object_id();
			if ( $post_id > 0 ) {
				$loc = myls_get_assigned_location_raw( $post_id );
				if ( is_array( $loc ) && ! empty( $loc['place_id'] ) ) {
					// Respect per-location rating toggle — default to enabled.
					$enabled = ( ( $loc['rating_enabled'] ?? '1' ) !== '0' );
					if ( $enabled ) {
						return sanitize_text_field( $loc['place_id'] );
					}
					// Disabled: fall through to global default below.
				}
			}
		}
		return trim( (string) get_option( 'myls_google_places_place_id', '' ) );
	}
}

/**
 * [google_review_count class=""]
 */
add_shortcode( 'google_review_count', function ( $atts ) {
	$atts  = shortcode_atts( [ 'class' => '' ], $atts, 'google_review_count' );
	$data  = myls_get_rating_data_for_place( myls_get_current_page_place_id() );
	$count = $data['count'];
	if ( $count === '' ) return '';
	$cls = 'google-review-count' . ( $atts['class'] !== '' ? ' ' . esc_attr( $atts['class'] ) : '' );
	return '<span class="' . esc_attr( $cls ) . '">' . esc_html( $count ) . '</span>';
} );

/**
 * [google_aggregate_rating class=""]
 */
add_shortcode( 'google_aggregate_rating', function ( $atts ) {
	$atts   = shortcode_atts( [ 'class' => '' ], $atts, 'google_aggregate_rating' );
	$data   = myls_get_rating_data_for_place( myls_get_current_page_place_id() );
	$rating = $data['rating'];
	if ( $rating === '' ) return '';
	$rating = number_format( (float) $rating, 1 );
	$cls = 'google-aggregate-rating' . ( $atts['class'] !== '' ? ' ' . esc_attr( $atts['class'] ) : '' );
	return '<span class="' . esc_attr( $cls ) . '">' . esc_html( $rating ) . '</span>';
} );

/** -----------------------------------------------------------------------
 * Helper: render star icons (private to this file to avoid redeclaration
 * conflict with google-reviews-slider.php which defines myls_render_stars
 * inside a non-guarded block).
 * --------------------------------------------------------------------- */
function myls_rating_badge_render_stars( $rating, $color = '#FFD700' ) {
	$full  = floor( $rating );
	$half  = ( $rating - $full ) >= 0.25 ? 1 : 0;
	$empty = 5 - $full - $half;
	$html  = '<span class="myls-gr-stars" style="color:' . esc_attr( $color ) . ';" aria-label="' . esc_attr( $rating . ' out of 5 stars' ) . '">';
	$html .= str_repeat( '&#9733;', $full );
	if ( $half ) $html .= '<span style="opacity:.55;">&#9733;</span>';
	$html .= str_repeat( '<span style="opacity:.25;">&#9733;</span>', $empty );
	$html .= '</span>';
	return $html;
}

/** -----------------------------------------------------------------------
 * [google_rating_badge]
 * --------------------------------------------------------------------- */
/**
 * Shortcode: [google_rating_badge class="" star_color="#FFD700" link="auto" dark="0"]
 *
 * Renders a Google Rating badge widget with the Google "G" logo, aggregate
 * rating, star icons, and review count. Matches the standard Google Rating
 * popup badge style.
 */
add_shortcode( 'google_rating_badge', function ( $atts ) {
	$atts = shortcode_atts( [
		'class'      => '',
		'star_color' => '#FFD700',
		'link'       => 'auto',
		'dark'       => '0',
	], $atts, 'google_rating_badge' );

	// ── Location-aware data resolution ──
	$place_id = myls_get_current_page_place_id();
	$data     = myls_get_rating_data_for_place( $place_id );
	$rating   = $data['rating'];
	$count    = $data['count'];

	if ( $rating === '' || $count === '' ) return '';

	$star_color = sanitize_hex_color( $atts['star_color'] ) ?: '#FFD700';
	$is_dark    = $atts['dark'] === '1';

	// ── Resolve link URL using resolved place_id ──
	$link_url = '';
	if ( $atts['link'] === 'auto' ) {
		$resolved_pid = $data['place_id'];
		if ( $resolved_pid !== '' ) {
			$link_url = 'https://search.google.com/local/reviews?placeid=' . urlencode( $resolved_pid );
		}
	} elseif ( $atts['link'] !== '0' && $atts['link'] !== '' ) {
		$link_url = $atts['link'];
	}

	// ── Inline CSS (once) ──
	static $badge_css_loaded = false;
	$css = '';
	if ( ! $badge_css_loaded ) {
		$badge_css_loaded = true;
		$css = '<style>
.myls-google-rating-badge{display:inline-flex;align-items:flex-start;gap:10px;background:#fff;border:1px solid #e0e0e0;border-top:5px solid #4CAF50;border-radius:8px;padding:12px 16px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;box-shadow:0 2px 8px rgba(0,0,0,.08);line-height:1.3;text-decoration:none;color:inherit;transition:box-shadow .2s}
.myls-google-rating-badge:hover{box-shadow:0 4px 16px rgba(0,0,0,.14)}
.myls-google-rating-badge--dark{background:#303134;border-color:#5f6368;color:#e8eaed}
.myls-google-rating-badge__logo{flex-shrink:0;width:36px;height:36px;margin-top:2px}
.myls-google-rating-badge__body{display:flex;flex-direction:column;gap:2px}
.myls-google-rating-badge__title{font-size:13px;font-weight:600;color:#5f6368}
.myls-google-rating-badge--dark .myls-google-rating-badge__title{color:#9aa0a6}
.myls-google-rating-badge__row{display:flex;align-items:center;gap:6px}
.myls-google-rating-badge__value{font-size:20px;font-weight:700;color:#e7711b}
.myls-google-rating-badge__stars{font-size:18px;letter-spacing:1px;line-height:1}
.myls-google-rating-badge__count{font-size:12px;color:#70757a}
.myls-google-rating-badge--dark .myls-google-rating-badge__count{color:#9aa0a6}
a.myls-google-rating-badge{text-decoration:none;color:inherit}
a.myls-google-rating-badge:hover{color:inherit;text-decoration:none}
</style>';
	}

	// ── Google "G" SVG ──
	$g_svg = '<svg class="myls-google-rating-badge__logo" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59a14.5 14.5 0 0 1 0-9.18l-7.98-6.19a24.0 24.0 0 0 0 0 21.56l7.98-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>';

	// ── Build HTML ──
	$wrapper_cls = 'myls-google-rating-badge';
	if ( $is_dark ) $wrapper_cls .= ' myls-google-rating-badge--dark';
	if ( $atts['class'] !== '' ) $wrapper_cls .= ' ' . esc_attr( $atts['class'] );

	$tag   = $link_url !== '' ? 'a' : 'div';
	$extra = $link_url !== '' ? ' href="' . esc_url( $link_url ) . '" target="_blank" rel="noopener"' : '';

	$html  = $css;
	$html .= '<' . $tag . ' class="' . esc_attr( $wrapper_cls ) . '"' . $extra . '>';
	$html .= $g_svg;
	$html .= '<div class="myls-google-rating-badge__body">';
	$html .= '<span class="myls-google-rating-badge__title">Google Rating</span>';
	$html .= '<div class="myls-google-rating-badge__row">';
	$html .= '<span class="myls-google-rating-badge__value">' . esc_html( $rating ) . '</span>';
	$html .= '<span class="myls-google-rating-badge__stars">' . myls_rating_badge_render_stars( (float) $rating, $star_color ) . '</span>';
	$html .= '</div>';
	$html .= '<span class="myls-google-rating-badge__count">Based on ' . esc_html( $count ) . ' reviews</span>';
	$html .= '</div>';
	$html .= '</' . $tag . '>';

	return $html;
} );
