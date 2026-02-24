<?php
/**
 * File: /inc/schema/providers/video-schema.php
 *
 * Purpose: Emit a robust VideoObject JSON-LD on individual video pages
 *          with guaranteed `thumbnailUrl` array (mirrored to `image`) and
 *          a proper `uploadDate` (fix for “missing field uploadDate”).
 *
 * Usage: Include/require this file from your plugin bootstrap. It will:
 *  - Run only on single posts of type `video`
 *  - Read YouTube ID from `_myls_youtube_video_id` (with legacy fallbacks)
 *  - Build thumbnails from the videoId when none are stored
 *  - Clean titles (stop at first "#", strip emojis/symbols) if your global cleaner isn't available
 *  - Avoid `array_filter()` that could accidentally remove `thumbnailUrl`
 *
 * Toggle: Disable this schema via:
 *   add_filter('myls_video_single_schema_enabled', '__return_false');
 */

if ( ! defined('ABSPATH') ) exit;

/** -----------------------------------------------------------------
 * Cleaner: prefer your central cleaner if present, else local fallback
 * ----------------------------------------------------------------- */
if ( ! function_exists('myls_vs_clean_title') ) {
	function myls_vs_clean_title( $raw ) {
		$raw = (string) $raw;

		// If your project exposes a global cleaner, use it
		if ( function_exists('myls_ytvb_clean_title') ) {
			return myls_ytvb_clean_title($raw);
		}
		if ( function_exists('myls_ycl_clean_title') ) {
			return myls_ycl_clean_title($raw);
		}

		// Local fallback
		$s = html_entity_decode( wp_strip_all_tags( $raw ), ENT_QUOTES, 'UTF-8' );

		// Remove URLs
		$s = preg_replace('~https?://\S+~i', '', $s);

		// Keep everything before first '#'
		if ( preg_match('/^(.*?)(?:\s*#|$)/u', $s, $m) ) {
			$s = isset($m[1]) ? trim($m[1]) : $s;
		}

		// Strip emojis / pictographs / symbols (broad ranges)
		$s = preg_replace('/[\x{1F100}-\x{1F1FF}\x{1F300}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', '', $s);

		// Normalize separators and excessive punctuation
		$s = str_replace(array('|','/','\\','–','—','·','•','►','»','«'), ' ', $s);
		$s = preg_replace('/[[:punct:]]{2,}/u', ' ', $s);

		// Collapse whitespace and trim
		$s = preg_replace('/\s+/u', ' ', trim($s));
		$s = trim($s, " \t\n\r\0\x0B-_.:,;!?#*()[]{}\"'");

		return $s !== '' ? $s : ( $raw !== '' ? $raw : 'Video' );
	}
}

/** -----------------------------------------------------------------
 * Get YouTube video ID from post meta (new key + legacy fallbacks)
 * ----------------------------------------------------------------- */
if ( ! function_exists('myls_vs_get_youtube_id') ) {
	function myls_vs_get_youtube_id( $post_id ) {
		$keys = apply_filters('myls_video_schema_youtube_id_keys', array(
			'_myls_youtube_video_id',
			'_myls_video_id',       // legacy
			'_ssseo_video_id',      // legacy
		));
		foreach ( (array) $keys as $k ) {
			$val = trim( (string) get_post_meta($post_id, $k, true) );
			if ( $val !== '' ) return $val;
		}
		return '';
	}
}

/** -----------------------------------------------------------------
 * Normalize a date/time string to ISO 8601 (e.g., 2024-01-31T12:34:56+00:00)
 * Accepts: already-ISO strings, mysql-style datetimes, unix timestamps, etc.
 * Returns '' if it cannot be parsed.
 * ----------------------------------------------------------------- */
