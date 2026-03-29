<?php
/**
 * AIntelligize — Video Object Auto-Detector
 * File: inc/schema/providers/video-object-detector.php
 *
 * Scans the current singular page for embedded videos across every major
 * page builder and emits VideoObject JSON-LD nodes into the schema graph.
 *
 * Supported sources:
 *   • Elementor  — video widget, section/container background video
 *   • Elementor Theme Builder — applied templates matched via conditions
 *   • Beaver Builder — video module (_fl_builder_data)
 *   • Divi — [et_pb_video] shortcode in post_content
 *   • WPBakery — [vc_video] shortcode in post_content
 *   • Gutenberg — <!-- wp:embed --> blocks (YouTube/Vimeo) + <!-- wp:video -->
 *   • Classic / fallback — <iframe> src scan + bare YouTube/Vimeo URLs in content
 *
 * Duration: auto-fetched from YouTube Data API v3 (key: myls_youtube_api_key).
 *           Cached in transient myls_yt_dur_{video_id} for 30 days.
 *           Vimeo duration requires Vimeo API — stored manually via meta for now.
 *
 * Toggle:   add_filter('myls_video_object_detector_enabled', '__return_false');
 *
 * @since 7.8.74
 */

if ( ! defined('ABSPATH') ) exit;


/* =========================================================================
 * SECTION 1 — URL UTILITIES
 * Extract video IDs from any YouTube or Vimeo URL.
 * ========================================================================= */

if ( ! function_exists('myls_extract_youtube_id') ) {
	/**
	 * Extract YouTube video ID from any valid YouTube URL format.
	 *
	 * Handles: youtube.com/watch?v=ID, youtu.be/ID, youtube.com/embed/ID,
	 *          youtube.com/shorts/ID, youtube.com/v/ID
	 *
	 * @param  string $url
	 * @return string  Video ID, or '' if not a YouTube URL.
	 */
	function myls_extract_youtube_id( string $url ) : string {
		$url = trim($url);
		if ( $url === '' ) return '';

		// youtu.be/VIDEO_ID
		if ( preg_match( '~youtu\.be/([a-zA-Z0-9_\-]{11})~', $url, $m ) ) return $m[1];

		// youtube.com/... ?v=VIDEO_ID or /embed/VIDEO_ID or /shorts/VIDEO_ID or /v/VIDEO_ID
		if ( preg_match( '~youtube(?:-nocookie)?\.com/(?:watch\?(?:[^#&]*&)*v=|embed/|shorts/|v/)([a-zA-Z0-9_\-]{11})~', $url, $m ) ) {
			return $m[1];
		}

		return '';
	}
}

if ( ! function_exists('myls_extract_vimeo_id') ) {
	/**
	 * Extract Vimeo video ID from any Vimeo URL.
	 *
	 * Handles: vimeo.com/ID, player.vimeo.com/video/ID
	 *
	 * @param  string $url
	 * @return string  Video ID, or '' if not a Vimeo URL.
	 */
	function myls_extract_vimeo_id( string $url ) : string {
		$url = trim($url);
		if ( $url === '' ) return '';
		if ( preg_match( '~vimeo\.com/(?:video/)?(\d+)~', $url, $m ) ) return $m[1];
		return '';
	}
}

if ( ! function_exists('myls_classify_video_url') ) {
	/**
	 * Classify a URL and extract the relevant ID.
	 *
	 * @param  string $url
	 * @return array{source:string,video_id:string}|null  null if unrecognized.
	 */
	function myls_classify_video_url( string $url ) : ?array {
		$yt = myls_extract_youtube_id($url);
		if ( $yt !== '' ) return [ 'source' => 'youtube', 'video_id' => $yt ];

		$vim = myls_extract_vimeo_id($url);
		if ( $vim !== '' ) return [ 'source' => 'vimeo', 'video_id' => $vim ];

		// Self-hosted — must be a direct media URL
		if ( preg_match( '~\.(mp4|webm|ogv|ogg|mov)(\?|$)~i', $url ) ) {
			return [ 'source' => 'hosted', 'video_id' => '' ];
		}

		return null;
	}
}


/* =========================================================================
 * SECTION 2 — YOUTUBE DURATION API
 * Fetch ISO 8601 duration from YouTube Data API v3 with transient caching.
 * ========================================================================= */

