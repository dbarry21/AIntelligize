<?php
/**
 * AIntelligize – Docs: Shortcode Auto Reference (Phase 3)
 * File: admin/docs/lib/shortcodes.php
 *
 * Scans /modules/shortcodes/*.php to build a reference of all add_shortcode() calls.
 *
 * We keep the parser intentionally lightweight (regex-based) because:
 *  - these are your own plugin files
 *  - we want it fast and dependency-free
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Return a stable plugin-relative path.
 */
function mlseo_docs_rel_path( string $abs_path ) : string {
	$root = wp_normalize_path( plugin_dir_path( dirname(__FILE__, 3) ) ); // .../aintelligize/
	$abs  = wp_normalize_path( $abs_path );
	if ( strpos( $abs, $root ) === 0 ) {
		return ltrim( substr( $abs, strlen($root) ), '/' );
	}
	return basename($abs);
}

/**
 * Extract the nearest docblock (PHPDoc block) that appears before a given position.
 */
function mlseo_docs_extract_nearest_docblock( string $src, int $pos, int $max_lookback = 2000 ) : string {
	$start = max(0, $pos - $max_lookback);
	$chunk = substr( $src, $start, $pos - $start );

	// Find the last docblock start in the chunk.
	$idx = strrpos( $chunk, "/**" );
	if ( $idx === false ) return '';

	$after = substr( $chunk, $idx );
	$end   = strpos( $after, "*/" );
	if ( $end === false ) return '';

	return substr( $after, 0, $end + 2 );
}

/**
 * Parse a docblock into a structured array.
 */
function mlseo_docs_parse_docblock( string $docblock ) : array {
	if ( $docblock === '' ) {
		return [
			'summary'   => '',
			'lines'     => [],
			'examples'  => [],
			'notes'     => [],
			'requires'  => [],
			'raw'       => '',
		];
	}

	$raw = $docblock;
	$docblock = preg_replace('/^\s*\/\*\*\s*/', '', $docblock);
	$docblock = preg_replace('/\*\/\s*$/', '', $docblock);

	$lines = preg_split('/\R/', (string) $docblock);
	$clean = [];
	foreach ( $lines as $line ) {
		$line = preg_replace('/^\s*\*\s?/', '', (string) $line);
		$clean[] = rtrim($line);
	}

	// Summary: first non-empty, non-tag line.
	$summary = '';
	foreach ( $clean as $l ) {
		if ( trim($l) === '' ) continue;
		if ( strpos(trim($l), '@') === 0 ) continue;
		$summary = trim($l);
		break;
	}

	// Examples: any [shortcode ...] snippets found.
	$examples = [];
	if ( preg_match_all('/\[[a-zA-Z0-9_\-]+[^\]]*\]/', implode("\n", $clean), $m) ) {
		$examples = array_values(array_unique(array_map('trim', $m[0])));
	}

	// Notes: bullet-ish lines or "- ..." lines.
	$notes = [];
	foreach ( $clean as $l ) {
		$tl = trim($l);
		if ( preg_match('/^[-*]\s+(.+)$/', $tl, $mm) ) {
			$notes[] = trim($mm[1]);
		}
	}
	$notes = array_values(array_unique($notes));

	// "Requires": we look for lines containing "Requires" or "Requires:" and also option/key hints.
	$requires = [];
	foreach ( $clean as $l ) {
		if ( stripos($l, 'requires') !== false ) {
			$requires[] = trim($l);
		}
		if ( preg_match('/\b(get_option\(|Site Options|ACF)\b/i', $l) ) {
			$requires[] = trim($l);
		}
	}
	$requires = array_values(array_unique(array_filter($requires)));

	return [
		'summary'   => $summary,
		'lines'     => $clean,
		'examples'  => $examples,
		'notes'     => $notes,
		'requires'  => $requires,
		'raw'       => $raw,
	];
}

/**
 * Try to extract shortcode attributes from a nearby shortcode_atts([ ... ]) call.
 */
function mlseo_docs_extract_atts( string $src, int $pos, int $max_lookahead = 5000 ) : array {
	$chunk = substr( $src, $pos, $max_lookahead );

	// Match shortcode_atts( [ 'a' =>, "b" => ... ],
	if ( ! preg_match('/shortcode_atts\s*\(\s*\[([\s\S]{0,4000}?)\]\s*,/m', $chunk, $m) ) {
		return [];
	}

	$inside = (string) $m[1];
	$keys = [];
	if ( preg_match_all('/[\'\"]([a-zA-Z0-9_\-]+)[\'\"]\s*=>/m', $inside, $mm) ) {
		$keys = array_values(array_unique($mm[1]));
	}
	return $keys;
}

