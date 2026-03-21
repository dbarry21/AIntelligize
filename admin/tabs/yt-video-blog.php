<?php
/**
 * Admin Tab: YT Video Blog
 * Path: admin/tabs/yt-video-blog.php
 *
 * Purpose:
 * - Configure templates and defaults, run generator, toggle debug and view logs.
 * - Title cleaning to remove hashtags/emojis/symbols and enforce short titles.
 *
 * How to use the cleaner in your generator:
 *   $raw_title = isset($youtube_item['title']) ? $youtube_item['title'] : '';
 *   $clean     = function_exists('myls_ytvb_clean_title') ? myls_ytvb_clean_title($raw_title) : $raw_title;
 *   // Now feed $clean into your {title} token and also build the slug from $clean
 */

if ( ! defined('ABSPATH') ) exit;

/* -----------------------------------------------------------------------------
 * Title Cleaning Helpers
 * ---------------------------------------------------------------------------*/

/**
 * Remove emoji and most pictographic symbols (safe for WP admin UI).
 */
if ( ! function_exists('myls_ytvb_strip_emoji') ) {
	function myls_ytvb_strip_emoji( $s ) {
		// Broad, but safe ranges for emoji/pictographs/symbols
		$regex = '/[\x{1F100}-\x{1F1FF}\x{1F300}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u';
		return preg_replace($regex, '', $s);
	}
}

/**
 * Return a short, clean title:
 *  - strips emoji/symbols
 *  - removes hashtags (#word) anywhere
 *  - removes URLs
 *  - trims common separators, extra punctuation and spaces
 *  - limits to N words (default option: 5)
 * Options (via Settings below):
 *  - myls_ytvb_strip_hashtags (1/0)
 *  - myls_ytvb_strip_emojis   (1/0)
 *  - myls_ytvb_title_max_words (int)
 */
if ( ! function_exists('myls_ytvb_clean_title') ) {
	function myls_ytvb_clean_title( $raw ) {
		$raw = html_entity_decode( wp_strip_all_tags( (string) $raw ), ENT_QUOTES, 'UTF-8' );

		$strip_hash = get_option('myls_ytvb_strip_hashtags', '1') === '1';
		$strip_emo  = get_option('myls_ytvb_strip_emojis',   '1') === '1';
		$max_words  = (int) get_option('myls_ytvb_title_max_words', 5);
		if ($max_words < 3)  $max_words = 3;
		if ($max_words > 12) $max_words = 12;

		$s = $raw;

		// 0) Remove URLs early
		$s = preg_replace('~https?://\S+~i', '', $s);

		// 1) HARD CUTOFF: keep everything BEFORE the first '#'
		//    (So any hashtags and anything after them is discarded.)
		if (preg_match('/^(.*?)(?:\s*#|$)/u', $s, $m)) {
			$s = isset($m[1]) ? trim($m[1]) : $s;
		}

		// 2) Remove leading bullet/emoji markers and decorative symbols
		$s = preg_replace('/^[\p{Ps}\p{Pe}\p{Pi}\p{Pf}\p{Po}\p{S}\p{Zs}]+/u', '', $s);

		// 3) Optionally strip emoji/pictographs
		if ( $strip_emo && function_exists('myls_ytvb_strip_emoji') ) {
			$s = myls_ytvb_strip_emoji($s);
		}

		// 4) Normalize separators to spaces
		$s = str_replace(array('|','/','\\','–','—','·','•','►','»','«'), ' ', $s);

		// 5) Collapse multiple punctuation
		$s = preg_replace('/[[:punct:]]{2,}/u', ' ', $s);

		// 6) Extra safety: if hashtags remain (e.g. exotic unicode), drop them
		if ( $strip_hash ) {
			$s = preg_replace('/(^|\s)#\S+/u', ' ', $s);
		}

		// 7) Collapse whitespace
		$s = preg_replace('/\s+/u', ' ', trim($s));

		// 8) Enforce max words (keep original casing)
		$parts = preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
		if ( is_array($parts) && count($parts) > $max_words ) {
			$parts = array_slice($parts, 0, $max_words);
			$s     = implode(' ', $parts);
		}

		// 9) Final trim of leftover punctuation at ends
		$s = trim($s, " \t\n\r\0\x0B-_.:,;!?#*()[]{}\"'");

		return $s !== '' ? $s : ( $raw !== '' ? $raw : 'Video' );
	}
}

/**
 * Filter hook so your generator can just do:
 *   $clean_title = apply_filters('myls_ytvb_prepare_title', $raw_title, array('max_words'=>5));
 */