if ( ! function_exists('myls_fetch_youtube_duration') ) {
	/**
	 * Fetch a video's ISO 8601 duration string from YouTube Data API v3.
	 *
	 * Result is cached in a 30-day transient to minimise API quota usage.
	 * Returns '' if the API key is not set, the request fails, or the
	 * video is not found.
	 *
	 * @param  string $video_id  YouTube video ID (11 chars).
	 * @return string            ISO 8601 duration e.g. "PT3M21S", or ''.
	 */
	function myls_fetch_youtube_duration( string $video_id ) : string {
		if ( $video_id === '' ) return '';

		// Sanitise the ID to prevent cache-key injection.
		$safe_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $video_id);
		if ( $safe_id === '' ) return '';

		$transient_key = 'myls_yt_dur_' . $safe_id;

		// Return cached value (including cached empty string sentinel '~none~').
		$cached = get_transient($transient_key);
		if ( $cached !== false ) {
			return ( $cached === '~none~' ) ? '' : (string) $cached;
		}

		$api_key = trim( (string) get_option('myls_youtube_api_key', '') );
		if ( $api_key === '' ) {
			// No key — cache miss sentinel for 24 hours so we don't retry constantly.
			set_transient($transient_key, '~none~', DAY_IN_SECONDS);
			return '';
		}

		$endpoint = add_query_arg([
			'part' => 'contentDetails',
			'id'   => rawurlencode($safe_id),
			'key'  => $api_key,
		], 'https://www.googleapis.com/youtube/v3/videos');

		$response = wp_remote_get( $endpoint, [
			'timeout'    => 5,
			'user-agent' => 'AIntelligize/' . ( defined('MYLS_VERSION') ? MYLS_VERSION : '1.0' ),
		]);

		if ( is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200 ) {
			set_transient($transient_key, '~none~', HOUR_IN_SECONDS);
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body($response), true );
		$duration = (string) ( $body['items'][0]['contentDetails']['duration'] ?? '' );

		if ( $duration !== '' && preg_match('/^PT/', $duration) ) {
			// Cache for 30 days — duration rarely changes.
			set_transient($transient_key, $duration, 30 * DAY_IN_SECONDS);
			return $duration;
		}

		set_transient($transient_key, '~none~', DAY_IN_SECONDS);
		return '';
	}
}


/* =========================================================================
 * SECTION 3 — NORMALIZED VIDEO ITEM BUILDER
 * Converts raw URL + metadata into a consistent associative array used
 * by every extractor. The schema builder then maps this to VideoObject.
 * ========================================================================= */

if ( ! function_exists('myls_make_video_item') ) {
	/**
	 * Build a normalized video item array from a raw URL and optional metadata.
	 *
	 * @param  string $url         Raw video URL (YouTube, Vimeo, or hosted MP4).
	 * @param  string $caption     Widget caption / title hint.
	 * @param  string $description Widget description hint.
	 * @param  string $thumb_url   Explicit thumbnail override URL.
	 * @param  int    $post_id     Owning post ID (for uploadDate fallback).
	 * @return array|null          Normalized item, or null if URL unrecognized.
	 */
	function myls_make_video_item(
		string $url,
		string $caption     = '',
		string $description = '',
		string $thumb_url   = '',
		int    $post_id     = 0
	) : ?array {
		$classified = myls_classify_video_url($url);
		if ( ! $classified ) return null;

		[ 'source' => $source, 'video_id' => $video_id ] = $classified;

		// ── URLs ────────────────────────────────────────────────────────────
		$watch_url = $url;
		$embed_url = '';
		$thumbnail = $thumb_url;

		if ( $source === 'youtube' && $video_id !== '' ) {
			$watch_url = 'https://www.youtube.com/watch?v=' . rawurlencode($video_id);
			$embed_url = 'https://www.youtube.com/embed/'  . rawurlencode($video_id);
			if ( $thumbnail === '' ) {
				// Prefer stored meta via local video post, then construct from video ID
				$local_pid = function_exists('myls_yt_find_video_post_id') ? myls_yt_find_video_post_id( $video_id ) : 0;
				$thumbnail = function_exists('myls_yt_thumbnail_url') ? myls_yt_thumbnail_url( $video_id, $local_pid ) : 'https://i.ytimg.com/vi/' . rawurlencode($video_id) . '/hqdefault.jpg';
			}
		} elseif ( $source === 'vimeo' && $video_id !== '' ) {
			$watch_url = 'https://vimeo.com/' . $video_id;
			$embed_url = 'https://player.vimeo.com/video/' . $video_id;
		}

		// ── Duration ────────────────────────────────────────────────────────
		$duration = '';
		if ( $source === 'youtube' && $video_id !== '' ) {
			$duration = myls_fetch_youtube_duration($video_id);
		}

		// ── Upload date: post publish date as fallback ────────────────────
		$upload_date = '';
		if ( $post_id > 0 ) {
			$upload_date = get_the_date('c', $post_id) ?: '';
		}

		return [
			'source'      => $source,
			'video_id'    => $video_id,
			'url'         => esc_url_raw($watch_url),
			'embed_url'   => $embed_url ? esc_url_raw($embed_url) : '',
			'thumbnail'   => $thumbnail ? esc_url_raw($thumbnail) : '',
			'caption'     => sanitize_text_field($caption),
			'description' => wp_strip_all_tags($description),
			'upload_date' => $upload_date,
			'duration'    => $duration,
		];
	}
}


