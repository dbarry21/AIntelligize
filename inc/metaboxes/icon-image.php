<?php
/**
 * AIntelligize — Icon Image Metabox
 * Path: inc/metaboxes/icon-image.php
 *
 * Adds an "Icon Image" metabox to all public, publicly_queryable post types.
 * Works exactly like the native Featured Image metabox — uses the WP media
 * library, stores the attachment ID, and renders a live preview.
 *
 * Meta key: _myls_icon_image_id  (attachment ID, integer)
 *
 * Shortcodes / templates use myls_get_icon_image_url() which automatically
 * falls back to the featured image if no icon image is set.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Helper: get icon image URL with featured image fallback ───────────────

if ( ! function_exists( 'myls_get_icon_image_url' ) ) {
	/**
	 * Returns the icon image URL for a post, falling back to featured image.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $size     Image size slug (default 'large').
	 * @return string|false    URL string or false if neither is set.
	 */
	function myls_get_icon_image_url( $post_id, $size = 'large' ) {
		$icon_id = (int) get_post_meta( $post_id, '_myls_icon_image_id', true );

		if ( $icon_id ) {
			$url = wp_get_attachment_image_url( $icon_id, $size );
			if ( $url ) return $url;
		}

		// Fallback: featured image
		return get_the_post_thumbnail_url( $post_id, $size );
	}
}

if ( ! function_exists( 'myls_get_icon_image_id' ) ) {
	/**
	 * Returns the icon image attachment ID, falling back to featured image ID.
	 *
	 * @param int $post_id Post ID.
	 * @return int Attachment ID or 0.
	 */
	function myls_get_icon_image_id( $post_id ) {
		$icon_id = (int) get_post_meta( $post_id, '_myls_icon_image_id', true );
		if ( $icon_id ) return $icon_id;
		return (int) get_post_thumbnail_id( $post_id );
	}
}


// ── Register metabox on all public + queryable post types ─────────────────

add_action( 'add_meta_boxes', function() {
	$post_types = get_post_types( [
		'public'             => true,
		'publicly_queryable' => true,
	], 'objects' );

	foreach ( $post_types as $pt ) {
		if ( $pt->name === 'attachment' ) continue;

		add_meta_box(
			'myls_icon_image',
			'<span class="dashicons dashicons-format-image" style="margin-right:4px;vertical-align:middle;"></span> Icon Image',
			'myls_icon_image_render',
			$pt->name,
			'side',
			'low'
		);
	}
} );


// ── Render ─────────────────────────────────────────────────────────────────

