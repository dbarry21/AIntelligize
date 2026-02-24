<?php
/**
 * AIntelligize – Page Builder Content Extraction
 *
 * Centralized utility for extracting readable text from posts regardless
 * of which editor/page builder created them.
 *
 * Supports:
 *  - Classic Editor / Gutenberg (post_content with HTML)
 *  - DIVI / WPBakery (shortcodes in post_content — tags stripped, content preserved)
 *  - Elementor (_elementor_data JSON in post meta)
 *  - Beaver Builder (_fl_builder_data serialized in post meta)
 *
 * Every function in the plugin that needs to read page content should call
 * myls_get_post_plain_text() or myls_get_post_html() instead of accessing
 * $post->post_content directly.
 *
 * @since 6.3.0.8
 */

if ( ! defined('ABSPATH') ) exit;


/* =========================================================================
 * PUBLIC API — Use these throughout the plugin
 * ========================================================================= */

/**
 * Get clean plain text from any post, regardless of page builder.
 *
 * Priority:
 *  1. post_content with smart shortcode handling (Classic, Gutenberg, DIVI, WPBakery)
 *  2. Elementor _elementor_data JSON
 *  3. Beaver Builder _fl_builder_data
 *
 * @since 6.3.0.8
 *
 * @param int $post_id    The post ID.
 * @param int $max_words  Optional word limit (0 = no limit).
 * @return string          Clean plain text.
 */
if ( ! function_exists('myls_get_post_plain_text') ) {
	function myls_get_post_plain_text( int $post_id, int $max_words = 0 ) : string {
		$post = get_post( $post_id );
		if ( ! $post ) return '';

		$text = '';
		$raw  = (string) $post->post_content;

		// ── 1. Try post_content (Classic, Gutenberg, DIVI, WPBakery) ──
		if ( $raw !== '' ) {
			// Strip shortcode TAGS but keep inner content.
			// Critical for DIVI/WPBakery where strip_shortcodes() destroys text.
			$clean = myls_strip_shortcode_tags( $raw );
			$clean = wp_strip_all_tags( $clean );
			$clean = myls_normalize_whitespace( $clean );

			if ( mb_strlen( $clean ) >= 50 ) {
				$text = $clean;
			}
		}

		// ── 2. Elementor fallback ──
		if ( $text === '' ) {
			$text = myls_extract_elementor_text( $post_id );
		}

		// ── 3. Beaver Builder fallback ──
		if ( $text === '' ) {
			$text = myls_extract_beaver_builder_text( $post_id );
		}

		// ── 4. Last resort: try do_shortcode rendering ──
		if ( $text === '' && $raw !== '' ) {
			$rendered = do_shortcode( $raw );
			$clean    = wp_strip_all_tags( $rendered );
			$clean    = myls_normalize_whitespace( $clean );
			if ( mb_strlen( $clean ) >= 20 ) {
				$text = $clean;
			}
		}

		// Word limit.
		if ( $max_words > 0 && $text !== '' ) {
			$text = wp_trim_words( $text, $max_words, '…' );
		}

		return $text;
	}
}

/**
 * Get the best available HTML representation of a post's content.
 *
 * For content analysis, word counting on HTML, etc. Not for plain text.
 *
 * Priority:
 *  1. post_content with do_shortcode() (Classic, Gutenberg, DIVI, WPBakery)
 *  2. Elementor: render from _elementor_data via their API or raw extraction
 *  3. Beaver Builder: rendered content if available
 *
 * @since 6.3.0.8
 *
 * @param int $post_id  The post ID.
 * @return string        HTML content.
 */
if ( ! function_exists('myls_get_post_html') ) {
	function myls_get_post_html( int $post_id ) : string {
		$post = get_post( $post_id );
		if ( ! $post ) return '';

		$raw = (string) $post->post_content;

		// ── 1. Elementor: use their content rendering if active ──
		if ( myls_post_uses_elementor( $post_id ) ) {
			// If Elementor plugin is active, use its rendering.
			if ( class_exists('\\Elementor\\Plugin') ) {
				$elementor_content = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $post_id );
				if ( ! empty( $elementor_content ) ) {
					return $elementor_content;
				}
			}
			// Fallback: wrap extracted text in paragraphs.
			$text = myls_extract_elementor_text( $post_id );
			if ( $text !== '' ) {
				return '<p>' . nl2br( esc_html( $text ) ) . '</p>';
			}
		}

		// ── 2. Beaver Builder: check for rendered content ──
		if ( myls_post_uses_beaver_builder( $post_id ) ) {
			if ( class_exists('FLBuilderModel') && method_exists('FLBuilderModel', 'is_builder_enabled') ) {
				// BB stores rendered content in post_content on save typically.
				// If post_content has content, do_shortcode on it.
				if ( $raw !== '' ) {
					return do_shortcode( $raw );
				}
			}
			$text = myls_extract_beaver_builder_text( $post_id );
			if ( $text !== '' ) {
				return '<p>' . nl2br( esc_html( $text ) ) . '</p>';
			}
		}

		// ── 3. Standard: do_shortcode on post_content (Classic, Gutenberg, DIVI, WPBakery) ──
		if ( $raw !== '' ) {
			return do_shortcode( $raw );
		}

		return '';
	}
}


