<?php
/**
 * Shortcode: [myls_youtube_embed]
 *
 * Lightweight YouTube video embed with thumbnail placeholder overlay.
 * No iframe loaded until the user clicks — great for page speed / CWV.
 * Outputs VideoObject JSON-LD schema inline. Title displayed over video.
 *
 * Usage:
 * - [myls_youtube_embed video_id="dQw4w9WgXcQ"]
 * - [myls_youtube_embed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"]
 * - [myls_youtube_embed video_id="dQw4w9WgXcQ" title="My Video"]
 * - [myls_youtube_embed use_page_video="1"]
 * - [myls_youtube_embed use_page_video="1" fallback_id="dQw4w9WgXcQ"]
 *
 * Attributes:
 * - video_id       YouTube video ID (11 chars). Required if url/use_page_video not provided.
 * - url            Full YouTube URL (watch, embed, shorts, youtu.be). Extracts ID automatically.
 * - use_page_video 1 = read video URL from current page meta. Fallback chain: _myls_page_video_url → video_url (ACF) → fallback_id → site default.
 * - fallback_id    (optional) Fallback video ID when use_page_video finds no URL.
 * - title          (optional) Video title for schema/alt text. Defaults to post title.
 * - autoplay       (optional) 1 = autoplay + mute on click. Default: 1.
 * - play_color     (optional) Hex color for play button. Overrides admin setting.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'myls_youtube_embed', 'myls_youtube_embed_shortcode' );

function myls_youtube_embed_shortcode( $atts = [] ) {

	$atts = shortcode_atts(
		[
			'video_id'       => '',
			'url'            => '',
			'use_page_video' => '0',
			'fallback_id'    => '',
			'title'          => '',
			'autoplay'       => '1',
			'play_color'     => '',
		],
		$atts,
		'myls_youtube_embed'
	);

	// Regex used to extract 11-char YouTube video ID from a URL
	$yt_url_regex = '%(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_-]{11})%i';

	// Resolve video ID: from video_id attribute, or extract from url attribute
	$video_id = trim( (string) $atts['video_id'] );
	if ( $video_id === '' && $atts['url'] !== '' ) {
		if ( preg_match( $yt_url_regex, $atts['url'], $m ) ) {
			$video_id = $m[1];
		}
	}

	// use_page_video: read video URL from current page meta → fallback chain
	if ( $video_id === '' && $atts['use_page_video'] === '1' ) {
		$page_id = (int) get_the_ID();
		if ( $page_id > 0 ) {
			// 1. Plugin-native meta key
			$page_url = get_post_meta( $page_id, '_myls_page_video_url', true );
			// 2. Legacy ACF field
			if ( ! is_string( $page_url ) || trim( $page_url ) === '' ) {
				$page_url = get_post_meta( $page_id, 'video_url', true );
			}
			// Extract video ID from URL
			if ( is_string( $page_url ) && trim( $page_url ) !== '' ) {
				if ( preg_match( $yt_url_regex, $page_url, $m ) ) {
					$video_id = $m[1];
				}
			}
		}
		// 3. Shortcode fallback_id attribute
		if ( $video_id === '' && trim( (string) $atts['fallback_id'] ) !== '' ) {
			$video_id = trim( (string) $atts['fallback_id'] );
		}
		// 4. Site-wide default video ID
		if ( $video_id === '' ) {
			$default_id = get_option( 'myls_ytvb_default_video_id', '' );
			if ( is_string( $default_id ) && trim( $default_id ) !== '' ) {
				$video_id = trim( $default_id );
			}
		}
	}

	$video_id = preg_replace( '/[^A-Za-z0-9_-]/', '', $video_id );
	if ( $video_id === '' || strlen( $video_id ) !== 11 ) {
		return '<p><em>Invalid or missing YouTube video ID.</em></p>';
	}

	// Resolve title: shortcode attr → local video post title → page title fallback
	$title = trim( (string) $atts['title'] );
	if ( $title === '' && function_exists( 'myls_yt_find_video_post_id' ) ) {
		$vid_pid = myls_yt_find_video_post_id( $video_id );
		if ( $vid_pid > 0 ) {
			$title = get_the_title( $vid_pid );
		}
	}
	if ( $title === '' ) {
		$title = get_the_title();
	}
	$title = wp_strip_all_tags( $title );

	// Thumbnail URL — prefer maxresdefault (fills 16:9), fall back to hqdefault
	$post_id   = (int) get_the_ID();
	$thumb_url = '';
	if ( function_exists( 'myls_yt_thumbnail_url' ) ) {
		$thumb_url = myls_yt_thumbnail_url( $video_id, $post_id );
	}
	if ( $thumb_url === '' ) {
		$thumb_url = 'https://i.ytimg.com/vi/' . rawurlencode( $video_id ) . '/maxresdefault.jpg';
	}

	// Embed URL
	$autoplay  = $atts['autoplay'] === '1';
	$embed_params = array_filter([
		'rel'            => 0,
		'modestbranding' => 1,
		'playsinline'    => 1,
		'autoplay'       => $autoplay ? 1 : 0,
		'mute'           => $autoplay ? 1 : 0,
	]);
	$embed_url = 'https://www.youtube.com/embed/' . rawurlencode( $video_id ) . '?' . http_build_query( $embed_params );
	$watch_url = 'https://www.youtube.com/watch?v=' . rawurlencode( $video_id );

	// Unique ID for this instance
	$uid = 'myls-yt-' . esc_attr( $video_id ) . '-' . wp_unique_id();

	// Resolve play button color: shortcode attr > admin option > YouTube Red default
	$play_color = trim( (string) $atts['play_color'] );
	if ( $play_color === '' ) {
		$play_color = get_option( 'myls_ytvb_play_button_color', '' );
	}
	if ( $play_color === '' ) {
		$play_color = '#FF0000';
	}

	// Enqueue inline CSS once
	static $css_printed = false;
	if ( ! $css_printed ) {
		$css_printed = true;
		add_action( 'wp_footer', function() {
			echo '<style>
.myls-yt-embed-wrap{width:100%;}
.myls-yt-facade{position:relative;width:100%;padding-top:56.25%;cursor:pointer;background:#000;overflow:hidden;border-radius:6px;}
.myls-yt-facade img{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;}
.myls-yt-facade .myls-yt-title{position:absolute;top:0;left:0;right:0;padding:12px 16px;color:#fff;font-size:16px;font-weight:600;line-height:1.3;background:linear-gradient(to bottom,rgba(0,0,0,.7),transparent);z-index:3;pointer-events:none;}
.myls-yt-facade .myls-yt-play{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:68px;height:48px;pointer-events:none;z-index:2;}
.myls-yt-facade .myls-yt-play svg{width:100%;height:100%;}
.myls-yt-facade .myls-yt-play svg .ytp-bg{transition:opacity .2s;}
.myls-yt-facade:hover .myls-yt-play svg .ytp-bg{opacity:1;}
.myls-yt-facade iframe{position:absolute;top:0;left:0;width:100%;height:100%;border:0;}
</style>';
		}, 99 );
	}

	// Build HTML
	$html  = '<div class="myls-yt-embed-wrap">';
	$html .= '<div id="' . $uid . '" class="myls-yt-facade" data-embed="' . esc_attr( $embed_url ) . '" role="button" tabindex="0" aria-label="' . esc_attr( 'Play video: ' . $title ) . '">';
	$html .= '<img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( $title ) . '" loading="lazy" />';
	// Title overlay at top
	$html .= '<span class="myls-yt-title">' . esc_html( $title ) . '</span>';
	// YouTube-style play button SVG — fill driven by resolved $play_color, no inline fill-opacity
	$html .= '<span class="myls-yt-play"><svg viewBox="0 0 68 48" xmlns="http://www.w3.org/2000/svg">'
	        . '<path class="ytp-bg" d="M66.52 7.74c-.78-2.93-2.49-5.41-5.42-6.19C55.79.13 34 0 34 0S12.21.13 6.9 1.55C3.97 2.33 2.27 4.81 1.48 7.74.06 13.05 0 24 0 24s.06 10.95 1.48 16.26c.78 2.93 2.49 5.41 5.42 6.19C12.21 47.87 34 48 34 48s21.79-.13 27.1-1.55c2.93-.78 4.64-3.26 5.42-6.19C67.94 34.95 68 24 68 24s-.06-10.95-1.48-16.26z" fill="' . esc_attr( $play_color ) . '" opacity=".85"/>'
	        . '<path d="M45 24 27 14v20" fill="#fff"/>'
	        . '</svg></span>';
	$html .= '</div>';
	$html .= '</div>';

	// Inline JS: click to replace with iframe
	$html .= '<script>
(function(){
var el=document.getElementById("' . $uid . '");
if(!el)return;
function play(){
var iframe=document.createElement("iframe");
iframe.src=el.dataset.embed;
iframe.setAttribute("allow","accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture;web-share");
iframe.setAttribute("allowfullscreen","");
iframe.setAttribute("title",' . wp_json_encode( $title ) . ');
el.innerHTML="";el.appendChild(iframe);
}
el.addEventListener("click",play);
el.addEventListener("keydown",function(e){if(e.key==="Enter"||e.key===" ")play();});
})();
</script>';

	// VideoObject JSON-LD schema
	// On singular pages the video-object-detector adds this to the main @graph —
	// suppress here to avoid a duplicate standalone block alongside @graph output.
	if ( ! is_singular() ) {
		$desc = has_excerpt() ? wp_strip_all_tags( get_the_excerpt() ) : '';
		if ( $desc === '' ) $desc = $title;

		$schema = [
			'@context'         => 'https://schema.org',
			'@type'            => 'VideoObject',
			'name'             => $title,
			'description'      => $desc,
			'thumbnailUrl'     => [ $thumb_url ],
			'image'            => [ $thumb_url ],
			'url'              => $watch_url,
			'embedUrl'         => 'https://www.youtube.com/embed/' . rawurlencode( $video_id ),
			'uploadDate'       => get_the_date( 'c' ),
			'isFamilyFriendly' => true,
		];

		// Try to get upload date / duration from local video post meta
		if ( function_exists( 'myls_yt_find_video_post_id' ) ) {
			$local_pid = myls_yt_find_video_post_id( $video_id );
			if ( $local_pid > 0 ) {
				$iso = get_post_meta( $local_pid, '_myls_video_upload_date_iso', true );
				if ( ! $iso ) $iso = get_post_meta( $local_pid, '_myls_youtube_published_at', true );
				if ( $iso ) $schema['uploadDate'] = $iso;

				$dur = get_post_meta( $local_pid, '_myls_video_duration_iso8601', true );
				if ( $dur ) $schema['duration'] = $dur;
			}
		}

		$schema = array_filter( $schema );
		$html  .= "\n" . '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
	}

	return $html;
}