/* =========================================================================
 * SECTION 4 — ELEMENTOR EXTRACTOR
 * Walk _elementor_data JSON for video widgets and background videos.
 * Also handles Elementor Theme Builder templates matched to the current page.
 * ========================================================================= */

if ( ! function_exists('myls_extract_videos_elementor_data') ) {
	/**
	 * Walk a decoded Elementor element tree and collect video items.
	 *
	 * Handles:
	 *   - widgetType 'video'         — youtube_url / vimeo_url / hosted_url
	 *   - widgetType 'video-playlist' — playlist items array (Elementor Pro)
	 *   - background_video_link      — any element's settings (section / container)
	 *   - background_video_mp4       — self-hosted background video
	 *
	 * @param  array $elements  Decoded Elementor element array.
	 * @param  int   $post_id   Owning post ID.
	 * @return array            Array of normalized video items.
	 */
	function myls_extract_videos_elementor_data( array $elements, int $post_id ) : array {
		$found = [];

		foreach ( $elements as $el ) {
			if ( ! is_array($el) ) continue;

			// Skip elements hidden in Elementor (deleted widget data can persist)
			$settings    = is_array($el['settings'] ?? null) ? $el['settings'] : [];
			if ( ! empty( $settings['_element_hidden'] ) ) continue;

			$widget_type = (string) ($el['widgetType'] ?? '');

			// ── Video widget ─────────────────────────────────────────────
			if ( $widget_type === 'video' ) {
				$type      = (string) ($settings['video_type'] ?? 'youtube');
				$caption   = (string) ($settings['caption']   ?? '');
				$thumb_id  = (int)    ($settings['image_overlay']['id'] ?? 0);
				$thumb_url = $thumb_id ? (string) wp_get_attachment_image_url($thumb_id, 'large') : '';

				$raw_url = '';
				switch ($type) {
					case 'youtube':
						$raw_url = (string) ($settings['youtube_url'] ?? '');
						break;
					case 'vimeo':
						$raw_url = (string) ($settings['vimeo_url'] ?? '');
						break;
					case 'hosted':
						// Elementor stores hosted URL either in 'hosted_url' (media obj) or direct string.
						$hosted = $settings['hosted_url'] ?? '';
						$raw_url = is_array($hosted) ? (string) ($hosted['url'] ?? '') : (string) $hosted;
						break;
					case 'dailymotion':
						$raw_url = (string) ($settings['dailymotion_url'] ?? '');
						break;
				}

				if ( $raw_url !== '' ) {
					$item = myls_make_video_item($raw_url, $caption, '', $thumb_url, $post_id);
					if ( $item ) $found[] = $item;
				}
			}

			// ── Video Playlist (Elementor Pro) ────────────────────────────
			if ( $widget_type === 'video-playlist' ) {
				$tabs = is_array($settings['tabs'] ?? null) ? $settings['tabs'] : [];
				foreach ( $tabs as $tab ) {
					$raw_url = (string) ($tab['youtube_url'] ?? $tab['vimeo_url'] ?? '');
					$caption = (string) ($tab['tab_title'] ?? '');
					if ( $raw_url !== '' ) {
						$item = myls_make_video_item($raw_url, $caption, '', '', $post_id);
						if ( $item ) $found[] = $item;
					}
				}
			}

			// ── HTML widget / Text Editor widget — scan for iframes ──────
			// widgetType 'html' stores raw markup in settings['html'].
			// widgetType 'text-editor' stores rich text in settings['editor'].
			// Both can contain manually embedded <iframe> YouTube/Vimeo embeds.
			$html_content = '';
			if ( $widget_type === 'html' ) {
				$html_content = (string) ($settings['html'] ?? '');
			} elseif ( $widget_type === 'text-editor' ) {
				$html_content = (string) ($settings['editor'] ?? '');
			}
			if ( $html_content !== '' ) {
				// Extract <iframe src="..."> attributes.
				if ( preg_match_all('/<iframe[^>]+\bsrc=["\']([^"\']+)["\'][^>]*>/i', $html_content, $iframe_m) ) {
					foreach ( $iframe_m[1] as $iframe_src ) {
						$item = myls_make_video_item($iframe_src, '', '', '', $post_id);
						if ( $item ) $found[] = $item;
					}
				}
			}
			// ── Shortcode widget — scan for [myls_youtube_embed] ─────────
			// widgetType 'shortcode' stores the raw shortcode in settings['shortcode'].
			if ( $widget_type === 'shortcode' ) {
				$sc_content = (string) ($settings['shortcode'] ?? '');
				if ( $sc_content !== '' && preg_match_all(
					'/\[myls_youtube_embed\s[^\]]*\bvideo_id=["\']([a-zA-Z0-9_\-]{11})["\']/',
					$sc_content,
					$sc_m
				) ) {
					foreach ( $sc_m[1] as $vid_id ) {
						$item = myls_make_video_item(
							'https://www.youtube.com/watch?v=' . rawurlencode( $vid_id ),
							'', '', '', $post_id
						);
						if ( $item ) $found[] = $item;
					}
				}
			}

			$bg_link = (string) ($settings['background_video_link'] ?? '');
			if ( $bg_link !== '' ) {
				$item = myls_make_video_item($bg_link, '', '', '', $post_id);
				if ( $item ) $found[] = $item;
			}
			$bg_mp4 = (string) ($settings['background_video_mp4'] ?? '');
			if ( $bg_mp4 !== '' ) {
				$item = myls_make_video_item($bg_mp4, '', '', '', $post_id);
				if ( $item ) $found[] = $item;
			}

			// ── Recurse into child elements ───────────────────────────────
			if ( ! empty($el['elements']) && is_array($el['elements']) ) {
				$child_items = myls_extract_videos_elementor_data($el['elements'], $post_id);
				$found       = array_merge($found, $child_items);
			}
		}

		return $found;
	}
}

