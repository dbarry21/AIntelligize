<?php
/**
 * Video Schema (aintelligize)
 *
 * - ItemList JSON-LD on the /video/ archive (ordered by modified DESC)
 * - VideoObject JSON-LD on single video pages
 * - Respects admin toggle: myls_schema_video_enabled ("1" to output)
 *
 * Safe fallbacks:
 * - Video ID meta: prefer _myls_video_id, fallback _ssseo_video_id
 * - Org options: prefer myls_org_*; fallback to old ssseo_* when present
 * - YouTube API key: prefer myls_youtube_api_key option; fallback theme_mod('ssseo_youtube_api_key')
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Helper: Is video schema enabled?
 */
function myls_is_video_schema_enabled(): bool {
	return get_option('myls_schema_video_enabled', '0') === '1';
}

/**
 * Helper: Resolve Organization basics (name, url, logo_url)
 */
function myls_get_publisher_for_schema(): array {
	$name = get_option('myls_org_name', get_bloginfo('name'));
	$url  = get_option('myls_org_url', home_url('/'));

	// New path: attachment ID in myls_org_logo_id
	$logo_id  = (int) get_option('myls_org_logo_id', 0);
	$logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

	// Legacy fallbacks (IDs or URLs as used by older plugins)
	if (empty($logo_url)) {
		$legacy_logo_id = (int) get_option('ssseo_organization_logo', 0);
		if ($legacy_logo_id) {
			$logo_url = wp_get_attachment_image_url($legacy_logo_id, 'full');
		}
	}
	if (empty($logo_url)) {
		// Some older builds stored a direct URL
		$legacy_logo_url = get_option('ssseo_schema_org_logo', '');
		if (is_string($legacy_logo_url) && $legacy_logo_url !== '') {
			$logo_url = $legacy_logo_url;
		}
	}

	// More fallbacks for name/url if the new ones are empty
	if (empty($name)) {
		$name = get_option('ssseo_schema_org_name', get_bloginfo('name'));
	}
	if (empty($url)) {
		$url = get_option('ssseo_schema_org_url', home_url('/'));
	}

	return [
		'name' => sanitize_text_field($name),
		'url'  => esc_url_raw($url),
		'logo_url' => $logo_url ? esc_url_raw($logo_url) : '',
	];
}

/**
 * Helper: Get a post's video id from meta (new key first, then legacy)
 */
function myls_get_video_id_for_post(int $post_id): string {
	$vid = get_post_meta($post_id, '_myls_video_id', true);
	if (empty($vid)) {
		$vid = get_post_meta($post_id, '_ssseo_video_id', true);
	}
	return is_string($vid) ? trim($vid) : '';
}

/**
 * Archive: /video/ ItemList
 */