/* =========================================================================
 * DETECTION — Which builder does this post use?
 * ========================================================================= */

/**
 * Check if a post was built with Elementor.
 *
 * @since 6.3.0.8
 */
if ( ! function_exists('myls_post_uses_elementor') ) {
	function myls_post_uses_elementor( int $post_id ) : bool {
		// Elementor sets '_elementor_edit_mode' = 'builder' on posts it manages.
		$mode = get_post_meta( $post_id, '_elementor_edit_mode', true );
		if ( $mode === 'builder' ) return true;

		// Also check if _elementor_data exists and is non-empty.
		$data = get_post_meta( $post_id, '_elementor_data', true );
		return ( ! empty($data) && $data !== '[]' );
	}
}

/**
 * Check if a post was built with Beaver Builder.
 *
 * @since 6.3.0.8
 */
if ( ! function_exists('myls_post_uses_beaver_builder') ) {
	function myls_post_uses_beaver_builder( int $post_id ) : bool {
		$enabled = get_post_meta( $post_id, '_fl_builder_enabled', true );
		return ( ! empty($enabled) );
	}
}

/**
 * Check if a post uses DIVI Builder.
 *
 * @since 6.3.0.8
 */
if ( ! function_exists('myls_post_uses_divi') ) {
	function myls_post_uses_divi( int $post_id ) : bool {
		// DIVI sets _et_builder_version when builder is used.
		$ver = get_post_meta( $post_id, '_et_builder_version', true );
		if ( ! empty($ver) ) return true;

		// Also check _et_pb_use_builder = 'on'.
		$use = get_post_meta( $post_id, '_et_pb_use_builder', true );
		return ( $use === 'on' );
	}
}

/**
 * Detect which page builder (if any) a post uses.
 *
 * @since 6.3.0.8
 *
 * @param int $post_id
 * @return string  'elementor'|'beaver_builder'|'divi'|'wpbakery'|'classic'|'gutenberg'
 */
if ( ! function_exists('myls_detect_page_builder') ) {
	function myls_detect_page_builder( int $post_id ) : string {
		if ( myls_post_uses_elementor( $post_id ) )       return 'elementor';
		if ( myls_post_uses_beaver_builder( $post_id ) )  return 'beaver_builder';
		if ( myls_post_uses_divi( $post_id ) )            return 'divi';

		// WPBakery detection: check for [vc_ shortcodes in content.
		$raw = (string) get_post_field( 'post_content', $post_id );
		if ( preg_match('/\[vc_/', $raw ) )               return 'wpbakery';

		// Gutenberg detection: has block comments.
		if ( strpos( $raw, '<!-- wp:' ) !== false )        return 'gutenberg';

		return 'classic';
	}
}


/* =========================================================================
 * EXTRACTION — Builder-specific content parsers
 * ========================================================================= */

/**
 * Strip shortcode TAGS but preserve inner content.
 *
 * Unlike WordPress strip_shortcodes() which removes registered shortcodes
 * AND their content, this only removes the bracket syntax:
 *   [et_pb_text admin_label="Text"]Hello world[/et_pb_text]
 *   → Hello world
 *
 * Critical for DIVI and WPBakery where the real text lives inside shortcodes.
 *
 * @since 6.3.0.8
 *
 * @param string $content  Raw post_content.
 * @return string           Content with shortcode tags removed.
 */
if ( ! function_exists('myls_strip_shortcode_tags') ) {
	function myls_strip_shortcode_tags( string $content ) : string {
		// Remove self-closing shortcodes: [shortcode attr="val" /]
		$content = preg_replace( '/\[\w+[^\]]*\/\]/', '', $content );

		// Remove opening and closing shortcode tags: [tag ...] and [/tag]
		$content = preg_replace( '/\[\/?\w+[^\]]*\]/', '', $content );

		return $content;
	}
}

/**
 * Normalize whitespace: collapse spaces/tabs, limit blank lines.
 *
 * @since 6.3.0.8
 */
if ( ! function_exists('myls_normalize_whitespace') ) {
	function myls_normalize_whitespace( string $text ) : string {
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );
		return trim( $text );
	}
}