function myls_icon_image_render( $post ) {
	wp_nonce_field( 'myls_icon_image_save', 'myls_icon_image_nonce' );

	$icon_id      = (int) get_post_meta( $post->ID, '_myls_icon_image_id', true );
	$thumb_id     = (int) get_post_thumbnail_id( $post->ID );
	$has_icon     = $icon_id > 0;
	$has_fallback = $thumb_id > 0;

	// Preview URL — use medium size for the metabox thumbnail
	$preview_url = $has_icon
		? wp_get_attachment_image_url( $icon_id, 'medium' )
		: '';

	?>
	<div id="myls-icon-image-wrap" style="margin: 4px 0 8px;">

		<?php // ── Preview image ──────────────────────────────────────── ?>
		<div id="myls-icon-image-preview" style="margin-bottom: 8px; <?php echo $has_icon ? '' : 'display:none;'; ?>">
			<?php if ( $preview_url ) : ?>
				<img id="myls-icon-image-preview-img"
					src="<?php echo esc_url( $preview_url ); ?>"
					alt=""
					style="max-width:100%;height:auto;display:block;border:1px solid #ddd;border-radius:3px;">
			<?php else : ?>
				<img id="myls-icon-image-preview-img" src="" alt=""
					style="max-width:100%;height:auto;display:block;border:1px solid #ddd;border-radius:3px;display:none;">
			<?php endif; ?>
		</div>

		<?php // ── Fallback notice ────────────────────────────────────── ?>
		<?php if ( ! $has_icon ) : ?>
		<div id="myls-icon-fallback-notice" style="font-size:11px;color:#666;margin-bottom:8px;padding:6px 8px;background:#f6f7f7;border-left:3px solid #c3c4c7;border-radius:2px;">
			<?php if ( $has_fallback ) : ?>
				⬇ Using Featured Image as fallback
			<?php else : ?>
				No icon set — will use Featured Image if available
			<?php endif; ?>
		</div>
		<?php else : ?>
		<div id="myls-icon-fallback-notice" style="display:none;font-size:11px;color:#666;margin-bottom:8px;padding:6px 8px;background:#f6f7f7;border-left:3px solid #c3c4c7;border-radius:2px;">
			No icon set — will use Featured Image if available
		</div>
		<?php endif; ?>

		<?php // ── Hidden field ───────────────────────────────────────── ?>
		<input type="hidden" id="myls_icon_image_id" name="myls_icon_image_id"
			value="<?php echo esc_attr( $icon_id ?: '' ); ?>">

		<?php // ── Action buttons ─────────────────────────────────────── ?>
		<div style="display:flex;gap:6px;flex-wrap:wrap;">
			<button type="button" id="myls-icon-image-set" class="button button-secondary" style="flex:1;">
				<?php echo $has_icon ? 'Change Icon Image' : 'Set Icon Image'; ?>
			</button>
			<?php if ( $has_icon ) : ?>
			<button type="button" id="myls-icon-image-remove" class="button" style="color:#a00;flex-shrink:0;">
				Remove
			</button>
			<?php else : ?>
			<button type="button" id="myls-icon-image-remove" class="button" style="color:#a00;flex-shrink:0;display:none;">
				Remove
			</button>
			<?php endif; ?>
		</div>

	</div>

	<script>
	jQuery( function( $ ) {
		var frame;
		var $wrap      = $( '#myls-icon-image-wrap' );
		var $preview   = $( '#myls-icon-image-preview' );
		var $previewImg= $( '#myls-icon-image-preview-img' );
		var $fallback  = $( '#myls-icon-fallback-notice' );
		var $hidden    = $( '#myls_icon_image_id' );
		var $setBtn    = $( '#myls-icon-image-set' );
		var $removeBtn = $( '#myls-icon-image-remove' );
		var hasFallback= <?php echo $has_fallback ? 'true' : 'false'; ?>;

		function showIcon( id, url ) {
			$hidden.val( id );
			$previewImg.attr( 'src', url ).show();
			$preview.show();
			$fallback.hide();
			$setBtn.text( 'Change Icon Image' );
			$removeBtn.show();
		}

		function clearIcon() {
			$hidden.val( '' );
			$previewImg.attr( 'src', '' ).hide();
			$preview.hide();
			$fallback
				.text( hasFallback ? '⬇ Using Featured Image as fallback' : 'No icon set — will use Featured Image if available' )
				.show();
			$setBtn.text( 'Set Icon Image' );
			$removeBtn.hide();
		}

		// Open media library
		$setBtn.on( 'click', function( e ) {
			e.preventDefault();

			if ( frame ) { frame.open(); return; }

			frame = wp.media( {
				title:    'Select or Upload Icon Image',
				button:   { text: 'Use as Icon Image' },
				library:  { type: 'image' },
				multiple: false,
			} );

			frame.on( 'select', function() {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var url = attachment.sizes?.medium?.url
					   || attachment.sizes?.thumbnail?.url
					   || attachment.url;
				showIcon( attachment.id, url );
			} );

			frame.open();
		} );

		// Remove
		$removeBtn.on( 'click', function( e ) {
			e.preventDefault();
			clearIcon();
		} );
	} );
	</script>
	<?php
}


// ── Save ───────────────────────────────────────────────────────────────────

add_action( 'save_post', function( $post_id, $post ) {
	if ( ! isset( $_POST['myls_icon_image_nonce'] ) ) return;
	if ( ! wp_verify_nonce( $_POST['myls_icon_image_nonce'], 'myls_icon_image_save' ) ) return;
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( ! current_user_can( 'edit_post', $post_id ) ) return;
	if ( $post->post_type === 'attachment' ) return;

	if ( isset( $_POST['myls_icon_image_id'] ) ) {
		$icon_id = intval( $_POST['myls_icon_image_id'] );
		if ( $icon_id > 0 ) {
			update_post_meta( $post_id, '_myls_icon_image_id', $icon_id );
		} else {
			delete_post_meta( $post_id, '_myls_icon_image_id' );
		}
	}
}, 10, 2 );
