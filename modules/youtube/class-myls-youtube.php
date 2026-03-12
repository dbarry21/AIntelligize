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
	private static function status()          { $s = get_option('myls_ytvb_status','publish'); return in_array($s,['draft','pending','publish'],true) ? $s : 'publish'; }
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

	/* ===== Transcript Accordion ===== */
	/**
	 * Build a Bootstrap 5 accordion containing the transcript for a video.
	 * Reads from the myls_video_transcripts table (supports manual edits).
	 */
	private static function build_transcript_accordion( string $video_id ) : string {
		if ( ! function_exists('myls_vt_get_by_id') ) return '';

		$row = myls_vt_get_by_id( $video_id );
		if ( ! $row || $row['status'] !== 'ok' || empty( $row['transcript'] ) ) return '';

		$uid = 'ytvbTT-' . esc_attr( $video_id );
		$html  = '<div class="accordion myls-ytvb-transcript-accordion" id="' . $uid . '" style="margin-top:1.5rem;">';
		$html .=   '<div class="accordion-item">';
		$html .=     '<h2 class="accordion-header" id="' . $uid . '-h">';
		$html .=       '<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#' . $uid . '-body" aria-expanded="false" aria-controls="' . $uid . '-body">';
		$html .=         'Transcript';
		$html .=       '</button>';
		$html .=     '</h2>';
		$html .=     '<div id="' . $uid . '-body" class="accordion-collapse collapse" aria-labelledby="' . $uid . '-h" data-bs-parent="#' . $uid . '">';
		$html .=       '<div class="accordion-body" style="max-height:400px;overflow-y:auto;font-size:14px;line-height:1.7;">';
		$html .=         wpautop( esc_html( $row['transcript'] ) );
		$html .=       '</div>';
		$html .=     '</div>';
		$html .=   '</div>';
		$html .= '</div>';

		return $html;
	}

	/* ===== Email Notification ===== */
	private static function maybe_send_notification( array $result ) : void {
		if ( get_option('myls_ytvb_notify_email_enabled', '0') !== '1' ) return;

		$to = trim( (string) get_option('myls_ytvb_notify_email', '') );
		if ( $to === '' ) $to = get_option('admin_email');
		if ( empty($to) ) return;

		$new     = (int) ($result['new_posts'] ?? 0);
		$updated = (int) ($result['updated_posts'] ?? 0);
		$skipped = (int) ($result['existing_posts'] ?? 0);
		$errors  = $result['errors'] ?? [];

		$subject = 'AIntelligize: YouTube Blog Generation Complete';

		$body  = '<h2 style="margin:0 0 12px;">YouTube Blog Generation Results</h2>';
		$body .= '<table style="border-collapse:collapse;font-family:sans-serif;font-size:14px;">';
		$body .= '<tr><td style="padding:4px 12px 4px 0;font-weight:600;">New Posts:</td><td>' . $new . '</td></tr>';
		$body .= '<tr><td style="padding:4px 12px 4px 0;font-weight:600;">Updated:</td><td>' . $updated . '</td></tr>';
		$body .= '<tr><td style="padding:4px 12px 4px 0;font-weight:600;">Skipped:</td><td>' . $skipped . '</td></tr>';
		if ( ! empty($errors) ) {
			$body .= '<tr><td style="padding:4px 12px 4px 0;font-weight:600;color:#dc3545;">Errors:</td><td>' . count($errors) . '</td></tr>';
		}
		$body .= '</table>';
		$body .= '<p style="margin-top:12px;font-size:13px;color:#666;">Generated at ' . esc_html( current_time('M j, Y g:i A') ) . '</p>';

		$headers = ['Content-Type: text/html; charset=UTF-8'];
		wp_mail( $to, $subject, $body, $headers );
	}

	/* ===== Generation ===== */

	/** Manual generation (admin AJAX / direct call) — checks user capability. */
	public static function generate( ?string $channel_id = null, int $max_pages = 0, bool $overwrite = false ) {
		if ( ! current_user_can('manage_options') ) {
			return new WP_Error('forbidden','You do not have permission to run this function.');
		}
		if ( ! self::enabled() ) {
			return new WP_Error('disabled','YT → Blog is disabled in settings.');
		}
		return self::do_generate( $channel_id, $max_pages, $overwrite );
	}

	/** Cron-safe generation — no capability check (WP-Cron has no logged-in user). */
	public static function generate_cron( ?string $channel_id = null, int $max_pages = 0, bool $overwrite = false ) {
		if ( ! self::enabled() ) {
			self::log('Cron: YT → Blog is disabled in settings.');
			return ['new_posts'=>0,'existing_posts'=>0,'updated_posts'=>0,'errors'=>['Disabled in settings.']];
		}
		return self::do_generate( $channel_id, $max_pages, $overwrite );
	}

	/** Shared generation logic used by both generate() and generate_cron(). */
	private static function do_generate( ?string $channel_id, int $max_pages, bool $overwrite ) {
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

		$fetch_transcript = get_option('myls_ytvb_fetch_transcript', '0') === '1';
		$next = '';
		$created = 0; $skipped = 0; $updated = 0; $errors = []; $page = 0;

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

				// Compose content via template tokens
				$embed_markup = $autoembed ? self::embed_block_from_url($url) : self::iframe_embed($vid);

				// Fetch & store transcript if enabled (populates DB before token rendering)
				if ( $fetch_transcript && function_exists('myls_vt_get_by_id') && function_exists('_myls_vt_do_fetch') ) {
					$existing_tt = myls_vt_get_by_id( $vid );
					if ( ! $existing_tt || ! in_array( $existing_tt['status'], ['ok','manual'], true ) ) {
						$tt_result = _myls_vt_do_fetch( $vid );
						if ( $tt_result !== null ) {
							myls_vt_upsert([
								'video_id'   => $vid,
								'title'      => $title,
								'transcript' => $tt_result['transcript'],
								'lang'       => $tt_result['lang'],
								'source'     => $tt_result['source'],
								'status'     => 'ok',
								'fetched_at' => current_time('mysql'),
							]);
							self::log(['transcript_fetched'=>$vid,'source'=>$tt_result['source']]);
						} else {
							myls_vt_upsert([
								'video_id'   => $vid,
								'title'      => $title,
								'status'     => 'none',
								'fetched_at' => current_time('mysql'),
							]);
						}
					}
				}

				// Build transcript accordion if token is used and transcript exists in DB
				$transcript_html = '';
				if ( strpos( $content_tpl, '{transcript}' ) !== false ) {
					$transcript_html = self::build_transcript_accordion( $vid );
				}

				$vars = [
					'title'       => $title,
					'description' => esc_html( $desc ),
					'channel'     => $channel_t,
					'date'        => esc_html( $date ),
					'url'         => esc_url( $url ),
					'slug'        => esc_html( $slug_base ),
					'embed'       => $embed_markup,
					'transcript'  => $transcript_html,
				];

				$final_title   = self::render_tokens( $title_tpl, $vars );
				$final_content = self::render_tokens( $content_tpl, $vars );

				// Duplicate check: by video ID meta only
				$dup = get_posts([
					'post_type'      => $post_type,
					'posts_per_page' => 1,
					'meta_key'       => '_myls_youtube_video_id',
					'meta_value'     => $vid,
					'fields'         => 'ids',
					'post_status'    => 'any',
				]);

				$existing_id = $dup ? $dup[0] : 0;

				if ( $existing_id ) {
					if ( $overwrite ) {
						$upd = wp_update_post([
							'ID'           => $existing_id,
							'post_title'   => $final_title,
							'post_content' => $final_content,
						], true);
						if ( is_wp_error($upd) ) {
							$errors[] = 'Update failed for '.$vid.': '.$upd->get_error_message();
							self::log(end($errors));
						} else {
							$updated++;
							self::log(['updated_post'=>$existing_id,'video_id'=>$vid,'slug'=>$slug]);
						}
					} else {
						$skipped++;
						self::log(['skip'=>'exists','vid'=>$vid,'post_id'=>$existing_id]);
					}
					continue;
				}

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

		$result = ['new_posts'=>$created,'existing_posts'=>$skipped,'updated_posts'=>$updated,'errors'=>$errors];

		update_option('myls_ytvb_last_run_time',   current_time('mysql'), false);
		update_option('myls_ytvb_last_run_result',  $result, false);

		self::maybe_send_notification( $result );

		return $result;
	}

	/* ===== AJAX ===== */
	public static function ajax_generate() : void {
		self::verify_ajax_or_fail();
		$limit     = isset($_POST['pages']) ? max(0, intval($_POST['pages'])) : 0;
		$overwrite = ! empty($_POST['overwrite']);
		$result    = self::generate( self::channel_id(), $limit, $overwrite );
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
