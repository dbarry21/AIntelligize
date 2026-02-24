<?php
/**
 * AIntelligize – Admin Docs Hub
 * File: admin/docs/documentation.php
 *
 * Tabs:
 *  - Overview (markdown)
 *  - Tabs & Subtabs (markdown)
 *  - Shortcodes (markdown)
 *  - Tutorials (markdown)
 *  - API Reference (auto-generated from PHPDoc blocks)
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * VERY small markdown-to-html helper for our internal docs.
 * We keep it intentionally limited for safety and simplicity.
 */
if ( ! function_exists('mlseo_docs_md_to_html') ) {
	function mlseo_docs_md_to_html( string $md ) : string {
		$md = str_replace(["\r\n", "\r"], "\n", $md);

		$lines = explode("\n", $md);
		$out = [];
		$in_ul = false;

		foreach ( $lines as $line ) {
			$raw = rtrim($line);

			// Headings
			if ( preg_match('/^(#{1,6})\s+(.*)$/', $raw, $m) ) {
				if ( $in_ul ) { $out[] = '</ul>'; $in_ul = false; }
				$level = strlen($m[1]);
				$text  = esc_html(trim($m[2]));
				$out[] = "<h{$level}>{$text}</h{$level}>";
				continue;
			}

			// List items
			if ( preg_match('/^\s*[-*]\s+(.*)$/', $raw, $m) ) {
				if ( ! $in_ul ) { $out[] = '<ul>'; $in_ul = true; }
				$out[] = '<li>' . esc_html(trim($m[1])) . '</li>';
				continue;
			}

			// Empty line
			if ( trim($raw) === '' ) {
				if ( $in_ul ) { $out[] = '</ul>'; $in_ul = false; }
				continue;
			}

			// Inline code `...`
			$text = esc_html($raw);
			$text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);

			$out[] = '<p>' . $text . '</p>';
		}

		if ( $in_ul ) $out[] = '</ul>';

		$html = implode("\n", $out);

		// Allow only safe HTML.
		return wp_kses_post($html);
	}
}

function mlseo_render_full_docs_page() {

	$active_tab = isset($_GET['dtab']) ? sanitize_text_field($_GET['dtab']) : 'overview';

	$tabs = [
		'quickstart' => '🚀 Quick Start',
		'overview'   => 'Overview',
		'tabs'       => 'Tabs & Subtabs',
		'shortcodes' => 'Shortcodes',
		'sc_interactive' => 'Shortcodes (Interactive)',
		'tutorials'  => 'Tutorials',
		'release'    => 'Release Notes',
		'autodocs'   => 'API Reference',
	];

	?>
	<div class="wrap">
		<h1>AIntelligize Documentation</h1>

		<h2 class="nav-tab-wrapper">
			<?php foreach ( $tabs as $slug => $label ): ?>
				<a href="<?php echo esc_url( admin_url('admin.php?page=mlseo-docs&dtab=' . $slug) ); ?>"
				   class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html($label); ?>
				</a>
			<?php endforeach; ?>
		</h2>

		<div style="background:#fff;padding:1em;border:1px solid #ccd0d4;border-radius:10px;margin-top:1em;">

			<?php
			// Quick Start Guide
			if ( $active_tab === 'quickstart' ) {
				include plugin_dir_path(__FILE__) . 'quick-start.php';
				echo '</div></div>';
				return;
			}

			// Auto-generated API reference (Phase 2)
			if ( $active_tab === 'autodocs' ) {
				include plugin_dir_path(__FILE__) . 'autodocs.php';
				echo '</div></div>';
				return;
			}

			// Interactive shortcodes documentation
			if ( $active_tab === 'sc_interactive' ) {
				include plugin_dir_path(__FILE__) . 'shortcode-data.php';
				include plugin_dir_path(__FILE__) . 'shortcodes-interactive.php';
				echo '</div></div>';
				return;
			}

			// Release notes (reads CHANGELOG.md and shows optional append UI)
			if ( $active_tab === 'release' ) {
				include plugin_dir_path(__FILE__) . 'release-notes.php';
				echo '</div></div>';
				return;
			}

			// Markdown docs (Phase 1)
			$doc_map = [
				'overview'   => 'index.md',
				'tabs'       => 'tabs.md',
				'shortcodes' => 'shortcodes.md',
				'tutorials'  => 'tutorials.md',
			];

			$md_file = $doc_map[$active_tab] ?? $doc_map['overview'];

			// Your repo already has these under /plugin-docs/
			$doc_path = plugin_dir_path(dirname(__FILE__, 2)) . 'plugin-docs/' . $md_file;

			if ( file_exists($doc_path) ) {
				$content = file_get_contents($doc_path);
				echo mlseo_docs_md_to_html( (string) $content );
			} else {
				echo '<p><strong>Documentation file not found:</strong> ' . esc_html($md_file) . '</p>';
				echo '<p><code>' . esc_html($doc_path) . '</code></p>';
			}
			?>

		</div>
	</div>
	<?php
}

mlseo_render_full_docs_page();