/**
 * Extract readable text from Elementor's _elementor_data JSON.
 *
 * Elementor stores page content as a nested JSON array of widget elements.
 * Text lives in widget settings under keys like 'editor', 'title',
 * 'description', 'text', 'html', 'content', etc.
 *
 * @since 6.3.0.8
 *
 * @param int $post_id  The post ID.
 * @return string        Clean plain text, or empty string.
 */
if ( ! function_exists('myls_extract_elementor_text') ) {
	function myls_extract_elementor_text( int $post_id ) : string {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty($raw) ) return '';

		if ( is_string($raw) ) {
			$data = json_decode( $raw, true );
		} else {
			$data = $raw;
		}

		if ( ! is_array($data) ) return '';

		$parts = [];
		myls_walk_elementor_elements( $data, $parts );

		if ( empty($parts) ) return '';

		$text = implode( "\n\n", $parts );
		return myls_normalize_whitespace( $text );
	}
}

/**
 * Recursively walk Elementor element tree and extract text.
 *
 * @since 6.3.0.8
 *
 * @param array  $elements  Array of Elementor element data.
 * @param array  &$parts    Accumulator for extracted text segments.
 */
if ( ! function_exists('myls_walk_elementor_elements') ) {
	function myls_walk_elementor_elements( array $elements, array &$parts ) : void {
		// Settings keys that typically contain user-authored text.
		static $text_keys = [
			'editor',           // Text Editor widget (rich text)
			'title',            // Heading, Accordion, Toggle, Tab titles
			'description',      // Icon Box, Image Box, CTA, etc.
			'text',             // Button text, Alert text
			'html',             // HTML widget
			'content',          // Inner content field
			'item_description', // Price List, Icon List
			'tab_content',      // Tabs widget content
		];

		foreach ( $elements as $el ) {
			if ( ! is_array($el) ) continue;

			// Extract text from widget settings.
			if ( ! empty($el['settings']) && is_array($el['settings']) ) {
				$settings = $el['settings'];

				foreach ( $text_keys as $key ) {
					if ( ! empty($settings[$key]) && is_string($settings[$key]) ) {
						$clean = trim( wp_strip_all_tags( $settings[$key] ) );
						if ( mb_strlen($clean) >= 5 ) {
							$parts[] = $clean;
						}
					}
				}

				// Handle repeater fields (Accordion items, Toggle items, Tabs, etc.).
				foreach ( $settings as $sval ) {
					if ( ! is_array($sval) || empty($sval) ) continue;
					foreach ( $sval as $item ) {
						if ( ! is_array($item) ) continue;
						foreach ( $text_keys as $tkey ) {
							if ( ! empty($item[$tkey]) && is_string($item[$tkey]) ) {
								$clean = trim( wp_strip_all_tags( $item[$tkey] ) );
								if ( mb_strlen($clean) >= 5 ) {
									$parts[] = $clean;
								}
							}
						}
					}
				}
			}

			// Recurse into child elements.
			if ( ! empty($el['elements']) && is_array($el['elements']) ) {
				myls_walk_elementor_elements( $el['elements'], $parts );
			}
		}
	}
}

/**
 * Extract readable text from Beaver Builder's _fl_builder_data.
 *
 * BB stores page data as a serialized PHP array of node objects.
 * Text content lives in nodes of type 'module' under settings fields
 * like 'text', 'html', 'content', 'heading', 'description', etc.
 *
 * @since 6.3.0.8
 *
 * @param int $post_id  The post ID.
 * @return string        Clean plain text, or empty string.
 */
if ( ! function_exists('myls_extract_beaver_builder_text') ) {
	function myls_extract_beaver_builder_text( int $post_id ) : string {
		$data = get_post_meta( $post_id, '_fl_builder_data', true );
		if ( empty($data) || ! is_array($data) ) return '';

		static $text_fields = [
			'text', 'html', 'content', 'heading', 'description',
			'title', 'editor', 'tab_content', 'label',
		];

		$parts = [];

		foreach ( $data as $node ) {
			if ( ! is_object($node) && ! is_array($node) ) continue;
			$node = (array) $node;

			// BB stores module settings in 'settings' key.
			$settings = isset($node['settings']) ? (array) $node['settings'] : [];

			foreach ( $text_fields as $field ) {
				if ( ! empty($settings[$field]) && is_string($settings[$field]) ) {
					$clean = trim( wp_strip_all_tags( $settings[$field] ) );
					if ( mb_strlen($clean) >= 5 ) {
						$parts[] = $clean;
					}
				}
			}
		}

		if ( empty($parts) ) return '';

		$text = implode( "\n\n", $parts );
		return myls_normalize_whitespace( $text );
	}
}
