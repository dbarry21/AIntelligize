<?php
/**
 * AIntelligize – Docs Auto-Generator (Phase 2)
 * File: admin/docs/lib/parser.php
 *
 * What it does:
 * - Scans the plugin for PHP docblocks (file headers, classes, functions)
 * - Builds a structured index for display in Admin → Documentation → API Reference
 * - Uses lightweight caching via a transient keyed by file mtimes
 *
 * How to document code so it shows nicely:
 * - Put a file header docblock at the top of the file
 * - Put PHPDoc blocks above functions/classes
 * - Optional tags supported:
 *    @myls-doc-title  Human-friendly title
 *    @myls-doc-group  Group name (e.g., "AJAX", "Schema", "Shortcodes")
 *    @myls-doc-tags   Comma list (e.g., "yoast, canonical, bulk")
 *    @since           Version or date
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('myls_docs_autogen_get_scan_roots') ) {

	/**
	 * Get folders to scan (relative to plugin root).
	 */
	function myls_docs_autogen_get_scan_roots() : array {
		return [
			'aintelligize.php',
			'inc',
			'modules',
			'admin/tabs',
			'admin/ajax',
			'modules/shortcodes',
		];
	}
}

if ( ! function_exists('myls_docs_autogen_build_index') ) {

	/**
	 * Build (or fetch cached) docs index.
	 *
	 * @param string $plugin_root Absolute plugin path.
	 * @return array
	 */
	function myls_docs_autogen_build_index( string $plugin_root ) : array {

		$plugin_root = rtrim($plugin_root, '/\\') . DIRECTORY_SEPARATOR;

		$roots = myls_docs_autogen_get_scan_roots();

		// Build a cache key from mtimes so docs update instantly when files change.
		$hash_input = '';
		$files = [];

		foreach ( $roots as $rel ) {
			$abs = $plugin_root . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);

			if ( is_file($abs) ) {
				$files[] = $abs;
				continue;
			}

			if ( is_dir($abs) ) {
				$iter = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS)
				);
				foreach ( $iter as $f ) {
					/** @var SplFileInfo $f */
					if ( strtolower($f->getExtension()) !== 'php' ) continue;
					$files[] = $f->getPathname();
				}
			}
		}

		$files = array_values(array_unique($files));
		sort($files);

		foreach ( $files as $f ) {
			$hash_input .= $f . '|' . @filemtime($f) . ';';
		}

		$key = 'myls_docs_autogen_v1_' . md5($hash_input);
		$cached = get_transient($key);
		if ( is_array($cached) ) return $cached;

		$index = [
			'generated_at' => gmdate('c'),
			'files'        => [],
			'groups'       => [],
		];

		foreach ( $files as $abs ) {
			$rel_path = str_replace($plugin_root, '', $abs);
			$rel_path = str_replace(DIRECTORY_SEPARATOR, '/', $rel_path);

			$src = @file_get_contents($abs);
			if ( ! is_string($src) || $src === '' ) continue;

			$file_doc = myls_docs_autogen_parse_file_header($src);
			$items    = myls_docs_autogen_parse_members($src);

			$group = $file_doc['group'] ?: myls_docs_autogen_guess_group($rel_path);

			$entry = [
				'path'        => $rel_path,
				'title'       => $file_doc['title'] ?: basename($rel_path),
				'summary'     => $file_doc['summary'],
				'group'       => $group,
				'tags'        => $file_doc['tags'],
				'since'       => $file_doc['since'],
				'items'       => $items,
			];

			$index['files'][] = $entry;

			if ( ! isset($index['groups'][$group]) ) $index['groups'][$group] = [];
			$index['groups'][$group][] = $rel_path;
		}

		// Simple sort: group name, then title.
		usort($index['files'], function($a, $b){
			$g = strcasecmp($a['group'], $b['group']);
			if ( $g !== 0 ) return $g;
			return strcasecmp($a['title'], $b['title']);
		});

		// Cache for 12 hours. Key changes whenever file mtimes change.
		set_transient($key, $index, 12 * HOUR_IN_SECONDS);

		return $index;
	}
}

if ( ! function_exists('myls_docs_autogen_guess_group') ) {

	function myls_docs_autogen_guess_group( string $rel_path ) : string {
		$p = strtolower($rel_path);

		if ( strpos($p, 'modules/shortcodes') !== false ) return 'Shortcodes';
		if ( strpos($p, 'inc/schema') !== false || strpos($p, 'schema') !== false ) return 'Schema';
		if ( strpos($p, 'admin/ajax') !== false || strpos($p, '/ajax') !== false ) return 'AJAX';
		if ( strpos($p, 'admin/tabs') !== false ) return 'Admin UI';
		if ( strpos($p, 'modules') !== false ) return 'Modules';
		if ( strpos($p, 'inc') !== false ) return 'Core';
		return 'Other';
	}
}

