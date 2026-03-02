<?php if ( ! defined('ABSPATH') ) exit;

return [
	'id'    => 'meta',
	'label' => 'Meta Titles & Descriptions',
	'icon'  => 'bi-tag',
	'order' => 10,
	'render'=> function () {

		$pts = get_post_types( ['public' => true], 'objects' );
		unset($pts['attachment']);
		$default_pt = isset($pts['page']) ? 'page' : ( $pts ? array_key_first($pts) : 'page' );

		// ---------- FACTORY DEFAULT TEMPLATES (loaded from assets/prompts/) ----------
		$default_title_prompt = myls_get_default_prompt('meta-title');
		$default_desc_prompt  = myls_get_default_prompt('meta-description');

		// ---------- LOAD SAVED (PERSISTENT) VALUES ----------
		$saved_title_prompt = get_option('myls_ai_prompt_title', $default_title_prompt);
		$saved_desc_prompt  = get_option('myls_ai_prompt_desc',  $default_desc_prompt);

		// Preload initial posts (fallback for first paint)
		$initial_posts = get_posts([
			'post_type'       => $default_pt,
			'post_status'     => ['publish','draft','pending','future','private'],
			'posts_per_page'  => 300,
			'orderby'         => 'title',
			'order'           => 'ASC',
			'fields'          => 'ids',
			'suppress_filters'=> true,
		]);

		$nonce = wp_create_nonce('myls_ai_ops');
		?>
		<div class="myls-two-col" style="display:grid;grid-template-columns:1fr 2fr;gap:20px;">

			<!-- Left: Target selection -->
			<div class="myls-left" style="border:1px solid #000;padding:16px;border-radius:1em;">
				<h4 class="mb-3">Select Posts</h4>

				<label class="form-label">Post Type</label>
				<select id="myls_ai_pt" class="form-select">
					<?php foreach ($pts as $pt => $o): ?>
						<option value="<?php echo esc_attr($pt); ?>" <?php selected($pt, $default_pt); ?>>
							<?php echo esc_html($o->labels->singular_name); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label class="form-label mt-3">Search</label>
				<input type="text" id="myls_ai_filter" class="form-control" placeholder="Filter posts...">

				<label class="form-label mt-3">Posts (multi-select)</label>
				<select id="myls_ai_posts" class="form-select" multiple size="12" aria-label="Select multiple posts">
					<?php
						foreach ($initial_posts as $pid) {
							$title = get_the_title($pid) ?: '(no title)';
							printf('<option value="%d">%s</option>', (int)$pid, esc_html($title));
						}
					?>
				</select>

				<div class="d-flex gap-2 mt-2">
					<button type="button" class="button" id="myls_ai_select_all">Select All</button>
					<button type="button" class="button" id="myls_ai_clear">Clear</button>
				</div>

				<hr class="my-3">

				<div class="form-check">
					<input class="form-check-input" type="checkbox" id="myls_ai_overwrite" checked>
					<label class="form-check-label" for="myls_ai_overwrite">Overwrite existing Yoast fields</label>
				</div>
				<div class="form-check mt-2">
					<input class="form-check-input" type="checkbox" id="myls_ai_dryrun">
					<label class="form-check-label" for="myls_ai_dryrun">Dry-run (preview only, don’t save)</label>
				</div>

				<input type="hidden" id="myls_ai_nonce" value="<?php echo esc_attr($nonce); ?>">
			</div>

			<!-- Right: Prompts + Actions -->
			<div class="myls-right" style="border:1px solid #000;padding:16px;border-radius:1em;">
				<h4 class="mb-2">AI Actions</h4>
				<p class="mb-3" style="color:#555;">
					Prompt placeholders: <code>{post_title}</code>, <code>{site_name}</code>, <code>{excerpt}</code>, <code>{primary_category}</code>, <code>{permalink}</code>, <code>{credentials}</code>.
					<?php
					if ( function_exists('myls_build_tagline_credentials') ) {
					    $cred = myls_build_tagline_credentials();
					    if ( $cred !== '' ) {
					        echo '<br/><strong>{credentials}:</strong> <code>' . esc_html($cred) . '</code>';
					    } else {
					        echo '<br/><strong>{credentials} is empty</strong> — add awards, certs, or memberships in <a href="admin.php?page=aintelligize&tab=schema">Schema → Organization</a>.';
					    }
					}
					?>
				</p>

				<div class="card mb-3" style="border:1px solid #ddd;">
					<div class="card-body">
						<div class="d-flex justify-content-between align-items-center mb-2" style="gap:8px;">
							<strong>Title Prompt Template</strong>
							<div>
								<button type="button" class="button button-primary" id="myls_ai_save_title_prompt">Save to DB</button>
							</div>
						</div>
						<?php myls_prompt_toolbar('meta-title', 'myls_ai_title_prompt'); ?>
						<textarea id="myls_ai_title_prompt" class="form-control" rows="8"><?php echo esc_textarea($saved_title_prompt); ?></textarea>
						<small style="color:#666;">Saved to: <code>myls_ai_prompt_title</code></small>
					</div>
				</div>

				<div class="card mb-3" style="border:1px solid #ddd;">
					<div class="card-body">
						<div class="d-flex justify-content-between align-items-center mb-2" style="gap:8px;">
							<strong>Description Prompt Template</strong>
							<div>
								<button type="button" class="button button-primary" id="myls_ai_save_desc_prompt">Save to DB</button>
							</div>
						</div>
						<?php myls_prompt_toolbar('meta-description', 'myls_ai_desc_prompt'); ?>
						<textarea id="myls_ai_desc_prompt" class="form-control" rows="8"><?php echo esc_textarea($saved_desc_prompt); ?></textarea>
						<small style="color:#666;">Saved to: <code>myls_ai_prompt_desc</code></small>
					</div>
				</div>

				<div class="d-flex flex-wrap gap-2 mb-2">
					<button class="button button-primary" id="myls_ai_gen_title">Generate SEO Titles for Selected</button>
					<button class="button" id="myls_ai_gen_desc">Generate Meta Descriptions for Selected</button>
					<button class="button button-primary" id="myls_ai_gen_both" style="background:#2e7d32;border-color:#2e7d32;">Generate Both for Selected</button>
					<button class="button" id="myls_ai_stop" style="background:#b32d2e;border-color:#b32d2e;color:#fff;display:none;">⏹ Stop</button>
				</div>
				<small style="color:#666;">Actions run on all selected posts. Respect “Dry-run” to preview without saving. <span id="myls_ai_progress" style="font-weight:600;"></span></small>

				<hr>

				<div class="myls-results-header">
					<h3 class="h5 mb-0"><i class="bi bi-terminal"></i> Results</h3>
					<button type="button" class="myls-btn-export-pdf" data-log-target="myls_ai_results"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
				</div>
				<pre id="myls_ai_results" class="myls-results-terminal">Ready.</pre>
			</div>
		</div>

		<!-- JSON bootstrap for initial posts list -->
		<script type="application/json" id="myls_ai_bootstrap_posts">
			<?php
				echo wp_json_encode([
					'post_type' => $default_pt,
					'posts'     => array_map(function($pid){
						return ['id' => (int)$pid, 'title' => get_the_title($pid) ?: '(no title)'];
					}, $initial_posts),
				]);
			?>
		</script>
		<?php
	}
];
