<?php
/**
 * AIntelligize – Docs: Auto-Generated API Reference
 * File: admin/docs/autodocs.php
 */
if ( ! defined('ABSPATH') ) exit;

require_once __DIR__ . '/lib/parser.php';

$plugin_root = plugin_dir_path(dirname(__FILE__, 2)); // .../aintelligize/
$index       = myls_docs_autogen_build_index($plugin_root);

$q      = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
$group  = isset($_GET['group']) ? sanitize_text_field($_GET['group']) : '';
$file   = isset($_GET['file']) ? sanitize_text_field($_GET['file']) : '';
$item   = isset($_GET['item']) ? sanitize_text_field($_GET['item']) : '';

/**
 * Filter file list by group + query.
 */
$files = $index['files'];

if ( $group ) {
	$files = array_values(array_filter($files, function($f) use ($group){
		return strcasecmp($f['group'], $group) === 0;
	}));
}

if ( $q ) {
	$qq = mb_strtolower($q);
	$files = array_values(array_filter($files, function($f) use ($qq){
		$hay = mb_strtolower($f['path'] . ' ' . $f['title'] . ' ' . $f['summary'] . ' ' . implode(',', $f['tags']));
		if ( strpos($hay, $qq) !== false ) return true;
		// Search items
		foreach ( $f['items'] as $it ) {
			$ih = mb_strtolower($it['name'] . ' ' . $it['signature'] . ' ' . $it['summary']);
			if ( strpos($ih, $qq) !== false ) return true;
		}
		return false;
	}));
}

$groups = array_keys($index['groups']);
sort($groups, SORT_NATURAL | SORT_FLAG_CASE);

// If a file isn't explicitly selected, pick first match.
if ( ! $file && ! empty($files) ) {
	$file = $files[0]['path'];
}

// Find selected file entry.
$selected = null;
foreach ( $files as $f ) {
	if ( $f['path'] === $file ) { $selected = $f; break; }
}

// If an item isn't selected, pick first member if present.
if ( $selected && ! $item && ! empty($selected['items']) ) {
	$item = $selected['items'][0]['name'];
}