if ( ! function_exists('myls_docs_autogen_parse_file_header') ) {

	/**
	 * Parse the top-of-file docblock for display metadata.
	 */
	function myls_docs_autogen_parse_file_header( string $src ) : array {

		$out = [
			'title'   => '',
			'summary' => '',
			'group'   => '',
			'tags'    => [],
			'since'   => '',
		];

		// Find the first docblock near the top (before any class/function).
		if ( ! preg_match('#^\s*<\?php\s*/\*\*(.*?)\*/#s', $src, $m) ) {
			return $out;
		}

		$block = myls_docs_autogen_clean_docblock($m[1]);
		$lines = preg_split('/\R/', $block);

		// First non-empty line becomes summary if no explicit title.
		foreach ( $lines as $ln ) {
			$ln = trim($ln);
			if ( $ln === '' ) continue;

			// Ignore typical boilerplate
			if ( stripos($ln, 'file:') === 0 ) continue;
			if ( stripos($ln, 'plugin:') === 0 ) continue;

			$out['summary'] = $ln;
			break;
		}

		// Tags
		$out['title'] = myls_docs_autogen_tag_value($block, '@myls-doc-title');
		$out['group'] = myls_docs_autogen_tag_value($block, '@myls-doc-group');
		$out['since'] = myls_docs_autogen_tag_value($block, '@since');

		$tags = myls_docs_autogen_tag_value($block, '@myls-doc-tags');
		if ( $tags ) {
			$out['tags'] = array_values(array_filter(array_map('trim', explode(',', $tags))));
		}

		return $out;
	}
}

if ( ! function_exists('myls_docs_autogen_parse_members') ) {

	/**
	 * Parse function/class docblocks with signatures.
	 *
	 * Returns list of:
	 *  - kind: function|class
	 *  - name: identifier
	 *  - signature: formatted string
	 *  - summary: first line
	 *  - tags: associative tags (since, param, return, etc.)
	 */
	function myls_docs_autogen_parse_members( string $src ) : array {

		$items = [];

		// Capture /** docblock */ followed by function or class declaration.
		$pattern = '#/\*\*(.*?)\*/\s*(?:final\s+|abstract\s+)?(?:(class|interface|trait)\s+([a-zA-Z0-9_\\\\]+)|function\s+([a-zA-Z0-9_]+)\s*\(([^)]*)\))#s';

		if ( ! preg_match_all($pattern, $src, $m, PREG_SET_ORDER) ) {
			return $items;
		}

		foreach ( $m as $hit ) {
			$raw_block = $hit[1] ?? '';
			$block = myls_docs_autogen_clean_docblock($raw_block);

			$kind = '';
			$name = '';
			$sig  = '';

			if ( ! empty($hit[2]) ) {
				$kind = 'class';
				$name = $hit[3] ?? '';
				$sig  = trim(($hit[2] ?: 'class') . ' ' . $name);
			} else {
				$kind = 'function';
				$name = $hit[4] ?? '';
				$args = trim($hit[5] ?? '');
				$args = preg_replace('/\s+/', ' ', $args);
				$sig  = 'function ' . $name . '(' . $args . ')';
			}

			$summary = '';
			foreach ( preg_split('/\R/', $block) as $ln ) {
				$ln = trim($ln);
				if ( $ln === '' ) continue;
				if ( $ln[0] === '@' ) continue;
				$summary = $ln;
				break;
			}

			$tags = myls_docs_autogen_parse_tags($block);

			$items[] = [
				'kind'      => $kind,
				'name'      => $name,
				'signature' => $sig,
				'summary'   => $summary,
				'tags'      => $tags,
			];
		}

		return $items;
	}
}

if ( ! function_exists('myls_docs_autogen_parse_tags') ) {

	function myls_docs_autogen_parse_tags( string $block ) : array {
		$tags = [
			'since'  => '',
			'params' => [],
			'return' => '',
			'see'    => [],
		];

		$tags['since'] = myls_docs_autogen_tag_value($block, '@since');

		// @param type $name description...
		if ( preg_match_all('/@param\s+([^\s]+)\s+(\$[^\s]+)\s*(.*)/', $block, $pm, PREG_SET_ORDER) ) {
			foreach ( $pm as $p ) {
				$tags['params'][] = [
					'type' => trim($p[1]),
					'name' => trim($p[2]),
					'desc' => trim($p[3]),
				];
			}
		}

		// @return type description...
		if ( preg_match('/@return\s+([^\s]+)\s*(.*)/', $block, $rm) ) {
			$tags['return'] = trim($rm[1] . ' ' . ($rm[2] ?? ''));
		}

		// @see Something
		if ( preg_match_all('/@see\s+([^\s]+)\s*(.*)/', $block, $sm, PREG_SET_ORDER) ) {
			foreach ( $sm as $s ) {
				$tags['see'][] = trim($s[1] . ' ' . ($s[2] ?? ''));
			}
		}

		return $tags;
	}
}

if ( ! function_exists('myls_docs_autogen_tag_value') ) {

	function myls_docs_autogen_tag_value( string $block, string $tag ) : string {
		if ( preg_match('/' . preg_quote($tag, '/') . '\s+([^\r\n]+)/', $block, $m) ) {
			return trim($m[1]);
		}
		return '';
	}
}

if ( ! function_exists('myls_docs_autogen_clean_docblock') ) {

	function myls_docs_autogen_clean_docblock( string $raw ) : string {
		$raw = str_replace(["\r\n", "\r"], "\n", $raw);
		$lines = explode("\n", $raw);
		$clean = [];
		foreach ( $lines as $ln ) {
			$ln = preg_replace('#^\s*\*\s?#', '', $ln);
			$clean[] = rtrim($ln);
		}
		return trim(implode("\n", $clean));
	}
}
