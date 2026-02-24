<?php
/**
 * File: modules/shortcodes/channel-list-detailed.php
 *
 * Shortcode: [youtube_channel_list_detailed max="6" channel="UCxxxx" desc_max="280"]
 * - Card list with larger thumbnail + optional description excerpt.
 * - Pulls uploads for a channel via YouTube Data API v3 (helpers provide calls + caching).
 *
 * Requirements:
 * - modules/youtube/helpers.php must define:
 *     myls_yt_get_api_key()
 *     myls_yt_get_channel_id()
 *     myls_yt_get_uploads_playlist_id( $channel_id, $api_key )
 *     myls_yt_fetch_uploads_batch( $playlist_id, $api_key )  // returns items with keys videoId,title,description,thumb
 *
 * Notes:
 * - Safe to include multiple times due to function_exists checks.
 */

if ( ! defined('ABSPATH') ) exit;

/* -------------------------------------------------------------
 * Ensure YouTube helpers are available
 * ------------------------------------------------------------- */
$__myls_yt_helpers = trailingslashit( defined('MYLS_PATH') ? MYLS_PATH : plugin_dir_path(__FILE__) ) . 'modules/youtube/helpers.php';
if ( file_exists( $__myls_yt_helpers ) ) {
	require_once $__myls_yt_helpers;
}

/* -------------------------------------------------------------
 * Fallback/Bootstrap: myls_register_shortcode (optional doc reg)
 * ------------------------------------------------------------- */
if ( ! function_exists('myls_register_shortcode') ) {
	function myls_register_shortcode( string $tag, callable $cb, array $doc = [] ) : void {
		add_shortcode( $tag, $cb );
		if ( ! isset( $GLOBALS['myls_shortcodes'] ) || ! is_array( $GLOBALS['myls_shortcodes'] ) ) {
			$GLOBALS['myls_shortcodes'] = [];
		}
		$GLOBALS['myls_shortcodes'][ $tag ] = $doc + ['tag' => $tag];
	}
}

/* -------------------------------------------------------------
 * Helper: trim description to a max length (word-boundary)
 * ------------------------------------------------------------- */
if ( ! function_exists('myls_yt_trim_excerpt') ) {
	function myls_yt_trim_excerpt( string $txt, int $max = 280 ) : string {
		$txt = trim( wp_strip_all_tags( $txt ) );
		if ( $max <= 0 || mb_strlen( $txt ) <= $max ) return $txt;
		$cut = mb_substr( $txt, 0, $max );
		// try to avoid chopping in middle of a word
		$last_space = mb_strrpos( $cut, ' ' );
		if ( $last_space !== false && $last_space > (int) floor( $max * 0.6 ) ) {
			$cut = mb_substr( $cut, 0, $last_space );
		}
		return rtrim( $cut, " \t\n\r\0\x0B.,;:!-—" ) . '…';
	}
}

/* -------------------------------------------------------------
 * Shortcode: [youtube_channel_list_detailed]
 * ------------------------------------------------------------- */
myls_register_shortcode('youtube_channel_list_detailed', function( $atts ){
	$a = shortcode_atts([
		'max'      => '6',    // [1..50]
		'channel'  => '',     // override saved channel id
		'desc_max' => '0',    // 0 = full description, otherwise trim to N chars
	], $atts, 'youtube_channel_list_detailed');

	// Guard: helpers must exist
	if ( ! function_exists('myls_yt_get_api_key')
	  || ! function_exists('myls_yt_get_channel_id')
	  || ! function_exists('myls_yt_get_uploads_playlist_id')
	  || ! function_exists('myls_yt_fetch_uploads_batch') ) {
		return '<div class="notice notice-error"><p>YouTube helpers missing. Ensure <code>modules/youtube/helpers.php</code> is included.</p></div>';
	}

	$api_key = (string) myls_yt_get_api_key();
	$channel = $a['channel'] !== '' ? sanitize_text_field( $a['channel'] ) : (string) myls_yt_get_channel_id();

	if ( $api_key === '' || $channel === '' ) {
		return '<div class="alert alert-warning">YouTube not configured. Add API key and Channel ID in API Integration.</div>';
	}

	$max      = max( 1, min( 50, (int) $a['max']      ) );
	$desc_max = max( 0,             (int) $a['desc_max'] );

	$uploads = myls_yt_get_uploads_playlist_id( $channel, $api_key );
	if ( $uploads === '' ) {
		return '<div class="alert alert-danger">Could not resolve channel uploads playlist.</div>';
	}

	$items = array_slice( myls_yt_fetch_uploads_batch( $uploads, $api_key ), 0, $max );

	ob_start(); ?>
	<div class="container myls-youtube-detailed">
		<div class="row g-4">
			<?php foreach ( $items as $it ) :
				$vid    = (string) ( $it['videoId'] ?? '' );
				$title  = (string) ( $it['title']   ?? $vid );
				$desc   = (string) ( $it['description'] ?? '' );
				$thumb  = (string) ( $it['thumb']   ?? '' );
				$yturl  = 'https://www.youtube.com/watch?v=' . rawurlencode( $vid );

				if ( $desc_max > 0 ) {
					$desc = myls_yt_trim_excerpt( $desc, $desc_max );
				}
			?>
			<div class="col-12">
				<div class="card shadow-sm">
					<div class="row g-0">
						<div class="col-md-4">
							<a class="ratio ratio-16x9 d-block" href="<?php echo esc_url( $yturl ); ?>" target="_blank" rel="noopener">
								<?php if ( $thumb ) : ?>
									<img
										src="<?php echo esc_url( $thumb ); ?>"
										alt="<?php echo esc_attr( $title ); ?>"
										class="img-fluid rounded-start"
										loading="lazy"
									>
								<?php else : ?>
									<div class="d-flex align-items-center justify-content-center bg-light" style="height:100%;min-height:180px;">
										<span class="text-muted"><?php echo esc_html__( 'No thumbnail', 'aintelligize' ); ?></span>
									</div>
								<?php endif; ?>
							</a>
						</div>
						<div class="col-md-8">
							<div class="card-body">
								<h5 class="card-title" style="margin-top:0;"><?php echo esc_html( $title ); ?></h5>
								<?php if ( $desc !== '' ) : ?>
									<div class="card-text" style="white-space:pre-wrap;"><?php echo esc_html( $desc ); ?></div>
								<?php endif; ?>
								<a class="btn btn-sm btn-primary mt-2" href="<?php echo esc_url( $yturl ); ?>" target="_blank" rel="noopener">
									<?php echo esc_html__( 'Watch on YouTube', 'aintelligize' ); ?>
								</a>
							</div>
						</div>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}, [
	'tag'         => 'youtube_channel_list_detailed',
	'description' => 'Larger layout with description excerpt for recent uploads.',
	'params'      => [
		'max'      => 'Max items to show (1–50). Default 6.',
		'channel'  => 'Channel ID override; default uses saved setting.',
		'desc_max' => 'Max characters for description (0 = full). Default 0.',
	],
]);
