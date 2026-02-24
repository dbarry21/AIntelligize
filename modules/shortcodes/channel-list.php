<?php
/**
 * Shortcode: [youtube_channel_list pagesize="12" max="0" channel="" schema="auto" page=""]
 *
 * Features
 * - Bootstrap 5 grid with server-side pagination (via ycl_page URL param OR page="" attr)
 * - Emits PER-PAGE ItemList JSON-LD (only the items shown on the current page)
 * - Guaranteed thumbnails in schema: builds from videoId when missing
 * - Clean titles: hard stop at first "#" and strip emojis/symbols for grid + schema
 * - "Visit Post" button when a local post meta `_myls_youtube_video_id` matches the YouTube ID
 * - "Read Description" modal (single global modal, data-fed by card buttons)
 * - "Watch" opens an in-page modal with a YouTube iframe (no new tab)
 * - Last row centering: the 4th card (and any partial last row) is centered using `justify-content-center`
 *
 * Drop-in ready. Requires Bootstrap 5 styles/scripts on the page.
 */

if ( ! defined('ABSPATH') ) exit;

/* ==============================================================
 * Title cleaner (grid + schema)
 * ============================================================== */
if ( ! function_exists('myls_ycl_clean_title') ) {
	function myls_ycl_clean_title( $raw ) {
		$raw = (string) $raw;

		// Prefer a central cleaner if your admin tab defines it
		if ( function_exists('myls_ytvb_clean_title') ) {
			return myls_ytvb_clean_title($raw);
		}

		$s = html_entity_decode( wp_strip_all_tags( $raw ), ENT_QUOTES, 'UTF-8' );

		// Remove URLs
		$s = preg_replace('~https?://\S+~i', '', $s);

		// Keep everything before the first '#'
		if ( preg_match('/^(.*?)(?:\s*#|$)/u', $s, $m) ) {
			$s = isset($m[1]) ? trim($m[1]) : $s;
		}

		// Strip emojis / pictographs / symbols
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

/* ==============================================================
 * Helper: find local "video" post permalink by YouTube ID
 * ============================================================== */
if ( ! function_exists('myls_ycl_find_post_permalink_by_youtube_id') ) {
	function myls_ycl_find_post_permalink_by_youtube_id( $video_id ) {
		$video_id = trim((string)$video_id);
		if ($video_id === '') return '';

		$posts = get_posts(array(
			'post_type'        => 'video', // adjust if your CPT differs
			'post_status'      => 'publish',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'meta_key'         => '_myls_youtube_video_id',
			'meta_value'       => $video_id,
			'no_found_rows'    => true,
			'suppress_filters' => true,
		));

		if ( ! empty($posts) ) {
			$pid  = (int) $posts[0];
			$link = get_permalink($pid);
			return $link ? $link : '';
		}
		return '';
	}
}

/* ==============================================================
 * JSON-LD queue + footer flusher (avoids duplicates)
 * ============================================================== */
if ( ! function_exists('myls_ycl_queue_schema') ) {
	$GLOBALS['myls_ycl_schema_queue'] = array();

	function myls_ycl_queue_schema( $schema ){
		if ( empty($schema) ) return;
		$GLOBALS['myls_ycl_schema_queue'][] = $schema;
	}

	function myls_ycl_flush_schema_footer(){
		$queue = isset($GLOBALS['myls_ycl_schema_queue']) ? $GLOBALS['myls_ycl_schema_queue'] : array();
		if ( empty($queue) ) return;

		$seen = array();
		foreach ( $queue as $schema ){
			$id = is_array($schema) && isset($schema['@id']) ? (string)$schema['@id'] : md5( wp_json_encode($schema) );
			if ( isset($seen[$id]) ) continue;
			$seen[$id] = true;

			echo "\n<!-- MYLS YouTube Channel ItemList JSON-LD (per-page) -->\n";
			echo '<script type="application/ld+json">' . "\n";
			echo wp_json_encode($schema, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT) . "\n";
			echo "</script>\n";
		}
		$GLOBALS['myls_ycl_schema_queue'] = array();
	}
	add_action('wp_footer', 'myls_ycl_flush_schema_footer', 20);
}

/* ==============================================================
 * PER-PAGE ItemList JSON-LD builder
 * ============================================================== */

/**
 * Best-effort uploadDate resolver for ItemList VideoObjects.
 *
 * Why this exists:
 * - Different helpers historically return different keys for the same concept:
 *     - `date`
 *     - `publishedAt`
 *     - `uploadDate`
 * - When we build the per-page ItemList schema, we want `uploadDate` to be
 *   present whenever we can reliably determine it.
 *
 * Priority order:
 *  1) Item payload keys: date / publishedAt / uploadDate
 *  2) Local post meta (if a local permalink exists):
 *       - _myls_video_upload_date_iso
 *       - _myls_youtube_published_at
 *       - WP post publish date
 *  3) YouTube API fallback (videos.list -> snippet.publishedAt), cached.
 *  4) Empty string (caller will omit field)
 */
if ( ! function_exists('myls_ycl_resolve_upload_date') ) {
	function myls_ycl_resolve_upload_date( array $it ) : string {
		// 1) Direct keys from helper payload
		foreach ( array('date','publishedAt','uploadDate') as $k ) {
			if ( ! empty($it[$k]) && is_string($it[$k]) ) {
				return sanitize_text_field($it[$k]);
			}
		}

		$vid   = ! empty($it['videoId']) ? sanitize_text_field((string)$it['videoId']) : '';
		$perma = ! empty($it['permalink']) ? esc_url_raw((string)$it['permalink']) : '';
		$post_id = 0;
		if ( $perma ) {
			$post_id = (int) url_to_postid( $perma );
		}

		// 2) Local post meta fallbacks
		if ( $post_id > 0 ) {
			$meta_iso = trim( (string) get_post_meta($post_id, '_myls_video_upload_date_iso', true) );
			if ( $meta_iso !== '' ) return sanitize_text_field($meta_iso);

			$yt_published = trim( (string) get_post_meta($post_id, '_myls_youtube_published_at', true) );
			if ( $yt_published !== '' ) return sanitize_text_field($yt_published);

			$pub = get_the_date('c', $post_id);
			if ( $pub ) return sanitize_text_field($pub);
		}

		// 3) YouTube API fallback (cached) if we have a video ID
		if ( $vid !== '' ) {
			$cache_key = 'myls_ycl_yt_pub_' . $vid;
			$cached = get_transient($cache_key);
			if ( is_string($cached) && $cached !== '' ) {
				return sanitize_text_field($cached);
			}

			// API key: prefer option, fallback to legacy theme_mod
			$api_key = (string) get_option('myls_youtube_api_key', '');
			if ( $api_key === '' ) {
				$api_key = (string) get_theme_mod('ssseo_youtube_api_key', '');
			}

			if ( $api_key !== '' ) {
				$resp = wp_remote_get( add_query_arg([
					'part' => 'snippet',
					'id'   => $vid,
					'key'  => $api_key,
				], 'https://www.googleapis.com/youtube/v3/videos'), [
					'timeout' => 10,
				] );
				if ( ! is_wp_error($resp) && 200 === (int) wp_remote_retrieve_response_code($resp) ) {
					$data = json_decode( wp_remote_retrieve_body($resp), true );
					if ( ! empty($data['items'][0]['snippet']['publishedAt']) ) {
						$publishedAt = sanitize_text_field( (string) $data['items'][0]['snippet']['publishedAt'] );
						// Cache for 30 days (filterable)
						set_transient( $cache_key, $publishedAt, apply_filters('myls_ycl_upload_date_cache_ttl', 30 * DAY_IN_SECONDS) );
						// If we later find a local post, it will pick this up via transient; optionally persist.
						if ( $post_id > 0 ) {
							update_post_meta($post_id, '_myls_youtube_published_at', $publishedAt);
						}
						return $publishedAt;
					}
				}
			}
		}

		return '';
	}
}
if ( ! function_exists('myls_ycl_build_itemlist_schema') ) {
	function myls_ycl_build_itemlist_schema( $items, $ctx = array() ) {
		$page_url  = ( isset($ctx['page_url']) && $ctx['page_url'] !== '' )
			? esc_url_raw($ctx['page_url'])
			: home_url(add_query_arg(array()));

		$list_id   = trailingslashit($page_url) . '#videoList';
		$site_name = get_bloginfo('name');
		$title     = ( isset($ctx['title']) && $ctx['title'] !== '' ) ? sanitize_text_field($ctx['title']) : ($site_name . ' – Video Gallery');

		$org_name = get_option('myls_org_name', $site_name);
		$org_url  = get_option('myls_org_url', home_url('/'));
		$logo_id  = (int) get_option('myls_org_logo_id', 0);
		$logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

		// NOTE: schema.org validators will flag publisher/dateModified on ItemList.
		// Best-practice: wrap the list in a CollectionPage and move those properties to the page entity.
		$itemList = array(
			'@type'           => 'ItemList',
			'@id'             => esc_url_raw($list_id),
			'name'            => $title,
			'description'     => 'Browse videos on ' . $site_name,
			'numberOfItems'   => is_array($items) ? count($items) : 0,
			'itemListOrder'   => 'https://schema.org/ItemListOrderDescending',
			'itemListElement' => array(),
		);

		if ( is_array($items) ) {
			foreach ($items as $i => $it) {
				$vid     = isset($it['videoId']) ? sanitize_text_field($it['videoId']) : '';
				$rawName = isset($it['title'])   ? (string) $it['title'] : 'Video';
				$name    = myls_ycl_clean_title( $rawName );
				$thumb   = isset($it['thumb'])   ? esc_url_raw($it['thumb']) : '';
				// uploadDate: normalize helper key differences and add safe fallbacks
				$dateISO = myls_ycl_resolve_upload_date( is_array($it) ? $it : array() );

				$local  = isset($it['permalink']) ? esc_url_raw($it['permalink']) : '';
				$watch  = $vid ? ('https://www.youtube.com/watch?v=' . rawurlencode($vid)) : '';
				$to     = $local ? $local : ( $watch ? $watch : $page_url );
				$embed  = $vid ? ('https://www.youtube.com/embed/' . rawurlencode($vid)) : '';

				// Thumbnails: prefer provided, then fallback from videoId
				$videoThumbs = array();
				if ( ! empty($thumb) ) {
					$videoThumbs[] = $thumb;
				}
				if ( ! empty($vid) ) {
					$videoThumbs[] = 'https://i.ytimg.com/vi/' . rawurlencode($vid) . '/hqdefault.jpg';
				}
				$videoThumbs = array_values(array_unique(array_filter($videoThumbs)));

				$videoIdUrl = ! empty($vid) ? ('https://www.youtube.com/watch?v=' . rawurlencode($vid)) : $to;

				$videoObj = array(
					'@type'            => 'VideoObject',
					'@id'              => esc_url_raw($videoIdUrl . '#video'),
					'mainEntityOfPage' => esc_url_raw($to),
					'url'              => esc_url_raw($to),
					'name'             => $name,
					'description'      => $name,
					'isFamilyFriendly' => 'true',
				);
				if ( ! empty($videoThumbs) ) {
					$videoObj['thumbnailUrl'] = $videoThumbs;
					$videoObj['image']        = $videoThumbs; // optional but recommended
				}
				if ( ! empty($dateISO) ) {
					$videoObj['uploadDate'] = $dateISO;
				}
				if ( ! empty($embed) ) {
					$videoObj['embedUrl'] = $embed;
				}

				$itemList['itemListElement'][] = array(
					'@type'    => 'ListItem',
					'position' => (int) $i + 1,
					'item'     => $videoObj,
				);
			}
		}

		$page_id = trailingslashit($page_url) . '#webpage';
		$pageSchema = array(
			'@context'      => 'https://schema.org',
			'@type'         => 'CollectionPage',
			'@id'           => esc_url_raw($page_id),
			'url'           => esc_url_raw($page_url),
			'name'          => $title,
			'description'   => 'Browse videos on ' . $site_name,
			'dateModified'  => current_time('c'),
			'publisher'     => array_filter(array(
				'@type' => 'Organization',
				'name'  => sanitize_text_field($org_name),
				'url'   => esc_url_raw($org_url),
				'logo'  => $logo_url ? array(
					'@type' => 'ImageObject',
					'url'   => esc_url_raw($logo_url),
				) : null,
			)),
			'mainEntity'    => $itemList,
		);

		return apply_filters('myls_ycl_itemlist_schema', $pageSchema, $items, $ctx);
	}
}

/* ==============================================================
 * Bootstrap 5 pagination builder
 * ============================================================== */
if ( ! function_exists('myls_ycl_build_pagination') ) {
	function myls_ycl_build_pagination( $total_pages, $current_page, $args = array() ) {
		$total_pages  = (int) $total_pages;
		$current_page = (int) $current_page;
		if ( $total_pages <= 1 ) return '';
		$param   = isset($args['param']) ? $args['param'] : 'ycl_page';
		$wrap    = isset($args['wrap_class']) ? $args['wrap_class'] : 'd-flex justify-content-center mt-4';
		$ul_cls  = isset($args['ul_class']) ? $args['ul_class'] : 'pagination';

		$qargs = $_GET; // preserve other query vars
		if ( isset($qargs[$param]) ) unset($qargs[$param]);

		$html  = '<nav class="'.esc_attr($wrap).'" aria-label="Video pagination">';
		$html .= '<ul class="'.esc_attr($ul_cls).'">';

		$prev_disabled = $current_page <= 1 ? ' disabled' : '';
		$qprev = $qargs; $qprev[$param] = max(1, $current_page - 1);
		$html .= '<li class="page-item'.$prev_disabled.'"><a class="page-link" href="'.esc_url(add_query_arg($qprev)).'" aria-label="Previous">&laquo;</a></li>';

		for ( $i = 1; $i <= $total_pages; $i++ ) {
			$active = $i === $current_page ? ' active' : '';
			$qi = $qargs; $qi[$param] = $i;
			$html .= '<li class="page-item'.$active.'"><a class="page-link" href="'.esc_url(add_query_arg($qi)).'">'.$i.'</a></li>';
		}

		$next_disabled = $current_page >= $total_pages ? ' disabled' : '';
		$qnext = $qargs; $qnext[$param] = min($total_pages, $current_page + 1);
		$html .= '<li class="page-item'.$next_disabled.'"><a class="page-link" href="'.esc_url(add_query_arg($qnext)).'" aria-label="Next">&raquo;</a></li>';

		$html .= '</ul></nav>';
		return $html;
	}
}

/* ==============================================================
 * Single, reusable modals — render once per page
 * ============================================================== */
if ( ! function_exists('myls_ycl_render_desc_modal_once') ) {
	function myls_ycl_render_desc_modal_once() {
		static $done = false;
		if ( $done ) return '';
		$done = true;

		ob_start(); ?>
<div class="modal fade" id="mylsVideoDescModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-lg modal-dialog-scrollable">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="mylsVideoDescTitle">Description</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<p id="mylsVideoDescBody" class="mb-0"></p>
			</div>
		</div>
	</div>
</div>
<script>
(function(){
  // --- Configure keywords to bold (case-insensitive) ---
  var KEYWORDS = [
    'drainage','tapered insulation','crickets','scuppers','internal drains','overflow',
    'flashing','membrane','TPO','PVC','modified bitumen','Mod-Bit','warranty',
    'ponding','slope','parapet','seams','leaks','waterproof','quality control'
  ];

  // --- utils ---
  function escapeHTML(s){
    return (s || '').replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function autoLink(escaped){
    return escaped.replace(/((?:https?|ftp):\/\/[^\s<]+)/gi, function(m){
      var url = m; // already escaped
      return '<a href="'+url+'" target="_blank" rel="nofollow noopener">'+url+'</a>';
    });
  }

  function boldKeywords(escaped){
    var out = escaped;
    KEYWORDS.forEach(function(term){
      var t = term.trim();
      if(!t) return;
      var start = '(?<![A-Za-z0-9])';
      var end   = '(?![A-Za-z0-9])';
      var pattern = new RegExp(start + '(' + t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')' + end, 'gi');
      out = out.replace(pattern, '<strong>$1</strong>');
    });
    return out;
  }

  // CHANGED: ≤ 8 words count for H3 headings (was ≤ 5)
  function isHeadingBlock(text){
    var words = (text.trim().match(/\S+/g) || []);
    return words.length > 0 && words.length <= 8;
  }

  function formatDescToHTML(raw){
    if(!raw){ return '<p>No description available.</p>'; }

    raw = raw.replace(/\r\n?/g, '\n');

    // Split into blocks by blank line
    var blocks = raw.split(/\n{2,}/);
    var htmlParts = [];
    var firstRendered = false;

    for (var i=0;i<blocks.length;i++){
      var block = blocks[i].trim();
      if(!block) continue;

      if (!firstRendered) {
        // FIRST non-empty block -> H2 (regardless of length)
        var h2 = escapeHTML(block);
        h2 = autoLink(h2);
        h2 = boldKeywords(h2);
        htmlParts.push('<h2><strong>'+ h2 +'</strong></h2>');
        firstRendered = true;
        continue;
      }

      if(isHeadingBlock(block)){
        // Subsequent short blocks -> H3
        var h3 = escapeHTML(block);
        h3 = autoLink(h3);
        h3 = boldKeywords(h3);
        htmlParts.push('<h3><strong>'+ h3 +'</strong></h3>');
      } else {
        // Paragraph: single newlines -> <br>, with auto-link + bold
        var lines = block.split('\n').map(function(line){
          var esc = escapeHTML(line);
          esc = autoLink(esc);
          esc = boldKeywords(esc);
          return esc;
        });
        htmlParts.push('<p>'+ lines.join('<br>') +'</p>');
      }
    }

    return htmlParts.length ? htmlParts.join('\n') : '<p>No description available.</p>';
  }

  // ---- Modal wiring: KEEP data-title and data-desc as-is; format on open ----
  document.addEventListener("click", function(e){
    var btn = e.target.closest(".myls-desc-btn");
    if(!btn) return;

    var title = btn.getAttribute("data-title") || "Description";
    var desc  = btn.getAttribute("data-desc")  || "";

    // Set modal H2 title (ensure your modal uses <h2 id="mylsVideoDescTitle">…</h2>)
    var titleEl = document.getElementById("mylsVideoDescTitle");
    if (titleEl) {
      var titleEsc = boldKeywords(escapeHTML(title));
      titleEl.innerHTML = titleEsc;
    }

    // Format description: FIRST LINE -> H2, then H3/paragraphs
    var bodyEl  = document.getElementById("mylsVideoDescBody");
    if (bodyEl) {
      bodyEl.innerHTML = formatDescToHTML(desc);
    }
  }, true);
})();
</script>




		<?php
		return (string) ob_get_clean();
	}
}

if ( ! function_exists('myls_ycl_render_watch_modal_once') ) {
	function myls_ycl_render_watch_modal_once() {
		static $done = false;
		if ( $done ) return '';
		$done = true;

		ob_start(); ?>
<div class="modal fade" id="mylsVideoWatchModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="mylsVideoWatchTitle">Watch</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body p-0">
				<div class="ratio ratio-16x9">
					<iframe id="mylsVideoWatchFrame"
						src=""
						title="YouTube video player"
						allow="autoplay; encrypted-media; picture-in-picture"
						allowfullscreen
						referrerpolicy="strict-origin-when-cross-origin"></iframe>
				</div>
			</div>
		</div>
	</div>
</div>
<script>
(function(){
	var modalEl = document.getElementById('mylsVideoWatchModal');
	var frame   = document.getElementById('mylsVideoWatchFrame');
	var titleEl = document.getElementById('mylsVideoWatchTitle');

	// Open: set iframe URL from data-vid (preferred) or data-embed
	document.addEventListener('click', function(e){
		var btn = e.target.closest('.myls-watch-btn');
		if(!btn) return;

		var title = btn.getAttribute('data-title') || 'Watch';
		var vid   = btn.getAttribute('data-vid')   || '';
		var embed = btn.getAttribute('data-embed') || '';

		titleEl.textContent = title;

		var src = '';
		if (vid) {
			src = 'https://www.youtube.com/embed/' + encodeURIComponent(vid) + '?autoplay=1&rel=0&modestbranding=1';
		} else if (embed) {
			src = embed;
			if (src.indexOf('?') === -1) src += '?autoplay=1&rel=0&modestbranding=1';
		}
		frame.setAttribute('src', src);
	}, true);

	// Close: clear src to stop playback
	modalEl && modalEl.addEventListener('hidden.bs.modal', function(){
		frame.setAttribute('src', '');
	});
})();
</script>
		<?php
		return (string) ob_get_clean();
	}
}

/* ==============================================================
 * Shortcode: youtube_channel_list
 * ============================================================== */
myls_register_shortcode('youtube_channel_list', function($atts){
	$a = shortcode_atts(array(
		'pagesize' => '12',        // items per page [1..50]
		'max'      => '0',         // hard cap for API [0..50], 0=no cap
		'channel'  => '',          // override channel ID
		'schema'   => 'auto',      // auto|inline|off
		'page'     => '',          // optional explicit page (overrides URL if set)
	), $atts, 'youtube_channel_list');

	$ppp         = max(1, min(50, (int) $a['pagesize']));
	$max_cap     = max(0, min(50, (int) $a['max']));
	$schema_mode = in_array($a['schema'], array('auto','inline','off'), true) ? $a['schema'] : 'auto';

	// Current page: shortcode attr > URL param > 1
	$url_page     = isset($_GET['ycl_page']) ? (int) $_GET['ycl_page'] : 0;
	$current_page = max(1, (int) ($a['page'] !== '' ? $a['page'] : $url_page));

	// Current full URL (with ycl_page if present) for schema @id
	$current_url_for_schema = home_url(add_query_arg(array()));

	// Project helpers (guarded)
	$api_key = function_exists('myls_yt_get_api_key') ? myls_yt_get_api_key() : ( function_exists('myls_get_youtube_api_key') ? myls_get_youtube_api_key() : '' );
	$channel = $a['channel'] !== '' ? sanitize_text_field($a['channel'])
	          : ( function_exists('myls_yt_get_channel_id') ? myls_yt_get_channel_id()
	          : ( function_exists('myls_get_youtube_channel_id') ? myls_get_youtube_channel_id() : '' ) );

	$html_grid    = '';
	$total_items  = 0;
	$total_pages  = 1;

	/* ----------------------------------------------
	 * Preferred path: YouTube API (uploads playlist)
	 * ---------------------------------------------- */
	if ( $api_key && $channel && function_exists('myls_yt_get_uploads_playlist_id') && function_exists('myls_yt_fetch_uploads_batch') ) {
		$uploads = myls_yt_get_uploads_playlist_id($channel, $api_key);
		if ( $uploads !== '' ) {
			$all = myls_yt_fetch_uploads_batch($uploads, $api_key); // up to 50 per helper
			if ( $all && is_array($all) ) {
				if ( $max_cap > 0 ) $all = array_slice($all, 0, $max_cap);

				// Map local permalinks + clean titles + ensure thumb fallback + normalize desc
				foreach ($all as &$it) {
					$vid   = isset($it['videoId']) ? (string) $it['videoId'] : '';

					$it['permalink'] = $vid ? myls_ycl_find_post_permalink_by_youtube_id($vid) : '';

					// Clean title
					$it['title'] = myls_ycl_clean_title( isset($it['title']) ? $it['title'] : 'Video' );

					// Robust thumbnail fallback
					$rawThumb = isset($it['thumb']) ? (string)$it['thumb'] : '';
					if ($rawThumb === '' && $vid !== '') {
						$rawThumb = 'https://i.ytimg.com/vi/' . rawurlencode($vid) . '/hqdefault.jpg';
					}
					$it['thumb'] = $rawThumb;

					// Normalize description if helper provided it
					if ( isset($it['description']) ) {
						$desc = wp_strip_all_tags( (string) $it['description'] );
						$it['description'] = $desc !== '' ? $desc : '';
					}
				}
				unset($it);

				$total_items  = count($all);
				$total_pages  = max(1, (int) ceil($total_items / $ppp));
				$current_page = min($current_page, $total_pages);

				// Slice for current page (grid + schema)
				$offset = ($current_page - 1) * $ppp;
				$items  = array_slice($all, $offset, $ppp);

				ob_start(); ?>
<div class="container myls-youtube-grid" id="videoList">
	<!-- Center partial last row -->
	<div class="row row-cols-1 row-cols-md-3 g-4 justify-content-center">
		<?php foreach ( $items as $it ):
			$vid   = isset($it['videoId']) ? $it['videoId'] : '';
			$title = isset($it['title'])   ? $it['title']   : 'Video'; // already cleaned
			$thumb = isset($it['thumb'])   ? $it['thumb']   : '';
			$perma = isset($it['permalink']) ? $it['permalink'] : '';

			// Description from API (if present), safe text fallback
			$desc  = isset($it['description']) ? $it['description'] : '';
			if ($desc === '') { $desc = 'No description available.'; }
		?>
		<div class="col">
			<div class="card h-100 shadow-sm">
				<?php if ( $thumb ) : ?>
					<?php if ( $vid ): ?>
						<!-- Thumbnail opens WATCH modal -->
						<a class="ratio ratio-16x9 d-block myls-watch-btn"
						   href="#"
						   data-bs-toggle="modal"
						   data-bs-target="#mylsVideoWatchModal"
						   data-vid="<?php echo esc_attr($vid); ?>"
						   data-title="<?php echo esc_attr($title); ?>">
							<img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($title); ?>" class="card-img-top" loading="lazy">
						</a>
					<?php else: ?>
						<div class="ratio ratio-16x9 d-block">
							<img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($title); ?>" class="card-img-top" loading="lazy">
						</div>
					<?php endif; ?>
				<?php else: ?>
					<div class="ratio ratio-16x9 bg-light d-flex align-items-center justify-content-center">
						<span class="text-muted">No thumbnail</span>
					</div>
				<?php endif; ?>
				<div class="card-body d-flex flex-column">
					<h5 class="card-title" style="font-size:1rem;"><?php echo esc_html($title); ?></h5>

					<!-- Read Description (modal trigger) -->
					<div class="d-grid gap-2 my-2">
						<a href="#"
						   class="btn btn-sm btn-outline-secondary myls-desc-btn"
						   data-bs-toggle="modal"
						   data-bs-target="#mylsVideoDescModal"
						   data-title="<?php echo esc_attr($title); ?>"
						   data-desc="<?php echo esc_attr($desc); ?>">
							Read Description
						</a>
					</div>

					<div class="mt-auto d-flex gap-2 flex-wrap">
						<?php if ( $vid ): ?>
							<!-- WATCH in modal -->
							<a href="#"
							   class="btn btn-sm btn-primary myls-watch-btn"
							   data-bs-toggle="modal"
							   data-bs-target="#mylsVideoWatchModal"
							   data-vid="<?php echo esc_attr($vid); ?>"
							   data-title="<?php echo esc_attr($title); ?>">
								Watch
							</a>
						<?php endif; ?>
						<?php if ( $perma ) : ?>
							<a class="btn btn-sm btn-outline-secondary myls-button" href="<?php echo esc_url($perma); ?>">Visit Post</a>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php endforeach; ?>
	</div>

	<?php echo myls_ycl_build_pagination($total_pages, $current_page, array('param' => 'ycl_page')); ?>
</div>
				<?php
				$html_grid = ob_get_clean();

				// PER-PAGE schema (items already contain cleaned titles + ensured thumbs)
				$schema = myls_ycl_build_itemlist_schema(
					$items,
					array(
						'page_url' => $current_url_for_schema,
						'title'    => get_the_title() . ' – Video Gallery (Page ' . $current_page . ')'
					)
				);

				if ( $schema_mode === 'inline' ) {
					$html_grid .= "\n<script type=\"application/ld+json\">\n" .
					             wp_json_encode($schema, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT) .
					             "\n</script>\n";
				} elseif ( $schema_mode !== 'off' ) {
					myls_ycl_queue_schema($schema);
				}

				// Append modals once
				$html_grid .= myls_ycl_render_desc_modal_once();
				$html_grid .= myls_ycl_render_watch_modal_once();

				return $html_grid;
			}
		}
	}

	/* ----------------------------------------------
	 * Fallback: local "video" posts (paged)
	 * ---------------------------------------------- */
	$paged = $current_page;

	$q = new WP_Query(array(
		'post_type'      => 'video',
		'post_status'    => 'publish',
		'orderby'        => 'date',
		'order'          => 'DESC',
		'posts_per_page' => $ppp,
		'paged'          => $paged,
		'no_found_rows'  => false,
	));

	if ( ! $q->have_posts() ) return '<p><em>No videos yet.</em></p>';

	$total_pages = max(1, (int) $q->max_num_pages);

	// Build current page items for schema (PER-PAGE)
	$schema_items = array();
	$page_posts   = $q->posts;
	foreach ( $page_posts as $p ) {
		$pid      = $p->ID;
		$raw_name = get_the_title($pid);
		$clean    = myls_ycl_clean_title( $raw_name ?: 'Video' );

		// Prefer new key, then legacy keys
		$vid_meta = get_post_meta($pid, '_myls_youtube_video_id', true);
		if ( ! $vid_meta ) $vid_meta = get_post_meta($pid, '_myls_video_id', true);
		if ( ! $vid_meta ) $vid_meta = get_post_meta($pid, '_ssseo_video_id', true);

		$schema_items[] = array(
			'videoId'   => $vid_meta ? $vid_meta : '',
			'title'     => $clean,
			'thumb'     => $vid_meta ? ('https://i.ytimg.com/vi/' . rawurlencode($vid_meta) . '/hqdefault.jpg') : '',
			'permalink' => get_permalink($pid),
			'date'      => get_the_date('c', $pid),
		);
	}

	ob_start();
	?>
<div class="container myls-video-list" id="videoList">
	<!-- Center partial last row -->
	<div class="row row-cols-1 row-cols-md-3 g-4 justify-content-center">
	<?php
	while ( $q->have_posts() ) { $q->the_post();
		$pid         = get_the_ID();
		$raw_title   = get_the_title() ?: 'Video';
		$clean_title = myls_ycl_clean_title( $raw_title );
		$link        = get_permalink() ?: '#';

		$vid_meta = get_post_meta($pid, '_myls_youtube_video_id', true);
		if ( ! $vid_meta ) $vid_meta = get_post_meta($pid, '_myls_video_id', true);
		if ( ! $vid_meta ) $vid_meta = get_post_meta($pid, '_ssseo_video_id', true);

		$thumb = $vid_meta ? ('https://i.ytimg.com/vi/' . rawurlencode($vid_meta) . '/hqdefault.jpg') : '';

		// Local description: excerpt > trimmed content
		$desc = has_excerpt($pid) ? get_the_excerpt($pid) : '';
		if ($desc === '') {
			$desc = wp_trim_words( wp_strip_all_tags( get_post_field('post_content', $pid) ), 60, '…' );
		}
		if ($desc === '') { $desc = 'No description available.'; }
		?>
		<div class="col">
			<div class="card h-100 shadow-sm">
				<?php if ( $thumb ) : ?>
					<?php if ( $vid_meta ): ?>
						<!-- Thumbnail opens WATCH modal -->
						<a class="ratio ratio-16x9 d-block myls-watch-btn"
						   href="#"
						   data-bs-toggle="modal"
						   data-bs-target="#mylsVideoWatchModal"
						   data-vid="<?php echo esc_attr($vid_meta); ?>"
						   data-title="<?php echo esc_attr($clean_title); ?>">
							<img class="card-img-top" src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($clean_title); ?>" loading="lazy">
						</a>
					<?php else: ?>
						<a class="ratio ratio-16x9 d-block" href="<?php echo esc_url($link); ?>">
							<img class="card-img-top" src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($clean_title); ?>" loading="lazy">
						</a>
					<?php endif; ?>
				<?php else: ?>
					<div class="ratio ratio-16x9 bg-light d-flex align-items-center justify-content-center"><span class="text-muted">No thumbnail</span></div>
				<?php endif; ?>
				<div class="card-body d-flex flex-column">
					<h2 class="card-title" style="font-size:1rem;"><?php echo esc_html($clean_title); ?></h2>

					<!-- Read Description (modal trigger) -->
					<div class="d-grid gap-2 my-2">
						<a href="#"
						   class="btn btn-sm btn-outline-secondary myls-desc-btn"
						   data-bs-toggle="modal"
						   data-bs-target="#mylsVideoDescModal"
						   data-title="<?php echo esc_attr($clean_title); ?>"
						   data-desc="<?php echo esc_attr($desc); ?>">
							Read Description
						</a>
					</div>

					<div class="mt-auto d-flex gap-2 flex-wrap">
						<?php if ( $vid_meta ): ?>
							<!-- WATCH in modal -->
							<a href="#"
							   class="btn btn-sm btn-primary myls-watch-btn"
							   data-bs-toggle="modal"
							   data-bs-target="#mylsVideoWatchModal"
							   data-vid="<?php echo esc_attr($vid_meta); ?>"
							   data-title="<?php echo esc_attr($clean_title); ?>">
								Watch
							</a>
						<?php endif; ?>
						<a class="btn btn-sm btn-outline-secondary" href="<?php echo esc_url($link); ?>">View</a>
					</div>
				</div>
			</div>
		</div>
	<?php } ?>
	</div>

	<?php echo myls_ycl_build_pagination($total_pages, $current_page, array('param' => 'ycl_page')); ?>
</div>
	<?php
	wp_reset_postdata();

	$html = ob_get_clean();

	// PER-PAGE schema for local posts
	$schema = myls_ycl_build_itemlist_schema(
		$schema_items,
		array(
			'page_url' => $current_url_for_schema,
			'title'    => get_the_title() . ' – Video Gallery (Page ' . $current_page . ')'
		)
	);

	if ( $schema_mode === 'inline' ) {
		$html .= "\n<script type=\"application/ld+json\">\n" .
		         wp_json_encode($schema, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT) .
		         "\n</script>\n";
	} elseif ( $schema_mode !== 'off' ) {
		myls_ycl_queue_schema($schema);
	}

	// Append modals once
	$html .= myls_ycl_render_desc_modal_once();
	$html .= myls_ycl_render_watch_modal_once();

	return $html;
}, array(
	'tag'         => 'youtube_channel_list',
	'description' => 'Grid of channel uploads with Bootstrap pagination. Emits per-page ItemList JSON-LD. Adds "Visit Post" when a local post with meta `_myls_youtube_video_id` matches the video ID. Cleans titles before display & schema. Includes “Read Description” and “Watch” modals. Centers the last row.',
	'params'      => array(
		'pagesize' => 'Items per page (1–50). Default 12.',
		'max'      => 'Hard cap from API (0–50). Default 0 (no cap).',
		'channel'  => 'Channel ID override; default uses saved setting.',
		'schema'   => 'Schema output: auto (footer), inline (next to grid), off.',
		'page'     => 'Force current page (overrides URL ycl_page param).',
	),
));
