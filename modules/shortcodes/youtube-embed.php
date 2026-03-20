<?php
/**
 * Shortcode: [myls_youtube_embed]
 *
 * Lightweight YouTube video embed with thumbnail placeholder overlay.
 * No iframe loaded until the user clicks — great for page speed / CWV.
 * Outputs VideoObject JSON-LD schema inline.
 *
 * Usage:
 * - [myls_youtube_embed video_id="dQw4w9WgXcQ"]
 * - [myls_youtube_embed video_id="dQw4w9WgXcQ" title="My Video"]
 * - [myls_youtube_embed video_id="dQw4w9WgXcQ" max_width="100%"]
 *
 * Attributes:
 * - video_id  (required) YouTube video ID (11 chars)
 * - title     (optional) Video title for schema/alt text. Defaults to post title.
 * - max_width (optional) CSS max-width for container. Default: 100% (fills parent).
 * - autoplay  (optional) 1 = autoplay + mute on click. Default: 1.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'myls_youtube_embed', 'myls_youtube_embed_shortcode' );

function myls_youtube_embed_shortcode( $atts = [] ) {

	$atts = shortcode_atts(
		[
			'video_id'  => '',
			'title'     => '',
			'max_width' => '100%',
			'autoplay'  => '1',
		],
		$atts,
		'myls_youtube_embed'
	);

	$video_id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $atts['video_id'] );
	if ( $video_id === '' ) {
		return '<p><em>No video_id provided.</em></p>';
	}

	// Resolve title
	$title = trim( (string) $atts['title'] );
	if ( $title === '' ) {
		$title = get_the_title();
	}
	$title = wp_strip_all_tags( $title );

	// Thumbnail URL via canonical helper
	$post_id   = (int) get_the_ID();
	$thumb_url = function_exists( 'myls_yt_thumbnail_url' )
		? myls_yt_thumbnail_url( $video_id, $post_id )
		: 'https://i.ytimg.com/vi/' . rawurlencode( $video_id ) . '/hqdefault.jpg';

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

	// Max width
	$max_width = esc_attr( $atts['max_width'] );

	// Unique ID for this instance
	$uid = 'myls-yt-' . esc_attr( $video_id ) . '-' . wp_unique_id();

	// Enqueue inline CSS once
	static $css_printed = false;
	if ( ! $css_printed ) {
		$css_printed = true;
		add_action( 'wp_footer', function() {
			echo '<style>
.myls-yt-facade{position:relative;width:100%;cursor:pointer;background:#000;overflow:hidden;border-radius:6px;}
.myls-yt-facade::before{content:"";display:block;padding-top:56.25%;}
.myls-yt-facade img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;}
.myls-yt-facade .myls-yt-play{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:68px;height:48px;pointer-events:none;z-index:2;}
.myls-yt-facade .myls-yt-play svg{width:100%;height:100%;}
.myls-yt-facade:hover .myls-yt-play svg .ytp-bg{fill:#f00;}
.myls-yt-facade iframe{position:absolute;inset:0;width:100%;height:100%;border:0;}
</style>';
		}, 99 );
	}

	// Build HTML
	$html  = '<div class="myls-yt-embed-wrap" style="max-width:' . $max_width . ';width:100%;margin:0 auto;">';
	$html .= '<div id="' . $uid . '" class="myls-yt-facade" data-embed="' . esc_attr( $embed_url ) . '" role="button" tabindex="0" aria-label="' . esc_attr( 'Play video: ' . $title ) . '">';
	$html .= '<img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( $title ) . '" loading="lazy" />';
	// YouTube-style play button SVG
	$html .= '<span class="myls-yt-play"><svg viewBox="0 0 68 48" xmlns="http://www.w3.org/2000/svg">'
	        . '<path class="ytp-bg" d="M66.52 7.74c-.78-2.93-2.49-5.41-5.42-6.19C55.79.13 34 0 34 0S12.21.13 6.9 1.55C3.97 2.33 2.27 4.81 1.48 7.74.06 13.05 0 24 0 24s.06 10.95 1.48 16.26c.78 2.93 2.49 5.41 5.42 6.19C12.21 47.87 34 48 34 48s21.79-.13 27.1-1.55c2.93-.78 4.64-3.26 5.42-6.19C67.94 34.95 68 24 68 24s-.06-10.95-1.48-16.26z" fill="#212121" fill-opacity=".8"/>'
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
	$schema = [
		'@context'    => 'https://schema.org',
		'@type'       => 'VideoObject',
		'name'        => $title,
		'description' => $title,
		'thumbnailUrl'=> [ $thumb_url ],
		'image'       => [ $thumb_url ],
		'url'         => $watch_url,
		'embedUrl'    => 'https://www.youtube.com/embed/' . rawurlencode( $video_id ),
		'uploadDate'  => get_the_date( 'c' ),
		'isFamilyFriendly' => 'true',
	];

	// Try to get upload date from local video post meta
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

	return $html;
}