/**
 * Build an index of shortcodes.
 */
function mlseo_docs_build_shortcodes_index( bool $force_refresh = false ) : array {
	$cache_key = 'mlseo_docs_shortcodes_index_v1';
	if ( ! $force_refresh ) {
		$cached = get_transient( $cache_key );
		if ( is_array($cached) ) return $cached;
	}

	$root = plugin_dir_path( dirname(__FILE__, 3) ); // .../aintelligize/
	$dir  = trailingslashit($root) . 'modules/shortcodes/';

	$files = glob( $dir . '*.php' ) ?: [];
	$items = [];

	foreach ( $files as $file ) {
		$src = file_get_contents($file);
		if ( ! is_string($src) || $src === '' ) continue;

		if ( ! preg_match_all('/\badd_shortcode\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/m', $src, $m, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}

		foreach ( $m[1] as $hit ) {
			$name = (string) $hit[0];
			$pos  = (int) $hit[1];

			$doc_raw = mlseo_docs_extract_nearest_docblock( $src, $pos );
			$doc     = mlseo_docs_parse_docblock( $doc_raw );
			$atts    = mlseo_docs_extract_atts( $src, $pos );

			$items[] = [
				'name'        => $name,
				'file'        => $file,
				'file_rel'    => mlseo_docs_rel_path($file),
				'summary'     => $doc['summary'],
				'examples'    => $doc['examples'],
				'notes'       => $doc['notes'],
				'requires'    => $doc['requires'],
				'attributes'  => $atts,
			];
		}
	}

	// Sort alphabetically.
	usort($items, function($a,$b){
		return strcmp($a['name'], $b['name']);
	});

	set_transient( $cache_key, $items, 10 * MINUTE_IN_SECONDS );
	return $items;
}

/**
 * Render export Markdown for shortcode index.
 */
function mlseo_docs_render_shortcodes_markdown( array $items ) : string {
	$out = [];
	$out[] = '# AIntelligize – Shortcodes Reference';
	$out[] = '';
	$out[] = 'Generated: ' . gmdate('Y-m-d H:i:s') . ' UTC';
	$out[] = '';

	foreach ( $items as $sc ) {
		$out[] = '## [' . $sc['name'] . ']';
		$out[] = '';
		if ( $sc['summary'] ) {
			$out[] = $sc['summary'];
			$out[] = '';
		}
		$out[] = '**File:** `' . $sc['file_rel'] . '`';
		$out[] = '';
		if ( ! empty($sc['examples']) ) {
			$out[] = '**Examples:**';
			foreach ( $sc['examples'] as $ex ) {
				$out[] = '';
				$out[] = '```text';
				$out[] = $ex;
				$out[] = '```';
			}
			$out[] = '';
		}
		if ( ! empty($sc['attributes']) ) {
			$out[] = '**Attributes:**';
			foreach ( $sc['attributes'] as $a ) {
				$out[] = '- `' . $a . '`';
			}
			$out[] = '';
		}
		if ( ! empty($sc['notes']) ) {
			$out[] = '**Notes:**';
			foreach ( $sc['notes'] as $n ) {
				$out[] = '- ' . $n;
			}
			$out[] = '';
		}
		if ( ! empty($sc['requires']) ) {
			$out[] = '**Requirements / Dependencies:**';
			foreach ( $sc['requires'] as $r ) {
				$out[] = '- ' . $r;
			}
			$out[] = '';
		}
	}

	return implode("\n", $out);
}

/**
 * Render export HTML for shortcode index.
 */
function mlseo_docs_render_shortcodes_html( array $items ) : string {
	$md = mlseo_docs_render_shortcodes_markdown( $items );

	// If the markdown helper exists (Phase 2 hub), reuse it.
	if ( function_exists('mlseo_docs_md_to_html') ) {
		$body = mlseo_docs_md_to_html( $md );
	} else {
		$body = '<pre>' . esc_html($md) . '</pre>';
	}

	return "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>AIntelligize – Shortcodes Reference</title></head><body>" . $body . "</body></html>";
}
