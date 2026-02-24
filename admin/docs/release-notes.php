<?php
/**
 * AIntelligize – Docs Tab: Release Notes
 * File: admin/docs/release-notes.php
 */

if ( ! defined('ABSPATH') ) exit;

// If loaded outside documentation.php, ensure a minimal markdown renderer exists.
if ( ! function_exists('mlseo_docs_md_to_html') ) {
	function mlseo_docs_md_to_html( string $md ) : string {
		$md = str_replace(["\r\n", "\r"], "\n", $md);
		$lines = explode("\n", $md);
		$out = [];
		$in_ul = false;
		foreach ( $lines as $line ) {
			$raw = rtrim($line);
			if ( preg_match('/^(#{1,6})\s+(.*)$/', $raw, $m) ) {
				if ( $in_ul ) { $out[] = '</ul>'; $in_ul = false; }
				$level = strlen($m[1]);
				$text  = esc_html(trim($m[2]));
				$out[] = "<h{$level}>{$text}</h{$level}>";
				continue;
			}
			if ( preg_match('/^\s*[-*]\s+(.*)$/', $raw, $m) ) {
				if ( ! $in_ul ) { $out[] = '<ul>'; $in_ul = true; }
				$out[] = '<li>' . esc_html(trim($m[1])) . '</li>';
				continue;
			}
			if ( trim($raw) === '' ) {
				if ( $in_ul ) { $out[] = '</ul>'; $in_ul = false; }
				continue;
			}
			$text = esc_html($raw);
			$text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
			$out[] = '<p>' . $text . '</p>';
		}
		if ( $in_ul ) $out[] = '</ul>';
		return wp_kses_post( implode("\n", $out) );
	}
}

$changelog_path = plugin_dir_path( dirname(__FILE__, 2) ) . 'CHANGELOG.md';
$pending_opt = defined('MYLS_RELEASE_NOTES_PENDING_OPT') ? MYLS_RELEASE_NOTES_PENDING_OPT : 'myls_pending_release_notes';
$pending = get_option($pending_opt, []);
if ( ! is_array($pending) ) $pending = [];

// Append form (optional; safe).
?>

<h2>Release Notes</h2>

<?php
$written = isset($_GET['myls_rn_written']) ? sanitize_text_field($_GET['myls_rn_written']) : '';
$queued  = isset($_GET['myls_rn_queued']) ? sanitize_text_field($_GET['myls_rn_queued']) : '';
if ( $written === '1' ) {
	echo '<div class="notice notice-success"><p><strong>Release notes appended to CHANGELOG.md.</strong></p></div>';
} elseif ( $queued === '1' ) {
	echo '<div class="notice notice-warning"><p><strong>CHANGELOG.md was not writable. Entry queued as Pending.</strong></p></div>';
}
?>

<div style="margin: 0 0 1em 0; padding: 1em; border: 1px solid #ccd0d4; border-radius: 10px; background: #f8f9fa;">
	<p style="margin:0 0 .5em 0;">
		<strong>Tip:</strong> If your server does not allow writing to plugin files, new entries will be stored as <em>Pending</em> and shown below.
	</p>
	<form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
		<input type="hidden" name="action" value="myls_release_notes_append" />
		<?php wp_nonce_field('myls_release_notes_append'); ?>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="myls_rn_version">Version</label></th>
					<td><input type="text" id="myls_rn_version" name="version" value="<?php echo esc_attr( defined('MYLS_VERSION') ? MYLS_VERSION : '' ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="myls_rn_title">Title</label></th>
					<td><input type="text" id="myls_rn_title" name="title" value="" placeholder="Short summary (e.g., FAQ Editor Improvements)" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="myls_rn_bullets">Bullets</label></th>
					<td>
						<textarea id="myls_rn_bullets" name="bullets" rows="5" class="large-text" placeholder="One bullet per line"></textarea>
						<p class="description">This will be added under a <code>### Notes</code> section for that version.</p>
					</td>
				</tr>
			</tbody>
		</table>
		<p>
			<button type="submit" class="button button-primary">Append Release Notes</button>
		</p>
	</form>
</div>

<?php if ( ! empty($pending) ) : ?>
	<div style="margin: 0 0 1em 0; padding: 1em; border: 1px solid #d63638; border-radius: 10px; background: #fff5f5;">
		<h3 style="margin-top:0;">Pending (Not Written to CHANGELOG.md)</h3>
		<ul style="margin:0;">
			<?php foreach ( $pending as $p ) :
				$pv = isset($p['version']) ? (string) $p['version'] : '';
				$pt = isset($p['title']) ? (string) $p['title'] : '';
				$ts = isset($p['ts']) ? (int) $p['ts'] : 0;
				$when = $ts ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $ts ) : '';
			?>
				<li><strong><?php echo esc_html($pv); ?></strong> — <?php echo esc_html($pt); ?><?php if ( $when ) : ?> <em style="opacity:.75;">(<?php echo esc_html($when); ?>)</em><?php endif; ?></li>
			<?php endforeach; ?>
		</ul>
		<p class="description" style="margin-top:.75em;">To commit these to the repo, copy them into your source-controlled CHANGELOG.md (recommended).
		If you want automatic write support, ensure the plugin folder is writable (not recommended on production sites).</p>
	</div>
<?php endif; ?>

<div style="background:#fff;padding:1em;border:1px solid #ccd0d4;border-radius:10px;">
	<?php
	if ( file_exists($changelog_path) ) {
		$md = (string) file_get_contents($changelog_path);
		echo mlseo_docs_md_to_html($md);
	} else {
		echo '<p><strong>CHANGELOG.md not found.</strong></p>';
		echo '<p><code>' . esc_html($changelog_path) . '</code></p>';
	}
	?>
</div>