if ( ! function_exists('myls_elementor_condition_matches_post') ) {
	/**
	 * Check whether a single Elementor Theme Builder condition matches the
	 * current request context.
	 *
	 * Elementor stores conditions as slash-delimited strings:
	 *   "include/general"
	 *   "include/singular/front_page"
	 *   "include/singular/page/123"
	 *
	 * Older or custom formats may also pass an associative array with
	 * keys 'type', 'sub_type', 'sub_id' — both are handled.
	 *
	 * @param  string|array $condition  Single condition (string or array).
	 * @param  int          $post_id    Current post/page ID.
	 * @return bool
	 */
	function myls_elementor_condition_matches_post( $condition, int $post_id ) : bool {

		// ── Normalise to type / sub_type / sub_id ─────────────────────────
		if ( is_string($condition) ) {
			// "include/singular/page/123"  or  "include/general"
			$parts    = explode('/', trim($condition), 4);
			$type     = $parts[0] ?? '';
			$sub_type = $parts[1] ?? '';
			$sub_id   = isset($parts[2]) ? implode('/', array_slice($parts, 2)) : '';
		} elseif ( is_array($condition) ) {
			$type     = (string) ($condition['type']     ?? '');
			$sub_type = (string) ($condition['sub_type'] ?? '');
			$sub_id   = (string) ($condition['sub_id']   ?? '');
		} else {
			return false;
		}

		// Only process include conditions.
		if ( $type !== 'include' ) return false;

		// ── Match sub_type ────────────────────────────────────────────────
		// 'general' — site-wide.
		if ( $sub_type === 'general' || $sub_type === '' ) return true;

		if ( $sub_type === 'singular' ) {
			if ( $sub_id === '' )            return is_singular();
			if ( $sub_id === 'front_page' )  return is_front_page();
			if ( $sub_id === 'home' )        return is_home();

			// "page/123" — specific post ID match.
			$id_parts  = explode('/', $sub_id, 2);
			$post_type = $id_parts[0];
			$specific  = isset($id_parts[1]) ? (int) $id_parts[1] : 0;

			if ( $specific > 0 ) return ( (int) $post_id === $specific );
			return is_singular($post_type);
		}

		if ( $sub_type === 'page' ) {
			if ( $sub_id !== '' ) return is_page((int) $sub_id);
			return is_page();
		}

		if ( $sub_type === 'front_page' ) return is_front_page();
		if ( $sub_type === 'home' )       return is_home();
		if ( $sub_type === 'posts_page' ) return is_home();
		if ( $sub_type === 'archive' )    return is_archive();
		if ( $sub_type === 'category' )   return is_category($sub_id ?: null);
		if ( $sub_type === 'tag' )        return is_tag($sub_id ?: null);

		return false;
	}
}

if ( ! function_exists('myls_get_applicable_elementor_templates') ) {
	/**
	 * Query all published Elementor Theme Builder templates and return IDs
	 * whose _elementor_conditions meta matches the current page context.
	 *
	 * Results are cached per page in a request-scoped static variable so
	 * the WP_Query only runs once even if called from multiple hooks.
	 *
	 * @param  int $post_id  Current page post ID.
	 * @return int[]          Array of matching elementor_library post IDs.
	 */
	function myls_get_applicable_elementor_templates( int $post_id ) : array {
		static $cache = [];

		if ( isset($cache[$post_id]) ) return $cache[$post_id];

		if ( ! post_type_exists('elementor_library') ) {
			$cache[$post_id] = [];
			return [];
		}

		// Only query template subtypes that can contain video:
		// header, footer, single, archive, section, popup.
		$templates = get_posts([
			'post_type'      => 'elementor_library',
			'post_status'    => 'publish',
			'posts_per_page' => 50, // safety cap
			'fields'         => 'ids',
			'meta_query'     => [[
				'key'     => '_elementor_conditions',
				'compare' => 'EXISTS',
			]],
			// Do not trigger Elementor hooks unnecessarily.
			'suppress_filters' => true,
		]);

		$matching = [];

		foreach ( $templates as $tpl_id ) {
			// WP auto-unserializes the meta value — it arrives as a PHP array
			// of slash-delimited condition strings e.g. ["include/general"].
			// No json_decode needed.
			$conditions = get_post_meta($tpl_id, '_elementor_conditions', true);
			if ( ! is_array($conditions) || empty($conditions) ) continue;

			foreach ( $conditions as $condition ) {
				if ( myls_elementor_condition_matches_post($condition, $post_id) ) {
					$matching[] = (int) $tpl_id;
					break;
				}
			}
		}

		$cache[$post_id] = $matching;
		return $matching;
	}
}