add_filter('myls_ytvb_prepare_title', function( $title, $args = array() ){
	if ( isset($args['max_words']) && is_numeric($args['max_words']) ) {
		$mw = (int) $args['max_words'];
		if ($mw < 3)  $mw = 3;
		if ($mw > 12) $mw = 12;
		update_option('myls_ytvb_title_max_words', $mw); // temp override if desired
	}
	return myls_ytvb_clean_title( (string) $title );
}, 10, 2);


/* -----------------------------------------------------------------------------
 * AJAX: Backfill thumbnail URLs for existing video posts
 * ---------------------------------------------------------------------------*/
add_action('wp_ajax_myls_youtube_backfill_thumbs', function() {
	check_ajax_referer('myls_ytvb_ajax', 'nonce');
	if ( ! current_user_can('manage_options') ) wp_send_json_error(['message'=>'Forbidden']);

	$posts = get_posts([
		'post_type'      => 'video',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	]);

	$updated = 0;
	$skipped = 0;
	$no_id   = 0;

	foreach ( $posts as $pid ) {
		// Already has a thumbnail URL
		$existing = get_post_meta( $pid, '_myls_video_thumb_url', true );
		if ( is_string( $existing ) && $existing !== '' ) {
			$skipped++;
			continue;
		}

		// Find video ID
		$vid = '';
		foreach ( ['_myls_youtube_video_id', '_myls_video_id', '_ssseo_video_id'] as $key ) {
			$val = get_post_meta( $pid, $key, true );
			if ( is_string( $val ) && trim( $val ) !== '' ) {
				$vid = trim( $val );
				break;
			}
		}

		if ( $vid === '' ) {
			$no_id++;
			continue;
		}

		$thumb = function_exists('myls_yt_thumbnail_url') ? myls_yt_thumbnail_url( $vid ) : "https://i.ytimg.com/vi/{$vid}/hqdefault.jpg";
		update_post_meta( $pid, '_myls_video_thumb_url', $thumb );
		$updated++;
	}

	wp_send_json_success([ 'updated' => $updated, 'skipped' => $skipped, 'no_id' => $no_id ]);
});

/* -----------------------------------------------------------------------------
 * Admin Tab (settings + runner UI)
 * ---------------------------------------------------------------------------*/
