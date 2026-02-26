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

		$out      = [];
		$in_ul    = false;
		$in_fence = false;
		$fence_buf = [];
		$in_table  = false;
		$table_buf = [];

		/**
		 * Flush an open <ul> if one is pending.
		 */
		$flush_ul = function() use (&$out, &$in_ul) {
			if ($in_ul) { $out[] = '</ul>'; $in_ul = false; }
		};

		/**
		 * Flush a collected markdown table into an HTML <table>.
		 */
		$flush_table = function() use (&$out, &$in_table, &$table_buf) {
			if (!$in_table || empty($table_buf)) { $in_table = false; $table_buf = []; return; }
			$html = '<table style="border-collapse:collapse;width:100%;margin:1em 0;">';
			foreach ($table_buf as $i => $raw_row) {
				// separator row (|---|---|) — skip
				if (preg_match('/^\s*\|?[\s\-|:]+\|?\s*$/', $raw_row)) continue;
				$cells = array_map('trim', explode('|', trim($raw_row, " \t|")));
				$tag   = $i === 0 ? 'th' : 'td';
				$html .= '<tr>';
				foreach ($cells as $cell) {
					$cell  = esc_html($cell);
					$cell  = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $cell);
					$cell  = preg_replace('/`([^`]+)`/', '<code>$1</code>', $cell);
					$style = $i === 0
						? 'background:#f6f7f7;font-weight:600;border:1px solid #cfcfcf;padding:6px 10px;text-align:left;'
						: 'border:1px solid #e2e4e7;padding:6px 10px;vertical-align:top;';
					$html .= "<{$tag} style=\"{$style}\">{$cell}</{$tag}>";
				}
				$html .= '</tr>';
			}
			$html .= '</table>';
			$out[]     = $html;
			$in_table  = false;
			$table_buf = [];
		};

		/**
		 * Apply inline formatting to a plain text paragraph line.
		 * Operates on already-esc_html'd content to keep it safe.
		 */
		$inline = function(string $raw) : string {
			$t = esc_html($raw);
			// Bold
			$t = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t);
			// Italic
			$t = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $t);
			// Inline code
			$t = preg_replace('/`([^`]+)`/', '<code>$1</code>', $t);
			return $t;
		};

		foreach ($lines as $line) {
			$raw = rtrim($line);

			// ── Fenced code block ──────────────────────────────────────
			if (preg_match('/^```/', $raw)) {
				if (!$in_fence) {
					($flush_ul)(); ($flush_table)();
					$in_fence  = true;
					$fence_buf = [];
				} else {
					$code = '<pre style="background:#f4f5f6;border:1px solid #ddd;padding:10px 14px;border-radius:6px;overflow-x:auto;"><code>'
						. esc_html(implode("\n", $fence_buf))
						. '</code></pre>';
					$out[]    = $code;
					$in_fence = false;
				}
				continue;
			}
			if ($in_fence) { $fence_buf[] = $raw; continue; }

			// ── Table row ──────────────────────────────────────────────
			if (strpos($raw, '|') !== false && preg_match('/^\s*\|/', $raw)) {
				($flush_ul)();
				$in_table    = true;
				$table_buf[] = $raw;
				continue;
			} elseif ($in_table) {
				($flush_table)();
			}

			// ── Blockquote ─────────────────────────────────────────────
			if (preg_match('/^>\s*(.*)$/', $raw, $m)) {
				($flush_ul)();
				$out[] = '<blockquote style="border-left:4px solid #2271b1;margin:0.8em 0;padding:6px 14px;background:#f0f4fb;border-radius:4px;">'
					. ($inline)(trim($m[1]))
					. '</blockquote>';
				continue;
			}

			// ── Headings ───────────────────────────────────────────────
			if (preg_match('/^(#{1,6})\s+(.*)$/', $raw, $m)) {
				($flush_ul)();
				$level = strlen($m[1]);
				$text  = esc_html(trim($m[2]));
				$mt    = $level <= 2 ? 'margin-top:1.4em;' : 'margin-top:1em;';
				$out[] = "<h{$level} style=\"{$mt}\">{$text}</h{$level}>";
				continue;
			}

			// ── Horizontal rule ────────────────────────────────────────
			if (preg_match('/^---+$/', trim($raw))) {
				($flush_ul)();
				$out[] = '<hr style="border:none;border-top:1px solid #ddd;margin:1.2em 0;">';
				continue;
			}

			// ── List item ──────────────────────────────────────────────
			if (preg_match('/^\s*[-*]\s+(.*)$/', $raw, $m)) {
				if (!$in_ul) { $out[] = '<ul>'; $in_ul = true; }
				$out[] = '<li>' . ($inline)(trim($m[1])) . '</li>';
				continue;
			}

			// ── Empty line ─────────────────────────────────────────────
			if (trim($raw) === '') {
				($flush_ul)();
				continue;
			}

			// ── Paragraph ──────────────────────────────────────────────
			($flush_ul)();
			$out[] = '<p>' . ($inline)($raw) . '</p>';
		}

		// Close any open blocks at EOF
		($flush_ul)();
		($flush_table)();
		if ($in_fence && !empty($fence_buf)) {
			$out[] = '<pre style="background:#f4f5f6;border:1px solid #ddd;padding:10px 14px;border-radius:6px;"><code>'
				. esc_html(implode("\n", $fence_buf))
				. '</code></pre>';
		}

		return wp_kses_post(implode("\n", $out));
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