/* =========================================================================
 * SECTION 5 — BEAVER BUILDER EXTRACTOR
 * Walk _fl_builder_data (unserialized array of node objects).
 * ========================================================================= */

if ( ! function_exists('myls_extract_videos_beaver_builder') ) {
	/**
	 * Extract video items from Beaver Builder module data.
	 *
	 * BB video module stores video URL in settings->video_type (youtube/vimeo/media_library)
	 * and settings->video_url / settings->video (media ID) / settings->video_service_url.
	 *
	 * @param  int $post_id
	 * @return array  Normalized video items.
	 */
	function myls_extract_videos_beaver_builder( int $post_id ) : array {
		$data = get_post_meta($post_id, '_fl_builder_data', true);
		if ( empty($data) || ! is_array($data) ) return [];

		$found = [];

		foreach ( $data as $node ) {
			if ( ! is_object($node) && ! is_array($node) ) continue;
			$node = (array) $node;

			$type     = (string) ($node['type']  ?? '');
			$module   = (string) ($node['attrs']['type'] ?? $node['module'] ?? '');
			$settings = isset($node['settings']) ? (array) $node['settings'] : [];

			// BB video module is type='module' with settings->type='video'.
			if ( $type !== 'module' ) continue;

			// ── Standard video module ─────────────────────────────────────
			if ( isset($settings['video_type']) ) {
				$vtype   = (string) ($settings['video_type'] ?? '');
				$raw_url = '';

				switch ($vtype) {
					case 'youtube':
					case 'vimeo':
						$raw_url = (string) ($settings['video_service_url'] ?? $settings['video_url'] ?? '');
						break;
					case 'media_library':
					case 'video':
						// Media library item — settings->video is the attachment ID.
						$att_id  = (int) ($settings['video'] ?? 0);
						$raw_url = $att_id ? (string) wp_get_attachment_url($att_id) : '';
						break;
				}

				if ( $raw_url !== '' ) {
					$caption = (string) ($settings['video_title'] ?? '');
					$item    = myls_make_video_item($raw_url, $caption, '', '', $post_id);
					if ( $item ) $found[] = $item;
				}
			}
		}

		return $found;
	}
}


/* =========================================================================
 * SECTION 6 — DIVI EXTRACTOR
 * Parse [et_pb_video] shortcodes from post_content.
 * ========================================================================= */

if ( ! function_exists('myls_extract_videos_divi') ) {
	/**
	 * Extract video items from Divi Builder [et_pb_video] shortcodes.
	 *
	 * Divi stores video in: src="URL" for YouTube/Vimeo, or src_webm / src_mp4
	 * for self-hosted. Title comes from the admin_label attribute.
	 *
	 * @param  string $content  Raw post_content.
	 * @param  int    $post_id
	 * @return array
	 */
	function myls_extract_videos_divi( string $content, int $post_id ) : array {
		if ( $content === '' ) return [];

		// Match [et_pb_video ...] and [et_pb_video_slider_item ...].
		if ( ! preg_match_all('/\[et_pb_video(?:_slider_item)?\s([^\]]+)\]/i', $content, $matches) ) {
			return [];
		}

		$found = [];

		foreach ( $matches[1] as $attrs_raw ) {
			// Extract src attribute.
			$raw_url = '';
			if ( preg_match('/\bsrc=["\']([^"\']+)["\']/', $attrs_raw, $m) ) {
				$raw_url = $m[1];
			}
			// Self-hosted fallback: src_mp4.
			if ( $raw_url === '' && preg_match('/\bsrc_mp4=["\']([^"\']+)["\']/', $attrs_raw, $m) ) {
				$raw_url = $m[1];
			}

			$caption = '';
			if ( preg_match('/\badmin_label=["\']([^"\']+)["\']/', $attrs_raw, $m) ) {
				$caption = $m[1];
			}

			if ( $raw_url !== '' ) {
				$item = myls_make_video_item($raw_url, $caption, '', '', $post_id);
				if ( $item ) $found[] = $item;
			}
		}

		return $found;
	}
}


/* =========================================================================
 * SECTION 7 — WPBAKERY EXTRACTOR
 * Parse [vc_video] shortcodes from post_content.
 * ========================================================================= */