?>
<style>
.myls-docs-grid{display:grid;grid-template-columns:340px 1fr;gap:16px;margin-top:12px}
.myls-docs-panel{background:#fff;border:1px solid #ccd0d4;border-radius:10px;padding:12px}
.myls-docs-panel h3{margin:0 0 8px 0}
.myls-docs-kbd{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:2px 6px}
.myls-docs-list{max-height:70vh;overflow:auto;margin:0;padding:0;list-style:none}
.myls-docs-list li{margin:0;padding:0}
.myls-docs-list a{display:block;padding:8px 10px;text-decoration:none;border-radius:8px}
.myls-docs-list a:hover{background:#f6f7f7}
.myls-docs-list a.active{background:#e7f1ff;border:1px solid #b6d6ff}
.myls-docs-sub{font-size:12px;color:#50575e;margin-top:2px}
.myls-docs-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.myls-docs-toolbar input[type="text"]{min-width:280px}
.myls-docs-badge{display:inline-block;background:#f0f0f1;border:1px solid #dcdcde;border-radius:999px;padding:2px 8px;font-size:12px;margin-right:6px}
.myls-docs-itemlist{display:flex;flex-wrap:wrap;gap:6px;margin:10px 0}
.myls-docs-itemlist a{display:inline-block;padding:6px 8px;border:1px solid #dcdcde;border-radius:8px;text-decoration:none}
.myls-docs-itemlist a.active{background:#e7f1ff;border-color:#b6d6ff}
.myls-docs-table{width:100%;border-collapse:collapse}
.myls-docs-table th,.myls-docs-table td{border:1px solid #dcdcde;padding:8px;vertical-align:top}
.myls-docs-muted{color:#6c7781}
</style>

<h2>API Reference (Auto-Generated)</h2>

<p class="myls-docs-muted">
This page scans your plugin files for PHPDoc blocks and builds a browsable reference.
Add <span class="myls-docs-kbd">@myls-doc-title</span>, <span class="myls-docs-kbd">@myls-doc-group</span>, and <span class="myls-docs-kbd">@myls-doc-tags</span> inside docblocks to improve results.
</p>

<form method="get" class="myls-docs-toolbar">
	<input type="hidden" name="page" value="mlseo-docs">
	<input type="hidden" name="tab" value="autodocs">

	<label>
		<span class="myls-docs-muted">Search</span><br>
		<input type="text" name="q" value="<?php echo esc_attr($q); ?>" placeholder="Search files, functions, classes…">
	</label>

	<label>
		<span class="myls-docs-muted">Group</span><br>
		<select name="group">
			<option value="">All Groups</option>
			<?php foreach ( $groups as $g ): ?>
				<option value="<?php echo esc_attr($g); ?>" <?php selected($group, $g); ?>><?php echo esc_html($g); ?></option>
			<?php endforeach; ?>
		</select>
	</label>

	<button class="button button-primary" type="submit">Apply</button>
	<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mlseo-docs&tab=autodocs')); ?>">Reset</a>
</form>

<div class="myls-docs-grid">

	<div class="myls-docs-panel">
		<h3>Files</h3>
		<ul class="myls-docs-list">
			<?php if ( empty($files) ): ?>
				<li><em>No matches.</em></li>
			<?php else: ?>
				<?php foreach ( $files as $f ): 
					$url = admin_url('admin.php?page=mlseo-docs&tab=autodocs'
						. ($q ? '&q=' . rawurlencode($q) : '')
						. ($group ? '&group=' . rawurlencode($group) : '')
						. '&file=' . rawurlencode($f['path'])
					);
					$active = ($selected && $selected['path'] === $f['path']);
				?>
					<li>
						<a href="<?php echo esc_url($url); ?>" class="<?php echo $active ? 'active' : ''; ?>">
							<strong><?php echo esc_html($f['title']); ?></strong>
							<div class="myls-docs-sub"><?php echo esc_html($f['path']); ?></div>
							<?php if ( $f['summary'] ): ?>
								<div class="myls-docs-sub"><?php echo esc_html($f['summary']); ?></div>
							<?php endif; ?>
						</a>
					</li>
				<?php endforeach; ?>
			<?php endif; ?>
		</ul>
	</div>

	<div class="myls-docs-panel">
		<?php if ( ! $selected ): ?>
			<p><em>Select a file to view its reference.</em></p>
		<?php else: ?>

			<h3><?php echo esc_html($selected['title']); ?></h3>

			<p class="myls-docs-muted">
				<span class="myls-docs-badge"><?php echo esc_html($selected['group']); ?></span>
				<span class="myls-docs-kbd"><?php echo esc_html($selected['path']); ?></span>
				<?php if ( $selected['since'] ): ?>
					&nbsp; <span class="myls-docs-muted">Since:</span> <span class="myls-docs-kbd"><?php echo esc_html($selected['since']); ?></span>
				<?php endif; ?>
			</p>

			<?php if ( ! empty($selected['tags']) ): ?>
				<p>
					<?php foreach ( $selected['tags'] as $t ): ?>
						<span class="myls-docs-badge"><?php echo esc_html($t); ?></span>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>

			<?php if ( $selected['summary'] ): ?>
				<p><?php echo esc_html($selected['summary']); ?></p>
			<?php endif; ?>

			<?php if ( empty($selected['items']) ): ?>
				<p><em>No documented functions/classes found in this file.</em></p>
			<?php else: ?>

				<div class="myls-docs-itemlist">
					<?php foreach ( $selected['items'] as $it ):
						$iu = admin_url('admin.php?page=mlseo-docs&tab=autodocs'
							. ($q ? '&q=' . rawurlencode($q) : '')
							. ($group ? '&group=' . rawurlencode($group) : '')
							. '&file=' . rawurlencode($selected['path'])
							. '&item=' . rawurlencode($it['name'])
						);
						$ia = ($item === $it['name']);
					?>
						<a href="<?php echo esc_url($iu); ?>" class="<?php echo $ia ? 'active' : ''; ?>">
							<?php echo esc_html($it['name']); ?>
						</a>
					<?php endforeach; ?>
				</div>

				<?php
				$current = null;
				foreach ( $selected['items'] as $it ) {
					if ( $it['name'] === $item ) { $current = $it; break; }
				}
				if ( ! $current ) $current = $selected['items'][0];
				?>

				<h4 style="margin-top:10px;"><?php echo esc_html( ucfirst($current['kind']) ); ?>: <span class="myls-docs-kbd"><?php echo esc_html($current['name']); ?></span></h4>
				<p><span class="myls-docs-kbd"><?php echo esc_html($current['signature']); ?></span></p>

				<?php if ( $current['summary'] ): ?>
					<p><?php echo esc_html($current['summary']); ?></p>
				<?php endif; ?>

				<?php if ( ! empty($current['tags']['params']) ): ?>
					<h4>Parameters</h4>
					<table class="myls-docs-table">
						<thead><tr><th>Type</th><th>Name</th><th>Description</th></tr></thead>
						<tbody>
						<?php foreach ( $current['tags']['params'] as $p ): ?>
							<tr>
								<td><span class="myls-docs-kbd"><?php echo esc_html($p['type']); ?></span></td>
								<td><span class="myls-docs-kbd"><?php echo esc_html($p['name']); ?></span></td>
								<td><?php echo esc_html($p['desc']); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php if ( ! empty($current['tags']['return']) ): ?>
					<h4>Returns</h4>
					<p><span class="myls-docs-kbd"><?php echo esc_html($current['tags']['return']); ?></span></p>
				<?php endif; ?>

				<?php if ( ! empty($current['tags']['since']) ): ?>
					<h4>Since</h4>
					<p><span class="myls-docs-kbd"><?php echo esc_html($current['tags']['since']); ?></span></p>
				<?php endif; ?>

				<?php if ( ! empty($current['tags']['see']) ): ?>
					<h4>See also</h4>
					<ul>
						<?php foreach ( $current['tags']['see'] as $s ): ?>
							<li><?php echo esc_html($s); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

			<?php endif; ?>

		<?php endif; ?>
	</div>

</div>
