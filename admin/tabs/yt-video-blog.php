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
			update_option('myls_ytvb_enabled',     isset($_POST['myls_ytvb_enabled']) ? '1' : '0');

			$status = isset($_POST['myls_ytvb_status']) ? sanitize_key( wp_unslash($_POST['myls_ytvb_status']) ) : 'draft';
			if ( ! in_array( $status, array('draft','pending','publish'), true ) ) $status = 'draft';
			update_option('myls_ytvb_status', $status);

			update_option('myls_ytvb_category',  isset($_POST['myls_ytvb_category']) ? absint($_POST['myls_ytvb_category']) : 0);
			update_option('myls_ytvb_autoembed', isset($_POST['myls_ytvb_autoembed']) ? '1' : '0');

			// IMPORTANT: Default title template now uses cleaned {title} and drops emoji prefix
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

			// Post type selector
			$post_type = isset($_POST['myls_ytvb_post_type']) ? sanitize_key($_POST['myls_ytvb_post_type']) : 'post';
			update_option('myls_ytvb_post_type', post_type_exists($post_type) ? $post_type : 'post');

			// Title cleaner options
			update_option('myls_ytvb_strip_hashtags', isset($_POST['myls_ytvb_strip_hashtags']) ? '1' : '0');
			update_option('myls_ytvb_strip_emojis',   isset($_POST['myls_ytvb_strip_emojis'])   ? '1' : '0');
			$max_words = isset($_POST['myls_ytvb_title_max_words']) ? (int) $_POST['myls_ytvb_title_max_words'] : 5;
			if ($max_words < 3)  $max_words = 3;
			if ($max_words > 12) $max_words = 12;
			update_option('myls_ytvb_title_max_words', $max_words);

			echo '<div class="notice notice-success is-dismissible"><p>YT Video Blog settings saved.</p></div>';
		}

		/* ===== Load settings ===== */
		$enabled     = get_option('myls_ytvb_enabled', '0');
		$status      = get_option('myls_ytvb_status', 'draft');
		$cat_id      = (int) get_option('myls_ytvb_category', 0);
		$auto_embed  = get_option('myls_ytvb_autoembed', '1');
		// Default title template now without emoji prefix
		$title_tpl   = get_option('myls_ytvb_title_tpl', '{title}');
		$content_tpl = get_option('myls_ytvb_content_tpl', "<p>{description}</p>\n{embed}\n<p>Source: {channel}</p>");
		$slug_prefix = get_option('myls_ytvb_slug_prefix', 'video');
		$post_type   = get_option('myls_ytvb_post_type', 'post');

		// Cleaner settings
		$strip_hash  = get_option('myls_ytvb_strip_hashtags', '1');
		$strip_emo   = get_option('myls_ytvb_strip_emojis', '1');
		$max_words   = (int) get_option('myls_ytvb_title_max_words', 5);

		// From API Integration tab options
		$yt_api_key  = get_option('myls_youtube_api_key','');
		$yt_channel  = get_option('myls_youtube_channel_id','');

		$categories = get_categories(array('hide_empty'=>false,'taxonomy'=>'category'));
		$ajax_nonce = wp_create_nonce('myls_ytvb_ajax');

		$token_help = '<code>{title}</code> <code>{description}</code> <code>{channel}</code> <code>{date}</code> <code>{embed}</code> <code>{url}</code> <code>{slug}</code>';
		?>
		<div class="wrap myls-ytvb">
			<h1 class="wp-heading-inline">YT Video Blog</h1>
			<p class="description">Use your YouTube channel to create posts with a template. API key &amp; Channel ID come from <em>API Integration</em>.</p>

			<?php if ( empty($yt_api_key) || empty($yt_channel) ) : ?>
				<div class="notice notice-warning"><p><strong>Missing API settings:</strong> Set your YouTube API Key &amp; Channel ID in the <em>API Integration</em> tab.</p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field('myls_ytvb_save', 'myls_ytvb_nonce'); ?>

				<div class="card" style="padding:16px; max-width:1200px;">
					<h2 class="title">General</h2>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><label for="myls_ytvb_enabled">Enable</label></th>
								<td><label><input type="checkbox" id="myls_ytvb_enabled" name="myls_ytvb_enabled" value="1" <?php checked('1', $enabled); ?>> Activate YT → Blog</label></td>
							</tr>
							<tr>
								<th scope="row"><label for="myls_ytvb_status">Default Post Status</label></th>
								<td>
									<select id="myls_ytvb_status" name="myls_ytvb_status">
										<option value="draft"   <?php selected($status,'draft'); ?>>Draft</option>
										<option value="pending" <?php selected($status,'pending'); ?>>Pending Review</option>
										<option value="publish" <?php selected($status,'publish'); ?>>Publish</option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="myls_ytvb_post_type">Post Type</label></th>
								<td>
									<select id="myls_ytvb_post_type" name="myls_ytvb_post_type">
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
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="myls_ytvb_category">Default Category</label></th>
								<td>
									<select id="myls_ytvb_category" name="myls_ytvb_category">
										<option value="0" <?php selected($cat_id, 0); ?>>— None —</option>
										<?php foreach ( $categories as $cat ) : ?>
											<option value="<?php echo (int) $cat->term_id; ?>" <?php selected($cat_id, (int)$cat->term_id); ?>>
												<?php echo esc_html( $cat->name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="myls_ytvb_autoembed">Auto-embed Video</label></th>
								<td><label><input type="checkbox" id="myls_ytvb_autoembed" name="myls_ytvb_autoembed" value="1" <?php checked('1', $auto_embed); ?>> Use oEmbed (recommended)</label></td>
							</tr>
							<tr>
								<th scope="row"><label for="myls_ytvb_slug_prefix">Slug Prefix</label></th>
								<td>
									<input type="text" class="regular-text" id="myls_ytvb_slug_prefix" name="myls_ytvb_slug_prefix" value="<?php echo esc_attr($slug_prefix); ?>" placeholder="video">
									<p class="description">Final slug becomes <code>{prefix}-{slug}</code>. Build {slug} from the <em>cleaned</em> title.</p>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<br>

				<div class="card" style="padding:16px; max-width:1200px;">
					<h2 class="title">Templates</h2>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><label for="myls_ytvb_title_tpl">Title Template</label></th>
								<td>
									<input type="text" class="regular-text" id="myls_ytvb_title_tpl" name="myls_ytvb_title_tpl" value="<?php echo esc_attr($title_tpl); ?>">
									<p class="description">Tokens: <?php echo $token_help; ?>. The <code>{title}</code> token uses the cleaned title.</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="myls_ytvb_content_tpl">Content Template</label></th>
								<td>
<textarea class="large-text code" id="myls_ytvb_content_tpl" name="myls_ytvb_content_tpl" rows="10"><?php echo esc_textarea($content_tpl); ?></textarea>
									<p class="description">Tokens: <?php echo $token_help; ?></p>
								</td>
							</tr>

							<!-- Title Cleaner Settings -->
							<tr>
								<th scope="row">Title Cleaner</th>
								<td>
									<label style="display:inline-flex;align-items:center;gap:.5rem;margin-right:14px;">
										<input type="checkbox" name="myls_ytvb_strip_hashtags" value="1" <?php checked('1',$strip_hash); ?>>
										Remove hashtags (e.g., <code>#pressurewashing</code>)
									</label>
									<label style="display:inline-flex;align-items:center;gap:.5rem;margin-right:14px;">
										<input type="checkbox" name="myls_ytvb_strip_emojis" value="1" <?php checked('1',$strip_emo); ?>>
										Remove emojis/symbols (🎥 etc.)
									</label>
									<label style="display:inline-flex;align-items:center;gap:.5rem;">
										Max words:
										<input type="number" min="3" max="12" name="myls_ytvb_title_max_words" value="<?php echo (int)$max_words; ?>" style="width:70px;">
									</label>
									<p class="description">We recommend 5–6 words for concise, clicky titles.</p>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<p><button type="submit" class="button button-primary">Save Settings</button></p>
			</form>

			<hr>

			<div class="card" style="padding:16px; max-width:1200px;">
				<h2 class="title">Run Now &amp; Debug</h2>
				<p class="description">API Key: <code><?php echo $yt_api_key ? '…'.esc_html(substr($yt_api_key, -6)) : 'not set'; ?></code> &nbsp; | &nbsp; Channel: <code><?php echo $yt_channel ? esc_html($yt_channel) : 'not set'; ?></code></p>

				<div class="actions" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
					<button type="button" class="button button-primary" id="myls-ytvb-run" data-nonce="<?php echo esc_attr($ajax_nonce); ?>">Generate Drafts</button>
					<label style="display:inline-flex; align-items:center; gap:.5rem; margin-left:8px;">
						<input type="checkbox" id="myls-ytvb-debug-toggle"> Enable debug log
					</label>
					<button type="button" class="button" id="myls-ytvb-log-refresh">Refresh Log</button>
					<button type="button" class="button button-secondary" id="myls-ytvb-log-clear">Clear Log</button>
					<select id="myls-ytvb-pages" class="regular-text" style="width:auto;">
						<option value="0" selected>All pages</option>
						<option value="1">1 page (50 videos)</option>
						<option value="2">2 pages (100 videos)</option>
						<option value="3">3 pages (150 videos)</option>
					</select>
				</div>

				<div id="myls-ytvb-run-result" class="notice inline" style="margin-top:10px;"></div>

				<details style="margin-top:14px;">
					<summary><strong>Debug Log</strong></summary>
					<div class="myls-results-header" style="margin-top:8px;">
						<strong>Results</strong>
						<button type="button" class="myls-btn-export-pdf" data-log-target="myls-ytvb-log"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
					</div>
					<pre id="myls-ytvb-log" class="myls-results-terminal">Ready.</pre>
				</details>
			</div>

			<details>
				<summary><strong>Token Preview (example)</strong></summary>
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
				);
				$render = function( $tpl ) use ( $example ) {
					foreach ($example as $k=>$v) { $tpl = str_replace('{'.$k.'}', $v, $tpl); }
					return $tpl;
				};
				?>
				<div class="card" style="padding:12px; max-width: 1000px;">
					<p><strong>Raw Title (sample):</strong> <?php echo esc_html( $example_raw_title ); ?></p>
					<p><strong>Cleaned Title (<?php echo (int) get_option('myls_ytvb_title_max_words', 5); ?> words max):</strong> <?php echo esc_html( $example_clean ); ?></p>
					<p><strong>Resolved Title using template:</strong> <?php echo wp_kses_post( $render( get_option('myls_ytvb_title_tpl', '{title}') ) ); ?></p>
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
		// === SAFELY EMIT JS CONFIG AS JSON (avoids quote/paren parse errors) ===
		$js_cfg = array(
			'ajaxurl'      => admin_url( 'admin-ajax.php' ),
			'debugEnabled' => (bool) get_option('myls_youtube_debug', false),
		);
		echo '<script type="text/javascript">window.MYLS_CFG = '.wp_json_encode($js_cfg).";</script>\n";
?>
<script type="text/javascript">
jQuery(function($){
	// Read server vars safely
	const ajaxurl = (window.MYLS_CFG && window.MYLS_CFG.ajaxurl) ? window.MYLS_CFG.ajaxurl : (window.ajaxurl || '');
	const nonce   = $('#myls-ytvb-run').data('nonce');

	function paint($el, ok, msg) {
		$el.removeClass('notice-success notice-error')
		   .addClass(ok ? 'notice-success' : 'notice-error')
		   .html('<p>' + $('<div>').text(msg || (ok ? 'OK' : 'Failed')).html() + '</p>');
	}

	// Toggle debug on load (reflect saved state)
	(function primeDebugToggle(){
		$('#myls-ytvb-debug-toggle').prop('checked', !!(window.MYLS_CFG && window.MYLS_CFG.debugEnabled));
		$.post(ajaxurl, { action:'myls_youtube_get_log', nonce: nonce })
		 .done(function(){ /* noop; checkbox already reflects saved option */ });
	})();

	$('#myls-ytvb-debug-toggle').on('change', function(){
		const checked = $(this).is(':checked') ? 1 : 0;
		$.post(ajaxurl, { action:'myls_youtube_toggle_debug', enabled: checked, nonce: nonce });
	});

	$('#myls-ytvb-run').on('click', function(){
		const pages = parseInt($('#myls-ytvb-pages').val() || '0', 10);
		const $out  = $('#myls-ytvb-run-result').removeClass('notice-success notice-error').html('<em>Running…</em>');
		$.post(ajaxurl, { action:'myls_youtube_generate_drafts', pages: pages, nonce: nonce })
		 .done(function(r){
			if (!r) return paint($out,false,'No response');
			if (!r.success) return paint($out,false, (r.data && r.data.message) ? r.data.message : 'Failed');
			const d = r.data || {};
			const parts = [
				'New posts: ' + (d.new_posts || 0),
				'Existing (skipped): ' + (d.existing_posts || 0)
			];
			if (Array.isArray(d.errors) && d.errors.length) parts.push('Errors: ' + d.errors.length);
			paint($out,true, parts.join(' • '));
			$('#myls-ytvb-log-refresh').trigger('click');
		 })
		 .fail(function(){ paint($out,false,'Network error'); });
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
});
</script>
<?php
	}, // end cb
)); // end register tab