if ( ! function_exists('myls_extract_videos_wpbakery') ) {
	/**
	 * Extract video items from WPBakery [vc_video link="URL"] shortcodes.
	 *
	 * @param  string $content  Raw post_content.
	 * @param  int    $post_id
	 * @return array
	 */
	function myls_extract_videos_wpbakery( string $content, int $post_id ) : array {
		if ( $content === '' ) return [];
		if ( ! preg_match_all('/\[vc_video\s([^\]]+)\]/i', $content, $matches) ) {
			return [];
		}

		$found = [];

		foreach ( $matches[1] as $attrs_raw ) {
			$raw_url = '';
			if ( preg_match('/\blink=["\']([^"\']+)["\']/', $attrs_raw, $m) ) {
				$raw_url = $m[1];
			}

			if ( $raw_url !== '' ) {
				$item = myls_make_video_item($raw_url, '', '', '', $post_id);
				if ( $item ) $found[] = $item;
			}
		}

		return $found;
	}
}


/* =========================================================================
 * SECTION 8 — GUTENBERG / CLASSIC / FALLBACK EXTRACTOR
 * Handles Gutenberg wp:embed blocks, wp:video blocks, and raw <iframe> tags.
 * ========================================================================= */

if ( ! function_exists('myls_extract_videos_content') ) {
	/**
	 * Extract video items from raw post_content covering:
	 *   - <!-- wp:embed --> blocks (Gutenberg YouTube/Vimeo oEmbeds)
	 *   - <!-- wp:video --> blocks (Gutenberg self-hosted)
	 *   - <iframe> src attributes (Classic Editor embeds)
	 *   - Bare YouTube/Vimeo URLs on their own line (auto-embed)
	 *
	 * @param  string $content  Raw post_content.
	 * @param  int    $post_id
	 * @return array
	 */
	function myls_extract_videos_content( string $content, int $post_id ) : array {
		if ( $content === '' ) return [];

		$found    = [];
		$seen_ids = []; // de-duplicate by video_id/url

		// ── Helper: add item with de-dup ─────────────────────────────────
		$add = function( string $url, string $caption = '' ) use ( $post_id, &$found, &$seen_ids ) {
			$item = myls_make_video_item($url, $caption, '', '', $post_id);
			if ( ! $item ) return;
			$dedup_key = $item['video_id'] ?: $item['url'];
			if ( isset($seen_ids[$dedup_key]) ) return;
			$seen_ids[$dedup_key] = true;
			$found[] = $item;
		};

		// ── 1. Gutenberg wp:embed with provider url ───────────────────────
		// <!-- wp:embed {"url":"https://youtu.be/...", "type":"video", ...} -->
		if ( preg_match_all('/<!--\s*wp:embed\s*(\{[^}]+\})/i', $content, $m) ) {
			foreach ( $m[1] as $json_frag ) {
				$block_attrs = json_decode($json_frag, true);
				if ( is_array($block_attrs) && ! empty($block_attrs['url']) ) {
					$add( (string) $block_attrs['url'] );
				}
			}
		}

		// ── 2. Gutenberg wp:video block — src in inner HTML ───────────────
		if ( preg_match_all('/<!--\s*wp:video[^>]*?-->(.*?)<!--\s*\/wp:video\s*-->/si', $content, $m) ) {
			foreach ( $m[1] as $block_html ) {
				if ( preg_match('/\bsrc=["\']([^"\']+\.(?:mp4|webm|ogv|ogg|mov))["\']/', $block_html, $src) ) {
					$add($src[1]);
				}
			}
		}

		// ── 3. <iframe> src attributes ────────────────────────────────────
		if ( preg_match_all('/<iframe[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $m) ) {
			foreach ( $m[1] as $src ) {
				$add($src);
			}
		}

		// ── 4. Bare YouTube/Vimeo URLs on their own line (auto-embed) ─────
		// Classic editor and some themes support this natively.
		if ( preg_match_all('/^\s*(https?:\/\/(?:www\.)?(?:youtube(?:-nocookie)?\.com|youtu\.be|vimeo\.com)\/[^\s<>"\']+)\s*$/im', $content, $m) ) {
			foreach ( $m[1] as $url ) {
				$add(trim($url));
			}
		}

		// ── 5. [myls_youtube_embed video_id="..."] shortcode ──────────────
		if ( preg_match_all('/\[myls_youtube_embed\s[^\]]*\bvideo_id=["\']([a-zA-Z0-9_\-]{11})["\']/', $content, $m) ) {
			foreach ( $m[1] as $vid_id ) {
				$add( 'https://www.youtube.com/watch?v=' . rawurlencode( $vid_id ) );
			}
		}

		return $found;
	}
}


/* =========================================================================
 * SECTION 9 — MAIN DETECTOR
 * Orchestrates all extractors for the current post, de-duplicates, and
 * returns a final flat array of normalized video items.
 * ========================================================================= */

if ( ! function_exists('myls_detect_videos_in_post') ) {
	/**
	 * Detect all videos on a given post/page across all supported builders.
	 *
	 * Runs every applicable extractor and merges results, de-duplicating
	 * by video_id (for hosted platforms) or URL (for self-hosted).
	 *
	 * Filter 'myls_detected_video_items' allows last-mile overrides:
	 *   add_filter('myls_detected_video_items', function($items, $post_id){ ... }, 10, 2);
	 *
	 * @param  int $post_id
	 * @return array  Array of normalized video item arrays.
	 */
	function myls_detect_videos_in_post( int $post_id ) : array {
		$all  = [];
		$seen = [];

		// De-dup helper — uses video_id for platform videos, URL for hosted.
		$merge = function( array $items ) use ( &$all, &$seen ) {
			foreach ( $items as $item ) {
				$key = $item['video_id'] !== '' ? $item['video_id'] : $item['url'];
				if ( $key === '' || isset($seen[$key]) ) continue;
				$seen[$key] = true;
				$all[]      = $item;
			}
		};

		$builder     = function_exists('myls_detect_page_builder')
			? myls_detect_page_builder($post_id)
			: 'unknown';
		$raw_content = (string) get_post_field('post_content', $post_id);

		// ── Elementor: page-level data ────────────────────────────────────
		if ( $builder === 'elementor' || myls_post_uses_elementor($post_id) ) {
			$raw = get_post_meta($post_id, '_elementor_data', true);
			$data = is_string($raw) ? json_decode($raw, true) : $raw;
			if ( is_array($data) ) {
				$merge( myls_extract_videos_elementor_data($data, $post_id) );
			}
		}

		// ── Elementor Theme Builder: applied templates ────────────────────
		$tpl_ids = myls_get_applicable_elementor_templates($post_id);
		foreach ( $tpl_ids as $tpl_id ) {
			$raw  = get_post_meta($tpl_id, '_elementor_data', true);
			$data = is_string($raw) ? json_decode($raw, true) : $raw;
			if ( is_array($data) ) {
				$merge( myls_extract_videos_elementor_data($data, $post_id) );
			}
		}

		// ── Beaver Builder ────────────────────────────────────────────────
		if ( $builder === 'beaver_builder' || myls_post_uses_beaver_builder($post_id) ) {
			$merge( myls_extract_videos_beaver_builder($post_id) );
		}

		// ── Divi ─────────────────────────────────────────────────────────
		if ( $builder === 'divi' || myls_post_uses_divi($post_id) ) {
			$merge( myls_extract_videos_divi($raw_content, $post_id) );
		}

		// ── WPBakery ──────────────────────────────────────────────────────
		if ( $builder === 'wpbakery' ) {
			$merge( myls_extract_videos_wpbakery($raw_content, $post_id) );
		}

		// ── Gutenberg / Classic / fallback — always run ───────────────────
		// Catches any remaining embeds not handled above (mixed builders,
		// manually embedded iframes, auto-embed URLs, etc.).
		$merge( myls_extract_videos_content($raw_content, $post_id) );

		// ── Cross-validation REMOVED (v7.9.16) ─────────────────────────
		// Previously re-rendered the page content via apply_filters('the_content')
		// or Elementor's get_builder_content() to filter out phantom videos.
		// This caused nested content rendering that corrupted Elementor's
		// Loop Grid state, breaking flip-box widgets and other loop-based
		// elements on the page. Removed entirely — phantom videos in schema
		// are a minor SEO nuisance; broken page layout is not acceptable.

		return apply_filters('myls_detected_video_items', $all, $post_id);
	}
}


/* =========================================================================
 * SECTION 10 — SCHEMA BUILDER
 * Convert a normalized video item into a schema.org VideoObject node.
 * ========================================================================= */

if ( ! function_exists('myls_build_video_object_node') ) {
	/**
	 * Build a schema.org VideoObject array from a normalized video item.
	 *
	 * Publisher details are pulled from org options so the node is consistent
	 * with other schema nodes on the page.
	 *
	 * @param  array $item     Normalized video item from myls_detect_videos_in_post().
	 * @param  int   $post_id  Post the video was found on.
	 * @param  int   $index    0-based index for @id uniqueness on multi-video pages.
	 * @return array           VideoObject schema array.
	 */
	function myls_build_video_object_node( array $item, int $post_id, int $index = 0 ) : array {
		$post_title = (string) get_the_title($post_id);

		// Look up admin-configured name and transcript for this video
		$admin_entries = get_option( 'myls_video_entries', [] );
		$admin_name    = '';
		$admin_trans   = '';
		if ( is_array( $admin_entries ) && $item['video_id'] !== '' ) {
			foreach ( $admin_entries as $ae ) {
				if ( ! is_array( $ae ) ) continue;
				if ( ( $ae['video_id'] ?? '' ) === $item['video_id'] ) {
					$admin_name  = trim( $ae['name'] ?? '' );
					$admin_trans = trim( $ae['transcript'] ?? '' );
					break;
				}
			}
		}

		// Name priority: admin entry → widget caption → post title (with index for uniqueness)
		$name = $admin_name;
		if ( $name === '' ) {
			$name = $item['caption'] !== '' ? $item['caption'] : ( $post_title ?: 'Video' );
		}
		// Ensure uniqueness on multi-video pages when falling back to post title
		if ( $name === $post_title && $index > 0 ) {
			$name .= ' — Video ' . ( $index + 1 );
		}

		// Description: widget description → post excerpt → name.
		$desc = $item['description'];
		if ( $desc === '' ) {
			$desc = has_excerpt($post_id)
				? (string) get_the_excerpt($post_id)
				: $post_title;
		}

		// For YouTube/Vimeo use the watch URL as @id (canonical platform URL).
		// For self-hosted MP4s, scope @id to the current site to avoid referencing
		// external/CDN domains — the URL property still holds the actual file location.
		if ( $item['source'] === 'hosted' ) {
			$page_url  = get_permalink( $post_id ) ?: home_url( '/' );
			$id_suffix = $index > 0 ? '#video-' . $index : '#video';
			$at_id     = trailingslashit( $page_url ) . $id_suffix;
		} else {
			$at_id = $item['url'] . ( $index > 0 ? '#video-' . $index : '#video' );
		}

		$node = [
			'@type'       => 'VideoObject',
			'@id'         => $at_id,
			'name'        => $name,
			'description' => $desc,
			'uploadDate'  => $item['upload_date'],
			'url'         => $item['url'],
		];

		if ( $item['thumbnail'] !== '' ) {
			$node['thumbnailUrl'] = $item['thumbnail'];
			$node['image']        = $item['thumbnail']; // Google recommends mirroring
		}

		if ( $item['embed_url'] !== '' ) $node['embedUrl']  = $item['embed_url'];
		if ( $item['duration']  !== '' ) $node['duration']  = $item['duration'];

		// Fallback: cache table transcript when admin entry has none
		if ( $admin_trans === '' && function_exists('myls_vt_get_by_id') && $item['video_id'] !== '' ) {
			$vt_row = myls_vt_get_by_id( $item['video_id'] );
			if ( $vt_row && $vt_row['status'] === 'ok' && ! empty($vt_row['transcript']) ) {
				$admin_trans = $vt_row['transcript'];
			}
		}

		// Transcript from admin entry or cache table
		if ( $admin_trans !== '' ) {
			$node['transcript'] = $admin_trans;
		}

		// Publisher — @id reference to Organization (in unified @graph)
		$node['publisher'] = [ '@id' => home_url( '/#organization' ) ];

		// isPartOf — @id reference to WebSite
		$node['isPartOf'] = [ '@id' => home_url( '/#website' ) ];

		// director — first enabled Person @id (creator/director credit for AI engines)
		$person_profiles_vid = get_option( 'myls_person_profiles', [] );
		if ( is_array( $person_profiles_vid ) && ! empty( $person_profiles_vid ) ) {
			foreach ( $person_profiles_vid as $fp ) {
				if ( empty( $fp['name'] ) || ( $fp['enabled'] ?? '1' ) !== '1' ) continue;
				$node['director'] = [ '@id' => home_url( '/#person-' . sanitize_title( $fp['name'] ) ) ];
				break;
			}
		}

		/**
		 * Filter individual VideoObject node before it enters the graph.
		 *
		 * @param array $node     The built VideoObject array.
		 * @param array $item     The raw normalized video item.
		 * @param int   $post_id  The post ID.
		 */
		return apply_filters('myls_video_object_node', $node, $item, $post_id);
	}
}


/* =========================================================================
 * SECTION 11 — SCHEMA GRAPH INJECTION
 * Push auto-detected VideoObject nodes into the unified @graph.
 * Runs only on singular pages; skips the existing video CPT handler
 * (video-schema.php) which already covers that case.
 *
 * @since 7.8.98 Moved from standalone wp_head emitter to myls_schema_graph filter.
 * ========================================================================= */

add_filter('myls_schema_graph', function ( array $graph ) : array {

	if ( is_admin() || is_feed() || is_preview() ) return $graph;
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return $graph;

	// Toggle off via filter if needed.
	if ( ! apply_filters('myls_video_object_detector_enabled', true) ) return $graph;

	// Only on singular pages; video CPT is handled by video-schema.php.
	if ( ! is_singular() || is_singular('video') ) return $graph;

	$post_id = (int) get_queried_object_id();
	if ( ! $post_id ) return $graph;

	$items = myls_detect_videos_in_post($post_id);
	if ( empty($items) ) return $graph;

	foreach ( $items as $index => $item ) {
		$graph[] = myls_build_video_object_node($item, $post_id, $index);
	}

	return $graph;

}, 46); // Priority 46: just after video-schema.php (45), before Service (50)
