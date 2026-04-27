<?php
/**
 * AIntelligize — Beaver Builder Sub-Tab: Environment Status Partial
 *
 * Renders a small status block at the top of the Beaver Builder sub-tab so
 * you and the client can see at a glance which BB components are detected.
 *
 * Expects $status (array) in scope, as returned by
 * AIntelligize_Beaver_Builder_Parser::get_environment_status().
 *
 * @package AIntelligize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $status ) || ! is_array( $status ) ) {
	return;
}

$badge = function( $bool ) {
	return $bool
		? '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#d4edda;color:#155724;font-size:11px;">Active</span>'
		: '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#f8d7da;color:#721c24;font-size:11px;">Not Active</span>';
};
?>
<div class="aintelligize-bb-status" style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:12px 16px;margin:0 0 20px;border-radius:2px;">
	<h3 style="margin:0 0 10px;font-size:14px;">Beaver Builder Environment</h3>
	<table class="widefat striped" style="border:none;">
		<tbody>
			<tr>
				<td style="width:220px;"><strong>Beaver Builder Plugin</strong></td>
				<td>
					<?php echo $badge( ! empty( $status['bb_plugin_active'] ) ); ?>
					<?php if ( ! empty( $status['bb_plugin_active'] ) ) : ?>
						<code style="margin-left:8px;">v<?php echo esc_html( $status['bb_plugin_version'] ); ?></code>
						<span style="margin-left:8px;color:#646970;">Edition: <?php echo esc_html( $status['bb_edition'] ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td><strong>Beaver Builder Theme</strong></td>
				<td>
					<?php echo $badge( ! empty( $status['bb_theme_active'] ) ); ?>
					<?php if ( ! empty( $status['bb_theme_active'] ) ) : ?>
						<code style="margin-left:8px;">v<?php echo esc_html( $status['bb_theme_version'] ); ?></code>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td><strong>Child Theme</strong></td>
				<td>
					<?php echo $badge( ! empty( $status['is_child_theme'] ) ); ?>
					<?php if ( ! empty( $status['is_child_theme'] ) ) : ?>
						<span style="margin-left:8px;"><?php echo esc_html( $status['child_theme_name'] ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td><strong>Beaver Themer</strong></td>
				<td>
					<?php echo $badge( ! empty( $status['bb_themer_active'] ) ); ?>
					<?php if ( ! empty( $status['bb_themer_active'] ) ) : ?>
						<span style="margin-left:8px;color:#646970;">Themer layouts not parsed in this version.</span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td><strong>Supported Version</strong></td>
				<td>
					<?php if ( ! empty( $status['supported_version'] ) ) : ?>
						<span style="color:#155724;">Yes — minimum is 2.0</span>
					<?php else : ?>
						<span style="color:#721c24;">No — AIntelligize requires Beaver Builder 2.0 or later.</span>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>
</div>
