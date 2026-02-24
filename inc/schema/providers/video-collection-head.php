<?php
if (!defined('ABSPATH')) exit;

/**
 * ItemList (Video Collection) in <head> for any page that uses [youtube_channel_list].
 *
 * - Uses same defaults as the shortcode: pagesize=12, max=0, channel=""
 * - Pulls Organization publisher bits from Schema → Organization options.
 * - If YouTube API cannot be used, falls back to local 'video' posts.
 * - Controlled by the "Video" schema toggle: myls_schema_video_enabled = '1'
 */

add_action('wp_head', 'myls_ycl_itemlists_into_head', 22);

function myls_ycl_itemlists_into_head() {
	if (is_admin() || is_feed() || !is_singular()) return;
	if (get_option('myls_schema_video_enabled','0') !== '1') return;

	// Shortcode-only mode (default): prevent duplicate ItemList schema.
	// This keeps schema.org validation clean and avoids competing ItemLists on the same page.
	if (get_option('myls_video_schema_shortcode_only', '1') === '1') return;

	global $post;
	if (!($post instanceof WP_Post)) return;

	$instances = myls_ycl_find_shortcodes($post->post_content);
	if (empty($instances)) return;

	$page_url = get_permalink($post) ?: home_url('/');
	$org_name = get_option('myls_org_name', get_bloginfo('name'));
	$org_url  = get_option('myls_org_url', home_url('/'));
	$logo_id  = (int) get_option('myls_org_logo_id', 0);
	$logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

	// Prefer helpers if present; else fall back to API Integration getters.
	$api_key   = function_exists('myls_yt_get_api_key')     ? myls_yt_get_api_key()     : (function_exists('myls_get_youtube_api_key') ? myls_get_youtube_api_key() : '');
	$def_chan  = function_exists('myls_yt_get_channel_id')  ? myls_yt_get_channel_id()  : (function_exists('myls_get_youtube_channel_id') ? myls_get_youtube_channel_id() : '');

	$instance_idx = 0;

	foreach ($instances as $atts) {
		$instance_idx++;

		$pagesize = max(1, min(50, (int)($atts['pagesize'] ?? 12)));
		$maxcap   = max(0, min(50, (int)($atts['max'] ?? 0)));
		$channel  = !empty($atts['channel']) ? sanitize_text_field($atts['channel']) : $def_chan;

		// Cache key: page + the effective shortcode attributes
		$cache_key = 'myls_ycl_schema_' . md5(get_the_ID() . '|' . wp_json_encode([$pagesize,$maxcap,$channel]));
		$schema_list = get_transient($cache_key);

		if ($schema_list === false) {
			$schema_list = [];

			// ---------- Try YouTube API ----------
			if ($api_key && $channel && function_exists('myls_yt_get_uploads_playlist_id') && function_exists('myls_yt_fetch_uploads_batch')) {
				$uploads = myls_yt_get_uploads_playlist_id($channel, $api_key);
				if ($uploads) {
					$items = myls_yt_fetch_uploads_batch($uploads, $api_key);
					if ($items) {
						if ($maxcap > 0) $items = array_slice($items, 0, $maxcap);
						$items = array_slice($items, 0, $pagesize);

						foreach ($items as $it) {
							$vid     = $it['videoId'] ?? '';
							if (!$vid) continue;
							$title   = $it['title'] ?? 'Video';
							$thumb   = $it['thumb'] ?? '';
							// Optional: publish date if your helper returns it
							$uploaded= $it['publishedAt'] ?? '';
							$perma   = function_exists('myls_yt_find_video_post_url') ? myls_yt_find_video_post_url($vid, $title) : '';
							$url     = $perma ?: ('https://www.youtube.com/watch?v=' . rawurlencode($vid));

							$schema_list[] = [
								'title'      => $title,
								'url'        => $url,
								'thumb'      => $thumb,
								'uploadDate' => $uploaded,
								'embedUrl'   => 'https://www.youtube.com/embed/' . rawurlencode($vid),
							];
						}
					}
				}
			}

			// ---------- Fallback to local 'video' posts ----------
			if (empty($schema_list)) {
				$rows = get_posts([
					'post_type'        => 'video',
					'post_status'      => 'publish',
					'posts_per_page'   => $pagesize,
					'orderby'          => 'date',
					'order'            => 'DESC',
					'suppress_filters' => true,
					'no_found_rows'    => true,
				]);

				foreach ($rows as $p) {
					$title   = get_the_title($p) ?: 'Video';
					$link    = get_permalink($p) ?: '';
					$vid_meta= get_post_meta($p->ID, '_myls_video_id', true);
					if (!$vid_meta) $vid_meta = get_post_meta($p->ID, '_ssseo_video_id', true);
					$thumb   = $vid_meta ? ('https://i.ytimg.com/vi/' . rawurlencode($vid_meta) . '/mqdefault.jpg') : '';
					$embed   = $vid_meta ? ('https://www.youtube.com/embed/' . rawurlencode($vid_meta)) : '';
					$schema_list[] = [
						'title'      => $title,
						'url'        => $link,
						'thumb'      => $thumb,
						'uploadDate' => get_the_date('c', $p),
						'embedUrl'   => $embed,
					];
				}
			}

			// Cache for 30 minutes (filterable)
			set_transient($cache_key, $schema_list, apply_filters('myls_ycl_schema_cache_ttl', 30 * MINUTE_IN_SECONDS));
		}

		if (empty($schema_list)) continue;

		$list_id = trailingslashit($page_url) . '#yclist-' . $instance_idx;

		// NOTE: schema.org validators will flag publisher/dateModified on ItemList.
		// Best-practice: wrap the list in a CollectionPage and move those properties to the page entity.
		$itemList = [
			'@type'           => 'ItemList',
			'@id'             => esc_url($list_id),
			'name'            => sanitize_text_field(get_bloginfo('name') . ' – Latest Videos'),
			'description'     => 'Video list rendered on this page.',
			'numberOfItems'   => count($schema_list),
			'itemListElement' => [],
		];

		foreach ($schema_list as $i => $v) {
			$itemList['itemListElement'][] = [
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'item'     => array_filter([
					'@type'            => 'VideoObject',
					'mainEntityOfPage' => esc_url_raw($v['url']),
					'url'              => esc_url_raw($v['url']),
					'name'             => sanitize_text_field($v['title']),
					'description'      => sanitize_text_field($v['title']), // fallback
					'thumbnailUrl'     => !empty($v['thumb']) ? [ esc_url_raw($v['thumb']) ] : null,
					'uploadDate'       => !empty($v['uploadDate']) ? $v['uploadDate'] : null,
					'embedUrl'         => !empty($v['embedUrl']) ? esc_url_raw($v['embedUrl']) : null,
					'isFamilyFriendly' => 'true',
				]),
			];
		}

		$pageSchema = [
			'@context'     => 'https://schema.org',
			'@type'        => 'CollectionPage',
			'@id'          => esc_url( trailingslashit($page_url) . '#webpage-yclist-' . $instance_idx ),
			'url'          => esc_url_raw($page_url),
			'name'         => sanitize_text_field(get_bloginfo('name') . ' – Latest Videos'),
			'description'  => 'Video list rendered on this page.',
			'dateModified' => current_time('c'),
			'publisher'    => array_filter([
				'@type' => 'Organization',
				'name'  => sanitize_text_field($org_name),
				'url'   => esc_url_raw($org_url),
				'logo'  => $logo_url ? [
					'@type' => 'ImageObject',
					'url'   => esc_url_raw($logo_url),
				] : null,
			]),
			'mainEntity'   => $itemList,
		];

		// Output in head
		echo "\n<!-- BEGIN Video CollectionPage+ItemList JSON-LD (yclist-{$instance_idx}) -->\n";
		echo '<script type="application/ld+json">' . "\n" . wp_json_encode($pageSchema, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT) . "\n</script>\n";
		echo "<!-- END Video CollectionPage+ItemList JSON-LD (yclist-{$instance_idx}) -->\n";
	}
}

/**
 * Find all [youtube_channel_list ...] occurrences and return parsed atts for each.
 */
function myls_ycl_find_shortcodes(string $content) : array {
	if (!has_shortcode($content, 'youtube_channel_list')) return [];
	$found = [];
	$pattern = get_shortcode_regex(['youtube_channel_list']);
	if (preg_match_all('/' . $pattern . '/s', $content, $m, PREG_SET_ORDER)) {
		foreach ($m as $match) {
			if (!isset($match[2]) || $match[2] !== 'youtube_channel_list') continue;
			$atts = shortcode_parse_atts($match[3] ?? '') ?: [];
			$found[] = $atts;
		}
	}
	return $found;
}
