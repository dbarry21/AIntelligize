<?php
/**
 * MYLS: YouTube integration (Video Blog)
 * - Generates WP posts from a YouTube channel uploads playlist
 * - Uses options saved by the "YT Video Blog" + "API Integration" tabs
 * - AJAX endpoints (admin):
 *     myls_youtube_generate_drafts
 *     myls_youtube_toggle_debug
 *     myls_youtube_get_log
 *     myls_youtube_clear_log
 * - Nonce action: myls_ytvb_ajax
 *
 * Options used (must exist via settings tab):
 *   - myls_youtube_api_key
 *   - myls_youtube_channel_id
 *   - myls_ytvb_enabled          ('0'/'1')
 *   - myls_ytvb_status           ('draft'|'pending'|'publish')
 *   - myls_ytvb_category         (int term_id or 0)
 *   - myls_ytvb_autoembed        ('0'/'1')
 *   - myls_ytvb_title_tpl        (string)
 *   - myls_ytvb_content_tpl      (string)
 *   - myls_ytvb_slug_prefix      (string)
 *   - myls_ytvb_post_type        ('post' or any registered)
 *   - myls_youtube_debug         (bool)
 *   - myls_youtube_debug_log     (array)
 */

if ( ! defined('ABSPATH') ) exit;

final class MYLS_Youtube {
	/* ===== Boot ===== */
	public static function init() : void {
		if ( is_admin() ) {
			add_action('wp_ajax_myls_youtube_generate_drafts', [__CLASS__, 'ajax_generate']);
			add_action('wp_ajax_myls_youtube_toggle_debug',    [__CLASS__, 'ajax_toggle_debug']);
			add_action('wp_ajax_myls_youtube_get_log',         [__CLASS__, 'ajax_get_log']);
			add_action('wp_ajax_myls_youtube_clear_log',       [__CLASS__, 'ajax_clear_log']);
		}
		add_shortcode('myls_youtube_with_transcript', [__CLASS__, 'shortcode_with_transcript']);
	}

	/* ===== Options ===== */
	private static function api_key()         { return get_option('myls_youtube_api_key', ''); }
	private static function channel_id()      { return get_option('myls_youtube_channel_id', ''); }
	private static function enabled()         { return get_option('myls_ytvb_enabled', '0') === '1'; }
	private static function status()          { $s = get_option('myls_ytvb_status','draft'); return in_array($s,['draft','pending','publish'],true) ? $s : 'draft'; }
	private static function category_id()     { return (int) get_option('myls_ytvb_category', 0); }
	private static function auto_embed()      { return get_option('myls_ytvb_autoembed','1') === '1'; }
	private static function title_tpl()       { return (string) get_option('myls_ytvb_title_tpl','🎥 {title}'); }
	private static function content_tpl()     { return (string) get_option('myls_ytvb_content_tpl', "<p>{description}</p>\n{embed}\n<p>Source: {channel}</p>"); }
	private static function slug_prefix()     { return (string) get_option('myls_ytvb_slug_prefix','video'); }
	private static function post_type()       { $pt = get_option('myls_ytvb_post_type','post'); return post_type_exists($pt) ? $pt : 'post'; }
	private static function debug_on()        { return (bool) get_option('myls_youtube_debug', false ); }

	/* ===== Logging ===== */
	private static function log($msg) : void {
		if ( ! self::debug_on() ) return;
		$log = (array) get_option('myls_youtube_debug_log', []);
		$log[] = '[' . current_time('mysql') . '] ' . (is_string($msg) ? $msg : wp_json_encode($msg));
		if ( count($log) > 1000 ) $log = array_slice($log, -1000);
		update_option('myls_youtube_debug_log', $log, false);
	}

	/* ===== Security ===== */
	private static function verify_ajax_or_fail() : void {
		if ( ! current_user_can('manage_options') ) {
			self::log(['auth'=>'no-manage-options']);
			wp_send_json_error(['message'=>'Insufficient permissions.']);
		}
		$nonce = '';
		foreach (['nonce','security','_ajax_nonce'] as $k) {
			if (isset($_POST[$k])) { $nonce = sanitize_text_field( wp_unslash($_POST[$k]) ); break; }
		}
		$valid = wp_verify_nonce($nonce, 'myls_ytvb_ajax');
		self::log(['nonce_received'=>$nonce ? 'yes':'no', 'nonce_valid'=>$valid?'yes':'no']);
		if ( ! $valid ) wp_send_json_error(['message'=>'Invalid nonce.']);
	}