if ( ! function_exists('myls_vs_iso8601') ) {
	function myls_vs_iso8601( $value ) {
		$value = trim((string)$value);
		if ( $value === '' ) return '';

		// If numeric, treat as UNIX timestamp
		if ( ctype_digit($value) ) {
			$ts = (int) $value;
		} else {
			$ts = strtotime( $value );
		}

		if ( $ts === false || $ts <= 0 ) return '';

		// Format as ISO 8601 using WP timezone
		$dt = new DateTime( "@$ts" );
		$dt->setTimezone( wp_timezone() );
		return $dt->format( DATE_ATOM ); // ISO 8601
	}
}

/** -----------------------------------------------------------------
 * Build an ordered list of thumbnail URLs (provided → fallbacks)
 * ----------------------------------------------------------------- */
if ( ! function_exists('myls_vs_build_thumbnails') ) {
	function myls_vs_build_thumbnails( $video_id, $provided = '' ) {
		$urls = array();

		if ( $provided ) {
			$urls[] = esc_url_raw($provided);
		}

		if ( $video_id ) {
			$vid = rawurlencode( $video_id );
			// Prefer higher quality first
			$urls[] = "https://i.ytimg.com/vi/{$vid}/hqdefault.jpg";
			$urls[] = "https://i.ytimg.com/vi/{$vid}/mqdefault.jpg";
		}

		// De-duplicate and drop empties
		$urls = array_values( array_unique( array_filter( $urls ) ) );

		/**
		 * Allow last-minute customization, e.g. add `maxresdefault.jpg` if you know it's present
		 * add_filter('myls_video_schema_thumbnails', function($urls,$video_id){ ... return $urls; }, 10, 2);
		 */
		return apply_filters( 'myls_video_schema_thumbnails', $urls, $video_id );
	}
}

/** -----------------------------------------------------------------
 * Emit VideoObject JSON-LD on single video posts
 *  - FIX: Always include `uploadDate`. Priority order:
 *      1) `_myls_video_upload_date_iso` (already ISO 8601)
 *      2) `_myls_youtube_published_at` (YouTube API RFC3339/ISO)
 *      3) Post publish date (get_the_date('c'))
 * ----------------------------------------------------------------- */