add_action('wp_head', 'myls_insert_video_archive_itemlist_schema', 20);
function myls_insert_video_archive_itemlist_schema() {
	if ( ! myls_is_video_schema_enabled() ) return;
	if ( ! is_post_type_archive('video') )  return;

	$videos = get_posts([
		'post_type'      => 'video',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	]);
	if (empty($videos)) return;

	$archive_url = get_post_type_archive_link('video');
	$list_id     = trailingslashit($archive_url) . '#videoList';

	$pub = myls_get_publisher_for_schema();

	// NOTE: schema.org validators will flag publisher/dateModified on ItemList.
	// Best-practice: wrap the list in a CollectionPage and move those properties to the page entity.
	$itemList = [
		'@type'           => 'ItemList',
		'@id'             => esc_url($list_id),
		'name'            => get_bloginfo('name') . ' – Video Gallery',
		'description'     => 'Browse all video posts on ' . get_bloginfo('name'),
		'numberOfItems'   => count($videos),
		'itemListOrder'   => 'https://schema.org/ItemListOrderDescending',
		'itemListElement' => [],
	];

	foreach ($videos as $index => $video_post) {
		$post_id     = (int) $video_post->ID;
		$permalink   = get_permalink($post_id);
		$title       = get_the_title($post_id);
		$publish_c   = get_the_date('c', $post_id);
		$video_id    = myls_get_video_id_for_post($post_id);

		$thumbnail   = function_exists('myls_yt_thumbnail_url') ? myls_yt_thumbnail_url( $video_id, $post_id ) : ( $video_id ? "https://i.ytimg.com/vi/{$video_id}/hqdefault.jpg" : '' );
		$embed_url   = $video_id ? "https://www.youtube.com/embed/{$video_id}" : '';
		$description = get_the_excerpt($post_id);
		if (empty($description)) $description = $title;

		$itemList['itemListElement'][] = [
			'@type'    => 'ListItem',
			'position' => $index + 1,
			'item'     => array_filter([
				'@type'            => 'VideoObject',
				'mainEntityOfPage' => esc_url($permalink),
				'url'              => esc_url($permalink),
				'name'             => sanitize_text_field($title),
				'description'      => sanitize_text_field($description),
				'thumbnailUrl'     => $thumbnail ? [ esc_url($thumbnail) ] : null,
				'uploadDate'       => $publish_c,
				'contentUrl'       => esc_url($permalink),
				'embedUrl'         => $embed_url ? esc_url($embed_url) : null,
			]),
		];
	}

	$pageSchema = [
		'@context'     => 'https://schema.org',
		'@type'        => 'CollectionPage',
		'@id'          => esc_url( trailingslashit($archive_url) . '#webpage' ),
		'url'          => esc_url($archive_url),
		'name'         => get_bloginfo('name') . ' – Video Gallery',
		'description'  => 'Browse all video posts on ' . get_bloginfo('name'),
		'dateModified' => current_time('c'),
		'publisher'    => array_filter([
			'@type' => 'Organization',
			'name'  => $pub['name'],
			'logo'  => $pub['logo_url'] ? ['@type'=>'ImageObject','url'=>$pub['logo_url']] : null,
			'url'   => $pub['url'],
		]),
		'mainEntity'   => $itemList,
	];

	echo "\n<!-- BEGIN Video CollectionPage+ItemList JSON-LD (aintelligize) -->\n";
	echo '<script type="application/ld+json">' . "\n";
	echo wp_json_encode($pageSchema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
	echo "</script>\n";
	echo "<!-- END Video CollectionPage+ItemList JSON-LD (aintelligize) -->\n";
}

/**
 * Single: /video/{slug} VideoObject
 */
add_action('wp_head', 'myls_insert_single_video_schema', 20);
function myls_insert_single_video_schema() {
	if ( ! myls_is_video_schema_enabled() ) return;
	if ( ! is_singular('video') )         return;

	$post_id  = get_the_ID();
	if ( ! $post_id ) return;

	$video_id = myls_get_video_id_for_post($post_id);
	if (empty($video_id)) return;

	$name        = get_the_title($post_id);
	$description = get_the_excerpt($post_id);
	if (empty($description)) $description = $name;

	$thumbnailUrl = "https://i.ytimg.com/vi/{$video_id}/mqdefault.jpg";
	$embedUrl     = "https://www.youtube.com/embed/{$video_id}";

	// API key: prefer new option; fallback to legacy theme_mod
	$api_key    = get_option('myls_youtube_api_key', '');
	if (empty($api_key)) {
		$api_key = get_theme_mod('ssseo_youtube_api_key', '');
	}

	$duration   = '';
	$uploadDate = get_the_date('c', $post_id); // fallback if API empty
	$viewCount  = '';

	if ( ! empty($api_key) ) {
		$yt_response = wp_remote_get( add_query_arg([
			'part' => 'snippet,contentDetails,statistics',
			'id'   => $video_id,
			'key'  => $api_key,
		], 'https://www.googleapis.com/youtube/v3/videos') );

		if ( ! is_wp_error($yt_response) && 200 === (int) wp_remote_retrieve_response_code($yt_response) ) {
			$yt_data = json_decode( wp_remote_retrieve_body($yt_response), true );
			if ( ! empty($yt_data['items'][0]) ) {
				$item       = $yt_data['items'][0];
				$duration   = $item['contentDetails']['duration'] ?? $duration;
				$uploadDate = $item['snippet']['publishedAt']     ?? $uploadDate;
				$viewCount  = $item['statistics']['viewCount']    ?? $viewCount;
			}
		}
	}

	$pub = myls_get_publisher_for_schema();

	$videoObject = array_filter([
		'@context'             => 'https://schema.org',
		'@type'                => 'VideoObject',
		'mainEntityOfPage'     => esc_url( get_permalink($post_id) ),
		'url'                  => esc_url( get_permalink($post_id) ),
		'name'                 => sanitize_text_field($name),
		'description'          => sanitize_text_field($description),
		'thumbnailUrl'         => [ esc_url($thumbnailUrl) ],
		'uploadDate'           => $uploadDate,
		'duration'             => $duration ?: null, // only include if known
		'contentUrl'           => esc_url( get_permalink($post_id) ),
		'embedUrl'             => esc_url($embedUrl),
		'interactionStatistic' => ($viewCount !== '' ? array_filter([
			'@type'                 => 'InteractionCounter',
			'interactionType'       => ['@type' => 'http://schema.org/WatchAction'],
			'userInteractionCount'  => (int) $viewCount,
		]) : null),
		'publisher'            => array_filter([
			'@type' => 'Organization',
			'name'  => $pub['name'],
			'logo'  => $pub['logo_url'] ? ['@type'=>'ImageObject','url'=>$pub['logo_url']] : null,
			'url'   => $pub['url'],
		]),
		'isFamilyFriendly'     => 'true',
	]);

	echo "\n<!-- BEGIN Single VideoObject JSON-LD (aintelligize) -->\n";
	echo '<script type="application/ld+json">' . "\n";
	echo wp_json_encode($videoObject, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
	echo "</script>\n";
	echo "<!-- END Single VideoObject JSON-LD (aintelligize) -->\n";
}