	/* ===== API helpers ===== */
	private static function uploads_playlist( string $channel, string $key ) : string {
		$url = add_query_arg([
			'part' => 'contentDetails',
			'id'   => $channel,
			'key'  => $key,
		], 'https://www.googleapis.com/youtube/v3/channels');

		$resp = wp_remote_get( $url, ['timeout'=>20] );
		if (is_wp_error($resp) || 200 !== wp_remote_retrieve_response_code($resp)) {
			self::log(['step'=>'channels','error'=> is_wp_error($resp) ? $resp->get_error_message() : wp_remote_retrieve_response_code($resp) ]);
			return '';
		}
		$data = json_decode( wp_remote_retrieve_body($resp), true );
		$uploads = $data['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? '';
		self::log(['step'=>'uploads_playlist','playlist'=>$uploads]);
		return (string) $uploads;
	}

	private static function embed_block_from_url( string $video_url ) : string {
		// Gutenberg/Classic will handle oEmbed if the URL is on its own line
		$url_line = esc_url_raw( $video_url );
		return "\n\n" . $url_line . "\n\n";
	}

	private static function iframe_embed( string $video_id ) : string {
		$id = esc_attr($video_id);
		return '<div class="myls-video-embed-wrapper" style="margin-bottom:2rem;max-width:800px;width:100%;margin-left:auto;margin-right:auto;">'
		     . '  <div class="ratio ratio-16x9">'
		     . '    <iframe src="https://www.youtube.com/embed/'.$id.'" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>'
		     . '  </div>'
		     . '</div>';
	}

	/* ===== Token Rendering ===== */
	private static function render_tokens( string $tpl, array $vars ) : string {
		$out = $tpl;
		foreach ( $vars as $k => $v ) {
			$out = str_replace( '{' . $k . '}', (string) $v, $out );
		}
		return $out;
	}

	/* ===== Generation ===== */
	public static function generate( ?string $channel_id = null, int $max_pages = 0 ) {
		if ( ! current_user_can('manage_options') ) {
			return new WP_Error('forbidden','You do not have permission to run this function.');
		}
		if ( ! self::enabled() ) {
			return new WP_Error('disabled','YT → Blog is disabled in settings.');
		}

		$channel = sanitize_text_field( $channel_id ?: self::channel_id() );
		if (empty($channel)) return new WP_Error('no_channel','No channel ID configured.');
		$key = self::api_key();
		if (empty($key))     return new WP_Error('no_api_key','YouTube API key not configured.');

		$uploads = self::uploads_playlist($channel, $key);
		if (empty($uploads)) return new WP_Error('no_uploads_playlist','No uploads playlist found for this channel.');

		$post_type  = self::post_type();
		$status     = self::status();
		$cat_id     = self::category_id();
		$autoembed  = self::auto_embed();
		$title_tpl  = self::title_tpl();
		$content_tpl= self::content_tpl();
		$slug_prefix= self::slug_prefix();

		$next = '';
		$created = 0; $skipped = 0; $errors = []; $page = 0;

		do {
			$page++;
			$args = ['part'=>'snippet','playlistId'=>$uploads,'maxResults'=>50,'key'=>$key];
			if ($next) $args['pageToken'] = $next;

			$pl = wp_remote_get( add_query_arg($args,'https://www.googleapis.com/youtube/v3/playlistItems'), ['timeout'=>25] );
			if (is_wp_error($pl) || 200 !== wp_remote_retrieve_response_code($pl)) {
				$errors[] = 'Playlist fetch failed: ' . (is_wp_error($pl) ? $pl->get_error_message() : wp_remote_retrieve_response_code($pl));
				self::log(end($errors));
				break;
			}
			$data  = json_decode( wp_remote_retrieve_body($pl), true );
			$items = $data['items'] ?? [];
			$next  = $data['nextPageToken'] ?? '';

			foreach ($items as $it) {
				$sn  = $it['snippet'] ?? [];
				$vid = $sn['resourceId']['videoId'] ?? '';
				if (!$vid) continue;

				$raw_title = $sn['title'] ?? $vid;
				$title     = wp_strip_all_tags( $raw_title );
				$desc      = (string) ($sn['description'] ?? '');
				$channel_t = (string) ($sn['channelTitle'] ?? '');
				$date      = isset($sn['publishedAt']) ? get_date_from_gmt( $sn['publishedAt'], get_option('date_format') ) : date_i18n( get_option('date_format') );
				$url       = 'https://www.youtube.com/watch?v=' . rawurlencode($vid);

				$slug_base = sanitize_title($raw_title ? $raw_title : $vid);
				$slug      = $slug_prefix ? "{$slug_prefix}-{$slug_base}" : $slug_base;

				// Duplicate checks: by meta OR by existing slug in chosen post type
				$dup = get_posts([
					'post_type'      => $post_type,
					'posts_per_page' => 1,
					'meta_key'       => '_myls_youtube_video_id',
					'meta_value'     => $vid,
					'fields'         => 'ids',
					'post_status'    => 'any',
				]);
				if ( $dup ) { $skipped++; self::log(['skip'=>'exists_by_meta','vid'=>$vid,'post_id'=>$dup[0]]); continue; }

				$existing = get_page_by_path( $slug, OBJECT, $post_type );
				if ( $existing ) { $skipped++; self::log(['skip'=>'exists_by_slug','vid'=>$vid,'slug'=>$slug]); continue; }

				// Compose content via template tokens
				$embed_markup = $autoembed ? self::embed_block_from_url($url) : self::iframe_embed($vid);

				$vars = [
					'title'       => $title,
					'description' => esc_html( $desc ),
					'channel'     => $channel_t,
					'date'        => esc_html( $date ),
					'url'         => esc_url( $url ),
					'slug'        => esc_html( $slug_base ),
					'embed'       => $embed_markup,
				];

				$final_title   = self::render_tokens( $title_tpl, $vars );
				$final_content = self::render_tokens( $content_tpl, $vars );

				$postarr = [
					'post_title'   => $final_title,
					'post_name'    => $slug,
					'post_content' => $final_content,
					'post_status'  => $status,
					'post_type'    => $post_type,
				];

				$new_id = wp_insert_post($postarr, true);
				if ( is_wp_error($new_id) ) {
					$errors[] = 'Insert failed for '.$vid.': '.$new_id->get_error_message();
					self::log(end($errors));
					continue;
				}
				update_post_meta($new_id, '_myls_youtube_video_id', $vid);

				if ( $cat_id && taxonomy_exists('category') && is_object_in_taxonomy($post_type,'category') ) {
					wp_set_post_terms($new_id, [$cat_id], 'category', true);
				}

				$created++;
				self::log(['created_post'=>$new_id,'video_id'=>$vid,'slug'=>$slug]);
			}

			if ( $max_pages > 0 && $page >= $max_pages ) break;
		} while ( ! empty($next) );

		return ['new_posts'=>$created,'existing_posts'=>$skipped,'errors'=>$errors];
	}

	/* ===== AJAX ===== */
	public static function ajax_generate() : void {
		self::verify_ajax_or_fail();
		$limit = isset($_POST['pages']) ? max(0, intval($_POST['pages'])) : 0;
		$result = self::generate( self::channel_id(), $limit );
		if (is_wp_error($result)) wp_send_json_error(['message'=>$result->get_error_message()]);
		wp_send_json_success($result);
	}
	public static function ajax_toggle_debug() : void {
		self::verify_ajax_or_fail();
		$enabled = ! empty($_POST['enabled']) ? 1 : 0;
		update_option('myls_youtube_debug', $enabled, false);
		wp_send_json_success(['enabled'=>$enabled]);
	}
	public static function ajax_get_log() : void {
		self::verify_ajax_or_fail();
		$log = (array) get_option('myls_youtube_debug_log', []);
		wp_send_json_success(['log'=>$log]);
	}
	public static function ajax_clear_log() : void {
		self::verify_ajax_or_fail();
		delete_option('myls_youtube_debug_log');
		wp_send_json_success('cleared');
	}

	/* ===== Shortcode (optional) ===== */
	public static function shortcode_with_transcript($atts) {
		$atts = shortcode_atts(['url'=>''], $atts, 'myls_youtube_with_transcript');
		if ( empty($atts['url']) ) return '<p><em>No YouTube URL provided.</em></p>';
		if ( ! preg_match('%(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_-]{11})%i', $atts['url'], $m) ) {
			return '<p><em>Invalid YouTube URL.</em></p>';
		}
		$video_id = $m[1];
		$html = '<div class="myls-youtube-wrapper">' . self::embed_block_from_url( 'https://www.youtube.com/watch?v='.$video_id );

		$key = self::api_key();
		if ($key) {
			$r = wp_remote_get( add_query_arg(['part'=>'snippet','id'=>$video_id,'key'=>$key],'https://www.googleapis.com/youtube/v3/videos'), ['timeout'=>20] );
			if ( ! is_wp_error($r) && 200 === wp_remote_retrieve_response_code($r) ) {
				$data = json_decode( wp_remote_retrieve_body($r), true );
				$desc = $data['items'][0]['snippet']['description'] ?? '';
				if ($desc) $html .= '<div class="myls-youtube-description">'. wpautop( esc_html($desc) ) .'</div>';
			}
		}

		// best-effort transcript
		$lines = [];
		$list  = wp_remote_get("https://video.google.com/timedtext?type=list&v={$video_id}", ['timeout'=>15]);
		if ( ! is_wp_error($list) && 200 === wp_remote_retrieve_response_code($list) ) {
			$xml = simplexml_load_string( wp_remote_retrieve_body($list) );
			if ( isset($xml->track[0]['lang_code']) ) {
				$lang = (string) $xml->track[0]['lang_code'];
				$tts  = wp_remote_get("https://video.google.com/timedtext?lang={$lang}&v={$video_id}", ['timeout'=>20]);
				if ( ! is_wp_error($tts) && 200 === wp_remote_retrieve_response_code($tts) ) {
					$tts_xml = simplexml_load_string( wp_remote_retrieve_body($tts) );
					foreach ($tts_xml->text as $t) $lines[] = esc_html( html_entity_decode( (string) $t ) );
				}
			}
		}
		if ($lines) {
			$html .= '<details class="myls-yt-transcript" style="margin-top:1rem;"><summary><strong>'
			      . esc_html__('Transcript','myls')
			      . '</strong></summary><div style="margin-top:.5rem;"><p>'
			      . implode('</p><p>',$lines)
			      . '</p></div></details>';
		}
		return $html . '</div>';
	}
}

MYLS_Youtube::init();