if ( ! function_exists('myls_video_schema_single') ) {
	function myls_video_schema_single() {
		if ( ! is_singular( apply_filters('myls_video_schema_post_types', array('video')) ) ) {
			return;
		}
		if ( ! apply_filters('myls_video_single_schema_enabled', true ) ) {
			return;
		}

		$post_id   = get_the_ID();
		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) return;

		// Core fields
		$raw_title = get_the_title( $post_id );
		$name      = myls_vs_clean_title( $raw_title ?: 'Video' );

		// Description: excerpt > trimmed content > name
		$desc = has_excerpt($post_id) ? get_the_excerpt($post_id) : '';
		if ( $desc === '' ) {
			$desc = function_exists('myls_get_post_plain_text') ? myls_get_post_plain_text( $post_id, 60 ) : wp_trim_words( wp_strip_all_tags( get_post_field('post_content', $post_id) ), 60, '…' );
		}
		if ( $desc === '' ) $desc = $name;

		// YouTube links
		$video_id  = myls_vs_get_youtube_id( $post_id );
		$watch_url = $video_id ? ('https://www.youtube.com/watch?v=' . rawurlencode($video_id)) : $permalink;
		$embed_url = $video_id ? ('https://www.youtube.com/embed/' . rawurlencode($video_id)) : '';

		// If you store a dedicated thumb in meta, use it
		$provided_thumb = trim( (string) get_post_meta($post_id, '_myls_video_thumb_url', true) );
		$thumbs         = myls_vs_build_thumbnails( $video_id, $provided_thumb );

		// Dates (ISO 8601)
		$datePublished = get_the_date('c', $post_id );
		$dateModified  = get_the_modified_date('c', $post_id );

		// --------- FIX: Determine uploadDate with graceful fallbacks ----------
		$uploadDate = '';
		// 1) Explicit ISO date you may save when ingesting the video
		$meta_iso = trim( (string) get_post_meta($post_id, '_myls_video_upload_date_iso', true) );
		if ( $meta_iso !== '' ) {
			$uploadDate = myls_vs_iso8601( $meta_iso );
		}
		// 2) YouTube "publishedAt" from API if stored (RFC3339/ISO)
		if ( $uploadDate === '' ) {
			$yt_published = trim( (string) get_post_meta($post_id, '_myls_youtube_published_at', true) );
			if ( $yt_published !== '' ) {
				$uploadDate = myls_vs_iso8601( $yt_published );
			}
		}
		// 3) Fall back to the WP post's publish date
		if ( $uploadDate === '' ) {
			$uploadDate = $datePublished ?: '';
		}
		// ---------------------------------------------------------------------

		// Optional structured bits
		// Store duration as ISO 8601 like "PT3M21S" (YouTube API `contentDetails.duration`)
		$duration = trim( (string) get_post_meta($post_id, '_myls_video_duration_iso8601', true) );
		$views    = (int) get_post_meta($post_id, '_myls_video_view_count', true );

		// Publisher org (from plugin options; adjust option keys if needed)
		$site_name = get_bloginfo('name');
		$org_name  = get_option('myls_org_name', $site_name);
		$org_url   = get_option('myls_org_url', home_url('/'));
		$logo_id   = (int) get_option('myls_org_logo_id', 0);
		$logo_url  = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

		$video = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'VideoObject',
			'@id'              => esc_url_raw($watch_url . '#video'),   // stable id helps dedupe
			'mainEntityOfPage' => esc_url_raw($permalink),
			'url'              => esc_url_raw($watch_url),              // canonical to watch (or post if no id)
			'name'             => $name,
			'description'      => $desc,
			'isFamilyFriendly' => 'true',
			'datePublished'    => $datePublished ?: null,
			'dateModified'     => $dateModified  ?: null,
			// REQUIRED/RECOMMENDED by Google; our fix ensures this is set:
			'uploadDate'       => $uploadDate ?: null,
		);

		// Thumbnails: set when non-empty; mirror to image[]
		if ( ! empty($thumbs) ) {
			$video['thumbnailUrl'] = $thumbs;
			$video['image']        = $thumbs; // recommended by Google
		}

		if ( $embed_url )   $video['embedUrl']      = esc_url_raw($embed_url);
		if ( $duration )    $video['duration']      = $duration;
		if ( $views > 0 )   $video['interactionStatistic'] = array(
			'@type'                => 'InteractionCounter',
			'interactionType'      => array('@type' => 'WatchAction'),
			'userInteractionCount' => $views,
		);

		// Publisher (Organization)
		$publisher = array(
			'@type' => 'Organization',
			'name'  => sanitize_text_field($org_name),
			'url'   => esc_url_raw($org_url),
		);
		if ( $logo_url ) {
			$publisher['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => esc_url_raw($logo_url),
			);
		}
		$video['publisher'] = $publisher;

		/**
		 * Final filter to customize the VideoObject before output.
		 * Example: add "contentUrl" if you host your own MP4.
		 */
		$video = apply_filters( 'myls_video_schema_single_object', $video, $post_id, $video_id );

		// Output JSON-LD
		echo "\n<!-- MYLS Single Video JSON-LD -->\n";
		echo '<script type="application/ld+json">' . "\n";
		echo wp_json_encode( $video, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		echo "\n</script>\n";
	}
	// Hook late so other SEO plugins run first; adjust if you need to override theirs
	add_action( 'wp_head', 'myls_video_schema_single', 40 );
}

/** -----------------------------------------------------------------
 * (Optional) If another SEO plugin emits a conflicting VideoObject
 * and you want ONLY this one on the `video` CPT, you can unhook it.
 * Example for Yoast (uncomment to use):
 *
 * add_filter('wpseo_json_ld_output', function($data){
 *     if ( is_singular('video') ) {
 *         return array(); // remove Yoast JSON-LD on single video pages
 *     }
 *     return $data;
 * }, 20);
 * ----------------------------------------------------------------- */
