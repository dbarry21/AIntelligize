<?php
/**
 * AIntelligize – Card Grid Shortcode (Image/Icon Boxes; columns via CSS)
 *
 * Shortcodes:
 *   [myls_card_grid button_text="Learn More" image_size="medium_large" use_icons="0" icon_class="bi bi-grid-3x3-gap"]
 *
 * Back-compat aliases:
 *   [ssseo_card_grid], [ssseo_flip_grid]
 */

namespace MYLS\CardGrid;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Enqueue /wp-content/plugins/aintelligize/assets/frontend.css exactly */
function enqueue_frontend_css() {
	static $enq = false;
	if ( $enq ) return;

	$rel  = 'aintelligize/assets/frontend.css';
	$src  = plugins_url( $rel );                    // https://example.com/wp-content/plugins/aintelligize/assets/frontend.css
	$path = WP_PLUGIN_DIR . '/' . $rel;             // /var/www/.../wp-content/plugins/aintelligize/assets/frontend.css
	$ver  = file_exists( $path ) ? filemtime( $path ) : null;

	wp_register_style( 'myls-frontend', $src, array(), $ver );
	wp_enqueue_style( 'myls-frontend' );
	$enq = true;
}

/** Register shortcodes (myls + legacy aliases) */
function bootstrap() {
	add_shortcode( 'myls_card_grid', __NAMESPACE__ . '\\shortcode' );
	add_shortcode( 'myls_flip_grid', __NAMESPACE__ . '\\shortcode' ); // alias
	add_shortcode( 'ssseo_card_grid', __NAMESPACE__ . '\\shortcode' ); // back-compat
	add_shortcode( 'ssseo_flip_grid', __NAMESPACE__ . '\\shortcode' ); // back-compat
}
add_action( 'init', __NAMESPACE__ . '\\bootstrap' );

/** Render shortcode */
function shortcode( $atts ) {
	enqueue_frontend_css();

	$atts = shortcode_atts( [
		'button_text' => 'Learn More',
		'image_size'  => 'medium_large',
		'use_icons'   => '0',
		'icon_class'  => 'bi bi-grid-3x3-gap'
	], $atts, 'myls_card_grid' );

	$current_id   = get_current_post_id();
	$is_sa_parent = $current_id && ( get_post_type( $current_id ) === 'service_area' );

	$services = get_posts( [
		'post_type'        => 'service',
		'post_status'      => 'publish',
		'posts_per_page'   => -1,
		'post_parent'      => 0,
		'orderby'          => 'menu_order title',
		'order'            => 'ASC',
		'no_found_rows'    => true,
		'suppress_filters' => true,
	] );

	$sa_children = [];
	if ( $is_sa_parent ) {
		$sa_children = get_children( [
			'post_parent' => $current_id,
			'post_type'   => 'service_area',
			'post_status' => 'publish',
			'orderby'     => 'menu_order title',
			'order'       => 'ASC',
		] );
	}

	$items = build_items_with_precedence( $services, $sa_children );
	if ( empty( $items ) ) return '';

	$use_icons = ( $atts['use_icons'] === '1' || $atts['use_icons'] === 1 );

	ob_start(); ?>
	<!-- Control columns with CSS vars on .myls-grid -->
	<div class="myls-grid" style="--gap:1rem;--mobile-columns:1;--tablet-columns:2;--desktop-columns:3;--wide-columns:4;">
		<?php foreach ( $items as $post_obj ) :
			$post_id   = (int) $post_obj->ID;
			$title     = get_the_title( $post_id );
			$permalink = get_permalink( $post_id );
			$excerpt   = get_manual_excerpt_only( $post_id );
			$thumb     = get_card_image( $post_id, $atts['image_size'] );
		?>
		<div class="myls-flip-box">
			<article class="myls-card">
				<?php if ( $thumb ) : ?>
					<a class="card-media" href="<?php echo esc_url( $permalink ); ?>" aria-label="<?php echo esc_attr( $title ); ?>">
						<?php echo $thumb; ?>
					</a>
				<?php elseif ( $use_icons ) : ?>
					<div class="card-media d-flex align-items-center justify-content-center">
						<i class="<?php echo esc_attr( $atts['icon_class'] ); ?>" aria-hidden="true"></i>
						<span class="visually-hidden"><?php echo esc_html( $title ); ?></span>
					</div>
				<?php endif; ?>

				<div class="card-body">
					<h3 class="flip-title">
						<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
					</h3>

					<?php if ( $excerpt !== '' ) : ?>
						<div class="flip-excerpt"><?php echo wp_kses_post( $excerpt ); ?></div>
					<?php endif; ?>

					<a class="flip-button btn btn-primary" href="<?php echo esc_url( $permalink ); ?>">
						<?php echo esc_html( $atts['button_text'] ); ?>
					</a>
				</div>
			</article>
		</div>
		<?php endforeach; ?>
	</div>
	<?php
	return trim( ob_get_clean() );
}

/* ===================== Helpers ===================== */

function get_current_post_id() {
	if ( is_singular() ) {
		return (int) get_queried_object_id();
	}
	global $post;
	return isset( $post->ID ) ? (int) $post->ID : null;
}

/** Precedence resolver */
function build_items_with_precedence( $services, $sa_children ) {
	if ( empty( $services ) ) return [];
	$used_child_ids = [];
	$result = [];

	foreach ( $services as $service ) {
		$svc_title = (string) ( $service->post_title ?? '' );
		$chosen    = $service;

		if ( $svc_title !== '' && ! empty( $sa_children ) ) {
			foreach ( $sa_children as $child ) {
				if ( in_array( $child->ID, $used_child_ids, true ) ) continue;
				if ( title_starts_with( (string) $child->post_title, $svc_title ) ) {
					$chosen = $child;
					$used_child_ids[] = (int) $child->ID;
					break;
				}
			}
		}
		$result[] = $chosen;
	}
	return $result;
}

/** Case-insensitive "starts with" */
function title_starts_with( $haystack, $needle ) {
	$h = preg_replace( '/\s+/', ' ', trim( (string) $haystack ) );
	$n = preg_replace( '/\s+/', ' ', trim( (string) $needle ) );
	if ( $n === '' ) return false;
	return stripos( $h, $n ) === 0;
}

/** Manual excerpt only (no auto content fallback). '' when none. */
function get_manual_excerpt_only( $post_id ) {
	$raw = get_post_field( 'post_excerpt', $post_id );
	$raw = is_string( $raw ) ? trim( $raw ) : '';
	if ( $raw === '' ) return '';
	$max = (int) apply_filters( 'myls/card_grid/excerpt_length', 24 );
	return wp_trim_words( $raw, max( 1, $max ) );
}

/** Featured image HTML or '' */
function get_card_image( $post_id, $size = 'medium_large' ) {
	return has_post_thumbnail( $post_id )
		? get_the_post_thumbnail( $post_id, $size, [ 'class' => 'img-fluid', 'alt' => get_the_title( $post_id ) ] )
		: '';
}
