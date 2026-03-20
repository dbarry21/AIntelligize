<?php
/**
 * Utilities Subtab: Migration
 * File: admin/tabs/utilities/subtab-migration.php
 *
 * Proper subtab format (returns config array).
 * Does NOT echo during include.
 */

if ( ! defined('ABSPATH') ) exit;

return [
	'id'    => 'migration',
	'label' => 'Migration',
	'order' => 50,
	'render'=> function () {
		?>

		<div style="border:1px solid #000;padding:20px;border-radius:12px;">
			<h2 style="margin-top:0;">Migration Tools</h2>

			<p>
				Use these tools to migrate legacy data (ACF FAQ fields, city/state fields, etc.)
				into native <strong>AIntelligize</strong> storage.
			</p>

			<hr/>

			<h3>ACF → MYLS FAQs</h3>
			<p>
				Migrates legacy <code>faq_items</code> ACF repeater fields into
				<code>_myls_faq_items</code>.
			</p>

			<button type="button" class="button button-primary" id="myls_run_faq_migration">
				Run FAQ Migration
			</button>

			<hr/>

			<h3>City/State Migration</h3>
			<p>
				Migrates legacy city/state ACF fields into MYLS storage.
			</p>

			<button type="button" class="button" id="myls_run_city_migration">
				Run City/State Migration
			</button>

			<hr/>

			<h3>Page Video URL Migration</h3>
			<p>
				Migrates legacy ACF <code>video_url</code> field into
				<code>_myls_page_video_url</code> for use with <code>[myls_youtube_embed use_page_video="1"]</code>.
			</p>

			<button type="button" class="button" id="myls_run_page_video_migration">
				Run Page Video URL Migration
			</button>

			<hr/>

			<div class="myls-results-header">
				<h5 class="mb-0">Results</h5>
				<button type="button" class="myls-btn-export-pdf" data-log-target="myls_migration_log"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
			</div>
			<pre id="myls_migration_log" class="myls-results-terminal">Ready.</pre>
		</div>

		<script>
		document.addEventListener('DOMContentLoaded', function(){

			function log(msg){
				const el = document.getElementById('myls_migration_log');
				el.textContent += msg + "\n";
			}

			document.getElementById('myls_run_faq_migration')?.addEventListener('click', function(){
				log("Starting FAQ migration...");
				// Hook your AJAX call here if needed
			});

			document.getElementById('myls_run_city_migration')?.addEventListener('click', function(){
				log("Starting City/State migration...");
			});

		});
		</script>

		<?php
	}
];
