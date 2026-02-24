<?php
/**
 * Module: Service Area Grid (map + excerpt, alternating)
 * Shortcode: [service_area_grid include_drafts="0" button_text="Schedule Estimate" show_page_title="1"]
 *
 * Attributes:
 *  show_page_title  "1"|"0"  Show/hide the current page's title as an H2 above the grid (default: 1)
 *  show_title       "1"|"0"  Show/hide each service area's title (default: 1)
 *  button_text      string   CTA button text per row (empty = no button)
 *  include_drafts   "0"|"1"  Include draft posts in the grid
 *  posts_per_page   int      Number of posts (-1 = all)
 *  parent_id        int      Filter by parent post ID
 *  orderby          string   WP_Query orderby tokens
 *  order            string   ASC|DESC
 *  map_ratio        string   Map aspect ratio (default: 16x9)
 *  class            string   Extra CSS class on container
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! shortcode_exists('myls_map_embed') && ! shortcode_exists('ssseo_map_embed') ) {
	add_shortcode('myls_map_embed', function(){ return '<em>Maps shortcode not available.</em>'; });
}

add_shortcode('service_area_grid', function( $atts ) {
	$a = shortcode_atts([
		'posts_per_page'  => -1,
		'parent_id'       => '',
		'orderby'         => 'menu_order title',
		'order'           => 'ASC',
		'class'           => '',
		'show_title'      => '1',
		'show_page_title' => '1',
		'map_ratio'       => '16x9',
		'include_drafts'  => '0',
		'button_text'     => '',
	], $atts, 'service_area_grid' );

	$want_drafts = in_array( strtolower( (string) $a['include_drafts'] ), ['1','true','yes'], true );
	$post_status = $want_drafts ? ['publish','draft'] : ['publish'];

	$args = [
		'post_type'        => 'service_area',
		'post_status'      => $post_status,
		'posts_per_page'   => (int) $a['posts_per_page'],
		'orderby'          => [],
		'order'            => strtoupper( $a['order'] ) === 'DESC' ? 'DESC' : 'ASC',
		'no_found_rows'    => true,
		'suppress_filters' => false,
	];

	if ( $a['parent_id'] !== '' && is_numeric( $a['parent_id'] ) ) {
		$args['post_parent'] = (int) $a['parent_id'];
	}

	// Parse orderby
	$tokens = preg_split('/[\s,]+/', trim((string)$a['orderby']));
	if ( $tokens && is_array($tokens) ) {
		foreach ( $tokens as $tok ) {
			$tok = trim($tok);
			if ( $tok ) $args['orderby'][$tok] = $args['order'];
		}
	}
	if ( empty($args['orderby']) ) {
		$args['orderby'] = ['menu_order' => $args['order'], 'title' => $args['order']];
	}

	$q = new WP_Query($args);
	if ( ! $q->have_posts() ) {
		return '<div class="container ssseo-service-area-grid '. esc_attr($a['class']) .'"><div class="alert alert-info">No service areas found.</div></div>';
	}

	$map_tag = shortcode_exists('myls_map_embed') ? 'myls_map_embed' : 'ssseo_map_embed';

	ob_start(); ?>
	<div class="container ssseo-service-area-grid <?php echo esc_attr($a['class']); ?>">
		<style>
		.ssseo-sa-col-link {
			display:block; text-decoration:none; color:inherit; cursor:pointer;
			border-radius:.75em; padding:8px; margin:-8px; transition:background .2s;
		}
		.ssseo-sa-col-link:hover { background:rgba(0,0,0,.03); text-decoration:none; color:inherit; }
		.ssseo-sa-col-link .ssseo-excerpt { pointer-events:none; }
		</style>
		<?php
		// Show the current page title as H2 above the grid
		$show_page_title = in_array( strtolower( (string) $a['show_page_title'] ), ['1','true','yes'], true );
		if ( $show_page_title ) :
			$page_title = get_the_title( get_queried_object_id() );
			if ( $page_title ) : ?>
				<h2 class="ssseo-service-area-grid-title mb-4"><?php echo esc_html( $page_title ); ?></h2>
			<?php endif;
		endif;
		?>
		<?php $i = 0;
		while ( $q->have_posts() ) : $q->the_post();
			$post_id = get_the_ID();
			$odd = ($i % 2 === 0);

			$map_col_classes  = $odd ? 'order-1 order-md-1' : 'order-1 order-md-2';
			$text_col_classes = $odd ? 'order-2 order-md-2' : 'order-2 order-md-1';

			$html_excerpt = '';
			if ( function_exists('get_field') ) $html_excerpt = (string) get_field('html_excerpt', $post_id);
			if ( $html_excerpt === '' ) $html_excerpt = (string) get_post_meta($post_id, 'html_excerpt', true);
			if ( $html_excerpt === '' ) {
				$html_excerpt = has_excerpt($post_id) ? get_the_excerpt($post_id)
					: wp_trim_words( wp_strip_all_tags( get_the_content(null, false, $post_id) ), 36 );
			}
			$html_excerpt = wp_kses_post( wpautop( do_shortcode( $html_excerpt ) ) );

			$map_html = do_shortcode(
				sprintf('[%s field="city_state" ratio="%s" width="100%%"]', esc_attr($map_tag), esc_attr($a['map_ratio']))
			);

			$title_text = esc_html( get_the_title($post_id) );
			$is_published = ( get_post_status($post_id) === 'publish' );

			$title_html = $is_published
				? sprintf('<a href="%s" aria-label="%s">%s</a>', esc_url( get_permalink($post_id) ), esc_attr( $title_text ), $title_text)
				: $title_text;
			?>
			<div class="row g-4 align-items-center ssseo-row mb-4">
				<div class="col-md-6 <?php echo esc_attr($map_col_classes); ?>">
					<?php echo $map_html; ?>
				</div>
				<div class="col-md-6 <?php echo esc_attr($text_col_classes); ?>">
					<?php
					$show_title = in_array( strtolower( (string) $a['show_title'] ), ['1','true','yes'], true );

					// When title is hidden, wrap heading + excerpt in a link so the column is clickable
					$wrap_link = ( ! $show_title && $is_published );
					if ( $wrap_link ) : ?>
						<a href="<?php echo esc_url( get_permalink($post_id) ); ?>" class="ssseo-sa-col-link" aria-label="<?php echo esc_attr( $title_text ); ?>">
					<?php endif; ?>

					<?php if ( $show_title ) : ?>
						<h3 class="h4 mb-3"><?php echo $title_html; ?></h3>
					<?php endif; ?>

					<div class="ssseo-excerpt mb-3">
						<?php echo $html_excerpt; ?>
					</div>

					<?php if ( $wrap_link ) : ?>
						</a>
					<?php endif; ?>

					<?php if ( ! empty($a['button_text']) ) : ?>
	<div class="ssseo-cta-btn-wrapper text-center">
		<?php if ( $is_published ) : ?>
			<a href="<?php echo esc_url( get_permalink($post_id) ); ?>" class="btn btn-primary ssseo-cta-btn" aria-label="<?php echo esc_attr( $a['button_text'] . ' — ' . $title_text ); ?>">
				<?php echo esc_html( $a['button_text'] ); ?>
			</a>
		<?php else : ?>
			<span class="btn btn-secondary disabled ssseo-cta-btn">
				<?php echo esc_html( $a['button_text'] ); ?>
			</span>
		<?php endif; ?>
	</div>
<?php endif; ?>

				</div>
			</div>
			<?php
			$i++;
		endwhile;
		wp_reset_postdata();
		?>
	</div>
	<?php
	return ob_get_clean();
});
