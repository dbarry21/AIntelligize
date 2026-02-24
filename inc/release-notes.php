<?php
/**
 * AIntelligize – Release Notes Helpers
 * File: inc/release-notes.php
 *
 * Provides:
 *  - myls_release_notes_append_entry(): attempts to prepend an entry to CHANGELOG.md
 *  - admin-post handler for the Docs → Release Notes form
 *
 * Important: many hosts do not allow writing to plugin files. In that case, we queue entries
 * in an option so you can still keep notes inside WP, then copy them into the repo later.
 */

if ( ! defined('ABSPATH') ) exit;

/** Option key for queued entries when file is not writable. */
if ( ! defined('MYLS_RELEASE_NOTES_PENDING_OPT') ) {
	define('MYLS_RELEASE_NOTES_PENDING_OPT', 'myls_pending_release_notes');
}

/**
 * Prepend a markdown block to CHANGELOG.md if possible; otherwise queue in an option.
 *
 * @param string $version  Version string (e.g. 4.6.2)
 * @param string $title    Short title (e.g. "Docs & Upgrade Notices")
 * @param string[] $bullets Bullet lines (without leading "-")
 * @return array{written:bool, queued:bool, error:string}
 */
if ( ! function_exists('myls_release_notes_append_entry') ) {
	function myls_release_notes_append_entry( string $version, string $title, array $bullets ) : array {
		$version = trim($version);
		$title   = trim($title);
		$bullets = array_values(array_filter(array_map('trim', $bullets), fn($v) => $v !== ''));

		if ( $version === '' ) {
			return ['written' => false, 'queued' => false, 'error' => 'Missing version.'];
		}
		if ( $title === '' ) {
			$title = 'Release Notes';
		}

		$block = "\n## {$version} – {$title}\n";
		if ( $bullets ) {
			$block .= "\n### Notes\n";
			foreach ( $bullets as $b ) {
				$block .= "- " . $b . "\n";
			}
		} else {
			$block .= "\n### Notes\n- (no details provided)\n";
		}
		$block .= "\n";

		$changelog_path = trailingslashit(MYLS_PATH) . 'CHANGELOG.md';

		// If we can write to the file, prepend the new block right under the title.
		if ( file_exists($changelog_path) && is_writable($changelog_path) ) {
			$existing = (string) file_get_contents($changelog_path);

			// If there's a top-level title, keep it at the very top.
			if ( preg_match('/^#\s+.*\n\n?/m', $existing, $m, PREG_OFFSET_CAPTURE) ) {
				$header = $m[0][0];
				$rest   = substr($existing, strlen($header));
				$new    = rtrim($header) . "\n" . ltrim($block) . ltrim($rest);
			} else {
				$new = ltrim($block) . "\n" . ltrim($existing);
			}

			$file_ok = file_put_contents($changelog_path, $new);
			if ( $file_ok !== false ) {
				return ['written' => true, 'queued' => false, 'error' => ''];
			}
			// Fallthrough to queue.
		}

		// Queue in option (we can still show it in Docs → Release Notes).
		$pending = get_option(MYLS_RELEASE_NOTES_PENDING_OPT, []);
		if ( ! is_array($pending) ) $pending = [];
		$pending[] = [
			'ts'      => time(),
			'version' => $version,
			'title'   => $title,
			'bullets' => $bullets,
		];
		update_option(MYLS_RELEASE_NOTES_PENDING_OPT, $pending, false);

		return ['written' => false, 'queued' => true, 'error' => 'CHANGELOG.md not writable; queued in options.'];
	}
}

/**
 * Admin-post handler: append/queue a release notes entry.
 */
add_action('admin_post_myls_release_notes_append', function () {
	if ( ! current_user_can('manage_options') ) {
		wp_die('Forbidden', 403);
	}

	check_admin_referer('myls_release_notes_append');

	$version = isset($_POST['version']) ? sanitize_text_field(wp_unslash($_POST['version'])) : MYLS_VERSION;
	$title   = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : 'Release Notes';
	$bullets_raw = isset($_POST['bullets']) ? (string) wp_unslash($_POST['bullets']) : '';

	$bullets = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $bullets_raw)));

	$res = myls_release_notes_append_entry($version, $title, $bullets);

	// Redirect back to Docs → Release Notes.
	$url = admin_url('admin.php?page=mlseo-docs&tab=release');
	$url = add_query_arg([
		'myls_rn_written' => $res['written'] ? '1' : '0',
		'myls_rn_queued'  => $res['queued'] ? '1' : '0',
	], $url);

	wp_safe_redirect($url);
	exit;
});