myls_register_admin_tab(array(
	'id'    => 'yt-video-blog',
	'title' => 'YT Video Blog',
	'order' => 25,
	'cap'   => 'manage_options',
	'icon'  => 'dashicons-video-alt3',
	'cb'    => function () {

		/* ===== Save settings ===== */
		if (
			isset($_POST['myls_ytvb_nonce']) &&
			wp_verify_nonce( $_POST['myls_ytvb_nonce'], 'myls_ytvb_save' ) &&
			current_user_can('manage_options')
		) {
			update_option('myls_ytvb_enabled', isset($_POST['myls_ytvb_enabled']) ? '1' : '0');

			$status = isset($_POST['myls_ytvb_status']) ? sanitize_key( wp_unslash($_POST['myls_ytvb_status']) ) : 'publish';
			if ( ! in_array( $status, array('draft','pending','publish'), true ) ) $status = 'publish';
			update_option('myls_ytvb_status', $status);

			update_option('myls_ytvb_category',  isset($_POST['myls_ytvb_category']) ? absint($_POST['myls_ytvb_category']) : 0);
			update_option('myls_ytvb_autoembed', isset($_POST['myls_ytvb_autoembed']) ? '1' : '0');

			$title_tpl   = isset($_POST['myls_ytvb_title_tpl'])
				? wp_kses_post( wp_unslash($_POST['myls_ytvb_title_tpl']) )
				: '{title}';
			$content_tpl = isset($_POST['myls_ytvb_content_tpl'])
				? wp_kses_post( wp_unslash($_POST['myls_ytvb_content_tpl']) )
				: "<p>{description}</p>\n{embed}\n<p>Source: {channel}</p>";
			update_option('myls_ytvb_title_tpl',   $title_tpl);
			update_option('myls_ytvb_content_tpl', $content_tpl);

			$slug_prefix = isset($_POST['myls_ytvb_slug_prefix']) ? sanitize_title( wp_unslash($_POST['myls_ytvb_slug_prefix']) ) : 'video';
			update_option('myls_ytvb_slug_prefix', $slug_prefix);

			$post_type = isset($_POST['myls_ytvb_post_type']) ? sanitize_key($_POST['myls_ytvb_post_type']) : 'post';
			update_option('myls_ytvb_post_type', post_type_exists($post_type) ? $post_type : 'post');

			// Title cleaner options
			update_option('myls_ytvb_strip_hashtags', isset($_POST['myls_ytvb_strip_hashtags']) ? '1' : '0');
			update_option('myls_ytvb_strip_emojis',   isset($_POST['myls_ytvb_strip_emojis'])   ? '1' : '0');
			$max_words = isset($_POST['myls_ytvb_title_max_words']) ? (int) $_POST['myls_ytvb_title_max_words'] : 5;
			if ($max_words < 3)  $max_words = 3;
			if ($max_words > 12) $max_words = 12;
			update_option('myls_ytvb_title_max_words', $max_words);

			// Auto-refresh (cron) toggle
			$was_auto = get_option('myls_ytvb_auto_refresh', '0');
			$new_auto = isset($_POST['myls_ytvb_auto_refresh']) ? '1' : '0';
			update_option('myls_ytvb_auto_refresh', $new_auto);

			if ( $new_auto === '1' && $was_auto !== '1' ) {
				if ( ! wp_next_scheduled('myls_ytvb_auto_generate') ) {
					wp_schedule_event( time(), 'myls_every_12_hours', 'myls_ytvb_auto_generate' );
				}
			} elseif ( $new_auto === '0' && $was_auto === '1' ) {
				wp_clear_scheduled_hook('myls_ytvb_auto_generate');
			}

			// Overwrite existing posts toggle
			update_option('myls_ytvb_overwrite', isset($_POST['myls_ytvb_overwrite']) ? '1' : '0');

			// Fetch Transcript toggle
			update_option('myls_ytvb_fetch_transcript', isset($_POST['myls_ytvb_fetch_transcript']) ? '1' : '0');

			// Email notification
			update_option('myls_ytvb_notify_email_enabled', isset($_POST['myls_ytvb_notify_email_enabled']) ? '1' : '0');
			$notify_email = isset($_POST['myls_ytvb_notify_email']) ? sanitize_email( wp_unslash($_POST['myls_ytvb_notify_email']) ) : '';
			update_option('myls_ytvb_notify_email', $notify_email);

			// Play button color
			$play_color = isset($_POST['myls_ytvb_play_button_color']) ? sanitize_hex_color( wp_unslash($_POST['myls_ytvb_play_button_color']) ) : '';
			update_option('myls_ytvb_play_button_color', $play_color ?: '');

			// Default fallback video ID
			$default_vid = isset($_POST['myls_ytvb_default_video_id']) ? preg_replace( '/[^A-Za-z0-9_-]/', '', wp_unslash($_POST['myls_ytvb_default_video_id']) ) : '';
			if ( $default_vid !== '' && strlen( $default_vid ) !== 11 ) $default_vid = '';
			update_option('myls_ytvb_default_video_id', $default_vid);

			echo '<div class="notice notice-success is-dismissible"><p>YT Video Blog settings saved.</p></div>';
		}

		/* ===== Load settings ===== */
		$enabled      = get_option('myls_ytvb_enabled', '0');
		$status       = get_option('myls_ytvb_status', 'publish');
		$cat_id       = (int) get_option('myls_ytvb_category', 0);
		$auto_embed   = get_option('myls_ytvb_autoembed', '1');
		$title_tpl    = get_option('myls_ytvb_title_tpl', '{title}');
		$content_tpl  = get_option('myls_ytvb_content_tpl', "<p>{description}</p>\n{embed}\n<p>Source: {channel}</p>");
		$slug_prefix  = get_option('myls_ytvb_slug_prefix', 'video');
		$post_type    = get_option('myls_ytvb_post_type', 'post');
		$strip_hash   = get_option('myls_ytvb_strip_hashtags', '1');
		$strip_emo    = get_option('myls_ytvb_strip_emojis', '1');
		$max_words    = (int) get_option('myls_ytvb_title_max_words', 5);
		$auto_refresh    = get_option('myls_ytvb_auto_refresh', '0');
		$overwrite       = get_option('myls_ytvb_overwrite', '0');
		$fetch_transcript= get_option('myls_ytvb_fetch_transcript', '0');
		$play_btn_color  = get_option('myls_ytvb_play_button_color', '');
		$default_video_id = get_option('myls_ytvb_default_video_id', '');
		$notify_enabled  = get_option('myls_ytvb_notify_email_enabled', '0');
		$notify_email    = get_option('myls_ytvb_notify_email', '');
		$last_run        = get_option('myls_ytvb_last_run_time', '');
		$last_result     = get_option('myls_ytvb_last_run_result', []);
		$next_run        = wp_next_scheduled('myls_ytvb_auto_generate');

		$yt_api_key   = get_option('myls_youtube_api_key','');
		$yt_channel   = get_option('myls_youtube_channel_id','');

		$categories   = get_categories(array('hide_empty'=>false,'taxonomy'=>'category'));
		$ajax_nonce   = wp_create_nonce('myls_ytvb_ajax');

		$token_help   = '<code>{title}</code> <code>{description}</code> <code>{channel}</code> <code>{date}</code> <code>{embed}</code> <code>{url}</code> <code>{slug}</code> <code>{transcript}</code>';
		?>
		<div class="wrap myls-admin-wrap myls-ytvb" style="max-width:1200px;">
			<h1 class="wp-heading-inline" style="margin-bottom:.5rem;">YT Video Blog <a href="<?php echo esc_url( plugins_url( 'admin/docs/yt-video-blog-guide.pdf', MYLS_MAIN_FILE ) ); ?>" target="_blank" rel="noopener" title="YT Video Blog Guide (PDF)" style="font-size:16px;vertical-align:middle;margin-left:4px;text-decoration:none;color:#0d6efd;"><i class="bi bi-file-earmark-pdf"></i></a></h1>
			<p class="myls-text-muted" style="margin-top:0;">Use your YouTube channel to auto-create posts with a template. API key &amp; Channel ID come from <em>API Integration</em>.</p>

			<?php if ( empty($yt_api_key) || empty($yt_channel) ) : ?>
				<div class="myls-status myls-status-warning" style="margin-bottom:1rem;">
					<strong>Missing API settings:</strong> Set your YouTube API Key &amp; Channel ID in the <em>API Integration</em> tab.
				</div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field('myls_ytvb_save', 'myls_ytvb_nonce'); ?>

				<!-- Card 1: General -->
				<div class="myls-card">
					<div class="myls-card-header">
						<h2 class="myls-card-title"><i class="bi bi-gear"></i> General</h2>
					</div>
					<div class="row g-3">
						<div class="col-md-6">
							<div class="form-check form-switch mb-3">
								<input class="form-check-input" type="checkbox" id="myls_ytvb_enabled" name="myls_ytvb_enabled" value="1" <?php checked('1', $enabled); ?>>
								<label class="form-check-label" for="myls_ytvb_enabled">Activate YT &rarr; Blog</label>
							</div>
						</div>
						<div class="col-md-6">
							<label class="form-label" for="myls_ytvb_status">Default Post Status</label>
							<select id="myls_ytvb_status" name="myls_ytvb_status" class="form-select">
								<option value="draft"   <?php selected($status,'draft'); ?>>Draft</option>
								<option value="pending" <?php selected($status,'pending'); ?>>Pending Review</option>
								<option value="publish" <?php selected($status,'publish'); ?>>Publish</option>
							</select>
						</div>
						<div class="col-md-6">
							<label class="form-label" for="myls_ytvb_post_type">Post Type</label>
							<select id="myls_ytvb_post_type" name="myls_ytvb_post_type" class="form-select">
								<?php
								$pts = get_post_types(array('public'=>true),'objects');
								foreach ($pts as $pt) {
									printf(
										'<option value="%s"%s>%s</option>',
										esc_attr($pt->name),
										selected($post_type,$pt->name,false),
										esc_html($pt->labels->singular_name.' ('.$pt->name.')')
									);
								}
								?>
							</select>
						</div>
						<div class="col-md-6">
							<label class="form-label" for="myls_ytvb_category">Default Category</label>
							<select id="myls_ytvb_category" name="myls_ytvb_category" class="form-select">
								<option value="0" <?php selected($cat_id, 0); ?>>&mdash; None &mdash;</option>
								<?php foreach ( $categories as $cat ) : ?>
									<option value="<?php echo (int) $cat->term_id; ?>" <?php selected($cat_id, (int)$cat->term_id); ?>>
										<?php echo esc_html( $cat->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="col-md-6">
							<div class="form-check mb-3">
								<input class="form-check-input" type="checkbox" id="myls_ytvb_autoembed" name="myls_ytvb_autoembed" value="1" <?php checked('1', $auto_embed); ?>>
								<label class="form-check-label" for="myls_ytvb_autoembed">Auto-embed Video (oEmbed)</label>
							</div>
						</div>
						<div class="col-md-6">
							<label class="form-label" for="myls_ytvb_slug_prefix">Slug Prefix</label>
							<input type="text" class="form-control" id="myls_ytvb_slug_prefix" name="myls_ytvb_slug_prefix" value="<?php echo esc_attr($slug_prefix); ?>" placeholder="video">
							<div class="form-text">Final slug: <code>{prefix}-{slug}</code></div>
						</div>
					</div>
				</div>

				<!-- Card 2: Templates -->
				<div class="myls-card">
					<div class="myls-card-header">
						<h2 class="myls-card-title"><i class="bi bi-file-earmark-code"></i> Templates</h2>
					</div>
					<div class="mb-3">
						<label class="form-label" for="myls_ytvb_title_tpl">Title Template</label>
						<input type="text" class="form-control" id="myls_ytvb_title_tpl" name="myls_ytvb_title_tpl" value="<?php echo esc_attr($title_tpl); ?>">
						<div class="form-text">Tokens: <?php echo $token_help; ?>. The <code>{title}</code> token uses the cleaned title.</div>
					</div>
					<div class="mb-3">
						<label class="form-label" for="myls_ytvb_content_tpl">Content Template</label>
						<textarea class="form-control font-monospace" id="myls_ytvb_content_tpl" name="myls_ytvb_content_tpl" rows="8"><?php echo esc_textarea($content_tpl); ?></textarea>
						<div class="form-text">Tokens: <?php echo $token_help; ?>. Use <code>{transcript}</code> to insert a collapsible transcript accordion (requires Fetch Transcript enabled + Supadata API key).</div>
					</div>
					<div class="mb-3">
						<div class="form-check form-switch">
							<input class="form-check-input" type="checkbox" id="myls_ytvb_fetch_transcript" name="myls_ytvb_fetch_transcript" value="1" <?php checked('1', $fetch_transcript); ?>>
							<label class="form-check-label" for="myls_ytvb_fetch_transcript"><strong>Fetch Transcript</strong></label>
						</div>
						<div class="form-text">Fetch video transcripts via Supadata API during generation and store in the Video Transcripts database. Requires API key in <em>API Integration &rarr; Supadata</em>. Use <code>{transcript}</code> token in content template to display.</div>
					</div>
					<div class="mb-3">
						<label class="form-label d-block">Title Cleaner</label>
						<div class="d-flex flex-wrap gap-3 align-items-center">
							<div class="form-check">
								<input class="form-check-input" type="checkbox" id="myls_ytvb_strip_hashtags" name="myls_ytvb_strip_hashtags" value="1" <?php checked('1',$strip_hash); ?>>
								<label class="form-check-label" for="myls_ytvb_strip_hashtags">Remove hashtags</label>
							</div>
							<div class="form-check">
								<input class="form-check-input" type="checkbox" id="myls_ytvb_strip_emojis" name="myls_ytvb_strip_emojis" value="1" <?php checked('1',$strip_emo); ?>>
								<label class="form-check-label" for="myls_ytvb_strip_emojis">Remove emojis/symbols</label>
							</div>
							<div class="d-flex align-items-center gap-2">
								<label class="form-label mb-0" for="myls_ytvb_title_max_words">Max words:</label>
								<input type="number" class="form-control" id="myls_ytvb_title_max_words" name="myls_ytvb_title_max_words" min="3" max="12" value="<?php echo (int)$max_words; ?>" style="width:80px;">
							</div>
						</div>
						<div class="form-text">We recommend 5&ndash;6 words for concise, clicky titles.</div>
					</div>
				</div>

				<!-- Card 2b: Display Settings -->
				<div class="myls-card">
					<div class="myls-card-header">
						<h2 class="myls-card-title"><i class="bi bi-palette"></i> Display Settings</h2>
					</div>
					<div class="row g-3 align-items-end">
						<div class="col-md-4">
							<label class="form-label" for="myls_ytvb_play_button_color"><strong>Play Button Color</strong></label>
							<div class="d-flex align-items-center gap-2">
								<input type="color" class="form-control form-control-color" id="myls_ytvb_play_button_color" name="myls_ytvb_play_button_color" value="<?php echo esc_attr( $play_btn_color ?: '#FF0000' ); ?>" title="Choose play button color">
								<code id="myls-play-color-hex"><?php echo esc_html( $play_btn_color ?: '#FF0000' ); ?></code>
							</div>
							<div class="form-text">Default: YouTube Red (#FF0000). Used by <code>[myls_youtube_embed]</code> shortcode globally.</div>
						</div>
						<div class="col-md-4">
							<label class="form-label" for="myls_ytvb_default_video_id"><strong>Default Fallback Video ID</strong></label>
							<input type="text" class="form-control" id="myls_ytvb_default_video_id" name="myls_ytvb_default_video_id" value="<?php echo esc_attr( $default_video_id ); ?>" placeholder="e.g. dQw4w9WgXcQ" maxlength="11" style="font-family:monospace;">
							<div class="form-text">11-char YouTube video ID. Used as fallback when <code>use_page_video="1"</code> but page has no video URL.</div>
						</div>
					</div>
				</div>
				<script>
				document.getElementById('myls_ytvb_play_button_color').addEventListener('input',function(){
					document.getElementById('myls-play-color-hex').textContent=this.value;
				});
				</script>

				<!-- Card 3: Scheduling & Behavior -->
				<div class="myls-card">
					<div class="myls-card-header">
						<h2 class="myls-card-title"><i class="bi bi-clock-history"></i> Scheduling &amp; Behavior</h2>
					</div>
					<div class="row g-3">
						<div class="col-md-6">
							<div class="form-check form-switch mb-2">
								<input class="form-check-input" type="checkbox" id="myls_ytvb_auto_refresh" name="myls_ytvb_auto_refresh" value="1" <?php checked('1', $auto_refresh); ?>>
								<label class="form-check-label" for="myls_ytvb_auto_refresh"><strong>Auto-refresh every 12 hours</strong></label>
							</div>
							<div class="form-text">When enabled, new videos are fetched and posts created automatically via WP-Cron.</div>
							<?php if ( $auto_refresh === '1' && $next_run ) : ?>
								<div class="myls-status myls-status-info mt-2">
									<i class="bi bi-calendar-check"></i>
									Next run: <strong><?php echo esc_html( get_date_from_gmt( gmdate('Y-m-d H:i:s', $next_run), 'M j, Y g:i A' ) ); ?></strong>
								</div>
							<?php endif; ?>
						</div>
						<div class="col-md-6">
							<div class="form-check form-switch mb-2">
								<input class="form-check-input" type="checkbox" id="myls_ytvb_overwrite" name="myls_ytvb_overwrite" value="1" <?php checked('1', $overwrite); ?>>
								<label class="form-check-label" for="myls_ytvb_overwrite"><strong>Overwrite existing posts</strong></label>
							</div>
							<div class="form-text">When OFF (default), existing posts are skipped. When ON, posts matched by video ID are updated with the latest title and content.</div>
						</div>
						<div class="col-md-6">
							<div class="form-check form-switch mb-2">
								<input class="form-check-input" type="checkbox" id="myls_ytvb_notify_email_enabled" name="myls_ytvb_notify_email_enabled" value="1" <?php checked('1', $notify_enabled); ?>>
								<label class="form-check-label" for="myls_ytvb_notify_email_enabled"><strong>Email notification</strong></label>
							</div>
							<div class="form-text">Send a summary email after generation completes (manual or cron).</div>
							<div id="myls-ytvb-email-field" class="mt-2" style="<?php echo $notify_enabled === '1' ? '' : 'display:none;'; ?>">
								<input type="email" class="form-control" id="myls_ytvb_notify_email" name="myls_ytvb_notify_email" value="<?php echo esc_attr($notify_email); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
								<div class="form-text">Leave blank to use the admin email.</div>
							</div>
						</div>
					</div>
					<?php if ( $last_run ) : ?>
						<hr class="myls-divider">
						<div class="d-flex flex-wrap gap-2 align-items-center">
							<span class="myls-badge myls-badge-primary">
								<i class="bi bi-clock me-1"></i> Last run: <?php echo esc_html($last_run); ?>
							</span>
							<?php if ( is_array($last_result) ) : ?>
								<span class="myls-badge myls-badge-success">New: <?php echo (int)($last_result['new_posts'] ?? 0); ?></span>
								<?php if ( ($last_result['updated_posts'] ?? 0) > 0 ) : ?>
									<span class="myls-badge myls-badge-warning">Updated: <?php echo (int)$last_result['updated_posts']; ?></span>
								<?php endif; ?>
								<span class="myls-badge">Skipped: <?php echo (int)($last_result['existing_posts'] ?? 0); ?></span>
								<?php if ( ! empty($last_result['errors']) ) : ?>
									<span class="myls-badge" style="background:rgba(220,53,69,.1);color:#dc3545;">Errors: <?php echo count($last_result['errors']); ?></span>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>

				<div class="myls-actions">
					<button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Save Settings</button>
				</div>
			</form>

			<hr class="myls-divider">

			<!-- Card 4: Run Now & Debug -->
			<div class="myls-card">
				<div class="myls-card-header">
					<h2 class="myls-card-title"><i class="bi bi-play-circle"></i> Run Now &amp; Debug</h2>
				</div>
				<p class="myls-text-muted">
					API Key: <code><?php echo $yt_api_key ? '&hellip;'.esc_html(substr($yt_api_key, -6)) : 'not set'; ?></code>
					&nbsp;|&nbsp;
					Channel: <code><?php echo $yt_channel ? esc_html($yt_channel) : 'not set'; ?></code>
				</p>

				<div class="myls-actions">
					<button type="button" class="btn btn-primary" id="myls-ytvb-run" data-nonce="<?php echo esc_attr($ajax_nonce); ?>">
						<i class="bi bi-lightning-charge me-1"></i> Generate Drafts
					</button>
					<div class="form-check form-switch d-flex align-items-center gap-2 ms-2">
						<input class="form-check-input" type="checkbox" id="myls-ytvb-debug-toggle">
						<label class="form-check-label" for="myls-ytvb-debug-toggle">Debug log</label>
					</div>
					<button type="button" class="btn btn-outline-secondary btn-sm" id="myls-ytvb-log-refresh">
						<i class="bi bi-arrow-clockwise"></i> Refresh
					</button>
					<button type="button" class="btn btn-outline-secondary btn-sm" id="myls-ytvb-log-clear">
						<i class="bi bi-trash"></i> Clear
					</button>
					<select id="myls-ytvb-pages" class="form-select" style="width:auto;">
						<option value="0" selected>All pages</option>
						<option value="1">1 page (50 videos)</option>
						<option value="2">2 pages (100 videos)</option>
						<option value="3">3 pages (150 videos)</option>
					</select>
				</div>

				<div id="myls-ytvb-run-result" class="myls-status" style="display:none;margin-top:10px;"></div>

				<div class="myls-results-header">
					<strong>Debug Log</strong>
					<button type="button" class="myls-btn-export-pdf" data-log-target="myls-ytvb-log"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
				</div>
				<pre id="myls-ytvb-log" class="myls-results-terminal">Ready.</pre>
			</div>

			<!-- Card 5: Thumbnail Backfill -->
			<div class="myls-card">
				<div class="myls-card-header">
					<h2 class="myls-card-title"><i class="bi bi-image"></i> Thumbnail Backfill</h2>
				</div>
				<p class="myls-text-muted">Scan existing video posts and populate <code>_myls_video_thumb_url</code> for any that are missing a stored thumbnail URL.</p>
				<div class="myls-actions">
					<button type="button" class="btn btn-outline-primary" id="myls-ytvb-backfill-thumbs" data-nonce="<?php echo esc_attr($ajax_nonce); ?>">
						<i class="bi bi-arrow-repeat me-1"></i> Backfill Thumbnails
					</button>
				</div>
				<div id="myls-ytvb-backfill-result" class="myls-status" style="display:none;margin-top:10px;"></div>
			</div>

			<!-- Token Preview -->
			<details class="myls-card" style="cursor:pointer;">
				<summary style="font-weight:600;display:flex;align-items:center;gap:.5rem;">
					<i class="bi bi-eye"></i> Token Preview (example)
				</summary>
				<?php
				$example_raw_title = '🎥 Patio paver sealer!  Patio sealing in Lithia, Fl.  #paversealing #lithia #resandingpavers';
				$example_clean     = myls_ytvb_clean_title($example_raw_title);
				$example = array(
					'title'       => $example_clean,
					'description' => 'Step-by-step tips for local SEO using GBP.',
					'channel'     => 'Your Channel Name',
					'date'        => date_i18n( get_option('date_format') ),
					'url'         => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
					'slug'        => sanitize_title( $example_clean ),
					'embed'       => '<figure class="wp-block-embed"><div class="wp-block-embed__wrapper">https://www.youtube.com/watch?v=dQw4w9WgXcQ</div></figure>',
					'transcript'  => '<em>[Transcript accordion renders here if available]</em>',
				);
				$render = function( $tpl ) use ( $example ) {
					foreach ($example as $k=>$v) { $tpl = str_replace('{'.$k.'}', $v, $tpl); }
					return $tpl;
				};
				?>
				<div style="margin-top:1rem;">
					<p class="mb-2"><strong>Raw Title (sample):</strong> <?php echo esc_html( $example_raw_title ); ?></p>
					<p class="mb-2"><strong>Cleaned Title (<?php echo (int) get_option('myls_ytvb_title_max_words', 5); ?> words max):</strong> <?php echo esc_html( $example_clean ); ?></p>
					<p class="mb-2"><strong>Resolved Title:</strong> <?php echo wp_kses_post( $render( get_option('myls_ytvb_title_tpl', '{title}') ) ); ?></p>
					<div><strong>Resolved Content:</strong><br>
<?php
$__tpl_default = "<p>{description}</p>\n{embed}\n<p>Source: {channel}</p>";
$__tpl         = get_option('myls_ytvb_content_tpl', $__tpl_default);
$__rendered    = $render($__tpl);
$__autop       = wpautop($__rendered);
echo wp_kses_post($__autop);
?>
					</div>
				</div>
			</details>
		</div>
<?php
		// === JS CONFIG ===
		$js_cfg = array(
			'ajaxurl'      => admin_url( 'admin-ajax.php' ),
			'debugEnabled' => (bool) get_option('myls_youtube_debug', false),
		);
		echo '<script type="text/javascript">window.MYLS_CFG = '.wp_json_encode($js_cfg).";</script>\n";
?>
<script type="text/javascript">
jQuery(function($){
	const ajaxurl = (window.MYLS_CFG && window.MYLS_CFG.ajaxurl) ? window.MYLS_CFG.ajaxurl : (window.ajaxurl || '');
	const nonce   = $('#myls-ytvb-run').data('nonce');

	function paint($el, ok, msg) {
		$el.show()
		   .removeClass('myls-status-success myls-status-warning')
		   .addClass(ok ? 'myls-status-success' : 'myls-status-warning')
		   .html(msg);
	}

	// Debug toggle — reflect saved state
	(function(){
		$('#myls-ytvb-debug-toggle').prop('checked', !!(window.MYLS_CFG && window.MYLS_CFG.debugEnabled));
	})();

	$('#myls-ytvb-debug-toggle').on('change', function(){
		$.post(ajaxurl, { action:'myls_youtube_toggle_debug', enabled: $(this).is(':checked') ? 1 : 0, nonce: nonce });
	});

	// Email field toggle
	$('#myls_ytvb_notify_email_enabled').on('change', function(){
		$('#myls-ytvb-email-field').toggle($(this).is(':checked'));
	});

	// Generate Drafts — reads overwrite checkbox from the settings form
	$('#myls-ytvb-run').on('click', function(){
		const pages     = parseInt($('#myls-ytvb-pages').val() || '0', 10);
		const overwrite = $('#myls_ytvb_overwrite').is(':checked') ? 1 : 0;
		const $out      = $('#myls-ytvb-run-result');
		paint($out, true, '<em>Running&hellip;</em>');

		$.post(ajaxurl, { action:'myls_youtube_generate_drafts', pages: pages, overwrite: overwrite, nonce: nonce })
		 .done(function(r){
			if (!r) return paint($out, false, 'No response');
			if (!r.success) return paint($out, false, (r.data && r.data.message) ? r.data.message : 'Failed');
			const d = r.data || {};
			const parts = [
				'New: ' + (d.new_posts || 0),
				'Updated: ' + (d.updated_posts || 0),
				'Skipped: ' + (d.existing_posts || 0)
			];
			if (Array.isArray(d.errors) && d.errors.length) parts.push('Errors: ' + d.errors.length);
			paint($out, true, parts.join(' &bull; '));
			$('#myls-ytvb-log-refresh').trigger('click');
		 })
		 .fail(function(){ paint($out, false, 'Network error'); });
	});

	$('#myls-ytvb-log-refresh').on('click', function(){
		$.post(ajaxurl, { action:'myls_youtube_get_log', nonce: nonce })
		 .done(function(r){
			const $pre = $('#myls-ytvb-log').empty();
			if (r && r.success && r.data && Array.isArray(r.data.log)) {
				$pre.text(r.data.log.join("\n"));
			} else {
				$pre.text('(no log)');
			}
		 });
	}).trigger('click');

	$('#myls-ytvb-log-clear').on('click', function(){
		$.post(ajaxurl, { action:'myls_youtube_clear_log', nonce: nonce })
		 .done(function(){ $('#myls-ytvb-log').text('(cleared)'); });
	});

	// Thumbnail Backfill
	$('#myls-ytvb-backfill-thumbs').on('click', function(){
		const $out = $('#myls-ytvb-backfill-result');
		const bfNonce = $(this).data('nonce');
		paint($out, true, '<em>Scanning video posts&hellip;</em>');
		$.post(ajaxurl, { action:'myls_youtube_backfill_thumbs', nonce: bfNonce })
		 .done(function(r){
			if (!r || !r.success) return paint($out, false, (r && r.data && r.data.message) ? r.data.message : 'Failed');
			const d = r.data || {};
			paint($out, true, 'Updated: ' + (d.updated || 0) + ' &bull; Already set: ' + (d.skipped || 0) + ' &bull; No video ID: ' + (d.no_id || 0));
		 })
		 .fail(function(){ paint($out, false, 'Network error'); });
	});
});
</script>
<?php
	}, // end cb
)); // end register tab
