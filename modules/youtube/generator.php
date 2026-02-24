<?php
/**
 * MYLS: YouTube → Draft generator
 * - Title: emoji/hashtag-free, first sentence only, MAX 5 words (strict).
 * - Slug: built ONLY from optimized title (never video id), MAX 5 tokens, optional prefix.
 * - Content: EMBED → Accordion(Description, Transcript).
 *
 * AJAX:
 *   action = myls_youtube_generate_drafts
 *   POST: { nonce: wp_create_nonce('myls_api_tab_ajax'), limit?: int }
 */

if ( ! defined('ABSPATH') ) exit;

// Ensure helpers are available (myls_yt_* funcs)
if ( ! function_exists('myls_yt_get_api_key') ) {
	require_once __DIR__ . '/helpers.php';
}

if ( ! class_exists('MYLS_YT_Generator') ) {
	final class MYLS_YT_Generator {

		public static function init() : void {
			if ( is_admin() ) {
				add_action('wp_ajax_myls_youtube_generate_drafts', [__CLASS__, 'ajax_generate']);
			}
		}

		/* -----------------------------------------------------------------
		 * Title optimizer:
		 *  - strip tags/emojis
		 *  - remove entire #hashtags
		 *  - prefer left chunk before separators (| — – - : • ·)
		 *  - snap to FIRST sentence (., !, ?, or line break)
		 *  - hard-cap to 5 words (filterable)
		 * ----------------------------------------------------------------- */
		private static function optimize_title( string $raw ) : string {
			$t = wp_strip_all_tags( $raw, true );

			// remove emojis (common ranges)
			$t = preg_replace('/[\x{1F1E6}-\x{1F1FF}\x{1F300}-\x{1FAFF}\x{1F900}-\x{1F9FF}\x{2600}-\x{27BF}]/u', '', $t);

			// normalize spaces
			$t = preg_replace('/\s+/', ' ', trim($t));

			// strip hashtags (entire token)
			$t = preg_replace('/(^|\s)#[^\s#]+/u', ' ', $t);
			$t = preg_replace('/\s+/', ' ', trim($t));

			// take left side of common separators first
			$parts = preg_split('/\s*[\|\-–—:•·]\s*/u', $t, -1, PREG_SPLIT_NO_EMPTY);
			if ( ! empty($parts) ) $t = trim($parts[0]);

			// snap to first sentence
			$sent = preg_split('/[\.!\?]+|\R/u', $t, 2, PREG_SPLIT_NO_EMPTY);
			if ( ! empty($sent) ) $t = trim($sent[0]);

			// word cap
			$cap   = (int) apply_filters('myls_optimize_title_word_cap', 5);
			$words = preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY);
			if ( count($words) > $cap ) $words = array_slice($words, 0, $cap);
			$t = implode(' ', $words);

			// final tidy
			$t = trim($t, " \t\n\r\0\x0B-–—:•·.,!?");

			if ( $t === '' ) $t = 'Video ' . date_i18n('Y-m-d');
			return $t;
		}

		/* -----------------------------------------------------------------
		 * Slug builder from optimized title ONLY:
		 *  - sanitize_title → tokens
		 *  - keep max 5 tokens (filterable)
		 *  - optional prefix
		 *  - limit length ~60 chars (filterable)
		 * ----------------------------------------------------------------- */
		private static function build_slug( string $optimized_title, string $prefix = '' ) : string {
			$base    = sanitize_title( $optimized_title );
			$tokens  = array_values( array_filter( explode('-', $base) ) );

			$word_cap = (int) apply_filters('myls_optimize_slug_word_cap', 5);
			if ( count($tokens) > $word_cap ) $tokens = array_slice($tokens, 0, $word_cap);

			$slug = implode('-', $tokens);
			if ( $prefix !== '' ) {
				$slug = sanitize_title( $prefix ) . '-' . $slug;
			}

			$char_cap = (int) apply_filters('myls_optimize_slug_char_cap', 60);
			if ( strlen($slug) > $char_cap ) {
				$slug = substr($slug, 0, $char_cap);
				$slug = trim($slug, '-');
			}
			return $slug !== '' ? $slug : sanitize_title( 'video-' . date('Ymd-His') );
		}

		/** Main: generate drafts from channel uploads */
		public static function generate_from_channel( ?string $channel_id = null, int $limit = 0 ) {
			if ( ! current_user_can('manage_options') ) {
				return new WP_Error('myls_forbidden', 'You do not have permission to generate drafts.');
			}

			$api_key    = myls_yt_get_api_key();
			$channel_id = $channel_id ? sanitize_text_field($channel_id) : myls_yt_get_channel_id();
			if ( $api_key === '' )    return new WP_Error('myls_no_api_key', 'YouTube API key not configured.');
			if ( $channel_id === '' ) return new WP_Error('myls_no_channel', 'YouTube Channel ID not configured.');

			$playlist = myls_yt_get_uploads_playlist_id($channel_id, $api_key);
			if ( $playlist === '' )   return new WP_Error('myls_no_playlist', 'Could not resolve uploads playlist for this channel.');

			$items = myls_yt_fetch_uploads_batch($playlist, $api_key);
			if ( $limit > 0 ) $items = array_slice($items, 0, max(1, $limit));

			$status      = get_option('myls_ytvb_status', 'draft');
			if ( ! in_array($status, ['draft','pending','publish'], true) ) $status = 'draft';
			$cat_id      = (int) get_option('myls_ytvb_category', 0);
			$slug_prefix = sanitize_title( (string) get_option('myls_ytvb_slug_prefix', 'video') );

			$results = ['created'=>0,'skipped'=>0,'updated'=>0,'errors'=>[]];

			foreach ( $items as $it ) {
				$video_id = $it['videoId'] ?? '';
				if ( ! $video_id ) continue;

				// optimized title (strict)
				$raw_title  = (string) ($it['title'] ?? '');
				$nice_title = self::optimize_title( $raw_title );

				// slug ONLY from optimized title (never from raw or video id)
				$final_slug = self::build_slug( $nice_title, $slug_prefix );

				// check existing post by meta or by path
				$existing_id = self::find_existing_post_id($video_id, $final_slug);

				// content: EMBED → accordion (Description, Transcript)
				$embed_html = myls_yt_embed_html($video_id);

				$desc = myls_yt_fetch_description($video_id, $api_key);
				if ( $desc === '' && ! empty($it['description']) ) $desc = (string) $it['description'];
				$desc_html = $desc !== '' ? wpautop( make_clickable( esc_html( $desc ) ) ) : '<p><em>No description available.</em></p>';

				$lines = myls_yt_fetch_transcript($video_id);
				$transcript_html = $lines ? ('<p>' . implode('</p><p>', $lines) . '</p>') : '<p><em>Transcript not available.</em></p>';

				$acc_id = 'mylsAcc_' . sanitize_html_class($video_id);
				$accordion = ''
					. '<div class="myls-video-accordion" style="margin-top:1rem;margin-bottom:1.25rem;">'
					. '  <div class="accordion" id="'.esc_attr($acc_id).'">'
					. '    <div class="accordion-item">'
					. '      <h2 class="accordion-header" id="'.esc_attr($acc_id).'_h1">'
					. '        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#'.esc_attr($acc_id).'_c1" aria-expanded="true" aria-controls="'.esc_attr($acc_id).'_c1">Description</button>'
					. '      </h2>'
					. '      <div id="'.esc_attr($acc_id).'_c1" class="accordion-collapse collapse show" aria-labelledby="'.esc_attr($acc_id).'_h1" data-bs-parent="#'.esc_attr($acc_id).'">'
					. '        <div class="accordion-body">'.$desc_html.'</div>'
					. '      </div>'
					. '    </div>'
					. '    <div class="accordion-item">'
					. '      <h2 class="accordion-header" id="'.esc_attr($acc_id).'_h2">'
					. '        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#'.esc_attr($acc_id).'_c2" aria-expanded="false" aria-controls="'.esc_attr($acc_id).'_c2">Transcript</button>'
					. '      </h2>'
					. '      <div id="'.esc_attr($acc_id).'_c2" class="accordion-collapse collapse" aria-labelledby="'.esc_attr($acc_id).'_h2" data-bs-parent="#'.esc_attr($acc_id).'">'
					. '        <div class="accordion-body">'.$transcript_html.'</div>'
					. '      </div>'
					. '    </div>'
					. '  </div>'
					. '</div>';

				$content = $embed_html . "\n\n" . $accordion;

				$postarr = [
					'post_title'   => $nice_title,
					'post_name'    => $final_slug,
					'post_content' => $content,
					'post_status'  => $status,
					'post_type'    => 'video',
				];

				if ( $existing_id ) {
					$postarr['ID'] = $existing_id;
					$updated = wp_update_post( $postarr, true );
					if ( is_wp_error($updated) ) {
						$results['errors'][] = 'Update failed for '.$video_id.': '.$updated->get_error_message();
						continue;
					}
					$results['updated']++;
					update_post_meta($existing_id, '_myls_video_id', $video_id);
				} else {
					$new_id = wp_insert_post( $postarr, true );
					if ( is_wp_error($new_id) ) {
						$results['errors'][] = 'Insert failed for '.$video_id.': '.$new_id->get_error_message();
						continue;
					}
					if ( $cat_id > 0 ) wp_set_post_categories( $new_id, [ $cat_id ], false );
					update_post_meta( $new_id, '_myls_video_id', $video_id );
					$results['created']++;
				}
			}

			return $results;
		}

		/** Find an existing post by meta (_myls_video_id / _ssseo_video_id) or by slug */
		private static function find_existing_post_id( string $video_id, string $final_slug ) : int {
			$q = get_posts([
				'post_type'        => 'video',
				'post_status'      => ['publish','draft','pending','future','private'],
				'meta_key'         => '_myls_video_id',
				'meta_value'       => $video_id,
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			]);
			if ( ! empty($q) ) return (int) $q[0];

			$q2 = get_posts([
				'post_type'        => 'video',
				'post_status'      => ['publish','draft','pending','future','private'],
				'meta_key'         => '_ssseo_video_id',
				'meta_value'       => $video_id,
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			]);
			if ( ! empty($q2) ) return (int) $q2[0];

			$maybe = get_page_by_path( $final_slug, OBJECT, 'video' );
			return $maybe ? (int) $maybe->ID : 0;
		}

		public static function ajax_generate() : void {
			if ( ! current_user_can('manage_options') ) {
				wp_send_json_error(['message'=>'Insufficient permissions.']);
			}
			$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
			if ( ! wp_verify_nonce( $nonce, 'myls_api_tab_ajax' ) ) {
				wp_send_json_error(['message'=>'Invalid nonce.']);
			}

			$limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 0;
			$res = self::generate_from_channel( null, $limit );
			if ( is_wp_error($res) ) {
				wp_send_json_error(['message'=>$res->get_error_message()]);
			}
			wp_send_json_success($res);
		}
	}

	MYLS_YT_Generator::init();
}
